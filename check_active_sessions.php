<?php
// Aktif oturumları detaylı göster
require_once 'includes/functions.php';
date_default_timezone_set('Europe/Istanbul');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
    // MySQL timezone ayarı
    $db->exec("SET time_zone = '+03:00'");
    
    echo "=== Aktif Oturumlar Detaylı Bilgi ===\n\n";
    
    // Aktif oturumları listele
    $query = "SELECT id, session_name, session_date, start_time, duration_minutes, status, is_active, closed_at,
              CONCAT(session_date, ' ', start_time) as start_datetime,
              ADDTIME(CONCAT(session_date, ' ', start_time), CONCAT(duration_minutes, ':00')) as end_datetime,
              CASE 
                WHEN NOW() > ADDTIME(CONCAT(session_date, ' ', start_time), CONCAT(duration_minutes, ':00')) THEN 'Süresi dolmuş'
                WHEN NOW() < CONCAT(session_date, ' ', start_time) THEN 'Henüz başlamamış'
                ELSE 'Süre içinde'
              END as time_status
              FROM attendance_sessions 
              WHERE status = 'active' OR is_active = 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $active_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($active_sessions) > 0) {
        echo "Toplam " . count($active_sessions) . " aktif/aktif görünen oturum bulundu:\n\n";
        
        foreach ($active_sessions as $session) {
            echo "ID: {$session['id']} - {$session['session_name']}\n";
            echo "  Başlangıç: {$session['start_datetime']}\n";
            echo "  Bitiş: {$session['end_datetime']}\n";
            echo "  Şu an: " . date('Y-m-d H:i:s') . "\n";
            echo "  Status: {$session['status']}, is_active: {$session['is_active']}\n";
            echo "  closed_at: " . ($session['closed_at'] ?: 'NULL') . "\n";
            echo "  Zaman durumu: {$session['time_status']}\n\n";
        }
        
        // Süresi dolmuş olanları manuel güncelle
        echo "Süresi dolmuş oturumları güncellemek için cron job'ı çalıştırmak ister misiniz? (y/n): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if(trim($line) == 'y'){
            echo "\nCron job çalıştırılıyor...\n";
            system('php ' . __DIR__ . '/cron/update_session_status.php');
            echo "\nTamamlandı.\n";
        }
        
    } else {
        echo "✅ Aktif oturum bulunamadı.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Hata: " . $e->getMessage() . "\n";
}
?>
