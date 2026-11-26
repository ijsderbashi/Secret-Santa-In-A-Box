<?php
// admin_secret_santa.php
// Admin/testing page for Secret Santa pairing.
// - Previews draws or saves and emails them after explicit confirmation.
// - Can send a test email using the configured SMTP/Gmail settings.
// - Shows a table of all participants, their wish lists, and panel visits.

require __DIR__ . '/vendor/autoload.php'; // PHPMailer via Composer
require __DIR__ . '/santa_database.php';
require __DIR__ . '/santa_draw.php';
require __DIR__ . '/santa_wishlist.php';
require __DIR__ . '/santa_mail.php';
$config = require __DIR__ . '/config.php';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

use PHPMailer\PHPMailer\Exception;

try {
    $pdo = santaDatabase($config);
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
$confirmation = null;
$expandedParticipantId = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if (!$isPost && isset($_SESSION['admin_flash'])) {
    $debugMessage = $_SESSION['admin_flash']['message'];
    $error = $_SESSION['admin_flash']['error'];
    $expandedParticipantId = $_SESSION['admin_flash']['participant_id'];
    unset($_SESSION['admin_flash']);
}

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
    exit('Could not load draw settings. Check the server logs.');
}

$historyYears = filter_var($_POST['history_years'] ?? $savedHistoryYears, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => SANTA_MAX_HISTORY_YEARS],
]);
$action = $_POST['action'] ?? '';

// Default test email address: SMTP from_email if set
$defaultTestEmail = $config['smtp']['from_email'] ?? '';
$testEmail        = $_POST['test_email'] ?? $defaultTestEmail;

// Utility for HTML escaping
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function adminParticipantEmail(PDO $pdo, array $config, array $participant, int $year): \PHPMailer\PHPMailer\PHPMailer {
    $find = $pdo->prepare('SELECT p.* FROM secret_santa_pairs s JOIN participants p ON p.id = s.receiver_id WHERE s.year = ? AND s.giver_id = ? LIMIT 1');
    $find->execute([$year, $participant['id']]);
    $receiver = $find->fetch();
    return $receiver
        ? santaAssignmentEmail($config, $participant, $receiver, $year, true)
        : santaWishlistLinkEmail($config, $participant);
}

// Include assignment IDs so even a redraw with the same recipients invalidates
// a stale confirmation. Wishlist edits and visit counts do not affect a draw.
function adminDrawState(PDO $pdo, int $year, int $historyYears): array {
    $participants = array_filter(santaParticipants($pdo, $year), static fn($p) => (bool)$p['is_in']);
    $statement = $pdo->prepare('SELECT id, giver_id, receiver_id FROM secret_santa_pairs WHERE year = ? ORDER BY id');
    $statement->execute([$year]);
    $existing = $statement->fetchAll();
    $excluded = santaExcludedRecipients($pdo, $year, $historyYears);
    ksort($excluded);
    foreach ($excluded as &$receivers) {
        ksort($receivers);
    }
    unset($receivers);
    $identities = array_map(static fn($p) => array_intersect_key($p, array_flip([
        'id', 'first_name', 'last_name', 'email', 'family_unit', 'wish_key',
    ])), $participants);
    return [
        'participants' => $participants,
        'excluded' => $excluded,
        'existing' => count($existing),
        'fingerprint' => hash('sha256', serialize([$identities, $existing, $excluded])),
    ];
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
        'family_unit' => is_string($_POST['family_unit'] ?? null) ? $_POST['family_unit'] : '',
    ];
    $familyUnit = filter_var($editValues['family_unit'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => -2147483648, 'max_range' => 2147483647],
    ]);

    if (!$participantId) {
        $error = 'Choose a valid participant.';
    } elseif ($editValues['first_name'] === '' || $editValues['email'] === '') {
        $error = 'First name and email are required.';
    } elseif (mb_strlen($editValues['first_name']) > 100 || mb_strlen($editValues['last_name']) > 100) {
        $error = 'First name and last name must each be 100 characters or fewer.';
    } elseif (strlen($editValues['email']) > 255 || !filter_var($editValues['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address of 255 characters or fewer.';
    } elseif ($familyUnit === false) {
        $error = 'Enter a whole number between -2147483648 and 2147483647 for the family group.';
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
            $update = $pdo->prepare('UPDATE participants SET first_name = ?, last_name = ?, email = ?, family_unit = ? WHERE id = ?');
            $update->execute([$editValues['first_name'], $editValues['last_name'], $editValues['email'], $familyUnit, $participantId]);
            $pdo->commit();
            $editValues = null;
            $debugMessage = 'Participant updated across all years. The family group applies to future draws; existing assignments are unchanged. No email was sent.';
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

if ($isPost && $action === 'regenerate_key' && $error === null) {
    $participantId = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    try {
        if (!$participantId) {
            throw new DomainException('Choose a valid participant.');
        }
        $pdo->beginTransaction();
        santaHistoryYears($pdo, true);
        $find = $pdo->prepare('SELECT * FROM participants WHERE id = ? FOR UPDATE');
        $find->execute([$participantId]);
        $person = $find->fetch();
        if (!$person) {
            throw new DomainException('This participant no longer exists. Refresh the roster.');
        }
        if (!is_string($_POST['key_version'] ?? null)
            || !hash_equals(hash('sha256', $person['wish_key'] ?? ''), $_POST['key_version'])) {
            throw new DomainException('The key has already changed. Refresh the roster before regenerating it again.');
        }
        $update = $pdo->prepare('UPDATE participants SET wish_key = ? WHERE id = ?');
        $person['wish_key'] = bin2hex(random_bytes(32));
        $update->execute([$person['wish_key'], $participantId]);
        $mail = adminParticipantEmail($pdo, $config, $person, $year);
        $pdo->commit();
        $expandedParticipantId = $participantId;
        try {
            $mail->send();
            $debugMessage = 'Wishlist key regenerated. The old link no longer works. An email with the new link was sent to <strong>' . h($person['email']) . '</strong>.';
        } catch (Exception $e) {
            error_log('Secret Santa regenerated-key email failed for participant ' . $participantId);
            $debugMessage = 'Wishlist key regenerated. The old link no longer works.';
            $error = 'The email with the new link could not be sent. Use Resend for this participant to retry without regenerating their key again.';
        }
    } catch (PDOException | DomainException | Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            $error = h($e->getMessage());
        } else {
            error_log('Secret Santa key regeneration failed: ' . $e->getMessage());
            $error = 'Could not regenerate the key. Please try again.';
        }
    }
}

// ------------------------
// ACTION: Add participant
// ------------------------
if ($isPost && $action === 'add_participant' && $error === null) {
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $familyUnit = filter_var($_POST['family_unit'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => -2147483648, 'max_range' => 2147483647],
    ]);

    if ($firstName === '' || $email === '') {
        $error = 'First name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($familyUnit === false) {
        $error = 'Enter a whole number for the family group.';
    } else {
        try {
            $pdo->beginTransaction();
            santaHistoryYears($pdo, true);
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
            $pdo->commit();
            $debugMessage = 'Participant added successfully.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to add participant: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Resend email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resend_email') {
    $pidToResend = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($error === null) {
        try {
            if (!$pidToResend) {
                throw new DomainException('Choose a valid participant.');
            }
            $find = $pdo->prepare('SELECT * FROM participants WHERE id = ?');
            $find->execute([$pidToResend]);
            $participant = $find->fetch();
            if (!$participant) {
                throw new DomainException('This participant no longer exists. Refresh the roster.');
            }
            adminParticipantEmail($pdo, $config, $participant, $year)->send();
            $debugMessage = 'Email with their wishlist link sent to <strong>' . h($participant['email']) . '</strong>.';
        } catch (PDOException | DomainException | Exception $e) {
            if ($e instanceof DomainException) {
                $error = h($e->getMessage());
            } else {
                error_log('Secret Santa resend failed for participant ' . $pidToResend);
                $error = 'Could not resend the email. Please try again.';
            }
        }
    }
}

// ------------------------
// ACTION: Delete participant
// ------------------------
if ($isPost && $action === 'delete_participant' && $error === null) {
    $pidToDelete = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
    if ($pidToDelete > 0) {
        try {
            $pdo->beginTransaction();
            santaHistoryYears($pdo, true);

            // Delete pairings where this participant is giver or receiver
            $delPairs = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE giver_id = ? OR receiver_id = ?");
            $delPairs->execute([$pidToDelete, $pidToDelete]);

            // Panel visits and participation cascade on participant deletion.
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
if ($isPost && $action === 'test_email' && $error === null) {
    if (empty($testEmail)) {
        $error = 'Please specify a test email address.';
    } else {
        try {
            $mailer = santaCreateMailer($GLOBALS['config']['smtp']);

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

// Confirmation state lives on the server: changing fields or replaying a final
// submission cannot bypass the overwrite warning or draw and email twice.
if ($isPost && $action === 'cancel_draw') {
    unset($_SESSION['admin_pending_draw']);
    $debugMessage = 'Draw cancelled. Assignments are unchanged and no emails were sent.';
}

if ($isPost && in_array($action, ['test_draw', 'prepare_draw', 'confirm_draw'], true) && $error === null) {
    try {
        if ($action === 'confirm_draw') {
            $pending = $_SESSION['admin_pending_draw'] ?? null;
            if (!$pending || !is_string($_POST['draw_token'] ?? null)
                || !hash_equals($pending['token'], $_POST['draw_token'])
                || ($_POST['confirmation_step'] ?? '') !== $pending['step']) {
                throw new DomainException('This draw confirmation has expired or was already used. Start again with Draw and Send.');
            }
            $year = $pending['year'];
            $historyYears = $pending['history_years'];
        }
        if ($historyYears === false) {
            throw new DomainException('Choose a history window from 0 to 5 years.');
        }
        $pdo->beginTransaction();
        santaHistoryYears($pdo, true);
        $state = adminDrawState($pdo, $year, $historyYears);

        if ($action === 'test_draw') {
            $resultPairs = santaGeneratePairs($state['participants'], $state['excluded']);
            foreach ($state['participants'] as $giver) {
                $receiver = $resultPairs[$giver['id']];
                $generatedPairs[] = [
                    'giver_name' => trim($giver['first_name'] . ' ' . $giver['last_name']),
                    'giver_family' => $giver['family_unit'],
                    'receiver_name' => trim($receiver['first_name'] . ' ' . $receiver['last_name']),
                    'receiver_family' => $receiver['family_unit'],
                ];
            }
            $pdo->commit();
            $debugMessage = 'Preview generated. No assignments were saved or emailed. Draw and Send generates a fresh draw.';
        } elseif ($action === 'prepare_draw') {
            if (count($state['participants']) < 2) {
                throw new DomainException('At least two participants must be In to generate a draw.');
            }
            $pdo->commit();
            $confirmation = [
                'token' => bin2hex(random_bytes(32)),
                'step' => 'draw',
                'year' => $year,
                'history_years' => $historyYears,
                'participant_count' => count($state['participants']),
                'existing' => $state['existing'],
                'fingerprint' => $state['fingerprint'],
            ];
            $_SESSION['admin_pending_draw'] = $confirmation;
        } else {
            if (!hash_equals($pending['fingerprint'], $state['fingerprint'])) {
                unset($_SESSION['admin_pending_draw']);
                throw new DomainException('The roster, keys, history, or saved draw changed during confirmation. Review the panel and start Draw and Send again. No assignments were changed or emails sent.');
            }
            if ($pending['step'] === 'draw' && $state['existing'] > 0) {
                $pdo->commit();
                $pending['step'] = 'overwrite';
                $_SESSION['admin_pending_draw'] = $pending;
                $confirmation = $pending;
            } else {
                // Consume once, before committing or contacting SMTP. The session
                // lock also serializes duplicate submissions from this browser.
                unset($_SESSION['admin_pending_draw']);
                $resultPairs = santaGeneratePairs($state['participants'], $state['excluded']);
                santaSavePairs($pdo, $year, $resultPairs);
                $pdo->commit();

                $sent = 0;
                $failed = [];
                foreach ($state['participants'] as $giver) {
                    try {
                        santaAssignmentEmail($config, $giver, $resultPairs[$giver['id']], $year)->send();
                        $sent++;
                    } catch (Exception $e) {
                        $failed[] = $giver['email'];
                        error_log('Secret Santa assignment email failed for participant ' . $giver['id'] . ': ' . $e->getMessage());
                    }
                }
                $debugMessage = "Draw saved for {$year}. Assignment emails sent: {$sent} of " . count($state['participants']) . '.';
                if ($failed) {
                    $error = 'The draw is saved, but email delivery failed for: ' . h(implode(', ', $failed))
                        . '. Use Resend for those participants to retry their saved assignment; do not draw again to retry emails.';
                }
            }
        }
    } catch (PDOException | DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            $error = h($e->getMessage());
        } else {
            error_log('Secret Santa draw failed: ' . $e->getMessage());
            $error = 'Could not generate or save the draw. Existing assignments were preserved.';
        }
    }
}

// Redirect after completed mutations so refreshing cannot resend emails, rotate
// a key again, or repeat a draw. Errors and previews keep entered values visible.
if ($isPost && $confirmation === null && !$generatedPairs && $debugMessage !== '') {
    $_SESSION['admin_flash'] = [
        'message' => $debugMessage,
        'error' => $error,
        'participant_id' => $expandedParticipantId,
    ];
    header('Location: admin_secret_santa.php?key=' . rawurlencode($ADMIN_KEY) . '&year=' . $year, true, 303);
    exit;
}

$participants = santaParticipants($pdo, $year);

// ------------------------
// Fetch panel visits for selected year
// ------------------------
$visitStats = [];
$stmtVisits = $pdo->prepare("
    SELECT participant_id, first_visited_at, last_visited_at, visit_count
    FROM panel_visits
    WHERE year = :year
");
$stmtVisits->execute([':year' => $year]);
while ($row = $stmtVisits->fetch()) {
    $visitStats[$row['participant_id']] = $row;
}

require __DIR__ . '/admin_panel.php';
