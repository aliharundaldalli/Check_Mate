<?php
require_once 'includes/functions.php';

$auth = new Auth();

// Zaten giriş yapmışsa ana sayfaya yönlendir
if ($auth->isLoggedIn()) {
    $auth->redirectByRole();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        show_message('Lütfen tüm alanları doldurun.', 'danger');
        redirect('index.php');
    }
    
    // Brute Force Kontrolü
    if (!isset($_SESSION['login_attempts'])) {
         $_SESSION['login_attempts'] = 0;
    }
    
    // 3 deneme sonrası CAPTCHA kontrolü
    if ($_SESSION['login_attempts'] >= 3) {
        $captcha_input = isset($_POST['captcha']) ? (int)$_POST['captcha'] : 0;
        $captcha_result = isset($_SESSION['captcha_result']) ? (int)$_SESSION['captcha_result'] : -1;
        
        if ($captcha_input !== $captcha_result) {
            show_message('Güvenlik sorusu hatalı. Lütfen tekrar deneyin.', 'danger');
            redirect('index.php');
            exit;
        }
    }
    
    if ($auth->login($email, $password)) {
        // Başarılı giriş -> sayacı sıfırla
        unset($_SESSION['login_attempts']);
        unset($_SESSION['captcha_result']);
        
        show_message('Giriş başarılı! Yönlendiriliyorsunuz...', 'success');
        $auth->redirectByRole();
    } else {
        // Başarısız giriş -> sayacı artır
        $_SESSION['login_attempts']++;
        
        $remaining = 3 - $_SESSION['login_attempts'];
        $msg = 'E-posta veya şifre hatalı.';
        
        if ($_SESSION['login_attempts'] >= 3) {
            $msg .= ' Güvenlik sorusunu cevaplamanız gerekecek.';
        }
        
        show_message($msg, 'danger');
        redirect('index.php');
    }
} else {
    redirect('index.php');
}
?>
