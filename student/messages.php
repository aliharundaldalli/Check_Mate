<?php
// messages.php

// Increase memory limit and execution time
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
    error_log('Student Messages Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Site ayarlarını yükle
try {
    $query = "SELECT setting_key, setting_value FROM site_settings";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $site_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
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
$message_type = $_GET['type'] ?? 'announcements'; // announcements, private
$filter = $_GET['filter'] ?? 'all'; // all, unread, read
$course_filter = (int)($_GET['course'] ?? 0);
$conversation_id = (int)($_GET['conversation'] ?? 0);

// Öğrenciye mesaj gönderme
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $course_id = (int)$_POST['course_id'];
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (empty($subject) || empty($message) || $course_id <= 0) {
            throw new Exception('Lütfen tüm alanları doldurun.');
        }
        
        // Öğrencinin bu derse kayıtlı olduğunu kontrol et
        $query = "SELECT c.*, u.full_name as teacher_name 
                  FROM courses c 
                  JOIN course_enrollments ce ON c.id = ce.course_id 
                  LEFT JOIN users u ON c.teacher_id = u.id
                  WHERE c.id = :course_id AND ce.student_id = :student_id AND ce.is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            throw new Exception('Bu derse kayıtlı değilsiniz.');
        }
        
        if (!$course['teacher_id']) {
            throw new Exception('Bu dersin öğretmeni atanmamış.');
        }
        
        // Mesajı gönder
        $query = "INSERT INTO private_messages (sender_id, recipient_id, course_id, subject, message, parent_message_id) 
                  VALUES (:sender_id, :recipient_id, :course_id, :subject, :message, :parent_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':sender_id', $student_id, PDO::PARAM_INT);
        $stmt->bindParam(':recipient_id', $course['teacher_id'], PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($parent_id) {
            show_message('Yanıtınız başarıyla gönderildi.', 'success');
        } else {
            show_message('Mesajınız ' . htmlspecialchars($course['teacher_name']) . ' öğretmenine başarıyla gönderildi.', 'success');
        }
        
    } catch (Exception $e) {
        show_message($e->getMessage(), 'danger');
    }
    
    redirect('messages.php?type=private');
    exit;
}

// Öğrencinin kayıtlı olduğu dersleri al
try {
    $query = "SELECT c.id, c.course_name, c.course_code, u.full_name as teacher_name 
              FROM courses c 
              JOIN course_enrollments ce ON c.id = ce.course_id 
              LEFT JOIN users u ON c.teacher_id = u.id
              WHERE ce.student_id = :student_id AND ce.is_active = 1 AND c.is_active = 1 AND c.teacher_id IS NOT NULL
              ORDER BY c.course_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Student courses error: ' . $e->getMessage());
    $student_courses = [];
}

// İstatistikler
try {
    // Genel duyurular
    $query = "SELECT COUNT(DISTINCT m.id) as total FROM messages m
              JOIN message_recipients mr ON m.id = mr.message_id
              WHERE mr.recipient_id = :student_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_announcements = $stmt->fetchColumn();
    
    $query = "SELECT COUNT(DISTINCT m.id) as unread FROM messages m
              JOIN message_recipients mr ON m.id = mr.message_id
              WHERE mr.recipient_id = :student_id AND mr.is_read = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $unread_announcements = $stmt->fetchColumn();
    
    // Özel mesajlar (konuşma bazlı)
    $query = "SELECT COUNT(DISTINCT COALESCE(parent_message_id, id)) FROM private_messages WHERE sender_id = :student_id OR recipient_id = :student_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_private = $stmt->fetchColumn();
    
    $query = "SELECT COUNT(DISTINCT COALESCE(parent_message_id, id)) FROM private_messages WHERE recipient_id = :student_id AND is_read = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $unread_private = $stmt->fetchColumn();
    
    $total_unread = $unread_announcements + $unread_private;
} catch (Exception $e) {
    error_log('Message stats error: ' . $e->getMessage());
    $total_announcements = $unread_announcements = $total_private = $unread_private = $total_unread = 0;
}

// Mesajları al
$messages = [];
if ($message_type === 'private') {
    // Özel mesajları al (konuşma grupları halinde)
    try {
        $where_conditions = ["(pm.sender_id = :student_id OR pm.recipient_id = :student_id)"];
        $params = [':student_id' => $student_id];
        
        if ($filter === 'unread') {
            $where_conditions[] = "EXISTS (SELECT 1 FROM private_messages sub_pm WHERE sub_pm.recipient_id = :student_id AND sub_pm.is_read = 0 AND COALESCE(sub_pm.parent_message_id, sub_pm.id) = COALESCE(pm.parent_message_id, pm.id))";
        }
        
        if ($course_filter > 0) {
            $where_conditions[] = "pm.course_id = :course_filter";
            $params[':course_filter'] = $course_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT 
                    COALESCE(pm.parent_message_id, pm.id) as conversation_id,
                    pm.course_id,
                    c.course_name, 
                    c.course_code,
                    MAX(pm.created_at) as last_message_time,
                    COUNT(CASE WHEN pm.recipient_id = :student_id AND pm.is_read = 0 THEN 1 END) as unread_count,
                    (SELECT subject FROM private_messages WHERE id = COALESCE(pm.parent_message_id, pm.id)) as conversation_subject,
                    (SELECT full_name FROM users WHERE id = CASE WHEN MAX(pm.sender_id) = :student_id THEN MAX(pm.recipient_id) ELSE MAX(pm.sender_id) END) as other_person
                  FROM private_messages pm
                  JOIN courses c ON pm.course_id = c.id
                  WHERE $where_clause
                  GROUP BY conversation_id, pm.course_id, c.course_name, c.course_code
                  ORDER BY last_message_time DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Private messages query error: ' . $e->getMessage());
    }
} else {
    // Genel duyuruları al
    try {
        $where_conditions = ["mr.recipient_id = :student_id"];
        $params = [':student_id' => $student_id];
        
        if ($filter === 'unread') $where_conditions[] = "mr.is_read = 0";
        if ($filter === 'read') $where_conditions[] = "mr.is_read = 1";
        if ($course_filter > 0) {
            $where_conditions[] = "m.course_id = :course_filter";
            $params[':course_filter'] = $course_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT m.*, c.course_name, c.course_code, u.full_name as sender_name, mr.is_read, mr.read_at
                  FROM messages m
                  JOIN message_recipients mr ON m.id = mr.message_id
                  JOIN courses c ON m.course_id = c.id
                  LEFT JOIN users u ON m.sender_id = u.id
                  WHERE $where_clause
                  GROUP BY m.id
                  ORDER BY m.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Announcements query error: ' . $e->getMessage());
    }
}

// Konuşma detayı
$conversation_messages = [];
if ($action === 'conversation' && $conversation_id > 0) {
    try {
        $query = "SELECT pm.*, u.full_name as sender_name
                  FROM private_messages pm
                  JOIN users u ON pm.sender_id = u.id
                  WHERE (pm.id = :conversation_id OR pm.parent_message_id = :conversation_id) 
                  AND (pm.sender_id = :student_id OR pm.recipient_id = :student_id)
                  ORDER BY pm.created_at ASC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':conversation_id', $conversation_id, PDO::PARAM_INT);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $conversation_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($conversation_messages)) {
            $query = "UPDATE private_messages SET is_read = 1, read_at = NOW() 
                      WHERE (id = :conversation_id OR parent_message_id = :conversation_id) 
                      AND recipient_id = :student_id AND is_read = 0";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':conversation_id', $conversation_id, PDO::PARAM_INT);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log('Conversation query error: ' . $e->getMessage());
    }
}

// Tek mesaj detayı (duyurular için)
$message_detail = null;
if ($action === 'view' && $message_id > 0 && $message_type === 'announcements') {
    try {
        $query = "SELECT m.*, c.course_name, c.course_code, u.full_name as sender_name, mr.is_read, mr.read_at
                  FROM messages m
                  JOIN message_recipients mr ON m.id = mr.message_id
                  JOIN courses c ON m.course_id = c.id
                  LEFT JOIN users u ON m.sender_id = u.id
                  WHERE m.id = :message_id AND mr.recipient_id = :student_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':message_id', $message_id, PDO::PARAM_INT);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $message_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($message_detail && !$message_detail['is_read']) {
            $query = "UPDATE message_recipients SET is_read = 1, read_at = NOW() 
                      WHERE message_id = :message_id AND recipient_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':message_id', $message_id, PDO::PARAM_INT);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log('Message detail error: ' . $e->getMessage());
    }
}

$page_title = "Mesaj ve Duyurularım - " . htmlspecialchars($site_name);
include '../includes/components/student_header.php';
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
        border-left-color: <?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
        background-color: #f8f9fa;
        font-weight: 500;
    }
    .conversation-message {
        border-radius: 18px;
        max-width: 80%;
        word-wrap: break-word;
    }
    .conversation-message.sent {
        background: <?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
        color: white;
        margin-left: auto;
    }
    .conversation-message.received {
        background: #e9ecef;
        color: #333;
    }
    .conversation-container {
        height: 60vh;
        overflow-y: auto;
        background: white;
        border-radius: 10px;
        padding: 20px;
        border: 1px solid #dee2e6;
    }
    .message-tabs .nav-link.active {
        color: white;
        background: <?php echo $site_settings['theme_color'] ?? '#3498db'; ?>;
    }
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($action === 'view' && $message_detail): ?>
        <!-- Announcement Detail View -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="fas fa-bullhorn me-2"></i><?php echo htmlspecialchars($message_detail['subject']); ?></h3>
            <a href="messages.php?type=announcements" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Geri Dön</a>
        </div>
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <strong>Gönderen:</strong> <?php echo htmlspecialchars($message_detail['sender_name']); ?><br>
                        <small class="text-muted"><?php echo htmlspecialchars($message_detail['course_name']); ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($message_detail['created_at'])); ?></small>
                    </div>
                </div>
                <hr>
                <div><?php echo nl2br(htmlspecialchars($message_detail['message'])); ?></div>
            </div>
        </div>

    <?php elseif ($action === 'conversation' && !empty($conversation_messages)): ?>
        <!-- Conversation View -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="fas fa-comments me-2"></i><?php echo htmlspecialchars($conversation_messages[0]['subject']); ?></h3>
            <a href="messages.php?type=private" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Geri Dön</a>
        </div>
        <div class="conversation-container mb-4">
            <?php foreach ($conversation_messages as $msg): ?>
                <div class="d-flex justify-content-<?php echo $msg['sender_id'] == $student_id ? 'end' : 'start'; ?> mb-3">
                    <div class="conversation-message <?php echo $msg['sender_id'] == $student_id ? 'sent' : 'received'; ?> p-3">
                        <p class="mb-1"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                        <small class="opacity-75"><?php echo htmlspecialchars($msg['sender_name']); ?> • <?php echo date('H:i', strtotime($msg['created_at'])); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-body">
                <form method="POST" action="messages.php?action=send">
                    <input type="hidden" name="course_id" value="<?php echo $conversation_messages[0]['course_id']; ?>">
                    <input type="hidden" name="subject" value="Re: <?php echo htmlspecialchars($conversation_messages[0]['subject']); ?>">
                    <input type="hidden" name="parent_id" value="<?php echo $conversation_id; ?>">
                    <div class="mb-3"><textarea class="form-control" name="message" rows="3" required placeholder="Yanıtınızı yazın..."></textarea></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Yanıt Gönder</button>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- Messages List View -->
        <ul class="nav nav-pills mb-4 message-tabs">
            <li class="nav-item"><a class="nav-link <?php if($message_type === 'announcements') echo 'active'; ?>" href="?type=announcements">Duyurular <?php if($unread_announcements > 0) echo "<span class='badge bg-warning ms-1'>{$unread_announcements}</span>"; ?></a></li>
            <li class="nav-item"><a class="nav-link <?php if($message_type === 'private') echo 'active'; ?>" href="?type=private">Özel Mesajlar <?php if($unread_private > 0) echo "<span class='badge bg-warning ms-1'>{$unread_private}</span>"; ?></a></li>
        </ul>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="btn-group">
                        <a href="?type=<?php echo $message_type; ?>&filter=all" class="btn btn-outline-secondary <?php if($filter === 'all') echo 'active'; ?>">Tümü</a>
                        <a href="?type=<?php echo $message_type; ?>&filter=unread" class="btn btn-outline-secondary <?php if($filter === 'unread') echo 'active'; ?>">Okunmamış</a>
                    </div>
                    <?php if ($message_type === 'private'): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal"><i class="fas fa-plus me-1"></i>Yeni Mesaj</button>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($messages)): ?>
                    <p class="text-center text-muted">Görüntülenecek mesaj bulunmuyor.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($messages as $message): ?>
                        <?php if ($message_type === 'private'): ?>
                            <a href="?action=conversation&conversation=<?php echo $message['conversation_id']; ?>" class="list-group-item list-group-item-action <?php if($message['unread_count'] > 0) echo 'fw-bold'; ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($message['conversation_subject']); ?></h6>
                                    <small><?php echo date('d.m.Y', strtotime($message['last_message_time'])); ?></small>
                                </div>
                                <p class="mb-1"><strong><?php echo htmlspecialchars($message['other_person']); ?></strong> - <?php echo htmlspecialchars($message['course_name']); ?></p>
                            </a>
                        <?php else: ?>
                            <a href="?action=view&id=<?php echo $message['id']; ?>&type=announcements" class="list-group-item list-group-item-action <?php if(!$message['is_read']) echo 'fw-bold'; ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($message['subject']); ?></h6>
                                    <small><?php echo date('d.m.Y', strtotime($message['created_at'])); ?></small>
                                </div>
                                <p class="mb-1"><?php echo htmlspecialchars($message['sender_name']); ?> - <?php echo htmlspecialchars($message['course_name']); ?></p>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- New Message Modal -->
<div class="modal fade" id="newMessageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="messages.php?action=send">
                <div class="modal-header"><h5 class="modal-title">Yeni Mesaj Gönder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label for="course_id" class="form-label">Ders (Öğretmen)</label><select class="form-select" name="course_id" required><option value="">Seçiniz...</option><?php foreach ($student_courses as $course): ?><option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['course_name'] . ' - ' . $course['teacher_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label for="subject" class="form-label">Konu</label><input type="text" class="form-control" name="subject" required></div>
                    <div class="mb-3"><label for="message" class="form-label">Mesaj</label><textarea class="form-control" name="message" rows="4" required></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Gönder</button></div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/components/shared_footer.php'; ?>
