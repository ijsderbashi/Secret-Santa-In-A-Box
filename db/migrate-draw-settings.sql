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
