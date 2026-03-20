-- PiDoors Database Migration Script
-- Upgrades database to industrial-grade access control system
-- Run this script to upgrade existing installations

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Base Tables
-- These are the core tables required by PiDoors.
-- On fresh installs they are created here; on upgrades
-- the IF NOT EXISTS clause keeps existing data intact.
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Access Groups Table
-- Groups cards together for easier permission management
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Access Schedules Table
-- Defines when access is permitted
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Holidays Table
-- Defines dates when special access rules apply
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If 1, repeats every year',
  `access_denied` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'If 1, denies access on this date',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date_unique` (`date`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Settings Table
-- System-wide configuration
-- --------------------------------------------------------

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
('default_daily_scan_limit', '0', 'Default daily scan limit for new cards (0 = unlimited)');

-- --------------------------------------------------------
-- Audit Logs Table
-- Tracks all admin actions for security
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Door Events Table
-- Real-time door status and events
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Master Cards Table
-- Configurable master override cards
-- --------------------------------------------------------

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

-- --------------------------------------------------------
-- Modify existing cards table to add new columns
-- --------------------------------------------------------

-- Add group_id column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'group_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `group_id` int(11) DEFAULT NULL AFTER `active`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add schedule_id column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'schedule_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `schedule_id` int(11) DEFAULT NULL AFTER `group_id`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add valid_from column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'valid_from');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `valid_from` date DEFAULT NULL AFTER `schedule_id`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add valid_until column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'valid_until');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `valid_until` date DEFAULT NULL AFTER `valid_from`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add pin_code column if not exists (for card + PIN access)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'pin_code');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `pin_code` varchar(10) DEFAULT NULL AFTER `valid_until`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add anti_passback_location column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'anti_passback_location');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `anti_passback_location` varchar(50) DEFAULT NULL AFTER `pin_code`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_at column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'created_at');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `created_at` datetime DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add updated_at column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'updated_at');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Modify existing doors table to add new columns
-- --------------------------------------------------------

-- Add ip_address column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'ip_address');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL AFTER `description`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add schedule_id column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'schedule_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `schedule_id` int(11) DEFAULT NULL AFTER `ip_address`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unlock_duration column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'unlock_duration');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `unlock_duration` int(11) DEFAULT 5 AFTER `schedule_id`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add status column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'status');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `status` enum(\'online\',\'offline\',\'unknown\') DEFAULT \'unknown\' AFTER `unlock_duration`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_seen column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'last_seen');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `last_seen` datetime DEFAULT NULL AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add locked column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'locked');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `locked` tinyint(1) DEFAULT 1 AFTER `last_seen`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lockdown_mode column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'lockdown_mode');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `lockdown_mode` tinyint(1) DEFAULT 0 AFTER `locked`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add reader_type column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'reader_type');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `reader_type` enum(\'wiegand\',\'osdp\',\'nfc_pn532\',\'nfc_mfrc522\') DEFAULT \'wiegand\' AFTER `lockdown_mode`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Add card_type column to cards table
-- --------------------------------------------------------

-- Add card_type column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'card_type');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `card_type` enum(\'wiegand\',\'nfc\') DEFAULT \'wiegand\' AFTER `bstr`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Door Groups Table
-- Links doors to access groups
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `door_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `door_name` varchar(20) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `door_group` (`door_name`, `group_id`),
  KEY `door_name` (`door_name`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Add indexes for performance
-- --------------------------------------------------------

-- Add index on logs.user_id if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'idx_user_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `logs` ADD INDEX `idx_user_id` (`user_id`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on logs.Date if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'idx_date');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `logs` ADD INDEX `idx_date` (`Date`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on logs.Location if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'idx_location');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `logs` ADD INDEX `idx_location` (`Location`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on cards.active if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cards' AND index_name = 'idx_active');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD INDEX `idx_active` (`active`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- v2.3.0 - Extended cardholder fields on cards table
-- --------------------------------------------------------

-- Add email column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'email');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `email` varchar(255) DEFAULT NULL AFTER `lastname`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add phone column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'phone');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `phone` varchar(30) DEFAULT NULL AFTER `email`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add department column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'department');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `department` varchar(100) DEFAULT NULL AFTER `phone`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add employee_id column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'employee_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `employee_id` varchar(50) DEFAULT NULL AFTER `department`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add company column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'company');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `company` varchar(100) DEFAULT NULL AFTER `employee_id`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add title column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'title');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `title` varchar(100) DEFAULT NULL AFTER `company`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'notes');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `notes` text DEFAULT NULL AFTER `title`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add daily_scan_limit column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cards' AND column_name = 'daily_scan_limit');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `cards` ADD COLUMN `daily_scan_limit` int(11) DEFAULT NULL AFTER `notes`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- v2.3.0 - Extended user profile fields (users database)
-- --------------------------------------------------------

USE `users`;

-- Add first_name column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'first_name');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `first_name` varchar(100) DEFAULT NULL AFTER `user_name`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_name column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_name');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `last_name` varchar(100) DEFAULT NULL AFTER `first_name`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add phone column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `phone` varchar(30) DEFAULT NULL AFTER `last_name`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add department column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'department');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `department` varchar(100) DEFAULT NULL AFTER `phone`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add employee_id column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'employee_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `employee_id` varchar(50) DEFAULT NULL AFTER `department`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add company column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'company');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `company` varchar(100) DEFAULT NULL AFTER `employee_id`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add job_title column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'job_title');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `job_title` varchar(100) DEFAULT NULL AFTER `company`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'notes');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `notes` text DEFAULT NULL AFTER `job_title`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add UNIQUE index on user_name if not exists (for username-based login)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'unique_user_name');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD UNIQUE KEY `unique_user_name` (`user_name`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Switch back to access database context
USE `access`;

-- --------------------------------------------------------
-- v2.5.0 - Version checking & update system
-- --------------------------------------------------------

-- Add controller_version column to doors
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'controller_version');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `controller_version` varchar(20) DEFAULT NULL AFTER `reader_type`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add update_requested column to doors
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'update_requested');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `update_requested` tinyint(1) DEFAULT 0 AFTER `controller_version`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add update_status column to doors (varchar(255) to hold status detail messages)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'update_status');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `update_status` varchar(255) DEFAULT NULL AFTER `update_requested`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Widen update_status from varchar(20) to varchar(255) for existing installs
ALTER TABLE `doors` MODIFY COLUMN `update_status` varchar(255) DEFAULT NULL;

-- Add update_status_time column to doors
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'update_status_time');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `update_status_time` datetime DEFAULT NULL AFTER `update_status`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add version-related settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('server_version', '', 'Current server software version'),
('target_controller_version', '', 'Target version for door controllers'),
('github_latest_version', '', 'Latest version available on GitHub'),
('github_check_time', '', 'Last time GitHub was checked for updates');

-- --------------------------------------------------------
-- v2.4.0 - Add doors column to access_groups
-- --------------------------------------------------------

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'access_groups' AND column_name = 'doors');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `access_groups` ADD COLUMN `doors` text DEFAULT NULL COMMENT \'JSON array of allowed door names\' AFTER `description`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- v2.6.3 - Remote unlock & poll interval columns
-- --------------------------------------------------------

-- Add unlock_requested column to doors (for remote unlock from web UI)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'unlock_requested');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `unlock_requested` tinyint(1) NOT NULL DEFAULT 0 AFTER `update_status_time`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add poll_interval column to doors (configurable command poll interval)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'poll_interval');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `poll_interval` int(11) NOT NULL DEFAULT 3 AFTER `unlock_requested`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- v2.7.0 - Held-open state columns for doors
-- --------------------------------------------------------

-- held_open: reported by controller (0=normal, 1=held open)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'held_open');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `held_open` tinyint(1) NOT NULL DEFAULT 0 AFTER `locked`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hold_requested: set by web UI (0=none, 1=hold open, 2=release)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'hold_requested');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `hold_requested` tinyint(1) NOT NULL DEFAULT 0 AFTER `held_open`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Notification log table for deduplication
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_lookup` (`event_type`, `event_key`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('smtp_from', '', 'SMTP from email address for notifications');

-- --------------------------------------------------------
-- v2.18.0 - Push-based controller communication
-- --------------------------------------------------------

-- Add listen_port column to doors (controller HTTPS listener port)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'listen_port');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `listen_port` int(11) DEFAULT NULL AFTER `poll_interval`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add api_key column to doors (shared secret for push auth)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'api_key');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `api_key` varchar(64) DEFAULT NULL AFTER `listen_port`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add push_available column to doors (tracks whether push is currently reachable)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'push_available');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `push_available` tinyint(1) NOT NULL DEFAULT 0 AFTER `api_key`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Push communication settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('default_listen_port', '8443', 'Default HTTPS listener port for controller push commands'),
('push_timeout', '3', 'Timeout in seconds for push commands to controllers'),
('push_fallback_poll_interval', '15', 'Poll interval in seconds when push listener is active (fallback safety net)'),
('status_check_timeout', '2', 'Timeout in seconds when pinging controllers for live status');

-- --------------------------------------------------------
-- v3.1.0 - Door sensor state columns
-- --------------------------------------------------------

-- door_sensor_gpio: GPIO pin number for magnetic reed switch (NULL = no sensor)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'door_sensor_gpio');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `door_sensor_gpio` int(11) DEFAULT NULL AFTER `push_available`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- door_open: current physical door state (NULL = no sensor, 0 = closed, 1 = open)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'door_open');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `door_open` tinyint(1) DEFAULT NULL AFTER `door_sensor_gpio`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- door_sensor_invert: invert door sensor logic (0 = normal NC, 1 = inverted NO)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doors' AND column_name = 'door_sensor_invert');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `doors` ADD COLUMN `door_sensor_invert` tinyint(1) NOT NULL DEFAULT 0 AFTER `door_open`', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
