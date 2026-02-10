-- Enhanced Student Grade Management Database with Admission Portal Integration
-- This database integrates admission portal data with student grade management
-- Version: 2.0
-- Date: 2025

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `student_grade_management_enhanced` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `student_grade_management_enhanced`;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `classrooms`;
CREATE TABLE `classrooms` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `classroom_students`;
CREATE TABLE `classroom_students` (
  `id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `enrollment_status` enum('enrolled','dropped','transferred') DEFAULT 'enrolled',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dropped_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `units` decimal(3,1) DEFAULT 3.0,
  `program` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `prerequisites` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `grade` decimal(5,2) NOT NULL,
  `grade_type` enum('quiz','assignment','exam','project','participation','final') NOT NULL,
  `max_points` decimal(5,2) DEFAULT 100.00,
  `remarks` text DEFAULT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `student_gpa`;
CREATE TABLE `student_gpa` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `gpa` decimal(3,2) DEFAULT NULL,
  `total_units` decimal(5,1) DEFAULT 0.0,
  `total_grade_points` decimal(8,2) DEFAULT 0.0,
  `status` enum('passed','failed','incomplete') DEFAULT 'passed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `admission_applications`;
CREATE TABLE `admission_applications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `application_number` varchar(20) NOT NULL,
  `application_date` date NOT NULL,
  `program_applied` varchar(100) NOT NULL,
  `educational_status` enum('New Student','Transferee','Returning Student') NOT NULL,
  `status` enum('pending','approved','rejected','waitlisted') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `academic_records`;
CREATE TABLE `academic_records` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `status` enum('enrolled','dropped','transferred','graduated') DEFAULT 'enrolled',
  `enrollment_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Insert sample data
--

-- Insert default admin user
INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`, `status`, `created_at`) VALUES
(1, 'admin', '$2y$10$bod.D77.aBWtEqU73d4t8ufT.1NxZNnLKMCRQFcZoSYsnXqLUYRqS', 'admin@colegiodeamore.edu', 'admin', 'System', 'Administrator', 'active', NOW());

-- Insert sample teacher
INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`, `program`, `status`, `created_at`) VALUES
(2, 'teacher', '$2y$10$E/gvwwLXV7Iv5tiVWEBNru2wcNCQAuvWCiVpwIrEzwER3vJ5WfA/u', 'teacher@colegiodeamore.edu', 'teacher', 'Maria', 'Santos', 'BS Computer Science', 'active', NOW());

-- Insert sample student account
INSERT INTO `users` (
  `id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`, `middle_name`, `suffix`, `birthday`,
  `nationality`, `phone_number`, `gender`, `program`, `educational_status`, `student_id_number`, `country`,
  `city_province`, `municipality`, `baranggay`, `address`, `address_line2`, `postal_code`, `mother_name`,
  `mother_phone`, `mother_occupation`, `father_name`, `father_phone`, `father_occupation`, `emergency_name`,
  `emergency_phone`, `emergency_address`, `status`, `created_at`
) VALUES (
  3, 'STU20250001', '$2y$10$zKkI4cFnL/xl3zI8psjmkutGhI3Uc.8p1qxcn45pA9uzy90JbjBNG', 'student@colegiodeamore.edu', 'student',
  'Juan', 'Dela Cruz', 'Reyes', 'N/A', '2005-05-12', 'Filipino', '+639123456789', 'Male', 'BS Computer Science',
  'New Student', 'STU20250001', 'Philippines', 'Cavite', 'Dasmariñas', 'Barangay 1', '123 University Road', NULL,
  '4114', 'Maria Dela Cruz', '+639123456788', 'Entrepreneur', 'Jose Dela Cruz', '+639123456787', 'Engineer',
  'Ana Dela Cruz', '+639123456786', '123 University Road, Dasmariñas City', 'active', NOW()
);

-- Insert sample subjects
INSERT INTO `subjects` (`id`, `name`, `code`, `description`, `units`, `program`, `year_level`) VALUES
(1, 'Introduction to Programming', 'CS101', 'Basic programming concepts and logic', 3.0, 'BS Computer Science', '1st Year'),
(2, 'Database Management Systems', 'CS201', 'Database design and implementation', 3.0, 'BS Computer Science', '2nd Year'),
(3, 'Web Development', 'CS301', 'Frontend and backend web development', 3.0, 'BS Computer Science', '3rd Year'),
(4, 'Criminology Fundamentals', 'CRIM101', 'Introduction to criminology', 3.0, 'BS Criminology', '1st Year'),
(5, 'Hospitality Management', 'HM101', 'Introduction to hospitality industry', 3.0, 'BS Hospitality Management', '1st Year');

-- Insert sample classroom
INSERT INTO `classrooms` (`id`, `name`, `description`, `teacher_id`, `program`, `year_level`, `section`, `academic_year`) VALUES
(1, 'CS 1A - Programming Fundamentals', 'First year Computer Science students', 2, 'BS Computer Science', '1st Year', 'A', '2024-2025');

-- Enroll sample student into classroom
INSERT INTO `classroom_students` (`id`, `classroom_id`, `student_id`, `enrollment_status`, `enrolled_at`, `dropped_at`, `notes`) VALUES
(1, 1, 3, 'enrolled', NOW(), NULL, NULL);

-- Record sample grade
INSERT INTO `grades` (`id`, `student_id`, `subject_id`, `classroom_id`, `teacher_id`, `grade`, `grade_type`, `max_points`, `remarks`, `graded_at`, `updated_at`) VALUES
(1, 3, 1, 1, 2, 92.50, 'exam', 100.00, 'Outstanding performance', NOW(), NOW());

-- Record sample GPA entry
INSERT INTO `student_gpa` (`id`, `student_id`, `semester`, `academic_year`, `gpa`, `total_units`, `total_grade_points`, `status`, `created_at`, `updated_at`) VALUES
(1, 3, '1st', '2024-2025', 3.75, 3.0, 11.25, 'passed', NOW(), NOW());

-- Record sample admission application
INSERT INTO `admission_applications` (`id`, `student_id`, `application_number`, `application_date`, `program_applied`, `educational_status`, `status`, `reviewed_by`, `reviewed_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, 3, 'APP20250001', CURDATE(), 'BS Computer Science', 'New Student', 'approved', 1, NOW(), 'Initial batch applicant', NOW(), NOW());

-- Record sample academic history entry
INSERT INTO `academic_records` (`id`, `student_id`, `academic_year`, `semester`, `year_level`, `section`, `status`, `enrollment_date`, `created_at`) VALUES
(1, 3, '2024-2025', '1st', '1st Year', 'A', 'enrolled', CURDATE(), NOW());

-- --------------------------------------------------------

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_student_id_number` (`student_id_number`),
  ADD KEY `idx_program` (`program`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `classrooms`
--
ALTER TABLE `classrooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_program_year` (`program`, `year_level`);

--
-- Indexes for table `classroom_students`
--
ALTER TABLE `classroom_students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `classroom_id` (`classroom_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_enrollment_status` (`enrollment_status`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_program_year` (`program`, `year_level`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `classroom_id` (`classroom_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_grade_type` (`grade_type`);

--
-- Indexes for table `student_gpa`
--
ALTER TABLE `student_gpa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_semester_year` (`semester`, `academic_year`);

--
-- Indexes for table `admission_applications`
--
ALTER TABLE `admission_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_number` (`application_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `academic_records`
--
ALTER TABLE `academic_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_academic_year_semester` (`academic_year`, `semester`);

-- --------------------------------------------------------

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `classrooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `classroom_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `student_gpa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `admission_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `academic_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- --------------------------------------------------------

--
-- Constraints for dumped tables
--

--
-- Constraints for table `classrooms`
--
ALTER TABLE `classrooms`
  ADD CONSTRAINT `classrooms_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `classroom_students`
--
ALTER TABLE `classroom_students`
  ADD CONSTRAINT `classroom_students_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `classroom_students_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_gpa`
--
ALTER TABLE `student_gpa`
  ADD CONSTRAINT `student_gpa_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admission_applications`
--
ALTER TABLE `admission_applications`
  ADD CONSTRAINT `admission_applications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_applications_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `academic_records`
--
ALTER TABLE `academic_records`
  ADD CONSTRAINT `academic_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

