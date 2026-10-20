<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Config
{
    private static bool $loaded = false;

    public static function load(string $root): void
    {
        if (self::$loaded) {
            return;
        }

        $file = $root . '/.env';
        if (is_file($file)) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
        self::$loaded = true;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return $value === false || $value === null || $value === '' ? $default : (string) $value;
    }

    public static function required(string $name): string
    {
        $value = self::get($name);
        if ($value === null) {
            throw new RuntimeException("Missing required environment variable {$name}");
        }
        return $value;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("{$name} must be an integer");
        }
        return (int) $value;
    }

    public static function float(string $name, float $default): float
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException("{$name} must be numeric");
        }
        return (float) $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException("{$name} must be true or false");
        }
        return $parsed;
    }

    public static function production(): bool
    {
        return strtolower(self::get('APP_ENV', 'production') ?? 'production') === 'production';
    }
}

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}

final class Util
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ApiException(400, 'invalid_json', 'The JSON body must be an object.');
        }
        return $value;
    }

    public static function canonicalJson(array $value): string
    {
        return self::json(self::sortRecursive($value));
    }

    private static function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sortRecursive($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sortRecursive($item);
            }
        }
        unset($item);
        return $value;
    }

    public static function money(mixed $value): string
    {
        $raw = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,4}))?$/D', $raw, $matches) !== 1) {
            throw new ApiException(422, 'invalid_amount', 'Amount must be a positive decimal with at most four decimal places.');
        }
        $fraction = str_pad($matches[2] ?? '', 4, '0');
        $normal = $matches[1] . '.' . $fraction;
        if (str_replace(['0', '.'], '', $normal) === '') {
            throw new ApiException(422, 'invalid_amount', 'Amount must be greater than zero.');
        }
        return $normal;
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \SimpleXMLElement) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false || $encoded === '{}') {
                return (string) $value;
            }
            return json_decode($encoded, true);
        }
        if (is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $encoded === false ? (string) $value : json_decode($encoded, true);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }
        }
        return $value;
    }

    public static function compareMoney(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftWhole = ltrim($leftWhole, '0') ?: '0';
        $rightWhole = ltrim($rightWhole, '0') ?: '0';
        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) <=> strlen($rightWhole);
        }
        $wholeComparison = strcmp($leftWhole, $rightWhole);
        if ($wholeComparison !== 0) {
            return $wholeComparison <=> 0;
        }
        return strcmp(str_pad($leftFraction, 4, '0'), str_pad($rightFraction, 4, '0')) <=> 0;
    }

    public static function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}

final class Db
{
    private static ?PDO $pdo = null;

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $socket = Config::get('DB_SOCKET');
        $dbHost = Config::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $dsn = $socket !== null
            ? 'mysql:unix_socket=' . $socket . ';dbname=' . Config::required('DB_NAME') . ';charset=utf8mb4'
            : 'mysql:host=' . $dbHost . ';port=' . Config::int('DB_PORT', 3306)
                . ';dbname=' . Config::required('DB_NAME') . ';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_PERSISTENT => false,
        ];
        $ca = Config::get('DB_TLS_CA');
        if (Config::production() && $socket === null && !in_array($dbHost, ['127.0.0.1', '::1', 'localhost'], true) && $ca === null) {
            throw new RuntimeException('A remote production database requires DB_TLS_CA.');
        }
        if ($ca !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            }
        }

        self::$pdo = new PDO($dsn, Config::required('DB_USER'), Config::required('DB_PASSWORD'), $options);
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return self::$pdo;
    }
}

final class Crypto
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $encoded = Config::required('APP_MASTER_KEY');
        if (!str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('APP_MASTER_KEY must start with base64:');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('APP_MASTER_KEY must contain exactly 32 random bytes.');
        }
        self::$key = $key;
        return $key;
    }

    public static function encrypt(string $plaintext, string $aad): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, self::key());
        return [$nonce, $cipher];
    }

    public static function decrypt(string $nonce, string $cipher, string $aad): string
    {
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $aad, $nonce, self::key());
        if ($plain === false) {
            throw new RuntimeException('Encrypted data failed authentication.');
        }
        return $plain;
    }

    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, self::key(), true);
    }
}

final class Audit
{
    public static function record(
        PDO $pdo,
        ?string $resellerId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $ip = null,
        array $metadata = [],
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_events (reseller_id, action, target_type, target_id, ip_hash, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $resellerId,
                $action,
                $targetType,
                $targetId,
                $ip === null ? null : Crypto::hmac($ip),
                $metadata === [] ? null : Util::json($metadata),
            ]);
        } catch (Throwable) {
            // Audit failure must not corrupt the transaction that was already completed.
        }
    }
}

final class Auth
{
    public static function login(PDO $pdo, string $username, string $password, string $ip): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D', $username) !== 1 || strlen($password) > 1024) {
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM resellers WHERE username = ? FOR UPDATE');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            static $dummyHash;
            $dummyHash ??= password_hash(Util::token(), PASSWORD_ARGON2ID);
            $valid = password_verify($password, $user['password_hash'] ?? $dummyHash);
            $now = Util::utcNow();
            $locked = $user !== false && $user['locked_until'] !== null && $user['locked_until'] > $now;
            $active = $user !== false && $user['status'] === 'active';

            if (!$valid || !$active || $locked) {
                if ($user !== false) {
                    $failures = (int) $user['failed_logins'] + 1;
                    $lockUntil = null;
                    if ($failures >= Config::int('AUTH_MAX_FAILURES', 5)) {
                        $lockUntil = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                            ->add(new DateInterval('PT' . Config::int('AUTH_LOCK_SECONDS', 900) . 'S'))
                            ->format('Y-m-d H:i:s.u');
                        $failures = 0;
                    }
                    $update = $pdo->prepare('UPDATE resellers SET failed_logins = ?, locked_until = ? WHERE id = ?');
                    $update->execute([$failures, $lockUntil, $user['id']]);
                }
                $pdo->commit();
                Audit::record($pdo, $user['id'] ?? null, 'auth.login_failed', 'reseller', $user['id'] ?? null, $ip);
                throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
            }

            if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
                $rehash = $pdo->prepare('UPDATE resellers SET password_hash = ? WHERE id = ?');
                $rehash->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
            }
            $reset = $pdo->prepare('UPDATE resellers SET failed_logins = 0, locked_until = NULL WHERE id = ?');
            $reset->execute([$user['id']]);

            $token = Util::token();
            $tokenId = Util::uuid();
            $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->add(new DateInterval('PT' . Config::int('TOKEN_TTL_SECONDS', 43200) . 'S'));
            $insert = $pdo->prepare(
                'INSERT INTO auth_tokens (id, reseller_id, token_hash, expires_at) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$tokenId, $user['id'], hash('sha256', $token, true), $expires->format('Y-m-d H:i:s.u')]);
            $pdo->commit();
            Audit::record($pdo, $user['id'], 'auth.login_succeeded', 'token', $tokenId, $ip);

            return [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => Config::int('TOKEN_TTL_SECONDS', 43200),
                'reseller_uuid' => $user['id'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function authenticate(PDO $pdo, ?string $authorization): array
    {
        if ($authorization === null || preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', trim($authorization), $matches) !== 1) {
            throw new ApiException(401, 'unauthorized', 'A valid bearer token is required.');
        }
        $stmt = $pdo->prepare(
            "SELECT t.id AS token_id, r.id, r.username, r.currency, w.available_balance, w.held_balance
             FROM auth_tokens t
             JOIN resellers r ON r.id = t.reseller_id
             JOIN wallets w ON w.reseller_id = r.id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6)
               AND r.status = 'active' LIMIT 1"
        );
        $stmt->execute([hash('sha256', $matches[1], true)]);
        $user = $stmt->fetch();
        if ($user === false) {
            throw new ApiException(401, 'unauthorized', 'The bearer token is invalid or expired.');
        }
        $touch = $pdo->prepare('UPDATE auth_tokens SET last_used_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $touch->execute([$user['token_id']]);
        return $user;
    }

    public static function revoke(PDO $pdo, string $tokenId): void
    {
        $stmt = $pdo->prepare('UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$tokenId]);
    }
}

final class CommandCatalog
{
    public const GLOBAL_PRICE_UUID = '00000000-0000-0000-0000-000000000000';

    private const COMMANDS = [
        'contact.check' => 'contactCheck',
        'contact.create' => 'contactCreate',
        'contact.delete' => 'contactDelete',
        'contact.info' => 'contactInfo',
        'contact.update' => 'contactUpdate',
        'contact.update_authinfo' => 'contactUpdateAuthinfo',
        'domain.check' => 'domainCheck',
        'domain.create' => 'domainCreate',
        'domain.create_dnssec' => 'domainCreateDNSSEC',
        'domain.delete' => 'domainDelete',
        'domain.info' => 'domainInfo',
        'domain.renew' => 'domainRenew',
        'domain.restore' => 'domainRestore',
        'domain.transfer' => 'domainTransfer',
        'domain.update_authinfo' => 'domainUpdateAuthinfo',
        'domain.update_contact' => 'domainUpdateContact',
        'domain.update_dnssec' => 'domainUpdateDNSSEC',
        'domain.update_ns' => 'domainUpdateNS',
        'domain.update_status' => 'domainUpdateStatus',
        'hello' => 'hello',
        'host.check' => 'hostCheck',
        'host.create' => 'hostCreate',
        'host.delete' => 'hostDelete',
        'host.info' => 'hostInfo',
        'host.update' => 'hostUpdate',
    ];

    private const MUTATING = [
        'contact.create', 'contact.delete', 'contact.update', 'contact.update_authinfo',
        'domain.create', 'domain.create_dnssec', 'domain.delete', 'domain.renew', 'domain.restore',
        'domain.transfer', 'domain.update_authinfo', 'domain.update_contact', 'domain.update_dnssec',
        'domain.update_ns', 'domain.update_status', 'host.create', 'host.delete', 'host.update',
    ];

    private const BASE_REQUIRED = [
        'contact.check' => ['contact'],
        'contact.delete' => ['contact'],
        'contact.info' => ['contact'],
        'contact.update_authinfo' => ['contactid', 'authInfo'],
        'domain.check' => ['domains'],
        'domain.create' => ['domainname', 'period'],
        'domain.create_dnssec' => ['domainname', 'period', 'dnssec_records'],
        'domain.delete' => ['domainname'],
        'domain.info' => ['domainname'],
        'domain.renew' => ['domainname', 'regperiod'],
        'domain.restore' => ['domainname'],
        'domain.transfer' => ['domainname'],
        'domain.update_authinfo' => ['domainname', 'authInfo'],
        'domain.update_contact' => ['domainname'],
        'domain.update_dnssec' => ['domainname', 'command'],
        'domain.update_ns' => ['domainname'],
        'domain.update_status' => ['domainname', 'command', 'status'],
        'host.check' => ['hostname'],
        'host.create' => ['hostname'],
        'host.delete' => ['hostname'],
        'host.info' => ['hostname'],
    ];

    private const PROFILES = [
        'generic', 'EE', 'EU', 'FI', 'FR', 'FRED', 'GE', 'GR', 'HK', 'HR', 'IS', 'IT',
        'LT', 'LV', 'MX', 'NO', 'PL', 'PT', 'SE', 'UA', 'VRSN',
    ];

    public static function method(string $command): string
    {
        return self::COMMANDS[$command]
            ?? throw new ApiException(404, 'unsupported_command', 'The requested EPP command is not exposed.');
    }

    public static function isMutating(string $command): bool
    {
        self::method($command);
        return in_array($command, self::MUTATING, true);
    }

    public static function normalizeProfile(string $profile): string
    {
        $profile = strtolower($profile) === 'generic' ? 'generic' : strtoupper($profile);
        if (!in_array($profile, self::PROFILES, true)) {
            throw new ApiException(422, 'invalid_profile', 'Unknown Namingo EPP registry profile.');
        }
        return $profile;
    }

    public static function profiles(): array
    {
        return self::PROFILES;
    }

    public static function all(): array
    {
        $out = [];
        foreach (self::COMMANDS as $command => $method) {
            $out[] = [
                'command' => $command,
                'upstream_method' => $method,
                'mutating' => self::isMutating($command),
                'base_required_params' => self::BASE_REQUIRED[$command] ?? [],
            ];
        }
        return $out;
    }

    public static function validateParams(string $command, array $params): void
    {
        self::method($command);
        if ($params !== [] && array_is_list($params)) {
            throw new ApiException(422, 'invalid_params', 'params must be a JSON object.');
        }
        foreach (self::BASE_REQUIRED[$command] ?? [] as $key) {
            if (!array_key_exists($key, $params)) {
                throw new ApiException(422, 'missing_parameter', "Missing required base parameter: {$key}", ['parameter' => $key]);
            }
        }
        self::validateNode($params, 0);
    }

    private static function validateNode(array $value, int $depth): void
    {
        if ($depth > 8) {
            throw new ApiException(422, 'params_too_deep', 'params nesting may not exceed eight levels.');
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $key) !== 1) {
                throw new ApiException(422, 'invalid_parameter_name', 'Parameter names must contain 1-64 safe ASCII characters.');
            }
            if (
                is_string($item)
                && (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $item) === 1 || str_contains($item, '<') || str_contains($item, '>'))
            ) {
                throw new ApiException(422, 'invalid_parameter', 'EPP parameter strings may not contain XML markup or control bytes.');
            }
            if (is_array($item)) {
                self::validateNode($item, $depth + 1);
            }
        }
    }
}

final class Ledger
{
    public static function createJob(
        PDO $pdo,
        string $resellerId,
        string $registryId,
        string $command,
        string $idempotencyKey,
        array $params,
    ): array {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $idempotencyKey) !== 1) {
            throw new ApiException(422, 'invalid_idempotency_key', 'Idempotency-Key must contain 8-128 safe ASCII characters.');
        }

        $request = ['registry_uuid' => $registryId, 'command' => $command, 'params' => $params];
        $requestJson = Util::canonicalJson($request);
        $requestHash = Crypto::hmac($requestJson);

        try {
            $pdo->beginTransaction();
            $existing = self::findByIdempotency($pdo, $resellerId, $idempotencyKey, true);
            if ($existing !== false) {
                self::assertSameRequest($existing, $registryId, $command, $requestHash);
                $pdo->commit();
                return ['existing' => true, 'job_id' => $existing['id'], 'status' => $existing['status']];
            }

            $access = $pdo->prepare(
                'SELECT r.id FROM registries r JOIN reseller_registries rr ON rr.registry_id = r.id
                 WHERE r.id = ? AND rr.reseller_id = ? AND r.enabled = 1 AND rr.enabled = 1 LIMIT 1'
            );
            $access->execute([$registryId, $resellerId]);
            if ($access->fetchColumn() === false) {
                throw new ApiException(404, 'registry_not_found', 'Registry is unavailable for this reseller.');
            }

            $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE reseller_id = ? FOR UPDATE');
            $walletStmt->execute([$resellerId]);
            $wallet = $walletStmt->fetch();
            if ($wallet === false) {
                throw new RuntimeException('Wallet is missing.');
            }
            [$price, $currency] = self::price($pdo, $registryId, $resellerId, $command, $wallet['currency']);
            if ($currency !== $wallet['currency']) {
                throw new ApiException(409, 'currency_mismatch', 'Registry price and wallet currencies do not match.');
            }
            if (Util::compareMoney($wallet['available_balance'], $price) < 0) {
                throw new ApiException(402, 'insufficient_funds', 'Available balance is insufficient.', [
                    'available' => $wallet['available_balance'],
                    'required' => $price,
                    'currency' => $currency,
                ]);
            }

            $jobId = Util::uuid();
            [$nonce, $cipher] = Crypto::encrypt($requestJson, 'job-request:' . $jobId);
            $insert = $pdo->prepare(
                'INSERT INTO epp_jobs
                 (id, reseller_id, registry_id, command, idempotency_key, request_hash, request_nonce, request_cipher, price, currency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$jobId, $resellerId, $registryId, $command, $idempotencyKey, $requestHash, $nonce, $cipher, $price, $currency]);

            if ($price !== '0.0000') {
                $update = $pdo->prepare(
                    'UPDATE wallets SET available_balance = available_balance - ?, held_balance = held_balance + ?
                     WHERE reseller_id = ? AND available_balance >= ?'
                );
                $update->execute([$price, $price, $resellerId, $price]);
                if ($update->rowCount() !== 1) {
                    throw new ApiException(402, 'insufficient_funds', 'Available balance is insufficient.');
                }
                self::entry($pdo, $resellerId, $jobId, null, 'hold', '-' . $price, $price, $currency, 'Reserve for ' . $command);
            }
            $pdo->commit();
            return ['existing' => false, 'job_id' => $jobId, 'status' => 'queued'];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $existing = self::findByIdempotency($pdo, $resellerId, $idempotencyKey, false);
                if ($existing !== false) {
                    self::assertSameRequest($existing, $registryId, $command, $requestHash);
                    return ['existing' => true, 'job_id' => $existing['id'], 'status' => $existing['status']];
                }
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function completeJob(PDO $pdo, string $jobId, string $status, array $result, ?int $eppCode): void
    {
        if (!in_array($status, ['succeeded', 'rejected', 'unknown'], true)) {
            throw new RuntimeException('Invalid final job status.');
        }
        $result = Util::normalize($result);
        [$nonce, $cipher] = Crypto::encrypt(Util::json($result), 'job-response:' . $jobId);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM epp_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            if ($job === false) {
                throw new RuntimeException('Job not found.');
            }
            if (in_array($job['status'], ['succeeded', 'rejected', 'unknown'], true)) {
                $pdo->commit();
                return;
            }

            if ($job['price'] !== '0.0000' && $status !== 'unknown') {
                $wallet = $pdo->prepare('SELECT * FROM wallets WHERE reseller_id = ? FOR UPDATE');
                $wallet->execute([$job['reseller_id']]);
                if ($wallet->fetch() === false) {
                    throw new RuntimeException('Wallet is missing.');
                }
                if ($status === 'succeeded') {
                    $update = $pdo->prepare('UPDATE wallets SET held_balance = held_balance - ? WHERE reseller_id = ? AND held_balance >= ?');
                    $update->execute([$job['price'], $job['reseller_id'], $job['price']]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Held balance invariant failed.');
                    }
                    self::entry($pdo, $job['reseller_id'], $jobId, null, 'charge', '0.0000', '-' . $job['price'], $job['currency'], 'Charge for ' . $job['command']);
                } else {
                    $update = $pdo->prepare(
                        'UPDATE wallets SET available_balance = available_balance + ?, held_balance = held_balance - ?
                         WHERE reseller_id = ? AND held_balance >= ?'
                    );
                    $update->execute([$job['price'], $job['price'], $job['reseller_id'], $job['price']]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Held balance invariant failed.');
                    }
                    self::entry($pdo, $job['reseller_id'], $jobId, null, 'release', $job['price'], '-' . $job['price'], $job['currency'], 'Release for ' . $job['command']);
                }
            }

            $updateJob = $pdo->prepare(
                'UPDATE epp_jobs SET status = ?, epp_code = ?, response_nonce = ?, response_cipher = ?, finished_at = UTC_TIMESTAMP(6)
                 WHERE id = ?'
            );
            $updateJob->execute([$status, $eppCode, $nonce, $cipher, $jobId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function fetchJob(PDO $pdo, string $jobId, ?string $resellerId = null): array
    {
        $sql = 'SELECT j.*, r.name AS registry_name, r.profile FROM epp_jobs j JOIN registries r ON r.id = j.registry_id WHERE j.id = ?';
        $args = [$jobId];
        if ($resellerId !== null) {
            $sql .= ' AND j.reseller_id = ?';
            $args[] = $resellerId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        $job = $stmt->fetch();
        if ($job === false) {
            throw new ApiException(404, 'job_not_found', 'Job was not found.');
        }
        $response = null;
        if ($job['response_nonce'] !== null && $job['response_cipher'] !== null) {
            $response = json_decode(
                Crypto::decrypt($job['response_nonce'], $job['response_cipher'], 'job-response:' . $job['id']),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        }
        return [
            'job_uuid' => $job['id'],
            'registry_uuid' => $job['registry_id'],
            'registry_name' => $job['registry_name'],
            'profile' => $job['profile'],
            'command' => $job['command'],
            'status' => $job['status'],
            'epp_code' => $job['epp_code'] === null ? null : (int) $job['epp_code'],
            'price' => $job['price'],
            'currency' => $job['currency'],
            'created_at' => $job['created_at'],
            'started_at' => $job['started_at'],
            'finished_at' => $job['finished_at'],
            'result' => $response,
        ];
    }

    public static function resolveUnknown(PDO $pdo, string $jobId, string $action): void
    {
        if (!in_array($action, ['charge', 'release'], true)) {
            throw new RuntimeException('Resolution must be charge or release.');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM epp_jobs WHERE id = ? FOR UPDATE');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            if ($job === false || $job['status'] !== 'unknown') {
                throw new RuntimeException('Only an unknown job can be resolved.');
            }
            if ($job['price'] !== '0.0000') {
                $wallet = $pdo->prepare('SELECT * FROM wallets WHERE reseller_id = ? FOR UPDATE');
                $wallet->execute([$job['reseller_id']]);
                if ($wallet->fetch() === false) {
                    throw new RuntimeException('Wallet is missing.');
                }
                if ($action === 'charge') {
                    $update = $pdo->prepare('UPDATE wallets SET held_balance = held_balance - ? WHERE reseller_id = ? AND held_balance >= ?');
                    $update->execute([$job['price'], $job['reseller_id'], $job['price']]);
                    self::entry($pdo, $job['reseller_id'], $jobId, null, 'charge', '0.0000', '-' . $job['price'], $job['currency'], 'Manual reconciliation charge');
                } else {
                    $update = $pdo->prepare(
                        'UPDATE wallets SET available_balance = available_balance + ?, held_balance = held_balance - ?
                         WHERE reseller_id = ? AND held_balance >= ?'
                    );
                    $update->execute([$job['price'], $job['price'], $job['reseller_id'], $job['price']]);
                    self::entry($pdo, $job['reseller_id'], $jobId, null, 'release', $job['price'], '-' . $job['price'], $job['currency'], 'Manual reconciliation release');
                }
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Held balance invariant failed.');
                }
            }
            $status = $action === 'charge' ? 'succeeded' : 'rejected';
            $originalResult = null;
            if ($job['response_nonce'] !== null && $job['response_cipher'] !== null) {
                $originalResult = json_decode(
                    Crypto::decrypt($job['response_nonce'], $job['response_cipher'], 'job-response:' . $jobId),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
            }
            [$nonce, $cipher] = Crypto::encrypt(Util::json([
                'manual_resolution' => $action,
                'original_result' => $originalResult,
            ]), 'job-response:' . $jobId);
            $finish = $pdo->prepare(
                'UPDATE epp_jobs SET status = ?, response_nonce = ?, response_cipher = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ?'
            );
            $finish->execute([$status, $nonce, $cipher, $jobId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function approveDeposit(PDO $pdo, string $depositId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM deposit_requests WHERE id = ? FOR UPDATE');
            $stmt->execute([$depositId]);
            $deposit = $stmt->fetch();
            if ($deposit === false || $deposit['status'] !== 'pending') {
                throw new RuntimeException('Pending deposit not found.');
            }
            $wallet = $pdo->prepare('SELECT * FROM wallets WHERE reseller_id = ? FOR UPDATE');
            $wallet->execute([$deposit['reseller_id']]);
            $row = $wallet->fetch();
            if ($row === false || $row['currency'] !== $deposit['currency']) {
                throw new RuntimeException('Deposit and wallet currencies do not match.');
            }
            $update = $pdo->prepare('UPDATE wallets SET available_balance = available_balance + ? WHERE reseller_id = ?');
            $update->execute([$deposit['amount'], $deposit['reseller_id']]);
            self::entry($pdo, $deposit['reseller_id'], null, $depositId, 'deposit', $deposit['amount'], '0.0000', $deposit['currency'], 'Approved deposit');
            $approve = $pdo->prepare("UPDATE deposit_requests SET status = 'approved', decided_at = UTC_TIMESTAMP(6) WHERE id = ?");
            $approve->execute([$depositId]);
            $pdo->commit();
            return $deposit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function rejectDeposit(PDO $pdo, string $depositId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE deposit_requests SET status = 'rejected', decided_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$depositId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Pending deposit not found.');
        }
    }

    private static function price(PDO $pdo, string $registryId, string $resellerId, string $command, string $walletCurrency): array
    {
        $stmt = $pdo->prepare(
            'SELECT amount, currency FROM registry_prices
             WHERE registry_id = ? AND command = ? AND reseller_id IN (?, ?)
             ORDER BY CASE WHEN reseller_id = ? THEN 0 ELSE 1 END LIMIT 1'
        );
        $stmt->execute([$registryId, $command, $resellerId, CommandCatalog::GLOBAL_PRICE_UUID, $resellerId]);
        $price = $stmt->fetch();
        return $price === false ? ['0.0000', $walletCurrency] : [$price['amount'], $price['currency']];
    }

    private static function findByIdempotency(PDO $pdo, string $resellerId, string $key, bool $lock): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, registry_id, command, request_hash, status FROM epp_jobs
             WHERE reseller_id = ? AND idempotency_key = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$resellerId, $key]);
        return $stmt->fetch();
    }

    private static function assertSameRequest(array $job, string $registryId, string $command, string $hash): void
    {
        if ($job['registry_id'] !== $registryId || $job['command'] !== $command || !hash_equals($job['request_hash'], $hash)) {
            throw new ApiException(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different request.');
        }
    }

    private static function entry(
        PDO $pdo,
        string $resellerId,
        ?string $jobId,
        ?string $depositId,
        string $kind,
        string $availableDelta,
        string $heldDelta,
        string $currency,
        string $description,
    ): void {
        $wallet = $pdo->prepare('SELECT available_balance, held_balance FROM wallets WHERE reseller_id = ?');
        $wallet->execute([$resellerId]);
        $after = $wallet->fetch();
        if ($after === false) {
            throw new RuntimeException('Wallet is missing.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO ledger_entries
             (id, reseller_id, job_id, deposit_id, kind, available_delta, held_delta, available_after, held_after, currency, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            Util::uuid(), $resellerId, $jobId, $depositId, $kind, $availableDelta, $heldDelta,
            $after['available_balance'], $after['held_balance'], $currency, $description,
        ]);
    }
}
