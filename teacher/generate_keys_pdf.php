<?php
session_start();
require_once '../includes/functions.php';

// Öğretmen yetkisi kontrolü
$auth = new Auth();
if (!$auth->checkRole('teacher')) {
    die('Yetkisiz erişim');
}

$database = new Database();
$db = $database->getConnection();

$session_id = (int)($_POST['session_id'] ?? 0);
$show_all = $_POST['show_all'] ?? '0';
$teacher_id = $_SESSION['user_id'];

// Oturum bilgilerini al
$query = "SELECT asess.*, c.course_name, c.course_code
          FROM attendance_sessions asess
          JOIN courses c ON asess.course_id = c.id
          WHERE asess.id = :session_id AND asess.teacher_id = :teacher_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
$stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
$stmt->execute();
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die('Oturum bulunamadı');
}

// Anahtarları al
if ($show_all == '1') {
    $query = "SELECT fpk.*, u.full_name as used_by_name 
              FROM first_phase_keys fpk
              LEFT JOIN users u ON fpk.used_by_student_id = u.id
              WHERE fpk.session_id = :session_id
              ORDER BY fpk.key_code ASC";
} else {
    $query = "SELECT fpk.*, u.full_name as used_by_name 
              FROM first_phase_keys fpk
              LEFT JOIN users u ON fpk.used_by_student_id = u.id
              WHERE fpk.session_id = :session_id AND fpk.is_used = 0
              ORDER BY fpk.used_at DESC";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':session_id', $session_id, PDO::PARAM_INT);
$stmt->execute();
$keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

// TCPDF kütüphanesini dahil et
require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');

// PDF oluştur
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// PDF bilgileri
$pdf->SetCreator('AhdaKade Yoklama Sistemi');
$pdf->SetAuthor($_SESSION['user_name']);
$pdf->SetTitle('Yoklama Anahtarları - ' . $session['session_name']);

// Sayfa numarası için alias ayarla
$pdf->setPageMark();

// Sayfa kenar boşlukları
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);

// Font
$pdf->SetFont('dejavusans', '', 10);

// Yeni sayfa ekle
$pdf->AddPage();

// Başlık
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->Cell(0, 10, 'YOKLAMA ANAHTARLARI', 0, 1, 'C');
$pdf->Ln(2);

// Oturum bilgileri - Yan yana düzenlendi
$pdf->SetFont('dejavusans', '', 9);
$info_text = 'Ders: ' . $session['course_name'] . ' (' . $session['course_code'] . ') | ' . 
              'Oturum: ' . $session['session_name'] . ' | ' . 
              'Tarih: ' . date('d.m.Y H:i', strtotime($session['session_date'] . ' ' . $session['start_time'])) . ' | ' . 
              'Toplam Anahtar: ' . count($keys);
$pdf->Cell(0, 8, $info_text, 0, 1, 'C');
$pdf->Ln(5);

// Anahtarları grid şeklinde düzenle - Premium Tasarım
$pdf->SetFont('dejavusans', 'B', 12);

// Oturum durumunu kontrol et (kapalı veya süresi dolmuş mu?)
$session_closed = in_array($session['status'], ['closed', 'expired']);

// QR URL base
$site_url = defined('SITE_URL') ? SITE_URL : 'https://checkmate.ahdakademi.com';

// Renk paletleri
$colors = [
    'used' => [
        'bg' => [232, 245, 233],    // Açık yeşil
        'border' => [76, 175, 80],   // Yeşil
        'text' => [27, 94, 32]       // Koyu yeşil
    ],
    'unused' => [
        'bg' => [255, 248, 225],    // Açık sarı
        'border' => [255, 193, 7],   // Sarı
        'text' => [102, 60, 0]       // Koyu sarı
    ],
    'expired' => [
        'bg' => [255, 235, 238],    // Açık kırmızı
        'border' => [244, 67, 54],   // Kırmızı
        'text' => [183, 28, 28]      // Koyu kırmızı
    ]
];

$cols = 3;
$cell_width = 62;
$cell_height = 35; // Kutu yüksekliği 38'den 35'e düşürüldü
$x_start = 8;
$y_start = $pdf->GetY();
$gap = 2; // Kartlar arası boşluk

$count = 0;
foreach ($keys as $key) {
    $col = $count % $cols;
    $row = floor($count / $cols);
    
    $x = $x_start + ($col * ($cell_width + $gap));
    $y = $y_start + ($row * ($cell_height + $gap));
    
    // Yeni sayfa kontrolü
    if ($y > 260) { // Yükseklik azaldığı için limit 250'den 260'a çekildi
        $pdf->AddPage();
        $y_start = 20;
        $y = $y_start;
        $row = 0;
        $count = 0; 
    }
    
    // Renk seçimi
    if ($key['is_used']) {
        $palette = $colors['used'];
        $status_text = 'TAMAMLANDI';
    } else if ($session_closed) {
        $palette = $colors['expired'];
        $status_text = 'KULLANILMADI';
    } else {
        $palette = $colors['unused'];
        $status_text = 'AKTİF';
    }
    
    // Kart arka planı
    $pdf->SetDrawColor($palette['border'][0], $palette['border'][1], $palette['border'][2]);
    $pdf->SetFillColor($palette['bg'][0], $palette['bg'][1], $palette['bg'][2]);
    $pdf->SetLineWidth(0.4);
    $pdf->RoundedRect($x, $y, $cell_width, $cell_height, 2, '1111', 'DF');
    
    // Üst kısım - Durum bandı
    $pdf->SetFillColor($palette['border'][0], $palette['border'][1], $palette['border'][2]);
    $pdf->Rect($x, $y, $cell_width, 7, 'F');
    
    // Durum yazısı
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($x, $y + 0.5);
    $pdf->Cell($cell_width, 6, $status_text, 0, 1, 'C');
    
    // QR Kod (sağ tarafta)
    $qr_url = $site_url . '/student/join_first_phase_qr.php?fk=' . $key['key_code'];
    $qr_size = 20;
    $qr_x = $x + $cell_width - $qr_size - 3;
    $qr_y = $y + 9;
    
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($qr_x - 0.5, $qr_y - 0.5, $qr_size + 1, $qr_size + 1, 'F');
    $pdf->write2DBarcode($qr_url, 'QRCODE,M', $qr_x, $qr_y, $qr_size, $qr_size, array(), 'N');
    
    // Anahtar kodu
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor($palette['text'][0], $palette['text'][1], $palette['text'][2]);
    $pdf->SetXY($x + 3, $y + 11);
    $pdf->Cell(32, 10, $key['key_code'], 0, 1, 'L');
    
    // Alt bilgi (Pozisyonlar 35mm yüksekliğe göre yukarı kaydırıldı)
    if ($key['is_used'] && $key['used_by_name']) {
        $pdf->SetFont('dejavusans', '', 5.5);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->SetXY($x + 2, $y + 22);
        
        $name = $key['used_by_name'];
        if (mb_strlen($name) > 18) {
            $name = mb_substr($name, 0, 15) . '...';
        }
        $pdf->Cell(35, 4, $name, 0, 1, 'L');
        
        if ($key['used_at']) {
            $pdf->SetXY($x + 2, $y + 25);
            $pdf->SetFont('dejavusans', 'I', 5.5);
            $pdf->Cell(35, 4, date('H:i:s', strtotime($key['used_at'])), 0, 1, 'L');
        }
    } else if (!$key['is_used'] && !$session_closed) {
        $pdf->SetFont('dejavusans', '', 5.5);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($x + 2, $y + 23);
        $pdf->Cell(35, 4, 'QR okut veya', 0, 1, 'L');
        $pdf->SetXY($x + 2, $y + 26);
        $pdf->Cell(35, 4, 'kodu gir', 0, 1, 'L');
    }
    
    // QR altı açıklama
    $pdf->SetFont('dejavusans', '', 5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY($qr_x - 1, $y + $qr_size + 9.5);
    $pdf->Cell($qr_size + 2, 3, 'QR ile katil', 0, 1, 'C');
    
    $count++;
}

// Alt bilgi - AutoPageBreak'i geçici olarak kapat
$pdf->SetAutoPageBreak(FALSE, 0);
$pdf->SetY(-15);
$pdf->SetFont('dejavusans', 'I', 7);
$pdf->Cell(0, 10, 'Oluşturulma Tarihi: ' . date('d.m.Y H:i:s'), 0, 0, 'L');
$pdf->Cell(0, 10, 'Sayfa ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'R');

// Türkçe karakter dönüşümleri
$turkish_chars = array('ş', 'Ş', 'ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç', ' ');
$english_chars = array('s', 'S', 'i', 'I', 'g', 'G', 'u', 'U', 'o', 'O', 'c', 'C', '_');

$safe_course_code = str_replace($turkish_chars, $english_chars, $session['course_code']);
$safe_course_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safe_course_code);
$safe_course_code = mb_substr($safe_course_code, 0, 20);

$safe_session_name = str_replace($turkish_chars, $english_chars, $session['session_name']);
$safe_session_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safe_session_name);
$safe_session_name = mb_substr($safe_session_name, 0, 40);

$filename = $safe_course_code . '_' . $safe_session_name . '_yoklama_anahtarlari.pdf';

$pdf->Output($filename, 'D');
?>