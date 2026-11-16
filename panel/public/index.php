<?php
/** Namingo Reseller panel — based on Argora Foundry (MIT). */
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (in_array($path, ['/panel.css', '/checks.js', '/prices.js', '/admin-actions.js', '/assets/css/tabler.min.css', '/assets/js/tabler.min.js'], true)
        && is_file(__DIR__ . $path)) {
        return false;
    }
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

try {
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $app->run();
} catch (Throwable $e) {
    error_log('panel.bootstrap_failed type=' . get_class($e));
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The panel is unavailable. Check its configuration and application log.';
}
