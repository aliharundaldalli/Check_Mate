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
    error_log('Student Profile Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Kullanıcı bilgilerini al
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $student_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

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

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Email güncelleme
    if (isset($_POST['update_email'])) {
        $new_email = sanitize_input($_POST['email']);
        
        // Email kontrolü
        if (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            // Email zaten kullanılıyor mu kontrol et
            $check_query = "SELECT id FROM users WHERE email = :email AND id != :user_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':email', $new_email);
            $check_stmt->bindParam(':user_id', $student_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                $update_query = "UPDATE users SET email = :email WHERE id = :user_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':email', $new_email);
                $update_stmt->bindParam(':user_id', $student_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['user_email'] = $new_email;
                    $user['email'] = $new_email;
                    show_message('E-posta adresiniz başarıyla güncellendi.', 'success');
                } else {
                    show_message('E-posta güncellenirken bir hata oluştu.', 'danger');
                }
            } else {
                show_message('Bu e-posta adresi zaten kullanılıyor.', 'warning');
            }
        } else {
            show_message('Geçerli bir e-posta adresi girin.', 'warning');
        }
    }
    
    // Şifre değiştirme
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 6) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $update_query = "UPDATE users SET password = :password WHERE id = :user_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed_password);
                    $update_stmt->bindParam(':user_id', $student_id);
                    
                    if ($update_stmt->execute()) {
                        $user['password'] = $hashed_password;
                        show_message('Şifreniz başarıyla değiştirildi.', 'success');
                    } else {
                        show_message('Şifre değiştirilirken bir hata oluştu.', 'danger');
                    }
                } else {
                    show_message('Yeni şifre en az 6 karakter olmalıdır.', 'warning');
                }
            } else {
                show_message('Yeni şifreler eşleşmiyor.', 'warning');
            }
        } else {
            show_message('Mevcut şifreniz yanlış.', 'danger');
        }
    }
    
    // Profil resmi yükleme
    if (isset($_POST['upload_image']) && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];
        
        if ($file['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
                $upload_dir = '../assets/images/profiles/';
                
                // Özel dosya ismi: profile_userID_timestamp
                $custom_name = 'profile_' . $student_id . '_' . time();
                
                $file_item = [
                    'name' => $file['name'],
                    'type' => $file['type'],
                    'tmp_name' => $file['tmp_name'],
                    'error' => $file['error'],
                    'size' => $file['size']
                ];
                
                $upload_result = secure_file_upload($file_item, $upload_dir, 'jpg,jpeg,png,gif', 2, $custom_name); // 2MB limit
                
                if ($upload_result['status']) {
                    $db_path = 'assets/images/profiles/' . $upload_result['stored_filename'];
                    
                    // Eski resmi sil
                    if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])) {
                        unlink('../' . $user['profile_image']);
                    }
                    
                    // Veritabanını güncelle
                    $update_query = "UPDATE users SET profile_image = :profile_image WHERE id = :user_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':profile_image', $db_path);
                    $update_stmt->bindParam(':user_id', $student_id);
                    
                    if ($update_stmt->execute()) {
                        $user['profile_image'] = $db_path;
                        show_message('Profil resminiz başarıyla güncellendi.', 'success');
                    } else {
                        show_message('Profil resmi veritabanına kaydedilirken hata oluştu.', 'danger');
                    }
                } else {
                    show_message($upload_result['message'], 'danger');
                }
            } else {
                show_message('Geçersiz dosya formatı veya boyutu. (Max: 2MB, Format: JPG, PNG, GIF)', 'warning');
            }
        } else {
            show_message('Dosya yükleme hatası.', 'danger');
        }
    }
    
    // Profil resmi silme
    if (isset($_POST['delete_image'])) {
        if (!empty($user['profile_image'])) {
            // Dosyayı sil
            if (file_exists('../' . $user['profile_image'])) {
                unlink('../' . $user['profile_image']);
            }
            
            // Veritabanından kaldır
            $update_query = "UPDATE users SET profile_image = NULL WHERE id = :user_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':user_id', $student_id);
            
            if ($update_stmt->execute()) {
                $user['profile_image'] = null;
                show_message('Profil resminiz başarıyla silindi.', 'success');
            } else {
                show_message('Profil resmi silinirken hata oluştu.', 'danger');
            }
        }
    }
}

// Set page title
$page_title = "Profil Bilgilerim - " . htmlspecialchars($site_name);

// Include header
include '../includes/components/student_header.php';
?>

<style>
    .profile-card {
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        border-radius: 15px;
        overflow: hidden;
    }
    .profile-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        text-align: center;
    }
    .profile-image {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 4px solid white;
        object-fit: cover;
        margin: 0 auto;
        display: block;
    }
    .profile-image-placeholder {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 4px solid white;
        background: rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        font-size: 3rem;
    }
    .form-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .section-title {
        color: #333;
        font-weight: 600;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #eee;
    }
</style>

<?php display_message(); ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <!-- Profile Card -->
        <div class="profile-card mb-4">
            <div class="profile-header">
                <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                    <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profil Resmi" class="profile-image">
                <?php else: ?>
                    <div class="profile-image-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
                <h3 class="mt-3 mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p class="mb-0">
                    <i class="fas fa-id-card me-2"></i>
                    Öğrenci No: <?php echo htmlspecialchars($user['student_number']); ?>
                </p>
                <p class="mb-0">
                    <i class="fas fa-envelope me-2"></i>
                    <?php echo htmlspecialchars($user['email']); ?>
                </p>
                <?php if (!empty($user['phone'])): ?>
                <p class="mb-0">
                    <i class="fas fa-phone me-2"></i>
                    <?php echo htmlspecialchars($user['phone']); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Image Upload -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-camera me-2"></i>Profil Resmi
            </h5>
            
            <form method="POST" enctype="multipart/form-data" class="mb-3">
                <div class="row align-items-end">
                    <div class="col-md-8">
                        <label for="profile_image" class="form-label">Yeni Profil Resmi Seçin</label>
                        <input type="file" class="form-control" id="profile_image" name="profile_image" 
                               accept="image/jpeg,image/png,image/gif" required>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Maksimum 2MB, JPG/PNG/GIF formatları desteklenir.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="upload_image" class="btn btn-primary w-100">
                            <i class="fas fa-upload me-1"></i>Yükle
                        </button>
                    </div>
                </div>
            </form>

            <?php if (!empty($user['profile_image'])): ?>
            <form method="POST" style="display: inline;">
                <button type="submit" name="delete_image" class="btn btn-outline-danger btn-sm" 
                        onclick="return confirm('Profil resminizi silmek istediğinizden emin misiniz?')">
                    <i class="fas fa-trash me-1"></i>Resmi Sil
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Email Update -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-envelope me-2"></i>E-posta Adresi Güncelle
            </h5>
            
            <form method="POST">
                <div class="row align-items-end">
                    <div class="col-md-8">
                        <label for="email" class="form-label">Yeni E-posta Adresi</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="update_email" class="btn btn-success w-100">
                            <i class="fas fa-save me-1"></i>Güncelle
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Password Change -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-lock me-2"></i>Şifre Değiştir
            </h5>
            
            <form method="POST">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="current_password" class="form-label">Mevcut Şifre</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="new_password" class="form-label">Yeni Şifre</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" 
                               minlength="6" required>
                        <div class="form-text">En az 6 karakter olmalıdır.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label">Yeni Şifre Tekrar</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               minlength="6" required>
                    </div>
                </div>
                <div class="text-end">
                    <button type="submit" name="change_password" class="btn btn-warning">
                        <i class="fas fa-key me-1"></i>Şifre Değiştir
                    </button>
                </div>
            </form>
        </div>

        <!-- Account Info -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-info-circle me-2"></i>Hesap Bilgileri
            </h5>
            
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                    <p><strong>Öğrenci No:</strong> <?php echo htmlspecialchars($user['student_number']); ?></p>
                    <p><strong>Kullanıcı Tipi:</strong> 
                        <span class="badge bg-info">
                            <i class="fas fa-user-graduate me-1"></i>Öğrenci
                        </span>
                    </p>
                </div>
                <div class="col-md-6">
                    <p><strong>Hesap Durumu:</strong> 
                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                            <?php echo $user['is_active'] ? 'Aktif' : 'Pasif'; ?>
                        </span>
                    </p>
                    <p><strong>Kayıt Tarihi:</strong> <?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></p>
                    <p><strong>Son Güncelleme:</strong> <?php echo date('d.m.Y H:i', strtotime($user['updated_at'])); ?></p>
                </div>
            </div>
            
            <div class="alert alert-info mt-3">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Not:</strong> Ad soyad ve öğrenci numarası değişiklikleri için sistem yöneticinize başvurun.
            </div>
        </div>

    </div>
</div>

<script>
// Şifre eşleşme kontrolü
document.addEventListener('DOMContentLoaded', function() {
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    function checkPasswords() {
        if (newPassword.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Şifreler eşleşmiyor');
        } else {
            confirmPassword.setCustomValidity('');
        }
    }
    
    newPassword.addEventListener('input', checkPasswords);
    confirmPassword.addEventListener('input', checkPasswords);
});
</script>

<?php include '../includes/components/shared_footer.php'; ?>