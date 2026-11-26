<?php
// Run in Docker:
// docker exec -i secretsanta_app php /dev/stdin /var/www/html < tests/mail.php
declare(strict_types=1);

$sourceDir = $argv[1] ?? dirname(__DIR__) . '/src';
require $sourceDir . '/vendor/autoload.php';
require $sourceDir . '/santa_mail.php';

$config = [
    'host' => 'smtp.example.invalid',
    'port' => 465,
    'username' => 'test',
    'password' => 'unused',
    'from_email' => 'santa@example.invalid',
    'from_name' => 'Santa Test',
];
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$mail = santaCreateMailer($config);
$expect($mail->SMTPSecure === 'ssl', 'Port 465 must negotiate TLS before SMTP.');
$expect($mail->SMTPAuth, 'Configured credentials must enable authentication.');
$expect($mail->Timeout === 5, 'SMTP connection waits must be bounded.');
$expect($mail->getSMTPInstance()->Timelimit === 5, 'SMTP command waits must be bounded.');

$config['port'] = 587;
$mail = santaCreateMailer($config);
$expect($mail->SMTPSecure === 'tls', 'Port 587 must use STARTTLS.');

$config['username'] = '';
$config['password'] = '';
$config['port'] = 25;
$mail = santaCreateMailer($config);
$expect(!$mail->SMTPAuth && !$mail->SMTPAutoTLS && $mail->SMTPSecure === '', 'Local relays without credentials must use unauthenticated SMTP.');
echo "PASS: SMTP encryption modes, authentication, and connection/command timeouts\n";

// Verify how hostile notes are interpreted by an HTML parser and MIME builder.
// preSend() builds the message locally; these checks never contact SMTP.
$originalHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><p>Pairing details</p></body></html>';
$originalText = 'Pairing details';
foreach (['', " \t\r\n"] as $note) {
    $mail->Body = $originalHtml;
    $mail->AltBody = $originalText;
    santaAppendPairingNote($mail, $note);
    $expect($mail->Body === $originalHtml && $mail->AltBody === $originalText, 'Blank notes must leave both bodies unchanged.');
}

$notes = [
    '</p></body><script>alert("x")</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">click</a>',
    '&lt;script&gt;alert(1)&lt;/script&gt; &#60;img src=x onerror=alert(1)&#62;',
    '<?php throw new RuntimeException("executed"); ?> $(exit 1) `exit 1` ${NOTE_TO_USERS} {{7*7}}',
    "Use VPN & sign in with \"your account\".\nThen open your wishlist. 🎄",
    "Instructions\r\nBcc: injected@example.invalid\r\nContent-Type: text/html\r\n\r\n<script>alert(1)</script>",
    '0',
];
foreach ($notes as $note) {
    $mail = santaCreateMailer($config);
    $mail->addAddress('participant@example.invalid');
    $mail->Subject = 'Pairing note safety check';
    $mail->Body = $originalHtml;
    $mail->AltBody = $originalText;
    santaAppendPairingNote($mail, $note);

    $document = new DOMDocument();
    $expect($document->loadHTML($mail->Body), 'The HTML body must parse.');
    $paragraphs = $document->getElementsByTagName('p');
    $expect($paragraphs->length === 2, 'A note must add exactly one paragraph.');
    $noteParagraph = $paragraphs->item(1);
    $expect($noteParagraph->textContent === '(Note: ' . $note . ')', 'The displayed note must retain its literal text, including entities and code snippets.');
    foreach ($document->getElementsByTagName('*') as $element) {
        $expect(in_array($element->tagName, ['html', 'head', 'meta', 'body', 'p', 'br'], true), 'Notes must not inject HTML elements.');
        foreach ($element->attributes as $attribute) {
            $expect(in_array($attribute->name, ['charset', 'style'], true), 'Notes must not inject HTML attributes.');
        }
    }
    $expect($mail->AltBody === $originalText . "\n\n(Note: {$note})", 'The fallback must retain the note as plain text.');
    $expect($mail->preSend(), 'The MIME message must build without sending.');
    $headers = explode("\r\n\r\n", $mail->getSentMIMEMessage(), 2)[0];
    $expect(stripos($headers, 'injected@example.invalid') === false, 'Note text must not enter email headers.');
    $expect(count($mail->getAllRecipientAddresses()) === 1, 'Notes must not add recipients.');
}

$mail->Body = $originalHtml;
$mail->AltBody = $originalText;
santaAppendPairingNote($mail, "Invalid UTF-8: \xC3\x28 <script>");
$expect(str_contains($mail->Body, "Invalid UTF-8: \xEF\xBF\xBD( &lt;script&gt;"), 'Invalid UTF-8 must be replaced without losing HTML escaping.');
echo "PASS: literal note rendering, blank notes, HTML/script escaping, invalid UTF-8, and header injection protection\n";

$assignmentConfig = [
    'smtp' => $config,
    'app' => [
        'base_url' => 'https://santa.example.invalid',
        'budget_limit' => '15 EUR & cheer',
        'note_to_users' => 'Use <your> new link.',
    ],
];
$giver = ['first_name' => 'A <elf>', 'last_name' => '& Friend', 'email' => 'giver@example.invalid', 'wish_key' => str_repeat('a', 64)];
$receiver = ['first_name' => 'B <recipient>', 'last_name' => '& Friend'];
$mail = santaAssignmentEmail($assignmentConfig, $giver, $receiver, 2026);
$expect(str_contains($mail->Body, 'A &lt;elf&gt; &amp; Friend') && str_contains($mail->Body, 'B &lt;recipient&gt; &amp; Friend'), 'Assignment HTML must escape participant names.');
$expect(str_contains($mail->Body, '15 EUR &amp; cheer') && str_contains($mail->AltBody, '15 EUR & cheer'), 'Assignment budgets must be escaped only in HTML.');
$expect(str_contains($mail->Body, 'href="https://santa.example.invalid/wishes.php?key=' . $giver['wish_key'] . '"') && str_contains($mail->AltBody, $giver['wish_key']), 'Both assignment bodies must use the current key.');
$expect(substr_count($mail->Body, '<a ') === 1, 'Assignments must use one link for both wishlists.');
$expect(str_contains($mail->Body, 'Use &lt;your&gt; new link.') && str_contains($mail->AltBody, 'Use <your> new link.'), 'Draw and resend must include the configured note.');
$expect(count($mail->getAllRecipientAddresses()) === 1 && isset($mail->getAllRecipientAddresses()['giver@example.invalid']), 'Assignments must go only to the giver.');
$expect(!str_contains($mail->Subject, 'Resend') && $mail->preSend(), 'New assignments must build without a resend label.');
$mail = santaAssignmentEmail($assignmentConfig, $giver, $receiver, 2026, true);
$expect(str_contains($mail->Subject, '(Resend)') && $mail->preSend(), 'Resent assignments must use the same builder with a resend label.');
echo "PASS: Assignment and resend recipient, current key, escaped HTML, plain text, and configured note\n";

$mail = santaWishlistLinkEmail($assignmentConfig, $giver);
$expect(str_contains($mail->Body, 'A &lt;elf&gt; &amp; Friend'), 'Access emails must escape participant names.');
$expect(str_contains($mail->Body, '/wishes.php?key=' . $giver['wish_key']) && str_contains($mail->AltBody, $giver['wish_key']), 'Access emails must include the current link in both bodies.');
$expect(!str_contains($mail->Body, 'Your Secret Santa recipient'), 'Access emails must not invent an assignment.');
$expect(count($mail->getAllRecipientAddresses()) === 1 && isset($mail->getAllRecipientAddresses()['giver@example.invalid']), 'Access emails must go only to the participant.');
$expect($mail->preSend(), 'Wishlist access emails must build successfully.');
echo "PASS: Private wishlist access email, recipient, current key, and HTML escaping\n";
