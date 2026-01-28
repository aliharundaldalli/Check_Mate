<?php
// teacher_dashboard.php - Öğretmen Kontrol Paneli

// Error reporting ve session/timezone ayarları
error_reporting(E_ALL);
ini_set('display_errors', 0); // Üretimde 0 olmalı
ini_set('log_errors', 1);
ini_set('error_log', '../../logs/php_errors.log'); // Hata log dosyasının yolu
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
date_default_timezone_set('Europe/Istanbul'); // Zaman dilimini ayarla

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
    error_log('Teacher Dashboard Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// === ARKA PLANDA CRON JOB ÇALIŞTIR ===
// (students.php'den kopyalandı ve log mesajı güncellendi)
function runBackgroundCron($db) {
    try {
        // Son cron çalışma zamanını kontrol et (gereksiz yere çok sık çalışmasın)
        $last_cron_file = '../logs/last_cron_run.txt';
        $current_time = time();

        // Eğer dosya varsa son çalışma zamanını oku
        if (file_exists($last_cron_file)) {
            $last_run = (int)@file_get_contents($last_cron_file); // @ suppress errors if file is empty/unreadable
            // 2 dakikadan az süre geçmişse cron'u çalıştırma
            if (($current_time - $last_run) < 120) {
                return false;
            }
        }

        // MySQL zaman dilimini ayarla (Oturum bazında ayarlamak daha güvenli olabilir)
        // $db->exec("SET time_zone = '+03:00'"); // Gerekliyse etkinleştirilebilir

        $cron_log = []; // İşlem logları

        // --- GÖREV 1: SÜRESİ DOLAN OTURUMLARI GÜNCELLE ---
        $query_expired = "UPDATE attendance_sessions
                          SET
                              status = 'expired',
                              is_active = 1, /* Keep is_active=1 for visibility? Or set to 0? Decide based on logic */
                              expired_at = NOW()
                          WHERE
                              status IN ('active', 'inactive')
                              AND closed_at IS NULL
                              AND NOW() > DATE_ADD(CONCAT(session_date, ' ', start_time), INTERVAL duration_minutes MINUTE)";

        $stmt_expired = $db->prepare($query_expired);
        $stmt_expired->execute();
        $expired_count = $stmt_expired->rowCount();

        if ($expired_count > 0) {
            $cron_log[] = "Süresi dolduğu için $expired_count oturum 'expired' olarak güncellendi.";
        }

        // --- GÖREV 2: GELECEKTEKİ OTURUMLARI BAŞLAMA ZAMANI GELİNCE AKTİF ('inactive') HALE GETİR ---
        $query_future = "UPDATE attendance_sessions
                         SET status = 'inactive'
                         WHERE status = 'future'
                           AND closed_at IS NULL
                           AND NOW() >= CONCAT(session_date, ' ', start_time)";

        $stmt_future = $db->prepare($query_future);
        $stmt_future->execute();
        $future_count = $stmt_future->rowCount();

        if ($future_count > 0) {
            $cron_log[] = "$future_count adet 'future' oturum, başlama zamanı geldiği için 'inactive' olarak ayarlandı.";
        }

        // --- GÖREV 3: ESKİ İKİNCİ AŞAMA ANAHTARLARINI TEMİZLE ---
         // (Bu anahtarların süresi dolduktan 1 saat sonra silinir)
        $query_keys = "DELETE FROM second_phase_keys
                       WHERE valid_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $stmt_keys = $db->prepare($query_keys);
        $stmt_keys->execute();
        $keys_count = $stmt_keys->rowCount();

        if ($keys_count > 0) {
            $cron_log[] = "$keys_count adet eski ikinci aşama anahtarı temizlendi.";
        }

        // Son çalışma zamanını kaydet
        $log_dir = dirname($last_cron_file);
        if (!file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true); // @ suppress errors if dir exists
        }
        @file_put_contents($last_cron_file, $current_time); // @ suppress errors

        // Logları kaydet
        if (!empty($cron_log)) {
            $log_entry = date('Y-m-d H:i:s') . " - Background Cron (Teacher Dashboard):\n";
            foreach ($cron_log as $log) {
                $log_entry .= " - " . $log . "\n";
            }
            // Ensure logs directory exists and is writable
            if (is_writable($log_dir)) {
                 @file_put_contents($log_dir.'/background_cron.log', $log_entry, FILE_APPEND | LOCK_EX); // @ suppress errors
            } else {
                 error_log("Background Cron: Log dizini yazılabilir değil: " . $log_dir);
            }
        }

        return count($cron_log) > 0;

    } catch (Exception $e) {
        error_log("Background Cron Error (Teacher Dashboard): " . $e->getMessage());
        return false;
    }
}


// Arka planda cron'u çalıştır
$cron_ran = runBackgroundCron($db);

// Site ayarlarını yükle
try {
    $query = "SELECT setting_key, setting_value FROM site_settings";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception('Settings query preparation failed: ' . implode(", ", $db->errorInfo()));
    }
    if(!$stmt->execute()){
         throw new Exception('Settings query execution failed: ' . implode(", ", $stmt->errorInfo()));
    }
    $site_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log('Site settings error: ' . $e->getMessage());
    $site_settings = []; // Set defaults even on error
}

// Varsayılan değerler
$site_name = $site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi';
$site_logo = $site_settings['site_logo'] ?? ''; // Provide a default logo path if needed
$site_favicon = $site_settings['site_favicon'] ?? ''; // Provide a default favicon path if needed
$theme_color = $site_settings['theme_color'] ?? '#3498db'; // Default theme color
$max_absence_percentage = (int)($site_settings['max_absence_percentage'] ?? 35);


// Öğretmenin derslerini al
try {
    $query = "SELECT c.*,
             (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as student_count,
             (SELECT COUNT(*) FROM attendance_sessions asess WHERE asess.course_id = c.id AND asess.status IN ('active', 'inactive', 'closed', 'expired')) as session_count, /* inactive included */
             (SELECT COUNT(*) FROM attendance_sessions asess WHERE asess.course_id = c.id AND asess.status = 'active' AND DATE(asess.session_date) = CURDATE()) as today_active_sessions /* Renamed for clarity */
             FROM courses c
             WHERE c.teacher_id = :teacher_id AND c.is_active = 1
             ORDER BY c.course_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $teacher_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Teacher courses error: ' . $e->getMessage());
    $teacher_courses = [];
     show_message('Dersleriniz yüklenirken bir hata oluştu.', 'danger'); // Show user message
}

// İstatistikler
$total_courses = 0;
$total_students = 0;
$total_sessions = 0;
$today_attendances = 0; // Bugün katılan *benzersiz* öğrenci sayısı
try {
    // Toplam ders sayısı
    $total_courses = count($teacher_courses);

    // Toplam *benzersiz* öğrenci sayısı (tüm derslerdeki)
    $query = "SELECT COUNT(DISTINCT ce.student_id) as count
              FROM course_enrollments ce
              JOIN courses c ON ce.course_id = c.id
              WHERE c.teacher_id = :teacher_id AND ce.is_active = 1 AND c.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_students_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $total_students_result ? (int)$total_students_result['count'] : 0;


    // Toplam yoklama oturumu sayısı (tüm durumlardaki)
    $query = "SELECT COUNT(asess.id) as count
              FROM attendance_sessions asess
              JOIN courses c ON asess.course_id = c.id
              WHERE c.teacher_id = :teacher_id AND c.is_active = 1 /* status filter removed */";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_sessions_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_sessions = $total_sessions_result ? (int)$total_sessions_result['count'] : 0;


    // Bugün katılan *benzersiz* öğrenci sayısı (tüm derslerdeki)
    $query = "SELECT COUNT(DISTINCT ar.student_id) as count
              FROM attendance_records ar
              JOIN attendance_sessions asess ON ar.session_id = asess.id
              JOIN courses c ON asess.course_id = c.id
              WHERE c.teacher_id = :teacher_id AND ar.second_phase_completed = 1 
              AND DATE(asess.session_date) = CURDATE()
              AND c.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $today_attendances_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $today_attendances = $today_attendances_result ? (int)$today_attendances_result['count'] : 0;

    // İstatistik: Puanlanmayı Bekleyen Ödev Sayısı
    $query = "SELECT COUNT(*) FROM assignment_submissions s
              JOIN assignments a ON s.assignment_id = a.id
              JOIN courses c ON a.course_id = c.id
              WHERE a.teacher_id = :teacher_id AND s.score IS NULL AND c.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $pending_grading_count = $stmt->fetchColumn();


    // İstatistik: Okunmamış Mesajlar (Özel Mesajlar)
    $query = "SELECT COUNT(*) FROM private_messages 
              WHERE recipient_id = :teacher_id 
              AND is_read = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $unread_messages_count = $stmt->fetchColumn();

    // Aktif Ödevleri Getir (Dashboard Listesi İçin)
    $active_assignments = [];
    $query = "SELECT a.*, c.course_name, c.course_code,
              (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id) as submission_count,
              (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = a.course_id AND ce.is_active = 1) as total_students
              FROM assignments a
              JOIN courses c ON a.course_id = c.id
              WHERE a.teacher_id = :teacher_id AND a.is_active = 1 AND c.is_active = 1
              ORDER BY a.due_date ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $active_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (Exception $e) {
    error_log('Teacher stats error: ' . $e->getMessage());
     show_message('İstatistikler yüklenirken bir hata oluştu.', 'danger');
    // Keep counts at 0
}

// Bugünkü aktif yoklama oturumları
try {
    $query = "SELECT asess.*, c.course_name, c.course_code,
             (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.second_phase_completed = 1) as attendance_count,
             (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as total_students
             FROM attendance_sessions asess
             JOIN courses c ON asess.course_id = c.id
             WHERE c.teacher_id = :teacher_id AND asess.status = 'active'
             AND c.is_active = 1
             AND DATE(asess.session_date) = CURDATE() /* Sadece bugünküler */
             ORDER BY asess.start_time ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $active_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Active sessions error: ' . $e->getMessage());
    $active_sessions = [];
     show_message('Aktif oturumlar yüklenirken bir hata oluştu.', 'danger');
}

// Sınav İstatistikleri ve Aktif Sınavlar
$total_quizzes = 0;
$active_quizzes = [];
try {
    // Toplam Sınav Sayısı
    $query = "SELECT COUNT(q.id) as count
              FROM quizzes q
              JOIN courses c ON q.course_id = c.id
              WHERE c.teacher_id = :teacher_id AND q.is_active = 1 AND c.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_quizzes = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Aktif Sınavları Getir
    $query = "SELECT q.*, c.course_name, c.course_code,
              (SELECT COUNT(*) FROM quiz_submissions qs WHERE qs.quiz_id = q.id) as submission_count,
              (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as total_students
              FROM quizzes q
              JOIN courses c ON q.course_id = c.id
              WHERE c.teacher_id = :teacher_id
              AND q.is_active = 1
              AND c.is_active = 1
              AND (q.available_from IS NULL OR q.available_from <= NOW())
              AND (q.available_until IS NULL OR q.available_until >= NOW())
              ORDER BY q.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $active_quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log('Quiz stats error: ' . $e->getMessage());
}

// Son yoklama geçmişi (Kapatılmış veya süresi dolmuş olanlar)
try {
    $query = "SELECT asess.*, c.course_name, c.course_code,
             (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.second_phase_completed = 1) as attendance_count,
             (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as total_students
             FROM attendance_sessions asess
             JOIN courses c ON asess.course_id = c.id
             WHERE c.teacher_id = :teacher_id AND asess.status IN ('closed', 'expired') /* Only closed/expired */
             AND c.is_active = 1
             ORDER BY CONCAT(asess.session_date, ' ', asess.start_time) DESC /* Order by actual datetime */
             LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Recent sessions error: ' . $e->getMessage());
    $recent_sessions = [];
     show_message('Son oturumlar yüklenirken bir hata oluştu.', 'danger');
}

// En çok devamsızlık yapan öğrenciler (Bu sorgu karmaşık ve potansiyel olarak yavaş olabilir)
// Devamsızlık oranı hesaplaması students.php ile aynı mantıkta olmalı
$absent_students = []; // Initialize
try {
     // Önce tüm derslerdeki tüm oturumları ve katılımları alıp PHP'de işlemek daha performanslı olabilir
     // Ama şimdilik SQL ile deneyelim, LIMIT ekleyerek yavaşlığı azaltalım
    $query_absent = "
        SELECT
            student_id,
            student_full_name,
            student_number,
            course_id,
            course_name,
            course_code,
            total_course_sessions,
            attended_course_sessions,
            (100 - (attended_course_sessions / total_course_sessions) * 100) as absence_rate
        FROM (
            SELECT
                u.id as student_id,
                u.full_name as student_full_name,
                u.student_number,
                c.id as course_id,
                c.course_name,
                c.course_code,
                (SELECT COUNT(*) FROM attendance_sessions s_inner
                 WHERE s_inner.course_id = c.id AND s_inner.status IN ('closed', 'expired')) as total_course_sessions,
                COUNT(ar.id) as attended_course_sessions
            FROM users u
            JOIN course_enrollments ce ON u.id = ce.student_id
            JOIN courses c ON ce.course_id = c.id
            LEFT JOIN attendance_sessions s ON c.id = s.course_id AND s.status IN ('closed', 'expired')
            LEFT JOIN attendance_records ar ON s.id = ar.session_id AND u.id = ar.student_id AND ar.second_phase_completed = 1
            WHERE c.teacher_id = :teacher_id AND u.user_type = 'student' AND ce.is_active = 1 AND c.is_active = 1
            GROUP BY u.id, c.id
        ) as student_course_attendance
        WHERE total_course_sessions > 0 /* Sadece oturum yapılmış dersler */
          AND (100 - (attended_course_sessions / total_course_sessions) * 100) > (:warning_threshold) /* Uyarı veya Kritik seviye */
        ORDER BY absence_rate DESC, total_course_sessions DESC
        LIMIT 6"; // Limiti artırabilir veya kaldırabilirsiniz

    $stmt_absent = $db->prepare($query_absent);
    $stmt_absent->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    // Bind warning threshold
    $warning_threshold = $max_absence_percentage * 0.7; // Calculate warning threshold
    $stmt_absent->bindParam(':warning_threshold', $warning_threshold);
    $stmt_absent->execute();
    $absent_students = $stmt_absent->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log('Absent students error: ' . $e->getMessage());
    $absent_students = []; // Initialize to empty array on error
    show_message('Devamsızlık yapan öğrenciler listesi alınırken hata oluştu.', 'warning');
}


$page_title = "Öğretmen Kontrol Paneli - ". htmlspecialchars($site_name);

// Include header
include '../includes/components/teacher_header.php'; // Ensure this path is correct
?>
<style>
:root {
    --theme-color: <?php echo $theme_color; ?>;
    --theme-color-rgb: <?php list($r, $g, $b) = sscanf($theme_color, "#%02x%02x%02x"); echo "$r, $g, $b"; ?>;
    --theme-color-light: rgba(var(--theme-color-rgb), 0.08);
}

/* === Temel Butonlar === */
.btn-primary {
    background-color: var(--theme-color);
    border-color: var(--theme-color);
}
.btn-primary:hover {
    opacity: 0.9;
}

/* === Stat Kartları (öğrenci paneli stili) === */
.stat-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    border: none;
    border-radius: 0.75rem;
    background-color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}
.stat-card h3 {
    font-size: 2rem;
    font-weight: 700;
    color: #212529;
}
.stat-card p {
    color: #6c757d;
    margin-bottom: 0;
}
.stat-card i {
    opacity: 0.75;
}

/* === Genel Kartlar === */
.card {
    border-radius: 0.75rem;
    border: none;
}
.card-header {
    background: transparent;
    border-bottom: none;
}
.card-title {
    font-weight: 600;
    font-size: 1rem;
}

/* === Attendance Kartları === */
.attendance-card {
    border: 1px solid #e9ecef;
    background: #fff;
    transition: box-shadow 0.2s ease;
}
.attendance-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.08);
}
.attendance-card h6 {
    font-weight: 600;
    color: #212529;
}
.attendance-card .text-muted {
    font-size: 0.85rem;
}

/* === Devamsızlık Kartları === */
.absent-student-card .badge {
    font-size: 1em;
    padding: 0.4rem 0.6rem;
}

/* === Alert & Badge === */
.alert-danger {
    background-color: rgba(220,53,69,0.1);
    border: 1px solid rgba(220,53,69,0.2);
    color: #dc3545;
}
.badge {
    font-weight: 500;
    padding: 0.35rem 0.5rem;
}
</style>

<div class="container-fluid py-4">

    <?php display_message(); ?>

    <!-- Debug: Cron Status -->
    <?php if ($cron_ran): ?>
    <div class="alert alert-secondary alert-dismissible fade show small" role="alert">
        <i class="fas fa-cog fa-spin me-1"></i>
        Arka plan görevleri çalıştırıldı.
        <button type="button" class="btn-close py-2 px-3" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-wrap gap-2 gap-lg-3">
                <a href="attendance.php?action=create" class="btn btn-primary shadow-sm">
                    <i class="fas fa-plus me-1"></i>Yeni Yoklama Başlat
                </a>
                <a href="messages.php?action=compose" class="btn btn-success shadow-sm">
                    <i class="fas fa-paper-plane me-1"></i>Duyuru Gönder
                </a>
                <a href="students.php" class="btn btn-info text-white shadow-sm">
                    <i class="fas fa-users me-1"></i>Öğrenci Listesi
                </a>
                <a href="reports.php" class="btn btn-warning text-dark shadow-sm">
                    <i class="fas fa-chart-line me-1"></i>Devamsızlık Raporu
                </a>
                 <a href="course_materials.php" class="btn btn-secondary shadow-sm"> <!-- Link to materials overview -->
                     <i class="fas fa-book-open me-1"></i>Ders İçerikleri
                 </a>
            </div>
        </div>
    </div>
<!-- Statistics Cards (Modernized like Student Dashboard) -->
<!-- Statistics Cards -->
    <!-- Statistics Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-4">
        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-primary" style="color: white;"><?php echo $total_courses; ?></h3>
                            <p class="mb-0" style="color: white;">Verdiğim Ders</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-book fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-info" style="color: white;"><?php echo $total_students; ?></h3>
                            <p class="mb-0" style="color: white;">Toplam Öğrencim</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-graduate fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-success" style="color: white;"><?php echo $total_sessions; ?></h3>
                            <p class="mb-0" style="color: white;">Toplam Oturum</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-check fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-purple" style="color: white;"><?php echo $total_quizzes; ?></h3> <!-- Purple for Quizzes -->
                            <p class="mb-0" style="color: white;">Toplam Sınav</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-file-alt fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-warning" style="color: white;"><?php echo $today_attendances; ?></h3>
                            <p class="mb-0" style="color: white;">Bugün Katılan</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-hand-paper fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-danger" style="color: white;"><?php echo $pending_grading_count; ?></h3>
                            <p class="mb-0" style="color: white;">Puanlanacak</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tasks fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100" style="color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-info" style="color: white;"><?php echo $unread_messages_count; ?></h3>
                            <p class="mb-0" style="color: white;">Okunmamış Mesaj</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-envelope fa-3x" style="color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Assignments Section -->
    <?php if (!empty($active_assignments)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tasks me-2"></i>Aktif Ödevler
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Ödev Başlığı</th>
                                        <th>Ders</th>
                                        <th>Son Teslim</th>
                                        <th>Gönderimler</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_assignments as $assign): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($assign['title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($assign['description'], 0, 50, "...")); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($assign['course_name']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($assign['course_code']); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                                $due_date = strtotime($assign['due_date']);
                                                $is_overdue = time() > $due_date;
                                                echo '<span class="' . ($is_overdue ? 'text-danger fw-bold' : '') . '">';
                                                echo date('d.m.Y H:i', $due_date);
                                                echo '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px; width: 100px;">
                                                    <?php 
                                                        $percentage = $assign['total_students'] > 0 ? ($assign['submission_count'] / $assign['total_students']) * 100 : 0;
                                                    ?>
                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                                <span class="ms-2 small"><?php echo $assign['submission_count']; ?>/<?php echo $assign['total_students']; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="assignments.php?action=submissions&id=<?php echo $assign['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye me-1"></i>İncele
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active Quizzes Section -->
    <?php if (!empty($active_quizzes)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-pen-nib me-2"></i>Aktif Sınavlar
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($active_quizzes as $quiz): ?>
                                <?php
                                $sub_rate = $quiz['total_students'] > 0 ? round(($quiz['submission_count'] / $quiz['total_students']) * 100, 1) : 0;
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card h-100 border-primary shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title text-primary mb-1">
                                                        <?php echo htmlspecialchars($quiz['title']); ?>
                                                    </h6>
                                                    <p class="card-text mb-2 small text-muted">
                                                        <?php echo htmlspecialchars($quiz['course_name']); ?>
                                                    </p>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3">
                                                            <small class="text-muted">Katılım:</small>
                                                            <strong><?php echo $quiz['submission_count']; ?>/<?php echo $quiz['total_students']; ?></strong>
                                                        </div>
                                                        <div>
                                                            <span class="badge bg-info">
                                                                %<?php echo $sub_rate; ?> Tamamlandı
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                     <?php if ($quiz['available_until']): ?>
                                                        <small class="text-danger fw-bold d-block mb-2">
                                                            <i class="fas fa-clock me-1"></i>Son: <?php echo date('d.m H:i', strtotime($quiz['available_until'])); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <small class="text-success fw-bold d-block mb-2">
                                                            <i class="fas fa-infinity me-1"></i>Süresiz
                                                        </small>
                                                    <?php endif; ?>
                                                    <a href="view_submission.php?quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        Sonuçlar
                                                    </a>
                                                    <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-light text-muted">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>



    <!-- Active Sessions Alert -->
    <?php if (!empty($active_sessions)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                     <i class="fas fa-broadcast-tower fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1">Aktif Yoklama Oturumları!</h5>
                        <p class="mb-0">Şu anda devam eden <?php echo count($active_sessions); ?> adet yoklama oturumu bulunmaktadır. Yönetmek için aşağıdaki listeden seçebilirsiniz.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Today's Active Sessions (If any) -->
    <?php if (!empty($active_sessions)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-day me-2"></i>Bugünkü Aktif Yoklama Oturumları
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($active_sessions as $session): ?>
                                <?php
                                $attendance_rate = $session['total_students'] > 0 ? round(($session['attendance_count'] / $session['total_students']) * 100, 1) : 0;
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card attendance-card h-100 border-danger">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title text-danger mb-1">
                                                        <?php echo htmlspecialchars($session['course_name']); ?>
                                                         <small class="text-muted">(<?php echo htmlspecialchars($session['course_code']); ?>)</small>
                                                    </h6>
                                                    <p class="card-text mb-2">
                                                        <strong>Oturum:</strong> <?php echo htmlspecialchars($session['session_name']); ?><br>
                                                        <strong>Başlangıç:</strong> <?php echo date('H:i', strtotime($session['start_time'])); ?> |
                                                        <strong>Süre:</strong> <?php echo $session['duration_minutes']; ?> dk
                                                    </p>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3">
                                                            <small class="text-muted">Katılım:</small>
                                                            <strong><?php echo $session['attendance_count']; ?>/<?php echo $session['total_students']; ?></strong>
                                                        </div>
                                                        <div>
                                                            <span class="badge bg-<?php echo $attendance_rate >= 70 ? 'success' : ($attendance_rate >= 50 ? 'warning' : 'danger'); ?>">
                                                                %<?php echo $attendance_rate; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="text-center ms-2 flex-shrink-0">
                                                     <i class="fas fa-broadcast-tower fa-2x text-danger mb-1"></i>
                                                     <small class="d-block text-danger fw-bold">Aktif</small>
                                                </div>
                                            </div>
                                            <div class="mt-3 text-end">
                                                <a href="attendance.php?action=manage&id=<?php echo $session['id']; ?>" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-cog me-1"></i>Yönet
                                                </a>
                                                <a href="attendance.php?action=view&id=<?php echo $session['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-eye me-1"></i>Detay
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <div class="row">
        <!-- My Courses -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-book me-2"></i>Verdiğim Dersler
                    </h5>
                </div>
                <div class="card-body" style="max-height: 450px; overflow-y: auto;">
                    <?php if (empty($teacher_courses)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-book fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz ders atanmamış.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($teacher_courses as $course): ?>
                            <div class="card attendance-card mb-3">
                                <div class="card-body p-3">
                                    <h6 class="card-title mb-1"><?php echo htmlspecialchars($course['course_name']); ?></h6>
                                    <p class="card-text text-muted mb-2">
                                        <small>
                                            <i class="fas fa-code me-1"></i><?php echo htmlspecialchars($course['course_code']); ?> |
                                            <i class="fas fa-calendar ms-2 me-1"></i><?php echo htmlspecialchars($course['semester'] . ' - ' . $course['academic_year']); ?>
                                        </small>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="text-center">
                                            <div class="text-primary fw-bold"><?php echo $course['student_count']; ?></div>
                                            <small class="text-muted">Öğrenci</small>
                                        </div>
                                         <div class="text-center">
                                            <div class="text-success fw-bold"><?php echo $course['session_count']; ?></div>
                                            <small class="text-muted">Oturum</small>
                                        </div>
                                         <div class="text-center">
                                            <div class="text-danger fw-bold"><?php echo $course['today_active_sessions']; ?></div>
                                            <small class="text-muted">Bugün Aktif</small>
                                        </div>
                                         <div class="text-end">
                                             <a href="students.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-primary" title="Öğrenci Listesi">
                                                 <i class="fas fa-users"></i>
                                             </a>
                                             <a href="course_materials.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-secondary text-white ms-1" title="Ders İçerikleri">
                                                 <i class="fas fa-book-open"></i>
                                             </a>
                                         </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history me-2"></i>Son Yoklama Geçmişi
                    </h5>
                </div>
                <div class="card-body" style="max-height: 450px; overflow-y: auto;">
                    <?php if (empty($recent_sessions)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz tamamlanmış yoklama geçmişi yok.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_sessions as $session): ?>
                            <?php
                            $attendance_rate = $session['total_students'] > 0 ? round(($session['attendance_count'] / $session['total_students']) * 100, 1) : 0;
                            $rate_class = $attendance_rate >= 85 ? 'success' :
                                          ($attendance_rate >= 70 ? 'warning' : 'danger');
                            ?>
                            <div class="d-flex justify-content-between align-items-center mb-3 p-3 border rounded attendance-card">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($session['course_name']); ?></h6>
                                    <small class="text-muted d-block">
                                        <?php echo htmlspecialchars($session['session_name']); ?> |
                                        <?php echo date('d.m.Y H:i', strtotime($session['session_date'] . ' ' . $session['start_time'])); ?>
                                    </small>
                                    <small class="text-muted">
                                        <?php echo $session['attendance_count']; ?>/<?php echo $session['total_students']; ?> öğrenci
                                    </small>
                                </div>
                                <div class="text-center ms-2">
                                     <span class="badge bg-<?php echo $rate_class; ?> fs-6 mb-1 d-block">
                                        <?php echo $attendance_rate; ?>%
                                    </span>
                                     <a href="attendance.php?action=view&id=<?php echo $session['id']; ?>" class="btn btn-sm btn-outline-info py-0 px-1" title="Detayları Gör">
                                         <i class="fas fa-eye"></i>
                                     </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Problem Students Alert -->
    <?php if (!empty($absent_students)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>Devamsızlık Uyarısı Gerektiren Öğrenciler
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-3 small text-muted">Aşağıdaki listede, en az 3 oturuma girilen derslerde devam oranı %<?php echo (100-($max_absence_percentage*0.7)); ?>'in altında olan öğrenciler gösterilmektedir (En düşük oranlılar öncelikli).</p>
                        <div class="row">
                            <?php foreach ($absent_students as $student): ?>
                                <?php
                                 // Recalculate absence_rate for status color determination
                                 $attended = (int)($student['attended_sessions'] ?? 0);
                                 $total = (int)($student['total_course_sessions'] ?? 0);
                                 $absence_rate = $total > 0 ? (100 - round(($attended / $total) * 100, 1)) : 0;
                                 $status_color = $absence_rate > $max_absence_percentage ? 'danger' : 'warning';
                                ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card attendance-card absent-student-card h-100 border-<?php echo $status_color; ?>">
                                        <div class="card-body p-3">
                                            <h6 class="card-title mb-1"><?php echo htmlspecialchars($student['student_full_name']); ?></h6>
                                            <p class="card-text mb-2">
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($student['student_number']); ?><br>
                                                    <?php echo htmlspecialchars($student['course_name']); ?> (<?php echo htmlspecialchars($student['course_code']); ?>)
                                                </small>
                                            </p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="badge bg-<?php echo $status_color; ?>">
                                                    Devam: %<?php echo round(100 - $student['absence_rate'], 1); // Display attendance rate ?>
                                                </span>
                                                <small class="text-muted">
                                                    <?php echo $attended; ?>/<?php echo $total; ?> Oturum
                                                </small>
                                            </div>
                                             <div class="text-end mt-2">
                                                 <a href="students.php?action=detail&student_id=<?php echo $student['student_id'];?>&course_id=<?php echo $student['course_id']; ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Öğrenci Detayları">
                                                     <i class="fas fa-user"></i> Detay
                                                 </a>
                                             </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                         <?php // Tüm devamsızları göster butonu? Belki students.php'ye link vermek yeterli? ?>
                         <div class="text-center mt-2">
                             <a href="students.php?status=warning" class="btn btn-outline-warning btn-sm">
                                 <i class="fas fa-list me-1"></i>Tüm Uyarıdaki Öğrencileri Gör
                             </a>
                             <a href="students.php?status=critical" class="btn btn-outline-danger btn-sm ms-2">
                                 <i class="fas fa-list me-1"></i>Tüm Kritik Öğrencileri Gör
                             </a>
                         </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
// Son tamamlanan/kapanan oturumları getir (Son 5)
// 'closed' veya 'expired' olanları getir. 'active' veya 'future' olanları getirme.
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    <?php if (!empty($active_sessions)): ?>
    let refreshInterval = setInterval(function() {
        console.log("Refreshing page for active sessions...");
        // Sayfayı yenile - arka planda cron da çalışacak
        location.reload();
    }, 120000); // 2 dakika

     // Clear interval if no active sessions are found after reload (optional)
     // Consider if this check is necessary or if reload handles it
     window.addEventListener('load', () => {
         const activeSessionCards = document.querySelectorAll('.card.border-danger'); // Check if active cards still exist
         if(activeSessionCards.length === 0) {
              console.log("No active sessions found after load, clearing refresh interval.");
             clearInterval(refreshInterval);
         }
     });

    <?php endif; ?>

    // Stat kartlarına tıklama animasyonu
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', function() {
            // Sadece görsel efekt, bir yere yönlendirme yapmıyor
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });
    });

    // Alert otomatik kapanma (Sadece success ve info mesajları için)
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible.alert-success, .alert-dismissible.alert-info, .alert-dismissible.alert-secondary'); // Added secondary for cron
        alerts.forEach(alert => {
             try {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            } catch (e) {
                console.warn("Could not close alert automatically.", e);
            }
        });
    }, 5000); // 5 saniye
</script>

<?php include '../includes/components/shared_footer.php'; // Ensure this path is correct ?>
