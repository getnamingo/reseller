<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact help@namingo.org.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;
use RuntimeException;

final class PanelSession
{
    public static function start(string $root): void
    {
        $origin = Config::required('PANEL_ORIGIN');
        $url = parse_url($origin);
        $secure = ($url['scheme'] ?? '') === 'https';
        if ($url === false || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query'])
            || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)
            || (!$secure && (Config::production() || ($url['scheme'] ?? '') !== 'http'
                || !in_array($url['host'], ['localhost', '127.0.0.1', '::1', '[::1]'], true)))) {
            throw new RuntimeException('PANEL_ORIGIN must be an HTTPS origin (loopback HTTP is allowed outside production).');
        }
        if ($secure && !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            throw new RuntimeException('The panel requires HTTPS; configure the trusted TLS proxy to set the FastCGI HTTPS parameter.');
        }
        $directory = Config::get('PANEL_SESSION_DIR', $root . '/var/panel-sessions');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the private panel session directory.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) Config::int('TOKEN_TTL_SECONDS', 43200));
        session_save_path($directory);
        session_name($secure ? '__Host-namingo_panel' : 'namingo_panel_dev');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) {
            throw new RuntimeException('Cannot start the panel session.');
        }
        $_SESSION['csrf'] ??= Util::token();
    }

    public static function csrf(mixed $value, string $origin = ''): void
    {
        if (!is_string($value) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $value)
            || ($origin !== '' && $origin !== rtrim(Config::required('PANEL_ORIGIN'), '/'))) {
            throw new ApiException(403, 'csrf_failed', 'The form expired. Reload the page and try again.');
        }
    }

    public static function user(PDO $pdo): ?array
    {
        $token = $_SESSION['api_token'] ?? null;
        if (!is_string($token)) {
            return null;
        }
        try {
            $user = Auth::authenticate($pdo, 'Bearer ' . $token);
        } catch (ApiException) {
            self::clear();
            return null;
        }
        if (time() - (int) ($_SESSION['last_seen'] ?? 0) > Config::int('PANEL_IDLE_SECONDS', 1800)) {
            Auth::revoke($pdo, $user['token_id']);
            self::clear();
            return null;
        }
        $_SESSION['last_seen'] = time();
        if (time() - (int) ($_SESSION['rotated_at'] ?? 0) > 900) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = time();
        }
        return $user; // Role and account state always come from the database, never the session.
    }

    public static function login(array $login): void
    {
        session_regenerate_id(true);
        $_SESSION = ['api_token' => $login['access_token'], 'csrf' => Util::token(), 'last_seen' => time(), 'rotated_at' => time()];
    }

    public static function clear(): void
    {
        $_SESSION = ['csrf' => Util::token()];
        session_regenerate_id(true);
    }
}
