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
    
    // Admin yetkisi kontrolü
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
    
    $admin_id = $_SESSION['user_id'];
} catch (Exception $e) {
    error_log('Admin Profile Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Kullanıcı bilgilerini al
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $admin_id);
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
            $check_stmt->bindParam(':user_id', $admin_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                $update_query = "UPDATE users SET email = :email WHERE id = :user_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':email', $new_email);
                $update_stmt->bindParam(':user_id', $admin_id);
                
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
    
    // Telefon güncelleme
    if (isset($_POST['update_phone'])) {
        $new_phone = sanitize_input($_POST['phone']);
        
        $update_query = "UPDATE users SET phone = :phone WHERE id = :user_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':phone', $new_phone);
        $update_stmt->bindParam(':user_id', $admin_id);
        
        if ($update_stmt->execute()) {
            $user['phone'] = $new_phone;
            show_message('Telefon numaranız başarıyla güncellendi.', 'success');
        } else {
            show_message('Telefon numarası güncellenirken bir hata oluştu.', 'danger');
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
                    $update_stmt->bindParam(':user_id', $admin_id);
                    
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
                $custom_name = 'profile_' . $admin_id . '_' . time();
                
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
                    $update_stmt->bindParam(':user_id', $admin_id);
                    
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
            $update_stmt->bindParam(':user_id', $admin_id);
            
            if ($update_stmt->execute()) {
                $user['profile_image'] = null;
                show_message('Profil resminiz başarıyla silindi.', 'success');
            } else {
                show_message('Profil resmi silinirken hata oluştu.', 'danger');
            }
        }
    }
}

// Sistem istatistikleri
try {
    // Toplam kullanıcı sayıları - Dashboard'tan kopyalandı
    $query = "SELECT user_type, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY user_type";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $user_counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_counts[$row['user_type']] = $row['count'];
    }
    
    // Toplam ders sayısı
    $query = "SELECT COUNT(*) as count FROM courses WHERE is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_courses = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Toplam yoklama oturumu
    $query = "SELECT COUNT(*) as count FROM attendance_sessions WHERE is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Bugünkü oturum sayısı
    $query = "SELECT COUNT(*) as count FROM attendance_sessions WHERE DATE(session_date) = CURDATE() AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $today_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Toplam mesaj sayısı
    $query = "SELECT COUNT(*) as count FROM messages";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_messages = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Son 30 gündeki aktivite
    $query = "SELECT COUNT(*) as count FROM attendance_records WHERE attendance_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $monthly_activity = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch (Exception $e) {
    error_log('Admin stats error: ' . $e->getMessage());
    $user_counts = [];
    $total_courses = $total_sessions = $today_sessions = $total_messages = $monthly_activity = 0;
}

// Set page title
$page_title = "Yönetici Profili - " . htmlspecialchars($site_name);

// Include header
include '../includes/components/admin_header.php';
?>

<style>
    .profile-card {
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        border-radius: 15px;
        overflow: hidden;
    }
    .profile-header {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
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
    .stats-card {
        border-radius: 10px;
        text-align: center;
        padding: 1.5rem;
        color: white;
        margin-bottom: 1rem;
    }
    .stats-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
    .stats-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
    .stats-card.green { background: linear-gradient(135deg, #27ae60 0%, #229954 100%); }
    .stats-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
    .stats-card.purple { background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%); }
    .stats-card.dark { background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%); }
</style>

<?php display_message(); ?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <!-- Profile Card -->
        <div class="profile-card mb-4">
            <div class="profile-header">
                <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                    <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profil Resmi" class="profile-image">
                <?php else: ?>
                    <div class="profile-image-placeholder">
                        <i class="fas fa-user-shield"></i>
                    </div>
                <?php endif; ?>
                <h3 class="mt-3 mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p class="mb-0">
                    <i class="fas fa-user-shield me-2"></i>
                    Sistem Yöneticisi
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

        <!-- System Statistics -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-chart-bar me-2"></i>Sistem İstatistikleri
            </h5>
            
            <div class="row">
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card red">
                        <h3><?php echo $user_counts['admin'] ?? 0; ?></h3>
                        <p class="mb-0">Admin</p>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card orange">
                        <h3><?php echo $user_counts['teacher'] ?? 0; ?></h3>
                        <p class="mb-0">Öğretmen</p>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card blue">
                        <h3><?php echo $user_counts['student'] ?? 0; ?></h3>
                        <p class="mb-0">Öğrenci</p>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card green">
                        <h3><?php echo $total_courses ?? 0; ?></h3>
                        <p class="mb-0">Toplam Ders</p>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card purple">
                        <h3><?php echo $total_sessions ?? 0; ?></h3>
                        <p class="mb-0">Yoklama Oturumu</p>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="stats-card dark">
                        <h3><?php echo $today_sessions ?? 0; ?></h3>
                        <p class="mb-0">Bugünkü Oturum</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="stats-card blue">
                        <h3><?php echo $total_messages ?? 0; ?></h3>
                        <p class="mb-0">Toplam Duyuru</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card green">
                        <h3><?php echo $monthly_activity ?? 0; ?></h3>
                        <p class="mb-0">Aylık Yoklama</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <!-- Profile Image Upload -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-camera me-2"></i>Profil Resmi
                    </h5>
                    
                    <form method="POST" enctype="multipart/form-data" class="mb-3">
                        <div class="mb-3">
                            <label for="profile_image" class="form-label">Yeni Profil Resmi Seçin</label>
                            <input type="file" class="form-control" id="profile_image" name="profile_image" 
                                   accept="image/jpeg,image/png,image/gif" required>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Maksimum 2MB, JPG/PNG/GIF formatları desteklenir.
                            </div>
                        </div>
                        <button type="submit" name="upload_image" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i>Yükle
                        </button>
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
                        <i class="fas fa-envelope me-2"></i>E-posta Adresi
                    </h5>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta Adresi</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <button type="submit" name="update_email" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Güncelle
                        </button>
                    </form>
                </div>

                <!-- Phone Update -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-phone me-2"></i>Telefon Numarası
                    </h5>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="phone" class="form-label">Telefon Numarası</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                   placeholder="05xx xxx xx xx">
                        </div>
                        <button type="submit" name="update_phone" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Güncelle
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-6">
                <!-- Password Change -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-lock me-2"></i>Şifre Değiştir
                    </h5>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Mevcut Şifre</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Yeni Şifre</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   minlength="6" required>
                            <div class="form-text">En az 6 karakter olmalıdır.</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Yeni Şifre Tekrar</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   minlength="6" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-warning">
                            <i class="fas fa-key me-1"></i>Şifre Değiştir
                        </button>
                    </form>
                </div>

                <!-- Account Info -->
                <div class="form-section">
                    <h5 class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Hesap Bilgileri
                    </h5>
                    
                    <div class="mb-3">
                        <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                        <p><strong>E-posta:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><strong>Kullanıcı Tipi:</strong> 
                            <span class="badge bg-danger">
                                <i class="fas fa-user-shield me-1"></i>Sistem Yöneticisi
                            </span>
                        </p>
                        <p><strong>Hesap Durumu:</strong> 
                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                <?php echo $user['is_active'] ? 'Aktif' : 'Pasif'; ?>
                            </span>
                        </p>
                        <p><strong>Kayıt Tarihi:</strong> <?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></p>
                        <p><strong>Son Güncelleme:</strong> <?php echo date('d.m.Y H:i', strtotime($user['updated_at'])); ?></p>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Uyarı:</strong> Sistem yöneticisi hesabıdır. Değişiklikler dikkatli yapılmalıdır.
                    </div>
                </div>
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