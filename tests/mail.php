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
$config['port'] = 1025;
$mail = santaCreateMailer($config);
$expect(!$mail->SMTPAuth && !$mail->SMTPAutoTLS && $mail->SMTPSecure === '', 'MailHog must use plain, unauthenticated SMTP.');
echo "PASS: SMTP encryption modes, authentication, and connection/command timeouts\n";
