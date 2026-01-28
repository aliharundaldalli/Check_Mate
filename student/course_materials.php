<?php
// Increase memory limit and execution time
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 120);

try {
  require_once '../includes/functions.php';

  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  $auth = new Auth();

  // Öğrenci yetkisi kontrolü
  if (!$auth->checkRole('student')) {
    show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
    redirect('../index.php');
    exit;
  }

  $database = new Database();
  $db = $database->getConnection();

  if (!$db) {
    throw new Exception('Veritabanı bağlantısı kurulamadı.');
  }

  $student_id = $_SESSION['user_id'];
  $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

} catch (Exception $e) {
  error_log('Student Course Materials Error: ' . $e->getMessage());
  die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// Site ayarlarını yükle
try {
  $query = "SELECT setting_key, setting_value FROM site_settings";
  $stmt = $db->prepare($query);
  $stmt->execute();
  $site_settings = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $site_settings[$row['setting_key']] = $row['setting_value'];
  }
} catch (Exception $e) {
  error_log('Site settings error: ' . $e->getMessage());
  $site_settings = [];
}

$site_name = $site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi';

// Dersi ve öğrencinin bu derse kayıtlı olup olmadığını kontrol et
$course = null;
if ($course_id > 0) {
  try {
    $query = "SELECT c.*, u.full_name as teacher_name
          FROM courses c
          JOIN users u ON c.teacher_id = u.id
          JOIN course_enrollments ce ON c.id = ce.course_id
          WHERE c.id = :course_id
          AND ce.student_id = :student_id
          AND ce.is_active = 1
          AND c.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
      show_message('Bu derse erişim yetkiniz bulunmamaktadır.', 'danger');
      redirect('courses.php');
      exit;
    }
  } catch (Exception $e) {
    error_log('Course fetch error: ' . $e->getMessage());
    show_message('Sistem hatası oluştu.', 'danger');
    redirect('courses.php');
    exit;
  }
} else {
  show_message('Geçersiz ders seçimi.', 'danger');
  redirect('courses.php');
  exit;
}

// Haftalık içerikleri al (sadece yayınlanmış olanlar)
$weeks = [];
try {
  // *** DÜZELTME 1 ***
  // Sorgu, sadece yayınlanmış (is_published = 1) haftaları alacak şekilde düzeltildi.
  // publish_date kontrolü kaldırıldı, böylece yayınlanmış ama tarihi
  // ileride olan haftalar da listelenir (eğer istenirse).
  // Öğrencinin yayınlanmamış haftaları görmesi engellendi.
  $query = "SELECT id, course_id, week_number, week_title, week_description,
         is_published, publish_date, created_at, updated_at
       FROM course_weeks
       WHERE course_id = :course_id
       AND is_published = 1
       ORDER BY week_number ASC";
  $stmt = $db->prepare($query);
  $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
  $stmt->execute();
  $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  error_log('Weeks fetch error: ' . $e->getMessage());
}

// Her hafta için materyalleri al
$materials_by_week = [];
if (!empty($weeks)) {
  try {
    $week_ids = array_column($weeks, 'id');

    // Hiç hafta bulunamazsa sorguyu çalıştırmayı engelle
    if (count($week_ids) > 0) {
      $placeholders = str_repeat('?,', count($week_ids) - 1) . '?';

      // GÜNCELLEME: file_path ve file_name alanları sorguya eklendi.
      $query = "SELECT id, week_id, material_type, material_title, material_url,
             material_description, duration_minutes, file_path, file_name, file_size,
             display_order, is_required, created_at, updated_at
          FROM course_materials
          WHERE week_id IN ($placeholders)
          ORDER BY week_id, display_order ASC";
      $stmt = $db->prepare($query);
      $stmt->execute($week_ids);
      $all_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Materyalleri haftalara göre grupla
      foreach ($all_materials as $material) {
        $materials_by_week[$material['week_id']][] = $material;
      }
    }
  } catch (Exception $e) {
    error_log('Materials fetch error: ' . $e->getMessage());
  }
}

// Öğrencinin tamamladığı materyallerin ID'lerini al
$completed_material_ids = [];
try {
  $query_completed = "SELECT material_id FROM student_material_progress
            WHERE student_id = :student_id AND is_completed = 1";
  $stmt_completed = $db->prepare($query_completed);
  $stmt_completed->bindParam(':student_id', $student_id, PDO::PARAM_INT);
  $stmt_completed->execute();
  $completed_material_ids = $stmt_completed->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
  error_log('Completed materials fetch error: ' . $e->getMessage());
}


// YouTube URL'den Video ID çıkarma fonksiyonu
function getYouTubeVideoId($url) {
  $video_id = '';
  $url = trim($url);

  // youtube.com/watch?v=VIDEO_ID
  if (preg_match('/youtube\.com\/watch\?v=([^\&\?\/]+)/', $url, $id)) {
    $video_id = $id[1];
  }
  // youtube.com/embed/VIDEO_ID
  elseif (preg_match('/youtube\.com\/embed\/([^\&\?\/]+)/', $url, $id)) {
    $video_id = $id[1];
  }
  // youtu.be/VIDEO_ID
  elseif (preg_match('/youtu\.be\/([^\&\?\/]+)/', $url, $id)) {
    $video_id = $id[1];
  }

  return $video_id;
}

// YouTube Embed HTML oluşturma
function getYouTubeEmbed($url, $title = 'Video', $material_id = 0) { // Added material_id
  $video_id = getYouTubeVideoId($url);

  if (empty($video_id)) {
    return '<div class="alert alert-warning">Geçersiz YouTube URL</div>';
  }

  // Added data-material-id to the wrapper
  return '<div class="video-wrapper" data-material-id="' . $material_id . '">
        <iframe
          src="https://www.youtube.com/embed/' . htmlspecialchars($video_id) . '?rel=0&enablejsapi=1"
          title="' . htmlspecialchars($title) . '"
          frameborder="0"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen>
        </iframe>
      </div>';
}

$page_title = "Ders İçerikleri - " . htmlspecialchars($course['course_name']) . " - " . htmlspecialchars($site_name);

include '../includes/components/student_header.php';
?>

<style>
  /* Genel Stil İyileştirmeleri */
  body {
    background-color: #f4f7f6; /* Hafif gri arka plan */
  }
  .container-fluid {
    max-width: 1200px; /* İçeriği ortala ve genişliği sınırla */
    margin: auto;
  }
  .card {
    border: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08); /* Daha belirgin gölge */
    border-radius: 12px; /* Daha yuvarlak köşeler */
  }

  /* Header Stilleri */
  .page-header h3 {
    color: #343a40; /* Koyu gri başlık */
    font-weight: 600;
  }
  .page-header p {
    color: #6c757d; /* Açık gri metin */
  }

  /* Hafta Kartları */
  .week-card {
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem; /* Kartlar arası boşluk artırıldı */
    border-radius: 12px; /* Daha yuvarlak köşeler */
    overflow: hidden;
  }

  .week-header {
    color: white;
    padding: 1.25rem 1.5rem; /* Dolgu artırıldı */
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex; /* İkonu sağa hizalamak için */
    justify-content: space-between;
    align-items: center;
  }

  .week-header.published {
    /* Yeşil gradient */
    background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
  }
  .week-header.published:hover {
    background: linear-gradient(135deg, #a8e063 0%, #56ab2f 100%);
  }

  /* Henüz yayınlanmamış (ama listede görünen) haftalar için stil */
  .week-header.unpublished {
    background: linear-gradient(135deg, #adb5bd 0%, #6c757d 100%);
    opacity: 0.9;
  }
  .week-header.unpublished:hover {
    background: linear-gradient(135deg, #6c757d 0%, #adb5bd 100%);
  }

  .week-title {
    font-size: 1.2rem; /* Başlık boyutu büyütüldü */
    font-weight: 600;
    margin: 0;
  }

  .week-info {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-top: 0.3rem;
  }
  .week-header i.fa-chevron-down {
    transition: transform 0.3s ease; /* Açma/kapama animasyonu */
  }
  .accordion-button:not(.collapsed) i.fa-chevron-down {
    transform: rotate(180deg);
  }

  /* Akordiyon Buton Stilleri */
  .accordion-button {
    background-color: transparent;
    border: none;
    padding: 0;
    width: 100%; /* Tam genişlik kaplaması için */
    text-align: left; /* Metni sola hizala */
  }
  .accordion-button:not(.collapsed) {
    background-color: transparent;
    box-shadow: none;
  }
  .accordion-button:focus {
    box-shadow: none;
    border: none;
  }
  .accordion-body {
    padding: 1.5rem; /* İçerik dolgusu artırıldı */
  }

  /* Materyal Öğeleri */
  .material-item {
    position: relative;
    padding: 1.25rem; /* Dolgu artırıldı */
    margin-bottom: 1rem;
    border-radius: 8px;
    background-color: #ffffff; /* Beyaz arka plan */
    border: 1px solid #e9ecef; /* İnce kenarlık */
    border-left-width: 5px; /* Sol kenarlık vurgusu */
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05); /* Hafif gölge */
  }
  .material-item:hover {
    background-color: #f8f9fa; /* Hover'da hafif gri */
    transform: translateY(-2px); /* Hafif yukarı kalkma efekti */
    box-shadow: 0 4px 8px rgba(0,0,0,0.07);
  }

  /* Tamamlanmış Materyal İşareti */
  .material-item.completed-material {
    border-left-color: #28a745 !important; /* Sol kenarlık yeşil */
    background-color: #e9f7ef; /* Çok açık yeşil arka plan */
  }
  .material-item.completed-material::after {
    content: '\f00c'; /* Font Awesome check */
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    top: 15px; /* Konum ayarlandı */
    right: 15px;
    font-size: 1.5rem;
    color: #28a745;
    opacity: 0.8;
  }

  /* Materyal Türüne Göre Sol Kenarlık Renkleri */
  .material-item.video:not(.completed-material) { border-left-color: #dc3545; }
  .material-item.document:not(.completed-material) { border-left-color: #0d6efd; }
  .material-item.link:not(.completed-material) { border-left-color: #6f42c1; }
  .material-item.other:not(.completed-material) { border-left-color: #6c757d; }

  .material-title {
    font-weight: 600;
    color: #343a40; /* Koyu gri başlık */
    margin-bottom: 0.5rem;
    font-size: 1.1rem; /* Başlık boyutu ayarlandı */
  }

  .material-description {
    color: #6c757d; /* Açık gri açıklama */
    font-size: 0.9rem;
    margin-bottom: 1rem; /* Meta ile arasına boşluk */
  }

  /* Meta Bilgileri (Badge'ler) */
  .material-meta {
    display: flex;
    gap: 0.75rem; /* Badge arası boşluk */
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 1rem; /* Butonlarla arasına boşluk */
  }

  .material-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.8rem; /* Dolgu ayarlandı */
    border-radius: 50px; /* Tam yuvarlak */
    font-size: 0.75rem; /* Boyut küçültüldü */
    font-weight: 500;
    text-transform: uppercase; /* Büyük harf */
    letter-spacing: 0.5px;
  }

  /* Badge Renkleri */
  .badge-video { background-color: #f8d7da; color: #721c24; }
  .badge-document { background-color: #cfe2ff; color: #052c65; }
  .badge-link { background-color: #e2d9f3; color: #2a0b55; }
  .badge-other { background-color: #e2e3e5; color: #383d41; }
  .badge-required { background-color: #fff3cd; color: #664d03; }
  .badge-completed { background-color: #d1e7dd; color: #0f5132; }

  /* Video Gömme */
  .video-wrapper {
    position: relative;
    padding-bottom: 56.25%; /* 16:9 */
    height: 0;
    overflow: hidden;
    border-radius: 8px;
    margin: 1.5rem 0; /* Üst ve alt boşluk artırıldı */
    background-color: #000;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
  }
  .video-wrapper iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
  }

  /* Boş Durum */
  .empty-state {
    text-align: center;
    padding: 4rem 1rem; /* Dolgu artırıldı */
  }
  .empty-state i {
    font-size: 4.5rem; /* İkon büyütüldü */
    color: #ced4da; /* Daha açık renk ikon */
    margin-bottom: 1.5rem;
  }
  .empty-state h4 { color: #6c757d; }
  .empty-state p { color: #adb5bd; }

  /* Aksiyon Butonları */
  .action-buttons {
    margin-top: 1rem; /* Üst boşluk ayarlandı */
    display: flex;
    gap: 0.75rem; /* Buton arası boşluk */
    flex-wrap: wrap;
  }

  .btn-view-material {
    padding: 0.6rem 1.2rem; /* Buton dolgusu artırıldı */
    font-size: 0.9rem;
    font-weight: 500;
    border-radius: 6px; /* Hafif yuvarlak köşe */
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    display: inline-flex; /* İkonu hizalamak için */
    align-items: center;
    gap: 0.5rem; /* İkon ve metin arası boşluk */
  }
  .btn-view-material:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
  }
  .btn-view-material.disabled-completed {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none; /* Hover efektini iptal et */
    box-shadow: 0 2px 4px rgba(0,0,0,0.1); /* Gölgeyi sıfırla */
  }

  /* Ek Dosya Bilgisi */
  .attachment-info {
    background-color: #e9ecef; /* Hafif gri arka plan */
    border-left: 4px solid #adb5bd; /* Sol kenarlık */
    padding: 0.75rem 1rem; /* Dolgu */
    margin-bottom: 1rem; /* Alt boşluk */
    border-radius: 4px; /* Hafif köşe yuvarlaklığı */
  }
  .attachment-info .fa-paperclip {
    color: #495057; /* İkon rengi */
  }
</style>

<div class="container-fluid py-4">
  <!-- Header -->
  <div class="row mb-4 page-header">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h3>
            <i class="fas fa-layer-group me-2 text-primary"></i>
            Ders İçerikleri
          </h3>
          <p class="text-muted mb-0">
            <strong><?php echo htmlspecialchars($course['course_name']); ?></strong>
            (<?php echo htmlspecialchars($course['course_code']); ?>) -
            <span class="fw-medium"><?php echo htmlspecialchars($course['teacher_name']); ?></span>
          </p>
        </div>
        <a href="courses.php" class="btn btn-outline-secondary">
          <i class="fas fa-arrow-left me-1"></i>Derslere Dön
        </a>
      </div>
    </div>
  </div>

  <?php display_message(); ?>

  <!-- Weeks & Materials -->
  <?php if (empty($weeks)): ?>
    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <div class="empty-state text-center py-4">
              <i class="fas fa-folder-open fa-3x mb-3 text-muted"></i>
              <h4 class="text-muted">Henüz İçerik Yok</h4>
              <p class="text-muted">Bu ders için öğretmen tarafından henüz içerik eklenmemiş veya yayınlanmamış.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="accordion" id="weeksAccordion">
      <?php foreach ($weeks as $index => $week): ?>
        <?php
        if ($week['is_published'] != 1) continue;
        $materials = $materials_by_week[$week['id']] ?? [];
        $material_count = count($materials);
        ?>
        <div class="week-card">
          <div class="accordion-item border-0 rounded-3 shadow-sm mb-3">
            <h2 class="accordion-header" id="heading<?php echo $week['id']; ?>">
              <button class="accordion-button <?php echo $index !== 0 ? 'collapsed' : ''; ?>"
                      type="button"
                      data-bs-toggle="collapse"
                      data-bs-target="#collapse<?php echo $week['id']; ?>"
                      aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                      aria-controls="collapse<?php echo $week['id']; ?>">
                <div class="week-header w-100">
                  <div>
                  <div class="week-title fw-medium" style="color: #000;">
  <i class="fas fa-calendar-week me-2"></i>
  Hafta <?php echo $week['week_number']; ?>
  <?php if (!empty($week['week_title'])): ?>
    - <?php echo htmlspecialchars($week['week_title']); ?>
  <?php endif; ?>
</div>

                    <div class="week-info text-muted small">
                      <i class="fas fa-layer-group me-1"></i> <?php echo $material_count; ?> içerik
                      <?php if ($week['publish_date']): ?>
                        <span class="ms-3">
                          <i class="fas fa-calendar-alt me-1"></i>
                          <?php echo date('d.m.Y', strtotime($week['publish_date'])); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <i class="fas fa-chevron-down ms-3"></i>
                </div>
              </button>
            </h2>

            <div id="collapse<?php echo $week['id']; ?>"
                 class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>"
                 aria-labelledby="heading<?php echo $week['id']; ?>"
                 data-bs-parent="#weeksAccordion">
              <div class="accordion-body">
                <?php if (!empty($week['week_description'])): ?>
                  <div class="alert alert-light border-start border-4 border-info py-2 px-3 mb-4">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    <?php echo nl2br(htmlspecialchars($week['week_description'])); ?>
                  </div>
                <?php endif; ?>

                <?php if (empty($materials)): ?>
                  <div class="empty-state py-3 text-center text-muted">
                    <i class="fas fa-box-open fa-2x mb-2"></i>
                    <p class="mb-0">Bu hafta için henüz içerik eklenmemiş.</p>
                  </div>
                <?php else: ?>
                  <?php foreach ($materials as $material): ?>
                    <?php
                    $is_completed = in_array($material['id'], $completed_material_ids);
                    $icon_map = [
                      'video' => 'fa-play-circle',
                      'document' => 'fa-file-alt',
                      'link' => 'fa-link',
                      'other' => 'fa-paperclip'
                    ];
                    $icon = $icon_map[$material['material_type']] ?? 'fa-file';
                    $badge_class_map = [
                      'video' => 'badge-video',
                      'document' => 'badge-document',
                      'link' => 'badge-link',
                      'other' => 'badge-other'
                    ];
                    $badge_class = $badge_class_map[$material['material_type']] ?? 'badge-other';
                    $type_names = [
                      'video' => 'Video',
                      'document' => 'Döküman',
                      'link' => 'Link',
                      'other' => 'Diğer'
                    ];
                    $type_name = $type_names[$material['material_type']] ?? 'Materyal';
                    $base_url = rtrim($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'], '/');
                    $download_url = $base_url . '/' . ltrim($material['file_path'], '/');
                    ?>
                    <div class="material-item <?php echo htmlspecialchars($material['material_type']); ?> <?php echo $is_completed ? 'completed-material' : ''; ?>" id="material-<?php echo $material['id']; ?>">
                      <div class="material-title fw-medium">
                        <i class="fas <?php echo $icon; ?> me-2"></i>
                        <?php echo htmlspecialchars($material['material_title']); ?>
                      </div>

                      <?php if (!empty($material['material_description'])): ?>
                        <div class="material-description text-muted">
                          <?php echo nl2br(htmlspecialchars($material['material_description'])); ?>
                        </div>
                      <?php endif; ?>

                      <?php if (!empty($material['file_path']) && !empty($material['file_name'])): ?>
                        <div class="attachment-info mb-2">
                          <i class="fas fa-paperclip me-2"></i>
                          <span class="fw-medium">Ek Dosya:</span> <?php echo htmlspecialchars($material['file_name']); ?>
                          <?php if (!empty($material['file_size'])): ?>
                            <small class="text-muted ms-1">(<?php echo $material['file_size']; ?>)</small>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <div class="material-meta mb-2">
                        <span class="material-badge <?php echo $badge_class; ?>">
                          <i class="fas <?php echo $icon; ?> me-1"></i>
                          <?php echo $type_name; ?>
                        </span>
                        <?php if ($material['is_required']): ?>
                          <span class="material-badge badge-required">
                            <i class="fas fa-star me-1"></i> Zorunlu
                          </span>
                        <?php endif; ?>
                        <?php if ($is_completed): ?>
                          <span class="material-badge badge-completed">
                            <i class="fas fa-check me-1"></i> Tamamlandı
                          </span>
                        <?php endif; ?>
                        <?php if ($material['duration_minutes']): ?>
                          <span class="material-badge bg-light text-dark">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo $material['duration_minutes']; ?> dk
                          </span>
                        <?php endif; ?>
                      </div>

                      <?php if ($material['material_type'] === 'video' && !empty($material['material_url'])): ?>
                        <?php echo getYouTubeEmbed($material['material_url'], $material['material_title'], $material['id']); ?>
                      <?php endif; ?>

                      <div class="action-buttons d-flex flex-wrap gap-2 mt-2">
                        <?php if (!empty($material['file_path'])): ?>
                          <a href="<?php echo htmlspecialchars($download_url); ?>"
                             class="btn btn-success btn-sm btn-view-material"
                             download="<?php echo htmlspecialchars($material['file_name'] ?? basename($material['file_path'])); ?>"
                             data-material-id="<?php echo $material['id']; ?>">
                            <i class="fas fa-download"></i> Dosyayı İndir
                          </a>
                        <?php endif; ?>

                        <?php if (!empty($material['material_url'])): ?>
                          <?php if ($material['material_type'] === 'video'): ?>
                            <a href="<?php echo htmlspecialchars($material['material_url']); ?>"
                               target="_blank"
                               class="btn btn-danger btn-sm btn-view-material"
                               data-material-id="<?php echo $material['id']; ?>">
                              <i class="fab fa-youtube"></i> YouTube'da Aç
                            </a>
                          <?php else: ?>
                            <a href="<?php echo htmlspecialchars($material['material_url']); ?>"
                               target="_blank"
                               class="btn btn-primary btn-sm btn-view-material"
                               data-material-id="<?php echo $material['id']; ?>">
                              <i class="fas fa-external-link-alt"></i>
                              <?php echo $material['material_type'] === 'document' ? 'Dökümanı Aç' : 'Linki Aç'; ?>
                            </a>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<!-- Added jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap Bundle JS (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- YouTube IFrame API -->
<script src="https://www.youtube.com/iframe_api"></script>

<script>
$(document).ready(function() {
  // YouTube Player Tracking
  var players = {}; // Object to hold player instances
  var materialMarkedAsPlaying = {}; // Track if 'playing' state triggered the AJAX call

  // Make the API ready function globally accessible
  window.onYouTubeIframeAPIReady = function() {
    console.log("YouTube API Ready");
    var iframes = document.querySelectorAll('.video-wrapper iframe');
    iframes.forEach(function(iframe) {
      var wrapper = iframe.closest('.video-wrapper');
      var materialId = wrapper ? wrapper.getAttribute('data-material-id') : null;
      if (materialId) {
        try {
          players[materialId] = new YT.Player(iframe, {
            events: {
              'onStateChange': function(event) {
                onPlayerStateChange(event, materialId);
              }
            }
          });
        } catch (e) {
          console.error("Error creating YouTube player for material ID " + materialId + ": ", e);
        }
      }
    });
  }

  // Player state change handler
  function onPlayerStateChange(event, materialId) {
    if (event.data == YT.PlayerState.PLAYING && !materialMarkedAsPlaying[materialId]) {
      console.log('Video started playing for material ID:', materialId);
      materialMarkedAsPlaying[materialId] = true;
      markMaterialAsComplete(materialId);
    }
  }

  // Function to send AJAX request to mark material as complete
  function markMaterialAsComplete(materialId) {
    if (!materialId) {
      console.error('Material ID not found for marking complete.');
      return;
    }

    const materialElement = $(`#material-${materialId}`);
    // Görsel olarak zaten işaretlenmişse tekrar AJAX gönderme
    if(materialElement.hasClass('completed-material')) {
      console.log(`Material ${materialId} is already marked as complete visually.`);
      return;
    }

    console.log(`Marking material ${materialId} as complete via AJAX...`);

    $.ajax({
      url: 'ajax/mark_material_complete.php', // AJAX dosyasının doğru yolu (varsayım)
      type: 'POST',
      data: { material_id: materialId },
      dataType: 'json',
      success: function(response) {
        console.log('AJAX Response:', response);
        if (response.status === 'success') {
          console.log('Material successfully marked as complete.');
          // Görsel geri bildirim ekle
          materialElement.addClass('completed-material');
          // Tamamlandı badge'ini ekle (eğer yoksa)
          if (materialElement.find('.badge-completed').length === 0) {
            const metaDiv = materialElement.find('.material-meta');
            $('<span class="material-badge badge-completed"><i class="fas fa-check me-1"></i> Tamamlandı</span>').appendTo(metaDiv);
          }
          // Butonları devre dışı bırak
          materialElement.find('.btn-view-material').addClass('disabled-completed');
        } else {
          console.error('Error marking material as complete:', response.message);
          // İsteğe bağlı: Kullanıcıya hata mesajı göster
          // alert('Materyal tamamlandı olarak işaretlenirken bir hata oluştu: ' + response.message);
        }
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.error('AJAX error:', textStatus, errorThrown);
        // İsteğe bağlı: Genel bir hata mesajı göster
        // alert('Materyal durumu güncellenirken bir ağ hatası oluştu.');
      }
    });
  }

  // Aksiyon butonlarına tıklama dinleyicisi ekle
  $('.action-buttons').on('click', '.btn-view-material', function(e) {
    // Zaten tamamlanmış/devre dışı ise tekrar işaretleme
    if ($(this).hasClass('disabled-completed')) {
      console.log('Button clicked, but material already completed.');
      // Varsayılan eylemi (link açma/indirme) engelleme, AJAX'ı engelle
      return;
    }

    const materialId = $(this).data('material-id');
    const materialElement = $(`#material-${materialId}`);

    // Video olmayan linkler/indirmeler için tıklandığında hemen tamamlandı olarak işaretle
    // Video tamamlama YouTube API tarafından onPlayerStateChange ile yönetilir
    if (!materialElement.hasClass('video')) {
      markMaterialAsComplete(materialId);
    } else {
      console.log('Video button clicked for material ID:', materialId, '- completion will be triggered by player start.');
      // YouTube linkine tıklandığında da tamamlandı olarak işaretle (eğer iframe yoksa veya kullanıcı dışarıda açarsa)
      // Bu, sadece YouTube linki içeren video materyalleri için geçerli.
      if ($(this).hasClass('btn-danger')) { // YouTube'da Aç butonu
        markMaterialAsComplete(materialId);
      }
    }
    // Varsayılan eylemi (link açma/indirme) engellemiyoruz, böylece normal şekilde çalışır.
  });

  // Sayfa yüklendiğinde zaten tamamlanmış öğelere sınıf ekle
  const completedIds = <?php echo json_encode($completed_material_ids); ?>;
  completedIds.forEach(id => {
    const materialElement = $(`#material-${id}`);
    if (materialElement.length) {
      materialElement.addClass('completed-material');
      materialElement.find('.btn-view-material').addClass('disabled-completed');
    }
  });

});
</script>

<?php include '../includes/components/shared_footer.php'; ?>

