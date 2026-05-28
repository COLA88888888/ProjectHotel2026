<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Session Inactivity Timeout Check (15 minutes) ---
$timeout_duration = 900; 

if (isset($_SESSION['checked']) && $_SESSION['checked'] === 1) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        $logout_url = (strpos($_SERVER['PHP_SELF'], '/users/') !== false || 
                       strpos($_SERVER['PHP_SELF'], '/rooms/') !== false || 
                       strpos($_SERVER['PHP_SELF'], '/currency/') !== false || 
                       strpos($_SERVER['PHP_SELF'], '/room_types/') !== false ||
                       strpos($_SERVER['PHP_SELF'], '/services/') !== false ||
                       strpos($_SERVER['PHP_SELF'], '/products/') !== false ||
                       strpos($_SERVER['PHP_SELF'], '/reports/') !== false ||
                       strpos($_SERVER['PHP_SELF'], '/print/') !== false ||
                       strpos($_SERVER['PHP_SELF'], '/tools/') !== false) ? '../logout.php?timeout=1' : 'logout.php?timeout=1';
                       
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        
        header("Location: " . $logout_url);
        exit();
    }
    $_SESSION['last_activity'] = time();
}

// Prevent browser caching for protected pages
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// --- 2. ສ່ວນກວດສອບເພັກເກັດ ແລະ ວັນໝົດອາຍຸ (Package Subscription & Expiry Check) ---
$is_sub = (strpos($_SERVER['PHP_SELF'], '/users/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/rooms/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/currency/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/room_types/') !== false ||
           strpos($_SERVER['PHP_SELF'], '/services/') !== false ||
           strpos($_SERVER['PHP_SELF'], '/products/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/reports/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/print/') !== false || 
           strpos($_SERVER['PHP_SELF'], '/tools/') !== false);
$db_path = $is_sub ? '../config/db.php' : 'config/db.php';

if (file_exists($db_path)) {
    require_once $db_path;
}

if (isset($pdo)) {
    try {
        // Load translations for session check block
        $current_lang = $_SESSION['lang'] ?? 'la';
        $lang_file = $is_sub ? "../lang/{$current_lang}.php" : "lang/{$current_lang}.php";
        if (file_exists($lang_file)) {
            include $lang_file;
        } else {
            $default_lang_file = $is_sub ? "../lang/la.php" : "lang/la.php";
            if (file_exists($default_lang_file)) {
                include $default_lang_file;
            }
        }

        $stmtPkg = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('package_name', 'package_expires')");
        $pkg_data = $stmtPkg->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $package_name = $pkg_data['package_name'] ?? null;
        $package_expires = $pkg_data['package_expires'] ?? null;
        
        // Auto-initialize default package settings if they are missing
        if ($package_name === null) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('package_name', '')")->execute();
            $package_name = '';
        }
        if ($package_expires === null) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('package_expires', '')")->execute();
            $package_expires = '';
        }
        
        // Calculate remaining days
        if (empty($package_expires) || $package_expires === '0000-00-00') {
            $days_remaining = 99999; // infinite duration if empty/not defined yet
        } else {
            $today = new DateTime(date('Y-m-d'));
            $expiry = new DateTime($package_expires);
            $interval = $today->diff($expiry);
            $days_remaining = (int)$interval->format('%r%a');
        }
        
        // --------------------------------------------------------------------------
        // [ໝາຍເຫດ ສຳລັບຜູ້ພັດທະນາ / Developer Note]:
        // ປັດຈຸບັນໄດ້ທຳການປ່ຽນແປງເງື່ອນໄຂຈາກ <= 0 ເປັນ <= -99999 ເພື່ອປົດລັອກລະບົບຊົ່ວຄາວ (Bypass Lock Screen)
        // ເຮັດໃຫ້ທ່ານສາມາດເຂົ້າສູ່ລະບົບ ແລະ ຈັດການແພັກເກັດການໃຊ້ງານໃນໜ້າ Settings.php ໄດ້ຢ່າງສະດວກ.
        // ຫາກຕ້ອງການເປີດການກວດສອບວັນໝົດອາຍຸໃຫ້ກັບມາເຮັດວຽກຕາມປົກກະຕິ ໃຫ້ປ່ຽນກັບເປັນ: $days_remaining <= 0
        // --------------------------------------------------------------------------
        if ($days_remaining <= -99999) { // ແກ້ໄຂຊົ່ວຄາວເພື່ອປົດລັອກລະບົບ (ຄ່າເລີ່ມຕົ້ນຄື: $days_remaining <= 0)
            $logout_url = $is_sub ? '../logout.php' : 'logout.php';
            $lock_title = $lang['package_lock_title'] ?? 'ລະບົບໝົດອາຍຸການໃຊ້ງານ';
            $lock_desc = $lang['package_lock_desc'] ?? 'ເພັກເກັດການນຳໃຊ້ລະບົບຂອງທ່ານໄດ້ໝົດອາຍຸລົງແລ້ວ. ກະລຸນາຕິດຕໍ່ຫາຜູ້ທີ່ໃຫ້ນຳໃຊ້ລະບົບ ຫຼື ຜູ້ພັດທະນາເພື່ອຕໍ່ອາຍຸການນຳໃຊ້.';
            $contact_title = $lang['contact_admin'] ?? 'ຊ່ອງທາງການຕິດຕໍ່ຜູ້ສະໜອງລະບົບ';
            $logout_txt = $lang['logout'] ?? 'ອອກຈາກລະບົບ';
            
            $css_path = $is_sub ? '../assets/css/pages/session_check.css' : 'assets/css/pages/session_check.css';
            echo "<!DOCTYPE html>
            <html lang='lo'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>" . htmlspecialchars($lock_title) . "</title>
                <link href='https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;700&display=swap' rel='stylesheet'>
                <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css'>
                <link rel='stylesheet' href='{$css_path}'>
            </head>
            <body class='lock-screen'>
                <div class='expired-card'>
                    <div class='icon-wrapper'>
                        <i class='fas fa-lock'></i>
                    </div>
                    <h1 class='lock-title'>" . htmlspecialchars($lock_title) . "</h1>
                    " . (!empty($package_name) ? "
                    <div class='pkg-badge'>
                        <i class='fas fa-cube mr-1'></i> " . htmlspecialchars($package_name) . "
                    </div>" : "") . "
                    <p class='lock-desc'>" . htmlspecialchars($lock_desc) . "</p>
                    
                    <div class='contact-box'>
                        <div class='contact-title'>" . htmlspecialchars($contact_title) . "</div>
                        <div class='contact-info'>
                            <i class='fas fa-phone-alt'></i> +856 20 55 949 007
                        </div>
                    </div>
                    
                    <div class='btn-group'>
                        <a href='https://wa.me/+8562055949007' target='_blank' class='btn btn-primary'>
                            <i class='fab fa-whatsapp'></i> WhatsApp
                        </a>
                        <a href='{$logout_url}' class='btn btn-secondary'>
                            <i class='fas fa-sign-out-alt'></i> " . htmlspecialchars($logout_txt) . "
                        </a>
                    </div>
                </div>
            </body>
            </html>";
            exit();
        }
    } catch (Exception $e) {
        // Fail silently or log error
    }
}

if (!isset($_SESSION['checked']) || $_SESSION['checked'] !== 1) {
    // If inside a subfolder (like /users/), we need to go up one level
    $rootPath = (strpos($_SERVER['PHP_SELF'], '/users/') !== false || 
                 strpos($_SERVER['PHP_SELF'], '/rooms/') !== false || 
                 strpos($_SERVER['PHP_SELF'], '/currency/') !== false || 
                 strpos($_SERVER['PHP_SELF'], '/room_types/') !== false ||
                 strpos($_SERVER['PHP_SELF'], '/services/') !== false ||
                 strpos($_SERVER['PHP_SELF'], '/products/') !== false ||
                 strpos($_SERVER['PHP_SELF'], '/reports/') !== false ||
                 strpos($_SERVER['PHP_SELF'], '/print/') !== false ||
                 strpos($_SERVER['PHP_SELF'], '/tools/') !== false) ? '../index.php' : 'index.php';
    header("Location: " . $rootPath);
    exit();
}

function hasPermission($perm) {
    if (!isset($_SESSION['checked']) || $_SESSION['checked'] !== 1) {
        return false;
    }
    if ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ') {
        return true; // Admin has all permissions
    }
    $perms = json_decode($_SESSION['permissions'] ?? '[]', true);
    return in_array($perm, $perms);
}

function enforcePermission($perm) {
    if (!hasPermission($perm)) {
            $css_path = $is_sub ? '../assets/css/pages/session_check.css' : 'assets/css/pages/session_check.css';
            echo "<!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <title>Access Denied</title>
                <link href='https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap' rel='stylesheet'>
                <link rel='stylesheet' href='{$css_path}'>
            </head>
            <body class='denied-screen'>
                <div class='error-box'>
                    <div style='font-size: 50px; margin-bottom: 15px;'>⚠️</div>
                    <h1 class='denied-title'>ຂໍອະໄພ, ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້ານີ້!</h1>
                    <p class='denied-desc'>ບັນຊີຂອງທ່ານບໍ່ໄດ້ຮັບອະນຸຍາດໃຫ້ເຂົ້າເຖິງ ຫຼື ຈັດການສ່ວນນີ້. ກະລຸນາຕິດຕໍ່ຜູ້ບໍລິຫານລະບົບຫາກທ່ານຕ້ອງການສິດນີ້.</p>
                    <a href='javascript:history.back()' class='btn-denied-back'>ຍ້ອນກັບ (Go Back)</a>
                </div>
            </body>
            </html>";
        exit();
    }
}
