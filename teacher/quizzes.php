<?php
// quizzes.php - Öğretmen Sınav Yönetimi

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Istanbul');

try {
    require_once '../includes/functions.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $auth = new Auth();
    if (!$auth->checkRole('teacher')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    
    $teacher_id = $_SESSION['user_id'];

} catch (Exception $e) {
    error_log('Quizzes Page Error: ' . $e->getMessage());
    die('Sistem hatası oluştu.');
}

// Sınav Silme İşlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    try {
        $stmt = $db->prepare("DELETE FROM quizzes WHERE id = :id AND created_by = :teacher_id");
        $stmt->execute([':id' => $del_id, ':teacher_id' => $teacher_id]);
        if ($stmt->rowCount() > 0) {
            show_message('Sınav başarıyla silindi.', 'success');
        } else {
            show_message('Sınav silinemedi veya yetkiniz yok.', 'danger');
        }
        redirect('quizzes.php');
    } catch (PDOException $e) {
        show_message('Hata: ' . $e->getMessage(), 'danger');
    }
}

// Sınavları Listele
$quizzes = [];
try {
    $query = "SELECT q.*, c.course_name, c.course_code 
              FROM quizzes q
              JOIN courses c ON q.course_id = c.id
              WHERE q.created_by = :teacher_id
              ORDER BY q.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':teacher_id' => $teacher_id]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tablo yoksa hata verebilir, kullanıcıya nazikçe söyleyelim
    $error_msg = $e->getMessage();
}

$page_title = "Sınav Yönetimi - Check Mate";
include '../includes/components/teacher_header.php';
?>

<div class="container-fluid py-4">
    <?php display_message(); ?>

    <?php if (isset($error_msg) && strpos($error_msg, 'Table') !== false): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Veritabanı Tabloları Eksik!</strong><br>
            Sınav sistemi için gerekli tablolar henüz oluşturulmamış gibi görünüyor. Lütfen sistem yöneticisiyle iletişime geçin.
        </div>
    <?php else: ?>

    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h1 class="h3 text-gray-800"><i class="fas fa-file-alt me-2"></i>Sınavlar</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="create_quiz.php" class="btn btn-primary shadow-sm">
                <i class="fas fa-plus me-1"></i> Yeni Sınav Oluştur
            </a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <?php if (empty($quizzes)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-signature fa-3x text-muted mb-3"></i>
                    <p class="lead text-muted">Henüz hiç sınav oluşturmadınız.</p>
                    <a href="create_quiz.php" class="btn btn-outline-primary mt-2">
                        <i class="fas fa-magic me-1"></i> İlk Sınavınızı Oluşturun
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Başlık</th>
                                <th>Ders</th>
                                <th>Durum</th>
                                <th>Oluşturulma</th>
                                <th class="text-end">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizzes as $quiz): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($quiz['title']); ?></strong>
                                        <?php if ($quiz['description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($quiz['description'], 0, 50)) . '...'; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($quiz['course_code']); ?></span>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($quiz['course_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($quiz['is_active']): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Pasif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($quiz['created_at'])); ?></td>
                                    <div class="d-block d-md-none mt-2"> <!-- Mobile view actions -->
                                        <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-primary w-100 mb-1">
                                            <i class="fas fa-edit me-1"></i> Düzenle
                                        </a>
                                        <a href="quiz_results.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-info w-100 mb-1 text-white">
                                            <i class="fas fa-chart-bar me-1"></i> Sonuçlar
                                        </a>
                                         <a href="quizzes.php?delete=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-danger w-100" onclick="return confirm('Bu sınavı silmek istediğinize emin misiniz?');">
                                            <i class="fas fa-trash me-1"></i> Sil
                                        </a>
                                    </div>
                                    <td class="text-end d-none d-md-table-cell">
                                        <div class="btn-group">
                                            <a href="quiz_results.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-info" title="Sonuçlar">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                            <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="quizzes.php?delete=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-danger" title="Sil" onclick="return confirm('Bu sınavı silmek istediğinize emin misiniz? Tüm sonuçlar silinecek!');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<?php 
// Footer is usually closed in header or layout, check index.php structure? 
// Based on dashboard.php, it just ends. But usually footer is needed. 
// I'll add a script tag just in case.
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
