<?php
// View included by the authenticated admin controller; not an HTTP entry point.
$adminUrl = 'admin_secret_santa.php?key=' . rawurlencode($ADMIN_KEY) . '&year=' . $year;
$activeCount = count(array_filter($participants, static fn($p) => (bool)$p['is_in']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secret Santa Admin</title>
<style>
    :root { color-scheme: dark; --page:#0b1b33; --card:#10203b; --line:#36537e; --muted:#c4d3ee; --gold:#ffd700; }
    * { box-sizing:border-box; }
    [hidden] { display:none !important; }
    body { margin:0; background:var(--page); color:#f5f7ff; font-family:Arial,Helvetica,sans-serif; line-height:1.45; }
    .container { max-width:1240px; margin:16px auto; padding:24px; background:#1b2e50; border-radius:14px; box-shadow:0 8px 28px #0005; }
    h1 { color:var(--gold); font-size:36px; text-align:center; margin:0 0 6px; }
    .subtitle { text-align:center; color:#e0e9ff; margin:0 0 26px; }
    h2 { color:var(--gold); font-size:25px; margin:0 0 3px; line-height:1.25; }
    h3 { margin:0 0 16px; font-size:20px; color:var(--gold); }
    p { margin:0 0 18px; }
    a { color:var(--gold); text-underline-offset:3px; }
    button, input, select { font:inherit; }
    input, select { border:1px solid #587fbe; background:#0b1427; color:#f5f7ff; padding:8px 10px; border-radius:5px; min-height:40px; max-width:100%; }
    input { width:100%; }
    input[type=number] { width:116px; }
    input[readonly] { color:var(--muted); }
    label { display:block; margin-bottom:5px; }
    button, .button { appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:9px 16px; border:0; border-radius:8px; color:#fff; background:linear-gradient(120deg,#b80000 0%,#934013 45%,#006400 100%); background-clip:padding-box; text-decoration:none; cursor:pointer; font-weight:500; }
    button:hover, .button:hover { box-shadow:inset 0 0 0 1px #ffffff40; }
    button:disabled { opacity:.6; cursor:wait; }
    :focus-visible { outline:3px solid #ffe674; outline-offset:3px; }
    .secondary { background:#263f65; border-color:#57749c; }
    .danger { background:#b71313; }
    .success { background:#096b23; }
    .card { border:1px solid var(--line); background:var(--card); padding:22px; border-radius:12px; }
    .settings { display:flex; align-items:center; justify-content:space-between; gap:20px; margin-bottom:24px; padding:16px 20px; flex-wrap:wrap; }
    .settings-group { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
    .settings-group label { margin:0; }
    .budget { padding:0 22px; border-left:1px solid #6784b0; border-right:1px solid #6784b0; }
    .budget strong { margin-left:6px; }
    .history { flex:1; justify-content:flex-end; }
    .history select { width:64px; }
    .help { cursor:help; color:var(--muted); }
    .tools { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px; }
    .tools .card { display:flex; flex-direction:column; }
    .draw-buttons { display:flex; gap:12px; margin:6px 0 16px; }
    .draw-buttons button { flex:1; padding:13px; font-size:18px; }
    .button-icon { width:24px; height:24px; flex-shrink:0; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .notice { border:1px solid #3a527c; border-radius:8px; background:#1c3052; padding:12px 14px; font-size:14px; }
    .notice p { margin:0; }
    .notice ul { padding-left:19px; margin:0; }
    .warning { background:#38292f; border-color:#80564e; color:#ffe2d8; }
    .draw-warning { margin:12px 0 0; }
    .small-note { color:var(--muted); font-size:13px; margin:12px 0 0; }
    .email-controls { display:flex; align-items:end; gap:12px; margin-bottom:16px; }
    .email-controls .field { flex:1; min-width:0; }
    .email-controls button { white-space:nowrap; }
    .add-card { margin-bottom:24px; }
    .add-grid { display:grid; grid-template-columns:1fr 1fr 1.35fr 116px auto; gap:18px; align-items:end; }
    .add-grid input[type=number] { width:100%; }
    .roster-intro { color:#e0e9ff; margin:6px 0 16px; }
    .table-scroll { overflow-x:auto; margin-top:16px; border:1px solid var(--line); border-radius:8px; }
    table { width:100%; min-width:1060px; border-collapse:collapse; font-size:14px; }
    th, td { padding:11px 10px; text-align:left; border-right:1px solid #32476c; border-bottom:1px solid #32476c; vertical-align:middle; }
    th { background:#243e71; font-weight:600; text-align:center; }
    th:last-child, td:last-child { border-right:0; }
    tbody tr:last-child td { border-bottom:0; }
    .participant-row { background:#111f3a; }
    .participant-row:nth-of-type(4n+3) { background:#192a48; }
    .date { white-space:nowrap; font-size:13px; font-variant-numeric:tabular-nums; }
    .badge { display:inline-block; padding:4px 8px; border-radius:5px; font-size:13px; background:#216a36; color:#fff; }
    .badge-warn { background:#893024; }
    .manage-toggle { width:160px; height:36px; padding:5px 8px; font-size:13px; white-space:nowrap; }
    .manage-toggle span { overflow:hidden; text-overflow:ellipsis; }
    .participation-column, .centered-column { text-align:center; }
    .participation { display:flex; align-items:center; justify-content:center; width:100%; gap:7px; padding:0; min-height:34px; background:transparent; font-size:13px; }
    .participation-label { width:3ch; text-align:left; }
    .switch { display:inline-block; position:relative; width:37px; height:22px; background:#737b87; border-radius:20px; flex-shrink:0; }
    .switch::after { content:''; display:block; position:absolute; width:16px; height:16px; top:3px; left:3px; border-radius:50%; background:white; }
    .participation[aria-pressed=true] .switch { background:#24a33d; }
    .participation[aria-pressed=true] .switch::after { left:18px; }
    .editor-cell { padding:22px; background:#203557; }
    .editor-grid { display:grid; grid-template-columns:1fr 1fr 1.4fr 140px; gap:18px; }
    .editor-grid input[type=number] { width:100%; }
    .editor-actions { display:flex; align-items:center; gap:12px; margin:14px 0 20px; }
    .participant-actions { border-top:1px solid #456082; padding-top:18px; }
    .participant-actions > .small-note { margin:0 0 18px; }
    .participant-action-grid { display:grid; grid-template-columns:1fr 1fr 1.3fr; gap:22px; }
    .participant-action-grid form { display:flex; flex-direction:column; align-items:flex-start; }
    .participant-action-grid p { margin:0 0 14px; font-size:14px; color:var(--muted); }
    .participant-action-grid .warning { color:#ffe2d8; }
    .participant-action-grid button { margin-top:auto; }
    .message { border:1px solid #4b8763; border-radius:8px; background:#16442d; color:#e4ffec; padding:14px 16px; margin-bottom:20px; }
    .message.error { background:#4b2427; border-color:#a35b5b; color:#ffe5e5; }
    .preview { margin-bottom:24px; }
    .preview table { min-width:550px; }
    .preview p { margin:8px 0 0; }
    dialog { color:#f5f7ff; background:#172a49; border:1px solid #5c7eac; border-radius:12px; padding:28px; width:min(540px,calc(100% - 32px)); box-shadow:0 20px 60px #0008; }
    dialog::backdrop { background:#020b1cbb; }
    dialog h2 { margin-bottom:16px; }
    .dialog-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:12px; margin-top:22px; }
    @media (max-width:1100px) {
        .budget { border-right:0; padding-right:0; }
        .history { flex-basis:100%; justify-content:flex-start; border-top:1px solid var(--line); padding-top:14px; }
        .add-grid { grid-template-columns:1fr 1fr 1.3fr; }
        .email-controls { flex-wrap:wrap; }
    }
    @media (max-width:700px) {
        .container { margin:0; border-radius:0; padding:18px 12px; }
        h1 { font-size:27px; }
        h2 { font-size:23px; }
        .subtitle { font-size:14px; }
        .tools { grid-template-columns:1fr; gap:18px; }
        .card { padding:18px; }
        .settings { align-items:flex-start; gap:14px; }
        .budget { padding:0; border:0; flex-basis:100%; }
        .history { gap:8px; }
        .history label { flex-basis:100%; }
        .add-grid { grid-template-columns:1fr 1fr; gap:14px; }
        .add-grid .email-field { grid-column:1 / -1; }
        .draw-buttons button { font-size:16px; }
        .email-controls { display:block; }
        .email-controls button { margin-top:12px; }
        .roster { padding:18px 12px; }
        .participant-action-grid { grid-template-columns:1fr; }
    }
</style>
</head>
<body>
<main class="container">
    <h1>🎄 Secret Santa Admin 🎄</h1>
    <p class="subtitle">Manage participants, run the draw, send assignments, test email delivery, and track wish lists for your Secret Santa.</p>

    <?php if ($debugMessage !== ''): ?>
        <div class="message" role="status"><?php echo $debugMessage; ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="message error" role="alert"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="post" id="draw-options" class="card settings">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <div class="settings-group">
            <label for="year">Year:</label>
            <input type="number" name="year" id="year" min="<?php echo SANTA_MIN_DRAW_YEAR; ?>" max="<?php echo SANTA_MAX_DRAW_YEAR; ?>" required value="<?php echo h($year); ?>">
            <button type="submit" name="action" value="view_year">Load Year</button>
        </div>
        <div class="settings-group budget">Budget Limit: <strong><?php echo h($config['app']['budget_limit'] ?? '$50'); ?></strong></div>
        <div class="settings-group history">
            <label for="history_years">Draw History Exclusion:</label>
            <select name="history_years" id="history_years">
                <?php for ($i = 0; $i <= SANTA_MAX_HISTORY_YEARS; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo ($historyYears === false ? $savedHistoryYears : $historyYears) === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
            <span>year(s)</span>
            <button type="submit" name="action" value="save_history">Save Default</button>
            <span class="help" tabindex="0" aria-label="Saved default: <?php echo h($savedHistoryYears); ?> years. The scheduled draw uses this default. History excludes repeat recipients from previous calendar years." title="Saved default: <?php echo h($savedHistoryYears); ?> years. Used by the scheduled draw. Excludes repeat recipients from previous calendar years.">ⓘ</span>
        </div>
    </form>

    <div class="tools">
        <section class="card" aria-labelledby="draw-title">
            <h2 id="draw-title">Draw &amp; Assignments</h2>
            <p>Run the Secret Santa draw for <?php echo h($year); ?>. <strong><?php echo $activeCount; ?></strong> participants are In.</p>
            <div class="draw-buttons">
                <button type="submit" form="draw-options" name="action" value="test_draw"><svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h3c5 0 7 10 12 10h3m-4-4 4 4-4 4M3 17h3c1.5 0 2.7-.9 3.8-2.2M14.2 9.2C15.3 7.9 16.5 7 18 7h3m-4-4 4 4-4 4"/></svg> Test Draw</button>
                <button type="submit" form="draw-options" name="action" value="prepare_draw"><svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Draw and Send</button>
            </div>
            <div class="notice">
                <ul>
                    <li><strong>Test Draw:</strong> Preview a random draw without saving or sending emails.</li>
                    <li><strong>Draw and Send:</strong> Generate a fresh draw, save it for the selected year, and email every participating person after confirmation.</li>
                </ul>
            </div>
            <p class="notice warning draw-warning"><strong>Redrawing replaces all existing assignments for that year.</strong> Participants receive new assignment emails. Existing wish lists are preserved.</p>
        </section>
        <section class="card" aria-labelledby="email-title">
            <h2 id="email-title">Email Tools</h2>
            <p>Test your email configuration.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="year" value="<?php echo h($year); ?>">
                <div class="email-controls">
                    <div class="field">
                        <label for="test_email">Send a test email to:</label>
                        <input type="email" name="test_email" id="test_email" required value="<?php echo h($testEmail); ?>">
                    </div>
                    <button type="submit" name="action" value="test_email"><svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Send Test Email</button>
                </div>
            </form>
            <div class="notice">Sends a single test email using your configured email server. It does not send any Secret Santa assignments.</div>
        </section>
    </div>

    <section class="card add-card" aria-labelledby="add-title">
        <h2 id="add-title">Add Single Participant</h2>
        <p>Add a new participant to the Secret Santa roster.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="year" value="<?php echo h($year); ?>">
            <div class="add-grid">
                <div><label for="first_name">First Name</label><input type="text" name="first_name" id="first_name" maxlength="100" required></div>
                <div><label for="last_name">Last Name</label><input type="text" name="last_name" id="last_name" maxlength="100"></div>
                <div class="email-field"><label for="email">Email</label><input type="email" name="email" id="email" maxlength="255" required></div>
                <div><label for="family_unit">Family Group</label><input type="number" name="family_unit" id="family_unit" min="-2147483648" max="2147483647" value="1" required></div>
                <button type="submit" name="action" value="add_participant">Add Elf 🎁</button>
            </div>
            <p class="small-note">Need to add many participants or past pairings? Use the <a href="import_users.php?key=<?php echo h(rawurlencode($ADMIN_KEY)); ?>">CSV Import</a>. People in the same family group cannot draw each other.</p>
        </form>
    </section>

    <?php if ($generatedPairs): ?>
    <section class="card preview" aria-labelledby="preview-title">
        <h2 id="preview-title">Test Draw for <?php echo h($year); ?></h2>
        <p>This is a preview. Nothing was saved or emailed. Draw and Send generates a fresh draw.</p>
        <div class="table-scroll" role="region" aria-label="Test draw results" tabindex="0">
            <table>
                <thead><tr><th scope="col">#</th><th scope="col">Giver</th><th scope="col">Family Group</th><th scope="col">Receiver</th><th scope="col">Family Group</th></tr></thead>
                <tbody>
                <?php foreach ($generatedPairs as $idx => $pair): ?>
                    <tr><td><?php echo $idx + 1; ?></td><td><?php echo h($pair['giver_name']); ?></td><td><?php echo h($pair['giver_family']); ?></td><td><?php echo h($pair['receiver_name']); ?></td><td><?php echo h($pair['receiver_family']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="card roster" aria-labelledby="roster-title">
        <h2 id="roster-title">Participants (Year <?php echo h($year); ?>)</h2>
        <p class="roster-intro">Manage participation, resend assignments, and monitor wish lists and portal activity.</p>
        <div class="table-scroll" role="region" aria-label="Participants and activity" tabindex="0">
            <table>
                <thead><tr>
                    <th scope="col">#</th><th scope="col">Name</th><th scope="col" class="participation-column">Participating</th><th scope="col">Email</th><th scope="col" class="centered-column">Family Group</th><th scope="col" class="centered-column">Wishlist Filled?</th><th scope="col">First Visit</th><th scope="col">Last Visit</th><th scope="col" class="centered-column">Panel Visits</th><th scope="col">Manage</th>
                </tr></thead>
                <tbody>
                <?php foreach ($participants as $idx => $p):
                    $id = (int)$p['id'];
                    $name = trim($p['first_name'] . ' ' . $p['last_name']);
                    $stats = $visitStats[$id] ?? null;
                    $editing = $editValues !== null && (int)$editValues['id'] === $id;
                    $expanded = $editing || $expandedParticipantId === $id;
                    $profile = $editing ? $editValues : $p;
                ?>
                    <tr class="participant-row">
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo h($name); ?></td>
                        <td class="participation-column">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="year" value="<?php echo h($year); ?>">
                                <input type="hidden" name="participant_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="participation" value="<?php echo $p['is_in'] ? '0' : '1'; ?>">
                                <button type="submit" name="action" value="set_participation" class="participation" aria-pressed="<?php echo $p['is_in'] ? 'true' : 'false'; ?>" aria-label="Participation for <?php echo h($name); ?>: <?php echo $p['is_in'] ? 'In' : 'Out'; ?>">
                                    <span class="switch" aria-hidden="true"></span><span class="participation-label"><?php echo $p['is_in'] ? 'In' : 'Out'; ?></span>
                                </button>
                            </form>
                        </td>
                        <td><?php echo h($p['email']); ?></td>
                        <td class="centered-column"><?php echo h($p['family_unit']); ?></td>
                        <td class="centered-column"><span class="badge <?php echo santaWishlistItems($p) ? '' : 'badge-warn'; ?>"><?php echo santaWishlistItems($p) ? 'Yes' : 'No'; ?></span></td>
                        <td class="date"><?php echo h($stats['first_visited_at'] ?? '—'); ?></td>
                        <td class="date"><?php echo h($stats['last_visited_at'] ?? '—'); ?></td>
                        <td class="centered-column"><?php echo h($stats['visit_count'] ?? 0); ?></td>
                        <td>
                            <button type="button" class="secondary manage-toggle" aria-label="Manage <?php echo h($name); ?>" title="Manage <?php echo h($p['first_name']); ?>" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="edit-row-<?php echo $id; ?>"><span>Manage <?php echo h($p['first_name']); ?></span></button>
                        </td>
                    </tr>
                    <tr id="edit-row-<?php echo $id; ?>" <?php echo $expanded ? '' : 'hidden'; ?>>
                        <td colspan="10" class="editor-cell">
                            <h3>Edit <?php echo h($name); ?></h3>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="year" value="<?php echo h($year); ?>">
                                <input type="hidden" name="participant_id" value="<?php echo $id; ?>">
                                <div class="editor-grid">
                                    <div><label for="edit-first-<?php echo $id; ?>">First Name</label><input type="text" name="first_name" id="edit-first-<?php echo $id; ?>" maxlength="100" required value="<?php echo h($profile['first_name']); ?>"></div>
                                    <div><label for="edit-last-<?php echo $id; ?>">Last Name</label><input type="text" name="last_name" id="edit-last-<?php echo $id; ?>" maxlength="100" value="<?php echo h($profile['last_name']); ?>"></div>
                                    <div><label for="edit-email-<?php echo $id; ?>">Email</label><input type="email" name="email" id="edit-email-<?php echo $id; ?>" maxlength="255" required value="<?php echo h($profile['email']); ?>"></div>
                                    <div><label for="edit-family-<?php echo $id; ?>">Family Group</label><input type="number" name="family_unit" id="edit-family-<?php echo $id; ?>" min="-2147483648" max="2147483647" required value="<?php echo h($profile['family_unit']); ?>"></div>
                                </div>
                                <p class="small-note">Changes apply across all years. People in the same family group cannot draw each other in future draws. Existing assignments, history, wishes, links, and visit statistics are preserved. Saving does not send email.</p>
                                <div class="editor-actions"><button type="submit" name="action" value="edit_participant">Save Changes</button><button type="button" class="secondary close-editor" data-editor="edit-row-<?php echo $id; ?>">Cancel</button></div>
                            </form>
                            <section class="participant-actions" aria-labelledby="participant-actions-<?php echo $id; ?>">
                                <h3 id="participant-actions-<?php echo $id; ?>">Participant actions</h3>
                                <p class="small-note">Emails use the saved address: <strong><?php echo h($p['email']); ?></strong>. Save any changes above before using these actions. Each action asks for confirmation.</p>
                                <div class="participant-action-grid">
                                <form method="post" data-confirm="<?php echo h('Regenerate the wishlist key for ' . $name . ' and resend to ' . $p['email'] . '? Their old link will stop working immediately. Their assignment for ' . $year . ' will be resent with the new link; if there is no assignment, only the new wishlist link will be emailed.'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="year" value="<?php echo h($year); ?>">
                                    <input type="hidden" name="participant_id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="key_version" value="<?php echo h(hash('sha256', $p['wish_key'] ?? '')); ?>">
                                    <p>Regenerating the user key immediately invalidates the old wishlist link and emails a new one with their saved assignment for <?php echo h($year); ?>, if available. Wishes and history are preserved.</p>
                                    <button type="submit" name="action" value="regenerate_key" class="secondary">Regenerate User Key</button>
                                </form>
                                <form method="post" data-confirm="<?php echo h('Resend the saved assignment for ' . $year . ' and current wishlist link to ' . $name . ' at ' . $p['email'] . '? If there is no assignment, only their wishlist link will be emailed. Their key will stay the same.'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="year" value="<?php echo h($year); ?>">
                                    <input type="hidden" name="participant_id" value="<?php echo $id; ?>">
                                    <p>Resend their saved assignment for <?php echo h($year); ?> with the current wishlist link. If there is no assignment, only the wishlist link is emailed. Their key stays the same.</p>
                                    <button type="submit" name="action" value="resend_email" class="secondary">Resend Email</button>
                                </form>
                                <form method="post" data-confirm="<?php echo h('Permanently delete ' . $name . '? This removes their wishlist, pairings from EVERY year, participation records, and panel visit statistics. It breaks historical continuity and removes those pairings from future repeat-recipient checks. Edit their information above or set them to Out to preserve history.'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="year" value="<?php echo h($year); ?>">
                                    <input type="hidden" name="participant_id" value="<?php echo $id; ?>">
                                    <p class="notice warning"><strong>Deletion is permanent:</strong> it removes their wishlist, pairings from every year, participation records, and panel visit statistics. This breaks historical continuity and removes those pairings from future repeat-recipient checks. Edit their information above or set them to <strong>Out</strong> to preserve history. Existing assignments remain until a redraw.</p>
                                    <button type="submit" name="action" value="delete_participant" class="secondary">Delete User</button>
                                </form>
                                </div>
                            </section>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$participants): ?><tr><td colspan="10">No participants yet. Add an elf above or use CSV Import to get started.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="small-note">Toggle Participating to include or exclude someone from the draw for <?php echo h($year); ?> and later years until changed again. Changes apply on the next draw. Panel visits count page loads and refreshes, excluding wishlist saves; automated link checks may also count.</p>
    </section>
</main>

<?php if ($confirmation !== null): ?>
<dialog id="draw-confirmation" open aria-labelledby="confirmation-title" aria-describedby="confirmation-description">
    <?php if ($confirmation['step'] === 'overwrite'): ?>
        <h2 id="confirmation-title">Replace the existing draw?</h2>
        <p id="confirmation-description">There is already a saved draw for <strong><?php echo h($confirmation['year']); ?></strong>. Continuing will <strong>overwrite all existing assignments for that year</strong> and send new assignment emails to all <strong><?php echo h($confirmation['participant_count']); ?></strong> participating people.</p>
        <div class="notice warning">Participants may already have read their previous assignment. Their new assignment may be different. Previous years and existing wish lists are preserved.</div>
    <?php else: ?>
        <h2 id="confirmation-title">Draw and send for <?php echo h($confirmation['year']); ?>?</h2>
        <p id="confirmation-description">Generate a new draw for <strong><?php echo h($confirmation['year']); ?></strong>, save the assignments, and send an assignment email to each of the <strong><?php echo h($confirmation['participant_count']); ?></strong> participating people?</p>
        <p class="small-note">History exclusion: <?php echo h($confirmation['history_years']); ?> year(s). People in the same family group cannot draw each other. This generates a fresh draw rather than saving a test preview.</p>
    <?php endif; ?>
    <form method="post" class="dialog-actions">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="year" value="<?php echo h($confirmation['year']); ?>">
        <input type="hidden" name="draw_token" value="<?php echo h($confirmation['token']); ?>">
        <input type="hidden" name="confirmation_step" value="<?php echo h($confirmation['step']); ?>">
        <button type="submit" name="action" value="cancel_draw" class="secondary" autofocus>Cancel</button>
        <button type="submit" name="action" value="confirm_draw" class="<?php echo $confirmation['step'] === 'overwrite' ? 'danger' : ''; ?>"><?php echo $confirmation['step'] === 'overwrite' ? 'Replace Draw and Send Emails' : 'Confirm Draw and Send'; ?></button>
    </form>
</dialog>
<?php endif; ?>
<script>
    function closeEditor(row) {
        row.hidden = true;
        const toggle = document.querySelector('[aria-controls="' + row.id + '"]');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
    }
    document.querySelectorAll('.manage-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const row = document.getElementById(button.getAttribute('aria-controls'));
            if (!row.hidden) { closeEditor(row); return; }
            row.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            row.querySelector('input[type="text"]').focus();
        });
    });
    document.querySelectorAll('.close-editor').forEach(button => {
        button.addEventListener('click', () => closeEditor(document.getElementById(button.dataset.editor)));
    });
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault(); return;
            }
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            // Keep the clicked action in the request; disabling it before the
            // form is serialized would remove its name and value.
            setTimeout(() => {
                document.querySelectorAll('button[type="submit"]').forEach(button => {
                    if (button.form === form) button.disabled = true;
                });
            }, 0);
        });
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form').forEach(form => {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
        });
        document.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = false);
    });
    const confirmation = document.getElementById('draw-confirmation');
    if (confirmation && typeof confirmation.showModal === 'function') {
        confirmation.close();
        confirmation.showModal();
        confirmation.addEventListener('cancel', event => {
            event.preventDefault();
            confirmation.querySelector('[value="cancel_draw"]').click();
        });
    }
</script>
</body>
</html>
