<?php
// Run in an isolated app container with Apache listening on localhost:80.
// No valid keys are sent and no draw, seed, or participant updates are run.

function checkResponse(string $base, string $path, string $method, int $expected, ?string $text = null): void
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 5,
        'follow_location' => 0,
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    $status = $http_response_header[0] ?? '';
    if ($body === false || !preg_match('/^HTTP\/\S+ ' . $expected . '\b/', $status)
        || ($text !== null && !str_contains($body, $text))) {
        throw new RuntimeException("Unexpected response for {$method} {$path}: {$status}");
    }
}

$blockedPaths = [
    '/', '/secret_santa.php', '/secret_santa.php?force=1', '/add_test_users.php',
    '/config.php', '/santa_database.php', '/santa_draw.php', '/santa_mail.php',
    '/santa_wishlist.php', '/santa_history_import.php', '/composer.json',
    '/composer.lock', '/vendor/', '/vendor/autoload.php',
    '/vendor/phpmailer/phpmailer/get_oauth_token.php', '/vendor/wishes.php',
    '/wishes.php/extra', '/wishes.js/extra', '/admin_secret_santa.php/extra',
    '/import_users.php/extra', '/wishes', '/.env', '/.git/config',
    '/backup.php', '/wishes.php.bak', '/anything-new.txt',
    '//secret_santa.php', '/%73ecret_santa.php',
    '/vendor/../secret_santa.php', '/wishes.php/../secret_santa.php',
];

foreach ($blockedPaths as $path) {
    foreach (['GET', 'HEAD', 'POST'] as $method) {
        checkResponse('http://127.0.0.1', $path, $method, 403);
    }
}

checkResponse('http://127.0.0.1', '/wishes.js', 'GET', 200, 'document');
checkResponse('http://127.0.0.1', '/wishes.js?v=1', 'GET', 200, 'document');
checkResponse('http://127.0.0.1', '/import_users.php', 'GET', 403, 'Forbidden.');
// This test container has no database. These responses prove the PHP pages
// execute through Apache and can still require their private dependencies.
checkResponse('http://127.0.0.1', '/wishes.php?key=invalid', 'GET', 500, 'Database connection failed.');
checkResponse('http://127.0.0.1', '/admin_secret_santa.php', 'GET', 200, 'DB connection failed:');

// Bypass Apache using PHP's built-in web server, to verify the CLI guards
// independently. The server stays on loopback in the isolated container.
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8081', '-t', '/var/www/html'], [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/tmp/santa-cli-guard-test.log', 'a'],
    2 => ['file', '/tmp/santa-cli-guard-test.log', 'a'],
], $pipes);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start the CLI guard test server.');
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8081, $errno, $error, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (!$ready) {
        throw new RuntimeException('CLI guard test server did not start.');
    }
    foreach (['/secret_santa.php', '/add_test_users.php'] as $path) {
        checkResponse('http://127.0.0.1:8081', $path, 'GET', 403,
            'This script can only be run from the command line.');
    }
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo count($blockedPaths) * 3 . " blocked HTTP requests passed; allowed pages, private includes, and CLI guards passed.\n";
