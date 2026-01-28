<?php
// teacher/reports.php

// --- Configuration and Initialization ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);

// Autoload Composer dependencies (for PhpSpreadsheet)
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
} else {
    die('Error: Composer autoloader not found. Please run "composer install" in your project root.');
}

require_once '../includes/functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// === ARKA PLANDA CRON JOB ÇALIŞTIR ===
function runBackgroundCron($db) {
    try {
        $last_cron_file = '../logs/last_cron_run.txt';
        $current_time = time();
        
        if (file_exists($last_cron_file)) {
            $last_run = (int)file_get_contents($last_cron_file);
            if (($current_time - $last_run) < 120) {
                return false;
            }
        }
        
        $db->exec("SET time_zone = '+03:00'");
        $cron_log = [];
        
        // Süresi dolan oturumları güncelle
        $query_expired = "UPDATE attendance_sessions 
                          SET status = 'expired', is_active = 0, expired_at = NOW()
                          WHERE status IN ('active', 'inactive') AND closed_at IS NULL 
                          AND DATE_ADD(CONCAT(session_date, ' ', start_time), INTERVAL duration_minutes MINUTE) < NOW()";
        
        $stmt_expired = $db->prepare($query_expired);
        $stmt_expired->execute();
        $expired_count = $stmt_expired->rowCount();
        
        if ($expired_count > 0) {
            $cron_log[] = "Süresi dolduğu için $expired_count oturum 'expired' olarak güncellendi.";
        }
        
        // Gelecekteki oturumları aktif hale getir
        $query_future = "UPDATE attendance_sessions SET status = 'inactive' 
                         WHERE status = 'future' AND closed_at IS NULL 
                         AND NOW() >= CONCAT(session_date, ' ', start_time)";
        
        $stmt_future = $db->prepare($query_future);
        $stmt_future->execute();
        $future_count = $stmt_future->rowCount();
        
        if ($future_count > 0) {
            $cron_log[] = "$future_count adet 'future' oturum 'inactive' olarak ayarlandı.";
        }
        
        // Eski anahtarları temizle
        $query_keys = "DELETE FROM second_phase_keys WHERE valid_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $stmt_keys = $db->prepare($query_keys);
        $stmt_keys->execute();
        $keys_count = $stmt_keys->rowCount();
        
        if ($keys_count > 0) {
            $cron_log[] = "$keys_count adet eski ikinci aşama anahtarı temizlendi.";
        }
        
        $log_dir = dirname($last_cron_file);
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        file_put_contents($last_cron_file, $current_time);
        
        if (!empty($cron_log)) {
            $log_entry = date('Y-m-d H:i:s') . " - Background Cron (Teacher Reports):\n";
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

// --- Main Application Logic ---
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Öğretmen yetkisi kontrolü
    $auth = new Auth();
    if (!$auth->checkRole('teacher')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $teacher_id = $_SESSION['user_id'];
    
    // Arka planda cron'u çalıştır
    $cron_ran = runBackgroundCron($db);

    // --- Handle API Requests (Dynamic Student Loading) ---
    if (isset($_GET['api']) && $_GET['api'] === 'get_students') {
        header('Content-Type: application/json');
        $courseIdApi = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
        $students = [];
        if ($courseIdApi) {
            // Sadece öğretmenin kendi derslerindeki AKTIF öğrencileri getir
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.student_number 
                 FROM users u
                 JOIN course_enrollments ce ON u.id = ce.student_id
                 JOIN courses c ON ce.course_id = c.id
                 WHERE ce.course_id = :course_id AND c.teacher_id = :teacher_id 
                 AND u.user_type = 'student' AND u.is_active = 1 AND ce.is_active = 1
                 ORDER BY u.full_name"
            );
            $stmt->execute([':course_id' => $courseIdApi, ':teacher_id' => $teacher_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($students);
        exit;
    }

    // --- Load Page Data and Handle Form Submissions ---
    $course_id  = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
    $student_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT) ?: 0;
    $start_date = filter_input(INPUT_GET, 'start_date', FILTER_DEFAULT) ?: date('Y-m-01');
    $end_date   = filter_input(INPUT_GET, 'end_date', FILTER_DEFAULT) ?: date('Y-m-t');
    $action     = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);

    // Site ayarları
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $settings_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_settings = [
        'site_name' => $settings_raw['site_name'] ?? 'AhdaKade Yoklama Sistemi',
        'max_absence_percentage' => (int)($settings_raw['max_absence_percentage'] ?? 25),
        'site_logo' => $settings_raw['site_logo'] ?? '',
        'site_favicon' => $settings_raw['site_favicon'] ?? '',
        'theme_color' => $settings_raw['theme_color'] ?? '#0d6efd'
    ];

    // Sadece öğretmenin derslerini getir
    $stmt = $db->prepare("SELECT id, course_code, course_name FROM courses 
                         WHERE teacher_id = :teacher_id AND is_active = 1 ORDER BY course_name");
    $stmt->execute([':teacher_id' => $teacher_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Seçilen varlıkların bilgilerini al
    $student_info = null;
    $course_info = null;
    
    if ($student_id > 0) {
        // Öğrencinin bu öğretmenin derslerinde AKTIF olarak kayıtlı olup olmadığını kontrol et
        $stmt = $db->prepare("SELECT u.full_name, u.student_number FROM users u
                             JOIN course_enrollments ce ON u.id = ce.student_id
                             JOIN courses c ON ce.course_id = c.id
                             WHERE u.id = :student_id AND c.teacher_id = :teacher_id 
                             AND u.user_type = 'student' AND ce.is_active = 1 LIMIT 1");
        $stmt->execute([':student_id' => $student_id, ':teacher_id' => $teacher_id]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($course_id > 0) {
        // Dersin bu öğretmene ait olup olmadığını kontrol et
        $stmt = $db->prepare("SELECT course_code, course_name FROM courses 
                             WHERE id = :course_id AND teacher_id = :teacher_id");
        $stmt->execute([':course_id' => $course_id, ':teacher_id' => $teacher_id]);
        $course_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $report_data = [];
    $report_type = null;

    // --- Generate Report Data ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($course_id > 0 || $student_id > 0)) {
        
        if ($student_id > 0 && $student_info) {
            // --- SINGLE STUDENT REPORT ---
            $report_type = 'student';
            $sql = "SELECT 
                        s.session_date,
                        s.start_time,
                        c.course_code,
                        c.course_name,
                        t.full_name AS teacher_name,
                        ar.attendance_time,
                        s.status
                    FROM attendance_sessions s
                    JOIN courses c ON s.course_id = c.id
                    JOIN users t ON s.teacher_id = t.id
                    JOIN course_enrollments ce ON ce.course_id = c.id AND ce.student_id = :student_id
                    LEFT JOIN attendance_records ar ON s.id = ar.session_id AND ar.student_id = :student_id
                    WHERE c.teacher_id = :teacher_id 
                    AND DATE(s.session_date) BETWEEN :start_date AND :end_date
                    AND ce.is_active = 1
                    AND s.status IN ('closed', 'expired')
                    " . ($course_id > 0 ? "AND s.course_id = :course_id" : "") . "
                    ORDER BY s.session_date DESC, s.start_time DESC
                    LIMIT 500";
            
            $stmt = $db->prepare($sql);
            $params = [
                ':student_id' => $student_id,
                ':teacher_id' => $teacher_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
            if ($course_id > 0) {
                $params[':course_id'] = $course_id;
            }
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } else if ($course_id > 0 && $course_info) {
            // --- COURSE SUMMARY REPORT ---
            $report_type = 'course';
            $sql = "SELECT 
                        u.id AS student_id,
                        u.full_name,
                        u.student_number,
                        (SELECT COUNT(*) FROM attendance_sessions 
                         WHERE course_id = :course_id 
                         AND DATE(session_date) BETWEEN :start_date AND :end_date
                         AND status IN ('closed', 'expired')) AS total_sessions,
                        COUNT(ar.id) AS present_total
                    FROM course_enrollments ce
                    JOIN users u ON ce.student_id = u.id
                    LEFT JOIN attendance_sessions s ON ce.course_id = s.course_id 
                         AND DATE(s.session_date) BETWEEN :start_date AND :end_date
                         AND s.status IN ('closed', 'expired')
                    LEFT JOIN attendance_records ar ON s.id = ar.session_id AND ar.student_id = u.id
                    WHERE ce.course_id = :course_id 
                    AND u.user_type = 'student' 
                    AND ce.is_active = 1
                    GROUP BY u.id, u.full_name, u.student_number
                    ORDER BY u.full_name
                    LIMIT 500";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':course_id' => $course_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // --- Handle Excel Export Action ---
    if ($action === 'export' && !empty($report_data)) {
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        if ($report_type === 'student') {
            $sheet->setCellValue('A1', 'Tarih');
            $sheet->setCellValue('B1', 'Ders');
            $sheet->setCellValue('C1', 'Öğretmen');
            $sheet->setCellValue('D1', 'Durum');
            $sheet->setCellValue('E1', 'Giriş Zamanı');
            $rowNum = 2;
            foreach ($report_data as $row) {
                $status = $row['attendance_time'] ? 'Mevcut' : 'Yok';
                
                $sheet->setCellValue('A' . $rowNum, date('d.m.Y', strtotime($row['session_date'])));
                $sheet->setCellValue('B' . $rowNum, htmlspecialchars($row['course_code'] . ' - ' . $row['course_name']));
                $sheet->setCellValue('C' . $rowNum, htmlspecialchars($row['teacher_name']));
                $sheet->setCellValue('D' . $rowNum, $status);
                $sheet->setCellValue('E' . $rowNum, $row['attendance_time'] ? date('H:i:s', strtotime($row['attendance_time'])) : '-');
                $rowNum++;
            }
        } else {
            $sheet->setCellValue('A1', 'Öğrenci Adı');
            $sheet->setCellValue('B1', 'Öğrenci No');
            $sheet->setCellValue('C1', 'Toplam Ders');
            $sheet->setCellValue('D1', 'Mevcut');
            $sheet->setCellValue('E1', 'Yok');
            $sheet->setCellValue('F1', 'Devam %');
            $rowNum = 2;
            foreach ($report_data as $row) {
                $total_sessions = (int)$row['total_sessions'];
                $present_total = (int)$row['present_total'];
                $absent_count = $total_sessions - $present_total;
                $attendance_percentage = ($total_sessions > 0) ? round(($present_total / $total_sessions) * 100, 2) : 0;
                
                $sheet->setCellValue('A' . $rowNum, htmlspecialchars($row['full_name']));
                $sheet->setCellValue('B' . $rowNum, htmlspecialchars($row['student_number']));
                $sheet->setCellValue('C' . $rowNum, $total_sessions);
                $sheet->setCellValue('D' . $rowNum, $present_total);
                $sheet->setCellValue('E' . $rowNum, $absent_count);
                $sheet->setCellValue('F' . $rowNum, $attendance_percentage);
                $rowNum++;
            }
        }

        $fileName = "yoklama_raporu_" . date('Y-m-d') . ".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . urlencode($fileName) . '"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

} catch (PDOException $e) {
    error_log('Database Error in teacher/reports.php: ' . $e->getMessage());
    $error_message = 'Veritabanı hatası oluştu. Lütfen sistem yöneticisi ile iletişime geçin.';
} catch (Exception $e) {
    error_log('General Error in teacher/reports.php: ' . $e->getMessage());
    $error_message = 'Bir sistem hatası oluştu. Lütfen daha sonra tekrar deneyin.';
}

$page_title = "Raporlar - " . htmlspecialchars($site_settings['site_name']);
include '../includes/components/teacher_header.php'; 
?>

<style>
    :root {
        --theme-color: <?php echo htmlspecialchars($site_settings['theme_color']); ?>;
        --theme-color-bg: color-mix(in srgb, var(--theme-color) 15%, transparent);
    }
    .filter-card {
        border-left: 5px solid #27ae60;
        background-color: var(--bs-light-bg-subtle);
    }
    .themed-header {
        background-color:#27ae60;
        color: white;
    }
    .report-info-card {
        background-color:#27ae60;
        border-left: 5px solid #27ae60;
    }
    .report-table th {
        font-weight: 600;
    }
    .progress {
        height: 22px;
        font-size: 0.85rem;
    }
    .form-label {
        font-weight: 500;
    }
    @media print {
        .no-print, .no-print * {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
    }
</style>

<div class="container-fluid p-4">
    
    <?php display_message(); ?>

    <!-- Debug: Cron Status -->
    <?php if ($cron_ran): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-cog fa-spin me-2"></i>
        <small>Sistem otomatik güncellemesi yapıldı.</small>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filter Form Card -->
    <div class="card filter-card mb-4 no-print">
        <div class="card-header themed-header text-white">
            <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Rapor Filtreleri</h5>
        </div>
        <div class="card-body">
            <form id="reportFilterForm" method="GET" action="reports.php">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="course_id" class="form-label">Ders Seçin</label>
                        <select class="form-select" id="course_id" name="course_id">
                            <option value="">-- Ders Seçiniz --</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course['id']; ?>" <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="student_id" class="form-label">Öğrenci Seçin</label>
                        <select class="form-select" id="student_id" name="student_id" <?php if ($course_id === 0) echo 'disabled';?>>
                            <option value="">Tüm Öğrenciler</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="start_date" class="form-label">Başlangıç Tarihi</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="end_date" class="form-label">Bitiş Tarihi</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-search me-1"></i>Raporla
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Results -->
    <?php if (!empty($report_data)): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-file-alt me-2"></i>
                    <?php 
                        if ($report_type === 'student' && $student_info) {
                            echo "Öğrenci Raporu: " . htmlspecialchars($student_info['full_name']);
                        } else if ($report_type === 'course' && $course_info) {
                            echo "Ders Raporu: " . htmlspecialchars($course_info['course_code'] . ' - ' . $course_info['course_name']);
                        } else {
                            echo "Rapor Sonuçları";
                        }
                    ?>
                </h5>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-print me-1"></i>Yazdır
                    </button>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['action' => 'export'])); ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel me-1"></i>Excel'e Aktar
                    </a>
                </div>
            </div>
            <div class="card-body">
                
                <?php if ($report_type === 'student' && $student_info): ?>
                <div class="card report-info-card p-3 mb-4">
                    <strong>Öğrenci:</strong> <?php echo htmlspecialchars($student_info['full_name']); ?><br>
                    <strong>Numara:</strong> <?php echo htmlspecialchars($student_info['student_number']); ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover table-striped report-table" id="reportTable">
                        
                        <?php if ($report_type === 'student'): ?>
                            <!-- Student Detail Report View -->
                            <thead>
                                <tr><th>Tarih</th><th>Ders</th><th>Öğretmen</th><th>Durum</th><th>Giriş Zamanı</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td><?php echo date('d.m.Y', strtotime($row['session_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['course_code'] . ' - ' . $row['course_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['teacher_name']); ?></td>
                                        <td>
                                            <?php
                                            $status_badge = '<span class="badge bg-danger">Yok</span>';
                                            if ($row['attendance_time']) {
                                                $status_badge = '<span class="badge bg-success">Mevcut</span>';
                                            }
                                            echo $status_badge;
                                            ?>
                                        </td>
                                        <td><?php echo $row['attendance_time'] ? date('H:i:s', strtotime($row['attendance_time'])) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        <?php else: // Course Summary Report View ?>
                            <thead>
                                <tr><th>Öğrenci</th><th>Öğrenci No</th><th>Toplam Ders</th><th>Mevcut</th><th>Yok</th><th>Devam %</th><th>Durum</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <?php
                                    $total_sessions = (int)$row['total_sessions'];
                                    $present_total = (int)$row['present_total'];
                                    $absent_count = $total_sessions - $present_total;
                                    $attendance_percentage = ($total_sessions > 0) ? round(($present_total / $total_sessions) * 100) : 0;
                                    $absence_percentage = 100 - $attendance_percentage;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['student_number']); ?></td>
                                        <td><?php echo $total_sessions; ?></td>
                                        <td><?php echo $present_total; ?></td>
                                        <td><?php echo $absent_count; ?></td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar <?php echo $attendance_percentage >= 75 ? 'bg-success' : ($attendance_percentage >= 50 ? 'bg-warning' : 'bg-danger'); ?>"
                                                     role="progressbar" style="width: <?php echo $attendance_percentage; ?>%"
                                                     aria-valuenow="<?php echo $attendance_percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <strong><?php echo $attendance_percentage; ?>%</strong>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($absence_percentage > $site_settings['max_absence_percentage']): ?>
                                                <span class="badge bg-danger">Devamdan Kaldı</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && ($course_id > 0 || $student_id > 0)): ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-info-circle me-2"></i>Seçilen kriterlere uygun veri bulunamadı.
        </div>
    <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
            <h4 class="text-muted">Rapor Görüntüleyin</h4>
            <p class="text-muted">Rapor oluşturmak için lütfen yukarıdaki filtrelerden en az bir ders seçimi yapın.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const courseSelect = document.getElementById('course_id');
    const studentSelect = document.getElementById('student_id');
    const initiallySelectedStudentId = '<?php echo $student_id; ?>';

    const fetchStudents = (selectedCourseId) => {
        studentSelect.innerHTML = '<option value="">Tüm Öğrenciler</option>';

        if (!selectedCourseId) {
            studentSelect.disabled = true;
            return;
        }

        studentSelect.disabled = false;
        
        const loadingOption = new Option('Öğrenciler Yükleniyor...', '');
        loadingOption.disabled = true;
        studentSelect.add(loadingOption);

        fetch(`?api=get_students&course_id=${selectedCourseId}`)
            .then(response => response.json())
            .then(data => {
                studentSelect.remove(loadingOption.index);

                data.forEach(student => {
                    const option = new Option(
                        `${student.full_name} (${student.student_number})`, 
                        student.id
                    );
                    if (student.id == initiallySelectedStudentId) {
                        option.selected = true;
                    }
                    studentSelect.add(option);
                });
            })
            .catch(error => {
                console.error('Error fetching students:', error);
                studentSelect.remove(loadingOption.index);
                const errorOption = new Option('Hata oluştu!', '');
                errorOption.disabled = true;
                studentSelect.add(errorOption);
            });
    };

    courseSelect.addEventListener('change', () => {
        fetchStudents(courseSelect.value);
    });

    if (courseSelect.value) {
        fetchStudents(courseSelect.value);
    }

    // Alert otomatik kapanma
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

<?php include '../includes/components/shared_footer.php'; ?>