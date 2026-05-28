<?php
session_start();
require_once 'config/db.php';
require_once 'config/session_check.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}
?>
<!DOCTYPE html>
<html lang="lo">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ລະບົບບໍລິຫານ ໂຮງແຮມ</title>
    
    <!-- Google Font: Source Sans Pro & Noto Sans Lao Looped -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <!-- Ionicons (CDN as used in main menu) -->
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
    <link rel="stylesheet" href="assets/css/pages/homepage.css">
  </head>
<?php
require_once 'config/db.php';

try {
    // Hotel Metrics
    // Hotel Metrics
    $total_rooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn() ?: 0;
    
    // Rooms that are NOT available (Occupied, Cleaning, or Reserved)
    // The user wants to count BOTH Reservations (Booked) and Staying (Occupied) as Unavailable
    $unavailable_rooms = $pdo->query("
        SELECT COUNT(DISTINCT id) FROM rooms 
        WHERE status != 'Available' 
        OR (housekeeping_status != 'ພ້ອມໃຊ້ງານ' AND housekeeping_status != 'Ready')
        OR id IN (SELECT room_id FROM bookings WHERE status IN ('Booked', 'Occupied', 'Checked In'))
    ")->fetchColumn() ?: 0;
    
    $available_rooms = $total_rooms - $unavailable_rooms;
    $guest_count = $pdo->query("SELECT COALESCE(SUM(guest_count), 0) FROM bookings WHERE status IN ('Occupied', 'Checked In')")->fetchColumn() ?: 0;
    
    // Fetch Tax Percent
    $stmtTaxSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
    $tax_percent_val = (float)($stmtTaxSetting->fetchColumn() ?: 0);
    $tax_mult = 1 + ($tax_percent_val / 100);

    // Revenue calculations (Room + POS)
    // Updated to include 'Occupied', 'Completed', and 'Checked In' to ensure Walk-in revenue shows up immediately
    $room_revenue = $pdo->query("SELECT SUM((total_price + COALESCE(food_charge, 0)) * $tax_mult) FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied')")->fetchColumn() ?: 0;
    $pos_revenue = $pdo->query("SELECT SUM(amount) FROM orders")->fetchColumn() ?: 0;    // Use current date from PHP to ensure sync with MySQL
    $current_date = date('Y-m-d');
    
    // 2. Today's Revenue Breakdown (Cash vs Transfer)
    // Bookings - Deposits received today
    $stmtCashRoomDep = $pdo->prepare("SELECT SUM(deposit_amount) FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (DATE(check_in_date) = ? OR DATE(created_at) = ?) AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
    $stmtCashRoomDep->execute([$current_date, $current_date]);
    $cash_dep = $stmtCashRoomDep->fetchColumn() ?: 0;

    // Bookings - Checkout balances received today
    $stmtCashRoomOut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) FROM bookings WHERE status = 'Completed' AND DATE(check_out_date) = ? AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
    $stmtCashRoomOut->execute([$current_date]);
    $cash_out = $stmtCashRoomOut->fetchColumn() ?: 0;

    $today_cash_room = $cash_dep + $cash_out;

    // Bookings - Transfer deposits received today
    $stmtTransferRoomDep = $pdo->prepare("SELECT SUM(deposit_amount) FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (DATE(check_in_date) = ? OR DATE(created_at) = ?) AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
    $stmtTransferRoomDep->execute([$current_date, $current_date]);
    $transfer_dep = $stmtTransferRoomDep->fetchColumn() ?: 0;

    // Bookings - Transfer checkout balances received today
    $stmtTransferRoomOut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) FROM bookings WHERE status = 'Completed' AND DATE(check_out_date) = ? AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
    $stmtTransferRoomOut->execute([$current_date]);
    $transfer_out = $stmtTransferRoomOut->fetchColumn() ?: 0;

    $today_transfer_room = $transfer_dep + $transfer_out;

    // POS Orders
    $stmtCashPos = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE(o_date) = ? AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
    $stmtCashPos->execute([$current_date]);
    $today_cash_pos = $stmtCashPos->fetchColumn() ?: 0;

    $stmtTransferPos = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE(o_date) = ? AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
    $stmtTransferPos->execute([$current_date]);
    $today_transfer_pos = $stmtTransferPos->fetchColumn() ?: 0;

    $today_cash_total = $today_cash_room + $today_cash_pos;
    $today_transfer_total = $today_transfer_room + $today_transfer_pos;

    // Room Revenue Received Today = Today Cash Room + Today Transfer Room
    $today_room = $today_cash_room + $today_transfer_room;

    $stmtTP = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE(o_date) = ?");
    $stmtTP->execute([$current_date]);
    $today_pos = $stmtTP->fetchColumn() ?: 0;
    
    $today_revenue = $today_room + $today_pos;
    
    // Fetch today's arrivals (Reservations starting today)
    $stmtArrivals = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'Booked' AND DATE(check_in_date) = ?");
    $stmtArrivals->execute([$current_date]);
    $arrivals_count = $stmtArrivals->fetchColumn() ?: 0;

    // Fetch system activity history (Last 8 actions)
    $stmt_history = $pdo->query("SELECT b.customer_name, b.status, b.created_at, r.room_number 
                                 FROM bookings b 
                                 JOIN rooms r ON b.room_id = r.id 
                                 ORDER BY b.created_at DESC 
                                 LIMIT 8");
    $activity_logs = $stmt_history->fetchAll();

    // 1. Today's Bookings (Made today)
    $stmtBookToday = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = ? AND status = 'Booked'");
    $stmtBookToday->execute([$current_date]);
    $today_bookings = $stmtBookToday->fetchColumn() ?: 0;

    // 3. Monthly Revenue (Bookings + POS)
    // Monthly deposits received
    $stmtMonthRoomDep = $pdo->prepare("SELECT SUM(deposit_amount) FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND MONTH(check_in_date) = MONTH(CURDATE()) AND YEAR(check_in_date) = YEAR(CURDATE())");
    $stmtMonthRoomDep->execute();
    $month_dep = $stmtMonthRoomDep->fetchColumn() ?: 0;

    // Monthly checkout balances received
    $stmtMonthRoomOut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) FROM bookings WHERE status = 'Completed' AND MONTH(check_out_date) = MONTH(CURDATE()) AND YEAR(check_out_date) = YEAR(CURDATE())");
    $stmtMonthRoomOut->execute();
    $month_out = $stmtMonthRoomOut->fetchColumn() ?: 0;

    $monthly_revenue_room = $month_dep + $month_out;

    $stmtMonthPos = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE MONTH(o_date) = MONTH(CURDATE()) AND YEAR(o_date) = YEAR(CURDATE())");
    $stmtMonthPos->execute();
    $monthly_revenue_pos = $stmtMonthPos->fetchColumn() ?: 0;

    $monthly_revenue = $monthly_revenue_room + $monthly_revenue_pos;

    // Fetch Last 7 Days Revenue
    $days = [];
    $room_revenue_7d = [];
    $pos_revenue_7d = [];

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $date_label = date('d/m', strtotime("-$i days"));
        $days[] = $date_label;

        // Room Revenue - Accurate payment-based cash flow model including tax
        $stmtRDep = $pdo->prepare("SELECT SUM(deposit_amount) as total FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND DATE(check_in_date) = ?");
        $stmtRDep->execute([$date]);
        $r_dep = (float)($stmtRDep->fetch()['total'] ?? 0);

        $stmtROut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) as total FROM bookings WHERE status = 'Completed' AND DATE(check_out_date) = ?");
        $stmtROut->execute([$date]);
        $r_out = (float)($stmtROut->fetch()['total'] ?? 0);

        $room_revenue_7d[] = $r_dep + $r_out;

        // POS Revenue
        $stmtP = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE DATE(o_date) = ?");
        $stmtP->execute([$date]);
        $pos_revenue_7d[] = $stmtP->fetch()['total'] ?? 0;
    }
} catch (PDOException $e) {
    $total_rooms = 0;
    $available_rooms = 0;
    $booked_rooms = 0;
    $guest_count = 0;
    $total_revenue = 0;
    $today_revenue = 0;
}
?>
  <body class="hold-transition sidebar-mini layout-fixed">
    <div class="dashboard-page">

      <!-- ===== Modern Stats Cards ===== -->
      <div class="stat-cards-row">

        <!-- Card 1: Available Rooms -->
        <a href="rooms/select_rooms.php" class="stat-card gc-green">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['available_rooms_label']; ?></div>
              <div class="stat-card-value"><?= number_format($available_rooms) ?> <span style="font-size:1rem;font-weight:600;"><?php echo $lang['room_unit']; ?></span></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-door-open"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['manage_rooms']; ?>
          </div>
        </a>

        <!-- Card 2: Today's Bookings -->
        <a href="services/reserve.php?today=1" class="stat-card gc-amber">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['today_bookings_label']; ?></div>
              <div class="stat-card-value"><?= number_format($today_bookings) ?> <span style="font-size:1rem;font-weight:600;"><?php echo $lang['bill_unit']; ?></span></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['view_booking_details']; ?>
          </div>
        </a>

        <!-- Card 3: Today's Cash -->
        <a href="reports/report.php?start_date=<?php echo $current_date; ?>&end_date=<?php echo $current_date; ?>" class="stat-card gc-blue">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['today_cash_label']; ?></div>
              <div class="stat-card-value"><?= formatCurrency($today_cash_total) ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-money-bill-wave"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['total_cash_label']; ?>
          </div>
        </a>

        <!-- Card 4: Today's Transfer -->
        <a href="reports/report.php?start_date=<?php echo $current_date; ?>&end_date=<?php echo $current_date; ?>" class="stat-card gc-indigo">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['today_transfer_label']; ?></div>
              <div class="stat-card-value"><?= formatCurrency($today_transfer_total) ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-university"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['total_transfer_label']; ?>
          </div>
        </a>

        <!-- Card 5: Today Total Revenue -->
        <a href="reports/report.php?start_date=<?php echo $current_date; ?>&end_date=<?php echo $current_date; ?>" class="stat-card gc-teal">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['today_revenue_label']; ?></div>
              <div class="stat-card-value"><?= formatCurrency($today_revenue) ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-wallet"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['view_detailed_report']; ?>
          </div>
        </a>

        <!-- Card 6: Monthly Revenue -->
        <a href="reports/report.php?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="stat-card gc-dark">
          <div class="stat-card-top">
            <div>
              <div class="stat-card-label"><?php echo $lang['monthly_revenue_label']; ?></div>
              <div class="stat-card-value"><?= formatCurrency($monthly_revenue ?? 0) ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
          </div>
          <div class="stat-card-footer">
            <i class="fas fa-arrow-right"></i> <?php echo $lang['monthly_summary']; ?>
          </div>
        </a>

      </div>
      <!-- /.stat-cards-row -->

      <!-- Chart Section -->
      <div class="row mt-3">
        <!-- Line Chart -->
        <div class="col-lg-8 col-12 mb-3">
            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-top-left-radius: 12px; border-top-right-radius: 12px;">
                    <h5 class="m-0 font-weight-bold text-dark" style="font-size: 0.95rem;"><i class="fas fa-chart-line mr-2 text-primary"></i> <?php echo $lang['revenue_chart']; ?></h5>
                    <div class="d-flex align-items-center">
                        <button id="btnChartPrev" class="btn btn-sm btn-outline-secondary mr-2" title="<?php echo $lang['previous'] ?? 'ຍ້ອນຫຼັງ'; ?>"><i class="fas fa-chevron-left"></i></button>
                        <select id="chartPeriod" class="form-control form-control-sm mr-2" style="width: auto; border-radius: 8px; font-weight: 600; border: 2px solid #3498DB; color: #3498DB;">
                            <option value="daily"><?php echo $lang['daily']; ?></option>
                            <option value="weekly"><?php echo $lang['weekly']; ?></option>
                            <option value="monthly"><?php echo $lang['monthly']; ?></option>
                            <option value="yearly"><?php echo $lang['yearly']; ?></option>
                        </select>
                        <button id="btnChartNext" class="btn btn-sm btn-outline-secondary" title="<?php echo $lang['next'] ?? 'ຖັດໄປ'; ?>" disabled><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="card-body p-2 p-md-3">
                    <canvas id="lineChart" style="min-height: 200px; height: 280px; max-height: 300px; max-width: 100%;"></canvas>
                </div>
            </div>
        </div>
        <!-- Donut Chart -->
        <div class="col-lg-4 col-12 mb-3">
            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                <div class="card-header bg-white" style="border-top-left-radius: 12px; border-top-right-radius: 12px;">
                    <h5 class="m-0 font-weight-bold text-dark" style="font-size: 0.95rem;"><i class="fas fa-chart-pie mr-2 text-danger"></i> <?php echo $lang['revenue_share']; ?></h5>
                </div>
                <div class="card-body p-2 p-md-3 d-flex align-items-center justify-content-center">
                    <canvas id="donutChart" style="max-height: 280px; max-width: 100%;"></canvas>
                </div>
            </div>
        </div>
      </div>



    </div>
  </div>
    
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
    
    <!-- PAGE LEVEL SCRIPTS-->
    <script>
      $(function () {
        // Set Chart.js global font
        Chart.defaults.global.defaultFontFamily = "'Noto Sans Lao Looped', sans-serif";
        
        var lineChart = null;
        var donutChart = null;
        var currentChartOffset = 0;

        function loadChartData(period, offset) {
          $.getJSON('reports/chart_data.php', { period: period, offset: offset }, function(data) {
            var roomTotal = data.roomData.reduce(function(a,b){ return a+b; }, 0);
            var posTotal = data.posData.reduce(function(a,b){ return a+b; }, 0);

            // Destroy old charts
            if (lineChart) lineChart.destroy();
            if (donutChart) donutChart.destroy();

            // ===== ສ້າງກຣາຟເສັ້ນສະແດງລາຍຮັບ (LINE CHART - ROOM vs POS) =====
            // ໜ້າທີ່: ສະແດງແນວໂນ້ມລາຍຮັບຫ້ອງພັກ ແລະ ຍອດຂາຍ POS ແຕ່ລະວັນ ແບບແຍກສີ ແລະ ມີລະບົບແປພາສາປ້າຍຊື່/ສະກຸນເງິນ
            var lineCtx = $('#lineChart').get(0).getContext('2d');
            lineChart = new Chart(lineCtx, {
              type: 'line',
              data: {
                labels: data.labels,
                datasets: [
                  {
                    // ດຶງຊື່ລາຍຮັບຫ້ອງພັກຕາມພາສາທີ່ເລືອກ
                    label: '<?php echo $lang['revenue_room'] ?? 'ລາຍຮັບຫ້ອງພັກ'; ?>',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderColor: '#3498DB',
                    pointBorderColor: '#3498DB',
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    borderWidth: 3,
                    lineTension: 0.3,
                    fill: true,
                    data: data.roomData
                  },
                  {
                    // ດຶງຊື່ລາຍຮັບ POS ຕາມພາສາທີ່ເລືອກ
                    label: '<?php echo $lang['revenue_pos'] ?? 'ລາຍຮັບ POS'; ?>',
                    backgroundColor: 'rgba(231, 76, 60, 0.1)',
                    borderColor: '#E74C3C',
                    pointBorderColor: '#E74C3C',
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    borderWidth: 3,
                    lineTension: 0.3,
                    fill: true,
                    data: data.posData
                  }
                ]
              },
              options: {
                animation: { 
                  duration: 2000,
                  easing: 'easeOutQuart'
                },
                maintainAspectRatio: false,
                responsive: true,
                legend: { display: true, position: 'bottom', labels: { fontSize: 12 } },
                scales: {
                  xAxes: [{ gridLines: { display: false } }],
                  yAxes: [{
                    gridLines: { color: 'rgba(0,0,0,0.05)' },
                    ticks: { 
                      beginAtZero: true,
                      // ສະແດງຕົວເລກເງິນໃນແກນ Y ພ້ອມສັນຍາລັກສະກຸນເງິນທີ່ແປແລ້ວ
                      callback: function(v) { return v.toLocaleString('en-US') + ' <?php echo $lang['currency_symbol'] ?? 'ກີບ'; ?>'; } 
                    }
                  }]
                },
                tooltips: {
                  callbacks: {
                    label: function(t, d) {
                      // ຈັດຮູບແບບການສະແດງຜົນຕົວເລກເມື່ອເອົາເມົ້າໄປຊີ້ (Tooltip) ພ້ອມສະກຸນເງິນ
                      return d.datasets[t.datasetIndex].label + ': ' + Number(t.yLabel).toLocaleString('en-US') + ' <?php echo $lang['currency_symbol'] ?? 'ກີບ'; ?>';
                    }
                  }
                }
              }
            });

            // ===== ສ້າງກຣາຟວົງມົນແບ່ງສ່ວນລາຍຮັບ (DONUT CHART) =====
            // ໜ້າທີ່: ສະແດງສັດສ່ວນປຽບທຽບລາຍຮັບລະຫວ່າງ ຫ້ອງພັກ ແລະ POS ເປັນເປີເຊັນ (%) ພ້ອມແປພາສາອັດຕະໂນມັດ
            var donutCtx = $('#donutChart').get(0).getContext('2d');
            donutChart = new Chart(donutCtx, {
              type: 'doughnut',
              data: {
                // ດຶງປ້າຍຊື່ຂອງລາຍຮັບແຕ່ລະປະເພດຕາມພາສາທີ່ເລືອກ
                labels: ['<?php echo $lang['revenue_room'] ?? 'ລາຍຮັບຫ້ອງພັກ'; ?>', '<?php echo $lang['revenue_pos'] ?? 'ລາຍຮັບ POS'; ?>'],
                datasets: [{
                  data: [roomTotal, posTotal],
                  backgroundColor: ['#3498DB', '#E74C3C'],
                  hoverBackgroundColor: ['#2980B9', '#C0392B'],
                  borderWidth: 2,
                  borderColor: '#fff'
                }]
              },
              options: {
                animation: { 
                  duration: 2000,
                  easing: 'easeOutQuart'
                },
                responsive: true,
                maintainAspectRatio: true,
                cutoutPercentage: 60,
                legend: { display: true, position: 'bottom', labels: { fontSize: 12 } },
                tooltips: {
                  callbacks: {
                    label: function(t, d) {
                      // ຄຳນວນຫາເປີເຊັນ (%) ຂອງແຕ່ລະສ່ວນແບບ Realtime
                      var val = d.datasets[0].data[t.index];
                      var total = d.datasets[0].data.reduce(function(a,b){ return a+b; }, 0);
                      var pct = total > 0 ? ((val/total)*100).toFixed(1) : 0;
                      // ສົ່ງຄ່າຂໍ້ຄວາມສະແດງຜົນ Tooltip ພ້ອມສະກຸນເງິນ ແລະ ເປີເຊັນ (%)
                      return d.labels[t.index] + ': ' + Number(val).toLocaleString('en-US') + ' <?php echo $lang['currency_symbol'] ?? 'ກີບ'; ?> (' + pct + '%)';
                    }
                  }
                }
              }
            });

          });
        }

        // Initial load
        loadChartData('daily', currentChartOffset);

        // Period change
        $('#chartPeriod').on('change', function() {
          currentChartOffset = 0;
          $('#btnChartNext').prop('disabled', true);
          loadChartData($(this).val(), currentChartOffset);
        });

        $('#btnChartPrev').on('click', function() {
          currentChartOffset++;
          $('#btnChartNext').prop('disabled', false);
          loadChartData($('#chartPeriod').val(), currentChartOffset);
        });

        $('#btnChartNext').on('click', function() {
          if (currentChartOffset > 0) {
            currentChartOffset--;
            if (currentChartOffset === 0) {
              $(this).prop('disabled', true);
            }
            loadChartData($('#chartPeriod').val(), currentChartOffset);
          }
        });
      })
    </script>

  </body>
  </html>



  
