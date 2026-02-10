-- System Features Migration
-- This migration adds tables and fields for new features

-- System Settings Table
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL UNIQUE,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('site_name', 'Colegio de Amore', 'string', 'Name of the institution'),
('site_email', 'admin@colegiodeamore.edu', 'string', 'System email address'),
('default_language', 'en', 'string', 'Default language code'),
('enable_dark_mode', '1', 'boolean', 'Enable dark mode option'),
('email_enabled', '1', 'boolean', 'Enable email notifications'),
('smtp_host', '', 'string', 'SMTP server host'),
('smtp_port', '587', 'integer', 'SMTP server port'),
('smtp_username', '', 'string', 'SMTP username'),
('smtp_password', '', 'string', 'SMTP password (encrypted)'),
('smtp_encryption', 'tls', 'string', 'SMTP encryption type'),
('backup_enabled', '1', 'boolean', 'Enable automatic backups'),
('backup_frequency', 'daily', 'string', 'Backup frequency (daily, weekly, monthly)'),
('max_upload_size', '5242880', 'integer', 'Maximum file upload size in bytes (5MB default)'),
('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx', 'string', 'Allowed file types for uploads')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- User Preferences Table (for dark mode, language, etc.)
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `preference_key` varchar(50) NOT NULL,
  `preference_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_preference` (`user_id`, `preference_key`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Language Translations Table
CREATE TABLE IF NOT EXISTS `translations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `language_code` varchar(10) NOT NULL DEFAULT 'en',
  `translation_key` varchar(100) NOT NULL,
  `translation_value` text NOT NULL,
  `context` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `lang_key` (`language_code`, `translation_key`),
  KEY `language_code` (`language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default English translations
INSERT INTO `translations` (`language_code`, `translation_key`, `translation_value`, `context`) VALUES
('en', 'dashboard', 'Dashboard', 'navigation'),
('en', 'applications', 'Applications', 'navigation'),
('en', 'users', 'Users', 'navigation'),
('en', 'teachers', 'Teachers', 'navigation'),
('en', 'subjects', 'Subjects', 'navigation'),
('en', 'courses', 'Courses', 'navigation'),
('en', 'sections', 'Sections', 'navigation'),
('en', 'settings', 'Settings', 'navigation'),
('en', 'logout', 'Logout', 'navigation'),
('en', 'welcome', 'Welcome', 'general'),
('en', 'save', 'Save', 'button'),
('en', 'cancel', 'Cancel', 'button'),
('en', 'delete', 'Delete', 'button'),
('en', 'edit', 'Edit', 'button'),
('en', 'add', 'Add', 'button'),
('en', 'search', 'Search', 'general'),
('en', 'profile', 'Profile', 'navigation'),
('en', 'dark_mode', 'Dark Mode', 'settings'),
('en', 'language', 'Language', 'settings')
ON DUPLICATE KEY UPDATE `translation_value` = VALUES(`translation_value`);

-- Database Backups Table
CREATE TABLE IF NOT EXISTS `database_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_name` varchar(255) NOT NULL,
  `backup_path` varchar(500) NOT NULL,
  `backup_size` bigint(20) DEFAULT NULL,
  `backup_type` enum('manual','automatic') DEFAULT 'manual',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `database_backups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add profile_picture field to users if it doesn't exist
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `profile_picture` varchar(255) DEFAULT NULL AFTER `email_verified`;

-- Add prerequisites enforcement flag to subjects if it doesn't exist
ALTER TABLE `subjects`
ADD COLUMN IF NOT EXISTS `enforce_prerequisites` tinyint(1) DEFAULT 1 AFTER `prerequisites`;

