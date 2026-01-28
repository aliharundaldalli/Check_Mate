<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// Oturum başlatma
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Kullanıcı doğrulama sınıfı
class Auth {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function login($email, $password) {
        $query = "SELECT * FROM users WHERE (email = :email OR student_number = :email) AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                return true;
            }
        }
        return false;
    }
    
    public function logout() {
        session_destroy();
        return true;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function checkRole($required_role) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        if (is_array($required_role)) {
            return in_array($_SESSION['user_type'], $required_role);
        }
        
        return $_SESSION['user_type'] === $required_role;
    }
    
    public function redirectByRole() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
        
        switch ($_SESSION['user_type']) {
            case 'admin':
                header('Location: admin/dashboard.php');
                break;
            case 'teacher':
                header('Location: teacher/dashboard.php');
                break;
            case 'student':
                header('Location: student/dashboard.php');
                break;
        }
        exit();
    }
}

// Yoklama yönetimi sınıfı
class AttendanceManager {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    // Yoklama oturumu oluştur
    public function createSession($course_id, $teacher_id, $session_name, $session_date, $start_time, $duration_minutes = 10) {
        $query = "INSERT INTO attendance_sessions (course_id, teacher_id, session_name, session_date, start_time, duration_minutes) 
                  VALUES (:course_id, :teacher_id, :session_name, :session_date, :start_time, :duration_minutes)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':teacher_id', $teacher_id);
        $stmt->bindParam(':session_name', $session_name);
        $stmt->bindParam(':session_date', $session_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':duration_minutes', $duration_minutes);
        
        if ($stmt->execute()) {
            $session_id = $this->db->lastInsertId();
            $this->generateFirstPhaseKeys($session_id, $course_id);
            return $session_id;
        }
        return false;
    }
    
    // Birinci aşama anahtarları üret
    private function generateFirstPhaseKeys($session_id, $course_id) {
        // Derse kayıtlı öğrenci sayısını al
        $query = "SELECT COUNT(*) as student_count FROM course_enrollments WHERE course_id = :course_id AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $student_count = $result['student_count'];
        
        // Her öğrenci için benzersiz 8 haneli anahtar üret
        for ($i = 0; $i < $student_count; $i++) {
            $key_code = $this->generateUniqueKey(8);
            
            $query = "INSERT INTO first_phase_keys (session_id, key_code) VALUES (:session_id, :key_code)";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':session_id', $session_id);
            $stmt->bindParam(':key_code', $key_code);
            $stmt->execute();
        }
    }
    
    // Benzersiz anahtar üret
    private function generateUniqueKey($length = 8) {
        do {
            $key = str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
            
            // Veritabanında bu anahtarın olup olmadığını kontrol et
            $query = "SELECT COUNT(*) as count FROM first_phase_keys WHERE key_code = :key_code";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':key_code', $key);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } while ($result['count'] > 0);
        
        return $key;
    }
    
    // İkinci aşama anahtarı üret (15 saniyede bir değişir)
    public function generateSecondPhaseKey($session_id) {
        $current_time = new DateTime();
        $valid_from = $current_time->format('Y-m-d H:i:s');
        $current_time->add(new DateInterval('PT15S')); // 15 saniye ekle
        $valid_until = $current_time->format('Y-m-d H:i:s');
        
        $key_code = $this->generateDynamicKey();
        
        $query = "INSERT INTO second_phase_keys (session_id, key_code, valid_from, valid_until) 
                  VALUES (:session_id, :key_code, :valid_from, :valid_until)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':key_code', $key_code);
        $stmt->bindParam(':valid_from', $valid_from);
        $stmt->bindParam(':valid_until', $valid_until);
        
        if ($stmt->execute()) {
            return $key_code;
        }
        return false;
    }
    
    // Dinamik anahtar üret
    private function generateDynamicKey() {
        return uniqid() . mt_rand(1000, 9999);
    }
    
    // Birinci aşama anahtar doğrulama
    public function validateFirstPhaseKey($session_id, $key_code, $student_id) {
        $query = "SELECT * FROM first_phase_keys 
                  WHERE session_id = :session_id AND key_code = :key_code AND is_used = 0";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':key_code', $key_code);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $key = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Anahtarı kullanıldı olarak işaretle
            $update_query = "UPDATE first_phase_keys 
                            SET is_used = 1, used_by_student_id = :student_id, used_at = NOW() 
                            WHERE id = :key_id";
            
            $update_stmt = $this->db->prepare($update_query);
            $update_stmt->bindParam(':student_id', $student_id);
            $update_stmt->bindParam(':key_id', $key['id']);
            $update_stmt->execute();
            
            return $key['id'];
        }
        return false;
    }
    
    // İkinci aşama anahtar doğrulama
    public function validateSecondPhaseKey($session_id, $key_code) {
        $current_time = date('Y-m-d H:i:s');
        
        $query = "SELECT * FROM second_phase_keys 
                  WHERE session_id = :session_id AND key_code = :key_code 
                  AND valid_from <= :current_time AND valid_until >= :current_time";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':key_code', $key_code);
        $stmt->bindParam(':current_time', $current_time);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    // Yoklama kaydı oluştur
    public function recordAttendance($session_id, $student_id, $first_phase_key_id, $ip_address, $user_agent) {
        $query = "INSERT INTO attendance_records (session_id, student_id, first_phase_key_id, ip_address, user_agent) 
                  VALUES (:session_id, :student_id, :first_phase_key_id, :ip_address, :user_agent)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':first_phase_key_id', $first_phase_key_id);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->bindParam(':user_agent', $user_agent);
        
        return $stmt->execute();
    }
    
    // İkinci aşamayı tamamla
    public function completeSecondPhase($session_id, $student_id) {
        $query = "UPDATE attendance_records 
                  SET second_phase_completed = 1 
                  WHERE session_id = :session_id AND student_id = :student_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':student_id', $student_id);
        
        return $stmt->execute();
    }
}

// QR Kod oluşturucu sınıfı
class QRCodeGenerator {
    public static function generateQRCode($data, $filename = null) {
        $qrCode = new QrCode($data);
        $writer = new PngWriter();
        
        if ($filename) {
            $result = $writer->write($qrCode);
            file_put_contents($filename, $result->getString());
            return $filename;
        } else {
            $result = $writer->write($qrCode);
            return 'data:image/png;base64,' . base64_encode($result->getString());
        }
    }
}

// E-posta gönderici sınıfı
class EmailSender {
    private $mailer;
    private $log_file;
    
    public function __construct() {
        $this->mailer = new PHPMailer(true);
        $this->log_file = dirname(__DIR__) . '/logs/email_errors.log';
        
        // Create logs directory if it doesn't exist
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        // SMTP ayarları (Gmail örneği - düzenleyiniz)
        $this->mailer->isSMTP();
        $this->mailer->Host = 'smtp.gmail.com';
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = 'ahdakademik@gmail.com'; // Güncelleyiniz
        $this->mailer->Password = 'shodcaxnalaafgln'; // Güncelleyiniz
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = 587;
        $this->mailer->CharSet = 'UTF-8';
        
        // Enable SMTP debugging for development (0 = off, 1 = client messages, 2 = client and server messages)
        $this->mailer->SMTPDebug = 0;
    }
    
    private function logError($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public function sendEmail($to, $subject, $body, $isHTML = true) {
        try {
            // Clear any previous recipients
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            $this->mailer->setFrom('no_reply@ahdakademi.com', 'Ahd Akademi Yoklama Sistemi');
            $this->mailer->addAddress($to);
            
            $this->mailer->isHTML($isHTML);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            
            // Add plain text alternative for HTML emails
            if ($isHTML) {
                $this->mailer->AltBody = strip_tags($body);
            }
            
            $result = $this->mailer->send();
            
            if ($result) {
                $this->logError("SUCCESS: Email sent to {$to} with subject: {$subject}");
            }
            
            return $result;
        } catch (Exception $e) {
            $error_message = "FAILED: Email to {$to} - Subject: {$subject} - Error: " . $this->mailer->ErrorInfo . " - Exception: " . $e->getMessage();
            $this->logError($error_message);
            
            // Log debug information if credentials are not configured
            if ($this->mailer->Username === 'ahdakademik@gmail.com') {
                $this->logError("WARNING: SMTP credentials not configured. Please update Username and Password in EmailSender class.");
            }
            
            return false;
        }
    }
    
    public function sendBulkEmail($recipients, $subject, $body) {
        $success_count = 0;
        
        foreach ($recipients as $recipient) {
            if ($this->sendEmail($recipient['email'], $subject, $body)) {
                $success_count++;
            }
        }
        
        return $success_count;
    }
}

// Yardımcı fonksiyonlar
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function show_message($message, $type = 'info') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

function display_message() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        
        if (is_array($message)) {
            $message = implode('<br>', $message);
        }
        echo "<div class='alert alert-$type'>$message</div>";
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
}

function calculateAbsencePercentage($student_id, $course_id) {
    $database = new Database();
    $db = $database->getConnection();
    
    // Toplam oturum sayısı
    $query = "SELECT COUNT(*) as total_sessions FROM attendance_sessions 
              WHERE course_id = :course_id AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':course_id', $course_id);
    $stmt->execute();
    $total_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['total_sessions'];
    
    // Öğrencinin katıldığı oturum sayısı
    $query = "SELECT COUNT(*) as attended_sessions FROM attendance_records ar
              JOIN attendance_sessions asess ON ar.session_id = asess.id
              WHERE ar.student_id = :student_id AND asess.course_id = :course_id 
              AND ar.second_phase_completed = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->bindParam(':course_id', $course_id);
    $stmt->execute();
    $attended_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['attended_sessions'];
    
    if ($total_sessions == 0) return 0;
    
    $absence_percentage = (($total_sessions - $attended_sessions) / $total_sessions) * 100;
    return round($absence_percentage, 2);
}

/**
 * Güvenli Dosya Yükleme Fonksiyonu
 * 
 * @param array $file_array $_FILES['input_name'] dizisi veya toplu yüklemede tekil dosya dizisi
 * @param string $upload_dir Yüklemenin yapılacağı dizin (sonu / ile bitmeli)
 * @param string $allowed_extensions İzin verilen uzantılar (virgülle ayrılmış string: 'pdf,jpg,png')
 * @param int $max_size_mb Maksimum dosya boyutu (MB cinsinden)
 * @param string|null $custom_filename İsteğe bağlı özel dosya adı (Uzantısız). Eğer verilirse bu isim kullanılır.
 * @return array ['status' => bool, 'message' => string, 'path' => string, 'filename' => string, 'size' => int]
 */
function secure_file_upload($file_array, $upload_dir, $allowed_extensions = 'pdf,doc,docx,zip,jpg,png', $max_size_mb = 10, $custom_filename = null) {
    // 1. Temel Hata Kontrolü
    if (!isset($file_array['error']) || is_array($file_array['error'])) {
        return ['status' => false, 'message' => 'Geçersiz dosya parametresi.'];
    }

    switch ($file_array['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['status' => false, 'message' => 'Dosya gönderilmedi.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['status' => false, 'message' => 'Dosya boyutu çok büyük.'];
        default:
            return ['status' => false, 'message' => 'Bilinmeyen yükleme hatası.'];
    }

    // 2. Boyut Kontrolü
    if ($file_array['size'] > $max_size_mb * 1024 * 1024) {
        return ['status' => false, 'message' => "Dosya boyutu {$max_size_mb}MB sınırını aşıyor."];
    }

    // 3. Uzantı ve MIME Type Kontrolü
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file_array['tmp_name']);
    
    $allowed_ext_array = array_map('trim', explode(',', strtolower($allowed_extensions)));
    $file_ext = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));

    // Güvenli MIME Türleri Haritası
    $mime_map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg'
    ];

    // Uzantı listede var mı?
    if (!in_array($file_ext, $allowed_ext_array)) {
        return ['status' => false, 'message' => 'Bu dosya uzantısına izin verilmiyor.'];
    }

    // MIME type uzantıyla uyuşuyor mu? (Opsiyonel ama önerilen sıkı kontrol)
    $dangerous_mimes = [
        'application/x-httpd-php', 'application/x-php', 'text/php', 'text/x-php', 'application/x-httpd-php-source'
    ];
    
    if (in_array($mime_type, $dangerous_mimes)) {
        return ['status' => false, 'message' => 'Güvenlik nedeniyle bu dosya türü engellendi.'];
    }

    // 4. Dizin Kontrolü
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['status' => false, 'message' => 'Yükleme dizini oluşturulamadı.'];
        }
    }

    // 5. Güvenli İsimlendirme ve Taşıma
    if ($custom_filename) {
        // Özel ismi temizle (sadece alfanümerik ve alt çizgi kalsın)
        $safe_custom_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $custom_filename);
        // Benzersiz olması için gerekirse sonuna uniqid ekle ama kullanıcı format istediği için
        // eğer aynı isimde dosya varsa üzerine yazar veya numara ekleriz.
        // Kullanıcı isteğine göre: ogrencino_odev_no_tarih.pdf
        // Bu format saniyeye kadar içeriyorsa çakışma zor.
        $new_filename = $safe_custom_name . '.' . $file_ext;
        
        // Çakışma kontrolü (Opsiyonel: üzerine yazmaması için)
        // while(file_exists($final_path)) ... eklenebilir. Şimdilik üzerine yazsın (son sürüm).
    } else {
        $new_filename = uniqid('file_', true) . '.' . $file_ext;
    }
    
    $destination = rtrim($upload_dir, '/') . '/' . $new_filename;

    if (!move_uploaded_file($file_array['tmp_name'], $destination)) {
        return ['status' => false, 'message' => 'Dosya taşınırken hata oluştu.'];
    }

    return [
        'status' => true,
        'message' => 'Yükleme başarılı.',
        'path' => $destination,
        'filename' => $file_array['name'], // Orijinal ad
        'stored_filename' => $new_filename, // Diskteki ad (yeni)
        'size' => $file_array['size'],
        'type' => $mime_type // Detected MIME type
    ];
}
?>
