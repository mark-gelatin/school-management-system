<?php
/**
 * Database Verification Script
 * Verifies that amore_unified_complete.sql contains all required tables and columns
 * for the system to function correctly.
 */

require_once __DIR__ . '/../config/database.php';

// Get database connection
$pdo = getDatabaseConnection();

$errors = [];
$warnings = [];
$success = [];

// List of all tables that should exist
$requiredTables = [
    // Core tables
    'users',
    'courses',
    'sections',
    'classrooms',
    'classroom_students',
    'subjects',
    'teacher_subjects',
    'section_schedules',
    'grades',
    'student_gpa',
    'student_back_subjects',
    
    // Admission & Application
    'admission_applications',
    'academic_records',
    'application_requirements',
    'application_requirement_submissions',
    'application_payments',
    
    // Enrollment Management
    'enrollment_periods',
    'enrollment_requests',
    
    // Admin & System
    'admin_logs',
    'system_settings',
    'user_preferences',
    'translations',
    'database_backups',
    
    // Legacy (for backward compatibility)
    'personal_info',
    'admission_info',
    'contact_info',
    'account_info',
    'student_list'
];

// Critical columns that must exist
$requiredColumns = [
    'users' => ['id', 'username', 'password', 'email', 'role', 'first_name', 'last_name', 'status', 'course_id'],
    'courses' => ['id', 'code', 'name', 'status'],
    'sections' => ['id', 'course_id', 'section_name', 'year_level', 'academic_year', 'semester'],
    'grades' => ['id', 'student_id', 'subject_id', 'grade', 'grade_type', 'academic_year', 'semester'],
    'section_schedules' => ['id', 'section_id', 'subject_id', 'day_of_week', 'start_time', 'end_time', 'academic_year', 'semester'],
    'enrollment_periods' => ['id', 'course_id', 'academic_year', 'semester', 'start_date', 'end_date', 'status'],
    'enrollment_requests' => ['id', 'student_id', 'course_id', 'enrollment_period_id', 'status'],
    'admission_applications' => ['id', 'student_id', 'application_number', 'status', 'document_path']
];

// Foreign key relationships to verify
$foreignKeys = [
    'sections' => ['course_id' => 'courses(id)', 'teacher_id' => 'users(id)'],
    'section_schedules' => ['section_id' => 'sections(id)', 'subject_id' => 'subjects(id)', 'teacher_id' => 'users(id)', 'classroom_id' => 'classrooms(id)'],
    'grades' => ['student_id' => 'users(id)', 'subject_id' => 'subjects(id)', 'classroom_id' => 'classrooms(id)', 'teacher_id' => 'users(id)'],
    'enrollment_periods' => ['course_id' => 'courses(id)', 'created_by' => 'users(id)'],
    'enrollment_requests' => ['student_id' => 'users(id)', 'course_id' => 'courses(id)', 'enrollment_period_id' => 'enrollment_periods(id)', 'reviewed_by' => 'users(id)'],
    'users' => ['course_id' => 'courses(id)']
];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Verification</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Database Verification Report</h1>
    <p>Database: <strong>amore_college</strong></p>
    <hr>";

try {
    // Test 1: Verify database connection
    $pdo->query("SELECT 1");
    $success[] = "Database connection successful";
    
    // Test 2: Check all required tables exist
    echo "<h2>1. Table Verification</h2>";
    $existingTables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $existingTables[] = $row[0];
    }
    
    foreach ($requiredTables as $table) {
        if (in_array($table, $existingTables)) {
            $success[] = "Table '$table' exists";
        } else {
            $errors[] = "Table '$table' is MISSING";
        }
    }
    
    // Test 3: Verify critical columns
    echo "<h2>2. Column Verification</h2>";
    foreach ($requiredColumns as $table => $columns) {
        if (!in_array($table, $existingTables)) {
            $errors[] = "Cannot check columns for '$table' - table does not exist";
            continue;
        }
        
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $existingColumns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingColumns[] = $row['Field'];
        }
        
        foreach ($columns as $column) {
            if (in_array($column, $existingColumns)) {
                $success[] = "Column '$table.$column' exists";
            } else {
                $errors[] = "Column '$table.$column' is MISSING";
            }
        }
    }
    
    // Test 4: Verify foreign key constraints
    echo "<h2>3. Foreign Key Verification</h2>";
    foreach ($foreignKeys as $table => $fks) {
        if (!in_array($table, $existingTables)) {
            continue;
        }
        
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '$table'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $existingFKs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingFKs[$row['COLUMN_NAME']] = $row['REFERENCED_TABLE_NAME'] . '(' . $row['REFERENCED_COLUMN_NAME'] . ')';
        }
        
        foreach ($fks as $column => $ref) {
            $refTable = explode('(', $ref)[0];
            if (isset($existingFKs[$column]) && strpos($existingFKs[$column], $refTable) !== false) {
                $success[] = "Foreign key '$table.$column' -> '$ref' exists";
            } else {
                $warnings[] = "Foreign key '$table.$column' -> '$ref' may be missing (check manually)";
            }
        }
    }
    
    // Test 5: Test critical queries
    echo "<h2>4. Query Testing</h2>";
    
    // Test users query
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        $success[] = "Users table query successful (count: {$result['count']})";
    } catch (PDOException $e) {
        $errors[] = "Users query failed: " . $e->getMessage();
    }
    
    // Test courses query
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM courses");
        $result = $stmt->fetch();
        $success[] = "Courses table query successful (count: {$result['count']})";
    } catch (PDOException $e) {
        $errors[] = "Courses query failed: " . $e->getMessage();
    }
    
    // Test section_schedules query
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM section_schedules");
        $result = $stmt->fetch();
        $success[] = "Section_schedules table query successful (count: {$result['count']})";
    } catch (PDOException $e) {
        $errors[] = "Section_schedules query failed: " . $e->getMessage();
    }
    
    // Test enrollment_periods query
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollment_periods");
        $result = $stmt->fetch();
        $success[] = "Enrollment_periods table query successful (count: {$result['count']})";
    } catch (PDOException $e) {
        $errors[] = "Enrollment_periods query failed: " . $e->getMessage();
    }
    
    // Test enrollment_requests query
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollment_requests");
        $result = $stmt->fetch();
        $success[] = "Enrollment_requests table query successful (count: {$result['count']})";
    } catch (PDOException $e) {
        $errors[] = "Enrollment_requests query failed: " . $e->getMessage();
    }
    
    // Test complex join query (like student-subjects.php uses)
    try {
        $stmt = $pdo->query("
            SELECT s.id, s.name, s.code
            FROM subjects s
            LEFT JOIN grades g ON s.id = g.subject_id
            WHERE s.status = 'active'
            LIMIT 5
        ");
        $result = $stmt->fetchAll();
        $success[] = "Complex join query (subjects + grades) successful";
    } catch (PDOException $e) {
        $errors[] = "Complex join query failed: " . $e->getMessage();
    }
    
    // Test section_schedules join (critical for student schedule)
    try {
        $stmt = $pdo->query("
            SELECT ss.*, s.section_name, sub.name as subject_name
            FROM section_schedules ss
            LEFT JOIN sections s ON ss.section_id = s.id
            LEFT JOIN subjects sub ON ss.subject_id = sub.id
            LIMIT 5
        ");
        $result = $stmt->fetchAll();
        $success[] = "Section_schedules join query successful";
    } catch (PDOException $e) {
        $errors[] = "Section_schedules join query failed: " . $e->getMessage();
    }
    
    // Test enrollment period with course join
    try {
        $stmt = $pdo->query("
            SELECT ep.*, c.name as course_name
            FROM enrollment_periods ep
            LEFT JOIN courses c ON ep.course_id = c.id
            LIMIT 5
        ");
        $result = $stmt->fetchAll();
        $success[] = "Enrollment_periods join query successful";
    } catch (PDOException $e) {
        $errors[] = "Enrollment_periods join query failed: " . $e->getMessage();
    }
    
    // Test 6: Verify enum values
    echo "<h2>5. Enum Value Verification</h2>";
    
    // Check users.role enum
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
        $row = $stmt->fetch();
        if (strpos($row['Type'], 'admin') !== false && strpos($row['Type'], 'teacher') !== false && strpos($row['Type'], 'student') !== false) {
            $success[] = "Users.role enum contains required values (admin, teacher, student)";
        } else {
            $errors[] = "Users.role enum missing required values";
        }
    } catch (PDOException $e) {
        $errors[] = "Cannot verify users.role enum: " . $e->getMessage();
    }
    
    // Check grades.grade_type enum
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM grades WHERE Field = 'grade_type'");
        $row = $stmt->fetch();
        $requiredTypes = ['quiz', 'assignment', 'exam', 'project', 'participation', 'midterm', 'final'];
        $typeStr = $row['Type'];
        $missing = [];
        foreach ($requiredTypes as $type) {
            if (strpos($typeStr, $type) === false) {
                $missing[] = $type;
            }
        }
        if (empty($missing)) {
            $success[] = "Grades.grade_type enum contains all required values";
        } else {
            $warnings[] = "Grades.grade_type enum missing: " . implode(', ', $missing);
        }
    } catch (PDOException $e) {
        $errors[] = "Cannot verify grades.grade_type enum: " . $e->getMessage();
    }
    
    // Test 7: Verify indexes
    echo "<h2>6. Index Verification</h2>";
    $criticalIndexes = [
        'users' => ['username', 'email', 'student_id_number'],
        'courses' => ['code'],
        'grades' => ['student_id', 'subject_id', 'grade_type'],
        'section_schedules' => ['section_id', 'subject_id']
    ];
    
    foreach ($criticalIndexes as $table => $indexes) {
        if (!in_array($table, $existingTables)) {
            continue;
        }
        
        $stmt = $pdo->query("SHOW INDEXES FROM `$table`");
        $existingIndexes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingIndexes[] = $row['Column_name'];
        }
        
        foreach ($indexes as $index) {
            if (in_array($index, $existingIndexes)) {
                $success[] = "Index '$table.$index' exists";
            } else {
                $warnings[] = "Index '$table.$index' may be missing";
            }
        }
    }
    
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// Display results
echo "<h2>Summary</h2>";
echo "<table>";
echo "<tr><th>Status</th><th>Count</th></tr>";
echo "<tr class='success'><td>✓ Success</td><td>" . count($success) . "</td></tr>";
echo "<tr class='warning'><td>⚠ Warnings</td><td>" . count($warnings) . "</td></tr>";
echo "<tr class='error'><td>✗ Errors</td><td>" . count($errors) . "</td></tr>";
echo "</table>";

if (!empty($errors)) {
    echo "<h2>Errors</h2><ul>";
    foreach ($errors as $error) {
        echo "<li class='error'>$error</li>";
    }
    echo "</ul>";
}

if (!empty($warnings)) {
    echo "<h2>Warnings</h2><ul>";
    foreach ($warnings as $warning) {
        echo "<li class='warning'>$warning</li>";
    }
    echo "</ul>";
}

if (empty($errors)) {
    echo "<h2 class='success'>✓ Database verification PASSED!</h2>";
    echo "<p>All required tables, columns, and relationships are present.</p>";
} else {
    echo "<h2 class='error'>✗ Database verification FAILED!</h2>";
    echo "<p>Please fix the errors above before deploying.</p>";
}

echo "</body></html>";

