<?php
session_start();

// Language Selection Logic
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$current_lang = $_SESSION['lang'] ?? 'la';

// Include the appropriate language file
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

$flags = [
    'la' => ['src' => 'assets/img/flags/Laos.png', 'alt' => 'Lao'],
    'en' => ['src' => 'assets/img/flags/uk.png', 'alt' => 'English'],
    'cn' => ['src' => 'assets/img/flags/China.png', 'alt' => 'Chinese'],
];
$active_flag = $flags[$current_lang] ?? $flags['la'];

require_once 'config/session_check.php';

// Fetch hotel logo & name from settings
require_once 'config/db.php';
try {
    $current_lang = $_SESSION['lang'] ?? 'la';
    $name_key = "hotel_name_" . $current_lang;
    $stmtLogo = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('hotel_logo', '$name_key', 'hotel_name', 'package_name', 'package_expires')");
    $hotel_settings = $stmtLogo->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $hotel_logo = !empty($hotel_settings['hotel_logo']) ? 'assets/img/logo/' . $hotel_settings['hotel_logo'] : 'assets/img/image.jpg';
    if (!file_exists($hotel_logo)) {
        $hotel_logo = 'assets/img/image.jpg';
    }
    $hotel_name = !empty($hotel_settings[$name_key]) ? $hotel_settings[$name_key] : ($hotel_settings['hotel_name'] ?? 'ລະບົບໂຮງແຮມ');
    
    // Calculate package days remaining
    $pkg_name = $hotel_settings['package_name'] ?? '';
    if (empty($pkg_name)) {
        $pkg_name = $lang['package_status_undefined'] ?? 'ບໍ່ທັນກຳນົດເທື່ອ';
    }
    
    $pkg_expires = $hotel_settings['package_expires'] ?? '';
    if (empty($pkg_expires) || $pkg_expires === '0000-00-00') {
        $pkg_status_text = $lang['package_status_undefined'] ?? 'ບໍ່ທັນກຳນົດເທື່ອ';
        $days_remaining = 99999;
    } else {
        $today = new DateTime(date('Y-m-d'));
        $expiry = new DateTime($pkg_expires);
        $interval = $today->diff($expiry);
        $days_remaining = (int)$interval->format('%r%a');
        if ($days_remaining < 0) {
            $days_remaining = 0;
        }
        $pkg_status_text = sprintf($lang['package_status_remaining'] ?? 'ເຫຼືອ %s ວັນ', $days_remaining);
    }
} catch (Exception $e) {
    $hotel_logo = 'assets/img/image.jpg';
    $hotel_name = 'ລະບົບໂຮງແຮມ';
    $pkg_name = $lang['package_status_undefined'] ?? 'ບໍ່ທັນກຳນົດເທື່ອ';
    $pkg_status_text = $lang['package_status_undefined'] ?? 'ບໍ່ທັນກຳນົດເທື່ອ';
    $days_remaining = 99999;
}

$session_img = !empty($_SESSION['profile_img']) ? $_SESSION['profile_img'] : 'default.png';
$nav_img_path = 'assets/img/' . $session_img;
if (!file_exists($nav_img_path)) {
    $nav_img_path = 'UserImg/default.png';
}

$perms = json_decode($_SESSION['permissions'] ?? '[]', true);
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
?>
<html>
<head>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/menu_layout.css">

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($hotel_name); ?></title>
  <link rel="shortcut icon" href="<?php echo $hotel_logo; ?>" type="image/x-icon">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- Tempusdominus Bootstrap 4 -->
  <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <!-- iCheck -->
  <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css">
	<script src="sweetalert/dist/sweetalert2.all.min.js"></script>		
	<script src="plugins/jquery/jquery.min.js"></script>
<body class="hold-transition sidebar-mini sidebar-no-expand layout-fixed">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-dark">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="Homepage.php" target="frame" class="nav-link"><b><?php echo $lang['home']; ?></b></a>
      </li>
      
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <!-- Package Info Badge (PC Only) -->
      <?php if ($_SESSION['status'] === 'GUIVIP' || $_SESSION['status'] === 'ຜູ້ບໍລິຫານ'): ?>
      <li class="nav-item d-none d-md-flex align-items-center mr-3">
          <span style="color: #ffffff; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
              <i class="fas fa-circle animate-pulse" style="font-size: 11px; color: #2ecc71;"></i>
              <span><?php echo $lang['package_usage'] ?? 'ເເພັກເກັດນຳໃຊ້'; ?>: <?php echo $pkg_status_text; ?></span>
          </span>
      </li>
      <?php endif; ?>
     
      <!-- Notifications Dropdown -->
      <li class="nav-item dropdown">
        <a class="nav-link" data-toggle="dropdown" href="#">
          <i class="far fa-bell"></i>
          <span class="badge badge-danger navbar-badge" id="notiCount">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right shadow-lg border-0" style="border-radius: 12px; overflow: hidden;">
          <span class="dropdown-item dropdown-header bg-light font-weight-bold"><?php echo $lang['notifications']; ?></span>
          <div class="dropdown-divider"></div>
          <div id="notiList" style="max-height: 300px; overflow-y: auto;">
             <a href="#" class="dropdown-item text-center py-4 text-muted">
               <i class="fas fa-bell-slash mb-2 d-block fa-2x opacity-2"></i>
               <span><?php echo $lang['no_notifications']; ?></span>
             </a>
          </div>
          <div class="dropdown-divider"></div>
          <a href="logs.php" target="frame" class="dropdown-item dropdown-footer"><?php echo $lang['view_all_logs']; ?></a>
        </div>
      </li>

      <li class="nav-item d-none d-sm-inline-block">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>  

      <!-- Usage Package -->
      <?php if ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ'): ?>
      <li class="nav-item d-none d-md-inline-block">
        <a class="nav-link" href="#" role="button" style="color: #28a745; font-weight: bold;">
          <i class="fas fa-crown"></i> ແພັກເກັດການນຳໃຊ້
        </a>
      </li>
      <?php endif; ?>

      <!-- Language Dropdown -->
      <li class="nav-item dropdown">
        <a class="nav-link" data-toggle="dropdown" href="#" id="langDropdown">
          <img src="<?php echo $active_flag['src']; ?>" alt="<?php echo $active_flag['alt']; ?>" style="width: 24px; border-radius: 2px;" id="currentLangFlag">
        </a>
        <div class="dropdown-menu dropdown-menu-right p-0">
          <a href="javascript:void(0);" class="dropdown-item lang-dropdown-item <?php echo $current_lang == 'la' ? 'active' : ''; ?>" data-lang="la">
            <img src="assets/img/flags/Laos.png" alt="Lao" class="mr-2" style="width: 20px;"> ລາວ (Lao)
          </a>
          <a href="javascript:void(0);" class="dropdown-item lang-dropdown-item <?php echo $current_lang == 'en' ? 'active' : ''; ?>" data-lang="en">
            <img src="assets/img/flags/uk.png" alt="English" class="mr-2" style="width: 20px;"> English
          </a>
          <a href="javascript:void(0);" class="dropdown-item lang-dropdown-item <?php echo $current_lang == 'cn' ? 'active' : ''; ?>" data-lang="cn">
            <img src="assets/img/flags/China.png" alt="Chinese" class="mr-2" style="width: 20px;"> 中文 (Chinese)
          </a>
        </div>
      </li>

      <li class="dropdown dropdown-user">
          <a class="nav-link dropdown-toggle link " data-toggle="dropdown">
            <img src="<?php echo $nav_img_path; ?>" height="30px" width="30px" class="rounded-circle mr-sm-2" style="object-fit: cover; border: 1px solid rgba(255,255,255,0.5);">
            <span class="d-none d-sm-inline-block"><?php echo $_SESSION['fname']; ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-right shadow-lg border-0" style="width: 220px; border-radius: 12px; margin-top: 10px;">
              <li class="dropdown-header text-center p-3 border-bottom mb-2 bg-light" style="border-top-left-radius: 12px; border-top-right-radius: 12px;">
                  <img src="<?php echo $nav_img_path; ?>" class="rounded-circle shadow-sm mb-2" style="width: 60px; height: 60px; object-fit: cover; border: 3px solid #fff;">
                  <div class="font-weight-bold text-dark"><?php echo $_SESSION['fname'] . ' ' . $_SESSION['lname']; ?></div>
                  <small class="text-primary font-weight-bold"><i class="fas fa-id-badge"></i> <?php echo $_SESSION['status']; ?></small>
              </li>
              <a class="dropdown-item py-2" href="javascript:void(0);" onclick="confirmLogout()">
                  <i class="fa fa-power-off mr-2 text-danger"></i> <?php echo $lang['logout']; ?>
              </a>
          </ul>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar elevation-4">
    <!-- Brand Logo -->
    <a href="#" class="brand-link text-center" style="padding: 14px 10px; height: auto; display: block; border-bottom: 1px solid #4b545c;">
      <img src="<?php echo $hotel_logo; ?>" alt="Hotel Logo" class="elevation-3" style="width: 80px; height: 80px; object-fit: cover; margin: 0 auto; opacity: 1; border-radius: 5px;">
      <span class="brand-text font-weight-light d-block mt-2" style="font-size: 15px;"><b><?php echo htmlspecialchars($hotel_name); ?></b></span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar user panel (optional) -->


      <!-- SidebarSearch Form -->
     

      <!-- Sidebar Menu -->
      <nav class="mt-2 pb-5">
        <ul class="nav nav-pills nav-sidebar flex-column nav-flat nav-child-indent" data-widget="treeview" role="menu" data-accordion="true">
        
          <li class="nav-header text-uppercase" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; letter-spacing: 1px;"><?php echo $lang['home']; ?></li>
          <li class="nav-item">
            <a href="Homepage.php" target="frame" class="nav-link active">
              <i class="nav-icon fas fa-chart-line"></i>
              <p><?php echo $lang['dashboard']; ?></p>
            </a>
          </li>

          <?php if($is_admin || in_array('bookings', $perms) || in_array('walkin', $perms) || in_array('checkout', $perms) || in_array('room_service', $perms)): ?>
          <li class="nav-header text-uppercase" style="color: rgba(255,255,255,0.5); font-size: 0.7rem; letter-spacing: 1.5px; padding-top: 20px;"><?php echo $lang['customer_service']; ?></li>
          <?php if($is_admin || in_array('walkin', $perms)): ?>
          <li class="nav-item">
            <a href="services/walkin.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-door-open"></i>
              <p><?php echo $lang['check_in']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('bookings', $perms)): ?>
          <li class="nav-item">
            <a href="services/reserve.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-calendar-alt"></i>
              <p><?php echo $lang['bookings']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('checkout', $perms)): ?>
          <li class="nav-item">
            <a href="services/checkout.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-receipt"></i>
              <p><?php echo $lang['check_out']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('room_service', $perms)): ?>
          <li class="nav-item">
            <a href="services/room_service.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-bell"></i>
              <p><?php echo $lang['room_service']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php endif; ?>

          <?php if($is_admin || in_array('pos', $perms) || in_array('stock', $perms)): ?>
          <li class="nav-header text-uppercase" style="color: rgba(255,255,255,0.5); font-size: 0.7rem; letter-spacing: 1.5px; padding-top: 20px;"><?php echo $lang['stock_and_sales']; ?></li>
          <?php if($is_admin || in_array('pos', $perms)): ?>
          <li class="nav-item">
            <a href="products/pos.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-cash-register"></i>
              <p><?php echo $lang['pos']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('stock', $perms)): ?>
          <li class="nav-item">
            <a href="products/stock.php" target="frame" class="nav-link">
              <i class="nav-icon fas fa-boxes"></i>
              <p><?php echo $lang['stock']; ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php endif; ?>
          
          <?php if($is_admin || in_array('report', $perms) || in_array('rooms', $perms) || in_array('settings', $perms) || in_array('users', $perms)): ?>
          <li class="nav-header text-uppercase" style="color: rgba(255,255,255,0.5); font-size: 0.7rem; letter-spacing: 1.5px; padding-top: 20px;"><?php echo $lang['management_and_reports']; ?></li>
          <?php if($is_admin || in_array('report', $perms)): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-chart-bar text-info"></i>
              <p>
                 ລາຍງານ
                 <i class="fas fa-angle-right right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="reports/report.php" target="frame" class="nav-link">
                  <i class="fas fa-file-invoice-dollar nav-icon text-danger"></i>
                  <p>ລາຍງານການເງິນ ແລະ ກຳໄລ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/report_sales.php" target="frame" class="nav-link">
                  <i class="fas fa-chart-line nav-icon text-success"></i>
                  <p>ລາຍງານການຂາຍ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/report_checkin_checkout.php" target="frame" class="nav-link">
                  <i class="fas fa-key nav-icon text-primary"></i>
                  <p>ລາຍງານການເຂົ້າພັກ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/report_room_popularity.php" target="frame" class="nav-link">
                  <i class="fas fa-door-open nav-icon text-warning"></i>
                  <p>ປະເພດຫ້ອງຈອງຫຼາຍ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/report_services.php" target="frame" class="nav-link">
                  <i class="fas fa-utensils nav-icon text-success"></i>
                  <p>ລາຍງານບໍລິການເພີ່ມເຕີມ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/expenses.php" target="frame" class="nav-link">
                  <i class="fas fa-receipt nav-icon text-info"></i>
                  <p>ຈັດການລາຍຈ່າຍ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="products/stock.php" target="frame" class="nav-link">
                  <i class="fas fa-boxes nav-icon text-light"></i>
                  <p>ລາຍງານສະຕ໋ອກ</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="reports/report_pos_history.php" target="frame" class="nav-link">
                  <i class="fas fa-shopping-cart nav-icon text-muted"></i>
                  <p>ປະຫວັດການຂາຍ POS</p>
                </a>
              </li>
            </ul>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('rooms', $perms)): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-hotel"></i>
              <p>
                 <?php echo $lang['room_settings']; ?>
				         <i class="fas fa-angle-right right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="rooms/select_rooms.php" target="frame" class="nav-link">
                  <i class="fas fa-door-open nav-icon"></i>
                  <p><?php echo $lang['room_details']; ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="room_types/form_room_types.php" target="frame" class="nav-link">
                  <i class="fas fa-tags nav-icon"></i>
                  <p><?php echo $lang['room_types']; ?></p>
                </a>
              </li>
            </ul>
          </li>
          <?php endif; ?>
          <?php if($is_admin || in_array('settings', $perms) || in_array('users', $perms)): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-cogs"></i>
              <p>
                 <?php echo $lang['settings']; ?>
                 <i class="fas fa-angle-right right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if($is_admin || in_array('settings', $perms)): ?>
              <li class="nav-item">
                <a href="settings.php" target="frame" class="nav-link">
                  <i class="fas fa-hotel nav-icon"></i>
                  <p><?php echo $lang['hotel_info']; ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="currency/form_currency.php" target="frame" class="nav-link">
                  <i class="fas fa-money-bill-wave nav-icon"></i>
                  <p><?php echo $lang['currency']; ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if($is_admin || in_array('users', $perms)): ?>
              <li class="nav-item">
                <a href="users/manage_users.php" target="frame" class="nav-link">
                  <i class="fas fa-users-cog nav-icon"></i>
                  <p><?php echo $lang['users']; ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="users/manage_permissions.php" target="frame" class="nav-link">
                  <i class="fas fa-user-shield nav-icon"></i>
                  <p>ກຳນົດສິດການໃຊ້ງານ</p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>
          <?php endif; ?>

        </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <?php 
      $iframe_src = 'Homepage.php';
    ?>
    <iframe width="100%" height="100%" frameborder="0" name="frame" src="<?php echo $iframe_src; ?>"></iframe>

       
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->
  <footer class="main-footer">
   
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 1
    </div>
  </footer>

  <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>
  $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- ChartJS -->
<script src="plugins/chart.js/Chart.min.js"></script>
<!-- Sparkline -->
<script src="plugins/sparklines/sparkline.js"></script>
<!-- JQVMap -->
<script src="plugins/jqvmap/jquery.vmap.min.js"></script>
<script src="plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
<!-- jQuery Knob Chart -->
<script src="plugins/jquery-knob/jquery.knob.min.js"></script>
<!-- daterangepicker -->
<script src="plugins/moment/moment.min.js"></script>
<script src="plugins/daterangepicker/daterangepicker.js"></script>
<!-- Tempusdominus Bootstrap 4 -->
<script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<!-- overlayScrollbars -->
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>
<!-- AdminLTE dashboard demo (This is only for demo purposes) -->
<script src="dist/js/pages/dashboard.js"></script>
<!-- Notification Sound -->
<audio id="notiSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>

<script>
  $(function() {
    // ===== Notifications Logic =====
    let lastNotiCount = 0;

    function fetchNotifications() {
      $.ajax({
        url: 'fetch_notifications.php',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
          if (data.error) return;

          $('#notiCount').text(data.count);
          
          if (data.count > 0) {
            $('#notiCount').show();
            let html = '';
            data.items.forEach(item => {
              html += `
                <a href="${item.link}" target="frame" class="dropdown-item">
                  <i class="${item.icon} mr-2 ${item.color}"></i>
                  <span class="text-sm font-weight-bold">${item.title}</span>
                  <div class="text-muted text-xs">${item.text}</div>
                </a>
                <div class="dropdown-divider"></div>
              `;
            });
            $('#notiList').html(html);

            // Play sound if count increased
            if (data.count > lastNotiCount) {
              document.getElementById('notiSound').play().catch(e => console.log("Sound play blocked"));
            }
          } else {
            $('#notiCount').hide();
            $('#notiList').html(`
              <a href="#" class="dropdown-item text-center py-4 text-muted">
                <i class="fas fa-bell-slash mb-2 d-block fa-2x opacity-2"></i>
                <span>ບໍ່ມີການແຈ້ງເຕືອນໃໝ່</span>
              </a>
            `);
          }
          lastNotiCount = data.count;
        }
      });
    }

    // Initial fetch and set interval
    fetchNotifications();
    setInterval(fetchNotifications, 30000); // Every 30 seconds

    // ===== Active Menu Highlight =====
    var $navLinks = $('.nav-sidebar .nav-link[target="frame"]');
    
    // Clear on refresh, default to first page
    sessionStorage.removeItem('activeMenu');
    $navLinks.first().addClass('active');

    // Sync active menu with iframe
    $('iframe[name="frame"]').on('load', function() {
      try {
        var iframeSrc = this.contentWindow.location.href.toLowerCase();
        $navLinks.removeClass('active');
        $('.nav-sidebar .nav-item > .nav-link').removeClass('active');
        $navLinks.each(function() {
          var href = $(this).attr('href');
          var hrefBase = href ? href.split('?')[0].toLowerCase() : '';
          
          if (hrefBase && iframeSrc.indexOf(hrefBase) !== -1) {
            $(this).addClass('active');
            var $parentLi = $(this).closest('.nav-treeview').closest('.nav-item');
            if ($parentLi.length) {
              $parentLi.addClass('menu-open menu-is-opening');
              $parentLi.children('.nav-link').addClass('active');
            }
          }
        });
      } catch(e) {}
    });

    // Click handler
    $navLinks.on('click', function() {
      var $clicked = $(this);
      $navLinks.removeClass('active');
      $('.nav-sidebar .nav-item > .nav-link').removeClass('active');
      $clicked.addClass('active');
      var $parentLi = $clicked.closest('.nav-treeview').closest('.nav-item');
      if ($parentLi.length) {
        $parentLi.children('.nav-link').addClass('active');
      }

      // Auto-close sidebar on small screens
      if ($(window).width() <= 991) {
        setTimeout(function() {
          $('body').removeClass('sidebar-open');
          $('body').addClass('sidebar-collapse sidebar-closed');
          $('#sidebar-overlay').remove();
          $('.sidebar-overlay').remove();
        }, 150);
      }
    });

    // Language dropdown
    $('.lang-select').on('click', function(e) {
      e.preventDefault();
      $('.lang-select').removeClass('active');
      $(this).addClass('active');
      var flagSrc = $(this).find('img').attr('src');
      var flagAlt = $(this).find('img').attr('alt');
      $('#currentLangFlag').attr('src', flagSrc).attr('alt', flagAlt);
    });
  });
</script>
<script>
function confirmLogout() {
    Swal.fire({
        title: '<?php echo $lang['confirm_logout']; ?>',
        text: "<?php echo $lang['logout_question']; ?>",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#007bff',
        cancelButtonColor: '#d33',
        confirmButtonText: '<?php echo $lang['ok']; ?>, <?php echo $lang['logout']; ?>',
        cancelButtonText: '<?php echo $lang['cancel']; ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'logout.php';
        }
    })
}

// Block back button after logout
window.addEventListener('pageshow', function (event) {
    if (event.persisted || (typeof window.performance != 'undefined' && window.performance.navigation.type === 2)) {
        window.location.reload();
    }
});

// Language dropdown switch
$('.lang-dropdown-item').on('click', function(e) {
  e.preventDefault();
  var lang = $(this).data('lang');
  window.location.href = '?lang=' + lang;
});

// Auto-logout due to inactivity on the front-end (15 minutes)
(function() {
    var idleTime = 0;
    var idleInterval = setInterval(timerIncrement, 60000); // Check every 1 minute

    // Reset idle timer on main page activity
    $(document).on('mousemove keypress click scroll', function() {
        idleTime = 0;
    });

    // Reset idle timer on iframe activity
    $('iframe[name="frame"]').on('load', function() {
        try {
            var iframeDoc = this.contentDocument || this.contentWindow.document;
            $(iframeDoc).on('mousemove keypress click scroll', function() {
                idleTime = 0;
            });
        } catch (e) {
            // Bypass cross-origin block if any
        }
    });

    function timerIncrement() {
        idleTime++;
        if (idleTime >= 15) { // 15 minutes
            window.location.href = 'logout.php?timeout=1';
        }
    }
})();
</script>
</body>
</html>

<!-- update !-->

