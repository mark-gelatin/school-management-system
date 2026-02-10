<?php
/**
 * Admin Account Fix Script
 * This script checks and fixes admin account status issues
 * Run this once to ensure your admin account is properly configured
 */

require_once __DIR__ . '/../../config/database.php';

// Check if admin account exists and fix status
try {
    // Find admin account
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo "<h2>Admin Account Found</h2>";
        echo "<p><strong>Username:</strong> " . htmlspecialchars($admin['username']) . "</p>";
        echo "<p><strong>Email:</strong> " . htmlspecialchars($admin['email']) . "</p>";
        echo "<p><strong>Current Status:</strong> " . ($admin['status'] ?? 'NULL') . "</p>";
        
        // Fix status if needed
        if (empty($admin['status']) || $admin['status'] !== 'active') {
            $updateStmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $updateStmt->execute([$admin['id']]);
            echo "<p style='color: green;'><strong>✓ Status updated to 'active'</strong></p>";
        } else {
            echo "<p style='color: green;'><strong>✓ Status is already 'active'</strong></p>";
        }
        
        // Verify password hash
        if (empty($admin['password']) || strlen($admin['password']) < 20) {
            echo "<p style='color: orange;'><strong>⚠ Password hash looks invalid. You may need to reset the password.</strong></p>";
        } else {
            echo "<p style='color: green;'><strong>✓ Password hash looks valid</strong></p>";
        }
        
        echo "<hr>";
        echo "<h3>Session Test</h3>";
        echo "<p>Testing session configuration...</p>";
        
        // Test session
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', 0);
        ini_set('session.gc_maxlifetime', 3600);
        ini_set('session.cookie_path', '/');
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['test'] = 'Session is working';
        echo "<p style='color: green;'><strong>✓ Session started successfully</strong></p>";
        echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
        echo "<p><strong>Session Path:</strong> " . session_save_path() . "</p>";
        
    } else {
        echo "<h2 style='color: red;'>No Admin Account Found</h2>";
        echo "<p>You need to create an admin account first.</p>";
    }
    
    echo "<hr>";
    echo "<h3>All Admin Accounts</h3>";
    $allAdmins = $pdo->query("SELECT id, username, email, status, role FROM users WHERE role = 'admin'")->fetchAll();
    if (count($allAdmins) > 0) {
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Status</th><th>Role</th></tr>";
        foreach ($allAdmins as $admin) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($admin['id']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['status'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($admin['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No admin accounts found in database.</p>";
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='staff-login.php'>← Back to Login</a></p>";
?>

