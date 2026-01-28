<?php
// student/join_session_qr.php - QR Kod ile Yoklama
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
        // Giriş yapmamışsa, login sayfasına yönlendir
        $current_url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $_SESSION['redirect_after_login'] = $current_url;
        
        show_message('QR kod ile yoklama vermek için önce giriş yapmalısınız.', 'warning');
        redirect('../login.php');
        exit;
    }
    
    // Öğrenci yetkisi kontrolü (opsiyonel - öğretmenler de QR okutabilir mi?)
    // if (!$auth->checkRole('student')) {
    //     show_message('Bu işlem yalnızca öğrenciler içindir.', 'danger');
    //     redirect('../index.php');
    //     exit;
    // }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    $db->exec("SET time_zone = '+03:00'");
    
    $student_id = $_SESSION['user_id'];
    
    // 2. Parametre Kontrolü (s = session_id, k = key - ModSecurity bypass için kısa isimler)
    $session_id = isset($_GET['s']) ? (int)$_GET['s'] : (isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0);
    $key_code = isset($_GET['k']) ? trim($_GET['k']) : (isset($_GET['key']) ? trim($_GET['key']) : '');
    
    if ($session_id <= 0 || empty($key_code)) {
        show_message('Geçersiz QR kod bağlantısı.', 'danger');
        redirect('attendance.php');
        exit;
    }
    
    // 3. Oturum ve Anahtar Doğrulama
    
    // A) Oturum aktif mi?
    $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Yoklama oturumu bulunamadı.');
    }
    
    // B) Geçerli bir ikinci aşama anahtarı mı?
    $stmt = $db->prepare("SELECT * FROM second_phase_keys WHERE session_id = ? AND key_code = ? AND valid_until > NOW()");
    $stmt->execute([$session_id, $key_code]);
    $validKey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$validKey) {
        throw new Exception('QR kodun süresi dolmuş veya geçersiz. Lütfen yenisini okutun.');
    }

    // 4. Yoklama Kaydı
    
    // Daha önce kaydı var mı? (Birinci anahtar girilmiş mi?)
    $stmt = $db->prepare("SELECT * FROM attendance_records WHERE session_id = ? AND student_id = ?");
    $stmt->execute([$session_id, $student_id]);
    $existing_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_record) {
        // Birinci anahtar girilmemiş - QR ile direkt yoklama verilemez
        throw new Exception('Önce ders anahtarını (birinci anahtar) girmelisiniz. QR kod sadece ikinci aşama içindir.');
    }
    
    // Birinci aşama var, şimdi ikinci aşamayı kontrol et
    if ($existing_record['second_phase_completed']) {
        show_message('Bu ders için yoklamanız zaten alınmış.', 'info');
    } else {
        // İkinci aşamayı tamamla (QR ile)
        $stmt = $db->prepare("UPDATE attendance_records SET second_phase_completed = 1, attendance_time = NOW() WHERE id = ?");
        $stmt->execute([$existing_record['id']]);
        show_message('Yoklama işleminiz tamamlandı. (QR ile)', 'success');
    }

} catch (Exception $e) {
    error_log('QR Attendance Error: ' . $e->getMessage());
    show_message($e->getMessage(), 'danger');
}

redirect('attendance.php');
exit;
?>
