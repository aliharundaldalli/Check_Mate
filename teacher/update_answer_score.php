<?php
/**
 * Update Answer Score - Manual Grading
 * Teacher can manually update a student's answer score
 */

error_reporting(E_ALL);
ini_set('display_errors', 1); // Geçici olarak açıldı
date_default_timezone_set('Europe/Istanbul');

// Output buffering - beklenmeyen çıktıları yakala
ob_start();

require_once '../includes/functions.php';

// Header'ı erken gönder
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('teacher')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$teacher_id = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['answer_id']) || !isset($input['score'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
    exit;
}

$answer_id = (int)$input['answer_id'];
$new_score = (float)$input['score'];

try {
    // Get answer details and verify teacher ownership
    $stmt = $db->prepare("
        SELECT sa.*, q.points as max_points, qs.quiz_id, qs.id as submission_id, quiz.created_by
        FROM student_answers sa
        JOIN quiz_submissions qs ON sa.submission_id = qs.id
        JOIN questions q ON sa.question_id = q.id
        JOIN quizzes quiz ON qs.quiz_id = quiz.id
        WHERE sa.id = :aid AND quiz.created_by = :tid
    ");
    $stmt->execute([':aid' => $answer_id, ':tid' => $teacher_id]);
    $answer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$answer) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cevap bulunamadı veya yetkiniz yok']);
        exit;
    }
    
    // Validate score
    if ($new_score < 0 || $new_score > $answer['max_points']) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Puan 0 ile ' . $answer['max_points'] . ' arasında olmalı']);
        exit;
    }
    
    // Update answer score
    $stmt = $db->prepare("UPDATE student_answers SET earned_points = :score WHERE id = :aid");
    $stmt->execute([':score' => $new_score, ':aid' => $answer_id]);
    
    // Recalculate total score for submission
    $stmt = $db->prepare("
        SELECT SUM(earned_points) as total_score 
        FROM student_answers 
        WHERE submission_id = :sid
    ");
    $stmt->execute([':sid' => $answer['submission_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_score = $result['total_score'] ?? 0;
    
    // Update submission total score
    $stmt = $db->prepare("UPDATE quiz_submissions SET score = :score WHERE id = :sid");
    $stmt->execute([':score' => $total_score, ':sid' => $answer['submission_id']]);
    
    
    // Beklenmeyen çıktıları temizle
    ob_clean();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Puan güncellendi',
        'new_score' => $new_score,
        'total_score' => $total_score
    ]);
    
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
}

// Output buffer'ı flush et
ob_end_flush();
?>
