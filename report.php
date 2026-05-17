<?php
session_start();
require_once 'config/db.php';



// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

// Fetch default currency
$stmtCur = $pdo->query("SELECT symbol FROM currency WHERE is_default = 1 LIMIT 1");
$currency_symbol = $stmtCur->fetchColumn() ?: '₭';

$type = $_GET['type'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of month
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch Tax Percent
$stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
$tax_percent = (float)($stmtTax->fetchColumn() ?: 0);
$tax_mult = 1 + ($tax_percent / 100);

// Period Revenue calculation will be moved below breakdown to ensure sum consistency

// Period Breakdown (Cash vs Transfer)
$stmtCashRoom = $pdo->prepare("SELECT SUM(CASE WHEN status = 'Booked' THEN deposit_amount ELSE (total_price + food_charge) * $tax_mult END) FROM bookings WHERE (DATE(check_in_date) BETWEEN ? AND ?) AND status IN ('Completed', 'Occupied', 'Booked') AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
$stmtCashRoom->execute([$start_date, $end_date]);
$period_cash_room = $stmtCashRoom->fetchColumn() ?: 0;

$stmtTransferRoom = $pdo->prepare("SELECT SUM(CASE WHEN status = 'Booked' THEN deposit_amount ELSE (total_price + food_charge) * $tax_mult END) FROM bookings WHERE (DATE(check_in_date) BETWEEN ? AND ?) AND status IN ('Completed', 'Occupied', 'Booked') AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
$stmtTransferRoom->execute([$start_date, $end_date]);
$period_transfer_room = $stmtTransferRoom->fetchColumn() ?: 0;

$stmtCashPos = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE (DATE(o_date) BETWEEN ? AND ?) AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
$stmtCashPos->execute([$start_date, $end_date]);
$period_cash_pos = $stmtCashPos->fetchColumn() ?: 0;

$stmtTransferPos = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE (DATE(o_date) BETWEEN ? AND ?) AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
$stmtTransferPos->execute([$start_date, $end_date]);
$period_transfer_pos = $stmtTransferPos->fetchColumn() ?: 0;

// --- 1. ຄຳນວນລາຍຮັບ ແລະ ກຳໄລສຸດທິ (Revenue & Net Profit Calculations) ---
// ຄຳນວນຫາລາຍຮັບລວມຂອງເງິນສົດ (Cash) ແລະ ເງິນໂອນ (Transfer) ຂອງທັງລະບົບຫ້ອງພັກ ແລະ POS
$period_cash_total = $period_cash_room + $period_cash_pos;
$period_transfer_total = $period_transfer_room + $period_transfer_pos;

// ລາຍຮັບທັງໝົດ (Total Revenue) ແມ່ນຜົນລວມຂອງ ເງິນສົດ + ເງິນໂອນ
$period_revenue = $period_cash_total + $period_transfer_total;

// ດຶງຂໍ້ມູນລາຍຈ່າຍທັງໝົດ (Period Expenses) ໃນຊ່ວງວັນທີທີ່ເລືອກ
$stmtExp = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?");
$stmtExp->execute([$start_date, $end_date]);
$period_expenses = $stmtExp->fetchColumn() ?: 0;

// ກຳໄລສຸດທິ (Net Profit) = ລາຍຮັບທັງໝົດ - ລາຍຈ່າຍທັງໝົດ
$period_profit = $period_revenue - $period_expenses;

// --- 2. ຄຳນວນລາຍຮັບປະຈຳເດືອນ (Monthly Revenue - Bookings & POS) ---
// ດຶງລາຍຮັບຂອງການຈອງຫ້ອງພັກ (Bookings) ພາຍໃນເດືອນປັດຈຸບັນ (ຄິດໄລ່ຄ່າພາສີ Tax ຕາມທີ່ຕັ້ງຄ່າ)
$stmtMonth = $pdo->prepare("SELECT SUM((total_price + food_charge) * $tax_mult) as monthly_revenue FROM bookings WHERE status IN ('Completed', 'Occupied', 'Checked In') AND MONTH(check_in_date) = MONTH(CURDATE()) AND YEAR(check_in_date) = YEAR(CURDATE())");
$stmtMonth->execute();
$monthly_revenue_bookings = $stmtMonth->fetch()['monthly_revenue'] ?? 0;

// ດຶງລາຍຮັບຈາກການຂາຍອາຫານ/ເຄື່ອງດື່ມ (POS Orders) ພາຍໃນເດືອນປັດຈຸບັນ
$stmtPosMonth = $pdo->prepare("SELECT SUM(amount) as monthly_pos FROM orders WHERE MONTH(o_date) = MONTH(CURDATE()) AND YEAR(o_date) = YEAR(CURDATE())");
$stmtPosMonth->execute();
$monthly_pos = $stmtPosMonth->fetch()['monthly_pos'] ?? 0;

// ລາຍຮັບລວມປະຈຳເດືອນ (Monthly Revenue Total)
$monthly_revenue = $monthly_revenue_bookings + $monthly_pos;

// --- 3. ນັບຈຳນວນລູກຄ້າທີ່ເຂົ້າພັກ (Number of Customers in Period) ---
// ນັບຈຳນວນລາຍການການຈອງທີ່ມີການເຂົ້າພັກ ຫຼື ສຳເລັດແລ້ວ ໃນຊ່ວງວັນທີທີ່ກຳນົດ
$stmtCust = $pdo->prepare("
    SELECT COUNT(id) as period_customers 
    FROM bookings 
    WHERE (DATE(check_in_date) BETWEEN ? AND ?) AND status IN ('Completed', 'Occupied')
");
$stmtCust->execute([$start_date, $end_date]);
$period_customers = $stmtCust->fetch()['period_customers'] ?? 0;

// 4. Guest Count (Total people stayed in period)
$stmtGuests = $pdo->prepare("
    SELECT SUM(guest_count) as total_guests 
    FROM bookings 
    WHERE (DATE(check_in_date) BETWEEN ? AND ?) AND status IN ('Completed', 'Occupied')
");
$stmtGuests->execute([$start_date, $end_date]);
$total_guests = $stmtGuests->fetch()['total_guests'] ?? 0;

// 5. Available Rooms
$available_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Available' AND (housekeeping_status = 'ພ້ອມໃຊ້ງານ' OR housekeeping_status = 'Ready')")->fetchColumn() ?: 0;

$current_lang = $_SESSION['lang'] ?? 'la';
$room_type_col = "room_type_name_" . $current_lang;

// Fetch Monthly Data for the last 6 months for the Chart
$months = [];
$room_revenue_chart = [];
$pos_revenue_chart = [];
$expenses_chart = [];
$occupancy_chart = [];

for ($i = 5; $i >= 0; $i--) {
    $month_date = date('Y-m', strtotime("-$i months"));
    $month_label = date('m/Y', strtotime("-$i months"));
    $months[] = $month_label;

    // Room Revenue
    $stmtRC = $pdo->prepare("SELECT SUM((total_price + food_charge) * $tax_mult) as total FROM bookings WHERE DATE_FORMAT(check_in_date, '%Y-%m') = ?");
    $stmtRC->execute([$month_date]);
    $room_revenue_chart[] = $stmtRC->fetch()['total'] ?? 0;

    // POS Revenue
    $stmtPC = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE DATE_FORMAT(o_date, '%Y-%m') = ?");
    $stmtPC->execute([$month_date]);
    $pos_revenue_chart[] = $stmtPC->fetch()['total'] ?? 0;

    // Expenses (Stock)
    $stmtEC = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
    $stmtEC->execute([$month_date]);
    $expenses_chart[] = $stmtEC->fetch()['total'] ?? 0;

    // Occupancy %
    $total_rooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn() ?: 1;
    $days_in_month = date('t', strtotime($month_date . "-01"));
    $stmtOcc = $pdo->prepare("SELECT SUM(DATEDIFF(check_out_date, check_in_date)) as total_nights FROM bookings WHERE DATE_FORMAT(check_in_date, '%Y-%m') = ? AND status IN ('Completed', 'Checked In')");
    $stmtOcc->execute([$month_date]);
    $nights_sold = $stmtOcc->fetch()['total_nights'] ?? 0;
    
    $max_possible_nights = $total_rooms * $days_in_month;
    $occupancy_percent = ($nights_sold / $max_possible_nights) * 100;
    $occupancy_chart[] = round(min($occupancy_percent, 100), 1);
}

// Fetch Room Type Revenue Breakdown (Total or Last 6 Months)
$room_type_labels = [];
$room_type_revenue = [];
$stmtRT = $pdo->query("
    SELECT rt.$room_type_col as room_type, rt.room_type_name, SUM((b.total_price + b.food_charge) * $tax_mult) as total 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE b.status IN ('Completed', 'Checked In')
    GROUP BY rt.room_type_name
    ORDER BY total DESC
");
while($row = $stmtRT->fetch()) {
    $room_type_labels[] = $row['room_type'] ?: $row['room_type_name'] ?: 'Unknown';
    $room_type_revenue[] = (float)$row['total'];
}

?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['report_title']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f0f4f8; padding: 20px; }
        
        /* ===== Modern & Compact Stat Cards ===== */
        .stat-cards-row { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 20px; 
            margin-bottom: 24px; 
        }
        @media (min-width: 1200px) {
            .stat-cards-row { grid-template-columns: repeat(4, 1fr); }
        }
        @media (min-width: 1600px) {
            .stat-cards-row { grid-template-columns: repeat(5, 1fr); }
        }

        .stat-card {
            position: relative;
            border-radius: 16px;
            padding: 20px 22px;
            color: #fff;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            text-decoration: none;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 125px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .stat-card:hover {
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
        }

        .stat-card.gc-green  { background: linear-gradient(135deg, #1D976C 0%, #93F9B9 100%); }
        .stat-card.gc-amber  { background: linear-gradient(135deg, #FF8008 0%, #FFC837 100%); }
        .stat-card.gc-blue   { background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%); }
        .stat-card.gc-indigo { background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%); }
        .stat-card.gc-teal   { background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%); }
        .stat-card.gc-dark   { background: linear-gradient(135deg, #30E8BF 0%, #FF8235 100%); }

        .stat-card-label { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; opacity: 0.9; margin-bottom: 8px; }
        .stat-card-value { font-size: 1.9rem; font-weight: 800; line-height: 1.1; }
        .stat-card-icon { font-size: 2.2rem; opacity: 0.2; position: absolute; top: 15px; right: 18px; }

        /* Section Header */
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #eef2f7; }
        .section-header h2 { margin: 0; font-weight: 800; color: #2c3e50; font-size: 1.7rem; }
        .section-header form .input-group { max-width: 200px; }

        .card { border-radius: 16px !important; border: none !important; box-shadow: 0 10px 30px rgba(0,0,0,0.05) !important; }
        .card-header { background: #fff !important; border-bottom: 1px solid #f0f4f8 !important; border-radius: 16px 16px 0 0 !important; padding: 15px 20px !important; }
        .card-title { font-weight: 800 !important; color: #2c3e50 !important; font-size: 1.1rem !important; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .stat-cards-row { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card-value { font-size: 1.25rem; }
            .stat-card { min-height: 90px; padding: 12px; }
            .stat-card-label { font-size: 0.75rem; letter-spacing: 0.3px; }
            .stat-card-icon { font-size: 1.8rem; top: 12px; right: 12px; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .section-header h2 { font-size: 1.3rem; margin-bottom: 0; }
            .section-header form { width: 100%; display: flex; flex-direction: column; gap: 0; }
            .filter-wrapper { 
                flex-direction: column; 
                width: 100%; 
                gap: 12px !important; 
                background: #fff;
                padding: 15px;
                border-radius: 12px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.03);
                margin-top: 10px;
            }
            .filter-wrapper .input-group { width: 100% !important; margin: 0 !important; }
            .filter-wrapper .form-control { height: 42px !important; border-radius: 8px !important; }
            .filter-wrapper .input-group-text { border-radius: 8px 0 0 8px !important; }
            .filter-wrapper .form-control { border-radius: 0 8px 8px 0 !important; }
            .filter-wrapper button { width: 100% !important; height: 42px; margin-top: 5px; border-radius: 8px !important; font-weight: 700; }
            .container-fluid { padding: 0 10px; width: 100% !important; overflow-x: hidden; }
            .row { margin-left: -5px; margin-right: -5px; width: 100% !important; }
            .col-12, .col-lg-8, .col-lg-4 { padding-left: 5px; padding-right: 5px; width: 100% !important; }
        }
        @media (max-width: 480px) {
            .stat-cards-row { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card-value { font-size: 1.15rem; word-break: break-word; }
            .stat-card-label { font-size: 0.7rem; }
            .stat-card { min-height: 80px; padding: 12px 10px; }
            .stat-card-icon { font-size: 1.5rem; top: 10px; right: 10px; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="section-header no-print">
        <h2><i class="fas fa-file-invoice-dollar mr-2"></i> <?php echo $lang['reports']; ?></h2>
        <form method="GET" class="form-inline">
            <input type="hidden" name="type" value="<?php echo $type; ?>">
            <div class="filter-wrapper d-flex align-items-center" style="gap: 10px;">
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><i class="fas fa-calendar-alt"></i></span>
                    </div>
                    <input type="date" name="start_date" class="form-control border-left-0" value="<?php echo $start_date; ?>">
                </div>
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0">ຫາ</span>
                    </div>
                    <input type="date" name="end_date" class="form-control border-left-0" value="<?php echo $end_date; ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm"><i class="fas fa-search"></i> <span class="d-none d-md-inline"><?php echo $lang['search'] ?? 'ຄົ້ນຫາ'; ?></span></button>
            </div>
        </form>
    </div>

    <!-- Small boxes (Stat box) -->
    <?php if($type == 'all' || $type == 'room_revenue' || $type == 'finance'): ?>
    <div class="stat-cards-row">
        <!-- Period Revenue -->
        <div class="stat-card gc-blue">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['total_revenue_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($period_revenue); ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-hand-holding-usd"></i></div>
        </div>

        <!-- Cash in Period -->
        <div class="stat-card gc-green">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['total_cash_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($period_cash_total); ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-money-bill-wave"></i></div>
        </div>

        <!-- Transfer in Period -->
        <div class="stat-card gc-indigo">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['total_transfer_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($period_transfer_total); ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-university"></i></div>
        </div>
        
        <!-- Period Expenses -->
        <div class="stat-card gc-amber" style="background: linear-gradient(135deg, #e74c3c 0%, #ff9a9e 100%);">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['total_expenses_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($period_expenses); ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-shopping-cart"></i></div>
        </div>

        <!-- Period Profit -->
        <div class="stat-card gc-teal">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['net_profit_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($period_profit); ?></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
        </div>

        <!-- Total Guests -->
        <div class="stat-card gc-dark">
            <div class="stat-card-top">
                <div class="stat-card-label"><?php echo $lang['total_customers'] ?? 'ຈຳນວນລູກຄ້າ'; ?></div>
                <div class="stat-card-value"><?php echo $total_guests; ?> <sup style="font-size: 0.55em; top: -0.4em; opacity: 0.8;"><?php echo $lang['people_unit'] ?? 'ຄົນ'; ?></sup></div>
            </div>
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
        </div>

    </div>
    <?php endif; ?>

    <!-- Finance & Room Revenue Charts -->
    <div class="row mt-3" id="chartsContainer">
        <?php if($type == 'all' || $type == 'finance'): ?>
        <div class="col-lg-8 col-12 mb-3">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-chart-bar text-primary"></i> <?php echo $lang['revenue_chart_title']; ?></h3>
                </div>
                <div class="card-body">
                    <canvas id="financeChart" style="min-height: 250px; height: 350px; max-height: 350px; max-width: 100%;"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($type == 'all' || $type == 'finance' || $type == 'room_revenue'): ?>
        <div class="<?php echo ($type == 'room_revenue') ? 'col-12' : 'col-lg-4 col-12'; ?> mb-3">
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-door-open text-success"></i> <?php echo $lang['revenue_by_type']; ?></h3>
                </div>
                <div class="card-body">
                    <canvas id="roomTypeChart" style="min-height: 250px; height: 350px; max-height: 350px; max-width: 100%;"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($type == 'room_revenue'): ?>
        <!-- Additional Detail for Room Revenue Page -->
        <div class="col-12 mb-3">
            <div class="card card-info card-outline shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-chart-line text-info"></i> ແນວໂນ້ມລາຍຮັບຫ້ອງພັກ (6 ເດືອນຫຼ້າສຸດ)</h3>
                </div>
                <div class="card-body">
                    <canvas id="roomTrendChart" style="min-height: 250px; height: 350px; max-height: 350px; max-width: 100%;"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>


    </div>



</div>

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<!-- ChartJS -->
<script src="plugins/chart.js/Chart.min.js"></script>
<!-- SweetAlert2 -->
<script src="sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {


    // Chart.js Configuration
    Chart.defaults.global.defaultFontFamily = "'Noto Sans Lao Looped', sans-serif";
    
    <?php if($type == 'all' || $type == 'finance'): ?>
    var financeChartCanvas = $('#financeChart').get(0).getContext('2d');
    var financeChartData = {
      labels  : <?php echo json_encode($months); ?>,
      datasets: [
        {
          label               : '<?php echo $lang['revenue_room']; ?>',
          backgroundColor     : '#3c8dbc',
          borderColor         : '#3c8dbc',
          data                : <?php echo json_encode($room_revenue_chart); ?>
        },
        {
          label               : '<?php echo $lang['revenue_pos']; ?>',
          backgroundColor     : '#28a745',
          borderColor         : '#28a745',
          data                : <?php echo json_encode($pos_revenue_chart); ?>
        },
        {
          label               : '<?php echo $lang['expense_label']; ?>',
          backgroundColor     : '#dc3545',
          borderColor         : '#dc3545',
          data                : <?php echo json_encode($expenses_chart); ?>
        }
      ]
    }

    var financeChartOptions = {
      animation: {
          duration: 2000,
          easing: 'easeOutQuart'
      },
      hover: {
          animationDuration: 1000
      },
      responsiveAnimationDuration: 1000,
      maintainAspectRatio : false,
      responsive : true,
      legend: {
        display: true
      },
      scales: {
        xAxes: [{
          gridLines : {
            display : false,
          }
        }],
        yAxes: [{
          gridLines : {
            display : false,
          },
          ticks: {
              callback: function(value) {
                  return value.toLocaleString('en-US') + ' ' + '<?php echo $currency_symbol; ?>';
              }
          }
        }]
      },
      tooltips: {
          callbacks: {
              label: function(tooltipItem, data) {
                  return data.datasets[tooltipItem.datasetIndex].label + ': ' + Number(tooltipItem.yLabel).toLocaleString('en-US') + ' <?php echo $currency_symbol; ?>';
              }
          }
      }
    }

    new Chart(financeChartCanvas, {
      type: 'bar',
      data: financeChartData,
      options: financeChartOptions
    });
    <?php endif; ?>

    <?php if($type == 'all' || $type == 'finance' || $type == 'room_revenue'): ?>
    // Room Type Revenue Chart
    var roomTypeCanvas = $('#roomTypeChart').get(0).getContext('2d');
    var roomTypeData = {
      labels  : <?php echo json_encode($room_type_labels); ?>,
      datasets: [
        {
          data                : <?php echo json_encode($room_type_revenue); ?>,
          backgroundColor     : ['#28a745', '#007bff', '#ffc107', '#dc3545', '#17a2b8', '#6610f2'],
        }
      ]
    }
    var roomTypeOptions = {
      animation: {
          duration: 2000,
          easing: 'easeOutQuart'
      },
      maintainAspectRatio : false,
      responsive : true,
      legend: {
        display: true,
        position: 'bottom'
      },
      tooltips: {
          callbacks: {
              label: function(tooltipItem, data) {
                  var val = data.datasets[0].data[tooltipItem.index];
                  return data.labels[tooltipItem.index] + ': ' + Number(val).toLocaleString('en-US') + ' <?php echo $currency_symbol; ?>';
              }
          }
      }
    }

    new Chart(roomTypeCanvas, {
      type: 'doughnut',
      data: roomTypeData,
      options: roomTypeOptions
    });
    <?php endif; ?>

    <?php if($type == 'room_revenue'): ?>
    // Room Trend Chart (Line Chart for room_revenue page)
    var roomTrendCanvas = $('#roomTrendChart').get(0).getContext('2d');
    var roomTrendData = {
      labels  : <?php echo json_encode($months); ?>,
      datasets: [
        {
          label: 'ລາຍຮັບຫ້ອງພັກ',
          backgroundColor: 'rgba(23, 162, 184, 0.1)',
          borderColor: '#17a2b8',
          borderWidth: 3,
          data: <?php echo json_encode($room_revenue_chart); ?>,
          fill: true,
          lineTension: 0.3,
          pointRadius: 5,
          pointBackgroundColor: '#17a2b8'
        }
      ]
    }
    new Chart(roomTrendCanvas, {
      type: 'line',
      data: roomTrendData,
      options: {
        animation: { duration: 2000, easing: 'easeOutQuart' },
        maintainAspectRatio: false,
        responsive: true,
        scales: {
          yAxes: [{
            ticks: { 
              beginAtZero: true,
              callback: function(v) { return v.toLocaleString('en-US') + ' ' + '<?php echo $currency_symbol; ?>'; } 
            }
          }]
        }
      }
    });
    <?php endif; ?>



    // SweetAlert Session notifications
    <?php if(isset($_SESSION['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: '<?php echo $lang['success_label'] ?? 'ສຳເລັດ'; ?>',
        text: '<?php echo $_SESSION['success']; ?>',
        confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
    });
    <?php unset($_SESSION['success']); endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>',
        text: '<?php echo $_SESSION['error']; ?>',
        confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
    });
    <?php unset($_SESSION['error']); endif; ?>

});
</script>
</body>
</html>
