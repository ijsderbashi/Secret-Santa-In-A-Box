<?php
// Read, normalize, and save expandable wishlists as JSON arrays.
function santaWishlistItems(array $participant): array
{
    return santaNormalizeWishes(json_decode($participant['wish_items'], true, 512, JSON_THROW_ON_ERROR));
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
    $update = $pdo->prepare('UPDATE participants SET wish_items = ? WHERE id = ?');
    $update->execute([
        json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $participantId,
    ]);
}

// Reserve the notification before SMTP so concurrent saves cannot send duplicates.
// Failed email attempts also consume the hour; saving wishes is independent.
function santaClaimWishlistNotification(PDO $pdo, int $participantId): bool
{
    $claim = $pdo->prepare('
        UPDATE participants
        SET wishlist_notified_at = NOW()
        WHERE id = ?
          AND (wishlist_notified_at IS NULL
               OR wishlist_notified_at < NOW() - INTERVAL 1 HOUR)
    ');
    $claim->execute([$participantId]);
    return $claim->rowCount() === 1;
}
