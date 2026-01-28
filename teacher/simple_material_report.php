<?php
/**
 * BASİT MATERYAL TAKİP RAPORU
 * Mevcut tablolarla çalışır, hiçbir değişiklik gerektirmez
 */

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
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Dersi kontrol et
$query = "SELECT * FROM courses WHERE id = :course_id AND teacher_id = :teacher_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
$stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
$stmt->execute();
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    show_message('Bu derse erişim yetkiniz bulunmamaktadır.', 'danger');
    redirect('courses.php');
    exit;
}

// Haftaları ve materyalleri getir
$query = "SELECT 
            cw.id as week_id,
            cw.week_number,
            cw.week_title,
            cm.id as material_id,
            cm.title as material_title,
            cm.material_type
          FROM course_weeks cw
          LEFT JOIN course_materials cm ON cm.week_id = cw.id
          WHERE cw.course_id = :course_id
          ORDER BY cw.week_number, cm.id";
$stmt = $db->prepare($query);
$stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
$stmt->execute();
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Öğrencileri getir
$query = "SELECT u.id, u.full_name, u.email
          FROM users u
          INNER JOIN course_enrollments ce ON ce.student_id = u.id
          WHERE ce.course_id = :course_id AND ce.status = 'active'
          ORDER BY u.full_name";
$stmt = $db->prepare($query);
$stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tamamlanma verilerini getir
$progress_data = [];
if (!empty($students) && !empty($materials)) {
    $query = "SELECT student_id, material_id, is_completed, progress_percentage 
              FROM student_material_progress";
    $stmt = $db->query($query);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $progress_data[$row['student_id']][$row['material_id']] = $row;
    }
}

include '../includes/components/shared_header.php';
?>

<style>
.report-container {
    padding: 20px;
    background: #f8f9fa;
    min-height: 100vh;
}

.page-header {
    background: white;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 25px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

.page-header h2 {
    margin: 0 0 10px 0;
    color: #2c3e50;
    font-size: 28px;
}

.page-header .course-name {
    color: #7f8c8d;
    font-size: 18px;
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    text-align: center;
}

.stat-card.blue { border-left: 4px solid #3498db; }
.stat-card.green { border-left: 4px solid #2ecc71; }
.stat-card.orange { border-left: 4px solid #e67e22; }
.stat-card.red { border-left: 4px solid #e74c3c; }

.stat-card .number {
    font-size: 36px;
    font-weight: bold;
    margin: 10px 0;
}

.stat-card .label {
    color: #7f8c8d;
    font-size: 14px;
}

.report-table-container {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.report-table th {
    background: #34495e;
    color: white;
    padding: 15px 10px;
    text-align: left;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 10;
}

.report-table th:first-child {
    border-radius: 8px 0 0 0;
    padding-left: 20px;
}

.report-table th:last-child {
    border-radius: 0 8px 0 0;
}

.report-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #ecf0f1;
}

.report-table td:first-child {
    padding-left: 20px;
    font-weight: 500;
    color: #2c3e50;
}

.report-table tbody tr:hover {
    background: #f8f9fa;
}

.report-table tbody tr:last-child td {
    border-bottom: none;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    min-width: 100px;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.status-incomplete {
    background: #fff3cd;
    color: #856404;
}

.status-not-started {
    background: #f8d7da;
    color: #721c24;
}

.status-icon {
    margin-right: 5px;
}

.material-header {
    font-size: 12px;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.week-label {
    font-size: 11px;
    color: #95a5a6;
    display: block;
    margin-bottom: 3px;
}

.progress-mini {
    font-size: 11px;
    color: #7f8c8d;
    margin-top: 3px;
}

.material-type-icon {
    font-size: 12px;
    margin-right: 3px;
}

@media print {
    .no-print { display: none !important; }
    .report-table { font-size: 11px; }
    .status-badge { padding: 4px 8px; font-size: 11px; }
}
</style>

<div class="report-container">
    <!-- Header -->
    <div class="page-header">
        <h2>📊 Materyal Tamamlanma Raporu</h2>
        <div class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></div>
    </div>

    <?php
    // İstatistikleri hesapla
    $total_students = count($students);
    $total_materials = 0;
    $total_completions = 0;
    $total_possible = 0;

    foreach ($materials as $material) {
        if ($material['material_id']) {
            $total_materials++;
            foreach ($students as $student) {
                $total_possible++;
                if (isset($progress_data[$student['id']][$material['material_id']])) {
                    if ($progress_data[$student['id']][$material['material_id']]['is_completed']) {
                        $total_completions++;
                    }
                }
            }
        }
    }

    $completion_rate = $total_possible > 0 ? round(($total_completions / $total_possible) * 100, 1) : 0;
    $incomplete = $total_possible - $total_completions;
    ?>

    <!-- İstatistik Kartları -->
    <div class="stats-cards no-print">
        <div class="stat-card blue">
            <div class="label">Toplam Öğrenci</div>
            <div class="number"><?php echo $total_students; ?></div>
        </div>
        <div class="stat-card green">
            <div class="label">Tamamlanan</div>
            <div class="number"><?php echo $total_completions; ?></div>
        </div>
        <div class="stat-card orange">
            <div class="label">Tamamlanma Oranı</div>
            <div class="number">%<?php echo $completion_rate; ?></div>
        </div>
        <div class="stat-card red">
            <div class="label">Eksik</div>
            <div class="number"><?php echo $incomplete; ?></div>
        </div>
    </div>

    <!-- Rapor Tablosu -->
    <div class="report-table-container">
        <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
            <h4 style="margin: 0; color: #2c3e50;">Öğrenci Tamamlanma Durumu</h4>
            <button onclick="window.print()" class="btn btn-sm btn-primary no-print">
                🖨️ Yazdır
            </button>
        </div>

        <?php if (empty($students)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Bu derse kayıtlı öğrenci bulunmamaktadır.
            </div>
        <?php elseif (empty($materials)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Henüz materyal eklenmemiş.
            </div>
        <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Öğrenci Adı</th>
                        <?php foreach ($materials as $material): ?>
                            <?php if ($material['material_id']): ?>
                                <th style="text-align: center;">
                                    <span class="week-label">Hafta <?php echo $material['week_number']; ?></span>
                                    <div class="material-header" title="<?php echo htmlspecialchars($material['material_title']); ?>">
                                        <?php if ($material['material_type'] == 'video'): ?>
                                            <span class="material-type-icon">🎥</span>
                                        <?php elseif ($material['material_type'] == 'document'): ?>
                                            <span class="material-type-icon">📄</span>
                                        <?php elseif ($material['material_type'] == 'link'): ?>
                                            <span class="material-type-icon">🔗</span>
                                        <?php else: ?>
                                            <span class="material-type-icon">📦</span>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($material['material_title']); ?>
                                    </div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                            </td>
                            <?php foreach ($materials as $material): ?>
                                <?php if ($material['material_id']): ?>
                                    <td style="text-align: center;">
                                        <?php
                                        $progress = isset($progress_data[$student['id']][$material['material_id']]) 
                                                    ? $progress_data[$student['id']][$material['material_id']] 
                                                    : null;
                                        
                                        if ($progress && $progress['is_completed']):
                                        ?>
                                            <span class="status-badge status-completed">
                                                <span class="status-icon">✓</span> Tamamlandı
                                            </span>
                                        <?php elseif ($progress): ?>
                                            <span class="status-badge status-incomplete">
                                                <span class="status-icon">⏳</span> Devam Ediyor
                                            </span>
                                            <div class="progress-mini">%<?php echo $progress['progress_percentage']; ?></div>
                                        <?php else: ?>
                                            <span class="status-badge status-not-started">
                                                <span class="status-icon">✗</span> İzlenmedi
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Geri Dön Butonu -->
    <div style="margin-top: 20px; text-align: center;" class="no-print">
        <a href="course_materials.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary">
            ← Geri Dön
        </a>
    </div>
</div>

<?php include '../includes/components/shared_footer.php'; ?>