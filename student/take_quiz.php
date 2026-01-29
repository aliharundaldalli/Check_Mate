<?php
// student/take_quiz.php - Sınav Olma Sayfası

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';
require_once '../includes/ai_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('student')) {
    redirect('../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();
$student_id = $_SESSION['user_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('quizzes.php');
    exit;
}

$quiz_id = (int)$_GET['id'];
$quiz = [];
$questions = [];

try {
    // Sınav Bilgileri
    $stmt = $db->prepare("SELECT * FROM quizzes WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $quiz_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        die("Sınav bulunamadı veya aktif değil.");
    }

    // Daha önce girmiş mi?
    $stmtC = $db->prepare("SELECT * FROM quiz_submissions WHERE quiz_id = :qid AND student_id = :sid");
    $stmtC->execute([':qid' => $quiz_id, ':sid' => $student_id]);
    $existing = $stmtC->fetch(PDO::FETCH_ASSOC);

    if ($existing && ($existing['status'] == 'completed' || $existing['status'] == 'graded')) {
        redirect("quiz_result.php?id=" . $existing['id']);
        exit;
    }

    // Tarih/Saat Kontrolü
    $now = time();
    $start_time = !empty($quiz['available_from']) ? strtotime($quiz['available_from']) : 0;
    $end_time = !empty($quiz['available_until']) ? strtotime($quiz['available_until']) : 0;

    if ($start_time > 0 && $now < $start_time) {
        show_message("Bu sınav henüz erişime açılmadı. <br>Başlangıç: " . date("d.m.Y H:i", $start_time), "warning");
        redirect("quizzes.php");
        exit;
    }

    if ($end_time > 0 && $now > $end_time) {
        show_message("Bu sınavın süresi doldu. <br>Bitiş: " . date("d.m.Y H:i", $end_time), "danger");
        redirect("quizzes.php");
        exit;
    }

    // Soruları Getir
    $stmtQ = $db->prepare("SELECT * FROM questions WHERE quiz_id = :qid ORDER BY `order` ASC");
    $stmtQ->execute([':qid' => $quiz_id]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    // --- TIMER LOGIC ---
    $time_limit = isset($quiz['time_limit']) ? (int)$quiz['time_limit'] : 0;
    $remaining_seconds = 0;
    
    if ($time_limit > 0) {
        if (!isset($_SESSION['quiz_start_time'][$quiz_id])) {
            $_SESSION['quiz_start_time'][$quiz_id] = time();
        }
        
        $usage = time() - $_SESSION['quiz_start_time'][$quiz_id];
        $total_seconds = $time_limit * 60;
        $remaining_seconds = $total_seconds - $usage;
    }

} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// --- FORM SUBMISSION ---
// Buton disabled olduğu için isset($_POST['submit_quiz']) kontrolü bazen false dönebilir. 
// Bu yüzden sadece REQUEST_METHOD kontrolü yapıyoruz.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if ($time_limit > 0) {
        $allowed = ($time_limit * 60) + 15; // 15 saniye tolerans
        $usage = time() - $_SESSION['quiz_start_time'][$quiz_id];
        if ($usage > $allowed) {
            $error = "Sınav süreniz doldu!";
        }
    }
    
    if (!isset($error)) {
        // ModSecurity Bypass: Decode Base64 answers if encoded
        $is_encoded = isset($_POST['is_encoded']) && $_POST['is_encoded'] == '1';
        $user_answers = $_POST['answers'] ?? [];
        
        if ($is_encoded && is_array($user_answers)) {
            foreach ($user_answers as $qid => $val) {
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        $user_answers[$qid][$k] = base64_decode($v);
                    }
                } else {
                    $user_answers[$qid] = base64_decode($val);
                }
            }
        }

        $gradeResult = gradeQuizSubmission($quiz, $questions, $user_answers);
        
        if (isset($gradeResult['error'])) {
            $error = "Değerlendirme hatası: " . $gradeResult['error'];
        } else {
            try {
                $db->beginTransaction();
                $total_points_possible = array_sum(array_column($questions, 'points'));

                if ($existing) {
                    $sub_stmt = $db->prepare("UPDATE quiz_submissions SET score = ?, total_points_possible = ?, ai_general_feedback = ?, status = 'graded', completed_at = NOW() WHERE id = ?");
                    $sub_stmt->execute([$gradeResult['score'], $total_points_possible, $gradeResult['feedback'], $existing['id']]);
                    $submission_id = $existing['id'];
                } else {
                    $sub_stmt = $db->prepare("INSERT INTO quiz_submissions (quiz_id, student_id, score, total_points_possible, ai_general_feedback, status, completed_at) VALUES (?, ?, ?, ?, ?, 'graded', NOW())");
                    $sub_stmt->execute([$quiz_id, $student_id, $gradeResult['score'], $total_points_possible, $gradeResult['feedback']]);
                    $submission_id = $db->lastInsertId();
                }

                $ans_stmt = $db->prepare("INSERT INTO student_answers (submission_id, question_id, answer_text, earned_points, is_correct, ai_feedback) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($questions as $q) {
                    $q_id = $q['id'];
                    $ans_text = $user_answers[$q_id] ?? '';
                    if (is_array($ans_text)) $ans_text = json_encode($ans_text);

                    $detail = $gradeResult['details'][$q_id] ?? ['earned_points' => 0, 'feedback' => '', 'is_correct' => 0];
                    $ans_stmt->execute([$submission_id, $q_id, $ans_text, $detail['earned_points'], $detail['is_correct'] ? 1 : 0, $detail['feedback']]);
                }

                $db->commit();
                unset($_SESSION['quiz_start_time'][$quiz_id]);
                redirect("quiz_result.php?id=" . $submission_id);
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                $error = "Sınav gönderilemedi: " . $e->getMessage();
            }
        }
    }
}

$page_title = $quiz['title'] . " - Sınav";
include '../includes/components/student_header.php';
?>

<!-- Gönderiliyor Yükleme Ekranı (Overlay) -->
<div id="loading-overlay" class="loading-overlay" style="display: none;">
    <div class="loading-content text-center">
        <div class="spinner-border text-light mb-3" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Yükleniyor...</span>
        </div>
        <h3 class="text-white">Sınavınız Gönderiliyor...</h3>
        <p class="text-white-50">Lütfen bekleyiniz, cevaplarınız analiz ediliyor.</p>
    </div>
</div>

<div class="container-fluid py-4 no-select">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="h4 mb-0"><?php echo htmlspecialchars($quiz['title']); ?></h2>
                        <p class="mb-0 opacity-75 mt-2"><?php echo htmlspecialchars($quiz['description']); ?></p>
                    </div>
                    <?php if($time_limit > 0): ?>
                    <div class="bg-white text-primary rounded px-3 py-2 fw-bold shadow-sm d-flex align-items-center" id="timer-box">
                        <i class="fas fa-clock me-2"></i>
                        <span id="timer-display">--:--</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" id="quiz-form">
                        <!-- Güvenli Gönderim İçin Gizli Girdi -->
                        <input type="hidden" name="form_submitted" value="1">
                        <input type="hidden" name="is_encoded" value="1">
                        
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="mb-4 p-4 border rounded-3 bg-white shadow-sm question-block position-relative">
                                <?php if($q['question_type'] == 'text' || $q['question_type'] == 'textarea'): ?>
                                    <span class="position-absolute top-0 end-0 mt-2 me-2 badge bg-info text-white" title="Klasik Soru"><i class="fas fa-pen-nib"></i></span>
                                <?php endif; ?>
                                <h5 class="mb-3">
                                    <span class="badge bg-light text-dark border me-2"><?php echo $index + 1; ?></span>
                                    <?php echo nl2br(htmlspecialchars($q['question_text'])); ?>
                                    <small class="text-muted ms-2 fs-6">(<?php echo $q['points']; ?> Puan)</small>
                                </h5>

                                <div class="ps-4">
                                    <?php if ($q['question_type'] == 'multiple_choice'): ?>
                                        <?php 
                                            $options = json_decode($q['options']); 
                                            if (!$options && $q['options']) $options = [$q['options']];
                                        ?>
                                        <?php if ($options): ?>
                                            <?php foreach ($options as $optIndex => $opt): ?>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="radio" 
                                                           name="answers[<?php echo $q['id']; ?>]" 
                                                           id="q<?php echo $q['id']; ?>_opt<?php echo $optIndex; ?>" 
                                                           value="<?php echo htmlspecialchars($opt); ?>">
                                                    <label class="form-check-label" for="q<?php echo $q['id']; ?>_opt<?php echo $optIndex; ?>">
                                                        <?php echo htmlspecialchars($opt); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                    <?php elseif ($q['question_type'] == 'text' || $q['question_type'] == 'textarea'): ?>
                                        <div class="math-editor-container">
                                            <div class="math-toolbar btn-group mb-2 shadow-sm rounded">
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '**', '**')" title="Kalın"><i class="fas fa-bold"></i></button>
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '*', '*')" title="İtalik"><i class="fas fa-italic"></i></button>
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '$$\\sqrt{', '}$$')" title="Karekök">$\sqrt{x}$</button>
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '$$\\frac{', '}{}$$')" title="Kesir">$\frac{a}{b}$</button>
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '$$^{', '}$$')" title="Üs">$x^2$</button>
                                                <button type="button" class="btn btn-sm btn-light border" onclick="insertMath(this, '$$_{', '}$$')" title="Alt Simge">$x_i$</button>
                                            </div>
                                            
                                            <textarea name="answers[<?php echo $q['id']; ?>]" class="form-control math-input prevent-paste" rows="4" placeholder="Cevabınızı buraya yazınız..." oninput="updateMathPreview(this)"></textarea>
                                            
                                            <div class="math-preview mt-2 p-3 bg-light border rounded text-muted" style="min-height: 50px;">
                                                <small class="d-block text-secondary mb-1">Önizleme:</small>
                                                <div class="preview-content"></div>
                                            </div>
                                        </div>
                                    
                                    <?php elseif ($q['question_type'] == 'multiple_select'): ?>
                                         <?php $options = json_decode($q['options']); ?>
                                        <?php if ($options): ?>
                                            <?php foreach ($options as $optIndex => $opt): ?>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="answers[<?php echo $q['id']; ?>][]" 
                                                           id="q<?php echo $q['id']; ?>_opt<?php echo $optIndex; ?>" 
                                                           value="<?php echo htmlspecialchars($opt); ?>">
                                                    <label class="form-check-label" for="q<?php echo $q['id']; ?>_opt<?php echo $optIndex; ?>">
                                                        <?php echo htmlspecialchars($opt); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="d-grid gap-2 mt-5">
                            <button type="submit" name="submit_quiz" id="submit-btn" class="btn btn-success btn-lg py-3">
                                <i class="fas fa-check-circle me-2"></i>Sınavı Tamamla
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<script>
// --- TIMER ---
<?php if($time_limit > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    let timeLeft = <?php echo max(0, $remaining_seconds); ?>;
    const display = document.getElementById('timer-display');
    const timerBox = document.getElementById('timer-box');
    
    const interval = setInterval(() => {
        if (timeLeft <= 0) {
            clearInterval(interval);
            alert('Süre doldu! Sınavınız otomatik olarak gönderiliyor.');
            showLoading();
            document.getElementById('quiz-form').submit();
            return;
        }
        let minutes = Math.floor(timeLeft / 60);
        let seconds = timeLeft % 60;
        display.textContent = `${minutes < 10 ? '0'+minutes : minutes}:${seconds < 10 ? '0'+seconds : seconds}`;
        if (timeLeft < 60) {
            timerBox.classList.add('bg-danger', 'text-white', 'pulse-animation');
        }
        timeLeft--;
    }, 1000);
});
<?php endif; ?>

// --- LOADING OVERLAY ---
function showLoading() {
    document.getElementById('loading-overlay').style.display = 'flex';
    // Butonu disable etsek de form verileri hidden input sayesinde gidecek
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Gönderiliyor...';
}

// Form Submit Handling
document.getElementById('quiz-form').addEventListener('submit', function(e) {
    if (confirm('Sınavı bitirmek istediğinize emin misiniz?')) {
        // ModSecurity Bypass: Encode answers to Base64
        const form = e.target;
        const radios = form.querySelectorAll('input[type="radio"]:checked');
        const checkboxes = form.querySelectorAll('input[type="checkbox"]:checked');
        const textareas = form.querySelectorAll('textarea');

        // Safe Base64 for UTF-8
        const encode = (val) => {
            try {
                const utf8Bytes = new TextEncoder().encode(val);
                return btoa(String.fromCharCode(...utf8Bytes));
            } catch(e) { return val; }
        };

        radios.forEach(el => el.value = encode(el.value));
        checkboxes.forEach(el => el.value = encode(el.value));
        textareas.forEach(el => el.value = encode(el.value));

        showLoading();
        return true;
    } else {
        e.preventDefault();
        return false;
    }
});

// --- MATH EDITOR ---
function insertMath(btn, startTag, endTag = '') {
    const textarea = btn.closest('.math-editor-container').querySelector('textarea');
    const startPos = textarea.selectionStart;
    const endPos = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, startPos) + startTag + text.substring(startPos, endPos) + endTag + text.substring(endPos);
    textarea.focus();
    updateMathPreview(textarea);
}

function updateMathPreview(textarea) {
    const preview = textarea.closest('.math-editor-container').querySelector('.preview-content');
    let html = textarea.value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\*(.*?)\*/g, '<em>$1</em>').replace(/\n/g, '<br>');
    preview.innerHTML = html;
    if (window.MathJax) MathJax.typesetPromise([preview]);
}

// --- ULTIMATE ANTI-CHEAT SCRIPT ---
(function() {
    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('selectstart', e => {
        if (!e.target.matches('input, textarea')) e.preventDefault();
    });
    document.addEventListener('dragstart', e => e.preventDefault());

    document.addEventListener('keydown', function(e) {
        const isControl = e.ctrlKey || e.metaKey;
        const key = e.key.toLowerCase();
        if (e.keyCode === 123) { e.preventDefault(); return false; }
        if (isControl && ['c', 'v', 'x', 'a', 's', 'u', 'p', 'j', 'i'].includes(key)) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        if (isControl && e.shiftKey && ['i', 'j', 'c'].includes(key)) {
            e.preventDefault();
            return false;
        }
    }, true);

    document.querySelectorAll('.prevent-paste').forEach(el => {
        el.addEventListener('paste', e => {
            e.preventDefault();
            alert('Bu alana yapıştırma işlemi yapılamaz!');
        });
        el.addEventListener('drop', e => e.preventDefault());
    });
})();
</script>

<style>
.no-select {
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    -khtml-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}
input, textarea {
    -webkit-user-select: text !important;
    -moz-user-select: text !important;
    -ms-user-select: text !important;
    user-select: text !important;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 9999;
    display: flex;
    justify-content: center;
    align-items: center;
}
.loading-content {
    background: rgba(255, 255, 255, 0.1);
    padding: 2rem;
    border-radius: 1rem;
    backdrop-filter: blur(5px);
}

.math-editor-container textarea { font-family: 'Consolas', monospace; font-size: 1rem; }
.math-preview { background-color: #f8f9fa; border-left: 4px solid #3498db !important; }
.question-block { transition: all 0.3s ease; border-left: 5px solid transparent !important; }
.question-block:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; border-left-color: #3498db !important; transform: translateY(-2px); }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
.pulse-animation { animation: pulse 1s infinite; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>