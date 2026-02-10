# Archive Folder - Historical Reference Only

This folder contains archived database files that are **NOT for production use**. These files are kept for historical reference, troubleshooting, and understanding the evolution of the database structure.

## ⚠️ DO NOT USE THESE FILES FOR NEW INSTALLATIONS

All content from these files has been consolidated into:
**`../main/amore_unified_complete.sql`**

---

## 📁 Contents

### `migrations/`
Individual migration files that were used to incrementally update the database. All migrations are now included in the unified complete file.

**Files:**
- `admin_panel_migration.sql` - Admin panel functionality
- `application_requirements_payment_migration.sql` - Requirements and payments
- `courses_sections_migration.sql` - Courses and sections management
- `enrollment_management_migration.sql` - Enrollment periods and requests
- `irregular_students_migration.sql` - Irregular students support
- `section_schedules_migration.sql` - Section-based scheduling
- `system_features_migration.sql` - System settings and features
- `update_grade_types_migration.sql` - Grade type updates

### Legacy Database Files
- `amore_unified_old.sql` - Previous version of unified database
- `amore_database_legacy.sql` - Legacy admission database
- `enhanced_student_grade_management.sql` - Enhanced grade management
- `student_grade_management_full.sql` - Full grade management
- `student_grade_management.sql` - Original grade management

---

## 📖 When to Reference These Files

1. **Understanding Database Evolution** - See how the schema developed over time
2. **Troubleshooting** - Compare old structures with current implementation
3. **Data Migration** - Reference old structures when migrating from legacy systems
4. **Historical Context** - Understand why certain design decisions were made

---

## ✅ What to Use Instead

**For all new installations and production use:**
- `../main/amore_unified_complete.sql`

This file contains everything you need in a single, unified database structure.

