<?php
// =================================================================
// INITIALIZATION & SECURITY
// =================================================================

// Increase server resources
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);

// Set default timezone
date_default_timezone_set('Europe/Istanbul');

try {
    // Include core functions
    require_once '../includes/functions.php';
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // --- Authentication & Authorization ---
    $auth = new Auth();
    if (!$auth->checkRole('student')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }
    
    // --- Database Connection ---
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    // Set MySQL timezone to match PHP
    $db->exec("SET time_zone = '+03:00'");
    
    // Get logged-in student's ID
    $student_id = $_SESSION['user_id'];

} catch (Exception $e) {
    error_log('Student Attendance Initialization Error: ' . $e->getMessage());
    // Use a user-friendly error message on production
    die('Sistemde kritik bir hata oluştu. Lütfen daha sonra tekrar deneyin veya sistem yöneticisine başvurun.');
}

// =================================================================
// SITE SETTINGS & CONFIGURATION
// =================================================================

try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $settings_from_db = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    error_log('Site Settings Load Error: ' . $e->getMessage());
    $settings_from_db = [];
}

// Assign settings with default fallbacks
$site_name = htmlspecialchars($settings_from_db['site_name'] ?? 'AhdaKade Yoklama Sistemi');
$site_logo = htmlspecialchars($settings_from_db['site_logo'] ?? '');
$site_favicon = htmlspecialchars($settings_from_db['site_favicon'] ?? '');
$qr_refresh_interval = (int)($settings_from_db['qr_refresh_interval'] ?? 15);

// Get session ID from URL
$session_id = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

// =================================================================
// POST REQUEST HANDLING (FORM SUBMISSIONS)
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $posted_session_id = (int)($_POST['session_id'] ?? 0);

        // --- PHASE 1: FIRST KEY SUBMISSION ---
        if ($action === 'first_key') {
            $first_key = trim($_POST['first_key'] ?? '');
            if (empty($first_key)) {
                throw new Exception('Birinci anahtar boş bırakılamaz.');
            }

            // Check if the session is valid, active, and the student is enrolled
            $query = "SELECT 1 FROM attendance_sessions asess
                      JOIN course_enrollments ce ON asess.course_id = ce.course_id
                      WHERE asess.id = :session_id AND ce.student_id = :student_id AND asess.status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':student_id' => $student_id]);
            if ($stmt->fetch() === false) {
                throw new Exception('Geçersiz veya aktif olmayan bir oturuma katılmaya çalıştınız.');
            }

            // Check if the student has already submitted a key for this session
            $query = "SELECT 1 FROM attendance_records WHERE session_id = :session_id AND student_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':student_id' => $student_id]);
            if ($stmt->fetch() !== false) {
                throw new Exception('Bu oturuma zaten katılım sağlamışsınız.');
            }

            // Check if the first key is valid and unused
            $query = "SELECT id as key_id FROM first_phase_keys 
                      WHERE session_id = :session_id AND key_code = :first_key AND is_used = 0";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':first_key' => $first_key]);
            $valid_key = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$valid_key) {
                throw new Exception('Birinci anahtar yanlış, süresi dolmuş veya daha önce kullanılmış.');
            }

            // Use a transaction for data integrity
            $db->beginTransaction();
            // Mark the key as used
            $query = "UPDATE first_phase_keys SET is_used = 1, used_by_student_id = :student_id, used_at = NOW() WHERE id = :key_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':student_id' => $student_id, ':key_id' => $valid_key['key_id']]);
            
            // Create the initial attendance record
            $query = "INSERT INTO attendance_records (session_id, student_id, first_phase_key_id, ip_address, user_agent) 
                      VALUES (:session_id, :student_id, :key_id, :ip_address, :user_agent)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':session_id' => $posted_session_id,
                ':student_id' => $student_id,
                ':key_id' => $valid_key['key_id'],
                ':ip_address' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            $db->commit();

            show_message('Birinci aşama başarıyla tamamlandı! Şimdi ikinci anahtarı bekleyin.', 'success');
            redirect("attendance.php?session_id={$posted_session_id}");
            exit;
        }

        // --- PHASE 2: SECOND KEY SUBMISSION ---
        elseif ($action === 'second_key') {
            $second_key = trim($_POST['second_key'] ?? '');
            if (empty($second_key)) {
                throw new Exception('İkinci anahtar boş bırakılamaz.');
            }

            // Check if the student has a pending phase 1 record
            $query = "SELECT 1 FROM attendance_records 
                      WHERE session_id = :session_id AND student_id = :student_id 
                      AND first_phase_key_id IS NOT NULL AND second_phase_completed = 0";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':student_id' => $student_id]);
            if ($stmt->fetch() === false) {
                 throw new Exception('İkinci anahtarı girmeden önce birinci aşamayı tamamlamanız veya bu oturumu zaten tamamlamış olmanız gerekiyor.');
            }

            // Check if the second key is valid and currently active
            $query = "SELECT 1 FROM second_phase_keys 
                      WHERE session_id = :session_id AND key_code = :second_key 
                      AND NOW() BETWEEN valid_from AND valid_until";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':second_key' => $second_key]);
            if ($stmt->fetch() === false) {
                throw new Exception('Girilen ikinci anahtar yanlış veya süresi dolmuş. Lütfen ekrandaki güncel anahtarı kullanın.');
            }

            // Finalize the attendance
            $query = "UPDATE attendance_records 
                      SET second_phase_completed = 1, attendance_time = NOW() 
                      WHERE session_id = :session_id AND student_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $posted_session_id, ':student_id' => $student_id]);
            
            show_message('Yoklama başarıyla tamamlandı! Devam durumunuz kaydedildi.', 'success');
            redirect('dashboard.php');
            exit;
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        show_message($e->getMessage(), 'danger');
        // Redirect back to the same page to show the error
        if ($session_id) {
            redirect("attendance.php?session_id={$session_id}");
            exit;
        }
    }
}

// =================================================================
// DATA FETCHING FOR PAGE DISPLAY (GET REQUEST)
// =================================================================

$session_info = null;
$attendance_status = null;
$active_sessions = [];

try {
    // If a specific session is selected, get its details
    if ($session_id) {
        $query = "SELECT asess.*, c.course_name, c.course_code, u.full_name as teacher_name
                  FROM attendance_sessions asess
                  JOIN courses c ON asess.course_id = c.id
                  JOIN course_enrollments ce ON c.id = ce.course_id
                  LEFT JOIN users u ON c.teacher_id = u.id
                  WHERE asess.id = :session_id AND ce.student_id = :student_id AND asess.status = 'active'
                  AND c.is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->execute([':session_id' => $session_id, ':student_id' => $student_id]);
        $session_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session_info) {
            // Get the student's current attendance status for this session
            $query = "SELECT ar.*, fpk.key_code as used_first_key
                      FROM attendance_records ar 
                      LEFT JOIN first_phase_keys fpk ON ar.first_phase_key_id = fpk.id
                      WHERE ar.session_id = :session_id AND ar.student_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $session_id, ':student_id' => $student_id]);
            $attendance_status = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // If no session is selected, get all active sessions for the student
        $query = "SELECT asess.id, asess.session_name, asess.start_time, asess.duration_minutes,
                         c.course_name, u.full_name as teacher_name,
                         (ar.second_phase_completed = 1) as is_attended
                  FROM attendance_sessions asess
                  JOIN courses c ON asess.course_id = c.id
                  JOIN course_enrollments ce ON c.id = ce.course_id
                  LEFT JOIN users u ON c.teacher_id = u.id
                  LEFT JOIN attendance_records ar ON asess.id = ar.session_id AND ar.student_id = ce.student_id
                  WHERE ce.student_id = :student_id 
                  AND c.is_active = 1
                  AND (asess.status = 'active' OR (asess.is_active = 1 AND asess.closed_at IS NULL))
                  ORDER BY asess.start_time DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $active_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Data Fetching Error: ' . $e->getMessage());
    show_message('Veriler yüklenirken bir hata oluştu.', 'danger');
}
// student/attendance.php

// ... (Ayarlar ve session kontrolleri bittikten sonra, POST işleminden önce) ...

// =================================================================
// AUTO ATTENDANCE VIA QR LINK (GET REQUEST)
// =================================================================

// URL'den gelen verileri kontrol et (örn: ?session_id=5&auto_key=XH92KL)
$auto_key = trim($_GET['auto_key'] ?? '');

if ($session_id && !empty($auto_key)) {
    try {
        // 1. Öğrenci bu derse kayıtlı mı ve oturum aktif mi?
        // (Aynı sorguları güvenlik için tekrar yapıyoruz)
        $query = "SELECT 1 FROM attendance_sessions asess
                  JOIN course_enrollments ce ON asess.course_id = ce.course_id
                  WHERE asess.id = :session_id AND ce.student_id = :student_id AND asess.status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->execute([':session_id' => $session_id, ':student_id' => $student_id]);
        
        if ($stmt->fetch() !== false) {
            
            // 2. Birinci aşama tamamlanmış mı?
            $query = "SELECT 1 FROM attendance_records 
                      WHERE session_id = :session_id AND student_id = :student_id 
                      AND first_phase_key_id IS NOT NULL AND second_phase_completed = 0";
            $stmt = $db->prepare($query);
            $stmt->execute([':session_id' => $session_id, ':student_id' => $student_id]);
            
            // Eğer birinci aşama tamamlanmışsa ve ikinci aşama henüz bitmemişse
            if ($stmt->fetch() !== false) {
                
                // 3. Anahtar geçerli mi?
                $query = "SELECT 1 FROM second_phase_keys 
                          WHERE session_id = :session_id AND key_code = :second_key 
                          AND NOW() BETWEEN valid_from AND valid_until";
                $stmt = $db->prepare($query);
                $stmt->execute([':session_id' => $session_id, ':second_key' => $auto_key]);
                
                if ($stmt->fetch() !== false) {
                    // 4. İşlemi Tamamla
                    $query = "UPDATE attendance_records 
                              SET second_phase_completed = 1, attendance_time = NOW() 
                              WHERE session_id = :session_id AND student_id = :student_id";
                    $stmt = $db->prepare($query);
                    $stmt->execute([':session_id' => $session_id, ':student_id' => $student_id]);
                    
                    show_message('QR Kod ile yoklama başarıyla tamamlandı!', 'success');
                    // URL'i temizlemek için redirect
                    redirect("dashboard.php"); 
                    exit;
                } else {
                    // Anahtar süresi dolmuşsa hata ver ama sayfada kal
                    show_message('QR Kodun süresi dolmuş, lütfen yenisini okutun.', 'warning');
                }
            } else {
                // 1. aşama yapılmamışsa veya zaten tamamlanmışsa
                 // Burada bir şey yapmaya gerek yok, normal akış devam etsin.
                 // Kullanıcı sayfada zaten durumu görecektir.
            }
        }
    } catch (Exception $e) {
        error_log('Auto QR Error: ' . $e->getMessage());
    }
}

// ... POST işlemleri buradan devam eder ...

$page_title = "Yoklama Oturumu - " . $site_name;
include '../includes/components/student_header.php';
?>

<!-- =================================================================
// STYLES & VIEW
// ================================================================= -->

<style>
    /* General card styling */
    .custom-card {
        border-radius: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border: none;
        transition: all 0.3s ease;
    }
    .custom-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        transform: translateY(-3px);
    }

    /* Dark card for the attendance form */
    .attendance-card {
        background: #2c3e50;
        color: white;
        border-radius: 15px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    }

    /* Key input styling */
    .key-input-lg {
        font-size: 2rem;
        text-align: center;
        font-weight: bold;
        letter-spacing: 5px;
        background-color: rgba(255, 255, 255, 0.1);
        border: 2px dashed #1abc9c;
        color: white;
        text-transform: uppercase;
    }
    .key-input-lg::placeholder { color: rgba(255, 255, 255, 0.4); }
    .key-input-lg:focus {
        background-color: rgba(255, 255, 255, 0.2);
        border-color: #f1c40f;
        box-shadow: 0 0 15px rgba(241, 196, 15, 0.5);
        color: white;
    }

    /* Submit button styling */
    .btn-submit-key {
        background: linear-gradient(45deg, #1abc9c, #16a085);
        color: white;
        border-radius: 50px;
        padding: 15px 40px;
        font-size: 1.2rem;
        font-weight: bold;
        border: none;
        transition: all 0.3s ease;
    }
    .btn-submit-key:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        color: white;
    }
    
    /* QR Scan button styling */
    .btn-qr-scan {
        background: linear-gradient(45deg, #9b59b6, #8e44ad);
        color: white;
        border-radius: 50px;
        padding: 15px 40px;
        font-size: 1.2rem;
        font-weight: bold;
        border: none;
        transition: all 0.3s ease;
    }
    .btn-qr-scan:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(155, 89, 182, 0.4);
        color: white;
    }
    
    /* Countdown progress bar */
    .progress-container {
        position: relative;
        height: 40px;
        background-color: rgba(0, 0, 0, 0.2);
        border-radius: 25px;
        overflow: hidden;
    }
    .progress-bar-custom {
        height: 100%;
        background: linear-gradient(45deg, #3498db, #2980b9);
        border-radius: 25px;
        transition: width 1s linear;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: bold;
        color: white;
    }
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($session_id && $session_info): // --- VIEW 1: SINGLE SESSION ATTENDANCE PROCESS --- ?>
        
        <!-- Session Information Header -->
        <div class="card custom-card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Yoklama Oturum Bilgileri</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Ders:</strong> <?php echo htmlspecialchars($session_info['course_name']); ?></p>
                        <p><strong>Öğretmen:</strong> <?php echo htmlspecialchars($session_info['teacher_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Oturum:</strong> <?php echo htmlspecialchars($session_info['session_name']); ?></p>
                        <p><strong>Tarih/Saat:</strong> <?php echo date('d.m.Y H:i', strtotime($session_info['start_time'])); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance State Machine -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <?php if (!$attendance_status): // State 1: Needs to enter First Key ?>
                    <div class="card attendance-card p-lg-5 p-4 text-center">
                        <form method="POST" id="firstKeyForm">
                            <input type="hidden" name="action" value="first_key">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <h2 class="mb-3">Birinci Aşama</h2>
                            <p class="text-white-50 mb-4">Lütfen öğretmeninizden aldığınız ilk anahtarı girin veya QR kodu tarayın.</p>
                            <div class="mb-4 mx-auto" style="max-width: 300px;">
                                <input type="text" name="first_key" id="first_key_input" class="form-control key-input-lg" placeholder="ANAHTAR" required autofocus>
                            </div>
                            <div class="d-flex justify-content-center gap-3 flex-wrap">
                                <button type="submit" class="btn btn-submit-key"><i class="fas fa-arrow-right me-2"></i>Onayla</button>
                                <button type="button" class="btn btn-qr-scan" onclick="startQRScanner('first')">
                                    <i class="fas fa-camera me-2"></i>QR Tara
                                </button>
                            </div>
                        </form>
                    </div>

                <?php elseif (!$attendance_status['second_phase_completed']): // State 2: Needs to enter Second Key ?>
                    <div class="card attendance-card p-lg-5 p-4 text-center">
                        <form method="POST" id="secondKeyForm">
                            <input type="hidden" name="action" value="second_key">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <div class="mb-4"><i class="fas fa-check-circle text-success fa-2x"></i></div>
                            <h2 class="mb-3">İkinci Aşama</h2>
                            <p class="text-white-50 mb-4">Harika! Şimdi ekrandaki güncel ikinci anahtarı girerek yoklamayı tamamlayın.</p>
                            
                            <div class="progress-container mb-4 mx-auto" style="max-width: 500px;">
                                <div id="countdown-progress" class="progress-bar-custom">
                                    <span id="countdown-timer">Öğretmen bekleniyor...</span>
                                </div>
                            </div>
                            
                            <div class="mb-4 mx-auto" style="max-width: 300px;">
                                <input type="text" name="second_key" id="second_key_input" class="form-control key-input-lg" placeholder="ANAHTAR" required autofocus>
                            </div>
                            
                            <div class="d-flex justify-content-center gap-3 flex-wrap">
                                <button type="submit" class="btn btn-submit-key"><i class="fas fa-paper-plane me-2"></i>Yoklamayı Tamamla</button>
                                <button type="button" class="btn btn-qr-scan" onclick="startQRScanner('second')">
                                    <i class="fas fa-camera me-2"></i>QR Tara
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: // State 3: Attendance Completed ?>
                    <div class="card custom-card p-lg-5 p-4 text-center">
                        <div class="mb-4"><i class="fas fa-check-circle text-success fa-4x"></i></div>
                        <h2 class="mb-3">Yoklama Tamamlandı!</h2>
                        <p class="text-muted mb-4">Bu dersteki devam durumunuz başarıyla kaydedildi.</p>
                        <p><strong>Tamamlanma Zamanı:</strong> <?php echo date('d.m.Y H:i:s', strtotime($attendance_status['attendance_time'])); ?></p>
                        <a href="dashboard.php" class="btn btn-primary mt-3"><i class="fas fa-home me-2"></i>Anasayfaya Dön</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    <?php else: // --- VIEW 2: LIST OF ACTIVE SESSIONS --- ?>
        
        <div class="card custom-card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-list me-2"></i>Aktif Yoklama Oturumları</h5>
            </div>
            <div class="card-body">
                <?php if (empty($active_sessions)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">Aktif Oturum Bulunmuyor</h4>
                        <p class="text-muted">Şu anda katılabileceğiniz bir yoklama oturumu yok.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($active_sessions as $session): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card custom-card h-100">
                                    <div class="card-body d-flex flex-column">
                                        <h6 class="card-title text-primary"><?php echo htmlspecialchars($session['course_name']); ?></h6>
                                        <p class="card-text small text-muted">
                                            <?php echo htmlspecialchars($session['session_name']); ?><br>
                                            <i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($session['teacher_name']); ?><br>
                                            <i class="fas fa-clock me-1"></i> <?php echo date('H:i', strtotime($session['start_time'])); ?>
                                        </p>
                                        <div class="mt-auto text-center">
                                            <?php if ($session['is_attended']): ?>
                                                <span class="btn btn-success disabled w-100"><i class="fas fa-check me-1"></i>Katıldınız</span>
                                            <?php else: ?>
                                                <a href="attendance.php?session_id=<?php echo $session['id']; ?>" class="btn btn-danger w-100">
                                                    <i class="fas fa-hand-paper me-1"></i>Yoklama Ver
                                                </a>
                                            <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-uppercase and focus on key inputs
    const keyInput = document.querySelector('.key-input-lg');
    if (keyInput) {
        keyInput.focus();
    }

    <?php if ($session_id && $session_info && $attendance_status && !$attendance_status['second_phase_completed']): ?>
    // --- Countdown and Key Check Mechanism for Phase 2 ---
    const progressBar = document.getElementById('countdown-progress');
    const timerDisplay = document.getElementById('countdown-timer');
    const refreshIntervalSeconds = <?php echo $qr_refresh_interval; ?>;
    let countdownInterval;

    function startCountdown() {
        clearInterval(countdownInterval);
        let timeLeft = refreshIntervalSeconds;

        countdownInterval = setInterval(() => {
            if (timeLeft <= 0) {
                // When timer hits zero, reset to waiting state and check for a new key soon
                clearInterval(countdownInterval);
                timerDisplay.textContent = 'Yeni anahtar bekleniyor...';
                progressBar.style.width = '0%';
                setTimeout(checkForKeyAndStart, 3000); // Check again after 3 seconds
            } else {
                const progress = (timeLeft / refreshIntervalSeconds) * 100;
                progressBar.style.width = progress + '%';
                timerDisplay.textContent = `Yeni Anahtar İçin: ${timeLeft}s`;
                timeLeft--;
            }
        }, 1000);
    }

    function checkForKeyAndStart() {
        // This function checks if the teacher has generated a key.
        // NOTE: This requires 'ajax/get_current_key.php' to exist and return JSON.
        fetch(`ajax/get_current_key.php?session_id=<?php echo $session_id; ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.key) {
                    console.log("Active key detected. Starting countdown.");
                    startCountdown();
                } else {
                    // If no key, wait and check again
                    console.log("No active key found. Checking again in 5 seconds.");
                    timerDisplay.textContent = 'Öğretmen bekleniyor...';
                    progressBar.style.width = '100%'; // Full bar indicates waiting
                    setTimeout(checkForKeyAndStart, 5000);
                }
            })
            .catch(error => {
                console.error('Error checking for key:', error);
                setTimeout(checkForKeyAndStart, 5000); // Retry on error
            });
    }

    // Initial check to start the process
    checkForKeyAndStart();
    <?php endif; ?>
});
</script>

<!-- QR Scanner Modal -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-labelledby="qrScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="qrScannerModalLabel">
                    <i class="fas fa-camera me-2"></i>QR Kod Tara
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="qr-reader" style="width: 100%; min-height: 300px;"></div>
                <div id="qr-reader-results" class="mt-3 text-center"></div>
                <p class="text-muted text-center mt-3 small">
                    <i class="fas fa-info-circle me-1"></i>
                    Kamerayı QR koda doğrultun. Otomatik olarak okunacak.
                </p>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopQRScanner()">
                    <i class="fas fa-times me-1"></i>İptal
                </button>
            </div>
        </div>
    </div>
</div>

<!-- HTML5 QR Code Library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
let html5QrCode = null;
let scannerType = 'second'; // 'first' or 'second'

function startQRScanner(type) {
    scannerType = type;
    
    // Modal'ı aç
    const modal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
    modal.show();
    
    // Modal açıldıktan sonra kamerayı başlat
    document.getElementById('qrScannerModal').addEventListener('shown.bs.modal', function() {
        initQRScanner();
    }, { once: true });
}

function initQRScanner() {
    const qrReaderElement = document.getElementById('qr-reader');
    const resultsElement = document.getElementById('qr-reader-results');
    
    if (!qrReaderElement) return;
    
    // Önceki scanner varsa durdur
    if (html5QrCode) {
        html5QrCode.stop().catch(err => console.log(err));
    }
    
    html5QrCode = new Html5Qrcode("qr-reader");
    
    const config = {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    };
    
    html5QrCode.start(
        { facingMode: "environment" }, // Arka kamera
        config,
        onScanSuccess,
        onScanFailure
    ).catch(err => {
        console.error("Kamera başlatılamadı:", err);
        resultsElement.innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Kamera erişimi sağlanamadı. Lütfen tarayıcı izinlerini kontrol edin.
            </div>
        `;
    });
}

function onScanSuccess(decodedText, decodedResult) {
    console.log("QR Tarandı:", decodedText);
    
    const resultsElement = document.getElementById('qr-reader-results');
    
    // Scanner'ı durdur
    stopQRScanner();
    
    // URL mi yoksa sadece kod mu kontrol et
    if (decodedText.includes('join_session_qr.php') || decodedText.includes('join_first_phase_qr.php')) {
        // Tam URL - direkt yönlendir
        resultsElement.innerHTML = `
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                QR kod okundu! Yönlendiriliyorsunuz...
            </div>
        `;
        
        // Modal'ı kapat ve yönlendir
        setTimeout(() => {
            window.location.href = decodedText;
        }, 500);
    } else {
        // Sadece anahtar kodu - forma yaz
        resultsElement.innerHTML = `
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                Anahtar okundu: <strong>${decodedText}</strong>
            </div>
        `;
        
        // İlgili input'a yaz
        const inputId = scannerType === 'first' ? 'first_key_input' : 'second_key_input';
        const inputElement = document.getElementById(inputId);
        
        if (inputElement) {
            inputElement.value = decodedText.toUpperCase();
        }
        
        // Modal'ı kapat
        setTimeout(() => {
            bootstrap.Modal.getInstance(document.getElementById('qrScannerModal')).hide();
        }, 1000);
    }
}

function onScanFailure(error) {
    // Tarama başarısız - sessizce devam et (her frame'de çağrılır)
}

function stopQRScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
            console.log("QR Scanner durduruldu");
        }).catch(err => {
            console.log("Scanner durdurma hatası:", err);
        });
    }
}

// Modal kapandığında scanner'ı durdur
document.getElementById('qrScannerModal')?.addEventListener('hidden.bs.modal', function() {
    stopQRScanner();
});
</script>

<?php include '../includes/components/shared_footer.php'; ?>
