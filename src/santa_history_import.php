<?php

require_once __DIR__ . '/santa_draw.php';

function santaImportPastPairs(PDO $pdo, $csv, int $currentYear): array
{
    $header = fgetcsv($csv, 0, ',', '"', '');
    if ($header === false) {
        throw new DomainException('The CSV is empty.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(static fn($field): string => strtolower(trim((string)$field)), $header);
    $columns = array_flip($header);
    if (count($header) !== 3 || !isset($columns['year'], $columns['giver_email'], $columns['receiver_email'])) {
        throw new DomainException('The header must contain year, giver_email, and receiver_email.');
    }

    $pdo->beginTransaction();
    try {
        santaHistoryYears($pdo, true);

        $idsByEmail = [];
        foreach ($pdo->query('SELECT id, email FROM participants') as $participant) {
            $idsByEmail[strtolower(trim($participant['email']))][] = (int)$participant['id'];
        }

        $history = $pdo->prepare('SELECT year, giver_id, receiver_id FROM secret_santa_pairs WHERE year < ?');
        $history->execute([$currentYear]);
        $receiversByGiver = [];
        $giversByReceiver = [];
        while ($pair = $history->fetch()) {
            $receiversByGiver[$pair['year']][$pair['giver_id']][$pair['receiver_id']] = true;
            $giversByReceiver[$pair['year']][$pair['receiver_id']][$pair['giver_id']] = true;
        }

        $errors = [];
        $pending = [];
        $skipped = 0;
        $rowNumber = 1;
        while (($fields = fgetcsv($csv, 0, ',', '"', '')) !== false) {
            $rowNumber++;
            if ($fields === [null]) {
                continue;
            }
            if (count($fields) !== 3) {
                $errors[$rowNumber] = 'Expected three fields.';
                continue;
            }

            $year = filter_var(trim($fields[$columns['year']]), FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => SANTA_MIN_DRAW_YEAR,
                    'max_range' => min($currentYear - 1, SANTA_MAX_DRAW_YEAR),
                ],
            ]);
            if ($year === false) {
                $errors[$rowNumber] = 'Choose a past draw year from ' . SANTA_MIN_DRAW_YEAR . ' onward.';
                continue;
            }

            $giverEmail = trim($fields[$columns['giver_email']]);
            $receiverEmail = trim($fields[$columns['receiver_email']]);
            $givers = $idsByEmail[strtolower($giverEmail)] ?? [];
            $receivers = $idsByEmail[strtolower($receiverEmail)] ?? [];
            if (count($givers) !== 1 || count($receivers) !== 1) {
                $errors[$rowNumber] = 'Each email must match exactly one participant. Check '
                    . $giverEmail . ' and ' . $receiverEmail . '.';
                continue;
            }
            [$giverId] = $givers;
            [$receiverId] = $receivers;
            if ($giverId === $receiverId) {
                $errors[$rowNumber] = 'A participant cannot give to themselves.';
                continue;
            }

            $giverAssignments = $receiversByGiver[$year][$giverId] ?? [];
            $receiverAssignments = $giversByReceiver[$year][$receiverId] ?? [];
            if ((count($giverAssignments) === 1 && isset($giverAssignments[$receiverId]))
                && (count($receiverAssignments) === 1 && isset($receiverAssignments[$giverId]))) {
                $skipped++;
                continue;
            }
            if ($giverAssignments || $receiverAssignments) {
                $errors[$rowNumber] = 'The giver or receiver already has a different assignment for ' . $year . '.';
                continue;
            }

            $receiversByGiver[$year][$giverId][$receiverId] = true;
            $giversByReceiver[$year][$receiverId][$giverId] = true;
            $pending[] = [$year, $giverId, $receiverId];
        }

        if (!feof($csv)) {
            throw new RuntimeException('Could not read the CSV.');
        }
        if ($errors) {
            $pdo->rollBack();
            return ['inserted' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        $insert = $pdo->prepare('INSERT INTO secret_santa_pairs (year, giver_id, receiver_id) VALUES (?, ?, ?)');
        foreach ($pending as $pair) {
            $insert->execute($pair);
        }
        $pdo->commit();

        return ['inserted' => count($pending), 'skipped' => $skipped, 'errors' => []];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
