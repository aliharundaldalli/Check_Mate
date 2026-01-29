<?php
// teacher/view_submission.php - Sınav Sonucu Detayı (Öğretmen)

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

$submission_id = (int)$_GET['id'];
$submission = [];
$answers = [];
$student = [];

try {
    // Submission & Quiz Details (Teacher check included via quiz created_by)
    $query = "SELECT qs.*, q.title, q.description, q.course_id, c.course_name, u.full_name, u.student_number, u.email
              FROM quiz_submissions qs
              JOIN quizzes q ON qs.quiz_id = q.id
              JOIN courses c ON q.course_id = c.id
              JOIN users u ON qs.student_id = u.id
              WHERE qs.id = :id AND q.created_by = :tid";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $submission_id, ':tid' => $teacher_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        die("Sonuç bulunamadı veya bu sınava erişim yetkiniz yok.");
    }

    // Answers Details
    $queryA = "SELECT sa.*, q.question_text, q.question_type, q.correct_answer, q.options, q.points as max_points
               FROM student_answers sa
               JOIN questions q ON sa.question_id = q.id
               WHERE sa.submission_id = :sid
               ORDER BY q.order ASC";
    $stmtA = $db->prepare($queryA);
    $stmtA->execute([':sid' => $submission_id]);
    $answers = $stmtA->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Hata: " . $e->getMessage());
}

$page_title = "Öğrenci Sonucu: " . $submission['full_name'];
include '../includes/components/teacher_header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                     <h1 class="h3 text-gray-800 mb-0"><?php echo htmlspecialchars($submission['full_name']); ?></h1>
                     <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['student_number']); ?> - <?php echo htmlspecialchars($submission['title']); ?></p>
                </div>
                <div>
                    <a href="quiz_results.php?id=<?php echo $submission['quiz_id']; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Listeye Dön
                    </a>
                </div>
            </div>

            <!-- Overall Score Card -->
            <div class="card shadow mb-4 border-start border-5 border-info">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                             <div class="mb-3">
                                <span class="badge bg-secondary me-2"><?php echo htmlspecialchars($submission['course_name']); ?></span>
                                <span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i> Tamamlandı: <?php echo date('d.m.Y H:i', strtotime($submission['completed_at'])); ?></span>
                             </div>
                            
                            <?php if ($submission['ai_general_feedback']): ?>
                                <div class="alert alert-info bg-light border-0 mb-0">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="text-info m-0"><i class="fas fa-robot me-2"></i>AI Genel Değerlendirmesi</h6>
                                        <button class="btn btn-sm btn-outline-info" onclick="refreshGeneralFeedback(<?php echo $submission['id']; ?>)" id="refresh-feedback-btn">
                                            <i class="fas fa-sync-alt me-1"></i> Yenile
                                        </button>
                                    </div>
                                    <p class="mb-2 text-dark" id="ai-general-feedback-text"><?php echo nl2br(htmlspecialchars($submission['ai_general_feedback'])); ?></p>
                                    <hr class="my-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Öğretmen Araçları:</strong> Aşağıdaki sorularda elle puanlama yapabilir veya açık uçlu soruları AI ile yeniden değerlendirebilirsiniz.
                                    </small>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary bg-light border-0 mb-0">
                                    <h6 class="text-secondary"><i class="fas fa-tools me-2"></i>Öğretmen Araçları</h6>
                                    <p class="mb-0 text-dark small">
                                        <i class="fas fa-check-circle text-success me-1"></i> Her sorunun puanını manuel olarak düzenleyebilirsiniz.<br>
                                        <i class="fas fa-robot text-warning me-1"></i> Açık uçlu soruları AI ile yeniden değerlendirebilirsiniz.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-center mt-3 mt-md-0">
                            <div class="d-inline-block p-4 rounded-circle bg-light border border-3 border-info position-relative" style="width: 150px; height: 150px; display: flex; align-items: center; justify-content: center;">
                                <div>
                                    <div class="h2 mb-0 fw-bold text-info"><?php echo $submission['score']; ?></div>
                                    <div class="small text-muted">/ <?php echo $submission['total_points_possible']; ?></div>
                                </div>
                            </div>
                            <div class="mt-2 fw-bold text-uppercase ls-1">Toplam Puan</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Question Details -->
            <h4 class="mb-3">Detaylı Cevap Analizi</h4>
            
            <?php foreach ($answers as $index => $ans): ?>
                <?php 
                    $isCorrect = $ans['is_correct'];
                    $cardClass = $isCorrect ? 'border-success' : 'border-danger';
                    $bgClass = $isCorrect ? 'bg-success-subtle' : 'bg-danger-subtle'; // Bootstrap 5.3
                    if ($ans['earned_points'] > 0 && $ans['earned_points'] < $ans['max_points']) {
                         $cardClass = 'border-warning'; // Partial credit
                         $bgClass = 'bg-warning-subtle';
                    }
                ?>
                <div class="card mb-3 shadow-sm <?php echo $cardClass; ?>" style="border-width: 1px; border-left-width: 5px;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <h6 class="card-title fw-bold">Soru <?php echo $index + 1; ?></h6>
                            <span class="badge <?php echo $isCorrect ? 'bg-success' : ($ans['earned_points'] > 0 ? 'bg-warning' : 'bg-danger'); ?>">
                                <?php echo $ans['earned_points']; ?> / <?php echo $ans['max_points']; ?> Puan
                            </span>
                        </div>
                        
                        <p class="card-text mb-3"><?php echo htmlspecialchars($ans['question_text']); ?></p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <strong class="d-block small text-muted text-uppercase">Öğrenci Cevabı</strong>
                                <div class="p-2 rounded bg-light border mb-2">
                                    <?php 
                                        if ($ans['question_type'] == 'multiple_select' || is_array(json_decode($ans['answer_text']))) {
                                             $decoded = json_decode($ans['answer_text'], true);
                                             echo htmlspecialchars(implode(', ', is_array($decoded) ? $decoded : [$ans['answer_text']]));
                                        } else {
                                             echo nl2br(htmlspecialchars($ans['answer_text'])); 
                                        }
                                    ?>
                                </div>
                            </div>
                            <?php if ($ans['question_type'] == 'multiple_choice' || $ans['question_type'] == 'multiple_select'): ?>
                            <div class="col-md-6">
                                <strong class="d-block small text-muted text-uppercase">Doğru Cevap</strong>
                                <div class="p-2 rounded bg-light border mb-2 text-dark">
                                    <?php 
                                         $correct = $ans['correct_answer'];
                                         if ($ans['question_type'] == 'multiple_select') {
                                             $cArr = json_decode($correct, true);
                                             echo htmlspecialchars(implode(', ', is_array($cArr) ? $cArr : [$correct]));
                                         } else {
                                             echo htmlspecialchars($correct); 
                                         }
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($ans['ai_feedback']): ?>
                            <div class="mt-2 p-2 rounded <?php echo $bgClass; ?>">
                                <small class="fw-bold"><i class="fas fa-comment-dots me-1"></i>Değerlendirme Notu:</small><br>
                                <?php echo nl2br(htmlspecialchars($ans['ai_feedback'])); ?>
                            </div>
                        <?php endif; ?>

                        <!-- ELLE PUANLAMA VE AI YENİDEN DEĞERLENDİRME -->
                        <div class="mt-3 p-3 bg-light rounded border">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold mb-1">Elle Puanlama</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" 
                                               class="form-control" 
                                               id="score-<?php echo $ans['id']; ?>" 
                                               min="0" 
                                               max="<?php echo $ans['max_points']; ?>" 
                                               step="0.01"
                                               value="<?php echo $ans['earned_points']; ?>">
                                        <span class="input-group-text">/ <?php echo $ans['max_points']; ?></span>
                                        <button class="btn btn-primary" 
                                                onclick="updateScore(<?php echo $ans['id']; ?>, <?php echo $ans['max_points']; ?>)">
                                            <i class="fas fa-save"></i> Kaydet
                                        </button>
                                    </div>
                                </div>
                                
                                <?php if ($ans['question_type'] == 'text'): ?>
                                <div class="col-md-6 mt-2 mt-md-0">
                                    <label class="form-label small fw-bold mb-1">AI Değerlendirme</label>
                                    <button class="btn btn-warning btn-sm w-100" 
                                            id="ai-btn-<?php echo $ans['id']; ?>"
                                            onclick="reEvaluateQuestion(<?php echo $ans['id']; ?>)">
                                        <i class="fas fa-robot"></i> AI ile Yeniden Değerlendir
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="col-md-6 mt-2 mt-md-0">
                                    <small class="text-muted">AI değerlendirme sadece açık uçlu sorular için kullanılabilir.</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateScore(answerId, maxPoints) {
    const scoreInput = document.getElementById('score-' + answerId);
    const newScore = parseFloat(scoreInput.value);
    
    // Validasyon
    if (isNaN(newScore) || newScore < 0 || newScore > maxPoints) {
        alert('Puan 0 ile ' + maxPoints + ' arasında olmalı!');
        return;
    }
    
    if (!confirm('Puanı ' + newScore + ' olarak güncellemek istediğinizden emin misiniz?')) {
        return;
    }
    
    // AJAX isteği
    fetch('update_answer_score.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({answer_id: answerId, score: newScore})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Puan güncellendi!\nYeni Toplam Puan: ' + data.total_score);
            location.reload();
        } else {
            alert('Hata: ' + data.message);
        }
    })
    .catch(error => {
        alert('Bir hata oluştu: ' + error);
    });
}

function reEvaluateQuestion(answerId) {
    if (!confirm('Bu soru AI tarafından yeniden değerlendirilecek. Mevcut puan ve geri bildirim değişecektir. Devam edilsin mi?')) {
        return;
    }
    
    // Loading göster
    const btn = document.getElementById('ai-btn-' + answerId);
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Değerlendiriliyor...';
    
    // AJAX isteği
    fetch('reevaluate_single_question.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({answer_id: answerId})
    })
    .then(response => response.json())
    .then(data => {
        console.log('AI Response:', data); // Debug için console'a yaz
        
        if (data.success) {
            alert('AI Değerlendirmesi Tamamlandı!\n\nYeni Puan: ' + data.new_score + '\nYeni Toplam: ' + data.total_score + '\n\nGeri Bildirim: ' + data.feedback);
            location.reload();
        } else {
            // Hata mesajını hem alert hem console'da göster
            console.error('AI Error:', data.message);
            alert('Hata: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
        alert('Bir hata oluştu: ' + error);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

function refreshGeneralFeedback(submissionId) {
    const btn = document.getElementById('refresh-feedback-btn');
    const originalHTML = btn.innerHTML;
    const textZone = document.getElementById('ai-general-feedback-text');
    
    if (!confirm('Genel değerlendirme raporu, mevcut güncel puanlara göre yeniden oluşturulacak. Devam edilsin mi?')) {
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    fetch('reevaluate_submission_feedback.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({submission_id: submissionId})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            textZone.innerHTML = data.feedback.replace(/\n/g, '<br>');
            alert('Genel değerlendirme güncellendi!');
        } else {
            alert('Hata: ' + data.message);
        }
    })
    .catch(error => {
        alert('Bir hata oluştu: ' + error);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}
</script>
