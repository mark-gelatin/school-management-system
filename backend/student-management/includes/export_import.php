<?php
/**
 * Export and Import Functions for Colegio de Amore
 */

if (!function_exists('exportToCSV')) {
    /**
     * Export data to CSV
     */
    function exportToCSV($data, $filename, $headers = null) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write headers
        if ($headers) {
            fputcsv($output, $headers);
        } elseif (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    }
}

if (!function_exists('exportToExcel')) {
    /**
     * Export data to Excel (CSV with Excel formatting)
     */
    function exportToExcel($data, $filename, $headers = null) {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
        
        // Write headers
        if ($headers) {
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
        } elseif (!empty($data)) {
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
        }
        
        // Write data
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</table></body></html>';
        exit();
    }
}

if (!function_exists('exportStudents')) {
    /**
     * Export students to CSV/Excel
     */
    function exportStudents($pdo, $format = 'csv') {
        $studentEligibilityClause = function_exists('getEnrolledStudentEligibilityCondition')
            ? getEnrolledStudentEligibilityCondition('u')
            : '1=1';
        $stmt = $pdo->query("
            SELECT 
                u.id,
                u.student_id_number,
                u.first_name,
                u.last_name,
                u.email,
                u.username,
                u.program,
                u.year_level,
                u.section,
                u.status,
                u.created_at
            FROM users u
            WHERE u.role = 'student'
              AND {$studentEligibilityClause}
            ORDER BY u.last_name, u.first_name
        ");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $headers = ['ID', 'Student Number', 'First Name', 'Last Name', 'Email', 'Username', 'Program', 'Year Level', 'Section', 'Status', 'Created At'];
        
        if ($format === 'excel') {
            exportToExcel($students, 'students_export_' . date('Y-m-d'), $headers);
        } else {
            exportToCSV($students, 'students_export_' . date('Y-m-d'), $headers);
        }
    }
}

if (!function_exists('exportGrades')) {
    /**
     * Export grades to CSV/Excel
     */
    function exportGrades($pdo, $format = 'csv', $studentId = null, $subjectId = null) {
        $query = "
            SELECT 
                g.id,
                u.student_id_number,
                u.first_name as student_first_name,
                u.last_name as student_last_name,
                s.code as subject_code,
                s.name as subject_name,
                g.grade,
                g.grade_type,
                g.max_points,
                g.remarks,
                t.first_name as teacher_first_name,
                t.last_name as teacher_last_name,
                c.name as classroom_name,
                g.graded_at
            FROM grades g
            JOIN users u ON g.student_id = u.id
            JOIN subjects s ON g.subject_id = s.id
            JOIN users t ON g.teacher_id = t.id
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            WHERE 1=1
        ";
        
        $params = [];
        if ($studentId) {
            $query .= " AND g.student_id = ?";
            $params[] = $studentId;
        }
        if ($subjectId) {
            $query .= " AND g.subject_id = ?";
            $params[] = $subjectId;
        }
        
        $query .= " ORDER BY g.graded_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $headers = ['ID', 'Student Number', 'Student First Name', 'Student Last Name', 'Subject Code', 'Subject Name', 'Grade', 'Grade Type', 'Max Points', 'Remarks', 'Teacher First Name', 'Teacher Last Name', 'Classroom', 'Graded At'];
        
        if ($format === 'excel') {
            exportToExcel($grades, 'grades_export_' . date('Y-m-d'), $headers);
        } else {
            exportToCSV($grades, 'grades_export_' . date('Y-m-d'), $headers);
        }
    }
}

if (!function_exists('importFromCSV')) {
    /**
     * Import data from CSV file
     */
    function importFromCSV($filePath, $callback) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'Cannot open file'];
        }
        
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
            rewind($handle);
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['success' => false, 'message' => 'Invalid CSV format'];
        }
        
        $imported = 0;
        $errors = [];
        $line = 1;
        
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) !== count($headers)) {
                $errors[] = "Line $line: Column count mismatch";
                continue;
            }
            
            $data = array_combine($headers, $row);
            $result = $callback($data, $line);
            
            if ($result['success']) {
                $imported++;
            } else {
                $errors[] = "Line $line: " . $result['message'];
            }
        }
        
        fclose($handle);
        
        return [
            'success' => true,
            'imported' => $imported,
            'errors' => $errors,
            'message' => "Imported $imported records" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
        ];
    }
}

if (!function_exists('importStudents')) {
    /**
     * Import students from CSV
     */
    function importStudents($pdo, $filePath) {
        return importFromCSV($filePath, function($data, $line) use ($pdo) {
            try {
                // Validate required fields
                if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
                    return ['success' => false, 'message' => 'Missing required fields'];
                }
                
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$data['email']]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'message' => 'Email already exists'];
                }
                
                // Generate username if not provided
                $username = $data['username'] ?? strtolower(substr($data['first_name'], 0, 1) . $data['last_name']);
                $username = preg_replace('/[^a-z0-9]/', '', $username);
                
                // Check username uniqueness
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $counter = 1;
                $originalUsername = $username;
                while ($stmt->fetch()) {
                    $username = $originalUsername . $counter;
                    $stmt->execute([$username]);
                    $counter++;
                }
                
                // Generate password if not provided
                $password = $data['password'] ?? bin2hex(random_bytes(8));
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert student
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, email, role, first_name, last_name, program, year_level, section, status)
                    VALUES (?, ?, ?, 'student', ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $username,
                    $hashedPassword,
                    $data['email'],
                    $data['first_name'],
                    $data['last_name'],
                    $data['program'] ?? null,
                    $data['year_level'] ?? null,
                    $data['section'] ?? null,
                    $data['status'] ?? 'active'
                ]);
                
                return ['success' => true, 'message' => 'Student imported'];
            } catch (PDOException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        });
    }
}

