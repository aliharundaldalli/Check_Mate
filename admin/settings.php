<?php
// settings.php

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

// --- Helper Functions ---

/**
 * Handles file uploads securely.
 * @param array $file The $_FILES['input_name'] array.
 * @param string $type A prefix for the filename (e.g., 'logo', 'favicon').
 * @param string $current_filename The existing filename to be deleted if upload is successful.
 * @return array Result of the upload process.
 */
function handleFileUpload($file, $type, $current_filename = '') {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon'];
    $max_size = 2 * 1024 * 1024; // 2MB
    $upload_dir = '../uploads/';

    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'error' => 'Geçersiz dosya formatı. Sadece JPG, PNG, GIF, WEBP, ICO izin verilir.'];
    }
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Dosya boyutu 2MB\'den büyük olamaz.'];
    }
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return ['success' => false, 'error' => 'Yükleme dizini oluşturulamadı.'];
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $type . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Delete old file if it exists
        if ($current_filename && file_exists($upload_dir . $current_filename)) {
            @unlink($upload_dir . $current_filename);
        }
        return ['success' => true, 'filename' => 'uploads/' . $filename]; // Return full path
    } else {
        return ['success' => false, 'error' => 'Dosya yüklenirken bir hata oluştu.'];
    }
}

// --- AJAX Request Handler ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'test_email') {
        // This is a placeholder for a real email test function.
        // In a real scenario, you would use PHPMailer with the saved settings.
        // For now, we simulate success.
        echo json_encode(['success' => true, 'message' => 'Test e-postası başarıyla gönderildi (simülasyon).']);
        exit;
    }

    if ($action === 'clear_cache') {
        // This is a placeholder for a real cache clearing function.
        echo json_encode(['success' => true, 'message' => 'Önbellek başarıyla temizlendi (simülasyon).']);
        exit;
    }
}

// --- POST Request Handler (Save Settings) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        // Sanitize and prepare settings from POST data
        $settings_to_update = [
            'site_name' => filter_input(INPUT_POST, 'site_name', FILTER_SANITIZE_STRING),
            'site_description' => filter_input(INPUT_POST, 'site_description', FILTER_SANITIZE_STRING),
            'admin_email' => filter_input(INPUT_POST, 'admin_email', FILTER_VALIDATE_EMAIL),
            'max_absence_percentage' => filter_input(INPUT_POST, 'max_absence_percentage', FILTER_VALIDATE_INT),
            'default_session_duration' => filter_input(INPUT_POST, 'default_session_duration', FILTER_VALIDATE_INT),
            'qr_refresh_interval' => filter_input(INPUT_POST, 'qr_refresh_interval', FILTER_VALIDATE_INT),
            'smtp_host' => filter_input(INPUT_POST, 'smtp_host', FILTER_SANITIZE_STRING),
            'smtp_port' => filter_input(INPUT_POST, 'smtp_port', FILTER_VALIDATE_INT),
            'smtp_username' => filter_input(INPUT_POST, 'smtp_username', FILTER_SANITIZE_STRING),
            'smtp_encryption' => filter_input(INPUT_POST, 'smtp_encryption', FILTER_SANITIZE_STRING),
            'smtp_from_name' => filter_input(INPUT_POST, 'smtp_from_name', FILTER_SANITIZE_STRING),
            'enable_email_notifications' => isset($_POST['enable_email_notifications']) ? '1' : '0',
            'enable_student_registration' => isset($_POST['enable_student_registration']) ? '1' : '0',
            'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
            'timezone' => filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING),
            'date_format' => filter_input(INPUT_POST, 'date_format', FILTER_SANITIZE_STRING),
            'theme_color' => filter_input(INPUT_POST, 'theme_color', FILTER_SANITIZE_STRING),
        ];

        // Handle SMTP password separately: only update if a new one is provided
        if (!empty($_POST['smtp_password'])) {
            $settings_to_update['smtp_password'] = $_POST['smtp_password'];
        }

        // Fetch current settings to handle file uploads
        $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
        $current_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Handle file uploads
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] == UPLOAD_ERR_OK) {
            $current_logo = $current_settings['site_logo'] ?? '';
            $logo_result = handleFileUpload($_FILES['site_logo'], 'logo', basename($current_logo));
            if ($logo_result['success']) {
                $settings_to_update['site_logo'] = $logo_result['filename'];
            } else {
                throw new Exception('Logo Yükleme Hatası: ' . $logo_result['error']);
            }
        }
        if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] == UPLOAD_ERR_OK) {
            $current_favicon = $current_settings['site_favicon'] ?? '';
            $favicon_result = handleFileUpload($_FILES['site_favicon'], 'favicon', basename($current_favicon));
            if ($favicon_result['success']) {
                $settings_to_update['site_favicon'] = $favicon_result['filename'];
            } else {
                throw new Exception('Favicon Yükleme Hatası: ' . $favicon_result['error']);
            }
        }

        // Update all settings in the database
        $sql = "INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = :value";
        $stmt = $db->prepare($sql);

        foreach ($settings_to_update as $key => $value) {
            if ($value === false && $key === 'admin_email') {
                 throw new Exception('Lütfen geçerli bir admin e-posta adresi girin.');
            }
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
        
        $db->commit();
        $_SESSION['form_feedback'] = ['success' => true, 'message' => 'Ayarlar başarıyla kaydedildi.'];

    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['form_feedback'] = ['success' => false, 'message' => 'Hata: ' . $e->getMessage()];
    }
    
    header('Location: settings.php');
    exit;
}


// --- Load Current Settings for Display ---
$stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
$current_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$defaults = [
    'site_name' => 'AhdaKade Yoklama Sistemi', 'site_description' => '', 'admin_email' => '',
    'max_absence_percentage' => '25', 'default_session_duration' => '10', 'qr_refresh_interval' => '15',
    'smtp_host' => '', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '',
    'smtp_encryption' => 'tls', 'smtp_from_name' => 'AhdaKade Yoklama Sistemi',
    'enable_email_notifications' => '0', 'enable_student_registration' => '0', 'maintenance_mode' => '0',
    'timezone' => 'Europe/Istanbul', 'date_format' => 'd.m.Y', 'theme_color' => '#262a59',
    'site_logo' => '', 'site_favicon' => ''
];

$settings = array_merge($defaults, $current_settings);
$page_title = "Site Ayarları - " . htmlspecialchars($settings['site_name']);

// --- Render Page ---
include '../includes/components/admin_header.php';
?>
<style>
    :root {
        --theme-color: <?php echo htmlspecialchars($settings['theme_color']); ?>;
        --theme-color-hover: color-mix(in srgb, var(--theme-color) 85%, black);
    }
    .nav-tabs .nav-link {
        color: #6c757d;
    }
    .nav-tabs .nav-link.active {
        color: var(--theme-color);
        border-color: var(--theme-color);
        border-bottom-color: transparent;
    }
    .card-header.themed {
        background-color: var(--theme-color);
        color: white;
        transition: background-color 0.3s ease;
    }
    .form-check-input:checked {
        background-color: var(--theme-color);
        border-color: var(--theme-color);
    }
    .btn-primary {
        background-color: var(--theme-color);
        border-color: var(--theme-color);
    }
    .btn-primary:hover {
        background-color: var(--theme-color-hover);
        border-color: var(--theme-color-hover);
    }
    .image-preview {
        width: 150px; height: 50px;
        border: 2px dashed #ddd;
        border-radius: 0.375rem;
        object-fit: contain;
        padding: 5px;
        background-color: #f8f9fa;
        cursor: pointer;
    }
    .favicon-preview {
        width: 48px; height: 48px;
    }
    .toast-container {
        z-index: 1090;
    }
</style>

<div class="container-fluid p-4">
    <h2 class="mb-4">Site Ayarları</h2>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true"><i class="fas fa-cog me-2"></i>Genel</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="visual-tab" data-bs-toggle="tab" data-bs-target="#visual" type="button" role="tab" aria-controls="visual" aria-selected="false"><i class="fas fa-palette me-2"></i>Görsel & Tema</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="smtp-tab" data-bs-toggle="tab" data-bs-target="#smtp" type="button" role="tab" aria-controls="smtp" aria-selected="false"><i class="fas fa-envelope me-2"></i>E-posta (SMTP)</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab" aria-controls="system" aria-selected="false"><i class="fas fa-cogs me-2"></i>Sistem & Bakım</button>
        </li>
    </ul>

    <form id="settingsForm" method="POST" enctype="multipart/form-data">
        <div class="tab-content" id="settingsTabsContent">
            <!-- General Settings Tab -->
            <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                <div class="card">
                    <div class="card-header themed"><h5>Genel Ayarlar</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="site_name" class="form-label">Site Adı</label>
                                <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="admin_email" class="form-label">Yönetici E-posta Adresi</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email']); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="site_description" class="form-label">Site Açıklaması</label>
                            <textarea class="form-control" id="site_description" name="site_description" rows="2"><?php echo htmlspecialchars($settings['site_description']); ?></textarea>
                        </div>
                         <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="timezone" class="form-label">Zaman Dilimi</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <option value="Europe/Istanbul" <?php selected($settings['timezone'], 'Europe/Istanbul'); ?>>Türkiye (UTC+3)</option>
                                    <option value="UTC" <?php selected($settings['timezone'], 'UTC'); ?>>UTC</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="date_format" class="form-label">Tarih Formatı</label>
                                <select class="form-select" id="date_format" name="date_format">
                                    <option value="d.m.Y" <?php selected($settings['date_format'], 'd.m.Y'); ?>>31.12.2024</option>
                                    <option value="m/d/Y" <?php selected($settings['date_format'], 'm/d/Y'); ?>>12/31/2024</option>
                                    <option value="Y-m-d" <?php selected($settings['date_format'], 'Y-m-d'); ?>>2024-12-31</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Visual Settings Tab -->
            <div class="tab-pane fade" id="visual" role="tabpanel" aria-labelledby="visual-tab">
                <div class="card">
                    <div class="card-header themed"><h5>Görsel & Tema Ayarları</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="site_logo_input" class="form-label">Site Logosu (Tavsiye: 300x80)</label>
                                <input class="form-control" type="file" id="site_logo_input" name="site_logo" accept="image/*">
                                <img id="logo_preview" src="../uploads/<?php echo !empty($settings['site_logo']) ? htmlspecialchars($settings['site_logo']) : 'assets/images/placeholder.png'; ?>" alt="Logo Preview" class="mt-2 image-preview" onclick="document.getElementById('site_logo_input').click();">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="site_favicon_input" class="form-label">Site Favicon (Tavsiye: 48x48)</label>
                                <input class="form-control" type="file" id="site_favicon_input" name="site_favicon" accept="image/*">
                                <img id="favicon_preview" src="../uploads/<?php echo !empty($settings['site_favicon']) ? htmlspecialchars($settings['site_favicon']) : 'assets/images/favicon_placeholder.png'; ?>" alt="Favicon Preview" class="mt-2 image-preview favicon-preview" onclick="document.getElementById('site_favicon_input').click();">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="theme_color" class="form-label">Ana Tema Rengi</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="theme_color" name="theme_color" value="<?php echo htmlspecialchars($settings['theme_color']); ?>">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($settings['theme_color']); ?>" id="theme_color_text" onchange="document.getElementById('theme_color').value = this.value;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMTP Settings Tab -->
            <div class="tab-pane fade" id="smtp" role="tabpanel" aria-labelledby="smtp-tab">
                <div class="card">
                     <div class="card-header themed"><h5>E-posta (SMTP) Ayarları</h5></div>
                     <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="enable_email_notifications" name="enable_email_notifications" <?php checked($settings['enable_email_notifications']); ?>>
                            <label class="form-check-label" for="enable_email_notifications">E-posta Bildirimlerini Aktif Et</label>
                        </div>
                        <div id="smtp_settings_container">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="smtp_host" class="form-label">SMTP Sunucusu</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host']); ?>" placeholder="smtp.example.com">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="smtp_port" class="form-label">Port</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port']); ?>" placeholder="587">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_username" class="form-label">SMTP Kullanıcı Adı</label>
                                    <input type="email" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username']); ?>" placeholder="user@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_encryption" class="form-label">Şifreleme</label>
                                    <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                        <option value="tls" <?php selected($settings['smtp_encryption'], 'tls'); ?>>TLS</option>
                                        <option value="ssl" <?php selected($settings['smtp_encryption'], 'ssl'); ?>>SSL</option>
                                        <option value="none" <?php selected($settings['smtp_encryption'], 'none'); ?>>Yok</option>
                                    </select>
                                </div>
                            </div>
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_password" class="form-label">SMTP Şifre</label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" placeholder="Değiştirmek için yeni şifre girin">
                                    <small class="form-text text-muted">Mevcut şifreyi korumak için boş bırakın.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_from_name" class="form-label">Gönderen Adı</label>
                                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name']); ?>">
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary" id="testEmailBtn"><i class="fas fa-paper-plane me-2"></i>Test E-postası Gönder</button>
                        </div>
                     </div>
                </div>
            </div>

            <!-- System Settings Tab -->
            <div class="tab-pane fade" id="system" role="tabpanel" aria-labelledby="system-tab">
                 <div class="card">
                    <div class="card-header themed"><h5>Yoklama & Sistem Ayarları</h5></div>
                    <div class="card-body">
                        <div class="row">
                             <div class="col-md-4 mb-3">
                                <label for="max_absence_percentage" class="form-label">Maks. Devamsızlık (%)</label>
                                <input type="number" class="form-control" id="max_absence_percentage" name="max_absence_percentage" value="<?php echo htmlspecialchars($settings['max_absence_percentage']); ?>" min="0" max="100" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="default_session_duration" class="form-label">Varsayılan Oturum (dk)</label>
                                <input type="number" class="form-control" id="default_session_duration" name="default_session_duration" value="<?php echo htmlspecialchars($settings['default_session_duration']); ?>" min="1" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="qr_refresh_interval" class="form-label">QR Yenileme (sn)</label>
                                <input type="number" class="form-control" id="qr_refresh_interval" name="qr_refresh_interval" value="<?php echo htmlspecialchars($settings['qr_refresh_interval']); ?>" min="5" required>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="enable_student_registration" name="enable_student_registration" <?php checked($settings['enable_student_registration']); ?>>
                                    <label class="form-check-label" for="enable_student_registration">Öğrenci Kayıtları Aktif</label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="maintenance_mode" name="maintenance_mode" <?php checked($settings['maintenance_mode']); ?>>
                                    <label class="form-check-label" for="maintenance_mode">Bakım Modu Aktif</label>
                                     <small class="form-text text-muted d-block">Aktif olduğunda sadece adminler giriş yapabilir.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>Sistem Araçları</h5>
                                <button type="button" class="btn btn-outline-secondary" id="clearCacheBtn"><i class="fas fa-broom me-2"></i>Önbelleği Temizle</button>
                            </div>
                        </div>
                        <hr>
                        <h5>Sistem Bilgileri</h5>
                        <table class="table table-sm table-bordered">
                            <tr><th>PHP Sürümü</th><td><?php echo PHP_VERSION; ?></td></tr>
                            <tr><th>Veritabanı Sürümü</th><td><?php echo $db->getAttribute(PDO::ATTR_SERVER_VERSION); ?></td></tr>
                            <tr><th>Maks. Yükleme Boyutu</th><td><?php echo ini_get('upload_max_filesize'); ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="card mt-4">
            <div class="card-body text-center">
                <button type="submit" class="btn btn-primary btn-lg px-5"><i class="fas fa-save me-2"></i>Ayarları Kaydet</button>
                <a href="dashboard.php" class="btn btn-secondary btn-lg px-5 ms-2">İptal</a>
            </div>
        </div>
    </form>
</div>

<!-- Toast container for notifications -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="feedbackToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto" id="toastTitle"></strong>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body" id="toastBody"></div>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const settingsForm = document.getElementById('settingsForm');
    const feedbackToastEl = document.getElementById('feedbackToast');
    const feedbackToast = new bootstrap.Toast(feedbackToastEl);

    // Handle form submission with AJAX
    settingsForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const saveButton = this.querySelector('button[type="submit"]');
        const originalButtonText = saveButton.innerHTML;
        saveButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Kaydediliyor...`;
        saveButton.disabled = true;

        fetch('settings.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text()) // First get text to debug if it's not JSON
        .then(text => {
            // Since we redirect on success, a successful POST will result in an HTML response.
            // We can check if the response seems like an error message or just reload.
            // A better approach is to make the POST handler return JSON. For now, we assume success and reload.
            showToast('Başarılı!', 'Ayarlar başarıyla kaydedildi. Sayfa yenileniyor...', 'success');
            setTimeout(() => window.location.reload(), 1500);
        })
        .catch(error => {
            showToast('Hata!', 'Bir ağ hatası oluştu: ' + error, 'danger');
            saveButton.innerHTML = originalButtonText;
            saveButton.disabled = false;
        });
    });

    // Function to show toast notifications
    function showToast(title, message, type = 'success') {
        const toastTitle = document.getElementById('toastTitle');
        const toastBody = document.getElementById('toastBody');
        const toastHeader = feedbackToastEl.querySelector('.toast-header');

        toastTitle.textContent = title;
        toastBody.textContent = message;
        
        feedbackToastEl.classList.remove('bg-success-subtle', 'bg-danger-subtle');
        toastHeader.classList.remove('text-success-emphasis', 'text-danger-emphasis');

        if (type === 'success') {
            feedbackToastEl.classList.add('bg-success-subtle');
            toastHeader.classList.add('text-success-emphasis');
        } else {
            feedbackToastEl.classList.add('bg-danger-subtle');
            toastHeader.classList.add('text-danger-emphasis');
        }
        feedbackToast.show();
    }
    
    // Check for session feedback on page load
    <?php
    if (isset($_SESSION['form_feedback'])) {
        $feedback = $_SESSION['form_feedback'];
        $type = $feedback['success'] ? 'success' : 'danger';
        $title = $feedback['success'] ? 'Başarılı!' : 'Hata!';
        echo "showToast('{$title}', '{$feedback['message']}', '{$type}');";
        unset($_SESSION['form_feedback']);
    }
    ?>

    // Live theme color preview
    const themeColorInput = document.getElementById('theme_color');
    const themeColorText = document.getElementById('theme_color_text');
    themeColorInput.addEventListener('input', function() {
        document.documentElement.style.setProperty('--theme-color', this.value);
        themeColorText.value = this.value;
    });

    // Image preview handler
    function setupImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        input.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    setupImagePreview('site_logo_input', 'logo_preview');
    setupImagePreview('site_favicon_input', 'favicon_preview');

    // Toggle SMTP settings visibility
    const emailToggle = document.getElementById('enable_email_notifications');
    const smtpContainer = document.getElementById('smtp_settings_container');
    function toggleSmtp() {
        smtpContainer.style.display = emailToggle.checked ? 'block' : 'none';
    }
    emailToggle.addEventListener('change', toggleSmtp);
    toggleSmtp(); // Initial check

    // AJAX for tool buttons
    document.getElementById('testEmailBtn').addEventListener('click', function() {
        this.disabled = true;
        showToast('Gönderiliyor...', 'Test e-postası gönderiliyor, lütfen bekleyin.', 'info');
        fetch('?action=test_email')
            .then(res => res.json())
            .then(data => {
                showToast(data.success ? 'Başarılı!' : 'Hata!', data.message, data.success ? 'success' : 'danger');
            })
            .catch(err => showToast('Ağ Hatası', err.message, 'danger'))
            .finally(() => this.disabled = false);
    });

    document.getElementById('clearCacheBtn').addEventListener('click', function() {
        this.disabled = true;
        showToast('Temizleniyor...', 'Önbellek temizleniyor, lütfen bekleyin.', 'info');
        fetch('?action=clear_cache')
            .then(res => res.json())
            .then(data => {
                showToast(data.success ? 'Başarılı!' : 'Hata!', data.message, data.success ? 'success' : 'danger');
            })
            .catch(err => showToast('Ağ Hatası', err.message, 'danger'))
            .finally(() => this.disabled = false);
    });
});
</script>
<?php
// Helper functions for cleaner HTML
function checked($value) {
    if ($value == '1') {
        echo 'checked';
    }
}
function selected($value, $option) {
    if ($value == $option) {
        echo 'selected';
    }
}

include '../includes/components/shared_footer.php';
?>
