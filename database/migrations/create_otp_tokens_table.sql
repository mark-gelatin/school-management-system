-- OTP Tokens Table for Email-based OTP System
-- Created for Colegio de Amore
-- Purpose: Store OTP tokens for registration and password reset

CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'User ID if user exists (for password reset), NULL for registration',
  `email` varchar(100) NOT NULL COMMENT 'Email address where OTP is sent',
  `otp` varchar(6) NOT NULL COMMENT '6-digit numeric OTP',
  `purpose` enum('registration','reset') NOT NULL COMMENT 'Purpose: registration or password reset',
  `expiry` datetime NOT NULL COMMENT 'OTP expiration timestamp',
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of validation attempts',
  `max_attempts` int(11) NOT NULL DEFAULT 5 COMMENT 'Maximum allowed attempts',
  `used` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether OTP has been used',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of requester',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_purpose` (`email`, `purpose`, `used`),
  KEY `idx_expiry` (`expiry`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_otp` (`otp`),
  CONSTRAINT `otp_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='OTP tokens for email verification';

-- Rate limiting table for OTP requests
CREATE TABLE IF NOT EXISTS `otp_rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `purpose` enum('registration','reset') NOT NULL,
  `request_count` int(11) NOT NULL DEFAULT 1,
  `first_request` datetime NOT NULL,
  `last_request` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL COMMENT 'Blocked until this timestamp if rate limit exceeded',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email_ip_purpose` (`email`, `ip_address`, `purpose`),
  KEY `idx_blocked_until` (`blocked_until`),
  KEY `idx_last_request` (`last_request`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rate limiting for OTP requests';











