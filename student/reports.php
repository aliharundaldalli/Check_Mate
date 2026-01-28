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
    error_log('Student Reports Error: ' . $e->getMessage());
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
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';
$max_absence_percentage = (int)($site_settings['max_absence_percentage'] ?? 25);

// Filtreler
$course_filter = (int)($_GET['course'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Bu ayın başı
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Bugün
$report_type = $_GET['type'] ?? 'summary'; // summary, detailed

// Öğrencinin kayıtlı olduğu dersleri al
try {
    $query = "SELECT c.*, u.full_name as teacher_name, ce.enrollment_date
              FROM courses c 
              JOIN course_enrollments ce ON c.id = ce.course_id 
              LEFT JOIN users u ON c.teacher_id = u.id
              WHERE ce.student_id = :student_id AND ce.is_active = 1 AND c.is_active = 1 
              ORDER BY c.course_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Student courses error: ' . $e->getMessage());
    $student_courses = [];
}

// Seçilen derse göre rapor verilerini al
$report_data = [];
$selected_course = null;

if ($course_filter > 0) {
    // Seçilen dersi bul
    foreach ($student_courses as $course) {
        if ($course['id'] == $course_filter) {
            $selected_course = $course;
            break;
        }
    }
    
    if ($selected_course) {
        try {
            // Ders için tüm oturumları al (tarih aralığında)
            $query = "SELECT asess.*, 
                             (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.student_id = :student_id AND ar.second_phase_completed = 1) as is_attended,
                             (SELECT ar.attendance_time FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.student_id = :student_id AND ar.second_phase_completed = 1) as attendance_time
                      FROM attendance_sessions asess
                      WHERE asess.course_id = :course_id
                      AND asess.session_date BETWEEN :date_from AND :date_to
                      ORDER BY asess.session_date DESC, asess.start_time DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $course_filter, PDO::PARAM_INT);
            $stmt->bindParam(':date_from', $date_from);
            $stmt->bindParam(':date_to', $date_to);
            $stmt->execute();
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // İstatistikleri hesapla
            $total_sessions = 0;
            $attended_sessions = 0;

            // Sadece geçmiş ve şimdiki oturumları say
            foreach ($sessions as $session) {
                $session_end_time = strtotime($session['session_date'] . ' ' . $session['start_time']) + ($session['duration_minutes'] * 60);
                if ($session_end_time < time() || $session['status'] === 'closed' || $session['status'] === 'expired') {
                    $total_sessions++;
                    if ($session['is_attended']) {
                        $attended_sessions++;
                    }
                }
            }

            $missed_sessions = $total_sessions - $attended_sessions;
            $attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100, 1) : 100;
            
            $report_data = [
                'course' => $selected_course,
                'sessions' => $sessions, // Tüm oturumları gönder, gösterimde filtrele
                'total_sessions' => $total_sessions,
                'attended_sessions' => $attended_sessions,
                'missed_sessions' => $missed_sessions,
                'attendance_rate' => $attendance_rate,
                'meets_requirement' => $attendance_rate >= (100 - $max_absence_percentage)
            ];
            
        } catch (Exception $e) {
            error_log('Report data error: ' . $e->getMessage());
        }
    }
} else {
    // Genel özet için tüm derslerin verilerini al
    try {
        $summary_data = [];
        foreach ($student_courses as $course) {
            $query = "SELECT 
                            COUNT(asess.id) as total_sessions,
                            SUM(CASE WHEN ar.id IS NOT NULL THEN 1 ELSE 0 END) as attended_sessions
                      FROM attendance_sessions asess
                      LEFT JOIN attendance_records ar ON asess.id = ar.session_id AND ar.student_id = :student_id AND ar.second_phase_completed = 1
                      WHERE asess.course_id = :course_id
                      AND (asess.status = 'closed' OR asess.status = 'expired' OR DATE_ADD(CONCAT(asess.session_date, ' ', asess.start_time), INTERVAL asess.duration_minutes MINUTE) < NOW())
                      AND asess.session_date BETWEEN :date_from AND :date_to";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $course['id'], PDO::PARAM_INT);
            $stmt->bindParam(':date_from', $date_from);
            $stmt->bindParam(':date_to', $date_to);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $attendance_rate = $stats['total_sessions'] > 0 ? round(((int)$stats['attended_sessions'] / (int)$stats['total_sessions']) * 100, 1) : 100;
            
            $summary_data[] = [
                'course' => $course,
                'total_sessions' => (int)$stats['total_sessions'],
                'attended_sessions' => (int)$stats['attended_sessions'],
                'missed_sessions' => (int)$stats['total_sessions'] - (int)$stats['attended_sessions'],
                'attendance_rate' => $attendance_rate,
                'meets_requirement' => $attendance_rate >= (100 - $max_absence_percentage)
            ];
        }
        
        $report_data = $summary_data;
    } catch (Exception $e) {
        error_log('Summary data error: ' . $e->getMessage());
        $report_data = [];
    }
}

// Grafik verileri hazırla
$chart_data = [];
if ($course_filter > 0 && !empty($report_data['sessions'])) {
    // Günlük devam verisi
    $daily_data = [];
    foreach ($report_data['sessions'] as $session) {
        $date = date('Y-m-d', strtotime($session['session_date']));
        if (!isset($daily_data[$date])) {
            $daily_data[$date] = ['total' => 0, 'attended' => 0];
        }
        $daily_data[$date]['total']++;
        if ($session['is_attended']) {
            $daily_data[$date]['attended']++;
        }
    }
    
    ksort($daily_data);
    $chart_data = $daily_data;
}
?>
<style>
    .sidebar {
        background: linear-gradient(180deg, #3498db 0%, #2980b9 100%);
        min-height: 100vh;
        position: fixed;
        width: 250px;
        top: 0;
        left: 0;
        z-index: 1000;
    }
    .main-content {
        margin-left: 250px;
        background-color: #f8f9fa;
        min-height: 100vh;
    }
    .sidebar .nav-link {
        color: #ecf0f1;
        padding: 12px 20px;
        border-radius: 8px;
        margin: 4px 12px;
        transition: all 0.3s ease;
    }
    .sidebar .nav-link:hover, .sidebar .nav-link.active {
        background-color: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    .stat-card {
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        border-radius: 15px;
        color: white;
        transition: transform 0.3s ease;
        border: none;
    }
    .stat-card:hover {
        transform: translateY(-5px);
    }
    .stat-card.success {
        background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
    }
    .stat-card.warning {
        background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
    }
    .stat-card.danger {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    }
    .progress-circle {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        margin: 0 auto;
    }
    .progress-circle::before {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: conic-gradient(
            var(--progress-color) calc(var(--progress) * 1%),
            #e9ecef calc(var(--progress) * 1%)
        );
    }
    .progress-circle-inner {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        z-index: 1;
    }
    .session-item {
        border-left: 4px solid #dee2e6;
        transition: transform 0.2s ease;
    }
    .session-item:hover {
        transform: translateX(5px);
    }
    .session-item.attended {
        border-left-color: #28a745;
        background-color: #d4edda;
    }
    .session-item.missed {
        border-left-color: #dc3545;
        background-color: #f8d7da;
    }
    .session-item.future {
        border-left-color: #6c757d;
        background-color: #e2e3e5;
    }
    .attendance-rate {
        font-size: 2rem;
        font-weight: bold;
    }
    .rate-excellent { color: #27ae60; }
    .rate-good { color: #f39c12; }
    .rate-warning { color: #e67e22; }
    .rate-danger { color: #e74c3c; }
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .course-summary-card {
        border-radius: 15px;
        border: none;
        transition: all 0.3s ease;
        overflow: hidden;
    }
    .course-summary-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }
        .main-content {
            margin-left: 0;
        }
    }
</style>
<?php
$page_title = "Devamsızlık Raporlarım - " . htmlspecialchars($site_name);

// Include header
include '../includes/components/student_header.php';
?>
    

        <!-- Page Content -->
        <div class="container-fluid p-4">
            <?php display_message(); ?>

            <!-- Filters -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="filter-card p-4">
                        <form method="GET" action="reports.php">
                            <div class="row align-items-end">
                                <div class="col-md-3">
                                    <label for="course" class="form-label">Ders Seçin</label>
                                    <select class="form-select" id="course" name="course">
                                        <option value="0">Tüm Dersler</option>
                                        <?php foreach ($student_courses as $course): ?>
                                            <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($course['course_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date_from" class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_to" class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search me-1"></i>Raporu Getir
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($course_filter > 0 && !empty($report_data)): ?>
                <!-- Single Course Report -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h3>
                            <i class="fas fa-book me-2"></i>
                            <?php echo htmlspecialchars($report_data['course']['course_name']); ?> - Devam Raporu
                        </h3>
                        <p class="text-muted">
                            <?php echo htmlspecialchars($report_data['course']['course_code']); ?> | 
                            Öğretmen: <?php echo htmlspecialchars($report_data['course']['teacher_name']); ?> | 
                            Tarih Aralığı: <?php echo date('d.m.Y', strtotime($date_from)); ?> - <?php echo date('d.m.Y', strtotime($date_to)); ?>
                        </p>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <h3><?php echo $report_data['total_sessions']; ?></h3>
                                <p class="mb-0">Toplam Oturum</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stat-card success">
                            <div class="card-body text-center">
                                <h3><?php echo $report_data['attended_sessions']; ?></h3>
                                <p class="mb-0">Katıldığım</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stat-card danger">
                            <div class="card-body text-center">
                                <h3><?php echo $report_data['missed_sessions']; ?></h3>
                                <p class="mb-0">Kaçırdığım</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stat-card <?php echo $report_data['meets_requirement'] ? 'success' : 'warning'; ?>">
                            <div class="card-body text-center">
                                <h3><?php echo $report_data['attendance_rate']; ?>%</h3>
                                <p class="mb-0">Devam Oranı</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Circle and Chart -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Devam Durumu
                                </h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="progress-circle mb-3" 
                                     style="--progress: <?php echo $report_data['attendance_rate']; ?>; --progress-color: <?php echo $report_data['attendance_rate'] >= 75 ? '#28a745' : ($report_data['attendance_rate'] >= 60 ? '#ffc107' : '#dc3545'); ?>;">
                                    <div class="progress-circle-inner">
                                        <div>
                                            <div class="h3 mb-0 <?php echo $report_data['attendance_rate'] >= 75 ? 'rate-excellent' : ($report_data['attendance_rate'] >= 60 ? 'rate-warning' : 'rate-danger'); ?>">
                                                <?php echo $report_data['attendance_rate']; ?>%
                                            </div>
                                            <small class="text-muted">Devam</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-<?php echo $report_data['meets_requirement'] ? 'success' : 'warning'; ?>">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <strong>Durum:</strong> 
                                    <?php if ($report_data['meets_requirement']): ?>
                                        Devam şartını sağlıyorsunuz
                                    <?php else: ?>
                                        Devam şartını sağlamıyorsunuz (Min. %<?php echo 100 - $max_absence_percentage; ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-line me-2"></i>Devam Grafiği
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="attendanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sessions List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>Yoklama Oturumları Detayı
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($report_data['sessions'])): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">Seçilen tarih aralığında yoklama oturumu bulunamadı.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="row">
                                        <?php foreach ($report_data['sessions'] as $session): ?>
                                            <?php
                                            $is_attended = $session['is_attended'] > 0;
                                            $session_db_status = $session['status']; // 'active', 'inactive', 'expired', 'closed', 'future'

                                            // Determine the final display status
                                            $display_status = '';
                                            if ($is_attended) {
                                                $display_status = 'attended';
                                            } elseif ($session_db_status === 'expired' || $session_db_status === 'closed') {
                                                $display_status = 'missed';
                                            } else {
                                                // For sessions not yet expired/closed
                                                $session_date = strtotime($session['session_date'] . ' ' . $session['start_time']);
                                                if ($session_date > time()) {
                                                    $display_status = 'future';
                                                } else {
                                                    // Session is in the past but not yet marked as expired by cron, or is currently active.
                                                    // In either case, if not attended, it's considered missed.
                                                    $display_status = 'missed';
                                                }
                                            }
                                            
                                            // Set display variables based on the final status
                                            if ($display_status === 'attended') {
                                                $card_class = 'attended';
                                                $icon = 'check-circle';
                                                $color = 'success';
                                                $status_text_html = '<small class="text-success"><i class="fas fa-check me-1"></i> Katıldım: ' . date('d.m.Y H:i', strtotime($session['attendance_time'])) . '</small>';
                                            } elseif ($display_status === 'missed') {
                                                $card_class = 'missed';
                                                $icon = 'times-circle';
                                                $color = 'danger';
                                                $status_text_html = '<small class="text-danger"><i class="fas fa-times me-1"></i> Katılınmadı</small>';
                                            } else { // future
                                                $card_class = 'future';
                                                $icon = 'clock';
                                                $color = 'secondary';
                                                $status_text_html = '<small class="text-muted"><i class="fas fa-clock me-1"></i> Henüz gerçekleşmedi</small>';
                                            }
                                            ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="session-item <?php echo $card_class; ?> card">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="card-title">
                                                                    <?php echo htmlspecialchars($session['session_name']); ?>
                                                                </h6>
                                                                <p class="card-text mb-2">
                                                                    <i class="fas fa-calendar me-1"></i>
                                                                    <?php echo date('d.m.Y', strtotime($session['session_date'])); ?>
                                                                    <br>
                                                                    <i class="fas fa-clock me-1"></i>
                                                                    <?php echo date('H:i', strtotime($session['start_time'])); ?>
                                                                    (<?php echo $session['duration_minutes']; ?> dk)
                                                                </p>
                                                                <?php echo $status_text_html; // Display the final status text ?>
                                                            </div>
                                                            <div class="text-center">
                                                                <i class="fas fa-<?php echo $icon; ?> fa-2x text-<?php echo $color; ?>"></i>
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
                    </div>
                </div>

            <?php elseif ($course_filter == 0 && !empty($report_data)): ?>
                <!-- Summary Report for All Courses -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h3>
                            <i class="fas fa-chart-bar me-2"></i>
                            Genel Devam Durumu Özeti
                        </h3>
                        <p class="text-muted">
                            Tarih Aralığı: <?php echo date('d.m.Y', strtotime($date_from)); ?> - <?php echo date('d.m.Y', strtotime($date_to)); ?>
                        </p>
                    </div>
                </div>

                <div class="row">
                    <?php foreach ($report_data as $course_data): ?>
                        <?php
                        $attendance_rate = $course_data['attendance_rate'];
                        $rate_class = $attendance_rate >= 85 ? 'rate-excellent' : 
                                      ($attendance_rate >= 75 ? 'rate-good' : 
                                      ($attendance_rate >= 60 ? 'rate-warning' : 'rate-danger'));
                        ?>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <div class="course-summary-card card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title text-primary">
                                                <?php echo htmlspecialchars($course_data['course']['course_name']); ?>
                                            </h5>
                                            <p class="card-text text-muted mb-1">
                                                <i class="fas fa-code me-1"></i>
                                                <?php echo htmlspecialchars($course_data['course']['course_code']); ?>
                                            </p>
                                            <p class="card-text text-muted">
                                                <i class="fas fa-user-tie me-1"></i>
                                                <?php echo htmlspecialchars($course_data['course']['teacher_name']); ?>
                                            </p>
                                        </div>
                                        <div class="text-center">
                                            <div class="attendance-rate <?php echo $rate_class; ?>">
                                                <?php echo $attendance_rate; ?>%
                                            </div>
                                            <small class="text-muted">Devam</small>
                                        </div>
                                    </div>

                                    <div class="row text-center mb-3">
                                        <div class="col-4">
                                            <div class="text-primary">
                                                <i class="fas fa-list"></i>
                                                <div class="fw-bold"><?php echo $course_data['total_sessions']; ?></div>
                                                <small>Toplam</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-success">
                                                <i class="fas fa-check"></i>
                                                <div class="fw-bold"><?php echo $course_data['attended_sessions']; ?></div>
                                                <small>Katıldım</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-danger">
                                                <i class="fas fa-times"></i>
                                                <div class="fw-bold"><?php echo $course_data['missed_sessions']; ?></div>
                                                <small>Kaçırdım</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="progress mb-3" style="height: 8px;">
                                        <div class="progress-bar bg-<?php echo $attendance_rate >= 75 ? 'success' : ($attendance_rate >= 60 ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo $attendance_rate; ?>%"></div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <?php if ($course_data['meets_requirement']): ?>
                                                <span class="badge bg-success">Şartı Sağlıyor</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Risk Altında</span>
                                            <?php endif; ?>
                                        </div>
                                        <a href="reports.php?course=<?php echo $course_data['course']['id']; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>Detaylar
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- No Data -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-chart-line fa-4x text-muted mb-3"></i>
                                <h4 class="text-muted">Veri Bulunamadı</h4>
                                <p class="text-muted">
                                    <?php if (empty($student_courses)): ?>
                                        Henüz hiçbir derse kayıtlı değilsiniz.
                                    <?php else: ?>
                                        Seçilen kriterlere uygun veri bulunamadı. Lütfen filtrelerinizi kontrol edin.
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($student_courses)): ?>
                                    <button type="button" class="btn btn-primary" onclick="resetFilters()">
                                        <i class="fas fa-refresh me-1"></i>Filtreleri Sıfırla
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Attendance Chart
        <?php if ($course_filter > 0 && !empty($chart_data)): ?>
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const chartData = <?php echo json_encode($chart_data); ?>;
        
        const labels = Object.keys(chartData);
        const attendedData = labels.map(date => chartData[date].attended);
        const totalData = labels.map(date => chartData[date].total);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.map(date => {
                    const d = new Date(date);
                    return d.toLocaleDateString('tr-TR', { month: 'short', day: 'numeric' });
                }),
                datasets: [{
                    label: 'Katıldığım Oturumlar',
                    data: attendedData,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Toplam Oturumlar',
                    data: totalData,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Günlük Devam Durumu'
                    },
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Reset filters function
        function resetFilters() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            
            document.getElementById('course').value = '0';
            document.getElementById('date_from').value = firstDay.toISOString().split('T')[0];
            document.getElementById('date_to').value = today.toISOString().split('T')[0];
            
            document.querySelector('form').submit();
        }
        
        // Auto-submit on date change
        document.getElementById('date_from').addEventListener('change', function() {
            if (this.value && document.getElementById('date_to').value) {
                if (new Date(this.value) > new Date(document.getElementById('date_to').value)) {
                    document.getElementById('date_to').value = this.value;
                }
            }
        });
        
        document.getElementById('date_to').addEventListener('change', function() {
            if (this.value && document.getElementById('date_from').value) {
                if (new Date(this.value) < new Date(document.getElementById('date_from').value)) {
                    document.getElementById('date_from').value = this.value;
                }
            }
        });
    </script>

<?php include '../includes/components/shared_footer.php'; ?>
