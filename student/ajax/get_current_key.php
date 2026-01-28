<?php
// Timezone ayarı
date_default_timezone_set('Europe/Istanbul');

require_once '../../includes/functions.php';

// CORS başlıkları
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Session kontrolü
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Oturum kontrolü
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
        throw new Exception('Unauthorized');
    }
    
    $session_id = (int)($_GET['session_id'] ?? 0);
    $student_id = $_SESSION['user_id'];
    
    if ($session_id <= 0) {
        throw new Exception('Invalid session ID');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // MySQL timezone ayarı
    $db->exec("SET time_zone = '+03:00'");
    
    // Öğrencinin bu oturuma erişimi var mı kontrol et
    $query = "SELECT asess.id FROM attendance_sessions asess
              JOIN courses c ON asess.course_id = c.id
              JOIN course_enrollments ce ON c.id = ce.course_id
              WHERE asess.id = :session_id 
              AND ce.student_id = :student_id 
              AND asess.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        throw new Exception('Session not found or access denied');
    }
    
    // Güncel ikinci anahtarı al
    $query = "SELECT spk.key_code, spk.valid_from, spk.valid_until,
              UNIX_TIMESTAMP(spk.valid_until) as expiration_timestamp,
              UNIX_TIMESTAMP(NOW()) as current_timestamp,
              CASE 
                  WHEN spk.valid_from > NOW() THEN 'FUTURE'
                  WHEN spk.valid_until < NOW() THEN 'EXPIRED' 
                  ELSE 'CURRENT'
              END as status
              FROM second_phase_keys spk 
              WHERE spk.session_id = :session_id 
              ORDER BY spk.created_at DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
    $stmt->execute();
    $key_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($key_data) {
        $remaining_seconds = max(0, $key_data['expiration_timestamp'] - $key_data['current_timestamp']);
        
        echo json_encode([
            'success' => true,
            'key' => $key_data['key_code'],
            'status' => $key_data['status'],
            'valid_from' => $key_data['valid_from'],
            'valid_until' => $key_data['valid_until'],
            'remaining_seconds' => $remaining_seconds,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No key found',
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]);
    }
    
} catch (Exception $e) {
    error_log('Get current key error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ]);
}
?>