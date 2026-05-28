<?php
require_once '../config/session_check.php';
enforcePermission('report');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of month
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$room_type_col = "room_type_name_" . $current_lang;

// --- 1. Stat Cards Data ---
// A. Total Bookings in Period
$stmtTotBook = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ?");
$stmtTotBook->execute([$start_date, $end_date]);
$total_bookings = $stmtTotBook->fetchColumn() ?: 0;

// B. Total Guests Stayed in Period
$stmtTotGuests = $pdo->prepare("SELECT SUM(guest_count) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ? AND status IN ('Completed', 'Occupied', 'Checked In')");
$stmtTotGuests->execute([$start_date, $end_date]);
$total_guests = $stmtTotGuests->fetchColumn() ?: 0;

// C. Most Popular Room Type
$stmtPopType = $pdo->prepare("
    SELECT rt.{$room_type_col} as room_type, rt.room_type_name, COUNT(b.id) as cnt 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name
    ORDER BY cnt DESC
    LIMIT 1
");
$stmtPopType->execute([$start_date, $end_date]);
$pop_type_row = $stmtPopType->fetch();
$most_popular_type = $pop_type_row ? ($pop_type_row['room_type'] ?: $pop_type_row['room_type_name']) : 'N/A';

// D. Most Popular Room Number
$stmtPopRoom = $pdo->prepare("
    SELECT r.room_number, COUNT(b.id) as cnt 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY r.id
    ORDER BY cnt DESC
    LIMIT 1
");
$stmtPopRoom->execute([$start_date, $end_date]);
$pop_room_row = $stmtPopRoom->fetch();
$most_popular_room = $pop_room_row ? $pop_room_row['room_number'] : 'N/A';

// E. Today's Most Booked Room (Highlight)
$today_str = date('Y-m-d');
$stmtTodayPopRoom = $pdo->prepare("
    SELECT r.room_number, COUNT(b.id) as cnt 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    WHERE DATE(b.check_in_date) = ?
    GROUP BY r.id
    ORDER BY cnt DESC
    LIMIT 1
");
$stmtTodayPopRoom->execute([$today_str]);
$today_pop_room_row = $stmtTodayPopRoom->fetch();
$today_most_popular_room = $today_pop_room_row ? $today_pop_room_row['room_number'] : null;
$today_most_popular_room_count = $today_pop_room_row ? $today_pop_room_row['cnt'] : 0;

// F. Most Stayed Room Type (by Guest Count)
$stmtPopTypeGuests = $pdo->prepare("
    SELECT rt.{$room_type_col} as room_type, rt.room_type_name, SUM(b.guest_count) as total_guests 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN ? AND ? AND b.status IN ('Completed', 'Occupied', 'Checked In')
    GROUP BY rt.room_type_name
    ORDER BY total_guests DESC
    LIMIT 1
");
$stmtPopTypeGuests->execute([$start_date, $end_date]);
$pop_type_guests_row = $stmtPopTypeGuests->fetch();
$most_popular_type_by_guests = $pop_type_guests_row ? ($pop_type_guests_row['room_type'] ?: $pop_type_guests_row['room_type_name']) : 'N/A';
$most_popular_type_guests_count = $pop_type_guests_row ? $pop_type_guests_row['total_guests'] : 0;

// G. Total Deposit (all payment methods)
$stmtTotalDeposit = $pdo->prepare("SELECT COALESCE(SUM(deposit_amount), 0) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ? AND status IN ('Completed', 'Checked In', 'Occupied', 'Booked')");
$stmtTotalDeposit->execute([$start_date, $end_date]);
$total_deposit = $stmtTotalDeposit->fetchColumn() ?: 0;

// H. Cash Deposit
$stmtCashDeposit = $pdo->prepare("SELECT COALESCE(SUM(deposit_amount), 0) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ? AND status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (payment_method LIKE '%ເງິນສົດ%' OR payment_method LIKE '%Cash%')");
$stmtCashDeposit->execute([$start_date, $end_date]);
$total_cash_deposit = $stmtCashDeposit->fetchColumn() ?: 0;

// I. Transfer Deposit
$stmtTransferDeposit = $pdo->prepare("SELECT COALESCE(SUM(deposit_amount), 0) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ? AND status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (payment_method LIKE '%ເງິນໂອນ%' OR payment_method LIKE '%Transfer%')");
$stmtTransferDeposit->execute([$start_date, $end_date]);
$total_transfer_deposit = $stmtTransferDeposit->fetchColumn() ?: 0;


// --- 2. Chart Data (Bookings by Room Type) ---
$room_type_labels = [];
$room_type_booking_count = [];
$top_room_types_data = [];

$stmtRT = $pdo->prepare("
    SELECT rt.{$room_type_col} as room_type, rt.room_type_name, COUNT(b.id) as total 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name
    ORDER BY total DESC
");
$stmtRT->execute([$start_date, $end_date]);
while($row = $stmtRT->fetch()) {
    $room_type_labels[] = $row['room_type'] ?: $row['room_type_name'] ?: 'Unknown';
    $room_type_booking_count[] = (int)$row['total'];
    $top_room_types_data[] = [
        'room_type' => $row['room_type'] ?: $row['room_type_name'] ?: 'Unknown',
        'total' => (int)$row['total']
    ];
}


// --- 3. Detailed Report Tables Data ---
// A. Detailed stats by Room Type
$stmtRTDetails = $pdo->prepare("
    SELECT 
        rt.{$room_type_col} as room_type_lang, 
        rt.room_type_name, 
        COUNT(b.id) as booking_count,
        COALESCE(SUM(CASE WHEN b.status IN ('Completed', 'Occupied', 'Checked In') THEN b.guest_count ELSE 0 END), 0) as total_guests,
        COALESCE(SUM(CASE WHEN b.status IN ('Completed', 'Occupied', 'Checked In') THEN DATEDIFF(b.check_out_date, b.check_in_date) ELSE 0 END), 0) as total_nights,
        COALESCE(SUM(b.total_price), 0) as total_revenue
    FROM room_types rt
    LEFT JOIN rooms r ON r.room_type = rt.room_type_name
    LEFT JOIN bookings b ON b.room_id = r.id AND DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name, rt.{$room_type_col}
    ORDER BY booking_count DESC
");
$stmtRTDetails->execute([$start_date, $end_date]);
$rt_details = $stmtRTDetails->fetchAll();

// B. Detailed stats by Individual Room
$stmtRoomDetails = $pdo->prepare("
    SELECT 
        r.room_number,
        rt.{$room_type_col} as room_type_lang,
        rt.room_type_name,
        COUNT(b.id) as booking_count,
        COALESCE(SUM(CASE WHEN b.status IN ('Completed', 'Occupied', 'Checked In') THEN b.guest_count ELSE 0 END), 0) as total_guests,
        COALESCE(SUM(CASE WHEN b.status IN ('Completed', 'Occupied', 'Checked In') THEN DATEDIFF(b.check_out_date, b.check_in_date) ELSE 0 END), 0) as total_nights,
        COALESCE(SUM(b.total_price), 0) as total_revenue
    FROM rooms r
    LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
    LEFT JOIN bookings b ON b.room_id = r.id AND DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY r.id, r.room_number, rt.room_type_name, rt.{$room_type_col}
    ORDER BY booking_count DESC
");
$stmtRoomDetails->execute([$start_date, $end_date]);
$room_details = $stmtRoomDetails->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ປະເພດຫ້ອງຈອງຫຼາຍ</title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;700&family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="css/report_room_popularity.css">
</head>
<body>

<div class="container-fluid">
    <!-- Header -->
    <div class="section-header no-print">
        <h2><i class="fas fa-chart-pie text-primary mr-2"></i> ຈັດການປະເພດຈອງ ແລະ ຫ້ອງພັກຍອດນິຍົມ</h2>
        
        <!-- Search Filter Form -->
        <form method="GET" class="form-inline">
            <div class="filter-wrapper d-flex align-items-center" style="gap: 10px;">
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><i class="fas fa-calendar-alt text-success"></i></span>
                    </div>
                    <input type="date" name="start_date" class="form-control border-left-0" value="<?php echo $start_date; ?>">
                </div>
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0">ຫາ</span>
                    </div>
                    <input type="date" name="end_date" class="form-control border-left-0" value="<?php echo $end_date; ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold">
                    <i class="fas fa-search mr-1"></i> ຄົ້ນຫາ
                </button>
            </div>
        </form>
    </div>

    <!-- Deposits Widgets Row -->
    <div class="row mb-4 no-print">
        <div class="col-md-4 mb-2">
            <div class="deposit-widget">
                <div>
                    <div class="deposit-title">ມັດຈຳລວມ (Total Deposit)</div>
                    <div class="deposit-val"><?php echo number_format($total_deposit); ?> ₭</div>
                </div>
                <i class="fas fa-wallet text-primary fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="deposit-widget cash">
                <div>
                    <div class="deposit-title">ມັດຈຳເງິນສົດ (Cash Deposit)</div>
                    <div class="deposit-val text-success"><?php echo number_format($total_cash_deposit); ?> ₭</div>
                </div>
                <i class="fas fa-money-bill-wave text-success fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="deposit-widget transfer">
                <div>
                    <div class="deposit-title">ມັດຈຳເງິນໂອນ (Transfer Deposit)</div>
                    <div class="deposit-val text-warning"><?php echo number_format($total_transfer_deposit); ?> ₭</div>
                </div>
                <i class="fas fa-university text-warning fa-2x opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Premium Stat Cards Grid -->
    <div class="stat-cards-row mb-4 no-print">
        <!-- 1. Total Bookings -->
        <div class="stat-card-premium blue">
            <div>
                <div class="stat-card-label">ຈຳນວນການຈອງທັງໝົດ</div>
                <div class="stat-card-value"><?php echo number_format($total_bookings); ?> <span style="font-size: 1rem; font-weight: 500;">ຄັ້ງ</span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-calendar-check mr-1"></i> ໃນໄລຍະເວລາທີ່ເລືອກ</div>
            <i class="fas fa-book-open stat-card-icon"></i>
        </div>

        <!-- 2. Total Guests -->
        <div class="stat-card-premium green">
            <div>
                <div class="stat-card-label">ຈຳນວນແຂກເຂົ້າພັກ</div>
                <div class="stat-card-value"><?php echo number_format($total_guests); ?> <span style="font-size: 1rem; font-weight: 500;">ຄົນ</span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-user-friends mr-1"></i> ສະເພາະຜູ້ເຂົ້າພັກແລ້ວ</div>
            <i class="fas fa-users stat-card-icon"></i>
        </div>

        <!-- 3. Most Popular Room Type -->
        <div class="stat-card-premium orange">
            <div>
                <div class="stat-card-label">ປະເພດຫ້ອງຍອດນິຍົມ</div>
                <div class="stat-card-value" style="font-size: 1.35rem; font-weight: 700; word-break: break-word;"><?php echo htmlspecialchars($most_popular_type); ?></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-star mr-1"></i> ຈອງຫຼາຍທີ່ສຸດ</div>
            <i class="fas fa-door-open stat-card-icon"></i>
        </div>

        <!-- 4. Most Booked Room Number -->
        <div class="stat-card-premium purple">
            <div>
                <div class="stat-card-label">ເບີຫ້ອງຍອດນິຍົມ</div>
                <div class="stat-card-value">ຫ້ອງ <?php echo htmlspecialchars($most_popular_room); ?></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-award mr-1"></i> ຫ້ອງທີ່ມີການເຂົ້າພັກຫຼາຍສຸດ</div>
            <i class="fas fa-key stat-card-icon"></i>
        </div>
    </div>

    <!-- Core Interactive Analytics Section -->
    <div class="row mb-4 no-print">
        <!-- Doughnut Chart Column -->
        <div class="col-lg-5 col-12 mb-3">
            <div class="card glass-card h-100">
                <div class="card-header bg-transparent border-0 d-flex align-items-center">
                    <h3 class="card-title m-0 font-weight-bold"><i class="fas fa-chart-pie text-danger mr-2"></i> ອັດຕາສ່ວນການຈອງແຕ່ລະປະເພດຫ້ອງ</h3>
                </div>
                <div class="card-body d-flex flex-column justify-content-center align-items-center p-3">
                    <?php if (count($room_type_booking_count) > 0): ?>
                        <div style="position: relative; height:280px; width:100%;">
                            <canvas id="roomTypeDoughnutChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="py-5 text-center text-muted">
                            <i class="fas fa-chart-pie fa-3x mb-3 opacity-30"></i>
                            <p class="m-0">ບໍ່ມີຂໍ້ມູນການຈອງໃນໄລຍະເວລານີ້</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Hot Highlights Column -->
        <div class="col-lg-7 col-12 mb-3">
            <div class="highlight-card d-flex flex-column justify-content-between">
                <div>
                    <h4 class="font-weight-bold mb-3"><i class="fas fa-bolt text-warning mr-2"></i> ສະຫຼຸບຈຸດເດັ່ນຍອດນິຍົມ</h4>
                    <hr style="border-top: 1px solid rgba(255,255,255,0.15);">
                    
                    <div class="row mt-4">
                        <div class="col-6 mb-3 border-right" style="border-color: rgba(255,255,255,0.1) !important;">
                            <span class="small text-white-50 d-block uppercase font-weight-bold">ປະເພດຫ້ອງທີ່ມີແຂກພັກຫຼາຍສຸດ</span>
                            <span class="h5 font-weight-bold d-block mt-1 text-warning"><?php echo htmlspecialchars($most_popular_type_by_guests); ?></span>
                            <span class="small text-white-50"><i class="fas fa-users mr-1"></i> ພັກລວມ: <strong><?php echo number_format($most_popular_type_guests_count); ?></strong> ຄົນ</span>
                        </div>
                        <div class="col-6 mb-3">
                            <span class="small text-white-50 d-block uppercase font-weight-bold">ຫ້ອງຮ້ອນແຮງປະຈຳວັນນີ້ (Today's Highlight)</span>
                            <?php if ($today_most_popular_room): ?>
                                <span class="h5 font-weight-bold d-block mt-1 text-success">ຫ້ອງ <?php echo htmlspecialchars($today_most_popular_room); ?></span>
                                <span class="small text-white-50"><i class="fas fa-calendar-day mr-1"></i> ຈອງວັນນີ້: <strong><?php echo $today_most_popular_room_count; ?></strong> ຄັ້ງ</span>
                            <?php else: ?>
                                <span class="h6 font-weight-bold d-block mt-1 text-white-50">ບໍ່ມີການຈອງໃໝ່ວັນນີ້</span>
                                <span class="small text-white-50"><i class="fas fa-calendar-day mr-1"></i> ບໍ່ພົບຂໍ້ມູນ</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white-5 p-3 rounded" style="background: rgba(255,255,255,0.06); border-radius: 12px;">
                    <p class="m-0 small text-white-50">
                        <i class="fas fa-info-circle text-info mr-1"></i>
                        ຂໍ້ມູນ Highlights ເຫຼົ່ານີ້ຖືກວິເຄາະແບບ Real-time ຈາກຖານຂໍ້ມູນການຈອງຫ້ອງພັກທັງໝົດຂອງໂຮງແຮມໃນລະບົບ.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Top 10 Popular Room Types Bar Chart Card -->
    <div class="card glass-card mb-4 no-print">
        <div class="card-header bg-transparent border-0 py-3">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-chart-bar text-primary mr-2"></i> ກາຟແທ່ງ ຈັດອັນດັບປະເພດຫ້ອງທີ່ຖືກຈອງຫຼາຍທີ່ສຸດ
            </h5>
        </div>
        <div class="card-body p-2">
            <div style="position: relative; height: 220px; max-width: 650px; margin: 0 auto;">
                <canvas id="topRoomTypesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Double Table Details Card -->
    <div class="card glass-card mb-5">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between py-3" style="gap: 15px;">
            <!-- Tabs Controls -->
            <ul class="nav nav-pills nav-pills-custom no-print" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="room-type-tab" data-toggle="pill" href="#room-type-pane" role="tab" aria-controls="room-type-pane" aria-selected="true">
                        <i class="fas fa-chart-bar mr-1"></i> 📊 ຈັດຕາມປະເພດຫ້ອງ
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="room-number-tab" data-toggle="pill" href="#room-number-pane" role="tab" aria-controls="room-number-pane" aria-selected="false">
                        <i class="fas fa-key mr-1"></i> 🔑 ຈັດຕາມລາຍຫ້ອງພັກ
                    </a>
                </li>
            </ul>
            
            <!-- Export Buttons -->
            <div class="d-flex align-items-center no-print ml-auto" style="gap: 10px;">
                <button type="button" class="btn btn-export btn-export-pdf" id="btnPdf">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </button>
            </div>
        </div>

        <div class="card-body p-2 p-md-4">
            <div class="tab-content" id="reportTabsContent">
                
                <!-- Tab 1: By Room Type -->
                <div class="tab-pane fade show active" id="room-type-pane" role="tabpanel" aria-labelledby="room-type-tab">
                    <div class="table-responsive">
                        <table id="roomTypeTable" class="table table-premium text-center" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>ປະເພດຫ້ອງ (Room Type)</th>
                                    <th>ຈຳນວນການຈອງ (Bookings)</th>
                                    <th>ຈຳນວນແແຂກພັກ (Guests)</th>
                                    <th>ຈຳນວນຄືນພັກ (Nights)</th>
                                    <th>ລາຍຮັບລວມ (Revenue)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (count($rt_details) > 0):
                                    foreach($rt_details as $row): 
                                        $type_name = htmlspecialchars($row['room_type_lang'] ?: $row['room_type_name'] ?: 'Unknown');
                                ?>
                                        <tr>
                                            <td class="text-left font-weight-bold text-dark">
                                                <i class="fas fa-door-closed text-primary mr-2"></i><?php echo $type_name; ?>
                                            </td>
                                            <td><span class="badge badge-pill badge-primary px-3 py-2"><?php echo number_format($row['booking_count']); ?> ຄັ້ງ</span></td>
                                            <td><strong><?php echo number_format($row['total_guests']); ?></strong> ຄົນ</td>
                                            <td><strong><?php echo number_format($row['total_nights']); ?></strong> ຄືນ</td>
                                            <td class="text-right font-weight-bold text-success" style="font-size: 1.05rem;"><?php echo number_format($row['total_revenue']); ?> ₭</td>
                                        </tr>
                                <?php 
                                    endforeach;
                                else: 
                                ?>
                                    <tr>
                                        <td colspan="5" class="py-4 text-muted">ບໍ່ມີຂໍ້ມູນ</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab 2: By Room Number -->
                <div class="tab-pane fade" id="room-number-pane" role="tabpanel" aria-labelledby="room-number-tab">
                    <div class="table-responsive">
                        <table id="roomNumberTable" class="table table-premium text-center" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>ເບີຫ້ອງ (Room Number)</th>
                                    <th>ປະເພດຫ້ອງ (Room Type)</th>
                                    <th>ຈຳນວນການຈອງ (Bookings)</th>
                                    <th>ຈຳນວນແຂກພັກ (Guests)</th>
                                    <th>ຈຳນວນຄືນພັກ (Nights)</th>
                                    <th>ລາຍຮັບລວມ (Revenue)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (count($room_details) > 0):
                                    foreach($room_details as $row): 
                                        $type_name = htmlspecialchars($row['room_type_lang'] ?: $row['room_type_name'] ?: 'Unknown');
                                ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark" style="font-size: 1.05rem;">
                                                <span class="badge badge-info px-3 py-2"><i class="fas fa-key mr-1"></i> ຫ້ອງ <?php echo htmlspecialchars($row['room_number']); ?></span>
                                            </td>
                                            <td class="text-left text-muted"><?php echo $type_name; ?></td>
                                            <td><span class="badge badge-pill badge-primary px-3 py-2"><?php echo number_format($row['booking_count']); ?> ຄັ້ງ</span></td>
                                            <td><strong><?php echo number_format($row['total_guests']); ?></strong> ຄົນ</td>
                                            <td><strong><?php echo number_format($row['total_nights']); ?></strong> ຄືນ</td>
                                            <td class="text-right font-weight-bold text-success" style="font-size: 1.05rem;"><?php echo number_format($row['total_revenue']); ?> ₭</td>
                                        </tr>
                                <?php 
                                    endforeach;
                                else: 
                                ?>
                                    <tr>
                                        <td colspan="6" class="py-4 text-muted">ບໍ່ມີຂໍ້ມູນ</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<!-- DataTables -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<!-- ChartJS -->
<script src="../plugins/chart.js/Chart.min.js"></script>
<!-- HTML2PDF CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // ----------------------------------------------------
    // 1. ChartJS Default Font
    // ----------------------------------------------------
    Chart.defaults.global.defaultFontFamily = "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif";
    
    // ----------------------------------------------------
    // 2. ChartJS Doughnut Initialization
    // ----------------------------------------------------
    <?php if (count($room_type_booking_count) > 0): ?>
    var ctxDoughnut = document.getElementById('roomTypeDoughnutChart').getContext('2d');
    var roomTypeDoughnut = new Chart(ctxDoughnut, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($room_type_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($room_type_booking_count); ?>,
                backgroundColor: [
                    '#3a7bd5', // Blue
                    '#11998e', // Emerald
                    '#f857a6', // Dark Pink/Magenta
                    '#6441a5', // Royal Purple
                    '#fd7e14', // Vibrant Amber/Orange
                    '#17a2b8', // Teal
                    '#dc3545', // Crimson Red
                    '#6c757d'  // Charcoal
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 15,
                    padding: 15,
                    fontStyle: 'bold',
                    fontSize: 11
                }
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var dataset = data.datasets[tooltipItem.datasetIndex];
                        var total = dataset.data.reduce(function(previousValue, currentValue, currentIndex, array) {
                            return previousValue + currentValue;
                        }, 0);
                        var currentValue = dataset.data[tooltipItem.index];
                        var percentage = Math.floor(((currentValue/total) * 100)+0.5);         
                        return data.labels[tooltipItem.index] + ": " + currentValue.toLocaleString() + " ຄັ້ງ (" + percentage + "%)";
                    }
                }
            },
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            }
        }
    });
    <?php endif; ?>

    // ----------------------------------------------------
    // 3. ChartJS Bar Chart Initialization (Ranking)
    // ----------------------------------------------------
    const topRoomTypesData = <?php echo json_encode($top_room_types_data); ?>;
    
    if (topRoomTypesData.length > 0) {
        const barLabels = topRoomTypesData.map(rt => rt.room_type);
        const barValues = topRoomTypesData.map(rt => rt.total);
        
        const ctxBar = document.getElementById('topRoomTypesChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{
                    label: 'ຈຳນວນການຈອງ (ຄັ້ງ)',
                    data: barValues,
                    backgroundColor: [
                        'rgba(58, 123, 213, 0.85)',
                        'rgba(17, 153, 142, 0.85)',
                        'rgba(248, 87, 166, 0.85)',
                        'rgba(100, 65, 165, 0.85)',
                        'rgba(253, 126, 20, 0.85)',
                        'rgba(23, 162, 184, 0.85)',
                        'rgba(220, 53, 69, 0.85)',
                        'rgba(108, 117, 125, 0.85)'
                    ],
                    borderColor: [
                        '#3a7bd5',
                        '#11998e',
                        '#f857a6',
                        '#6441a5',
                        '#fd7e14',
                        '#17a2b8',
                        '#dc3545',
                        '#6c757d'
                    ],
                    borderWidth: 1.5,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    display: false
                },
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            fontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 11
                        },
                        gridLines: {
                            color: 'rgba(226, 232, 240, 0.6)'
                        }
                    }],
                    xAxes: [{
                        ticks: {
                            fontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 10
                        },
                        gridLines: {
                            display: false
                        }
                    }]
                },
                tooltips: {
                    titleFontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                    bodyFontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleFontSize: 12,
                    bodyFontSize: 12,
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(tooltipItem) {
                            return 'ຈຳນວນ: ' + Number(tooltipItem.yLabel).toLocaleString('en-US') + ' ຄັ້ງ';
                        }
                    }
                }
            }
        });
    }

    // ----------------------------------------------------
    // 4. DataTables Configuration (Lao Localized)
    // ----------------------------------------------------
    var rtTable = $('#roomTypeTable').DataTable({
        "language": datatableLaoLang,
        "order": [[1, "desc"]], // Sort by Booking count by default
        "pageLength": 10,
        "lengthMenu": [5, 10, 25, 50]
    });

    var rnTable = $('#roomNumberTable').DataTable({
        "language": datatableLaoLang,
        "order": [[2, "desc"]], // Sort by Booking count by default
        "pageLength": 10,
        "lengthMenu": [5, 10, 25, 50]
    });

    // Fix responsive rendering of DataTables inside Bootstrap tabs
    $('a[data-toggle="pill"]').on('shown.bs.tab', function(e) {
        $($.fn.dataTable.tables(true)).DataTable().columns.adjust().responsive.recalc();
    });

    // ----------------------------------------------------
    // 5. Original PDF Export Click Handler (html2pdf)
    // ----------------------------------------------------
    $('#btnPdf').click(function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> ກຳລັງສ້າງ PDF...');

        var activeTabId = $('#reportTabs .active').attr('id');
        var reportTitle = "";
        var targetTableId = "";

        if (activeTabId === 'room-type-tab') {
            reportTitle = "ລາຍງານປະເພດຫ້ອງຈອງຫຼາຍ";
            targetTableId = "#roomTypeTable";
        } else {
            reportTitle = "ລາຍງານຫ້ອງພັກຍອດນິຍົມ";
            targetTableId = "#roomNumberTable";
        }

        var dateRange = "<?php echo date('d/m/Y', strtotime($start_date)); ?> ຫາ <?php echo date('d/m/Y', strtotime($end_date)); ?>";

        var theadHtml = '<thead><tr style="background-color:#007bff;color:#fff;">';
        var headers = $(targetTableId + ' thead th');
        headers.each(function() {
            theadHtml += '<th style="border:1px solid #dee2e6;padding:8px 6px;text-align:center;">' + $(this).text().trim() + '</th>';
        });
        theadHtml += '</tr></thead>';

        var tbodyHtml = '<tbody>';
        var rows = $(targetTableId + ' tbody tr');
        rows.each(function() {
            tbodyHtml += '<tr>';
            var cells = $(this).find('td');
            cells.each(function(i) {
                var align = (i === headers.length - 1) ? 'right' : 'center';
                tbodyHtml += '<td style="border:1px solid #dee2e6;padding:7px 6px;text-align:' + align + ';color:#333;vertical-align:middle;">' + $(this).text().trim() + '</td>';
            });
            tbodyHtml += '</tr>';
        });
        tbodyHtml += '</tbody>';

        var tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:11px;font-family:\'Noto Sans Lao Looped\',\'Noto Sans Lao\',sans-serif;">' + theadHtml + tbodyHtml + '</table>';

        var fullHtml = '<div style="padding:20px;background:#fff;font-family:\'Noto Sans Lao Looped\',\'Noto Sans Lao\',sans-serif;">'
            + '<div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #333;padding-bottom:10px;">'
            + '<h2 style="margin:0;font-size:18px;font-weight:bold;color:#333;">' + reportTitle + '</h2>'
            + '<p style="margin:6px 0 0 0;font-size:12px;color:#555;">ໄລຍະເວລາ: <strong>' + dateRange + '</strong></p>'
            + '</div>'
            + tableHtml
            + '</div>';

        var opt = {
            margin: 8,
            filename: reportTitle + '_<?php echo $start_date; ?>_ຫາ_<?php echo $end_date; ?>.pdf',
            image: { type: 'jpeg', quality: 0.97 },
            html2canvas: { scale: 2, useCORS: true, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };

        html2pdf().set(opt).from(fullHtml).save().then(function() {
            btn.prop('disabled', false).html(originalHtml);
        }).catch(function(err) {
            btn.prop('disabled', false).html(originalHtml);
            console.error('PDF Error:', err);
        });
    });

});
</script>
</body>
</html>
