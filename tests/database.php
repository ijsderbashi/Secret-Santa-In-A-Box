<?php
// Integration checks against disposable MariaDB databases; never uses DB_NAME.
declare(strict_types=1);

require dirname(__DIR__) . '/src/santa_database.php';
require dirname(__DIR__) . '/src/santa_draw.php';
require dirname(__DIR__) . '/src/santa_history_import.php';
require dirname(__DIR__) . '/src/santa_wishlist.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function schema(PDO $pdo): array
{
    return [
        $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
        $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, COLUMN_NAME')->fetchAll(),
        $pdo->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(),
        $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, COLUMN_NAME')->fetchAll(),
    ];
}

$config = require dirname(__DIR__) . '/src/config.php';
$config['db']['dsn'] = sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'db', getenv('DB_PORT') ?: '3306');
$server = santaDatabase($config);
$databases = [];
$newDatabase = static function () use ($server, $config, &$databases): PDO {
    $name = 'santa_test_' . bin2hex(random_bytes(8));
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $databases[] = $name;
    $dbConfig = $config;
    $dbConfig['db']['dsn'] .= ';dbname=' . $name;
    return santaDatabase($dbConfig);
};
$initialSchema = file_get_contents(dirname(__DIR__) . '/db/init-db.sql');
$migration = file_get_contents(dirname(__DIR__) . '/migrate/original-to-current.sql');
$cooldownMigration = file_get_contents(dirname(__DIR__) . '/migrate/wishlist-notification-cooldown.sql');

try {
    $fresh = $newDatabase();
    $fresh->exec($initialSchema);
    $currentSchema = schema($fresh);
    $priorTables = [];
    foreach (['santa_draw_settings', 'santa_participation', 'panel_visits'] as $table) {
        $priorTables[$table] = $fresh->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    }
    expect(count($currentSchema[0]) === 5, 'Fresh installs must have only the five current tables.');
    expect(santaHistoryYears($fresh) === 1, 'Fresh installs must start with one year of history.');

    $insert = $fresh->prepare('INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
        VALUES (?, ?, ?, ?, ?)');
    foreach (range(1, 4) as $id) {
        $insert->execute(['Person ' . $id, 'Test', "person$id@example.invalid", $id, "key$id"]);
    }
    $person = $fresh->query('SELECT * FROM participants WHERE id = 1')->fetch();
    expect(santaWishlistItems($person) === [], 'New participants must have an empty JSON wishlist.');
    santaSaveWishlist($fresh, 1, ['  Book  ', '', 'Coffee', 'Socks', 'Tea', str_repeat('🎁', 256)]);
    $wishes = santaWishlistItems($fresh->query('SELECT * FROM participants WHERE id = 1')->fetch());
    expect($wishes === ['Book', 'Coffee', 'Socks', 'Tea', str_repeat('🎁', 255)],
        'JSON wishlists must preserve more than three wishes and normalize whitespace/length.');
    santaSaveWishlist($fresh, 1, []);
    expect(santaWishlistItems($fresh->query('SELECT * FROM participants WHERE id = 1')->fetch()) === [],
        'Saving an empty list must clear every wish.');
    try {
        santaSaveWishlist($fresh, 1, [['invalid']]);
        throw new RuntimeException('Nested wish values must be rejected.');
    } catch (InvalidArgumentException $e) {
        // Invalid input must leave the saved list untouched.
    }

    // Freeze the database clock to check the strict "more than one hour" boundary.
    $fresh->exec('SET timestamp = 1800000000');
    expect(santaClaimWishlistNotification($fresh, 1), 'The first save must be eligible to notify.');
    $notificationTime = $fresh->query('SELECT wishlist_notified_at FROM participants WHERE id = 1')->fetchColumn();
    expect(!santaClaimWishlistNotification($fresh, 1), 'An immediate repeat must not notify.');
    expect(santaClaimWishlistNotification($fresh, 2), 'Each participant must have an independent cooldown.');
    expect(!santaClaimWishlistNotification($fresh, 9999), 'Missing participants must not reserve notifications.');

    $fresh->exec('SET timestamp = 1800003599');
    santaSaveWishlist($fresh, 1, ['Updated during cooldown']);
    expect(!santaClaimWishlistNotification($fresh, 1), 'Saves inside the hour must not notify.');
    $person = $fresh->query('SELECT * FROM participants WHERE id = 1')->fetch();
    expect(santaWishlistItems($person) === ['Updated during cooldown'], 'The cooldown must never prevent wishlist saves.');
    expect($person['wishlist_notified_at'] === $notificationTime, 'Suppressed notifications must leave the timestamp unchanged.');

    $fresh->exec('SET timestamp = 1800003600');
    expect(!santaClaimWishlistNotification($fresh, 1), 'Exactly one hour must still be suppressed.');
    $fresh->exec('SET timestamp = 1800003601');
    expect(santaClaimWishlistNotification($fresh, 1), 'More than one hour must allow a new notification.');
    $nextNotificationTime = $fresh->query('SELECT wishlist_notified_at FROM participants WHERE id = 1')->fetchColumn();
    expect($nextNotificationTime !== $notificationTime, 'Eligible notifications must advance the timestamp.');
    $otherConfig = $config;
    $otherConfig['db']['dsn'] .= ';dbname=' . $fresh->query('SELECT DATABASE()')->fetchColumn();
    $otherConnection = santaDatabase($otherConfig);
    $otherConnection->exec('SET timestamp = 1800003601');
    expect(!santaClaimWishlistNotification($otherConnection, 1), 'Another connection must observe the reserved cooldown.');
    $fresh->exec('SET timestamp = 0');
    echo "PASS: Notification eligibility, strict one-hour boundary, independent cooldowns, and saving during cooldown\n";

    // Upgrade a database that already has JSON wishes but no cooldown column.
    $upgrade = $newDatabase();
    $upgrade->exec($initialSchema);
    $upgrade->exec('ALTER TABLE participants DROP COLUMN wishlist_notified_at');
    $upgrade->exec("INSERT INTO participants (first_name, last_name, email, family_unit, wish_items, wish_key)
        VALUES ('Existing', 'Person', 'existing@example.invalid', 1, JSON_ARRAY('Keep this wish'), 'existing-key')");
    $upgrade->exec($cooldownMigration);
    expect(schema($upgrade) === $currentSchema, 'The cooldown-only upgrade must match the fresh schema.');
    $person = $upgrade->query('SELECT * FROM participants WHERE id = 1')->fetch();
    expect($person['wishlist_notified_at'] === null && santaWishlistItems($person) === ['Keep this wish'],
        'The cooldown migration must preserve wishes and initially allow notifications.');
    expect(santaClaimWishlistNotification($upgrade, 1), 'Upgraded participants must be eligible for their first notification.');
    $notificationTime = $upgrade->query('SELECT wishlist_notified_at FROM participants WHERE id = 1')->fetchColumn();
    $upgrade->exec($cooldownMigration);
    $upgrade->exec($migration);
    expect($upgrade->query('SELECT wishlist_notified_at FROM participants WHERE id = 1')->fetchColumn() === $notificationTime,
        'Repeated cooldown and full migrations must preserve notification timestamps.');
    echo "PASS: Cooldown-only upgrade, wishlist preservation, and repeatability\n";

    $year = santaCurrentYear($config);
    $csv = fopen('php://temp', 'w+');
    fputcsv($csv, ['year', 'giver_email', 'receiver_email']);
    fputcsv($csv, [$year - 1, 'person1@example.invalid', 'person2@example.invalid']);
    rewind($csv);
    $import = santaImportPastPairs($fresh, $csv, $year);
    fclose($csv);
    expect($import['inserted'] === 1 && !$import['errors'], 'Past-pairing imports must work on fresh installs.');
    $excluded = santaExcludedRecipients($fresh, $year, 1);
    expect(isset($excluded[1][2]), 'Imported history must exclude repeat recipients.');
    $pairs = santaGeneratePairs(santaParticipants($fresh, $year), $excluded);
    expect(count($pairs) === 4 && $pairs[1]['id'] !== 2, 'Draws must respect imported history.');
    santaSavePairs($fresh, $year, $pairs);
    expect((int)$fresh->query("SELECT COUNT(*) FROM secret_santa_pairs WHERE year = $year")->fetchColumn() === 4,
        'Draws must save all assignments on the current schema.');
    $fresh->exec("INSERT INTO santa_participation VALUES (1, $year, 0)");
    $roster = array_column(santaParticipants($fresh, $year + 1), null, 'id');
    expect((int)$roster[1]['is_in'] === 0, 'Participation must carry forward.');
    $fresh->exec("INSERT INTO panel_visits VALUES (1, $year, NOW(), NOW(), 2)");
    $fresh->exec('DELETE FROM secret_santa_pairs WHERE giver_id = 1 OR receiver_id = 1');
    $fresh->exec('DELETE FROM participants WHERE id = 1');
    expect((int)$fresh->query('SELECT COUNT(*) FROM panel_visits')->fetchColumn() === 0,
        'Participant deletion must cascade panel visits without retired tables.');
    expect((int)$fresh->query('SELECT COUNT(*) FROM santa_participation')->fetchColumn() === 0,
        'Participant deletion must cascade participation.');
    echo "PASS: Fresh schema, JSON wishes, imports, draws, participation, and deletion\n";

    // Reconstruct the original storage and test every prior migration stage.
    foreach (range(0, 3) as $stage) {
        $legacy = $newDatabase();
        $legacy->exec($initialSchema);
        $legacy->exec('DROP TABLE panel_visits, santa_participation, santa_draw_settings');
        $legacy->exec('ALTER TABLE participants DROP COLUMN wish_items, DROP COLUMN wishlist_notified_at,
            ADD COLUMN wish_item1 VARCHAR(255) NULL,
            ADD COLUMN wish_item2 VARCHAR(255) NULL,
            ADD COLUMN wish_item3 VARCHAR(255) NULL');
        $legacy->exec('CREATE TABLE email_opens (
            id INT AUTO_INCREMENT PRIMARY KEY, participant_id INT NOT NULL, year INT NOT NULL,
            first_opened_at DATETIME NULL, last_opened_at DATETIME NULL,
            open_count INT NOT NULL DEFAULT 0, last_ip VARCHAR(45) NULL, last_user_agent VARCHAR(255) NULL,
            UNIQUE KEY uniq_open (participant_id, year), INDEX idx_open_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $legacy->exec("INSERT INTO participants
            (first_name, last_name, email, family_unit, wish_key, wish_item1, wish_item2, wish_item3) VALUES
            ('Old', 'List', 'old@example.invalid', 1, 'old-key', 'Book', '   ', 'Čaj 🎁'),
            ('Saved', 'List', 'saved@example.invalid', 2, 'saved-key', 'Stale', NULL, NULL),
            ('Cleared', 'List', 'cleared@example.invalid', 3, 'cleared-key', 'Stale', NULL, NULL),
            ('Empty', 'List', 'empty@example.invalid', 4, 'empty-key', NULL, '', '   ')");
        $legacy->exec('INSERT INTO secret_santa_pairs (year, giver_id, receiver_id) VALUES (2024, 1, 2)');
        $legacy->exec('INSERT INTO email_opens (participant_id, year, open_count) VALUES (1, 2024, 8)');
        if ($stage >= 1) {
            $legacy->exec($priorTables['santa_draw_settings']);
            $legacy->exec($priorTables['santa_participation']);
            $legacy->exec('INSERT INTO santa_draw_settings VALUES (1, 3)');
            $legacy->exec('INSERT INTO santa_participation VALUES (1, 2025, 0)');
        }
        if ($stage >= 2) {
            $legacy->exec($priorTables['panel_visits']);
            $legacy->exec("INSERT INTO panel_visits VALUES (1, 2025, '2025-12-01', '2025-12-02', 7)");
        }
        if ($stage >= 3) {
            $legacy->exec('ALTER TABLE participants ADD COLUMN wish_items JSON NULL');
            $legacy->exec("UPDATE participants SET wish_items = JSON_ARRAY('One', 'Two', 'Three', 'Four') WHERE id = 2");
            $legacy->exec('UPDATE participants SET wish_items = JSON_ARRAY() WHERE id = 3');
        }

        // A complete upgrade and then a repeat must both preserve data.
        foreach (range(1, 2) as $pass) {
            $legacy->exec($migration);
            expect(schema($legacy) === $currentSchema, "Stage $stage, pass $pass: upgraded schema must match fresh initialization.");
            $people = $legacy->query('SELECT * FROM participants ORDER BY id')->fetchAll();
            expect(count($people) === 4 && $people[0]['wish_key'] === 'old-key', 'Participant identity and keys must survive.');
            expect(santaWishlistItems($people[0]) === ['Book', 'Čaj 🎁'], 'Three-item lists must convert without losing Unicode or order.');
            expect(santaWishlistItems($people[1]) === ($stage >= 3 ? ['One', 'Two', 'Three', 'Four'] : ['Stale']),
                'Saved JSON lists must take precedence over old columns.');
            expect(santaWishlistItems($people[2]) === ($stage >= 3 ? [] : ['Stale']),
                'Deliberately cleared JSON lists must stay empty.');
            expect(santaWishlistItems($people[3]) === [], 'Blank and NULL wishes must become empty lists.');
            expect((int)$legacy->query('SELECT COUNT(*) FROM secret_santa_pairs WHERE year = 2024 AND giver_id = 1 AND receiver_id = 2')->fetchColumn() === 1,
                'Historical pairings must survive.');
            expect(santaHistoryYears($legacy) === ($stage >= 1 ? 3 : 1), 'Saved history defaults must survive.');
            expect((int)$legacy->query('SELECT COUNT(*) FROM santa_participation')->fetchColumn() === ($stage >= 1 ? 1 : 0),
                'Participation records must survive.');
            expect((int)$legacy->query('SELECT COALESCE(SUM(visit_count), 0) FROM panel_visits')->fetchColumn() === ($stage >= 2 ? 7 : 0),
                'Panel visits must survive; email opens must never become visits.');
        }
        santaSaveWishlist($legacy, 1, ['New', 'Wishes', 'After', 'Upgrade']);
        expect(santaWishlistItems($legacy->query('SELECT * FROM participants WHERE id = 1')->fetch()) === ['New', 'Wishes', 'After', 'Upgrade'],
            'The current app must save wishes on upgraded databases.');
        echo "PASS: Upgrade from stage $stage, data preservation, schema parity, and repeatability\n";
    }

    $fresh->exec($migration);
    expect(schema($fresh) === $currentSchema, 'Running upgrades against a fresh schema must also be safe.');
    echo "PASS: Migration also accepts the current fresh schema\n";
} finally {
    foreach ($databases as $name) {
        $server->exec("DROP DATABASE `$name`");
    }
}
