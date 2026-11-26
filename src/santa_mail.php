<?php

use PHPMailer\PHPMailer\PHPMailer;

function santaAppendPairingNote(PHPMailer $mail, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        return;
    }

    // Treat the note only as text. Encode existing entities too, so supplied
    // markup cannot become HTML; only our own line-break tags are inserted.
    $escapedNote = nl2br(htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true));
    $noteHtml = '<p style="max-width:560px;margin:0 auto 20px;padding:20px;background:#ffffff;color:#333333;border-radius:10px;font-family:Arial,Helvetica,sans-serif;font-size:13px;">'
        . '(Note: ' . $escapedNote . ")</p>\n";
    $mail->Body = str_replace('</body>', $noteHtml . '</body>', $mail->Body);
    $mail->AltBody .= "\n\n(Note: {$note})";
}

function santaCreateMailer(array $smtpConfig): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpConfig['host'];
    $mail->Port = (int) $smtpConfig['port'];

    // Connection and command waits must both be bounded. PHPMailer defaults to
    // five minutes, which is far too long for a mail attempt during a web request.
    $mail->Timeout = 5;
    $mail->getSMTPInstance()->Timelimit = 5;

    if (!empty($smtpConfig['username'])) {
        $mail->SMTPAuth = true;
        $mail->Username = $smtpConfig['username'];
        $mail->Password = $smtpConfig['password'];
        // Port 465 starts with TLS; submission ports such as 587 use STARTTLS.
        $mail->SMTPSecure = $mail->Port === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        // Unauthenticated local SMTP relays need neither authentication nor TLS.
        $mail->SMTPAuth = false;
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    return $mail;
}

function santaAssignmentEmail(array $config, array $giver, array $receiver, int $year, bool $resend = false): PHPMailer
{
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $giverName = $escape(trim($giver['first_name'] . ' ' . $giver['last_name']));
    $receiverName = $escape(trim($receiver['first_name'] . ' ' . $receiver['last_name']));
    $budget = $escape($config['app']['budget_limit'] ?? '$50');
    $wishlistUrl = rtrim($config['app']['base_url'] ?? '', '/') . '/wishes.php?key=' . rawurlencode($giver['wish_key']);
    $htmlUrl = $escape($wishlistUrl);
    $mail = santaCreateMailer($config['smtp']);
    $mail->addAddress($giver['email'], trim($giver['first_name'] . ' ' . $giver['last_name']));
    $mail->Subject = "Your Secret Santa Person for $year 🎄" . ($resend ? ' (Resend)' : '');
    $mail->Body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Secret Santa $year</title></head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;">🎄 Secret Santa $year 🎄</h1>
      <p>Hi <strong>{$giverName}</strong>!<br>The elves have spoken...</p>
      <div style="background:#ffffff;color:#444444;border-radius:10px;padding:30px;">
        <p>Your Secret Santa person is:</p>
        <div style="font-size:30px;font-weight:bold;color:#b30000;">{$receiverName}</div>
        <p><strong>💰 Budget Limit: {$budget}</strong></p>
        <p>✅ View <strong>{$receiverName}</strong>'s wish list:<br>
          <a href="{$htmlUrl}#recipient" style="color:#006600;font-weight:bold;">View Their Wishes</a></p>
        <p>✏️ Enter or update <strong>your own</strong> wish list:<br>
          <a href="{$htmlUrl}#mine" style="color:#b30000;font-weight:bold;">Enter My Wishes</a></p>
        <p>Please keep this a secret and bring some holiday cheer!</p>
      </div>
    </div>
  </div>
</body></html>
HTML;
    $mail->AltBody = "Hi {$giver['first_name']},\n\nYour Secret Santa person for $year is {$receiver['first_name']} {$receiver['last_name']}.\n\n"
        . 'Budget Limit: ' . ($config['app']['budget_limit'] ?? '$50') . "\n\nView their wishes and update your own here: $wishlistUrl";
    santaAppendPairingNote($mail, $config['app']['note_to_users'] ?? '');
    return $mail;
}

function santaWishlistLinkEmail(array $config, array $participant): PHPMailer
{
    $name = htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $url = rtrim($config['app']['base_url'] ?? '', '/') . '/wishes.php?key=' . rawurlencode($participant['wish_key']);
    $htmlUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mail = santaCreateMailer($config['smtp']);
    $mail->addAddress($participant['email'], trim($participant['first_name'] . ' ' . $participant['last_name']));
    $mail->Subject = 'Your Secret Santa Wishlist Link 🎄';
    $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Your Wishlist Link</title></head>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;background:#0b1b33;color:#ffffff;padding:24px;">'
        . '<h1>Your Secret Santa wishlist</h1><p>Hi ' . $name . '!</p>'
        . '<p>Use your personal link to access your wishlist:</p><p><a style="color:#ffd700;" href="' . $htmlUrl . '">Open My Wishlist</a></p>'
        . '<p>Keep this link private. If a new key was requested, your previous link no longer works.</p></body></html>';
    $mail->AltBody = "Hi {$participant['first_name']},\n\nUse your personal link to access your wishlist:\n{$url}\n\n"
        . 'Keep this link private. If a new key was requested, your previous link no longer works.';
    return $mail;
}
