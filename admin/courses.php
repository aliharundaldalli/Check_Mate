<?php
// courses.php

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
$action = $_GET['action'] ?? 'list';
$course_id = (int)($_GET['id'] ?? 0);

// --- Form & Action Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $handler_action = $_POST['action'] ?? $action;

    try {
        $db->beginTransaction();

        switch ($handler_action) {
            case 'add':
            case 'edit':
                $courseIdPost = (int)($_POST['course_id'] ?? 0);
                $course_name = filter_input(INPUT_POST, 'course_name', FILTER_SANITIZE_STRING);
                $course_code = filter_input(INPUT_POST, 'course_code', FILTER_SANITIZE_STRING);
                $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT) ?: null;
                $semester = filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_STRING);
                $academic_year = filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_STRING);
                $max_students = filter_input(INPUT_POST, 'max_students', FILTER_VALIDATE_INT);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if (empty($course_name) || empty($course_code) || empty($semester) || empty($academic_year)) {
                    throw new Exception("Lütfen tüm zorunlu alanları doldurun.");
                }

                // Check for duplicate course code on add or when changing code on edit
                $stmt = $db->prepare("SELECT id FROM courses WHERE course_code = ? AND id != ?");
                $stmt->execute([$course_code, $courseIdPost]);
                if ($stmt->fetch()) {
                    throw new Exception("Bu ders kodu zaten başka bir ders için kullanılıyor.");
                }

                if ($handler_action === 'edit') { // Update
                    $sql = "UPDATE courses SET course_name = ?, course_code = ?, teacher_id = ?, semester = ?, academic_year = ?, max_students = ?, is_active = ? WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$course_name, $course_code, $teacher_id, $semester, $academic_year, $max_students, $is_active, $courseIdPost]);
                    $_SESSION['message'] = ['text' => 'Ders başarıyla güncellendi.', 'type' => 'success'];
                } else { // Insert
                    $sql = "INSERT INTO courses (course_name, course_code, teacher_id, semester, academic_year, max_students) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$course_name, $course_code, $teacher_id, $semester, $academic_year, $max_students]);
                    $_SESSION['message'] = ['text' => 'Ders başarıyla eklendi.', 'type' => 'success'];
                }
                break;

            case 'enroll':
                $student_id = (int)($_POST['student_id'] ?? 0);
                
                if (empty($student_id) || empty($course_id)) {
                    throw new Exception("Öğrenci veya ders bilgisi eksik.");
                }

                // Check if enrollment exists
                $stmt = $db->prepare("SELECT id, is_active FROM course_enrollments WHERE student_id = ? AND course_id = ?");
                $stmt->execute([$student_id, $course_id]);
                $enrollment = $stmt->fetch();

                if ($enrollment) {
                    if ($enrollment['is_active']) {
                        $_SESSION['message'] = ['text' => 'Öğrenci zaten bu derse kayıtlı.', 'type' => 'warning'];
                    } else {
                        // Re-activate enrollment
                        $stmt = $db->prepare("UPDATE course_enrollments SET is_active = 1 WHERE id = ?");
                        $stmt->execute([$enrollment['id']]);
                        $_SESSION['message'] = ['text' => 'Öğrencinin ders kaydı yeniden aktifleştirildi.', 'type' => 'success'];
                    }
                } else {
                    // Create new enrollment
                    $stmt = $db->prepare("INSERT INTO course_enrollments (student_id, course_id, is_active) VALUES (?, ?, 1)");
                    $stmt->execute([$student_id, $course_id]);
                    $_SESSION['message'] = ['text' => 'Öğrenci derse başarıyla kaydedildi.', 'type' => 'success'];
                }
                break;
        }
        
        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = ['text' => 'Bir hata oluştu: ' . $e->getMessage(), 'type' => 'danger'];
    }

    // Redirect after POST to prevent re-submission
    $redirect_url = ($action === 'view' || $handler_action === 'enroll') ? "courses.php?action=view&id={$course_id}" : "courses.php";
    redirect($redirect_url);
    exit;
}

// --- GET Request Handler (for Deletion and Page Views) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {
        case 'delete':
            // Using a soft delete by setting is_active to 0
            $stmt = $db->prepare("UPDATE courses SET is_active = 0 WHERE id = ?");
            if ($stmt->execute([$course_id])) {
                $_SESSION['message'] = ['text' => 'Ders başarıyla deaktif edildi.', 'type' => 'success'];
            } else {
                $_SESSION['message'] = ['text' => 'Ders deaktif edilirken bir hata oluştu.', 'type' => 'danger'];
            }
            redirect('courses.php');
            exit;

        case 'remove_student':
            $student_id = (int)($_GET['student_id'] ?? 0);
            $stmt = $db->prepare("UPDATE course_enrollments SET is_active = 0 WHERE student_id = ? AND course_id = ?");
            if ($stmt->execute([$student_id, $course_id])) {
                $_SESSION['message'] = ['text' => 'Öğrenci dersten başarıyla çıkarıldı.', 'type' => 'success'];
            } else {
                $_SESSION['message'] = ['text' => 'Öğrenci dersten çıkarılırken bir hata oluştu.', 'type' => 'danger'];
            }
            redirect("courses.php?action=view&id={$course_id}");
            exit;
    }
}


// --- Data Fetching for Page Display ---
$page_data = [];
$page_title = "Ders Yönetimi";

switch ($action) {
    case 'add':
    case 'edit':
        $page_title = $action === 'add' ? 'Yeni Ders Ekle' : 'Ders Düzenle';
        // Fetch teachers for the dropdown
        $page_data['teachers'] = $db->query("SELECT id, full_name FROM users WHERE user_type = 'teacher' AND is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        if ($action === 'edit') {
            $stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $page_data['course'] = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$page_data['course']) {
                show_message('Ders bulunamadı.', 'danger');
                redirect('courses.php');
            }
        }
        break;

    case 'view':
        $stmt = $db->prepare("SELECT c.*, u.full_name as teacher_name FROM courses c LEFT JOIN users u ON c.teacher_id = u.id WHERE c.id = ?");
        $stmt->execute([$course_id]);
        $page_data['course'] = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page_data['course']) {
            show_message('Ders bulunamadı.', 'danger');
            redirect('courses.php');
        }
        $page_title = "Ders Detayları: " . htmlspecialchars($page_data['course']['course_name']);

        $stmt = $db->prepare("SELECT u.id, u.full_name, u.student_number, u.email, ce.enrollment_date FROM users u JOIN course_enrollments ce ON u.id = ce.student_id WHERE ce.course_id = ? AND ce.is_active = 1 ORDER BY u.full_name");
        $stmt->execute([$course_id]);
        $page_data['enrolled_students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT id, full_name, student_number FROM users WHERE user_type = 'student' AND is_active = 1 AND id NOT IN (SELECT student_id FROM course_enrollments WHERE course_id = ? AND is_active = 1) ORDER BY full_name");
        $stmt->execute([$course_id]);
        $page_data['available_students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    
    case 'list':
    default:
        $stmt = $db->query("SELECT c.*, u.full_name as teacher_name, (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as student_count FROM courses c LEFT JOIN users u ON c.teacher_id = u.id ORDER BY c.created_at DESC");
        $page_data['courses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

include '../includes/components/admin_header.php';
?>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- Add/Edit Form View -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><?php echo $page_title; ?></h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="courses.php">
                            <input type="hidden" name="action" value="<?php echo $action; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                            <div class="row">
                                <div class="col-md-6 mb-3"><label for="course_name" class="form-label">Ders Adı *</label><input type="text" class="form-control" id="course_name" name="course_name" value="<?php echo htmlspecialchars($page_data['course']['course_name'] ?? ''); ?>" required></div>
                                <div class="col-md-6 mb-3"><label for="course_code" class="form-label">Ders Kodu *</label><input type="text" class="form-control" id="course_code" name="course_code" value="<?php echo htmlspecialchars($page_data['course']['course_code'] ?? ''); ?>" required></div>
                            </div>
                            <div class="mb-3"><label for="teacher_id" class="form-label">Öğretmen</label><select class="form-select" id="teacher_id" name="teacher_id"><option value="">Öğretmen Seçin</option><?php foreach ($page_data['teachers'] as $teacher): ?><option value="<?php echo $teacher['id']; ?>" <?php if(isset($page_data['course']) && $page_data['course']['teacher_id'] == $teacher['id']) echo 'selected'; ?>><?php echo htmlspecialchars($teacher['full_name']); ?></option><?php endforeach; ?></select></div>
                            <div class="row">
                                <div class="col-md-6 mb-3"><label for="semester" class="form-label">Dönem *</label><select class="form-select" id="semester" name="semester" required><option value="">Dönem Seçin</option><option value="Güz" <?php if(isset($page_data['course']) && $page_data['course']['semester'] == 'Güz') echo 'selected'; ?>>Güz</option><option value="Bahar" <?php if(isset($page_data['course']) && $page_data['course']['semester'] == 'Bahar') echo 'selected'; ?>>Bahar</option><option value="Yaz" <?php if(isset($page_data['course']) && $page_data['course']['semester'] == 'Yaz') echo 'selected'; ?>>Yaz</option></select></div>
                                <div class="col-md-6 mb-3"><label for="academic_year" class="form-label">Akademik Yıl *</label><input type="text" class="form-control" id="academic_year" name="academic_year" value="<?php echo htmlspecialchars($page_data['course']['academic_year'] ?? date('Y').'-'.(date('Y')+1)); ?>" placeholder="2024-2025" required></div>
                            </div>
                            <div class="mb-3"><label for="max_students" class="form-label">Maksimum Öğrenci</label><input type="number" class="form-control" id="max_students" name="max_students" value="<?php echo $page_data['course']['max_students'] ?? 100; ?>" min="1"></div>
                            <?php if ($action === 'edit'): ?>
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" <?php if(isset($page_data['course']) && $page_data['course']['is_active']) echo 'checked'; ?>><label class="form-check-label" for="is_active">Ders Aktif</label></div>
                            <?php endif; ?>
                            <hr>
                            <div class="d-flex justify-content-end">
                                <a href="courses.php" class="btn btn-secondary me-2">İptal</a>
                                <button type="submit" class="btn btn-primary"><?php echo $action === 'edit' ? 'Güncelle' : 'Kaydet'; ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'view'): ?>
        <!-- Course Detail View -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><?php echo htmlspecialchars($page_data['course']['course_name']); ?></h2>
            <a href="courses.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Ders Listesi</a>
        </div>
        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header"><h4>Ders Bilgileri</h4></div>
                    <div class="card-body">
                        <p><strong>Kod:</strong> <?php echo htmlspecialchars($page_data['course']['course_code']); ?></p>
                        <p><strong>Öğretmen:</strong> <?php echo htmlspecialchars($page_data['course']['teacher_name'] ?? 'Atanmamış'); ?></p>
                        <p><strong>Dönem:</strong> <?php echo htmlspecialchars($page_data['course']['semester'] . ' ' . $page_data['course']['academic_year']); ?></p>
                        <p><strong>Kontenjan:</strong> <?php echo count($page_data['enrolled_students']); ?> / <?php echo $page_data['course']['max_students']; ?></p>
                        <p><strong>Durum:</strong> <span class="badge bg-<?php echo $page_data['course']['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $page_data['course']['is_active'] ? 'Aktif' : 'Pasif'; ?></span></p>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header"><h4>Öğrenci Kaydet</h4></div>
                    <div class="card-body">
                        <form method="POST" action="courses.php?action=view&id=<?php echo $course_id; ?>">
                            <input type="hidden" name="action" value="enroll">
                            <div class="mb-3"><label for="student_id" class="form-label">Öğrenci Seç</label><select class="form-select" id="student_id" name="student_id" required><option value="">Seçiniz...</option><?php foreach ($page_data['available_students'] as $student): ?><option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['full_name'] . ' (' . $student['student_number'] . ')'); ?></option><?php endforeach; ?></select></div>
                            <button type="submit" class="btn btn-primary w-100">Derse Ekle</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h4>Kayıtlı Öğrenciler</h4></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th>Ad Soyad</th><th>Numara</th><th>E-posta</th><th>İşlem</th></tr></thead>
                                <tbody>
                                    <?php if(empty($page_data['enrolled_students'])): ?>
                                        <tr><td colspan="4" class="text-center">Bu derse kayıtlı öğrenci yok.</td></tr>
                                    <?php else: foreach($page_data['enrolled_students'] as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['student_number']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><a href="courses.php?action=remove_student&id=<?php echo $course_id; ?>&student_id=<?php echo $student['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu öğrenciyi dersten çıkarmak istediğinizden emin misiniz?');"><i class="fas fa-times"></i></a></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Course List View -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Dersler</h2>
            <a href="courses.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Yeni Ders Ekle</a>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Ders Adı</th><th>Kod</th><th>Öğretmen</th><th>Dönem</th><th>Öğrenci</th><th>Durum</th><th class="text-end">İşlemler</th></tr></thead>
                        <tbody>
                            <?php if(empty($page_data['courses'])): ?>
                                <tr><td colspan="7" class="text-center py-4">Sistemde kayıtlı ders bulunmuyor.</td></tr>
                            <?php else: foreach ($page_data['courses'] as $course): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($course['course_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                <td><?php echo htmlspecialchars($course['teacher_name'] ?? 'Atanmamış'); ?></td>
                                <td><?php echo htmlspecialchars($course['semester'] . ' ' . $course['academic_year']); ?></td>
                                <td><span class="badge bg-info"><?php echo $course['student_count']; ?> / <?php echo $course['max_students']; ?></span></td>
                                <td><span class="badge bg-<?php echo $course['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $course['is_active'] ? 'Aktif' : 'Pasif'; ?></span></td>
                                <td class="text-end">
                                    <a href="courses.php?action=view&id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info" title="Detayları Gör"><i class="fas fa-eye"></i></a>
                                    <a href="courses.php?action=edit&id=<?php echo $course['id']; ?>" class="btn btn-sm btn-primary" title="Düzenle"><i class="fas fa-edit"></i></a>
                                    <a href="course_content.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-success" title="İçerik Yönetimi"><i class="fas fa-list-alt"></i></a>
                                    <a href="courses.php?action=delete&id=<?php echo $course['id']; ?>" class="btn btn-sm btn-warning" title="Deaktif Et" onclick="return confirm('Bu dersi deaktif etmek istediğinizden emin misiniz? Öğrenci kayıtları korunur.');"><i class="fas fa-times-circle"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/components/shared_footer.php'; ?>

