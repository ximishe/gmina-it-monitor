-- Gmina IT Monitor - MySQL / MariaDB Schema (kompatybilne z XAMPP)
CREATE TABLE IF NOT EXISTS `devices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_uuid` VARCHAR(64) NOT NULL UNIQUE,
    `hostname` VARCHAR(128) NOT NULL,
    `os_name` VARCHAR(128),
    `os_version` VARCHAR(64),
    `ip_address` VARCHAR(64),
    `gateway` VARCHAR(64),
    `dns_servers` VARCHAR(255),
    `agent_version` VARCHAR(32),
    `first_seen` DATETIME NULL,
    `last_seen` DATETIME NULL,
    `overall_status` VARCHAR(20) DEFAULT 'UNKNOWN',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hostname` (`hostname`),
    INDEX `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `token_hint` VARCHAR(16),
    `name` VARCHAR(128) DEFAULT 'Główny token agenta',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` DATETIME NULL,
    INDEX `idx_token_hash` (`token_hash`),
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `check_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT NOT NULL,
    `check_key` VARCHAR(64) NOT NULL,
    `check_name` VARCHAR(128) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `message` TEXT,
    `duration_ms` INT NULL,
    `checked_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_device_checked` (`device_id`, `checked_at`),
    INDEX `idx_check_key` (`device_id`, `check_key`, `checked_at`),
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incidents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT NOT NULL,
    `check_key` VARCHAR(64) NOT NULL,
    `check_name` VARCHAR(128) NOT NULL,
    `severity` VARCHAR(20) DEFAULT 'ERROR',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `opened_at` DATETIME NOT NULL,
    `resolved_at` DATETIME NULL,
    `status` VARCHAR(20) DEFAULT 'OPEN',
    INDEX `idx_status` (`device_id`, `status`, `opened_at`),
    FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

