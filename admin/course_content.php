<?php
// admin/course_content.php
// GÖRSEL İYİLEŞTİRME VE GELİŞMİŞ RAPORLAMA SÜRÜMÜ
// Sürüm 6: Liste görünümünde harici link (örn: Drive) gösterimi düzeltildi.

// --- Initialization and Security ---
require_once '../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('admin')) {
    show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
    redirect('../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// --- Configuration ---
$course_id = (int)($_REQUEST['course_id'] ?? 0); // Ana kurs ID'si
$action = $_GET['action'] ?? 'list'; // Varsayılan mod: 'list'
$material_id = (int)($_GET['id'] ?? 0); // Düzenlenen materyal ID'si

// --- Course Check ---
if (empty($course_id)) {
    show_message('Geçersiz ders kimliği.', 'danger');
    redirect('courses.php');
    exit;
}

// Kurs bilgilerini al
$stmt = $db->prepare("SELECT id, course_name FROM courses WHERE id = ?");
$stmt->execute([$course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    show_message('Ders bulunamadı.', 'danger');
    redirect('courses.php');
    exit;
}

// --- Definitions ---
$upload_dir_base = '../uploads/courses/';
$upload_dir = $upload_dir_base . $course_id . '/';
define('UPLOAD_PATH', $upload_dir);

// Helper function to format file sizes
function format_size_units($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}

// --- Form & Action Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $handler_action = $_POST['action'] ?? $action;
    $material_id_post = (int)($_POST['material_id'] ?? 0);

    try {
        $db->beginTransaction();

        switch ($handler_action) {
            case 'add':
            case 'edit':
                // Şemaya uygun alanlar
                $week_id = filter_input(INPUT_POST, 'week_id', FILTER_VALIDATE_INT);
                $material_title = filter_input(INPUT_POST, 'material_title', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $material_type = filter_input(INPUT_POST, 'material_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $material_url = filter_input(INPUT_POST, 'material_url', FILTER_SANITIZE_URL); // URL olarak temizle
                $material_description = $_POST['material_description'] ?? ''; // CKEditor
                $display_order = filter_input(INPUT_POST, 'display_order', FILTER_VALIDATE_INT) ?? 0;
                $is_required = isset($_POST['is_required']) ? 1 : 0;

                $file_path = null;
                $file_name = null;
                $file_size = null;

                // Edit modunda mevcut dosya bilgilerini al (yeni dosya yüklenmezse diye)
                $existing_material = null;
                if ($handler_action === 'edit') {
                    $stmt_check_file = $db->prepare("SELECT file_path, file_name, file_size FROM course_materials WHERE id = ?");
                    $stmt_check_file->execute([$material_id_post]);
                    $existing_material = $stmt_check_file->fetch(PDO::FETCH_ASSOC);
                    if ($existing_material) {
                        $file_path = $existing_material['file_path'];
                        $file_name = $existing_material['file_name'];
                        $file_size = $existing_material['file_size'];
                    }
                }


                if (empty($material_title) || empty($material_type) || empty($week_id)) {
                    throw new Exception("Lütfen hafta, başlık ve materyal türünü belirtin.");
                }

                // Dosya yükleme mantığı (video, document, other için)
                // Dosya yükleme mantığı (video, document, other için)
                if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                    $file_file = $_FILES['material_file'];
                    
                     // Eski dosyayı sil (Edit modunda ve yeni dosya yüklendiyse)
                    if ($handler_action === 'edit' && $existing_material && !empty($existing_material['file_path'])) {
                         $old_full_path = '../' . $existing_material['file_path'];
                         if (file_exists($old_full_path)) {
                             @unlink($old_full_path);
                         }
                    }

                    // Custom filename: material_courseID_timestamp
                    $custom_name = 'material_' . $course_id . '_' . time();
                    
                    // Dosya öğesini hazırla
                     $file_item = [
                        'name' => $file_file['name'],
                        'type' => $file_file['type'],
                        'tmp_name' => $file_file['tmp_name'],
                        'error' => $file_file['error'],
                        'size' => $file_file['size']
                    ];

                    $upload_result = secure_file_upload(
                        $file_item, 
                        UPLOAD_PATH, 
                        'pdf,doc,docx,ppt,pptx,xls,xlsx,zip,rar,txt,jpg,jpeg,png,mp4,avi,mov', 
                        50, // 50MB limit similar to teacher
                        $custom_name
                    );

                    if ($upload_result['status']) {
                        // secure_file_upload returns path as ../uploads/courses/1/filename.ext
                        // But DB expects uploads/courses/1/filename.ext (without ../) if we follow existing convention
                        // Let's check existing convention in this file:
                        // line 45: $upload_dir_base = '../uploads/courses/';
                        // line 142: $file_path = 'uploads/courses/' . $course_id . '/' . $safe_file_name;
                        
                        $file_path = ltrim($upload_result['path'], './'); // remove ./
                        if (strpos($file_path, '../') === 0) {
                            $file_path = substr($file_path, 3);
                        }
                        
                        $file_name = $file_file['name']; // Original name for display
                        $file_size = format_size_units($upload_result['size']);
                    } else {
                        throw new Exception($upload_result['message']);
                    }
                }
                // Edit modunda yeni dosya yüklenmediyse, mevcut bilgiler zaten yukarıda $file_path, $file_name, $file_size'a atandı.

                // Link tipindeyse URL zorunlu, dosya bilgilerini temizle
                if ($material_type === 'link') {
                    $file_path = null;
                    $file_name = null;
                    $file_size = null;
                    if (empty($material_url) || !filter_var($material_url, FILTER_VALIDATE_URL)) {
                        throw new Exception("Web Bağlantısı türü için geçerli bir URL girmek zorunludur.");
                    }
                } else if ($material_type === 'video') {
                     // Video tipinde hem URL hem dosya olabilir, ikisi de yoksa hata ver
                     if (empty($material_url) && empty($file_path)) {
                        throw new Exception("Video türü için bir dosya yüklemeli veya YouTube URL'si girmelisiniz.");
                     }
                } else { // document, other
                    // Dosya zorunlu (yeni eklemede veya edit'te hiç yoksa)
                    if (empty($file_path)) {
                         throw new Exception("Bu materyal türü için dosya yüklemek zorunludur.");
                    }
                    // Opsiyonel: Dosya varsa URL'yi temizle
                    // $material_url = null;
                }


                if ($handler_action === 'edit') { // Update
                    // Önceki dosyayı silmek gerekebilir mi? Şimdilik hayır, üzerine yazılıyor veya yeni isimle kaydediliyor.
                    $sql = "UPDATE course_materials SET
                                week_id = ?, material_type = ?, material_title = ?, material_url = ?,
                                file_path = ?, file_name = ?, file_size = ?,
                                material_description = ?, display_order = ?, is_required = ?
                            WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $week_id, $material_type, $material_title, $material_url,
                        $file_path, $file_name, $file_size,
                        $material_description, $display_order, $is_required,
                        $material_id_post
                    ]);
                    $_SESSION['message'] = ['text' => 'Materyal başarıyla güncellendi.', 'type' => 'success'];
                } else { // Insert
                    $sql = "INSERT INTO course_materials
                                (week_id, material_type, material_title, material_url, file_path, file_name, file_size, material_description, display_order, is_required)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $week_id, $material_type, $material_title, $material_url,
                        $file_path, $file_name, $file_size,
                        $material_description, $display_order, $is_required
                    ]);
                    $_SESSION['message'] = ['text' => 'Materyal başarıyla eklendi.', 'type' => 'success'];
                }
                break;
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = ['text' => 'Bir hata oluştu: ' . $e->getMessage(), 'type' => 'danger'];
    }

    redirect("course_content.php?course_id={$course_id}&action=list"); // Her zaman yönetim listesine dön
    exit;
}

// --- GET Request Handler (for Deletion) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'delete') {
         // Materyali alıp dosyasını silmek için
        $stmt_get = $db->prepare("SELECT file_path FROM course_materials WHERE id = ?");
        $stmt_get->execute([$material_id]);
        $material_to_delete = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if ($material_to_delete && !empty($material_to_delete['file_path'])) {
            $full_file_path = '../' . $material_to_delete['file_path'];
             if (file_exists($full_file_path)) {
                @unlink($full_file_path); // Hata kontrolü olmadan silmeyi dene
             }
        }

        // Veritabanından sil
        $stmt = $db->prepare("DELETE FROM course_materials WHERE id = ?");
        if ($stmt->execute([$material_id])) {
            $_SESSION['message'] = ['text' => 'Materyal başarıyla silindi.', 'type' => 'success'];
        } else {
            $_SESSION['message'] = ['text' => 'Materyal silinirken bir veritabanı hatası oluştu.', 'type' => 'danger'];
        }
        redirect("course_content.php?course_id={$course_id}&action=list");
        exit;
    }
}

// --- Data Fetching for Page Display ---
$page_data = [];
$page_title = $course['course_name'] . " | İçerik Yönetimi";
$page_data['course'] = $course;

// DERS HAFTALARINI AL (Form için gerekli)
try {
    $week_stmt = $db->prepare("SELECT id, week_title, week_number FROM course_weeks WHERE course_id = ? ORDER BY week_number");
    $week_stmt->execute([$course_id]);
    $page_data['weeks'] = $week_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $page_data['weeks'] = [];
    if ($action != 'reports') {
         show_message('DİKKAT: `course_weeks` tablosu bulunamadı veya hatalı. Materyal ekleyemezsiniz. Hata: ' . $e->getMessage(), 'danger');
    }
}


switch ($action) {
    case 'add':
    case 'edit':
        $page_title = $action === 'add' ? 'Yeni Materyal Ekle' : 'Materyal Düzenle';
        if ($action === 'edit') {
            $stmt = $db->prepare("SELECT * FROM course_materials WHERE id = ?");
            $stmt->execute([$material_id]);
            $page_data['content'] = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$page_data['content']) {
                show_message('Materyal bulunamadı.', 'danger');
                redirect("course_content.php?course_id={$course_id}");
                exit;
            }
        }
        break;

    case 'reports':
        $page_title = $course['course_name'] . " | Öğrenci Raporları";

        // 1. Toplam Öğrenci
        $stmt = $db->prepare("SELECT COUNT(id) FROM course_enrollments WHERE course_id = ? AND is_active = 1");
        $stmt->execute([$course_id]);
        $page_data['total_students'] = $stmt->fetchColumn() ?: 0;

        // 2. Toplam Materyal Sayısı (Gerekli olanlar ve olmayanlar)
        $stmt = $db->prepare("
            SELECT
                COUNT(cm.id) AS total_items,
                SUM(CASE WHEN cm.is_required = 1 THEN 1 ELSE 0 END) AS required_items
            FROM course_materials cm
            JOIN course_weeks cw ON cm.week_id = cw.id
            WHERE cw.course_id = ?
        ");
        $stmt->execute([$course_id]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        $page_data['total_content_items'] = (int)($counts['total_items'] ?? 0);
        $page_data['total_required_items'] = (int)($counts['required_items'] ?? 0);

        // 3. Toplam Tamamlanma Sayısı (Sadece zorunlu materyaller üzerinden)
        $total_possible_completions = $page_data['total_students'] * $page_data['total_required_items'];
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT smp.student_id, smp.material_id)
            FROM student_material_progress smp
            JOIN course_materials cm ON smp.material_id = cm.id
            JOIN course_weeks cw ON cm.week_id = cw.id
            WHERE cw.course_id = ? AND smp.is_completed = 1 AND cm.is_required = 1
        ");
        $stmt->execute([$course_id]);
        $page_data['total_completions'] = $stmt->fetchColumn() ?: 0;
        $page_data['overall_completion_percent'] = ($total_possible_completions > 0)
            ? round(($page_data['total_completions'] / $total_possible_completions) * 100)
            : 0;

        // 4. Öğrenci Bazlı Rapor
        $sql_student_report = "
            SELECT
                u.id, u.full_name, u.student_number,
                (SELECT COUNT(DISTINCT smp.material_id)
                 FROM student_material_progress smp
                 JOIN course_materials cm_inner ON smp.material_id = cm_inner.id
                 JOIN course_weeks cw_inner ON cm_inner.week_id = cw_inner.id
                 WHERE smp.student_id = u.id AND cw_inner.course_id = ?) AS viewed_items,
                (SELECT COUNT(DISTINCT smp.material_id)
                 FROM student_material_progress smp
                 JOIN course_materials cm_inner ON smp.material_id = cm_inner.id
                 JOIN course_weeks cw_inner ON cm_inner.week_id = cw_inner.id
                 WHERE smp.student_id = u.id AND cw_inner.course_id = ? AND smp.is_completed = 1 AND cm_inner.is_required = 1) AS completed_required_items
            FROM users u
            JOIN course_enrollments ce ON u.id = ce.student_id
            WHERE ce.course_id = ? AND ce.is_active = 1
            GROUP BY u.id, u.full_name, u.student_number
            ORDER BY completed_required_items DESC, u.full_name
        ";
        $stmt = $db->prepare($sql_student_report);
        $stmt->execute([$course_id, $course_id, $course_id]);
        $page_data['student_report'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. İçerik Bazlı Rapor
        $sql_content_report = "
            SELECT
                cm.id, cm.material_title, cw.week_title, cw.week_number, cm.material_type, cm.is_required,
                COUNT(DISTINCT smp.student_id) AS viewed_by_students,
                SUM(CASE WHEN smp.is_completed = 1 THEN 1 ELSE 0 END) AS completed_by_students
            FROM course_materials cm
            JOIN course_weeks cw ON cm.week_id = cw.id
            LEFT JOIN student_material_progress smp ON cm.id = smp.material_id
            WHERE cw.course_id = ?
            GROUP BY cm.id, cm.material_title, cw.week_title, cw.week_number, cm.material_type, cm.is_required
            ORDER BY cw.week_number, cm.display_order
        ";
        $stmt = $db->prepare($sql_content_report);
        $stmt->execute([$course_id]);
        $page_data['content_report'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Chart.js için Veri Hazırlama
        $chart_data = [
            'overall_completion' => [
                'completed' => $page_data['total_completions'],
                'pending' => max(0, $total_possible_completions - $page_data['total_completions']) // Negatif olmasın
            ],
            'student_performance' => ['labels' => [], 'data' => []],
            'content_engagement' => ['labels' => [], 'viewed' => [], 'completed' => []]
        ];

        // En başarılı 10 öğrenci
        $student_limit = 10;
        foreach (array_slice($page_data['student_report'], 0, $student_limit) as $student) {
            $chart_data['student_performance']['labels'][] = $student['full_name'];
            $chart_data['student_performance']['data'][] = $page_data['total_required_items'] > 0
                ? round(($student['completed_required_items'] / $page_data['total_required_items']) * 100)
                : 0;
        }

        // Materyal etkileşimi (ilk 20 materyal)
        $content_limit = 20;
        foreach (array_slice($page_data['content_report'], 0, $content_limit) as $content) {
             // Grafik etiketlerinde hafta bilgisini de ekleyelim
            $label = $content['material_title'] . ' (H' . $content['week_number'] . ')';
            $chart_data['content_engagement']['labels'][] = $label;
            $chart_data['content_engagement']['viewed'][] = $content['viewed_by_students'];
            $chart_data['content_engagement']['completed'][] = $content['completed_by_students'];
        }
        $page_data['chart_data'] = $chart_data;

        break;

    case 'list':
    default:
        $action = 'list'; // Eylemi 'list' olarak ayarla
        $stmt = $db->prepare("
            SELECT cm.*, cw.week_title, cw.week_number
            FROM course_materials cm
            LEFT JOIN course_weeks cw ON cm.week_id = cw.id
            WHERE cw.course_id = ?
            ORDER BY cw.week_number, cm.display_order
        ");
        $stmt->execute([$course_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Haftalara göre grupla
        $modules = [];
         // Haftaları önce veritabanından aldığımız sırayla (week_number) ekleyelim
        foreach ($page_data['weeks'] as $week) {
            $module_name = 'Hafta ' . $week['week_number'] . ': ' . $week['week_title'];
            $modules[$module_name] = []; // Boş bile olsa ekle
        }
        // Materyalleri ilgili haftalara yerleştir
        foreach ($items as $item) {
             $module_name = 'Hafta ' . $item['week_number'] . ': ' . $item['week_title'];
             if (isset($modules[$module_name])) { // Haftanın var olduğundan emin ol
                $modules[$module_name][] = $item;
             }
        }
        $page_data['modules'] = $modules;
        break;
}

include '../includes/components/admin_header.php';
?>
<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- CKEditor 5 Script -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>

<style>
    /* Add some visual enhancements */
    .nav-tabs .nav-link { margin-bottom: -1px; border-top-left-radius: .375rem; border-top-right-radius: .375rem; }
    .nav-tabs .nav-link.active { background-color: #f8f9fa; border-color: #dee2e6 #dee2e6 #f8f9fa; }
    .list-group-item:hover { background-color: #f8f9fa; }
    /* Modal content styling */
    #modal-material-content object, #modal-material-content iframe, #modal-material-content video { max-width: 100%; border: 1px solid #dee2e6; }
    #modal-material-description { background-color: #f1f3f5 !important; }
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">
            <a href="courses.php" class="text-decoration-none" title="Ders Listesi"><i class="fas fa-arrow-left me-2 text-muted"></i></a>
            <?php echo htmlspecialchars($course['course_name']); ?>
        </h2>
    </div>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- ### ADD/EDIT FORM VIEW (ŞEMAYA GÖRE GÜNCELLENDİ) ### -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light border-0"><h4 class="mb-0"><?php echo $page_title; ?></h4></div>
                    <div class="card-body">
                        <form method="POST" action="course_content.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="<?php echo $action; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                            <input type="hidden" name="material_id" value="<?php echo $material_id; ?>">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="week_id" class="form-label">Hafta / Modül *</label>
                                    <select class="form-select" id="week_id" name="week_id" required>
                                        <option value="">Hafta Seçin...</option>
                                        <?php foreach ($page_data['weeks'] as $week): ?>
                                            <option value="<?php echo $week['id']; ?>" <?php if(isset($page_data['content']) && $page_data['content']['week_id'] == $week['id']) echo 'selected'; ?>>
                                                Hafta <?php echo $week['week_number']; ?>: <?php echo htmlspecialchars($week['week_title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (empty($page_data['weeks'])): ?>
                                            <option value="" disabled>Bu ders için hiç hafta oluşturulmamış.</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="material_type" class="form-label">Materyal Türü *</label>
                                    <select class="form-select" id="material_type" name="material_type" required>
                                        <option value="">Seçiniz...</option>
                                        <!-- Şemadaki ENUM değerleri -->
                                        <option value="video" <?php if(isset($page_data['content']) && $page_data['content']['material_type'] == 'video') echo 'selected'; ?>>Video (YouTube/Dosya)</option>
                                        <option value="document" <?php if(isset($page_data['content']) && $page_data['content']['material_type'] == 'document') echo 'selected'; ?>>Doküman (PDF, PPT)</option>
                                        <option value="link" <?php if(isset($page_data['content']) && $page_data['content']['material_type'] == 'link') echo 'selected'; ?>>Web Bağlantısı (URL)</option>
                                        <option value="other" <?php if(isset($page_data['content']) && $page_data['content']['material_type'] == 'other') echo 'selected'; ?>>Diğer (Dosya)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3"><label for="material_title" class="form-label">Materyal Başlığı *</label><input type="text" class="form-control" id="material_title" name="material_title" value="<?php echo htmlspecialchars($page_data['content']['material_title'] ?? ''); ?>" required></div>

                            <!-- Dinamik Alanlar -->
                            <div id="field_link" class="mb-3" style="display: none;">
                                <label for="material_url" class="form-label">Web Bağlantısı (URL)</label>
                                <input type="url" class="form-control" id="material_url" name="material_url" placeholder="https://..." value="<?php echo htmlspecialchars($page_data['content']['material_url'] ?? ''); ?>">
                                <small class="form-text text-muted">Video türü için YouTube linki veya diğer türler için harici link.</small>
                            </div>

                            <div id="field_file" class="mb-3" style="display: none;">
                                <label for="material_file" class="form-label">Dosya Seç (Video/Doküman/Diğer)</label>
                                <input class="form-control" type="file" id="material_file" name="material_file">
                                <?php if ($action === 'edit' && !empty($page_data['content']['file_path'])): ?>
                                    <div class="form-text mt-2">
                                        Mevcut dosya: <a href="../<?php echo htmlspecialchars($page_data['content']['file_path']); ?>" target="_blank" class="fw-bold"><i class="fas fa-download me-1"></i><?php echo htmlspecialchars($page_data['content']['file_name'] ?? 'Dosyayı Gör'); ?></a>
                                        (Boyut: <?php echo htmlspecialchars($page_data['content']['file_size'] ?? 'Bilinmiyor'); ?>)
                                        <em class="ms-2">(Yeni dosya yüklerseniz bu dosya silinir)</em>
                                    </div>
                                    <input type="hidden" name="existing_file_path" value="<?php echo htmlspecialchars($page_data['content']['file_path']); ?>">
                                    <input type="hidden" name="existing_file_name" value="<?php echo htmlspecialchars($page_data['content']['file_name']); ?>">
                                    <input type="hidden" name="existing_file_size" value="<?php echo htmlspecialchars($page_data['content']['file_size']); ?>">
                                <?php endif; ?>
                            </div>

                            <div id="field_description" class="mb-3" style="display: block;"> <!-- Açıklama her zaman görünür -->
                                <label for="material_description" class="form-label">Açıklama / Metin İçeriği</label>
                                <textarea class="form-control" id="material_description" name="material_description" rows="10"><?php echo htmlspecialchars($page_data['content']['material_description'] ?? ''); ?></textarea>
                            </div>
                            <!-- /Dinamik Alanlar -->

                            <div class="row">
                                <div class="col-md-6 mb-3"><label for="display_order" class="form-label">Sıralama</label><input type="number" class="form-control" id="display_order" name="display_order" value="<?php echo $page_data['content']['display_order'] ?? 0; ?>" min="0"></div>
                                <div class="col-md-6 mb-3 d-flex align-items-center pt-3">
                                    <div class="form-check form-switch fs-5">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_required" name="is_required" <?php echo ($page_data['content']['is_required'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_required">Tamamlanması Zorunlu</label>
                                    </div>
                                </div>
                            </div>

                            <hr>
                            <div class="d-flex justify-content-end">
                                <a href="course_content.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary me-2">İptal</a>
                                <button type="submit" class="btn btn-primary"><?php echo $action === 'edit' ? 'Güncelle' : 'Kaydet'; ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- ### LIST AND REPORT TABS VIEW ### -->
        <ul class="nav nav-tabs mb-3" id="courseTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link fs-5 <?php if($action === 'list') echo 'active'; ?>" href="course_content.php?course_id=<?php echo $course_id; ?>&action=list"><i class="fas fa-edit me-2"></i>İçerik Yönetimi</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link fs-5 <?php if($action === 'reports') echo 'active'; ?>" href="course_content.php?course_id=<?php echo $course_id; ?>&action=reports"><i class="fas fa-chart-pie me-2"></i>Öğrenci Raporları</a>
            </li>
        </ul>

        <div class="tab-content" id="courseTabsContent">

            <!-- ======================= -->
            <!-- == İÇERİK YÖNETİMİ TAB == -->
            <!-- ======================= -->
            <div class="tab-pane fade <?php if($action === 'list') echo 'show active'; ?>" id="management" role="tabpanel">
                <div class="d-flex justify-content-end mb-3">
                    <a href="course_content.php?action=add&course_id=<?php echo $course_id; ?>" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Yeni Materyal Ekle</a>
                </div>
                <?php if (empty($page_data['modules'])): ?>
                    <div class="alert alert-info text-center">Bu ders için henüz materyal eklenmemiş.</div>
                <?php else: ?>
                    <?php foreach ($page_data['modules'] as $module_name => $contents): ?>
                        <div class="card mb-4 shadow-sm border-0">
                            <div class="card-header bg-light border-0">
                                <h5 class="mb-0"><?php echo htmlspecialchars($module_name); ?></h5>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php if (empty($contents)): ?>
                                     <li class="list-group-item text-muted text-center p-3">Bu hafta için henüz materyal eklenmemiş.</li>
                                <?php else: ?>
                                    <?php foreach ($contents as $item): ?>
                                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center p-3">
                                            <div class="d-flex align-items-center flex-grow-1 me-3 mb-2 mb-md-0" style="min-width: 300px;">
                                                <?php
                                                $icon = 'fa-file'; $color = 'text-muted';
                                                if ($item['material_type'] == 'video') { $icon = 'fa-play-circle'; $color = 'text-danger'; }
                                                if ($item['material_type'] == 'link') { $icon = 'fa-link'; $color = 'text-info'; }
                                                if ($item['material_type'] == 'document') { $icon = 'fa-file-pdf'; $color = 'text-primary'; }
                                                if ($item['material_type'] == 'other') { $icon = 'fa-question-circle'; $color = 'text-secondary';} // 'other' için ikon
                                                ?>
                                                <i class="fas <?php echo $icon; ?> <?php echo $color; ?> fa-2x me-3"></i>
                                                <div>
                                                    <strong class="d-block"><?php echo htmlspecialchars($item['material_title']); ?></strong>
                                                    <small class="text-muted">
                                                    <?php if ($item['material_type'] === 'link' && !empty($item['material_url'])): ?>
                                                        <a href="<?php echo htmlspecialchars($item['material_url']); ?>" target="_blank" class="text-truncate" style="max-width: 250px; display: inline-block;"><?php echo htmlspecialchars($item['material_url']); ?></a>
                                                    <?php elseif (!empty($item['file_path'])): ?>
                                                        <a href="../<?php echo htmlspecialchars($item['file_path']); ?>" target="_blank"><i class="fas fa-download me-1"></i><?php echo htmlspecialchars($item['file_name'] ?? basename($item['file_path'])); ?> (<?php echo htmlspecialchars($item['file_size'] ?? 'Bilinmiyor'); ?>)</a>
                                                    <?php elseif (!empty($item['material_url'])): // Dosya yoksa ama URL varsa (Drive, YouTube vb.) ?>
                                                        <a href="<?php echo htmlspecialchars($item['material_url']); ?>" target="_blank"><i class="fas fa-external-link-alt me-1"></i>Harici Link</a>
                                                    <?php elseif (!empty($item['material_description'])): ?>
                                                        (Açıklama mevcut)
                                                    <?php else: ?>
                                                        (İçerik bilgisi yok)
                                                    <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="ms-3 text-nowrap">
                                                <span class="badge bg-<?php echo $item['is_required'] ? 'danger' : 'secondary'; ?> me-2 p-2"><?php echo $item['is_required'] ? 'Zorunlu' : 'İsteğe Bağlı'; ?></span>

                                                <!-- GÖRÜNTÜLEME BUTONU -->
                                                <button type="button" class="btn btn-sm btn-info" title="Görüntüle"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#viewMaterialModal"
                                                    data-title="<?php echo htmlspecialchars($item['material_title']); ?>"
                                                    data-type="<?php echo $item['material_type']; ?>"
                                                    data-link-url="<?php echo htmlspecialchars($item['material_url'] ?? ''); ?>"
                                                    data-file-path="<?php echo htmlspecialchars($item['file_path'] ?? ''); // PDF için path lazım ?>"
                                                    data-file-url="../<?php echo htmlspecialchars($item['file_path'] ?? ''); ?>"
                                                    data-file-name="<?php echo htmlspecialchars($item['file_name'] ?? ''); ?>"
                                                    data-description="<?php echo !empty($item['material_description']) ? base64_encode($item['material_description']) : ''; // Boşsa boş string gönder ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <a href="course_content.php?action=edit&id=<?php echo $item['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-primary" title="Düzenle"><i class="fas fa-edit"></i></a>
                                                <a href="course_content.php?action=delete&id=<?php echo $item['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-danger" title="Sil" onclick="return confirm('Bu materyali kalıcı olarak silmek istediğinizden emin misiniz? Dosyası da sunucudan silinecektir.');"><i class="fas fa-trash-alt"></i></a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- MATERYAL GÖRÜNTÜLEME MODALI (İçerik Gömme Özellikli) -->
                <div class="modal fade" id="viewMaterialModal" tabindex="-1" aria-labelledby="viewMaterialModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"> <!-- Daha büyük ve kaydırılabilir -->
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="viewMaterialModalLabel">Materyal Detayı</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <!-- İçerik Alanı (JS ile doldurulacak) -->
                        <div id="modal-material-content" class="mb-3 text-center">
                            <!-- Video/PDF buraya gömülecek -->
                        </div>
                         <!-- Link/Dosya Butonları (JS ile doldurulacak) -->
                        <div id="modal-material-links" class="mb-3 text-center">
                          <!-- Link/İndirme butonları buraya gelecek -->
                        </div>
                        <hr>
                        <h6>Açıklama:</h6>
                        <div id="modal-material-description" class="p-2 bg-light rounded border" style="min-height: 100px; max-height: 300px; overflow-y: auto;">
                          <!-- JS ile doldurulacak -->
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                      </div>
                    </div>
                  </div>
                </div>

            </div>

            <!-- ======================== -->
            <!-- == ÖĞRENCİ RAPORLARI TAB == -->
            <!-- ======================== -->
            <div class="tab-pane fade <?php if($action === 'reports') echo 'show active'; ?>" id="reports" role="tabpanel">
                <!-- Rapor Özeti Kartları -->
                <div class="row mb-4">
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100 py-2">
                            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col me-2"><div class="text-xs fw-bold text-primary text-uppercase mb-1">Kayıtlı Öğrenci</div><div class="h5 mb-0 fw-bold text-gray-800"><?php echo $page_data['total_students']; ?></div></div><div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div></div></div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100 py-2">
                            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col me-2"><div class="text-xs fw-bold text-info text-uppercase mb-1">Toplam Materyal (Zorunlu)</div><div class="h5 mb-0 fw-bold text-gray-800"><?php echo $page_data['total_content_items']; ?> (<?php echo $page_data['total_required_items']; ?>)</div></div><div class="col-auto"><i class="fas fa-book-open fa-2x text-gray-300"></i></div></div></div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-12 mb-4">
                        <div class="card border-0 shadow-sm h-100 py-2">
                            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col me-2"><div class="text-xs fw-bold text-success text-uppercase mb-1">Genel Tamamlanma (Zorunlu)</div><div class="h5 mb-0 fw-bold text-gray-800"><?php echo $page_data['overall_completion_percent']; ?>%</div></div><div class="col-auto"><i class="fas fa-check-circle fa-2x text-gray-300"></i></div></div></div>
                        </div>
                    </div>
                </div>

                <!-- Grafik Alanı -->
                <div class="row">
                    <div class="col-xl-4 col-lg-5">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-light border-0"><h6 class="m-0 fw-bold text-primary">Genel Tamamlanma Oranı</h6></div>
                            <div class="card-body"><canvas id="overallCompletionChart"></canvas></div>
                        </div>
                    </div>
                    <div class="col-xl-8 col-lg-7">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-light border-0"><h6 class="m-0 fw-bold text-primary">En Başarılı 10 Öğrenci (Zorunlu Materyaller)</h6></div>
                            <div class="card-body"><canvas id="studentPerformanceChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-light border-0"><h6 class="m-0 fw-bold text-primary">Materyal Etkileşim Raporu (İlk 20 Materyal)</h6></div>
                    <div class="card-body"><canvas id="contentEngagementChart" style="min-height: 250px;"></canvas></div>
                </div>

                <!-- Rapor Tabloları -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light border-0"><ul class="nav nav-tabs card-header-tabs" id="reportSubTabs" role="tablist"><li class="nav-item" role="presentation"><button class="nav-link active" id="student-report-tab" data-bs-toggle="tab" data-bs-target="#student-report" type="button">Öğrenci Bazlı Rapor</button></li><li class="nav-item" role="presentation"><button class="nav-link" id="content-report-tab" data-bs-toggle="tab" data-bs-target="#content-report" type="button">İçerik Bazlı Rapor</button></li></ul></div>
                    <div class="card-body p-0">
                        <div class="tab-content" id="reportSubTabsContent">
                            <!-- Öğrenci Bazlı Rapor Tablosu -->
                            <div class="tab-pane fade show active" id="student-report" role="tabpanel">
                                <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th class="p-3">Öğrenci Adı</th><th>Numarası</th><th>Görüntülenen</th><th>Tamamlanan (Zorunlu)</th><th style="width: 200px;">Tamamlanma Oranı (Zorunlu)</th></tr></thead>
                                    <tbody>
                                        <?php if (!empty($page_data['student_report'])): ?>
                                             <?php if($page_data['total_required_items'] == 0): ?>
                                                  <tr><td colspan="5" class="text-center p-4 text-muted">Derse henüz zorunlu materyal eklenmemiş, oranlar hesaplanamıyor.</td></tr>
                                             <?php else: ?>
                                                 <?php foreach ($page_data['student_report'] as $student):
                                                     $progress_percent = round(($student['completed_required_items'] / $page_data['total_required_items']) * 100);
                                                 ?>
                                                 <tr>
                                                     <td class="p-3"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                     <td><?php echo htmlspecialchars($student['student_number']); ?></td>
                                                     <td><?php echo $student['viewed_items']; ?> / <?php echo $page_data['total_content_items']; ?></td>
                                                     <td><?php echo $student['completed_required_items']; ?> / <?php echo $page_data['total_required_items']; ?></td>
                                                     <td><div class="progress" style="height: 20px;" title="<?php echo $progress_percent; ?>%"><div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress_percent; ?>%;" aria-valuenow="<?php echo $progress_percent; ?>"><?php echo $progress_percent; ?>%</div></div></td>
                                                 </tr>
                                                 <?php endforeach; ?>
                                             <?php endif; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center p-4 text-muted">Derse henüz öğrenci kaydedilmemiş.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table></div>
                            </div>

                            <!-- İçerik Bazlı Rapor Tablosu -->
                            <div class="tab-pane fade" id="content-report" role="tabpanel">
                                <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th class="p-3">Modül</th><th>İçerik Başlığı</th><th>Görüntüleyen</th><th>Tamamlayan</th><th style="width: 200px;">Tamamlanma Oranı</th></tr></thead>
                                    <tbody>
                                        <?php if (!empty($page_data['content_report'])): ?>
                                             <?php if($page_data['total_students'] == 0): ?>
                                                  <tr><td colspan="5" class="text-center p-4 text-muted">Derse henüz öğrenci kaydedilmemiş, oranlar hesaplanamıyor.</td></tr>
                                             <?php else: ?>
                                                 <?php foreach ($page_data['content_report'] as $content):
                                                     // Tamamlanma oranını görüntüleyen öğrenci sayısına göre hesapla
                                                     $completion_rate = ($content['viewed_by_students'] > 0) ? round(($content['completed_by_students'] / $content['viewed_by_students']) * 100) : 0;
                                                 ?>
                                                 <tr>
                                                     <td class="p-3">H<?php echo $content['week_number']; ?>: <?php echo htmlspecialchars($content['week_title'] ?: 'Genel'); ?></td>
                                                     <td>
                                                         <?php echo htmlspecialchars($content['material_title']); ?>
                                                         <?php if($content['is_required']): ?><span class="badge bg-danger ms-2">Zorunlu</span><?php endif; ?>
                                                     </td>
                                                     <td><?php echo $content['viewed_by_students']; ?> / <?php echo $page_data['total_students']; ?></td>
                                                     <td><?php echo $content['completed_by_students']; ?> / <?php echo max(1, $content['viewed_by_students']); // Tamamlayan / Görüntüleyen (0'a bölme hatası önlemi) ?></td>
                                                     <td><div class="progress" style="height: 20px;" title="<?php echo $completion_rate; ?>%"><div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $completion_rate; ?>%;" aria-valuenow="<?php echo $completion_rate; ?>"><?php echo $completion_rate; ?>%</div></div></td>
                                                 </tr>
                                                 <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center p-4 text-muted">Derste görüntülenecek materyal bulunmuyor.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Veritabanı şemasına (course_materials) göre form dinamizmi
    const contentTypeSelect = document.getElementById('material_type');
    const descriptionField = document.getElementById('field_description');
    const linkField = document.getElementById('field_link');
    const linkInput = document.getElementById('material_url'); // Şemaya göre
    const fileField = document.getElementById('field_file');
    const descriptionInput = document.getElementById('material_description');
    let editorInstance = null;

     function initCKEditor(elementId) {
        // Zaten varsa yok et
        if (editorInstance) {
            editorInstance.destroy().catch(error => { /* console.error(error); */ });
            editorInstance = null;
        }
        // Yeniden oluştur
        const element = document.getElementById(elementId);
        if (element) {
             ClassicEditor
                .create(element, { /* CKEditor Config Options */ })
                .then(newEditor => {
                    editorInstance = newEditor;
                })
                .catch(error => { console.error( 'CKEditor initialization error:', error ); });
        }
    }


    function toggleFields() {
         const selectElement = document.getElementById('material_type');
        if (!selectElement) return; // Eğer element yoksa çık

        const selectedType = selectElement.value;


        // Alanların görünürlüğünü ayarla
        linkField.style.display = 'none';
        fileField.style.display = 'none';
        descriptionField.style.display = 'block'; // Açıklama genelde açık
        linkInput.required = false; // Varsayılan olarak zorunlu değil

        if (selectedType === 'link') {
            linkField.style.display = 'block';
            linkInput.required = true; // Link zorunlu
        } else if (selectedType === 'video') {
            linkField.style.display = 'block'; // Hem URL hem dosya olabilir
            fileField.style.display = 'block';
             // Video için URL veya dosya zorunlu, bunu form submit'te kontrol edeceğiz
        } else if (selectedType === 'document' || selectedType === 'other') {
            fileField.style.display = 'block';
            // Doküman/Diğer için dosya zorunlu, bunu form submit'te kontrol edeceğiz
        }

        // CKEditor'ü yeniden başlat (açıklama alanı görünürse)
        if (descriptionField.style.display === 'block') {
             initCKEditor('material_description');
        } else {
             if (editorInstance) {
                editorInstance.destroy().catch(error => console.error(error));
                editorInstance = null;
            }
        }
    }


    // Form yüklendiğinde ve değiştiğinde alanları ayarla
    if (contentTypeSelect) {
        toggleFields(); // İlk yüklemede çalıştır
        contentTypeSelect.addEventListener('change', toggleFields);
    } else {
         // Edit modunda select olmayabilir, yine de CKEditor'ü başlatmaya çalış
          if (descriptionField && descriptionField.style.display === 'block') {
            initCKEditor('material_description');
         }
    }


    // Submit öncesi CKEditor verisini senkronize et (Editör varsa)
    const form = document.querySelector('form[method="POST"]');
    if(form) {
        form.addEventListener('submit', function(e) {
            // CKEditor verisini al
            if (editorInstance && descriptionField.style.display === 'block') {
                descriptionInput.value = editorInstance.getData();
            }

             // Tür ve zorunluluk kontrolü
            const currentTypeSelect = document.getElementById('material_type'); // Buton submit anındaki seçimi al
            const selectedType = currentTypeSelect ? currentTypeSelect.value : null;
            const fileInput = document.getElementById('material_file');
            const hasExistingFile = document.querySelector('input[name="existing_file_path"]');
            const isEditMode = document.querySelector('input[name="material_id"]').value > 0;

            // Edit modunda, mevcut dosya bilgisini kullan
            const hasFile = (fileInput && fileInput.files && fileInput.files.length > 0) || (isEditMode && hasExistingFile && hasExistingFile.value);
            // Link inputunun varlığını kontrol et
            const hasUrl = linkInput && linkInput.value.trim() !== '' && linkInput.checkValidity(); // HTML5 validasyonunu kullan


            // Gerekli kontrolleri tekrar yap
            if (selectedType === 'link' && !hasUrl) {
                alert('Web Bağlantısı türü için geçerli bir URL girmek zorunludur.');
                 linkInput.focus(); // Hatalı alana odaklan
                e.preventDefault();
                return;
            }
            if ((selectedType === 'document' || selectedType === 'other') && !hasFile) {
                 alert('Doküman veya Diğer türü için bir dosya yüklemek zorunludur.');
                 if(fileInput) fileInput.focus();
                 e.preventDefault();
                 return;
            }
             if (selectedType === 'video' && !hasFile && !hasUrl) {
                 alert('Video türü için bir dosya yüklemeli veya bir YouTube URL\'si girmelisiniz.');
                 if(linkInput) linkInput.focus();
                 e.preventDefault();
                 return;
             }
        });
    }

    // ===================================
    // MATERYAL GÖRÜNTÜLEME MODALI
    // ===================================
    const viewModal = document.getElementById('viewMaterialModal');
    if (viewModal) {
        viewModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;

            // Veriyi çek
            const title = button.getAttribute('data-title');
            const type = button.getAttribute('data-type');
            const linkUrl = button.getAttribute('data-link-url');
            const filePath = button.getAttribute('data-file-path');
            const fileUrl = button.getAttribute('data-file-url');
            const fileName = button.getAttribute('data-file-name');
            const descriptionBase64 = button.getAttribute('data-description');

            // Modalı doldur
            const modalTitle = viewModal.querySelector('.modal-title');
            const modalContent = viewModal.querySelector('#modal-material-content');
            const modalLinks = viewModal.querySelector('#modal-material-links');
            const modalDescription = viewModal.querySelector('#modal-material-description');

            modalTitle.textContent = title;
            modalContent.innerHTML = ''; // İçerik alanını temizle
            modalLinks.innerHTML = '';   // Link alanını temizle

            // YouTube video ID'sini çıkarma fonksiyonu
            function getYouTubeId(url) {
                if (!url) return null;
                const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
                const match = url.match(regExp);
                return (match && match[2].length === 11) ? match[2] : null;
            }

            // İçeriği Gömme / Linkleri Oluşturma
            const youtubeId = (type === 'video' && linkUrl) ? getYouTubeId(linkUrl) : null;
            const isPdf = ((type === 'document' || type === 'other') && fileName && fileName.toLowerCase().endsWith('.pdf'));

            if (youtubeId) {
                // YouTube videosunu göm
                modalContent.innerHTML = `
                    <div class="ratio ratio-16x9">
                        <iframe src="https://www.youtube.com/embed/${youtubeId}" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                    </div>`;
            } else if (isPdf && fileUrl && fileUrl !== '../') { // fileUrl'nin geçerli olduğundan emin ol
                // PDF'i göm
                modalContent.innerHTML = `
                    <object data="${fileUrl}" type="application/pdf" width="100%" height="500px">
                        <p>Tarayıcınız PDF dosyalarını bu pencerede görüntülemeyi desteklemiyor. PDF'i indirmek için <a href="${fileUrl}" target="_blank">buraya tıklayın</a>.</p>
                    </object>`;
                modalLinks.innerHTML = `<a href="${fileUrl}" target="_blank" class="btn btn-secondary mt-2"><i class="fas fa-download me-2"></i>PDF'i Ayrı Sekmede Aç / İndir</a>`;
            } else if (type === 'video' && fileUrl && fileUrl !== '../') { // Yüklenmiş video dosyası
                 modalContent.innerHTML = `
                    <video controls width="100%" style="max-height: 500px;">
                        <source src="${fileUrl}"> <!-- Type otomatik algılanır genelde -->
                        Tarayıcınız video etiketini desteklemiyor. Dosyayı indirmek için aşağıdaki linki kullanın.
                    </video>`;
                 modalLinks.innerHTML = `<a href="${fileUrl}" target="_blank" class="btn btn-primary mt-2"><i class="fas fa-download me-2"></i>Video Dosyasını İndir (${fileName || 'video'})</a>`;
            } else if (type === 'link' && linkUrl) {
                // Diğer linkler
                modalLinks.innerHTML = `<a href="${linkUrl}" target="_blank" class="btn btn-info"><i class="fas fa-link me-2"></i>Web Bağlantısını Aç</a>`;
            } else if (fileUrl && fileName && fileUrl !== '../') {
                // Diğer dosyalar
                 modalLinks.innerHTML = `<a href="${fileUrl}" target="_blank" class="btn btn-primary"><i class="fas fa-download me-2"></i>Dosyayı İndir/Görüntüle (${fileName})</a>`;
            } else {
                 // Ne link ne dosya varsa (veya açıklama tek içerikse)
                 // Hiçbir içerik veya link alanı doldurulmadı
            }


            // Açıklamayı çöz ve ata
            try {
                 const decodedDescription = descriptionBase64 ? atob(descriptionBase64) : '';
                 modalDescription.innerHTML = decodedDescription ? decodedDescription : '<p class="text-muted">Açıklama girilmemiş.</p>';
            } catch (e) {
                console.error('Base64 decode hatası:', e);
                modalDescription.innerHTML = '<p class="text-danger">Açıklama yüklenirken bir hata oluştu.</p>';
            }

             // Eğer hiçbir görsel içerik yoksa (video/pdf gömülmedi) ve link de yoksa, bir mesaj göster
            if (!modalContent.innerHTML && !modalLinks.innerHTML) {
                 modalContent.innerHTML = '<p class="text-muted fst-italic">Görüntülenecek doğrudan içerik veya link bulunmuyor. Açıklamaya bakınız.</p>';
            }
        });

         // Modal kapandığında içeriği temizle
        viewModal.addEventListener('hidden.bs.modal', function () {
             const modalContent = viewModal.querySelector('#modal-material-content');
             // Videoyu durdur
             const videoElement = modalContent.querySelector('video');
             if (videoElement) { videoElement.pause(); }
             // iframe src'sini temizle (YouTube için)
             const iframeElement = modalContent.querySelector('iframe');
             if (iframeElement) { iframeElement.src = ''; }

             modalContent.innerHTML = ''; // iframe, object, video kaldırılır
             const modalDescription = viewModal.querySelector('#modal-material-description');
             modalDescription.innerHTML = '';
             const modalLinks = viewModal.querySelector('#modal-material-links');
             modalLinks.innerHTML = '';
        });
    }

    // ===================================
    // CHART.JS GÖRSELLEŞTİRME
    // ===================================
    const isReportPage = <?php echo json_encode($action === 'reports'); ?>;

    if (isReportPage) {
        const chartData = <?php echo json_encode($page_data['chart_data'] ?? null); ?>;

        if (!chartData) {
            console.error('Rapor verisi yüklenemedi.');
            // Grafikler yerine hata mesajı gösterilebilir
            const charts = ['overallCompletionChart', 'studentPerformanceChart', 'contentEngagementChart'];
            charts.forEach(id => {
                 const canvas = document.getElementById(id);
                 if(canvas) canvas.parentNode.innerHTML = '<p class="text-center text-danger mt-3">Grafik verisi yüklenemedi.</p>';
            });
            return;
        }

        Chart.defaults.font.family = "'Nunito', sans-serif"; // Genel font ayarı

        // 1. Genel Tamamlanma Grafiği (Doughnut)
        const ctxOverall = document.getElementById('overallCompletionChart');
        if (ctxOverall && chartData.overall_completion) {
             // Veri yoksa gösterme veya mesaj ver
            if(chartData.overall_completion.completed === 0 && chartData.overall_completion.pending === 0) {
                 ctxOverall.parentNode.innerHTML = '<p class="text-center text-muted mt-3">Henüz tamamlanma verisi yok.</p>';
            } else {
                new Chart(ctxOverall, {
                    type: 'doughnut',
                    data: {
                        labels: ['Tamamlandı', 'Beklemede'],
                        datasets: [{
                            data: [chartData.overall_completion.completed, chartData.overall_completion.pending],
                            backgroundColor: ['#1cc88a', '#e9ecef'], // Yeşil ve Gri
                            borderColor: ['#ffffff', '#ffffff'], // Beyaz kenarlık
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '80%', // Daha ince halka
                        plugins: {
                            legend: { display: false }, // Lejantı gizle
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed !== null) {
                                             const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                             const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) + '%' : '0%';
                                            label += `${context.parsed} (${percentage})`;
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } else if(ctxOverall) {
            ctxOverall.parentNode.innerHTML = '<p class="text-center text-muted mt-3">Genel tamamlanma verisi alınamadı.</p>';
        }

        // 2. Öğrenci Performansı Grafiği (Bar)
        const ctxStudent = document.getElementById('studentPerformanceChart');
        if (ctxStudent && chartData.student_performance && chartData.student_performance.labels.length > 0) {
            new Chart(ctxStudent, {
                type: 'bar',
                data: {
                    labels: chartData.student_performance.labels,
                    datasets: [{
                        label: 'Tamamlanma Yüzdesi (%)',
                        data: chartData.student_performance.data,
                        backgroundColor: '#4e73df', // Mavi
                        borderRadius: 4,
                        barThickness: 'flex', // Esnek kalınlık
                        maxBarThickness: 30 // Maksimum kalınlık
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Yüksekliği serbest bırak
                    indexAxis: 'y', // Yatay bar grafik
                    scales: {
                        x: { beginAtZero: true, max: 100, ticks: { callback: function(value) { return value + "%" } } },
                        y: { ticks: { autoSkip: false } }
                    },
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { return ` Tamamlanma: ${context.raw}%`; } } } }
                }
            });
        } else if (ctxStudent) {
            ctxStudent.parentNode.innerHTML = '<p class="text-center text-muted mt-3">Henüz öğrenci performans verisi yok.</p>';
        }

        // 3. Materyal Etkileşim Grafiği (Bar)
        const ctxContent = document.getElementById('contentEngagementChart');
        if (ctxContent && chartData.content_engagement && chartData.content_engagement.labels.length > 0) {
            new Chart(ctxContent, {
                type: 'bar',
                data: {
                    labels: chartData.content_engagement.labels,
                    datasets: [
                        { label: 'Görüntüleyen Öğrenci', data: chartData.content_engagement.viewed, backgroundColor: '#f6c23e', borderRadius: 4 }, // Sarı
                        { label: 'Tamamlayan Öğrenci', data: chartData.content_engagement.completed, backgroundColor: '#1cc88a', borderRadius: 4 } // Yeşil
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: <?php echo $page_data['total_students'] > 0 ? $page_data['total_students'] : 10; ?>, ticks: { stepSize: Math.max(1, Math.ceil(($page_data['total_students'] ?? 10) / 10)) } }, // Adım boyutunu dinamik ayarla
                         x: { ticks: { callback: function(value, index, values) { const label = this.getLabelForValue(value); return label.length > 20 ? label.substring(0, 18) + '...' : label; }, autoSkip: false, maxRotation: 70, minRotation: 30 } } // Döndürme ve kısaltma
                    },
                    plugins: { legend: { position: 'bottom' }, tooltip: { mode: 'index', intersect: false } }
                }
            });
        } else if (ctxContent) {
             ctxContent.parentNode.innerHTML = '<p class="text-center text-muted mt-3">Henüz materyal etkileşim verisi yok.</p>';
        }
    }
});
</script>

<?php include '../includes/components/shared_footer.php'; ?>

