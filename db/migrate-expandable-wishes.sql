-- NULL preserves legacy wish_item1/2/3 until the participant saves their list.
-- An empty JSON array represents a deliberately cleared wishlist.
ALTER TABLE participants ADD COLUMN IF NOT EXISTS wish_items JSON NULL;
