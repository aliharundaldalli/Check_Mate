<?php
// === CONFIGURATION & INITIALIZATION ===
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Karakter Kodlaması
header('Content-Type: text/html; charset=utf-8');

// Oturum ve Temel Dosyalar
try {
    require_once '../includes/functions.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Yetkilendirme
    $auth = new Auth();
    if (!$auth->isLoggedIn() || !$auth->checkRole('teacher')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../login.php');
        exit;
    }

    // Veritabanı Bağlantısı
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    $db->exec("SET NAMES utf8mb4");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Değişkenler
    $teacher_id = (int)$_SESSION['user_id'];
    $course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);

    if ($course_id === false || $course_id <= 0) {
        show_message('Geçersiz ders ID.', 'danger');
        redirect('courses.php');
        exit;
    }

} catch (Throwable $e) {
    error_log('Teacher Course Materials Setup Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    die('Sistem başlatılırken kritik bir hata oluştu. Lütfen sistem yöneticisine başvurun.');
}

// === HELPER FUNCTIONS ===

/**
 * Dosya yükleme işlemini gerçekleştirir ve doğrular.
 */
function handleMaterialFileUpload(array $file, int $course_id, int $week_id, string $base_upload_path): array {
    // 1. Hedef Klasör Oluşturma
    // $base_upload_path genellikle '../uploads/course_materials'
    $course_folder = $base_upload_path . '/course_' . $course_id;
    $week_folder = $course_folder . '/week_' . $week_id . '/';
    
    // 2. İzin Verilen Uzantılar
    $allowed_extensions = 'pdf,doc,docx,ppt,pptx,xls,xlsx,zip,rar,txt,jpg,jpeg,png';
    
    // 3. Dosya Boyutu (MB)
    $max_size_mb = 50;
    
    // 4. Güvenli Dosya Adı Oluşturma Temeli
    // secure_file_upload bizim için uzantıyı ekleyecek, sadece "basename" veriyoruz.
    $original_filename = $file['name'];
    $filename_without_ext = pathinfo($original_filename, PATHINFO_FILENAME);
    $safe_name_base = preg_replace('/[^\p{L}\p{N}\s._-]+/u', '_', $filename_without_ext);
    $safe_name_base = preg_replace('/\s+/', '_', $safe_name_base);
    $safe_name_base = trim($safe_name_base, '_');
    $safe_name_base = mb_substr($safe_name_base, 0, 100, 'UTF-8');
    if (empty($safe_name_base)) { $safe_name_base = 'dosya'; }
    
    // Benzersizlik için suffix ekleyelim (secure_file_upload üzerine yazmayı engellemek için uniqid kullanmıyor, biz veriyoruz)
    $unique_suffix = substr(bin2hex(random_bytes(6)), 0, 8);
    $custom_filename = $safe_name_base . '_' . $unique_suffix; // Uzantı yok, fonksiyon ekleyecek

    // 5. Yükleme İşlemi
    $upload_result = secure_file_upload($file, $week_folder, $allowed_extensions, $max_size_mb, $custom_filename);

    if (!$upload_result['status']) {
        throw new Exception($upload_result['message']);
    }

    // 6. DB için relative path
    // secure_file_upload 'path' olarak full destination path dönüyor (örn: ../uploads/.../file.ext)
    // Bizim DB'ye kaydettiğimiz format: uploads/course_materials/course_X/week_Y/file.ext (başında ../ olmadan)
    
    // path: ../uploads/course_materials/course_1/week_1/file.pdf
    // relative: uploads/course_materials/course_1/week_1/file.pdf
    
    $full_path = $upload_result['path'];
    // '../' silinmeli
    $relative_path_for_db = ltrim($full_path, './'); // . ve / temizler, ama '../' durumuna dikkat.
    if (strpos($full_path, '../') === 0) {
        $relative_path_for_db = substr($full_path, 3);
    }

    return [
        'file_path'      => $relative_path_for_db,
        'file_name'      => $original_filename,
        'file_size'      => $upload_result['size'], // Byte
        'file_extension' => pathinfo($upload_result['stored_filename'], PATHINFO_EXTENSION)
    ];
}

/**
 * Byte formatı
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 1) {
        if (!is_numeric($bytes) || $bytes < 0) return '0 B';
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($bytes, 1024);
        $floor = floor($base);
        $index = min((int)$floor, count($units) - 1);
        return round(pow(1024, $base - $floor), $precision) . ' ' . $units[$index];
    }
}

// === DATA FETCHING ===
$weeks = [];
$materials_by_week = [];
$course = null;
$site_name = 'AhdaKade Yoklama Sistemi';
$total_students = 0;
$progress_counts = [];
$uploads_base = '../uploads/course_materials';

// Site Ayarları
try {
    $stmt_settings = $db->prepare("SELECT setting_key, setting_value FROM site_settings");
    $stmt_settings->execute();
    $site_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = htmlspecialchars($site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi', ENT_QUOTES, 'UTF-8');
} catch (PDOException $e) {
    error_log('Site settings fetch PDO error: ' . $e->getMessage());
    show_message('Site ayarları yüklenirken bir veritabanı hatası oluştu.', 'warning');
}

// Ders Bilgileri
try {
    if ($course_id > 0) {
        $query_course = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
        $stmt_course = $db->prepare($query_course);
        $stmt_course->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt_course->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt_course->execute();
        $course = $stmt_course->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            show_message('Bu derse erişim yetkiniz bulunmamaktadır veya ders aktif değil.', 'warning');
            redirect('courses.php');
            exit;
        }
    } else {
        show_message('Geçersiz ders seçimi.', 'danger');
        redirect('courses.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Course fetch PDO error: ' . $e->getMessage());
    show_message('Ders bilgileri alınırken bir veritabanı hatası oluştu.', 'danger');
    $page_title = "Hata - " . $site_name;
    include '../includes/components/teacher_header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Ders bilgileri alınamadı. Lütfen <a href="courses.php">ders listesine</a> geri dönün.</div></div>';
    include '../includes/components/shared_footer.php';
    exit;
}

// Haftalar
try {
    $query_weeks = "SELECT * FROM course_weeks WHERE course_id = :course_id ORDER BY week_number ASC";
    $stmt_weeks = $db->prepare($query_weeks);
    $stmt_weeks->bindParam(':course_id', $course_id, PDO::PARAM_INT);
    $stmt_weeks->execute();
    $weeks = $stmt_weeks->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Weeks fetch PDO error: ' . $e->getMessage());
    show_message('Haftalık içerikler yüklenirken bir veritabanı hatası oluştu.', 'danger');
}

// Materyaller
if (!empty($weeks)) {
    try {
        $week_ids = array_column($weeks, 'id');
        if (!empty($week_ids)) {
            $placeholders = str_repeat('?,', count($week_ids) - 1) . '?';
            $query_materials = "SELECT id, week_id, material_type, material_title, material_url,
                                       file_path, file_name, material_description, duration_minutes,
                                       file_size, display_order, is_required, created_at, updated_at
                                FROM course_materials
                                WHERE week_id IN ($placeholders)
                                ORDER BY week_id, display_order ASC, id ASC";
            $stmt_materials = $db->prepare($query_materials);
            $stmt_materials->execute($week_ids);
            $all_materials = $stmt_materials->fetchAll(PDO::FETCH_ASSOC);

            foreach ($all_materials as $material) {
                $materials_by_week[$material['week_id']][] = $material;
            }
        }
    } catch (PDOException $e) {
        error_log('Materials fetch PDO error: ' . $e->getMessage());
        show_message('Materyaller yüklenirken bir veritabanı hatası oluştu.', 'danger');
    }
}

// Öğrenci Sayısı
try {
    $query_students = "SELECT COUNT(DISTINCT u.id) as student_count
                       FROM course_enrollments ce
                       JOIN users u ON ce.student_id = u.id
                       WHERE ce.course_id = :course_id AND ce.is_active = 1 AND u.is_active = 1 AND u.user_type = 'student'";
    $stmt_students = $db->prepare($query_students);
    $stmt_students->bindParam(':course_id', $course_id, PDO::PARAM_INT);
    $stmt_students->execute();
    $result_students = $stmt_students->fetch(PDO::FETCH_ASSOC);
    $total_students = $result_students ? (int)$result_students['student_count'] : 0;
} catch (PDOException $e) {
    error_log('Student count fetch PDO error: ' . $e->getMessage());
    show_message('Öğrenci sayısı alınırken bir veritabanı hatası oluştu.', 'warning');
}

// Tamamlanma Sayıları
try {
    if ($total_students > 0 && !empty($weeks)) {
        $all_material_ids = [];
        foreach($materials_by_week as $mats) {
            if (is_array($mats)) {
                $all_material_ids = array_merge($all_material_ids, array_column($mats, 'id'));
            }
        }
        $all_material_ids = array_unique(array_filter($all_material_ids, 'is_numeric'));

        if (!empty($all_material_ids)) {
            $in_params = [];
            foreach ($all_material_ids as $index => $id) {
                $in_params[":matid{$index}"] = $id;
            }
            $in_clause = implode(',', array_keys($in_params));

            $query_progress = "SELECT smp.material_id, COUNT(DISTINCT smp.student_id) as completed_count
                               FROM student_material_progress smp
                               JOIN course_materials cm ON smp.material_id = cm.id
                               JOIN course_weeks cw ON cm.week_id = cw.id
                               WHERE cw.course_id = :course_id
                                 AND smp.material_id IN ({$in_clause})
                                 AND smp.is_completed = 1
                               GROUP BY smp.material_id";

            $stmt_progress = $db->prepare($query_progress);
            $stmt_progress->bindValue(':course_id', $course_id, PDO::PARAM_INT);
            foreach ($in_params as $placeholder => $id) {
                $stmt_progress->bindValue($placeholder, $id, PDO::PARAM_INT);
            }

            $stmt_progress->execute();
            $progress_counts = $stmt_progress->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
} catch (PDOException $e) {
    error_log('Progress fetch PDO error: ' . $e->getMessage());
    show_message('Öğrenci ilerleme durumları alınırken bir veritabanı hatası oluştu.', 'warning');
}

// === POST REQUEST HANDLING ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_hash = '';

    try {
        $db->beginTransaction();

        // --- Hafta Ekleme ---
        if ($action === 'add_week') {
            $week_number = filter_input(INPUT_POST, 'week_number', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $week_title = trim(htmlspecialchars($_POST['week_title'] ?? '', ENT_QUOTES, 'UTF-8'));
            $week_description = trim(htmlspecialchars($_POST['week_description'] ?? '', ENT_QUOTES, 'UTF-8'));
            $is_published = isset($_POST['is_published']) ? 1 : 0;
            $publish_date_input = filter_input(INPUT_POST, 'publish_date', FILTER_SANITIZE_URL);
            $publish_date = (!empty($publish_date_input) && strtotime($publish_date_input)) ? date('Y-m-d', strtotime($publish_date_input)) : null;

            if ($week_number === false || $week_number === null || empty($week_title)) {
                throw new Exception('Hafta numarası (pozitif tam sayı) ve başlık zorunludur.');
            }
            $stmt_check = $db->prepare("SELECT 1 FROM course_weeks WHERE course_id = ? AND week_number = ?");
            $stmt_check->execute([$course_id, $week_number]);
            if ($stmt_check->fetchColumn()) {
                throw new Exception('Bu hafta numarası zaten kullanılmaktadır.');
            }
            $sql = "INSERT INTO course_weeks (course_id, week_number, week_title, week_description, is_published, publish_date) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_insert = $db->prepare($sql);
            $stmt_insert->execute([$course_id, $week_number, $week_title, $week_description, $is_published, $publish_date]);
            $new_week_id = $db->lastInsertId();
            $redirect_hash = "#heading{$new_week_id}";
            $db->commit();
            show_message('Hafta başarıyla eklendi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        // --- Hafta Güncelleme ---
        elseif ($action === 'update_week') {
            $week_id = filter_input(INPUT_POST, 'week_id', FILTER_VALIDATE_INT);
            $week_number = filter_input(INPUT_POST, 'week_number', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $week_title = trim(htmlspecialchars($_POST['week_title'] ?? '', ENT_QUOTES, 'UTF-8'));
            $week_description = trim(htmlspecialchars($_POST['week_description'] ?? '', ENT_QUOTES, 'UTF-8'));
            $is_published = isset($_POST['is_published']) ? 1 : 0;
            $publish_date_input = filter_input(INPUT_POST, 'publish_date', FILTER_SANITIZE_URL);
            $publish_date = (!empty($publish_date_input) && strtotime($publish_date_input)) ? date('Y-m-d', strtotime($publish_date_input)) : null;

            if ($week_id === false || $week_id === null || $week_number === false || $week_number === null || empty($week_title)) {
                throw new Exception('Hafta ID, numara (pozitif tam sayı) ve başlık zorunludur.');
            }
            $stmt_owner = $db->prepare("SELECT 1 FROM course_weeks cw JOIN courses c ON cw.course_id = c.id WHERE cw.id = ? AND c.teacher_id = ?");
            $stmt_owner->execute([$week_id, $teacher_id]);
            if (!$stmt_owner->fetchColumn()) {
                throw new Exception('Hafta bulunamadı veya düzenleme yetkiniz yok.');
            }
            $stmt_check_num = $db->prepare("SELECT 1 FROM course_weeks WHERE course_id = ? AND week_number = ? AND id != ?");
            $stmt_check_num->execute([$course_id, $week_number, $week_id]);
            if ($stmt_check_num->fetchColumn()) {
                throw new Exception('Bu hafta numarası zaten başka bir hafta için kullanılıyor.');
            }
            $sql = "UPDATE course_weeks SET week_number = ?, week_title = ?, week_description = ?, is_published = ?, publish_date = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql);
            $stmt_update->execute([$week_number, $week_title, $week_description, $is_published, $publish_date, $week_id]);
            $redirect_hash = "#heading{$week_id}";
            $db->commit();
            show_message('Hafta başarıyla güncellendi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        // --- Hafta Silme ---
        elseif ($action === 'delete_week') {
            $week_id = filter_input(INPUT_POST, 'week_id', FILTER_VALIDATE_INT);
            if ($week_id === false || $week_id === null) { throw new Exception('Geçersiz hafta ID.'); }
            $stmt_owner = $db->prepare("SELECT 1 FROM course_weeks cw JOIN courses c ON cw.course_id = c.id WHERE cw.id = ? AND c.teacher_id = ?");
            $stmt_owner->execute([$week_id, $teacher_id]);
            if (!$stmt_owner->fetchColumn()) { throw new Exception('Hafta bulunamadı veya silme yetkiniz yok.'); }
            $stmt_mats = $db->prepare("SELECT id, file_path FROM course_materials WHERE week_id = ?");
            $stmt_mats->execute([$week_id]);
            $materials = $stmt_mats->fetchAll(PDO::FETCH_ASSOC);
            $material_ids = array_column($materials, 'id');
            foreach ($materials as $material) {
                if (!empty($material['file_path'])) {
                    $full_path = '../' . ltrim($material['file_path'], '/');
                    if (file_exists($full_path)) { if (!@unlink($full_path)) { error_log("Dosya silinemedi (hafta silme): " . $full_path); } }
                    else { error_log("Silinecek dosya bulunamadı (hafta silme): " . $full_path); }
                }
            }
            if (!empty($material_ids)) {
                $placeholders = str_repeat('?,', count($material_ids) - 1) . '?';
                $stmt_del_prog = $db->prepare("DELETE FROM student_material_progress WHERE material_id IN ($placeholders)");
                $stmt_del_prog->execute($material_ids);
            }
            $stmt_del_mats = $db->prepare("DELETE FROM course_materials WHERE week_id = ?");
            $stmt_del_mats->execute([$week_id]);
            $stmt_del_week = $db->prepare("DELETE FROM course_weeks WHERE id = ?");
            $stmt_del_week->execute([$week_id]);
            $db->commit();
            show_message('Hafta ve tüm materyalleri başarıyla silindi.', 'success');
            redirect("course_materials.php?course_id={$course_id}");
            exit;
        }
        
        // --- Materyal Ekleme ---
        elseif ($action === 'add_material') {
            $week_id = filter_input(INPUT_POST, 'week_id', FILTER_VALIDATE_INT);
            $material_type_input = filter_input(INPUT_POST, 'material_type', FILTER_SANITIZE_URL);
            $allowed_material_types = ['video', 'document', 'link', 'other'];
            $material_type = in_array($material_type_input, $allowed_material_types) ? $material_type_input : null;
            $material_title = trim(htmlspecialchars($_POST['material_title'] ?? '', ENT_QUOTES, 'UTF-8'));
            $material_description = trim(htmlspecialchars($_POST['material_description'] ?? '', ENT_QUOTES, 'UTF-8'));
            $material_url = trim(filter_input(INPUT_POST, 'material_url', FILTER_VALIDATE_URL) ?: '');
            $duration_minutes = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $is_required = isset($_POST['is_required']) ? 1 : 0;

            if ($week_id === false || $week_id === null || empty($material_title) || $material_type === null) {
                throw new Exception('Geçersiz hafta ID, materyal türü veya başlık.');
            }
            $stmt_owner = $db->prepare("SELECT 1 FROM course_weeks cw JOIN courses c ON cw.course_id = c.id WHERE cw.id = ? AND c.teacher_id = ?");
            $stmt_owner->execute([$week_id, $teacher_id]);
            if (!$stmt_owner->fetchColumn()) {
                throw new Exception('Hafta bulunamadı veya bu haftaya materyal ekleme yetkiniz yok.');
            }
            $stmt_order = $db->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM course_materials WHERE week_id = ?");
            $stmt_order->execute([$week_id]);
            $display_order = $stmt_order->fetchColumn();

            $file_path = null; $file_name = null; $file_size_display = null;
            if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                $upload_result = handleMaterialFileUpload($_FILES['material_file'], $course_id, $week_id, $uploads_base);
                $file_path = $upload_result['file_path'];
                $file_name = $upload_result['file_name'];
                $file_size_bytes = $upload_result['file_size'];
                $file_size_display = function_exists('formatBytes') ? formatBytes($file_size_bytes) : round($file_size_bytes / 1024 / 1024, 2) . ' MB';
            } elseif (isset($_FILES['material_file']) && $_FILES['material_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception('Dosya yüklenirken bir sorun oluştu. Hata kodu: ' . $_FILES['material_file']['error']);
            }
            if ($material_type === 'video' && empty($material_url)) { throw new Exception('Video tipi için YouTube URL zorunludur.'); }
            if ($material_type === 'link' && empty($material_url)) { throw new Exception('Link tipi için URL zorunludur.'); }
            if ($material_type === 'document' && empty($file_path) && empty($material_url)) { throw new Exception('Döküman tipi için dosya yüklemeli veya harici link sağlamalısınız.'); }

            $sql = "INSERT INTO course_materials (week_id, material_type, material_title, material_url, material_description, duration_minutes, file_size, display_order, is_required, file_path, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $db->prepare($sql);
            $stmt_insert->execute([ $week_id, $material_type, $material_title, $material_url, $material_description, $duration_minutes, $file_size_display, $display_order, $is_required, $file_path, $file_name ]);
            $redirect_hash = "#heading{$week_id}";
            $db->commit();
            show_message('Materyal başarıyla eklendi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        // --- Materyal Güncelleme ---
        elseif ($action === 'update_material') {
            $material_id = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            $material_type_input = filter_input(INPUT_POST, 'material_type', FILTER_SANITIZE_URL);
            $allowed_material_types = ['video', 'document', 'link', 'other'];
            $material_type = in_array($material_type_input, $allowed_material_types) ? $material_type_input : null;
            $material_title = trim(htmlspecialchars($_POST['material_title'] ?? '', ENT_QUOTES, 'UTF-8'));
            $material_description = trim(htmlspecialchars($_POST['material_description'] ?? '', ENT_QUOTES, 'UTF-8'));
            $material_url = trim(filter_input(INPUT_POST, 'material_url', FILTER_VALIDATE_URL) ?: '');
            $duration_minutes = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $replace_file = isset($_POST['replace_file']) ? 1 : 0;

            if ($material_id === false || $material_id === null || empty($material_title) || $material_type === null) {
                throw new Exception('Geçersiz materyal ID, materyal türü veya başlık.');
            }

            $stmt_mat = $db->prepare("SELECT cm.*, cw.id as week_id FROM course_materials cm JOIN course_weeks cw ON cm.week_id = cw.id JOIN courses c ON cw.course_id = c.id WHERE cm.id = ? AND c.teacher_id = ?");
            $stmt_mat->execute([$material_id, $teacher_id]);
            $existing_material = $stmt_mat->fetch(PDO::FETCH_ASSOC);
            if (!$existing_material) {
                throw new Exception('Materyal bulunamadı veya düzenleme yetkiniz yok.');
            }

            $old_week_id = $existing_material['week_id'];
            $file_path = $existing_material['file_path'];
            $file_name = $existing_material['file_name'];
            $file_size_display = $existing_material['file_size'];

            if ($replace_file && isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                if (!empty($existing_material['file_path'])) {
                    $old_file_path = '../' . ltrim($existing_material['file_path'], '/');
                    if (file_exists($old_file_path)) {
                        @unlink($old_file_path);
                    }
                }
                
                $upload_result = handleMaterialFileUpload($_FILES['material_file'], $course_id, $old_week_id, $uploads_base);
                $file_path = $upload_result['file_path'];
                $file_name = $upload_result['file_name'];
                $file_size_bytes = $upload_result['file_size'];
                $file_size_display = function_exists('formatBytes') ? formatBytes($file_size_bytes) : round($file_size_bytes / 1024 / 1024, 2) . ' MB';
            }

            if ($material_type === 'video' && empty($material_url)) {
                throw new Exception('Video tipi için YouTube URL zorunludur.');
            }
            if ($material_type === 'link' && empty($material_url)) {
                throw new Exception('Link tipi için URL zorunludur.');
            }
            if ($material_type === 'document' && empty($file_path) && empty($material_url)) {
                throw new Exception('Döküman tipi için dosya veya harici link sağlamalısınız.');
            }

            $sql = "UPDATE course_materials SET material_type = ?, material_title = ?, material_url = ?, material_description = ?, duration_minutes = ?, file_size = ?, is_required = ?, file_path = ?, file_name = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $db->prepare($sql);
            $stmt_update->execute([$material_type, $material_title, $material_url, $material_description, $duration_minutes, $file_size_display, $is_required, $file_path, $file_name, $material_id]);
            
            $redirect_hash = "#heading{$old_week_id}";
            $db->commit();
            show_message('Materyal başarıyla güncellendi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        // --- Materyal Silme ---
        elseif ($action === 'delete_material') {
            $material_id = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            if ($material_id === false || $material_id === null) { throw new Exception('Geçersiz materyal ID.'); }
            $stmt_mat = $db->prepare("SELECT cm.id, cm.file_path, cw.id as week_id FROM course_materials cm JOIN course_weeks cw ON cm.week_id = cw.id JOIN courses c ON cw.course_id = c.id WHERE cm.id = ? AND c.teacher_id = ?");
            $stmt_mat->execute([$material_id, $teacher_id]);
            $material = $stmt_mat->fetch(PDO::FETCH_ASSOC);
            if (!$material) { throw new Exception('Materyal bulunamadı veya silme yetkiniz yok.'); }
            $deleted_week_id = $material['week_id'];
            if (!empty($material['file_path'])) {
                $full_path = '../' . ltrim($material['file_path'], '/');
                if (file_exists($full_path)) { if(!@unlink($full_path)) { error_log("Dosya silinemedi (materyal silme): " . $full_path); } }
                else { error_log("Silinecek dosya bulunamadı (materyal silme): " . $full_path); }
            }
            $stmt_del_prog = $db->prepare("DELETE FROM student_material_progress WHERE material_id = ?");
            $stmt_del_prog->execute([$material_id]);
            $stmt_del_mat = $db->prepare("DELETE FROM course_materials WHERE id = ?");
            $stmt_del_mat->execute([$material_id]);
            $redirect_hash = "#heading{$deleted_week_id}";
            $db->commit();
            show_message('Materyal başarıyla silindi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        // --- Materyal Sıralama ---
        elseif ($action === 'reorder_material') {
            $material_id = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            $direction = filter_input(INPUT_POST, 'direction', FILTER_SANITIZE_URL);
            
            if ($material_id === false || $material_id === null || !in_array($direction, ['up', 'down'])) {
                throw new Exception('Geçersiz sıralama parametreleri.');
            }

            $stmt_mat = $db->prepare("SELECT cm.*, cw.id as week_id FROM course_materials cm JOIN course_weeks cw ON cm.week_id = cw.id JOIN courses c ON cw.course_id = c.id WHERE cm.id = ? AND c.teacher_id = ?");
            $stmt_mat->execute([$material_id, $teacher_id]);
            $material = $stmt_mat->fetch(PDO::FETCH_ASSOC);
            
            if (!$material) {
                throw new Exception('Materyal bulunamadı veya düzenleme yetkiniz yok.');
            }

            $current_order = $material['display_order'];
            $week_id = $material['week_id'];

            if ($direction === 'up') {
                $stmt_target = $db->prepare("SELECT id, display_order FROM course_materials WHERE week_id = ? AND display_order < ? ORDER BY display_order DESC LIMIT 1");
                $stmt_target->execute([$week_id, $current_order]);
            } else {
                $stmt_target = $db->prepare("SELECT id, display_order FROM course_materials WHERE week_id = ? AND display_order > ? ORDER BY display_order ASC LIMIT 1");
                $stmt_target->execute([$week_id, $current_order]);
            }
            
            $target_material = $stmt_target->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_material) {
                throw new Exception('Bu yönde taşıma yapılamaz.');
            }

            $target_order = $target_material['display_order'];
            $stmt_swap1 = $db->prepare("UPDATE course_materials SET display_order = ? WHERE id = ?");
            $stmt_swap2 = $db->prepare("UPDATE course_materials SET display_order = ? WHERE id = ?");
            
            $stmt_swap1->execute([$target_order, $material_id]);
            $stmt_swap2->execute([$current_order, $target_material['id']]);

            $redirect_hash = "#heading{$week_id}";
            $db->commit();
            show_message('Materyal sırası başarıyla değiştirildi.', 'success');
            redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
            exit;
        }
        
        else {
            $db->rollBack();
            show_message('Geçersiz işlem isteği.', 'warning');
        }

    } catch (PDOException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log("Course Material PDO Error: " . $e->getMessage() . " | Action: " . $action);
        show_message('Veritabanı işlemi sırasında bir hata oluştu. Lütfen tekrar deneyin.', 'danger');
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log("Course Material General Error: " . $e->getMessage() . " | Action: " . $action);
        show_message('İşlem sırasında beklenmedik bir hata oluştu: ' . htmlspecialchars($e->getMessage()), 'danger');
    }

    $redirect_hash = '';
    if (!empty($_POST['week_id']) && is_numeric($_POST['week_id'])) {
        $redirect_hash = '#heading' . (int)$_POST['week_id'];
    } elseif (!empty($deleted_week_id)) {
        $redirect_hash = '#heading' . $deleted_week_id;
    }

    if (!isset($_SESSION['message'])) {
        show_message('İşlem tamamlanamadı. Lütfen bilgileri kontrol edip tekrar deneyin.', 'warning');
    }

    redirect("course_materials.php?course_id={$course_id}{$redirect_hash}");
    exit;
}

// === PAGE DISPLAY ===
$page_title = "İçerik Yönetimi - " . htmlspecialchars($course['course_name'] ?? 'Bilinmeyen Ders') . " - " . $site_name;
include '../includes/components/teacher_header.php';
?>

<style>
    /* Genel İyileştirmeler */
    body { background-color: #f8f9fa; }
    .container-fluid { max-width: 1200px; }
    .alert { box-shadow: 0 2px 5px rgba(0,0,0,0.1); border: none; }
    .btn { box-shadow: 0 1px 3px rgba(0,0,0,0.1); transition: all 0.2s ease; }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
    .fs-sm { font-size: 0.9rem; }
    .fw-medium { font-weight: 500; }

    /* Akordiyon Stilleri */
    .accordion-item {
        border: 1px solid #dee2e6;
        margin-bottom: 1rem;
        border-radius: 0.5rem;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        background-color: #fff;
    }
    .accordion-header { padding: 0; }
    .accordion-button {
        padding: 1rem 1.25rem;
        font-weight: 600;
        color: #343a40;
        background-color: #fff;
        border: none;
        width: 100%; 
        text-align: left; 
        display: flex;
        justify-content: space-between; 
        align-items: center;
        transition: background-color 0.15s ease-in-out;
        text-decoration: none;
    }
    .accordion-button:not(.collapsed) {
        color: #0d6efd;
        background-color: #eef5ff;
        box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.08);
    }
    .accordion-button:focus { box-shadow: none; border: none; outline: none; }
    .accordion-button::after { margin-left: auto; transition: transform 0.2s ease-in-out; }

    .accordion-button.unpublished { background-color: #f8f9fa; color: #6c757d; }
    .accordion-button.unpublished:not(.collapsed) { background-color: #e2e3e5; color: #495057;}
    .accordion-button:hover { background-color: #f8f9fa; text-decoration: none;}
    .accordion-button.published:hover { background-color: #f0f6ff; }
    .accordion-button.unpublished:hover { background-color: #e9ecef;}

    .week-header-content { flex-grow: 1; margin-right: 1rem; }
    .week-title { font-size: 1.15rem; }
    .week-title i { color: #0d6efd; }
    .week-meta { font-size: 0.85rem; color: #6c757d; margin-top: 0.3rem; }
    .week-meta .badge { font-size: 0.75rem; vertical-align: middle; padding: 0.3em 0.6em; font-weight: 500;}
    .bg-success-light { background-color: #d1e7dd; border: 1px solid #a3cfbb; color: #0a3622 !important; }
    .bg-secondary-light { background-color: #e2e3e5; border: 1px solid #d3d6d8; color: #41464b !important; }

    .week-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
    .week-actions .btn { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
    .week-actions .btn-outline-primary:hover,
    .week-actions .btn-outline-success:hover,
    .week-actions .btn-outline-danger:hover { color: white; }

    .accordion-body { padding: 1.5rem; background-color: #ffffff; }

    /* Materyal Kartı Stilleri */
    .material-card {
        border: 1px solid #e9ecef; 
        border-left-width: 4px;
        border-radius: 0.375rem; 
        margin-bottom: 1.25rem;
        background-color: #fff; 
        transition: transform 0.2s ease, box-shadow 0.2s ease; 
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        position: relative;
    }
    .accordion-body .material-card:last-child { margin-bottom: 0; }

    .material-card:hover { 
        box-shadow: 0 8px 20px rgba(0,0,0,0.1) !important;
        transform: translateY(-2px);
    }
    .material-card.type-video { border-left-color: #dc3545; }
    .material-card.type-document { border-left-color: #0d6efd; }
    .material-card.type-link { border-left-color: #6f42c1; }
    .material-card.type-other { border-left-color: #6c757d; }

    .material-card-body { padding: 1.25rem; }
    .material-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
    .material-title { font-weight: 600; font-size: 1.1rem; color: #343a40; margin-bottom: 0; }
    .material-title i { margin-right: 0.6rem; color: #6c757d; font-size: 1.1em; vertical-align: middle;}

    .material-meta { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 1rem; align-items: center; }
    .material-badge {
        display: inline-flex; 
        align-items: center; 
        gap: 0.35rem;
        padding: 0.35em 0.8em; 
        border-radius: 50rem;
        font-size: 0.75rem; 
        font-weight: 500; 
        line-height: 1; 
        border: 1px solid transparent;
    }
    .badge-type-video { background-color: #fdeeee; border-color: #fcd7db; color: #9c3f4a; }
    .badge-type-document { background-color: #e7f1ff; border-color: #cfe2ff; color: #0a58ca; }
    .badge-type-link { background-color: #f1eafa; border-color: #e4d7f7; color: #5a2b9d; }
    .badge-type-other { background-color: #f8f9fa; border-color: #dee2e6; color: #495057; }
    .badge-required { background-color: #fff9e6; border-color: #ffecb5; color: #856404; }
    .badge-duration { background-color: #f8f9fa; border-color: #dee2e6; color: #495057; }
    .material-badge i { font-size: 0.9em; margin-top: -1px; }

    .material-description { font-size: 0.9rem; color: #495057; margin-bottom: 1.25rem; line-height: 1.6; }

    .material-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; }
    .material-actions .btn { font-size: 0.85rem; padding: 0.4rem 0.9rem; }
    .material-actions .btn.disabled { opacity: 0.65; cursor: not-allowed; }

    /* Materyal Sıralama Kontrolleri */
    .material-order-controls {
        position: absolute;
        right: 10px;
        top: 10px;
        display: flex;
        flex-direction: column;
        gap: 2px;
        z-index: 10;
    }
    .material-order-controls .btn {
        width: 28px;
        height: 28px;
        padding: 0;
        line-height: 28px;
        background: rgba(255, 255, 255, 0.95);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
    .material-order-controls .btn:hover {
        background: white;
        transform: scale(1.1);
        box-shadow: 0 3px 8px rgba(0,0,0,0.15);
    }
    .material-order-controls .btn i { font-size: 0.9rem; }

    /* Materyal Üst Aksiyonlar */
    .material-actions-top {
        display: flex;
        gap: 0.25rem;
        margin-left: 1rem;
    }
    .material-actions-top .btn {
        padding: 0.1rem 0.4rem;
        line-height: 1;
        opacity: 0.7;
        transition: all 0.2s ease;
        border: none;
        background: none;
    }
    .material-actions-top .btn:hover {
        opacity: 1;
        transform: scale(1.1);
        background: transparent;
    }
    .material-actions-top .btn i { font-size: 1.1rem; }

    /* Materyal Ön İzleme */
    .material-preview {
        background: #f8f9fa;
        border-radius: 0.5rem;
        padding: 0.5rem;
        margin-bottom: 1rem;
    }
    .material-preview iframe { border-radius: 0.375rem; }

    /* PDF Modal */
    #pdfPreviewModal .modal-dialog {
        max-width: 90vw;
        height: 90vh;
    }
    #pdfPreviewModal .modal-body {
        padding: 0;
        height: calc(90vh - 120px);
    }
    #pdfPreviewModal iframe {
        width: 100%;
        height: 100%;
        border: none;
    }

    /* İlerleme Çubuğu Stilleri */
    .progress-wrapper { margin-top: 1rem; margin-bottom: 1rem; }
    .progress-wrapper .progress { height: 10px; background-color: #e9ecef; border-radius: 5px; overflow: hidden; }
    .progress-wrapper .progress-bar { background-color: #198754; border-radius: 5px; transition: width 0.3s ease-in-out; }
    .progress-wrapper .progress-text { font-size: 0.8rem; color: #6c757d; margin-top: 0.3rem; }

    /* Dosya Yükleme Alanı */
    .file-upload-area {
        border: 2px dashed #adb5bd; 
        border-radius: 8px; 
        padding: 2.5rem 1rem;
        text-align: center; 
        background-color: #f8f9fa; 
        cursor: pointer; 
        transition: all 0.2s ease;
    }
    .file-upload-area:hover { border-color: #0d6efd; background-color: #e7f1ff; }
    .file-upload-area.drag-over { border-color: #198754; background-color: #d1e7dd; transform: scale(1.02); }
    .file-upload-area i { font-size: 2.5rem; color: #adb5bd; margin-bottom: 0.75rem; }
    .file-upload-area p { margin-bottom: 0.25rem; color: #495057; font-weight: 500;}
    .file-info {
        display: none; 
        align-items: center; 
        margin-top: 1rem; 
        padding: 0.75rem 1rem;
        background-color: #e3f2fd; 
        border: 1px solid #bde0fe; 
        border-radius: 6px; 
        font-size: 0.9rem;
    }
    .file-info .file-name { font-weight: 500; color: #0a58ca; margin-right: auto; word-break: break-all;}
    .file-info .file-size { color: #5a6268; white-space: nowrap; }
    .file-info .btn-close { font-size: 0.75rem; padding: 0.5rem; margin-left: 1rem;}

    /* Empty State */
    .empty-state { text-align: center; padding: 3rem 1rem; color: #6c757d; }
    .empty-state i { font-size: 3rem; color: #adb5bd; margin-bottom: 1rem; }

    /* Modal Switch Buton */
    .form-switch .form-check-input { width: 2.5em; height: 1.25em; cursor: pointer;}
    .form-switch .form-check-label { cursor: pointer; }

    /* Modal Butonlar */
    .modal-footer { gap: 0.5rem; }

    /* Responsive */
    @media (max-width: 768px) {
        .material-order-controls {
            right: 5px;
            top: 5px;
        }
        .material-order-controls .btn {
            width: 24px;
            height: 24px;
            line-height: 24px;
        }
        .material-actions-top {
            flex-direction: column;
        }
        .material-meta {
            flex-direction: column;
            align-items: flex-start !important;
        }
        .material-badge {
            margin-bottom: 0.25rem;
        }
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h3>
                        <i class="fas fa-book-reader me-2"></i>
                        Ders İçerikleri Yönetimi
                    </h3>
                    <p class="text-muted mb-0">
                        <strong><?php echo htmlspecialchars($course['course_name'] ?? 'Bilinmeyen Ders'); ?></strong>
                        (<?php echo htmlspecialchars($course['course_code'] ?? '???'); ?>)
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWeekModal">
                        <i class="fas fa-plus me-1"></i>Yeni Hafta Ekle
                    </button>
                    <a href="courses.php?action=edit&id=<?php echo $course_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Geri Dön
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php display_message(); ?>

    <!-- Info Alert -->
    <div class="alert alert-info d-flex align-items-center border-0 shadow-sm rounded-3">
        <i class="fas fa-info-circle fa-2x me-3 text-primary"></i>
        <div>
            Videoları YouTube URL'si ile, dökümanları dosya yükleyerek veya harici link ile ekleyebilirsiniz. Maksimum dosya boyutu: <strong>50MB</strong>.
            <br>Bu derse kayıtlı <strong><?php echo $total_students; ?></strong> aktif öğrenci bulunmaktadır.
        </div>
    </div>

    <!-- Weeks & Materials -->
    <?php if (empty($weeks)): ?>
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body empty-state">
                <i class="fas fa-folder-plus text-primary"></i>
                <h4 class="text-muted">Henüz Hafta Eklenmemiş</h4>
                <p>İçerik eklemek için önce bir hafta oluşturmalısınız.</p>
                <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addWeekModal">
                    <i class="fas fa-plus me-1"></i>İlk Haftayı Ekle
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="accordion" id="weeksAccordion">
            <?php foreach ($weeks as $index => $week): ?>
                <?php
                $materials = $materials_by_week[$week['id']] ?? [];
                $material_count = count($materials);
                $is_published = $week['is_published'] == 1;
                $status_class = $is_published ? 'published' : 'unpublished';
                $status_text = $is_published ? 'Yayında' : 'Taslak';
                $status_badge_class = $is_published ? 'bg-success-light text-success' : 'bg-secondary-light text-secondary';
                $collapse_id = "collapse{$week['id']}";
                $heading_id = "heading{$week['id']}";
                ?>
                <div class="accordion-item week-card shadow-sm border-0 rounded-3 mb-3">
                    <h2 class="accordion-header" id="<?php echo $heading_id; ?>">
                        <button class="accordion-button <?php echo $index !== 0 ? 'collapsed' : ''; ?> <?php echo $status_class; ?> rounded-top-3"
                                type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapse_id; ?>"
                                aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo $collapse_id; ?>">

                            <div class="week-header-content">
                                <div class="week-title fw-medium">
                                    <i class="fas fa-calendar-alt me-2 text-primary opacity-75"></i>
                                    Hafta <?php echo htmlspecialchars($week['week_number']); ?> - <?php echo htmlspecialchars($week['week_title']); ?>
                                </div>
                                <div class="week-meta">
                                    <span class="me-3"><i class="fas fa-layer-group me-1 opacity-75"></i><?php echo $material_count; ?> içerik</span>
                                    <?php if ($week['publish_date']): ?>
                                        <span class="me-3"><i class="fas fa-calendar-check me-1 opacity-75"></i>Yayın: <?php echo date('d.m.Y', strtotime($week['publish_date'])); ?></span>
                                    <?php endif; ?>
                                    <span class="badge rounded-pill <?php echo $status_badge_class; ?>"><?php echo $status_text; ?></span>
                                </div>
                            </div>

                            <div class="week-actions">
                                <button class="btn btn-sm btn-outline-primary rounded-circle p-0" style="width: 2.2rem; height: 2.2rem; line-height: 2.2rem;" title="Haftayı Düzenle" onclick="event.stopPropagation(); editWeek(<?php echo htmlspecialchars(json_encode($week, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-success rounded-circle p-0" style="width: 2.2rem; height: 2.2rem; line-height: 2.2rem;" title="Materyal Ekle" data-bs-toggle="modal" data-bs-target="#addMaterialModal<?php echo $week['id']; ?>" onclick="event.stopPropagation();">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <form method="POST" class="d-inline week-delete-form" onsubmit="return confirm('Bu haftayı ve içindeki tüm materyalleri kalıcı olarak silmek istediğinizden emin misiniz?')">
                                    <input type="hidden" name="action" value="delete_week">
                                    <input type="hidden" name="week_id" value="<?php echo $week['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle p-0" style="width: 2.2rem; height: 2.2rem; line-height: 2.2rem;" title="Haftayı Sil" onclick="event.stopPropagation();">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </button>
                    </h2>
                    <div id="<?php echo $collapse_id; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" aria-labelledby="<?php echo $heading_id; ?>" data-bs-parent="#weeksAccordion">
                        <div class="accordion-body">
                            <?php if (!empty($week['week_description'])): ?>
                                <div class="alert alert-secondary border-start border-4 border-secondary mb-4 p-3 fs-sm bg-light rounded-3">
                                    <h6 class="alert-heading fw-medium text-dark mb-1"><i class="fas fa-info-circle me-2"></i>Hafta Notları</h6>
                                    <p class="mb-0 text-muted"><?php echo nl2br(htmlspecialchars($week['week_description'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($materials)): ?>
                                <div class="text-center py-4 text-muted border rounded-3" style="border-style: dashed;">
                                    <i class="fas fa-box-open fa-2x mb-3 text-primary opacity-50"></i>
                                    <p class="mb-2 fw-medium">Bu hafta için henüz materyal yok.</p>
                                    <button class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addMaterialModal<?php echo $week['id']; ?>">
                                        <i class="fas fa-plus me-1"></i> Materyal Ekle
                                    </button>
                                </div>
                            <?php else: ?>
<?php foreach ($materials as $mat_index => $material): ?>
    <?php
    $icon_map = [
        'video' => 'fa-play-circle',
        'document' => 'fa-file-alt',
        'link' => 'fa-link',
        'other' => 'fa-paperclip'
    ];
    $icon = $icon_map[$material['material_type']] ?? 'fa-file';
    $type_class = 'type-' . htmlspecialchars($material['material_type']);

    $badge_map = [
        'video' => ['class' => 'badge-type-video', 'text' => 'Video', 'icon' => 'fa-video'],
        'document' => ['class' => 'badge-type-document', 'text' => 'Döküman', 'icon' => 'fa-file-pdf'],
        'link' => ['class' => 'badge-type-link', 'text' => 'Link', 'icon' => 'fa-external-link-alt'],
        'other' => ['class' => 'badge-type-other', 'text' => 'Diğer', 'icon' => 'fa-paperclip']
    ];
    $type_badge = $badge_map[$material['material_type']] ?? $badge_map['other'];

    // YouTube ID yakala
    $youtube_id = null;
    if ($material['material_type'] === 'video' && !empty($material['material_url'])) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $material['material_url'], $matches)) {
            $youtube_id = $matches[1];
        }
    }

    // Dosya uzantısı
    $file_extension = null;
    if (!empty($material['file_path'])) {
        $file_extension = strtolower(pathinfo($material['file_path'], PATHINFO_EXTENSION));
    }

    $materials_in_week = count($materials);
    $is_first = $mat_index === 0;
    $is_last = $mat_index === ($materials_in_week - 1);
    ?>
    <div class="material-card <?php echo $type_class; ?> shadow-sm border rounded-3 position-relative">
        <!-- Sıralama Butonları -->
        <div class="material-order-controls">
            <?php if (!$is_first): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="reorder_material">
                    <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn btn-sm btn-light border-0" title="Yukarı Taşı">
                        <i class="fas fa-chevron-up text-muted"></i>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!$is_last): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="reorder_material">
                    <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn btn-sm btn-light border-0" title="Aşağı Taşı">
                        <i class="fas fa-chevron-down text-muted"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="material-card-body">
            <div class="material-card-header">
                <div class="flex-grow-1">
                    <h5 class="material-title mb-2 fw-medium">
                        <i class="fas <?php echo $icon; ?> opacity-75"></i>
                        <?php echo htmlspecialchars($material['material_title']); ?>
                    </h5>
                    <div class="material-meta">
                        <span class="material-badge <?php echo $type_badge['class']; ?>">
                            <i class="fas <?php echo $type_badge['icon']; ?>"></i> <?php echo $type_badge['text']; ?>
                        </span>
                        <?php if ($material['is_required']): ?>
                            <span class="material-badge badge-required">
                                <i class="fas fa-star"></i> Zorunlu
                            </span>
                        <?php endif; ?>
                        <?php if ($material['duration_minutes']): ?>
                            <span class="material-badge badge-duration">
                                <i class="fas fa-clock"></i> <?php echo $material['duration_minutes']; ?> dk
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($material['file_size'])): ?>
                            <span class="material-badge badge-duration">
                                <i class="fas fa-hdd"></i> <?php echo $material['file_size']; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($material['updated_at']) && $material['updated_at'] !== $material['created_at']): ?>
                            <span class="material-badge badge-duration" title="Son güncelleme: <?php echo date('d.m.Y H:i', strtotime($material['updated_at'])); ?>">
                                <i class="fas fa-sync-alt"></i> Güncellendi
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="material-actions-top">
                    <button class="btn btn-sm btn-link text-primary p-1" title="Materyali Düzenle"
                        onclick="editMaterial(<?php echo htmlspecialchars(json_encode($material, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                        <i class="fas fa-edit fa-lg"></i>
                    </button>
                    <form method="POST" class="d-inline material-delete-form" onsubmit="return confirm('Bu materyali kalıcı olarak silmek istediğinizden emin misiniz?')">
                        <input type="hidden" name="action" value="delete_material">
                        <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-1" title="Materyali Sil">
                            <i class="fas fa-trash-alt fa-lg"></i>
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($material['is_required'] && $total_students > 0): ?>
                <?php
                $completed_count = $progress_counts[$material['id']] ?? 0;
                $progress_percentage = $total_students > 0 ? round(($completed_count / $total_students) * 100) : 0;
                ?>
                <div class="progress-wrapper">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="progress-text fw-medium text-dark fs-sm">Tamamlanma Durumu</span>
                        <span class="progress-text fs-sm"><strong><?php echo $completed_count; ?> / <?php echo $total_students; ?></strong> (%<?php echo $progress_percentage; ?>)</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success rounded-pill" role="progressbar"
                             style="width: <?php echo $progress_percentage; ?>%;"
                             aria-valuenow="<?php echo $progress_percentage; ?>"
                             aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($material['material_description'])): ?>
                <p class="material-description fs-sm text-muted">
                    <?php echo nl2br(htmlspecialchars($material['material_description'])); ?>
                </p>
            <?php endif; ?>

            <!-- Video Ön İzleme -->
            <?php if ($youtube_id): ?>
                <div class="material-preview">
                    <div class="ratio ratio-16x9 rounded-3 overflow-hidden shadow-sm">
                        <iframe
                            src="https://www.youtube.com/embed/<?php echo htmlspecialchars($youtube_id); ?>"
                            title="<?php echo htmlspecialchars($material['material_title']); ?>"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen>
                        </iframe>
                    </div>
                </div>
            <?php endif; ?>

            <div class="material-actions border-top pt-3 mt-3 d-flex justify-content-end gap-2">
                <?php if (!empty($material['file_path'])): ?>
                    <?php
                    $file_relative_path_from_script = $material['file_path'];

                    // teacher klasöründe miyiz?
                 $current_dir = basename(__DIR__);
if ($current_dir === 'teacher') {
    $file_path_for_browser = '/' . ltrim($file_relative_path_from_script, '/');
} else {
    $file_path_for_browser = '../' . ltrim($file_relative_path_from_script, '/');
}

                    $file_path_for_server = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file_relative_path_from_script, '/');
                    $file_exists = file_exists($file_path_for_server);
                    $download_url = $file_exists ? $file_path_for_browser : '#';
                    $download_name = $material['file_name'] ?? basename($material['file_path']);
                    ?>

                    <?php if ($file_extension === 'pdf' && $file_exists): ?>
                        <button
                            class="btn btn-sm btn-outline-success"
                            onclick="previewPDF('<?php echo htmlspecialchars($file_path_for_browser); ?>', '<?php echo htmlspecialchars($material['material_title']); ?>')"
                            title="PDF Ön İzleme">
                            <i class="fas fa-eye me-1"></i> Ön İzleme
                        </button>
                    <?php endif; ?>

                    <a href="<?php echo htmlspecialchars($download_url); ?>"
                       class="btn btn-sm btn-outline-primary <?php echo !$file_exists ? 'disabled pe-none' : ''; ?>"
                       <?php if ($file_exists): ?>
                           download="<?php echo htmlspecialchars($download_name); ?>"
                           target="_blank"
                       <?php endif; ?>
                       title="<?php echo $file_exists ? htmlspecialchars($download_name) : 'Dosya bulunamadı'; ?>">
                        <i class="fas fa-download me-1"></i>
                        İndir<?php echo $material['file_size'] ? ' (' . $material['file_size'] . ')' : ''; ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($material['material_url'])): ?>
                    <?php
                    $is_video = $material['material_type'] === 'video';
                    $btn_class = $is_video ? 'btn-outline-danger' : 'btn-outline-info';
                    $btn_icon = $is_video ? 'fab fa-youtube' : 'fas fa-external-link-alt';
                    $btn_text = $is_video ? "YouTube'da Aç" : 'Linki Aç';
                    ?>
                    <a href="<?php echo htmlspecialchars($material['material_url']); ?>"
                       target="_blank" class="btn btn-sm <?php echo $btn_class; ?>">
                        <i class="<?php echo $btn_icon; ?> me-1"></i> <?php echo $btn_text; ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Week Modal -->
<div class="modal fade" id="addWeekModal" tabindex="-1" aria-labelledby="addWeekModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="addWeekModalLabel">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>Yeni Hafta Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="weekForm">
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="add_week" id="week_action">
                    <input type="hidden" name="week_id" id="edit_week_id">

                    <div class="mb-3">
                        <label for="week_number" class="form-label fw-medium">Hafta Numarası *</label>
                        <input type="number" class="form-control form-control-lg" name="week_number" id="week_number" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label for="week_title" class="form-label fw-medium">Hafta Başlığı *</label>
                        <input type="text" class="form-control form-control-lg" name="week_title" id="week_title" required>
                    </div>
                    <div class="mb-3">
                        <label for="week_description" class="form-label fw-medium">Açıklama</label>
                        <textarea class="form-control" name="week_description" id="week_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="publish_date" class="form-label fw-medium">Yayın Tarihi</label>
                        <input type="date" class="form-control" name="publish_date" id="publish_date">
                        <small class="form-text text-muted">Belirtilmezse, "Yayında" işaretliyse hemen yayınlanır.</small>
                    </div>
                    <div class="form-check form-switch fs-6">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_published" id="is_published" value="1">
                        <label class="form-check-label fw-medium" for="is_published">
                            Öğrencilere Görünür Olsun (Yayında)
                        </label>
                    </div>
                </div>
                <div class="modal-footer flex-column flex-sm-row border-top-0">
                    <button type="submit" class="btn btn-primary btn-lg w-100 w-sm-auto" id="weekSubmitButton">
                        <i class="fas fa-save me-2"></i>Kaydet
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg w-100 w-sm-auto mt-2 mt-sm-0" data-bs-dismiss="modal">İptal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Material Modals -->
<?php foreach ($weeks as $week): ?>
<div class="modal fade" id="addMaterialModal<?php echo $week['id']; ?>" tabindex="-1" aria-labelledby="addMaterialModalLabel<?php echo $week['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="addMaterialModalLabel<?php echo $week['id']; ?>">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>
                    Materyal Ekle - Hafta <?php echo $week['week_number']; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="addMaterialForm">
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="add_material">
                    <input type="hidden" name="week_id" value="<?php echo $week['id']; ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="material_type_<?php echo $week['id']; ?>" class="form-label fw-medium">Materyal Tipi *</label>
                            <select class="form-select form-select-lg material-type-select" name="material_type" id="material_type_<?php echo $week['id']; ?>" required data-week-id="<?php echo $week['id']; ?>">
                                <option value="video">Video (YouTube)</option>
                                <option value="document">Döküman (PDF, Word, vb.)</option>
                                <option value="link">Harici Link</option>
                                <option value="other">Diğer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="material_title_<?php echo $week['id']; ?>" class="form-label fw-medium">Materyal Başlığı *</label>
                            <input type="text" class="form-control form-control-lg" name="material_title" id="material_title_<?php echo $week['id']; ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="material_description_<?php echo $week['id']; ?>" class="form-label fw-medium">Açıklama</label>
                        <textarea class="form-control" name="material_description" id="material_description_<?php echo $week['id']; ?>" rows="2"></textarea>
                    </div>
                    <div class="mb-3 url-field" id="url-field-<?php echo $week['id']; ?>">
                        <label class="form-label fw-medium" for="material_url_<?php echo $week['id']; ?>">
                            <span class="url-label">YouTube URL *</span>
                        </label>
                        <input type="url" class="form-control material-url-input" name="material_url" id="material_url_<?php echo $week['id']; ?>"
                               placeholder="https://www.youtube.com/watch?v=..." required>
                        <small class="form-text text-muted url-help">Videonun YouTube linki</small>
                    </div>
                    <div class="mb-3 file-field" id="file-field-<?php echo $week['id']; ?>" style="display: none;">
                        <label class="form-label file-label fw-medium">Dosya Yükle *</label>
                        <div class="file-upload-area" onclick="this.nextElementSibling.click()">
                            <i class="fas fa-cloud-upload-alt text-primary"></i>
                            <p>Dosya seçmek için tıklayın veya sürükleyip bırakın</p>
                            <small class="text-muted">Maksimum 50MB</small>
                        </div>
                        <input type="file" class="material-file-input" name="material_file" style="display: none;" data-week-id="<?php echo $week['id']; ?>">
                        <div class="file-info" id="file-info-<?php echo $week['id']; ?>">
                            <i class="fas fa-paperclip me-2 text-primary"></i>
                            <span class="file-name"></span>
                            <span class="file-size ms-auto text-muted"></span>
                            <button type="button" class="btn-close btn-sm" aria-label="Kaldır" onclick="removeSelectedFile(this)"></button>
                        </div>
                        <small class="form-text text-danger file-error-msg mt-1" style="display: none;"></small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="duration_minutes_<?php echo $week['id']; ?>" class="form-label fw-medium">Tahmini Süre (dakika)</label>
                            <input type="number" class="form-control" name="duration_minutes" id="duration_minutes_<?php echo $week['id']; ?>" min="1" placeholder="Örn: 25">
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center">
                            <div class="form-check form-switch fs-6 mt-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_required" id="required<?php echo $week['id']; ?>" value="1">
                                <label class="form-check-label fw-medium" for="required<?php echo $week['id']; ?>">
                                    Zorunlu Materyal
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer flex-column flex-sm-row border-top-0">
                    <button type="submit" class="btn btn-primary btn-lg w-100 w-sm-auto">
                        <i class="fas fa-plus me-2"></i>Materyal Ekle
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg w-100 w-sm-auto mt-2 mt-sm-0" data-bs-dismiss="modal">İptal</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Edit Material Modal -->
<div class="modal fade" id="editMaterialModal" tabindex="-1" aria-labelledby="editMaterialModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="editMaterialModalLabel">
                    <i class="fas fa-edit me-2 text-primary"></i>Materyal Düzenle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editMaterialForm">
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="update_material">
                    <input type="hidden" name="material_id" id="edit_material_id">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_material_type" class="form-label fw-medium">Materyal Tipi *</label>
                            <select class="form-select form-select-lg" name="material_type" id="edit_material_type" required>
                                <option value="video">Video (YouTube)</option>
                                <option value="document">Döküman (PDF, Word, vb.)</option>
                                <option value="link">Harici Link</option>
                                <option value="other">Diğer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_material_title" class="form-label fw-medium">Materyal Başlığı *</label>
                            <input type="text" class="form-control form-control-lg" name="material_title" id="edit_material_title" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_material_description" class="form-label fw-medium">Açıklama</label>
                        <textarea class="form-control" name="material_description" id="edit_material_description" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3" id="edit_url_field">
                        <label class="form-label fw-medium" for="edit_material_url">
                            <span id="edit_url_label">YouTube URL</span>
                        </label>
                        <input type="url" class="form-control" name="material_url" id="edit_material_url" placeholder="https://...">
                        <small class="form-text text-muted" id="edit_url_help">URL adresi</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="edit_replace_file" name="replace_file" value="1">
                            <label class="form-check-label fw-medium" for="edit_replace_file">
                                Mevcut Dosyayı Değiştir
                            </label>
                        </div>
                        <div id="edit_current_file_info" class="alert alert-info mt-2" style="display: none;">
                            <i class="fas fa-file me-2"></i>
                            <span id="edit_current_file_name"></span>
                            <span class="badge bg-primary ms-2" id="edit_current_file_size"></span>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="edit_file_field" style="display: none;">
                        <label class="form-label fw-medium">Yeni Dosya Seç</label>
                        <div class="file-upload-area" onclick="this.nextElementSibling.click()">
                            <i class="fas fa-cloud-upload-alt text-primary"></i>
                            <p>Yeni dosya seçmek için tıklayın</p>
                            <small class="text-muted">Maksimum 50MB</small>
                        </div>
                        <input type="file" name="material_file" id="edit_material_file" style="display: none;">
                        <div class="file-info" id="edit_new_file_info" style="display: none;">
                            <i class="fas fa-paperclip me-2 text-primary"></i>
                            <span class="file-name"></span>
                            <span class="file-size ms-auto text-muted"></span>
                            <button type="button" class="btn-close btn-sm" onclick="document.getElementById('edit_material_file').value = ''; this.closest('.file-info').style.display = 'none';"></button>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_duration_minutes" class="form-label fw-medium">Tahmini Süre (dakika)</label>
                            <input type="number" class="form-control" name="duration_minutes" id="edit_duration_minutes" min="1" placeholder="Örn: 25">
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center">
                            <div class="form-check form-switch fs-6 mt-3">
                                <input class="form-check-input" type="checkbox" name="is_required" id="edit_is_required" value="1">
                                <label class="form-check-label fw-medium" for="edit_is_required">
                                    Zorunlu Materyal
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Değişiklikleri Kaydet
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">İptal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PDF Preview Modal -->
<div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-labelledby="pdfPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header">
                <h5 class="modal-title" id="pdfPreviewModalLabel">
                    <i class="fas fa-file-pdf me-2 text-danger"></i>PDF Ön İzleme
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <iframe id="pdfPreviewFrame" src="" style="width: 100%; height: 75vh; border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <a href="#" id="pdfDownloadLink" class="btn btn-primary" download>
                    <i class="fas fa-download me-2"></i>İndir
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- Hafta Düzenleme Modalı ---
    const weekModalElement = document.getElementById('addWeekModal');
    if (weekModalElement) {
        const weekModal = new bootstrap.Modal(weekModalElement);
        const weekModalLabel = document.getElementById('addWeekModalLabel');
        const weekSubmitButton = document.getElementById('weekSubmitButton');
        const weekForm = document.getElementById('weekForm');
        const weekActionInput = document.getElementById('week_action');
        const weekIdInput = document.getElementById('edit_week_id');

        window.editWeek = function(week) {
            if (!weekForm || !week) return;
            weekActionInput.value = 'update_week';
            weekIdInput.value = week.id;
            weekForm.elements['week_number'].value = week.week_number;
            weekForm.elements['week_title'].value = week.week_title;
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = week.week_description || '';
            weekForm.elements['week_description'].value = tempDiv.textContent || tempDiv.innerText || '';
            weekForm.elements['publish_date'].value = week.publish_date || '';
            weekForm.elements['is_published'].checked = week.is_published == 1;

            weekModalLabel.innerHTML = '<i class="fas fa-edit me-2 text-primary"></i>Haftayı Düzenle';
            weekSubmitButton.innerHTML = '<i class="fas fa-save me-1"></i>Güncelle';
            weekSubmitButton.classList.remove('btn-success', 'btn-primary');
            weekSubmitButton.classList.add('btn-primary');
            weekModal.show();
        }

        weekModalElement.addEventListener('hidden.bs.modal', function () {
            if (!weekForm) return;
            weekForm.reset();
            weekActionInput.value = 'add_week';
            weekIdInput.value = '';
            weekModalLabel.innerHTML = '<i class="fas fa-plus-circle me-2 text-primary"></i>Yeni Hafta Ekle';
            weekSubmitButton.innerHTML = '<i class="fas fa-save me-1"></i>Kaydet';
            weekSubmitButton.classList.remove('btn-success', 'btn-primary');
            weekSubmitButton.classList.add('btn-primary');
        });
    }

    // --- Materyal Ekleme Modalı Alan Kontrolleri ---
    const materialTypeSelects = document.querySelectorAll('.material-type-select');
    materialTypeSelects.forEach(select => {
        select.addEventListener('change', function() {
            toggleMaterialFields(this);
        });

        const modal = select.closest('.modal');
        if (modal) {
            modal.addEventListener('show.bs.modal', function() {
                const currentSelect = modal.querySelector('.material-type-select');
                if(currentSelect) {
                    currentSelect.value = 'video';
                    toggleMaterialFields(currentSelect);
                    const form = modal.querySelector('form');
                    if (form) {
                        form.reset();
                        currentSelect.value = 'video';
                        const fileInfo = form.querySelector('.file-info');
                        const fileInput = form.querySelector('.material-file-input');
                        const errorMsg = form.querySelector('.file-error-msg');
                        if (fileInfo) fileInfo.style.display = 'none';
                        if (fileInput) fileInput.value = '';
                        if (errorMsg) errorMsg.style.display = 'none';
                    }
                }
            });
        }
    });

    function toggleMaterialFields(selectElement) {
        const weekId = selectElement.dataset.weekId;
        const selectedType = selectElement.value;
        const modal = selectElement.closest('.modal');
        if (!modal) return;

        const urlField = modal.querySelector(`#url-field-${weekId}`);
        const fileField = modal.querySelector(`#file-field-${weekId}`);
        if (!urlField || !fileField) return;

        const urlLabelSpan = urlField.querySelector('.url-label');
        const urlHelp = urlField.querySelector('.url-help');
        const urlInput = urlField.querySelector('.material-url-input');
        const fileInput = fileField.querySelector('.material-file-input');
        const fileLabel = fileField.querySelector('.file-label');
        const fileErrorMsg = fileField.querySelector('.file-error-msg');

        urlInput.required = false;
        fileInput.required = false;
        if(urlLabelSpan) urlLabelSpan.textContent = urlLabelSpan.textContent.replace(' *', '');
        if(fileLabel) fileLabel.textContent = fileLabel.textContent.replace(' *', '');
        if(fileErrorMsg) fileErrorMsg.style.display = 'none';

        urlField.style.display = 'none';
        fileField.style.display = 'none';

        if (selectedType === 'video') {
            urlField.style.display = 'block';
            if(urlLabelSpan) urlLabelSpan.textContent = 'YouTube URL *';
            if(urlHelp) urlHelp.textContent = 'Videonun YouTube linki (örn: https://www.youtube.com/watch?v=...)';
            urlInput.placeholder = 'https://www.youtube.com/watch?v=...';
            urlInput.required = true;
        } else if (selectedType === 'document') {
            urlField.style.display = 'block';
            fileField.style.display = 'block';
            if(urlLabelSpan) urlLabelSpan.textContent = 'Harici Link (Opsiyonel)';
            if(urlHelp) urlHelp.textContent = 'Dosya yüklemezseniz, Google Drive, Dropbox vb. linki buraya girin.';
            urlInput.placeholder = 'https://...';
            if(fileLabel) fileLabel.textContent = 'Dosya Yükle *';
        } else if (selectedType === 'link') {
            urlField.style.display = 'block';
            if(urlLabelSpan) urlLabelSpan.textContent = 'Link URL *';
            if(urlHelp) urlHelp.textContent = 'Erişilecek harici web sitesi adresi';
            urlInput.placeholder = 'https://...';
            urlInput.required = true;
        } else {
            urlField.style.display = 'block';
            fileField.style.display = 'block';
            if(urlLabelSpan) urlLabelSpan.textContent = 'Link (Opsiyonel)';
            if(urlHelp) urlHelp.textContent = 'İsteğe bağlı harici link';
            urlInput.placeholder = 'https://...';
            if(fileLabel) fileLabel.textContent = 'Dosya (Opsiyonel)';
        }
    }

    // Form gönderiminde 'document' tipi için kontrol
    const materialForms = document.querySelectorAll('.addMaterialForm');
    materialForms.forEach(form => {
        form.addEventListener('submit', function(event) {
            const select = form.querySelector('.material-type-select');
            const urlInput = form.querySelector('.material-url-input');
            const fileInput = form.querySelector('.material-file-input');
            const fileErrorMsg = form.querySelector('.file-error-msg');

            if (fileErrorMsg) fileErrorMsg.style.display = 'none';

            if (select.value === 'document') {
                const hasUrl = urlInput.value.trim().length > 0;
                const hasFile = fileInput.files && fileInput.files.length > 0;

                if (!hasUrl && !hasFile) {
                    event.preventDefault();
                    if (fileErrorMsg) {
                        fileErrorMsg.textContent = 'Lütfen bir dosya yükleyin veya harici bir link girin.';
                        fileErrorMsg.style.display = 'block';
                    }
                    const uploadArea = fileInput.closest('.file-field').querySelector('.file-upload-area');
                    if(uploadArea) uploadArea.focus();
                }
            }
        });
    });

    // --- Dosya Seçimi Gösterimi ---
    const fileInputs = document.querySelectorAll('.material-file-input');
    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            showFileInfo(this);
        });
    });

    window.showFileInfo = function(inputElement) {
        const weekId = inputElement.dataset.weekId;
        const fileInfoDiv = document.getElementById(`file-info-${weekId}`);
        const file = inputElement.files[0];
        const errorMsg = inputElement.closest('.file-field').querySelector('.file-error-msg');

        if (!fileInfoDiv) return;

        if (errorMsg) errorMsg.style.display = 'none';

        if (file) {
            const sizeInMB = file.size / 1024 / 1024;
            if (sizeInMB > 50) {
                if (errorMsg) {
                    errorMsg.textContent = `Dosya boyutu çok büyük (${sizeInMB.toFixed(1)} MB). İzin verilen en yüksek boyut 50 MB'dir.`;
                    errorMsg.style.display = 'block';
                }
                inputElement.value = '';
                fileInfoDiv.style.display = 'none';
                return;
            }

            const sizeFormatted = sizeInMB < 0.1 ? (file.size / 1024).toFixed(1) + ' KB' : sizeInMB.toFixed(1) + ' MB';
            const fileNameSpan = fileInfoDiv.querySelector('.file-name');
            const fileSizeSpan = fileInfoDiv.querySelector('.file-size');

            if(fileNameSpan) fileNameSpan.textContent = file.name.length > 40 ? file.name.substring(0, 37) + '...' : file.name;
            if(fileSizeSpan) fileSizeSpan.textContent = sizeFormatted;
            fileInfoDiv.style.display = 'flex';
        } else {
            fileInfoDiv.style.display = 'none';
        }
    }

    window.removeSelectedFile = function(buttonElement) {
        const fileInfoDiv = buttonElement.closest('.file-info');
        if (!fileInfoDiv) return;
        const fileField = fileInfoDiv.closest('.file-field');
        if (!fileField) return;
        const inputElement = fileField.querySelector('.material-file-input');
        const errorMsg = fileField.querySelector('.file-error-msg');

        if(inputElement) inputElement.value = '';
        fileInfoDiv.style.display = 'none';
        if (errorMsg) errorMsg.style.display = 'none';
    }

    // --- Materyal Düzenleme ---
    window.editMaterial = function(material) {
        const modal = new bootstrap.Modal(document.getElementById('editMaterialModal'));
        const form = document.getElementById('editMaterialForm');
        
        document.getElementById('edit_material_id').value = material.id;
        document.getElementById('edit_material_type').value = material.material_type;
        document.getElementById('edit_material_title').value = material.material_title;
        document.getElementById('edit_material_description').value = material.material_description || '';
        document.getElementById('edit_material_url').value = material.material_url || '';
        document.getElementById('edit_duration_minutes').value = material.duration_minutes || '';
        document.getElementById('edit_is_required').checked = material.is_required == 1;
        
        const currentFileInfo = document.getElementById('edit_current_file_info');
        const replaceFileCheckbox = document.getElementById('edit_replace_file');
        const editFileField = document.getElementById('edit_file_field');
        
        if (material.file_path && material.file_name) {
            document.getElementById('edit_current_file_name').textContent = material.file_name;
            document.getElementById('edit_current_file_size').textContent = material.file_size || '';
            currentFileInfo.style.display = 'block';
            
            replaceFileCheckbox.addEventListener('change', function() {
                editFileField.style.display = this.checked ? 'block' : 'none';
            });
        } else {
            currentFileInfo.style.display = 'none';
            editFileField.style.display = 'none';
        }
        
        document.getElementById('edit_material_file').addEventListener('change', function() {
            const file = this.files[0];
            const fileInfo = document.getElementById('edit_new_file_info');
            
            if (file) {
                const sizeInMB = file.size / 1024 / 1024;
                const sizeFormatted = sizeInMB < 0.1 ? (file.size / 1024).toFixed(1) + ' KB' : sizeInMB.toFixed(1) + ' MB';
                
                fileInfo.querySelector('.file-name').textContent = file.name;
                fileInfo.querySelector('.file-size').textContent = sizeFormatted;
                fileInfo.style.display = 'flex';
            } else {
                fileInfo.style.display = 'none';
            }
        });
        
        updateEditMaterialFields(material.material_type);
        document.getElementById('edit_material_type').addEventListener('change', function() {
            updateEditMaterialFields(this.value);
        });
        
        modal.show();
    }

    function updateEditMaterialFields(materialType) {
        const urlField = document.getElementById('edit_url_field');
        const urlLabel = document.getElementById('edit_url_label');
        const urlHelp = document.getElementById('edit_url_help');
        const urlInput = document.getElementById('edit_material_url');
        
        if (materialType === 'video') {
            urlLabel.textContent = 'YouTube URL *';
            urlHelp.textContent = 'Videonun YouTube linki';
            urlInput.placeholder = 'https://www.youtube.com/watch?v=...';
            urlInput.required = true;
        } else if (materialType === 'link') {
            urlLabel.textContent = 'Link URL *';
            urlHelp.textContent = 'Erişilecek harici web sitesi adresi';
            urlInput.placeholder = 'https://...';
            urlInput.required = true;
        } else {
            urlLabel.textContent = 'Link (Opsiyonel)';
            urlHelp.textContent = 'İsteğe bağlı harici link';
            urlInput.placeholder = 'https://...';
            urlInput.required = false;
        }
    }

    // --- PDF Ön İzleme ---
    window.previewPDF = function(filePath, title) {
        const modal = new bootstrap.Modal(document.getElementById('pdfPreviewModal'));
        const iframe = document.getElementById('pdfPreviewFrame');
        const downloadLink = document.getElementById('pdfDownloadLink');
        const modalTitle = document.getElementById('pdfPreviewModalLabel');
        
        modalTitle.innerHTML = `<i class="fas fa-file-pdf me-2 text-danger"></i>${title}`;
        iframe.src = filePath;
        downloadLink.href = filePath;
        downloadLink.download = title + '.pdf';
        
        modal.show();
    }

    // --- Sürükle Bırak ---
    document.querySelectorAll('.file-upload-area').forEach(area => {
        area.addEventListener('dragover', (e) => { e.preventDefault(); e.stopPropagation(); area.classList.add('drag-over'); });
        area.addEventListener('dragleave', (e) => { e.preventDefault(); e.stopPropagation(); area.classList.remove('drag-over'); });
        area.addEventListener('drop', (e) => {
            e.preventDefault(); e.stopPropagation(); area.classList.remove('drag-over');
            const inputElement = area.nextElementSibling;
            if (!inputElement || inputElement.type !== 'file') {
                console.error('File input not found after upload area.');
                return;
            }
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                if (e.dataTransfer.files.length > 1) {
                    const errorMsg = inputElement.closest('.file-field').querySelector('.file-error-msg');
                    if(errorMsg){
                        errorMsg.textContent = 'Lütfen tek seferde yalnızca bir dosya yükleyin.';
                        errorMsg.style.display = 'block';
                    }
                    return;
                }
                inputElement.files = e.dataTransfer.files;
                showFileInfo(inputElement);
            }
        });
    });

    // --- Akordiyon Açık Kalma ---
    const restoreAccordionState = () => {
        if(window.location.hash && window.location.hash.startsWith('#heading')) {
            const hash = window.location.hash;
            const collapseId = hash.replace('#heading', '#collapse');
            const targetCollapse = document.querySelector(collapseId);

            if (targetCollapse && targetCollapse.closest('#weeksAccordion')) {
                const bsCollapse = bootstrap.Collapse.getInstance(targetCollapse) || new bootstrap.Collapse(targetCollapse, { toggle: false });
                bsCollapse.show();
                setTimeout(() => {
                    const header = document.querySelector(hash);
                    if (header) {
                        header.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }, 350);
            }
        }
    };
    restoreAccordionState();

    document.querySelectorAll('.week-delete-form, .material-delete-form').forEach(form => {
        form.addEventListener('submit', function() {
            const weekAccordionItem = form.closest('.accordion-item');
            if(weekAccordionItem) {
                const header = weekAccordionItem.querySelector('.accordion-header');
                if (header && header.id) {
                    const headerId = header.id;
                    const currentAction = form.getAttribute('action') || window.location.pathname + window.location.search;
                    const baseUrl = currentAction.split('#')[0].split('?')[0];
                    const queryString = window.location.search;
                    form.setAttribute('action', `${baseUrl}${queryString}#${headerId}`);
                }
            }
        });
    });

});
</script>

<?php include '../includes/components/shared_footer.php'; ?>