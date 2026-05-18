<?php
require_once '../config/session_check.php';
enforcePermission('bookings');
require_once '../config/db.php';
require_once '../config/logger.php';

// --- 1. ສ່ວນໂຫຼດໄຟລ໌ພາສາ ແລະ ຕັ້ງຄ່າ Session (Language File Loader) ---
// ກວດສອບ ແລະ ດຶງພາສາປັດຈຸບັນຈາກ Session, ຫາກບໍ່ມີໃຫ້ໃຊ້ພາສາລາວ 'la' ເປັນຫຼັກ
$language = $_SESSION['lang'] ?? 'la'; 
$lang_file = "../lang/{$language}.php";
if (file_exists($lang_file)) {
    include_once $lang_file;
} else {
    include_once "../lang/la.php";
}

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('bookings_edit'));
$can_delete = ($is_admin || hasPermission('bookings_delete'));

// 2. ກຳນົດຊື່ຄໍລຳແປພາສາຂອງປະເພດຫ້ອງ (Room Type Column Name Map)
// ເພື່ອກວດສອບ ແລະ ດຶງຄໍລຳແປພາສາທີ່ຖືກຕ້ອງຈາກຖານຂໍ້ມູນ ເຊັ່ນ: room_type_name_la, room_type_name_en, room_type_name_cn
$rt_name_col = "room_type_name_" . $language;

// 3. ດຶງຂໍ້ມູນປະເພດຫ້ອງທັງໝົດ (Fetch Room Types)
// ສົ່ງຄຳສັ່ງ SQL ໄປດຶງລາຍການປະເພດຫ້ອງທັງໝົດມາສະແດງຜົນໃນ Select Option
$stmtTypes = $pdo->query("SELECT * FROM room_types ORDER BY id DESC");
$room_types = $stmtTypes->fetchAll();

// Handle cancel reservation
if (isset($_GET['cancel_booking'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຍົກເລີກການຈອງ!";
        header("Location: reserve.php");
        exit();
    }
    $bookingId = (int)$_GET['cancel_booking'];
    $roomId = (int)$_GET['room_id'];
    
    // Fetch customer and room details before deleting
    $stmtFetch = $pdo->prepare("SELECT b.customer_name, r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
    $stmtFetch->execute([$bookingId]);
    $bk = $stmtFetch->fetch();
    $cust = $bk['customer_name'] ?? '';
    $roomNum = $bk['room_number'] ?? '';

    $pdo->prepare("DELETE FROM bookings WHERE id = ? AND status = 'Booked'")->execute([$bookingId]);
    $pdo->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?")->execute([$roomId]);

    logActivity($pdo, "ຍົກເລີກການຈອງ", "ຍົກເລີກການຈອງຫ້ອງ $roomNum ຂອງລູກຄ້າ $cust");

    $_SESSION['success'] = $lang['booking_cancel_success'] ?? "ຍົກເລີກການຈອງສຳເລັດ!";
    header("Location: reserve.php");
    exit();
}

$available_rooms = [];
$check_in_search = $_POST['check_in_search'] ?? date('Y-m-d');
$check_out_search = $_POST['check_out_search'] ?? date('Y-m-d', strtotime('+1 day'));
$selected_type = $_POST['room_type'] ?? 'all';

// Calculate nights for display and pricing
$d1 = new DateTime($check_in_search);
$d2 = new DateTime($check_out_search);
$nights_count = $d1->diff($d2)->days;
if($nights_count < 1) $nights_count = 1;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $query = "
        SELECT r.*, rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
        FROM rooms r 
        LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
        WHERE (r.housekeeping_status = 'ພ້ອມໃຊ້ງານ' OR r.housekeeping_status = 'Ready')
        AND r.status != 'Maintenance'
        AND r.id NOT IN (
            SELECT room_id FROM bookings 
            WHERE status IN ('Booked', 'Occupied', 'Checked In') 
            AND check_in_date < ? 
            AND check_out_date > ?
        )
    ";
    
    if ($check_in_search == date('Y-m-d')) {
        $query .= " AND r.status NOT IN ('Occupied', 'Checked In')";
    }
    
    if ($selected_type !== 'all') {
        $query .= " AND r.room_type = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$check_out_search, $check_in_search, $selected_type]);
    } else {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$check_out_search, $check_in_search]);
    }
    $available_rooms = $stmt->fetchAll();

    if (count($available_rooms) == 0) {
        $_SESSION['info_msg'] = $lang['full_room_msg'] ?? "ຂໍອະໄພ, ຫ້ອງພັກທຸກຫ້ອງແມ່ນເຕັມໝົດແລ້ວໃນຊ່ວງວັນທີທີ່ທ່ານເລືອກ!";
    }
}

// Handle Update Reservation (Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_reserve'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂການຈອງ!";
        header("Location: reserve.php");
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $guest_count = (int)$_POST['guest_count'];
    $check_in = $_POST['check_in_date'];
    $check_out = $_POST['check_out_date'];
    $deposit = (float)str_replace(',', '', $_POST['deposit_amount']);
    
    $stmtRoomId = $pdo->prepare("SELECT room_id FROM bookings WHERE id = ?");
    $stmtRoomId->execute([$booking_id]);
    $current_room_id = $stmtRoomId->fetchColumn();

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND status IN ('Booked', 'Occupied', 'Checked In') 
        AND id != ?
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmtCheck->execute([$current_room_id, $booking_id, $check_out, $check_in]);
    $is_occupied = $stmtCheck->fetchColumn() > 0;

    if ($is_occupied) {
        $_SESSION['error'] = $lang['error_label'] ?? "ຂໍອະໄພ, ວັນທີທີ່ທ່ານປ່ຽນໃໝ່ມີຄົນຈອງຫ້ອງນີ້ແລ້ວ!";
        header("Location: reserve.php");
        exit();
    }

    $stmtPrice = $pdo->prepare("SELECT r.price FROM rooms r JOIN bookings b ON r.id = b.room_id WHERE b.id = ?");
    $stmtPrice->execute([$booking_id]);
    $room_price = $stmtPrice->fetchColumn() ?: 0;
    
    $d1 = new DateTime($check_in);
    $d2 = new DateTime($check_out);
    $nights = $d1->diff($d2)->days;
    if($nights < 1) $nights = 1;
    $total_price = $room_price * $nights;

    // Fetch old details before update to compare what was edited
    $stmtOld = $pdo->prepare("SELECT b.*, r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
    $stmtOld->execute([$booking_id]);
    $old = $stmtOld->fetch();

    $stmt = $pdo->prepare("UPDATE bookings SET customer_name = ?, customer_phone = ?, guest_count = ?, check_in_date = ?, check_out_date = ?, total_price = ?, deposit_amount = ?, payment_method = ? WHERE id = ?");
    if ($stmt->execute([$customer_name, $customer_phone, $guest_count, $check_in, $check_out, $total_price, $deposit, $_POST['payment_method'], $booking_id])) {
        $changes = [];
        if ($old['customer_name'] !== $customer_name) {
            $changes[] = "ຊື່ລູກຄ້າ: '{$old['customer_name']}' -> '{$customer_name}'";
        }
        if ($old['customer_phone'] !== $customer_phone) {
            $changes[] = "ເບີໂທ: '{$old['customer_phone']}' -> '{$customer_phone}'";
        }
        if ((int)$old['guest_count'] !== $guest_count) {
            $changes[] = "ແຂກ: '{$old['guest_count']}' -> '{$guest_count}'";
        }
        if ($old['check_in_date'] !== $check_in) {
            $changes[] = "ວັນທີເຂົ້າ: '{$old['check_in_date']}' -> '{$check_in}'";
        }
        if ($old['check_out_date'] !== $check_out) {
            $changes[] = "ວັນທີອອກ: '{$old['check_out_date']}' -> '{$check_out}'";
        }
        if ((float)$old['deposit_amount'] !== $deposit) {
            $changes[] = "ມັດຈຳ: '" . number_format($old['deposit_amount']) . "' -> '" . number_format($deposit) . "'";
        }
        if ($old['payment_method'] !== $_POST['payment_method']) {
            $changes[] = "ຊ່ອງທາງຊຳລະ: '{$old['payment_method']}' -> '{$_POST['payment_method']}'";
        }

        $roomNum = $old['room_number'] ?? '';
        $details = "ແກ້ໄຂການຈອງຫ້ອງ $roomNum";
        if (!empty($changes)) {
            $details .= " (" . implode(', ', $changes) . ")";
        } else {
            $details .= " (ບໍ່ມີການປ່ຽນແປງຂໍ້ມູນ)";
        }

        logActivity($pdo, "ແກ້ໄຂການຈອງ", $details);

        $_SESSION['success'] = $lang['booking_edit_success'] ?? "ແກ້ໄຂການຈອງສຳເລັດ!";
    } else {
        $_SESSION['error'] = $lang['error_label'] ?? "ບໍ່ສາມາດແກ້ໄຂຂໍ້ມູນໄດ້!";
    }
    header("Location: reserve.php");
    exit();
}

// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reserve'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຈອງຫ້ອງພັກ!";
        header("Location: reserve.php");
        exit();
    }
    $room_id = (int)$_POST['room_id'];
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $passport_number = trim($_POST['passport_number']);
    $address = trim($_POST['address']);
    $guest_count = (int)$_POST['guest_count'];
    $check_in_date = $_POST['check_in_date'];
    $nights_res = (int)$_POST['nights_count'];
    $check_out_date = date('Y-m-d', strtotime($check_in_date . " +$nights_res days"));
    $deposit_amount = (float)str_replace(',', '', $_POST['deposit_amount']);

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND status IN ('Booked', 'Occupied', 'Checked In') 
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmtCheck->execute([$room_id, $check_out_date, $check_in_date]);
    $is_occupied = $stmtCheck->fetchColumn() > 0;

    if (!$is_occupied) {
        $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmtRoom->execute([$room_id]);
        $room = $stmtRoom->fetch();

        if ($room) {
            $total_price = $room['price'] * $nights_res;
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

            $stmt = $pdo->prepare("INSERT INTO bookings (room_id, customer_name, customer_phone, passport_number, address, guest_count, check_in_date, check_out_date, total_price, deposit_amount, payment_method, status, bill_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Booked', ?)");
            
            if ($stmt->execute([$room_id, $customer_name, $customer_phone, $passport_number, $address, $guest_count, $check_in_date, $check_out_date, $total_price, $deposit_amount, $_POST['payment_method'], $bill_number])) {
                // Update room status to Booked in rooms table
                $pdo->prepare("UPDATE rooms SET status = 'Booked' WHERE id = ?")->execute([$room_id]);

                // Fetch room number
                $stmtRoomNum = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
                $stmtRoomNum->execute([$room_id]);
                $roomNum = $stmtRoomNum->fetchColumn() ?: '';

                logActivity($pdo, "ຈອງຫ້ອງພັກ", "ຈອງຫ້ອງ $roomNum ໃຫ້ລູກຄ້າ $customer_name, ວັນທີ $check_in_date ຫາ $check_out_date");

                $_SESSION['success'] = $lang['booking_success'] ?? "ຈອງຫ້ອງສຳເລັດ!";
                header("Location: reserve.php");
                exit();
            }
        }
    } else {
        $_SESSION['error'] = $lang['error_label'] ?? "ຂໍອະໄພ, ຫ້ອງນີ້ມີຄົນຈອງໃນຊ່ວງວັນທີນີ້ແລ້ວ!";
    }
    header("Location: reserve.php");
    exit();
}

$today_filter = isset($_GET['today']) && $_GET['today'] == 1;
$where_clause = "WHERE b.status = 'Booked'";
if ($today_filter) {
    $where_clause .= " AND DATE(b.created_at) = CURDATE()";
}

$stmtReserved = $pdo->prepare("
    SELECT b.*, r.room_number, r.room_type,
           rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn,
    (SELECT COUNT(*) FROM bookings b2 
     WHERE ((b2.customer_phone = b.customer_phone AND b.customer_phone != '' AND b.customer_phone != '-') 
            OR (b2.customer_name = b.customer_name AND b2.customer_phone = b.customer_phone))
     AND b2.status IN ('Booked', 'Occupied') 
     AND b2.id != b.id
     AND b2.check_in_date < b.check_out_date 
     AND b2.check_out_date > b.check_in_date) as other_bookings
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
    $where_clause
    ORDER BY b.check_in_date ASC
");
$stmtReserved->execute();
$reservations = $stmtReserved->fetchAll();
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['booking_title']; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .dataTables_filter { display: none; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 10px; }
        .room-card { transition: transform 0.2s; border-radius: 10px; }
        .room-card:hover { transform: scale(1.02); cursor: pointer; border-color: #f39c12; }
        .room-price { font-size: 1.1rem; font-weight: 600; color: #f39c12; }
        .btn-action-group { 
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }
        .btn-action-group .btn { 
            background: transparent !important;
            border: none !important;
            padding: 0;
            width: auto;
            height: auto;
            box-shadow: none !important;
            font-size: 1.1rem;
            transition: opacity 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1;
        }
        .btn-action-group .btn span {
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 2px;
            white-space: nowrap;
        }
        .btn-action-group .btn:hover { opacity: 0.7; }
        .btn-action-group .btn-success { color: #28a745 !important; }
        .btn-action-group .btn-primary { color: #007bff !important; }
        .btn-action-group .btn-info { color: #17a2b8 !important; }
        .btn-action-group .btn-danger { color: #dc3545 !important; }
        @media (max-width: 768px) {
            body { padding: 5px; }
            h2 { font-size: 1.1rem !important; }
            h3 { font-size: 1rem !important; }
            .room-card .display-4 { font-size: 1.5rem; }
            .room-card h4 { font-size: 0.9rem; }
            .table { font-size: 0.75rem !important; }
            .table th, .table td { padding: 6px 4px !important; }
            .card-header { padding: 8px 12px !important; }
            .card-title { font-size: 0.9rem !important; }
            .btn-sm { padding: 0.2rem 0.4rem; font-size: 0.75rem; }
            .form-control-sm, .input-group-sm > .form-control { font-size: 0.8rem; }
            .stat-card-value { font-size: 1.2rem; }
        }
    </style>
    <script>
        if (window.top === window.self) { window.location.href = '../menu_admin.php'; }
    </script>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: '<?php echo $lang['success_label']; ?>', text: '<?php echo $_SESSION['success']; ?>', showConfirmButton: false, timer: 2500 });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: '<?php echo $lang['error_label']; ?>', text: '<?php echo $_SESSION['error']; ?>', confirmButtonText: '<?php echo $lang['ok']; ?>' });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <?php if(isset($_SESSION['info_msg'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'info', title: '<?php echo $lang['info_label']; ?>', text: '<?php echo $_SESSION['info_msg']; ?>', confirmButtonText: '<?php echo $lang['ok']; ?>' });
            });
        </script>
    <?php unset($_SESSION['info_msg']); endif; ?>

    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="fas fa-calendar-check text-warning"></i> <?php echo $lang['booking_title']; ?></h2>
        </div>
    </div>

    <!-- Search Form -->
    <div class="card card-warning card-outline shadow-sm">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-search"></i> <?php echo $lang['search_rooms_title']; ?></h3></div>
        <form action="" method="post">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 col-6">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt text-success"></i> <?php echo $lang['checkin_date']; ?></label>
                            <input type="date" name="check_in_search" id="search_checkin" class="form-control" value="<?php echo $check_in_search; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="form-group">
                            <label><i class="fas fa-moon text-warning"></i> <?php echo $lang['nights_count']; ?></label>
                            <input type="number" id="search_nights" class="form-control" value="<?php echo $nights_count; ?>" min="1">
                        </div>
                    </div>
                    <div class="col-md-3 col-12">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check text-danger"></i> <?php echo $lang['checkout_date']; ?></label>
                            <input type="date" name="check_out_search" id="search_checkout" class="form-control" value="<?php echo $check_out_search; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2 col-12">
                        <div class="form-group">
                            <label><?php echo $lang['room_type']; ?></label>
                            <select name="room_type" class="form-control">
                                <option value="all">-- <?php echo $lang['all']; ?> --</option>
                                <?php foreach($room_types as $rt): 
                                    $r_type_mapped = $rt[$rt_name_col] ?: $rt['room_type_name'];
                                    if ($r_type_mapped == 'Standard' || strtolower($r_type_mapped) == 'standard') {
                                        $r_type_mapped = $lang['room_type_standard'] ?? 'Standard';
                                    } elseif ($r_type_mapped == 'VIP' || strtolower($r_type_mapped) == 'vip') {
                                        $r_type_mapped = $lang['room_type_vip'] ?? 'VIP';
                                    } elseif ($r_type_mapped == 'ຫ້ອງຕຽງດ່ຽວ' || strtolower($r_type_mapped) == 'single bed room' || strtolower($r_type_mapped) == 'single room') {
                                        $r_type_mapped = $lang['room_type_single'] ?? 'Single Bed Room';
                                    } elseif ($r_type_mapped == 'ຫ້ອງຕຽງຄູ່' || strtolower($r_type_mapped) == 'double bed room' || strtolower($r_type_mapped) == 'double room') {
                                        $r_type_mapped = $lang['room_type_double'] ?? 'Double Bed Room';
                                    } elseif ($r_type_mapped == 'ຫ້ອງຄອບຄົວ' || strtolower($r_type_mapped) == 'family room') {
                                        $r_type_mapped = $lang['room_type_family'] ?? 'Family Room';
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($rt['room_type_name']); ?>" <?php echo ($selected_type == $rt['room_type_name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($r_type_mapped); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <div class="form-group w-100">
                            <button type="submit" name="search" class="btn btn-warning btn-block text-white">
                                <i class="fas fa-search"></i> <span class="d-none d-md-inline"><?php echo $lang['search']; ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Available Rooms Results -->
    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])): ?>
    <div class="card card-outline card-success shadow-sm">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-door-open"></i> <?php echo $lang['available_rooms']; ?> (<?php echo count($available_rooms); ?> <?php echo $lang['room_unit']; ?>)</h3></div>
        <div class="card-body bg-light">
            <?php if (count($available_rooms) > 0): ?>
                <div class="row">
                    <?php foreach($available_rooms as $room): ?>
                        <div class="col-lg-3 col-md-4 col-6 mb-3">
                            <div class="card room-card shadow-sm border-warning">
                                <div class="card-body text-center p-3">
                                    <div class="display-4 text-warning mb-2"><i class="fas fa-door-closed"></i></div>
                                    <h4 class="font-weight-bold"><?php echo $lang['room']; ?> <?php echo htmlspecialchars($room['room_number']); ?></h4>
                                    <p class="text-muted mb-1 small">
                                        <?php 
                                            $r_type_mapped = $room[$rt_name_col] ?: $room['room_type'];
                                            if ($r_type_mapped == 'Standard' || strtolower($r_type_mapped) == 'standard') {
                                                $r_type_mapped = $lang['room_type_standard'] ?? 'Standard';
                                            } elseif ($r_type_mapped == 'VIP' || strtolower($r_type_mapped) == 'vip') {
                                                $r_type_mapped = $lang['room_type_vip'] ?? 'VIP';
                                            } elseif ($r_type_mapped == 'ຫ້ອງຕຽງດ່ຽວ' || strtolower($r_type_mapped) == 'single bed room' || strtolower($r_type_mapped) == 'single room') {
                                                $r_type_mapped = $lang['room_type_single'] ?? 'Single Bed Room';
                                            } elseif ($r_type_mapped == 'ຫ້ອງຕຽງຄູ່' || strtolower($r_type_mapped) == 'double bed room' || strtolower($r_type_mapped) == 'double room') {
                                                $r_type_mapped = $lang['room_type_double'] ?? 'Double Bed Room';
                                            } elseif ($r_type_mapped == 'ຫ້ອງຄອບຄົວ' || strtolower($r_type_mapped) == 'family room') {
                                                $r_type_mapped = $lang['room_type_family'] ?? 'Family Room';
                                            }
                                            $r_type = $r_type_mapped;

                                            $b_type_val = $room['bed_type'];
                                            if ($b_type_val == 'ຕຽງດ່ຽວ' || strtolower($b_type_val) == 'single' || strtolower($b_type_val) == 'single bed') {
                                                $b_type = $lang['single_bed'] ?? 'Single Bed';
                                            } elseif ($b_type_val == 'ຕຽງຄູ່' || strtolower($b_type_val) == 'double' || strtolower($b_type_val) == 'double bed') {
                                                $b_type = $lang['double_bed'] ?? 'Double Bed';
                                            } else {
                                                $b_type = $b_type_val;
                                            }
                                            echo htmlspecialchars($r_type);
                                            if (strpos(strtoupper($r_type), 'VIP') !== false || (strpos($r_type, 'ຕຽງ') === false && strpos(strtolower($r_type), 'bed') === false)) {
                                                echo " (" . htmlspecialchars($b_type) . ")";
                                            }
                                        ?>
                                    </p>
                                    <hr class="my-2">
                                    <p class="room-price mb-0 text-orange font-weight-bold" style="font-size: 1.2rem;"><?php echo number_format($room['price']); ?> <?php echo $lang['per_night']; ?></p>
                                    <div class="mt-3 p-2 border-success rounded shadow-sm" style="background-color: #e8f5e9; border: 1px dashed #28a745;">
                                        <div class="text-success small font-weight-bold mb-1"><i class="fas fa-check-circle"></i> <?php echo $lang['available']; ?>:</div>
                                        <div class="h6 mb-0 font-weight-bold text-dark">
                                            <?php echo date('d/m/Y', strtotime($check_in_search)); ?> 
                                            <span class="text-muted mx-1"><?php echo $lang['to'] ?? 'ຫາ'; ?></span> 
                                            <?php echo date('d/m/Y', strtotime($check_out_search)); ?>
                                        </div>
                                    </div>
                                    <?php if($nights_count > 1): ?>
                                        <div class="mt-2 text-primary font-weight-bold">
                                            <div class="room-price"><?php echo formatCurrency($room['price']); ?> <span class="small text-muted">/ <?php echo $lang['nights_count']; ?></span></div>
                                            <?php echo $lang['total']; ?>: <?php echo formatCurrency($room['price'] * $nights_count); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer p-0">
                                    <button class="btn btn-warning btn-block rounded-0 text-white btn-reserve"
                                        data-room-id="<?php echo $room['id']; ?>"
                                        data-room-number="<?php echo htmlspecialchars($room['room_number']); ?>"
                                        data-room-type="<?php echo htmlspecialchars($room['room_type']); ?>"
                                        data-price="<?php echo $room['price']; ?>"
                                        data-nights="<?php echo $nights_count; ?>"
                                        data-total="<?php echo $room['price'] * $nights_count; ?>"
                                        data-checkin="<?php echo $check_in_search; ?>">
                                        <i class="fas fa-calendar-plus"></i> <?php echo $lang['bookings']; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-times-circle text-danger fa-3x mb-3 d-block"></i>
                    <h5 class="text-danger"><?php echo $lang['no_available_rooms_msg']; ?></h5>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current Reservations List -->
    <?php if (count($reservations) > 0): ?>
    <div class="card card-outline card-info shadow-sm">
        <div class="card-header d-flex flex-wrap align-items-center">
            <h3 class="card-title mr-auto mb-1 mb-md-0"><i class="fas fa-list"></i> <?php echo $lang['bookings']; ?> (<?php echo count($reservations); ?>)</h3>
            <div class="card-tools" style="flex: 1; min-width: 200px; max-width: 300px;">
                <div class="input-group input-group-sm">
                    <input type="text" id="res_search_input" class="form-control" placeholder="<?php echo $lang['search']; ?>...">
                    <div class="input-group-append">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-warning"></i></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-2 p-md-3">
            <div class="table-responsive">
            <table id="resTable" class="table table-bordered table-striped text-center mb-0" style="min-width: 600px;">
                <thead class="bg-warning text-white">
                    <tr>
                        <th><?php echo $lang['room']; ?></th>
                        <th><?php echo $lang['customer']; ?> / <?php echo $lang['guests']; ?></th>
                        <th><?php echo $lang['phone']; ?></th>
                        <th><?php echo $lang['checkin_date']; ?></th>
                        <th><?php echo $lang['checkout_date']; ?></th>
                        <th><?php echo $lang['nights']; ?></th>
                        <th><?php echo $lang['total']; ?></th>
                        <th><?php echo $lang['deposit']; ?></th>
                        <th><?php echo $lang['action']; ?></th>
                    </tr>
                </thead>
                <tbody id="res_table_body">
                    <?php foreach($reservations as $res): ?>
                    <tr class="res-row">
                        <td><strong><?php echo htmlspecialchars($res['room_number']); ?></strong><br><small class="text-muted">
                            <?php 
                                $r_type_mapped = $res[$rt_name_col] ?: $res['room_type'];
                                if ($r_type_mapped == 'Standard' || strtolower($r_type_mapped) == 'standard') {
                                    $r_type_mapped = $lang['room_type_standard'] ?? 'Standard';
                                } elseif ($r_type_mapped == 'VIP' || strtolower($r_type_mapped) == 'vip') {
                                    $r_type_mapped = $lang['room_type_vip'] ?? 'VIP';
                                } elseif ($r_type_mapped == 'ຫ້ອງຕຽງດ່ຽວ' || strtolower($r_type_mapped) == 'single bed room' || strtolower($r_type_mapped) == 'single room') {
                                    $r_type_mapped = $lang['room_type_single'] ?? 'Single Bed Room';
                                } elseif ($r_type_mapped == 'ຫ້ອງຕຽງຄູ່' || strtolower($r_type_mapped) == 'double bed room' || strtolower($r_type_mapped) == 'double room') {
                                    $r_type_mapped = $lang['room_type_double'] ?? 'Double Bed Room';
                                } elseif ($r_type_mapped == 'ຫ້ອງຄອບຄົວ' || strtolower($r_type_mapped) == 'family room') {
                                    $r_type_mapped = $lang['room_type_family'] ?? 'Family Room';
                                }
                                echo htmlspecialchars($r_type_mapped);
                            ?>
                        </small></td>
                        <td class="text-left">
                            <div class="font-weight-bold customer-name-text"><?php echo htmlspecialchars($res['customer_name']); ?></div>
                            <div class="small text-muted"><i class="fas fa-users mr-1"></i> <?php echo $lang['guests']; ?>: <strong><?php echo $res['guest_count']; ?></strong> <?php echo $lang['person_unit']; ?></div>
                        </td>
                        <td class="customer-phone-text"><?php echo htmlspecialchars($res['customer_phone']); ?></td>
                        <td class="text-success font-weight-bold"><?php echo date('d/m/Y', strtotime($res['check_in_date'])); ?></td>
                        <td class="text-danger"><?php echo date('d/m/Y', strtotime($res['check_out_date'])); ?></td>
                        <td>
                            <?php 
                                $diff = date_diff(date_create($res['check_in_date']), date_create($res['check_out_date']));
                                echo $diff->format("%a"); 
                            ?>
                        </td>
                        <td class="text-right"><?php echo formatCurrency($res['total_price']); ?></td>
                        <td class="text-right text-info"><?php echo formatCurrency($res['deposit_amount']); ?></td>
                        <td class="align-middle text-center">
                            <div class="btn-action-group">
                                <?php if ($can_edit): ?>
                                <a href="checkin_reserved.php?booking_id=<?php echo $res['id']; ?>" class="btn btn-sm btn-success" title="<?php echo $lang['check_in_now']; ?>">
                                    <i class="fas fa-sign-in-alt"></i> <span class="d-none d-md-inline"><?php echo $lang['check_in'] ?? 'Check-in'; ?></span>
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-primary btn-view-reserve" 
                                    data-id="<?php echo $res['id']; ?>"
                                    data-room="<?php echo htmlspecialchars($res['room_number']); ?>"
                                    data-type="<?php echo htmlspecialchars($res[$rt_name_col] ?: $res['room_type']); ?>"
                                    data-name="<?php echo htmlspecialchars($res['customer_name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($res['customer_phone']); ?>"
                                    data-passport="<?php echo htmlspecialchars($res['passport_number'] ?? '-'); ?>"
                                    data-address="<?php echo htmlspecialchars($res['address'] ?? '-'); ?>"
                                    data-guests="<?php echo $res['guest_count']; ?>"
                                    data-checkin="<?php echo date('d/m/Y', strtotime($res['check_in_date'])); ?>"
                                    data-checkout="<?php echo date('d/m/Y', strtotime($res['check_out_date'])); ?>"
                                    data-total="<?php echo number_format($res['total_price']); ?>"
                                    data-deposit="<?php echo number_format($res['deposit_amount']); ?>"
                                    data-payment="<?php echo $res['payment_method'] ?? 'Cash'; ?>"
                                    title="<?php echo $lang['view_details']; ?>">
                                    <i class="fas fa-eye"></i> <span class="d-none d-md-inline"><?php echo $lang['view'] ?? 'ເບິ່ງ'; ?></span>
                                </button>
                                <?php if ($can_edit): ?>
                                <button class="btn btn-sm btn-info btn-edit-reserve" 
                                    data-id="<?php echo $res['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($res['customer_name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($res['customer_phone']); ?>"
                                    data-guests="<?php echo $res['guest_count']; ?>"
                                    data-checkin="<?php echo $res['check_in_date']; ?>"
                                    data-checkout="<?php echo $res['check_out_date']; ?>"
                                    data-deposit="<?php echo $res['deposit_amount']; ?>"
                                    data-payment="<?php echo $res['payment_method'] ?? 'Cash'; ?>"
                                    title="<?php echo $lang['edit']; ?>">
                                    <i class="fas fa-edit"></i> <span class="d-none d-md-inline"><?php echo $lang['edit']; ?></span>
                                </button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                <a href="#" class="btn btn-sm btn-danger btn-cancel-reserve" data-id="<?php echo $res['id']; ?>" data-room-id="<?php echo $res['room_id']; ?>" title="<?php echo $lang['cancel']; ?>">
                                    <i class="fas fa-times-circle"></i> <span class="d-none d-md-inline"><?php echo $lang['cancel']; ?></span>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>


        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Reserve Modal -->
<div class="modal fade" id="reserveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="" method="post" id="reserveForm">
                <input type="hidden" name="room_id" id="modal_room_id">
                <input type="hidden" name="check_in_date" id="modal_checkin">
                <input type="hidden" name="nights_count" id="modal_nights">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-check"></i> <?php echo $lang['booking_info']; ?> <span id="modal_room_label"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        <strong><i class="fas fa-info-circle"></i> <?php echo $lang['booking_info']; ?>:</strong> 
                        <?php echo $lang['room']; ?> <span id="info_room" class="font-weight-bold"></span> | 
                        <?php echo $lang['date_label']; ?>: <span id="info_date" class="text-white font-weight-bold"></span> | 
                        <span id="info_nights"></span> <?php echo $lang['nights']; ?> | 
                        <?php echo $lang['subtotal']; ?>: <span id="info_total" class="font-weight-bold text-dark bg-warning"></span> ₭
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['full_name']; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="customer_name" id="res_name" class="form-control" placeholder="<?php echo $lang['enter_name']; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['phone']; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="customer_phone" id="res_phone" class="form-control" placeholder="020 XXXXXXXX" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['passport']; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="passport_number" class="form-control" placeholder="<?php echo $lang['enter_passport']; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['guests']; ?> <span class="text-danger">*</span></label>
                                <input type="number" name="guest_count" class="form-control" value="1" min="1" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label><?php echo $lang['address']; ?> <span class="text-danger">*</span></label>
                                <textarea name="address" class="form-control" rows="2" placeholder="<?php echo $lang['enter_address']; ?>" required></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['deposit']; ?> (<?php echo $lang['currency_symbol'] ?? '₭'; ?>)</label>
                                <input type="text" name="deposit_amount" id="modal_deposit" class="form-control number-format" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?php echo $lang['payment_method_label']; ?></label>
                                <select name="payment_method" class="form-control" required>
                                    <option value="Cash"><?php echo $lang['cash']; ?></option>
                                    <option value="Transfer"><?php echo $lang['transfer']; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><i class="fas fa-times"></i> <?php echo $lang['cancel']; ?></button>
                    <button type="submit" name="reserve" class="btn btn-warning text-white px-4"><i class="fas fa-calendar-check"></i> <?php echo $lang['confirm']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Reserve Modal -->
<div class="modal fade" id="viewReserveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-circle"></i> <?php echo $lang['details']; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="display-4 text-primary"><i class="fas fa-address-card"></i></div>
                    <h4 class="mt-2 font-weight-bold" id="v_name"></h4>
                    <span class="badge badge-info" id="v_room_info"></span>
                </div>
                <table class="table table-sm table-borderless">
                    <tr><td width="40%" class="text-muted"><?php echo $lang['phone']; ?>:</td><td class="font-weight-bold" id="v_phone"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['passport']; ?>:</td><td class="font-weight-bold" id="v_passport"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['address']; ?>:</td><td id="v_address"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['guests']; ?>:</td><td class="font-weight-bold"><span id="v_guests"></span> <?php echo $lang['person_unit']; ?></td></tr>
                    <tr><td colspan="2"><hr class="my-1"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['checkin_date']; ?>:</td><td class="text-success font-weight-bold" id="v_checkin"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['checkout_date']; ?>:</td><td class="text-danger font-weight-bold" id="v_checkout"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['total']; ?>:</td><td class="font-weight-bold text-primary" id="v_total"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['deposit']; ?>:</td><td class="font-weight-bold text-info" id="v_deposit"></td></tr>
                    <tr><td class="text-muted"><?php echo $lang['payment_method_label']; ?>:</td><td class="font-weight-bold" id="v_payment"></td></tr>
                </table>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal"><?php echo $lang['close']; ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Reserve Modal -->
<div class="modal fade" id="editReserveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="" method="post">
                <input type="hidden" name="booking_id" id="edit_booking_id">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit_booking']; ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $lang['customer']; ?></label>
                        <input type="text" name="customer_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo $lang['phone']; ?></label>
                        <input type="text" name="customer_phone" id="edit_phone" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo $lang['guest_count']; ?></label>
                        <input type="number" name="guest_count" id="edit_guests" class="form-control" min="1" required>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['check_in']; ?></label>
                                <input type="date" name="check_in_date" id="edit_checkin" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['check_out']; ?></label>
                                <input type="date" name="check_out_date" id="edit_checkout" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['deposit']; ?></label>
                                <input type="text" name="deposit_amount" id="edit_deposit" class="form-control number-format">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['payment_method_label']; ?></label>
                                <select name="payment_method" id="edit_payment_method" class="form-control" required>
                                    <option value="Cash"><?php echo $lang['cash']; ?></option>
                                    <option value="Transfer"><?php echo $lang['transfer']; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $lang['close']; ?></button>
                    <button type="submit" name="update_reserve" class="btn btn-info"><?php echo $lang['save']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
</style>
<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    // Helper to calculate date difference
    function getDaysBetween(d1, d2) {
        return Math.ceil((new Date(d2) - new Date(d1)) / (1000 * 60 * 60 * 24));
    }

    // Search Form: Auto-update Checkout when Check-in or Nights change
    function updateSearchDates(source) {
        let cin = $('#search_checkin').val();
        let nights = parseInt($('#search_nights').val()) || 1;
        let cout = $('#search_checkout').val();

        if (source === 'cin' || source === 'nights') {
            if (cin) {
                let d = new Date(cin);
                d.setDate(d.getDate() + nights);
                $('#search_checkout').val(d.toISOString().split('T')[0]);
            }
        } else if (source === 'cout') {
            if (cin && cout) {
                let diff = getDaysBetween(cin, cout);
                if (diff < 1) diff = 1;
                $('#search_nights').val(diff);
            }
        }
    }
    $('#search_checkin, #search_nights').on('change input', function() { updateSearchDates('cin'); });
    $('#search_checkout').on('change', function() { updateSearchDates('cout'); });

    // Edit Modal logic
    $('#edit_checkin, #edit_checkout').on('change', function() {
        let cin = $('#edit_checkin').val();
        let cout = $('#edit_checkout').val();
        if(cin && cout && new Date(cin) >= new Date(cout)) {
            let d = new Date(cin);
            d.setDate(d.getDate() + 1);
            $('#edit_checkout').val(d.toISOString().split('T')[0]);
        }
    });

    // Open reserve modal
    $('.btn-reserve').on('click', function() {
        var btn = $(this);
        var roomId = btn.data('room-id');
        var roomNum = btn.data('room-number');
        var price = btn.data('price');
        
        var checkin = "<?php echo $check_in_search; ?>";
        var checkout = "<?php echo $check_out_search; ?>";
        
        var diff = getDaysBetween(checkin, checkout);
        if (diff < 1) diff = 1;
        
        var total = price * diff;

        $('#modal_room_id').val(roomId);
        $('#modal_checkin').val(checkin);
        $('#modal_nights').val(diff);
        $('#modal_room_label').text('<?php echo $lang['room']; ?> ' + roomNum);
        $('#info_room').text(roomNum);
        $('#info_date').text(checkin + ' <?php echo $lang['to'] ?? 'ຫາ'; ?> ' + checkout);
        $('#info_nights').text(diff);
        $('#info_total').text(new Intl.NumberFormat().format(total));
        
        // Auto-calculate 50% deposit
        var deposit = Math.round(total / 2);
        $('#modal_deposit').val(new Intl.NumberFormat().format(deposit));
        
        $('#reserveModal').modal('show');
    });

    // Open Edit Modal
    $('.btn-edit-reserve').on('click', function() {
        var btn = $(this);
        $('#edit_booking_id').val(btn.data('id'));
        $('#edit_name').val(btn.data('name'));
        $('#edit_phone').val(btn.data('phone'));
        $('#edit_guests').val(btn.data('guests'));
        $('#edit_checkin').val(btn.data('checkin'));
        $('#edit_checkout').val(btn.data('checkout'));
        $('#edit_deposit').val(new Intl.NumberFormat().format(btn.data('deposit')));
        $('#edit_payment_method').val(btn.data('payment'));
        $('#editReserveModal').modal('show');
    });

    // Open View Modal
    $('.btn-view-reserve').on('click', function() {
        var btn = $(this);
        $('#v_name').text(btn.data('name'));
        $('#v_room_info').text('<?php echo $lang['room']; ?> ' + btn.data('room') + ' (' + btn.data('type') + ')');
        $('#v_phone').text(btn.data('phone'));
        $('#v_passport').text(btn.data('passport'));
        $('#v_address').text(btn.data('address'));
        $('#v_guests').text(btn.data('guests'));
        $('#v_checkin').text(btn.data('checkin'));
        $('#v_checkout').text(btn.data('checkout'));
        $('#v_total').text(btn.data('total') + ' ₭');
        $('#v_deposit').text(btn.data('deposit') + ' ₭');
        $('#v_payment').text(btn.data('payment'));
        $('#viewReserveModal').modal('show');
    });

    // Cancel reservation
    $('.btn-cancel-reserve').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var roomId = $(this).data('room-id');
        Swal.fire({
            title: '<?php echo $lang['cancel_booking_title']; ?>',
            text: "<?php echo $lang['cancel_booking_question']; ?>",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<?php echo $lang['confirm']; ?>',
            cancelButtonText: '<?php echo $lang['cancel']; ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'reserve.php?cancel_booking=' + id + '&room_id=' + roomId;
            }
        });
    });

    // Auto format number
    $('.number-format').on('input', function() {
        var val = $(this).val().replace(/,/g, '');
        if(!isNaN(val) && val !== '') {
            $(this).val(new Intl.NumberFormat().format(val));
        }
    });

    // Initialize DataTable
    var table = $('#resTable').DataTable({
        "language": {
            "sLengthMenu":   "<?php echo $lang['dt_length'] ?? 'ສະແດງ _MENU_ ລາຍການ'; ?>",
            "sZeroRecords":  "<?php echo $lang['dt_zeroRecords'] ?? 'ບໍ່ມີຂໍ້ມູນ'; ?>",
            "sInfo":         "<?php echo $lang['dt_info'] ?? 'ສະແດງ _START_ ຫາ _END_ ຈາກທັງໝົດ _TOTAL_ ລາຍການ'; ?>",
            "sSearch":       "<?php echo $lang['dt_search'] ?? 'ຄົ້ນຫາ:'; ?>",
            "oPaginate": { 
                "sPrevious": "<?php echo $lang['dt_paginate_previous'] ?? 'ກ່ອນໜ້າ'; ?>", 
                "sNext": "<?php echo $lang['dt_paginate_next'] ?? 'ຖັດໄປ'; ?>" 
            }
        },
        "responsive": false,
        "autoWidth": false,
        "order": [[ 3, "asc" ]] // Sort by check-in date by default
    });

    // Custom Live Search linked to DataTable
    $('#res_search_input').on('keyup', function() {
        table.search(this.value).draw();
    });
});
</script>
</body>
</html>
