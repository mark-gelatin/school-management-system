# Database Unification Notes

## Overview
All database tables have been unified into a single `amore_college` database. This simplifies database management, export/import operations, and ensures all system features work together seamlessly.

## What Was Unified

### Previously Separate Databases:
- `amore_college` - Main system database
- `amore_database` - Legacy admission database (tables now included in unified database)

### Migration Files Consolidated:
All tables from the following migration files are now included in `amore_unified_complete.sql`:
- `admin_panel_migration.sql` - Admin logs and teacher subjects
- `application_requirements_payment_migration.sql` - Requirements and payments
- `courses_sections_migration.sql` - Courses and sections
- `section_schedules_migration.sql` - Section schedules
- `enrollment_management_migration.sql` - Enrollment periods and requests
- `system_features_migration.sql` - System settings, preferences, translations, backups
- `irregular_students_migration.sql` - Back subjects tracking
- `update_grade_types_migration.sql` - Grade type updates (integrated into grades table)

## Complete Table List (28 Tables)

### Core Tables:
1. `users` - All system users (admin, teacher, student)
2. `courses` - Academic programs/courses
3. `sections` - Class sections
4. `classrooms` - Classroom management
5. `classroom_students` - Student enrollment in classrooms
6. `subjects` - Course subjects
7. `teacher_subjects` - Teacher-subject assignments
8. `section_schedules` - Section-based schedules
9. `grades` - Student grades
10. `student_gpa` - GPA tracking
11. `student_back_subjects` - Back subjects for irregular students

### Admission & Application Tables:
12. `admission_applications` - Student admission applications
13. `academic_records` - Academic history
14. `application_requirements` - Required documents
15. `application_requirement_submissions` - Submitted requirements
16. `application_payments` - Payment tracking

### Enrollment Management Tables:
17. `enrollment_periods` - Enrollment period definitions
18. `enrollment_requests` - Student enrollment requests

### Admin & System Tables:
19. `admin_logs` - Admin activity logs
20. `system_settings` - System configuration
21. `user_preferences` - User preferences (dark mode, language, etc.)
22. `translations` - Multi-language support
23. `database_backups` - Backup tracking

### Legacy Tables (Backward Compatibility):
24. `personal_info` - Legacy personal information
25. `admission_info` - Legacy admission info
26. `contact_info` - Legacy contact info
27. `account_info` - Legacy account info
28. `student_list` - Legacy student list

## Benefits of Unification

1. **Simplified Export/Import**: Single database file contains everything
2. **Easier Backup/Restore**: One database to backup instead of multiple
3. **Better Data Integrity**: All foreign key relationships preserved
4. **Reduced Complexity**: No need to manage multiple databases
5. **Consistent Naming**: All tables follow the same naming conventions
6. **Complete Feature Set**: All system features available in one place

## Database Connection

All system components connect to the single `amore_college` database:
- **Connection File**: `server/includes/database.php`
- **Database Name**: `amore_college`
- **Character Set**: `utf8mb4`
- **Collation**: `utf8mb4_general_ci`

## Installation

For new installations, simply import:
```
database/main/amore_unified_complete.sql
```

This single file will create:
- The `amore_college` database
- All 28 tables with proper relationships
- All foreign key constraints
- Default seed data (admin, teacher, student accounts)
- Sample courses, subjects, and other reference data

## Migration from Existing System

If you have an existing system with separate databases:

1. **Backup all existing databases**
2. Export all data from existing databases
3. Import `amore_unified_complete.sql` to create the unified structure
4. Import your data into the unified database
5. Update any code that references multiple databases (if any)

## Foreign Key Relationships

All foreign key constraints are preserved:
- Users → Courses (via `course_id`)
- Sections → Courses, Users (teachers)
- Classrooms → Users (teachers)
- Classroom Students → Classrooms, Users
- Subjects → (standalone)
- Teacher Subjects → Users, Subjects
- Section Schedules → Sections, Subjects, Users (teachers), Classrooms
- Grades → Users (students, teachers), Subjects, Classrooms
- Student GPA → Users
- Student Back Subjects → Users, Subjects
- Admission Applications → Users
- Academic Records → Users
- Application Requirements → (standalone)
- Application Requirement Submissions → Admission Applications, Application Requirements, Users
- Application Payments → Admission Applications, Users
- Enrollment Periods → Courses, Users
- Enrollment Requests → Users, Courses, Enrollment Periods
- Admin Logs → Users
- System Settings → Users
- User Preferences → Users
- Database Backups → Users

## Notes

- All tables use InnoDB engine for foreign key support
- All timestamps use `current_timestamp()` defaults
- All character sets are `utf8mb4` for full Unicode support
- Legacy tables are maintained for backward compatibility with older code
- The `users` table includes a `course_id` field that references `courses` (added via ALTER TABLE after courses table is created)

