<?php
// Site ayarlarını yükle
function loadSiteSettings() {
    global $db;
    if (!$db) {
        $database = new Database();
        $db = $database->getConnection();
    }
    
    $query = "SELECT setting_key, setting_value FROM site_settings";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $site_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Varsayılan değerler
    $defaults = [
        'site_name' => 'AhdaKade Öğretmen',
        'site_logo' => '',
        'site_favicon' => '',
        'theme_color' => '#27ae60'
    ];
    
    return array_merge($defaults, $site_settings);
}

$site_settings = loadSiteSettings();
$site_name = $site_settings['site_name'];
$site_logo = $site_settings['site_logo'];
$site_favicon = $site_settings['site_favicon'];
$theme_color = $site_settings['theme_color'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Öğretmen Panel'; ?> - <?php echo htmlspecialchars($site_name); ?></title>
    <!-- MathJax -->
    <script>window.MathJax = { tex: { inlineMath: [['$', '$'], ['\\(', '\\)']] }, svg: { fontCache: 'global' } };</script>
    <script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>
    <?php if (!empty($site_favicon)): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '../' : ''; ?>uploads/<?php echo htmlspecialchars($site_favicon); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --theme-color: <?php echo $theme_color; ?>;
            --theme-color-rgb: <?php 
                $hex = str_replace('#', '', $theme_color);
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                echo "$r, $g, $b";
            ?>;
        }
        
        .sidebar {
            background: linear-gradient(180deg, #27ae60 0%, #229954 100%);
            min-height: 100vh;
            position: fixed;
            width: 280px;
            top: 0;
            left: 0;
            z-index: 1000;
            transform: translateX(0);
            transition: transform 0.3s ease;
        }
        
        .sidebar.collapsed {
            transform: translateX(-280px);
        }
        
        .main-content {
            margin-left: 280px;
            background-color: #f8f9fa;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 4px 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        
        .navbar-brand {
            background: linear-gradient(45deg, var(--theme-color), rgba(var(--theme-color-rgb), 0.8));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: bold;
        }
        
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        .mobile-toggle {
            display: none;
            background: #27ae60;
            border: none;
            color: white;
            padding: 10px;
            border-radius: 5px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
        }
        
        .logo-container img {
            max-height: 50px;
            max-width: 160px;
            object-fit: contain;
        }
        
        .logo-container h4 {
            color: white;
            margin: 0;
            font-size: 1rem;
            text-align: center;
        }
        
        .sidebar {
            display: flex;
            flex-direction: column;
        }
        
        .sidebar .nav {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 10px;
        }
        
        .sidebar-footer {
            padding: 10px 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-info .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .user-info .user-details {
            flex: 1;
            min-width: 0;
            text-align: left;
        }
        
        .user-info .user-name {
            font-weight: bold;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-info .user-role {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        .user-info .user-actions {
            display: flex;
            gap: 5px;
            flex-shrink: 0;
        }
        
        .user-info .user-actions .btn {
            padding: 4px 8px;
            font-size: 0.75rem;
        }
        
        .top-navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 15px 0;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
            border-radius: 15px;
            color: white;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(39, 174, 96, 0.3);
        }
        
        .stat-card.secondary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }
        
        .stat-card.secondary:hover {
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.3);
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
        }
        
        .stat-card.warning:hover {
            box-shadow: 0 10px 25px rgba(243, 156, 18, 0.3);
        }
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }
        
        .stat-card.danger:hover {
            box-shadow: 0 10px 25px rgba(231, 76, 60, 0.3);
        }
        
        .session-card {
            border-left: 4px solid #27ae60;
            transition: transform 0.2s ease;
        }
        
        .session-card:hover {
            transform: translateX(5px);
        }
        
        .session-card.active {
            border-left-color: #e74c3c;
            background: linear-gradient(45deg, rgba(231, 76, 60, 0.1), rgba(192, 57, 43, 0.05));
        }
        
        .session-card.upcoming {
            border-left-color: #3498db;
            background: linear-gradient(45deg, rgba(52, 152, 219, 0.1), rgba(41, 128, 185, 0.05));
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-280px);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-toggle {
                display: block;
            }
            
            .mobile-overlay.show {
                display: block;
            }
        }
        
        /* Tema rengi düğmeleri için */
        .btn-theme {
            background: var(--theme-color);
            border-color: var(--theme-color);
            color: white;
        }
        
        .btn-theme:hover {
            background: rgba(var(--theme-color-rgb), 0.8);
            border-color: rgba(var(--theme-color-rgb), 0.8);
            color: white;
        }
        
        .quick-action {
            background: linear-gradient(135deg, var(--theme-color) 0%, rgba(var(--theme-color-rgb), 0.8) 100%);
            border: none;
            border-radius: 50px;
            color: white;
            padding: 12px 30px;
            transition: all 0.3s ease;
        }
        
        .quick-action:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="logo-container">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '../' : ''; ?>uploads/<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
            <?php else: ?>
                <h4>
                    <i class="fas fa-chalkboard-teacher me-2"></i>
                    <?php echo htmlspecialchars($site_name); ?>
                </h4>
            <?php endif; ?>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>Kontrol Paneli
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>courses.php">
                    <i class="fas fa-book"></i>Derslerim
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'quizzes.php' || basename($_SERVER['PHP_SELF']) == 'create_quiz.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>quizzes.php">
                    <i class="fas fa-file-alt"></i>Sınav Yönetimi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'assignments.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>assignments.php">
                    <i class="fas fa-tasks"></i>Ödevler
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>attendance.php">
                    <i class="fas fa-calendar-check"></i>Yoklama Yönetimi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>students.php">
                    <i class="fas fa-user-graduate"></i>Öğrencilerim
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>messages.php">
                    <i class="fas fa-envelope"></i>Mesajlarım
                </a>
            </li>
        </ul>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <?php 
                $profile_image_path = null;
                if (isset($_SESSION['user_id'])) {
                    $query = "SELECT profile_image FROM users WHERE id = :user_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $_SESSION['user_id']);
                    $stmt->execute();
                    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user_data && !empty($user_data['profile_image']) && file_exists('../' . $user_data['profile_image'])) {
                        $profile_image_path = '../' . $user_data['profile_image'];
                    }
                }
                ?>
                
                <?php if ($profile_image_path): ?>
                    <img src="<?php echo htmlspecialchars($profile_image_path); ?>" alt="Profil" class="user-avatar">
                <?php else: ?>
                    <i class="fas fa-user-circle" style="font-size: 2rem;"></i>
                <?php endif; ?>
                
                <div class="user-details">
                    <div class="user-name"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Öğretmen'; ?></div>
                    <div class="user-role">Öğretmen</div>
                </div>
                
                <div class="user-actions">
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '' : 'teacher/'; ?>profile.php" 
                       class="btn btn-sm btn-outline-light" title="Profil">
                        <i class="fas fa-user-edit"></i>
                    </a>
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/teacher/') !== false ? '../' : ''; ?>logout.php" 
                       class="btn btn-sm btn-outline-light" title="Çıkış">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light top-navbar">
            <div class="container-fluid">
                <button class="mobile-toggle me-3" id="mobileToggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="navbar-brand mb-0 h1"><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Öğretmen Panel'; ?></span>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user-circle me-1"></i>
                        Hoş geldiniz, <?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Öğretmen'; ?>
                    </span>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="container-fluid p-4">
