# Database SQL Files Organization

This folder contains all SQL files for the Colegio de Amore database system, organized for easy maintenance and deployment.

## 📁 Folder Structure

```
database/
├── main/                          # Active database files (for production use)
│   └── amore_unified_complete.sql # PRIMARY FILE - Complete unified database
├── archive/                       # Historical/reference files (not for production)
│   ├── migrations/                # Individual migration files (consolidated into complete file)
│   ├── amore_unified_old.sql     # Previous version
│   ├── amore_database_legacy.sql # Legacy admission database
│   └── [other legacy files]       # Older database versions
├── README.md                      # This file
└── UNIFICATION_NOTES.md          # Detailed unification documentation
```

---

## 🚀 Quick Start

### For New Installation:
**Use:** `main/amore_unified_complete.sql`

This single file contains the complete database schema with all features:
- ✅ Admission portal
- ✅ Student management
- ✅ Admin panel
- ✅ Courses/sections management
- ✅ Section schedules
- ✅ Enrollment management (periods and requests)
- ✅ Requirements and payments tracking
- ✅ System settings and preferences
- ✅ Irregular students support
- ✅ Legacy login data (backward compatibility)

**Installation:**
1. Import `main/amore_unified_complete.sql` into MySQL/MariaDB
2. Or use `public/setup-database.php` for automated setup

---

## 📋 Active Files

### `main/amore_unified_complete.sql`
**Status:** ✅ **PRIMARY FILE - USE THIS FOR NEW INSTALLATIONS**

- **Database Name:** `amore_college`
- **Tables:** 28 tables (all features included)
- **Character Set:** utf8mb4
- **Includes:** All tables, relationships, constraints, and seed data
- **Last Updated:** 2025-01-XX

**What's Included:**
- All core tables (users, courses, sections, classrooms, subjects, grades, etc.)
- All admission and application tables
- All enrollment management tables
- All admin and system tables
- All legacy tables (for backward compatibility)

---

## 📦 Archived Files

### `archive/migrations/`
**Status:** 📦 **ARCHIVED - For Reference Only**

These migration files are **no longer needed** for new installations because all their content has been consolidated into `amore_unified_complete.sql`. They are kept for:
- Historical reference
- Understanding incremental changes
- Troubleshooting existing systems

**Migration Files:**
- `admin_panel_migration.sql`
- `application_requirements_payment_migration.sql`
- `courses_sections_migration.sql`
- `enrollment_management_migration.sql`
- `irregular_students_migration.sql`
- `section_schedules_migration.sql`
- `system_features_migration.sql`
- `update_grade_types_migration.sql`

### `archive/amore_unified_old.sql`
**Status:** 📦 **ARCHIVED - Superseded**

Previous version of the unified database. Superseded by `amore_unified_complete.sql`.

### `archive/amore_database_legacy.sql`
**Status:** 📦 **ARCHIVED - Legacy**

Legacy admission database. All tables are now included in the unified database.

### Other Archive Files
All other files in `archive/` are older versions kept for historical reference only.

---

## 🔄 Migration from Existing System

If you have an existing system:

1. **Backup your current database**
2. Export all data from existing databases
3. Import `main/amore_unified_complete.sql` to create the unified structure
4. Import your data into the unified `amore_college` database
5. Verify all functionality

**Note:** The unified database includes all tables from all migrations, so you don't need to run individual migration files.

---

## 📝 Database Connection

All system components connect to the single unified database:

- **Database Name:** `amore_college`
- **Connection File:** `server/includes/database.php`
- **Character Set:** utf8mb4
- **Collation:** utf8mb4_general_ci

---

## ⚠️ Important Notes

1. **Always backup** your database before making changes
2. **Use `amore_unified_complete.sql`** for all new installations
3. **Migration files are archived** - they're included in the complete file
4. **Archive folder is for reference only** - do not use for production
5. **Single database** - all tables are in `amore_college` for easier management

---

## 📚 Additional Documentation

- **UNIFICATION_NOTES.md** - Detailed information about database unification
- **CREDENTIALS.md** - Database connection credentials and default accounts

---

## 🛠️ Maintenance

### Adding New Features
When adding new database features:
1. Update `main/amore_unified_complete.sql` with new tables/changes
2. Update this README if structure changes significantly
3. Keep migration files in archive for historical reference

### Backup Strategy
- Regular backups of the `amore_college` database
- Export using: `mysqldump amore_college > backup.sql`
- Or use the admin panel backup feature

---

**Last Updated:** 2025-01-XX
