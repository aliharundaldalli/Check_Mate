<?php
// teacher/edit_quiz.php - Sınav Düzenle

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';
require_once '../includes/ai_helper.php';

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
$quiz = [];
$questions = [];

try {
    // Sınav Bilgileri
    $stmt = $db->prepare("SELECT * FROM quizzes WHERE id = :id AND created_by = :tid");
    $stmt->execute([':id' => $quiz_id, ':tid' => $teacher_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        show_message('Sınav bulunamadı veya yetkiniz yok.', 'danger');
        redirect('quizzes.php');
        exit;
    }

    // Soruları Getir
    $stmtQ = $db->prepare("SELECT * FROM questions WHERE quiz_id = :qid ORDER BY `order` ASC");
    $stmtQ->execute([':qid' => $quiz_id]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
    
    // JSON options decode
    foreach ($questions as &$q) {
        $q['options'] = json_decode($q['options'] ?? '', true);
    }
    unset($q);

    // Katılımcı Sayısı Kontrolü (Soru düzenlemeyi kısıtlamak için)
    $stmtS = $db->prepare("SELECT COUNT(*) as sub_count FROM quiz_submissions WHERE quiz_id = :qid");
    $stmtS->execute([':qid' => $quiz_id]);
    $participation_count = $stmtS->fetch(PDO::FETCH_ASSOC)['sub_count'];
    $is_editable = ($participation_count == 0);

} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// --- FORM HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!$is_editable && (isset($_POST['questions']) || (isset($_POST['action']) && $_POST['action'] === 'delete_question'))) {
        show_message('Bu sınava öğrenciler katıldığı için soruları düzenleyemez veya silemezsiniz.', 'danger');
        redirect("edit_quiz.php?id=$quiz_id");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'generate_ai') {
        $topic = $_POST['topic'] ?? '';
        $difficulty = $_POST['difficulty'] ?? 'orta';
        $type = $_POST['type'] ?? 'all';
        $q_count = (int)($_POST['ai_count'] ?? 1);
        
        try {
            // Function name is generateQuizQuestions in ai_helper.php
            // Param order: $topic, $difficulty, $count, $type
            $generated = generateQuizQuestions($topic, $difficulty, $q_count, $type);
            
            if (!empty($generated)) {
                $db->beginTransaction();
                $q_stmt = $db->prepare("INSERT INTO questions (quiz_id, question_text, question_type, options, correct_answer, points, ai_grading_prompt, is_ai_graded, `order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                // Get current max order
                $order_stmt = $db->prepare("SELECT MAX(`order`) as max_o FROM questions WHERE quiz_id = ?");
                $order_stmt->execute([$quiz_id]);
                $current_order = (int)$order_stmt->fetch(PDO::FETCH_ASSOC)['max_o'] + 1;

                foreach ($generated as $q) {
                    $q_type = $q['type'] ?? 'multiple_choice';
                    $options_json = isset($q['options']) ? json_encode($q['options'], JSON_UNESCAPED_UNICODE) : null;
                    $is_ai = ($q_type === 'text' || $q_type === 'textarea') ? 1 : 0;
                    
                    // Accept both ai_grading_prompt and ai_prompt fallback
                    $grading_prompt = $q['ai_grading_prompt'] ?? ($q['ai_prompt'] ?? null);

                    $q_stmt->execute([
                        $quiz_id, $q['text'], $q_type, $options_json, $q['correct_answer'], 
                        10, $grading_prompt, $is_ai, $current_order++
                    ]);
                }
                $db->commit();
                show_message("$q_count adet AI sorusu başarıyla eklendi.", "success");
            } else {
                $ai_error = "AI soru üretemedi, lütfen tekrar deneyin.";
            }
        } catch (Exception $e) {
            $ai_error = "AI Hatası: " . $e->getMessage();
        }
        header("Location: edit_quiz.php?id=$quiz_id");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_quiz') {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $time_limit = !empty($_POST['time_limit']) ? (int)$_POST['time_limit'] : null;
        $available_from = !empty($_POST['available_from']) ? $_POST['available_from'] : null;
        $available_until = !empty($_POST['available_until']) ? $_POST['available_until'] : null;
        
        // Base64 Decode Helper
        $decode_data = function($data) {
            if (empty($data)) return $data;
            if (isset($_POST['is_encoded']) && $_POST['is_encoded'] == '1') {
                return base64_decode($data);
            }
            return $data;
        };

        try {
            $db->beginTransaction();
            
            // 1. Quiz Update
            $stmt = $db->prepare("UPDATE quizzes SET title = ?, description = ?, time_limit = ?, available_from = ?, available_until = ? WHERE id = ?");
            $stmt->execute([
                $decode_data($title), 
                $decode_data($description), 
                $time_limit, 
                $available_from, 
                $available_until, 
                $quiz_id
            ]);
            
            // 2. Questions Update/Insert
            if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                $order = 1;
                foreach ($_POST['questions'] as $key => $q_data) {
                    $q_text = $decode_data($q_data['text']);
                    $q_type = $q_data['type'];
                    $q_correct = $decode_data($q_data['correct_answer']);
                    $q_points = (int)$q_data['points'];
                    $q_ai_prompt = $decode_data($q_data['ai_prompt'] ?? null);
                    $q_is_ai = ($q_type === 'text' || $q_type === 'textarea') ? 1 : 0;
                    
                     // Options processing
                    $options_json = null;
                    if (isset($q_data['options']) && is_array($q_data['options'])) {
                        $clean_opts = [];
                        foreach ($q_data['options'] as $opt) {
                            $decoded_opt = $decode_data($opt);
                            if (trim($decoded_opt) !== '') {
                                $clean_opts[] = $decoded_opt;
                            }
                        }
                        $options_json = json_encode($clean_opts, JSON_UNESCAPED_UNICODE);
                    }
                    
                    if (isset($q_data['id']) && is_numeric($q_data['id'])) {
                        // UPDATE
                        $u_stmt = $db->prepare("UPDATE questions SET question_text=?, question_type=?, options=?, correct_answer=?, points=?, ai_grading_prompt=?, is_ai_graded=?, `order`=? WHERE id=? AND quiz_id=?");
                        $u_stmt->execute([$q_text, $q_type, $options_json, $q_correct, $q_points, $q_ai_prompt, $q_is_ai, $order, $q_data['id'], $quiz_id]);
                    } else {
                        // INSERT (New Question)
                        $i_stmt = $db->prepare("INSERT INTO questions (quiz_id, question_text, question_type, options, correct_answer, points, ai_grading_prompt, is_ai_graded, `order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $i_stmt->execute([$quiz_id, $q_text, $q_type, $options_json, $q_correct, $q_points, $q_ai_prompt, $q_is_ai, $order]);
                    }
                    $order++;
                }
            }
            
            $db->commit();
            show_message('Sınav güncellendi.', 'success');
            // Refresh info
            header("Location: edit_quiz.php?id=$quiz_id");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Güncelleme hatası: " . $e->getMessage();
        }
    }
    
    // Delete Question
    if (isset($_POST['action']) && $_POST['action'] === 'delete_question') {
        $q_del_id = $_POST['question_id'];
        try {
            $stmt = $db->prepare("DELETE FROM questions WHERE id = ? AND quiz_id = ?");
            $stmt->execute([$q_del_id, $quiz_id]);
            show_message('Soru silindi.', 'warning');
            header("Location: edit_quiz.php?id=$quiz_id");
            exit;
        } catch (Exception $e) {
             $error = "Silme hatası: " . $e->getMessage();
        }
    }
}

$page_title = "Sınavı Düzenle - " . $quiz['title'];
include '../includes/components/teacher_header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                     <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Sınav Düzenle</h5>
                     <a href="quizzes.php" class="btn btn-sm btn-dark">Geri Dön</a>
                </div>
                <div class="card-body">
                    <?php if (isset($ai_error)): ?>
                        <div class="alert alert-danger shadow-sm border-start border-4 border-danger"><?php echo htmlspecialchars($ai_error); ?></div>
                    <?php endif; ?>

                    <?php if (!$is_editable): ?>
                        <div class="alert alert-warning shadow-sm border-start border-4 border-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Dikkat:</strong> Bu sınava toplam <b><?php echo $participation_count; ?></b> öğrenci katılmıştır. Veri güvenliği için soru metinlerini veya şıkları değiştiremezsiniz, sadece başlık ve tarihleri güncelleyebilirsiniz.
                        </div>
                    <?php endif; ?>
                    
                    <?php display_message(); ?>

                    <form method="POST" action="edit_quiz.php?id=<?php echo $quiz_id; ?>" id="editQuizForm">
                        <input type="hidden" name="action" value="update_quiz">
                        <input type="hidden" name="is_encoded" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sınav Başlığı</label>
                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($quiz['title']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($quiz['description']); ?></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Süre (Dakika)</label>
                                <input type="number" name="time_limit" class="form-control" value="<?php echo $quiz['time_limit'] ?? ''; ?>" min="1" placeholder="Limitsiz">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Başlangıç Tarihi</label>
                                <input type="datetime-local" name="available_from" class="form-control" value="<?php echo !empty($quiz['available_from']) ? date('Y-m-d\TH:i', strtotime($quiz['available_from'])) : ''; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Bitiş Tarihi</label>
                                <input type="datetime-local" name="available_until" class="form-control" value="<?php echo !empty($quiz['available_until']) ? date('Y-m-d\TH:i', strtotime($quiz['available_until'])) : ''; ?>">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-3 text-primary">Sorular</h6>
                        <div id="questions_wrapper">
                            <?php foreach ($questions as $index => $q): ?>
                                <div class="card mb-3 border question-item" id="q_card_<?php echo $index; ?>">
                                    <div class="card-header d-flex justify-content-between align-items-center bg-light">
                                        <span class="fw-bold">Soru #<?php echo $index + 1; ?></span>
                                        <button type="button" class="btn btn-sm btn-danger delete-question-btn" data-id="<?php echo $q['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <input type="hidden" name="questions[<?php echo $index; ?>][id]" value="<?php echo $q['id']; ?>">
                                        
                                        <div class="row mb-2">
                                            <div class="col-md-9">
                                                <label class="form-label">Soru Metni</label>
                                                <textarea name="questions[<?php echo $index; ?>][text]" class="form-control" rows="2" required <?php echo !$is_editable ? 'readonly' : ''; ?>><?php echo htmlspecialchars($q['question_text']); ?></textarea>
                                            </div>
                                            <div class="col-md-3">
                                                 <select name="questions[<?php echo $index; ?>][type]" class="form-select q-type-select" data-index="<?php echo $index; ?>" <?php echo !$is_editable ? 'disabled' : ''; ?>>
                                                     <option value="multiple_choice" <?php echo $q['question_type'] == 'multiple_choice' ? 'selected' : ''; ?>>Çoktan Seçmeli</option>
                                                     <option value="multiple_select" <?php echo $q['question_type'] == 'multiple_select' ? 'selected' : ''; ?>>Çoktan Çok Seçmeli</option>
                                                     <option value="text" <?php echo $q['question_type'] == 'text' ? 'selected' : ''; ?>>Klasik (Text)</option>
                                                 </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Options Area (shown if multiple_choice or multiple_select) -->
                                        <div class="mb-2 options-area" id="options_area_<?php echo $index; ?>" style="<?php echo ($q['question_type'] == 'multiple_choice' || $q['question_type'] == 'multiple_select') ? '' : 'display:none;'; ?>">
                                            <label class="form-label small text-muted">Seçenekler</label>
                                            <?php 
                                            // Ensure at least 4 inputs appear
                                            $opts = is_array($q['options']) ? $q['options'] : ['', '', '', ''];
                                            for($i=0; $i<4; $i++): 
                                                $val = $opts[$i] ?? '';
                                            ?>
                                                <div class="input-group input-group-sm mb-1">
                                                    <span class="input-group-text"><?php echo chr(65 + $i); ?></span>
                                                    <input type="text" name="questions[<?php echo $index; ?>][options][]" class="form-control" value="<?php echo htmlspecialchars($val); ?>" <?php echo !$is_editable ? 'readonly' : ''; ?>>
                                                </div>
                                            <?php endfor; ?>
                                            
                                            <label class="form-label small text-muted mt-2">Doğru Cevap (Metin)</label>
                                            <input type="text" name="questions[<?php echo $index; ?>][correct_answer]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($q['correct_answer']); ?>" <?php echo !$is_editable ? 'readonly' : ''; ?>>
                                            <small class="text-muted d-block mt-1" style="<?php echo $q['question_type'] == 'multiple_select' ? '' : 'display:none;'; ?>" id="ms_hint_<?php echo $index; ?>">Birden fazla cevap varsa virgülle ayırın.</small>
                                        </div>
                                        
                                        <!-- AI Prompt Area -->
                                        <div class="mb-2 ai-area" id="ai_area_<?php echo $index; ?>" style="<?php echo ($q['question_type'] == 'multiple_choice' || $q['question_type'] == 'multiple_select') ? 'display:none;' : ''; ?>">
                                            <label class="form-label small text-muted">AI Değerlendirme Kriteri</label>
                                            <textarea name="questions[<?php echo $index; ?>][ai_prompt]" class="form-control form-control-sm" rows="2" <?php echo !$is_editable ? 'readonly' : ''; ?>><?php echo htmlspecialchars($q['ai_grading_prompt'] ?? ''); ?></textarea>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-3">
                                                <label class="form-label small">Puan</label>
                                                <input type="number" name="questions[<?php echo $index; ?>][points]" class="form-control form-control-sm" value="<?php echo $q['points']; ?>" <?php echo !$is_editable ? 'readonly' : ''; ?>>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($is_editable): ?>
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card bg-light border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="fw-bold mb-3"><i class="fas fa-robot me-2 text-primary"></i>AI ile Akıllı Soru Ekle</h6>
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Konu</label>
                                                <input type="text" id="ai_topic" class="form-control" placeholder="Örn: PHP Döngüler">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small fw-bold">Zorluk</label>
                                                <select id="ai_diff" class="form-select">
                                                    <option value="kolay">Kolay</option>
                                                    <option value="orta" selected>Orta</option>
                                                    <option value="zor">Zor</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Soru Tipi</label>
                                                <select id="ai_type" class="form-select">
                                                    <option value="all">Karışık</option>
                                                    <option value="multiple_choice">Çoktan Seçmeli</option>
                                                    <option value="text">Klasik (Açık Uçlu)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                                <label class="form-label small fw-bold">Adet</label>
                                                <select id="ai_count_val" class="form-select">
                                                    <option value="1">1</option>
                                                    <option value="3">3</option>
                                                    <option value="5">5</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button type="button" class="btn btn-primary w-100" id="ai_single_gen_btn">
                                                    <i class="fas fa-magic me-1"></i> Üret
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="text-end mb-4">
                            <button type="button" class="btn btn-outline-primary" id="add-manual-edit-btn">
                                <i class="fas fa-plus me-2"></i>Manuel Soru Ekle
                            </button>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-success btn-lg shadow-sm">
                                <i class="fas fa-save me-2"></i>Değişiklikleri Kaydet
                            </button>
                        </div>
                    </form>

                    <!-- AI Generation Form (Hidden) -->
                    <form id="aiGenForm" method="POST" action="edit_quiz.php?id=<?php echo $quiz_id; ?>">
                        <input type="hidden" name="action" value="generate_ai">
                        <input type="hidden" name="topic" id="ai_topic_hidden">
                        <input type="hidden" name="difficulty" id="ai_diff_hidden">
                        <input type="hidden" name="type" id="ai_type_hidden">
                        <input type="hidden" name="ai_count" id="ai_count_hidden">
                    </form>
                    
                    <!-- Hidden delete form -->
                    <form id="deleteForm" method="POST" action="">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="question_id" id="delete_q_id">
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ModSecurity Bypass: Encode sensitive data to Base64 before submit
    const form = document.getElementById('editQuizForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const toEncode = form.querySelectorAll('input[type="text"], textarea');
            toEncode.forEach(el => {
                if (el.name && el.name !== 'action' && el.name !== 'is_encoded') {
                    try {
                        const utf8Bytes = new TextEncoder().encode(el.value);
                        const base64 = btoa(String.fromCharCode(...utf8Bytes));
                        el.value = base64;
                    } catch(err) { console.error("Encoding failed", err); }
                }
            });
        });
    }

    // Type change handler
    document.querySelectorAll('.q-type-select').forEach(sel => {
        sel.addEventListener('change', function() {
            const idx = this.getAttribute('data-index');
            const val = this.value;
            const msHint = document.getElementById('ms_hint_'+idx);
            
            if(val === 'multiple_choice' || val === 'multiple_select') {
                document.getElementById('options_area_'+idx).style.display = 'block';
                document.getElementById('ai_area_'+idx).style.display = 'none';
                
                if(val === 'multiple_select') {
                    if(msHint) msHint.style.display = 'block';
                } else {
                    if(msHint) msHint.style.display = 'none';
                }
            } else {
                document.getElementById('options_area_'+idx).style.display = 'none';
                document.getElementById('ai_area_'+idx).style.display = 'block';
                if(msHint) msHint.style.display = 'none';
            }
        });
    });
    
    // AI Single Gen Button
    const aiBtn = document.getElementById('ai_single_gen_btn');
    if (aiBtn) {
        aiBtn.addEventListener('click', function() {
            const topic = document.getElementById('ai_topic').value;
            const diff = document.getElementById('ai_diff').value;
            const type = document.getElementById('ai_type').value;
            const count = document.getElementById('ai_count_val').value;
            
            if(!topic) { alert('Lütfen bir konu yazın.'); return; }
            
            document.getElementById('ai_topic_hidden').value = topic;
            document.getElementById('ai_diff_hidden').value = diff;
            document.getElementById('ai_type_hidden').value = type;
            document.getElementById('ai_count_hidden').value = count;
            document.getElementById('aiGenForm').submit();
        });
    }

    // Add Manual Edit Button
    const addManualBtn = document.getElementById('add-manual-edit-btn');
    if (addManualBtn) {
        addManualBtn.addEventListener('click', function() {
            const wrapper = document.getElementById('questions_wrapper');
            const index = new Date().getTime();
            
            const html = `
                <div class="card mb-3 border question-item" id="q_card_new_${index}">
                    <div class="card-header d-flex justify-content-between align-items-center bg-info text-white">
                        <span class="fw-bold">YENİ SORU</span>
                        <button type="button" class="btn btn-sm btn-light" onclick="this.closest('.card').remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-md-9">
                                <label class="form-label">Soru Metni</label>
                                <textarea name="questions[n_${index}][text]" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-md-3">
                                 <select name="questions[n_${index}][type]" class="form-select q-type-select-new" data-index="n_${index}">
                                     <option value="multiple_choice">Çoktan Seçmeli</option>
                                     <option value="multiple_select">Çoktan Çok Seçmeli</option>
                                     <option value="text">Klasik (Text)</option>
                                 </select>
                            </div>
                        </div>
                        <div class="mb-2 options-area" id="options_area_n_${index}">
                            <label class="form-label small text-muted">Seçenekler</label>
                            <div class="input-group input-group-sm mb-1"><span class="input-group-text">A</span><input type="text" name="questions[n_${index}][options][]" class="form-control"></div>
                            <div class="input-group input-group-sm mb-1"><span class="input-group-text">B</span><input type="text" name="questions[n_${index}][options][]" class="form-control"></div>
                            <div class="input-group input-group-sm mb-1"><span class="input-group-text">C</span><input type="text" name="questions[n_${index}][options][]" class="form-control"></div>
                            <div class="input-group input-group-sm mb-1"><span class="input-group-text">D</span><input type="text" name="questions[n_${index}][options][]" class="form-control"></div>
                            <label class="form-label small text-muted mt-2">Doğru Cevap (Metin)</label>
                            <input type="text" name="questions[n_${index}][correct_answer]" class="form-control form-control-sm">
                            <small class="text-muted d-block mt-1" style="display:none;" id="ms_hint_n_${index}">Birden fazla cevap varsa virgülle ayırın.</small>
                        </div>
                        <div class="mb-2 ai-area" id="ai_area_n_${index}" style="display:none;">
                            <label class="form-label small text-muted">AI Değerlendirme Kriteri</label>
                            <textarea name="questions[n_${index}][ai_prompt]" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label small">Puan</label>
                                <input type="number" name="questions[n_${index}][points]" class="form-control form-control-sm" value="10">
                            </div>
                        </div>
                    </div>
                </div>`;
            wrapper.insertAdjacentHTML('beforeend', html);
            
            // New type change listener
            const newSel = wrapper.querySelector(`#q_card_new_${index} .q-type-select-new`);
            newSel.addEventListener('change', function() {
                 const idx = this.getAttribute('data-index');
                 const val = this.value;
                 if(val === 'multiple_choice' || val === 'multiple_select') {
                     document.getElementById('options_area_'+idx).style.display = 'block';
                     document.getElementById('ai_area_'+idx).style.display = 'none';
                 } else {
                     document.getElementById('options_area_'+idx).style.display = 'none';
                     document.getElementById('ai_area_'+idx).style.display = 'block';
                 }
            });
        });
    }

    // Delete handler
    document.querySelectorAll('.delete-question-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if(confirm('Bu soruyu silmek istediğinize emin misiniz?')) {
                document.getElementById('delete_q_id').value = this.getAttribute('data-id');
                document.getElementById('deleteForm').submit();
            }
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
