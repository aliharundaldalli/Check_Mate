<?php
/**
 * Check Mate - Quiz Analysis PDF Report Generator
 * Generates professional PDF reports for quiz analysis
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';
require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->checkRole('teacher')) {
    die('Yetkisiz erişim');
}

$database = new Database();
$db = $database->getConnection();
$teacher_id = $_SESSION['user_id'];

// Quiz ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Geçersiz sınav ID');
}

$quiz_id = (int)$_GET['id'];

// Sınav ve analiz verilerini çek
try {
    $stmt = $db->prepare("
        SELECT q.*, c.course_name, c.course_code 
        FROM quizzes q 
        JOIN courses c ON q.course_id = c.id 
        WHERE q.id = :id AND q.created_by = :tid
    ");
    $stmt->execute([':id' => $quiz_id, ':tid' => $teacher_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz || empty($quiz['ai_analysis'])) {
        die('Sınav bulunamadı veya analiz raporu mevcut değil.');
    }
    
    $analysis = json_decode($quiz['ai_analysis'], true);
    
    if (!$analysis) {
        die('Analiz verisi okunamadı.');
    }
    
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// İstatistikleri hesapla
$stmt_sub = $db->prepare("SELECT score FROM quiz_submissions WHERE quiz_id = ?");
$stmt_sub->execute([$quiz_id]);
$scores = $stmt_sub->fetchAll(PDO::FETCH_COLUMN);

$total_students = count($scores);
$avg_score = $total_students > 0 ? array_sum($scores) / $total_students : 0;
$max_score = $total_students > 0 ? max($scores) : 0;
$min_score = $total_students > 0 ? min($scores) : 0;
$pass_count = count(array_filter($scores, fn($s) => $s >= 50));
$pass_rate = $total_students > 0 ? ($pass_count / $total_students) * 100 : 0;

// PDF Oluştur
class QuizReportPDF extends TCPDF {
    public $quiz_title = '';
    public $course_info = '';
    
    public function Header() {
        // Logo (varsa)
        // $this->Image('../assets/logo.png', 15, 10, 30);
        
        // Başlık
        $this->SetFont('dejavusans', 'B', 16);
        $this->SetTextColor(111, 66, 193); // Purple
        $this->Cell(0, 10, 'SINAV ANALIZ RAPORU', 0, 1, 'C');
        
        $this->SetFont('dejavusans', '', 11);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 6, $this->quiz_title, 0, 1, 'C');
        $this->SetFont('dejavusans', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, $this->course_info, 0, 1, 'C');
        
        // Çizgi
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(111, 66, 193);
        $this->Line(15, 35, 195, 35);
        $this->Ln(8);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Sayfa ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// PDF başlat
$pdf = new QuizReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->quiz_title = $quiz['title'];
$pdf->course_info = $quiz['course_name'] . ' (' . $quiz['course_code'] . ')';

$pdf->SetCreator('Check Mate');
$pdf->SetAuthor('Check Mate - AI Analiz Sistemi');
$pdf->SetTitle('Sınav Analiz Raporu - ' . $quiz['title']);
$pdf->SetSubject('AI Destekli Sınav Değerlendirmesi');

$pdf->SetMargins(15, 45, 15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();

// Rapor Tarihi
$pdf->SetFont('dejavusans', 'I', 9);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Rapor Oluşturulma Tarihi: ' . date('d.m.Y H:i'), 0, 1, 'R');
$pdf->Ln(5);

// ===== İSTATİSTİKLER =====
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 8, 'GENEL İSTATİSTİKLER', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetFillColor(240, 240, 240);

$stats = [
    ['Katılımcı Sayısı', $total_students],
    ['Ortalama Puan', number_format($avg_score, 2)],
    ['En Yüksek Puan', number_format($max_score, 2)],
    ['En Düşük Puan', number_format($min_score, 2)],
    ['Başarı Oranı (≥50)', '%' . number_format($pass_rate, 1)],
];

foreach ($stats as $i => $stat) {
    $fill = $i % 2 == 0;
    $pdf->Cell(100, 7, $stat[0], 1, 0, 'L', $fill);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->Cell(80, 7, $stat[1], 1, 1, 'C', $fill);
    $pdf->SetFont('dejavusans', '', 10);
}

$pdf->Ln(8);

// ===== GENEL ÖZET =====
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell(0, 8, 'GENEL ÖZET', 0, 1, 'L');
$pdf->Ln(2);

// Sayfa bolunmesini engelle
$pdf->startTransaction();
$start_y = $pdf->GetY();
if ($start_y > 200) { // Sayfa sonuna yakinsa yeni sayfa ac
    $pdf->AddPage();
}

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(50, 50, 50);
$summary = is_array($analysis['general_summary']) ? implode("\n", $analysis['general_summary']) : $analysis['general_summary'];
$pdf->MultiCell(0, 6, $summary, 0, 'J');
$pdf->commitTransaction();
$pdf->Ln(5);

// ===== GÜÇLÜ YÖNLER =====
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 8, 'GÜÇLÜ YÖNLER', 0, 1, 'L');
$pdf->Ln(2);

// Sayfa bolunmesini engelle
if ($pdf->GetY() > 220) $pdf->AddPage();

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(209, 231, 221);
$strengths = is_array($analysis['strengths']) ? implode("\n", $analysis['strengths']) : $analysis['strengths'];
$pdf->MultiCell(0, 6, $strengths, 1, 'L', true);
$pdf->Ln(5);

// ===== GELİŞTİRİLMESİ GEREKENLER =====
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->SetTextColor(200, 0, 0);
$pdf->Cell(0, 8, 'GELİŞTİRİLMESİ GEREKENLER', 0, 1, 'L');
$pdf->Ln(2);

// Sayfa bolunmesini engelle
if ($pdf->GetY() > 220) $pdf->AddPage();

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(248, 215, 218);
$weaknesses = is_array($analysis['weaknesses']) ? implode("\n", $analysis['weaknesses']) : $analysis['weaknesses'];
$pdf->MultiCell(0, 6, $weaknesses, 1, 'L', true);
$pdf->Ln(5);

// ===== ÖĞRETMEN İÇİN TAVSİYELER =====
// Sayfa bölünmesini engelle - başlık ve içerik birlikte kalsın
if ($pdf->GetY() > 200) $pdf->AddPage(); // Daha erken kontrol

$pdf->SetFont('dejavusans', 'B', 12);
$pdf->SetTextColor(0, 100, 200);
$pdf->Cell(0, 8, 'ÖĞRETMEN İÇİN TAVSİYELER', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(207, 226, 255);
$recommendations = is_array($analysis['recommendations']) ? implode("\n", $analysis['recommendations']) : $analysis['recommendations'];
$pdf->MultiCell(0, 6, $recommendations, 1, 'L', true);
$pdf->Ln(8);

// ===== TELAFI ETKINLIKLERI =====
if (!empty($analysis['remedial_activities'])) {
    // Yeni sayfa gerekirse ac
    if ($pdf->GetY() > 180) $pdf->AddPage();
    
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(200, 150, 0);
    $pdf->Cell(0, 8, 'ÖNERİLEN TELAFİ ETKİNLİKLERİ', 0, 1, 'L');
    $pdf->Ln(2);
    
    // Tablo başlıkları - Yeniden düzenlendi
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(255, 193, 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 7, 'Konu', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Zorluk', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Tür', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Süre', 1, 1, 'C', true);
    
    // Etkinlikler - Her satır için açıklama alt satırda
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetFillColor(255, 248, 225);
    
    foreach ($analysis['remedial_activities'] as $i => $activity) {
        $fill = $i % 2 == 0;
        
        // Türkçe çeviriler
        $type_tr = $activity['activity_type'];
        if (strpos(strtolower($type_tr), 'exercise') !== false) $type_tr = 'Alıştırma';
        elseif (strpos(strtolower($type_tr), 'reading') !== false) $type_tr = 'Okuma';
        elseif (strpos(strtolower($type_tr), 'quiz') !== false) $type_tr = 'Quiz';
        
        // Ana bilgiler
        $pdf->Cell(70, 8, $activity['topic'] ?? '', 1, 0, 'L', $fill);
        $pdf->Cell(30, 8, ucfirst($activity['difficulty'] ?? 'Orta'), 1, 0, 'C', $fill);
        $pdf->Cell(40, 8, $type_tr, 1, 0, 'C', $fill);
        $pdf->Cell(40, 8, $activity['estimated_time'] ?? '15 dk', 1, 1, 'C', $fill);
        
        // Açıklama - Alt satırda, tüm genişlikte
        $pdf->SetFont('dejavusans', 'I', 7);
        $pdf->SetFillColor(255, 252, 240);
        $description = $activity['description'] ?? '';
        $pdf->MultiCell(180, 5, '> ' . $description, 1, 'L', $fill);
        $pdf->SetFont('dejavusans', '', 8);
    }
}

// PDF Çıktısı
$filename = 'Sinav_Analiz_Raporu_' . $quiz_id . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D'); // D = Download
exit;
?>
