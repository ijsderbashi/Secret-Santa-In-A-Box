<?php

const SANTA_MAX_HISTORY_YEARS = 5;
const SANTA_MIN_DRAW_YEAR = 2000;
const SANTA_MAX_DRAW_YEAR = 2100;

function santaCurrentYear(array $config): int
{
    $timezone = new DateTimeZone($config['app']['timezone']);
    return (int)(new DateTimeImmutable('now', $timezone))->format('Y');
}

function santaHistoryYears(PDO $pdo, bool $lock = false): int
{
    $sql = 'SELECT history_years FROM santa_draw_settings WHERE id = 1';
    $value = $pdo->query($sql . ($lock ? ' FOR UPDATE' : ''))->fetchColumn();
    if ($value === false) {
        throw new RuntimeException('The Secret Santa draw settings are missing.');
    }
    return (int)$value;
}

function santaParticipants(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare("
        SELECT p.*, COALESCE((
            SELECT s.is_in
            FROM santa_participation s
            WHERE s.participant_id = p.id AND s.effective_year <= :year
            ORDER BY s.effective_year DESC
            LIMIT 1
        ), 1) AS is_in
        FROM participants p
        ORDER BY is_in DESC, p.id ASC
    ");
    $stmt->execute([':year' => $year]);
    return $stmt->fetchAll();
}

function santaExcludedRecipients(PDO $pdo, int $year, int $historyYears): array
{
    if ($historyYears === 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT giver_id, receiver_id
        FROM secret_santa_pairs
        WHERE year >= :start_year AND year < :draw_year
    ");
    $stmt->execute([':start_year' => $year - $historyYears, ':draw_year' => $year]);
    $excluded = [];
    while ($pair = $stmt->fetch()) {
        $excluded[$pair['giver_id']][$pair['receiver_id']] = true;
    }
    return $excluded;
}

function santaGeneratePairs(array $participants, array $excluded): array
{
    if (count($participants) < 2) {
        throw new DomainException('At least two participants must be In to generate a draw.');
    }

    $eligible = [];
    $byId = array_column($participants, null, 'id');
    foreach ($byId as $giverId => $giver) {
        $eligible[$giverId] = [];
        foreach ($byId as $receiverId => $receiver) {
            if ($giverId !== $receiverId
                && (int)$giver['family_unit'] !== (int)$receiver['family_unit']
                && !isset($excluded[$giverId][$receiverId])) {
                $eligible[$giverId][] = $receiverId;
            }
        }
        shuffle($eligible[$giverId]);
    }

    // An augmenting path moves existing assignments when a giver needs their receiver.
    $giverByReceiver = [];
    $assign = function (int $giverId, array &$visited) use (&$assign, &$giverByReceiver, $eligible): bool {
        foreach ($eligible[$giverId] as $receiverId) {
            if (isset($visited[$receiverId])) {
                continue;
            }
            $visited[$receiverId] = true;
            if (!isset($giverByReceiver[$receiverId]) || $assign($giverByReceiver[$receiverId], $visited)) {
                $giverByReceiver[$receiverId] = $giverId;
                return true;
            }
        }
        return false;
    };

    $giverIds = array_keys($eligible);
    shuffle($giverIds);
    foreach ($giverIds as $giverId) {
        $visited = [];
        if (!$assign($giverId, $visited)) {
            throw new DomainException('No valid draw exists. Check the roster, family units, and history setting.');
        }
    }

    $pairs = [];
    foreach ($giverByReceiver as $receiverId => $giverId) {
        $pairs[$giverId] = $byId[$receiverId];
    }
    return $pairs;
}

function santaSavePairs(PDO $pdo, int $year, array $pairs): void
{
    $delete = $pdo->prepare('DELETE FROM secret_santa_pairs WHERE year = ?');
    $delete->execute([$year]);

    $insert = $pdo->prepare('INSERT INTO secret_santa_pairs (year, giver_id, receiver_id) VALUES (?, ?, ?)');
    foreach ($pairs as $giverId => $receiver) {
        $insert->execute([$year, $giverId, $receiver['id']]);
    }
}
