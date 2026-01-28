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
    error_log('Student Courses Error: ' . $e->getMessage());
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

// Seçilen ders ID'si
$selected_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Öğrencinin kayıtlı olduğu dersleri ve istatistikleri al
try {
    $query = "SELECT c.*, u.full_name as teacher_name, u.email as teacher_email,
              ce.enrollment_date,
              (SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.course_id = c.id AND ats.is_active = 1) as total_sessions,
              (SELECT COUNT(*) FROM attendance_records ar 
               JOIN attendance_sessions ats ON ar.session_id = ats.id 
               WHERE ats.course_id = c.id AND ar.student_id = :student_id AND ar.second_phase_completed = 1) as attended_sessions,
              (SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.course_id = c.id AND ats.is_active = 1 AND DATE(ats.session_date) = CURDATE()) as today_sessions,
              (SELECT COUNT(*) FROM attendance_records ar 
               JOIN attendance_sessions ats ON ar.session_id = ats.id 
               WHERE ats.course_id = c.id AND ar.student_id = :student_id AND ar.second_phase_completed = 1 AND DATE(ats.session_date) = CURDATE()) as today_attended
              FROM courses c 
              JOIN course_enrollments ce ON c.id = ce.course_id 
              LEFT JOIN users u ON c.teacher_id = u.id
              WHERE ce.student_id = :student_id AND ce.is_active = 1 AND c.is_active = 1 
              ORDER BY c.course_name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Courses query error: ' . $e->getMessage());
    $courses = [];
}

// Seçilen ders için detaylı bilgiler
$course_detail = null;
$course_sessions = [];
$course_attendance = [];

if ($selected_course_id > 0) {
    // Dersin öğrenciye ait olup olmadığını kontrol et
    $course_detail = array_filter($courses, function($course) use ($selected_course_id) {
        return $course['id'] == $selected_course_id;
    });
    
    if (!empty($course_detail)) {
        $course_detail = reset($course_detail);
        
        // Ders oturumları
        try {
            $query = "SELECT ats.*, 
                      (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = ats.id AND ar.student_id = :student_id) as is_attended,
                      (SELECT ar.attendance_time FROM attendance_records ar WHERE ar.session_id = ats.id AND ar.student_id = :student_id AND ar.second_phase_completed = 1) as attendance_time
                      FROM attendance_sessions ats
                      WHERE ats.course_id = :course_id AND ats.is_active = 1
                      ORDER BY ats.session_date DESC, ats.start_time DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $selected_course_id, PDO::PARAM_INT);
            $stmt->execute();
            $course_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Course sessions error: ' . $e->getMessage());
        }
        
        // Öğrencinin bu dersteki katılım geçmişi
        try {
            $query = "SELECT ar.*, ats.session_name, ats.session_date, ats.start_time, ats.duration_minutes
                      FROM attendance_records ar
                      JOIN attendance_sessions ats ON ar.session_id = ats.id
                      WHERE ats.course_id = :course_id AND ar.student_id = :student_id
                      ORDER BY ats.session_date DESC, ats.start_time DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':course_id', $selected_course_id, PDO::PARAM_INT);
            $stmt->execute();
            $course_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Course attendance error: ' . $e->getMessage());
        }
    }
}

// Maksimum devamsızlık yüzdesi
$max_absence_percentage = (int)($site_settings['max_absence_percentage'] ?? 25);

// Set page title
$page_title = "Derslerim - ". htmlspecialchars($site_name);

// Include header
include '../includes/components/student_header.php';
?>

    <style>
        /* Custom styles for courses page */
        .course-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid #3498db;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .attendance-rate {
            font-size: 2rem;
            font-weight: bold;
        }
        .rate-excellent { color: #27ae60; }
        .rate-good { color: #f39c12; }
        .rate-warning { color: #e67e22; }
        .rate-danger { color: #e74c3c; }
        .session-attended {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
        }
        .session-missed {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .session-future {
            background-color: #e2e3e5;
            border-left: 4px solid #6c757d;
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
    </style>

            <?php if ($course_detail): ?>
                <!-- Update navbar title -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var navbar = document.querySelector('.navbar-brand');
                        if (navbar) {
                            navbar.textContent = '<?php echo htmlspecialchars($course_detail['course_name']); ?> - Detayları';
                        }
                    });
                </script>
            <?php endif; ?>
            <?php display_message(); ?>

            <?php if ($course_detail): ?>
                <!-- Course Detail View -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3>
                                    <i class="fas fa-book me-2"></i>
                                    <?php echo htmlspecialchars($course_detail['course_name']); ?>
                                </h3>
                                <p class="text-muted mb-0">
                                    <?php echo htmlspecialchars($course_detail['course_code']); ?> | 
                                    <?php echo htmlspecialchars($course_detail['teacher_name']); ?> | 
                                    <?php echo htmlspecialchars($course_detail['semester'] . ' - ' . $course_detail['academic_year']); ?>
                                </p>
                            </div>
                            <div>
                                <a href="course_materials.php?course_id=<?php echo $course_detail['id']; ?>" class="btn btn-info me-2">
                                    <i class="fas fa-video me-1"></i>Ders İçerikleri
                                </a>
                                <a href="courses.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Geri Dön
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course Statistics -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-primary"><?php echo $course_detail['total_sessions']; ?></h4>
                                <p class="mb-0">Toplam Oturum</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-success"><?php echo $course_detail['attended_sessions']; ?></h4>
                                <p class="mb-0">Katıldığım Oturum</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-danger"><?php echo max(0, $course_detail['total_sessions'] - $course_detail['attended_sessions']); ?></h4>
                                <p class="mb-0">Kaçırdığım Oturum</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <?php 
                                $attendance_rate = $course_detail['total_sessions'] > 0 ? 
                                    round(($course_detail['attended_sessions'] / $course_detail['total_sessions']) * 100, 1) : 100;
                                $rate_class = $attendance_rate >= 90 ? 'rate-excellent' : 
                                            ($attendance_rate >= 75 ? 'rate-good' : 
                                            ($attendance_rate >= 60 ? 'rate-warning' : 'rate-danger'));
                                ?>
                                <h4 class="<?php echo $rate_class; ?>">%<?php echo $attendance_rate; ?></h4>
                                <p class="mb-0">Devam Oranı</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Progress -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Devam Durumu
                                </h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="progress-circle" 
                                             style="--progress: <?php echo $attendance_rate; ?>; --progress-color: <?php echo $attendance_rate >= 75 ? '#28a745' : ($attendance_rate >= 60 ? '#ffc107' : '#dc3545'); ?>;">
                                            <div class="progress-circle-inner">
                                                <div>
                                                    <div class="h3 mb-0 <?php echo $rate_class; ?>"><?php echo $attendance_rate; ?>%</div>
                                                    <small class="text-muted">Devam</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="row text-start">
                                            <div class="col-6">
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="bg-success rounded" style="width: 12px; height: 12px; margin-right: 8px;"></div>
                                                    <span>Katıldım: <?php echo $course_detail['attended_sessions']; ?></span>
                                                </div>
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="bg-danger rounded" style="width: 12px; height: 12px; margin-right: 8px;"></div>
                                                    <span>Kaçırdım: <?php echo max(0, $course_detail['total_sessions'] - $course_detail['attended_sessions']); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="alert alert-<?php echo $attendance_rate >= (100 - $max_absence_percentage) ? 'success' : 'warning'; ?> mb-0">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    <strong>Durum:</strong> 
                                                    <?php if ($attendance_rate >= (100 - $max_absence_percentage)): ?>
                                                        Devam şartını sağlıyorsunuz
                                                    <?php else: ?>
                                                        Devam şartını sağlamıyorsunuz (Min. %<?php echo 100 - $max_absence_percentage; ?>)
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
                                    <i class="fas fa-list me-2"></i>Yoklama Oturumları
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($course_sessions)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">Bu ders için henüz yoklama oturumu oluşturulmamış.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="row">
                                        <?php foreach ($course_sessions as $session): ?>
                                            <?php
                                            $session_date = strtotime($session['session_date'] . ' ' . $session['start_time']);
                                            $now = time();
                                            $is_future = $session_date > $now;
                                            $is_attended = $session['is_attended'] > 0;
                                            
                                            $card_class = $is_future ? 'session-future' : ($is_attended ? 'session-attended' : 'session-missed');
                                            $status_icon = $is_future ? 'clock' : ($is_attended ? 'check-circle' : 'times-circle');
                                            $status_color = $is_future ? 'secondary' : ($is_attended ? 'success' : 'danger');
                                            ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card <?php echo $card_class; ?>">
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
                                                                <?php if ($is_attended && $session['attendance_time']): ?>
                                                                    <small class="text-muted">
                                                                        <i class="fas fa-check me-1"></i>
                                                                        Katılım: <?php echo date('d.m.Y H:i', strtotime($session['attendance_time'])); ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-center">
                                                                <i class="fas fa-<?php echo $status_icon; ?> fa-2x text-<?php echo $status_color; ?>"></i>
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

            <?php else: ?>
                <!-- Courses List -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h3>
                            <i class="fas fa-book me-2"></i>
                            Kayıtlı Olduğum Dersler
                        </h3>
                        <p class="text-muted">Aşağıda kayıtlı olduğunuz derslerin listesi ve devam durumunuz görüntülenmektedir.</p>
                    </div>
                </div>

                <?php if (empty($courses)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-book fa-4x text-muted mb-3"></i>
                                    <h4 class="text-muted">Henüz Kayıtlı Ders Yok</h4>
                                    <p class="text-muted">Şu anda hiçbir derse kayıtlı değilsiniz. Ders kaydı için sistem yöneticinize başvurun.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($courses as $course): ?>
                            <?php
                            $attendance_rate = $course['total_sessions'] > 0 ? 
                                round(($course['attended_sessions'] / $course['total_sessions']) * 100, 1) : 100;
                            $rate_class = $attendance_rate >= 90 ? 'rate-excellent' : 
                                        ($attendance_rate >= 75 ? 'rate-good' : 
                                        ($attendance_rate >= 60 ? 'rate-warning' : 'rate-danger'));
                            ?>
                            <div class="col-xl-4 col-lg-6 mb-4">
                                <div class="card course-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title text-primary">
                                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                                </h5>
                                                <p class="card-text text-muted mb-1">
                                                    <i class="fas fa-code me-1"></i>
                                                    <?php echo htmlspecialchars($course['course_code']); ?>
                                                </p>
                                                <p class="card-text text-muted mb-1">
                                                    <i class="fas fa-user-tie me-1"></i>
                                                    <?php echo htmlspecialchars($course['teacher_name']); ?>
                                                </p>
                                                <p class="card-text text-muted mb-3">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo htmlspecialchars($course['semester'] . ' - ' . $course['academic_year']); ?>
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
                                                    <div class="fw-bold"><?php echo $course['total_sessions']; ?></div>
                                                    <small>Toplam</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-success">
                                                    <i class="fas fa-check"></i>
                                                    <div class="fw-bold"><?php echo $course['attended_sessions']; ?></div>
                                                    <small>Katıldım</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-danger">
                                                    <i class="fas fa-times"></i>
                                                    <div class="fw-bold"><?php echo max(0, $course['total_sessions'] - $course['attended_sessions']); ?></div>
                                                    <small>Kaçırdım</small>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if ($course['today_sessions'] > 0): ?>
                                            <div class="alert alert-info mb-3">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <strong>Bugün:</strong> <?php echo $course['today_sessions']; ?> oturum var, 
                                                <?php echo $course['today_attended']; ?> tanesine katıldınız.
                                            </div>
                                        <?php endif; ?>

                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $attendance_rate >= 75 ? 'success' : ($attendance_rate >= 60 ? 'warning' : 'danger'); ?>" 
                                                 style="width: <?php echo $attendance_rate; ?>%"></div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                Kayıt: <?php echo date('d.m.Y', strtotime($course['enrollment_date'])); ?>
                                            </small>
                                            <div>
                                                <a href="course_materials.php?course_id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-info btn-sm me-1" title="Ders İçerikleri">
                                                    <i class="fas fa-video"></i>
                                                </a>
                                                <a href="courses.php?course_id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="Detaylar">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

<?php include '../includes/components/shared_footer.php'; ?>