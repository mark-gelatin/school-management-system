<?php
/**
 * Email System for Colegio de Amore
 * Handles all email notifications
 */

if (!function_exists('sendEmail')) {
    /**
     * Send email using system settings
     */
    function sendEmail($to, $subject, $message, $isHTML = true) {
        global $pdo;
        
        // Get email settings
        $emailEnabled = getSystemSetting('email_enabled', '1');
        if ($emailEnabled !== '1') {
            return ['success' => false, 'message' => 'Email system is disabled'];
        }
        
        $smtpHost = getSystemSetting('smtp_host', '');
        $smtpPort = getSystemSetting('smtp_port', '587');
        $smtpUsername = getSystemSetting('smtp_username', '');
        $smtpPassword = getSystemSetting('smtp_password', '');
        $smtpEncryption = getSystemSetting('smtp_encryption', 'tls');
        $fromEmail = getSystemSetting('site_email', 'admin@colegiodeamore.edu');
        $siteName = getSystemSetting('site_name', 'Colegio de Amore');
        
        // If SMTP is not configured, use PHP mail() function
        if (empty($smtpHost) || empty($smtpUsername)) {
            return sendEmailPHP($to, $subject, $message, $fromEmail, $siteName, $isHTML);
        }
        
        // Use PHPMailer if available, otherwise fallback to PHP mail()
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return sendEmailSMTP($to, $subject, $message, $smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncryption, $fromEmail, $siteName, $isHTML);
        }
        
        return sendEmailPHP($to, $subject, $message, $fromEmail, $siteName, $isHTML);
    }
}

if (!function_exists('sendEmailPHP')) {
    /**
     * Send email using PHP mail() function
     */
    function sendEmailPHP($to, $subject, $message, $fromEmail, $fromName, $isHTML = true) {
        $headers = [];
        $headers[] = "From: $fromName <$fromEmail>";
        $headers[] = "Reply-To: $fromEmail";
        $headers[] = "X-Mailer: PHP/" . phpversion();
        
        if ($isHTML) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-type: text/html; charset=UTF-8";
        }
        
        $result = @mail($to, $subject, $message, implode("\r\n", $headers));
        
        return [
            'success' => $result,
            'message' => $result ? 'Email sent successfully' : 'Failed to send email'
        ];
    }
}

if (!function_exists('sendEmailSMTP')) {
    /**
     * Send email using SMTP (requires PHPMailer)
     */
    function sendEmailSMTP($to, $subject, $message, $host, $port, $username, $password, $encryption, $fromEmail, $fromName, $isHTML = true) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = $encryption;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            
            // Recipients
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            $mail->send();
            return ['success' => true, 'message' => 'Email sent successfully'];
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
            return ['success' => false, 'message' => 'Email sending failed: ' . $mail->ErrorInfo];
        }
    }
}

if (!function_exists('sendApplicationStatusEmail')) {
    /**
     * Send email notification for application status change
     */
    function sendApplicationStatusEmail($applicationId, $status, $studentEmail, $studentName) {
        global $pdo;
        
        $subject = "Application Status Update - Colegio de Amore";
        $statusText = ucfirst($status);
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a11c27; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .status { padding: 10px; margin: 20px 0; border-radius: 5px; }
                .approved { background: #d4edda; color: #155724; }
                .rejected { background: #f8d7da; color: #721c24; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Colegio de Amore</h1>
                </div>
                <div class='content'>
                    <p>Dear $studentName,</p>
                    <p>Your admission application status has been updated.</p>
                    <div class='status " . strtolower($status) . "'>
                        <strong>Status: $statusText</strong>
                    </div>
                    <p>Please log in to your account to view more details.</p>
                    <p>Thank you for your interest in Colegio de Amore.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return sendEmail($studentEmail, $subject, $message, true);
    }
}

if (!function_exists('sendPasswordResetEmail')) {
    /**
     * Send password reset email
     */
    function sendPasswordResetEmail($userEmail, $userName, $resetToken, $resetUrl) {
        $subject = "Password Reset Request - Colegio de Amore";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a11c27; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #a11c27; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                <div class='content'>
                    <p>Dear $userName,</p>
                    <p>You have requested to reset your password. Click the button below to reset it:</p>
                    <a href='$resetUrl' class='button'>Reset Password</a>
                    <p>Or copy and paste this link into your browser:</p>
                    <p>$resetUrl</p>
                    <p>This link will expire in 24 hours.</p>
                    <p>If you did not request this, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return sendEmail($userEmail, $subject, $message, true);
    }
}

if (!function_exists('sendGradeNotificationEmail')) {
    /**
     * Send email notification when grade is posted
     */
    function sendGradeNotificationEmail($studentEmail, $studentName, $subjectName, $grade, $gradeType) {
        $subject = "New Grade Posted - $subjectName";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #a11c27; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .grade-box { padding: 15px; background: white; border-left: 4px solid #a11c27; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>New Grade Posted</h1>
                </div>
                <div class='content'>
                    <p>Dear $studentName,</p>
                    <p>A new grade has been posted for your subject.</p>
                    <div class='grade-box'>
                        <strong>Subject:</strong> $subjectName<br>
                        <strong>Grade Type:</strong> " . ucfirst($gradeType) . "<br>
                        <strong>Grade:</strong> $grade
                    </div>
                    <p>Please log in to view more details.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return sendEmail($studentEmail, $subject, $message, true);
    }
}

