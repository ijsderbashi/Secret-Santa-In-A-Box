<?php
// admin_secret_santa.php
// Admin/testing page for Secret Santa pairing.
// - Can run in TEST mode (no DB writes) or COMMIT mode (writes to DB for selected year).
// - Can send a test email using the configured SMTP/Gmail settings.
// - Shows a table of all participants, their wish lists, and panel visits.

require __DIR__ . '/vendor/autoload.php'; // PHPMailer via Composer
require __DIR__ . '/santa_draw.php';
$config = require __DIR__ . '/config.php';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------
// Minimal "auth" – change this to something safer or integrate with real auth
// Example usage: /admin_secret_santa.php?key=changeme
// -----------------------------------------------------------------
$ADMIN_KEY = $config['app']['admin_key'] ?? 'changeme';
if (!isset($_GET['key']) || $_GET['key'] !== $ADMIN_KEY) {
    http_response_code(403);
    echo "Forbidden. Admin key missing or incorrect.";
    exit;
}

// ------------------------
// Helper: create PHPMailer instance
// ------------------------
function createMailer(array $smtpConfig): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpConfig['host'];
    $mail->Port = $smtpConfig['port'];

    if (!empty($smtpConfig['username'])) {
        // Real SMTP (e.g., Gmail) – use auth + STARTTLS
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpConfig['username'];
        $mail->Password   = $smtpConfig['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = true;
    } else {
        // No username configured (e.g., MailHog dev) – no auth, no TLS
        $mail->SMTPAuth    = false;
        $mail->SMTPSecure  = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

// ------------------------
// Handle form submit
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed.');
    }
}

$generatedPairs = [];
$debugMessage = '';
$error = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$defaultYear = santaCurrentYear($config);
$requestedYear = $_POST['year'] ?? $_GET['year'] ?? $defaultYear;
$year = filter_var($requestedYear, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => SANTA_MIN_DRAW_YEAR, 'max_range' => SANTA_MAX_DRAW_YEAR],
]);
if ($year === false) {
    $error = 'Choose a draw year between ' . SANTA_MIN_DRAW_YEAR . ' and ' . SANTA_MAX_DRAW_YEAR . '.';
    $year = $defaultYear;
}

try {
    $savedHistoryYears = santaHistoryYears($pdo);
} catch (PDOException | RuntimeException $e) {
    error_log('Secret Santa settings could not be loaded: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not load draw settings. Check that the database migration has been applied.');
}

$historyYears = filter_var($_POST['history_years'] ?? $savedHistoryYears, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => SANTA_MAX_HISTORY_YEARS],
]);
$commitRequested = !empty($_POST['commit_to_db']);
$action = $_POST['action'] ?? '';

// Default test email address: SMTP from_email if set
$defaultTestEmail = $config['smtp']['from_email'] ?? '';
$testEmail        = $_POST['test_email'] ?? $defaultTestEmail;

// Utility for HTML escaping
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

if ($isPost && $action === 'save_history' && $error === null) {
    if ($historyYears === false) {
        $error = 'Choose a history window from 0 to 5 years.';
    } else {
        try {
            $update = $pdo->prepare('UPDATE santa_draw_settings SET history_years = ? WHERE id = 1');
            $update->execute([$historyYears]);
            $savedHistoryYears = $historyYears;
            $debugMessage = 'The history default was saved.';
        } catch (PDOException $e) {
            error_log('Secret Santa history setting could not be saved: ' . $e->getMessage());
            $error = 'Could not save the history default.';
        }
    }
}

if ($isPost && $action === 'set_participation' && $error === null) {
    $participantId = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $status = $_POST['participation'] ?? null;
    if ($participantId === false || !in_array($status, ['0', '1'], true)) {
        $error = 'Choose a valid participant and participation status.';
    } else {
        try {
            $pdo->beginTransaction();
            santaHistoryYears($pdo, true);
            $update = $pdo->prepare("
                INSERT INTO santa_participation (participant_id, effective_year, is_in)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE is_in = VALUES(is_in)
            ");
            $update->execute([$participantId, $year, (int)$status]);
            $pdo->commit();
            $debugMessage = 'Participation updated for ' . $year . '.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Secret Santa participation could not be saved: ' . $e->getMessage());
            $error = 'Could not update participation. Check that the participant still exists.';
        }
    }
}

// ------------------------
// ACTION: Edit participant (one identity across all years)
// ------------------------
$editValues = null;
if ($isPost && $action === 'edit_participant' && $error === null) {
    $participantId = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $editValues = [
        'id' => $participantId,
        'first_name' => is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '',
        'last_name' => is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '',
        'email' => is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '',
    ];

    if (!$participantId) {
        $error = 'Choose a valid participant.';
    } elseif ($editValues['first_name'] === '' || $editValues['email'] === '') {
        $error = 'First name and email are required.';
    } elseif (mb_strlen($editValues['first_name']) > 100 || mb_strlen($editValues['last_name']) > 100) {
        $error = 'First name and last name must each be 100 characters or fewer.';
    } elseif (strlen($editValues['email']) > 255 || !filter_var($editValues['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address of 255 characters or fewer.';
    } else {
        try {
            $pdo->beginTransaction();
            santaHistoryYears($pdo, true);
            $find = $pdo->prepare('SELECT email FROM participants WHERE id = ? FOR UPDATE');
            $find->execute([$participantId]);
            $existing = $find->fetch();
            if (!$existing) {
                throw new DomainException('This participant no longer exists. Refresh the roster.');
            }
            if (strtolower(trim($existing['email'])) !== strtolower($editValues['email'])) {
                $duplicate = $pdo->prepare('SELECT id FROM participants WHERE LOWER(TRIM(email)) = LOWER(?) AND id <> ? LIMIT 1');
                $duplicate->execute([$editValues['email'], $participantId]);
                if ($duplicate->fetchColumn() !== false) {
                    throw new DomainException('That email address is already used by another participant.');
                }
            }
            $update = $pdo->prepare('UPDATE participants SET first_name = ?, last_name = ?, email = ? WHERE id = ?');
            $update->execute([$editValues['first_name'], $editValues['last_name'], $editValues['email'], $participantId]);
            $pdo->commit();
            $editValues = null;
            $debugMessage = 'Participant updated across all years. Historical pairings and participation are preserved. No email was sent.';
        } catch (PDOException | DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                $error = h($e->getMessage());
            } else {
                error_log('Secret Santa participant update failed: ' . $e->getMessage());
                $error = 'Could not update the participant. Please try again.';
            }
        }
    }
}

// ------------------------
// ACTION: Add participant
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_participant') {
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $familyUnit = isset($_POST['family_unit']) ? (int)$_POST['family_unit'] : 0;

    if ($firstName === '' || $email === '') {
        $error = 'First name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $wishKey = bin2hex(random_bytes(16));
            $ins = $pdo->prepare("
                INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
                VALUES (:first_name, :last_name, :email, :family_unit, :wish_key)
            ");
            $ins->execute([
                ':first_name'  => mb_substr($firstName, 0, 100),
                ':last_name'   => mb_substr($lastName, 0, 100),
                ':email'       => mb_substr($email, 0, 255),
                ':family_unit' => $familyUnit,
                ':wish_key'    => $wishKey,
            ]);
            $debugMessage = 'Participant added successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to add participant: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Resend email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resend_email') {
    $pidToResend = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
    if ($pidToResend > 0 && $error === null) {
        try {
            // Find assignment for current year
            $pairStmt = $pdo->prepare("SELECT receiver_id FROM secret_santa_pairs WHERE year = :year AND giver_id = :giver_id");
            $pairStmt->execute([':year' => $year, ':giver_id' => $pidToResend]);
            $pair = $pairStmt->fetch();

            if ($pair) {
                // Load giver and receiver details
                $giverStmt = $pdo->prepare("SELECT * FROM participants WHERE id = :id");
                $giverStmt->execute([':id' => $pidToResend]);
                $giver = $giverStmt->fetch();

                $receiverStmt = $pdo->prepare("SELECT * FROM participants WHERE id = :id");
                $receiverStmt->execute([':id' => $pair['receiver_id']]);
                $receiver = $receiverStmt->fetch();

                if ($giver && $receiver && !empty($giver['email'])) {
                    $mailer = createMailer($config['smtp']);
                    $baseUrl = $config['app']['base_url'] ?? '';

                    // We need a way to build the email HTML.
                    // For now, I'll copy the helper from secret_santa.php or implement a shared one.
                    // Let's implement it here as a helper for now.

                    $giverName    = h($giver['first_name'] . ' ' . $giver['last_name']);
                    $receiverName = h($receiver['first_name'] . ' ' . $receiver['last_name']);
                    $wishKey      = urlencode($giver['wish_key']);
                    $wishlistUrl  = rtrim($baseUrl, '/') . '/wishes.php?key=' . $wishKey;
                    $budget       = h($config['app']['budget_limit'] ?? '$50');

                    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Secret Santa $year</title></head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">🎄 Secret Santa $year 🎄</h1>
      <p style="font-size:16px;margin:10px 0 20px;">Hi <strong>{$giverName}</strong>!<br>The elves have spoken...</p>
      <div style="background:#ffffff;border-radius:10px;padding:30px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">Your Secret Santa person is:</p>
        <div style="font-size:30px;font-weight:bold;color:#b30000;margin:10px 0 10px;">{$receiverName}</div>
        <p style="font-size:14px;color:#333;margin:15px 0 5px;font-weight:bold;">💰 Budget Limit: {$budget}</p>
        <p style="font-size:13px;color:#444;margin:10px 0;">✅ View <strong>{$receiverName}</strong>'s wish list:<br>
          <a href="{$wishlistUrl}#recipient" style="color:#006600;font-weight:bold;">View Their Wishes</a></p>
        <p style="font-size:13px;color:#444;margin:10px 0;">✏️ Enter or update <strong>your own</strong> wish list:<br>
          <a href="{$wishlistUrl}#mine" style="color:#b30000;font-weight:bold;">Enter My Wishes</a></p>
      </div>
    </div>
  </div>
</body>
</html>
HTML;

                    $mail = clone $mailer;
                    $mail->clearAllRecipients();
                    $mail->addAddress($giver['email'], $giverName);
                    $mail->Subject = "Your Secret Santa Person for $year 🎄 (Resend)";
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = "Hi {$giver['first_name']},\n\nYour Secret Santa person for $year is {$receiver['first_name']} {$receiver['last_name']}.\n\nBudget Limit: {$budget}\n\nView wishes here: $wishlistUrl";

                    $mail->send();
                    $debugMessage = 'Assignment email resent to <strong>' . h($giver['email']) . '</strong>.';
                } else {
                    $error = 'Could not find giver/receiver or email is missing.';
                }
            } else {
                $error = 'No assignment found for this participant in ' . h($year) . '. Generate pairings first!';
            }
        } catch (Exception $e) {
            $error = 'Failed to resend email: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Delete participant
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_participant') {
    $pidToDelete = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
    if ($pidToDelete > 0) {
        try {
            $pdo->beginTransaction();
            santaHistoryYears($pdo, true);

            // Delete pairings where this participant is giver or receiver
            $delPairs = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE giver_id = ? OR receiver_id = ?");
            $delPairs->execute([$pidToDelete, $pidToDelete]);

            // Delete legacy email-open stats. Panel visits cascade on participant deletion.
            $delOpens = $pdo->prepare("DELETE FROM email_opens WHERE participant_id = :id");
            $delOpens->execute([':id' => $pidToDelete]);

            // Delete participant
            $delPart = $pdo->prepare("DELETE FROM participants WHERE id = :id");
            $delPart->execute([':id' => $pidToDelete]);

            $pdo->commit();
            $debugMessage = 'Participant and their associated data deleted.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to delete participant: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Send test email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_email') {
    if (empty($testEmail)) {
        $error = 'Please specify a test email address.';
    } else {
        try {
            $mailer = createMailer($GLOBALS['config']['smtp']);

            $mail = clone $mailer;
            $mail->clearAllRecipients();
            $mail->addAddress($testEmail);

            $mail->Subject = 'Secret Santa SMTP Test 🎄';
            $yearNow = date('Y');

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa SMTP Test</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎄 Secret Santa SMTP Test 🎄
      </h1>
      <p style="font-size:16px;margin:10px 0 20px;">
        This is a <strong>test email</strong> from your Secret Santa application for {$yearNow}.
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:20px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          If you can read this, your Gmail/SMTP configuration is working!
        </p>
        <p style="font-size:13px;color:#666;margin:10px 0 0;">
          When the real drawing runs, participants will receive similarly styled emails
          with the name of their Secret Santa recipient in a big festive font.
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        (You triggered this test from the Secret Santa admin page.)
      </p>
    </div>
  </div>
</body>
</html>
HTML;

            $mail->Body    = $htmlBody;
            $mail->AltBody = "Secret Santa SMTP Test.\n\nIf you received this, SMTP is working.";

            $mail->send();
            $debugMessage = 'Test email sent successfully to <strong>' . h($testEmail) . '</strong>.';
        } catch (Exception $e) {
            $error = 'Failed to send test email: ' . h($e->getMessage());
        }
    }
}

if ($isPost && $action === 'generate' && $error === null) {
    if ($historyYears === false) {
        $error = 'Choose a history window from 0 to 5 years.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($commitRequested) {
                santaHistoryYears($pdo, true);
            }
            $activeParticipants = array_filter(
                santaParticipants($pdo, $year),
                static fn(array $participant): bool => (bool)$participant['is_in']
            );
            $excluded = santaExcludedRecipients($pdo, $year, $historyYears);
            $resultPairs = santaGeneratePairs($activeParticipants, $excluded);

            if ($commitRequested) {
                santaSavePairs($pdo, $year, $resultPairs);
            }
            $pdo->commit();

            foreach ($activeParticipants as $giver) {
                $receiver = $resultPairs[$giver['id']];
                $generatedPairs[] = [
                    'giver_name'      => $giver['first_name'] . ' ' . $giver['last_name'],
                    'giver_family'    => $giver['family_unit'],
                    'receiver_name'   => $receiver['first_name'] . ' ' . $receiver['last_name'],
                    'receiver_family' => $receiver['family_unit'],
                ];
            }
            $debugMessage = $commitRequested
                ? "Pairings saved for {$year}."
                : 'Preview generated. No assignments were saved or emailed.';
        } catch (PDOException | DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                $error = h($e->getMessage());
            } else {
                error_log('Secret Santa draw failed: ' . $e->getMessage());
                $error = 'Could not generate or save the draw.';
            }
        }
    }
}

$participants = santaParticipants($pdo, $year);

// ------------------------
// Fetch panel visits for selected year
// ------------------------
$visitStats = [];
try {
    $stmtVisits = $pdo->prepare("
        SELECT participant_id, first_visited_at, last_visited_at, visit_count
        FROM panel_visits
        WHERE year = :year
    ");
    $stmtVisits->execute([':year' => $year]);
    while ($row = $stmtVisits->fetch()) {
        $visitStats[$row['participant_id']] = $row;
    }
} catch (PDOException $e) {
    $visitStats = null;
    error_log('Failed to fetch panel visits. Check that migrate-panel-visits.sql has been applied.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secret Santa Admin</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #0b1b33;
        color: #f5f5f5;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 1100px;
        margin: 30px auto;
        background: #1b2b4d;
        border-radius: 12px;
        padding: 20px 25px 30px;
        box-shadow: 0 0 12px rgba(0,0,0,0.5);
    }
    h1 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 10px;
        color: #ffd700;
    }
    .subtitle {
        text-align: center;
        margin-bottom: 25px;
        color: #d0e0ff;
    }
    form {
        margin-bottom: 25px;
        background: #12203b;
        border-radius: 10px;
        padding: 15px 20px;
    }
    label {
        display: inline-block;
        margin: 5px 0;
    }
    input[type="number"],
    input[type="email"],
    input[type="text"] {
        padding: 5px 8px;
        border-radius: 4px;
        border: 1px solid #4a6bb8;
        background: #0b1427;
        color: #f5f5f5;
    }
    input[type="number"] {
        width: 100px;
    }
    input[type="email"] {
        width: 260px;
    }
    input[type="checkbox"] {
        transform: scale(1.1);
        margin-right: 5px;
    }
    button {
        background: linear-gradient(135deg, #b30000, #006600);
        border: none;
        color: #fff;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
        margin-right: 8px;
    }
    button:hover {
        opacity: 0.9;
    }
    .msg {
        margin-bottom: 15px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 14px;
    }
    .msg.error {
        background: #661212;
        color: #ffd0d0;
    }
    .msg.ok {
        background: #124b2a;
        color: #d0ffd8;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    th, td {
        padding: 6px 8px;
        border: 1px solid #32456f;
        font-size: 13px;
        vertical-align: top;
    }
    th {
        background: #243a6f;
    }
    tr:nth-child(even) {
        background: #19274a;
    }
    tr:nth-child(odd) {
        background: #111b34;
    }
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
    }
    .badge-ok {
        background: #185c2f;
        color: #c8ffd5;
    }
    .badge-warn {
        background: #7a261e;
        color: #ffe0d0;
    }
    .warning-text {
        font-size: 12px;
        color: #ffdddd;
        margin-top: 5px;
    }
    .section-title {
        font-weight: bold;
        margin-top: 5px;
        margin-bottom: 5px;
        color: #ffe9a4;
    }
    .section-divider {
        border-top: 1px solid #32456f;
        margin: 15px 0;
    }
    h2 {
        margin-top: 25px;
        margin-bottom: 8px;
        color: #ffd700;
    }
    .small-note {
        font-size: 11px;
        color: #cbd5ff;
        margin-top: 6px;
    }
</style>
</head>
<body>
<div class="container">
    <h1>🎄 Secret Santa Admin 🎄</h1>
    <div class="subtitle">
        Generate Secret Santa pairings, commit them to the database, test SMTP email delivery,<br>
        and view everyone’s wish lists and open stats for the selected year.
    </div>

    <?php if (!empty($error)): ?>
        <div class="msg error"><?php echo $error; ?></div>
    <?php elseif (!empty($debugMessage)): ?>
        <div class="msg ok"><?php echo $debugMessage; ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="section-title">Pairing Options</div>

        <div>
            <label for="year">Target year:&nbsp;</label>
            <input type="number" name="year" id="year" min="<?php echo SANTA_MIN_DRAW_YEAR; ?>"
                   max="<?php echo SANTA_MAX_DRAW_YEAR; ?>" required value="<?php echo h($year); ?>">
            <button type="submit" name="action" value="view_year">View Roster</button>
        </div>
        <div style="margin-top:10px;">
            <label>Budget Limit: <strong><?php echo h($config['app']['budget_limit'] ?? '$50'); ?></strong></label>
            <span class="small-note">(Change this via <code>BUDGET_LIMIT</code> environment variable)</span>
        </div>
        <div style="margin-top:10px;">
            <label for="history_years">Years of history to exclude</label>
            <select name="history_years" id="history_years">
                <?php for ($i = 0; $i <= SANTA_MAX_HISTORY_YEARS; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo ($historyYears === false ? $savedHistoryYears : $historyYears) === $i ? 'selected' : ''; ?>>
                        <?php echo $i; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" name="action" value="save_history">Save Default</button>
            <div class="small-note">Saved default: <?php echo h($savedHistoryYears); ?>. The scheduled draw uses this value.</div>
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="commit_to_db">
                <strong>Commit this pairing to the database for the selected year</strong>
            </label>
            <div class="warning-text">
                Warning: This will <strong>delete any existing pairings</strong> for that year
                from <code>secret_santa_pairs</code> and replace them with this result.
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="section-title">Email Test</div>
        <div>
            <label for="test_email">Send a test email to:&nbsp;</label>
            <input type="email" name="test_email" id="test_email"
                   value="<?php echo h($testEmail); ?>">
        </div>
        <div class="warning-text">
            This sends a <strong>single test email</strong> using your Gmail/SMTP config.
            It does not send any Secret Santa assignments.
        </div>

        <div style="margin-top:15px;">
            <button type="submit" name="action" value="generate">Generate Pairings</button>
            <button type="submit" name="action" value="test_email">Send Test Email</button>
        </div>
    </form>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="year" value="<?php echo h($year); ?>">
        <div class="section-title">Add Single Participant</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items: flex-end;">
            <div>
                <label for="first_name">First Name</label><br>
                <input type="text" name="first_name" id="first_name" required style="width:140px;">
            </div>
            <div>
                <label for="last_name">Last Name</label><br>
                <input type="text" name="last_name" id="last_name" style="width:140px;">
            </div>
            <div>
                <label for="email">Email</label><br>
                <input type="email" name="email" id="email" required style="width:200px;">
            </div>
            <div>
                <label for="family_unit">Family Unit</label><br>
                <input type="number" name="family_unit" id="family_unit" value="1" required style="width:80px;">
            </div>
            <div>
                <button type="submit" name="action" value="add_participant" style="margin:0;">Add Elf 🎁</button>
            </div>
        </div>
        <div class="small-note">
            Need to add many? Use the <a href="import_users.php?key=<?php echo h(rawurlencode($ADMIN_KEY)); ?>" style="color:#ffd700;">CSV Import</a> for participants and past pairings.
        </div>
    </form>

    <?php if (!empty($generatedPairs)): ?>
        <h2>Generated Pairings for <?php echo h($year); ?></h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Giver</th>
                    <th>Giver Family</th>
                    <th>Receiver</th>
                    <th>Receiver Family</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($generatedPairs as $idx => $row): ?>
                <tr>
                    <td><?php echo h($idx + 1); ?></td>
                    <td><?php echo h($row['giver_name']); ?></td>
                    <td><?php echo h($row['giver_family']); ?></td>
                    <td><?php echo h($row['receiver_name']); ?></td>
                    <td><?php echo h($row['receiver_family']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Wishes & panel visits table -->
    <h2>All Participants, Wish Lists & Panel Visits (Year <?php echo h($year); ?>)</h2>
    <?php if ($visitStats === null): ?>
        <p class="warning-text">Panel visit statistics are unavailable. Check that the panel visits database migration has been applied.</p>
    <?php endif; ?>
    <p class="warning-text">
        Deleting a participant permanently removes their pairings from every year and their panel visit statistics,
        breaking historical continuity and removing those pairings from future repeat-recipient checks.
        Prefer <strong>Edit</strong> to change their details, or mark them <strong>Out</strong> to deactivate them
        from the selected year onward while preserving history. Existing assignments remain until a redraw.
    </p>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Participation</th>
                <th>Email</th>
                <th>Family Unit</th>
                <th>Wishlist Filled?</th>
                <th>First Visit</th>
                <th>Last Visit</th>
                <th>Panel Visits</th>
                <th>Manage</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($participants as $idx => $p): ?>
            <?php
                $stats = $visitStats[$p['id']] ?? null;
                $firstVisit = $stats['first_visited_at'] ?? '';
                $lastVisit  = $stats['last_visited_at'] ?? '';
                $visitCount = $visitStats === null ? 'Unavailable' : ($stats['visit_count'] ?? 0);
            ?>
            <tr>
                <td><?php echo h($idx + 1); ?></td>
                <td><?php echo h($p['first_name'] . ' ' . $p['last_name']); ?></td>
                <td>
                    <form method="post" style="padding:0;background:none;margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="year" value="<?php echo h($year); ?>">
                        <input type="hidden" name="participant_id" value="<?php echo h($p['id']); ?>">
                        <input type="hidden" name="participation" value="<?php echo $p['is_in'] ? '0' : '1'; ?>">
                        <button type="submit" name="action" value="set_participation" aria-pressed="<?php echo $p['is_in'] ? 'true' : 'false'; ?>"
                                aria-label="Participation for <?php echo h($p['first_name'] . ' ' . $p['last_name']); ?>"
                                class="badge <?php echo $p['is_in'] ? 'badge-ok' : 'badge-warn'; ?>" style="margin:0;">
                            <?php echo $p['is_in'] ? 'In' : 'Out'; ?>
                        </button>
                    </form>
                </td>
                <td><?php echo h($p['email']); ?></td>
                <td><?php echo h($p['family_unit']); ?></td>
                <td>
                    <?php if ($p['wish_item1'] || $p['wish_item2'] || $p['wish_item3']): ?>
                        <span class="badge badge-ok">Yes</span>
                    <?php else: ?>
                        <span class="badge badge-warn">No</span>
                    <?php endif; ?>
                </td>
                <td><?php echo h($firstVisit); ?></td>
                <td><?php echo h($lastVisit); ?></td>
                <td><?php echo h($visitCount); ?></td>
                <td style="white-space:nowrap;">
                    <?php
                        $editing = $editValues !== null && (int)$editValues['id'] === (int)$p['id'];
                        $profile = $editing ? $editValues : $p;
                    ?>
                    <details <?php echo $editing ? 'open' : ''; ?>>
                        <summary>Edit <span class="small-note"><?php echo h($p['first_name'] . ' ' . $p['last_name']); ?></span></summary>
                        <form method="post" style="white-space:normal;">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="year" value="<?php echo h($year); ?>">
                            <input type="hidden" name="participant_id" value="<?php echo h($p['id']); ?>">
                            <label for="edit_first_<?php echo h($p['id']); ?>">First Name</label><br>
                            <input type="text" name="first_name" id="edit_first_<?php echo h($p['id']); ?>" maxlength="100" required value="<?php echo h($profile['first_name']); ?>"><br>
                            <label for="edit_last_<?php echo h($p['id']); ?>">Last Name</label><br>
                            <input type="text" name="last_name" id="edit_last_<?php echo h($p['id']); ?>" maxlength="100" value="<?php echo h($profile['last_name']); ?>"><br>
                            <label for="edit_email_<?php echo h($p['id']); ?>">Email</label><br>
                            <input type="email" name="email" id="edit_email_<?php echo h($p['id']); ?>" maxlength="255" required value="<?php echo h($profile['email']); ?>">
                            <p class="small-note">Changes apply across all years. Pairings, participation, and wishlist links are preserved. Use Resend separately if needed.</p>
                            <button type="submit" name="action" value="edit_participant">Save</button>
                            <a href="?key=<?php echo h(rawurlencode($ADMIN_KEY)); ?>&amp;year=<?php echo h($year); ?>">Cancel</a>
                        </form>
                    </details>
                    <form method="post" style="padding:0;background:none;margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="year" value="<?php echo h($year); ?>">
                        <input type="hidden" name="participant_id" value="<?php echo h($p['id']); ?>">
                        <button type="submit" name="action" value="resend_email"
                                style="background:#006600;padding:4px 8px;font-size:11px;margin:0;">
                            Resend
                        </button>
                    </form>
                    <form method="post" onsubmit="return confirm(this.dataset.warning);"
                          data-warning="<?php echo h('Permanently delete ' . $p['first_name'] . ' ' . $p['last_name'] . '? This removes their pairings from EVERY year and their panel visit statistics, breaking historical continuity and removing those pairings from future repeat-recipient checks. Prefer editing their details or marking them Out to deactivate them while preserving history. Cancel to keep this person, or OK to permanently delete.'); ?>"
                          style="padding:0;background:none;margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="year" value="<?php echo h($year); ?>">
                        <input type="hidden" name="participant_id" value="<?php echo h($p['id']); ?>">
                        <button type="submit" name="action" value="delete_participant"
                                style="background:#b30000;padding:4px 8px;font-size:11px;margin:0;">
                            Delete
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="small-note">
        Visits count panel loads and refreshes this year, excluding wishlist saves. Automated link checks may also count.
    </div>
</div>
</body>
</html>

