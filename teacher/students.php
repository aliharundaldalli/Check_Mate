<?php
// students.php - Öğretmen Öğrenci Listesi ve Yoklama Yönetimi

// Error reporting ve session ayarları
error_reporting(E_ALL);
ini_set('display_errors', 0); // Üretimde 0 olmalı
ini_set('log_errors', 1);
ini_set('error_log', '../../logs/php_errors.log'); // Hata log dosyasının yolu
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);

require_once '../includes/functions.php';

try {
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
    error_log('Teacher Students Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Site ayarlarını yükle
try {
    $query = "SELECT setting_key, setting_value FROM site_settings";
    $stmt = $db->prepare($query);
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
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';
$theme_color = $site_settings['theme_color'] ?? '#3498db';
// $max_absence_percentage değişkenini global scope'ta tanımlayalım
$max_absence_percentage = (int)($site_settings['max_absence_percentage'] ?? 35);


// Filtreleme parametreleri
$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$filter_status = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all'; // all, regular, warning, critical
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$student_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT) ?: 0;

// API istekleri için (Değişiklik yok)
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    if ($_GET['api'] === 'get_sessions' && $course_id > 0) {
        // Dersin yoklama oturumlarını getir
        $query = "SELECT id, session_name, session_date, start_time
                  FROM attendance_sessions
                  WHERE course_id = :course_id AND teacher_id = :teacher_id
                  ORDER BY session_date DESC, start_time DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([':course_id' => $course_id, ':teacher_id' => $teacher_id]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($sessions);
        exit;
    }
     // Yeni API endpoint: Öğrenci materyal ilerlemesi
     elseif ($_GET['api'] === 'get_material_progress' && $student_id > 0 && $course_id > 0) {
        try {
            // Toplam zorunlu materyal sayısı
            $query_total = "SELECT COUNT(cm.id)
                            FROM course_materials cm
                            JOIN course_weeks cw ON cm.week_id = cw.id
                            WHERE cw.course_id = :course_id AND cm.is_required = 1";
            $stmt_total = $db->prepare($query_total);
            $stmt_total->execute([':course_id' => $course_id]);
            $total_required = (int)$stmt_total->fetchColumn();

            // Öğrencinin tamamladığı zorunlu materyal sayısı
            $query_completed = "SELECT COUNT(smp.id)
                                FROM student_material_progress smp
                                JOIN course_materials cm ON smp.material_id = cm.id
                                JOIN course_weeks cw ON cm.week_id = cw.id
                                WHERE smp.student_id = :student_id
                                AND cw.course_id = :course_id
                                AND cm.is_required = 1
                                AND smp.is_completed = 1";
            $stmt_completed = $db->prepare($query_completed);
            $stmt_completed->execute([':student_id' => $student_id, ':course_id' => $course_id]);
            $completed_required = (int)$stmt_completed->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'total_required' => $total_required,
                'completed_required' => $completed_required,
                'progress_percentage' => $total_required > 0 ? round(($completed_required / $total_required) * 100) : 100 // Eğer zorunlu yoksa %100 kabul et
            ]);
        } catch (Exception $e) {
            error_log("API get_material_progress error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Materyal ilerlemesi alınırken hata oluştu.']);
        }
        exit;
    }
}


// Manuel yoklama ekleme (Değişiklik yok)
if ($action === 'add_attendance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_course_id = (int)($_POST['course_id'] ?? 0);
    $posted_student_id = (int)($_POST['student_id'] ?? 0);
    try {
        $session_id = (int)$_POST['session_id'];

        // Yetki kontrolü - öğretmen bu oturumu açmış mı?
        $query = "SELECT * FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':session_id' => $session_id, ':teacher_id' => $teacher_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new Exception('Bu oturuma erişim yetkiniz bulunmamaktadır.');
        }

        // Yoklama kaydı var mı kontrol et
        $query = "SELECT * FROM attendance_records WHERE session_id = :session_id AND student_id = :student_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':session_id' => $session_id, ':student_id' => $posted_student_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Eğer kayıt varsa ve 2. faz tamamlanmamışsa, güncelle
            if (!$existing['second_phase_completed']) {
                $query = "UPDATE attendance_records
                          SET second_phase_completed = 1,
                              is_manual_entry = 1,
                              ip_address = 'Manual Entry',
                              user_agent = 'Teacher Override - Completed'
                          WHERE id = :record_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':record_id' => $existing['id']]);

                show_message('Yarım kalan yoklama kaydı tamamlandı.', 'success');
            } else {
                throw new Exception('Bu öğrenci için zaten tamamlanmış yoklama kaydı bulunmaktadır.');
            }
        } else {
            // Yeni yoklama kaydı ekle
            $query = "INSERT INTO attendance_records (session_id, student_id, second_phase_completed, attendance_time, ip_address, user_agent, is_manual_entry)
                      VALUES (:session_id, :student_id, 1, NOW(), 'Manual Entry', 'Teacher Override', 1)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':session_id' => $session_id,
                ':student_id' => $posted_student_id
            ]);

            show_message('Yoklama kaydı başarıyla eklendi.', 'success');
        }

    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }

    // Redirect back to the detail page
    redirect("students.php?action=detail&student_id=$posted_student_id&course_id=$posted_course_id");
    exit;
}

// Yoklama kaydı silme (Değişiklik yok)
if ($action === 'delete_attendance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_course_id = (int)($_POST['course_id'] ?? 0);
    $posted_student_id = (int)($_POST['student_id'] ?? 0);
    try {
        $record_id = (int)$_POST['record_id'];

        // Yetki kontrolü - öğretmen bu kaydı silebilir mi?
        $query = "SELECT ar.* FROM attendance_records ar
                  JOIN attendance_sessions s ON ar.session_id = s.id
                  WHERE ar.id = :record_id AND s.teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':record_id' => $record_id, ':teacher_id' => $teacher_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new Exception('Bu kayda erişim yetkiniz bulunmamaktadır.');
        }

        // Kaydı sil
        $query = "DELETE FROM attendance_records WHERE id = :record_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':record_id' => $record_id]);

        show_message('Yoklama kaydı başarıyla silindi.', 'success');

    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }

    // Redirect back to the detail page
    redirect("students.php?action=detail&student_id=$posted_student_id&course_id=$posted_course_id");
    exit;
}

// Yarım kalan kayıtları toplu tamamlama (Tek öğrenci için) (Değişiklik yok)
if ($action === 'complete_partial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_course_id = (int)($_POST['course_id'] ?? 0);
    $posted_student_id = (int)($_POST['student_id'] ?? 0);
    try {
        // Yetki kontrolü ve yarım kalan kayıtları bul
        $query = "SELECT ar.id FROM attendance_records ar
                  JOIN attendance_sessions s ON ar.session_id = s.id
                  WHERE ar.student_id = :student_id
                  AND s.course_id = :course_id
                  AND s.teacher_id = :teacher_id
                  AND ar.second_phase_completed = 0
                  AND s.status IN ('closed', 'expired')";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':student_id' => $posted_student_id,
            ':course_id' => $posted_course_id,
            ':teacher_id' => $teacher_id
        ]);
        $partial_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($partial_records)) {
            throw new Exception('Tamamlanacak yarım kalmış kayıt bulunamadı.');
        }

        // Tüm yarım kalan kayıtları tamamla
        $record_ids = array_column($partial_records, 'id');
        $placeholders = implode(',', array_fill(0, count($record_ids), '?'));

        $query = "UPDATE attendance_records
                  SET second_phase_completed = 1,
                      is_manual_entry = 1,
                      user_agent = 'Teacher Override - Bulk Completed'
                  WHERE id IN ($placeholders)";
        $stmt = $db->prepare($query);
        $stmt->execute($record_ids);

        $count = count($partial_records);
        show_message("$count adet yarım kalan yoklama kaydı tamamlandı.", 'success');

    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }

    redirect("students.php?action=detail&student_id=$posted_student_id&course_id=$posted_course_id");
    exit;
}

// Tüm öğrencilerin yarım kalan kayıtlarını tamamlama (Ders bazında) (Değişiklik yok)
if ($action === 'complete_all_partial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_course_id = (int)($_POST['course_id'] ?? 0);
    try {
        // Yetki kontrolü ve dersin tüm yarım kalan kayıtlarını bul
        $query = "SELECT ar.id FROM attendance_records ar
                  JOIN attendance_sessions s ON ar.session_id = s.id
                  WHERE s.course_id = :course_id
                  AND s.teacher_id = :teacher_id
                  AND ar.second_phase_completed = 0
                  AND s.status IN ('closed', 'expired')";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':course_id' => $posted_course_id,
            ':teacher_id' => $teacher_id
        ]);
        $partial_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($partial_records)) {
            show_message('Bu derste tamamlanacak yarım kalmış kayıt bulunamadı.', 'info');
        } else {
            // Tüm yarım kalan kayıtları tamamla
            $record_ids = array_column($partial_records, 'id');
            $placeholders = implode(',', array_fill(0, count($record_ids), '?'));

            $query = "UPDATE attendance_records
                      SET second_phase_completed = 1,
                          is_manual_entry = 1,
                          user_agent = 'Teacher Override - Bulk Completed (Course-wide)'
                      WHERE id IN ($placeholders)";
            $stmt = $db->prepare($query);
            $stmt->execute($record_ids);

            $count = count($partial_records);
            show_message("Toplam $count öğrencinin yarım kalan yoklama kaydı tamamlandı.", 'success');
        }

    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }

    redirect("students.php?course_id=$posted_course_id");
    exit;
}


// Öğretmenin derslerini al (Değişiklik yok)
try {
    $query = "SELECT c.id, c.course_name, c.course_code,
             (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as student_count,
             (SELECT COUNT(*) FROM attendance_sessions s WHERE s.course_id = c.id AND s.teacher_id = :teacher_id) as session_count
             FROM courses c
             WHERE c.teacher_id = :teacher_id AND c.is_active = 1
             ORDER BY c.course_name";
    $stmt = $db->prepare($query);
    $stmt->execute([':teacher_id' => $teacher_id]);
    $teacher_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Teacher courses error: ' . $e->getMessage());
    $teacher_courses = [];
}

// Seçili dersin bilgilerini al (Değişiklik yok)
$selected_course = null;
if ($course_id > 0) {
    foreach ($teacher_courses as $course) {
        if ($course['id'] == $course_id) {
            $selected_course = $course;
            break;
        }
    }
}

// Öğrenci listesini al
$students = [];
$student_stats = [];
if ($course_id > 0) {
    try {
        // GÜNCELLEME: Materyal ilerlemesi için gerekli alanlar eklendi
        $query = "SELECT
                     u.id, u.full_name, u.student_number, u.email, u.phone, u.profile_image, ce.enrollment_date,
                     (SELECT COUNT(*) FROM attendance_sessions
                      WHERE course_id = :course_id AND status IN ('closed', 'expired')) as total_sessions,
                     (SELECT COUNT(*) FROM attendance_records ar
                      JOIN attendance_sessions s ON ar.session_id = s.id
                      WHERE s.course_id = :course_id AND ar.student_id = u.id AND ar.second_phase_completed = 1 AND s.status IN ('closed', 'expired')) as attended_sessions,
                     (SELECT COUNT(*) FROM attendance_records ar
                      JOIN attendance_sessions s ON ar.session_id = s.id
                      WHERE s.course_id = :course_id AND ar.student_id = u.id AND ar.is_manual_entry = 1 AND ar.second_phase_completed = 1 AND s.status IN ('closed', 'expired')) as manual_entries,
                     -- Toplam Zorunlu Materyal Sayısı
                     (SELECT COUNT(cm.id)
                      FROM course_materials cm
                      JOIN course_weeks cw ON cm.week_id = cw.id
                      WHERE cw.course_id = :course_id AND cm.is_required = 1) as total_required_materials,
                     -- Tamamlanan Zorunlu Materyal Sayısı
                     (SELECT COUNT(smp.id)
                      FROM student_material_progress smp
                      JOIN course_materials cm ON smp.material_id = cm.id
                      JOIN course_weeks cw ON cm.week_id = cw.id
                      WHERE smp.student_id = u.id AND cw.course_id = :course_id AND cm.is_required = 1 AND smp.is_completed = 1) as completed_required_materials
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.student_id
                  WHERE ce.course_id = :course_id AND ce.is_active = 1 AND u.user_type = 'student'";

        // Arama filtresi (Değişiklik yok)
        if (!empty($search)) {
            $query .= " AND (u.full_name LIKE :search OR u.student_number LIKE :search OR u.email LIKE :search)";
        }

        $query .= " ORDER BY u.full_name";

        $stmt = $db->prepare($query);
        $params = [':course_id' => $course_id];
        if (!empty($search)) {
            $params[':search'] = "%$search%";
        }
        $stmt->execute($params);
        $students_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // İstatistikleri hesapla ve filtreleme uygula
        $regular_count = 0;
        $warning_count = 0;
        $critical_count = 0;

        foreach ($students_raw as $student) {
            $total = (int)$student['total_sessions'];
            $attended = (int)$student['attended_sessions'];
            $absent = $total - $attended;
            $attendance_rate = $total > 0 ? round(($attended / $total) * 100, 1) : 100; // Yoklama yoksa %100 kabul et
            $absence_rate = 100 - $attendance_rate;

             // Materyal ilerlemesi hesapla
            $total_required = (int)$student['total_required_materials'];
            $completed_required = (int)$student['completed_required_materials'];
            $material_progress_rate = $total_required > 0 ? round(($completed_required / $total_required) * 100) : 100; // Zorunlu yoksa %100

            // Durum belirleme (Devamsızlığa göre)
            $status = 'regular';
            // DİKKAT: $max_absence_percentage'i globalden almak yerine doğrudan kullanalım
            if ($absence_rate > $max_absence_percentage) {
                $status = 'critical';
                $critical_count++;
            } elseif ($absence_rate > ($max_absence_percentage * 0.7)) { // %70 sınırı
                $status = 'warning';
                $warning_count++;
            } else {
                 $regular_count++;
            }


            $student['total_sessions'] = $total;
            $student['attended_sessions'] = $attended;
            $student['absent_sessions'] = $absent;
            $student['attendance_rate'] = $attendance_rate;
            $student['absence_rate'] = $absence_rate;
            $student['status'] = $status;
            $student['material_progress_rate'] = $material_progress_rate; // Yeni eklenen alan
            $student['total_required_materials'] = $total_required; // Yeni eklenen alan
            $student['completed_required_materials'] = $completed_required; // Yeni eklenen alan

            // Durum filtrelemesi
            if ($filter_status === 'all' || $filter_status === $status) {
                $students[] = $student;
            }
        }

        // İstatistikler (Doğrudan sayım ile)
        $total_students = count($students_raw);
        $student_stats = [
            'total' => $total_students,
            'regular' => $regular_count,
            'warning' => $warning_count,
            'critical' => $critical_count // Düzeltilmiş sayım kullanılıyor
        ];

    } catch (Exception $e) {
        error_log('Students query error: ' . $e->getMessage());
        show_message('Öğrenci listesi alınırken bir hata oluştu: ' . $e->getMessage(), 'danger');
    }
}


// Öğrenci detayları için yoklama kayıtları (Değişiklik yok)
$student_detail = null;
$attendance_records = [];
if ($action === 'detail' && $student_id > 0 && $course_id > 0) {
    try {
        // Öğrenci bilgilerini al
        $query = "SELECT u.*, ce.enrollment_date
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.student_id
                  WHERE u.id = :student_id AND ce.course_id = :course_id AND ce.is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->execute([':student_id' => $student_id, ':course_id' => $course_id]);
        $student_detail = $stmt->fetch(PDO::FETCH_ASSOC);

        // Yoklama kayıtlarını al
        // ✅ SADECE KAPATILMIŞ VE SÜRESİ DOLMUŞ OTURUMLAR
        if ($student_detail) {
            $query = "SELECT
                            s.id as session_id, s.session_name, s.session_date, s.start_time, s.duration_minutes, s.status,
                            ar.id as record_id, ar.attendance_time, ar.is_manual_entry, ar.first_phase_key_id, ar.second_phase_completed
                          FROM attendance_sessions s
                          LEFT JOIN attendance_records ar ON s.id = ar.session_id AND ar.student_id = :student_id
                          WHERE s.course_id = :course_id
                          AND s.teacher_id = :teacher_id
                          AND s.status IN ('closed', 'expired')
                          ORDER BY s.session_date DESC, s.start_time DESC";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':student_id' => $student_id,
                ':course_id' => $course_id,
                ':teacher_id' => $teacher_id
            ]);
            $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log('Student detail error: ' . $e->getMessage());
         show_message('Öğrenci detayları alınırken bir hata oluştu.', 'danger');
    }
}


$page_title = ($action === 'detail' && $student_detail ? $student_detail['full_name'] . ' Detayları' : "Öğrenci Listesi") . " - " . htmlspecialchars($site_name);
include '../includes/components/teacher_header.php';
?>

<style>
    /* ... Mevcut stiller ... */
    .material-progress-bar-container {
        margin-top: 0.5rem;
    }
     .material-progress-text {
        font-size: 0.75rem;
        color: #6c757d;
        margin-bottom: 0.2rem;
     }

    :root {
        --theme-color: <?php echo $theme_color; ?>;
        --theme-color-rgb: <?php
            list($r, $g, $b) = sscanf($theme_color, "#%02x%02x%02x");
            echo "$r, $g, $b";
        ?>;
        --theme-color-light: rgba(var(--theme-color-rgb), 0.1);
        --theme-color-bg: rgba(var(--theme-color-rgb), 0.15);
    }

    .course-card {
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }

    .course-card:hover, .course-card.active {
        /* border-left-color: var(--theme-color); */ /* Stil tema rengine göre ayarlandı */
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(var(--theme-color-rgb), 0.2);
    }
     .course-card.active {
         border-left-color: var(--theme-color); /* Aktif olanı tema rengiyle vurgula */
     }


    .stats-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }

    .stats-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .stats-card.total { border-left-color: var(--theme-color); }
    .stats-card.total .icon { color: var(--theme-color); }
    .stats-card.regular { border-left-color: #28a745; }
    .stats-card.regular .icon { color: #28a745; }
    .stats-card.warning { border-left-color: #ffc107; }
    .stats-card.warning .icon { color: #ffc107; }
    .stats-card.critical { border-left-color: #dc3545; }
    .stats-card.critical .icon { color: #dc3545; }

    .stats-card .icon {
        font-size: 2rem;
        opacity: 0.7;
        margin-bottom: 0.5rem;
    }

    .stats-card h3 {
        font-size: 2.5rem;
        margin: 0.5rem 0;
        font-weight: bold;
    }

    .student-list-card {
        cursor: pointer;
        transition: all 0.2s ease;
        border-left: 3px solid transparent;
        border-radius: 8px; /* Added border radius */
    }

    .student-list-card:hover {
        border-left-color: var(--theme-color);
        transform: translateX(3px);
        box-shadow: 0 3px 10px rgba(0,0,0,0.1);
    }

    .student-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
    }

    .student-avatar-placeholder {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.5rem;
    }

    .filter-tabs .nav-link {
        color: #6c757d;
        border-radius: 20px;
        padding: 0.5rem 1.2rem;
        margin-right: 0.5rem;
        transition: all 0.3s ease;
        border: 1px solid transparent; /* Add border for consistency */
    }
     .filter-tabs .nav-link:last-child {
         margin-right: 0; /* Remove margin from last item */
     }

    .filter-tabs .nav-link:hover {
        background-color: var(--theme-color-light);
        color: var(--theme-color);
         border-color: var(--theme-color-light);
    }

    .filter-tabs .nav-link.active {
        background-color: var(--theme-color);
        color: white;
        border-color: var(--theme-color);
    }

    .attendance-timeline {
        position: relative;
    }

    .timeline-item {
        position: relative;
        padding-left: 50px; /* Increased padding */
        padding-bottom: 2rem;
        border-left: 2px solid #e9ecef;
        margin-left: 20px;
    }

    .timeline-item:last-child {
        border-left: 2px solid transparent;
        padding-bottom: 0;
    }

    .timeline-dot {
        position: absolute;
        left: -21px; /* Adjusted position */
        top: 0; /* Align with top */
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 3px solid;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: white;
        z-index: 1; /* Ensure dot is above the line */
    }
    .timeline-dot.present { border-color: #28a745; background-color: #28a745; }
    .timeline-dot.absent { border-color: #dc3545; background-color: #dc3545; }
    .timeline-dot.partial { border-color: #ffc107; background-color: #ffc107; }

     /* Responsive adjustments */
     @media (max-width: 767.98px) {
        .stats-card h3 { font-size: 2rem; }
        .filter-tabs .nav-link { padding: 0.4rem 0.8rem; margin-right: 0.3rem; font-size: 0.9rem;}
        .student-list-card .d-flex { flex-direction: column; align-items: flex-start !important;}
        .student-list-card .text-end { text-align: left !important; margin-top: 0.5rem;}
        .student-avatar, .student-avatar-placeholder { margin-bottom: 0.5rem;}
     }

    @media print {
        .no-print { display: none !important; }
        .main-content { margin-left: 0 !important; }
        body { font-size: 10pt; }
        .card { border: 1px solid #ccc; box-shadow: none; }
        .progress, .progress-bar { background-color: #eee !important; color: #333 !important; }
        .badge { border: 1px solid #ccc; background-color: transparent !important; color: #333 !important;}
        .timeline-dot { border: 2px solid #ccc !important; background-color: white !important; color: #333 !important;}
    }
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($action === 'detail' && $student_detail): ?>
        <!-- Öğrenci Detay Görünümü (İçerik Değişmedi) -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">
                <i class="fas fa-user-graduate me-2" style="color: <?php echo $theme_color;?>;"></i>
                <?php echo htmlspecialchars($student_detail['full_name']); ?>
            </h2>
            <a href="students.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-secondary no-print">
                <i class="fas fa-arrow-left me-1"></i>Listeye Geri Dön
            </a>
        </div>

        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <?php if (!empty($student_detail['profile_image']) && file_exists('../' . $student_detail['profile_image'])): ?>
                            <img src="../<?php echo htmlspecialchars($student_detail['profile_image']); ?>" alt="Profil Resmi" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center" style="width: 120px; height: 120px; background-color: <?php echo $theme_color; ?>; color: white; font-size: 3rem;">
                                <?php echo mb_substr($student_detail['full_name'], 0, 1); ?>
                            </div>
                        <?php endif; ?>
                        <h5 class="card-title"><?php echo htmlspecialchars($student_detail['full_name']); ?></h5>
                        <p class="text-muted"><?php echo htmlspecialchars($student_detail['student_number']); ?></p>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item"><i class="fas fa-envelope fa-fw me-2 text-muted"></i><?php echo htmlspecialchars($student_detail['email']); ?></li>
                        <?php if ($student_detail['phone']): ?>
                        <li class="list-group-item"><i class="fas fa-phone fa-fw me-2 text-muted"></i><?php echo htmlspecialchars($student_detail['phone']); ?></li>
                        <?php endif; ?>
                        <li class="list-group-item"><i class="fas fa-calendar-check fa-fw me-2 text-muted"></i>Kayıt: <?php echo date('d.m.Y', strtotime($student_detail['enrollment_date'])); ?></li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Genel Devam Durumu</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $total_sessions = count($attendance_records);
                        $attended_sessions = 0;
                        $manual_entries = 0;

                        foreach ($attendance_records as $record) {
                            if ($record['record_id'] && $record['second_phase_completed']) {
                                $attended_sessions++;
                                if ($record['is_manual_entry']) {
                                    $manual_entries++;
                                }
                            }
                        }

                        $absent_sessions = $total_sessions - $attended_sessions;
                        $attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100, 1) : 100; // Yoklama yoksa %100
                        $absence_rate = 100 - $attendance_rate; // Devamsızlık oranı

                        // Durum belirleme
                         $detail_status = 'regular';
                         if ($absence_rate > $max_absence_percentage) {
                             $detail_status = 'critical';
                         } elseif ($absence_rate > ($max_absence_percentage * 0.7)) {
                             $detail_status = 'warning';
                         }
                         $status_class = $detail_status === 'regular' ? 'success' : ($detail_status === 'warning' ? 'warning' : 'danger');

                        ?>

                        <div class="row text-center mb-3">
                            <div class="col">
                                <div class="h2 fw-bold"><?php echo $total_sessions; ?></div>
                                <div class="text-muted small">Toplam Oturum</div>
                            </div>
                            <div class="col">
                                <div class="h2 fw-bold text-success"><?php echo $attended_sessions; ?></div>
                                <div class="text-muted small">Katıldığı</div>
                            </div>
                            <div class="col">
                                <div class="h2 fw-bold text-danger"><?php echo $absent_sessions; ?></div>
                                <div class="text-muted small">Katılmadığı</div>
                            </div>
                        </div>
                        <label class="form-label">Devam Oranı: <span class="fw-bold text-<?php echo $status_class; ?>">%<?php echo $attendance_rate; ?></span></label>
                        <div class="progress mb-2" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-<?php echo $status_class; ?>" role="progressbar" style="width: <?php echo $attendance_rate; ?>%" aria-valuenow="<?php echo $attendance_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                <strong>%<?php echo $attendance_rate; ?></strong>
                            </div>
                        </div>
                         <label class="form-label">Devamsızlık Oranı: <span class="fw-bold text-danger">%<?php echo round($absence_rate, 1); ?></span> (Sınır: %<?php echo $max_absence_percentage; ?>)</label>
                         <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo round($absence_rate, 1); ?>%" aria-valuenow="<?php echo round($absence_rate, 1); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>


                         <?php if ($manual_entries > 0): ?>
                            <div class="alert alert-info mt-3 mb-0 small">
                                <i class="fas fa-info-circle me-1"></i>
                                <?php echo $manual_entries; ?> adet manuel yoklama girişi bulunmaktadır.
                            </div>
                        <?php endif; ?>
                    </div>
                     <!-- Zorunlu Materyal İlerlemesi -->
                     <div class="card-footer">
                         <h6 class="mb-2"><i class="fas fa-book-reader me-2"></i>Zorunlu İçerik Tamamlama</h6>
                         <div id="material-progress-container">
                             <div class="text-center text-muted">
                                 <div class="spinner-border spinner-border-sm" role="status">
                                     <span class="visually-hidden">Yükleniyor...</span>
                                 </div>
                                 <span class="ms-1">İlerleme durumu yükleniyor...</span>
                             </div>
                         </div>
                     </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Yoklama Kayıtları</h5>
                <div class="btn-group no-print" role="group">
                    <?php
                    $absent_count = 0;
                    $partial_count = 0;
                    foreach ($attendance_records as $record) {
                        if (!$record['record_id']) {
                            $absent_count++;
                        } elseif (!$record['second_phase_completed']) {
                            $partial_count++;
                            $absent_count++; // Yarım kalan da devamsız sayılır
                        }
                    }
                    ?>

                    <?php if ($partial_count > 0): ?>
                        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#completePartialModal" title="Yarım kalan kayıtları tamamla">
                            <i class="fas fa-clock me-1"></i>Yarım Kalanları Tamamla (<?php echo $partial_count; ?>)
                        </button>
                    <?php endif; ?>

                    <?php if ($absent_count > 0 || $partial_count > 0): // Devamsız VEYA yarım kalan varsa ekleme butonu göster ?>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                            <i class="fas fa-plus me-1"></i>Manuel Yoklama Ekle
                        </button>
                    <?php else: ?>
                        <span class="badge bg-success p-2" style="font-size: 0.9rem;">
                            <i class="fas fa-check-circle me-1"></i>Öğrenci tüm oturumlara katıldı
                        </span>
                    <?php endif; ?>
                      <button onclick="window.print();" class="btn btn-secondary btn-sm ms-2 no-print">
                         <i class="fas fa-print me-1"></i> Yazdır
                     </button>
                </div>
            </div>
            <div class="card-body">
                <div class="attendance-timeline">
                    <?php if(empty($attendance_records)): ?>
                        <div class="alert alert-light text-center">Bu ders için henüz tamamlanmış yoklama oturumu bulunmuyor.</div>
                    <?php else: foreach ($attendance_records as $record): ?>
                        <div class="timeline-item">
                            <?php
                            $is_attended = $record['record_id'] && $record['second_phase_completed'];
                            $is_partial = $record['record_id'] && !$record['second_phase_completed'];
                            $dot_class = $is_attended ? 'present' : ($is_partial ? 'partial' : 'absent');
                            $icon_class = $is_attended ? 'fa-check' : ($is_partial ? 'fa-clock' : 'fa-times');
                            ?>
                            <div class="timeline-dot <?php echo $dot_class; ?>">
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            </div>
                            <div class="ms-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div>
                                        <strong><?php echo htmlspecialchars($record['session_name']); ?></strong>
                                        <div class="text-muted small">
                                            <?php echo date('d.m.Y - H:i', strtotime($record['session_date'] . ' ' . $record['start_time'])); ?>
                                            (<?php echo $record['duration_minutes']; ?> dk)
                                        </div>
                                         <?php if($is_attended && $record['attendance_time']): ?>
                                             <div class="text-muted small fst-italic">
                                                Katılım: <?php echo date('d.m.Y H:i:s', strtotime($record['attendance_time'])); ?>
                                             </div>
                                         <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center mt-1 mt-sm-0">
                                        <?php if ($is_attended): ?>
                                            <span class="badge bg-success-subtle text-success-emphasis">Katıldı</span>
                                            <?php if ($record['is_manual_entry']): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis ms-1">Manuel</span>
                                                <form method="POST" action="students.php?action=delete_attendance" class="d-inline ms-2 no-print" onsubmit="return confirm('Bu manuel kaydı silmek istediğinizden emin misiniz?');">
                                                    <input type="hidden" name="record_id" value="<?php echo $record['record_id']; ?>">
                                                    <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm p-0" style="width: 20px; height: 20px; line-height: 1; font-size: 0.7rem;" title="Manuel Kaydı Sil">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php elseif ($is_partial): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis">Yarım Kaldı</span>
                                            <small class="text-muted ms-2 no-print">(2. faz tamamlanmamış)</small>
                                             <?php // Yarım kalan kaydı tamamlama butonu eklenebilir ?>
                                             <form method="POST" action="students.php?action=complete_partial" class="d-inline ms-2 no-print">
                                                 <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                 <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                                                 <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Bu kaydı tamamla">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                             </form>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis">Katılmadı</span>
                                             <?php // Manuel ekleme butonu eklenebilir ?>
                                             <button type="button" class="btn btn-outline-success btn-sm ms-2 py-0 px-1 no-print"
                                                     data-bs-toggle="modal" data-bs-target="#addAttendanceModal"
                                                     onclick="document.getElementById('session_id').value='<?php echo $record['session_id']; ?>';" title="Bu oturum için manuel ekle">
                                                 <i class="fas fa-plus"></i>
                                             </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Modallar (İçerik Değişmedi) -->
         <div class="modal fade" id="addAttendanceModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Manuel Yoklama Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="students.php?action=add_attendance">
                        <div class="modal-body">
                            <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                            <div class="mb-3">
                                <label for="session_id" class="form-label">Yoklama Alınacak Oturum</label>
                                <select class="form-select" id="session_id" name="session_id" required>
                                    <option value="">Oturum seçiniz...</option>
                                    <?php foreach ($attendance_records as $record): ?>
                                        <?php // Manuel ekleme için sadece katılmadığı veya yarım kaldığı oturumları listele ?>
                                        <?php if (!$record['record_id'] || !$record['second_phase_completed']): ?>
                                            <option value="<?php echo $record['session_id']; ?>">
                                                <?php echo htmlspecialchars($record['session_name']); ?> -
                                                <?php echo date('d.m.Y H:i', strtotime($record['session_date'] . ' ' . $record['start_time'])); ?>
                                                <?php if ($record['record_id'] && !$record['second_phase_completed']): ?>
                                                    (Yarım kaldı)
                                                <?php endif; ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div id="no-session-alert-placeholder"></div> <!-- Placeholder for alert -->
                            </div>
                            <div class="alert alert-warning small">
                                <i class="fas fa-exclamation-triangle me-1"></i>Bu işlem sistem kayıtlarında manuel olarak işaretlenecektir.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-success" id="addAttendanceSubmitBtn"><i class="fas fa-check me-1"></i>Yoklama Ekle</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal fade" id="completePartialModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-clock me-2"></i>Yarım Kalan Kayıtları Tamamla</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="students.php?action=complete_partial" onsubmit="return confirm('Bu öğrencinin tüm yarım kalan kayıtları tamamlanacak. Emin misiniz?');">
                        <div class="modal-body">
                            <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong><?php echo $partial_count; ?> adet</strong> yarım kalan yoklama kaydı bulundu.
                            </div>

                            <p>Bu öğrencinin aşağıdaki oturumlara ait yarım kalan kayıtları tamamlanacak:</p>

                            <ul class="list-group mb-3" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($attendance_records as $record): ?>
                                    <?php if ($record['record_id'] && !$record['second_phase_completed']): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>
                                                <i class="fas fa-clock text-warning me-2"></i>
                                                <?php echo htmlspecialchars($record['session_name']); ?>
                                                 <small class="text-muted ms-1">(<?php echo date('d.m.Y', strtotime($record['session_date'])); ?>)</small>
                                            </span>
                                            <span class="badge bg-warning text-dark">Yarım Kaldı</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>

                            <div class="alert alert-warning small mb-0">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Bu işlem geri alınamaz ve kayıtlar manuel olarak işaretlenecektir.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-check-double me-1"></i>Tümünü Tamamla
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Ana Liste Görünümü -->
        <h2 class="mb-4"><i class="fa-solid fa-chalkboard-user me-2" style="color: <?php echo $theme_color;?>;"></i>Derslerim</h2>

        <?php if (empty($teacher_courses)): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i>Henüz size atanmış bir ders bulunmamaktadır.</div>
        <?php else: ?>
            <div class="row mb-4">
                 <?php foreach ($teacher_courses as $course): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card course-card h-100 <?php echo $course_id == $course['id'] ? 'active' : ''; ?>"
                             onclick="location.href='students.php?course_id=<?php echo $course['id']; ?>'"
                             style="cursor: pointer; <?php if($course_id == $course['id']) echo 'border-left-color: '.$theme_color.';'; ?>">
                            <div class="card-body">
                                <h5 class="card-title" style="color: <?php echo $theme_color; ?>;"><?php echo htmlspecialchars($course['course_name']); ?></h5>
                                <p class="card-subtitle mb-2 text-muted"><?php echo htmlspecialchars($course['course_code']); ?></p>
                                <div class="d-flex justify-content-between text-muted small mt-3">
                                    <span><i class="fas fa-users me-1"></i><?php echo $course['student_count']; ?> Öğrenci</span>
                                    <span><i class="fas fa-clipboard-list me-1"></i><?php echo $course['session_count']; ?> Oturum</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($course_id > 0 && $selected_course): ?>
                 <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <h3 class="mb-0 me-2"><?php echo htmlspecialchars($selected_course['course_name']); ?> Öğrenci Listesi</h3>
                    <div>
                         <?php // Sadece yarım kalan kayıt varsa butonu göster
                         $has_partial_in_course = false;
                         if(!empty($students_raw)){ // $students_raw'ın boş olup olmadığını kontrol et
                             foreach($students_raw as $s_raw){
                                 // Yarım kalan kaydı olan öğrenci var mı kontrol et (basitleştirilmiş kontrol)
                                 // Daha doğru kontrol için ayrı bir sorgu gerekebilir ama bu çoğu durumu kapsar
                                 if($s_raw['total_sessions'] > 0 && $s_raw['attended_sessions'] < $s_raw['total_sessions']){
                                     // Bu öğrencinin gerçekten yarım kalan kaydı olup olmadığını kontrol etmek daha iyi olurdu,
                                     // ama şimdilik en az 1 devamsızlığı olan varsa butonu gösterelim.
                                     // TODO: Daha kesin bir kontrol için COUNT(ar.id WHERE second_phase_completed = 0) sorgusu eklenebilir.
                                     // Şimdilik, eğer dersin genelinde hiç devamsız yoksa butonu gizleyebiliriz.
                                     $has_any_absence = array_sum(array_column($students_raw, 'absent_sessions')) > 0;
                                     if($has_any_absence){ // Eğer derste hiç devamsız yoksa bu butona gerek yok
                                        $has_partial_in_course = true; // Potansiyel yarım kalan var kabul edelim
                                        break;
                                     }
                                 }
                             }
                         }
                         ?>
                         <?php if ($has_partial_in_course): ?>
                            <form method="POST" action="students.php?action=complete_all_partial" class="d-inline" onsubmit="return confirm('Bu dersteki TÜM öğrencilerin yarım kalan kayıtları tamamlanacak. Bu işlem geri alınamaz. Emin misiniz?');">
                                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-magic me-1"></i>Tüm Yarım Kalanları Düzelt
                                </button>
                            </form>
                         <?php endif; ?>
                          <button onclick="window.print();" class="btn btn-secondary btn-sm ms-2 no-print">
                            <i class="fas fa-print me-1"></i> Listeyi Yazdır
                         </button>
                    </div>
                </div>


                <div class="row mb-4">
                     <div class="col-lg-3 col-md-6 mb-3"><div class="stats-card total"><i class="fas fa-users icon"></i><h3><?php echo $student_stats['total']; ?></h3><p class="mb-0">Toplam Öğrenci</p></div></div>
                     <div class="col-lg-3 col-md-6 mb-3"><div class="stats-card regular"><i class="fas fa-user-check icon"></i><h3><?php echo $student_stats['regular']; ?></h3><p class="mb-0">Düzenli</p></div></div>
                     <div class="col-lg-3 col-md-6 mb-3"><div class="stats-card warning"><i class="fas fa-user-clock icon"></i><h3><?php echo $student_stats['warning']; ?></h3><p class="mb-0">Uyarıda</p></div></div>
                     <div class="col-lg-3 col-md-6 mb-3"><div class="stats-card critical"><i class="fas fa-user-times icon"></i><h3><?php echo $student_stats['critical']; ?></h3><p class="mb-0">Kritik</p></div></div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                         <div class="row gy-3 align-items-center">
                            <div class="col-lg-7 col-md-12">
                                <ul class="nav nav-pills filter-tabs flex-nowrap overflow-auto pb-2">
                                     <li class="nav-item flex-shrink-0"><a href="students.php?course_id=<?php echo $course_id; ?>&status=all" class="nav-link <?php echo $filter_status === 'all' ? 'active' : ''; ?>">Tümü</a></li>
                                     <li class="nav-item flex-shrink-0"><a href="students.php?course_id=<?php echo $course_id; ?>&status=regular" class="nav-link <?php echo $filter_status === 'regular' ? 'active' : ''; ?>">Düzenli</a></li>
                                     <li class="nav-item flex-shrink-0"><a href="students.php?course_id=<?php echo $course_id; ?>&status=warning" class="nav-link <?php echo $filter_status === 'warning' ? 'active' : ''; ?>">Uyarı</a></li>
                                     <li class="nav-item flex-shrink-0"><a href="students.php?course_id=<?php echo $course_id; ?>&status=critical" class="nav-link <?php echo $filter_status === 'critical' ? 'active' : ''; ?>">Kritik</a></li>
                                </ul>
                            </div>
                            <div class="col-lg-5 col-md-12">
                                <form method="GET" action="students.php" class="d-flex">
                                    <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="search" placeholder="Ad, numara veya e-posta..." value="<?php echo htmlspecialchars($search); ?>">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                         <?php if(!empty($search) || $filter_status !== 'all'): ?>
                                             <a href="students.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-secondary" title="Filtreleri Temizle"><i class="fas fa-times"></i></a>
                                         <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($students)): ?>
                    <div class="alert alert-info text-center"><i class="fas fa-info-circle me-2"></i>Bu filtrede gösterilecek öğrenci bulunamadı.</div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($students as $student): ?>
                             <a href="students.php?action=detail&student_id=<?php echo $student['id']; ?>&course_id=<?php echo $course_id; ?>" class="list-group-item list-group-item-action student-list-card mb-2">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($student['profile_image']) && file_exists('../' . $student['profile_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Profil" class="student-avatar me-3">
                                        <?php else: ?>
                                            <div class="student-avatar-placeholder me-3" style="background-color: var(--theme-color-bg); color: var(--theme-color);">
                                                <?php echo mb_substr($student['full_name'], 0, 1); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($student['full_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['student_number']); ?></small>
                                        </div>
                                    </div>
                                    <div class="text-end ms-3 flex-shrink-0">
                                        <div class="fw-bold h5 mb-0 text-<?php echo $student['status'] === 'regular' ? 'success' : ($student['status'] === 'warning' ? 'warning' : 'danger'); ?>"><?php echo $student['attendance_rate']; ?>%</div>
                                        <small class="text-muted"><?php echo $student['attended_sessions']; ?>/<?php echo $student['total_sessions']; ?> Ders</small>
                                    </div>
                                </div>
                                <div class="progress-wrapper mt-2">
                                     <div class="progress" style="height: 6px;" title="Devam Oranı">
                                        <div class="progress-bar bg-<?php echo $student['status'] === 'regular' ? 'success' : ($student['status'] === 'warning' ? 'warning' : 'danger'); ?>" role="progressbar" style="width: <?php echo $student['attendance_rate']; ?>%" aria-valuenow="<?php echo $student['attendance_rate']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <!-- Zorunlu Materyal İlerlemesi -->
                                <div class="material-progress-bar-container mt-2">
                                     <div class="material-progress-text">Zorunlu İçerik Tamamlama: <?php echo $student['completed_required_materials']; ?>/<?php echo $student['total_required_materials']; ?></div>
                                     <div class="progress" style="height: 6px;" title="Zorunlu İçerik Tamamlama Oranı">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $student['material_progress_rate']; ?>%" aria-valuenow="<?php echo $student['material_progress_rate']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($course_id > 0): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>Seçilen ders bulunamadı veya bu derse erişim yetkiniz yok.</div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Modal açıldığında oturum listesini kontrol et (Detay sayfası için)
        const addAttendanceModal = document.getElementById('addAttendanceModal');
        if (addAttendanceModal) {
            addAttendanceModal.addEventListener('show.bs.modal', function() {
                const sessionSelect = document.getElementById('session_id');
                 const alertPlaceholder = document.getElementById('no-session-alert-placeholder');
                 const submitBtn = document.getElementById('addAttendanceSubmitBtn');
                 alertPlaceholder.innerHTML = ''; // Clear previous alerts

                if (sessionSelect && sessionSelect.options.length <= 1) { // Only has the placeholder
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success mt-2 mb-0'; // Added mb-0
                     alertDiv.innerHTML = '<i class="fas fa-check-circle me-1"></i>Bu öğrenci tüm oturumlara katılmış veya yarım kalan kaydı yok. Manuel ekleme yapacak devamsızlık bulunmuyor.';
                    alertPlaceholder.appendChild(alertDiv);
                     // Disable submit button
                    if(submitBtn) submitBtn.disabled = true;
                } else {
                     if(submitBtn) submitBtn.disabled = false;
                }
            });
        }

         // Öğrenci Detay Sayfasında Materyal İlerlemesini Yükle
         <?php if ($action === 'detail' && $student_id > 0 && $course_id > 0): ?>
         const progressContainer = document.getElementById('material-progress-container');
         if(progressContainer){
             fetch(`students.php?api=get_material_progress&student_id=<?php echo $student_id; ?>&course_id=<?php echo $course_id; ?>`)
                 .then(response => response.json())
                 .then(data => {
                     if (data.status === 'success') {
                         const percentage = data.progress_percentage;
                         const total = data.total_required;
                         const completed = data.completed_required;

                         if (total === 0) {
                              progressContainer.innerHTML = `
                                 <div class="text-muted small">Bu ders için zorunlu materyal bulunmamaktadır.</div>
                                 <div class="progress" style="height: 10px;" title="Zorunlu İçerik Tamamlama Oranı">
                                     <div class="progress-bar bg-success" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                                 </div>`;
                         } else {
                             progressContainer.innerHTML = `
                                 <div class="material-progress-text">Tamamlanan: ${completed} / ${total}</div>
                                 <div class="progress" style="height: 15px;" title="Zorunlu İçerik Tamamlama Oranı: ${percentage}%">
                                     <div class="progress-bar bg-info progress-bar-striped ${percentage == 100 ? '' : 'progress-bar-animated'}" role="progressbar" style="width: ${percentage}%" aria-valuenow="${percentage}" aria-valuemin="0" aria-valuemax="100">
                                         ${percentage}%
                                     </div>
                                 </div>`;
                         }
                     } else {
                         progressContainer.innerHTML = `<div class="text-danger small">${data.message || 'İlerleme durumu alınamadı.'}</div>`;
                     }
                 })
                 .catch(error => {
                     console.error('Materyal ilerlemesi alınırken hata:', error);
                     progressContainer.innerHTML = `<div class="text-danger small">İlerleme durumu yüklenirken bir hata oluştu.</div>`;
                 });
         }
         <?php endif; ?>

    });
</script>

<?php include '../includes/components/shared_footer.php'; ?>
