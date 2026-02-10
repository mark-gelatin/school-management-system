# Database Folder Structure

## Current Organization (2025)

```
database/
├── main/                                    # ✅ ACTIVE - Production files
│   └── amore_unified_complete.sql          # PRIMARY FILE - Use this for all installations
│
├── archive/                                 # 📦 ARCHIVED - Reference only
│   ├── migrations/                         # Individual migration files (consolidated)
│   │   ├── admin_panel_migration.sql
│   │   ├── application_requirements_payment_migration.sql
│   │   ├── courses_sections_migration.sql
│   │   ├── enrollment_management_migration.sql
│   │   ├── irregular_students_migration.sql
│   │   ├── section_schedules_migration.sql
│   │   ├── system_features_migration.sql
│   │   └── update_grade_types_migration.sql
│   │
│   ├── amore_unified_old.sql              # Previous unified version
│   ├── amore_database_legacy.sql          # Legacy admission database
│   ├── enhanced_student_grade_management.sql
│   ├── student_grade_management_full.sql
│   ├── student_grade_management.sql
│   └── ARCHIVE_README.md                   # Archive documentation
│
├── README.md                                # Main documentation
├── UNIFICATION_NOTES.md                    # Unification details
└── STRUCTURE.md                            # This file
```

---

## File Status

### ✅ Active Files (Production Use)

| File | Status | Purpose |
|------|--------|---------|
| `main/amore_unified_complete.sql` | **PRIMARY** | Complete unified database - use for all new installations |

### 📦 Archived Files (Reference Only)

| Location | Status | Reason |
|----------|--------|--------|
| `archive/migrations/*` | Archived | All migrations consolidated into complete file |
| `archive/amore_unified_old.sql` | Archived | Superseded by complete version |
| `archive/amore_database_legacy.sql` | Archived | Legacy database, tables now in unified |
| `archive/*.sql` (others) | Archived | Older versions, superseded |

---

## Migration Path

### Old Structure (Before Unification)
- Multiple migration files needed to be run in sequence
- Separate databases for different features
- Complex setup process

### New Structure (After Unification)
- Single file contains everything
- One database (`amore_college`) for all features
- Simple one-step installation

---

## Maintenance Guidelines

### Adding New Features
1. Update `main/amore_unified_complete.sql` directly
2. Do NOT create new migration files
3. Update documentation if structure changes significantly

### Archiving Files
1. Move obsolete files to `archive/`
2. Rename with descriptive suffix (e.g., `_old`, `_legacy`)
3. Update this document if structure changes

### Backup Strategy
- Regular backups of `amore_college` database
- Keep archive folder for historical reference
- Document major structural changes

---

## Quick Reference

**For New Installation:**
```bash
# Import the unified complete file
mysql -u root -p < database/main/amore_unified_complete.sql

# Or use the setup script
# Navigate to: public/setup-database.php
```

**For Backup:**
```bash
# Export the unified database
mysqldump -u root -p amore_college > backup_$(date +%Y%m%d).sql
```

**For Reference:**
- Check `archive/` folder for historical files
- See `UNIFICATION_NOTES.md` for detailed table list
- See `README.md` for usage instructions

---

**Last Updated:** 2025-01-XX

