<?php
session_start();
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Öğretmen yetkisi kontrolü
$auth = new Auth();
if (!$auth->checkRole('teacher')) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$session_id = (int)($_GET['session_id'] ?? 0);
$teacher_id = $_SESSION['user_id'];

// Oturum kontrolü
$query = "SELECT id FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
$stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
$stmt->execute();

if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// İstatistikleri al
$query = "SELECT 
          COUNT(ar.id) as total_attendees,
          COUNT(CASE WHEN ar.second_phase_completed = 1 THEN 1 END) as completed_attendees,
          COUNT(CASE WHEN ar.second_phase_completed = 0 THEN 1 END) as pending_attendees,
          (SELECT COUNT(*) FROM course_enrollments ce 
           JOIN attendance_sessions asess ON asess.course_id = ce.course_id 
           WHERE asess.id = :session_id1 AND ce.is_active = 1) as total_students
          FROM attendance_records ar
          WHERE ar.session_id = :session_id2";
$stmt = $db->prepare($query);
$stmt->bindParam(':session_id1', $session_id, PDO::PARAM_INT);
$stmt->bindParam(':session_id2', $session_id, PDO::PARAM_INT);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Tamamlanma oranını hesapla
$stats['completion_rate'] = $stats['total_students'] > 0 
    ? round(($stats['completed_attendees'] / $stats['total_students']) * 100, 1) 
    : 0;

// Katılımcıları al
$query = "SELECT u.full_name, u.student_number, ar.attendance_time, ar.second_phase_completed,
          fpk.key_code as used_first_key
          FROM attendance_records ar
          JOIN users u ON ar.student_id = u.id
          LEFT JOIN first_phase_keys fpk ON ar.first_phase_key_id = fpk.id
          WHERE ar.session_id = :session_id
          ORDER BY ar.attendance_time DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
$stmt->execute();
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aktif ikinci anahtarı kontrol et
$query = "SELECT key_code, valid_until FROM second_phase_keys 
          WHERE session_id = :session_id AND valid_until > NOW()
          ORDER BY created_at DESC LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
$stmt->execute();
$current_key = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'stats' => $stats,
    'attendees' => $attendees,
    'current_key' => $current_key
]);
?>
