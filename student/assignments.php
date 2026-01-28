<?php
// =================================================================
// STUDENT ASSIGNMENTS - Öğrenci Ödev Sayfası
// =================================================================

ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);
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
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    $db->exec("SET time_zone = '+03:00'");
    $db->exec("SET time_zone = '+03:00'");
    $student_id = $_SESSION['user_id'];
    
    // Öğrenci numarasını çek (Dosya isimlendirmesi için)
    $stmt = $db->prepare("SELECT student_number FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $current_student_number = $stmt->fetchColumn() ?? 'Ogrenci';

} catch (Exception $e) {
    error_log('Student Assignments Error: ' . $e->getMessage());
    die('Sistem hatası oluştu.');
}

// Site ayarları
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $site_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $site_settings = [];
}
$site_name = $site_settings['site_name'] ?? 'CheckMate';

// Ödev ID
$assignment_id = (int)($_GET['id'] ?? 0);

// =================================================================
// DOSYA YÜKLEME İŞLEMİ (POST)
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assignment_id > 0) {
    try {
        $action = $_POST['action'] ?? '';
        
        // Ödev bilgisi al ve erişim kontrolü
        $stmt = $db->prepare("
            SELECT a.* FROM assignments a
            JOIN course_enrollments ce ON a.course_id = ce.course_id
            WHERE a.id = ? AND ce.student_id = ? AND a.is_active = 1
        ");
        $stmt->execute([$assignment_id, $student_id]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) throw new Exception('Bu ödeve erişim yetkiniz yok.');
        
        // Süre kontrolü
        $now = time();
        $due = strtotime($assignment['due_date']);
        if ($now > $due) throw new Exception('Ödevin süresi dolmuş, işlem yapılamaz.');
        
        $start = strtotime($assignment['start_date']);
        if ($now < $start) throw new Exception('Ödev henüz başlamamış.');
        
        // Mevcut submission var mı?
        $stmt = $db->prepare("SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
        $stmt->execute([$assignment_id, $student_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Dosya yükleme
        if ($action === 'upload' && isset($_FILES['files'])) {
            // Puanlanmışsa işlem yapma
            if ($submission && $submission['score'] !== null) {
                show_message('Puanlanmış ödev üzerinde değişiklik yapamazsınız.', 'warning');
                redirect('assignments.php?id=' . $assignment_id);
                exit;
            }

            $submission_text = trim($_POST['submission_text'] ?? '');
            
            // Submission yoksa oluştur
            if (!$submission) {
                $stmt = $db->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, submission_text) VALUES (?, ?, ?)");
                $stmt->execute([$assignment_id, $student_id, $submission_text]);
                $submission_id = $db->lastInsertId();
            } else {
                $submission_id = $submission['id'];
                // Notu güncelle
                $stmt = $db->prepare("UPDATE assignment_submissions SET submission_text = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$submission_text, $submission_id]);
            }
            
            // Mevcut dosya sayısını al
            $stmt = $db->prepare("SELECT COUNT(*) FROM assignment_files WHERE submission_id = ?");
            $stmt->execute([$submission_id]);
            $current_file_count = $stmt->fetchColumn();
            
            // Upload klasörü
            $upload_dir = '../uploads/assignments/' . $assignment_id . '/' . $student_id . '/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $allowed_extensions = array_map('trim', explode(',', strtolower($assignment['allowed_extensions'])));
            $max_size = $assignment['max_file_size'] ?? 10485760; // 10MB default
            $uploaded_count = 0;
            $errors = [];
            
            // Dosyaları işle
            $files = $_FILES['files'];
            $file_count = count($files['name']);
            
            for ($i = 0; $i < $file_count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors[] = $files['name'][$i] . ': Yükleme hatası';
                    continue;
                }
                
                // Limit kontrolü
                if (($current_file_count + $uploaded_count) >= $assignment['max_files']) {
                    $errors[] = 'Maksimum dosya sayısına ulaşıldı (' . $assignment['max_files'] . ')';
                    break;
                }
                
                $original_name = $files['name'][$i];
                $file_size = $files['size'][$i];
                $file_type = $files['type'][$i];

                $file_item = [
                    'name' => $original_name,
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];
                
                $allowed_extensions_str = implode(',', $allowed_extensions);
                $max_file_size_mb = round($max_size / 1024 / 1024);
                
                // Özel dosya ismi oluştur: ogrenciNo_odevId_tarih Saat
                // Tarih formatı: YmdHis (YılAyGünSaatDakikaSaniye) -> dosya isimlerinde boşluk ve nokta tavsiye edilmez
                $custom_name = $current_student_number . '_' . $assignment_id . '_' . date('YmdHis') . '_' . ($i + 1); // Birden fazla dosya varsa çakışmasın diye index ekledim
                
                $upload_result = secure_file_upload($file_item, $upload_dir, $allowed_extensions_str, $max_file_size_mb, $custom_name);
                
                if ($upload_result['status']) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO assignment_files (submission_id, file_name, file_path, file_size, file_type)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$submission_id, $upload_result['filename'], $upload_result['path'], $upload_result['size'], $upload_result['type']]);
                        $uploaded_count++;
                    } catch (Exception $e) {
                        $errors[] = $original_name . ': Veritabanına kaydedilemedi.';
                        error_log('File DB save error: ' . $e->getMessage());
                    }
                } else {
                    $errors[] = $original_name . ': ' . $upload_result['message'];
                }
            }
            
            if ($uploaded_count > 0) {
                show_message($uploaded_count . ' dosya başarıyla yüklendi.', 'success');
            }
            if (!empty($errors)) {
                show_message(implode('<br>', $errors), 'warning');
            }
            
            redirect('assignments.php?id=' . $assignment_id);
            exit;
        }
        
        // Dosya silme
        elseif ($action === 'delete_file') {
            $file_id = (int)$_POST['file_id'];
            
            if (!$submission) throw new Exception('Gönderi bulunamadı.');
            
            // Dosya bu öğrencinin mi?
            $stmt = $db->prepare("
                SELECT af.* FROM assignment_files af
                JOIN assignment_submissions asub ON af.submission_id = asub.id
                WHERE af.id = ? AND asub.student_id = ?
            ");
            $stmt->execute([$file_id, $student_id]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$file) throw new Exception('Dosya bulunamadı.');
            
            // Puanlanmış mı kontrol et
            $stmt = $db->prepare("SELECT score FROM assignment_submissions WHERE id = ?");
            $stmt->execute([$file['submission_id']]);
            $sub_check = $stmt->fetch();
            
            if ($sub_check && $sub_check['score'] !== null) {
                show_message('Puanlanmış ödevden dosya silemezsiniz.', 'warning');
                redirect('assignments.php?id=' . $assignment_id);
                exit;
            }

            // Fiziksel dosyayı sil
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
            
            // Veritabanından sil
            $stmt = $db->prepare("DELETE FROM assignment_files WHERE id = ?");
            $stmt->execute([$file_id]);
            
            show_message('Dosya silindi.', 'success');
            redirect('assignments.php?id=' . $assignment_id);
            exit;
        }
        
    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }
}

// =================================================================
// VERİ ÇEKME
// =================================================================

$assignments = [];
$assignment_detail = null;
$my_submission = null;
$my_files = [];

try {
    if ($assignment_id > 0) {
        // Tek ödev detayı
        $stmt = $db->prepare("
            SELECT a.*, c.course_name, c.course_code, u.full_name as teacher_name
            FROM assignments a
            JOIN courses c ON a.course_id = c.id
            JOIN course_enrollments ce ON c.id = ce.course_id
            LEFT JOIN users u ON a.teacher_id = u.id
            WHERE a.id = ? AND ce.student_id = ? AND a.is_active = 1
        ");
        $stmt->execute([$assignment_id, $student_id]);
        $assignment_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($assignment_detail) {
            // Benim gönderim
            $stmt = $db->prepare("SELECT * FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
            $stmt->execute([$assignment_id, $student_id]);
            $my_submission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Dosyalarım
            if ($my_submission) {
                $stmt = $db->prepare("SELECT * FROM assignment_files WHERE submission_id = ? ORDER BY uploaded_at DESC");
                $stmt->execute([$my_submission['id']]);
                $my_files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            
            // Ek dosyalar (Talimatlar)
            $stmt = $db->prepare("SELECT * FROM assignment_attachments WHERE assignment_id = ?");
            $stmt->execute([$assignment_id]);
            $assignment_detail['attachments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Tüm ödevlerim
        $stmt = $db->prepare("
            SELECT a.*, c.course_name, c.course_code,
            (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND student_id = ?) as has_submission,
            (SELECT score FROM assignment_submissions WHERE assignment_id = a.id AND student_id = ?) as my_score
            FROM assignments a
            JOIN courses c ON a.course_id = c.id
            JOIN course_enrollments ce ON c.id = ce.course_id
            WHERE ce.student_id = ? AND a.is_active = 1
            ORDER BY a.due_date ASC
        ");
        $stmt->execute([$student_id, $student_id, $student_id]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Student assignments error: ' . $e->getMessage());
}

// Durum fonksiyonu
function getAssignmentStatus($assignment) {
    $now = time();
    $start = strtotime($assignment['start_date']);
    $due = strtotime($assignment['due_date']);
    
    if ($now < $start) return ['status' => 'not_started', 'label' => 'Başlamadı', 'class' => 'info', 'can_submit' => false];
    if ($now >= $start && $now <= $due) return ['status' => 'active', 'label' => 'Aktif', 'class' => 'success', 'can_submit' => true];
    return ['status' => 'expired', 'label' => 'Süresi Doldu', 'class' => 'secondary', 'can_submit' => false];
}

// Kalan süre hesapla
function getTimeRemaining($due_date) {
    $now = time();
    $due = strtotime($due_date);
    $diff = $due - $now;
    
    if ($diff <= 0) return 'Süresi doldu';
    
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);
    
    if ($days > 0) return $days . ' gün ' . $hours . ' saat';
    if ($hours > 0) return $hours . ' saat ' . $minutes . ' dakika';
    return $minutes . ' dakika';
}

$page_title = "Ödevler - " . $site_name;
include '../includes/components/student_header.php';
?>

<style>
.assignment-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
}
.assignment-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.12);
}
.file-item {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    transition: all 0.2s ease;
}
.file-item:hover {
    background: #f8f9fa;
    border-color: #cbd3da;
}
.file-info {
    display: flex;
    align-items: center;
    overflow: hidden;
}
.file-icon {
    font-size: 1.5rem;
    color: #6c757d;
    margin-right: 12px;
    flex-shrink: 0;
}
.file-details {
    min-width: 0;
}
.file-name {
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    color: #2d3436;
}
.file-size {
    font-size: 0.75rem;
    color: #6c757d;
}
.upload-zone {
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    padding: 30px;
    text-align: center;
    background: #fafbfc;
    transition: all 0.3s ease;
}
.upload-zone:hover, .upload-zone.dragover {
    border-color: #0d6efd;
    background: #f0f7ff;
}
.countdown-badge {
    font-size: 0.9rem;
    padding: 8px 16px;
}
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($assignment_id === 0): ?>
    <!-- ===================== ÖDEV LİSTESİ ===================== -->
    <h4 class="mb-4"><i class="fas fa-tasks me-2"></i>Ödevlerim</h4>

    <?php if (empty($assignments)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">Henüz ödev bulunmuyor</h5>
                <p class="text-muted">Kayıtlı olduğunuz derslerde ödev verildiğinde burada görünecek.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($assignments as $a): 
                $status = getAssignmentStatus($a);
            ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="card assignment-card h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <span class="badge bg-<?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                            <small class="text-muted"><?php echo htmlspecialchars($a['course_code']); ?></small>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($a['title']); ?></h5>
                            <p class="card-text text-muted small"><?php echo htmlspecialchars($a['course_name']); ?></p>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block">
                                    <i class="fas fa-clock me-1"></i>Son teslim: 
                                    <?php echo date('d.m.Y H:i', strtotime($a['due_date'])); ?>
                                </small>
                                <?php if ($status['can_submit']): ?>
                                    <small class="text-warning d-block mt-1">
                                        <i class="fas fa-hourglass-half me-1"></i>
                                        Kalan: <?php echo getTimeRemaining($a['due_date']); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($a['has_submission']): ?>
                                <?php if ($a['my_score'] !== null): ?>
                                    <span class="badge bg-success">Puanlandı: <?php echo $a['my_score']; ?>/<?php echo $a['max_score']; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-primary">Gönderildi</span>
                                <?php endif; ?>
                            <?php elseif ($status['can_submit']): ?>
                                <span class="badge bg-warning text-dark">Gönderilmedi</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white border-0">
                            <a href="assignments.php?id=<?php echo $a['id']; ?>" class="btn btn-primary w-100">
                                <i class="fas fa-eye me-1"></i>Detay
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php elseif ($assignment_detail): ?>
    <!-- ===================== ÖDEV DETAYI ===================== -->
    <?php 
    $status = getAssignmentStatus($assignment_detail); 
    
    // Eğer puanlanmışsa gönderim/düzenleme kapat
    if ($my_submission && $my_submission['score'] !== null) {
        $status['can_submit'] = false;
        $is_graded = true;
    } else {
        $is_graded = false;
    }
    ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="assignments.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i>Geri
        </a>
        <?php if ($status['can_submit']): ?>
            <span class="badge bg-warning text-dark countdown-badge">
                <i class="fas fa-hourglass-half me-1"></i>Kalan: <?php echo getTimeRemaining($assignment_detail['due_date']); ?>
            </span>
        <?php endif; ?>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Ödev Bilgileri -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars($assignment_detail['title']); ?></h5>
                    <span class="badge bg-<?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-sm-6">
                            <small class="text-muted">Ders</small>
                            <p class="mb-0"><?php echo htmlspecialchars($assignment_detail['course_name']); ?></p>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted">Öğretmen</small>
                            <p class="mb-0"><?php echo htmlspecialchars($assignment_detail['teacher_name']); ?></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-6">
                            <small class="text-muted">Başlangıç</small>
                            <p class="mb-0"><?php echo date('d.m.Y H:i', strtotime($assignment_detail['start_date'])); ?></p>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted">Son Teslim</small>
                            <p class="mb-0 fw-bold text-danger"><?php echo date('d.m.Y H:i', strtotime($assignment_detail['due_date'])); ?></p>
                        </div>
                    </div>
                    
                    <?php if (!empty($assignment_detail['description'])): ?>
                        <hr>
                        <h6>Açıklama</h6>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($assignment_detail['description'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($assignment_detail['attachments'])): ?>
                        <hr>
                        <h6 class="mb-3"><i class="fas fa-paperclip me-2"></i>Ek Dosyalar (Talimatlar)</h6>
                        <div class="list-group">
                            <?php foreach ($assignment_detail['attachments'] as $index => $att): ?>
                                <a href="<?php echo htmlspecialchars($att['file_path']); ?>" class="list-group-item list-group-item-action d-flex align-items-center" target="_blank">
                                    <i class="fas fa-file-alt text-primary me-3 fa-lg"></i>
                                    <div>
                                        <div class="fw-bold">Talimat Dosyası <?php echo $index + 1; ?></div>
                                        <small class="text-muted"><?php echo round($att['file_size'] / 1024); ?> KB</small>
                                    </div>
                                    <i class="fas fa-eye ms-auto text-muted" title="Görüntüle"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dosya Yükleme -->
            <?php if ($status['can_submit']): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Dosya Yükle</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label class="form-label">Not (Opsiyonel)</label>
                            <textarea name="submission_text" class="form-control" rows="2" 
                                      placeholder="Öğretmeninize iletmek istediğiniz bir not varsa yazın..."><?php echo htmlspecialchars($my_submission['submission_text'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="upload-zone mb-3" id="uploadZone">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                            <p class="mb-2">Dosyaları sürükleyip bırakın veya tıklayın</p>
                            <input type="file" name="files[]" id="fileInput" multiple class="d-none"
                                   accept=".<?php echo str_replace(',', ',.', $assignment_detail['allowed_extensions']); ?>">
                            <small class="text-muted d-block">
                                İzin verilenler: <?php echo $assignment_detail['allowed_extensions']; ?><br>
                                Maks. <?php echo $assignment_detail['max_files']; ?> dosya, her biri max 10MB
                            </small>
                        </div>
                        
                        <div id="fileList" class="mb-3"></div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i>Yükle
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-lg-4">
            <!-- Gönderim Durumu -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Gönderim Durumu</h5>
                </div>
                <div class="card-body">
                    <?php if ($my_submission): ?>
                        <div class="alert alert-success mb-3">
                            <i class="fas fa-check me-1"></i>Gönderildi
                            <small class="d-block mt-1">
                                <?php echo date('d.m.Y H:i', strtotime($my_submission['submitted_at'])); ?>
                            </small>
                        </div>
                        
                        <?php if ($my_submission['score'] !== null): ?>
                            <div class="text-center p-3 bg-light rounded mb-3">
                                <h2 class="text-primary mb-0"><?php echo $my_submission['score']; ?></h2>
                                <small class="text-muted">/ <?php echo $assignment_detail['max_score']; ?> puan</small>
                            </div>
                            <?php if (!empty($my_submission['feedback'])): ?>
                                <div class="alert alert-info">
                                    <strong>Öğretmen Notu:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($my_submission['feedback'])); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Henüz puanlanmadı</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?php if ($status['can_submit']): ?>
                                Henüz gönderi yapmadınız
                            <?php else: ?>
                                Gönderi yapılmadı
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Yüklenen Dosyalar -->
            <?php if (!empty($my_files)): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Dosyalarım</h5>
                    <span class="badge bg-secondary"><?php echo count($my_files); ?>/<?php echo $assignment_detail['max_files']; ?></span>
                </div>
                <div class="card-body">
                    <?php foreach ($my_files as $index => $file): ?>
                        <div class="file-item">
                            <div class="file-info flex-grow-1">
                                <i class="fas fa-file file-icon"></i>
                                <div class="file-details">
                                    <span class="file-name" title="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        Dosyam <?php echo $index + 1; ?>
                                    </span>
                                    <span class="file-size">
                                        <?php echo round($file['file_size'] / 1024, 1); ?> KB
                                    </span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Görüntüle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($status['can_submit']): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Dosyayı silmek istediğinize emin misiniz?')">
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
        <div class="alert alert-danger">Ödev bulunamadı veya erişim yetkiniz yok.</div>
        <a href="assignments.php" class="btn btn-secondary">Geri Dön</a>
    <?php endif; ?>
</div>

<script>
// Drag & Drop
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const fileList = document.getElementById('fileList');

if (uploadZone) {
    uploadZone.addEventListener('click', () => fileInput.click());
    
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });
    
    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('dragover');
    });
    
    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        fileInput.files = e.dataTransfer.files;
        updateFileList();
    });
    
    fileInput.addEventListener('change', updateFileList);
}

function updateFileList() {
    fileList.innerHTML = '';
    const files = fileInput.files;
    for (let i = 0; i < files.length; i++) {
        const div = document.createElement('div');
        div.className = 'file-item';
        div.innerHTML = `
            <i class="fas fa-file me-2"></i>
            <span>${files[i].name}</span>
            <span class="ms-auto text-muted small">${(files[i].size / 1024).toFixed(1)} KB</span>
        `;
        fileList.appendChild(div);
    }
}
</script>

<?php include '../includes/components/shared_footer.php'; ?>
