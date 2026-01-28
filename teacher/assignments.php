<?php
// =================================================================
// TEACHER ASSIGNMENTS - Ödev Yönetimi
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
    if (!$auth->checkRole('teacher')) {
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
    $teacher_id = $_SESSION['user_id'];

} catch (Exception $e) {
    error_log('Teacher Assignments Error: ' . $e->getMessage());
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

// Action ve ID
$action = $_GET['action'] ?? 'list';
$assignment_id = (int)($_GET['id'] ?? 0);
$course_id = (int)($_GET['course_id'] ?? 0);

// Öğretmenin dersleri
$teacher_courses = [];
try {
    $stmt = $db->prepare("SELECT id, course_name, course_code FROM courses WHERE teacher_id = ? AND is_active = 1 ORDER BY course_name");
    $stmt->execute([$teacher_id]);
    $teacher_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Courses fetch error: ' . $e->getMessage());
}

// =================================================================
// POST İŞLEMLERİ
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    try {
        // Yeni ödev oluştur
        if ($post_action === 'create') {
            $course_id = (int)$_POST['course_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $start_date = $_POST['start_date'];
            $due_date = $_POST['due_date'];
            $max_score = (float)($_POST['max_score'] ?? 100);
            $max_files = (int)($_POST['max_files'] ?? 5);
            $allowed_extensions = trim($_POST['allowed_extensions'] ?? 'pdf,doc,docx,txt,zip,rar,jpg,png');
            
            // Validasyon
            if (empty($title)) throw new Exception('Ödev başlığı boş bırakılamaz.');
            if (empty($start_date) || empty($due_date)) throw new Exception('Tarihler boş bırakılamaz.');
            if (strtotime($due_date) <= strtotime($start_date)) throw new Exception('Bitiş tarihi başlangıç tarihinden sonra olmalıdır.');
            
            // Öğretmenin dersi mi kontrol et
            $stmt = $db->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$course_id, $teacher_id]);
            if (!$stmt->fetch()) throw new Exception('Bu derse erişim yetkiniz yok.');
            
            // Ödev oluştur
            $stmt = $db->prepare("
                INSERT INTO assignments (course_id, teacher_id, title, description, start_date, due_date, max_score, max_files, allowed_extensions)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$course_id, $teacher_id, $title, $description, $start_date, $due_date, $max_score, $max_files, $allowed_extensions]);
            $new_assignment_id = $db->lastInsertId();
            
            // Dosya yükleme
            if (isset($_FILES['attachments'])) {
                // Absolute path kullan
                $upload_dir_name = 'uploads/assignment_attachments';
                $upload_dir = dirname(__DIR__) . '/' . $upload_dir_name . '/';
                
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                         // Dosya dizisini manuel oluştur
                         $file_item = [
                            'name' => $_FILES['attachments']['name'][$key],
                            'type' => $_FILES['attachments']['type'][$key],
                            'tmp_name' => $tmp_name,
                            'error' => $_FILES['attachments']['error'][$key],
                            'size' => $_FILES['attachments']['size'][$key]
                        ];

                        // Özel isim: talimat_odevID_zaman_index
                        // odevID create aşamasında $new_assignment_id
                        $custom_name = 'talimat_' . $new_assignment_id . '_' . date('YmdHis') . '_' . $key;

                        $upload_result = secure_file_upload($file_item, $upload_dir, 'pdf,doc,docx,zip,rar,jpg,png', 25, $custom_name);
                        
                        if ($upload_result['status']) {
                            // Veritabanına göreceli yol kaydet: ../uploads/...
                            // secure_file_upload absolute path döndürebilir, bu yüzden path'i düzeltelim.
                            // Ancak fonksiyon $destination döndürüyor ve biz $upload_dir'i absolute verdik.
                            // DB'ye kaydederken web path kullanmalıyız.
                            // Teacher panelinden: ../uploads/assignment_attachments/filename
                            
                            $web_path = '../' . $upload_dir_name . '/' . $upload_result['stored_filename'];

                            try {
                                $stmt = $db->prepare("INSERT INTO assignment_attachments (assignment_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$new_assignment_id, $web_path, $upload_result['filename'], $upload_result['size']]);
                            } catch (Exception $e) { /* Tablo yoksa pass */ }
                        } else {
                            throw new Exception("Dosya yükleme hatası: " . $upload_result['message']);
                        }
                    }
                }
            }
            
            show_message('Ödev başarıyla oluşturuldu.', 'success');
            redirect('assignments.php');
            exit;
        }
        
        // Ödev güncelle
        elseif ($post_action === 'update') {
            $assignment_id = (int)$_POST['assignment_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $start_date = $_POST['start_date'];
            $due_date = $_POST['due_date'];
            $max_score = (float)($_POST['max_score'] ?? 100);
            $max_files = (int)($_POST['max_files'] ?? 5);
            $allowed_extensions = trim($_POST['allowed_extensions'] ?? 'pdf,doc,docx,txt,zip,rar,jpg,png');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validasyon
            if (empty($title)) throw new Exception('Ödev başlığı boş bırakılamaz.');
            if (strtotime($due_date) <= strtotime($start_date)) throw new Exception('Bitiş tarihi başlangıç tarihinden sonra olmalıdır.');
            
            // Öğretmenin ödevi mi kontrol et
            $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$assignment_id, $teacher_id]);
            if (!$stmt->fetch()) throw new Exception('Bu ödeve erişim yetkiniz yok.');
            
            // Güncelle
            $stmt = $db->prepare("
                UPDATE assignments SET title = ?, description = ?, start_date = ?, due_date = ?, 
                max_score = ?, max_files = ?, allowed_extensions = ?, is_active = ?
                WHERE id = ? AND teacher_id = ?
            ");
            $stmt->execute([$title, $description, $start_date, $due_date, $max_score, $max_files, $allowed_extensions, $is_active, $assignment_id, $teacher_id]);
            
            // Dosya yükleme
            if (isset($_FILES['attachments'])) {
                // Absolute path kullan
                $upload_dir_name = 'uploads/assignment_attachments';
                $upload_dir = dirname(__DIR__) . '/' . $upload_dir_name . '/';
                
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                         // Dosya dizisini manuel oluştur
                         $file_item = [
                            'name' => $_FILES['attachments']['name'][$key],
                            'type' => $_FILES['attachments']['type'][$key],
                            'tmp_name' => $tmp_name,
                            'error' => $_FILES['attachments']['error'][$key],
                            'size' => $_FILES['attachments']['size'][$key]
                        ];

                        // Özel isim: talimat_odevID_zaman_index
                        $custom_name = 'talimat_' . $assignment_id . '_' . date('YmdHis') . '_' . $key;
                        
                        $upload_result = secure_file_upload($file_item, $upload_dir, 'pdf,doc,docx,zip,rar,jpg,png', 25, $custom_name);
                        
                        if ($upload_result['status']) {
                            // Veritabanına göreceli yol kaydet: ../uploads/...
                            $web_path = '../' . $upload_dir_name . '/' . $upload_result['stored_filename'];

                            try {
                                $stmt = $db->prepare("INSERT INTO assignment_attachments (assignment_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$assignment_id, $web_path, $upload_result['filename'], $upload_result['size']]);
                            } catch (Exception $e) { /* Tablo yoksa pass */ }
                        } else {
                            throw new Exception("Dosya yükleme hatası: " . $upload_result['message']);
                        }
                    }
                }
            }

            
            show_message('Ödev başarıyla güncellendi.', 'success');
            redirect('assignments.php');
            exit;
        }
        
        // Ödev sil
        elseif ($post_action === 'delete') {
            $assignment_id = (int)$_POST['assignment_id'];
            
            // Öğretmenin ödevi mi kontrol et
            $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$assignment_id, $teacher_id]);
            if (!$stmt->fetch()) throw new Exception('Bu ödeve erişim yetkiniz yok.');
            
            // Sil (CASCADE ile dosyalar da silinir)
            $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            
            show_message('Ödev başarıyla silindi.', 'success');
            redirect('assignments.php');
            exit;
        }
        
        // Puanlama
        elseif ($post_action === 'grade') {
            $submission_id = (int)$_POST['submission_id'];
            $score = $_POST['score'] !== '' ? (float)$_POST['score'] : null;
            $feedback = trim($_POST['feedback'] ?? '');
            
            // Submission öğretmenin mi kontrol et
            $stmt = $db->prepare("
                SELECT asub.id FROM assignment_submissions asub
                JOIN assignments a ON asub.assignment_id = a.id
                WHERE asub.id = ? AND a.teacher_id = ?
            ");
            $stmt->execute([$submission_id, $teacher_id]);
            if (!$stmt->fetch()) throw new Exception('Bu gönderiye erişim yetkiniz yok.');
            
            // Puanla
            $stmt = $db->prepare("
                UPDATE assignment_submissions 
                SET score = ?, feedback = ?, graded_at = NOW(), graded_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$score, $feedback, $teacher_id, $submission_id]);
            
            show_message('Puanlama kaydedildi.', 'success');
            redirect($_SERVER['HTTP_REFERER'] ?? 'assignments.php');
            exit;
        }
        
        // Ek dosya silme
        elseif ($post_action === 'delete_attachment') {
            $attachment_id = (int)$_POST['attachment_id'];
            $assign_id = (int)$_POST['assignment_id']; // Güvenlik için
            
            // Yetki kontrolü
            $stmt = $db->prepare("SELECT aa.* FROM assignment_attachments aa JOIN assignments a ON aa.assignment_id = a.id WHERE aa.id = ? AND a.teacher_id = ?");
            $stmt->execute([$attachment_id, $teacher_id]);
            $attachment = $stmt->fetch();
            
            if ($attachment) {
                if (file_exists($attachment['file_path'])) unlink($attachment['file_path']);
                $db->prepare("DELETE FROM assignment_attachments WHERE id = ?")->execute([$attachment_id]);
                show_message('Dosya silindi.', 'success');
            } else {
                show_message('Dosya bulunamadı veya yetkiniz yok.', 'danger');
            }
            
            redirect('assignments.php?action=edit&id='.$assign_id);
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
$edit_assignment = null;
$submissions = [];

try {
    // Liste görünümü - tüm ödevler
    if ($action === 'list') {
        $query = "SELECT a.*, c.course_name, c.course_code,
                  (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count,
                  (SELECT COUNT(*) FROM course_enrollments WHERE course_id = a.course_id AND is_active = 1) as total_students
                  FROM assignments a
                  JOIN courses c ON a.course_id = c.id
                  WHERE a.teacher_id = ?
                  ORDER BY a.due_date DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$teacher_id]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Düzenleme için ödev al
    elseif ($action === 'edit' && $assignment_id > 0) {
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$assignment_id, $teacher_id]);
        $edit_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_assignment) {
            // Ek dosyaları çek
            try {
                $stmt_att = $db->prepare("SELECT * FROM assignment_attachments WHERE assignment_id = ?");
                $stmt_att->execute([$assignment_id]);
                $edit_assignment['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { 
                $edit_assignment['attachments'] = [];
            }
        }
        
        if (!$edit_assignment) {
            show_message('Ödev bulunamadı.', 'danger');
            redirect('assignments.php');
            exit;
        }
    }
    
    // Gönderileri görüntüle
    elseif ($action === 'submissions' && $assignment_id > 0) {
        // Ödev bilgisi
        $stmt = $db->prepare("
            SELECT a.*, c.course_name, c.course_code 
            FROM assignments a 
            JOIN courses c ON a.course_id = c.id 
            WHERE a.id = ? AND a.teacher_id = ?
        ");
        $stmt->execute([$assignment_id, $teacher_id]);
        $edit_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_assignment) {
            show_message('Ödev bulunamadı.', 'danger');
            redirect('assignments.php');
            exit;
        }
        
        // Gönderiler
        $stmt = $db->prepare("
            SELECT asub.*, u.full_name, u.student_number,
            (SELECT COUNT(*) FROM assignment_files WHERE submission_id = asub.id) as file_count
            FROM assignment_submissions asub
            JOIN users u ON asub.student_id = u.id
            WHERE asub.assignment_id = ?
            ORDER BY asub.submitted_at DESC
        ");
        $stmt->execute([$assignment_id]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    error_log('Assignments data error: ' . $e->getMessage());
    show_message('Veriler yüklenirken hata oluştu.', 'danger');
}

// Durum belirleme fonksiyonu
function getAssignmentStatus($assignment) {
    $now = time();
    $start = strtotime($assignment['start_date']);
    $due = strtotime($assignment['due_date']);
    
    if (!$assignment['is_active']) return ['status' => 'inactive', 'label' => 'Pasif', 'class' => 'secondary'];
    if ($now < $start) return ['status' => 'not_started', 'label' => 'Başlamadı', 'class' => 'info'];
    if ($now >= $start && $now <= $due) return ['status' => 'active', 'label' => 'Aktif', 'class' => 'success'];
    return ['status' => 'expired', 'label' => 'Süresi Doldu', 'class' => 'warning'];
}

$page_title = "Ödevler - " . $site_name;
include '../includes/components/teacher_header.php';
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
    transform: translateY(-2px);
}
.status-badge {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
}
.progress-submissions {
    height: 8px;
    border-radius: 4px;
}
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($action === 'list'): ?>
    <!-- ===================== ÖDEV LİSTESİ ===================== -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="fas fa-tasks me-2"></i>Ödevler</h4>
        <a href="assignments.php?action=create" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i>Yeni Ödev
        </a>
    </div>

    <?php if (empty($assignments)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">Henüz ödev oluşturmadınız</h5>
                <p class="text-muted">Öğrencilerinize ödev vermek için yeni bir ödev oluşturun.</p>
                <a href="assignments.php?action=create" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-1"></i>İlk Ödevi Oluştur
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($assignments as $assignment): 
                $status = getAssignmentStatus($assignment);
                $submission_percent = $assignment['total_students'] > 0 
                    ? round(($assignment['submission_count'] / $assignment['total_students']) * 100) 
                    : 0;
            ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="card assignment-card h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <span class="badge bg-<?php echo $status['class']; ?> status-badge"><?php echo $status['label']; ?></span>
                            <small class="text-muted"><?php echo htmlspecialchars($assignment['course_code']); ?></small>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($assignment['title']); ?></h5>
                            <p class="card-text text-muted small"><?php echo htmlspecialchars($assignment['course_name']); ?></p>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <?php echo date('d.m.Y H:i', strtotime($assignment['start_date'])); ?> - 
                                    <?php echo date('d.m.Y H:i', strtotime($assignment['due_date'])); ?>
                                </small>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>Gönderiler</span>
                                    <span><?php echo $assignment['submission_count']; ?>/<?php echo $assignment['total_students']; ?></span>
                                </div>
                                <div class="progress progress-submissions">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $submission_percent; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0">
                            <div class="btn-group w-100">
                                <a href="assignments.php?action=submissions&id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-inbox me-1"></i>Gönderiler
                                </a>
                                <a href="assignments.php?action=edit&id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-edit me-1"></i>Düzenle
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['title'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php elseif ($action === 'create' || $action === 'edit'): ?>
    <!-- ===================== ÖDEV OLUŞTUR/DÜZENLE ===================== -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-<?php echo $action === 'create' ? 'plus' : 'edit'; ?> me-2"></i>
                <?php echo $action === 'create' ? 'Yeni Ödev Oluştur' : 'Ödevi Düzenle'; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo $action === 'create' ? 'create' : 'update'; ?>">
                <?php if ($edit_assignment): ?>
                    <input type="hidden" name="assignment_id" value="<?php echo $edit_assignment['id']; ?>">
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Ödev Başlığı <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required 
                               value="<?php echo htmlspecialchars($edit_assignment['title'] ?? ''); ?>"
                               placeholder="Örn: Hafta 3 - Veri Yapıları Ödevi">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Ders <span class="text-danger">*</span></label>
                        <select name="course_id" class="form-select" required <?php echo $action === 'edit' ? 'disabled' : ''; ?>>
                            <option value="">Ders Seçin</option>
                            <?php foreach ($teacher_courses as $course): ?>
                                <option value="<?php echo $course['id']; ?>" 
                                    <?php echo (($edit_assignment['course_id'] ?? $course_id) == $course['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['course_name'] . ' (' . $course['course_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Açıklama</label>
                    <textarea name="description" class="form-control" rows="4" 
                              placeholder="Ödev hakkında detaylı bilgi yazın..."><?php echo htmlspecialchars($edit_assignment['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="start_date" class="form-control" required
                               value="<?php echo $edit_assignment ? date('Y-m-d\TH:i', strtotime($edit_assignment['start_date'])) : date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="due_date" class="form-control" required
                               value="<?php echo $edit_assignment ? date('Y-m-d\TH:i', strtotime($edit_assignment['due_date'])) : date('Y-m-d\TH:i', strtotime('+1 week')); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Maksimum Puan</label>
                        <input type="number" name="max_score" class="form-control" step="0.01" min="0" max="1000"
                               value="<?php echo htmlspecialchars($edit_assignment['max_score'] ?? '100'); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Maksimum Dosya Sayısı</label>
                        <input type="number" name="max_files" class="form-control" min="1" max="20"
                               value="<?php echo htmlspecialchars($edit_assignment['max_files'] ?? '5'); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">İzin Verilen Uzantılar</label>
                        <input type="text" name="allowed_extensions" class="form-control"
                               value="<?php echo htmlspecialchars($edit_assignment['allowed_extensions'] ?? 'pdf,doc,docx,txt,zip,rar,jpg,png'); ?>"
                               placeholder="pdf,doc,docx,zip">
                        <small class="text-muted">Virgülle ayırın</small>
                    </div>
                </div>
                
                <?php if ($action === 'edit'): ?>
                <div class="mb-3">
                    <label class="form-label">Talimat Dosyaları (PDF, Resim vs.)</label>
                    <input type="file" name="attachments[]" class="form-control" multiple>
                    <small class="text-muted">Birden fazla dosya seçebilirsiniz.</small>
                    
                    <?php if (!empty($edit_assignment['attachments'])): ?>
                        <div class="mt-2">
                            <h6>Yüklü Dosyalar:</h6>
                            <div class="list-group">
                                <?php foreach ($edit_assignment['attachments'] as $att): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-file me-2"></i>
                                            <a href="<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank">
                                                <?php echo htmlspecialchars($att['file_name']); ?>
                                            </a>
                                            <br>
                                            <small class="text-muted" style="font-size: 0.75rem;">
                                                <i class="fas fa-server me-1"></i>Sunucu Adı: <?php echo htmlspecialchars(basename($att['file_path'])); ?>
                                            </small>
                                            <small class="text-muted ms-2">(<?php echo round($att['file_size']/1024); ?> KB)</small>
                                        </div>
                                        <button type="button" onclick="document.forms['deleteAttachmentForm<?php echo $att['id']; ?>'].submit();" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                               <?php echo ($edit_assignment['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Ödev Aktif</label>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><?php echo $action === 'create' ? 'Oluştur' : 'Kaydet'; ?>
                    </button>
                    <a href="assignments.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i>İptal
                    </a>
                </div>
            </form>
            
            <!-- Silme Formları (Ana formun dışında olmalı) -->
            <?php if (!empty($edit_assignment['attachments'])): ?>
                <?php foreach ($edit_assignment['attachments'] as $att): ?>
                    <form id="deleteAttachmentForm<?php echo $att['id']; ?>" method="POST" style="display:none;">
                        <input type="hidden" name="action" value="delete_attachment">
                        <input type="hidden" name="attachment_id" value="<?php echo $att['id']; ?>">
                        <input type="hidden" name="assignment_id" value="<?php echo $edit_assignment['id']; ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($action === 'submissions' && $edit_assignment): ?>
    <!-- ===================== GÖNDERİLER ===================== -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><?php echo htmlspecialchars($edit_assignment['title']); ?></h4>
            <small class="text-muted"><?php echo htmlspecialchars($edit_assignment['course_name']); ?></small>
        </div>
        <a href="assignments.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i>Geri
        </a>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-inbox me-2"></i>Gönderiler (<?php echo count($submissions); ?>)</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($submissions)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Henüz gönderi yok</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Öğrenci</th>
                                <th>Numara</th>
                                <th>Gönderim Tarihi</th>
                                <th>Dosyalar</th>
                                <th>Puan</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $index => $sub): 
                                // Bu submission'ın dosyalarını çek
                                $stmt = $db->prepare("SELECT * FROM assignment_files WHERE submission_id = ? ORDER BY uploaded_at DESC");
                                $stmt->execute([$sub['id']]);
                                $sub_files = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sub['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($sub['student_number']); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($sub['submitted_at'])); ?></td>
                                <td>
                                    <?php if (count($sub_files) > 0): ?>
                                        <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" 
                                                data-bs-target="#files-<?php echo $sub['id']; ?>">
                                            <i class="fas fa-folder-open me-1"></i><?php echo count($sub_files); ?> dosya
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sub['score'] !== null): ?>
                                        <span class="badge bg-success"><?php echo $sub['score']; ?>/<?php echo $edit_assignment['max_score']; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Bekliyor</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="openGradeModal(<?php echo htmlspecialchars(json_encode($sub)); ?>, <?php echo $edit_assignment['max_score']; ?>)">
                                        <i class="fas fa-star me-1"></i>Puanla
                                    </button>
                                </td>
                            </tr>
                            <?php if (count($sub_files) > 0): ?>
                            <tr class="collapse" id="files-<?php echo $sub['id']; ?>">
                                <td colspan="6" class="bg-light p-3">
                                    <?php if (!empty($sub['submission_text'])): ?>
                                        <div class="alert alert-secondary mb-3">
                                            <h6 class="alert-heading"><i class="fas fa-comment-alt me-2"></i>Öğrenci Notu:</h6>
                                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($sub['submission_text'])); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="row">
                                        <?php foreach ($sub_files as $file): ?>
                                            <div class="col-md-6 col-lg-4 mb-2">
                                                <div class="d-flex align-items-center bg-white rounded p-2 border">
                                                    <i class="fas fa-file text-primary me-2"></i>
                                                    <div class="flex-grow-1 small text-truncate">
                                                        <?php echo htmlspecialchars($file['file_name']); ?>
                                                        <br>
                                                        <small class="text-muted"><?php echo round($file['file_size'] / 1024, 1); ?> KB</small>
                                                    </div>
                                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                                       class="btn btn-sm btn-success" download="<?php echo htmlspecialchars($file['file_name']); ?>">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Silme Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ödevi Sil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong id="deleteAssignmentTitle"></strong> ödevini silmek istediğinize emin misiniz?</p>
                <p class="text-danger"><small>Bu işlem geri alınamaz ve tüm gönderiler silinir!</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="assignment_id" id="deleteAssignmentId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">Sil</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Puanlama Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Puanlama</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="grade">
                <input type="hidden" name="submission_id" id="gradeSubmissionId">
                <div class="modal-body">
                    <p><strong>Öğrenci:</strong> <span id="gradeStudentName"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Puan</label>
                        <div class="input-group">
                            <input type="number" name="score" id="gradeScore" class="form-control" step="0.01" min="0">
                            <span class="input-group-text">/ <span id="gradeMaxScore"></span></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Geri Bildirim</label>
                        <textarea name="feedback" id="gradeFeedback" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, title) {
    document.getElementById('deleteAssignmentId').value = id;
    document.getElementById('deleteAssignmentTitle').textContent = title;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function openGradeModal(submission, maxScore) {
    document.getElementById('gradeSubmissionId').value = submission.id;
    document.getElementById('gradeStudentName').textContent = submission.full_name;
    document.getElementById('gradeScore').value = submission.score || '';
    document.getElementById('gradeScore').max = maxScore;
    document.getElementById('gradeMaxScore').textContent = maxScore;
    document.getElementById('gradeFeedback').value = submission.feedback || '';
    new bootstrap.Modal(document.getElementById('gradeModal')).show();
}
</script>

<?php include '../includes/components/shared_footer.php'; ?>
