<?php
/**
 * Re-evaluate General Submission Feedback with AI
 * Updates the overall AI feedback for a submission based on current scores
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Istanbul');

// Output buffering
ob_start();

require_once '../includes/functions.php';
require_once '../includes/ai_helper.php';

// Header
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

if (!isset($input['submission_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
    exit;
}

$submission_id = (int)$input['submission_id'];

try {
    // 1. Get submission and quiz details (verify ownership)
    $stmt = $db->prepare("
        SELECT qs.id, qs.quiz_id, quiz.title, quiz.created_by
        FROM quiz_submissions qs
        JOIN quizzes quiz ON qs.quiz_id = quiz.id
        WHERE qs.id = :sid AND quiz.created_by = :tid
    ");
    $stmt->execute([':sid' => $submission_id, ':tid' => $teacher_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Gönderim bulunamadı veya yetkiniz yok']);
        exit;
    }
    
    // 2. Get all answers for this submission details for AI context
    $stmt = $db->prepare("
        SELECT sa.earned_points, q.points as max_points, q.question_text
        FROM student_answers sa
        JOIN questions q ON sa.question_id = q.id
        WHERE sa.submission_id = :sid
    ");
    $stmt->execute([':sid' => $submission_id]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($answers)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cevap bulunamadı']);
        exit;
    }
    
    // 3. Call AI to generate general feedback
    $result = generateSubmissionFeedback($submission['title'], $answers);
    
    if (isset($result['error'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'AI Hatası: ' . $result['error']]);
        exit;
    }
    
    $newBoard = $result['feedback'];
    
    // 4. Update database
    $stmt = $db->prepare("UPDATE quiz_submissions SET ai_general_feedback = :feed WHERE id = :sid");
    $stmt->execute([':feed' => $newBoard, ':sid' => $submission_id]);
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Genel değerlendirme güncellendi',
        'feedback' => $newBoard
    ]);
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
}

ob_end_flush();
?>
