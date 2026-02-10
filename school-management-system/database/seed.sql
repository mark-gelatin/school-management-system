-- Colegio De Amore - Seed data
-- NOTE: default demo admin login:
-- email: admin@colegiodeamore.edu
-- password: password

SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO roles (id, name, description) VALUES
    (1, 'admin', 'System administrator'),
    (2, 'student', 'Student portal user'),
    (3, 'faculty', 'Faculty portal user')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO permissions (id, permission_key, description) VALUES
    (1, 'manage_users', 'Manage student and faculty user accounts'),
    (2, 'manage_programs', 'Create and update programs'),
    (3, 'manage_subjects', 'Create and update subjects'),
    (4, 'manage_sections', 'Create and update sections'),
    (5, 'approve_enrollment', 'Approve or reject enrollments'),
    (6, 'verify_documents', 'Verify student documents'),
    (7, 'manage_lms', 'Manage LMS content globally'),
    (8, 'view_reports', 'View analytics and reports'),
    (9, 'view_audit_logs', 'View system audit logs'),
    (10, 'enroll_subjects', 'Enroll in available subjects'),
    (11, 'upload_documents', 'Upload admission and school documents'),
    (12, 'view_grades', 'View student grade records'),
    (13, 'access_lms', 'Access LMS modules and lessons'),
    (14, 'submit_lms_work', 'Submit lesson requirements'),
    (15, 'manage_assigned_subjects', 'Manage assigned class subjects'),
    (16, 'encode_grades', 'Encode and update student grades'),
    (17, 'manage_modules', 'Create and update LMS modules'),
    (18, 'manage_lessons', 'Create and update LMS lessons'),
    (19, 'grade_submissions', 'Review and grade LMS submissions'),
    (20, 'view_masterlists', 'View section student masterlists'),
    (21, 'manage_attendance', 'Track class attendance')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO role_permissions (role_id, permission_id) VALUES
    (2, 10), (2, 11), (2, 12), (2, 13), (2, 14),
    (3, 13), (3, 15), (3, 16), (3, 17), (3, 18), (3, 19), (3, 20), (3, 21)
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO users (
    id, role, role_id, email, password_hash, first_name, last_name, middle_name, phone, status, is_verified
) VALUES
    (1, 'admin', 1, 'admin@colegiodeamore.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', NULL, '09170000001', 'active', 1),
    (2, 'faculty', 3, 'faculty@colegiodeamore.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria', 'Santos', 'R', '09170000002', 'active', 1),
    (3, 'student', 2, 'student@colegiodeamore.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan', 'Dela Cruz', 'T', '09170000003', 'active', 1)
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    role_id = VALUES(role_id),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    status = VALUES(status),
    is_verified = VALUES(is_verified);

INSERT INTO programs (id, code, name, description, years_to_complete, status, created_by) VALUES
    (1, 'BSIT', 'Bachelor of Science in Information Technology', 'Core computing, systems and software development curriculum.', 4, 'active', 1),
    (2, 'BSED', 'Bachelor of Secondary Education', 'Professional teaching degree with discipline specialization.', 4, 'active', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    status = VALUES(status);

INSERT INTO faculty (id, user_id, employee_no, department, hire_date, is_adviser) VALUES
    (1, 2, 'FAC-2026-001', 'College of Computing', '2020-06-01', 1)
ON DUPLICATE KEY UPDATE
    department = VALUES(department),
    is_adviser = VALUES(is_adviser);

INSERT INTO sections (id, program_id, name, school_year, year_level, adviser_faculty_id, status) VALUES
    (1, 1, 'A', '2026-2027', 1, 1, 'active')
ON DUPLICATE KEY UPDATE
    adviser_faculty_id = VALUES(adviser_faculty_id),
    status = VALUES(status);

INSERT INTO students (id, user_id, student_no, program_id, section_id, year_level, admission_date, guardian_name, guardian_phone) VALUES
    (1, 3, '2026-0001', 1, 1, 1, '2026-06-10', 'Pedro Dela Cruz', '09179999999')
ON DUPLICATE KEY UPDATE
    program_id = VALUES(program_id),
    section_id = VALUES(section_id),
    year_level = VALUES(year_level);

INSERT INTO subjects (id, program_id, code, title, units, description, year_level, semester, status, created_by) VALUES
    (1, 1, 'IT101', 'Introduction to Computing', 3.0, 'Foundational computing concepts.', 1, '1st', 'active', 1),
    (2, 1, 'IT102', 'Computer Programming 1', 3.0, 'Procedural programming and problem solving.', 1, '1st', 'active', 1),
    (3, 1, 'IT103', 'Human Computer Interaction', 3.0, 'Designing usable and accessible interfaces.', 1, '2nd', 'active', 1)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    status = VALUES(status);

INSERT INTO section_subjects (section_id, subject_id, faculty_id, schedule_text, room) VALUES
    (1, 1, 1, 'Mon/Wed 08:00-09:30', 'Lab 1'),
    (1, 2, 1, 'Tue/Thu 10:00-11:30', 'Lab 2')
ON DUPLICATE KEY UPDATE
    faculty_id = VALUES(faculty_id),
    schedule_text = VALUES(schedule_text),
    room = VALUES(room);

INSERT INTO enrollments (id, student_id, program_id, section_id, school_year, semester, status, submitted_at) VALUES
    (1, 1, 1, 1, '2026-2027', '1st', 'pending', NOW())
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    section_id = VALUES(section_id);

INSERT INTO enrollment_subjects (enrollment_id, subject_id, status) VALUES
    (1, 1, 'enrolled'),
    (1, 2, 'enrolled')
ON DUPLICATE KEY UPDATE
    status = VALUES(status);

INSERT INTO grades (
    student_id, subject_id, faculty_id, section_id, school_year, semester,
    prelim, midterm, finals, final_grade, remarks, encoded_at
) VALUES
    (1, 1, 1, 1, '2026-2027', '1st', 92.00, 90.00, 91.00, 91.00, 'PASSED', NOW())
ON DUPLICATE KEY UPDATE
    prelim = VALUES(prelim),
    midterm = VALUES(midterm),
    finals = VALUES(finals),
    final_grade = VALUES(final_grade),
    remarks = VALUES(remarks),
    encoded_at = VALUES(encoded_at);

INSERT INTO documents (id, name, description, required_for_enrollment, status) VALUES
    (1, 'Birth Certificate', 'PSA-certified birth certificate', 1, 'active'),
    (2, 'Report Card', 'Latest report card or transcript', 1, 'active'),
    (3, 'Good Moral Certificate', 'Certificate from previous school', 1, 'active')
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    status = VALUES(status);

INSERT INTO student_documents (
    student_id, document_id, file_path, status, uploaded_at
) VALUES
    (1, 1, 'uploads/documents/sample_birth_certificate.pdf', 'pending', NOW())
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    uploaded_at = VALUES(uploaded_at);

INSERT INTO lms_modules (
    id, subject_id, faculty_id, title, description, status, published_at
) VALUES
    (1, 1, 1, 'Module 1: Fundamentals', 'Core introduction module for the subject.', 'published', NOW())
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    status = VALUES(status),
    published_at = VALUES(published_at);

INSERT INTO lms_lessons (
    id, module_id, title, content_text, resource_link, due_date, order_no
) VALUES
    (1, 1, 'Lesson 1: Computer Basics', 'Read the lesson and answer the reflection prompt.', 'https://example.com/lesson-resource', DATE_ADD(NOW(), INTERVAL 7 DAY), 1)
ON DUPLICATE KEY UPDATE
    content_text = VALUES(content_text),
    due_date = VALUES(due_date);

INSERT INTO lms_submissions (
    lesson_id, student_id, submission_text, status, submitted_at, score, feedback, graded_by, graded_at
) VALUES
    (1, 1, 'My reflection on the basics of computing.', 'graded', NOW(), 95.00, 'Excellent analysis and structure.', 1, NOW())
ON DUPLICATE KEY UPDATE
    submission_text = VALUES(submission_text),
    status = VALUES(status),
    score = VALUES(score),
    feedback = VALUES(feedback),
    graded_by = VALUES(graded_by),
    graded_at = VALUES(graded_at);

INSERT INTO announcements (title, body, audience, posted_by, posted_at) VALUES
    ('Welcome to Colegio De Amore LMS', 'Enrollment and LMS features are now available online.', 'all', 1, NOW())
ON DUPLICATE KEY UPDATE
    body = VALUES(body);

INSERT INTO notifications (user_id, title, message, link_url, type, is_read) VALUES
    (3, 'Enrollment Submitted', 'Your enrollment request was received and is pending approval.', '/school-management-system/student/enrollment.php', 'enrollment', 0),
    (3, 'LMS Score Posted', 'A graded LMS submission is now available.', '/school-management-system/student/lms/modules.php', 'lms', 0)
ON DUPLICATE KEY UPDATE
    message = VALUES(message),
    is_read = VALUES(is_read);

INSERT INTO email_verification (user_id, otp_code, expires_at, status) VALUES
    (3, '123456', DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'pending')
ON DUPLICATE KEY UPDATE
    otp_code = VALUES(otp_code),
    expires_at = VALUES(expires_at),
    status = VALUES(status);

INSERT INTO audit_logs (user_id, action, module, description, ip_address, user_agent) VALUES
    (1, 'SEED_DATA', 'database', 'Initial seed data inserted', '127.0.0.1', 'Seed Script');
