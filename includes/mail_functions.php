<?php
/**
 * Mail Functions - Common email sending utilities
 * This file contains reusable functions for sending various types of emails
 * including password reset, notifications, and other system emails
 */

// Include PHPMailer library if using (optional - you can use PHP's mail() function)
// require_once '../vendor/autoload.php'; // If using Composer
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\SMTP;
// use PHPMailer\PHPMailer\Exception;

/**
 * Send email using PHP's built-in mail function
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message Email body (HTML supported)
 * @param array $additional_headers Optional additional headers
 * @return bool Success status
 */
function sendEmail($to, $subject, $message, $additional_headers = []) {
    // Get site settings for sender info
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT site_name, site_email FROM site_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $site_name = $settings['site_name'] ?? 'CheckMate Yoklama Sistemi';
    $site_email = $settings['site_email'] ?? 'noreply@checkmate.com';
    
    // Default headers
    $headers = [
        'From' => "$site_name <$site_email>",
        'Reply-To' => $site_email,
        'X-Mailer' => 'PHP/' . phpversion(),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8'
    ];
    
    // Merge with additional headers
    $headers = array_merge($headers, $additional_headers);
    
    // Convert headers array to string
    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "$key: $value\r\n";
    }
    
    // Send email
    return mail($to, $subject, $message, $header_string);
}

/**
 * Send password reset email
 * 
 * @param string $to Recipient email address
 * @param string $name Recipient name
 * @param string $reset_token Password reset token
 * @return bool Success status
 */
function sendPasswordResetEmail($to, $name, $reset_token) {
    // Get site URL from settings or use default
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT site_name, site_url FROM site_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $site_name = $settings['site_name'] ?? 'CheckMate Yoklama Sistemi';
    $site_url = $settings['site_url'] ?? 'http://localhost/ahdakade_checkmate';
    
    // Remove trailing slash from site URL
    $site_url = rtrim($site_url, '/');
    
    $subject = "$site_name - Şifre Sıfırlama Talebi";
    
    $reset_link = $site_url . "/reset_password.php?token=" . $reset_token;
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f4f4f4; padding: 20px; margin-top: 20px; }
            .button { display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$site_name</h1>
                <h2>Şifre Sıfırlama Talebi</h2>
            </div>
            <div class='content'>
                <p>Merhaba $name,</p>
                <p>Hesabınız için bir şifre sıfırlama talebi aldık. Eğer bu talebi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.</p>
                <p>Şifrenizi sıfırlamak için aşağıdaki butona tıklayın:</p>
                <p style='text-align: center;'>
                    <a href='$reset_link' class='button'>Şifremi Sıfırla</a>
                </p>
                <p>Veya aşağıdaki linki tarayıcınıza kopyalayın:</p>
                <p style='word-break: break-all;'>$reset_link</p>
                <p><strong>Not:</strong> Bu link 1 saat içinde geçerliliğini yitirecektir.</p>
            </div>
            <div class='footer'>
                <p>Bu otomatik bir e-postadır, lütfen yanıtlamayın.</p>
                <p>&copy; " . date('Y') . " $site_name. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to, $subject, $message);
}

/**
 * Send account creation notification email
 * 
 * @param string $to Recipient email address
 * @param string $name Recipient name
 * @param string $username Username for login
 * @param string $password Initial password
 * @param string $role User role (teacher/student)
 * @return bool Success status
 */
function sendAccountCreationEmail($to, $name, $username, $password, $role) {
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT site_name, site_url FROM site_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $site_name = $settings['site_name'] ?? 'CheckMate Yoklama Sistemi';
    $site_url = $settings['site_url'] ?? 'http://localhost/ahdakade_checkmate';
    
    $role_tr = $role == 'teacher' ? 'Öğretmen' : 'Öğrenci';
    $subject = "$site_name - Hesabınız Oluşturuldu";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f4f4f4; padding: 20px; margin-top: 20px; }
            .info-box { background-color: white; padding: 15px; margin: 10px 0; border-left: 4px solid #2196F3; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$site_name</h1>
                <h2>Hoş Geldiniz!</h2>
            </div>
            <div class='content'>
                <p>Merhaba $name,</p>
                <p>$site_name sistemine $role_tr olarak kaydınız başarıyla oluşturuldu.</p>
                <p>Giriş bilgileriniz:</p>
                <div class='info-box'>
                    <strong>Kullanıcı Adı:</strong> $username<br>
                    <strong>Şifre:</strong> $password<br>
                    <strong>Rol:</strong> $role_tr
                </div>
                <p>Sisteme giriş yapmak için: <a href='$site_url'>$site_url</a></p>
                <p><strong>Güvenlik Uyarısı:</strong> İlk girişinizde şifrenizi değiştirmenizi öneririz.</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " $site_name. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to, $subject, $message);
}

/**
 * Send attendance notification email
 * 
 * @param string $to Recipient email address
 * @param string $name Student name
 * @param string $course_name Course name
 * @param string $status Attendance status (present/absent/late)
 * @param string $date Attendance date
 * @return bool Success status
 */
function sendAttendanceNotificationEmail($to, $name, $course_name, $status, $date) {
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT site_name FROM site_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $site_name = $settings['site_name'] ?? 'CheckMate Yoklama Sistemi';
    
    $status_tr = [
        'present' => 'Mevcut',
        'absent' => 'Yok',
        'late' => 'Geç'
    ];
    
    $status_color = [
        'present' => '#4CAF50',
        'absent' => '#f44336',
        'late' => '#ff9800'
    ];
    
    $subject = "$site_name - Yoklama Bildirimi";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #673AB7; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f4f4f4; padding: 20px; margin-top: 20px; }
            .status-box { background-color: white; padding: 15px; margin: 10px 0; border-left: 4px solid " . $status_color[$status] . "; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$site_name</h1>
                <h2>Yoklama Bildirimi</h2>
            </div>
            <div class='content'>
                <p>Merhaba $name,</p>
                <p>Aşağıdaki ders için yoklama durumunuz kaydedildi:</p>
                <div class='status-box'>
                    <strong>Ders:</strong> $course_name<br>
                    <strong>Tarih:</strong> $date<br>
                    <strong>Durum:</strong> <span style='color: " . $status_color[$status] . "'>" . $status_tr[$status] . "</span>
                </div>
            </div>
            <div class='footer'>
                <p>Bu otomatik bir bildirimdir.</p>
                <p>&copy; " . date('Y') . " $site_name. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to, $subject, $message);
}

/**
 * Send custom notification email
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $title Notification title
 * @param string $body Notification body (HTML supported)
 * @param string $header_color Header background color (default: #673AB7)
 * @return bool Success status
 */
function sendCustomNotificationEmail($to, $subject, $title, $body, $header_color = '#673AB7') {
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT site_name FROM site_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $site_name = $settings['site_name'] ?? 'CheckMate Yoklama Sistemi';
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: $header_color; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f4f4f4; padding: 20px; margin-top: 20px; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$site_name</h1>
                <h2>$title</h2>
            </div>
            <div class='content'>
                $body
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " $site_name. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to, $subject, $message);
}

/**
 * Send bulk email to multiple recipients
 * 
 * @param array $recipients Array of email addresses
 * @param string $subject Email subject
 * @param string $message Email body
 * @param bool $use_bcc Use BCC for privacy (default: true)
 * @return array Results array with success/failure for each recipient
 */
function sendBulkEmail($recipients, $subject, $message, $use_bcc = true) {
    $results = [];
    
    if ($use_bcc && count($recipients) > 1) {
        // Send single email with all recipients in BCC
        $to = 'noreply@checkmate.com'; // Use a dummy address for To field
        $bcc_list = implode(', ', $recipients);
        
        $additional_headers = ['Bcc' => $bcc_list];
        $success = sendEmail($to, $subject, $message, $additional_headers);
        
        foreach ($recipients as $email) {
            $results[$email] = $success;
        }
    } else {
        // Send individual emails
        foreach ($recipients as $email) {
            $results[$email] = sendEmail($email, $subject, $message);
        }
    }
    
    return $results;
}

/**
 * Validate email address
 * 
 * @param string $email Email address to validate
 * @return bool True if valid, false otherwise
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate password reset token and save to database
 * 
 * @param int $user_id User ID
 * @param PDO $conn Database connection
 * @return string|false Reset token on success, false on failure
 */
function generatePasswordResetToken($user_id, $conn) {
    // Generate unique token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    try {
        // Delete any existing tokens for this user
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Insert new token
        $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $token, $expires_at]);
        
        return $token;
    } catch (PDOException $e) {
        error_log("Password reset token generation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify password reset token
 * 
 * @param string $token Reset token
 * @param PDO $conn Database connection
 * @return int|false User ID if valid, false if invalid or expired
 */
function verifyPasswordResetToken($token, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT user_id 
            FROM password_resets 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $row['user_id'];
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Password reset token verification failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete password reset token after use
 * 
 * @param string $token Reset token
 * @param PDO $conn Database connection
 * @return bool Success status
 */
function deletePasswordResetToken($token, $conn) {
    try {
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);
        return true;
    } catch (PDOException $e) {
        error_log("Password reset token deletion failed: " . $e->getMessage());
        return false;
    }
}
?>
