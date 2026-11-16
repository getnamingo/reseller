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
        if ($action === 'user.update') {
            $target = $id('reseller_id');
            $changes = array_intersect_key($input, array_flip(['username', 'display_name', 'company', 'email', 'phone', 'address', 'country_code']));
            self::updateAccount($pdo, $target, $changes);
        } elseif ($action === 'tld.delete') {
            $target = $id('registry_id');
            $metadata['tld'] = Access::tld($get('tld'));
            if ($get('confirmed') !== '1') { throw new ApiException(422, 'confirmation_required', 'Confirm removal of this TLD, its grants and its exact TLD prices.'); }
            self::deleteTld($pdo, $target, $metadata['tld']);
        } elseif (in_array($action, ['deposit.approve', 'deposit.reject', 'job.resolve', 'job.mark_unknown'], true)) {
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
                    case 'tld.unassign':
                        $target = $id('registry_id');
                        $metadata['tld'] = Access::tld($get('tld'));
                        $pdo->prepare('DELETE FROM reseller_tlds WHERE reseller_id = ? AND registry_id = ? AND tld = ?')
                            ->execute([$id('reseller_id'), $target, $metadata['tld']]);
                        break;
                    case 'price.batch':
                        $zone = explode(':', $get('zone'), 2);
                        if (count($zone) !== 2 || !Util::isUuid($zone[0]) || $zone[1] === '') {
                            throw new ApiException(422, 'invalid_price_scope', 'Choose one configured registry and TLD.');
                        }
                        $target = strtolower($zone[0]);
                        $tld = Access::tld($zone[1]);
                        $scope = $get('scope') === 'global' ? CommandCatalog::GLOBAL_PRICE_UUID : $id('scope');
                        $amounts = $input['amounts'] ?? null;
                        $operations = CommandCatalog::priceOperations();
                        if (!is_array($amounts) || count($amounts) !== count($operations) || array_diff(array_keys($amounts), array_keys($operations))) {
                            throw new ApiException(422, 'invalid_price_table', 'Supply prices for exactly the four supported domain operations.');
                        }
                        foreach ($operations as $command => $label) {
                            $periods = $command === 'domain.restore' ? [0] : range(1, 10);
                            $values = $amounts[$command] ?? null;
                            if (!is_array($values) || count($values) !== count($periods) || array_diff(array_keys($values), $periods)) {
                                throw new ApiException(422, 'required_price', "Fill every {$label} price; enter 0 explicitly for free operations.");
                            }
                            foreach ($periods as $period) {
                                if (!is_string($values[$period] ?? null) || trim($values[$period]) === '') {
                                    throw new ApiException(422, 'required_price', "Enter {$label} price for term {$period}; use 0 explicitly for free operations.");
                                }
                                self::savePrice($pdo, $target, $scope, $command, $tld, $period, trim($values[$period]), $get('currency'));
                            }
                        }
                        $metadata = ['scope' => $scope, 'tld' => $tld, 'prices' => 31];
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
                            self::savePrice($pdo, $target, $scope, $command, $tld, (int) $period, $get('amount'), $get('currency'));
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

    /** Shared price writer for authenticated administration and the local CLI. */
    public static function savePrice(PDO $pdo, string $registry, string $scope, string $command, string $tld, int $period, string $raw, string $currency): void
    {
        CommandCatalog::method($command);
        if (!CommandCatalog::requiresPrice($command)) {
            throw new ApiException(422, 'unsupported_price_command', 'Prices may only be set for domain.create, domain.renew, domain.restore and incoming domain.transfer.');
        }
        if (!Util::isUuid($registry) || ($scope !== CommandCatalog::GLOBAL_PRICE_UUID && !Util::isUuid($scope)) || $period < 0 || $period > 10
            || ($command !== 'domain.restore' && ($tld === '' || $period === 0))
            || ($period > 0 && ($tld === '' || $command === 'domain.restore'))) {
            throw new ApiException(422, 'invalid_price_scope', 'Term prices need a TLD and 1–10 years; only restore uses term 0. Transfer follow-ups are always free.');
        }
        if ($tld !== '') {
            $tld = Access::tld($tld);
            $exists = $pdo->prepare('SELECT 1 FROM registry_tlds WHERE registry_id = ? AND tld = ?');
            $exists->execute([$registry, $tld]);
            if (!$exists->fetchColumn()) { throw new ApiException(422, 'unknown_tld', 'Add this TLD to the registry first.'); }
        }
        $raw = trim($raw);
        $amount = preg_match('/^0(?:\.0{1,4})?$/D', $raw) ? '0.0000' : Util::money($raw);
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new ApiException(422, 'invalid_currency', 'Use a three-letter currency.');
        }
        $pdo->prepare('INSERT INTO registry_prices (registry_id, reseller_id, command, tld, period, amount, currency) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency)')
            ->execute([$registry, $scope, $command, $tld, $period, $amount, $currency]);
    }

    /** Shared panel/CLI account edit. UUID, credentials and wallet currency stay independent. */
    public static function updateAccount(PDO $pdo, string $id, array $changes): void
    {
        $limits = ['username' => 64, 'display_name' => 255, 'company' => 255, 'email' => 254, 'phone' => 32, 'address' => 2000, 'country_code' => 2];
        if ($changes === [] || array_diff_key($changes, $limits) !== []) {
            throw new ApiException(422, 'invalid_account_fields', 'Edit username, display_name, company, email, phone, address or country_code. Currency and credentials use their existing controls.');
        }
        foreach ($changes as $field => &$value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { throw new ApiException(422, 'invalid_account', 'Account fields must be UTF-8 strings.'); }
            $value = trim($value);
            if ($field === 'country_code') { $value = strtoupper($value); }
            if (mb_strlen($value) > $limits[$field] || preg_match($field === 'address' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $value)
                || $field === 'username' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D', $value) !== 1
                || $field === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false
                || $field === 'country_code' && $value !== '' && preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
                throw new ApiException(422, 'invalid_account', "Invalid account field: {$field}.");
            }
        }
        unset($value);
        $pdo->beginTransaction();
        try {
            $old = $pdo->prepare('SELECT username FROM resellers WHERE id = ? FOR UPDATE'); $old->execute([$id]);
            $username = $old->fetchColumn();
            if ($username === false) { throw new ApiException(404, 'user_not_found', 'Reseller was not found.'); }
            $sets = implode(', ', array_map(static fn ($field) => "{$field} = ?", array_keys($changes)));
            $pdo->prepare("UPDATE resellers SET {$sets} WHERE id = ?")->execute([...array_values($changes), $id]);
            if (isset($changes['username']) && $changes['username'] !== $username) {
                $pdo->prepare('UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE reseller_id = ? AND revoked_at IS NULL')->execute([$id]);
                $pdo->prepare('UPDATE resellers SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'username_exists', 'This username is already in use.');
            }
            throw $e;
        }
    }

    /** Remove only unused zones; disabled rows retain the longest-suffix access boundary. */
    public static function deleteTld(PDO $pdo, string $registry, string $tld): void
    {
        $tld = Access::tld($tld);
        $name = Queue::lockName($registry);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$name]);
        if ((string) $lock->fetchColumn() !== '1') { throw new ApiException(409, 'registry_busy', 'Registry work is running. Disable this TLD or retry later.'); }
        try {
            $pdo->beginTransaction();
            $row = $pdo->prepare('SELECT 1 FROM registry_tlds WHERE registry_id = ? AND tld = ? FOR UPDATE'); $row->execute([$registry, $tld]);
            if (!$row->fetchColumn()) { throw new ApiException(404, 'unknown_tld', 'TLD was not found.'); }
            $used = $pdo->prepare("SELECT 1 FROM object_ownership WHERE registry_id = ? AND object_type = 'domain'
                AND (object_name = ? OR RIGHT(object_name, CHAR_LENGTH(?) + 1) = CONCAT('.', ?)) LIMIT 1");
            $used->execute([$registry, $tld, $tld, $tld]);
            $jobs = $pdo->prepare("SELECT 1 FROM epp_jobs WHERE registry_id = ? AND status IN ('queued', 'processing', 'pending', 'unknown') LIMIT 1"); $jobs->execute([$registry]);
            if ($used->fetchColumn() || $jobs->fetchColumn()) { throw new ApiException(409, 'tld_in_use', 'This TLD has owned domains or the registry has unsettled jobs. Disable it instead.'); }
            $pdo->prepare('DELETE FROM registry_prices WHERE registry_id = ? AND tld = ?')->execute([$registry, $tld]);
            $pdo->prepare('DELETE FROM reseller_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, $tld]);
            $pdo->prepare('DELETE FROM registry_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, $tld]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }
}
