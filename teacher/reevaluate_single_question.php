<?php
/**
 * Re-evaluate Single Question with AI
 * Send a single question to AI for re-evaluation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1); // Geçici olarak açıldı
date_default_timezone_set('Europe/Istanbul');

// Output buffering - beklenmeyen çıktıları yakala
ob_start();

require_once '../includes/functions.php';
require_once '../includes/ai_helper.php';

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

if (!isset($input['answer_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
    exit;
}

$answer_id = (int)$input['answer_id'];

try {
    // Get answer details and verify teacher ownership
    $stmt = $db->prepare("
        SELECT sa.*, q.question_text, q.question_type, q.correct_answer, q.points as max_points, 
               qs.quiz_id, qs.id as submission_id, quiz.created_by
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
    
    // Only re-evaluate text questions (open-ended)
    if ($answer['question_type'] !== 'text') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Sadece açık uçlu (text) sorular AI ile değerlendirilebilir']);
        exit;
    }
    
    // Call AI evaluation
    $evaluation = evaluateOpenEndedAnswer(
        $answer['question_text'],
        $answer['correct_answer'], // Expected answer/rubric
        $answer['answer_text'],     // Student's answer
        $answer['max_points']
    );
    
    if (isset($evaluation['error'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'AI değerlendirme hatası: ' . $evaluation['error']]);
        exit;
    }
    
    $new_score = $evaluation['score'] ?? 0;
    $feedback = $evaluation['feedback'] ?? 'Değerlendirme tamamlandı.';
    
    // Update answer with new AI evaluation
    $stmt = $db->prepare("
        UPDATE student_answers 
        SET earned_points = :score, ai_feedback = :feedback 
        WHERE id = :aid
    ");
    $stmt->execute([
        ':score' => $new_score, 
        ':feedback' => $feedback,
        ':aid' => $answer_id
    ]);
    
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
        'message' => 'AI değerlendirmesi tamamlandı',
        'new_score' => $new_score,
        'feedback' => $feedback,
        'total_score' => $total_score
    ]);
    
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
}

// Output buffer'ı flush et
ob_end_flush();
?>
