<?php
// Increase memory limit and execution time
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);

try {
    require_once '../includes/functions.php';
    
    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Öğretmen yetkisi kontrolü
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
    
    $teacher_id = $_SESSION['user_id'];
} catch (Exception $e) {
    error_log('Teacher Courses Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Site ayarlarını yükle
try {
    $query = "SELECT setting_key, setting_value FROM site_settings";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception('Settings query preparation failed');
    }
    $stmt->execute();
    $site_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log('Site settings error: ' . $e->getMessage());
    $site_settings = [];
}

// Varsayılan değerler
$site_name = $site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi';

// Actions
$action = $_GET['action'] ?? 'list';
$course_id = (int)($_GET['id'] ?? 0);

// Düzenlenecek ders verilerini al (edit action için)
$edit_course = null;
if ($action === 'edit' && $course_id > 0) {
    try {
        $query = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $edit_course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_course) {
            show_message('Düzenlenecek ders bulunamadı.', 'danger');
            redirect('courses.php');
            exit;
        }
    } catch (Exception $e) {
        error_log('Edit course fetch error: ' . $e->getMessage());
        show_message('Sistem hatası oluştu.', 'danger');
        redirect('courses.php');
        exit;
    }
}

// Öğrenci listesi için dersi al
$manage_course = null;
$course_students = [];
$available_students = [];

if ($action === 'manage' && $course_id > 0) {
    try {
        $query = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $manage_course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$manage_course) {
            show_message('Yönetilecek ders bulunamadı.', 'danger');
            redirect('courses.php');
            exit;
        }
        
        // Dersin öğrencilerini al
        $query = "SELECT u.id, u.full_name, u.student_number, u.email, ce.enrollment_date
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.student_id
                  WHERE ce.course_id = :course_id AND ce.is_active = 1
                  ORDER BY u.full_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->execute();
        $course_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Derse kayıtlı olmayan öğrencileri al
        $query = "SELECT u.id, u.full_name, u.student_number, u.email
                  FROM users u
                  WHERE u.user_type = 'student' AND u.is_active = 1
                  AND u.id NOT IN (
                      SELECT ce.student_id FROM course_enrollments ce 
                      WHERE ce.course_id = :course_id AND ce.is_active = 1
                  )
                  ORDER BY u.full_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->execute();
        $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log('Manage course error: ' . $e->getMessage());
        show_message('Sistem hatası oluştu.', 'danger');
        redirect('courses.php');
        exit;
    }
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    try {
        if ($post_action === 'create') {
            // Yeni ders oluştur
            $course_name = trim($_POST['course_name']);
            $course_code = trim(strtoupper($_POST['course_code']));
            $semester = $_POST['semester'];
            $academic_year = $_POST['academic_year'];
            $max_students = (int)$_POST['max_students'];
            
            if (empty($course_name) || empty($course_code) || empty($semester) || empty($academic_year)) {
                throw new Exception('Lütfen tüm gerekli alanları doldurun.');
            }
            
            if ($max_students < 1 || $max_students > 500) {
                throw new Exception('Maksimum öğrenci sayısı 1-500 arasında olmalıdır.');
            }
            
            // Ders kodu benzersizliği kontrolü
            $query = "SELECT id FROM courses WHERE course_code = :course_code AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_code', $course_code);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                throw new Exception('Bu ders kodu zaten kullanılmaktadır.');
            }
            
            // Dersi oluştur
            $query = "INSERT INTO courses (course_name, course_code, teacher_id, semester, academic_year, max_students) 
                      VALUES (:course_name, :course_code, :teacher_id, :semester, :academic_year, :max_students)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_name', $course_name);
            $stmt->bindParam(':course_code', $course_code);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->bindParam(':semester', $semester);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':max_students', $max_students, PDO::PARAM_INT);
            $stmt->execute();
            
            show_message('Ders başarıyla oluşturuldu.', 'success');
            redirect('courses.php');
            exit;
            
        } elseif ($post_action === 'update') {
            // Ders bilgilerini güncelle
            $course_id = (int)$_POST['course_id'];
            $course_name = trim($_POST['course_name']);
            $course_code = trim(strtoupper($_POST['course_code']));
            $semester = $_POST['semester'];
            $academic_year = $_POST['academic_year'];
            $max_students = (int)$_POST['max_students'];
            
            if (empty($course_name) || empty($course_code) || empty($semester) || empty($academic_year) || $course_id <= 0) {
                throw new Exception('Lütfen tüm gerekli alanları doldurun.');
            }
            
            if ($max_students < 1 || $max_students > 500) {
                throw new Exception('Maksimum öğrenci sayısı 1-500 arasında olmalıdır.');
            }
            
            // Öğretmenin bu dersi verdiğini kontrol et
            $query = "SELECT id FROM courses WHERE id = :course_id AND teacher_id = :teacher_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                throw new Exception('Bu dersi düzenleme yetkiniz bulunmamaktadır.');
            }
            
            // Ders kodu benzersizliği kontrolü (kendi dersi hariç)
            $query = "SELECT id FROM courses WHERE course_code = :course_code AND id != :course_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_code', $course_code);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                throw new Exception('Bu ders kodu zaten kullanılmaktadır.');
            }
            
            // Mevcut öğrenci sayısını kontrol et
            $query = "SELECT COUNT(*) as student_count FROM course_enrollments WHERE course_id = :course_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $current_student_count = $stmt->fetch(PDO::FETCH_ASSOC)['student_count'];
            
            if ($max_students < $current_student_count) {
                throw new Exception("Maksimum öğrenci sayısı mevcut öğrenci sayısından ({$current_student_count}) az olamaz.");
            }
            
            // Dersi güncelle
            $query = "UPDATE courses 
                      SET course_name = :course_name, course_code = :course_code, 
                          semester = :semester, academic_year = :academic_year, 
                          max_students = :max_students
                      WHERE id = :course_id AND teacher_id = :teacher_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_name', $course_name);
            $stmt->bindParam(':course_code', $course_code);
            $stmt->bindParam(':semester', $semester);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':max_students', $max_students, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
            
            show_message('Ders bilgileri başarıyla güncellendi.', 'success');
            redirect('courses.php');
            exit;
            
        } elseif ($post_action === 'delete') {
            // Dersi pasif yap (silme yerine)
            $course_id = (int)$_POST['course_id'];
            
            // Öğretmenin bu dersi verdiğini kontrol et
            $query = "SELECT id FROM courses WHERE id = :course_id AND teacher_id = :teacher_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                throw new Exception('Bu dersi silme yetkiniz bulunmamaktadır.');
            }
            
            // Aktif yoklama oturumu var mı kontrol et
            $query = "SELECT COUNT(*) as active_sessions FROM attendance_sessions 
                      WHERE course_id = :course_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $active_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['active_sessions'];
            
            if ($active_sessions > 0) {
                throw new Exception('Aktif yoklama oturumu olan ders silinemez. Önce oturumları kapatın.');
            }
            
            $db->beginTransaction();
            
            try {
                // Dersi pasif yap
                $query = "UPDATE courses SET is_active = 0 WHERE id = :course_id AND teacher_id = :teacher_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
                $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Öğrenci kayıtlarını pasif yap
                $query = "UPDATE course_enrollments SET is_active = 0 WHERE course_id = :course_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
                $stmt->execute();
                
                $db->commit();
                show_message('Ders başarıyla silindi.', 'success');
                redirect('courses.php');
                exit;
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            
        } elseif ($post_action === 'add_student') {
            // Öğrenci ekle
            $course_id = (int)$_POST['course_id'];
            $student_id = (int)$_POST['student_id'];
            
            if ($course_id <= 0 || $student_id <= 0) {
                throw new Exception('Geçersiz ders veya öğrenci seçimi.');
            }
            
            // Öğretmenin bu dersi verdiğini kontrol et
            $query = "SELECT max_students FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
            $course = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$course) {
                throw new Exception('Bu derse öğrenci ekleme yetkiniz bulunmamaktadır.');
            }
            
            // Mevcut öğrenci sayısını kontrol et
            $query = "SELECT COUNT(*) as student_count FROM course_enrollments WHERE course_id = :course_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $current_count = $stmt->fetch(PDO::FETCH_ASSOC)['student_count'];
            
            if ($current_count >= $course['max_students']) {
                throw new Exception('Ders kapasitesi dolu. Maksimum ' . $course['max_students'] . ' öğrenci alabilir.');
            }
            
            // Öğrencinin zaten kayıtlı olup olmadığını kontrol et
            $query = "SELECT id FROM course_enrollments WHERE course_id = :course_id AND student_id = :student_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                throw new Exception('Öğrenci zaten bu derse kayıtlı.');
            }
            
            // Öğrenciyi ekle
            $query = "INSERT INTO course_enrollments (student_id, course_id) VALUES (:student_id, :course_id)
                      ON DUPLICATE KEY UPDATE is_active = 1, enrollment_date = NOW()";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            
            show_message('Öğrenci başarıyla derse eklendi.', 'success');
            redirect("courses.php?action=manage&id={$course_id}");
            exit;
            
        } elseif ($post_action === 'remove_student') {
            // Öğrenci çıkar
            $course_id = (int)$_POST['course_id'];
            $student_id = (int)$_POST['student_id'];
            
            // Öğretmenin bu dersi verdiğini kontrol et
            $query = "SELECT id FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                throw new Exception('Bu dersten öğrenci çıkarma yetkiniz bulunmamaktadır.');
            }
            
            // Öğrenciyi pasif yap
            $query = "UPDATE course_enrollments SET is_active = 0 WHERE course_id = :course_id AND student_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                show_message('Öğrenci başarıyla dersten çıkarıldı.', 'success');
            } else {
                show_message('Öğrenci bulunamadı veya zaten derste değil.', 'warning');
            }
            
            redirect("courses.php?action=manage&id={$course_id}");
            exit;
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        show_message($e->getMessage(), 'danger');
    }
}

// Öğretmenin derslerini al
$teacher_courses = [];
if ($action === 'list') {
    try {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as student_count,
                  (SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.course_id = c.id) as session_count,
                  (SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.course_id = c.id AND ats.is_active = 1) as active_sessions
                  FROM courses c 
                  WHERE c.teacher_id = :teacher_id AND c.is_active = 1 
                  ORDER BY c.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $teacher_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Teacher courses error: ' . $e->getMessage());
        $teacher_courses = [];
    }
}

$page_title = "Ders Yönetimi - ". htmlspecialchars($site_name);

// Include header
include '../includes/components/teacher_header.php';
?>

<style>
    .course-card {
        border: none;
        box-shadow: 0 0 20px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
        border-radius: 10px;
        overflow: hidden;
    }
    
    .course-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 30px rgba(0,0,0,0.15);
    }
    
    .course-card .card-body {
        padding: 1.5rem;
    }
    
    .course-card .card-title {
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    
    .badge-semester {
        background-color: #e3f2fd;
        color: #1976d2;
        padding: 0.3rem 0.6rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .badge-year {
        background-color: #f3e5f5;
        color: #7b1fa2;
        padding: 0.3rem 0.6rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .progress {
        background-color: #e9ecef;
    }
    
    .text-info .fw-bold {
        font-size: 1.5rem;
        margin: 0.2rem 0;
    }
    
    .text-secondary .fw-bold {
        font-size: 1.5rem;
        margin: 0.2rem 0;
    }
    
    .text-primary .fw-bold {
        font-size: 1.5rem;
        margin: 0.2rem 0;
    }
    
    .form-control:focus,
    .form-select:focus {
        border-color: #4CAF50;
        box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25);
    }
    
    .student-table {
        background: white;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .student-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }
    
    .student-table td {
        vertical-align: middle;
    }
    
    .add-student-form {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 2rem;
    }
    
    .stat-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 10px;
        margin-bottom: 1rem;
    }
    
    .stat-box h4 {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }
    
    .stat-box p {
        margin: 0;
        opacity: 0.9;
    }
</style>

<div class="container-fluid py-4">
    <?php if ($action === 'create'): ?>
        <!-- Create Course Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>
                            Yeni Ders Oluştur
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="create">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="course_name" class="form-label">Ders Adı *</label>
                                    <input type="text" class="form-control" id="course_name" name="course_name" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="course_code" class="form-label">Ders Kodu *</label>
                                    <input type="text" class="form-control" id="course_code" name="course_code" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="semester" class="form-label">Dönem *</label>
                                    <select class="form-select" id="semester" name="semester" required>
                                        <option value="">Seçiniz...</option>
                                        <option value="Güz">Güz</option>
                                        <option value="Bahar">Bahar</option>
                                        <option value="Yaz">Yaz</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="academic_year" class="form-label">Akademik Yıl *</label>
                                    <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                           placeholder="2024-2025" required>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="max_students" class="form-label">Maksimum Öğrenci *</label>
                                    <input type="number" class="form-control" id="max_students" name="max_students" 
                                           min="1" max="500" value="100" required>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="courses.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>İptal
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-1"></i>Dersi Oluştur
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'edit' && $edit_course): ?>
        <!-- Edit Course Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-edit me-2"></i>
                            Ders Düzenle
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="course_id" value="<?php echo $edit_course['id']; ?>">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="course_name" class="form-label">Ders Adı *</label>
                                    <input type="text" class="form-control" id="course_name" name="course_name" 
                                           value="<?php echo htmlspecialchars($edit_course['course_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="course_code" class="form-label">Ders Kodu *</label>
                                    <input type="text" class="form-control" id="course_code" name="course_code" 
                                           value="<?php echo htmlspecialchars($edit_course['course_code']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="semester" class="form-label">Dönem *</label>
                                    <select class="form-select" id="semester" name="semester" required>
                                        <option value="">Seçiniz...</option>
                                        <option value="Güz" <?php echo $edit_course['semester'] === 'Güz' ? 'selected' : ''; ?>>Güz</option>
                                        <option value="Bahar" <?php echo $edit_course['semester'] === 'Bahar' ? 'selected' : ''; ?>>Bahar</option>
                                        <option value="Yaz" <?php echo $edit_course['semester'] === 'Yaz' ? 'selected' : ''; ?>>Yaz</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="academic_year" class="form-label">Akademik Yıl *</label>
                                    <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                           value="<?php echo htmlspecialchars($edit_course['academic_year']); ?>" 
                                           placeholder="2024-2025" required>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="max_students" class="form-label">Maksimum Öğrenci *</label>
                                    <input type="number" class="form-control" id="max_students" name="max_students" 
                                           min="1" max="500" value="<?php echo $edit_course['max_students']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <div>
                                    <a href="courses.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-1"></i>Geri
                                    </a>
                                    <a href="courses.php?action=manage&id=<?php echo $edit_course['id']; ?>" class="btn btn-info ms-2">
                                        <i class="fas fa-users me-1"></i>Öğrenci Yönetimi
                                    </a>
                                </div>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save me-1"></i>Güncelle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'manage' && $manage_course): ?>
        <!-- Manage Course Students -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?php echo htmlspecialchars($manage_course['course_name']); ?></h3>
                        <p class="text-muted mb-0">
                            <?php echo htmlspecialchars($manage_course['course_code']); ?> • 
                            <?php echo htmlspecialchars($manage_course['semester']); ?> • 
                            <?php echo htmlspecialchars($manage_course['academic_year']); ?>
                        </p>
                    </div>
                    <div>
                        <a href="courses.php?action=edit&id=<?php echo $course_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i>Ders Düzenle
                        </a>
                        <a href="courses.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-arrow-left me-1"></i>Geri
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Statistics -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="stat-box">
                    <h4><?php echo count($course_students); ?> / <?php echo $manage_course['max_students']; ?></h4>
                    <p><i class="fas fa-users me-2"></i>Kayıtlı Öğrenci</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h4><?php echo count($available_students); ?></h4>
                    <p><i class="fas fa-user-plus me-2"></i>Eklenebilir Öğrenci</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h4>
                        <?php 
                        $capacity_rate = $manage_course['max_students'] > 0 ? 
                            round((count($course_students) / $manage_course['max_students']) * 100) : 0;
                        echo $capacity_rate; 
                        ?>%
                    </h4>
                    <p><i class="fas fa-chart-pie me-2"></i>Doluluk Oranı</p>
                </div>
            </div>
        </div>

        <!-- Add Student Form -->
        <?php if (count($course_students) < $manage_course['max_students'] && count($available_students) > 0): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="add-student-form">
                        <h5 class="mb-3">
                            <i class="fas fa-user-plus me-2"></i>Öğrenci Ekle
                        </h5>
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="add_student">
                            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                            
                            <div class="col-md-10">
                                <select class="form-select" name="student_id" required>
                                    <option value="">Öğrenci Seçiniz...</option>
                                    <?php foreach ($available_students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['full_name']); ?> 
                                            (<?php echo htmlspecialchars($student['student_number']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-plus me-1"></i>Ekle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif (count($course_students) >= $manage_course['max_students']): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Ders kapasitesi dolmuştur.
            </div>
        <?php endif; ?>

        <!-- Students List -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-users me-2"></i>
                            Kayıtlı Öğrenciler (<?php echo count($course_students); ?>)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($course_students)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-user-slash fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Bu derse henüz öğrenci eklenmemiş</h5>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover student-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Öğrenci Adı</th>
                                            <th>Öğrenci No</th>
                                            <th>E-posta</th>
                                            <th>Kayıt Tarihi</th>
                                            <th class="text-center">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($course_students as $student): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo htmlspecialchars($student['student_number']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                                <td><?php echo date('d.m.Y', strtotime($student['enrollment_date'])); ?></td>
                                                <td class="text-center">
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Bu öğrenciyi dersten çıkarmak istediğinizden emin misiniz?')">
                                                        <input type="hidden" name="action" value="remove_student">
                                                        <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
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
        </div>

    <?php else: ?>
        <!-- Courses List -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h3>
                        <i class="fas fa-book me-2"></i>
                        Derslerim
                    </h3>
                    <a href="courses.php?action=create" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>Yeni Ders Oluştur
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <?php if (empty($teacher_courses)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
                            <h4 class="text-muted">Henüz Ders Yok</h4>
                            <p class="text-muted">İlk dersinizi oluşturun ve öğrencilerinizi yönetmeye başlayın.</p>
                            <a href="courses.php?action=create" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>İlk Dersi Oluştur
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($teacher_courses as $course): ?>
                            <div class="col-xl-4 col-lg-6 mb-4">
                                <div class="course-card card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title text-primary">
                                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                                </h5>
                                                <p class="card-text text-muted mb-1">
                                                    <i class="fas fa-code me-1"></i>
                                                    <strong><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                                </p>
                                                <p class="card-text mb-2">
                                                    <span class="badge badge-semester me-1">
                                                        <?php echo htmlspecialchars($course['semester']); ?>
                                                    </span>
                                                    <span class="badge badge-year">
                                                        <?php echo htmlspecialchars($course['academic_year']); ?>
                                                    </span>
                                                </p>
                                            </div>
                                            <?php if ($course['active_sessions'] > 0): ?>
                                                <span class="badge bg-success">Aktif Yoklama</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="row text-center mb-3">
                                            <div class="col-4">
                                                <div class="text-info">
                                                    <i class="fas fa-users"></i>
                                                    <div class="fw-bold"><?php echo $course['student_count']; ?></div>
                                                    <small>Öğrenci</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-secondary">
                                                    <i class="fas fa-user-check"></i>
                                                    <div class="fw-bold"><?php echo $course['max_students']; ?></div>
                                                    <small>Kapasite</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-primary">
                                                    <i class="fas fa-calendar-check"></i>
                                                    <div class="fw-bold"><?php echo $course['session_count']; ?></div>
                                                    <small>Yoklama</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="progress mb-3" style="height: 8px;">
                                            <?php $capacity_rate = $course['max_students'] > 0 ? ($course['student_count'] / $course['max_students']) * 100 : 0; ?>
                                            <div class="progress-bar bg-info" style="width: <?php echo $capacity_rate; ?>%"></div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y', strtotime($course['created_at'])); ?>
                                            </small>
                                            <div>
                                                <a href="courses.php?action=edit&id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-warning btn-sm me-1" title="Ders Düzenle">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="course_materials.php?course_id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-info btn-sm me-1" title="Ders İçerikleri">
                                                    <i class="fas fa-video"></i>
                                                </a>
                                                <a href="courses.php?action=manage&id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-primary btn-sm me-1" title="Öğrenci Yönetimi">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                <form method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Bu dersi silmek istediğinizden emin misiniz? Tüm veriler kaybolacaktır.')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Dersi Sil">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Course code uppercase transformation
    document.getElementById('course_code')?.addEventListener('input', function(e) {
        e.target.value = e.target.value.toUpperCase();
    });
    
    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Lütfen tüm gerekli alanları doldurun.');
            }
        });
    });
</script>

<?php include '../includes/components/shared_footer.php'; ?>