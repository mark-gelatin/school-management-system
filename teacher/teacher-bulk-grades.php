<?php
// Teacher Bulk Grades Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // teacher/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/teacher
    $projectRoot = dirname($currentDir); // /www/wwwroot/72.62.65.224
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    
    // Use realpath to resolve any symbolic links and get absolute path
    $realPathsFile = realpath($pathsFile);
    if ($realPathsFile && file_exists($realPathsFile)) {
        require_once $realPathsFile;
    } else {
        // Fallback to VPS path (absolute path)
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('config/database.php');
require_once getAbsolutePath('backend/includes/grade_converter.php');
require_once getAbsolutePath('backend/includes/data_synchronization.php');
require_once getAbsolutePath('backend/student-management/includes/functions.php');

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    redirectTo('auth/staff-login.php');
}

$teacherId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle Excel file upload for bulk grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_excel_grades') {
    $classroomId = $_POST['classroom_id'] ?? null;
    $subjectId = $_POST['subject_id'] ?? null;
    $gradeType = $_POST['grade_type'] ?? null;
    $maxPoints = floatval($_POST['max_points'] ?? 100);
    
    if (!$classroomId || !$subjectId || !$gradeType) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please select an Excel file to upload.';
        $message_type = 'error';
    } else {
        $file = $_FILES['excel_file'];
        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            $message = 'Invalid file type. Please upload Excel (.xlsx, .xls) or CSV (.csv) file.';
            $message_type = 'error';
        } else {
            try {
                $uploadDir = __DIR__ . '/uploads/temp/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Create .htaccess to protect temp files
                $htaccessFile = $uploadDir . '.htaccess';
                if (!file_exists($htaccessFile)) {
                    file_put_contents($htaccessFile, "deny from all\n");
                }
                
                $fileName = 'grades_' . time() . '_' . basename($file['name']);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    // Parse Excel/CSV file
                    $gradesData = parseGradesFile($filePath, $fileExtension);
                    
                    if (empty($gradesData)) {
                        $message = 'No valid grade data found in the file. Please ensure:<br>';
                        $message .= '1. The file contains a header row with "Student ID" (or "Student Name") and "Raw Score" (or "Grade")<br>';
                        $message .= '2. You have filled in at least one grade value<br>';
                        $message .= '3. Instruction rows and example rows are not being processed<br>';
                        $message .= '<br><strong>Tip:</strong> Use the "Download Template" button to get a properly formatted file.';
                        $message_type = 'error';
                    } else {
                        // Get students in the classroom
                        $studentsStmt = $pdo->prepare("
                            SELECT u.id, u.student_id_number, u.first_name, u.last_name
                            FROM users u
                            JOIN classroom_students cs ON u.id = cs.student_id
                            WHERE cs.classroom_id = ? AND u.role = 'student'
                        ");
                        $studentsStmt->execute([$classroomId]);
                        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Create lookup maps
                        $studentByIdNumber = [];
                        $studentByName = [];
                        foreach ($students as $student) {
                            if (!empty($student['student_id_number'])) {
                                $studentByIdNumber[strtolower(trim($student['student_id_number']))] = $student;
                            }
                            $fullName = strtolower(trim($student['first_name'] . ' ' . $student['last_name']));
                            $studentByName[$fullName] = $student;
                        }
                        
                        // Process grades
                        $pdo->beginTransaction();
                        $successCount = 0;
                        $errorCount = 0;
                        $errors = [];
                        
                        foreach ($gradesData as $rowIndex => $row) {
                            $studentId = null;
                            $gradeValue = null;
                            
                            // Try to find student by ID number first, then by name
                            // Check multiple possible column names
                            $studentIdValue = null;
                            $studentNameValue = null;
                            
                            $idColumnNames = ['student id', 'student_id', 'id', 'student_id_number'];
                            $nameColumnNames = ['student name', 'student_name', 'name', 'student'];
                            
                            foreach ($idColumnNames as $colName) {
                                if (isset($row[$colName]) && !empty(trim($row[$colName]))) {
                                    $studentIdValue = strtolower(trim($row[$colName]));
                                    break;
                                }
                            }
                            
                            foreach ($nameColumnNames as $colName) {
                                if (isset($row[$colName]) && !empty(trim($row[$colName]))) {
                                    $studentNameValue = strtolower(trim($row[$colName]));
                                    break;
                                }
                            }
                            
                            if ($studentIdValue && isset($studentByIdNumber[$studentIdValue])) {
                                $studentId = $studentByIdNumber[$studentIdValue]['id'];
                            } elseif ($studentNameValue && isset($studentByName[$studentNameValue])) {
                                $studentId = $studentByName[$studentNameValue]['id'];
                            }
                            
                            if (!$studentId) {
                                $studentIdentifier = 'Unknown';
                                if ($studentIdValue) {
                                    $studentIdentifier = $studentIdValue;
                                } elseif ($studentNameValue) {
                                    $studentIdentifier = $studentNameValue;
                                } else {
                                    // Try to get any identifier from the row
                                    foreach ($row as $key => $value) {
                                        if (!empty(trim($value)) && stripos($key, 'student') !== false) {
                                            $studentIdentifier = trim($value);
                                            break;
                                        }
                                    }
                                }
                                $errors[] = "Row " . ($rowIndex + 2) . ": Student not found (" . htmlspecialchars($studentIdentifier) . ")";
                                $errorCount++;
                                continue;
                            }
                            
                            // Get grade value - check multiple possible column names
                            $gradeValue = null;
                            $gradeColumnNames = ['raw score', 'raw_score', 'grade', 'score', 'marks', 'points'];
                            
                            // First try exact matches
                            foreach ($gradeColumnNames as $colName) {
                                if (isset($row[$colName]) && !empty(trim($row[$colName]))) {
                                    $gradeValue = floatval($row[$colName]);
                                    break;
                                }
                            }
                            
                            // If not found, try partial matches (in case column name has extra text like "Raw Score (0-100)")
                            if ($gradeValue === null) {
                                foreach ($row as $key => $value) {
                                    $normalizedKey = strtolower(trim($key));
                                    // Remove parentheses and numbers for matching
                                    $normalizedKey = preg_replace('/\s*\([^)]*\)\s*/', '', $normalizedKey);
                                    $normalizedKey = trim($normalizedKey);
                                    
                                    foreach ($gradeColumnNames as $colName) {
                                        if ($normalizedKey === $colName || 
                                            strpos($normalizedKey, $colName) !== false ||
                                            strpos($colName, $normalizedKey) !== false) {
                                            if (!empty(trim($value))) {
                                                $gradeValue = floatval($value);
                                                break 2; // Break both loops
                                            }
                                        }
                                    }
                                }
                            }
                            
                            if ($gradeValue === null) {
                                // Show which columns were found for debugging
                                $foundColumns = implode(', ', array_keys($row));
                                $errors[] = "Row " . ($rowIndex + 2) . ": No grade value found. Available columns: " . htmlspecialchars($foundColumns);
                                $errorCount++;
                                continue;
                            }
                            
                            if ($gradeValue < 0 || $gradeValue > $maxPoints) {
                                $errors[] = "Row " . ($rowIndex + 2) . ": Invalid grade value ($gradeValue)";
                                $errorCount++;
                                continue;
                            }
                            
                            // Check if grade already exists
                            $checkStmt = $pdo->prepare("
                                SELECT id FROM grades 
                                WHERE student_id = ? AND subject_id = ? AND classroom_id = ? AND grade_type = ?
                                LIMIT 1
                            ");
                            $checkStmt->execute([$studentId, $subjectId, $classroomId, $gradeType]);
                            
                            if ($checkStmt->rowCount() > 0) {
                                // Update existing grade
                                $updateStmt = $pdo->prepare("
                                    UPDATE grades 
                                    SET grade = ?, max_points = ?, graded_at = NOW()
                                    WHERE student_id = ? AND subject_id = ? AND classroom_id = ? AND grade_type = ?
                                ");
                                $updateStmt->execute([$gradeValue, $maxPoints, $studentId, $subjectId, $classroomId, $gradeType]);
                            } else {
                                // Insert new grade
                                $insertStmt = $pdo->prepare("
                                    INSERT INTO grades (student_id, subject_id, classroom_id, teacher_id, grade, grade_type, max_points, graded_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $insertStmt->execute([$studentId, $subjectId, $classroomId, $teacherId, $gradeValue, $gradeType, $maxPoints]);
                            }
                            $successCount++;
                        }
                        
                        $pdo->commit();
                        
                        // Log teacher action - backend-first, cannot be bypassed
                        if ($successCount > 0) {
                            try {
                                // Get subject and course info for logging
                                $subjectInfo = $pdo->prepare("SELECT s.name as subject_name, s.code as subject_code, c.id as course_id, c.name as course_name 
                                                              FROM subjects s 
                                                              LEFT JOIN section_schedules ss ON s.id = ss.subject_id 
                                                              LEFT JOIN sections sec ON ss.section_id = sec.id 
                                                              LEFT JOIN courses c ON sec.course_id = c.id 
                                                              WHERE s.id = ? LIMIT 1");
                                $subjectInfo->execute([$subjectId]);
                                $subjInfo = $subjectInfo->fetch(PDO::FETCH_ASSOC);
                                
                                if ($subjInfo) {
                                    $courseId = $subjInfo['course_id'] ?? null;
                                    $subjectName = $subjInfo['subject_name'] ?? 'Unknown Subject';
                                    $subjectCode = $subjInfo['subject_code'] ?? '';
                                    $description = "Uploaded final grades via Excel: {$successCount} grade(s) for subject '{$subjectName}'" . 
                                                  ($subjectCode ? " ({$subjectCode})" : "") . 
                                                  ($gradeType === 'final' ? " - Grade type: {$gradeType}" : "");
                                    
                                    logTeacherAction($pdo, $teacherId, 'upload_final_grades', 'grade', null, $description, $courseId, $subjectId);
                                } else {
                                    // Fallback if subject not found
                                    logTeacherAction($pdo, $teacherId, 'upload_final_grades', 'grade', null, "Uploaded final grades via Excel: {$successCount} grade(s) for subject ID: {$subjectId}", null, $subjectId);
                                }
                            } catch (Exception $e) {
                                error_log("Failed to log teacher action for grade upload: " . $e->getMessage());
                            }
                        }
                        
                        // Clean up uploaded file
                        unlink($filePath);
                        
                        if ($successCount > 0) {
                            $message = "Successfully imported {$successCount} grade(s)!";
                            if ($errorCount > 0) {
                                $errorPreview = implode('; ', array_slice($errors, 0, 5));
                                if (count($errors) > 5) {
                                    $errorPreview .= ' (and ' . (count($errors) - 5) . ' more)';
                                }
                                $message .= "<br><small style='margin-top: 10px; display: block;'>Errors: {$errorPreview}</small>";
                            }
                            $message_type = 'success';
                        } else {
                            $errorDetails = implode('<br>', array_slice($errors, 0, 10));
                            if (count($errors) > 10) {
                                $errorDetails .= '<br><em>... and ' . (count($errors) - 10) . ' more errors</em>';
                            }
                            $message = 'No grades were imported. Please check your file format.<br><br><strong>Errors:</strong><br>' . $errorDetails;
                            $message .= '<br><br><small><strong>Tip:</strong> Make sure you downloaded the template from this page and only filled in the grade column. Do not modify Student ID or Student Name columns.</small>';
                            $message_type = 'error';
                        }
                    }
                } else {
                    $message = 'Failed to upload file.';
                    $message_type = 'error';
                }
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Error processing file: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Function to parse Excel/CSV file
function parseGradesFile($filePath, $extension) {
    $data = [];
    
    if ($extension === 'csv') {
        // Parse CSV
        if (($handle = fopen($filePath, 'r')) !== false) {
            $lineNumber = 0;
            $headerRowFound = false;
            $normalizedHeaders = [];
            
            // Read rows until we find the header row
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                
                // Skip instruction rows - look for "INSTRUCTIONS" or rows that don't look like headers
                $firstCell = strtolower(trim($row[0] ?? ''));
                $rowText = strtolower(implode(' ', $row));
                
                // Skip if it's clearly an instruction row
                if (strpos($firstCell, 'instruction') !== false || 
                    strpos($firstCell, 'colegio') !== false ||
                    strpos($firstCell, 'grades template') !== false ||
                    strpos($rowText, 'enter raw scores') !== false ||
                    strpos($rowText, 'philippine grades') !== false ||
                    strpos($rowText, 'do not modify') !== false ||
                    strpos($rowText, 'save this file') !== false ||
                    (empty($firstCell) && !$headerRowFound)) {
                    continue; // Skip instruction/header rows
                }
                
                // Check if this looks like a header row (contains "student" and "grade" or "score")
                $rowText = strtolower(implode(' ', $row));
                if (strpos($rowText, 'student') !== false && 
                    (strpos($rowText, 'grade') !== false || strpos($rowText, 'score') !== false || strpos($rowText, 'raw') !== false)) {
                    // This is the header row - normalize column names (remove special chars, keep core words)
                    $normalizedHeaders = [];
                    foreach ($row as $header) {
                        $normalized = strtolower(trim($header));
                        // Remove parentheses and numbers for matching, but keep original for display
                        $normalized = preg_replace('/\s*\([^)]*\)\s*/', '', $normalized); // Remove (0-100.00)
                        $normalized = trim($normalized);
                        $normalizedHeaders[] = $normalized;
                    }
                    $headerRowFound = true;
                    continue;
                }
                
                // If we haven't found headers yet, skip this row
                if (!$headerRowFound) {
                    continue;
                }
                
                // Now process data rows
                if (count($row) < count($normalizedHeaders)) {
                    // Pad with empty strings if row is shorter
                    $row = array_pad($row, count($normalizedHeaders), '');
                }
                
                $rowData = [];
                foreach ($normalizedHeaders as $index => $header) {
                    $rowData[$header] = trim($row[$index] ?? '');
                }
                
                // Skip rows that are completely empty or are example rows
                $allEmpty = true;
                $isExample = false;
                foreach ($rowData as $key => $value) {
                    if (!empty($value)) {
                        $allEmpty = false;
                    }
                    // Check if this is an example row (contains "example" or "delete")
                    if (stripos($value, 'example') !== false || stripos($value, 'delete') !== false) {
                        $isExample = true;
                        break;
                    }
                }
                
                if (!$allEmpty && !$isExample) {
                    $data[] = $rowData;
                }
            }
            
            fclose($handle);
        }
    } else {
        // For Excel files, try to use PhpSpreadsheet if available
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                if (empty($rows)) {
                    return [];
                }
                
                // Find the header row (look for row containing "student" and "grade"/"score")
                $headerRowIndex = -1;
                $normalizedHeaders = [];
                
                for ($i = 0; $i < count($rows); $i++) {
                    $rowText = strtolower(implode(' ', array_filter($rows[$i])));
                    if (strpos($rowText, 'student') !== false && 
                        (strpos($rowText, 'grade') !== false || strpos($rowText, 'score') !== false || strpos($rowText, 'raw') !== false)) {
                        $headerRowIndex = $i;
                        // Normalize headers - remove parentheses and special formatting
                        $normalizedHeaders = [];
                        foreach ($rows[$i] as $header) {
                            $normalized = strtolower(trim($header));
                            // Remove parentheses and numbers for matching
                            $normalized = preg_replace('/\s*\([^)]*\)\s*/', '', $normalized);
                            $normalized = trim($normalized);
                            $normalizedHeaders[] = $normalized;
                        }
                        break;
                    }
                }
                
                if ($headerRowIndex === -1) {
                    return []; // No header row found
                }
                
                // Process data rows after header
                for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    
                    // Skip completely empty rows
                    if (empty(array_filter($row, function($cell) { return trim($cell) !== ''; }))) {
                        continue;
                    }
                    
                    // Check if this is an instruction or example row
                    $rowText = strtolower(implode(' ', array_filter($row)));
                    if (strpos($rowText, 'example') !== false || 
                        strpos($rowText, 'delete') !== false ||
                        strpos($rowText, 'instruction') !== false ||
                        strpos($rowText, 'enter raw scores') !== false ||
                        strpos($rowText, 'philippine grades') !== false ||
                        strpos($rowText, 'do not modify') !== false ||
                        strpos($rowText, 'save this file') !== false) {
                        continue;
                    }
                    
                    $rowData = [];
                    foreach ($normalizedHeaders as $index => $header) {
                        $rowData[$header] = trim($row[$index] ?? '');
                    }
                    
                    // Only add if it has at least one non-empty value
                    if (!empty(array_filter($rowData))) {
                        $data[] = $rowData;
                    }
                }
            } catch (Exception $e) {
                // Fall back to CSV parsing if PhpSpreadsheet fails
                return parseGradesFile($filePath, 'csv');
            }
        } else {
            // If PhpSpreadsheet is not available, try to read as CSV
            return parseGradesFile($filePath, 'csv');
        }
    }
    
    return $data;
}

// Handle template download
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $classroomId = $_GET['classroom_id'] ?? null;
    $maxPoints = isset($_GET['max_points']) ? floatval($_GET['max_points']) : 100;
    
    if ($classroomId) {
        try {
            // Get classroom name
            $classroomStmt = $pdo->prepare("SELECT name FROM classrooms WHERE id = ?");
            $classroomStmt->execute([$classroomId]);
            $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
            $classroomName = $classroom['name'] ?? 'Section';
            
            // Sanitize classroom name for filename
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $classroomName);
            $safeName = substr($safeName, 0, 50); // Limit length
            
            // Get students in classroom
            $studentsStmt = $pdo->prepare("
                SELECT u.student_id_number, u.first_name, u.last_name
                FROM users u
                JOIN classroom_students cs ON u.id = cs.student_id
                WHERE cs.classroom_id = ? AND u.role = 'student'
                ORDER BY u.last_name, u.first_name
            ");
            $studentsStmt->execute([$classroomId]);
            $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Try to use PhpSpreadsheet for formatted Excel, fallback to CSV
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                generateFormattedExcelTemplate($classroomName, $students, $maxPoints, $safeName);
            } else {
                generateFormattedCSVTemplate($students, $maxPoints, $safeName);
            }
            exit();
        } catch (Exception $e) {
            $message = 'Error generating template: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Generate formatted Excel template with styling
function generateFormattedExcelTemplate($classroomName, $students, $maxPoints, $safeName) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('Colegio de Amore')
        ->setTitle('Grades Template - ' . $classroomName)
        ->setSubject('Student Grades Template');
    
    $row = 1;
    
    // Title Section
    $sheet->setCellValue('A' . $row, 'COLEGIO DE AMORE');
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A11C27']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension($row)->setRowHeight(30);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'GRADES TEMPLATE - ' . strtoupper($classroomName));
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B31310']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension($row)->setRowHeight(25);
    $row++;
    
    // Instructions Section
    $row++;
    $sheet->setCellValue('A' . $row, 'INSTRUCTIONS');
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '333333']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
    ]);
    $sheet->getRowDimension($row)->setRowHeight(20);
    $row++;
    
    $instructions = [
        '1. Enter RAW SCORES (0 to ' . number_format($maxPoints, 2) . ') in the "Raw Score" column',
        '2. Philippine grades (1.0-5.0) will be calculated automatically',
        '3. Do NOT modify Student ID or Student Name columns',
        '4. Save this file and upload it back to import grades'
    ];
    
    foreach ($instructions as $instruction) {
        $sheet->setCellValue('A' . $row, $instruction);
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font' => ['size' => 10],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['left' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN], 
                         'right' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
                         'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;
    }
    
    // Empty row
    $row++;
    
    // Table Headers
    $headers = ['Student ID', 'Student Name', 'Raw Score (0-' . number_format($maxPoints, 2) . ')'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A11C27']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]]
        ]);
        $col++;
    }
    $sheet->getRowDimension($row)->setRowHeight(25);
    $headerRow = $row;
    $row++;
    
    // Example row
    if (!empty($students)) {
        $firstStudent = $students[0];
        $exampleScore = min(85.5, $maxPoints);
        
        $sheet->setCellValue('A' . $row, $firstStudent['student_id_number'] ?? 'STU20250001');
        $sheet->setCellValue('B' . $row, trim($firstStudent['first_name'] . ' ' . $firstStudent['last_name']));
        $sheet->setCellValue('C' . $row, number_format($exampleScore, 2));
        
        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9E6']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]]
        ]);
        $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->setCellValue('D' . $row, '(Example - Delete this row)');
        $sheet->getStyle('D' . $row)->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '999999']]
        ]);
        $row++;
        
        // Empty separator row
        $row++;
    }
    
    // Student data rows
    $dataStartRow = $row;
    foreach ($students as $index => $student) {
        $sheet->setCellValue('A' . $row, $student['student_id_number'] ?? '');
        $sheet->setCellValue('B' . $row, trim($student['first_name'] . ' ' . $student['last_name']));
        $sheet->setCellValue('C' . $row, ''); // Empty for teacher to fill
        
        // Alternate row colors
        $fillColor = ($index % 2 == 0) ? 'FFFFFF' : 'F8F9FA';
        
        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
            'font' => ['size' => 10],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]]
        ]);
        $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;
    }
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(25);
    
    // Freeze header row
    $sheet->freezePane('A' . ($headerRow + 1));
    
    // Protect Student ID and Name columns (optional - can be enabled)
    // $sheet->getProtection()->setSheet(true);
    // $sheet->getStyle('A' . $dataStartRow . ':B' . ($row - 1))->getProtection()->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED);
    // $sheet->getStyle('C' . $dataStartRow . ':C' . ($row - 1))->getProtection()->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
    
    // Set print area
    $sheet->getPageSetup()->setPrintArea('A1:C' . ($row - 1));
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    
    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '_grades_template_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// Fallback: Generate formatted CSV template (if PhpSpreadsheet not available)
function generateFormattedCSVTemplate($students, $maxPoints, $safeName) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '_grades_template_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write instruction rows
    fputcsv($output, ['INSTRUCTIONS:']);
    fputcsv($output, ['1. Enter RAW SCORES (0 to ' . number_format($maxPoints, 2) . ') in the "Raw Score" column']);
    fputcsv($output, ['2. Philippine grades (1.0-5.0) will be calculated automatically']);
    fputcsv($output, ['3. Do NOT modify Student ID or Student Name columns']);
    fputcsv($output, ['4. Save this file and upload it back to import grades']);
    fputcsv($output, []); // Empty row for spacing
    
    // Write column headers
    fputcsv($output, ['Student ID', 'Student Name', 'Raw Score (0-' . number_format($maxPoints, 2) . ')']);
    
    // Write example row
    if (!empty($students)) {
        $firstStudent = $students[0];
        $exampleScore = min(85.5, $maxPoints);
        fputcsv($output, [
            $firstStudent['student_id_number'] ?? 'STU20250001',
            trim($firstStudent['first_name'] . ' ' . $firstStudent['last_name']),
            number_format($exampleScore, 2) // Example grade
        ]);
        fputcsv($output, []); // Empty row separator
    }
    
    // Write student rows with empty grade column
    foreach ($students as $student) {
        fputcsv($output, [
            $student['student_id_number'] ?? '',
            trim($student['first_name'] . ' ' . $student['last_name']),
            '' // Empty grade for teacher to fill
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle bulk grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_add_grades') {
    $classroomId = $_POST['classroom_id'] ?? null;
    $subjectId = $_POST['subject_id'] ?? null;
    $gradeType = $_POST['grade_type'] ?? null;
    $maxPoints = $_POST['max_points'] ?? 100;
    $grades = $_POST['grades'] ?? []; // Array of [student_id => grade_value]
    
    if (!$classroomId || !$subjectId || !$gradeType) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($grades as $studentId => $gradeValue) {
                if (empty($gradeValue) || $gradeValue === '') {
                    continue; // Skip empty grades
                }
                
                $gradeValue = floatval($gradeValue);
                if ($gradeValue < 0 || $gradeValue > $maxPoints) {
                    $errorCount++;
                    continue;
                }
                
                // Check if grade already exists for this student, subject, and type
                $checkStmt = $pdo->prepare("
                    SELECT id FROM grades 
                    WHERE student_id = ? AND subject_id = ? AND classroom_id = ? AND grade_type = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$studentId, $subjectId, $classroomId, $gradeType]);
                $existingGrade = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                // Use synchronization module for consistent data flow
                $gradeData = [
                    'student_id' => $studentId,
                    'subject_id' => $subjectId,
                    'classroom_id' => $classroomId,
                    'grade' => $gradeValue,
                    'grade_type' => $gradeType,
                    'max_points' => $maxPoints
                ];
                
                if ($existingGrade) {
                    // Update existing grade using synchronization
                    $gradeData['grade_id'] = $existingGrade['id'];
                    $result = synchronizeGradeOperation($pdo, 'update', $gradeData, $teacherId);
                } else {
                    // Create new grade using synchronization
                    $result = synchronizeGradeOperation($pdo, 'create', $gradeData, $teacherId);
                }
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                    error_log("Grade operation failed for student $studentId: " . $result['message']);
                }
            }
            
            $pdo->commit();
            
            // Log teacher action - backend-first, cannot be bypassed
            if ($successCount > 0) {
                try {
                    // Get subject and course info for logging
                    $subjectInfo = $pdo->prepare("SELECT s.name as subject_name, s.code as subject_code, c.id as course_id, c.name as course_name 
                                                  FROM subjects s 
                                                  LEFT JOIN section_schedules ss ON s.id = ss.subject_id 
                                                  LEFT JOIN sections sec ON ss.section_id = sec.id 
                                                  LEFT JOIN courses c ON sec.course_id = c.id 
                                                  WHERE s.id = ? LIMIT 1");
                    $subjectInfo->execute([$subjectId]);
                    $subjInfo = $subjectInfo->fetch(PDO::FETCH_ASSOC);
                    
                    if ($subjInfo) {
                        $courseId = $subjInfo['course_id'] ?? null;
                        $subjectName = $subjInfo['subject_name'] ?? 'Unknown Subject';
                        $subjectCode = $subjInfo['subject_code'] ?? '';
                        $description = "Entered grades manually: {$successCount} grade(s) for subject '{$subjectName}'" . 
                                      ($subjectCode ? " ({$subjectCode})" : "") . 
                                      ($gradeType === 'final' ? " - Grade type: {$gradeType}" : "");
                        
                        logTeacherAction($pdo, $teacherId, 'enter_grades', 'grade', null, $description, $courseId, $subjectId);
                    } else {
                        // Fallback if subject not found
                        logTeacherAction($pdo, $teacherId, 'enter_grades', 'grade', null, "Entered grades manually: {$successCount} grade(s) for subject ID: {$subjectId}", null, $subjectId);
                    }
                } catch (Exception $e) {
                    error_log("Failed to log teacher action for manual grade entry: " . $e->getMessage());
                }
            }
            
            if ($successCount > 0) {
                $message = "Successfully saved {$successCount} grade(s)!";
                $message_type = 'success';
            } else {
                $message = 'No grades were saved. Please enter at least one grade.';
                $message_type = 'error';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = 'Error saving grades: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Get teacher information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving teacher information: ' . $e->getMessage();
    $message_type = 'error';
}

// Get teacher's classrooms
$classrooms = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM classrooms WHERE teacher_id = ? ORDER BY name");
    $stmt->execute([$teacherId]);
    $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving classrooms: ' . $e->getMessage();
    $message_type = 'error';
}

// Get subjects (all subjects that teacher has graded or can grade)
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.name, s.code 
        FROM subjects s
        JOIN grades g ON s.id = g.subject_id
        WHERE g.teacher_id = ?
        ORDER BY s.name
    ");
    $stmt->execute([$teacherId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no subjects from grades, get all active subjects
    if (empty($subjects)) {
        $stmt = $pdo->prepare("SELECT id, name, code FROM subjects WHERE status = 'active' ORDER BY name");
        $stmt->execute();
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // If status column doesn't exist, get all subjects
    try {
        $stmt = $pdo->query("SELECT id, name, code FROM subjects ORDER BY name");
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $subjects = [];
    }
}

// Handle AJAX request for getting students
if (isset($_GET['action']) && $_GET['action'] === 'get_students' && isset($_GET['classroom_id'])) {
    header('Content-Type: application/json');
    try {
        $classroomId = $_GET['classroom_id'];
        
        // Verify classroom belongs to teacher
        $checkStmt = $pdo->prepare("SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?");
        $checkStmt->execute([$classroomId, $teacherId]);
        
        if ($checkStmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'Classroom not found']);
            exit();
        }
        
        // Get students in this classroom (exclude rejected students)
        $studentsStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.student_id_number
            FROM users u
            JOIN classroom_students cs ON u.id = cs.student_id
            WHERE cs.classroom_id = ? 
            AND u.role = 'student'
            AND u.student_id_number IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM admission_applications aa 
                WHERE aa.student_id = u.id 
                AND aa.status = 'rejected'
            )
            ORDER BY u.last_name, u.first_name
        ");
        $studentsStmt->execute([$classroomId]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'students' => $students]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    redirectTo('auth/staff-login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Add Grades - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <?php include __DIR__ . '/../includes/teacher-sidebar-styles.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
        }
        
        /* Legacy support - map container to main-content */
        .container {
            margin-left: 300px;
            flex: 1;
            padding: 30px;
            width: calc(100% - 300px);
            max-width: 100%;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .container.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        @media (max-width: 1024px) {
            .container {
                margin-left: 280px;
                width: calc(100% - 280px);
            }
        }
        
        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
                width: 100%;
                transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }
        }
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        .form-group select,
        .form-group input[type="number"],
        .form-group input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.2s;
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .students-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .students-table th,
        .students-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .students-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .students-table td {
            color: #666;
        }
        .students-table input[type="number"] {
            width: 120px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }
        .students-table input[type="number"]:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 2px rgba(161, 28, 39, 0.1);
        }
        .ph-grade-preview {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            background: #f8f9fa;
            min-width: 60px;
            text-align: center;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #a11c27;
            color: white;
        }
        .btn-primary:hover {
            background: #8a1620;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'grades';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus-circle"></i> Bulk Add Grades</h1>
            <a href="teacher-grades.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Grades
            </a>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>" style="line-height: 1.6;">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <!-- Excel Upload Section -->
        <div class="card" style="margin-bottom: 20px;">
            <h2 class="card-title"><i class="fas fa-file-excel"></i> Upload Grades from Excel</h2>
            <form method="POST" enctype="multipart/form-data" id="excelUploadForm">
                <input type="hidden" name="action" value="upload_excel_grades">
                <div class="form-row">
                    <div class="form-group">
                        <label for="excel_classroom_id">Classroom/Section <span style="color: red;">*</span></label>
                        <select id="excel_classroom_id" name="classroom_id" required>
                            <option value="">-- Select Classroom --</option>
                            <?php foreach ($classrooms as $classroom): ?>
                                <option value="<?= htmlspecialchars($classroom['id']) ?>">
                                    <?= htmlspecialchars($classroom['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="excel_subject_id">Course <span style="color: red;">*</span></label>
                        <select id="excel_subject_id" name="subject_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['id']) ?>">
                                    <?= htmlspecialchars($subject['name']) ?> (<?= htmlspecialchars($subject['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="excel_grade_type">Grade Type <span style="color: red;">*</span></label>
                        <select id="excel_grade_type" name="grade_type" required>
                            <option value="">-- Select Type --</option>
                            <option value="midterm">Midterm</option>
                            <option value="finals">Finals</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="excel_max_points">Max Points</label>
                        <input type="number" id="excel_max_points" name="max_points" value="100" min="1" step="0.01" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="excel_file">Excel/CSV File <span style="color: red;">*</span></label>
                    <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; margin-top: 8px; border-left: 3px solid #a11c27;">
                        <small style="display: block; color: #666; font-size: 0.85rem; line-height: 1.6;">
                            <i class="fas fa-info-circle" style="color: #a11c27;"></i> <strong>File Format Requirements:</strong><br>
                            • Upload Excel (.xlsx, .xls) or CSV file<br>
                            • Required columns: <strong>Student ID</strong> (or <strong>Student Name</strong>), <strong>Raw Score</strong> (or <strong>Grade</strong> or <strong>Score</strong>)<br>
                            • Enter raw scores (0 to Max Points). Philippine grades will be calculated automatically<br>
                            • Use the "Download Template" button to get a pre-filled template with all students
                        </small>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload & Import Grades
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="downloadTemplate()" id="downloadTemplateBtn" disabled>
                        <i class="fas fa-download"></i> Download Template
                    </button>
                </div>
            </form>
        </div>
        
        <div class="card" style="margin-bottom: 20px; border-left: 4px solid #a11c27;">
            <h2 class="card-title" style="font-size: 1.1rem; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Manual Entry</h2>
        </div>
        
        <div class="card">
            <h2 class="card-title"><i class="fas fa-filter"></i> Select Section & Course</h2>
            <form id="filterForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="classroom_id">Classroom/Section <span style="color: red;">*</span></label>
                        <select id="classroom_id" name="classroom_id" required>
                            <option value="">-- Select Classroom --</option>
                            <?php foreach ($classrooms as $classroom): ?>
                                <option value="<?= htmlspecialchars($classroom['id']) ?>">
                                    <?= htmlspecialchars($classroom['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subject_id">Course <span style="color: red;">*</span></label>
                        <select id="subject_id" name="subject_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['id']) ?>">
                                    <?= htmlspecialchars($subject['name']) ?> (<?= htmlspecialchars($subject['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="grade_type">Grade Type <span style="color: red;">*</span></label>
                        <select id="grade_type" name="grade_type" required>
                            <option value="">-- Select Type --</option>
                            <option value="midterm">Midterm</option>
                            <option value="finals">Finals</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="max_points">Max Points (Raw Score)</label>
                        <input type="number" id="max_points" name="max_points" value="100" min="1" step="0.01" required>
                        <small style="display: block; margin-top: 5px; color: #666; font-size: 0.85rem;">
                            Enter raw scores (0-100). Philippine grades (1.0-5.0) will be calculated automatically.
                        </small>
                    </div>
                </div>
                <button type="button" class="btn btn-primary" onclick="loadStudents()">
                    <i class="fas fa-search"></i> Load Students
                </button>
            </form>
        </div>
        
        <div id="studentsContainer" style="display: none;">
            <div class="card">
                <h2 class="card-title"><i class="fas fa-users"></i> Enter Grades</h2>
                <form method="POST" id="gradesForm" onsubmit="return validateGradesForm()">
                    <input type="hidden" name="action" value="bulk_add_grades">
                    <input type="hidden" name="classroom_id" id="form_classroom_id">
                    <input type="hidden" name="subject_id" id="form_subject_id">
                    <input type="hidden" name="grade_type" id="form_grade_type">
                    <input type="hidden" name="max_points" id="form_max_points">
                    
                    <div id="studentsTableContainer">
                        <div class="loading">
                            <i class="fas fa-spinner"></i>
                            <p>Loading students...</p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save All Grades
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearAllGrades()">
                            <i class="fas fa-eraser"></i> Clear All
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
    
    <script>
        function loadStudents() {
            const classroomId = document.getElementById('classroom_id').value;
            const subjectId = document.getElementById('subject_id').value;
            const gradeType = document.getElementById('grade_type').value;
            const maxPoints = document.getElementById('max_points').value;
            
            if (!classroomId || !subjectId || !gradeType) {
                alert('Please select classroom, subject, and grade type first.');
                return;
            }
            
            // Update hidden form fields
            document.getElementById('form_classroom_id').value = classroomId;
            document.getElementById('form_subject_id').value = subjectId;
            document.getElementById('form_grade_type').value = gradeType;
            document.getElementById('form_max_points').value = maxPoints;
            
            // Show loading
            document.getElementById('studentsContainer').style.display = 'block';
            document.getElementById('studentsTableContainer').innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading students...</p></div>';
            
            // Fetch students
            fetch(`teacher-bulk-grades.php?action=get_students&classroom_id=${classroomId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text(); // Get as text first to check for errors
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success && Array.isArray(data.students)) {
                            displayStudents(data.students, maxPoints);
                        } else {
                            const errorMsg = escapeHtml(data.error || 'Error loading students');
                            document.getElementById('studentsTableContainer').innerHTML = 
                                `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${errorMsg}</p></div>`;
                        }
                    } catch (parseError) {
                        // If response is not valid JSON, it might contain JavaScript or HTML
                        console.error('Invalid JSON response:', parseError);
                        document.getElementById('studentsTableContainer').innerHTML = 
                            `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error: Invalid response from server. Please refresh the page and try again.</p></div>`;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    const errorMsg = escapeHtml(error.message || 'Unknown error occurred');
                    document.getElementById('studentsTableContainer').innerHTML = 
                        `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error: ${errorMsg}</p></div>`;
                });
        }
        
        // Utility function: Escape HTML to prevent XSS
        function escapeHtml(text) {
            if (text === null || text === undefined) {
                return '';
            }
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
        
        // Display students in a table - only generates HTML markup, no JavaScript
        function displayStudents(students, maxPoints) {
            if (!Array.isArray(students) || students.length === 0) {
                document.getElementById('studentsTableContainer').innerHTML = 
                    '<div class="empty-state"><i class="fas fa-user-slash"></i><p>No students found in this classroom.</p></div>';
                return;
            }
            
            // Ensure maxPoints is a valid number
            const safeMaxPoints = parseFloat(maxPoints) || 100;
            
            // Build HTML table - only HTML markup, no JavaScript code
            let html = '<table class="students-table">';
            html += '<thead><tr>';
            html += '<th>#</th>';
            html += '<th>Student Name</th>';
            html += '<th>Student ID</th>';
            html += '<th>Raw Score (Max: ' + escapeHtml(safeMaxPoints) + ')</th>';
            html += '<th>Philippine Grade</th>';
            html += '</tr></thead><tbody>';
            
            students.forEach((student, index) => {
                const studentId = parseInt(student.id) || 0;
                const firstName = escapeHtml(student.first_name || '');
                const lastName = escapeHtml(student.last_name || '');
                const studentIdNumber = escapeHtml(student.student_id_number || 'N/A');
                
                html += '<tr>';
                html += '<td>' + (index + 1) + '</td>';
                html += '<td><strong>' + firstName + ' ' + lastName + '</strong></td>';
                html += '<td>' + studentIdNumber + '</td>';
                html += '<td>';
                html += '<input type="number" ';
                html += 'name="grades[' + studentId + ']" ';
                html += 'class="grade-input" ';
                html += 'data-student-id="' + studentId + '" ';
                html += 'data-max-points="' + safeMaxPoints + '" ';
                html += 'min="0" ';
                html += 'max="' + safeMaxPoints + '" ';
                html += 'step="0.01" ';
                html += 'placeholder="0.00" ';
                html += 'oninput="updatePhilippineGrade(this)" ';
                html += 'style="width: 120px;">';
                html += '</td>';
                html += '<td>';
                html += '<span class="ph-grade-preview" id="ph-grade-' + studentId + '" style="font-weight: 600; color: #666;">-</span>';
                html += '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            // Insert only HTML markup into DOM
            document.getElementById('studentsTableContainer').innerHTML = html;
        }
        
        function validateGradesForm() {
            const maxPoints = parseFloat(document.getElementById('max_points').value);
            const gradeInputs = document.querySelectorAll('input[name^="grades["]');
            let hasAtLeastOne = false;
            let allValid = true;
            
            gradeInputs.forEach(input => {
                const value = parseFloat(input.value);
                if (value !== '' && !isNaN(value)) {
                    hasAtLeastOne = true;
                    if (value < 0 || value > maxPoints) {
                        input.style.borderColor = 'red';
                        allValid = false;
                    } else {
                        input.style.borderColor = '';
                    }
                } else {
                    input.style.borderColor = '';
                }
            });
            
            if (!hasAtLeastOne) {
                alert('Please enter at least one grade.');
                return false;
            }
            
            if (!allValid) {
                alert('Some grades are invalid. Please check that all grades are between 0 and ' + maxPoints + '.');
                return false;
            }
            
            return confirm('Are you sure you want to save these grades?');
        }
        
        function clearAllGrades() {
            const gradeInputs = document.querySelectorAll('input[name^="grades["]');
            gradeInputs.forEach(input => {
                input.value = '';
                input.style.borderColor = '';
                updatePhilippineGrade(input);
            });
        }
        
        // Enable/disable template download button based on classroom selection
        document.getElementById('excel_classroom_id').addEventListener('change', function() {
            const downloadBtn = document.getElementById('downloadTemplateBtn');
            if (this.value) {
                downloadBtn.disabled = false;
            } else {
                downloadBtn.disabled = true;
            }
        });
        
        function downloadTemplate() {
            const classroomId = document.getElementById('excel_classroom_id').value;
            const maxPoints = document.getElementById('excel_max_points').value || 100;
            if (!classroomId) {
                alert('Please select a classroom first.');
                return;
            }
            window.location.href = `teacher-bulk-grades.php?action=download_template&classroom_id=${classroomId}&max_points=${maxPoints}`;
        }
        
        function updatePhilippineGrade(input) {
            const studentId = input.getAttribute('data-student-id');
            const maxPoints = parseFloat(input.getAttribute('data-max-points')) || 100;
            const rawScore = parseFloat(input.value) || 0;
            const previewElement = document.getElementById('ph-grade-' + studentId);
            
            if (rawScore === 0 && input.value === '') {
                previewElement.textContent = '-';
                previewElement.style.color = '#666';
                return;
            }
            
            // Calculate percentage
            const percentage = (rawScore / maxPoints) * 100;
            
            // Convert to Philippine grade
            let phGrade = 5.0; // Default to failed
            if (percentage >= 97) phGrade = 1.0;
            else if (percentage >= 94) phGrade = 1.25;
            else if (percentage >= 91) phGrade = 1.5;
            else if (percentage >= 88) phGrade = 1.75;
            else if (percentage >= 85) phGrade = 2.0;
            else if (percentage >= 82) phGrade = 2.25;
            else if (percentage >= 79) phGrade = 2.5;
            else if (percentage >= 76) phGrade = 2.75;
            else if (percentage >= 75) phGrade = 3.0;
            else if (percentage >= 70) phGrade = 4.0;
            
            // Display Philippine grade with color
            previewElement.textContent = phGrade.toFixed(2);
            
            // Set color based on grade
            if (phGrade <= 1.5) {
                previewElement.style.color = '#28a745'; // Green for excellent
            } else if (phGrade <= 2.5) {
                previewElement.style.color = '#007bff'; // Blue for good
            } else if (phGrade <= 3.0) {
                previewElement.style.color = '#ff9800'; // Orange for conditional pass
            } else if (phGrade <= 4.0) {
                previewElement.style.color = '#ffc107'; // Yellow for low conditional pass
            } else {
                previewElement.style.color = '#dc3545'; // Red for failed
            }
        }
    </script>
</body>
</html>

