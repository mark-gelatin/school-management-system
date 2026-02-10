-- Unified Colegio de Amore Database - Complete Version
-- Consolidates ALL features: admission portal, student management, admin panel,
-- course/section, requirement tracking, schedules, enrollment management, and legacy login data
-- Import this single file into MySQL/MariaDB to provision the complete system.
-- This replaces the need for multiple databases and migration files.
-- Generated: 2025-01-XX (Updated with all migrations)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `amore_college`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `amore_college`;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- CORE TABLES
-- ============================================================

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
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT 'N/A',
  `birthday` date DEFAULT NULL,
  `nationality` varchar(50) DEFAULT 'Filipino',
  `phone_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `educational_status` enum('New Student','Transferee','Returning Student','Irregular') DEFAULT 'New Student',
  `student_id_number` varchar(20) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `country` varchar(50) DEFAULT 'Philippines',
  `city_province` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `baranggay` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `mother_occupation` varchar(100) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `father_occupation` varchar(100) DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `emergency_address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','suspended','graduated') DEFAULT 'active',
  `email_verified` tinyint(1) DEFAULT 0,
  `profile_picture` varchar(255) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_student_id_number` (`student_id_number`),
  KEY `idx_program` (`program`),
  KEY `idx_status` (`status`),
  KEY `idx_educational_status` (`educational_status`),
  KEY `course_id` (`course_id`)
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
  `enforce_prerequisites` tinyint(1) DEFAULT 1,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_program_year` (`program`, `year_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for teacher_subjects
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
-- Table structure for section_schedules
-- --------------------------------------------------------
DROP TABLE IF EXISTS `section_schedules`;
CREATE TABLE `section_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(50) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT '1st',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  KEY `subject_id` (`subject_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `classroom_id` (`classroom_id`),
  KEY `idx_section_day` (`section_id`, `day_of_week`),
  KEY `idx_academic_year` (`academic_year`, `semester`),
  CONSTRAINT `section_schedules_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `section_schedules_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `section_schedules_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `section_schedules_ibfk_4` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for grades
-- --------------------------------------------------------
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `grade` decimal(5,2) NOT NULL,
  `grade_type` enum('quiz','assignment','exam','project','participation','midterm','final') NOT NULL,
  `max_points` decimal(5,2) DEFAULT 100.00,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `manually_edited` tinyint(1) DEFAULT 0,
  `edited_by` int(11) DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_grade_type` (`grade_type`),
  KEY `edited_by` (`edited_by`),
  KEY `idx_academic_year` (`academic_year`, `semester`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grades_ibfk_5` FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for student_gpa
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
-- Table structure for student_back_subjects
-- --------------------------------------------------------
DROP TABLE IF EXISTS `student_back_subjects`;
CREATE TABLE `student_back_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `required_units` decimal(3,1) DEFAULT NULL,
  `completed_units` decimal(3,1) DEFAULT 0.0,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `completion_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_subject` (`student_id`, `subject_id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  KEY `status` (`status`),
  CONSTRAINT `student_back_subjects_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_back_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- ADMISSION & APPLICATION TABLES
-- ============================================================

-- --------------------------------------------------------
-- Table structure for admission_applications
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
-- Table structure for academic_records
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
-- Table structure for application_requirements
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
-- Table structure for application_requirement_submissions
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
-- Table structure for application_payments
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

-- ============================================================
-- ENROLLMENT MANAGEMENT TABLES
-- ============================================================

-- --------------------------------------------------------
-- Table structure for enrollment_periods
-- --------------------------------------------------------
DROP TABLE IF EXISTS `enrollment_periods`;
CREATE TABLE `enrollment_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('active','closed','scheduled') DEFAULT 'scheduled',
  `auto_close` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_course_period` (`course_id`, `academic_year`, `semester`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`, `end_date`),
  CONSTRAINT `enrollment_periods_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollment_periods_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for enrollment_requests
-- --------------------------------------------------------
DROP TABLE IF EXISTS `enrollment_requests`;
CREATE TABLE `enrollment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrollment_period_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `status` enum('pending','approved','rejected','voided') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `requirements_verified` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_period` (`student_id`, `enrollment_period_id`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`),
  KEY `enrollment_period_id` (`enrollment_period_id`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_status` (`status`),
  KEY `idx_academic_year` (`academic_year`, `semester`),
  CONSTRAINT `enrollment_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollment_requests_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollment_requests_ibfk_3` FOREIGN KEY (`enrollment_period_id`) REFERENCES `enrollment_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollment_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- ADMIN & SYSTEM TABLES
-- ============================================================

-- --------------------------------------------------------
-- Table structure for admin_logs
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
-- Table structure for system_settings
-- --------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for user_preferences
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
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

-- --------------------------------------------------------
-- Table structure for translations
-- --------------------------------------------------------
DROP TABLE IF EXISTS `translations`;
CREATE TABLE `translations` (
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

-- --------------------------------------------------------
-- Table structure for database_backups
-- --------------------------------------------------------
DROP TABLE IF EXISTS `database_backups`;
CREATE TABLE `database_backups` (
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

-- ============================================================
-- ADD FOREIGN KEY CONSTRAINTS (after all tables are created)
-- ============================================================

-- Add course_id foreign key to users table
ALTER TABLE `users`
ADD CONSTRAINT `users_ibfk_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;

-- ============================================================
-- LEGACY TABLES (for backward compatibility)
-- ============================================================

-- --------------------------------------------------------
-- Legacy admission + login tables (formerly `amore_database`)
-- --------------------------------------------------------
DROP TABLE IF EXISTS `personal_info`;
CREATE TABLE `personal_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstname` varchar(100) NOT NULL,
  `middlename` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) NOT NULL,
  `birthdate` date NOT NULL,
  `sex` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_personal_name` (`lastname`, `firstname`, `birthdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `admission_info`;
CREATE TABLE `admission_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_to_enroll` varchar(255) NOT NULL,
  `educational_status` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admission_program` (`program_to_enroll`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `contact_info`;
CREATE TABLE `contact_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `current_address` text NOT NULL,
  `permanent_address` text DEFAULT NULL,
  `mobile_number` varchar(30) NOT NULL,
  `landline_number` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_mobile` (`mobile_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `account_info`;
CREATE TABLE `account_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `student_list`;
CREATE TABLE `student_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_number` (`student_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

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

-- Default system settings
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

-- Default English translations
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

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

