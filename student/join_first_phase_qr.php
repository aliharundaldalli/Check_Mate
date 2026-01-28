<?php
// student/join_first_phase_qr.php - QR Kod ile Birinci Aşama Yoklama
error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

try {
    require_once '../includes/functions.php';
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // 1. Giriş Kontrolü
    if (!isset($_SESSION['user_id'])) {
        // Giriş yapmamışsa, URL'i kaydet ve login'e yönlendir
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $_SESSION['redirect_after_login'] = $current_url;
        
        show_message('QR kod ile yoklama vermek için önce giriş yapmalısınız.', 'warning');
        redirect('../login.php');
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    $db->exec("SET time_zone = '+03:00'");
    
    $student_id = $_SESSION['user_id'];
    
    // 2. Parametre Kontrolü (fk = first_key)
    $key_code = isset($_GET['fk']) ? trim($_GET['fk']) : '';
    
    if (empty($key_code)) {
        show_message('Geçersiz QR kod bağlantısı.', 'danger');
        redirect('attendance.php');
        exit;
    }
    
    // 3. Anahtar Doğrulama - Bu anahtar hangi oturuma ait?
    $stmt = $db->prepare("
        SELECT fpk.*, asess.id as session_id, asess.status, asess.is_active, asess.course_id,
               asess.session_date, asess.start_time, asess.duration_minutes
        FROM first_phase_keys fpk
        JOIN attendance_sessions asess ON fpk.session_id = asess.id
        WHERE fpk.key_code = ?
    ");
    $stmt->execute([$key_code]);
    $keyData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keyData) {
        throw new Exception('Geçersiz yoklama kodu.');
    }
    
    // Anahtar zaten kullanılmış mı?
    if ($keyData['is_used']) {
        throw new Exception('Bu yoklama kodu daha önce kullanılmış.');
    }
    
    $session_id = $keyData['session_id'];
    
    // 4. Öğrenci bu derse kayıtlı mı?
    $stmt = $db->prepare("SELECT * FROM course_enrollments WHERE course_id = ? AND student_id = ? AND is_active = 1");
    $stmt->execute([$keyData['course_id'], $student_id]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        throw new Exception('Bu derse kayıtlı değilsiniz.');
    }
    
    // 5. Oturum durumu kontrolü
    // Oturum aktif değilse bile kaydedelim (öğrenci daha sonra ikinci aşamayı tamamlayacak)
    // Ama oturum "closed" ise hata verelim
    if ($keyData['status'] === 'closed' || $keyData['status'] === 'expired') {
        throw new Exception('Bu yoklama oturumu kapanmış veya süresi dolmuş.');
    }
    
    // 6. Daha önce bu oturumda kayıt var mı?
    $stmt = $db->prepare("SELECT * FROM attendance_records WHERE session_id = ? AND student_id = ?");
    $stmt->execute([$session_id, $student_id]);
    $existing_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing_record) {
        // Zaten kayıt var
        show_message('Bu yoklama oturumuna zaten kaydınız var.', 'info');
    } else {
        // Yeni kayıt oluştur - Birinci aşama tamamlandı
        $stmt = $db->prepare("
            INSERT INTO attendance_records (session_id, student_id, first_phase_key_id, attendance_time, second_phase_completed) 
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$session_id, $student_id, $keyData['id']]);
        
        // Anahtarı kullanıldı olarak işaretle (used_by_student_id ile birlikte)
        $stmt = $db->prepare("UPDATE first_phase_keys SET is_used = 1, used_at = NOW(), used_by_student_id = ? WHERE id = ?");
        $stmt->execute([$student_id, $keyData['id']]);
        
        // Oturum aktif mi? Aktifse ikinci aşama için yönlendir
        if ($keyData['is_active'] && $keyData['status'] === 'active') {
            show_message('Birinci aşama tamamlandı! Şimdi ikinci aşama kodunu girin veya QR okutun.', 'success');
        } else {
            show_message('Birinci aşama kaydınız alındı. Oturum aktif olduğunda ikinci aşamayı tamamlayabilirsiniz.', 'success');
        }
    }

} catch (Exception $e) {
    error_log('First Phase QR Error: ' . $e->getMessage());
    show_message($e->getMessage(), 'danger');
}

redirect('attendance.php');
exit;
?>
