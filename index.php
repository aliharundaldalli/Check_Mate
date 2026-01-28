<?php
// index.php - Standalone versiyon

// Hata raporlaması (Geliştirme ortamı için)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone ayarı
date_default_timezone_set('Europe/Istanbul');

// Veritabanı ve basit fonksiyonlar
require_once 'config/database.php';

// Oturum başlatma
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Basit yardımcı fonksiyonlar
function sanitize_input($data) {
    return htmlspecialchars(trim($data));
}

function show_message($message, $type = 'info') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

function display_message() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        echo "<div class='alert alert-$type'>$message</div>";
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
}

function redirect($url) {
    header("Location: $url");
    exit();
}

// Basit Auth kontrolü
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function redirectByRole() {
    if (!isLoggedIn()) {
        return;
    }
    
    switch ($_SESSION['user_type']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'teacher':
            header('Location: teacher/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
    }
    exit();
}

// Zaten giriş yapmışsa ilgili sayfaya yönlendir
if (isLoggedIn()) {
    redirectByRole();
}

// Veritabanı bağlantısı ve site ayarları
$site_settings = [];
$site_name = 'AhdaKade'; // Default
$site_logo = '';
$site_favicon = '';
$theme_color = '#667eea'; // Default

try {
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings");
    $stmt->execute();
    
    $settings_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($settings_raw) {
        $site_settings = $settings_raw;
    }

    $site_name = htmlspecialchars($site_settings['site_name'] ?? $site_name);
    $site_logo = htmlspecialchars($site_settings['site_logo'] ?? $site_logo);
    $site_favicon = htmlspecialchars($site_settings['site_favicon'] ?? $site_favicon);
    $theme_color = htmlspecialchars($site_settings['theme_color'] ?? $theme_color);

} catch (Exception $e) {
    error_log("Error on index.php: " . $e->getMessage());
}

// Hex to RGB converter
function hex2rgb($hex) {
    $hex = str_replace('#', '', $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}

$theme_color_rgb = hex2rgb($theme_color);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_name; ?> - Yoklama Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php if ($site_favicon): ?>
    <link rel="icon" type="image/x-icon" href="uploads/<?php echo htmlspecialchars($site_favicon); ?>">
    <?php endif; ?>
    <style>
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
        
        .login-card {
            background: transparent;
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 10;
        }
        
        .btn-login {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .form-control {
            border-radius: 50px;
            border: 2px solid #e3f2fd;
            padding: 12px 20px;
            background-color: rgba(255, 255, 255, 0.9);
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.45);
            background-color: #fff;
        }
        

        
        .forgot-link {
            color: rgba(0, 0, 0, 0.6);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .forgot-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--theme-color);
            transition: width 0.3s ease;
        }
        
        .forgot-link:hover {
            color: var(--theme-color);
        }
        
        .forgot-link:hover::after {
            width: 100%;
        }
        

        
        .brand-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .brand-logo img {
            max-height: 120px;
            max-width: 180px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 1rem;
        }
        
        .brand-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0.5rem 0;
        }
        
        .brand-subtitle {
            color: rgba(0, 0, 0, 0.6);
            font-size: 0.95rem;
            margin: 0;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            backdrop-filter: blur(10px);
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: rgba(72, 187, 120, 0.1);
            color: #2f855a;
        }
        
        .alert-danger {
            background: rgba(245, 101, 101, 0.1);
            color: #c53030;
        }
        
        .alert-warning {
            background: rgba(237, 137, 54, 0.1);
            color: #d69e2e;
        }
        
        .alert-info {
            background: rgba(66, 153, 225, 0.1);
            color: #3182ce;
        }
        
        @media (max-width: 768px) {
            .login-card {
                margin: 1rem;
            }
            
            .roles-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .role-item {
                display: flex;
                align-items: center;
                text-align: left;
                padding: 0.75rem 1rem;
            }
            
            .role-icon {
                margin-bottom: 0;
                margin-right: 0.75rem;
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card p-4 p-md-5">
                    <div class="brand-section">
                        <?php if ($site_logo): ?>
                            <div class="brand-logo">
                                <img src="uploads/<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
                            </div>
                        <?php else: ?>
                            <i class="fas fa-graduation-cap" style="font-size: 3rem; color: #667eea; margin-bottom: 1rem;"></i>
                        <?php endif; ?>
                        <h1 class="brand-title"><?php echo htmlspecialchars($site_name); ?></h1>
                        <p class="brand-subtitle">Yoklama Sistemine Hoş Geldiniz</p>
                    </div>

                    <?php display_message(); ?>

                    <form action="login.php" method="POST" class="mt-4">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="email" name="email" placeholder="E-posta veya No" required>
                            <label for="email"><i class="fas fa-user me-2"></i>E-posta veya Öğrenci Numarası</label>
                        </div>

                        <div class="form-floating mb-4">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Şifre" required>
                            <label for="password"><i class="fas fa-lock me-2"></i>Şifre</label>
                        </div>

                        <?php 
                        // Brute Force Koruması: 3 hatalı deneme sonrası CAPTCHA
                        if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 3): 
                            $num1 = rand(1, 9);
                            $num2 = rand(1, 9);
                            $_SESSION['captcha_result'] = $num1 + $num2;
                        ?>
                        <div class="form-floating mb-4">
                            <input type="number" class="form-control" id="captcha" name="captcha" placeholder="Sonuç" required>
                            <label for="captcha"><i class="fas fa-robot me-2"></i>Güvenlik Sorusu: <?php echo $num1; ?> + <?php echo $num2; ?> = ?</label>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid mb-4">
                            <button type="submit" class="btn btn-login btn-lg">
                                GİRİŞ YAP <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                    <div class="text-center mb-0">
                        <a href="forgot-password.php" class="forgot-link text-muted small">
                            <i class="fas fa-key me-1"></i>Şifremi Unuttum
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>