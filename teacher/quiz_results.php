<?php
// teacher/quiz_results.php - Sınav Sonuçları
error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('teacher')) {
    redirect('../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();
$teacher_id = $_SESSION['user_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('quizzes.php');
    exit;
}

$quiz_id = (int)$_GET['id'];

// Sınav Bilgisi ve Yetki Kontrolü
$quiz = [];
try {
    $stmt = $db->prepare("SELECT q.*, c.course_name, c.course_code FROM quizzes q JOIN courses c ON q.course_id = c.id WHERE q.id = :id AND q.created_by = :tid");
    $stmt->execute([':id' => $quiz_id, ':tid' => $teacher_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        show_message('Sınav bulunamadı veya erişim yetkiniz yok.', 'danger');
        redirect('quizzes.php');
        exit;
    }
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// Sonuçları Getir
$submissions = [];
try {
    $query = "SELECT qs.*, u.full_name, u.student_number, u.email 
              FROM quiz_submissions qs 
              JOIN users u ON qs.student_id = u.id 
              WHERE qs.quiz_id = :qid 
              ORDER BY qs.score DESC, qs.completed_at ASC";
    $stmtSub = $db->prepare($query);
    $stmtSub->execute([':qid' => $quiz_id]);
    $submissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// --- AI ANALİZ İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analyze_quiz'])) {
    require_once '../includes/ai_helper.php';

    try {
        // 1. Soruları Çek
        $stmtQ = $db->prepare("SELECT * FROM questions WHERE quiz_id = ?");
        $stmtQ->execute([$quiz_id]);
        $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        // 2. Tüm Cevapları Çek (Soru bazlı analiz için)
        // (Sadece bu sınavın submission'larına ait cevaplar)
        $stmtAns = $db->prepare("
            SELECT sa.question_id, sa.earned_points 
            FROM student_answers sa
            JOIN quiz_submissions qs ON sa.submission_id = qs.id
            WHERE qs.quiz_id = ?
        ");
        $stmtAns->execute([$quiz_id]);
        $allAnswers = $stmtAns->fetchAll(PDO::FETCH_ASSOC);

        // 3. AI Helper Çağır
        $analysisResult = generateQuizOverviewAnalysis($quiz, $questions, $submissions, $allAnswers);

        if (isset($analysisResult['error'])) {
            $error = "Analiz Hatası: " . $analysisResult['error'];
        } else {
            // 4. Sonucu Veritabanına Kaydet (JSON olarak saklayalım)
            $jsonAnalysis = json_encode($analysisResult, JSON_UNESCAPED_UNICODE);
            $stmtUpd = $db->prepare("UPDATE quizzes SET ai_analysis = ? WHERE id = ?");
            $stmtUpd->execute([$jsonAnalysis, $quiz_id]);
            
            // Sayfayı yenile
            header("Location: quiz_results.php?id=" . $quiz_id . "&analyzed=1");
            exit;
        }

    } catch (Exception $e) {
        $error = "İşlem hatası: " . $e->getMessage();
    }
}

$page_title = "Sınav Sonuçları: " . $quiz['title'];
include '../includes/components/teacher_header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="quizzes.php">Sınavlar</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Sonuçlar</li>
                </ol>
            </nav>
            <h1 class="h3 text-gray-800 mb-0">
                <i class="fas fa-chart-bar me-2"></i><?php echo htmlspecialchars($quiz['title']); ?> <small class="text-muted h6 mb-0">(<?php echo htmlspecialchars($quiz['course_code']); ?>)</small>
            </h1>
        </div>
        <div>
            <!-- AI Analiz Butonu -->
             <form method="POST" class="d-inline">
                <button type="submit" name="analyze_quiz" class="btn btn-purple text-white shadow-sm" onclick="return confirm('Tüm sonuçlar analiz edilip rapor oluşturulacak. Devam edilsin mi?');" style="background-color: #6f42c1;">
                    <i class="fas fa-magic me-2"></i> Genel Değerlendirme Raporu Oluştur (AI)
                </button>
            </form>
            <a href="quizzes.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left me-1"></i> Geri Dön
            </a>
        </div>
    </div>

    <?php if (isset($error)) display_message($error, 'danger'); ?>
    <?php if (isset($_GET['analyzed'])) display_message('Sınıf analizi başarıyla oluşturuldu.', 'success'); ?>

    <!-- AI ANALİZ RAPORU KARTI -->
    <?php if (!empty($quiz['ai_analysis'])): 
        $analysis = json_decode($quiz['ai_analysis'], true);
        if ($analysis):
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-lg border-start border-5 border-purple" style="border-left-color: #6f42c1 !important;">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold" style="color: #6f42c1;"><i class="fas fa-robot me-2"></i>AI Sınıf Analiz Raporu</h5>
                    <small class="text-muted">Son Güncelleme: <?php echo date('d.m.Y H:i'); // Kabaca ?></small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <h6 class="fw-bold text-dark"><i class="fas fa-quote-left me-2 text-muted"></i>Genel Özet</h6>
                            <p class="lead fs-6 text-secondary">
                                <?php 
                                    $val = $analysis['general_summary'] ?? '';
                                    if(is_array($val)) $val = implode("<br>", $val);
                                    echo nl2br(htmlspecialchars($val)); 
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="p-3 bg-success-subtle rounded h-100 border border-success-subtle">
                                <h6 class="fw-bold text-success"><i class="fas fa-check-circle me-2"></i>Güçlü Yönler</h6>
                                <p class="small mb-0 text-success-emphasis">
                                    <?php 
                                        $val = $analysis['strengths'] ?? '';
                                        if(is_array($val)) {
                                            echo '<ul class="mb-0 ps-3">';
                                            foreach($val as $v) echo '<li>' . htmlspecialchars($v) . '</li>';
                                            echo '</ul>';
                                        } else {
                                            echo nl2br(htmlspecialchars($val));
                                        }
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-danger-subtle rounded h-100 border border-danger-subtle">
                                <h6 class="fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Geliştirilmeli</h6>
                                <p class="small mb-0 text-danger-emphasis">
                                    <?php 
                                        $val = $analysis['weaknesses'] ?? '';
                                        if(is_array($val)) {
                                            echo '<ul class="mb-0 ps-3">';
                                            foreach($val as $v) echo '<li>' . htmlspecialchars($v) . '</li>';
                                            echo '</ul>';
                                        } else {
                                            echo nl2br(htmlspecialchars($val));
                                        }
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-info-subtle rounded h-100 border border-info-subtle">
                                <h6 class="fw-bold text-info"><i class="fas fa-lightbulb me-2"></i>Öğretmen Tavsiyesi</h6>
                                <p class="small mb-0 text-info-emphasis">
                                    <?php 
                                        $val = $analysis['recommendations'] ?? '';
                                        if(is_array($val)) {
                                            echo '<ul class="mb-0 ps-3">';
                                            foreach($val as $v) echo '<li>' . htmlspecialchars($v) . '</li>';
                                            echo '</ul>';
                                        } else {
                                            echo nl2br(htmlspecialchars($val));
                                        }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <div class="row align-items-center">
                <div class="col">
                    <h6 class="m-0 font-weight-bold text-primary">Öğrenci Performansı</h6>
                </div>
                <div class="col-auto">
                    <span class="badge bg-primary rounded-pill px-3"><?php echo count($submissions); ?> Gönderim</span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($submissions)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-users-slash fa-3x mb-3"></i>
                    <p>Henüz bu sınavı tamamlayan öğrenci bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="resultsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Öğrenci</th>
                                <th>Numara</th>
                                <th>Puan</th>
                                <th>Durum</th>
                                <th>Tamamlanma Tarihi</th>
                                <th class="text-end">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $sub): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; color: #4e73df;">
                                                <span class="fw-bold"><?php echo strtoupper(substr($sub['full_name'], 0, 1)); ?></span>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($sub['full_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($sub['email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($sub['student_number']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold fs-5 me-2 <?php echo $sub['score'] >= 50 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format($sub['score'], 1); ?>
                                            </span>
                                            <small class="text-muted">/ <?php echo $sub['total_points_possible']; ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($sub['status'] == 'graded'): ?>
                                            <span class="badge bg-success">Notlandırıldı</span>
                                        <?php elseif ($sub['status'] == 'completed'): ?>
                                            <span class="badge bg-warning text-dark">Beklemede</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($sub['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('d.m.Y H:i', strtotime($sub['completed_at'])); ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="view_submission.php?id=<?php echo $sub['id']; ?>" class="btn btn-sm btn-info text-white" title="Detaylı İnceleme">
                                            <i class="fas fa-search"></i> İncele
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
