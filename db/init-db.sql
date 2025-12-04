-- db/init-db.sql
-- Secret Santa Database Initialization Script

CREATE DATABASE IF NOT EXISTS secret_santa
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE secret_santa;

CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    family_unit INT NOT NULL,

    wish_item1 VARCHAR(255) NULL,
    wish_item2 VARCHAR(255) NULL,
    wish_item3 VARCHAR(255) NULL,

    wish_key   VARCHAR(64) NULL,

    UNIQUE KEY uniq_wish_key (wish_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS secret_santa_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_year (year),
    INDEX idx_giver (giver_id),
    INDEX idx_receiver (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_opens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    year INT NOT NULL,

    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,

    open_count INT NOT NULL DEFAULT 0,
    last_ip VARCHAR(45) NULL,
    last_user_agent VARCHAR(255) NULL,

    UNIQUE KEY uniq_open (participant_id, year),
    INDEX idx_open_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional seed row (commented out)
-- INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
-- VALUES ('Test', 'User', 'test@example.com', 1, 'sample_key_123');

