<?php
// admin/quiz_submissions.php - Bir Sınavın Sonuçları ve Yönetimi
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/functions.php';

// Oturum ve Yetki Kontrolü
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$auth = new Auth();
if (!$auth->checkRole('admin')) {
    redirect('../login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();
$message = '';

if (!isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) {
    redirect('quizzes.php');
    exit;
}
$quiz_id = (int)$_GET['quiz_id'];

// Sınav Bilgilerini Al
$stmtQ = $db->prepare("SELECT title FROM quizzes WHERE id = :id");
$stmtQ->execute([':id' => $quiz_id]);
$quiz = $stmtQ->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    die("Sınav bulunamadı.");
}

// SİLME (RESETLEME) İŞLEMİ
if (isset($_GET['delete_submission_id'])) {
    $sub_id = (int)$_GET['delete_submission_id'];
    try {
        $stmtDel = $db->prepare("DELETE FROM quiz_submissions WHERE id = :id");
        $stmtDel->execute([':id' => $sub_id]);
        $message = show_message('Öğrencinin sınav kaydı silindi. Öğrenci sınava tekrar girebilir.', 'success');
    } catch (PDOException $e) {
        $message = show_message('Hata: ' . $e->getMessage(), 'danger');
    }
}

// BAŞVURULARI GETİR
try {
    $query = "SELECT qs.*, u.full_name, u.student_number 
              FROM quiz_submissions qs 
              JOIN users u ON qs.student_id = u.id 
              WHERE qs.quiz_id = :qid 
              ORDER BY qs.score DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':qid' => $quiz_id]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

$page_title = 'Sınav Sonuçları: ' . $quiz['title'];
include '../includes/components/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Sonuçlar: <?php echo htmlspecialchars($quiz['title']); ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="quizzes.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>
</div>

<?php echo $message; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Öğrenci No</th>
                        <th>Ad Soyad</th>
                        <th>Puan</th>
                        <th>Durum</th>
                        <th>Tamamlanma</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">Henüz bu sınava katılım olmamış.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sub['student_number']); ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($sub['full_name']); ?></td>
                                <td>
                                    <?php if ($sub['status'] == 'graded'): ?>
                                        <span class="badge bg-success fs-6"><?php echo $sub['score']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $statusMap = [
                                            'started' => '<span class="badge bg-warning text-dark">Başladı / Devam Ediyor</span>',
                                            'completed' => '<span class="badge bg-info text-dark">Tamamladı (Puanlanmadı)</span>',
                                            'graded' => '<span class="badge bg-success">Puanlandı</span>'
                                        ];
                                        echo $statusMap[$sub['status']] ?? $sub['status'];
                                    ?>
                                </td>
                                <td>
                                    <small><?php echo $sub['completed_at'] ? date('d.m.Y H:i', strtotime($sub['completed_at'])) : '-'; ?></small>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="view_submission.php?id=<?php echo $sub['id']; ?>" class="btn btn-sm btn-outline-primary" title="Detaylı İncele">
                                            <i class="fas fa-search"></i>
                                        </a>
                                        <a href="?quiz_id=<?php echo $quiz_id; ?>&delete_submission_id=<?php echo $sub['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bu sınav kaydını silmek istediğinize emin misiniz? Öğrencinin notu silinecek ve TEKRAR SINAVA GİREBİLECEK.');" title="Hakkı Sıfırla (Sil)">
                                            <i class="fas fa-trash-restore"></i> Sıfırla
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/components/shared_footer.php'; ?>
