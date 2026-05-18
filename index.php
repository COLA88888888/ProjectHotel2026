<?php
session_start();
if (isset($_SESSION['checked'])) {
    if (isset($_SESSION['status']) && $_SESSION['status'] == "ຜູ້ບໍລິຫານ") {
        header("Location: menu_admin.php");
    } else {
        header("Location: menu_user.php");
    }
    exit();
}
require_once 'config/db.php';

// Language Selection Logic
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

try {
    $stmtLogo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'hotel_logo'");
    $logo_val = $stmtLogo->fetchColumn();
    
    $stmtName = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'hotel_name'");
    $hotel_name = $stmtName->fetchColumn() ?? 'ລະບົບໂຮງແຮມ';
    
    $hotel_logo = !empty($logo_val) ? 'assets/img/logo/' . $logo_val : 'assets/img/image.jpg';
    if (!file_exists($hotel_logo)) {
        $hotel_logo = 'assets/img/image.jpg';
    }
} catch (Exception $e) { 
    $hotel_logo = 'assets/img/image.jpg';
    $hotel_name = 'ລະບົບໂຮງແຮມ';
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hotel_name); ?></title>
    <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">

    <link rel="shortcut icon" href="<?php echo $hotel_logo; ?>" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        .fas, .far, .fab, .fa { font-family: "Font Awesome 5 Free" !important; font-weight: 900 !important; }
        :root {
            --primary-blue: #007bff;
            --blue-hover: #0069d9;
        }
        body {
            font-family: 'Noto Sans Lao Looped', sans-serif !important;
            margin: 0;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }
        .hero-bg {
            background: linear-gradient(rgba(0, 123, 255, 0.7), rgba(0, 123, 255, 0.7)), url('assets/img/hotel_pool.jpg');
            background-size: cover;
            background-position: center;
            height: 40vh;
            width: 100%;
            position: absolute;
            top: 0;
            left: 0;
            clip-path: polygon(0 0, 100% 0, 100% 80%, 0 100%);
            z-index: 1;
        }
        .login-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 10;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 35px 40px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            margin-top: 5vh;
        }
        .lang-switcher {
            position: absolute;
            top: 25px;
            right: 25px;
            display: flex;
            gap: 12px;
            z-index: 2000;
            background: rgba(255, 255, 255, 0.9);
            padding: 8px 15px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.5);
        }
        .lang-item {
            text-decoration: none !important;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            opacity: 0.6;
            padding: 2px;
        }
        .lang-item:hover, .lang-item.active {
            opacity: 1;
            transform: scale(1.1);
        }
        .lang-flag {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #ddd;
        }
        .lang-text {
            font-size: 0.8rem;
            font-weight: 700;
            color: #444;
        }
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .hotel-logo-wrapper {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            background: #fff;
            border-radius: 50%;
            padding: 5px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .hotel-logo-wrapper img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .login-header h2 {
            color: var(--primary-blue);
            font-weight: 700;
            font-size: 1.6rem;
            margin-bottom: 5px;
        }
        .login-header p {
            color: #888;
            font-size: 0.9rem;
        }
        .form-label {
            font-weight: 500;
            font-size: 0.95rem;
            margin-bottom: 8px;
            color: #444;
        }
        .form-control {
            border-radius: 10px;
            padding: 14px 15px;
            border: 1px solid #dee2e6;
            font-size: 1.1rem;
            width: 100%;
            display: block;
            background-color: #f0f7ff;
            transition: all 0.3s;
            box-sizing: border-box;
            height: 48px;
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 10px 15px;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
        }
        .password-container {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
        }
        .btn-login {
            background-color: var(--primary-blue);
            color: #fff;
            height: 48px;
            border-radius: 8px;
            font-weight: 700;
            width: 100%;
            margin-top: 20px;
            transition: all 0.3s;
            border: none;
        }
        .btn-login:hover {
            background-color: var(--blue-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
        }
        
        @media (max-width: 576px) {
            .lang-switcher {
                top: 15px;
                right: 15px;
                padding: 5px 10px;
                gap: 8px;
            }
            .lang-text {
                display: none; /* Hide text on mobile to save space */
            }
            .lang-flag {
                width: 26px;
                height: 26px;
            }
            .login-card { 
                padding: 30px 25px; 
                width: 92%; 
                max-width: 340px; 
                margin: 0 auto; 
                border-radius: 20px;
            }
            .hotel-logo-wrapper {
                width: 65px;
                height: 65px;
            }
            .login-header h2 { font-size: 1.4rem; }
            .login-header p { font-size: 0.85rem; }
            .form-control { height: 45px; font-size: 1rem; }
            .btn-login { height: 45px; font-size: 1rem; }
            .hero-bg { height: 35vh; }
        }
        .remember-me {
            margin-top: 15px;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: #666;
        }
        .remember-me input {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-blue);
        }
        .footer-info {
            position: relative;
            padding: 30px 0 50px; /* Increased bottom padding to move text up */
            width: 100%;
            text-align: center;
            color: #999;
            font-size: 0.85rem;
            z-index: 1;
        }
    </style>
</head>
<body>

    <div class="lang-switcher">
        <a href="?lang=la" class="lang-item <?php echo $current_lang == 'la' ? 'active' : ''; ?>">
            <img src="assets/img/flags/Laos.png" class="lang-flag">
            <span class="lang-text">Lao</span>
        </a>
        <a href="?lang=en" class="lang-item <?php echo $current_lang == 'en' ? 'active' : ''; ?>">
            <img src="assets/img/flags/uk.png" class="lang-flag">
            <span class="lang-text">English</span>
        </a>
        <a href="?lang=cn" class="lang-item <?php echo $current_lang == 'cn' ? 'active' : ''; ?>">
            <img src="assets/img/flags/China.png" class="lang-flag">
            <span class="lang-text">Chinese</span>
        </a>
    </div>

    <div class="hero-bg"></div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <div class="hotel-logo-wrapper">
                    <img src="<?php echo $hotel_logo; ?>" alt="Logo">
                </div>
                <h2><?php echo $lang['welcome_back']; ?></h2>
                <p><?php echo $lang['login_subtitle']; ?></p>
            </div>

            <form id="loginForm">
                <div class="form-group mb-3">
                    <label class="form-label"><?php echo $lang['username']; ?>:</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #888;">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" id="username" class="form-control" placeholder="<?php echo $lang['username_placeholder']; ?>" required autofocus style="padding-left: 45px;">
                    </div>
                </div>
                
                <div class="form-group mb-3">
                    <label class="form-label"><?php echo $lang['password']; ?>:</label>
                    <div class="password-container">
                        <span style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #888; z-index: 5;">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" id="password" class="form-control" placeholder="<?php echo $lang['password_placeholder']; ?>" required style="padding-left: 45px; padding-right: 45px;">
                        <span class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>
<!-- 
                <div class="remember-me">
                    <input type="checkbox" id="remember">
                    <label for="remember" class="mb-0">ຈື່ຂ້ອຍໄວ້ໃນລະບົບ</label>
                </div> -->
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="fas fa-user-check mr-2"></i> <?php echo $lang['login_btn']; ?>
                </button>
            </form>
        </div>
    </div>

    <div class="footer-info">
        <?php echo $lang['login_subtitle']; ?> V 1.0.0<br>
        <?php echo $lang['developed_by']; ?>: SoneDev
    </div>

    <!-- Use CDN for maximum reliability -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="sweetalert/dist/sweetalert2.all.min.js"></script>

    <script>
        $(document).ready(function() {
            console.log("Login page ready"); // Debug log

            // Toggle Password
            $('#togglePassword').on('click', function() {
                const passwordField = $('#password');
                const type = passwordField.attr('type') === 'password' ? 'text' : 'password';
                passwordField.attr('type', type);
                $('#eyeIcon').toggleClass('fa-eye fa-eye-slash');
            });

            $('#loginForm').on('submit', function(e) {
                e.preventDefault();
                
                var username = $('#username').val();
                var password = $('#password').val();
                var btn = $('#btnLogin');

                if (!username || !password) {
                    Swal.fire({ icon: 'warning', title: '<?php echo $lang['login_required_msg']; ?>' });
                    return;
                }

                btn.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin"></i> <?php echo $lang['searching'] ?? 'ກຳລັງກວດສອບ...'; ?>');

                $.ajax({
                    url: 'Check_user.php',
                    type: 'POST',
                    data: { username: username, password: password },
                    dataType: 'json',
                    success: function(response) {
                        console.log("Login Response:", response); // Debug log
                        if (response.success) {
                            window.location.href = response.redirect;
                        } else {
                            btn.prop('disabled', false).text('<?php echo $lang['login_btn']; ?>');
                            Swal.fire({
                                icon: 'error',
                                title: '<?php echo $lang['error_label']; ?>',
                                text: response.message,
                                confirmButtonColor: '#007bff'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error:", status, error); // Debug log
                        btn.prop('disabled', false).text('<?php echo $lang['login_btn']; ?>');
                        Swal.fire({
                            icon: 'error',
                            title: '<?php echo $lang['error_label']; ?>',
                            text: '<?php echo $lang['cannot_connect_server'] ?? "ບໍ່ສາມາດເຊື່ອມຕໍ່ກັບ Server ໄດ້!"; ?> (Error: ' + error + ')'
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>
