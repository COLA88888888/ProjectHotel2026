<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
        
        // Block access and render lock screen if expired
        if ($days_remaining <= 0) {
            $logout_url = $is_sub ? '../logout.php' : 'logout.php';
            $lock_title = $lang['package_lock_title'] ?? 'ລະບົບໝົດອາຍຸການໃຊ້ງານ';
            $lock_desc = $lang['package_lock_desc'] ?? 'ເພັກເກັດການນຳໃຊ້ລະບົບຂອງທ່ານໄດ້ໝົດອາຍຸລົງແລ້ວ. ກະລຸນາຕິດຕໍ່ຫາຜູ້ທີ່ໃຫ້ນຳໃຊ້ລະບົບ ຫຼື ຜູ້ພັດທະນາເພື່ອຕໍ່ອາຍຸການນຳໃຊ້.';
            $contact_title = $lang['contact_admin'] ?? 'ຊ່ອງທາງການຕິດຕໍ່ຜູ້ສະໜອງລະບົບ';
            $logout_txt = $lang['logout'] ?? 'ອອກຈາກລະບົບ';
            
            echo "<!DOCTYPE html>
            <html lang='lo'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>" . htmlspecialchars($lock_title) . "</title>
                <link href='https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;700&display=swap' rel='stylesheet'>
                <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css'>
                <style>
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: 'Noto Sans Lao Looped', sans-serif;
                        background: linear-gradient(135deg, #1e272e 0%, #0f172a 100%);
                        height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #fff;
                        overflow: hidden;
                    }
                    .expired-card {
                        max-width: 550px;
                        width: 90%;
                        background: rgba(255, 255, 255, 0.06);
                        backdrop-filter: blur(20px);
                        -webkit-backdrop-filter: blur(20px);
                        border: 1px solid rgba(255, 255, 255, 0.12);
                        border-radius: 28px;
                        padding: 45px 35px;
                        text-align: center;
                        box-shadow: 0 30px 60px rgba(0,0,0,0.6);
                        animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
                    }
                    @keyframes fadeInUp {
                        from { opacity: 0; transform: translateY(40px); }
                        to { opacity: 1; transform: translateY(0); }
                    }
                    .icon-wrapper {
                        width: 90px;
                        height: 90px;
                        background: linear-gradient(135deg, #ff3f34 0%, #d2143a 100%);
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        margin: 0 auto 25px;
                        box-shadow: 0 10px 25px rgba(255, 63, 52, 0.4);
                        border: 4px solid rgba(255, 255, 255, 0.1);
                    }
                    .icon-wrapper i {
                        font-size: 38px;
                        color: #fff;
                        animation: pulse 2s infinite;
                    }
                    @keyframes pulse {
                        0% { transform: scale(1); }
                        50% { transform: scale(1.08); }
                        100% { transform: scale(1); }
                    }
                    h1 {
                        font-size: 26px;
                        font-weight: 700;
                        margin-top: 0;
                        margin-bottom: 12px;
                        background: linear-gradient(to right, #ff7675, #ff3f34);
                        -webkit-background-clip: text;
                        -webkit-text-fill-color: transparent;
                    }
                    .pkg-badge {
                        display: inline-block;
                        background: rgba(255, 63, 52, 0.15);
                        border: 1px solid rgba(255, 63, 52, 0.3);
                        color: #ff7675;
                        font-size: 13px;
                        font-weight: 700;
                        padding: 6px 18px;
                        border-radius: 20px;
                        margin-bottom: 25px;
                    }
                    p {
                        font-size: 15px;
                        color: #cbd5e1;
                        line-height: 1.7;
                        margin-bottom: 35px;
                    }
                    .contact-box {
                        background: rgba(255, 255, 255, 0.04);
                        border: 1px solid rgba(255, 255, 255, 0.08);
                        border-radius: 16px;
                        padding: 20px;
                        margin-bottom: 30px;
                    }
                    .contact-title {
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                        color: #94a3b8;
                        margin-bottom: 8px;
                        font-weight: 700;
                    }
                    .contact-info {
                        font-size: 19px;
                        font-weight: 700;
                        color: #38bdf8;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 8px;
                    }
                    .btn-group {
                        display: flex;
                        gap: 15px;
                        justify-content: center;
                    }
                    .btn {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        gap: 8px;
                        padding: 12px 28px;
                        border-radius: 12px;
                        font-weight: 700;
                        font-size: 14px;
                        text-decoration: none;
                        transition: all 0.25s ease;
                        cursor: pointer;
                    }
                    .btn-primary {
                        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                        color: #fff;
                        box-shadow: 0 4px 15px rgba(52, 152, 219, 0.35);
                        border: none;
                    }
                    .btn-primary:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 6px 20px rgba(52, 152, 219, 0.5);
                    }
                    .btn-secondary {
                        background: rgba(255, 255, 255, 0.08);
                        color: #fff;
                        border: 1px solid rgba(255, 255, 255, 0.12);
                    }
                    .btn-secondary:hover {
                        background: rgba(255, 255, 255, 0.15);
                        transform: translateY(-2px);
                    }
                </style>
            </head>
            <body>
                <div class='expired-card'>
                    <div class='icon-wrapper'>
                        <i class='fas fa-lock'></i>
                    </div>
                    <h1>" . htmlspecialchars($lock_title) . "</h1>
                    " . (!empty($package_name) ? "
                    <div class='pkg-badge'>
                        <i class='fas fa-cube mr-1'></i> " . htmlspecialchars($package_name) . "
                    </div>" : "") . "
                    <p>" . htmlspecialchars($lock_desc) . "</p>
                    
                    <div class='contact-box'>
                        <div class='contact-title'>" . htmlspecialchars($contact_title) . "</div>
                        <div class='contact-info'>
                            <i class='fas fa-phone-alt'></i> +856 20 9999 8888
                        </div>
                    </div>
                    
                    <div class='btn-group'>
                        <a href='https://wa.me/8562099998888' target='_blank' class='btn btn-primary'>
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
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Access Denied</title>
            <link href='https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap' rel='stylesheet'>
            <style>
                body { font-family: 'Noto Sans Lao Looped', sans-serif; text-align: center; padding: 60px 20px; background-color: #f8f9fa; color: #333; margin: 0; }
                .error-box { max-width: 500px; margin: 0 auto; background: #fff; padding: 40px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-top: 5px solid #dc3545; }
                h1 { color: #dc3545; font-size: 22px; margin-top: 0; margin-bottom: 15px; font-weight: 700; }
                p { font-size: 15px; color: #666; line-height: 1.6; margin-bottom: 25px; }
                .btn { display: inline-block; padding: 10px 24px; background-color: #007bff; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; transition: all 0.2s; box-shadow: 0 4px 10px rgba(0,123,255,0.25); }
                .btn:hover { background-color: #0056b3; transform: translateY(-1px); }
            </style>
        </head>
        <body>
            <div class='error-box'>
                <div style='font-size: 50px; margin-bottom: 15px;'>⚠️</div>
                <h1>ຂໍອະໄພ, ທ່ານບໍ່ມີສິດເຂົ້າເຖິງໜ້ານີ້!</h1>
                <p>ບັນຊີຂອງທ່ານບໍ່ໄດ້ຮັບອະນຸຍາດໃຫ້ເຂົ້າເຖິງ ຫຼື ຈັດການສ່ວນນີ້. ກະລຸນາຕິດຕໍ່ຜູ້ບໍລິຫານລະບົບຫາກທ່ານຕ້ອງການສິດນີ້.</p>
                <a href='javascript:history.back()' class='btn'>ຍ້ອນກັບ (Go Back)</a>
            </div>
        </body>
        </html>";
        exit();
    }
}
