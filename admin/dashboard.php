<?php
// admin/dashboard.php

// Increase memory limit and execution time
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Admin role check
    if (!$auth->checkRole('admin')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
} catch (Exception $e) {
    error_log('Admin Dashboard Error: ' . $e->getMessage());
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
            $log_entry = date('Y-m-d H:i:s') . " - Background Cron (Admin Dashboard):\n";
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

// Load site settings
$query = "SELECT setting_key, setting_value FROM site_settings";
$stmt = $db->prepare($query);
$stmt->execute();
$site_settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $site_settings[$row['setting_key']] = $row['setting_value'];
}

// Default values
$site_name = $site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi';
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';
$theme_color = $site_settings['theme_color'] ?? '#620ec8';

// Statistics
$stats = [];

// Total user count by type
$query = "SELECT user_type, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY user_type";
$stmt = $db->prepare($query);
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stats[$row['user_type']] = $row['count'];
}

// Ensure all user types have a value
$stats['admin'] = $stats['admin'] ?? 0;
$stats['teacher'] = $stats['teacher'] ?? 0;
$stats['student'] = $stats['student'] ?? 0;

// Total course count
$query = "SELECT COUNT(*) as count FROM courses WHERE is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['courses'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total attendance session count
$query = "SELECT COUNT(*) as count FROM attendance_sessions WHERE is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Today's attendance sessions
$query = "SELECT COUNT(*) as count FROM attendance_sessions WHERE DATE(session_date) = CURDATE() AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['today_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Aktif yoklama oturumları
$query = "SELECT COUNT(*) as count FROM attendance_sessions WHERE status = 'active' AND DATE(session_date) = CURDATE()";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['active_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total quizzes count
$stats['quizzes'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Active Assignments Count (Active & Submission Open)
$query = "SELECT COUNT(*) as count FROM assignments WHERE is_active = 1 AND due_date > NOW()";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['active_assignments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Active Quizzes List (Active & Available Now)
$active_quizzes = [];
$query = "SELECT q.*, c.course_name, c.course_code 
          FROM quizzes q
          JOIN courses c ON q.course_id = c.id
          WHERE q.is_active = 1 
          AND (q.available_from IS NULL OR q.available_from <= NOW())
          AND (q.available_until IS NULL OR q.available_until >= NOW())
          ORDER BY q.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$active_quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active Assignments List
$active_assignments = [];
$query = "SELECT a.*, c.course_name, c.course_code, u.full_name as teacher_name
          FROM assignments a
          JOIN courses c ON a.course_id = c.id
          JOIN users u ON c.teacher_id = u.id
          WHERE a.is_active = 1 AND a.due_date > NOW()
          ORDER BY a.due_date ASC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$active_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Admin Kontrol Paneli - " . htmlspecialchars($site_name);

// Include header
include '../includes/components/admin_header.php';
?>

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

    <!-- Statistics Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-4">
        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['admin']; ?></h3>
                            <p class="mb-0">Admin</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-shield fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['teacher']; ?></h3>
                            <p class="mb-0">Öğretmen</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-chalkboard-teacher fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['student']; ?></h3>
                            <p class="mb-0">Öğrenci</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-graduate fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['courses']; ?></h3>
                            <p class="mb-0">Aktif Ders</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-book-open fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['quizzes']; ?></h3>
                            <p class="mb-0">Toplam Sınav</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-pen-fancy fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?php echo $stats['active_assignments']; ?></h3>
                            <p class="mb-0">Aktif Ödev</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tasks fa-2x opacity-75 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-wrap gap-3">
                <a href="users.php?action=add" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Yeni Kullanıcı Ekle
                </a>
                <a href="courses.php?action=add" class="btn btn-danger">
                    <i class="fas fa-plus-circle me-2"></i>Yeni Ders Ekle
                </a>
                <a href="quizzes.php" class="btn btn-purple" style="background-color: #6f42c1; color: white;">
                    <i class="fas fa-pen-fancy me-2"></i>Sınav Yönetimi
                </a>
                <a href="reports.php" class="btn btn-info">
                    <i class="fas fa-file-alt me-2"></i>Raporları Görüntüle
                </a>
                <a href="settings.php" class="btn btn-warning">
                    <i class="fas fa-cogs me-2"></i>Sistem Ayarları
                </a>
                 <a href="backup.php" class="btn btn-success">
                    <i class="fa-solid fa-database me-2"></i></i>Yedek Al
                </a>
            </div>
        </div>
    </div>

    <!-- Active Quizzes & Assignments Row -->
    <div class="row mb-4">
        <!-- Active Quizzes -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-purple text-white" style="background-color: #6f42c1;">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-signature me-2"></i>Aktif Sınavlar
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (!empty($active_quizzes)): ?>
                            <?php foreach ($active_quizzes as $quiz): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 text-primary fw-bold">
                                                <?php echo htmlspecialchars($quiz['title']); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($quiz['course_name']); ?>
                                            </small>
                                        </div>
                                        <a href="quizzes.php?action=edit&id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                    <small class="text-danger">
                                        <?php if ($quiz['available_until']): ?>
                                            <i class="fas fa-clock me-1"></i>Son: <?php echo date('d.m H:i', strtotime($quiz['available_until'])); ?>
                                        <?php else: ?>
                                            <i class="fas fa-infinity me-1"></i>Süresiz
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                            <div class="card-footer text-center">
                                <a href="quizzes.php" class="text-decoration-none small">Tüm Sınavları Gör</a>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">Aktif sınav bulunmuyor.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Assignments -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tasks me-2"></i>Aktif Ödevler
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (!empty($active_assignments)): ?>
                            <?php foreach ($active_assignments as $assign): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 text-dark fw-bold">
                                                <?php echo htmlspecialchars($assign['title']); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($assign['course_name']); ?> | 
                                                <i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($assign['teacher_name']); ?>
                                            </small>
                                        </div>
                                        <a href="assignments.php?id=<?php echo $assign['id']; ?>" class="btn btn-sm btn-outline-warning text-dark">
                                            <i class="fas fa-search"></i>
                                        </a>
                                    </div>
                                    <small class="text-danger">
                                        <i class="fas fa-hourglass-end me-1"></i>Son Teslim: <?php echo date('d.m H:i', strtotime($assign['due_date'])); ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                            <div class="card-footer text-center">
                                <a href="assignments.php" class="text-decoration-none small text-warning">Tüm Ödevleri Gör</a>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">Aktif ödev bulunmuyor.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- User Distribution Chart -->
        <div class="col-xl-8 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Kullanıcı Dağılımı
                    </h5>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="userDistributionChart" style="max-height: 350px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-body text-center p-4">
                    <i class="fas fa-calendar-check text-primary fa-2x mb-3"></i>
                    <h3 class="mb-1"><?php echo $stats['sessions']; ?></h3>
                    <p class="text-muted mb-0">Toplam Yoklama Oturumu</p>
                </div>
            </div>
            
            <div class="card mb-3">
                <div class="card-body text-center p-4">
                    <i class="fas fa-calendar-day text-success fa-2x mb-3"></i>
                    <h3 class="mb-1"><?php echo $stats['today_sessions']; ?></h3>
                    <p class="text-muted mb-0">Bugünkü Yoklama Oturumları</p>
                </div>
            </div>

            <div class="card">
                <div class="card-body text-center p-4">
                    <i class="fas fa-broadcast-tower text-danger fa-2x mb-3"></i>
                    <h3 class="mb-1"><?php echo $stats['active_sessions']; ?></h3>
                    <p class="text-muted mb-0">Şu An Aktif Oturumlar</p>
                </div>
            </div>
        </div>
    </div>

    <!-- System Status -->
    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-server me-2"></i>Sistem Durumu
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span>Veritabanı Bağlantısı</span>
                        <span class="badge bg-success">Aktif</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span>Arka Plan İşlemleri</span>
                        <span class="badge bg-<?php echo $cron_ran ? 'success' : 'secondary'; ?>">
                            <?php echo $cron_ran ? 'Çalışıyor' : 'Beklemede'; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Son Güncelleme</span>
                        <small class="text-muted"><?php echo date('H:i:s'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-line me-2"></i>Bugünkü Aktivite
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span>Toplam Oturum</span>
                        <strong><?php echo $stats['today_sessions']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span>Aktif Oturum</span>
                        <strong class="text-danger"><?php echo $stats['active_sessions']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Oturum Oranı</span>
                        <strong class="text-success">
                            <?php echo $stats['today_sessions'] > 0 ? round(($stats['active_sessions'] / $stats['today_sessions']) * 100) : 0; ?>%
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tools me-2"></i>Yönetim Araçları
                    </h5>
                </div>
                <div class="card-body">
                    <a href="settings.php" class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="fas fa-cogs me-1"></i>Sistem Ayarları
                    </a>
                    <a href="users.php" class="btn btn-outline-danger btn-sm w-100 mb-2">
                        <i class="fas fa-users me-1"></i>Kullanıcı Yönetimi
                    </a>
                    <a href="courses.php" class="btn btn-outline-info btn-sm w-100 mb-2">
                        <i class="fas fa-book me-1"></i>Ders Yönetimi
                    </a>
                        <i class="fas fa-book me-1"></i>Ders Yönetimi
                    </a>
                    <a href="assignments.php" class="btn btn-outline-warning btn-sm w-100 mb-2">
                        <i class="fas fa-tasks me-1"></i>Ödev Yönetimi
                    </a>
                    <a href="quizzes.php" class="btn btn-outline-purple btn-sm w-100 mb-2" style="color: #6f42c1; border-color: #6f42c1;">
                        <i class="fas fa-pen-fancy me-1"></i>Sınav Yönetimi
                    </a>
                    <a href="reports.php" class="btn btn-outline-warning btn-sm w-100">
                        <i class="fas fa-chart-bar me-1"></i>Detaylı Raporlar
                    </a>
                    <a href="backup.php" class="btn btn-outline-success btn-sm w-100">
                       <i class="fa-solid fa-database me-1"></i></i>Yedek Alma
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Chart.js: User Distribution Chart
    const userCtx = document.getElementById('userDistributionChart').getContext('2d');
    
    const userData = {
        labels: ['Admin', 'Öğretmen', 'Öğrenci'],
        datasets: [{
            label: 'Kullanıcı Sayısı',
            data: [
                <?php echo $stats['admin']; ?>,
                <?php echo $stats['teacher']; ?>,
                <?php echo $stats['student']; ?>
            ],
            backgroundColor: [
                'rgba(255, 99, 132, 0.8)',
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 206, 86, 0.8)'
            ],
            borderColor: [
                'rgba(255, 99, 132, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(255, 206, 86, 1)'
            ],
            borderWidth: 2,
            hoverOffset: 8
        }]
    };

    new Chart(userCtx, {
        type: 'doughnut',
        data: userData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                           family: 'Inter'
                        }
                    }
                },
                tooltip: {
                    bodyFont: {
                        family: 'Inter'
                    },
                    titleFont: {
                        family: 'Inter'
                    }
                }
            }
        }
    });

    // Stat kartlarına tıklama animasyonu
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', function() {
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });
    });

    // Alert otomatik kapanma
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Aktif oturumlar varsa sayfayı 3 dakikada bir yenile
    <?php if ($stats['active_sessions'] > 0): ?>
    setTimeout(function() {
        location.reload();
    }, 180000); // 3 dakika
    <?php endif; ?>
});
</script>

<?php include '../includes/components/shared_footer.php'; ?> 