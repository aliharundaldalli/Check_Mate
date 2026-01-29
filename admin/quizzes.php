<?php
// admin/quizzes.php - Tüm Sınavların Listesi
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

// SİLME İŞLEMİ
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    try {
        $stmt = $db->prepare("DELETE FROM quizzes WHERE id = :id");
        $stmt->execute([':id' => $delete_id]);
        $message = show_message('Sınav başarıyla silindi.', 'success');
    } catch (PDOException $e) {
        $message = show_message('Hata: ' . $e->getMessage(), 'danger');
    }
}

// SINAVLARI GETİR
try {
    // Sınav ile birlikte ders adı ve oluşturan öğretmen adını da çekelim
    $query = "SELECT q.*, c.course_name, c.course_code, u.full_name as teacher_name 
              FROM quizzes q 
              JOIN courses c ON q.course_id = c.id
              JOIN users u ON q.created_by = u.id 
              ORDER BY q.created_at DESC";
    $stmt = $db->query($query);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

$page_title = 'Sınav Yönetimi';
include '../includes/components/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Sınav Yönetimi</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <!-- İlerde toplu işlem vb eklenebilir -->
    </div>
</div>

<?php echo $message; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle">
                <thead class="table-light">
                        <th width="20%">Sınav Başlığı</th>
                        <th width="20%">Ders</th>
                        <th width="20%">Öğretmen</th>
                        <th width="15%">Tarih</th>
                        <th width="20%">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quizzes)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">Henüz hiç sınav oluşturulmamış.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quizzes as $quiz): ?>
                            <tr>
                                <td class="fw-bold text-primary">
                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($quiz['course_code']); ?>
                                    </span><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($quiz['course_name']); ?></small>
                                </td>
                                <td>
                                    <i class="fas fa-user-tie text-secondary me-1"></i>
                                    <?php echo htmlspecialchars($quiz['teacher_name']); ?>
                                </td>
                                <td>
                                    <small><?php echo date('d.m.Y H:i', strtotime($quiz['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="quiz_submissions.php?quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-info text-white" title="Katılımları Gör">
                                            <i class="fas fa-users"></i> Sonuçlar
                                        </a>
                                        <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-primary" title="Düzenle">
                                            <i class="fas fa-edit"></i> Düzenle
                                        </a>
                                        <a href="?delete_id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu sınavı silmek istediğinize emin misiniz? Tüm öğrenci cevapları da silinecektir!');" title="Sınavı Sil">
                                            <i class="fas fa-trash"></i>
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
