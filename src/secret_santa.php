<?php
// secret_santa.php
// Main Secret Santa drawing script.
// - Intended to be run from cron on (or around) Thanksgiving at noon.
// - With no arguments, it will *only* run on Thanksgiving Day (4th Thursday in November).
// - When run as: php secret_santa.php -force
//   it will bypass the Thanksgiving check and run immediately.
// - Clears participating wishlists when a new draw is saved.
// - Sends individual HTML emails to participants with wishlist links.
// - Sends a master list to the admin only when SEND_PAIRINGS=true.
//
// Requirements:
// - config.php in the same directory
// - Composer autoload (vendor/autoload.php)
// - PHPMailer via Composer (phpmailer/phpmailer)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/santa_database.php';
require __DIR__ . '/santa_draw.php';
require __DIR__ . '/santa_mail.php';
$config = require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\Exception;

// ---------------------------------------------------------
// CLI argument handling: support "-force"
// ---------------------------------------------------------
$forceRun = false;
if (PHP_SAPI === 'cli') {
    global $argc, $argv;
    if ($argc > 1) {
        foreach ($argv as $arg) {
            if ($arg === '-force' || $arg === '--force') {
                $forceRun = true;
                break;
            }
        }
    }
}

// ---------------------------------------------------------
// Check if today is Thanksgiving (4th Thursday in November)
// ---------------------------------------------------------
function isThanksgivingToday(): bool {
    $today = new DateTime('today', new DateTimeZone('America/New_York'));
    $year  = (int)$today->format('Y');

    $fourthThursday = new DateTime("fourth thursday of november $year", new DateTimeZone('America/New_York'));
    return $today->format('Y-m-d') === $fourthThursday->format('Y-m-d');
}

// If not forced, only run on Thanksgiving
if (!$forceRun && !isThanksgivingToday()) {
    // Not Thanksgiving and not forced, exit quietly.
    exit(0);
}

// Current year
$year = santaCurrentYear($config);

// ---------------------------------------------------------
// DB connection
// ---------------------------------------------------------
try {
    $pdo = santaDatabase($config);
} catch (PDOException $e) {
    error_log('Secret Santa DB connection failed: ' . $e->getMessage());
    exit(1);
}

try {
    $pdo->beginTransaction();
    $historyYears = santaHistoryYears($pdo, true);
    $participants = array_filter(
        santaParticipants($pdo, $year),
        static fn(array $participant): bool => (bool)$participant['is_in']
    );
    $excluded = santaExcludedRecipients($pdo, $year, $historyYears);
    $resultPairs = santaGeneratePairs($participants, $excluded);

    $resetWishes = $pdo->prepare("
        UPDATE participants
        SET wish_items = JSON_ARRAY(), wish_key = ?
        WHERE id = ?
    ");
    foreach ($participants as &$participant) {
        $participant['wish_key'] = $participant['wish_key'] ?: bin2hex(random_bytes(16));
        $resetWishes->execute([$participant['wish_key'], $participant['id']]);
    }
    unset($participant);

    santaSavePairs($pdo, $year, $resultPairs);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Secret Santa: Draw failed for {$year}: " . $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------
// Setup mailer
// ---------------------------------------------------------
$mailer     = santaCreateMailer($config['smtp']);
$baseUrl    = $config['app']['base_url'] ?? '';
$adminEmail = $config['smtp']['from_email'];

// ---------------------------------------------------------
// Build HTML email body for recipient with wishlist links
// ---------------------------------------------------------
function buildRecipientEmailHtml(array $giver, array $receiver, int $year, string $baseUrl, string $budget): string
{
    $giverName    = htmlspecialchars($giver['first_name'] . ' ' . $giver['last_name']);
    $receiverName = htmlspecialchars($receiver['first_name'] . ' ' . $receiver['last_name']);

    // Link for viewing recipient's wish list + editing own
    $wishKey     = urlencode($giver['wish_key']);
    $wishlistUrl = rtrim($baseUrl, '/') . '/wishes.php?key=' . $wishKey;

    // Two labeled links, same page with anchors
    $viewRecipientUrl = $wishlistUrl . '#recipient';
    $editOwnUrl       = $wishlistUrl . '#mine';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa $year</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎄 Secret Santa $year 🎄
      </h1>
      <p style="font-size:16px;margin:10px 0 20px;">
        Hi <strong>{$giverName}</strong>!<br>
        The elves have spoken...
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:30px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          Your Secret Santa person is:
        </p>
        <div style="font-size:30px;font-weight:bold;color:#b30000;margin:10px 0 10px;">
          {$receiverName}
        </div>
        <p style="font-size:14px;color:#333;margin:15px 0 5px;font-weight:bold;">
          💰 Budget Limit: {$budget}
        </p>
        <p style="font-size:13px;color:#666;margin:10px 0 15px;">
          🎁 Please keep this a secret and bring some holiday cheer to your person! 🎁
        </p>
        <p style="font-size:13px;color:#444;margin:10px 0;">
          ✅ View <strong>{$receiverName}</strong>'s wish list:
          <br>
          <a href="{$viewRecipientUrl}" style="color:#006600;font-weight:bold;">
            View Their Wishes
          </a>
        </p>
        <p style="font-size:13px;color:#444;margin:10px 0;">
          ✏️ Enter or update <strong>your own</strong> wish list (up to 3 items):
          <br>
          <a href="{$editOwnUrl}" style="color:#b30000;font-weight:bold;">
            Enter My Wishes
          </a>
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        (This is an automated Secret Santa drawing. If you think something is wrong, contact the organizer.)
      </p>
    </div>
  </div>
</body>
</html>
HTML;
}

// ---------------------------------------------------------
// Send individual emails + build master list
// ---------------------------------------------------------
$masterListRows = [];

foreach ($participants as $giver) {
    $giverId  = $giver['id'];
    $receiver = $resultPairs[$giverId];

    $toEmail = $giver['email'];
    if (empty($toEmail)) {
        error_log("Secret Santa: Participant ID {$giverId} has no email, skipping.");
        continue;
    }

    $budget = htmlspecialchars($config['app']['budget_limit'] ?? '$50');
    $htmlBody = buildRecipientEmailHtml($giver, $receiver, $year, $baseUrl, $budget);
    $subject  = "Your Secret Santa Person for $year 🎄";

    try {
        $mail = clone $mailer; // fresh instance per send
        $mail->clearAllRecipients();
        $mail->addAddress($toEmail, $giver['first_name'] . ' ' . $giver['last_name']);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        // Plain-text fallback including basic info and wishlist URL
        $wishKey     = $giver['wish_key'];
        $wishlistUrl = rtrim($baseUrl, '/') . '/wishes.php?key=' . $wishKey;

        $mail->AltBody = "Hi {$giver['first_name']},\n\n"
            . "Your Secret Santa person for $year is {$receiver['first_name']} {$receiver['last_name']}.\n\n"
            . "Budget Limit: {$budget}\n\n"
            . "View their wish list and enter your own wishes here:\n$wishlistUrl\n\n"
            . "Please keep this a secret and bring some holiday cheer!";

        santaAppendPairingNote($mail, $config['app']['note_to_users'] ?? '');
        $mail->send();
    } catch (Exception $e) {
        error_log("Secret Santa: Failed to send email to {$toEmail}: " . $e->getMessage());
    }

    // Build master list row
    $masterListRows[] = [
        'giver'    => $giver['first_name'] . ' ' . $giver['last_name'],
        'receiver' => $receiver['first_name'] . ' ' . $receiver['last_name'],
    ];
}

// ---------------------------------------------------------
// Send master list to admin
// ---------------------------------------------------------
if (($config['app']['send_pairings'] ?? false) !== true) {
    exit(0);
}

$rowsHtml = '';
foreach ($masterListRows as $row) {
    $g = htmlspecialchars($row['giver']);
    $r = htmlspecialchars($row['receiver']);
    $rowsHtml .= "<tr><td style=\"padding:6px 10px;border:1px solid #ddd;\">{$g}</td><td style=\"padding:6px 10px;border:1px solid #ddd;\">{$r}</td></tr>";
}

$masterHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa Master List $year</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;padding:20px;">
  <h2>Secret Santa Master List - {$year}</h2>
  <table style="border-collapse:collapse;background:#ffffff;">
    <thead>
      <tr style="background:#006600;color:#ffffff;">
        <th style="padding:8px 10px;border:1px solid #ddd;">Giver</th>
        <th style="padding:8px 10px;border:1px solid #ddd;">Receiver</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
    </tbody>
  </table>
</body>
</html>
HTML;

try {
    $mail = clone $mailer;
    $mail->clearAllRecipients();
    $mail->addAddress($adminEmail, 'Secret Santa Admin');
    $mail->Subject = "Secret Santa Pairings for $year";
    $mail->Body    = $masterHtml;
    $mail->AltBody = "Secret Santa $year master list:\n\n" .
        implode("\n", array_map(
            fn($row) => "{$row['giver']} -> {$row['receiver']}",
            $masterListRows
        ));

    $mail->send();
} catch (Exception $e) {
    error_log("Secret Santa: Failed to send master list: " . $e->getMessage());
}

exit(0);

