-- Panel visits start at zero; legacy email opens are not panel visits.
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
