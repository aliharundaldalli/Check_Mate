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

    // Öğrenci yetkisi kontrolü
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

    $student_id = $_SESSION['user_id'];
} catch (Exception $e) {
    error_log('Student Dashboard Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// === ARKA PLANDA CRON JOB ÇALIŞTIR ===
function runBackgroundCron($db) {
    try {
        // Son cron çalışma zamanını kontrol et (gereksiz yere çok sık çalışmasın)
        $last_cron_file = '../logs/last_cron_run.txt';
        $current_time = time();

        // Eğer dosya varsa son çalışma zamanını oku
        if (file_exists($last_cron_file)) {
            $last_run = (int)file_get_contents($last_cron_file);
            // 2 dakikadan az süre geçmişse cron'u çalıştırma
            if (($current_time - $last_run) < 120) {
                return false;
            }
        }

        // MySQL zaman dilimini ayarla
        $db->exec("SET time_zone = '+03:00'");

        $cron_log = []; // İşlem logları

        // --- GÖREV 1: SÜRESİ DOLAN OTURUMLARI GÜNCELLE ---
        $query_expired = "UPDATE attendance_sessions
                          SET
                              status = 'expired',
                              is_active = 1,
                              expired_at = NOW()
                          WHERE
                              status IN ('active', 'inactive')
                              AND closed_at IS NULL
                              AND DATE_ADD(CONCAT(session_date, ' ', start_time), INTERVAL duration_minutes MINUTE) < NOW()";

        $stmt_expired = $db->prepare($query_expired);
        $stmt_expired->execute();
        $expired_count = $stmt_expired->rowCount();

        if ($expired_count > 0) {
            $cron_log[] = "Süresi dolduğu için $expired_count oturum 'expired' olarak güncellendi.";
        }

        // --- GÖREV 2: GELECEKTEKİ OTURUMLARI AKTİF HALE GETİR ---
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
            mkdir($log_dir, 0755, true);
        }
        file_put_contents($last_cron_file, $current_time);

        // Logları kaydet
        if (!empty($cron_log)) {
            $log_entry = date('Y-m-d H:i:s') . " - Background Cron (Student Dashboard):\n";
            foreach ($cron_log as $log) {
                $log_entry .= " - " . $log . "\n";
            }
            file_put_contents('../logs/background_cron.log', $log_entry, FILE_APPEND | LOCK_EX);
        }

        return count($cron_log) > 0;

    } catch (Exception $e) {
        error_log("Background Cron Error: " . $e->getMessage());
        return false;
    }
}

// Arka planda cron'u çalıştır
$cron_ran = runBackgroundCron($db);

// Öğrencinin kayıtlı olduğu dersleri al
$query = "SELECT c.*, u.full_name as teacher_name
          FROM courses c
          JOIN course_enrollments ce ON c.id = ce.course_id
          LEFT JOIN users u ON c.teacher_id = u.id
          WHERE ce.student_id = :student_id AND ce.is_active = 1 AND c.is_active = 1
          ORDER BY c.course_name";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$course_ids = array_column($courses, 'id'); // Ders ID'lerini al

// İstatistikler
$stats = [];
$stats['total_courses'] = count($courses);

// Toplam katıldığı yoklama sayısı
$query = "SELECT COUNT(*) as count FROM attendance_records ar
          JOIN attendance_sessions asess ON ar.session_id = asess.id
          JOIN courses c ON asess.course_id = c.id
          WHERE ar.student_id = :student_id AND ar.second_phase_completed = 1 AND c.is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$stats['attended_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

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
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';

// Bugün katıldığı yoklama sayısı
$query = "SELECT COUNT(*) as count FROM attendance_records ar
          JOIN attendance_sessions asess ON ar.session_id = asess.id
          JOIN courses c ON asess.course_id = c.id
          WHERE ar.student_id = :student_id AND ar.second_phase_completed = 1
          AND DATE(asess.session_date) = CURDATE()
          AND c.is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$stats['today_attended'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// İstatistikler: Bekleyen Ödev Sayısı (Süresi dolmamış ve gönderim yapılmamış)
$query = "SELECT COUNT(*) FROM assignments a
          JOIN course_enrollments ce ON a.course_id = ce.course_id
          LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = :student_id
          WHERE ce.student_id = :student_id_2 
          AND a.is_active = 1 
          AND a.due_date > NOW() 
          AND s.id IS NULL";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->bindParam(':student_id_2', $student_id);
$stmt->execute();
$stats['pending_assignments_count'] = $stmt->fetchColumn();

// Bekleyen Ödevleri Getir (Liste için)
$pending_assignments = [];
try {
    $query = "SELECT a.*, c.course_name, c.course_code 
              FROM assignments a
              JOIN course_enrollments ce ON a.course_id = ce.course_id
              LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = :student_id
              WHERE ce.student_id = :student_id_2 
              AND a.is_active = 1 
              AND a.due_date > NOW() 
              AND s.id IS NULL
              ORDER BY a.due_date ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->bindParam(':student_id_2', $student_id);
    $stmt->execute();
    $pending_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Pending assignments fetch error: ' . $e->getMessage());
}



// Aktif yoklama oturumları (bugün ve henüz bitmemiş)
$query = "SELECT asess.*, c.course_name, c.course_code, u.full_name as teacher_name,
          (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.student_id = :student_id) as is_attended
          FROM attendance_sessions asess
          JOIN courses c ON asess.course_id = c.id
          JOIN course_enrollments ce ON c.id = ce.course_id
          LEFT JOIN users u ON c.teacher_id = u.id
          WHERE ce.student_id = :student_id AND asess.status = 'active'
          AND c.is_active = 1
          AND DATE(asess.session_date) = CURDATE()
          AND CONCAT(asess.session_date, ' ', asess.start_time) <= NOW()
          AND NOW() <= DATE_ADD(CONCAT(asess.session_date, ' ', asess.start_time), INTERVAL asess.duration_minutes MINUTE)
          ORDER BY asess.start_time DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$active_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['active_sessions_count'] = count($active_sessions);

// Aktif Sınavları Getir
$active_quizzes = [];
if (!empty($course_ids)) {
    try {
        $placeholders = str_repeat('?,', count($course_ids) - 1) . '?';
        $queryQ = "SELECT q.*, c.course_name, c.course_code 
                   FROM quizzes q
                   JOIN courses c ON q.course_id = c.id
                   WHERE q.course_id IN ($placeholders)
                   AND q.is_active = 1
                   AND (q.available_from IS NULL OR q.available_from <= NOW())
                   AND (q.available_until IS NULL OR q.available_until >= NOW())
                   AND NOT EXISTS (SELECT 1 FROM quiz_submissions qs WHERE qs.quiz_id = q.id AND qs.student_id = ?)
                   ORDER BY q.created_at DESC";
        $params = array_merge($course_ids, [$student_id]);
        $stmtQ = $db->prepare($queryQ);
        $stmtQ->execute($params);
        $active_quizzes = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Active quizzes fetch error: ' . $e->getMessage());
    }
}
$stats['active_quizzes_count'] = count($active_quizzes);

// Son duyuruları al (öğrencinin kayıtlı olduğu dersler + genel duyurular)
$announcements = [];
if (!empty($course_ids)) {
    try {
        $placeholders = str_repeat('?,', count($course_ids) - 1) . '?';
        $query = "SELECT m.*, c.course_name, c.course_code, u.full_name as sender_name
                  FROM messages m
                  LEFT JOIN courses c ON m.course_id = c.id
                  LEFT JOIN users u ON m.sender_id = u.id
                  WHERE (m.recipient_type = 'all' OR 
                        (m.recipient_type = 'specific' AND m.course_id IN ($placeholders)))
                  ORDER BY m.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute($course_ids);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Announcements fetch error: ' . $e->getMessage());
    }
} else {
    // Kayıtlı ders yoksa sadece genel duyuruları göster
    try {
        $query = "SELECT m.*, u.full_name as sender_name
                  FROM messages m
                  LEFT JOIN users u ON m.sender_id = u.id
                  WHERE m.recipient_type = 'all'
                  ORDER BY m.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Announcements fetch error: ' . $e->getMessage());
    }
}

// Duyuru sayısını istatistiklere ekle
$stats['announcements_count'] = count($announcements);

// Zorunlu Materyaller (YENİ - Filtreleme için öğrenci tamamlama durumu eklendi)
$required_materials = [];
$completed_material_ids = [];
$uncompleted_required_materials = [];
if (!empty($course_ids)) {
    try {
        // Önce öğrencinin tamamladığı materyallerin ID'lerini al
        $query_completed = "SELECT material_id FROM student_material_progress
                            WHERE student_id = :student_id AND is_completed = 1";
        $stmt_completed = $db->prepare($query_completed);
        $stmt_completed->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt_completed->execute();
        $completed_material_ids = $stmt_completed->fetchAll(PDO::FETCH_COLUMN);

        // Sonra tüm zorunlu materyalleri al
        $placeholders = str_repeat('?,', count($course_ids) - 1) . '?';
        $query = "SELECT cm.id, cm.material_title, cm.week_id, cw.week_number, c.id as course_id, c.course_name, c.course_code
                  FROM course_materials cm
                  JOIN course_weeks cw ON cm.week_id = cw.id
                  JOIN courses c ON cw.course_id = c.id
                  WHERE cw.course_id IN ($placeholders)
                  AND cm.is_required = 1
                  AND cw.is_published = 1
                  AND (cw.publish_date IS NULL OR cw.publish_date <= CURDATE())
                  ORDER BY c.course_name, cw.week_number, cm.display_order";
        $stmt = $db->prepare($query);
        $stmt->execute($course_ids);
        $required_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tamamlanmamış olanları filtrele
        foreach ($required_materials as $material) {
            if (!in_array($material['id'], $completed_material_ids)) {
                $uncompleted_required_materials[] = $material;
            }
        }

    } catch (Exception $e) {
        error_log('Required Materials fetch error: ' . $e->getMessage());
    }
}
// İstatistik sayısını tamamlanmamış olanlara göre güncelle
$stats['required_materials_count'] = count($uncompleted_required_materials);

// Son yoklama geçmişi
$query = "SELECT asess.*, c.course_name, c.course_code, ar.attendance_time,
          ar.second_phase_completed
          FROM attendance_records ar
          JOIN attendance_sessions asess ON ar.session_id = asess.id
          JOIN courses c ON asess.course_id = c.id
          WHERE ar.student_id = :student_id
          ORDER BY ar.attendance_time DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$attendance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Öğrenci Kontrol Paneli - ". htmlspecialchars($site_name);

// Include header
include '../includes/components/student_header.php';
?>

<style>
    /* Add some basic styling for the new card */
    .stat-card {
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    }
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    }
    .attendance-card {
        transition: box-shadow 0.2s ease-in-out;
    }
    .attendance-card:hover {
         box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .required-material-card {
        transition: all 0.2s ease-in-out;
    }
    .required-material-card:hover {
        background-color: #fff3cd !important;
        transform: translateY(-2px);
    }
    .card-actions {
        margin-top: 1rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .announcement-item {
        transition: all 0.2s ease-in-out;
        cursor: pointer;
    }
    .announcement-item:hover {
        background-color: #e7f3ff !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
    }
    .active-session {
        transition: all 0.2s ease-in-out;
    }
    .active-session:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
    }
    
    /* Responsive adjustments */
    @media (max-width: 991px) {
        .col-lg-4 {
            margin-bottom: 1rem !important;
        }
    }
</style>

<div class="container-fluid py-4">

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-tachometer-alt me-2"></i>Kontrol Paneli
            </h3>
            <p class="text-muted">Hoş geldiniz, <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>!</p>
        </div>
    </div>

    <?php display_message(); ?>

    <!-- Debug: Cron Status (Geliştirme için - kaldırabilirsin) -->
    <?php if ($cron_ran): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-cog fa-spin me-2"></i>
        <small>Sistem otomatik güncellemesi yapıldı.</small>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['total_courses']; ?></h3>
                            <p class="mb-0 small">Kayıtlı Dersim</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-book fa-2x opacity-75 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['attended_sessions']; ?></h3>
                            <p class="mb-0 small">Katıldığım Yoklama</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-check fa-2x opacity-75 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['active_quizzes_count']; ?></h3>
                            <p class="mb-0 small">Aktif Sınav</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-file-alt fa-2x opacity-75 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['pending_assignments_count']; ?></h3>
                            <p class="mb-0 small">Bekleyen Ödev</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tasks fa-2x opacity-75 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['active_sessions_count']; ?></h3>
                            <p class="mb-0 small">Aktif Yoklama</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-clock fa-2x opacity-75 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['required_materials_count']; ?></h3>
                            <p class="mb-0 small">Bekleyen İçerik</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-star fa-2x opacity-75 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['announcements_count']; ?></h3>
                            <p class="mb-0 small">Son Duyuru</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-bullhorn fa-2x opacity-75 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['today_attended']; ?></h3>
                            <p class="mb-0 small">Bugün Katıldım</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-day fa-2x opacity-75 text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Sessions, Required Materials & Announcements Side-by-Side (3 Column) -->
    <div class="row mb-4">
        <!-- Active Sessions -->
        <div class="col-lg-4 mb-4">
            
            <!-- Active Quizzes -->
            <?php if (!empty($active_quizzes)): ?>
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-signature me-2"></i>Aktif Sınavlar (<?php echo count($active_quizzes); ?>)
                    </h5>
                </div>
                <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;">
                    <?php foreach ($active_quizzes as $quiz): ?>
                    <div class="card mb-2 shadow-sm border-start border-4 border-primary">
                        <div class="card-body p-2">
                            <h6 class="card-title text-primary mb-1 small fw-bold">
                                <?php echo htmlspecialchars($quiz['title']); ?>
                            </h6>
                            <p class="card-text small mb-2 text-muted">
                                <?php echo htmlspecialchars($quiz['course_name']); ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-danger fw-bold">
                                    <?php if ($quiz['available_until']): ?>
                                        <i class="fas fa-hourglass-end me-1"></i>Son: <?php echo date('d.m H:i', strtotime($quiz['available_until'])); ?>
                                    <?php else: ?>
                                        <i class="fas fa-infinity me-1"></i>Süresiz
                                    <?php endif; ?>
                                </small>
                                <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-primary btn-sm py-0">Başla</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-3">
                <div class="card-body d-flex align-items-center justify-content-center flex-column">
                    <div class="text-center text-muted">
                        <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                        <p class="mb-0">Aktif sınav bulunmamaktadır.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Assignments -->
            <?php if (!empty($pending_assignments)): ?>
            <div class="card mb-3">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tasks me-2"></i>Bekleyen Ödevler (<?php echo count($pending_assignments); ?>)
                    </h5>
                </div>
                <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;">
                    <?php foreach ($pending_assignments as $assign): ?>
                    <div class="card mb-2 shadow-sm border-start border-4 border-warning">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="card-title text-dark mb-1 small fw-bold">
                                        <?php echo htmlspecialchars($assign['title']); ?>
                                    </h6>
                                    <p class="card-text small mb-2 text-muted">
                                        <?php echo htmlspecialchars($assign['course_name']); ?>
                                    </p>
                                </div>
                                <span class="badge bg-warning text-dark" style="font-size: 0.7rem;">
                                    <?php echo round($assign['max_score']); ?> Puan
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-danger fw-bold">
                                    <i class="fas fa-clock me-1"></i>Son: <?php echo date('d.m H:i', strtotime($assign['due_date'])); ?>
                                </small>
                                <a href="assignments.php?id=<?php echo $assign['id']; ?>" class="btn btn-warning btn-sm py-0 text-dark">Yükle</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-3">
                <div class="card-body d-flex align-items-center justify-content-center flex-column">
                    <div class="text-center text-muted">
                        <i class="fas fa-tasks fa-2x mb-2 text-success"></i>
                        <p class="mb-0">Bekleyen ödeviniz bulunmamaktadır.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($active_sessions)): ?>
            <div class="card mb-3">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-fire me-2"></i>Aktif Yoklamalar (<?php echo count($active_sessions); ?>)
                    </h5>
                </div>
                <div class="card-body p-2" style="max-height: 450px; overflow-y: auto;">
                    <?php foreach ($active_sessions as $session): ?>
                    <div class="card mb-2 active-session shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title text-danger mb-1 small">
                                <i class="fas fa-clock me-1"></i>
                                <?php echo htmlspecialchars($session['course_name']); ?>
                            </h6>
                            <p class="card-text small mb-2">
                                <strong>Oturum:</strong> <?php echo htmlspecialchars($session['session_name']); ?><br>
                                <strong>Öğretmen:</strong> <?php echo htmlspecialchars($session['teacher_name']); ?><br>
                                <strong>Saat:</strong> <?php echo date('H:i', strtotime($session['start_time'])); ?> |
                                <strong>Süre:</strong> <?php echo $session['duration_minutes']; ?> dk
                            </p>
                            <?php if ($session['is_attended'] > 0): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i>Katıldım
                                </span>
                            <?php else: ?>
                                <a href="attendance.php?session_id=<?php echo $session['id']; ?>" class="btn btn-danger btn-sm">
                                    <i class="fas fa-hand-paper me-1"></i>Yoklama Ver
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-3">
                <div class="card-body d-flex align-items-center justify-content-center flex-column">
                    <div class="text-center text-muted">
                        <i class="fas fa-calendar-check fa-3x mb-3 text-success"></i>
                        <p class="mb-0">Şu anda aktif yoklama oturumu bulunmamaktadır.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Required Materials -->
        <div class="col-lg-4 mb-4">
            <?php if (!empty($uncompleted_required_materials)): ?>
            <div class="card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-star me-2"></i>Bekleyen İçerikler (<?php echo count($uncompleted_required_materials); ?>)
                    </h5>
                </div>
                <div class="card-body p-2" style="max-height: 450px; overflow-y: auto;">
                    <?php foreach ($uncompleted_required_materials as $material): ?>
                    <a href="course_materials.php?course_id=<?php echo $material['course_id']; ?>#heading<?php echo $material['week_id']; ?>" class="text-decoration-none text-dark">
                        <div class="card mb-2 required-material-card shadow-sm">
                            <div class="card-body p-2">
                                <h6 class="card-title text-dark mb-1 small">
                                    <i class="fas fa-book-reader me-1"></i>
                                    <?php echo htmlspecialchars($material['material_title']); ?>
                                </h6>
                                <p class="card-text small mb-0 text-muted">
                                    <?php echo htmlspecialchars($material['course_name']); ?> (<?php echo htmlspecialchars($material['course_code']); ?>)
                                    - Hafta <?php echo $material['week_number']; ?>
                                </p>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card h-100">
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div class="text-center text-muted">
                        <i class="fas fa-thumbs-up fa-3x mb-3 text-info"></i>
                        <p class="mb-0">Tamamlanması gereken zorunlu bir ders içeriği bulunmamaktadır.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Announcements (COMPACT VERSION) -->
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bullhorn me-2"></i>Son Duyurular
                    </h5>
                    <?php if (!empty($announcements)): ?>
                    <a href="messages.php" class="btn btn-sm btn-light">
                        <i class="fas fa-arrow-right"></i> Tümü
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-2" style="max-height: 450px; overflow-y: auto;">
                    <?php if (empty($announcements)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">Henüz hiçbir duyuru bulunmamaktadır.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                        <a href="messages.php" class="text-decoration-none">
                            <div class="card mb-2 shadow-sm announcement-item">
                                <div class="card-body p-2">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="mb-1 small">
                                            <?php if ($announcement['recipient_type'] === 'all'): ?>
                                                <span class="badge bg-primary me-1" style="font-size: 0.7rem;">
                                                    <i class="fas fa-globe"></i> Genel
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary me-1" style="font-size: 0.7rem;">
                                                    <i class="fas fa-book"></i> 
                                                    <?php echo htmlspecialchars($announcement['course_code'] ?? 'Ders'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted" style="font-size: 0.7rem;">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php 
                                            $date = new DateTime($announcement['created_at']);
                                            $now = new DateTime();
                                            $diff = $now->diff($date);
                                            
                                            if ($diff->days == 0) {
                                                if ($diff->h == 0) {
                                                    echo $diff->i . 'dk';
                                                } else {
                                                    echo $diff->h . 'sa';
                                                }
                                            } elseif ($diff->days == 1) {
                                                echo 'Dün';
                                            } elseif ($diff->days < 7) {
                                                echo $diff->days . 'g';
                                            } else {
                                                echo $date->format('d.m');
                                            }
                                            ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 small fw-bold text-dark">
                                        <?php echo htmlspecialchars($announcement['subject']); ?>
                                    </p>
                                    <p class="mb-0 text-muted" style="font-size: 0.75rem;">
                                        <?php 
                                        // Mesajı 100 karakterle sınırla
                                        $message = htmlspecialchars($announcement['message']);
                                        if (strlen($message) > 100) {
                                            echo substr($message, 0, 100) . '...';
                                        } else {
                                            echo $message;
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- My Courses -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-book me-2"></i>Kayıtlı Olduğum Dersler
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($courses)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz hiçbir derse kayıtlı değilsiniz.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($courses as $course): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card attendance-card h-100">
                                        <div class="card-body d-flex flex-column">
                                            <h6 class="card-title"><?php echo htmlspecialchars($course['course_name']); ?></h6>
                                            <p class="card-text text-muted small flex-grow-1">
                                                <i class="fas fa-code me-1"></i><?php echo htmlspecialchars($course['course_code']); ?><br>
                                                <i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($course['teacher_name'] ?? 'Atanmamış'); ?><br>
                                                <i class="fas fa-calendar me-1"></i><?php echo htmlspecialchars($course['semester'] . ' - ' . $course['academic_year']); ?>
                                            </p>
                                            <div class="card-actions">
                                                <a href="reports.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-chart-line me-1"></i>Devamsızlık
                                                </a>
                                                <a href="course_materials.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-folder-open me-1"></i>İçerikler
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance History -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history me-2"></i>Son Yoklama Geçmişim (En Fazla 10)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($attendance_history)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz hiçbir yoklamaya katılmadınız.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>Ders</th>
                                        <th>Oturum Adı</th>
                                        <th>Oturum Zamanı</th>
                                        <th>Katılım Zamanı</th>
                                        <th>Durum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance_history as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['course_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($record['course_code']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['session_name']); ?></td>
                                            <td>
                                                <?php echo date('d.m.Y', strtotime($record['session_date'])); ?><br>
                                                <small class="text-muted"><?php echo date('H:i', strtotime($record['start_time'])); ?></small>
                                            </td>
                                            <td>
                                                <?php echo date('d.m.Y H:i', strtotime($record['attendance_time'])); ?>
                                            </td>
                                            <td>
                                                <?php if ($record['second_phase_completed']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i>Tamamlandı
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-clock me-1"></i>Yarım Kaldı
                                                    </span>
                                                <?php endif; ?>
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

</div> <!-- Closing container-fluid -->

<script>
    // Auto-refresh active sessions section only if there are active sessions initially
    var refreshActiveContent = <?php echo !empty($active_sessions) ? 'true' : 'false'; ?>;

    // Sayfayı 2 dakikada bir yenile (aktif oturumları güncel tutmak için)
    // Sadece aktif oturum varsa yenileme yapar, aksi halde gereksiz yere sayfayı yenilemez.
    if (refreshActiveContent) {
        setTimeout(function() {
            // Check again before reloading, maybe the session ended
             fetch(window.location.href, { method: 'HEAD' }) // Simple check if page is still valid
                .then(response => {
                    if (response.ok) {
                         // Check for active sessions again via a small API endpoint if possible,
                         // otherwise, just reload. For now, we reload.
                         console.log("Refreshing dashboard for active sessions...");
                         location.reload();
                    }
                }).catch(err => console.error("Error checking page before refresh:", err));
        }, 120000); // 2 dakika = 120000 ms
    }
</script>

<?php include '../includes/components/shared_footer.php'; ?>