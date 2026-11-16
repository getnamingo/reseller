<?php

declare(strict_types=1);

// Run the documented PHP development router and fetch the actual polling asset.
$root = dirname(__DIR__);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'reseller-router-');
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/panel/public', $root . '/panel/public/index.php'],
    [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, $root);
if (!is_resource($process)) { throw new RuntimeException('Could not start PHP development server.'); }
fclose($pipes[0]);
try {
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    foreach (['/checks.js', '/prices.js', '/admin-actions.js', '/contacts.js'] as $asset) {
        $body = false;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $body = @file_get_contents('http://' . $address . $asset . '?v=router-test', false, $context);
            if ($body !== false) { break; }
            usleep(100_000);
        }
        if ($body !== file_get_contents($root . '/panel/public' . $asset)) {
            throw new RuntimeException('Router did not serve ' . $asset . ': ' . file_get_contents($log));
        }
        $headers = implode("\n", http_get_last_response_headers() ?? []);
        if (!str_contains($headers, '200 OK') || preg_match('/Content-Type: (?:application|text)\/javascript/i', $headers) !== 1) {
            throw new RuntimeException('Expected HTTP 200 JavaScript, got: ' . $headers);
        }
    }
    echo "PASS: development router serves polling, price and admin action JavaScript\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    unlink($log);
}
