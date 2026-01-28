<?php
/**
 * Yoklama Sistemi - Otomatik Cron Job
 * Her 2 dakikada bir çalışır
 * Görevler: Süresi dolan oturumları günceller, gelecek oturumları aktif eder, eski anahtarları temizler
 */

ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);

// Klasör yolunu düzelt
require_once __DIR__ . '/../includes/functions.php';

// --- DÜZELTME: Log dosyası yolunu en başta tanımlıyoruz ---
$log_file = __DIR__ . '/../logs/cron_job.log';

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }

    // MySQL zaman dilimini ayarla
    $db->exec("SET time_zone = '+03:00'");

    $cron_log = [];

    // Başlangıç zamanı
    $start_time = microtime(true);

    // --- GÖREV 1: SÜRESİ DOLAN OTURUMLARI GÜNCELLE ---
    $query_expired = "UPDATE attendance_sessions
                      SET
                          status = 'expired',
                          is_active = 1,
                          expired_at = NOW()
                      WHERE
                          status IN ('active', 'inactive')
                          AND closed_at IS NULL
                          AND DATE_ADD(CONCAT(session_date, ' ', start_time), INTERVAL duration_minutes MINUTE) < NOW()";

    $stmt_expired = $db->prepare($query_expired);
    $stmt_expired->execute();
    $expired_count = $stmt_expired->rowCount();

    if ($expired_count > 0) {
        $cron_log[] = "✓ Süresi dolduğu için {$expired_count} oturum 'expired' olarak güncellendi.";
    }

    // --- GÖREV 2: GELECEKTEKİ OTURUMLARI AKTİF HALE GETİR ---
    $query_future = "UPDATE attendance_sessions
                     SET status = 'inactive'
                     WHERE status = 'future'
                       AND closed_at IS NULL
                       AND NOW() >= CONCAT(session_date, ' ', start_time)";

    $stmt_future = $db->prepare($query_future);
    $stmt_future->execute();
    $future_count = $stmt_future->rowCount();

    if ($future_count > 0) {
        $cron_log[] = "✓ {$future_count} adet 'future' oturum, başlama zamanı geldiği için 'inactive' olarak ayarlandı.";
    }

    // --- GÖREV 3: ESKİ İKİNCİ AŞAMA ANAHTARLARINI TEMİZLE ---
    $query_keys = "DELETE FROM second_phase_keys
                   WHERE valid_until < DATE_SUB(NOW(), INTERVAL 1 HOUR)";

    $stmt_keys = $db->prepare($query_keys);
    $stmt_keys->execute();
    $keys_count = $stmt_keys->rowCount();

    if ($keys_count > 0) {
        $cron_log[] = "✓ {$keys_count} adet eski ikinci aşama anahtarı temizlendi.";
    }

    // Bitiş zamanı ve süre hesaplama
    $end_time = microtime(true);
    $execution_time = round(($end_time - $start_time) * 1000, 2); // milisaniye

    // Logları kaydet
    // --- DÜZELTME: $log_file artık tanımlı olduğu için burası hata vermez ---
    $log_dir = dirname($log_file);
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_entry = "╔══════════════════════════════════════════════════════════════╗\n";
    $log_entry .= "║ CRON JOB ÇALIŞTI: " . date('Y-m-d H:i:s') . "                    ║\n";
    $log_entry .= "╠══════════════════════════════════════════════════════════════╣\n";
    
    if (!empty($cron_log)) {
        foreach ($cron_log as $log) {
            $log_entry .= "  " . $log . "\n";
        }
    } else {
        $log_entry .= "  ℹ  Güncellenecek kayıt bulunamadı.\n";
    }
    
    $log_entry .= "╠══════════════════════════════════════════════════════════════╣\n";
    $log_entry .= "  ⏱  Çalışma Süresi: {$execution_time} ms\n";
    $log_entry .= "╚══════════════════════════════════════════════════════════════╝\n\n";

    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

    // Konsol çıktısı (cron email için)
    echo "✓ Cron job başarıyla tamamlandı.\n";
    echo "⏱  Çalışma Süresi: {$execution_time} ms\n";
    if (!empty($cron_log)) {
        echo "\n" . implode("\n", $cron_log) . "\n";
    } else {
        echo "ℹ  Güncellenecek kayıt bulunamadı.\n";
    }

    exit(0); // Başarılı

} catch (Exception $e) {
    $error_msg = "╔══════════════════════════════════════════════════════════════╗\n";
    $error_msg .= "║ CRON JOB HATASI: " . date('Y-m-d H:i:s') . "                    ║\n";
    $error_msg .= "╠══════════════════════════════════════════════════════════════╣\n";
    $error_msg .= "  ✖  HATA: " . $e->getMessage() . "\n";
    $error_msg .= "  📄 Dosya: " . $e->getFile() . "\n";
    $error_msg .= "  📍 Satır: " . $e->getLine() . "\n";
    $error_msg .= "╚══════════════════════════════════════════════════════════════╝\n\n";
    
    error_log($error_msg);
    
    // Catch bloğundaki $log_file tanımına artık gerek yok çünkü en başta tanımladık.
    $log_dir = dirname($log_file);
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    file_put_contents($log_file, $error_msg, FILE_APPEND | LOCK_EX);
    
    echo "✖ HATA: " . $e->getMessage() . "\n";
    exit(1); // Hata
}