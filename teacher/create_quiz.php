<?php
// create_quiz.php - Yeni Sınav Oluşturma

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

// Dersleri Getir
$courses = [];
try {
    $stmt = $db->prepare("SELECT id, course_name, course_code FROM courses WHERE teacher_id = :tid AND is_active = 1");
    $stmt->execute([':tid' => $teacher_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

$generated_questions = [];
$ai_error = '';
$form_data = [
    'title' => '',
    'course_id' => '',
    'description' => '',
    'topic' => '',
    'difficulty' => 'Orta',
    'q_count' => 5,
    'q_type' => 'mixed'
];

// --- FORM HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. AI ile Soru Üret
    if (isset($_POST['action']) && $_POST['action'] === 'generate_ai') {
        $form_data['topic'] = trim($_POST['topic']);
        $form_data['difficulty'] = $_POST['difficulty'];
        $form_data['q_count'] = (int)$_POST['q_count'];
        $form_data['q_type'] = $_POST['q_type'];
        
        // Preserve other fields
        $form_data['title'] = $_POST['title'];
        $form_data['course_id'] = $_POST['course_id'];
        $form_data['description'] = $_POST['description'];
        $form_data['time_limit'] = $_POST['time_limit'];
        $form_data['available_from'] = $_POST['available_from'];
        $form_data['available_until'] = $_POST['available_until'];

        if ($form_data['topic']) {
            $result = generateQuizQuestions($form_data['topic'], $form_data['difficulty'], $form_data['q_count'], $form_data['q_type']);
            if (isset($result['error'])) {
                $ai_error = $result['error'];
            } else {
                $generated_questions = $result;
            }
        } else {
            $ai_error = "Lütfen bir konu başlığı girin.";
        }
    }

    // 2. Sınavı Kaydet (Action logic update)
    if (isset($_POST['action']) && $_POST['action'] === 'save_quiz') {
        $title = $_POST['title'];
        $course_id = $_POST['course_id'];
        $description = $_POST['description'];
        $time_limit = !empty($_POST['time_limit']) ? (int)$_POST['time_limit'] : NULL;
        $available_from = !empty($_POST['available_from']) ? $_POST['available_from'] : NULL;
        $available_until = !empty($_POST['available_until']) ? $_POST['available_until'] : NULL;
        
        if (empty($title) || empty($course_id)) {
            $ai_error = "Sınav başlığı ve ders seçimi zorunludur.";
        } else {
            try {
                $db->beginTransaction();
                
                // Quiz ekle
                $stmt = $db->prepare("INSERT INTO quizzes (course_id, title, description, time_limit, available_from, available_until, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$course_id, $title, $description, $time_limit, $available_from, $available_until, $teacher_id]);
                $quiz_id = $db->lastInsertId();
                
                // Soruları ekle
                if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                    $q_stmt = $db->prepare("INSERT INTO questions (quiz_id, question_text, question_type, options, correct_answer, points, ai_grading_prompt, is_ai_graded, `order`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $order = 1;
                    foreach ($_POST['questions'] as $q) {
                        $q_text = $q['text'];
                        $q_type = $q['type']; // multiple_choice, text, etc.
                        $q_correct = $q['correct_answer'];
                        $q_points = (int)$q['points'];
                        $q_ai_prompt = $q['ai_prompt'] ?? null;
                        
                        // Options processing
                        $options_json = null;
                        if (isset($q['options']) && is_array($q['options'])) {
                            // Filter empty options
                            $clean_opts = array_filter($q['options'], function($val) { return trim($val) !== ''; });
                            $options_json = json_encode(array_values($clean_opts), JSON_UNESCAPED_UNICODE);
                        }
                        
                        $is_ai = ($q_type === 'text' || $q_type === 'textarea') ? 1 : 0;
                        
                        $q_stmt->execute([
                            $quiz_id, 
                            $q_text, 
                            $q_type, 
                            $options_json, 
                            $q_correct, 
                            $q_points, 
                            $q_ai_prompt, 
                            $is_ai,
                            $order++
                        ]);
                    }
                }
                
                $db->commit();
                show_message('Sınav başarıyla oluşturuldu!', 'success');
                redirect('quizzes.php');
                
            } catch (Exception $e) {
                $db->rollBack();
                $ai_error = "Kayıt hatası: " . $e->getMessage();
            }
        }
    }
}

$page_title = "Yeni Sınav Oluştur - Check Mate";
include '../includes/components/teacher_header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-lg border-0 rounded-3">
                <div class="card-header bg-gradient-primary text-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0"><i class="fas fa-magic me-2"></i>Yeni Sınav Oluştur</h5>
                    <a href="quizzes.php" class="btn btn-sm btn-light text-primary shadow-sm fw-bold">Listeye Dön</a>
                </div>
                <div class="card-body p-4 bg-light">
                    
                    <?php if ($ai_error): ?>
                        <div class="alert alert-danger shadow-sm border-start border-4 border-danger"><?php echo htmlspecialchars($ai_error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" id="quizForm">
                        
                        <!-- Temel Bilgiler Card -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Sınav Bilgileri</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Ders Seçimi <span class="text-danger">*</span></label>
                                        <select name="course_id" class="form-select" required>
                                            <option value="">Ders Seçiniz...</option>
                                            <?php foreach ($courses as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo ($form_data['course_id'] == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Sınav Başlığı <span class="text-danger">*</span></label>
                                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($form_data['title']); ?>" placeholder="Örn: Vize 1, Ara Sınav..." required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Süre (Dakika)</label>
                                        <input type="number" name="time_limit" class="form-control" placeholder="Örn: 45" min="1" value="<?php echo isset($form_data['time_limit']) ? $form_data['time_limit'] : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Başlangıç Tarihi</label>
                                        <input type="datetime-local" name="available_from" class="form-control" value="<?php echo isset($form_data['available_from']) ? $form_data['available_from'] : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Bitiş Tarihi</label>
                                        <input type="datetime-local" name="available_until" class="form-control" value="<?php echo isset($form_data['available_until']) ? $form_data['available_until'] : ''; ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <small class="text-muted"><i class="fas fa-info-circle me-1"></i> Süre boş bırakılırsa sınırsız olur. Tarihler boş bırakılırsa sınav hemen ve süresiz aktif olur.</small>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Açıklama</label>
                                        <textarea name="description" class="form-control" rows="1" placeholder="Sınav hakkında kısa bilgi..."><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- AI Bölümü -->
                        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%);">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="icon-circle bg-primary text-white me-3 d-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 48px; height: 48px;">
                                        <i class="fas fa-robot fa-lg"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-primary mb-0 fw-bold">Yapay Zeka Asistanı</h5>
                                        <small class="text-muted">Konu başlığını girin, soruları sizin için hazırlayalım.</small>
                                    </div>
                                </div>
                                
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Konu / İçerik</label>
                                        <div class="input-group shadow-sm">
                                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-primary"></i></span>
                                            <input type="text" name="topic" class="form-control border-start-0 ps-0" value="<?php echo htmlspecialchars($form_data['topic']); ?>" placeholder="Örn: PHP Döngüler, Osmanlı Tarihi...">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Zorluk</label>
                                        <select name="difficulty" class="form-select shadow-sm">
                                            <option value="Kolay" <?php echo $form_data['difficulty'] == 'Kolay' ? 'selected' : ''; ?>>Kolay</option>
                                            <option value="Orta" <?php echo $form_data['difficulty'] == 'Orta' ? 'selected' : ''; ?>>Orta</option>
                                            <option value="Zor" <?php echo $form_data['difficulty'] == 'Zor' ? 'selected' : ''; ?>>Zor</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Adet</label>
                                        <input type="number" name="q_count" class="form-control shadow-sm" value="<?php echo $form_data['q_count']; ?>" min="1" max="20">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-muted small fw-bold text-uppercase">Tip</label>
                                        <select name="q_type" class="form-select shadow-sm">
                                            <option value="mixed" <?php echo $form_data['q_type'] == 'mixed' ? 'selected' : ''; ?>>Karışık</option>
                                            <option value="multiple_choice" <?php echo $form_data['q_type'] == 'multiple_choice' ? 'selected' : ''; ?>>Çoktan Seçmeli</option>
                                            <option value="multiple_select" <?php echo $form_data['q_type'] == 'multiple_select' ? 'selected' : ''; ?>>Çoktan Çok Seçmeli</option>
                                            <option value="text" <?php echo $form_data['q_type'] == 'text' ? 'selected' : ''; ?>>Klasik</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" name="action" value="generate_ai" class="btn btn-primary w-100 shadow-sm gradient-btn fw-bold">
                                            <i class="fas fa-bolt me-1"></i> Üret
                                        </button>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between mt-3 px-1">
                                    <small class="text-muted"><i class="fas fa-key me-1"></i>.env dosyasında API anahtarınız tanımlı olmalıdır.</small>
                                    <small class="text-info"><i class="fas fa-square-root-alt me-1"></i>LaTeX desteklenir: <code>$$x^2$$</code></small>
                                </div>
                            </div>
                        </div>

                        <style>
                        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); }
                        .gradient-btn { background: linear-gradient(45deg, #0d6efd, #0dcaf0); border: none; transition: all 0.2s; }
                        .gradient-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); filter: brightness(110%); }
                        
                        .question-item { 
                            transition: all 0.3s ease; 
                            border-left: 5px solid #0d6efd !important; 
                            border-radius: 8px;
                            background: #fff;
                        }
                        .question-item:hover { 
                            transform: translateY(-3px); 
                            box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1); 
                        }
                        
                        .q-preview-box {
                            background: #f8f9fa;
                            border: 1px dashed #ced4da;
                            border-radius: 4px;
                            padding: 10px;
                            min-height: 40px;
                            margin-top: 5px;
                            font-family: 'Times New Roman', serif;
                        }
                        </style>

                        <!-- Sorular Listesi -->
                        <div id="questions-container">
                            <h5 class="mb-3 text-secondary fw-bold px-1">Sınav Soruları</h5>
                            
                            <?php if (empty($generated_questions)): ?>
                                <div class="alert alert-secondary text-center py-5 shadow-sm rounded-3" id="empty-msg">
                                    <div class="mb-3"><i class="fas fa-clipboard-list fa-3x text-muted"></i></div>
                                    <h6 class="fw-bold">Henüz Soru Eklenmedi</h6>
                                    <p class="text-muted mb-0">Yukarıdaki AI aracını kullanarak soru üretebilir veya aşağıdaki butondan manuel ekleyebilirsiniz.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($generated_questions as $index => $q): ?>
                                    <div class="card mb-4 question-item border-0 shadow-sm">
                                        <div class="card-header bg-white border-bottom-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
                                            <span class="badge bg-light text-primary border border-primary fw-bold px-3 py-2 rounded-pill">Soru <?php echo $index + 1; ?></span>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-secondary"><?php echo $q['type']; ?></span>
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-q-btn rounded-circle" style="width: 32px; height: 32px;"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <input type="hidden" name="questions[<?php echo $index; ?>][type]" value="<?php echo htmlspecialchars($q['type']); ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small text-muted">Soru Metni</label>
                                                <textarea name="questions[<?php echo $index; ?>][text]" class="form-control q-text-input" rows="2" oninput="updatePreview(this)"><?php echo htmlspecialchars($q['text']); ?></textarea>
                                                <div class="q-preview-box small text-dark mt-2" title="Önizleme (LaTeX)"></div>
                                            </div>

                                            <?php if ($q['type'] == 'multiple_choice' || $q['type'] == 'multiple_select'): ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted">Seçenekler</label>
                                                    <div class="row g-2">
                                                        <?php foreach ($q['options'] ?? [] as $optIndex => $opt): ?>
                                                            <div class="col-md-6">
                                                                <div class="input-group">
                                                                    <span class="input-group-text bg-light fw-bold"><?php echo chr(65 + $optIndex); ?></span>
                                                                    <input type="text" name="questions[<?php echo $index; ?>][options][]" class="form-control q-opt-input" value="<?php echo htmlspecialchars($opt); ?>" oninput="updatePreview(this)">
                                                                </div>
                                                                <div class="q-preview-box small text-dark mt-1" style="min-height: 25px; padding: 5px;" title="Seçenek Önizleme"></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted">Doğru Cevap (Tam Metin)</label>
                                                    <?php 
                                                        $ca_val = $q['correct_answer'];
                                                        if (is_array($ca_val)) {
                                                            // AI gave array, convert to JSON for input value (or comma separated if you prefer, but backend uses json for multiple_select)
                                                            // Actually, let's look at how the manual input expects it. The manual input is just a text field.
                                                            // For multiple_select, the backend expects a JSON string.
                                                            // But the teacher might want to edit it easily. 
                                                            // Let's assume the teacher sees a JSON string or we convert it to a readable string and then rely on the teacher to fix it?
                                                            // Better: Use json_encode if array.
                                                            $ca_val = json_encode($ca_val, JSON_UNESCAPED_UNICODE);
                                                        }
                                                    ?>
                                                    <input type="text" name="questions[<?php echo $index; ?>][correct_answer]" class="form-control is-valid bg-light" value="<?php echo htmlspecialchars($ca_val); ?>">
                                                    <?php if($q['type'] == 'multiple_select'): ?>
                                                        <small class="text-danger">* Çoktan çok seçmeli için cevapları JSON formatında örn: ["A) ...", "B) ..."] şeklinde bırakınız.</small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted">AI Değerlendirme Kriteri</label>
                                                    <textarea name="questions[<?php echo $index; ?>][ai_prompt]" class="form-control text-muted bg-light" rows="2"><?php echo htmlspecialchars($q['ai_prompt'] ?? ''); ?></textarea>
                                                </div>
                                            <?php endif; ?>

                                            <div class="row">
                                                <div class="col-md-3">
                                                    <label class="form-label fw-bold small text-muted">Puan</label>
                                                    <div class="input-group">
                                                        <input type="number" name="questions[<?php echo $index; ?>][points]" class="form-control" value="<?php echo $q['points']; ?>">
                                                        <span class="input-group-text">Puan</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="text-center mb-5">
                            <button type="button" class="btn btn-white border shadow-sm text-primary py-2 px-4 rounded-pill fw-bold hover-scale" id="add-manual-btn">
                                <i class="fas fa-plus-circle me-1"></i> Manuel Soru Ekle
                            </button>
                        </div>

                        <div class="d-grid gap-2 mb-4" id="save-btn-container" style="display: <?php echo !empty($generated_questions) ? 'block' : 'none'; ?>;">
                            <button type="submit" name="action" value="save_quiz" class="btn btn-success btn-lg shadow fw-bold">
                                <i class="fas fa-save me-2"></i>Sınavı Kaydet
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="question-template">
    <div class="card mb-4 question-item border-0 shadow-sm">
        <div class="card-header bg-white border-bottom-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
            <span class="badge bg-light text-secondary border fw-bold px-3 py-2 rounded-pill intro-text">Soru #</span>
            <div>
                <span class="badge bg-secondary">Manuel</span>
                <button type="button" class="btn btn-sm btn-outline-danger remove-q-btn rounded-circle ms-2" style="width: 32px; height: 32px;"><i class="fas fa-trash"></i></button>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-9">
                     <label class="form-label fw-bold small text-muted">Soru Metni</label>
                     <textarea class="form-control q-text q-text-input" rows="2" placeholder="Sorunuzu buraya yazın..." oninput="updatePreview(this)"></textarea>
                     <div class="q-preview-box small text-dark mt-2"></div>
                </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small text-muted">Soru Tipi</label>
                <select class="form-select q-type">
                    <option value="multiple_choice">Çoktan Seçmeli</option>
                    <option value="multiple_select">Çoktan Çok Seçmeli</option>
                    <option value="text">Klasik (Yazılı)</option>
                </select>
            </div>
        </div>

        <div class="options-block">
            <label class="form-label fw-bold small text-muted">Seçenekler</label>
            <div class="row g-2">
                <!-- Options A-D -->
                <div class="col-md-6">
                    <div class="input-group mb-1"><span class="input-group-text">A</span><input type="text" class="form-control q-opt q-opt-input" oninput="updatePreview(this)"></div>
                    <div class="q-preview-box small text-dark px-2 py-1 mb-2"></div>
                </div>
                <div class="col-md-6">
                    <div class="input-group mb-1"><span class="input-group-text">B</span><input type="text" class="form-control q-opt q-opt-input" oninput="updatePreview(this)"></div>
                    <div class="q-preview-box small text-dark px-2 py-1 mb-2"></div>
                </div>
                <div class="col-md-6">
                    <div class="input-group mb-1"><span class="input-group-text">C</span><input type="text" class="form-control q-opt q-opt-input" oninput="updatePreview(this)"></div>
                    <div class="q-preview-box small text-dark px-2 py-1 mb-2"></div>
                </div>
                <div class="col-md-6">
                    <div class="input-group mb-1"><span class="input-group-text">D</span><input type="text" class="form-control q-opt q-opt-input" oninput="updatePreview(this)"></div>
                    <div class="q-preview-box small text-dark px-2 py-1 mb-2"></div>
                </div>
            </div>
            <div class="mt-2">
                <label class="form-label fw-bold small text-muted">Doğru Cevap</label>
                <input type="text" class="form-control q-correct bg-light" placeholder="Doğru seçeneğin metnini yazın (Birden fazlaysa virgülle ayırın)">
            </div>
        </div>

        <div class="ai-block" style="display:none;">
            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">AI Değerlendirme Kriteri</label>
                <textarea class="form-control q-ai-prompt bg-light text-muted" rows="2" placeholder="Sorunun yapay zeka tarafından nasıl puanlanacağını belirtin..."></textarea>
            </div>
        </div>

        <div class="row">
             <div class="col-md-3">
                <label class="form-label fw-bold small text-muted">Puan</label>
                <div class="input-group">
                    <input type="number" class="form-control q-points" value="10">
                    <span class="input-group-text">Puan</span>
                </div>
             </div>
        </div>
    </div>
</div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let questionCount = document.querySelectorAll('.question-item').length;
    const addBtn = document.getElementById('add-manual-btn');
    const saveBtnContainer = document.getElementById('save-btn-container');
    const form = document.querySelector('form');
    // Insert before the add button container
    const insertTarget = document.querySelector('.text-center.mb-5');

    if(addBtn) {
        addBtn.addEventListener('click', function() {
            questionCount++;
            const template = document.getElementById('question-template');
            const clone = template.content.cloneNode(true);
            const index = new Date().getTime(); // Unique index

            clone.querySelector('.intro-text').textContent = 'Soru (Manuel)';
            
            // Names
            clone.querySelector('.q-text').name = `questions[m_${index}][text]`;
            clone.querySelector('.q-type').name = `questions[m_${index}][type]`;
            clone.querySelector('.q-correct').name = `questions[m_${index}][correct_answer]`;
            clone.querySelector('.q-points').name = `questions[m_${index}][points]`;
            clone.querySelector('.q-ai-prompt').name = `questions[m_${index}][ai_prompt]`;
            clone.querySelectorAll('.q-opt').forEach(el => el.name = `questions[m_${index}][options][]`);

            // Logic vars
            const typeSel = clone.querySelector('.q-type');
            const optsBlock = clone.querySelector('.options-block');
            const aiBlock = clone.querySelector('.ai-block');
            const removeBtn = clone.querySelector('.remove-q-btn');
            const item = clone.querySelector('.question-item');

            removeBtn.addEventListener('click', function() {
                item.remove();
                if(document.querySelectorAll('.question-item').length === 0) saveBtnContainer.style.display = 'none';
            });

            saveBtnContainer.style.display = 'block';
            form.insertBefore(clone, insertTarget);

            // Type Logic
            typeSel.addEventListener('change', function() {
            if(this.value === 'multiple_choice' || this.value === 'multiple_select') {
                optsBlock.style.display = 'block';
                aiBlock.style.display = 'none';
            } else {
                optsBlock.style.display = 'none';
                aiBlock.style.display = 'block';
            }
        });
     });
   }
});
</script>
