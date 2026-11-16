-- Select your existing playbook database in phpMyAdmin, then import this file.
-- Adds only staff progress storage. Does not change reservations.status.
-- No FK is imposed because the original reservation/user ID types were not supplied.
CREATE TABLE IF NOT EXISTS reservation_staff_progress (
    reservation_id BIGINT UNSIGNED NOT NULL,
    progress ENUM('not_started', 'in_progress', 'completed') NOT NULL DEFAULT 'not_started',
    version INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (reservation_id),
    KEY idx_staff_progress (progress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
