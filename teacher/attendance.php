<?php
// Increase memory limit and execution time
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);
date_default_timezone_set('Europe/Istanbul');

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
    
    // MySQL timezone ayarı
    $db->exec("SET time_zone = '+03:00'");
    
    $teacher_id = $_SESSION['user_id'];
} catch (Exception $e) {
    error_log('Teacher Attendance Error: ' . $e->getMessage());
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
$site_name = $site_settings['site_name'] ?? 'Yoklama Sistemi';
$default_duration = (int)($site_settings['default_session_duration'] ?? 10);
$qr_refresh_interval = (int)($site_settings['qr_refresh_interval'] ?? 15);

// Actions
$action = $_GET['action'] ?? 'list';
$session_id = (int)($_GET['id'] ?? 0);

// Status güncellemelerini yap
function updateSessionStatuses($db) {
    try {
        // 1. Süresi dolmuş oturumları güncelle (is_active değişmez)
        $query = "UPDATE attendance_sessions 
                  SET status = 'expired',
                      expired_at = CASE WHEN expired_at IS NULL THEN NOW() ELSE expired_at END,
                      closed_at = CASE WHEN closed_at IS NULL THEN NOW() ELSE closed_at END
                  WHERE closed_at IS NULL 
                  AND status != 'expired'
                  AND NOW() > ADDTIME(CONCAT(session_date, ' ', start_time), CONCAT(duration_minutes, ':00'))";
        $db->exec($query);
        
        // 2. Henüz başlamamış oturumları güncelle
        $query = "UPDATE attendance_sessions 
                  SET status = 'future' 
                  WHERE closed_at IS NULL 
                  AND status != 'future'
                  AND NOW() < CONCAT(session_date, ' ', start_time)";
        $db->exec($query);
        
        // 3. Aktif oturumları güncelle (is_active=1 ve süre içinde)
        $query = "UPDATE attendance_sessions 
                  SET status = 'active' 
                  WHERE closed_at IS NULL 
                  AND is_active = 1
                  AND status != 'active'
                  AND NOW() >= CONCAT(session_date, ' ', start_time)
                  AND NOW() <= ADDTIME(CONCAT(session_date, ' ', start_time), CONCAT(duration_minutes, ':00'))";
        $db->exec($query);
        
        // 4. Pasif oturumları güncelle (is_active=0 ve süre içinde)
        $query = "UPDATE attendance_sessions 
                  SET status = 'inactive' 
                  WHERE closed_at IS NULL 
                  AND is_active = 0
                  AND status != 'inactive'
                  AND NOW() >= CONCAT(session_date, ' ', start_time)
                  AND NOW() <= ADDTIME(CONCAT(session_date, ' ', start_time), CONCAT(duration_minutes, ':00'))";
        $db->exec($query);
        
    } catch (Exception $e) {
        error_log('Update session statuses error: ' . $e->getMessage());
    }
}

// Oturum durumunu kontrol et
/* Removed duplicate getSessionStatus function */

// Kalan süreyi hesapla
function getRemainingTime($session) {
    if (!$session || $session['closed_at']) return 0;
    
    // Türkiye saati için zaman hesaplama
    $tz = new DateTimeZone('Europe/Istanbul');
    $now = new DateTime('now', $tz);
    $session_end = new DateTime($session['session_date'] . ' ' . $session['start_time'], $tz);
    $session_end->add(new DateInterval('PT' . $session['duration_minutes'] . 'M'));
    
    $remaining = $session_end->getTimestamp() - $now->getTimestamp();
    
    return max(0, $remaining);
}

// Random key generator
function generateRandomKey($length = 8) {
    return strtoupper(substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length));
}

// İkinci anahtar oluştur
function generateSecondPhaseKey($db, $session_id, $duration = 30) {
    try {
        // Oturum durumunu kontrol et
        $query = "SELECT * FROM attendance_sessions WHERE id = :session_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session || !$session['is_active'] || $session['closed_at']) {
            return false;
        }
        
        // Süre kontrolü - Türkiye saati
        $tz = new DateTimeZone('Europe/Istanbul');
        $now = new DateTime('now', $tz);
        $session_end = new DateTime($session['session_date'] . ' ' . $session['start_time'], $tz);
        $session_end->add(new DateInterval('PT' . $session['duration_minutes'] . 'M'));
        
        if ($now > $session_end) {
            return false;
        }
        
        // Eski anahtarları temizle
        $query = "DELETE FROM second_phase_keys WHERE session_id = :session_id AND valid_until < NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Yeni anahtar oluştur
        $key_code = generateRandomKey(6);
        $now = new DateTime();
        $valid_from = $now->format('Y-m-d H:i:s');
        
        // Anahtar süresini oturum bitiş zamanı ile sınırla
        $session_remaining = $session_end->getTimestamp() - $now->getTimestamp();
        $key_duration = min($duration, $session_remaining);
        
        if ($key_duration <= 0) return false;
        
        $valid_until = $now->add(new DateInterval('PT' . $key_duration . 'S'))->format('Y-m-d H:i:s');
        
        $query = "INSERT INTO second_phase_keys (session_id, key_code, valid_from, valid_until) 
                  VALUES (:session_id, :key_code, :valid_from, :valid_until)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
        $stmt->bindParam(':key_code', $key_code);
        $stmt->bindParam(':valid_from', $valid_from);
        $stmt->bindParam(':valid_until', $valid_until);
        $stmt->execute();
        
        return $key_code;
    } catch (Exception $e) {
        error_log('Generate second phase key error: ' . $e->getMessage());
        return false;
    }
}

// Her sayfa yüklendiğinde status güncellemesi
updateSessionStatuses($db);

// Öğretmenin derslerini al
$teacher_courses = [];
try {
    $query = "SELECT c.*, 
              (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as student_count
              FROM courses c 
              WHERE c.teacher_id = :teacher_id AND c.is_active = 1 
              ORDER BY c.course_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $teacher_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Teacher courses error: ' . $e->getMessage());
}

// Düzenlenecek oturum verilerini al
$edit_session = null;
if ($action === 'edit' && $session_id > 0) {
    try {
        $query = "SELECT * FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $edit_session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_session) {
            show_message('Düzenlenecek oturum bulunamadı.', 'danger');
            redirect('attendance.php');
            exit;
        }
    } catch (Exception $e) {
        error_log('Edit session fetch error: ' . $e->getMessage());
        show_message('Sistem hatası oluştu.', 'danger');
        redirect('attendance.php');
        exit;
    }
}

// POST Actions

function getSessionStatus($session) {
    if (!$session) return 'not_found';
    // Status sütununu kullan
    return $session['status'] ?? 'inactive';
}


// --- GÜNCELLENMİŞ POST İŞLEMLERİ BLOĞU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    try {
        // Yeni Oturum Oluşturma
        if ($post_action === 'create') {
            $course_id = (int)$_POST['course_id'];
            $session_name = trim($_POST['session_name']);
            $session_date = $_POST['session_date'];
            $start_time = $_POST['start_time'];
            $duration_minutes = (int)$_POST['duration_minutes'];
            
            if (empty($session_name) || empty($session_date) || empty($start_time) || $course_id <= 0) {
                throw new Exception('Lütfen tüm alanları doldurun.');
            }
            
            $db->beginTransaction();
            
            // Başlangıç durumunu hesapla
            $session_datetime = new DateTime($session_date . ' ' . $start_time);
            $now = new DateTime();
            $initial_status = 'inactive';
            if ($now < $session_datetime) {
                $initial_status = 'future';
            }
            
            // Oturum oluştur
            $query = "INSERT INTO attendance_sessions (course_id, teacher_id, session_name, session_date, start_time, duration_minutes, status) 
                      VALUES (:course_id, :teacher_id, :session_name, :session_date, :start_time, :duration_minutes, :status)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':course_id' => $course_id,
                ':teacher_id' => $teacher_id,
                ':session_name' => $session_name,
                ':session_date' => $session_date,
                ':start_time' => $start_time,
                ':duration_minutes' => $duration_minutes,
                ':status' => $initial_status
            ]);
            
            $new_session_id = $db->lastInsertId();
            
            // Birinci anahtar kodları oluştur
            $stmt = $db->prepare("SELECT COUNT(*) FROM course_enrollments WHERE course_id = :course_id AND is_active = 1");
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $student_count = $stmt->fetchColumn();
            
            $key_count = max(2, $student_count + 2); // En az 2, öğrenci sayısından 2 fazla anahtar oluştur
            
            $stmt = $db->prepare("INSERT INTO first_phase_keys (session_id, key_code) VALUES (:session_id, :key_code)");
            for ($i = 0; $i < $key_count; $i++) {
                $stmt->execute([
                    ':session_id' => $new_session_id,
                    ':key_code' => generateRandomKey(8)
                ]);
            }
            
            $db->commit();
            show_message('Yoklama oturumu başarıyla oluşturuldu.', 'success');
            redirect("attendance.php?action=manage&id={$new_session_id}");
            exit;
        } 
        
        // İkinci Anahtar Oluştur (AJAX)
        elseif ($post_action === 'generate_second_key') {
            header('Content-Type: application/json');
            $session_id = (int)$_POST['session_id'];
            
            // Oturum kontrolü
            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id");
            $stmt->execute([':session_id' => $session_id, ':teacher_id' => $teacher_id]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
                exit;
            }
            
            $status = getSessionStatus($session);
            $remaining = getRemainingTime($session);
            
            if ($status !== 'active' || $remaining <= 0) {
                echo json_encode(['success' => false, 'message' => 'Oturum aktif değil veya süresi dolmuş', 'expired' => true]);
                exit;
            }
            
            $new_key = generateSecondPhaseKey($db, $session_id, $qr_refresh_interval);
            
            if ($new_key) {
                echo json_encode([
                    'success' => true, 
                    'key' => $new_key, 
                    'expires_in' => min($qr_refresh_interval, $remaining),
                    'session_remaining' => $remaining
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Anahtar oluşturulamadı']);
            }
            exit;
        }
        
        // Oturum Durumunu Değiştir (Başlat/Durdur)
        elseif ($post_action === 'toggle_session') {
            $session_id = (int)$_POST['session_id'];
            $is_active = (int)$_POST['is_active'];
            
            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id");
            $stmt->execute([':session_id' => $session_id, ':teacher_id' => $teacher_id]);
            $current_session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_session) {
                throw new Exception('Oturum bulunamadı.');
            }
            
            $status = getSessionStatus($current_session);
            
            if ($status === 'future' && $is_active == 1) throw new Exception('Henüz zamanı gelmemiş oturum başlatılamaz.');
            if ($status === 'closed' && $is_active == 1) throw new Exception('Manuel olarak kapatılmış oturum tekrar başlatılamaz.');
            if ($status === 'expired' && $is_active == 1) throw new Exception('Süresi dolmuş oturum tekrar başlatılamaz.');
            
            $db->beginTransaction();
            
            // is_active ve status'ü güncelle
            $new_status = $status;
            if ($is_active == 1 && in_array($status, ['inactive', 'active'])) {
                $new_status = 'active';
            } elseif ($is_active == 0 && in_array($status, ['inactive', 'active'])) {
                $new_status = 'inactive';
            }
            
            $stmt = $db->prepare("UPDATE attendance_sessions SET is_active = :is_active, status = :status WHERE id = :session_id");
            $stmt->execute([':is_active' => $is_active, ':status' => $new_status, ':session_id' => $session_id]);
            
            if ($is_active == 0) { // Oturum durduruluyorsa
                $stmt = $db->prepare("UPDATE second_phase_keys SET valid_until = NOW() WHERE session_id = :session_id AND valid_until > NOW()");
                $stmt->execute([':session_id' => $session_id]);
            }
            
            $db->commit();
            $status_text = $is_active ? 'aktif' : 'pasif';
            show_message("Oturum durumu {$status_text} olarak güncellendi.", 'success');
            redirect("attendance.php?action=manage&id={$session_id}");
            exit;
        } 
        
        // Oturumu Kalıcı Olarak Kapat
        elseif ($post_action === 'close_session') {
            $session_id = (int)$_POST['session_id'];
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE attendance_sessions SET is_active = 1, status = 'closed', closed_at = NOW() WHERE id = :session_id AND teacher_id = :teacher_id");
            $stmt->execute([':session_id' => $session_id, ':teacher_id' => $teacher_id]);
            
            if ($stmt->rowCount() == 0) throw new Exception('Oturum bulunamadı veya kapatılamadı.');
            
            $stmt = $db->prepare("UPDATE second_phase_keys SET valid_until = NOW() WHERE session_id = :session_id AND valid_until > NOW()");
            $stmt->execute([':session_id' => $session_id]);
            
            $db->commit();
            show_message('Yoklama oturumu tamamen kapatıldı.', 'success');
            redirect("attendance.php");
            exit;
        } 
        
        // Oturum Bilgilerini Güncelle
        elseif ($post_action === 'update') {
            $session_id = (int)$_POST['session_id'];
            $session_name = trim($_POST['session_name']);
            $duration_minutes = (int)$_POST['duration_minutes'];
            
            if (empty($session_name) || $session_id <= 0) {
                throw new Exception('Lütfen gerekli alanları doldurun.');
            }
            
            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = :session_id AND teacher_id = :teacher_id");
            $stmt->execute([':session_id' => $session_id, ':teacher_id' => $teacher_id]);
            $existing_session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing_session) throw new Exception('Güncellenecek oturum bulunamadı.');
            
            $status = getSessionStatus($existing_session);
            
            if ($status === 'closed') throw new Exception('Kapatılmış oturum güncellenemez.');
            
            if (in_array($status, ['active', 'expired'])) {
                // Aktif veya süresi dolmuş oturumda sadece isim ve süre değiştirilebilir
                $stmt = $db->prepare("UPDATE attendance_sessions SET session_name = :session_name, duration_minutes = :duration_minutes WHERE id = :session_id");
                $stmt->execute([':session_name' => $session_name, ':duration_minutes' => $duration_minutes, ':session_id' => $session_id]);
                show_message('Oturum adı ve süresi güncellendi.', 'success');
            } else {
                // Pasif veya gelecek oturumlarda tüm bilgiler değiştirilebilir
                $session_date = $_POST['session_date'] ?? '';
                $start_time = $_POST['start_time'] ?? '';
                if (empty($session_date) || empty($start_time)) {
                    throw new Exception('Tarih ve saat alanları zorunludur.');
                }
                
                $stmt = $db->prepare("UPDATE attendance_sessions SET session_name = :session_name, session_date = :session_date, start_time = :start_time, duration_minutes = :duration_minutes WHERE id = :session_id");
                $stmt->execute([
                    ':session_name' => $session_name,
                    ':session_date' => $session_date,
                    ':start_time' => $start_time,
                    ':duration_minutes' => $duration_minutes,
                    ':session_id' => $session_id
                ]);
                show_message('Oturum bilgileri başarıyla güncellendi.', 'success');
            }
            
            redirect("attendance.php?action=manage&id={$session_id}");
            exit;
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        show_message($e->getMessage(), 'danger');
        // Hata durumunda, formun olduğu sayfaya geri yönlendirme (isteğe bağlı)
        $redirect_url = in_array($action, ['edit', 'create']) ? "attendance.php?action={$action}&id={$session_id}" : "attendance.php";
        redirect($redirect_url);
        exit;
    }
}
// Oturum detaylarını al
$session_detail = null;
$session_stats = null;
$first_phase_keys = [];
$current_second_key = null;
$attendees = [];
$session_remaining_time = 0;

if ($action === 'manage' && $session_id > 0) {
    try {
        // Oturum detayı
        $query = "SELECT asess.*, c.course_name, c.course_code,
                  (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as total_students
                  FROM attendance_sessions asess
                  JOIN courses c ON asess.course_id = c.id
                  WHERE asess.id = :session_id AND asess.teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $session_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session_detail) {
            $session_remaining_time = getRemainingTime($session_detail);
            $session_status = getSessionStatus($session_detail);
            
            // İstatistikler
            $query = "SELECT 
                      COUNT(ar.id) as total_attendees,
                      COUNT(CASE WHEN ar.second_phase_completed = 1 THEN 1 END) as completed_attendees,
                      COUNT(CASE WHEN ar.second_phase_completed = 0 THEN 1 END) as pending_attendees
                      FROM attendance_records ar
                      WHERE ar.session_id = :session_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $stmt->execute();
            $session_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Birinci anahtar kodları
            $show_all_keys = isset($_GET['show_all']) && $_GET['show_all'] == '1';
            
            if ($show_all_keys) {
                $query = "SELECT fpk.*, u.full_name as used_by_name 
                          FROM first_phase_keys fpk
                          LEFT JOIN users u ON fpk.used_by_student_id = u.id
                          WHERE fpk.session_id = :session_id
                          ORDER BY fpk.is_used DESC, fpk.created_at ASC";
            } else {
                $query = "SELECT fpk.*, u.full_name as used_by_name 
                          FROM first_phase_keys fpk
                          LEFT JOIN users u ON fpk.used_by_student_id = u.id
                          WHERE fpk.session_id = :session_id AND fpk.is_used = 1
                          ORDER BY fpk.used_at DESC";
            }
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $stmt->execute();
            $first_phase_keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Aktif ikinci anahtar
            $query = "SELECT * FROM second_phase_keys 
                      WHERE session_id = :session_id AND valid_until > NOW()
                      ORDER BY created_at DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $stmt->execute();
            $current_second_key = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Eğer aktif ve ikinci anahtar yoksa oluştur
            if ($session_detail['is_active'] && !$session_detail['closed_at'] && !$current_second_key && $session_remaining_time > 0) {
                $new_key = generateSecondPhaseKey($db, $session_id, $qr_refresh_interval);
                if ($new_key) {
                    $stmt->execute();
                    $current_second_key = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
            
            // Katılımcılar
            $query = "SELECT u.full_name, u.student_number, ar.attendance_time, ar.second_phase_completed,
                      fpk.key_code as used_first_key
                      FROM attendance_records ar
                      JOIN users u ON ar.student_id = u.id
                      LEFT JOIN first_phase_keys fpk ON ar.first_phase_key_id = fpk.id
                      WHERE ar.session_id = :session_id
                      ORDER BY ar.attendance_time DESC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $stmt->execute();
            $attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Session detail error: ' . $e->getMessage());
    }
}

// Öğretmenin oturumlarını al
$teacher_sessions = [];
if ($action === 'list') {
    try {
        // Aktif/güncel/gelecek oturumlar için filtre
        $filter = $_GET['filter'] ?? 'all';
        
        $query = "SELECT asess.*, c.course_name, c.course_code,
                  (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.is_active = 1) as total_students,
                  (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = asess.id AND ar.second_phase_completed = 1) as completed_attendees
                  FROM attendance_sessions asess
                  JOIN courses c ON asess.course_id = c.id
                  WHERE asess.teacher_id = :teacher_id AND c.is_active = 1";
        
        if ($filter === 'active') {
            $query .= " AND asess.is_active = 1 AND asess.closed_at IS NULL";
        } elseif ($filter === 'today') {
            $query .= " AND DATE(asess.session_date) = CURDATE()";
        } elseif ($filter === 'future') {
            $query .= " AND CONCAT(asess.session_date, ' ', asess.start_time) > NOW()";
        } elseif ($filter === 'past') {
            $query .= " AND CONCAT(asess.session_date, ' ', asess.start_time) < NOW()";
        }
        
        $query .= " ORDER BY asess.session_date DESC, asess.start_time DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $teacher_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Teacher sessions error: ' . $e->getMessage());
    }
}

$page_title = "Yoklama Yönetimi - ". htmlspecialchars($site_name);

// Include header
include '../includes/components/teacher_header.php';
?>

<style>
    .session-card {
        border-radius: 15px;
        border: none;
        transition: all 0.3s ease;
        overflow: hidden;
    }
    .session-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .session-card.active {
        border-left: 5px solid #27ae60;
    }
    .session-card.inactive {
        border-left: 5px solid #6c757d;
        opacity: 0.7;
    }
    .session-card.expired {
        border-left: 5px solid #dc3545;
        opacity: 0.8;
    }
    .session-card.closed {
        border-left: 5px solid #343a40;
        opacity: 0.6;
    }
    .session-card.future {
        border-left: 5px solid #17a2b8;
    }
    .key-display {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        font-size: 2rem;
        font-weight: bold;
        letter-spacing: 0.5rem;
        margin-bottom: 15px;
        animation: pulse-glow 2s infinite;
    }
    .key-display.expired {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        animation: none;
    }
    @keyframes pulse-glow {
        0% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(102, 126, 234, 0); }
        100% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0); }
    }
    .session-timer {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 10px;
        text-align: center;
        margin-bottom: 15px;
    }
    .session-timer.expired {
        background: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }
    .session-timer.warning {
        background: #fff3cd;
        border-color: #ffc107;
        color: #856404;
    }
    .countdown-timer {
        font-size: 1.2rem;
        font-weight: bold;
        color: #e74c3c;
    }
    .session-countdown {
        font-size: 1.1rem;
        font-weight: bold;
    }
    .stats-card {
        background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
        color: white;
        border-radius: 15px;
        border: none;
    }
    .attendee-item {
        border-left: 4px solid #dee2e6;
        background: #f8f9fa;
        border-radius: 0 8px 8px 0;
        transition: all 0.3s ease;
    }
    .attendee-item.completed {
        border-left-color: #28a745;
        background: #d4edda;
    }
    .attendee-item.pending {
        border-left-color: #ffc107;
        background: #fff3cd;
    }
    .qr-code-container {
        background: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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

<?php display_message(); ?>

<?php if ($action === 'manage' && $session_detail): ?>
    <!-- Session Timer Display -->
    <?php 
    $status = getSessionStatus($session_detail);
    if ($session_detail['is_active'] && in_array($status, ['active', 'expired'])): 
    ?>
        <div class="session-timer <?php echo $session_remaining_time <= 0 ? 'expired' : ($session_remaining_time <= 300 ? 'warning' : ''); ?>">
            <?php if ($session_remaining_time <= 0): ?>
                <i class="fas fa-clock text-danger me-2"></i>
                <strong>Oturum Süresi Doldu!</strong>
                <small class="d-block mt-1">Sistem otomatik olarak oturumu sonlandıracak</small>
            <?php elseif ($session_remaining_time <= 300): ?>
                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                <strong>Oturum Yakında Sona Erecek!</strong>
                <span class="session-countdown ms-2" id="session-countdown"><?php echo gmdate('i:s', $session_remaining_time); ?></span>
            <?php else: ?>
                <i class="fas fa-clock text-success me-2"></i>
                <strong>Kalan Süre:</strong>
                <span class="session-countdown ms-2" id="session-countdown"><?php echo gmdate('i:s', $session_remaining_time); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Session Management -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3><?php echo htmlspecialchars($session_detail['session_name']); ?>
                        <?php 
                        $badge_class = [
                            'active' => 'bg-success',
                            'inactive' => 'bg-secondary', 
                            'expired' => 'bg-danger',
                            'closed' => 'bg-dark',
                            'future' => 'bg-info'
                        ][$status] ?? 'bg-secondary';
                        $status_text = [
                            'active' => 'Aktif',
                            'inactive' => 'Pasif',
                            'expired' => 'Süresi Doldu', 
                            'closed' => 'Kapatıldı',
                            'future' => 'Beklemede'
                        ][$status] ?? 'Bilinmiyor';
                        ?>
                        <span class="badge <?php echo $badge_class; ?> ms-2"><?php echo $status_text; ?></span>
                    </h3>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($session_detail['course_name']); ?> • 
                        <?php echo date('d.m.Y H:i', strtotime($session_detail['session_date'] . ' ' . $session_detail['start_time'])); ?> • 
                        <?php echo $session_detail['duration_minutes']; ?> dakika
                    </p>
                </div>
                <div>
                    <?php if (!$session_detail['closed_at']): ?>
                        <?php $can_toggle = in_array($status, ['active', 'inactive']); ?>
                        <?php if ($can_toggle): ?>
                            <form method="POST" class="d-inline" action="attendance.php">
                                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $session_detail['is_active'] ? 0 : 1; ?>">
                                <input type="hidden" name="action" value="toggle_session">
                                <button type="submit" 
                                        class="btn btn-<?php echo $session_detail['is_active'] ? 'warning' : 'success'; ?>"
                                        <?php echo ($status === 'future' && !$session_detail['is_active']) ? 'disabled title="Henüz zamanı gelmemiş oturum başlatılamaz"' : ''; ?>>
                                    <i class="fas fa-<?php echo $session_detail['is_active'] ? 'pause' : 'play'; ?> me-1"></i>
                                    <?php echo $session_detail['is_active'] ? 'Durdur' : 'Başlat'; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <a href="attendance.php?action=edit&id=<?php echo $session_id; ?>" class="btn btn-warning ms-2">
                        <i class="fas fa-edit me-1"></i>Düzenle
                    </a>
                    
                    <?php if ($status !== 'closed'): ?>
                    <form method="POST" class="d-inline" action="attendance.php" onsubmit="return confirm('Yoklamayı tamamen kapatmak istediğinizden emin misiniz? Bu işlem geri alınamaz.')">
                        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                        <input type="hidden" name="action" value="close_session">
                        <button type="submit" class="btn btn-danger ms-2">
                            <i class="fas fa-times me-1"></i>Yoklamayı Kapat
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <a href="attendance.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left me-1"></i>Geri
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Session Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <h3><?php echo $session_detail['total_students']; ?></h3>
                    <p class="mb-0">Toplam Öğrenci</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <h3><?php echo $session_stats['completed_attendees']; ?></h3>
                    <p class="mb-0">Tamamlanan</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <h3><?php echo $session_stats['pending_attendees']; ?></h3>
                    <p class="mb-0">Bekleyen</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <?php $completion_rate = $session_detail['total_students'] > 0 ? round(($session_stats['completed_attendees'] / $session_detail['total_students']) * 100, 1) : 0; ?>
                    <h3><?php echo $completion_rate; ?>%</h3>
                    <p class="mb-0">Tamamlanma</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- İkinci Anahtar + QR Kod (Yan Yana) -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-key me-2"></i>Anlık Yoklama Kodu
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <!-- Sol: İkinci Anahtar -->
                        <div class="col-md-6 text-center border-end">
                            <?php if ($status === 'future'): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-clock me-2"></i>
                                    Oturum henüz başlamadı. Başlama zamanı: 
                                    <?php echo date('d.m.Y H:i', strtotime($session_detail['session_date'] . ' ' . $session_detail['start_time'])); ?>
                                </div>
                            <?php else: ?>
                                <div id="second-key-display" class="key-display <?php echo ($session_remaining_time <= 0 || $status === 'expired' ? 'expired' : ''); ?>" style="font-size: 3rem; font-weight: bold; letter-spacing: 5px;">
                                    <?php 
                                    if ($status === 'expired' || $session_remaining_time <= 0) {
                                        echo 'SÜRESİ DOLDU';
                                    } elseif (!$session_detail['is_active']) {
                                        echo 'OTURUM PASİF';
                                    } elseif ($current_second_key) {
                                        echo $current_second_key['key_code'];
                                    } else {
                                        echo 'BEKLIYOR';
                                    }
                                    ?>
                                </div>
                                <div class="countdown-timer my-3">
                                    <i class="fas fa-clock me-1"></i>
                                    <span id="countdown" class="fs-4">--:--</span>
                                </div>
                                <?php if ($session_detail['is_active'] && $session_remaining_time > 0 && $status === 'active'): ?>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="button" class="btn btn-primary" onclick="generateSecondKey()">
                                            <i class="fas fa-sync-alt me-1"></i>Yenile
                                        </button>
                                        <button type="button" class="btn btn-info" onclick="copyKeyToClipboard()">
                                            <i class="fas fa-copy me-1"></i>Kopyala
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">
                                        <?php 
                                        if ($status === 'expired' || $session_remaining_time <= 0) {
                                            echo 'Oturum süresi doldu.';
                                        } elseif (!$session_detail['is_active']) {
                                            echo 'Oturum aktif değil.';
                                        }
                                        ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Sağ: QR Kod -->
                        <div class="col-md-6 text-center">
                            <div class="qr-code-container">
                                <div id="qr-code" class="d-inline-block mb-2"></div>
                                <p class="mb-2">
                                    <small class="text-muted">Öğrenciler bu QR'ı okutarak<br>ikinci aşamayı tamamlayabilir</small>
                                </p>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadQRCode()">
                                    <i class="fas fa-download me-1"></i>QR İndir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Birinci Anahtar Kodları (Ayrı Satır) -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-key me-2"></i>Birinci Anahtar Kodları
                        <?php if (!$show_all_keys): ?>
                            <span class="badge bg-info ms-2">Sadece Kullanılanlar</span>
                        <?php endif; ?>
                    </h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="downloadKeysPDF()">
                            <i class="fas fa-file-pdf me-1"></i>PDF İndir
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportKeysToExcel()">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                        <?php if ($show_all_keys): ?>
                            <a href="?action=manage&id=<?php echo $session_id; ?>" class="btn btn-sm btn-outline-info ms-2">
                                <i class="fas fa-filter me-1"></i>Sadece Kullanılanlar
                            </a>
                        <?php else: ?>
                            <a href="?action=manage&id=<?php echo $session_id; ?>&show_all=1" class="btn btn-sm btn-outline-info ms-2">
                                <i class="fas fa-eye me-1"></i>Tümünü Göster
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($first_phase_keys)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-key fa-2x text-muted mb-2"></i>
                            <p class="text-muted">
                                <?php echo $show_all_keys ? 'Henüz anahtar oluşturulmamış.' : 'Henüz kullanılmış anahtar bulunmamaktadır.'; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Anahtar</th>
                                        <th>QR</th>
                                        <th>Kullanan</th>
                                        <th>Kullanım Zamanı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($first_phase_keys as $key): ?>
                                        <tr>
                                            <td>
                                                <code class="text-primary fw-bold"><?php echo $key['key_code']; ?></code>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm <?php echo $key['is_used'] ? 'btn-success' : 'btn-outline-dark'; ?>" 
                                                        onclick="showFirstPhaseQR('<?php echo $key['key_code']; ?>')"
                                                        title="QR Kod Göster">
                                                    <i class="fas fa-qrcode"></i>
                                                </button>
                                            </td>
                                            <td>
                                                <?php if ($key['is_used']): ?>
                                                    <i class="fas fa-check-circle text-success me-1"></i>
                                                    <?php echo htmlspecialchars($key['used_by_name'] ?? 'Bilinmiyor'); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($key['is_used'] && $key['used_at']): ?>
                                                    <?php echo date('H:i:s', strtotime($key['used_at'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
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

    <!-- Attendees List -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-users me-2"></i>Katılımcılar 
                        <span class="badge bg-primary ms-2"><?php echo count($attendees); ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($attendees)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-clock fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz katılımcı bulunmuyor.</p>
                        </div>
                    <?php else: ?>
                        <div id="attendees-list">
                            <?php foreach ($attendees as $attendee): ?>
                                <div class="attendee-item <?php echo $attendee['second_phase_completed'] ? 'completed' : 'pending'; ?> p-3 mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($attendee['full_name']); ?></strong>
                                            <small class="text-muted ms-2">(<?php echo htmlspecialchars($attendee['student_number']); ?>)</small>
                                            <br>
                                            <small class="text-muted">
                                                Anahtar: <?php echo htmlspecialchars($attendee['used_first_key']); ?> • 
                                                <?php echo date('H:i:s', strtotime($attendee['attendance_time'])); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($attendee['second_phase_completed']): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check me-1"></i>Tamamlandı
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="fas fa-clock me-1"></i>1. Aşama
                                                </span>
                                            <?php endif; ?>
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

<?php elseif ($action === 'create'): ?>
    <!-- Create New Session -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus me-2"></i>Yeni Yoklama Oturumu Oluştur
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="attendance.php">
                        <input type="hidden" name="action" value="create">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="course_id" class="form-label">Ders Seçin</label>
                                    <select class="form-select" id="course_id" name="course_id" required>
                                        <option value="">Ders seçiniz...</option>
                                        <?php foreach ($teacher_courses as $course): ?>
                                            <option value="<?php echo $course['id']; ?>">
                                                <?php echo htmlspecialchars($course['course_name']); ?> 
                                                (<?php echo $course['student_count']; ?> öğrenci)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="session_name" class="form-label">Oturum Adı</label>
                                    <input type="text" class="form-control" id="session_name" name="session_name" 
                                           placeholder="Örn: 1. Hafta Yoklaması" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="session_date" class="form-label">Tarih</label>
                                    <input type="date" class="form-control" id="session_date" name="session_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="start_time" class="form-label">Başlangıç Saati</label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" 
                                           value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="duration_minutes" class="form-label">Süre (Dakika)</label>
                                    <select class="form-select" id="duration_minutes" name="duration_minutes" required>
                                        <option value="5">5 Dakika</option>
                                        <option value="10" <?php echo $default_duration == 10 ? 'selected' : ''; ?>>10 Dakika</option>
                                        <option value="15">15 Dakika</option>
                                        <option value="20">20 Dakika</option>
                                        <option value="30">30 Dakika</option>
                                        <option value="45">45 Dakika</option>
                                        <option value="60">60 Dakika</option>
                                        <option value="90">90 Dakika</option>
                                        <option value="120">120 Dakika</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="attendance.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Geri
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>Oturum Oluştur
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($action === 'edit' && $edit_session): ?>
    <!-- Edit Session -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-edit me-2"></i>Yoklama Oturumu Düzenle
                    </h5>
                </div>
                <div class="card-body">
                    <?php 
                    $edit_status = getSessionStatus($edit_session);
                    if (in_array($edit_status, ['active', 'expired'])): 
                    ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Dikkat:</strong> Bu oturum <?php echo $edit_status === 'active' ? 'aktif' : 'süresi dolmuş'; ?> olduğu için sadece oturum adı ve süre değiştirilebilir.
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="attendance.php">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="session_id" value="<?php echo $edit_session['id']; ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="session_name" class="form-label">Oturum Adı</label>
                                    <input type="text" class="form-control" id="session_name" name="session_name" 
                                           value="<?php echo htmlspecialchars($edit_session['session_name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="duration_minutes" class="form-label">Süre (Dakika)</label>
                                    <select class="form-select" id="duration_minutes" name="duration_minutes" required>
                                        <?php foreach ([5, 10, 15, 20, 30, 45, 60, 90, 120] as $duration): ?>
                                            <option value="<?php echo $duration; ?>" <?php echo $edit_session['duration_minutes'] == $duration ? 'selected' : ''; ?>>
                                                <?php echo $duration; ?> Dakika
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!in_array($edit_status, ['active', 'expired'])): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="session_date" class="form-label">Tarih</label>
                                    <input type="date" class="form-control" id="session_date" name="session_date" 
                                           value="<?php echo $edit_session['session_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_time" class="form-label">Başlangıç Saati</label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" 
                                           value="<?php echo $edit_session['start_time']; ?>" required>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="session_date" value="<?php echo htmlspecialchars($edit_session['session_date']); ?>">
                        <input type="hidden" name="start_time" value="<?php echo htmlspecialchars($edit_session['start_time']); ?>">
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between">
                            <div>
                                <a href="attendance.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Geri
                                </a>
                                <a href="attendance.php?action=manage&id=<?php echo $edit_session['id']; ?>" class="btn btn-info ms-2">
                                    <i class="fas fa-cog me-1"></i>Oturum Yönetimi
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

<?php else: ?>
    <!-- Sessions List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h3>
                    <i class="fas fa-calendar-check me-2"></i>
                    Yoklama Oturumları
                </h3>
                <div>
                    <!-- Filter buttons -->
                    <div class="btn-group me-2" role="group">
                        <a href="?filter=all" class="btn btn-sm btn-outline-secondary <?php echo (!isset($_GET['filter']) || $_GET['filter'] === 'all') ? 'active' : ''; ?>">Tümü</a>
                        <a href="?filter=active" class="btn btn-sm btn-outline-secondary <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'active') ? 'active' : ''; ?>">Aktif</a>
                        <a href="?filter=today" class="btn btn-sm btn-outline-secondary <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'today') ? 'active' : ''; ?>">Bugün</a>
                        <a href="?filter=future" class="btn btn-sm btn-outline-secondary <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'future') ? 'active' : ''; ?>">Gelecek</a>
                        <a href="?filter=past" class="btn btn-sm btn-outline-secondary <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'past') ? 'active' : ''; ?>">Geçmiş</a>
                    </div>
                    <a href="attendance.php?action=create" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>Yeni Oturum Oluştur
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <?php if (empty($teacher_sessions)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">Henüz Oturum Yok</h4>
                        <p class="text-muted">İlk yoklama oturumunuzu oluşturun.</p>
                        <a href="attendance.php?action=create" class="btn btn-success">
                            <i class="fas fa-plus me-1"></i>İlk Oturumu Oluştur
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($teacher_sessions as $session): ?>
                        <?php 
                        $session_status = getSessionStatus($session);
                        $card_class = [
                            'active' => 'active',
                            'inactive' => 'inactive',
                            'expired' => 'expired',
                            'closed' => 'closed',
                            'future' => 'future'
                        ][$session_status] ?? 'inactive';
                        ?>
                        <div class="col-xl-4 col-lg-6 mb-4">
                            <div class="session-card card h-100 <?php echo $card_class; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title text-success">
                                                <?php echo htmlspecialchars($session['session_name']); ?>
                                            </h5>
                                            <p class="card-text text-muted mb-1">
                                                <i class="fas fa-book me-1"></i>
                                                <?php echo htmlspecialchars($session['course_name']); ?>
                                            </p>
                                            <p class="card-text text-muted mb-1">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo date('d.m.Y', strtotime($session['session_date'])); ?>
                                            </p>
                                            <p class="card-text text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('H:i', strtotime($session['start_time'])); ?> 
                                                (<?php echo $session['duration_minutes']; ?> dk)
                                            </p>
                                        </div>
                                        <div class="text-center">
                                            <?php 
                                            $badge_class = [
                                                'active' => 'bg-success',
                                                'inactive' => 'bg-secondary',
                                                'expired' => 'bg-danger', 
                                                'closed' => 'bg-dark',
                                                'future' => 'bg-info'
                                            ][$session_status] ?? 'bg-secondary';
                                            $status_text = [
                                                'active' => 'Aktif',
                                                'inactive' => 'Pasif',
                                                'expired' => 'Süresi Doldu',
                                                'closed' => 'Kapatıldı',
                                                'future' => 'Beklemede'
                                            ][$session_status] ?? 'Bilinmiyor';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> mb-2"><?php echo $status_text; ?></span>
                                        </div>
                                    </div>

                                    <div class="row text-center mb-3">
                                        <div class="col-6">
                                            <div class="text-primary">
                                                <i class="fas fa-users"></i>
                                                <div class="fw-bold"><?php echo $session['total_students']; ?></div>
                                                <small>Toplam</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-success">
                                                <i class="fas fa-check"></i>
                                                <div class="fw-bold"><?php echo $session['completed_attendees']; ?></div>
                                                <small>Katılan</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="progress mb-3" style="height: 8px;">
                                        <?php $rate = $session['total_students'] > 0 ? ($session['completed_attendees'] / $session['total_students']) * 100 : 0; ?>
                                        <div class="progress-bar bg-success" style="width: <?php echo $rate; ?>%"></div>
                                    </div>

                                    <div class="d-flex justify-content-between">
                                        <small class="text-muted">
                                            <?php echo date('d.m.Y H:i', strtotime($session['created_at'])); ?>
                                        </small>
                                        <div>
                                            <a href="attendance.php?action=edit&id=<?php echo $session['id']; ?>" class="btn btn-warning btn-sm me-1">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="attendance.php?action=manage&id=<?php echo $session['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-cog me-1"></i>Yönet
                                            </a>
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

<!-- Birinci Aşama QR Modal -->
<div class="modal fade" id="firstPhaseQRModal" tabindex="-1" aria-labelledby="firstPhaseQRModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="firstPhaseQRModalLabel">
                    <i class="fas fa-qrcode me-2"></i>Birinci Aşama QR Kod
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body text-center">
                <div id="first-phase-qr-container" class="mb-3"></div>
                <p class="mb-1"><strong>Anahtar Kodu:</strong></p>
                <code id="first-phase-key-display" class="fs-4 text-primary"></code>
                <p class="text-muted mt-3 small">
                    Öğrenci bu QR'ı okutarak birinci aşamayı tamamlayabilir.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    <?php if ($action === 'manage' && $session_detail): ?>
    let countdownInterval;
    let sessionCountdownInterval;
    let keyExpiration = <?php echo $current_second_key ? strtotime($current_second_key['valid_until']) : 0; ?>;
    let sessionRemainingTime = <?php echo $session_remaining_time; ?>;
    const refreshInterval = <?php echo $qr_refresh_interval; ?>;
    const sessionId = <?php echo $session_id; ?>;
    let sessionExpired = <?php echo $session_remaining_time <= 0 ? 'true' : 'false'; ?>;
    const sessionStatus = '<?php echo $status; ?>';
    
    // PHP'den mevcut ikinci anahtar değerini al
    const initialSecondKey = '<?php echo $current_second_key ? $current_second_key['key_code'] : ''; ?>';
    
    // Sayfa yüklendiğinde hemen QR oluştur
    if (initialSecondKey && sessionId > 0) {
        setTimeout(function() {
            generateQRCode(sessionId, initialSecondKey);
        }, 100); // QRCode library yüklenmesi için kısa bekleme
    }
    
    function updateSessionCountdown() {
        if (sessionRemainingTime <= 0 || sessionStatus === 'expired') {
            sessionExpired = true;
            const countdownEl = document.getElementById('session-countdown');
            if (countdownEl) countdownEl.textContent = '00:00';
            
            const keyDisplayEl = document.getElementById('second-key-display');
            if (keyDisplayEl && !keyDisplayEl.classList.contains('expired')) {
                keyDisplayEl.textContent = 'SÜRESİ DOLDU';
                keyDisplayEl.classList.add('expired');
            }
            
            clearInterval(sessionCountdownInterval);
            clearInterval(countdownInterval);
            
            // Sayfayı yenile
            setTimeout(() => window.location.reload(), 3000);
            return;
        }
        
        const countdownEl = document.getElementById('session-countdown');
        if (countdownEl) {
            const minutes = Math.floor(sessionRemainingTime / 60);
            const seconds = sessionRemainingTime % 60;
            countdownEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }
        
        sessionRemainingTime--;
    }
    
    function updateCountdown() {
        const now = Math.floor(Date.now() / 1000);
        const remaining = keyExpiration - now;
        
        const countdownEl = document.getElementById('countdown');
        if (!countdownEl) return;
        
        if (remaining > 0 && !sessionExpired) {
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            countdownEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        } else {
            countdownEl.textContent = '00:00';
            if (!sessionExpired && sessionStatus === 'active') {
                generateSecondKey();
            }
        }
    }
    
 function generateSecondKey() {
    if (sessionExpired || sessionStatus !== 'active') return;
    
    fetch('attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=generate_second_key&session_id=' + sessionId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const keyDisplayEl = document.getElementById('second-key-display');
            if (keyDisplayEl) {
                keyDisplayEl.textContent = data.key;
                keyDisplayEl.classList.remove('expired');
            }
            keyExpiration = Math.floor(Date.now() / 1000) + data.expires_in;
            updateQRCode(data.key); // key'i parametre olarak gönder
            
            if (data.session_remaining) {
                sessionRemainingTime = data.session_remaining;
            }
        } else {
            console.error('Key generation failed:', data.message);
            if (data.expired) {
                sessionExpired = true;
                const keyDisplayEl = document.getElementById('second-key-display');
                if (keyDisplayEl) {
                    keyDisplayEl.textContent = 'SÜRESİ DOLDU';
                    keyDisplayEl.classList.add('expired');
                }
                setTimeout(() => window.location.reload(), 3000);
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

function updateQRCode(secondKey) {
    const qrElement = document.getElementById('qr-code');
    const keyDisplayEl = document.getElementById('current-key-display');
    
    if (!qrElement) return;
    
    qrElement.innerHTML = '';
    
    // Eğer secondKey parametresi verilmişse onu kullan, yoksa DOM'dan al
    const keyToShow = secondKey || document.getElementById('second-key-display')?.textContent;
    
    if (keyToShow && keyToShow !== 'SÜRESİ DOLDU' && keyToShow !== 'BEKLIYOR' && keyToShow !== 'OTURUM PASİF') {
        // SITE_URL'i PHP'den al - hem lokalde hem cPanel'de doğru çalışır
        const siteUrl = '<?php echo defined("SITE_URL") ? SITE_URL : ""; ?>';
        const qrUrl = `${siteUrl}/student/join_session_qr.php?s=${sessionId}&k=${keyToShow}`;
        
        new QRCode(qrElement, {
            text: qrUrl,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        
        // Alt kısımda da key'i göster
        if (keyDisplayEl) {
            keyDisplayEl.textContent = keyToShow;
        }
    } else {
        if (keyDisplayEl) {
            keyDisplayEl.textContent = 'Anahtar oluşturulmadı';
        }
    }
}

function copyKeyToClipboard() {
    const keyElement = document.getElementById('current-key-display');
    if (keyElement && keyElement.textContent !== 'Anahtar oluşturulmadı') {
        navigator.clipboard.writeText(keyElement.textContent)
            .then(() => {
                // Başarılı kopyalama bildirimi
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-1"></i>Kopyalandı!';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            })
            .catch(err => console.error('Kopyalama hatası:', err));
    }
}
    
    // Initialize
    if (sessionStatus === 'active') {
        updateCountdown();
        updateSessionCountdown();
        updateQRCode('A5H5D7');
        
        // Set intervals
        countdownInterval = setInterval(updateCountdown, 1000);
        sessionCountdownInterval = setInterval(updateSessionCountdown, 1000);
        
        // İlk anahtarı otomatik oluştur
        if (keyExpiration === 0 && !sessionExpired) {
            setTimeout(() => generateSecondKey(), 1000);
        }
        
        // Auto-generate keys
        setInterval(() => {
            if (!sessionExpired && sessionStatus === 'active') {
                generateSecondKey();
            }
        }, refreshInterval * 1000);
    } else {
        // Aktif değilse sadece QR kodu göster
        updateQRCode();
    }
    <?php endif; ?>
    
    // Copy functions
    function copyKeyToClipboard() {
        const keyText = document.getElementById('second-key-display')?.textContent;
        if (keyText && keyText !== 'SÜRESİ DOLDU' && keyText !== 'BEKLIYOR' && keyText !== 'OTURUM PASİF') {
            navigator.clipboard.writeText(keyText).then(() => {
                alert('Anahtar kopyalandı: ' + keyText);
            }).catch(err => console.error('Kopyalama hatası:', err));
        }
    }
    
    function copyLinkToClipboard() {
        const linkText = document.getElementById('session-link')?.textContent;
        if (linkText) {
            navigator.clipboard.writeText('https://' + linkText).then(() => {
                alert('Link kopyalandı!');
            }).catch(err => console.error('Kopyalama hatası:', err));
        }
    }
    
    // Download functions
    function downloadQRCode() {
        const qrElement = document.querySelector('#qr-code img');
        if (qrElement) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = qrElement.width;
            canvas.height = qrElement.height;
            ctx.drawImage(qrElement, 0, 0);
            
            canvas.toBlob(blob => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'qr_code_session_' + sessionId + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        }
    }
    
    function downloadKeysPDF() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate_keys_pdf.php';
        form.target = '_blank';
        
        const sessionIdInput = document.createElement('input');
        sessionIdInput.type = 'hidden';
        sessionIdInput.name = 'session_id';
        sessionIdInput.value = '<?php echo $session_id ?? 0; ?>';
        form.appendChild(sessionIdInput);
        
        const showAllInput = document.createElement('input');
        showAllInput.type = 'hidden';
        showAllInput.name = 'show_all';
        showAllInput.value = '<?php echo isset($show_all_keys) && $show_all_keys ? '1' : '0'; ?>';
        form.appendChild(showAllInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    function exportKeysToExcel() {
        const keys = <?php echo json_encode($first_phase_keys ?? []); ?>;
        let csvContent = "\uFEFF";
        csvContent += "Anahtar Kodu,Kullanan Öğrenci,Kullanım Zamanı\n";
        
        keys.forEach(key => {
            const keyCode = key.key_code;
            const usedBy = key.is_used ? (key.used_by_name || 'Bilinmiyor') : 'Kullanılmadı';
            const usedAt = key.is_used && key.used_at ? new Date(key.used_at).toLocaleString('tr-TR') : '-';
            csvContent += `"${keyCode}","${usedBy}","${usedAt}"\n`;
        });
        
        const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'yoklama_anahtarlari_' + new Date().getTime() + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
</script>

<script>
    // QR Code Logic
    let qrCodeObj = null;

    function generateQRCode(sessionId, key) {
        // Container Check - using existing id="qr-code"
        const qrContainer = document.getElementById('qr-code');
        
        if (!qrContainer) {
            console.log("QR Container not found");
            return;
        }
        
        // Clear previous
        qrContainer.innerHTML = '';
        
        // SITE_URL'i PHP'den al
        const siteUrl = '<?php echo defined("SITE_URL") ? SITE_URL : ""; ?>';
        const fullUrl = `${siteUrl}/student/join_session_qr.php?s=${sessionId}&k=${key}`;
        console.log("QR URL:", fullUrl);
        
        try {
            new QRCode(qrContainer, {
                text: fullUrl,
                width: 180,
                height: 180,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        } catch(e) {
            console.error("QRCode Error:", e);
        }
    }

    // Hook into existing updateSecondKey if possible, or poll separately?
    // The existing updateSecondKey is not global. We need to override it or set up a parallel observer.
    // However, since we can't easily modify the random logic inside the PHP-generated script block above without risk,
    // let's use a MutationObserver on the key display ('second-key-display') to trigger QR update.
    
    document.addEventListener('DOMContentLoaded', function() {
        const keyDisplay = document.getElementById('second-key-display');
        if (keyDisplay) {
            // Observer configuration
            const config = { childList: true, characterData: true, subtree: true };
            
            // Callback function
            const callback = function(mutationsList, observer) {
                for(const mutation of mutationsList) {
                    const newKey = keyDisplay.innerText.trim();
                    // Exclude non-key texts
                    const invalidKeys = ['---', 'BEKLIYOR', 'SÜRESİ DOLDU', 'OTURUM PASİF', 'YENİLENİYOR...'];
                    if (newKey && !invalidKeys.includes(newKey)) {
                         const sessId = <?php echo $session_id ?? 0; ?>;
                         if(sessId > 0) generateQRCode(sessId, newKey);
                    }
                }
            };
            
            const observer = new MutationObserver(callback);
            observer.observe(keyDisplay, config);
            
            // Initial check
            const initialKey = keyDisplay.innerText.trim();
            const invalidKeys = ['---', 'BEKLIYOR', 'SÜRESİ DOLDU', 'OTURUM PASİF'];
            if (initialKey && !invalidKeys.includes(initialKey)) {
                 const sessId = <?php echo $session_id ?? 0; ?>;
                 if(sessId > 0) generateQRCode(sessId, initialKey);
            }
        }
    });
    
    // Birinci Aşama QR Kod Göster
    function showFirstPhaseQR(keyCode) {
        const qrContainer = document.getElementById('first-phase-qr-container');
        const keyDisplay = document.getElementById('first-phase-key-display');
        
        if (!qrContainer) return;
        
        // Temizle
        qrContainer.innerHTML = '';
        keyDisplay.textContent = keyCode;
        
        // SITE_URL'i PHP'den al
        const siteUrl = '<?php echo defined("SITE_URL") ? SITE_URL : ""; ?>';
        const qrUrl = `${siteUrl}/student/join_first_phase_qr.php?fk=${keyCode}`;
        
        // QR oluştur
        new QRCode(qrContainer, {
            text: qrUrl,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        
        // Modal'ı aç
        const modal = new bootstrap.Modal(document.getElementById('firstPhaseQRModal'));
        modal.show();
    }
</script>

<?php include '../includes/components/shared_footer.php'; ?>