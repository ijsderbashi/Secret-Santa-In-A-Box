<?php
// HTTP integration checks against a disposable database and local MailHog.
// php tests/admin.php [source directory] [schema SQL file]
declare(strict_types=1);

$sourceDir = $argv[1] ?? dirname(__DIR__) . '/src';
$schemaFile = $argv[2] ?? dirname(__DIR__) . '/db/init-db.sql';
require $sourceDir . '/santa_database.php';
$config = require $sourceDir . '/config.php';
if (($config['smtp']['host'] ?? '') !== 'mailhog') {
    throw new RuntimeException('These checks require the isolated MailHog preview; no external email may be sent.');
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function field(string $html, string $name): string {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $node = (new DOMXPath($document))->query('//input[@name="' . $name . '"]')->item(0);
    check($node !== null, 'Missing field: ' . $name);
    return $node->getAttribute('value');
}
function request(string $url, ?array $data = null, string &$cookie = ''): array {
    $headers = 'Cookie: ' . $cookie . "\r\n";
    $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 40, 'header' => $headers];
    if ($data !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($data);
    }
    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    check($body !== false, 'HTTP request failed.');
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $match)) $cookie = $match[1];
    }
    check(!str_contains($body, 'Fatal error') && !str_contains($body, '<b>Warning</b>'), 'PHP failed during HTTP request.');
    return [(int)$status[1], $body];
}
function startServer(string $sourceDir, string $database, bool $failMail = false): array {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $env = getenv();
    $env['DB_NAME'] = $database;
    if ($failMail) { $env['SMTP_HOST'] = '127.0.0.1'; $env['SMTP_PORT'] = '1'; }
    $log = tempnam(sys_get_temp_dir(), 'santa-admin-test-');
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $sourceDir], [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a'],
    ], $pipes, $sourceDir, $env);
    check(is_resource($process), 'Could not start test HTTP server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($connection) { fclose($connection); return [$process, 'http://' . $address, $log]; }
        usleep(50000);
    }
    proc_terminate($process);
    throw new RuntimeException('HTTP server did not start: ' . file_get_contents($log));
}

$run = bin2hex(random_bytes(6));
$database = 'santa_admin_test_' . $run;
$serverConfig = $config;
$serverConfig['db']['dsn'] = 'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';charset=utf8mb4';
$server = santaDatabase($serverConfig);
$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$dbConfig = $serverConfig;
$dbConfig['db']['dsn'] .= ';dbname=' . $database;
$db = santaDatabase($dbConfig);
$servers = [];
$mailbox = static function () use ($run): array {
    $data = json_decode(file_get_contents('http://mailhog:8025/api/v2/search?kind=to&query=' . rawurlencode('admin-test-' . $run) . '&limit=100'), true);
    return $data['items'] ?? [];
};
try {
    $db->exec(file_get_contents($schemaFile));
    $insert = $db->prepare('INSERT INTO participants (first_name,last_name,email,family_unit,wish_key,wish_items) VALUES (?,?,?,?,?,?)');
    foreach (range(1, 4) as $id) {
        $insert->execute(['Person ' . $id, 'Test', "admin-test-{$run}-{$id}@example.invalid", $id, 'old-' . $run . '-' . $id, '["Keep my wishes"]']);
    }
    $db->exec("INSERT INTO panel_visits VALUES (1,2026,NOW(),NOW(),7)");
    $db->exec('INSERT INTO secret_santa_pairs (year,giver_id,receiver_id) VALUES (2025,1,2),(2026,1,2),(2026,2,3),(2026,3,4),(2026,4,1)');
    $person = static fn() => $db->query('SELECT * FROM participants WHERE id=1')->fetch();
    $pairs = static fn(int $year) => $db->query('SELECT * FROM secret_santa_pairs WHERE year=' . $year . ' ORDER BY id')->fetchAll();
    $originalPairs = $pairs(2026);
    $originalPerson = $person();
    $servers[] = startServer($sourceDir, $database);
    $base = $servers[0][1];
    $url = $base . '/admin_secret_santa.php?key=' . rawurlencode($config['app']['admin_key']);
    $cookie = '';
    [$status, $html] = request($url, null, $cookie);
    check($status === 200, 'Admin must load.');
    foreach (range(1, 4) as $id) {
        check(!str_contains($html, 'old-' . $run . '-' . $id), 'Admin HTML must not expose participant keys.');
    }
    check(!str_contains($html, 'wishes.php?key=') && !str_contains($html, 'Copy Link'), 'Admin must not render personal links or copy controls.');
    $csrf = field($html, 'csrf_token');
    $post = static function (array $data) use ($url, &$cookie, $csrf): array {
        return request($url, array_merge(['csrf_token' => $csrf, 'year' => '2026'], $data), $cookie);
    };
    $prepare = static function (int $year) use ($post): array {
        [$status, $html] = $post(['action' => 'prepare_draw', 'year' => (string)$year, 'history_years' => '0']);
        check($status === 200 && str_contains($html, 'id="draw-confirmation"'), 'Draw preparation must show a dialog.');
        return ['action' => 'confirm_draw', 'year' => (string)$year, 'draw_token' => field($html, 'draw_token'), 'confirmation_step' => field($html, 'confirmation_step')];
    };

    [$status] = $post(['csrf_token' => 'bad', 'action' => 'regenerate_key', 'participant_id' => '1']);
    check($person() === $originalPerson, 'Missing CSRF must preserve the key and profile.');
    $edit = ['action' => 'edit_participant', 'participant_id' => '1', 'first_name' => 'Person 1', 'last_name' => 'Test', 'email' => $originalPerson['email'], 'family_unit' => '12'];
    [$status] = $post($edit);
    check($status === 303 && (int)$person()['family_unit'] === 12, 'Family editing must save and redirect.');
    foreach (['id', 'wish_key', 'wish_items', 'wishlist_notified_at'] as $column) check($person()[$column] === $originalPerson[$column], 'Family editing must preserve ' . $column);
    check($pairs(2026) === $originalPairs && count($pairs(2025)) === 1, 'Family editing must preserve all draw history.');
    check((int)$db->query('SELECT visit_count FROM panel_visits WHERE participant_id=1')->fetchColumn() === 7, 'Editing must preserve visits.');
    [$status, $html] = $post(array_merge($edit, ['family_unit' => '2.5']));
    check(str_contains($html, 'Enter a whole number') && (int)$person()['family_unit'] === 12, 'Fractional family groups must be rejected.');
    [$status, $html] = $post(array_merge($edit, ['email' => "admin-test-{$run}-2@example.invalid"]));
    check(str_contains($html, 'already used') && $person()['email'] === $originalPerson['email'], 'Duplicate participant email must be rejected.');
    echo "PASS: Family group editing, validation, and preserved wishes, keys, visits, and history\n";

    $rotate = ['action' => 'regenerate_key', 'participant_id' => '1', 'key_version' => hash('sha256', $originalPerson['wish_key'])];
    [$status] = $post($rotate);
    $newKey = $person()['wish_key'];
    check($status === 303 && strlen($newKey) === 64 && $newKey !== $originalPerson['wish_key'], 'Regeneration must create a new random key.');
    check($person()['wish_items'] === $originalPerson['wish_items'] && $pairs(2026) === $originalPairs, 'Rotation must preserve wishes and assignments.');
    [$status, $html] = request($url, null, $cookie);
    check(!str_contains($html, $newKey) && !str_contains($html, 'wishes.php?key=') && str_contains($html, 'id="edit-row-1" >'), 'Regeneration must keep the replacement link private and expand the editor.');
    check(str_contains($html, 'An email with the new link was sent to'), 'Regeneration must report the automatic resend.');
    [$status] = request($base . '/wishes.php?key=' . $originalPerson['wish_key']);
    check($status === 403, 'Old key GET must be invalid.');
    [$status] = request($base . '/wishes.php?key=' . $originalPerson['wish_key'], ['csrf_token' => $csrf, 'wish_items' => ['Stolen']], $cookie);
    check($status === 403 && $person()['wish_items'] === $originalPerson['wish_items'], 'Old key POST must not change wishes.');
    [$status] = request($base . '/wishes.php?key=' . $newKey);
    check($status === 200, 'New key must open the portal.');
    [$status, $html] = $post($rotate);
    check(str_contains($html, 'already changed') && $person()['wish_key'] === $newKey, 'Repeated rotation requests must not rotate again.');
    $messages = $mailbox();
    check(count($messages) === 1, 'Regeneration must automatically resend exactly one email; replay must send nothing.');
    $body = $messages[0]['Content']['Body'];
    foreach ($messages[0]['MIME']['Parts'] ?? [] as $part) {
        $encoding = strtolower($part['Headers']['Content-Transfer-Encoding'][0] ?? '');
        $body .= match ($encoding) {
            'base64' => base64_decode($part['Body']),
            'quoted-printable' => quoted_printable_decode($part['Body']),
            default => $part['Body'],
        };
    }
    check(str_contains($body, $newKey) && !str_contains($body, $originalPerson['wish_key']), 'Resend must use the regenerated link.');
    echo "PASS: Private key rotation, old-link rejection, new-link access, automatic resend, and replay protection\n";

    [$status, $html] = $post(['action' => 'test_draw', 'history_years' => '0']);
    check(str_contains($html, 'Test Draw for 2026') && $pairs(2026) === $originalPairs && count($mailbox()) === 1, 'Preview must not save or email.');
    [$status, $html] = $post(['action' => 'confirm_draw', 'draw_token' => 'forged', 'confirmation_step' => 'overwrite']);
    check(str_contains($html, 'expired or was already used') && $pairs(2026) === $originalPairs, 'Unconfirmed draws must not execute.');
    $confirm = $prepare(2026);
    check($confirm['confirmation_step'] === 'draw', 'First dialog must be the general confirmation.');
    [$status, $html] = $post($confirm);
    check(field($html, 'confirmation_step') === 'overwrite' && str_contains($html, 'overwrite all existing assignments'), 'Existing draw must require a second explicit warning.');
    check($pairs(2026) === $originalPairs && count($mailbox()) === 1, 'First confirmation must not overwrite or email.');
    [$status, $html] = $post($confirm);
    check(str_contains($html, 'expired or was already used'), 'Replaying the first step must not skip the second confirmation.');
    $post(['action' => 'cancel_draw']);
    check($pairs(2026) === $originalPairs && count($mailbox()) === 1, 'Cancellation must preserve assignments and send no email.');
    echo "PASS: Preview, mandatory two-step overwrite confirmation, cancellation, and confirmation bypass prevention\n";

    $confirm = $prepare(2027);
    [$status] = $post($confirm);
    check($status === 303 && count($pairs(2027)) === 4 && count($mailbox()) === 5, 'New year must save and send after one confirmation.');
    $saved2027 = $pairs(2027);
    [$status, $html] = request($url . '&year=2027', null, $cookie);
    check(str_contains($html, 'Assignment emails sent: 4 of 4'), 'Draw success must report the delivery count.');
    $post($confirm);
    check($pairs(2027) === $saved2027 && count($mailbox()) === 5, 'Replayed final submission must not redraw or resend.');
    $confirm = $prepare(2026);
    [$status, $html] = $post($confirm);
    $confirm['confirmation_step'] = field($html, 'confirmation_step');
    [$status] = $post($confirm);
    check($status === 303 && $pairs(2026) !== $originalPairs && count($pairs(2026)) === 4 && count($mailbox()) === 9, 'Confirmed overwrite must replace assignments and email each participant once.');
    $saved2026 = $pairs(2026);
    $post($confirm);
    check($pairs(2026) === $saved2026 && count($mailbox()) === 9, 'Duplicate overwrite submission must be harmless.');
    check(count($pairs(2025)) === 1 && $person()['wish_items'] === $originalPerson['wish_items'], 'Draws must preserve other years and existing wishes.');
    echo "PASS: New draw, confirmed replacement, delivery counts, duplicate submissions, and preserved prior years and wishes\n";

    $confirm = $prepare(2026);
    $db->exec('UPDATE participants SET family_unit=90 WHERE id=2');
    [$status, $html] = $post($confirm);
    check(str_contains($html, 'changed during confirmation') && $pairs(2026) === $saved2026 && count($mailbox()) === 9, 'Stale roster confirmation must not overwrite or email.');
    $db->exec('UPDATE participants SET family_unit=1');
    $confirm = $prepare(2026);
    [$status, $html] = $post($confirm);
    $confirm['confirmation_step'] = field($html, 'confirmation_step');
    [$status, $html] = $post($confirm);
    check(str_contains($html, 'No valid draw exists') && $pairs(2026) === $saved2026 && count($mailbox()) === 9, 'Impossible draws must preserve existing assignments and send nothing.');
    echo "PASS: Changed-roster protection and rollback of an impossible replacement draw\n";

    $db->exec('UPDATE participants SET family_unit=id');
    $servers[] = startServer($sourceDir, $database, true);
    $failUrl = $servers[1][1] . '/admin_secret_santa.php?key=' . rawurlencode($config['app']['admin_key']);
    $failCookie = '';
    [$status, $html] = request($failUrl, null, $failCookie);
    $data = ['csrf_token' => field($html, 'csrf_token'), 'year' => '2030', 'history_years' => '0', 'action' => 'prepare_draw'];
    [$status, $html] = request($failUrl, $data, $failCookie);
    $data['action'] = 'confirm_draw';
    $data['draw_token'] = field($html, 'draw_token');
    $data['confirmation_step'] = field($html, 'confirmation_step');
    [$status] = request($failUrl, $data, $failCookie);
    check($status === 303 && count($pairs(2030)) === 4, 'SMTP failure must preserve the saved draw.');
    [$status, $html] = request($failUrl . '&year=2030', null, $failCookie);
    check(str_contains($html, 'Assignment emails sent: 0 of 4') && str_contains($html, 'Use Resend for those participants'), 'SMTP failure must show saved-draw status and individual retry guidance.');
    check(count($mailbox()) === 9, 'Failed SMTP must not deliver any mail.');
    echo "PASS: SMTP failure reporting, saved assignments, and retry guidance\n";

    $oldKey = $person()['wish_key'];
    [$status] = $post(['action' => 'regenerate_key', 'participant_id' => '1', 'year' => '2040', 'key_version' => hash('sha256', $oldKey)]);
    $accessKey = $person()['wish_key'];
    $messages = $mailbox();
    check($status === 303 && $accessKey !== $oldKey && count($messages) === 10, 'Regeneration before a draw must email a replacement link.');
    check(str_contains($messages[0]['Content']['Body'], $accessKey) && !str_contains($messages[0]['Content']['Body'], 'Your Secret Santa person for'), 'Without an assignment, email only portal access.');
    [$status] = $post(['action' => 'resend_email', 'participant_id' => '1', 'year' => '2040']);
    check($status === 303 && $person()['wish_key'] === $accessKey && count($mailbox()) === 11, 'Resend without an assignment must email the current link without rotating it.');
    echo "PASS: Automatic link email before a draw exists and resend without another rotation\n";

    $rotation = ['csrf_token' => $data['csrf_token'], 'year' => '2030', 'action' => 'regenerate_key', 'participant_id' => '1', 'key_version' => hash('sha256', $accessKey)];
    [$status] = request($failUrl, $rotation, $failCookie);
    $failedDeliveryKey = $person()['wish_key'];
    check($status === 303 && $failedDeliveryKey !== $accessKey && count($mailbox()) === 11, 'A failed email must not undo revocation of the old key.');
    [$status, $html] = request($failUrl . '&year=2030', null, $failCookie);
    check(str_contains($html, 'Use Resend for this participant to retry') && !str_contains($html, $failedDeliveryKey), 'Key email failure must offer a retry without exposing the key.');
    $post(['action' => 'resend_email', 'participant_id' => '1', 'year' => '2030']);
    check($person()['wish_key'] === $failedDeliveryKey && count($mailbox()) === 12, 'Resend after a delivery failure must reuse the rotated key.');
    check($pairs(2026) === $saved2026 && $person()['wish_items'] === $originalPerson['wish_items'], 'Regeneration and resend must preserve wishes and history.');
    echo "PASS: Regenerated-key email failure, hidden key, preserved revocation, and successful resend without rotating again\n";
} finally {
    foreach ($mailbox() as $mail) {
        file_get_contents('http://mailhog:8025/api/v1/messages/' . rawurlencode($mail['ID']), false,
            stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true]]));
    }
    foreach ($servers as [$process, $base, $log]) {
        proc_terminate($process);
        proc_close($process);
        unlink($log);
    }
    $server->exec("DROP DATABASE `$database`");
}
