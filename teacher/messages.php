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
    error_log('Teacher Messages Error: ' . $e->getMessage());
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

// Actions
$action = $_GET['action'] ?? 'list';
$message_id = (int)($_GET['id'] ?? 0);
$message_type = $_GET['type'] ?? 'private'; // private, announcements
$filter = $_GET['filter'] ?? 'all'; // all, unread, read
$course_filter = (int)($_GET['course'] ?? 0);
$conversation_id = (int)($_GET['conversation'] ?? 0);

// Duyuru gönderme
if ($action === 'send_announcement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $course_id = (int)$_POST['course_id'];
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $recipient_type = $_POST['recipient_type'] ?? 'all';
        $specific_students = $_POST['specific_students'] ?? [];
        
        if (empty($subject) || empty($message) || $course_id <= 0) {
            throw new Exception('Lütfen tüm alanları doldurun.');
        }
        
        // Öğretmenin bu dersi verdiğini kontrol et
        $query = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            throw new Exception('Bu dersi verme yetkiniz bulunmamaktadır.');
        }
        
        // Duyuruyu oluştur
        $query = "INSERT INTO messages (sender_id, course_id, subject, message, recipient_type) 
                  VALUES (:sender_id, :course_id, :subject, :message, :recipient_type)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':sender_id', $teacher_id, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->execute();
        
        $announcement_id = $db->lastInsertId();
        
        // Alıcıları belirle ve ekle
        $recipients = [];
        
        if ($recipient_type === 'all') {
            // Tüm öğrenciler
            $query = "SELECT student_id FROM course_enrollments WHERE course_id = :course_id AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } elseif ($recipient_type === 'absent_students') {
            // Devamsızlık yapan öğrenciler (son 5 oturumda %60'ın altında katılım)
            $query = "SELECT DISTINCT ce.student_id
                      FROM course_enrollments ce
                      WHERE ce.course_id = :course_id AND ce.is_active = 1
                      AND ce.student_id IN (
                          SELECT sub.student_id
                          FROM (
                              SELECT ce2.student_id,
                                     COUNT(asess.id) as total_sessions,
                                     COUNT(ar.id) as attended_sessions,
                                     COALESCE(COUNT(ar.id) / COUNT(asess.id) * 100, 0) as attendance_rate
                              FROM course_enrollments ce2
                              JOIN attendance_sessions asess ON ce2.course_id = asess.course_id
                              LEFT JOIN attendance_records ar ON asess.id = ar.session_id AND ce2.student_id = ar.student_id AND ar.second_phase_completed = 1
                              WHERE ce2.course_id = :course_id AND ce2.is_active = 1 AND asess.is_active = 1
                              AND asess.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                              GROUP BY ce2.student_id
                              HAVING total_sessions >= 3 AND attendance_rate < 60
                          ) sub
                      )";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
            $stmt->execute();
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } elseif ($recipient_type === 'specific' && !empty($specific_students)) {
            $recipients = array_map('intval', $specific_students);
        }
        
        // Alıcıları message_recipients tablosuna ekle
        if (!empty($recipients)) {
            $query = "INSERT INTO message_recipients (message_id, recipient_id) VALUES ";
            $values = [];
            $params = [];
            
            foreach ($recipients as $i => $student_id) {
                $values[] = "(:message_id, :student_id_$i)";
                $params[":student_id_$i"] = $student_id;
            }
            
            $query .= implode(', ', $values);
            $params[':message_id'] = $announcement_id;
            
            $stmt = $db->prepare($query);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
        }
        
        show_message('Duyuru başarıyla gönderildi. ' . count($recipients) . ' öğrenciye ulaştırıldı.', 'success');
        
    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }
    
    redirect('messages.php?type=announcements');
    exit;
}

// Özel mesaja yanıt verme
if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $course_id = (int)$_POST['course_id'];
        $recipient_id = (int)$_POST['recipient_id'];
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (empty($subject) || empty($message) || $course_id <= 0 || $recipient_id <= 0) {
            throw new Exception('Lütfen tüm alanları doldurun.');
        }
        
        // Öğretmenin bu dersi verdiğini kontrol et
        $query = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            throw new Exception('Bu dersi verme yetkiniz bulunmamaktadır.');
        }
        
        // Yanıtı gönder
        $query = "INSERT INTO private_messages (sender_id, recipient_id, course_id, subject, message, parent_message_id) 
                  VALUES (:sender_id, :recipient_id, :course_id, :subject, :message, :parent_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':sender_id', $teacher_id, PDO::PARAM_INT);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $stmt->execute();
        
        show_message('Yanıtınız başarıyla gönderildi.', 'success');
        
    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }
    
    redirect('messages.php?type=private');
    exit;
}

// Öğretmenin derslerini al
try {
    $query = "SELECT c.id, c.course_name, c.course_code,
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
    $teacher_courses = [];
}

// İstatistikler
try {
    // Gönderilen duyurular
    $query = "SELECT COUNT(*) as total FROM messages m
              JOIN courses c ON m.course_id = c.id
              WHERE m.sender_id = :teacher_id AND c.teacher_id = :teacher_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Gelen özel mesajlar
    $query = "SELECT COUNT(*) as total FROM private_messages pm
              JOIN courses c ON pm.course_id = c.id
              WHERE pm.recipient_id = :teacher_id AND c.teacher_id = :teacher_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_private = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Okunmamış özel mesajlar
    $query = "SELECT COUNT(*) as unread FROM private_messages pm
              JOIN courses c ON pm.course_id = c.id
              WHERE pm.recipient_id = :teacher_id AND pm.is_read = 0 AND c.teacher_id = :teacher_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
    $stmt->execute();
    $unread_private = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];
    
} catch (Exception $e) {
    error_log('Message stats error: ' . $e->getMessage());
    $total_announcements = $total_private = $unread_private = 0;
}

// Mesajları al
$messages = [];
if ($message_type === 'announcements') {
    // Gönderilen duyuruları al
    try {
        $where_conditions = ["m.sender_id = :teacher_id"];
        $params = [':teacher_id' => $teacher_id];
        
        if ($course_filter > 0) {
            $where_conditions[] = "m.course_id = :course_filter";
            $params[':course_filter'] = $course_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT m.*, c.course_name, c.course_code,
                  COUNT(mr.id) as total_recipients,
                  COUNT(CASE WHEN mr.is_read = 1 THEN 1 END) as read_count
                  FROM messages m
                  JOIN courses c ON m.course_id = c.id
                  LEFT JOIN message_recipients mr ON m.id = mr.message_id
                  WHERE $where_clause
                  GROUP BY m.id
                  ORDER BY m.created_at DESC";
        
        $stmt = $db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Announcements query error: ' . $e->getMessage());
    }
} else {
    // Gelen özel mesajları al (konuşma grupları halinde)
    try {
        $where_conditions = ["c.teacher_id = :teacher_id"];
        $params = [':teacher_id' => $teacher_id];
        
        if ($filter === 'unread') {
            $where_conditions[] = "pm.recipient_id = :teacher_id2 AND pm.is_read = 0";
            $params[':teacher_id2'] = $teacher_id;
        } elseif ($filter === 'read') {
            $where_conditions[] = "pm.recipient_id = :teacher_id2 AND pm.is_read = 1";
            $params[':teacher_id2'] = $teacher_id;
        }
        
        if ($course_filter > 0) {
            $where_conditions[] = "pm.course_id = :course_filter";
            $params[':course_filter'] = $course_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Konuşmaları al (en son mesaja göre grupla)
        $query = "SELECT 
                    COALESCE(pm.parent_message_id, pm.id) as conversation_id,
                    pm.course_id,
                    c.course_name, 
                    c.course_code,
                    MAX(pm.created_at) as last_message_time,
                    COUNT(CASE WHEN pm.recipient_id = :teacher_id AND pm.is_read = 0 THEN 1 END) as unread_count,
                    (SELECT subject FROM private_messages WHERE id = COALESCE(pm.parent_message_id, pm.id)) as conversation_subject,
                    (SELECT full_name FROM users WHERE id = 
                        CASE WHEN pm.sender_id = :teacher_id THEN pm.recipient_id ELSE pm.sender_id END
                    ) as other_person,
                    (SELECT student_number FROM users WHERE id = 
                        CASE WHEN pm.sender_id = :teacher_id THEN pm.recipient_id ELSE pm.sender_id END
                    ) as student_number
                  FROM private_messages pm
                  JOIN courses c ON pm.course_id = c.id
                  WHERE $where_clause
                  GROUP BY COALESCE(pm.parent_message_id, pm.id), pm.course_id
                  ORDER BY last_message_time DESC";
        
        $stmt = $db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Private messages query error: ' . $e->getMessage());
    }
}

// Konuşma detayı
$conversation_messages = [];
if ($action === 'conversation' && $conversation_id > 0) {
    try {
        $query = "SELECT pm.*, u.full_name as sender_name, u.user_type as sender_type, u.student_number
                  FROM private_messages pm
                  JOIN users u ON pm.sender_id = u.id
                  JOIN courses c ON pm.course_id = c.id
                  WHERE (pm.id = :conversation_id OR pm.parent_message_id = :conversation_id) 
                  AND (pm.sender_id = :teacher_id OR pm.recipient_id = :teacher_id)
                  AND c.teacher_id = :teacher_id
                  ORDER BY pm.created_at ASC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':conversation_id', $conversation_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $conversation_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Okunmamış mesajları okundu işaretle
        if (!empty($conversation_messages)) {
            $query = "UPDATE private_messages SET is_read = 1, read_at = NOW() 
                      WHERE (id = :conversation_id OR parent_message_id = :conversation_id) 
                      AND recipient_id = :teacher_id AND is_read = 0";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':conversation_id', $conversation_id, PDO::PARAM_INT);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log('Conversation query error: ' . $e->getMessage());
    }
}

// Duyuru detayı
$announcement_detail = null;
if ($action === 'view' && $message_id > 0 && $message_type === 'announcements') {
    try {
        $query = "SELECT m.*, c.course_name, c.course_code,
                  COUNT(mr.id) as total_recipients,
                  COUNT(CASE WHEN mr.is_read = 1 THEN 1 END) as read_count
                  FROM messages m
                  JOIN courses c ON m.course_id = c.id
                  LEFT JOIN message_recipients mr ON m.id = mr.message_id
                  WHERE m.id = :message_id AND m.sender_id = :teacher_id AND c.teacher_id = :teacher_id
                  GROUP BY m.id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':message_id', $message_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        $announcement_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Alıcı listesini al
        if ($announcement_detail) {
            $query = "SELECT u.full_name, u.student_number, mr.is_read, mr.read_at
                      FROM message_recipients mr
                      JOIN users u ON mr.recipient_id = u.id
                      WHERE mr.message_id = :message_id
                      ORDER BY u.full_name";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':message_id', $message_id, PDO::PARAM_INT);
            $stmt->execute();
            $announcement_detail['recipients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Announcement detail error: ' . $e->getMessage());
    }
}
?>
<?php
$page_title = "Mesaj ve Duyurularım - " . htmlspecialchars($site_name);

// Include header
include '../includes/components/teacher_header.php';
?>
    <style>
      
        .message-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
            border-left: 4px solid #dee2e6;
        }
        .message-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .message-card.unread {
            border-left-color:<?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
            background-color: #f8f9fa;
            font-weight: 500;
        }
        .message-card.read {
            border-left-color: #6c757d;
        }
        .conversation-message {
            border-radius: 18px;
            max-width: 80%;
            word-wrap: break-word;
        }
        .conversation-message.sent {
            background:<?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
            color: white;
            margin-left: auto;
        }
        .conversation-message.received {
            background: #e9ecef;
            color: #333;
        }
        .message-bubble {
            position: relative;
            margin-bottom: 15px;
        }
        .message-bubble.sent {
            text-align: right;
        }
        .message-bubble.received {
            text-align: left;
        }
        .conversation-container {
            height: 60vh;
            overflow-y: auto;
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
        }
        .badge-unread {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .message-tabs .nav-link {
            color:<?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
            border: none;
            background: transparent;
            border-radius: 25px;
            margin-right: 10px;
        }
        .message-tabs .nav-link.active {
            color: white;
            background: #27ae60;
        }
        .announcement-stats {
            background: linear-gradient(135deg,rgb(91, 82, 135) 0%,rgb(17, 12, 68) 100%);
            color: white;
            border-radius: 10px;
        }
        .recipient-item {
            border-left: 3px solid<?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
            background: #f8f9fa;
            margin-bottom: 8px;
        }
        .recipient-item.read {
            border-left-color:<?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
            background: #d4edda;
        }
        .recipient-item.unread {
            border-left-color: #ffc107;
            background: #fff3cd;
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

            <?php if ($action === 'view' && $announcement_detail): ?>
                <!-- Announcement Detail View -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3>
                                <i class="fas fa-bullhorn me-2"></i>
                                <?php echo htmlspecialchars($announcement_detail['subject']); ?>
                            </h3>
                            <a href="messages.php?type=announcements" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Geri Dön
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Announcement Stats -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card announcement-stats">
                            <div class="card-body text-center">
                                <h3><?php echo $announcement_detail['total_recipients']; ?></h3>
                                <p class="mb-0">Alıcı Sayısı</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card announcement-stats">
                            <div class="card-body text-center">
                                <h3><?php echo $announcement_detail['read_count']; ?></h3>
                                <p class="mb-0">Okuyan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card announcement-stats">
                            <div class="card-body text-center">
                                <h3><?php echo $announcement_detail['total_recipients'] - $announcement_detail['read_count']; ?></h3>
                                <p class="mb-0">Okumayan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card announcement-stats">
                            <div class="card-body text-center">
                                <?php $read_rate = $announcement_detail['total_recipients'] > 0 ? round(($announcement_detail['read_count'] / $announcement_detail['total_recipients']) * 100, 1) : 0; ?>
                                <h3><?php echo $read_rate; ?>%</h3>
                                <p class="mb-0">Okunma Oranı</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8">
                        <!-- Message Content -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Mesaj İçeriği</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong>Ders:</strong> <?php echo htmlspecialchars($announcement_detail['course_name']); ?> (<?php echo htmlspecialchars($announcement_detail['course_code']); ?>)
                                </div>
                                <div class="mb-3">
                                    <strong>Gönderim Tarihi:</strong> <?php echo date('d.m.Y H:i', strtotime($announcement_detail['created_at'])); ?>
                                </div>
                                <div class="mb-3">
                                    <strong>Alıcı Tipi:</strong> 
                                    <span class="badge bg-<?php echo $announcement_detail['recipient_type'] === 'all' ? 'primary' : 'warning'; ?>">
                                        <?php 
                                        $recipient_labels = [
                                            'all' => 'Tüm Öğrenciler',
                                            'absent_students' => 'Devamsız Öğrenciler',
                                            'specific' => 'Seçili Öğrenciler'
                                        ];
                                        echo $recipient_labels[$announcement_detail['recipient_type']] ?? 'Bilinmiyor';
                                        ?>
                                    </span>
                                </div>
                                <hr>
                                <div class="message-content">
                                    <?php echo nl2br(htmlspecialchars($announcement_detail['message'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <!-- Recipients List -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Alıcılar</h5>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($announcement_detail['recipients'] as $recipient): ?>
                                    <div class="recipient-item <?php echo $recipient['is_read'] ? 'read' : 'unread'; ?> p-2 rounded">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($recipient['full_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($recipient['student_number']); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <?php if ($recipient['is_read']): ?>
                                                    <i class="fas fa-check-circle text-success"></i>
                                                    <br><small class="text-muted"><?php echo date('d.m H:i', strtotime($recipient['read_at'])); ?></small>
                                                <?php else: ?>
                                                    <i class="fas fa-clock text-warning"></i>
                                                    <br><small class="text-muted">Okunmadı</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action === 'conversation' && !empty($conversation_messages)): ?>
                <!-- Conversation View -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3>
                                <i class="fas fa-comments me-2"></i>
                                <?php echo htmlspecialchars($conversation_messages[0]['subject']); ?>
                            </h3>
                            <a href="messages.php?type=private" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Geri Dön
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <!-- Conversation Messages -->
                        <div class="conversation-container mb-4">
                            <?php foreach ($conversation_messages as $msg): ?>
                                <div class="message-bubble <?php echo $msg['sender_id'] == $teacher_id ? 'sent' : 'received'; ?>">
                                    <div class="conversation-message <?php echo $msg['sender_id'] == $teacher_id ? 'sent' : 'received'; ?> p-3">
                                        <div class="mb-2"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                        <small class="opacity-75">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <?php if ($msg['sender_type'] === 'student'): ?>
                                                (<?php echo htmlspecialchars($msg['student_number']); ?>)
                                            <?php endif; ?>
                                             • <?php echo date('d.m.Y H:i', strtotime($msg['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Reply Form -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-reply me-2"></i>Yanıtla
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="messages.php?action=reply">
                                    <input type="hidden" name="course_id" value="<?php echo $conversation_messages[0]['course_id']; ?>">
                                    <input type="hidden" name="recipient_id" value="<?php echo $conversation_messages[0]['sender_id'] == $teacher_id ? $conversation_messages[0]['recipient_id'] : $conversation_messages[0]['sender_id']; ?>">
                                    <input type="hidden" name="subject" value="Re: <?php echo htmlspecialchars($conversation_messages[0]['subject']); ?>">
                                    <input type="hidden" name="parent_id" value="<?php echo $conversation_id; ?>">
                                    
                                    <div class="mb-3">
                                        <label for="message" class="form-label">Yanıtınız</label>
                                        <textarea class="form-control" id="message" name="message" rows="4" required 
                                                  placeholder="Yanıtınızı buraya yazın..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-paper-plane me-1"></i>Yanıt Gönder
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Messages List View -->
                
                <!-- Message Type Tabs -->
                <div class="row mb-4">
                    <div class="col-12">
                        <ul class="nav nav-pills message-tabs">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $message_type === 'private' ? 'active' : ''; ?>" 
                                   href="messages.php?type=private">
                                    <i class="fas fa-comments me-1"></i>Gelen Mesajlar (<?php echo $total_private; ?>)
                                    <?php if ($unread_private > 0): ?>
                                        <span class="badge bg-warning ms-1"><?php echo $unread_private; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $message_type === 'announcements' ? 'active' : ''; ?>" 
                                   href="messages.php?type=announcements">
                                    <i class="fas fa-bullhorn me-1"></i>Gönderilen Duyurular (<?php echo $total_announcements; ?>)
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if ($message_type === 'private'): ?>
                                    <div class="btn-group" role="group">
                                        <a href="messages.php?type=private&filter=all<?php echo $course_filter ? '&course=' . $course_filter : ''; ?>" 
                                           class="btn btn-outline-primary <?php echo $filter === 'all' ? 'active' : ''; ?>">
                                            Tümü
                                        </a>
                                        <a href="messages.php?type=private&filter=unread<?php echo $course_filter ? '&course=' . $course_filter : ''; ?>" 
                                           class="btn btn-outline-warning <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                                            Okunmamış
                                        </a>
                                        <a href="messages.php?type=private&filter=read<?php echo $course_filter ? '&course=' . $course_filter : ''; ?>" 
                                           class="btn btn-outline-success <?php echo $filter === 'read' ? 'active' : ''; ?>">
                                            Okunmuş
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <select class="form-select me-2 d-inline-block" style="width: auto;" onchange="location.href='messages.php?type=<?php echo $message_type; ?>&filter=<?php echo $filter; ?>&course=' + this.value">
                                    <option value="0">Tüm Dersler</option>
                                    <?php foreach ($teacher_courses as $course): ?>
                                        <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal">
                                    <i class="fas fa-bullhorn me-1"></i>Duyuru Yayınla
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages List -->
                <div class="row">
                    <div class="col-12">
                        <?php if (empty($messages)): ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                                    <h4 class="text-muted">
                                        <?php if ($message_type === 'private'): ?>
                                            Gelen Mesaj Yok
                                        <?php else: ?>
                                            Gönderilen Duyuru Yok
                                        <?php endif; ?>
                                    </h4>
                                    <p class="text-muted">
                                        <?php if ($message_type === 'private'): ?>
                                            Henüz öğrencilerden mesaj almadınız.
                                        <?php else: ?>
                                            Henüz duyuru göndermediniz.
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($message_type === 'announcements'): ?>
                                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal">
                                            <i class="fas fa-plus me-1"></i>İlk Duyurunuzu Yayınlayın
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <?php if ($message_type === 'private'): ?>
                                    <!-- Private Message Card -->
                                    <div class="card message-card <?php echo $message['unread_count'] > 0 ? 'unread' : 'read'; ?> mb-3" 
                                         onclick="location.href='messages.php?action=conversation&conversation=<?php echo $message['conversation_id']; ?>'">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-start">
                                                        <div class="me-3 mt-1">
                                                            <i class="fas fa-user-graduate fa-2x text-primary"></i>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="card-title mb-1">
                                                                <?php echo htmlspecialchars($message['conversation_subject']); ?>
                                                                <?php if ($message['unread_count'] > 0): ?>
                                                                    <span class="badge bg-warning ms-2"><?php echo $message['unread_count']; ?> Yeni</span>
                                                                <?php endif; ?>
                                                            </h6>
                                                            <p class="text-muted mb-2">
                                                                <i class="fas fa-user me-1"></i>
                                                                <?php echo htmlspecialchars($message['other_person']); ?>
                                                                <small>(<?php echo htmlspecialchars($message['student_number']); ?>)</small>
                                                                <span class="ms-2">
                                                                    <i class="fas fa-book me-1"></i>
                                                                    <?php echo htmlspecialchars($message['course_name']); ?>
                                                                </span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php echo date('d.m.Y H:i', strtotime($message['last_message_time'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- Announcement Card -->
                                    <div class="card message-card mb-3" 
                                         onclick="location.href='messages.php?action=view&id=<?php echo $message['id']; ?>&type=announcements'">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-start">
                                                        <div class="me-3 mt-1">
                                                            <i class="fas fa-bullhorn text-success"></i>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="card-title mb-1">
                                                                <?php echo htmlspecialchars($message['subject']); ?>
                                                            </h6>
                                                            <p class="text-muted mb-2">
                                                                <i class="fas fa-book me-1"></i>
                                                                <?php echo htmlspecialchars($message['course_name']); ?>
                                                                <span class="ms-2">
                                                                    <i class="fas fa-users me-1"></i>
                                                                    <?php echo $message['total_recipients']; ?> alıcı
                                                                </span>
                                                            </p>
                                                            <div class="text-muted">
                                                                <?php echo nl2br(htmlspecialchars(substr($message['message'], 0, 150))); ?>
                                                                <?php if (strlen($message['message']) > 150): ?>...<?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="text-muted mb-2">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php echo date('d.m.Y H:i', strtotime($message['created_at'])); ?>
                                                    </div>
                                                    <div class="mb-2">
                                                        <?php $read_rate = $message['total_recipients'] > 0 ? round(($message['read_count'] / $message['total_recipients']) * 100) : 0; ?>
                                                        <span class="badge bg-<?php echo $read_rate >= 70 ? 'success' : ($read_rate >= 50 ? 'warning' : 'danger'); ?>">
                                                            %<?php echo $read_rate; ?> okundu
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-<?php echo $message['recipient_type'] === 'all' ? 'primary' : 'warning'; ?>">
                                                            <?php 
                                                            $recipient_labels = [
                                                                'all' => 'Genel',
                                                                'absent_students' => 'Devamsız',
                                                                'specific' => 'Özel'
                                                            ];
                                                            echo $recipient_labels[$message['recipient_type']] ?? 'Bilinmiyor';
                                                            ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Announcement Modal -->
    <div class="modal fade" id="newAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bullhorn me-2"></i>Yeni Duyuru Yayınla
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="messages.php?action=send_announcement">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="course_id" class="form-label">Ders Seçin</label>
                            <select class="form-select" id="course_id" name="course_id" required onchange="loadStudents(this.value)">
                                <option value="">Ders seçiniz...</option>
                                <?php foreach ($teacher_courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>">
                                        <?php echo htmlspecialchars($course['course_name']); ?> (<?php echo $course['student_count']; ?> öğrenci)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="recipient_type" class="form-label">Alıcılar</label>
                            <select class="form-select" id="recipient_type" name="recipient_type" required onchange="toggleSpecificStudents()">
                                <option value="all">Tüm Öğrenciler</option>
                                <option value="absent_students">Devamsızlık Yapan Öğrenciler</option>
                                <option value="specific">Seçili Öğrenciler</option>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="specific_students_div" style="display: none;">
                            <label class="form-label">Öğrenci Seçimi</label>
                            <div id="students_list" class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                <!-- Öğrenci listesi buraya yüklenecek -->
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="subject" class="form-label">Konu</label>
                            <input type="text" class="form-control" id="subject" name="subject" required 
                                   placeholder="Duyuru konusu...">
                        </div>
                        
                        <div class="mb-3">
                            <label for="message" class="form-label">Duyuru İçeriği</label>
                            <textarea class="form-control" id="message" name="message" rows="6" required 
                                      placeholder="Duyuru içeriğinizi buraya yazın..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-bullhorn me-1"></i>Duyuru Yayınla
                        </button>
                    </div>
                </form>
            </div>
        </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mesaj kartlarına hover efekti
        document.querySelectorAll('.message-card').forEach(function(card) {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Konuşma scroll down
        const conversationContainer = document.querySelector('.conversation-container');
        if (conversationContainer) {
            conversationContainer.scrollTop = conversationContainer.scrollHeight;
        }
        
        // Specific students toggle
        function toggleSpecificStudents() {
            const recipientType = document.getElementById('recipient_type').value;
            const specificDiv = document.getElementById('specific_students_div');
            
            if (recipientType === 'specific') {
                specificDiv.style.display = 'block';
            } else {
                specificDiv.style.display = 'none';
            }
        }
        
        // Load students for specific selection
        function loadStudents(courseId) {
            if (!courseId) return;
            
            fetch(`ajax/get_students.php?course_id=${courseId}`)
                .then(response => response.json())
                .then(data => {
                    const studentsList = document.getElementById('students_list');
                    studentsList.innerHTML = '';
                    
                    if (data.students && data.students.length > 0) {
                        data.students.forEach(student => {
                            const div = document.createElement('div');
                            div.className = 'form-check';
                            div.innerHTML = `
                                <input class="form-check-input" type="checkbox" name="specific_students[]" value="${student.id}" id="student_${student.id}">
                                <label class="form-check-label" for="student_${student.id}">
                                    ${student.full_name} (${student.student_number})
                                </label>
                            `;
                            studentsList.appendChild(div);
                        });
                    } else {
                        studentsList.innerHTML = '<p class="text-muted">Bu derste öğrenci bulunamadı.</p>';
                    }
                })
                .catch(error => {
                    console.error('Öğrenci yükleme hatası:', error);
                    document.getElementById('students_list').innerHTML = '<p class="text-danger">Öğrenciler yüklenirken hata oluştu.</p>';
                });
        }
    </script>
<?php include '../includes/components/shared_footer.php'; ?>