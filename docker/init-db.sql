-- PiDoors Docker Database Initialization
-- Creates both databases, user permissions, and all tables

-- Create databases
CREATE DATABASE IF NOT EXISTS `users`;
CREATE DATABASE IF NOT EXISTS `access`;

-- Grant permissions (user already created by MARIADB_USER env var)
GRANT ALL PRIVILEGES ON `users`.* TO 'pidoors'@'%';
GRANT ALL PRIVILEGES ON `access`.* TO 'pidoors'@'%';
FLUSH PRIVILEGES;

-- ========================================
-- USERS DATABASE
-- ========================================
USE `users`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(100) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `user_email` varchar(255) NOT NULL,
  `user_pass` varchar(255) NOT NULL,
  `admin` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_email` (`user_email`),
  UNIQUE KEY `unique_user_name` (`user_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_type` (`event_type`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create default admin user (admin@pidoors.local / PiDoors2024!)
-- Password hash is bcrypt of 'PiDoors2024!'
INSERT INTO `users` (`user_name`, `user_email`, `user_pass`, `admin`, `active`) VALUES
('Admin', 'admin@pidoors.local', '$2y$12$mBbwG6jxtJetQWLIQqhBfO9q34t57WdhpDmmWM7eEZkFjLJ3/e9jm', 1, 1);

-- ========================================
-- ACCESS DATABASE
-- ========================================
USE `access`;

-- Base tables
CREATE TABLE IF NOT EXISTS `cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `facility` varchar(16) NOT NULL,
  `bstr` varchar(255) DEFAULT NULL,
  `firstname` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) DEFAULT NULL,
  `doors` text,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_card` (`card_id`, `facility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `doors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `doornum` int(11) DEFAULT NULL,
  `description` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(32) NOT NULL,
  `Date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Granted` tinyint(1) NOT NULL DEFAULT 0,
  `Location` varchar(20) DEFAULT NULL,
  `doorip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Access groups
CREATE TABLE IF NOT EXISTS `access_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `doors` text DEFAULT NULL COMMENT 'JSON array of allowed door names, NULL = all doors',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `access_groups` (`name`, `description`) VALUES
('All Access', 'Full access to all doors at all times'),
('Employees', 'Standard employee access'),
('Visitors', 'Limited visitor access'),
('Contractors', 'Contractor access with time restrictions');

-- Access schedules
CREATE TABLE IF NOT EXISTS `access_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `monday_start` time DEFAULT NULL,
  `monday_end` time DEFAULT NULL,
  `tuesday_start` time DEFAULT NULL,
  `tuesday_end` time DEFAULT NULL,
  `wednesday_start` time DEFAULT NULL,
  `wednesday_end` time DEFAULT NULL,
  `thursday_start` time DEFAULT NULL,
  `thursday_end` time DEFAULT NULL,
  `friday_start` time DEFAULT NULL,
  `friday_end` time DEFAULT NULL,
  `saturday_start` time DEFAULT NULL,
  `saturday_end` time DEFAULT NULL,
  `sunday_start` time DEFAULT NULL,
  `sunday_end` time DEFAULT NULL,
  `is_24_7` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `access_schedules` (`name`, `description`, `is_24_7`) VALUES
('24/7 Access', 'Unlimited access at all times', 1);

INSERT IGNORE INTO `access_schedules` (`name`, `description`,
  `monday_start`, `monday_end`,
  `tuesday_start`, `tuesday_end`,
  `wednesday_start`, `wednesday_end`,
  `thursday_start`, `thursday_end`,
  `friday_start`, `friday_end`) VALUES
('Business Hours', 'Monday to Friday 8am-6pm',
  '08:00:00', '18:00:00',
  '08:00:00', '18:00:00',
  '08:00:00', '18:00:00',
  '08:00:00', '18:00:00',
  '08:00:00', '18:00:00');

INSERT IGNORE INTO `access_schedules` (`name`, `description`,
  `monday_start`, `monday_end`,
  `tuesday_start`, `tuesday_end`,
  `wednesday_start`, `wednesday_end`,
  `thursday_start`, `thursday_end`,
  `friday_start`, `friday_end`,
  `saturday_start`, `saturday_end`,
  `sunday_start`, `sunday_end`) VALUES
('Extended Hours', 'Daily 6am-10pm',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00',
  '06:00:00', '22:00:00');

-- Holidays
CREATE TABLE IF NOT EXISTS `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `recurring` tinyint(1) NOT NULL DEFAULT 0,
  `access_denied` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date_unique` (`date`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  `description` text,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('default_unlock_duration', '5', 'Default door unlock duration in seconds'),
('max_failed_attempts', '5', 'Maximum failed access attempts before lockout'),
('lockout_duration', '300', 'Lockout duration in seconds after max failed attempts'),
('anti_passback_enabled', '0', 'Enable anti-passback feature'),
('anti_passback_timeout', '60', 'Anti-passback timeout in seconds'),
('email_notifications', '0', 'Enable email notifications for events'),
('notification_email', '', 'Email address for notifications'),
('smtp_host', '', 'SMTP server hostname'),
('smtp_port', '587', 'SMTP server port'),
('smtp_user', '', 'SMTP username'),
('smtp_pass', '', 'SMTP password'),
('system_name', 'PiDoors', 'System display name'),
('timezone', 'America/New_York', 'System timezone'),
('max_unlock_duration', '3600', 'Maximum unlock duration in seconds that admins can set per door'),
('default_daily_scan_limit', '0', 'Default daily scan limit for new cards (0 = unlimited)'),
('server_version', '', 'Current server software version'),
('target_controller_version', '', 'Target version for door controllers'),
('github_latest_version', '', 'Latest version available on GitHub'),
('github_check_time', '', 'Last time GitHub was checked for updates'),
('smtp_from', '', 'SMTP from email address for notifications');

-- Audit logs (in access DB too)
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_type` (`event_type`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Door events
CREATE TABLE IF NOT EXISTS `door_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `door_name` varchar(20) NOT NULL,
  `event_type` enum('access_granted','access_denied','door_opened','door_closed','door_held_open','forced_entry','lock','unlock','online','offline','tamper') NOT NULL,
  `card_id` varchar(32) DEFAULT NULL,
  `user_id` varchar(32) DEFAULT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `door_name` (`door_name`),
  KEY `event_type` (`event_type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master cards
CREATE TABLE IF NOT EXISTS `master_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `facility` varchar(16) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_id` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Door groups
CREATE TABLE IF NOT EXISTS `door_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `door_name` varchar(20) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `door_group` (`door_name`, `group_id`),
  KEY `door_name` (`door_name`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification log for deduplication
CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_lookup` (`event_type`, `event_key`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add extended columns to cards table
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `group_id` int(11) DEFAULT NULL AFTER `active`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `schedule_id` int(11) DEFAULT NULL AFTER `group_id`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `valid_from` date DEFAULT NULL AFTER `schedule_id`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `valid_until` date DEFAULT NULL AFTER `valid_from`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `pin_code` varchar(10) DEFAULT NULL AFTER `valid_until`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `anti_passback_location` varchar(50) DEFAULT NULL AFTER `pin_code`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `created_at` datetime DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `card_type` enum('wiegand','nfc') DEFAULT 'wiegand' AFTER `bstr`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `email` varchar(255) DEFAULT NULL AFTER `lastname`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `phone` varchar(30) DEFAULT NULL AFTER `email`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `department` varchar(100) DEFAULT NULL AFTER `phone`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `employee_id` varchar(50) DEFAULT NULL AFTER `department`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `company` varchar(100) DEFAULT NULL AFTER `employee_id`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `title` varchar(100) DEFAULT NULL AFTER `company`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `title`;
ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `daily_scan_limit` int(11) DEFAULT NULL AFTER `notes`;

-- Add extended columns to doors table
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) DEFAULT NULL AFTER `description`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `schedule_id` int(11) DEFAULT NULL AFTER `ip_address`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `unlock_duration` int(11) DEFAULT 5 AFTER `schedule_id`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `status` enum('online','offline','unknown') DEFAULT 'unknown' AFTER `unlock_duration`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `last_seen` datetime DEFAULT NULL AFTER `status`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `locked` tinyint(1) DEFAULT 1 AFTER `last_seen`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `lockdown_mode` tinyint(1) DEFAULT 0 AFTER `locked`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `reader_type` enum('wiegand','osdp','nfc_pn532','nfc_mfrc522') DEFAULT 'wiegand' AFTER `lockdown_mode`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `controller_version` varchar(20) DEFAULT NULL AFTER `reader_type`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `update_requested` tinyint(1) DEFAULT 0 AFTER `controller_version`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `update_status` varchar(255) DEFAULT NULL AFTER `update_requested`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `update_status_time` datetime DEFAULT NULL AFTER `update_status`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `unlock_requested` tinyint(1) NOT NULL DEFAULT 0 AFTER `update_status_time`;
ALTER TABLE `doors` ADD COLUMN IF NOT EXISTS `poll_interval` int(11) NOT NULL DEFAULT 3 AFTER `unlock_requested`;

-- Add indexes for performance
ALTER TABLE `logs` ADD INDEX IF NOT EXISTS `idx_user_id` (`user_id`);
ALTER TABLE `logs` ADD INDEX IF NOT EXISTS `idx_date` (`Date`);
ALTER TABLE `logs` ADD INDEX IF NOT EXISTS `idx_location` (`Location`);
ALTER TABLE `cards` ADD INDEX IF NOT EXISTS `idx_active` (`active`);

-- Insert a sample door for testing
INSERT IGNORE INTO `doors` (`name`, `location`, `doornum`, `description`) VALUES
('FrontDoor', 'Main Entrance', 1, 'Front door - Docker test environment');

-- ========================================
-- EXAMPLE DATA
-- ========================================

-- Example user (password: PiDoors2024!)
USE `users`;
INSERT IGNORE INTO `users` (`user_name`, `user_email`, `user_pass`, `first_name`, `last_name`, `department`, `company`, `job_title`, `admin`, `active`)
VALUES ('jsmith', 'jsmith@example.com', '$2y$12$mBbwG6jxtJetQWLIQqhBfO9q34t57WdhpDmmWM7eEZkFjLJ3/e9jm', 'John', 'Smith', 'Engineering', 'Acme Corp', 'Engineer', 0, 1);

USE `access`;

-- Example door
INSERT IGNORE INTO `doors` (`name`, `location`, `description`, `reader_type`, `unlock_duration`, `status`)
VALUES ('front_door', 'Main Entrance', 'Front door with card reader', 'wiegand', 5, 'unknown');

-- Example cards
INSERT IGNORE INTO `cards` (`card_id`, `user_id`, `facility`, `firstname`, `lastname`, `department`, `group_id`, `active`)
VALUES ('12345678', 'EMP001', '100', 'Jane', 'Doe', 'Security', 1, 1);

INSERT IGNORE INTO `cards` (`card_id`, `user_id`, `facility`, `firstname`, `lastname`, `department`, `group_id`, `schedule_id`, `active`)
VALUES ('87654321', 'EMP002', '100', 'Bob', 'Wilson', 'Engineering', 2, 2, 1);

-- Example master card
INSERT IGNORE INTO `master_cards` (`card_id`, `user_id`, `facility`, `description`, `active`)
VALUES ('12345678', 'EMP001', '100', 'Jane Doe', 1);

-- Example holiday
INSERT IGNORE INTO `holidays` (`name`, `date`, `recurring`, `access_denied`)
VALUES ('New Year''s Day', '2026-01-01', 1, 1);
