-- Migration script to update grade_type enum to only include 'midterm' and 'finals'
-- Run this script to update your database schema for college portal

-- First, update any existing grades to use the new types
-- Convert 'quiz', 'assignment', 'exam', 'project' to 'midterm' or 'finals' as appropriate
-- You may need to adjust this based on your data

-- Update existing grades (optional - adjust based on your needs)
-- UPDATE grades SET grade_type = 'midterm' WHERE grade_type IN ('quiz', 'assignment', 'exam', 'project');

-- Alter the enum to include only midterm and finals
ALTER TABLE `grades` MODIFY `grade_type` ENUM('midterm','finals') NOT NULL;

