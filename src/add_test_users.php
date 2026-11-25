<?php
// add_test_users.php
// Inject 4 test participants into the DB for development/testing.
// Safe to run multiple times — it will not duplicate users.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/santa_database.php';
$config = require __DIR__ . '/config.php';

try {
    $pdo = santaDatabase($config);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

$testUsers = [
    [
        'first_name'  => 'Alice',
        'last_name'   => 'Wonderland',
        'email'       => 'alice@example.com',
        'family_unit' => 1,
    ],
    [
        'first_name'  => 'Bob',
        'last_name'   => 'Biscuit',
        'email'       => 'bob@example.com',
        'family_unit' => 1,
    ],
    [
        'first_name'  => 'Charlie',
        'last_name'   => 'Chestnut',
        'email'       => 'charlie@example.com',
        'family_unit' => 2,
    ],
    [
        'first_name'  => 'Daisy',
        'last_name'   => 'Dazzle',
        'email'       => 'daisy@example.com',
        'family_unit' => 3,
    ],
];

// Prepare lookup to avoid duplicates
$existingStmt = $pdo->query("SELECT email FROM participants");
$existingEmails = array_column($existingStmt->fetchAll(), 'email');

$inserted = 0;

$insert = $pdo->prepare("
    INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
    VALUES (:first_name, :last_name, :email, :family_unit, :wish_key)
");

foreach ($testUsers as $user) {
    if (in_array($user['email'], $existingEmails)) {
        echo "Skipping existing user: {$user['email']}\n";
        continue;
    }

    $wishKey = bin2hex(random_bytes(16));

    $insert->execute([
        ':first_name'  => $user['first_name'],
        ':last_name'   => $user['last_name'],
        ':email'       => $user['email'],
        ':family_unit' => $user['family_unit'],
        ':wish_key'    => $wishKey,
    ]);

    echo "Inserted test user: {$user['first_name']} {$user['last_name']} ({$user['email']})\n";
    $inserted++;
}

echo "\nDone. Inserted {$inserted} new test users.\n";
