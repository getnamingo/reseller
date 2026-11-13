<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;
use Pinga\Tembo\EppRegistryFactory;
use RuntimeException;
use Throwable;

final class EppWorker
{
    public static function process(string $jobId): array
    {
        if (!Util::isUuid($jobId)) {
            return ['ok' => false, 'error' => 'invalid_job_id'];
        }

        $pdo = Db::pdo();
        $claim = $pdo->prepare(
            "UPDATE epp_jobs SET status = 'processing', started_at = UTC_TIMESTAMP(6), dispatch_until = NULL
             WHERE id = ? AND status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP(6)"
        );
        $claim->execute([$jobId]);
        if ($claim->rowCount() !== 1) {
            $stmt = $pdo->prepare('SELECT status FROM epp_jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            return ['ok' => true, 'status' => $stmt->fetchColumn() ?: 'missing'];
        }

        $stmt = $pdo->prepare(
            'SELECT j.*, r.profile, r.config_nonce, r.config_cipher
             FROM epp_jobs j JOIN registries r ON r.id = j.registry_id WHERE j.id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if ($job === false) {
            return ['ok' => false, 'error' => 'job_missing_after_claim'];
        }

        $status = 'rejected';
        $eppCode = null;
        $result = ['error' => 'The registry command could not be processed.'];
        $commandAttempted = false;
        $lockName = null;
        $lockAcquired = false;
        $requeue = false;
        $finalizeError = null;

        try {
            $profile = CommandCatalog::normalizeProfile($job['profile']);
            $config = json_decode(
                Crypto::decrypt($job['config_nonce'], $job['config_cipher'], 'registry-config:' . $job['registry_id']),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $request = json_decode(
                Crypto::decrypt($job['request_nonce'], $job['request_cipher'], 'job-request:' . $jobId),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($config) || !is_array($request) || !is_array($request['params'] ?? null)) {
                throw new RuntimeException('Encrypted registry or request data is invalid.');
            }
            $config = self::validateRegistryConfig($profile, $config);

            $lockName = 'epp:' . substr(hash('sha256', $job['registry_id']), 0, 48);
            $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $lock->bindValue(1, $lockName);
            $lock->bindValue(2, Config::int('API_EPP_LOCK_SECONDS', 35), PDO::PARAM_INT);
            $lock->execute();
            if ((string) $lock->fetchColumn() !== '1') {
                throw new RegistryBusyException('Registry session is busy; this queued job will retry automatically.');
            }
            $lockAcquired = true;

            $access = $pdo->prepare("SELECT 1 FROM reseller_registries rr JOIN registries r ON r.id = rr.registry_id
                JOIN resellers u ON u.id = rr.reseller_id
                WHERE rr.registry_id = ? AND rr.reseller_id = ? AND rr.enabled = 1 AND r.enabled = 1 AND u.status = 'active'");
            $access->execute([$job['registry_id'], $job['reseller_id']]);
            if (!$access->fetchColumn()) {
                throw new ApiException(403, 'registry_not_permitted', 'Registry access has been revoked.');
            }
            if ($job['parent_job_id'] !== null && $job['command'] === 'domain.transfer') {
                $parent = $pdo->prepare("SELECT * FROM epp_jobs WHERE id = ? AND reseller_id = ? AND registry_id = ? AND command = 'domain.transfer'");
                $parent->execute([$job['parent_job_id'], $job['reseller_id'], $job['registry_id']]);
                $parentRow = $parent->fetch();
                if ($parentRow !== false) { $request['params'] = array_replace(Ownership::params($parentRow), $request['params']); }
            }
            Access::domains($pdo, $job['registry_id'], $job['reseller_id'], $job['command'], $request['params']);
            Ownership::authorize($pdo, $job['registry_id'], $job['reseller_id'], $job['command'],
                $request['params'], $profile, $jobId);

            $current = $pdo->prepare('SELECT status FROM epp_jobs WHERE id = ?');
            $current->execute([$jobId]);
            if ($current->fetchColumn() !== 'processing') {
                return ['ok' => true, 'status' => 'unchanged', 'job_uuid' => $jobId];
            }
            $raw = self::withConnection($profile, $config, static function ($epp) use ($job, $request, &$commandAttempted) {
                $method = CommandCatalog::method($job['command']);
                $commandAttempted = true;
                return $job['command'] === 'domain.restore_report' ? Rgp::report($epp, $request['params'])
                    : ($method === 'hello' ? $epp->hello() : $epp->{$method}($request['params']));
            });
            $result = Util::normalize($raw);
            if (!is_array($result)) {
                $result = ['response' => $result];
            }
            $eppCode = isset($result['code']) && is_numeric($result['code']) ? (int) $result['code'] : null;

            $status = Lifecycle::status($job['command'], $request['params'], $result);
        } catch (RegistryBusyException $e) {
            $result = ['error' => 'registry_busy', 'message' => $e->getMessage()];
            $requeue = true;
        } catch (RegistryLoginException $e) {
            $result = ['error' => 'registry_login_failed', 'message' => $e->getMessage()];
            $status = 'rejected';
        } catch (Throwable $e) {
            $message = self::safeException($e);
            $result = ['error' => $commandAttempted ? 'registry_command_failed' : 'registry_connection_failed', 'message' => $message];
            $status = $commandAttempted && CommandCatalog::isMutating($job['command'], $request['params'] ?? [])
                ? 'unknown'
                : 'rejected';
        } finally {
            // Commit ownership and funds before another registry command can execute.
            if (!$requeue) {
                try {
                    Ledger::completeJob($pdo, $jobId, $status, $result, $eppCode);
                    if ($status === 'succeeded' && isset($config['login']['clID'])) {
                        Lifecycle::observeFollowup($pdo, $job, $result, $config['login']['clID']);
                    }
                } catch (Throwable $e) {
                    $finalizeError = $e;
                }
            }
            if ($lockAcquired && $lockName !== null) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$lockName]);
                } catch (Throwable) {
                }
            }
        }

        if ($requeue) {
            $queue = $pdo->prepare(
                "UPDATE epp_jobs SET status = 'queued', started_at = NULL, dispatch_until = NULL, next_attempt_at = UTC_TIMESTAMP(6) + INTERVAL 5 SECOND WHERE id = ? AND status = 'processing'"
            );
            $queue->execute([$jobId]);
            Audit::record($pdo, $job['reseller_id'], 'epp.job_deferred', 'job', $jobId, null, [
                'command' => $job['command'],
                'registry_uuid' => $job['registry_id'],
                'reason' => 'registry_busy',
            ]);
            return ['ok' => true, 'status' => $queue->rowCount() === 1 ? 'queued' : 'unchanged', 'job_uuid' => $jobId];
        }

        try {
            if ($finalizeError !== null) {
                throw $finalizeError;
            }
            Audit::record($pdo, $job['reseller_id'], 'epp.job_' . $status, 'job', $jobId, null, [
                'command' => $job['command'],
                'registry_uuid' => $job['registry_id'],
                'epp_code' => $eppCode,
            ]);
            return ['ok' => true, 'status' => $status, 'job_uuid' => $jobId];
        } catch (Throwable $e) {
            error_log(Util::json([
                'level' => 'error',
                'event' => 'job_finalize_failed',
                'job_uuid' => $jobId,
                'message' => $e->getMessage(),
            ]));
            return ['ok' => false, 'error' => 'job_finalize_failed', 'job_uuid' => $jobId];
        }
    }

    /** Connection scope shared by writes and read-only lifecycle observations. */
    public static function withConnection(string $profile, array $config, callable $operation): mixed
    {
        $epp = null; $connected = false; $loggedIn = false; $secretFiles = [];
        try {
            [$connection, $secretFiles] = self::prepareConnection($config['connection']);
            $epp = EppRegistryFactory::create($profile); $epp->disableLogging();
            if (isset($config['loginObjects'])) { $epp->setLoginObjects($config['loginObjects']); }
            if (isset($config['loginExtensions'])) { $epp->setLoginExtensions($config['loginExtensions']); }
            $epp->connect($connection); $connected = true;
            self::enforceTransportTls($epp, $profile, $connection['tls'], $connection['timeout']);
            $login = Util::normalize($epp->login($config['login']));
            if (!is_array($login) || isset($login['error']) || (isset($login['code']) && (int) $login['code'] >= 2000)) {
                throw new RegistryLoginException(is_array($login) ? self::message($login) : 'Registry login failed.');
            }
            $loggedIn = true;
            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try { return $operation($epp); } finally { restore_error_handler(); }
        } finally {
            if ($epp !== null && $loggedIn) { try { $epp->logout([]); } catch (Throwable) {} }
            if ($epp !== null && $connected) { try { $epp->disconnect(); } catch (Throwable) {} }
            foreach ($secretFiles as $file) { if (is_string($file) && is_file($file)) { @unlink($file); } }
        }
    }

    public static function reconcile(string $jobId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT j.*, r.profile, r.config_nonce, r.config_cipher, r.enabled
            FROM epp_jobs j JOIN registries r ON r.id = j.registry_id WHERE j.id = ? AND j.status = 'pending'");
        $stmt->execute([$jobId]); $job = $stmt->fetch();
        if ($job === false) { return ['ok' => true, 'status' => 'unchanged']; }
        $lockName = Queue::lockName($job['registry_id']); $locked = false;
        $error = 'Awaiting registry confirmation; original command has not been replayed.';
        $retry = true;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$lockName]);
            if ((string) $lock->fetchColumn() !== '1') { return ['ok' => true, 'status' => 'pending']; }
            $locked = true;
            if (!(bool) $job['enabled']) { throw new RuntimeException('Registry is disabled; reconcile after re-enabling it or verify manually.'); }
            $config = self::validateRegistryConfig($job['profile'], json_decode(Crypto::decrypt($job['config_nonce'], $job['config_cipher'], 'registry-config:' . $job['registry_id']), true, 32, JSON_THROW_ON_ERROR));
            $params = Ownership::params($job);
            if (!in_array($job['command'], ['domain.transfer', 'domain.delete', 'domain.restore', 'domain.restore_report', 'domain.create', 'domain.create_dnssec'], true)) {
                $retry = false;
                throw new RuntimeException('This pending command needs profile-specific operator reconciliation.');
            }
            $observation = self::withConnection($job['profile'], $config, static fn ($epp) => $job['command'] === 'domain.transfer'
                ? Util::normalize($epp->domainTransfer(array_replace($params, ['op' => 'query'])))
                : Rgp::info($epp, $job['profile'], $params));
            if (Lifecycle::settle($pdo, $job, $observation, $config['login']['clID'])) {
                Lifecycle::observeFollowup($pdo, $job, $observation, $config['login']['clID']);
                return ['ok' => true, 'status' => Ledger::fetchJob($pdo, $jobId)['status']];
            }
            if (isset($observation['error']) || (int) ($observation['code'] ?? 0) >= 2000) {
                $error = 'Registry observation did not confirm completion; funds remain held.';
            }
        } catch (Throwable $e) { $error = self::safeException($e); }
        finally {
            $interval = max(5, Config::int('API_RECONCILE_SECONDS', 30));
            if ($locked) { $pdo->prepare("UPDATE epp_jobs SET dispatch_until = NULL, reconcile_at = IF(? = 1, UTC_TIMESTAMP(6) + INTERVAL {$interval} SECOND, NULL), last_error = ? WHERE id = ? AND status = 'pending'")
                ->execute([(int) $retry, mb_substr($error, 0, 255), $jobId]); }
            if ($locked) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        }
        return ['ok' => true, 'status' => 'pending'];
    }

    public static function checkDeleted(string $registry, string $name, string $owner): array
    {
        $pdo = Db::pdo(); $lockName = Queue::lockName($registry); $locked = false;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$lockName]);
            if ((string) $lock->fetchColumn() !== '1') { return ['ok' => true, 'status' => 'busy']; }
            $locked = true;
            $stmt = $pdo->prepare("SELECT r.* FROM object_ownership o JOIN registries r ON r.id = o.registry_id
                WHERE o.registry_id = ? AND o.object_name = ? AND o.reseller_id = ? AND o.object_type = 'domain'
                  AND o.domain_state = 'deleted' AND o.job_id IS NULL AND r.enabled = 1");
            $stmt->execute([$registry, $name, $owner]); $row = $stmt->fetch();
            if ($row === false) { return ['ok' => true, 'status' => 'unchanged']; }
            $config = self::validateRegistryConfig($row['profile'], json_decode(Crypto::decrypt($row['config_nonce'], $row['config_cipher'], 'registry-config:' . $registry), true, 32, JSON_THROW_ON_ERROR));
            $info = self::withConnection($row['profile'], $config, static fn ($epp) => Rgp::info($epp, $row['profile'], ['domainname' => $name]));
            Lifecycle::observeDeleted($pdo, $registry, $name, $owner, $info);
            return ['ok' => true, 'status' => 'checked'];
        } catch (Throwable) { return ['ok' => false, 'status' => 'unconfirmed']; }
        finally {
            $pdo->prepare("UPDATE object_ownership SET check_until = NULL WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND reseller_id = ?")->execute([$registry, $name, $owner]);
            if ($locked) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
        }
    }

    public static function validateRegistryConfig(string $profile, array $config): array
    {
        $profile = CommandCatalog::normalizeProfile($profile);
        $connection = $config['connection'] ?? null;
        $login = $config['login'] ?? null;
        if (!is_array($connection) || !is_array($login)) {
            throw new RuntimeException('Registry config requires connection and login objects.');
        }

        $host = $connection['host'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 255 || preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            throw new RuntimeException('Registry connection.host is invalid.');
        }
        if (in_array($profile, ['GR', 'IT', 'PL'], true)) {
            $parts = parse_url($host);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException("Profile {$profile} requires a full https:// EPP endpoint URL.");
            }
        } elseif (str_contains($host, '://') || preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1) {
            throw new RuntimeException('TLS/TCP EPP profiles require a DNS hostname or IP without a URL scheme.');
        }

        $port = filter_var($connection['port'] ?? (in_array($profile, ['GR', 'IT', 'PL'], true) ? 443 : 700), FILTER_VALIDATE_INT);
        $timeout = filter_var($connection['timeout'] ?? 30, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535 || $timeout === false || $timeout < 3 || $timeout > 120) {
            throw new RuntimeException('Registry port or timeout is invalid.');
        }
        $tls = (string) ($connection['tls'] ?? '1.2');
        if (!in_array($tls, ['1.2', '1.3'], true)) {
            throw new RuntimeException('Only TLS 1.2 and TLS 1.3 are permitted.');
        }
        $verifyPeer = filter_var($connection['verify_peer'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $verifyName = filter_var($connection['verify_peer_name'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $selfSigned = filter_var($connection['allow_self_signed'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $bind = filter_var($connection['bind'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($verifyPeer === null || $verifyName === null || $selfSigned === null || $bind === null) {
            throw new RuntimeException('TLS verification options must be boolean.');
        }
        /*if (Config::production() && (!$verifyPeer || !$verifyName || $selfSigned)) {
            throw new RuntimeException('Production registry connections must verify the peer and hostname and reject self-signed certificates.');
        }*/

        foreach (['client_cert_pem', 'client_key_pem', 'ca_pem'] as $pemKey) {
            if (isset($connection[$pemKey]) && (!is_string($connection[$pemKey]) || strlen($connection[$pemKey]) > 2_000_000)) {
                throw new RuntimeException("Registry {$pemKey} is invalid.");
            }
        }
        if (isset($connection['client_cert_pem']) && !str_contains($connection['client_cert_pem'], 'BEGIN CERTIFICATE')) {
            throw new RuntimeException('client_cert_pem is not PEM encoded.');
        }
        if (isset($connection['client_key_pem']) && !str_contains($connection['client_key_pem'], 'PRIVATE KEY')) {
            throw new RuntimeException('client_key_pem is not PEM encoded.');
        }
        if (isset($connection['ca_pem']) && !str_contains($connection['ca_pem'], 'BEGIN CERTIFICATE')) {
            throw new RuntimeException('ca_pem is not PEM encoded.');
        }
        foreach (['cafile', 'local_cert', 'local_pk'] as $pathKey) {
            if (!empty($connection[$pathKey])) {
                throw new RuntimeException("Use encrypted PEM content instead of connection.{$pathKey} paths.");
            }
        }
        $passphrase = $connection['client_key_passphrase'] ?? $connection['passphrase'] ?? '';
        if (!is_string($passphrase) || strlen($passphrase) > 1024 || str_contains($passphrase, "\0")) {
            throw new RuntimeException('Registry client key passphrase is invalid.');
        }
        $hasCertificate = !empty($connection['client_cert_pem']);
        $hasPrivateKey = !empty($connection['client_key_pem']);
        if ($hasCertificate !== $hasPrivateKey) {
            throw new RuntimeException('Registry client certificate and private key PEM must be supplied together.');
        }
        if (!empty($connection['ca_pem']) && openssl_x509_read($connection['ca_pem']) === false) {
            throw new RuntimeException('Registry CA PEM could not be parsed.');
        }
        if ($hasCertificate) {
            $certificate = openssl_x509_read($connection['client_cert_pem']);
            $privateKey = openssl_pkey_get_private($connection['client_key_pem'], $passphrase);
            if ($certificate === false || $privateKey === false) {
                throw new RuntimeException('Registry client certificate or private key could not be parsed.');
            }
            if (!openssl_x509_check_private_key($certificate, $privateKey)) {
                throw new RuntimeException('Registry client certificate and private key do not match.');
            }
        }

        $clId = $login['clID'] ?? null;
        $password = $login['pw'] ?? null;
        if (array_key_exists('newpw', $login)) {
            throw new RuntimeException('Registry password changes are not allowed through stored login configuration.');
        }
        if (
            !is_string($clId) || $clId === '' || strlen($clId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $clId) === 1
            || !is_string($password) || $password === '' || strlen($password) > 1024
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password) === 1 || str_contains($password, ']]>')
        ) {
            throw new RuntimeException('Registry login.clID or login.pw is invalid.');
        }
        $prefix = (string) ($login['prefix'] ?? 'api');
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $prefix) !== 1) {
            throw new RuntimeException('Registry login.prefix is invalid.');
        }

        $connection['host'] = $host;
        $connection['port'] = (int) $port;
        $connection['timeout'] = (int) $timeout;
        $connection['tls'] = $tls;
        $bindIp = (string) ($connection['bindip'] ?? '');
        if (strlen($bindIp) > 128 || preg_match('/[\x00-\x20\x7F]/', $bindIp) === 1) {
            throw new RuntimeException('Registry bindip is invalid.');
        }
        $connection['bind'] = $bind;
        $connection['bindip'] = $bindIp;
        $connection['verify_peer'] = $verifyPeer;
        $connection['verify_peer_name'] = $verifyName;
        $connection['allow_self_signed'] = $selfSigned;
        $connection['cafile'] = (string) ($connection['cafile'] ?? '');
        $connection['local_cert'] = (string) ($connection['local_cert'] ?? '');
        $connection['local_pk'] = (string) ($connection['local_pk'] ?? '');
        $connection['passphrase'] = $passphrase;
        $login['prefix'] = $prefix;

        $config['connection'] = $connection;
        $config['login'] = $login;
        foreach (['loginObjects', 'loginExtensions'] as $key) {
            if (isset($config[$key])) {
                if (!is_array($config[$key]) || count($config[$key]) > 64) {
                    throw new RuntimeException("Registry {$key} must be a short array.");
                }
                foreach ($config[$key] as $uri) {
                    if (!is_string($uri) || strlen($uri) > 512 || preg_match('/[\x00-\x20\x7F]/', $uri) === 1) {
                        throw new RuntimeException("Registry {$key} contains an invalid URI.");
                    }
                }
            }
        }
        return $config;
    }

    private static function prepareConnection(array $connection): array
    {
        $files = [];
        if (!empty($connection['ca_pem'])) {
            $connection['cafile'] = self::writeSecret($connection['ca_pem'], $files);
        }
        if (!empty($connection['client_cert_pem'])) {
            $connection['local_cert'] = self::writeSecret($connection['client_cert_pem'], $files);
        }
        if (!empty($connection['client_key_pem'])) {
            $connection['local_pk'] = self::writeSecret($connection['client_key_pem'], $files);
        }
        unset($connection['ca_pem'], $connection['client_cert_pem'], $connection['client_key_pem'], $connection['client_key_passphrase']);
        return [$connection, $files];
    }

    private static function enforceTransportTls(object $epp, string $profile, string $minimumTls, int $timeout): void
    {
        if (in_array($profile, ['GR', 'IT', 'PL'], true)) {
            $handle = self::objectProperty($epp, 'ch');
            if (!$handle instanceof \CurlHandle) {
                throw new RuntimeException('The HTTPS EPP transport did not initialize cURL.');
            }
            $version = $minimumTls === '1.3'
                ? (defined('CURL_SSLVERSION_TLSv1_3') ? constant('CURL_SSLVERSION_TLSv1_3') : null)
                : CURL_SSLVERSION_TLSv1_2;
            if ($version === null || !curl_setopt($handle, CURLOPT_SSLVERSION, $version)) {
                throw new RuntimeException("The HTTPS EPP transport cannot enforce TLS {$minimumTls} or newer.");
            }
            return;
        }

        $resource = self::objectProperty($epp, 'resource');
        if (!is_resource($resource)) {
            throw new RuntimeException('The EPP TLS stream was not initialized.');
        }
        stream_set_timeout($resource, $timeout);
        $metadata = stream_get_meta_data($resource);
        $protocol = (string) ($metadata['crypto']['protocol'] ?? '');
        if (!in_array($protocol, ['TLSv1.2', 'TLSv1.3'], true) || ($minimumTls === '1.3' && $protocol !== 'TLSv1.3')) {
            throw new RuntimeException('The registry negotiated a disallowed TLS protocol.');
        }
    }

    private static function objectProperty(object $object, string $name): mixed
    {
        $reflection = new \ReflectionClass($object);
        do {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                return $property->getValue($object);
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);
        throw new RuntimeException("Pinned EPP transport property {$name} is unavailable.");
    }

    private static function writeSecret(string $contents, array &$files): string
    {
        $directory = Config::get('RUNTIME_DIR', '/run/namingo-epp-api') ?? '/run/namingo-epp-api';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the runtime credential directory.');
        }
        $path = tempnam($directory, 'cred-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate a runtime credential file.');
        }
        chmod($path, 0600);
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Cannot write a runtime credential file.');
        }
        $files[] = $path;
        return $path;
    }

    private static function message(array $result): string
    {
        $value = $result['error'] ?? $result['msg'] ?? $result['message'] ?? 'Registry rejected the command.';
        if (is_array($value)) {
            $value = implode(' ', array_map(static fn (mixed $part): string => (string) $part, $value));
        }
        return mb_substr(trim((string) $value), 0, 1000);
    }

    private static function safeException(Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'The registry operation failed.';
        }
        return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? 'The registry operation failed.', 0, 1000);
    }
}

final class RegistryBusyException extends RuntimeException
{
}

final class RegistryLoginException extends RuntimeException
{
}
