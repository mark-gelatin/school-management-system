<?php
// Unified database setup script
// Imports database/amore_unified.sql and provisions the amore_college schema

$host = 'localhost';
$username = 'root';
$password = '';
$databaseFile = __DIR__ . '/../database/main/amore_unified_complete.sql';
$targetDb = 'amore_college';

try {
    if (!file_exists($databaseFile)) {
        throw new RuntimeException('Unified SQL file not found at ' . $databaseFile);
    }

    $pdo = new PDO("mysql:host=$host", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Ensure the unified database exists before import
    $pdo->exec("CREATE DATABASE IF NOT EXISTS {$targetDb} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE {$targetDb}");

    $sql = file_get_contents($databaseFile);
    if ($sql === false) {
        throw new RuntimeException('Unable to read unified SQL file.');
    }

    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);
    }

    $pdo->exec($sql);

    echo "Unified database '{$targetDb}' imported successfully from amore_unified_complete.sql.<br>";
    echo "<a href='student-management/index.php'>Go to Student Management System</a>";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
?>
