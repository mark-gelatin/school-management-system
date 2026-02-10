-- Full Database Setup for Colegio de Amore Student Management System
-- Combines enhanced grade management schema, admission portal, admin panel,
-- course/section management, and application requirement/payment features.
-- Import this file into MySQL/MariaDB to provision a fresh environment.
-- Generated: 2025-11-11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `student_grade_management_enhanced`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `student_grade_management_enhanced`;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for users
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('admin','teacher','student') NOT NULL DEFAULT 'student',

  -- Personal Information
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT 'N/A',
  `birthday` date DEFAULT NULL,
  `nationality` varchar(50) DEFAULT 'Filipino',
  `phone_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,

  -- Academic Information
  `program` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `educational_status` enum('New Student','Transferee','Returning Student') DEFAULT 'New Student',
  `student_id_number` varchar(20) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,

  -- Address Information
  `country` varchar(50) DEFAULT 'Philippines',
  `city_province` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `baranggay` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,

  -- Parents Information
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `mother_occupation` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `father_occupation` varchar(100) DEFAULT NULL,

  -- Emergency Contact
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `emergency_address` varchar(255) DEFAULT NULL,

  -- Account Status
  `status` enum('active','inactive','suspended','graduated') DEFAULT 'active',
  `email_verified` tinyint(1) DEFAULT 0,
  `profile_picture` varchar(255) DEFAULT NULL,

  -- Timestamps
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_student_id_number` (`student_id_number`),
  KEY `idx_program` (`program`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for courses
-- --------------------------------------------------------
DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_years` int(11) DEFAULT 4,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for sections
-- --------------------------------------------------------
DROP TABLE IF EXISTS `sections`;
CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT '1st',
  `teacher_id` int(11) DEFAULT NULL,
  `max_students` int(11) DEFAULT 50,
  `current_students` int(11) DEFAULT 0,
  `status` enum('active','inactive','closed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_course_year` (`course_id`, `year_level`, `academic_year`),
  CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sections_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for classrooms
-- --------------------------------------------------------
DROP TABLE IF EXISTS `classrooms`;
CREATE TABLE `classrooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT '1st',
  `max_students` int(11) DEFAULT 50,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_program_year` (`program`, `year_level`),
  CONSTRAINT `classrooms_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for classroom_students
-- --------------------------------------------------------
DROP TABLE IF EXISTS `classroom_students`;
CREATE TABLE `classroom_students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `classroom_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `enrollment_status` enum('enrolled','dropped','transferred') DEFAULT 'enrolled',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dropped_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_id` (`classroom_id`),
  KEY `student_id` (`student_id`),
  KEY `idx_enrollment_status` (`enrollment_status`),
  CONSTRAINT `classroom_students_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classroom_students_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for subjects
-- --------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `units` decimal(3,1) DEFAULT 3.0,
  `program` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `prerequisites` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_program_year` (`program`, `year_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for admin-managed teacher subjects
-- --------------------------------------------------------
DROP TABLE IF EXISTS `teacher_subjects`;
CREATE TABLE `teacher_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_teacher_subject` (`teacher_id`, `subject_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for grades
-- --------------------------------------------------------
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `grade` decimal(5,2) NOT NULL,
  `grade_type` enum('quiz','assignment','exam','project','participation','final') NOT NULL,
  `max_points` decimal(5,2) DEFAULT 100.00,
  `remarks` text DEFAULT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_grade_type` (`grade_type`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for student GPA tracking
-- --------------------------------------------------------
DROP TABLE IF EXISTS `student_gpa`;
CREATE TABLE `student_gpa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `gpa` decimal(3,2) DEFAULT NULL,
  `total_units` decimal(5,1) DEFAULT 0.0,
  `total_grade_points` decimal(8,2) DEFAULT 0.0,
  `status` enum('passed','failed','incomplete') DEFAULT 'passed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_semester_year` (`semester`, `academic_year`),
  CONSTRAINT `student_gpa_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for admission applications
-- --------------------------------------------------------
DROP TABLE IF EXISTS `admission_applications`;
CREATE TABLE `admission_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `application_number` varchar(20) NOT NULL,
  `application_date` date NOT NULL,
  `program_applied` varchar(100) NOT NULL,
  `educational_status` enum('New Student','Transferee','Returning Student') NOT NULL,
  `status` enum('pending','approved','rejected','waitlisted') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document_path` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_number` (`application_number`),
  KEY `student_id` (`student_id`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_status` (`status`),
  CONSTRAINT `admission_applications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admission_applications_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for academic records
-- --------------------------------------------------------
DROP TABLE IF EXISTS `academic_records`;
CREATE TABLE `academic_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `status` enum('enrolled','dropped','transferred','graduated') DEFAULT 'enrolled',
  `enrollment_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_academic_year_semester` (`academic_year`, `semester`),
  CONSTRAINT `academic_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for admin activity logs
-- --------------------------------------------------------
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for application requirements
-- --------------------------------------------------------
DROP TABLE IF EXISTS `application_requirements`;
CREATE TABLE `application_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requirement_name` varchar(255) NOT NULL,
  `requirement_description` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for requirement submissions
-- --------------------------------------------------------
DROP TABLE IF EXISTS `application_requirement_submissions`;
CREATE TABLE `application_requirement_submissions` (
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

-- --------------------------------------------------------
-- Table structure for application payments
-- --------------------------------------------------------
DROP TABLE IF EXISTS `application_payments`;
CREATE TABLE `application_payments` (
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

-- --------------------------------------------------------
-- Seed data
-- --------------------------------------------------------

-- Default admin account
INSERT INTO `users` (
  `id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`,
  `middle_name`, `suffix`, `birthday`, `nationality`, `phone_number`, `gender`,
  `program`, `department`, `educational_status`, `student_id_number`, `year_level`, `section`,
  `country`, `city_province`, `municipality`, `baranggay`, `address`, `address_line2`, `postal_code`,
  `mother_name`, `mother_phone`, `mother_occupation`, `father_name`, `father_phone`, `father_occupation`,
  `emergency_name`, `emergency_phone`, `emergency_address`, `status`, `email_verified`, `profile_picture`,
  `created_at`, `updated_at`
) VALUES (
  1, 'admin', '$2y$10$bod.D77.aBWtEqU73d4t8ufT.1NxZNnLKMCRQFcZoSYsnXqLUYRqS',
  'admin@colegiodeamore.edu', 'admin', 'System', 'Administrator',
  NULL, 'N/A', NULL, 'Filipino', NULL, NULL,
  NULL, 'Administration', 'New Student', NULL, NULL, NULL,
  'Philippines', NULL, NULL, NULL, NULL, NULL, NULL,
  NULL, NULL, NULL, NULL, NULL, NULL,
  NULL, NULL, NULL, 'active', 0, NULL,
  NOW(), NOW()
);

-- Sample teacher account
INSERT INTO `users` (
  `id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`,
  `middle_name`, `suffix`, `birthday`, `nationality`, `phone_number`, `gender`,
  `program`, `department`, `educational_status`, `student_id_number`, `year_level`, `section`,
  `country`, `city_province`, `municipality`, `baranggay`, `address`, `address_line2`, `postal_code`,
  `mother_name`, `mother_phone`, `mother_occupation`, `father_name`, `father_phone`, `father_occupation`,
  `emergency_name`, `emergency_phone`, `emergency_address`, `status`, `email_verified`, `profile_picture`,
  `created_at`, `updated_at`
) VALUES (
  2, 'teacher', '$2y$10$E/gvwwLXV7Iv5tiVWEBNru2wcNCQAuvWCiVpwIrEzwER3vJ5WfA/u',
  'teacher@colegiodeamore.edu', 'teacher', 'Maria', 'Santos',
  NULL, 'N/A', NULL, 'Filipino', '+639171234567', 'Female',
  'BS Computer Science', 'College of Computing', 'New Student', NULL, NULL, NULL,
  'Philippines', 'Cavite', 'Dasmariñas', 'Barangay 1', '123 University Road', NULL, '4114',
  NULL, NULL, NULL, NULL, NULL, NULL,
  NULL, NULL, NULL, 'active', 0, NULL,
  NOW(), NOW()
);

-- Sample student account
INSERT INTO `users` (
  `id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`,
  `middle_name`, `suffix`, `birthday`, `nationality`, `phone_number`, `gender`,
  `program`, `department`, `educational_status`, `student_id_number`, `year_level`, `section`,
  `country`, `city_province`, `municipality`, `baranggay`, `address`, `address_line2`, `postal_code`,
  `mother_name`, `mother_phone`, `mother_occupation`, `father_name`, `father_phone`, `father_occupation`,
  `emergency_name`, `emergency_phone`, `emergency_address`, `status`, `email_verified`, `profile_picture`,
  `created_at`, `updated_at`
) VALUES (
  3, 'STU20250001', '$2y$10$zKkI4cFnL/xl3zI8psjmkutGhI3Uc.8p1qxcn45pA9uzy90JbjBNG',
  'student@colegiodeamore.edu', 'student', 'Juan', 'Dela Cruz',
  'Reyes', 'N/A', '2005-05-12', 'Filipino', '+639123456789', 'Male',
  'BS Computer Science', 'College of Computing', 'New Student', 'STU20250001', '1st Year', 'A',
  'Philippines', 'Cavite', 'Dasmariñas', 'Barangay 1', '123 University Road', NULL, '4114',
  'Maria Dela Cruz', '+639123456788', 'Entrepreneur', 'Jose Dela Cruz', '+639123456787', 'Engineer',
  'Ana Dela Cruz', '+639123456786', '123 University Road, Dasmariñas City', 'active', 0, NULL,
  NOW(), NOW()
);

-- Sample courses
INSERT INTO `courses` (`code`, `name`, `description`, `duration_years`, `status`) VALUES
('BSBA', 'Bachelor of Science in Business Administration', 'Business administration and management program', 4, 'active'),
('BSIT', 'Bachelor of Science in Information Technology', 'Information technology and computer systems program', 4, 'active'),
('BSCS', 'Bachelor of Science in Computer Science', 'Computer science and programming program', 4, 'active'),
('BSCRIM', 'Bachelor of Science in Criminology', 'Criminology and law enforcement program', 4, 'active'),
('BSHM', 'Bachelor of Science in Hospitality Management', 'Hospitality and tourism management program', 4, 'active');

-- Sample subjects
INSERT INTO `subjects` (`id`, `name`, `code`, `description`, `units`, `program`, `year_level`) VALUES
(1, 'Introduction to Programming', 'CS101', 'Basic programming concepts and logic', 3.0, 'BS Computer Science', '1st Year'),
(2, 'Database Management Systems', 'CS201', 'Database design and implementation', 3.0, 'BS Computer Science', '2nd Year'),
(3, 'Web Development', 'CS301', 'Frontend and backend web development', 3.0, 'BS Computer Science', '3rd Year'),
(4, 'Criminology Fundamentals', 'CRIM101', 'Introduction to criminology', 3.0, 'BS Criminology', '1st Year'),
(5, 'Hospitality Management', 'HM101', 'Introduction to hospitality industry', 3.0, 'BS Hospitality Management', '1st Year');

-- Sample classroom
INSERT INTO `classrooms` (`id`, `name`, `description`, `teacher_id`, `program`, `year_level`, `section`, `academic_year`, `semester`, `max_students`, `status`, `created_at`, `updated_at`) VALUES
(1, 'CS 1A - Programming Fundamentals', 'First year Computer Science students', 2, 'BS Computer Science', '1st Year', 'A', '2024-2025', '1st', 50, 'active', NOW(), NOW());

-- Enroll sample student into classroom
INSERT INTO `classroom_students` (`id`, `classroom_id`, `student_id`, `enrollment_status`, `enrolled_at`, `dropped_at`, `notes`) VALUES
(1, 1, 3, 'enrolled', NOW(), NULL, NULL);

-- Teacher assignment to subject
INSERT INTO `teacher_subjects` (`id`, `teacher_id`, `subject_id`, `created_at`) VALUES
(1, 2, 1, NOW());

-- Record sample grade
INSERT INTO `grades` (`id`, `student_id`, `subject_id`, `classroom_id`, `teacher_id`, `grade`, `grade_type`, `max_points`, `remarks`, `graded_at`, `updated_at`) VALUES
(1, 3, 1, 1, 2, 92.50, 'exam', 100.00, 'Outstanding performance', NOW(), NOW());

-- Record sample GPA entry
INSERT INTO `student_gpa` (`id`, `student_id`, `semester`, `academic_year`, `gpa`, `total_units`, `total_grade_points`, `status`, `created_at`, `updated_at`) VALUES
(1, 3, '1st', '2024-2025', 3.75, 3.0, 11.25, 'passed', NOW(), NOW());

-- Record sample admission application
INSERT INTO `admission_applications` (`id`, `student_id`, `application_number`, `application_date`, `program_applied`, `educational_status`, `status`, `reviewed_by`, `reviewed_at`, `notes`, `document_path`, `created_at`, `updated_at`) VALUES
(1, 3, 'APP20250001', CURDATE(), 'BS Computer Science', 'New Student', 'approved', 1, NOW(), 'Initial batch applicant', NULL, NOW(), NOW());

-- Record sample academic history entry
INSERT INTO `academic_records` (`id`, `student_id`, `academic_year`, `semester`, `year_level`, `section`, `status`, `enrollment_date`, `created_at`) VALUES
(1, 3, '2024-2025', '1st', '1st Year', 'A', 'enrolled', CURDATE(), NOW());

-- Default admission requirements
INSERT INTO `application_requirements` (`id`, `requirement_name`, `requirement_description`, `is_required`, `created_at`, `updated_at`) VALUES
(1, 'Birth Certificate', 'Certified true copy of birth certificate from PSA', 1, NOW(), NOW()),
(2, 'Form 138 (Report Card)', 'Original copy of Form 138 or Transcript of Records for transferees', 1, NOW(), NOW()),
(3, 'Good Moral Character', 'Certificate of Good Moral Character from previous school', 1, NOW(), NOW()),
(4, '2x2 ID Picture', 'Two recent 2x2 ID pictures with white background', 1, NOW(), NOW()),
(5, 'Medical Certificate', 'Medical certificate from a licensed physician', 1, NOW(), NOW());

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


