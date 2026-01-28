<?php
// Handles marking a course material as completed for a student

header('Content-Type: application/json'); // Set header for JSON response
ini_set('log_errors', 1); // Hata loglamayı etkinleştir (genellikle zaten açıktır)
ini_set('error_log', '../../logs/ajax_errors.log'); // Hata log dosyasının yolu (logs klasörünün var olduğundan ve yazılabilir olduğundan emin olun)
error_log("--- mark_material_complete.php Başlatıldı ---"); // Log başlangıcı

try {
    // Corrected path based on student/ajax/ location
    require_once '../../includes/functions.php';
    error_log("functions.php yüklendi.");

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        error_log("Session başlatıldı.");
    }

    $response = ['status' => 'error', 'message' => 'Bilinmeyen bir hata oluştu.'];

    // Check if user is logged in and is a student
    $auth = new Auth();
    if (!$auth->isLoggedIn() || !$auth->checkRole('student')) {
        $response['message'] = 'Yetkisiz erişim.';
        error_log("Yetkisiz erişim denemesi. Session: " . print_r($_SESSION, true));
        echo json_encode($response);
        exit;
    }
    error_log("Yetki kontrolü başarılı. Öğrenci ID: " . $_SESSION['user_id']);

    // Check if material_id is provided via POST
    if (!isset($_POST['material_id']) || !is_numeric($_POST['material_id'])) {
        $response['message'] = 'Geçersiz materyal ID.';
        error_log("Geçersiz materyal ID. POST verisi: " . print_r($_POST, true));
        echo json_encode($response);
        exit;
    }

    $student_id = $_SESSION['user_id'];
    $material_id = (int)$_POST['material_id'];
    error_log("Alınan veriler - Student ID: $student_id, Material ID: $material_id");

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        error_log("Veritabanı bağlantısı kurulamadı.");
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    error_log("Veritabanı bağlantısı başarılı.");

    // Check if a record already exists
    error_log("Mevcut kayıt kontrol ediliyor...");
    $query_check = "SELECT id FROM student_material_progress
                    WHERE student_id = :student_id AND material_id = :material_id";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':material_id', $material_id, PDO::PARAM_INT);
    $stmt_check->execute();
    $existing_record = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_record) {
        error_log("Mevcut kayıt bulundu (ID: {$existing_record['id']}). Güncelleme deneniyor...");
        // Record exists, update it if not already completed
        $query_update = "UPDATE student_material_progress
                         SET is_completed = 1, completed_at = NOW(), progress_percentage = 100
                         WHERE id = :id AND is_completed = 0"; // Only update if not already completed
        $stmt_update = $db->prepare($query_update);
        $stmt_update->bindParam(':id', $existing_record['id'], PDO::PARAM_INT);
        if ($stmt_update->execute()) {
             $rowCount = $stmt_update->rowCount();
             if ($rowCount > 0) {
                 $response = ['status' => 'success', 'message' => 'Materyal tamamlandı olarak işaretlendi (güncellendi).'];
                 error_log("Materyal güncellendi (ID: {$existing_record['id']}).");
             } else {
                 $response = ['status' => 'success', 'message' => 'Materyal zaten tamamlanmış.'];
                 error_log("Materyal zaten tamamlanmış (ID: {$existing_record['id']}). Güncelleme yapılmadı.");
             }
        } else {
            $response['message'] = 'Materyal güncellenirken veritabanı hatası oluştu.';
            error_log("Materyal güncelleme hatası (ID: {$existing_record['id']}): " . print_r($stmt_update->errorInfo(), true));
        }
    } else {
        error_log("Mevcut kayıt bulunamadı. Yeni kayıt ekleniyor...");
        // Record does not exist, insert a new one
        $query_insert = "INSERT INTO student_material_progress
                         (student_id, material_id, is_completed, completed_at, progress_percentage)
                         VALUES
                         (:student_id, :material_id, 1, NOW(), 100)";
        $stmt_insert = $db->prepare($query_insert);
        $stmt_insert->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt_insert->bindParam(':material_id', $material_id, PDO::PARAM_INT);
        if ($stmt_insert->execute()) {
            $response = ['status' => 'success', 'message' => 'Materyal tamamlandı olarak işaretlendi (yeni kayıt).'];
            error_log("Yeni ilerleme kaydı eklendi. Student ID: $student_id, Material ID: $material_id");
        } else {
            $response['message'] = 'Materyal kaydedilirken veritabanı hatası oluştu.';
            error_log("Yeni ilerleme kaydı ekleme hatası: " . print_r($stmt_insert->errorInfo(), true));
        }
    }

} catch (PDOException $pdoEx) { // Catch PDO specific exceptions
    error_log('PDO Veritabanı Hatası: ' . $pdoEx->getMessage());
    $response = ['status' => 'error', 'message' => 'Veritabanı hatası oluştu.'];
} catch (Exception $e) {
    error_log('Genel Hata (Mark Material Complete): ' . $e->getMessage() . " Satır: " . $e->getLine());
    $response = ['status' => 'error', 'message' => 'Sunucu hatası oluştu.'];
}

error_log("Yanıt gönderiliyor: " . json_encode($response));
echo json_encode($response);
exit;
?>
