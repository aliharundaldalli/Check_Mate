<?php
// AJAX endpoint for getting students of a course
header('Content-Type: application/json');

try {
    require_once '../../includes/functions.php';
    
    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Öğretmen yetkisi kontrolü
    if (!$auth->checkRole('teacher')) {
        throw new Exception('Yetkiniz bulunmamaktadır.');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    $teacher_id = $_SESSION['user_id'];
    $course_id = (int)($_GET['course_id'] ?? 0);
    
    if ($course_id <= 0) {
        throw new Exception('Geçersiz ders ID.');
    }
    
    // Öğretmenin bu dersi verdiğini kontrol et
    $query = "SELECT id FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        throw new Exception('Bu dersi verme yetkiniz bulunmamaktadır.');
    }
    
    // Dersin öğrencilerini al
    $query = "SELECT u.id, u.full_name, u.student_number
              FROM users u
              JOIN course_enrollments ce ON u.id = ce.student_id
              WHERE ce.course_id = :course_id AND ce.is_active = 1 AND u.user_type = 'student'
              ORDER BY u.full_name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}