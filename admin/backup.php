<?php
// admin/backup.php

// Increase memory limit and execution time for backup operations
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 600); // 10 minutes
date_default_timezone_set('Europe/Istanbul');

require_once '../includes/functions.php';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Admin role check
    if (!$auth->checkRole('admin')) {
        show_message('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'danger');
        redirect('../index.php');
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Veritabanı bağlantısı kurulamadı.');
    }
    
} catch (Exception $e) {
    error_log('Admin Backup Error: ' . $e->getMessage());
    die('Sistem hatası oluştu. Lütfen sistem yöneticisine başvurun.');
}

// === BACKUP SYSTEM CLASS ===
class BackupSystem {
    private $db;
    private $backup_dir;
    private $site_settings;
    
    public function __construct($database) {
        $this->db = $database;
        $this->backup_dir = dirname(__DIR__) . '/backups/';
        
        // Backup klasörü oluştur
        if (!file_exists($this->backup_dir)) {
            mkdir($this->backup_dir, 0755, true);
        }
        
        // Site ayarlarını yükle
        $this->loadSiteSettings();
    }
    
    private function loadSiteSettings() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings");
            $this->site_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $this->site_settings = [];
        }
    }
    
    // Veritabanı yedeği al
    public function createDatabaseBackup() {
        try {
            $backup_file = $this->backup_dir . 'database_backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Tüm tabloları al
            $tables = [];
            $result = $this->db->query("SHOW TABLES");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            $sql_dump = "-- Database Backup\n";
            $sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
            $sql_dump .= "-- AhdaKade Yoklama Sistemi\n\n";
            
            $sql_dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sql_dump .= "START TRANSACTION;\n";
            $sql_dump .= "SET time_zone = \"+00:00\";\n\n";
            
            foreach ($tables as $table) {
                // Tablo yapısını al
                $create_table = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $sql_dump .= "\n\n-- Table structure for table `$table`\n";
                $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_dump .= $create_table['Create Table'] . ";\n\n";
                
                // Tablo verilerini al
                $sql_dump .= "-- Dumping data for table `$table`\n";
                $rows = $this->db->query("SELECT * FROM `$table`");
                
                if ($rows->rowCount() > 0) {
                    $sql_dump .= "INSERT INTO `$table` VALUES ";
                    $first_row = true;
                    
                    while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                        if (!$first_row) {
                            $sql_dump .= ",\n";
                        }
                        $first_row = false;
                        
                        $sql_dump .= "(";
                        for ($i = 0; $i < count($row); $i++) {
                            if ($i > 0) $sql_dump .= ", ";
                            $sql_dump .= $row[$i] === null ? 'NULL' : "'" . addslashes($row[$i]) . "'";
                        }
                        $sql_dump .= ")";
                    }
                    $sql_dump .= ";\n";
                }
            }
            
            $sql_dump .= "\nCOMMIT;\n";
            
            file_put_contents($backup_file, $sql_dump);
            
            return [
                'success' => true,
                'file' => basename($backup_file),
                'size' => $this->formatBytes(filesize($backup_file)),
                'path' => $backup_file
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // Schema Backup (Structure Only)
    public function createSchemaBackup() {
        try {
            $backup_file = $this->backup_dir . 'schema_backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            $tables = [];
            $result = $this->db->query("SHOW TABLES");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            $sql_dump = "-- Database Schema (Structure Only)\n";
            $sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
            $sql_dump .= "-- AhdaKade Yoklama Sistemi\n\n";
            
            $sql_dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sql_dump .= "START TRANSACTION;\n";
            $sql_dump .= "SET time_zone = \"+00:00\";\n\n";
            
            foreach ($tables as $table) {
                // Get structure
                $create_table = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $sql_dump .= "\n\n-- Table structure for table `$table`\n";
                $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_dump .= $create_table['Create Table'] . ";\n";
                
                // Optionally dump configuration/static data only
                if ($table === 'site_settings') {
                     $sql_dump .= "\n-- Default Data for `$table`\n";
                     $rows = $this->db->query("SELECT * FROM `$table`");
                     if ($rows->rowCount() > 0) {
                        $sql_dump .= "INSERT INTO `$table` VALUES ";
                        $first_row = true;
                        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                            if (!$first_row) $sql_dump .= ",\n";
                            $first_row = false;
                            $sql_dump .= "(";
                            for ($i = 0; $i < count($row); $i++) {
                                if ($i > 0) $sql_dump .= ", ";
                                $sql_dump .= $row[$i] === null ? 'NULL' : "'" . addslashes($row[$i]) . "'";
                            }
                            $sql_dump .= ")";
                        }
                        $sql_dump .= ";\n";
                     }
                }
            }
            
            $sql_dump .= "\nCOMMIT;\n";
            
            file_put_contents($backup_file, $sql_dump);
            
            return [
                'success' => true,
                'file' => basename($backup_file),
                'size' => $this->formatBytes(filesize($backup_file)),
                'path' => $backup_file
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Dosya sistemi yedeği al
    public function createFileBackup($include_uploads = true) {
        try {
            $backup_file = $this->backup_dir . 'files_backup_' . date('Y-m-d_H-i-s') . '.zip';
            
            $zip = new ZipArchive();
            if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception('Zip dosyası oluşturulamadı');
            }
            
            $project_root = dirname(__DIR__);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($project_root, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($project_root) + 1);
                
                // Hariç tutulacak klasörler
                $exclude_dirs = ['backups', 'logs', 'vendor', '.git', 'node_modules'];
                if (!$include_uploads) {
                    $exclude_dirs[] = 'uploads';
                }
                
                $should_exclude = false;
                foreach ($exclude_dirs as $exclude_dir) {
                    if (strpos($relative_path, $exclude_dir . DIRECTORY_SEPARATOR) === 0 || 
                        $relative_path === $exclude_dir) {
                        $should_exclude = true;
                        break;
                    }
                }
                
                // Büyük dosyaları ve geçici dosyaları hariç tut
                if (!$should_exclude && $file->isFile() && $file->getSize() < 50 * 1024 * 1024) { // 50MB limit
                    $zip->addFile($file_path, $relative_path);
                }
            }
            
            $zip->close();
            
            return [
                'success' => true,
                'file' => basename($backup_file),
                'size' => $this->formatBytes(filesize($backup_file)),
                'path' => $backup_file
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Tam yedek (veritabanı + dosyalar)
    public function createFullBackup($include_uploads = true) {
        try {
            $backup_file = $this->backup_dir . 'full_backup_' . date('Y-m-d_H-i-s') . '.zip';
            
            // Önce veritabanı yedeği al
            $db_backup = $this->createDatabaseBackup();
            if (!$db_backup['success']) {
                throw new Exception('Veritabanı yedeği alınamadı: ' . $db_backup['error']);
            }
            
            $zip = new ZipArchive();
            if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception('Zip dosyası oluşturulamadı');
            }
            
            // Veritabanı yedeğini ekle
            $zip->addFile($db_backup['path'], 'database/' . $db_backup['file']);
            
            // Dosya sistemi yedeği ekle
            $project_root = dirname(__DIR__);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($project_root, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($project_root) + 1);
                
                $exclude_dirs = ['backups', 'logs', 'vendor', '.git', 'node_modules'];
                if (!$include_uploads) {
                    $exclude_dirs[] = 'uploads';
                }
                
                $should_exclude = false;
                foreach ($exclude_dirs as $exclude_dir) {
                    if (strpos($relative_path, $exclude_dir . DIRECTORY_SEPARATOR) === 0 || 
                        $relative_path === $exclude_dir) {
                        $should_exclude = true;
                        break;
                    }
                }
                
                if (!$should_exclude && $file->isFile() && $file->getSize() < 50 * 1024 * 1024) {
                    $zip->addFile($file_path, 'files/' . $relative_path);
                }
            }
            
            // Yedek bilgi dosyası ekle
            $backup_info = "Backup Information\n";
            $backup_info .= "==================\n";
            $backup_info .= "Date: " . date('Y-m-d H:i:s') . "\n";
            $backup_info .= "Site: " . ($this->site_settings['site_name'] ?? 'AhdaKade') . "\n";
            $backup_info .= "Version: 1.0\n";
            $backup_info .= "Type: Full Backup\n";
            $backup_info .= "Include Uploads: " . ($include_uploads ? 'Yes' : 'No') . "\n";
            
            $zip->addFromString('backup_info.txt', $backup_info);
            $zip->close();
            
            // Geçici veritabanı dosyasını sil
            unlink($db_backup['path']);
            
            return [
                'success' => true,
                'file' => basename($backup_file),
                'size' => $this->formatBytes(filesize($backup_file)),
                'path' => $backup_file
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Mevcut yedekleri listele
    public function listBackups() {
        $backups = [];
        
        if (is_dir($this->backup_dir)) {
            $files = glob($this->backup_dir . '*');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $backups[] = [
                        'name' => basename($file),
                        'size' => $this->formatBytes(filesize($file)),
                        'date' => date('d.m.Y H:i:s', filemtime($file)),
                        'type' => $this->getBackupType(basename($file))
                    ];
                }
            }
            
            // Tarihe göre sırala (en yeni önce)
            usort($backups, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
        }
        
        return $backups;
    }
    
    // Yedek dosyasını sil
    public function deleteBackup($filename) {
        $file_path = $this->backup_dir . basename($filename);
        
        if (file_exists($file_path) && is_file($file_path)) {
            return unlink($file_path);
        }
        
        return false;
    }
    
    // Dosya boyutunu formatla
    private function formatBytes($size, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }
    
    // Yedek tipini belirle
    private function getBackupType($filename) {
        if (strpos($filename, 'database_') === 0) {
            return 'Veritabanı';
        } elseif (strpos($filename, 'files_') === 0) {
            return 'Dosyalar';
        } elseif (strpos($filename, 'full_') === 0) {
            return 'Tam Yedek';
        } elseif (strpos($filename, 'schema_') === 0) {
            return 'Şema (Yapı)';
        }
        return 'Bilinmeyen';
    }
}

// === MAIN LOGIC ===
$backup_system = new BackupSystem($db);
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);
$backup_result = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backup_type = filter_input(INPUT_POST, 'backup_type', FILTER_SANITIZE_STRING);
    $include_uploads = isset($_POST['include_uploads']);
    
    switch ($backup_type) {
        case 'database':
            $backup_result = $backup_system->createDatabaseBackup();
            break;
        case 'files':
            $backup_result = $backup_system->createFileBackup($include_uploads);
            break;
        case 'full':
            $backup_result = $backup_system->createFullBackup($include_uploads);
            break;
        case 'schema':
            $backup_result = $backup_system->createSchemaBackup();
            break;
    }
    
    if ($backup_result) {
        if ($backup_result['success']) {
            show_message('Yedek başarıyla oluşturuldu: ' . $backup_result['file'] . ' (' . $backup_result['size'] . ')', 'success');
        } else {
            show_message('Yedek oluşturulurken hata: ' . $backup_result['error'], 'danger');
        }
    }
}

// Handle backup deletion
if ($action === 'delete') {
    $filename = filter_input(INPUT_GET, 'file', FILTER_SANITIZE_STRING);
    if ($filename && $backup_system->deleteBackup($filename)) {
        show_message('Yedek dosyası başarıyla silindi.', 'success');
    } else {
        show_message('Yedek dosyası silinemedi.', 'danger');
    }
}

// Handle backup download
if ($action === 'download') {
    $filename = filter_input(INPUT_GET, 'file', FILTER_SANITIZE_STRING);
    $file_path = dirname(__DIR__) . '/backups/' . basename($filename);
    
    if (file_exists($file_path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        show_message('Dosya bulunamadı.', 'danger');
    }
}

// Site ayarları
$stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
$site_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_name = $site_settings['site_name'] ?? 'AhdaKade Yoklama Sistemi';

// Mevcut yedekleri listele
$existing_backups = $backup_system->listBackups();

$page_title = "Yedekleme - " . htmlspecialchars($site_name);
include '../includes/components/admin_header.php';
?>

<div class="container-fluid p-4">
    
    <?php display_message(); ?>

    <!-- Backup Creation Form -->
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus-circle me-2"></i>Yeni Yedek Oluştur
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="backupForm">
                        <div class="mb-3">
                            <label class="form-label">Yedek Türü</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="backup_type" id="database" value="database" checked>
                                <label class="form-check-label" for="database">
                                    <i class="fas fa-database me-1"></i>Sadece Veritabanı
                                    <small class="text-muted d-block">Tüm tablo verileri ve yapıları</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="backup_type" id="files" value="files">
                                <label class="form-check-label" for="files">
                                    <i class="fas fa-folder me-1"></i>Sadece Dosyalar
                                    <small class="text-muted d-block">PHP dosyaları ve ayarlar</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="backup_type" id="schema" value="schema">
                                <label class="form-check-label" for="schema">
                                    <i class="fas fa-code me-1"></i>Sadece Şema (Yapı)
                                    <small class="text-muted d-block">Boş veritabanı yapısı (Deploy için)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="backup_type" id="full" value="full">
                                <label class="form-check-label" for="full">
                                    <i class="fas fa-archive me-1"></i>Tam Yedek
                                    <small class="text-muted d-block">Veritabanı + dosyalar (önerilen)</small>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_uploads" id="include_uploads" checked>
                                <label class="form-check-label" for="include_uploads">
                                    <i class="fas fa-images me-1"></i>Yüklenen dosyaları dahil et
                                    <small class="text-muted d-block">Logo, favicon ve diğer yüklenen dosyalar</small>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="createBackupBtn">
                            <i class="fas fa-download me-1"></i>Yedek Oluştur
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Yedekleme Hakkında
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6 class="alert-heading">Önemli Bilgiler:</h6>
                        <ul class="mb-0">
                            <li><strong>Veritabanı Yedeği:</strong> Tüm kullanıcı verileri, dersler, yoklama kayıtları</li>
                            <li><strong>Dosya Yedeği:</strong> PHP kodları, ayar dosyaları, tema dosyaları</li>
                            <li><strong>Tam Yedek:</strong> Her şey dahil (önerilen seçenek)</li>
                            <li><strong>Şema (Yapı):</strong> Sadece veritabanı tabloları (veri içermez)</li>
                            <li><strong>Boyut Limiti:</strong> Tek dosya maksimum 50MB</li>
                            <li><strong>Saklama:</strong> Yedekler sunucuda /backups/ klasöründe saklanır</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6 class="alert-heading">Güvenlik Uyarısı:</h6>
                        <p class="mb-0">Yedek dosyalarını güvenli bir yerde saklayın ve düzenli olarak silin.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Existing Backups -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history me-2"></i>Mevcut Yedekler (<?php echo count($existing_backups); ?>)
                    </h5>
                    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-1"></i>Yenile
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($existing_backups)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-archive fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz yedek dosyası bulunmuyor.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Dosya Adı</th>
                                        <th>Tür</th>
                                        <th>Boyut</th>
                                        <th>Tarih</th>
                                        <th width="150">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($existing_backups as $backup): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-file-archive me-1"></i>
                                                <?php echo htmlspecialchars($backup['name']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $backup['type'] === 'Tam Yedek' ? 'success' : 
                                                        ($backup['type'] === 'Veritabanı' ? 'primary' : 
                                                        ($backup['type'] === 'Şema (Yapı)' ? 'secondary' : 'info')); 
                                                ?>">
                                                    <?php echo htmlspecialchars($backup['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($backup['size']); ?></td>
                                            <td><?php echo htmlspecialchars($backup['date']); ?></td>
                                            <td>
                                                <a href="?action=download&file=<?php echo urlencode($backup['name']); ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="İndir">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <a href="?action=delete&file=<?php echo urlencode($backup['name']); ?>" 
                                                   class="btn btn-sm btn-outline-danger" 
                                                   onclick="return confirm('Bu yedek dosyasını silmek istediğinizden emin misiniz?')"
                                                   title="Sil">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('backupForm');
    const button = document.getElementById('createBackupBtn');
    
    form.addEventListener('submit', function() {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Yedek Oluşturuluyor...';
        
        // 30 saniye sonra butonu tekrar aktif et (güvenlik)
        setTimeout(function() {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-download me-1"></i>Yedek Oluştur';
        }, 30000);
    });
    
    // Form değişikliklerinde upload checkbox'ını kontrol et
    const radioButtons = document.querySelectorAll('input[name="backup_type"]');
    const uploadCheckbox = document.getElementById('include_uploads');
    
    radioButtons.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'database' || this.value === 'schema') {
                uploadCheckbox.disabled = true;
                uploadCheckbox.checked = false;
            } else {
                uploadCheckbox.disabled = false;
                uploadCheckbox.checked = true;
            }
        });
    });
});
</script>

<?php include '../includes/components/shared_footer.php'; ?>