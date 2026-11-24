-- Techzo Kochi Scratch & Win - MySQL 8+ / MariaDB 10.4+ Schema
-- Charset: utf8mb4 for full English + Malayalam + Emoji support

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- --------------------------------------------------------
-- 1. Settings Table (key-value store for campaign config)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Participants Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `participants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entry_no` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `whatsapp` VARCHAR(20) NOT NULL,
    `instagram_username` VARCHAR(64) NOT NULL,
    `ip` VARCHAR(45) NOT NULL,
    `result_type` ENUM('gift', 'discount') NOT NULL DEFAULT 'discount',
    `code` VARCHAR(32) NOT NULL,
    `status` ENUM('pending', 'verified', 'redeemed', 'delivered', 'revoked') NOT NULL DEFAULT 'pending',
    `ig_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `redeemed_at` DATETIME NULL DEFAULT NULL,
    `delivered_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_entry_no` (`entry_no`),
    UNIQUE KEY `uniq_whatsapp` (`whatsapp`),
    UNIQUE KEY `uniq_instagram` (`instagram_username`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_result_type` (`result_type`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Gift Slots Table (pre-selected winning entry numbers)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gift_slots` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entry_no` INT UNSIGNED NOT NULL,
    `awarded` TINYINT(1) NOT NULL DEFAULT 0,
    `participant_id` INT UNSIGNED NULL DEFAULT NULL,
    `awarded_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_slot_entry_no` (`entry_no`),
    KEY `idx_awarded` (`awarded`),
    CONSTRAINT `fk_gift_slots_participant`
        FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Admin Users Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(120) NOT NULL DEFAULT 'Admin',
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. IP Rate Limits Table (shared-hosting friendly)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip` VARCHAR(45) NOT NULL,
    `action` VARCHAR(32) NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `window_start` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ip_action` (`ip`, `action`),
    KEY `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Verified Instagram DM Unlock Tokens (Strict Auto-Verify)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_verify_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` VARCHAR(64) NOT NULL,
    `instagram_username` VARCHAR(64) NOT NULL,
    `ig_scoped_id` VARCHAR(64) NULL DEFAULT NULL,
    `used` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ig_token` (`token`),
    KEY `idx_ig_token_user` (`instagram_username`),
    KEY `idx_ig_token_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Default Campaign Settings Seed
-- --------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('campaign_active', '1'),
('campaign_title', 'Techzo Kochi Scratch & Win'),
('total_gifts', '3'),
('gift_name', 'Bluetooth Speaker'),
('pool_size', '100'),
('discount_amount', '1000'),
('valid_from', '2026-11-30'),
('valid_to', '2026-12-05'),
('min_bill_amount', '0'),
('campaign_start', '2026-11-01 00:00:00'),
('campaign_end', '2026-12-05 23:59:59'),
('instagram_handle', 'techzo__official'),
('whatsapp_claim_number', '919999999999'),
('scratch_hint_text', 'Scratch Here'),
('scratch_subtext', 'Swipe to reveal your Kochi surprise'),
('scratch_foil_primary', '#d4a64a'),
('scratch_foil_secondary', '#f5d782'),
('scratch_card_bg', '#0a3d42'),
('entry_counter', '0'),
('require_ig_token', '1'),
('ig_dm_keyword', 'KOCHI'),
('ig_webhook_secret', 'tz_kochi_change_this_secret_98765')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
