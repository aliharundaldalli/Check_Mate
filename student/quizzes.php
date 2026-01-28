<?php
// student/quizzes.php - Öğrenci Sınavları

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

try {
    require_once '../includes/functions.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $auth = new Auth();
    if (!$auth->checkRole('student')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    
    $student_id = $_SESSION['user_id'];

} catch (Exception $e) {
    error_log('Student Quizzes Error: ' . $e->getMessage());
    die('Sistem hatası oluştu.');
}

// Öğrencinin derslerine ait sınavları getir
$quizzes = [];
try {
    // 1. Öğrencinin aktif derslerini bul
    // 2. Bu derslere ait aktif sınavları getir
    // 3. Öğrencinin bu sınavlara girip girmediğini kontrol et (LEFT JOIN submissions)
    
    $query = "SELECT q.*, c.course_name, c.course_code, 
                     qs.id as submission_id, qs.score, qs.status as submission_status, qs.completed_at
              FROM quizzes q
              JOIN courses c ON q.course_id = c.id
              JOIN course_enrollments ce ON c.id = ce.course_id
              LEFT JOIN quiz_submissions qs ON q.id = qs.quiz_id AND qs.student_id = :student_id
              WHERE ce.student_id = :student_id2 
              AND ce.is_active = 1 
              AND q.is_active = 1
              ORDER BY q.created_at DESC";
              
    $stmt = $db->prepare($query);
    $stmt->execute([':student_id' => $student_id, ':student_id2' => $student_id]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msg = $e->getMessage();
}

$page_title = "Sınavlarım - Check Mate";
include '../includes/components/student_header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 text-gray-800"><i class="fas fa-pen-nib me-2"></i>Sınavlarım</h1>
            <p class="text-muted">Kayıtlı olduğunuz derslerin sınavlarını buradan takip edebilirsiniz.</p>
        </div>
    </div>
    
    <?php if (isset($error_msg) && strpos($error_msg, 'Table') !== false): ?>
         <div class="alert alert-warning">Sınav sistemi şu anda bakımda.</div>
    <?php else: ?>

    <div class="row">
        <?php if (empty($quizzes)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center py-4">
                    <i class="fas fa-info-circle fa-2x mb-2"></i><br>
                    Şu an aktif bir sınavınız bulunmamaktadır.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($quizzes as $quiz): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($quiz['course_code']); ?></span>
                                <?php if ($quiz['submission_status'] == 'completed' || $quiz['submission_status'] == 'graded'): ?>
                                    <span class="badge bg-success">Tamamlandı</span>
                                <?php else: ?>
                                    <span class="badge bg-primary">Aktif</span>
                                <?php endif; ?>
                            </div>
                            
                            <h5 class="card-title fw-bold mb-1"><?php echo htmlspecialchars($quiz['title']); ?></h5>
                            <p class="text-muted small mb-3"><?php echo htmlspecialchars($quiz['course_name']); ?></p>
                            
                            <?php if ($quiz['description']): ?>
                                <p class="card-text text-muted mb-3 small">
                                    <?php echo htmlspecialchars(substr($quiz['description'], 0, 100)) . (strlen($quiz['description']) > 100 ? '...' : ''); ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="d-grid mt-auto">
                                <?php if ($quiz['submission_status'] == 'completed' || $quiz['submission_status'] == 'graded'): ?>
                                    <a href="quiz_result.php?id=<?php echo $quiz['submission_id']; ?>" class="btn btn-outline-success">
                                        <i class="fas fa-chart-pie me-1"></i> Sonucu Gör
                                    </a>
                                    <div class="text-center mt-2 small text-muted">
                                        Puan: <strong><?php echo $quiz['score']; ?></strong>
                                    </div>
                                <?php else: ?>
                                    <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-play me-1"></i> Sınava Başla
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include '../includes/components/shared_footer.php'; ?>
