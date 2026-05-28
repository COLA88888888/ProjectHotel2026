<?php
session_start();
// If already logged in and visiting the login page, destroy the session to force a new login
if (isset($_SESSION['checked'])) {
    session_unset();
    session_destroy();
    session_start();
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
    <link rel="stylesheet" href="assets/css/pages/login.css">
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

            // Show Session Timeout warning if redirected
            <?php if (isset($_GET['timeout']) && $_GET['timeout'] == 1): ?>
            Swal.fire({
                icon: 'warning',
                title: 'ເຊດຊັນໝົດເວລາ!',
                text: 'ເນື່ອງຈາກບໍ່ມີການເຄື່ອນໄຫວເປັນເວລາດົນ, ກະລຸນາເຂົ້າສູ່ລະບົບຄືນໃໝ່ເພື່ອຄວາມປອດໄພ.',
                confirmButtonColor: '#007bff',
                confirmButtonText: 'ຕົກລົງ'
            });
            <?php endif; ?>

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
