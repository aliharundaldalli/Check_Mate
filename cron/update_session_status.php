#!/usr/bin/php
<?php
// =================================================================
// AhdaKade - Oturum Durumu Güncelleyici (Cron Job)
// =================================================================
// AMAÇ: Bu betik, zamanlanmış bir görev (cron job) olarak çalışarak
//       veritabanındaki yoklama oturumlarının durumlarını günceller.
// ÇALIŞMA SIKLIĞI: Dakikada bir (* * * * *) çalıştırılması önerilir.
// =================================================================

// --- cPanel UYUMLULUĞU İÇİN TEMEL AYARLAR ---

// Betiğin kendi konumundan bir üst dizini projenin kök dizini olarak tanımla.
// Bu, cron job'un nerede çalıştığından bağımsız olarak doğru dosya yolunu bulmasını sağlar.
define('PROJECT_ROOT', dirname(__DIR__));

// Hata raporlamayı etkinleştir (cron logları için faydalıdır)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hataları ekrana basma, logla
ini_set('log_errors', 1);

// Zaman dilimini ayarla
date_default_timezone_set('Europe/Istanbul');

try {
    // Projenin ana fonksiyon dosyasını dahil et
    require_once PROJECT_ROOT . '/includes/functions.php';

    // Veritabanı bağlantısını kur
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    // MySQL zaman dilimini ayarla
    $db->exec("SET time_zone = '+03:00'");
    
    $output_log = []; // İşlem loglarını biriktirmek için dizi

    // --- GÖREV 1: SÜRESİ DOLAN OTURUMLARI GÜNCELLE ---
    // Bitiş zamanı geçmiş olan 'active' veya 'inactive' durumundaki oturumları bulur.
    // Bu oturumların durumunu 'expired' olarak ayarlar ve is_active durumunu 0 yapar.
    $query_expired = "UPDATE attendance_sessions 
                      SET 
                          status = 'expired', 
                          is_active = 1, /* Mantıksal düzeltme: Süresi dolan oturum aktif olamaz */
                          closed_at = NOW()
                      WHERE 
                          status IN ('active', 'inactive')
                          AND closed_at IS NULL 
                          AND DATE_ADD(CONCAT(session_date, ' ', start_time), INTERVAL duration_minutes MINUTE) < NOW()";
    
    $stmt_expired = $db->prepare($query_expired);
    $stmt_expired->execute();
    $expired_count = $stmt_expired->rowCount();

    if ($expired_count > 0) {
        $output_log[] = "Süresi dolduğu için $expired_count oturum 'expired' olarak güncellendi.";
    }

    // --- GÖREV 2: GELECEKTEKİ OTURUMLARI AKTİF HALE GETİR ---
    // Başlama zamanı gelmiş olan 'future' durumundaki oturumları bulur.
    // Öğretmen manuel olarak başlatana kadar durumlarını 'inactive' yapar.
    $query_future = "UPDATE attendance_sessions 
                     SET status = 'inactive' 
                     WHERE status = 'future'
                       AND closed_at IS NULL 
                       AND NOW() >= CONCAT(session_date, ' ', start_time)";
    
    $stmt_future = $db->prepare($query_future);
    $stmt_future->execute();
    $future_count = $stmt_future->rowCount();

    if ($future_count > 0) {
        $output_log[] = "$future_count adet 'future' oturum, başlama zamanı geldiği için 'inactive' olarak ayarlandı.";
    }

    // --- GÖREV 3: ESKİ İKİNCİ AŞAMA ANAHTARLARINI TEMİZLE ---
    // Veritabanını temiz tutmak için 1 saatten daha eski anahtarları siler.
    $query_keys = "DELETE FROM second_phase_keys 
                   WHERE valid_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    
    $stmt_keys = $db->prepare($query_keys);
    $stmt_keys->execute();
    $keys_count = $stmt_keys->rowCount();

    if ($keys_count > 0) {
        $output_log[] = "$keys_count adet eski ikinci aşama anahtarı temizlendi.";
    }

    // --- SONUÇ RAPORU ---
    if (empty($output_log)) {
        echo date('Y-m-d H:i:s') . " - Herhangi bir güncelleme yapılmadı.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Cron Job Tamamlandı:\n";
        foreach ($output_log as $log_entry) {
            echo " - " . $log_entry . "\n";
        }
    }
    
} catch (Exception $e) {
    $error_message = date('Y-m-d H:i:s') . " - CRON JOB HATASI: " . $e->getMessage() . "\n";
    // Hatayı hem sunucunun ana hata günlüğüne hem de cron çıktısına yaz
    error_log($error_message); 
    echo $error_message;
    exit(1); // Hata durumunda script'i hata koduyla sonlandır
}

exit(0); // Başarılı bir şekilde sonlandır
?>
