<?php
/**
 * Admin Password Reset Script
 * Use this to reset your admin password if you can't log in
 */

require_once __DIR__ . '/../../config/database.php';

$message = '';
$message_type = '';

// Handle password reset
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    $username = trim($_POST['username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($username)) {
        $message = "Please enter a username";
        $message_type = "error";
    } elseif (empty($new_password)) {
        $message = "Please enter a new password";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match";
        $message_type = "error";
    } elseif (strlen($new_password) < 4) {
        $message = "Password must be at least 4 characters";
        $message_type = "error";
    } else {
        try {
            // Find admin account
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin'");
            $stmt->execute([$username, $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                // Hash the new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password and ensure status is active
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, status = 'active' WHERE id = ?");
                $updateStmt->execute([$hashed_password, $admin['id']]);
                
                $message = "Password reset successfully! You can now log in with username: " . htmlspecialchars($admin['username']) . " and your new password.";
                $message_type = "success";
            } else {
                $message = "Admin account not found with username/email: " . htmlspecialchars($username);
                $message_type = "error";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get all admin accounts for reference
try {
    $adminAccounts = $pdo->query("SELECT id, username, email, status, created_at FROM users WHERE role = 'admin' ORDER BY id")->fetchAll();
} catch (Exception $e) {
    $adminAccounts = [];
    $message = "Error fetching admin accounts: " . $e->getMessage();
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Admin Password - Colegio de Amore</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 100%;
        }
        h1 {
            color: #a11c27;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #a11c27;
        }
        button {
            background: #a11c27;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
        }
        button:hover {
            background: #b31310;
        }
        .admin-list {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #eee;
        }
        .admin-list h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table th,
        table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #a11c27;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .info-box p {
            margin: 5px 0;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reset Admin Password</h1>
        <p class="subtitle">Use this tool to reset your admin account password if you can't log in.</p>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <p><strong>Note:</strong> This will reset the password for an admin account. Make sure you have the correct username or email.</p>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Admin Username or Email:</label>
                <input type="text" id="username" name="username" required 
                       placeholder="Enter admin username or email" 
                       value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>">
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required 
                       placeholder="Enter new password (min 4 characters)">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required 
                       placeholder="Confirm new password">
            </div>
            
            <button type="submit" name="reset_password">Reset Password</button>
        </form>
        
        <?php if (!empty($adminAccounts)): ?>
            <div class="admin-list">
                <h2>Existing Admin Accounts</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminAccounts as $admin): ?>
                            <tr>
                                <td><?= htmlspecialchars($admin['id']) ?></td>
                                <td><?= htmlspecialchars($admin['username']) ?></td>
                                <td><?= htmlspecialchars($admin['email']) ?></td>
                                <td><?= htmlspecialchars($admin['status'] ?? 'NULL') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <a href="staff-login.php" class="back-link">← Back to Login</a>
    </div>
</body>
</html>

