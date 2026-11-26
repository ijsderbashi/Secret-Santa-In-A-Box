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
