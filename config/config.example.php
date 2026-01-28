<?php
/**
 * Check Mate (Yoklama Sistemi) - Genel Config Dosyası
 * Sistem genelinde sabitler ve ayarlar burada tanımlanır.
 */

// =============================================
// TEMEL AYARLAR
// =============================================

// Çıktı tamponlama
ob_start();

// Hata raporlama
if ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Zaman dilimi
date_default_timezone_set('Europe/Istanbul');

// =============================================
// .ENV LOADER
// =============================================
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// =============================================
// VERİTABANI AYARLARI
// =============================================
$is_local = false;
if (php_sapi_name() === 'cli' || 
    (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['HTTP_HOST'] == '127.0.0.1'))) {
    $is_local = true;
}

// Session ayarları
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (!$is_local || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')) {
    ini_set('session.cookie_secure', 1); 
}
ini_set('session.cookie_samesite', 'Lax');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($is_local) {
    // Localhost Development
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'ahdakade_checkmate'); 
    define('DB_USER', 'root');
    define('DB_PASS', 'root');
    define('DB_PORT', 8889);
    define('SITE_URL', 'http://localhost:8888/check_mate');
} else {
    // Production (Change these or use Environment Variables)
    define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
    define('DB_NAME', $_ENV['DB_NAME'] ?? 'your_db_name');
    define('DB_USER', $_ENV['DB_USER'] ?? 'your_db_user');
    define('DB_PASS', $_ENV['DB_PASS'] ?? 'your_db_pass');
    define('DB_PORT', $_ENV['DB_PORT'] ?? 3306);
    define('SITE_URL', $_ENV['SITE_URL'] ?? 'https://yourdomain.com');
}
define('DB_CHARSET', 'utf8mb4');

// =============================================
// SİTE BİLGİLERİ
// =============================================
define('SITE_NAME', 'Check Mate');
define('SITE_EMAIL', 'info@example.com');
define('ADMIN_URL', SITE_URL . '/admin');

// =============================================
// DOSYA YÜKLEME AYARLARI
// =============================================
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOADS_PATH', __DIR__ . '/../uploads');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5); // MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);

// =============================================
// GÜVENLİK AYARLARI
// =============================================
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_TIME', 3600);
define('PASSWORD_COST', 12);
define('SESSION_TIMEOUT', 7200);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900);

// =============================================
// SAYFALAMA
// =============================================
define('ITEMS_PER_PAGE', 12);
define('NEWS_PER_PAGE', 9);
define('PAGINATION_RANGE', 2);

// =============================================
// CACHE AYARLARI
// =============================================
define('CACHE_ENABLED', false);
define('CACHE_TIME', 3600);

// =============================================
// E-POSTA AYARLARI
// =============================================
define('SMTP_ENABLED', false);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('SMTP_ENCRYPTION', 'tls');

// =============================================
// SEO & META AYARLARI
// =============================================
define('META_DESCRIPTION', 'Check Mate - Yoklama Sistemi');
define('META_KEYWORDS', 'yoklama, akademi, eğitim, teknoloji');
define('META_AUTHOR', 'AhdaKade');
define('OG_IMAGE', SITE_URL . '/assets/images/og-image.jpg');
define('OG_TYPE', 'website');
define('OG_LOCALE', 'tr_TR');

// =============================================
// BAKIM MODU
// =============================================
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'Sitemiz şu anda bakımdadır.');
define('MAINTENANCE_ALLOWED_IPS', ['127.0.0.1', '::1']);

// =============================================
// DEBUG & LOG
// =============================================
define('DEBUG_MODE', $is_local);
define('LOG_ENABLED', true);
define('LOG_FILE', __DIR__ . '/../logs/app.log');

// =============================================
// UYGULAMA VERSİYONU
// =============================================
define('APP_VERSION', '1.0.0');
define('APP_BUILD', date('Ymd'));

// =============================================
// CAPTCHA AYARLARI
// =============================================
define('CAPTCHA_ENABLED', true);
define('CAPTCHA_LENGTH', 6);
define('CAPTCHA_EXPIRE', 600);
