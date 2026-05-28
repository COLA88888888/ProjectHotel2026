<?php
require_once '../config/session_check.php';
enforcePermission('bookings');
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
if (!$is_admin && !hasPermission('bookings_edit')) {
    $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຢືນຢັນການເຂົ້າພັກ!";
    header("Location: reserve.php");
    exit();
}
require_once '../config/db.php';
require_once '../config/logger.php';

// --- 1. ສ່ວນໂຫຼດໄຟລ໌ພາສາ ແລະ ຕັ້ງຄ່າ Session (Check-in Language Loader) ---
// ກວດສອບ ແລະ ດຶງພາສາປັດຈຸບັນຈາກ Session, ຫາກບໍ່ມີໃຫ້ເລືອກພາສາລາວ 'la'
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// 2. ກຳນົດຊື່ຄໍລຳແປພາສາຂອງປະເພດຫ້ອງ (Room Type Column Name Map)
// ເພື່ອກວດສອບ ແລະ ດຶງຄໍລຳແປພາສາທີ່ຖືກຕ້ອງຈາກຖານຂໍ້ມູນ ເຊັ່ນ: room_type_name_la, room_type_name_en, room_type_name_cn
$rt_name_col = "room_type_name_" . $current_lang;

// 3. ກວດສອບລະຫັດການຈອງ (Validate Booking ID from URL)
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($booking_id <= 0) {
    $_SESSION['error'] = $lang['booking_not_found'] ?? "ບໍ່ພົບຂໍ້ມູນການຈອງ!";
    header("Location: reserve.php");
    exit();
}

// Get booking info
$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, r.room_type, r.bed_type, r.price, 
           rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE b.id = ? AND b.status = 'Booked'
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['error'] = $lang['booking_already_checkin_or_invalid'] ?? "ການຈອງນີ້ບໍ່ມີ ຫຼື ໄດ້ເຂົ້າພັກແລ້ວ!";
    header("Location: reserve.php");
    exit();
}

// Handle confirm check-in
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_checkin'])) {
    // Update booking status to Occupied
    $bill_number = $booking['bill_number'];
    if (empty($bill_number)) {
        // Generate Bill Number: YYYYMMDDXXX (e.g. 20260518001)
        $today_str = date('Ymd');
        $stmtLast = $pdo->prepare("SELECT bill_number FROM bookings WHERE bill_number LIKE ? AND bill_number REGEXP '^[0-9]+$' ORDER BY bill_number DESC LIMIT 1");
        $stmtLast->execute([$today_str . '%']);
        $lastBill = $stmtLast->fetchColumn();

        if ($lastBill) {
            $lastNum = (int)substr($lastBill, 8);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }
        $bill_number = $today_str . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }

    $pdo->prepare("UPDATE bookings SET status = 'Occupied', check_in_date = CURDATE(), bill_number = ? WHERE id = ?")->execute([$bill_number, $booking_id]);
    // Update room status to Occupied
    $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?")->execute([$booking['room_id']]);
    
    logActivity($pdo, "Check-in (ຈອງ)", "ເຂົ້າພັກຫ້ອງ " . $booking['room_number'] . " ຂອງລູກຄ້າ " . $booking['customer_name']);
    
    $_SESSION['success'] = ($lang['checkin_success'] ?? "ເຂົ້າພັກສຳເລັດ!") . " " . $lang['room'] . " " . $booking['room_number'];
    header("Location: reserve.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['confirm_checkin_title'] ?? 'ຢືນຢັນເຂົ້າພັກ'; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/css/pages/checkin_reserved.css">
</head>
<body>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8 col-12">
            <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
                <div class="card-header bg-success text-white text-center py-3">
                    <h4 class="m-0"><i class="fas fa-sign-in-alt"></i> <?php echo $lang['confirm_checkin_title'] ?? 'ຢືນຢັນການເຂົ້າພັກ'; ?></h4>
                    <small><?php echo $lang['from_booking_label'] ?? 'ຈາກການຈອງລ່ວງໜ້າ'; ?></small>
                </div>
                <div class="card-body text-center">
                    <div class="display-3 text-success mb-3"><i class="fas fa-door-open"></i></div>
                    <h3 class="mb-1"><?php echo $lang['room']; ?> <?php echo htmlspecialchars($booking['room_number']); ?></h3>
                    <p class="text-muted">
                        <?php 
                            $r_type = $booking[$rt_name_col] ?: $booking['room_type'];
                            $b_type_val = $booking['bed_type'];
                            if ($b_type_val == 'ຕຽງດ່ຽວ' || strtolower($b_type_val) == 'single' || strtolower($b_type_val) == 'single bed') {
                                $b_type = $lang['single_bed'] ?? 'Single Bed';
                            } elseif ($b_type_val == 'ຕຽງຄູ່' || strtolower($b_type_val) == 'double' || strtolower($b_type_val) == 'double bed') {
                                $b_type = $lang['double_bed'] ?? 'Double Bed';
                            } else {
                                $b_type = $b_type_val;
                            }
                            echo htmlspecialchars($r_type) . " (" . htmlspecialchars($b_type) . ")";
                        ?>
                    </p>
                    
                    <hr>
                    
                    <div class="row text-left px-3">
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-user text-primary"></i> <?php echo $lang['customer_name'] ?? $lang['customer'] ?? 'ຊື່ລູກຄ້າ'; ?>:</span>
                            <span class="info-value ml-1"><?php echo htmlspecialchars($booking['customer_name']); ?></span>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-phone text-primary"></i> <?php echo $lang['phone'] ?? 'ເບີໂທ'; ?>:</span>
                            <span class="info-value ml-1"><?php echo htmlspecialchars($booking['customer_phone']); ?></span>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-calendar-check text-success"></i> <?php echo $lang['checkin_date'] ?? 'ວັນເຂົ້າ'; ?>:</span>
                            <span class="info-value text-success ml-1"><?php echo date('d/m/Y', strtotime($booking['check_in_date'])); ?></span>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-calendar-times text-danger"></i> <?php echo $lang['checkout_date'] ?? 'ວັນອອກ'; ?>:</span>
                            <span class="info-value text-danger ml-1"><?php echo date('d/m/Y', strtotime($booking['check_out_date'])); ?></span>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-users text-info"></i> <?php echo $lang['guests'] ?? 'ຈຳນວນແຂກ'; ?>:</span>
                            <span class="info-value ml-1"><?php echo $booking['guest_count']; ?> <?php echo $lang['people_unit'] ?? 'ຄົນ'; ?></span>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="info-label"><i class="fas fa-money-bill text-warning"></i> <?php echo $lang['deposit'] ?? 'ມັດຈຳ'; ?>:</span>
                            <span class="info-value text-info ml-1"><?php echo number_format($booking['deposit_amount']); ?> <?php echo $lang['currency_symbol'] ?? '₭'; ?></span>
                        </div>
                    </div>
                    
                    <div class="alert alert-success mt-3 py-2">
                        <strong><i class="fas fa-coins"></i> <?php echo $lang['total'] ?? 'ຍອດລວມ'; ?>: <?php echo number_format($booking['total_price']); ?> <?php echo $lang['currency_symbol'] ?? '₭'; ?></strong>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <form action="" method="post" class="d-flex gap-2">
                        <a href="reserve.php" class="btn btn-default flex-fill mr-2"><i class="fas fa-arrow-left"></i> <?php echo $lang['cancel'] ?? 'ກັບຄືນ'; ?></a>
                        <button type="submit" name="confirm_checkin" class="btn btn-success flex-fill px-4">
                            <i class="fas fa-check-circle"></i> Check-in
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
</body>
</html>
