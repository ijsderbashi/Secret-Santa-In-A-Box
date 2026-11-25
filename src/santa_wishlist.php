<?php
// Read legacy three-item lists until a participant first saves the expandable list.
function santaWishlistItems(array $participant): array
{
    $items = isset($participant['wish_items'])
        ? json_decode($participant['wish_items'], true, 512, JSON_THROW_ON_ERROR)
        : [
            $participant['wish_item1'] ?? '',
            $participant['wish_item2'] ?? '',
            $participant['wish_item3'] ?? '',
        ];

    return santaNormalizeWishes($items);
}

function santaNormalizeWishes(array $items): array
{
    $wishes = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            throw new InvalidArgumentException('Each wish must be text.');
        }
        $item = mb_substr(trim($item), 0, 255);
        if ($item !== '') {
            $wishes[] = $item;
        }
    }
    return $wishes;
}

function santaSaveWishlist(PDO $pdo, int $participantId, array $items): void
{
    $items = santaNormalizeWishes($items);
    // Mirror the first three items for compatibility with existing integrations.
    $update = $pdo->prepare('
        UPDATE participants
        SET wish_items = ?, wish_item1 = ?, wish_item2 = ?, wish_item3 = ?
        WHERE id = ?
    ');
    $update->execute([
        json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $items[0] ?? null,
        $items[1] ?? null,
        $items[2] ?? null,
        $participantId,
    ]);
}
