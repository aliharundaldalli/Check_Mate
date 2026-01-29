<?php
// error.php - Merkezi Hata Sayfası
require_once 'config/config.php';

$code = isset($_GET['code']) ? (int)$_GET['code'] : 404;
$message = "Sayfa Bulunamadı";
$description = "Ulaşmaya çalıştığınız sayfa mevcut değil veya taşınmış olabilir.";

if ($code === 403) {
    $message = "Erişim Engellendi (403)";
    $description = "Bu sayfaya erişim yetkiniz bulunmamaktadır veya sunucu güvenlik duvarı (ModSecurity) isteğinizi engelledi.";
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $message; ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .error-card { max-width: 500px; padding: 2rem; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); background: white; text-align: center; }
        .error-code { font-size: 5rem; font-weight: bold; background: linear-gradient(45deg, #27ae60, #2ecc71); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code"><?php echo $code; ?></div>
        <h2 class="mb-3"><?php echo $message; ?></h2>
        <p class="text-muted mb-4"><?php echo $description; ?></p>
        
        <?php if ($code === 403): ?>
            <div class="alert alert-warning small text-start mb-4">
                <strong>💡 Çözüm Önerisi:</strong>
                 Eğer sınav kaydedirken bu hatayı alıyorsanız, cPanel üzerinden <strong>ModSecurity</strong> özelliğini geçici olarak kapatmayı deneyin. Soru içerikleri bazen güvenlik duvarına takılabilmektedir.
            </div>
        <?php endif; ?>

        <a href="<?php echo SITE_URL; ?>" class="btn btn-primary px-4 rounded-pill">
            <i class="fas fa-home me-2"></i>Anasayfaya Dön
        </a>
    </div>
</body>
</html>
