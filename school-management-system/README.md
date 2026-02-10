# Colegio De Amore - School Management System & LMS

Production-ready modular PHP + MySQL web application for school operations and learning management.

## Tech Stack

- PHP (procedural, modular includes)
- MySQL (PDO + prepared statements)
- HTML5 / CSS3 / Vanilla JavaScript
- AJAX via `fetch()`
- PHPMailer (OTP email verification)

## Features

### Admin
- User management (students/faculty/admin)
- Program, subject, and section management
- Enrollment approval (AJAX)
- Document verification (AJAX)
- Reports and audit logs

### Student
- Profile management
- Enrollment submission (AJAX)
- Document uploads
- Grade and GPA view
- Notification center
- LMS module access and assignment submission (AJAX)

### Faculty
- Assigned subjects and section masterlists
- Grade encoding (AJAX, no page reload)
- Attendance recording (AJAX)
- LMS module and lesson authoring
- LMS submission grading (AJAX, no page reload)

## Folder Structure

```
school-management-system/
├── config/
├── database/
├── includes/
├── auth/
├── admin/
├── student/
├── faculty/
├── assets/
├── uploads/
├── index.php
├── .htaccess
└── README.md
```

## Setup Instructions

1. **Create database** (e.g. `school_management_system`) in MySQL.
2. **Import schema and seed**:
   ```bash
   mysql -u root -p school_management_system < database/schema.sql
   mysql -u root -p school_management_system < database/seed.sql
   ```
3. **Configure DB connection**
   - Edit `config/database.php` defaults, or set environment variables:
     - `SMS_DB_HOST`
     - `SMS_DB_PORT`
     - `SMS_DB_NAME`
     - `SMS_DB_USER`
     - `SMS_DB_PASS`
4. **Install PHPMailer**:
   ```bash
   composer require phpmailer/phpmailer
   ```
5. Optional SMTP environment variables for OTP emails:
   - `SMS_SMTP_HOST`
   - `SMS_SMTP_PORT`
   - `SMS_SMTP_USER`
   - `SMS_SMTP_PASS`
   - `SMS_SMTP_SECURE` (`tls` or `ssl`)
   - `SMS_MAIL_FROM_ADDRESS`
   - `SMS_MAIL_FROM_NAME`

## Default Seed Accounts

Password for all demo accounts in seed file: `password`

- Admin: `admin@colegiodeamore.edu`
- Faculty: `faculty@colegiodeamore.edu`
- Student: `student@colegiodeamore.edu`

## Security Notes

- Passwords are hashed using PHP `password_hash()`.
- All DB operations use prepared statements.
- Role and permission checks are enforced on server-side routes/APIs.
- Audit trail logging is enabled for key actions.

## Deployment Notes

- Set document root to `school-management-system/`.
- Ensure `uploads/documents/` is writable by PHP.
- Apache mod_rewrite and mod_headers should be enabled for `.htaccess`.
