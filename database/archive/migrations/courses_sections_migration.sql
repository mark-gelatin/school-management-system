-- Courses and Sections Management Migration
-- Adds courses and sections tables for better management

USE `amore_college`;

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
-- Insert sample courses
-- --------------------------------------------------------

INSERT INTO `courses` (`code`, `name`, `description`, `duration_years`, `status`) VALUES
('BSBA', 'Bachelor of Science in Business Administration', 'Business administration and management program', 4, 'active'),
('BSIT', 'Bachelor of Science in Information Technology', 'Information technology and computer systems program', 4, 'active'),
('BSCS', 'Bachelor of Science in Computer Science', 'Computer science and programming program', 4, 'active'),
('BSCRIM', 'Bachelor of Science in Criminology', 'Criminology and law enforcement program', 4, 'active'),
('BSHM', 'Bachelor of Science in Hospitality Management', 'Hospitality and tourism management program', 4, 'active');



