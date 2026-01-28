<?php
// forgot-password.php

// Hata raporlaması (Geliştirme ortamı için)
// UYARI: Canlı sunucuda bu ayarları kapatın!
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone ayarı
date_default_timezone_set('Europe/Istanbul');

require_once 'includes/functions.php';

$auth = new Auth();
$email_sender = new EmailSender();

// Zaten giriş yapmışsa ilgili sayfaya yönlendir
if ($auth->isLoggedIn()) {
    $auth->redirectByRole();
}

$database = new Database();
$db = $database->getConnection();

// Site ayarlarını doğru bir şekilde yükle
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $site_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    error_log("Site settings fetch failed: " . $e->getMessage());
    $site_settings = [];
}


// Site ayarlarını değişkenlere ata
$site_name = $site_settings['site_name'] ?? 'AhdaKade';
$site_logo = $site_settings['site_logo'] ?? null;
$site_favicon = $site_settings['site_favicon'] ?? null;

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$token = isset($_GET['token']) ? sanitize_input($_GET['token']) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($step == 1) {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        
        if (!$email) {
            show_message('Lütfen geçerli bir e-posta adresi girin.', 'danger');
        } else {
            $query = "SELECT id, full_name FROM users WHERE email = :email AND is_active = 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                try {
                    $token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $user['id']]);
                    
                    $stmt = $db->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)");
                    $stmt->execute([':user_id' => $user['id'], ':token' => $token, ':expires_at' => $expires_at]);
                    
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                    $base_url = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                    $reset_link = $base_url . "/forgot-password.php?step=2&token=" . $token;
                    $logo_url = $site_logo ? $base_url . '/uploads/' . htmlspecialchars($site_logo) : '';

                    $subject = $site_name . " - Şifre Sıfırlama Talebi";

                    // E-posta gövdesi için görsel şablon
                    $body = '
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($subject) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; border-collapse: collapse; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid #dddddd;">
        ' . ($logo_url ? '
        <tr>
            <td align="center" style="padding: 20px 0; background-color: #f8f9fa; border-bottom: 1px solid #dddddd;">
                <img src="' . $logo_url . '" alt="' . htmlspecialchars($site_name) . '" style="max-width: 180px; height: auto; display: block;">
            </td>
        </tr>' : '') . '
        <tr>
            <td style="padding: 30px 40px; color: #333; line-height: 1.6; text-align: center;">
                <h1 style="color: #333; margin-top: 0; font-size: 24px;">Şifre Sıfırlama Talebi</h1>
                <p style="font-size: 16px;">Merhaba, <strong>' . htmlspecialchars($user['full_name']) . '</strong></p>
                <p>Hesabınız için bir şifre sıfırlama talebi aldık. Şifrenizi sıfırlamak için lütfen aşağıdaki düğmeye tıklayın.</p>
                <p>Bu bağlantı 1 saat boyunca geçerlidir. Eğer bu talebi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.</p>
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td align="center" style="padding: 20px 0;">
                            <a href="' . $reset_link . '" target="_blank" style="background: linear-gradient(45deg, #667eea, #764ba2); color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; display: inline-block; font-size: 16px;">Şifremi Sıfırla</a>
                        </td>
                    </tr>
                </table>
                <p style="font-size: 12px; color: #777;">Eğer düğme çalışmazsa, aşağıdaki bağlantıyı kopyalayıp tarayıcınıza yapıştırabilirsiniz:</p>
                <p style="font-size: 12px; color: #777; word-break: break-all;"><a href="' . $reset_link . '" target="_blank" style="color: #667eea;">' . $reset_link . '</a></p>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f9f9f9; padding: 20px 40px; text-align: center; color: #777; font-size: 12px; border-top: 1px solid #eaeaea;">
                &copy; ' . date("Y") . ' ' . htmlspecialchars($site_name) . '. Tüm hakları saklıdır.
            </td>
        </tr>
    </table>
</body>
</html>';
                    
                    // Hata gösterimini engellemek için @ operatörü kullanıldı.
                    // İdeal çözüm, sunucudaki 'logs' klasörüne yazma izni vermektir.
                    @$email_sender->sendEmail($email, $subject, $body);
                    
                    $db->commit();

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Password reset token error: " . $e->getMessage());
                }
            }
            // Güvenlik nedeniyle, e-posta bulunsa da bulunmasa da, gönderim başarılı olsa da olmasa da aynı mesajı göster.
            show_message('Eğer bu e-posta adresi sistemimizde kayıtlıysa, şifre sıfırlama bağlantısı gönderilmiştir.', 'success');
        }
    } elseif ($step == 2) {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($new_password) || $new_password !== $confirm_password || strlen($new_password) < 6) {
            show_message('Lütfen geçerli ve eşleşen şifreler girin (en az 6 karakter).', 'danger');
        } else {
            $query = "SELECT * FROM password_reset_tokens WHERE token = :token AND expires_at > NOW() AND used = 0";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            $reset_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reset_data) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $db->beginTransaction();
                
                $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :user_id");
                $stmt->execute([':password' => $hashed_password, ':user_id' => $reset_data['user_id']]);
                
                $stmt = $db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = :id");
                $stmt->execute([':id' => $reset_data['id']]);
                
                $db->commit();
                
                show_message('Şifreniz başarıyla sıfırlandı. Artık yeni şifrenizle giriş yapabilirsiniz.', 'success');
                redirect('index.php');
                exit;
            } else {
                show_message('Geçersiz veya süresi dolmuş bir bağlantı kullandınız. Lütfen tekrar deneyin.', 'danger');
            }
        }
    }
}

// Token kontrolü (step 2 için)
$user_data = null;
if ($step == 2 && !empty($token)) {
    $query = "SELECT u.full_name FROM password_reset_tokens pt JOIN users u ON pt.user_id = u.id WHERE pt.token = :token AND pt.expires_at > NOW() AND pt.used = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        show_message('Geçersiz veya süresi dolmuş şifre sıfırlama bağlantısı.', 'danger');
        $step = 1; // Adım 1'e geri döndür
    }
}
$theme_c = '#667eea';
 $theme_color = htmlspecialchars($site_settings['theme_color'] ?? $theme_c);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifre Sıfırlama - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php if ($site_favicon): ?>
    <link rel="icon" type="image/x-icon" href="uploads/<?php echo htmlspecialchars($site_favicon); ?>">
    <?php endif; ?>
    <style>
    /* Body arkaplanı şeffaf yapıldı, diğer özellikler korundu. */
  :root {
            --theme-color: <?php echo $theme_color; ?>;
            --theme-color-rgb: <?php echo $theme_color_rgb; ?>;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, var(--theme-color) 0%, rgba(var(--theme-color-rgb), 0.8) 100%);
            --shadow-soft: 0 20px 60px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 30px 80px rgba(0, 0, 0, 0.12);
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient-2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(120, 119, 198, 0.2) 0%, transparent 50%);
            animation: floatBg 20s ease-in-out infinite;
        }
        
        @keyframes floatBg {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(1deg); }
        }
        
    .reset-card { 
        background: transparent; 
        backdrop-filter: blur(10px); 
        border-radius: 15px; 
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4); 
    }

    /* ----- Diğer stiller orijinal haliyle korundu ----- */

    .btn-reset { 
        background: linear-gradient(45deg, #667eea, #764ba2); 
        border: none; 
        border-radius: 50px; 
        padding: 12px 30px; 
        font-weight: 600; 
        color: white;
        transition: all 0.3s ease; 
    }

    .btn-reset:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); 
    }

    .form-control { 
        border-radius: 50px; 
        border: 2px solid #e3f2fd; 
        padding: 12px 20px; 
    }

    .form-control:focus { 
        border-color: #667eea; 
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.45); 
    }

    .step-indicator { 
        display: flex; 
        justify-content: center; 
        margin-bottom: 2rem; 
    }

    .step { 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        width: 40px; 
        height: 40px; 
        border-radius: 50%; 
        background: #ddd; 
        margin: 0 10px; 
        font-weight: bold; 
        color: #666; 
    }

    .step.active { 
        background: linear-gradient(45deg, #667eea, #764ba2); 
        color: white; 
    }

    .input-group-text { 
        cursor: pointer; 
        background-color: transparent; 
        border-left: 0; 
        border-radius: 0 50px 50px 0 !important; 
    }

    .form-control.is-invalid { 
        border-right: 0; 
    }

    .progress { 
        height: 5px; 
        border-radius: 5px; 
        background-color: #e9ecef; /* İlerleme çubuğu arkaplanı eklendi */
    }

    .progress-bar { /* İlerleme çubuğu için stil eklendi */
        background-color: #667eea;
    }
</style>

    
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="reset-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <?php if ($site_logo): ?>
                            <img src="uploads/<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="mb-3" style="max-height: 200px;">
                        <?php else: ?>
                            <h2 class="mt-3 mb-2"><?php echo htmlspecialchars($site_name); ?></h2>
                        <?php endif; ?>
                        <p class="text-muted">Şifre Sıfırlama</p>
                    </div>

                    <div class="step-indicator">
                        <div class="step <?php echo $step >= 1 ? 'active' : ''; ?>">1</div>
                        <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">2</div>
                    </div>

                    <?php display_message(); ?>

                    <?php if ($step == 1): ?>
                        <form method="POST">
                            <div class="mb-3"><input type="email" class="form-control text-center" name="email" placeholder="E-posta adresiniz" required></div>
                            <div class="d-grid mb-3"><button type="submit" class="btn btn-primary btn-reset text-white">Sıfırlama Bağlantısı Gönder</button></div>
                            <div class="text-center"><a href="index.php" class="text-decoration-none">Giriş Sayfasına Dön</a></div>
                        </form>

                    <?php elseif ($step == 2 && $user_data): ?>
                        <p class="text-center text-muted">Merhaba, <strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong>. Lütfen yeni şifrenizi belirleyin.</p>
                        <form id="resetForm" method="POST" novalidate>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            
                            <!-- Yeni Şifre Alanı -->
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="password" class="form-control text-center" id="new_password" name="new_password" placeholder="Yeni şifre" required minlength="6">
                                    <span class="input-group-text" id="toggle_new_password">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                                <!-- Şifre Güç Ölçer -->
                                <div class="mt-2">
                                    <div class="progress">
                                        <div id="password-strength-bar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small id="password-strength-text" class="form-text text-muted"></small>
                                </div>
                            </div>

                            <!-- Şifre Tekrar Alanı -->
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="password" class="form-control text-center" id="confirm_password" name="confirm_password" placeholder="Yeni şifre (tekrar)" required minlength="6">
                                    <span class="input-group-text" id="toggle_confirm_password">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                            </div>

                            <div id="passwordError" class="text-danger text-center mb-3" style="display: none;">Şifreler eşleşmiyor veya yeterince güçlü değil!</div>
                            <div class="d-grid mb-3"><button type="submit" class="btn btn-success btn-reset text-white">Şifremi Sıfırla</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        <?php if ($step == 2): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const resetForm = document.getElementById('resetForm');
            const newPasswordField = document.getElementById('new_password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const errorDiv = document.getElementById('passwordError');

            // Göz ikonları için fonksiyon
            function setupPasswordToggle(inputId, toggleId) {
                const passwordField = document.getElementById(inputId);
                const toggleIcon = document.getElementById(toggleId);
                
                toggleIcon.addEventListener('click', function() {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }

            setupPasswordToggle('new_password', 'toggle_new_password');
            setupPasswordToggle('confirm_password', 'toggle_confirm_password');

            // Şifre güç ölçer fonksiyonu
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');

            newPasswordField.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let text = '';
                let barClass = '';

                if (password.length >= 8) strength++;
                if (password.match(/[a-z]/)) strength++;
                if (password.match(/[A-Z]/)) strength++;
                if (password.match(/[0-9]/)) strength++;
                if (password.match(/[^a-zA-Z0-9]/)) strength++;
                
                let width = (strength / 5) * 100;

                switch (strength) {
                    case 0:
                    case 1:
                        text = 'Zayıf';
                        barClass = 'bg-danger';
                        break;
                    case 2:
                        text = 'Orta';
                        barClass = 'bg-warning';
                        break;
                    case 3:
                    case 4:
                        text = 'Güçlü';
                        barClass = 'bg-info';
                        break;
                    case 5:
                        text = 'Çok Güçlü';
                        barClass = 'bg-success';
                        break;
                }

                if (password.length === 0) {
                    width = 0;
                    text = '';
                }

                strengthBar.style.width = width + '%';
                strengthBar.className = 'progress-bar ' + barClass;
                strengthText.textContent = text;
            });

            // Form gönderim kontrolü
            resetForm.addEventListener('submit', function(e) {
                if (newPasswordField.value !== confirmPasswordField.value || newPasswordField.value.length < 6) {
                    e.preventDefault(); // Formun gönderilmesini engelle
                    errorDiv.textContent = 'Şifreler eşleşmiyor veya 6 karakterden az!';
                    errorDiv.style.display = 'block';
                } else {
                    errorDiv.style.display = 'none';
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>
