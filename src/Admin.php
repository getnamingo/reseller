<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;
use Throwable;

final class Admin
{
    public static function perform(PDO $pdo, string $actor, string $action, array $input, string $ip): string
    {
        $admin = $pdo->prepare("SELECT 1 FROM resellers WHERE id = ? AND role = 0 AND status = 'active'");
        $admin->execute([$actor]);
        if (!$admin->fetchColumn()) {
            throw new ApiException(403, 'admin_required', 'Administrator access is required.');
        }
        $get = static fn (string $key, string $default = ''): string => is_string($input[$key] ?? $default)
            ? trim($input[$key] ?? $default) : throw new ApiException(422, 'invalid_input', 'Invalid form value.');
        $id = static function (string $key) use ($get): string {
            $value = strtolower($get($key));
            if (!Util::isUuid($value)) {
                throw new ApiException(422, 'invalid_uuid', "{$key} must be a reseller application UUID.");
            }
            return $value;
        };
        $flag = static function () use ($get): int {
            $value = $get('enabled');
            if (!in_array($value, ['0', '1'], true)) {
                throw new ApiException(422, 'invalid_state', 'Choose enabled or disabled.');
            }
            return (int) $value;
        };
        $password = static function () use ($input): string {
            $value = $input['password'] ?? null;
            if (!is_string($value) || strlen($value) < 14 || strlen($value) > 1024
                || !is_string($input['password_confirm'] ?? null) || !hash_equals($value, $input['password_confirm'])) {
                throw new ApiException(422, 'invalid_password', 'Passwords must match and contain 14–1024 bytes.');
            }
            return password_hash($value, PASSWORD_ARGON2ID);
        };
        $target = null;
        $metadata = [];
        // Ledger methods own their transactions and preserve the CLI's reconciliation rules.
        if (in_array($action, ['deposit.approve', 'deposit.reject', 'job.resolve', 'job.mark_unknown'], true)) {
            if ($get('confirmed') !== '1') {
                throw new ApiException(422, 'confirmation_required', 'Confirm that the funds or registry outcome were independently verified.');
            }
            $target = $id('id');
            if ($action === 'deposit.approve') {
                Ledger::approveDeposit($pdo, $target);
            } elseif ($action === 'deposit.reject') {
                Ledger::rejectDeposit($pdo, $target);
            } elseif ($action === 'job.resolve') {
                Ledger::resolveUnknown($pdo, $target, $get('resolution'));
                $metadata['resolution'] = $get('resolution');
            } else {
                if (Ledger::fetchJob($pdo, $target)['status'] !== 'processing') {
                    throw new ApiException(409, 'invalid_job_state', 'Only a processing job may be marked unknown.');
                }
                Ledger::completeJob($pdo, $target, 'unknown', ['error' => 'operator_marked_unknown'], null);
            }
        } else {
            $pdo->beginTransaction();
            try {
                switch ($action) {
                    case 'user.create':
                        $username = $get('username');
                        $currency = strtoupper($get('currency'));
                        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D', $username) !== 1
                            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                            throw new ApiException(422, 'invalid_user', 'Use a 3–64 character username and a three-letter currency.');
                        }
                        $target = Util::uuid();
                        $pdo->prepare('INSERT INTO resellers (id, username, password_hash, currency) VALUES (?, ?, ?, ?)')
                            ->execute([$target, $username, $password(), $currency]);
                        $pdo->prepare('INSERT INTO wallets (reseller_id, currency) VALUES (?, ?)')->execute([$target, $currency]);
                        break;
                    case 'user.password':
                        $target = $id('reseller_id');
                        $stmt = $pdo->prepare('UPDATE resellers SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?');
                        $stmt->execute([$password(), $target]);
                        if ($stmt->rowCount() !== 1) {
                            throw new ApiException(404, 'user_not_found', 'Reseller was not found.');
                        }
                        $pdo->prepare('UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE reseller_id = ? AND revoked_at IS NULL')->execute([$target]);
                        break;
                    case 'user.status':
                    case 'user.role':
                        $target = $id('reseller_id');
                        $column = $action === 'user.role' ? 'role' : 'status';
                        $value = $get($column);
                        if (!in_array($value, $column === 'role' ? ['0', '1'] : ['active', 'suspended'], true)
                            || ($target === $actor && $value !== ($column === 'role' ? '0' : 'active'))) {
                            throw new ApiException(422, 'invalid_state', 'Invalid value or attempt to remove your own administrator access.');
                        }
                        $pdo->prepare("UPDATE resellers SET {$column} = ? WHERE id = ?")->execute([$value, $target]);
                        if ($value === 'suspended') {
                            $pdo->prepare('UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE reseller_id = ? AND revoked_at IS NULL')->execute([$target]);
                        }
                        break;
                    case 'tokens.revoke':
                        $target = $id('reseller_id');
                        $pdo->prepare('UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE reseller_id = ? AND revoked_at IS NULL')->execute([$target]);
                        break;
                    case 'registry.save':
                        $profile = CommandCatalog::normalizeProfile($get('profile'));
                        $name = $get('name');
                        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                            throw new ApiException(422, 'invalid_name', 'Registry name must contain 1–128 printable characters.');
                        }
                        $config = EppWorker::validateRegistryConfig($profile, Util::decodeObject($get('config_json')));
                        $new = $get('registry_id') === '';
                        $target = $new ? Util::uuid() : $id('registry_id');
                        [$nonce, $cipher] = Crypto::encrypt(Util::json($config), 'registry-config:' . $target);
                        if ($new) {
                            $pdo->prepare('INSERT INTO registries (id, name, profile, config_nonce, config_cipher) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$target, $name, $profile, $nonce, $cipher]);
                        } else {
                            $pdo->prepare('UPDATE registries SET name = ?, profile = ?, config_nonce = ?, config_cipher = ? WHERE id = ?')
                                ->execute([$name, $profile, $nonce, $cipher, $target]);
                        }
                        break;
                    case 'registry.status':
                        $target = $id('registry_id');
                        $pdo->prepare('UPDATE registries SET enabled = ? WHERE id = ?')->execute([$flag(), $target]);
                        break;
                    case 'registry.access':
                        $target = $id('registry_id');
                        $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
                            ->execute([$id('reseller_id'), $target, $flag()]);
                        break;
                    case 'tld.save':
                        $target = $id('registry_id');
                        $metadata['tld'] = Access::tld($get('tld'));
                        $pdo->prepare('INSERT INTO registry_tlds (registry_id, tld, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
                            ->execute([$target, $metadata['tld'], $flag()]);
                        break;
                    case 'tld.access':
                        $target = $id('registry_id');
                        $metadata['tld'] = Access::tld($get('tld'));
                        $pdo->prepare('INSERT INTO reseller_tlds (reseller_id, registry_id, tld, enabled) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
                            ->execute([$id('reseller_id'), $target, $metadata['tld'], $flag()]);
                        break;
                    case 'price.set':
                    case 'price.delete':
                        $target = $id('registry_id');
                        $command = $get('command');
                        CommandCatalog::method($command);
                        $scope = $get('scope', 'global');
                        $scope = $scope === 'global' ? CommandCatalog::GLOBAL_PRICE_UUID : $id('scope');
                        $tld = $get('tld') === '' ? '' : Access::tld($get('tld'));
                        $period = $get('period', '0');
                        if (preg_match('/^(?:[0-9]|10)$/D', $period) !== 1) {
                            throw new ApiException(422, 'invalid_period', 'Use a term from 0 to 10.');
                        }
                        if ($action === 'price.delete') {
                            $pdo->prepare('DELETE FROM registry_prices WHERE registry_id = ? AND reseller_id = ? AND command = ? AND tld = ? AND period = ?')
                                ->execute([$target, $scope, $command, $tld, $period]);
                        } else {
                            if ((in_array($command, ['domain.create', 'domain.create_dnssec', 'domain.renew'], true) && ($tld === '' || $period === '0'))
                                || ($period !== '0' && ($tld === '' || !in_array($command, ['domain.create', 'domain.create_dnssec', 'domain.renew', 'domain.transfer'], true)))) {
                                throw new ApiException(422, 'invalid_price_scope', 'Term prices need a TLD and 1–10 years; other commands use term 0.');
                            }
                            if ($tld !== '') {
                                $exists = $pdo->prepare('SELECT 1 FROM registry_tlds WHERE registry_id = ? AND tld = ?');
                                $exists->execute([$target, $tld]);
                                if (!$exists->fetchColumn()) {
                                    throw new ApiException(422, 'unknown_tld', 'Add this TLD to the registry first.');
                                }
                            }
                            $raw = $get('amount');
                            $amount = preg_match('/^0(?:\.0{1,4})?$/D', $raw) ? '0.0000' : Util::money($raw);
                            $currency = strtoupper($get('currency'));
                            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                                throw new ApiException(422, 'invalid_currency', 'Use a three-letter currency.');
                            }
                            $pdo->prepare('INSERT INTO registry_prices (registry_id, reseller_id, command, tld, period, amount, currency) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency)')
                                ->execute([$target, $scope, $command, $tld, $period, $amount, $currency]);
                        }
                        $metadata = ['command' => $command, 'scope' => $scope, 'tld' => $tld, 'period' => $period];
                        break;
                    case 'object.assign':
                        $target = $id('registry_id');
                        $type = $get('object_type');
                        if (!in_array($type, ['domain', 'contact', 'host'], true)) {
                            throw new ApiException(422, 'invalid_type', 'Choose domain, contact or host.');
                        }
                        $state = $get('domain_state', 'active');
                        if (!in_array($state, ['active', 'deleted'], true) || $type !== 'domain' && $state !== 'active') {
                            throw new ApiException(422, 'invalid_state', 'Only domains may be imported as deleted.');
                        }
                        $pdo->prepare('INSERT INTO object_ownership (registry_id, object_type, object_name, reseller_id, active, domain_state, next_check_at) VALUES (?, ?, ?, ?, 1, ?, IF(? = 1, UTC_TIMESTAMP(6), NULL))')
                            ->execute([$target, $type, Ownership::name($get('object_name'), $type), $id('reseller_id'), $state, (int) ($state === 'deleted')]);
                        break;
                    default:
                        throw new ApiException(404, 'unknown_action', 'Unknown administration action.');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        Audit::record($pdo, $actor, 'panel.admin.' . $action, null, $target, $ip,
            $metadata + array_intersect_key($input, array_flip(['reseller_id', 'enabled', 'role', 'status'])));
        return $target ?? 'Saved';
    }
}
