-- Upgrade the original database directly to the current schema.
-- Also accepts partially upgraded databases and can be rerun safely.
CREATE TABLE IF NOT EXISTS santa_draw_settings (
    id TINYINT PRIMARY KEY,
    history_years TINYINT NOT NULL CHECK (history_years BETWEEN 0 AND 5)
) ENGINE=InnoDB;

INSERT IGNORE INTO santa_draw_settings (id, history_years) VALUES (1, 1);

CREATE TABLE IF NOT EXISTS santa_participation (
    participant_id INT NOT NULL,
    effective_year INT NOT NULL,
    is_in BOOLEAN NOT NULL,
    PRIMARY KEY (participant_id, effective_year),
    FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS panel_visits (
    participant_id INT NOT NULL,
    year INT NOT NULL,
    first_visited_at DATETIME NOT NULL,
    last_visited_at DATETIME NOT NULL,
    visit_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (participant_id, year),
    INDEX idx_panel_visit_year (year),
    FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NULL identifies wishlists that still need conversion from the old columns.
ALTER TABLE participants ADD COLUMN IF NOT EXISTS wish_items JSON NULL;
ALTER TABLE participants ADD COLUMN IF NOT EXISTS wishlist_notified_at DATETIME NULL;

-- Convert remaining three-item wishlists, then remove retired storage.
-- Saved JSON lists (including deliberately empty lists) take precedence.
SET @has_old_wishes = (
    SELECT COUNT(*) = 3
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participants'
      AND COLUMN_NAME IN ('wish_item1', 'wish_item2', 'wish_item3')
);
SET @convert_wishes = IF(@has_old_wishes,
    'UPDATE participants
     SET wish_items = JSON_MERGE_PRESERVE(
         IF(COALESCE(TRIM(wish_item1), '''') = '''', JSON_ARRAY(), JSON_ARRAY(wish_item1)),
         IF(COALESCE(TRIM(wish_item2), '''') = '''', JSON_ARRAY(), JSON_ARRAY(wish_item2)),
         IF(COALESCE(TRIM(wish_item3), '''') = '''', JSON_ARRAY(), JSON_ARRAY(wish_item3))
     )
     WHERE wish_items IS NULL',
    'DO 0'
);
PREPARE convert_wishes FROM @convert_wishes;
EXECUTE convert_wishes;
DEALLOCATE PREPARE convert_wishes;

UPDATE participants SET wish_items = JSON_ARRAY() WHERE wish_items IS NULL;
ALTER TABLE participants
    MODIFY COLUMN wish_items JSON NOT NULL DEFAULT (JSON_ARRAY()),
    DROP COLUMN IF EXISTS wish_item1,
    DROP COLUMN IF EXISTS wish_item2,
    DROP COLUMN IF EXISTS wish_item3;

DROP TABLE IF EXISTS email_opens;
