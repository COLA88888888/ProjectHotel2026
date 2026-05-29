<?php
require_once '../config/session_check.php';
enforcePermission('rooms');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('rooms_edit'));
$can_delete = ($is_admin || hasPermission('rooms_delete'));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການເພີ່ມຂໍ້ມູນຫ້ອງ!";
        header("Location: select_rooms.php");
        exit();
    }
    $room_number = $_POST['room_number'];
    $room_type = $_POST['room_type'];
    $bed_type = $_POST['bed_type'];
    $price = str_replace(',', '', $_POST['price']); // Remove commas before saving
    $status = 'Available'; // Default status
    $housekeeping_status = $_POST['housekeeping_status']; // Default 'ພ້ອມໃຊ້'
    
    // Convert Lao to system status if needed, or save directly. User specified:
    // ພ້ອມໃຊ້ (Clean), Maintenance (ຫ້ອງເສຍ), Cleaning (ຫ້ອງກຳລັງທຳຄວາມສະອາດ)
    
    $stmt = $pdo->prepare("INSERT INTO rooms (room_number, room_type, bed_type, price, status, housekeeping_status) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$room_number, $room_type, $bed_type, $price, $status, $housekeeping_status])) {
        logActivity($pdo, "ເພີ່ມຫ້ອງໃໝ່", "ເລກຫ້ອງ: $room_number, ປະເພດ: $room_type");
        $_SESSION['success'] = $lang['save_success'];
        header("Location: select_rooms.php");
        exit();
    } else {
        $_SESSION['error'] = $lang['error_occurred'];
    }
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂຂໍ້ມູນຫ້ອງ!";
        header("Location: select_rooms.php");
        exit();
    }
    $id = $_POST['id'];
    $room_number = $_POST['room_number'];
    $room_type = $_POST['room_type'];
    $bed_type = $_POST['bed_type'];
    $price = str_replace(',', '', $_POST['price']); // Remove commas before saving
    $status = $_POST['status']; 
    $housekeeping_status = $_POST['housekeeping_status']; 

    $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, room_type = ?, bed_type = ?, price = ?, status = ?, housekeeping_status = ? WHERE id = ?");
    if ($stmt->execute([$room_number, $room_type, $bed_type, $price, $status, $housekeeping_status, $id])) {
        logActivity($pdo, "ແກ້ໄຂຂໍ້ມູນຫ້ອງ", "ເລກຫ້ອງ: $room_number, ປະເພດ: $room_type, ລາຄາ: " . number_format((float)$price) . " ກີບ");
        $_SESSION['success'] = $lang['save_success'];
        header("Location: select_rooms.php");
        exit();
    } else {
        $_SESSION['error'] = $lang['error_occurred'];
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບຂໍ້ມູນຫ້ອງພັກ!";
        header("Location: select_rooms.php");
        exit();
    }
    $id = $_GET['delete'];
    
    // Fetch details before delete
    $stmtOld = $pdo->prepare("SELECT room_number, room_type FROM rooms WHERE id = ?");
    $stmtOld->execute([$id]);
    $room = $stmtOld->fetch();
    $roomNum = $room['room_number'] ?? '';
    $roomType = $room['room_type'] ?? '';

    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
    if ($stmt->execute([$id])) {
        logActivity($pdo, "ລົບຂໍ້ມູນຫ້ອງ", "ລົບຫ້ອງ $roomNum (ປະເພດ: $roomType)");
        $_SESSION['success'] = $lang['delete_success'];
    } else {
        $_SESSION['error'] = $lang['error_occurred'] ?? "ເກີດຂໍ້ຜິດພາດໃນການລົບ";
    }
    header("Location: select_rooms.php");
    exit();
}

// AJAX: Update housekeeping status
if (isset($_POST['update_housekeeping'])) {
    $id = (int)$_POST['room_id'];
    $hk_status = $_POST['hk_status'];
    
    // Fetch room number first
    $stmtRoom = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
    $stmtRoom->execute([$id]);
    $roomNum = $stmtRoom->fetchColumn() ?: '';

    $stmt = $pdo->prepare("UPDATE rooms SET housekeeping_status = ?, status = CASE WHEN status NOT IN ('Occupied', 'Booked') AND ? IN ('ພ້ອມໃຊ້ງານ', 'Ready') THEN 'Available' ELSE status END WHERE id = ?");
    $ok = $stmt->execute([$hk_status, $hk_status, $id]);
    if ($ok) {
        logActivity($pdo, "ອັບເດດສະຖານະຄວາມພ້ອມ", "ອັບເດດສະຖານະຄວາມພ້ອມຂອງຫ້ອງ $roomNum ເປັນ: $hk_status");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok]);
    exit();
}

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$rt_name_col = "room_type_name_" . $current_lang;
$bed_name_col = "bed_type_" . $current_lang;

$stmt = $pdo->query("SELECT r.*, rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
                     FROM rooms r 
                     LEFT JOIN room_types rt ON r.room_type = rt.room_type_name 
                     GROUP BY r.id
                     ORDER BY r.id DESC");
$rooms = $stmt->fetchAll();

// Fetch room types for dropdown
$stmtTypes = $pdo->query("SELECT * FROM room_types ORDER BY id DESC");
$room_types = $stmtTypes->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['room_details']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/pages/select_rooms.css">
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $lang['success_label'] ?? 'ສຳເລັດ'; ?>',
                    text: '<?php echo $_SESSION['success']; ?>',
                    showConfirmButton: false,
                    timer: 2000
                });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>',
                    text: '<?php echo $_SESSION['error']; ?>',
                    confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
                });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row">
        <!-- Form Section -->
        <?php if ($can_edit): ?>
        <div class="col-md-3">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> <?php echo $lang['add_new_room']; ?></h3>
                </div>
                <form action="" method="post" id="roomForm">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['room_code']; ?></label>
                            <input type="text" class="form-control" value="[ <?php echo $lang['auto_generated']; ?> ]" readonly style="background-color: #e9ecef; font-weight: 700; color: #6c757d;">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_number_label']; ?></label>
                            <input type="text" name="room_number" id="room_number" class="form-control" placeholder="<?php echo $lang['enter_room_number']; ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_type_label']; ?></label>
                            <select name="room_type" id="room_type" class="form-control">
                                <option value=""><?php echo $lang['select_type']; ?></option>
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
                                    <option value="<?php echo htmlspecialchars($rt['room_type_name']); ?>"><?php echo htmlspecialchars($r_type_mapped); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['bed_type_label']; ?></label>
                            <select name="bed_type" id="bed_type" class="form-control">
                                <option value=""><?php echo $lang['select_bed']; ?></option>
                                <option value="ຕຽງດ່ຽວ"><?php echo $lang['single_bed']; ?></option>
                                <option value="ຕຽງຄູ່"><?php echo $lang['double_bed']; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['price_per_night']; ?> (<?php echo $lang['currency_symbol'] ?? '₭'; ?>)</label>
                            <input type="text" name="price" id="price" class="form-control number-format" placeholder="<?php echo $lang['enter_price']; ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['housekeeping_status_label']; ?></label>
                            <select name="housekeeping_status" id="housekeeping_status" class="form-control">
                                <option value="ພ້ອມໃຊ້ງານ"><?php echo $lang['ready']; ?></option>
                                <option value="Cleaning"><?php echo $lang['cleaning']; ?></option>
                                <option value="Maintenance"><?php echo $lang['maintenance']; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-start bg-white border-top-0">
                        <button type="submit" name="save" class="btn btn-primary px-4 font-weight-bold" style="border-radius: 8px;"><i class="fas fa-save mr-1"></i> <?php echo $lang['save']; ?></button>
                        <button type="reset" class="btn btn-default px-4 font-weight-bold ml-2" style="border-radius: 8px;"><?php echo $lang['cancel']; ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="<?php echo $can_edit ? 'col-md-9' : 'col-md-12'; ?>">
            <div class="card card-outline card-info">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-list"></i> <?php echo $lang['all_rooms_detail']; ?></h3>
                </div>
                <div class="card-body table-responsive">
                    <table id="roomTable" class="table table-bordered table-striped table-hover text-center">
                        <thead class="bg-light">
                            <tr>
                                <th><?php echo $lang['room_code'] ?? 'ລະຫັດຫ້ອງ'; ?></th>
                                <th><?php echo $lang['room']; ?></th>
                                <th><?php echo $lang['room_types_header']; ?></th>
                                <th><?php echo $lang['bed_type_header']; ?></th>
                                <th><?php echo $lang['price']; ?></th>
                                <th><?php echo $lang['status']; ?></th>
                                <th><?php echo $lang['housekeeping']; ?></th>
                                <th><?php echo $lang['action']; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rooms) > 0): ?>
                                <?php $i = 1; foreach ($rooms as $row): ?>
                                    <tr>
                                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                                        <td class="room-number-cell"><?php echo htmlspecialchars($row['room_number']); ?></td>
                                        <td><?php 
                                             $r_type_mapped = $row[$rt_name_col] ?: $row['room_type'];
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
                                         ?></td>
                                        <td>
                                            <?php 
                                                $b_type = $row['bed_type'];
                                                if ($b_type == 'ຕຽງດ່ຽວ' || strtolower($b_type) == 'single' || strtolower($b_type) == 'single bed') {
                                                    echo htmlspecialchars($lang['single_bed'] ?? 'Single Bed');
                                                } elseif ($b_type == 'ຕຽງຄູ່' || strtolower($b_type) == 'double' || strtolower($b_type) == 'double bed') {
                                                    echo htmlspecialchars($lang['double_bed'] ?? 'Double Bed');
                                                } else {
                                                    echo htmlspecialchars($b_type);
                                                }
                                            ?>
                                        </td>
                                        <td class="price-cell">
                                            <?php echo number_format($row['price']); ?>
                                            <span class="currency-label"><?php echo $lang['currency_symbol'] ?? '₭'; ?></span>
                                        </td>
                                        <td>
                                            <?php if($row['status'] == 'Available'): ?>
                                                <?php if($row['housekeeping_status'] == 'ພ້ອມໃຊ້ງານ' || $row['housekeeping_status'] == 'Ready'): ?>
                                                    <span class="badge badge-available badge-status"><?php echo $lang['available']; ?></span>
                                                <?php elseif($row['housekeeping_status'] == 'Cleaning'): ?>
                                                    <span class="badge badge-booked badge-status"><?php echo $lang['cleaning']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary badge-status"><?php echo $lang['maintenance']; ?></span>
                                                <?php endif; ?>
                                            <?php elseif($row['status'] == 'Booked'): ?>
                                                <span class="badge badge-booked badge-status"><?php echo $lang['booked']; ?></span>
                                            <?php elseif($row['status'] == 'Occupied'): ?>
                                                <span class="badge badge-occupied badge-status"><?php echo $lang['occupied']; ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary badge-status"><?php echo htmlspecialchars($row['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $hk = $row['housekeeping_status'];
                                                $hk_class = 'hk-ready';
                                                if ($hk == 'Cleaning') $hk_class = 'hk-cleaning';
                                                elseif ($hk == 'Maintenance') $hk_class = 'hk-maintenance';
                                                $is_disabled = ($row['status'] == 'Booked' || $row['status'] == 'Occupied') ? 'disabled' : '';
                                            ?>
                                            <select class="hk-select <?php echo $hk_class; ?>" data-room-id="<?php echo $row['id']; ?>" data-status="<?php echo htmlspecialchars($row['status']); ?>" <?php echo $is_disabled; ?>>
                                                <option value="ພ້ອມໃຊ້ງານ" <?php echo ($hk == 'ພ້ອມໃຊ້ງານ' || $hk == 'Ready') ? 'selected' : ''; ?>><?php echo $lang['ready']; ?></option>
                                                <option value="Cleaning" <?php echo ($hk == 'Cleaning') ? 'selected' : ''; ?>><?php echo $lang['cleaning']; ?></option>
                                                <option value="Maintenance" <?php echo ($hk == 'Maintenance') ? 'selected' : ''; ?>><?php echo $lang['maintenance']; ?></option>
                                            </select>
                                        </td>
                                        <td style="width: 100px;">
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($can_edit): ?>
                                                 <a href="#" class="btn btn-warning text-white btn-edit-room" title="<?php echo $lang['edit']; ?>" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-room-number="<?php echo htmlspecialchars($row['room_number']); ?>"
                                                    data-room-type="<?php echo htmlspecialchars($row['room_type']); ?>"
                                                    data-bed-type="<?php echo htmlspecialchars($row['bed_type']); ?>"
                                                    data-price="<?php echo number_format($row['price']); ?>"
                                                    data-status="<?php echo htmlspecialchars($row['status']); ?>"
                                                    data-housekeeping-status="<?php echo htmlspecialchars($row['housekeeping_status']); ?>"><i class="fas fa-edit"></i></a>
                                                <?php endif; ?>
                                                <?php if ($can_delete): ?>
                                                <a href="#" class="btn btn-danger btn-delete" data-id="<?php echo $row['id']; ?>" title="<?php echo $lang['delete']; ?>"><i class="fas fa-trash-alt"></i></a>
                                                <?php endif; ?>
                                                <?php if (!$can_edit && !$can_delete): ?>
                                                <span class="text-muted small">ເບິ່ງຢ່າງດຽວ</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-muted"><?php echo $lang['table_zero_records']; ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
</div>

<!-- Edit Room Modal -->
<div class="modal fade" id="editRoomModal" tabindex="-1" role="dialog" aria-labelledby="editRoomModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.15);">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title font-weight-bold" id="editRoomModalLabel"><i class="fas fa-edit mr-2"></i> <?php echo $lang['edit_room'] ?? 'ແກ້ໄຂຂໍ້ມູນຫ້ອງ'; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="post" id="editRoomModalForm">
                <div class="modal-body p-4 text-left">
                    <input type="hidden" name="id" id="modal_room_id">
                    
                    <div class="row">
                        <!-- Left Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['room_code'] ?? 'ລະຫັດຫ້ອງ'; ?></label>
                                <input type="text" id="modal_room_id_display" class="form-control" readonly style="background-color: #e9ecef; font-weight: 700; color: #495057; border-radius: 8px;">
                            </div>
                        </div>
                        
                        <!-- Right Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['room_number_label'] ?? 'ເລກຫ້ອງ'; ?></label>
                                <input type="text" name="room_number" id="modal_room_number" class="form-control" required style="border-radius: 8px;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <!-- Left Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['room_type_label'] ?? 'ປະເພດຫ້ອງ'; ?></label>
                                <select name="room_type" id="modal_room_type" class="form-control" style="border-radius: 8px;">
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
                                        <option value="<?php echo htmlspecialchars($rt['room_type_name']); ?>">
                                            <?php echo htmlspecialchars($r_type_mapped); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Right Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['bed_type_label'] ?? 'ປະເພດຕຽງ'; ?></label>
                                <select name="bed_type" id="modal_bed_type" class="form-control" style="border-radius: 8px;">
                                    <option value="ຕຽງດ່ຽວ"><?php echo $lang['single_bed'] ?? 'Single Bed'; ?></option>
                                    <option value="ຕຽງຄູ່"><?php echo $lang['double_bed'] ?? 'Double Bed'; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <!-- Left Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['price_per_night'] ?? 'ລາຄາ'; ?> (<?php echo $lang['currency_symbol'] ?? '₭'; ?>)</label>
                                <input type="text" name="price" id="modal_price" class="form-control number-format" required style="border-radius: 8px;">
                            </div>
                        </div>
                        
                        <!-- Right Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['status_label'] ?? 'ສະຖານະຫ້ອງ'; ?></label>
                                <select name="status" id="modal_status" class="form-control" style="border-radius: 8px;">
                                    <option value="Available"><?php echo $lang['available']; ?></option>
                                    <option value="Booked"><?php echo $lang['booked']; ?></option>
                                    <option value="Occupied"><?php echo $lang['occupied']; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <!-- Left Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['housekeeping_status_label'] ?? 'ສະຖານະຄວາມພ້ອມ'; ?></label>
                                <select name="housekeeping_status" id="modal_housekeeping_status" class="form-control" style="border-radius: 8px;">
                                    <option value="ພ້ອມໃຊ້ງານ"><?php echo $lang['ready']; ?></option>
                                    <option value="Cleaning"><?php echo $lang['cleaning']; ?></option>
                                    <option value="Maintenance"><?php echo $lang['maintenance']; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer bg-white border-top-0 d-flex justify-content-end p-3 px-4">
                    <button type="submit" name="update" class="btn btn-warning text-white px-4 font-weight-bold" style="border-radius: 8px; border: 2px solid #d39e00;"><i class="fas fa-save mr-1"></i> <?php echo $lang['save'] ?? 'ບັນທຶກ'; ?></button>
                    <button type="button" class="btn btn-default px-4 font-weight-bold ml-2" data-dismiss="modal" style="border-radius: 8px;"><?php echo $lang['cancel'] ?? 'ຍົກເລີກ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- DataTables  & Plugins -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="../plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="../plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#roomTable').DataTable({
      "paging": true,
      "lengthChange": false,
      "searching": true,
      "ordering": true,
      "info": true,
      "autoWidth": false,
      "responsive": false,
      "pageLength": 10,
      "language": {
          "search": "<?php echo $lang['search']; ?>:",
          "info": "<?php echo $lang['table_info']; ?>",
          "infoEmpty": "<?php echo $lang['table_info_empty']; ?>",
          "zeroRecords": "<?php echo $lang['table_zero_records']; ?>",
          "paginate": {
              "first": "<?php echo $lang['first']; ?>",
              "last": "<?php echo $lang['last']; ?>",
              "next": "<?php echo $lang['next']; ?>",
              "previous": "<?php echo $lang['previous']; ?>"
          }
      }
    });

    // Number formatting with commas
    $('.number-format').on('input', function(e) {
        // Remove non-numeric characters (except for maybe a period if decimal is needed, but we assume integers for Kip)
        var value = $(this).val().replace(/[^0-9]/g, '');
        // Format with commas
        if (value !== '') {
            $(this).val(parseInt(value, 10).toLocaleString('en-US'));
        } else {
            $(this).val('');
        }
    });

    $('#roomForm').on('submit', function(e) {
        var roomNumber = $('#room_number').val().trim();
        var roomType = $('#room_type').val();
        var bedType = $('#bed_type').val();
        var price = $('#price').val().trim();
        
        if (roomNumber === '' || roomType === '' || bedType === '' || price === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: '<?php echo $lang['warning_label'] ?? 'ແຈ້ງເຕືອນ'; ?>',
                text: '<?php echo $lang['form_required_msg'] ?? 'ກະລຸນາປ້ອນຂໍ້ມູນໃຫ້ຄົບຖ້ວນທຸກຊ່ອງ!'; ?>',
                confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
            });
            return false;
        }
    });

    // Delete Confirmation
    $('.btn-delete').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({
            title: '<?php echo $lang['confirm_delete']; ?>',
            text: "<?php echo $lang['delete_warning_room'] ?? 'ທ່ານຕ້ອງການລົບຫ້ອງນີ້ແທ້ບໍ່?'; ?>",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<?php echo $lang['confirm'] ?? 'ຢືນຢັນ'; ?>',
            cancelButtonText: '<?php echo $lang['cancel']; ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "?delete=" + id;
            }
        });
    });

    // Edit room modal pop-up and data populating
    $(document).on('click', '.btn-edit-room', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var num = $(this).data('room-number');
        var type = $(this).data('room-type');
        var bed = $(this).data('bed-type');
        var price = $(this).data('price');
        var status = $(this).data('status');
        var hk = $(this).data('housekeeping-status');
        
        $('#modal_room_id').val(id);
        $('#modal_room_id_display').val('#' + id);
        $('#modal_room_number').val(num);
        $('#modal_room_type').val(type);
        $('#modal_bed_type').val(bed);
        $('#modal_price').val(price);
        $('#modal_status').val(status);
        $('#modal_housekeeping_status').val(hk);
        
        $('#editRoomModal').modal('show');
    });

    // Validate Edit Room Modal Form
    $('#editRoomModalForm').on('submit', function(e) {
        var roomNumber = $('#modal_room_number').val().trim();
        var roomType = $('#modal_room_type').val();
        var bedType = $('#modal_bed_type').val();
        var price = $('#modal_price').val().trim();
        
        if (roomNumber === '' || roomType === '' || bedType === '' || price === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: '<?php echo $lang['warning_label'] ?? 'ແຈ້ງເຕືອນ'; ?>',
                text: '<?php echo $lang['form_required_msg'] ?? 'ກະລຸນາປ້ອນຂໍ້ມູນໃຫ້ຄົບຖ້ວນທຸກຊ່ອງ!'; ?>',
                confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
            });
            return false;
        }
    });

    // Housekeeping status update via AJAX
    $(document).on('change', '.hk-select', function() {
        var $sel = $(this);
        var roomId = $sel.data('room-id');
        var currentStatus = $sel.data('status');
        var newStatus = $sel.val();
        
        $sel.addClass('hk-saving');
        
        $.post('select_rooms.php', {
            update_housekeeping: 1,
            room_id: roomId,
            hk_status: newStatus
        }, function(res) {
            $sel.removeClass('hk-saving');
            // Update color class
            $sel.removeClass('hk-ready hk-cleaning hk-maintenance');
            if (newStatus === 'ພ້ອມໃຊ້ງານ') $sel.addClass('hk-ready');
            else if (newStatus === 'Cleaning') $sel.addClass('hk-cleaning');
            else $sel.addClass('hk-maintenance');
            
            // Dynamically update status column if current status is Available
            if (currentStatus === 'Available') {
                var $statusTd = $sel.closest('tr').find('td').eq(5);
                if (newStatus === 'ພ້ອມໃຊ້ງານ') {
                    $statusTd.html('<span class="badge badge-available badge-status"><?php echo $lang['available']; ?></span>');
                } else if (newStatus === 'Cleaning') {
                    $statusTd.html('<span class="badge badge-booked badge-status"><?php echo $lang['cleaning']; ?></span>');
                } else {
                    $statusTd.html('<span class="badge badge-secondary badge-status"><?php echo $lang['maintenance']; ?></span>');
                }
            }
            
            // Show toast
            Swal.fire({
                icon: 'success',
                title: '<?php echo $lang['save_success']; ?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500
            });
        }).fail(function() {
            $sel.removeClass('hk-saving');
            Swal.fire({ 
                icon: 'error', 
                title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>', 
                text: '<?php echo $lang['error_occurred'] ?? 'ບໍ່ສາມາດອັບເດດໄດ້'; ?>' 
            });
        });
    });
});
</script>
</body>
</html>
