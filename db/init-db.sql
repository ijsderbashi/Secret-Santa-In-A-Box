-- Current schema for a new, empty database selected by MYSQL_DATABASE.
CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    family_unit INT NOT NULL,

    wish_items JSON NOT NULL DEFAULT (JSON_ARRAY()),
    wishlist_notified_at DATETIME NULL,

    wish_key   VARCHAR(64) NULL,

    UNIQUE KEY uniq_wish_key (wish_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE secret_santa_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_year (year),
    INDEX idx_giver (giver_id),
    INDEX idx_receiver (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE panel_visits (
    participant_id INT NOT NULL,
    year INT NOT NULL,
    first_visited_at DATETIME NOT NULL,
    last_visited_at DATETIME NOT NULL,
    visit_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (participant_id, year),
    INDEX idx_panel_visit_year (year),
    FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE santa_draw_settings (
    id TINYINT PRIMARY KEY,
    history_years TINYINT NOT NULL CHECK (history_years BETWEEN 0 AND 5)
) ENGINE=InnoDB;

INSERT INTO santa_draw_settings (id, history_years) VALUES (1, 1);

CREATE TABLE santa_participation (
    participant_id INT NOT NULL,
    effective_year INT NOT NULL,
    is_in BOOLEAN NOT NULL,
    PRIMARY KEY (participant_id, effective_year),
    FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB;
