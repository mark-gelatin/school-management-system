-- Migration: Application Requirements and Payment System
-- This migration adds support for students to submit requirements and payment before approval

-- Table for defining required documents/requirements
CREATE TABLE IF NOT EXISTS `application_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requirement_name` varchar(255) NOT NULL,
  `requirement_description` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table for student requirement submissions
CREATE TABLE IF NOT EXISTS `application_requirement_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `submission_notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `requirement_id` (`requirement_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `application_requirement_submissions_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `admission_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_requirement_submissions_ibfk_2` FOREIGN KEY (`requirement_id`) REFERENCES `application_requirements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_requirement_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table for payment tracking
CREATE TABLE IF NOT EXISTS `application_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `receipt_path` varchar(500) DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `application_payments_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `admission_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default requirements
INSERT INTO `application_requirements` (`requirement_name`, `requirement_description`, `is_required`) VALUES
('Birth Certificate', 'Certified true copy of birth certificate from PSA', 1),
('Form 138 (Report Card)', 'Original copy of Form 138 (Report Card) or Transcript of Records for transferees', 1),
('Good Moral Character', 'Certificate of Good Moral Character from previous school', 1),
('2x2 ID Picture', 'Two (2) recent 2x2 ID pictures with white background', 1),
('Medical Certificate', 'Medical certificate from a licensed physician', 1);


