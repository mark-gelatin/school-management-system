-- Irregular Students Management Migration
-- Adds support for irregular students, back subjects tracking, and grade management

USE `amore_college`;

-- Add 'Irregular' to educational_status enum
ALTER TABLE `users` 
MODIFY COLUMN `educational_status` enum('New Student','Transferee','Returning Student','Irregular') DEFAULT 'New Student';

-- Table structure for tracking back subjects for irregular students
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

-- Add index for faster queries on irregular students
ALTER TABLE `users` 
ADD INDEX `idx_educational_status` (`educational_status`);

-- Add column to track if grade was manually edited by admin/registrar
-- Note: If columns already exist, you may need to comment out the corresponding ALTER TABLE statements
ALTER TABLE `grades`
ADD COLUMN `manually_edited` tinyint(1) DEFAULT 0 AFTER `remarks`;

ALTER TABLE `grades`
ADD COLUMN `edited_by` int(11) DEFAULT NULL AFTER `manually_edited`;

ALTER TABLE `grades`
ADD COLUMN `edited_at` timestamp NULL DEFAULT NULL AFTER `edited_by`;

-- Add index and foreign key (ignore errors if they already exist)
ALTER TABLE `grades`
ADD KEY `edited_by` (`edited_by`);

ALTER TABLE `grades`
ADD CONSTRAINT `grades_ibfk_edited_by` FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

