<?php
// users.php

// --- Initialization and Security ---
require_once '../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('admin')) {
    show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
    redirect('../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// --- Configuration ---
$action = $_GET['action'] ?? 'list';
$user_id = (int)($_GET['id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// --- Form & Action Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $handler_action = $_POST['action'] ?? $action;

    try {
        $db->beginTransaction();

        switch ($handler_action) {
            case 'import_csv':
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Dosya yüklenirken bir hata oluştu veya dosya seçilmedi.");
                }
                $file_path = $_FILES['csv_file']['tmp_name'];

                $file = fopen($file_path, 'r');
                if ($file === false) throw new Exception("Yüklenen dosya açılamadı.");

                $imported_count = 0;
                $skipped_count = 0;
                $error_lines = [];

                $check_user_stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR student_number = ?");
                $insert_user_stmt = $db->prepare("INSERT INTO users (full_name, student_number, email, password, user_type) VALUES (?, ?, ?, ?, 'student')");

                // Skip header row
                fgetcsv($file); 
                $line_number = 1;

                while (($row = fgetcsv($file)) !== FALSE) {
                    $line_number++;
                    // CSV Columns: username,lastname,firstname,password,email
                    if (count($row) < 5) {
                        $error_lines[] = $line_number;
                        continue;
                    }
                    
                    $student_number = trim($row[0]);
                    $lastname = trim($row[1]);
                    $firstname = trim($row[2]);
                    $password = trim($row[3]);
                    $email = trim($row[4]);

                    if (empty($student_number) || empty($firstname) || empty($lastname) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error_lines[] = $line_number;
                        continue;
                    }

                    // Check for duplicates
                    $check_user_stmt->execute([$email, $student_number]);
                    if ($check_user_stmt->fetch()) {
                        $skipped_count++;
                        continue;
                    }

                    $full_name = $firstname . ' ' . $lastname;
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    if ($insert_user_stmt->execute([$full_name, $student_number, $email, $hashed_password])) {
                        $imported_count++;
                    } else {
                        $error_lines[] = $line_number;
                    }
                }
                fclose($file);

                $message = "{$imported_count} öğrenci başarıyla aktarıldı.";
                if ($skipped_count > 0) $message .= " {$skipped_count} öğrenci (e-posta/öğrenci no zaten kayıtlı olduğu için) atlandı.";
                if (!empty($error_lines)) $message .= " Hatalı veya eksik bilgi içeren satırlar (" . count($error_lines) . " adet) işlenemedi.";
                
                $_SESSION['message'] = ['text' => $message, 'type' => 'success'];
                break;

            case 'upload_profile_image':
                $target_user_id = (int)($_POST['target_user_id'] ?? 0);
                if ($target_user_id <= 0) {
                    throw new Exception("Geçersiz kullanıcı ID.");
                }

                if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Profil resmi yüklenirken hata oluştu.");
                }

                $file = $_FILES['profile_image'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $max_size = 2 * 1024 * 1024; // 2MB

                if (!in_array($file['type'], $allowed_types) || $file['size'] > $max_size) {
                    throw new Exception("Geçersiz dosya formatı veya boyutu. (Max: 2MB, Format: JPG, PNG, GIF)");
                }

                $upload_dir = '../assets/images/profiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'profile_' . $target_user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                $db_path = 'assets/images/profiles/' . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Delete old image
                    $stmt = $db->prepare("SELECT profile_image FROM users WHERE id = ?");
                    $stmt->execute([$target_user_id]);
                    $old_image = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($old_image && !empty($old_image['profile_image']) && file_exists('../' . $old_image['profile_image'])) {
                        unlink('../' . $old_image['profile_image']);
                    }

                    // Update database
                    $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$db_path, $target_user_id]);
                    $_SESSION['message'] = ['text' => 'Profil resmi başarıyla güncellendi.', 'type' => 'success'];
                } else {
                    throw new Exception("Dosya yüklenirken hata oluştu.");
                }
                break;

            case 'delete_profile_image':
                $target_user_id = (int)($_POST['target_user_id'] ?? 0);
                if ($target_user_id <= 0) {
                    throw new Exception("Geçersiz kullanıcı ID.");
                }

                $stmt = $db->prepare("SELECT profile_image FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user_data && !empty($user_data['profile_image'])) {
                    if (file_exists('../' . $user_data['profile_image'])) {
                        unlink('../' . $user_data['profile_image']);
                    }
                    $stmt = $db->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
                    $stmt->execute([$target_user_id]);
                    $_SESSION['message'] = ['text' => 'Profil resmi başarıyla silindi.', 'type' => 'success'];
                }
                break;

            case 'add':
            case 'edit':
                $userIdPost = (int)($_POST['user_id'] ?? 0);
                $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
                $full_name = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_STRING);
                $user_type = filter_input(INPUT_POST, 'user_type', FILTER_SANITIZE_STRING);
                $student_number = !empty(trim($_POST['student_number'] ?? '')) ? filter_input(INPUT_POST, 'student_number', FILTER_SANITIZE_STRING) : null;
                $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $password = $_POST['password'] ?? '';

                if (!$email || empty($full_name) || empty($user_type)) {
                    throw new Exception("Lütfen tüm zorunlu alanları (* ile işaretli) doldurun.");
                }
                
                // Prevent admin from changing their own role or status
                if ($userIdPost === $current_user_id) {
                    $stmt = $db->prepare("SELECT user_type, is_active FROM users WHERE id = ?");
                    $stmt->execute([$current_user_id]);
                    $self_user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_type = $self_user['user_type'];
                    $is_active = $self_user['is_active'];
                }

                // Check for duplicate email
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $userIdPost]);
                if ($stmt->fetch()) {
                    throw new Exception("Bu e-posta adresi zaten başka bir kullanıcı tarafından kullanılıyor.");
                }
                // Check for duplicate student number
                if ($student_number !== null) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE student_number = ? AND id != ?");
                    $stmt->execute([$student_number, $userIdPost]);
                    if ($stmt->fetch()) {
                        throw new Exception("Bu öğrenci numarası zaten başka bir kullanıcı tarafından kullanılıyor.");
                    }
                }

                if ($handler_action === 'edit') {
                    $sql = "UPDATE users SET email = ?, full_name = ?, user_type = ?, student_number = ?, phone = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                    $params = [$email, $full_name, $user_type, $student_number, $phone, $is_active, $userIdPost];
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashed_password, $userIdPost]);
                    }
                    $_SESSION['message'] = ['text' => 'Kullanıcı başarıyla güncellendi.', 'type' => 'success'];

                } else { // Add
                    if (empty($password)) {
                        throw new Exception("Yeni kullanıcı için şifre zorunludur.");
                    }
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "INSERT INTO users (email, password, full_name, user_type, student_number, phone) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$email, $hashed_password, $full_name, $user_type, $student_number, $phone]);
                    $_SESSION['message'] = ['text' => 'Kullanıcı başarıyla eklendi.', 'type' => 'success'];
                }
                break;
        }
        
        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['message'] = ['text' => 'Hata: ' . $e->getMessage(), 'type' => 'danger'];
    }

    redirect('users.php');
    exit;
}

// --- GET Request Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'suspend') {
    if ($user_id === $current_user_id) {
        $_SESSION['message'] = ['text' => 'Kendi hesabınızı askıya alamazsınız.', 'type' => 'danger'];
    } else {
        $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            $_SESSION['message'] = ['text' => 'Kullanıcı başarıyla askıya alındı.', 'type' => 'success'];
        } else {
            $_SESSION['message'] = ['text' => 'İşlem sırasında bir hata oluştu.', 'type' => 'danger'];
        }
    }
    redirect('users.php');
    exit;
}

// --- Data Fetching for Page Display ---
$page_data = [];
$page_title = "Kullanıcı Yönetimi";

switch ($action) {
    case 'add':
    case 'edit':
        $page_title = $action === 'add' ? 'Yeni Kullanıcı Ekle' : 'Kullanıcı Düzenle';
        if ($action === 'edit') {
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $page_data['user'] = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$page_data['user']) {
                show_message('Kullanıcı bulunamadı.', 'danger');
                redirect('users.php');
            }
        }
        break;
    
    case 'list':
    default:
        $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
        $page_data['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

include '../includes/components/admin_header.php';
?>
<style>
    .user-card { 
        transition: all 0.2s ease-in-out; 
        border-left: 5px solid #eee; 
        border-radius: .375rem;
    }
    .user-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15) !important;
    }
    .user-card.type-admin { border-left-color: var(--bs-danger); }
    .user-card.type-teacher { border-left-color: var(--bs-warning); }
    .user-card.type-student { border-left-color: var(--bs-info); }
    
    .profile-image-lg {
        width: 120px;
        height: 120px;
        object-fit: cover;
    }
    .profile-icon-placeholder-lg {
        width: 120px;
        height: 120px;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #adb5bd;
        font-size: 3rem;
    }
    .profile-image-sm {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        object-fit: cover;
    }
    .profile-icon-placeholder-sm {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #e9ecef;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6c757d;
        font-size: 1.1rem;
    }
    .card-body .text-muted {
        color: #6c757d !important;
    }
</style>

<div class="container-fluid p-4">
    <?php display_message(); ?>

    <?php if ($action === 'add' || $action === 'edit'): ?>
       <!-- Add/Edit Form View -->
<div class="row justify-content-center">
    <div class="col-xl-10 col-lg-12">
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h4 class="mb-0"><i class="fas fa-user-edit me-2"></i><?php echo $page_title; ?></h4>
            </div>
            <div class="card-body p-4">
                <!-- SINGLE MAIN FORM -->
                <form method="POST" action="users.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $action; ?>">
                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                    <input type="hidden" name="target_user_id" value="<?php echo $user_id; ?>">

                    <div class="row">
                        <!-- Profile Image Section (only on edit) -->
                        <?php if ($action === 'edit'): ?>
                        <div class="col-lg-4 text-center mb-4 mb-lg-0">
                            <h5>Profil Resmi</h5>
                            <hr class="mt-2 mb-3">
                            <?php if (!empty($page_data['user']['profile_image']) && file_exists('../' . $page_data['user']['profile_image'])): ?>
                                <img src="../<?php echo htmlspecialchars($page_data['user']['profile_image']); ?>" alt="Profil" class="rounded-circle profile-image-lg shadow-sm">
                            <?php else: ?>
                                <div class="rounded-circle profile-icon-placeholder-lg d-inline-flex shadow-sm">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Image upload controls (NO NESTED FORM) -->
                            <div class="mt-3">
                                <div class="input-group input-group-sm">
                                    <input type="file" class="form-control" name="profile_image" accept="image/jpeg,image/png,image/gif">
                                    <!-- This button submits the main form but with a specific action -->
                                    <button type="submit" name="action" value="upload_profile_image" class="btn btn-outline-primary">Yükle</button>
                                </div>
                                <small class="text-muted d-block text-start">Max: 2MB</small>
                            </div>

                            <?php if (!empty($page_data['user']['profile_image'])): ?>
                            <!-- Image deletion control (NO NESTED FORM) -->
                            <div class="mt-2 d-grid">
                                <!-- This button also submits the main form with its own action -->
                                <button type="submit" name="action" value="delete_profile_image" class="btn btn-outline-danger btn-sm" onclick="return confirm('Profil resmini silmek istediğinizden emin misiniz?')">
                                    <i class="fas fa-trash me-1"></i> Resmi Sil
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- User Details Section -->
                        <div class="<?php echo ($action === 'edit') ? 'col-lg-8' : 'col-lg-12'; ?>">
                            <h5>Temel Bilgiler</h5>
                            <hr class="mt-2 mb-3">
                            <div class="row">
                                <div class="col-md-6 mb-3"><label for="full_name" class="form-label">Ad Soyad*</label><input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($page_data['user']['full_name'] ?? ''); ?>" required></div>
                                <div class="col-md-6 mb-3"><label for="email" class="form-label">E-posta*</label><input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($page_data['user']['email'] ?? ''); ?>" required></div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Şifre <?php if($action === 'add') echo '*'; ?></label>
                                <input type="password" class="form-control" id="password" name="password" <?php if($action === 'add') echo 'required'; ?>>
                                <?php if($action === 'edit'): ?><small class="form-text text-muted">Şifreyi değiştirmek istemiyorsanız bu alanı boş bırakın.</small><?php endif; ?>
                            </div>

                            <h5 class="mt-4">Rol ve Detaylar</h5>
                            <hr class="mt-2 mb-3">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="user_type" class="form-label">Kullanıcı Tipi*</label>
                                    <select class="form-select" id="user_type" name="user_type" required <?php if ($user_id === $current_user_id) echo 'disabled'; ?>>
                                        <option value="admin" <?php if(isset($page_data['user']) && $page_data['user']['user_type'] == 'admin') echo 'selected'; ?>>Admin</option>
                                        <option value="teacher" <?php if(isset($page_data['user']) && $page_data['user']['user_type'] == 'teacher') echo 'selected'; ?>>Öğretmen</option>
                                        <option value="student" <?php if(isset($page_data['user']) && $page_data['user']['user_type'] == 'student') echo 'selected'; ?>>Öğrenci</option>
                                    </select>
                                    <?php if ($user_id === $current_user_id): ?><small class="form-text text-danger">Kendi rolünüzü değiştiremezsiniz.</small><?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3" id="student_number_group">
                                    <label for="student_number" class="form-label">Öğrenci Numarası</label>
                                    <input type="text" class="form-control" id="student_number" name="student_number" value="<?php echo htmlspecialchars($page_data['user']['student_number'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mb-3"><label for="phone" class="form-label">Telefon</label><input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($page_data['user']['phone'] ?? ''); ?>"></div>
                            
                            <?php if ($action === 'edit'): ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" <?php if(isset($page_data['user']) && $page_data['user']['is_active']) echo 'checked'; ?> <?php if ($user_id === $current_user_id) echo 'disabled'; ?>>
                                <label class="form-check-label" for="is_active">Kullanıcı Aktif</label>
                                <?php if ($user_id === $current_user_id): ?><small class="form-text text-danger ms-2">Kendi durumunuzu değiştiremezsiniz.</small><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4 border-top pt-3">
                        <a href="users.php" class="btn btn-secondary me-2">İptal</a>
                        <!-- This is the main submit button, it will use the hidden action 'edit' -->
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?php echo $action === 'edit' ? 'Değişiklikleri Kaydet' : 'Kullanıcıyı Ekle'; ?></button>
                    </div>
                </form>
                <!-- END OF SINGLE MAIN FORM -->
            </div>
        </div>
    </div>
</div>


    <?php else: ?>
        <!-- User List View -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><i class="fas fa-users me-2"></i>Kullanıcılar</h2>
            <div>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-csv me-2"></i>CSV ile Aktar
                </button>
                <a href="users.php?action=add" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Yeni Kullanıcı</a>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="input-group mb-4">
                    <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                    <input type="text" id="userSearch" class="form-control" placeholder="Kullanıcı ara (Ad, E-posta, Rol)...">
                </div>
                <div class="row">
                    <?php if(empty($page_data['users'])): ?>
                        <div class="col-12 text-center py-5"><p class="text-muted fs-5">Sistemde kayıtlı kullanıcı bulunmuyor.</p></div>
                    <?php else: foreach ($page_data['users'] as $user): ?>
                    <div class="col-md-6 col-lg-4 mb-4 user-item" data-search-term="<?php echo strtolower(htmlspecialchars($user['full_name'] . ' ' . $user['email'] . ' ' . $user['user_type'])); ?>">
                        <div class="card h-100 user-card shadow-sm type-<?php echo $user['user_type']; ?>">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profil" class="profile-image-sm me-3">
                                    <?php else: ?>
                                        <div class="profile-icon-placeholder-sm me-3"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                    <h5 class="mb-0 fs-6"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                                </div>
                                <span class="badge rounded-pill bg-<?php echo $user['is_active'] ? 'success-subtle text-success-emphasis' : 'secondary-subtle text-secondary-emphasis'; ?>"><?php echo $user['is_active'] ? 'Aktif' : 'Askıda'; ?></span>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <div class="flex-grow-1">
                                    <p class="card-text mb-2 d-flex align-items-center"><i class="fas fa-envelope fa-fw me-2 text-muted"></i><?php echo htmlspecialchars($user['email']); ?></p>
                                    <p class="card-text mb-2 d-flex align-items-center">
                                        <i class="fas fa-user-shield fa-fw me-2 text-muted"></i>
                                        <?php 
                                            $roles = ['admin' => 'Admin', 'teacher' => 'Öğretmen', 'student' => 'Öğrenci'];
                                            echo $roles[$user['user_type']];
                                        ?>
                                    </p>
                                    <?php if($user['user_type'] === 'student' && !empty($user['student_number'])): ?>
                                    <p class="card-text mb-2 d-flex align-items-center"><i class="fas fa-hashtag fa-fw me-2 text-muted"></i><?php echo htmlspecialchars($user['student_number']); ?></p>
                                    <?php endif; ?>
                                    <p class="card-text mb-2 d-flex align-items-center"><i class="fas fa-calendar-alt fa-fw me-2 text-muted"></i><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></p>
                                </div>
                                <div class="mt-3 text-end">
                                    <a href="users.php?action=edit&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary">Düzenle</a>
                                    <?php if ($user['id'] !== $current_user_id && $user['is_active']): ?>
                                    <a href="users.php?action=suspend&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-warning" onclick="return confirm('`<?php echo htmlspecialchars($user['full_name']); ?>` adlı kullanıcıyı askıya almak istediğinizden emin misiniz? Kullanıcı tekrar aktifleştirilene kadar sisteme giriş yapamaz.');">Askıya Al</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- CSV Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="users.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_csv">
        <div class="modal-header">
          <h5 class="modal-title" id="importModalLabel">Öğrencileri CSV ile Aktar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Lütfen aşağıdaki sütun başlıklarına sahip bir CSV dosyası yükleyin:</p>
          <p><code>username,lastname,firstname,password,email</code></p>
          <div class="mb-3 mt-3">
            <label for="csv_file" class="form-label">CSV Dosyası Seçin</label>
            <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
          </div>
          <div class="alert alert-info small">
            <strong>Not:</strong> 
            <ul>
                <li><code>username</code> alanı <strong>Öğrenci Numarası</strong> olarak kullanılacaktır.</li>
                <li><code>password</code> alanı öğrencinin ilk şifresi olacaktır (örn: TC Kimlik No).</li>
                <li>Tüm kullanıcılar otomatik olarak 'öğrenci' rolü ile oluşturulacaktır.</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
          <button type="submit" class="btn btn-primary">Yükle ve Aktar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
    // User search filter
    document.getElementById('userSearch')?.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('.user-item').forEach(item => {
            item.style.display = item.dataset.searchTerm.includes(filter) ? '' : 'none';
        });
    });

    // Toggle student number field based on user type
    const userTypeSelect = document.getElementById('user_type');
    const studentNumberGroup = document.getElementById('student_number_group');
    function toggleStudentNumberField() {
        if (userTypeSelect && studentNumberGroup) {
            studentNumberGroup.style.display = userTypeSelect.value === 'student' ? 'block' : 'none';
        }
    }
    userTypeSelect?.addEventListener('change', toggleStudentNumberField);
    window.addEventListener('DOMContentLoaded', toggleStudentNumberField);
</script>
<?php include '../includes/components/shared_footer.php'; ?>
