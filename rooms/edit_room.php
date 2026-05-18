<?php
require_once '../config/session_check.php';
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
if (!$is_admin && !hasPermission('rooms_edit')) {
    $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂຂໍ້ມູນຫ້ອງ!";
    header("Location: select_rooms.php");
    exit();
}
require_once '../config/db.php';
require_once '../config/logger.php';

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

if (!isset($_GET['id'])) {
    header("Location: select_rooms.php");
    exit();
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->execute([$id]);
$room = $stmt->fetch();

if (!$room) {
    header("Location: select_rooms.php");
    exit();
}

// Fetch room types for dropdown
$stmtTypes = $pdo->query("SELECT * FROM room_types ORDER BY id DESC");
$room_types = $stmtTypes->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
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
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['edit_room'] ?? 'ແແກ້ໄຂຂໍ້ມູນຫ້ອງ'; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free-5.15.3-web/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-edit"></i> <?php echo $lang['edit'] ?? 'ແກ້ໄຂ'; ?> (<?php echo htmlspecialchars($room['room_number']); ?>)</h3>
                </div>
                <form action="" method="post" id="editRoomForm">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['room_code'] ?? 'ລະຫັດຫ້ອງ'; ?></label>
                            <input type="text" class="form-control" value="#<?php echo htmlspecialchars($room['id']); ?>" readonly style="background-color: #e9ecef; font-weight: 700; color: #495057;">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_number_label']; ?></label>
                            <input type="text" name="room_number" id="room_number" class="form-control" value="<?php echo htmlspecialchars($room['room_number']); ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_type_label']; ?></label>
                            <select name="room_type" id="room_type" class="form-control">
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
                                    <option value="<?php echo htmlspecialchars($rt['room_type_name']); ?>" <?php echo ($rt['room_type_name'] == $room['room_type']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($r_type_mapped); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['bed_type_label']; ?></label>
                            <select name="bed_type" id="bed_type" class="form-control">
                                <option value="ຕຽງດ່ຽວ" <?php echo ($room['bed_type'] == 'ຕຽງດ່ຽວ') ? 'selected' : ''; ?>><?php echo $lang['single_bed'] ?? 'Single Bed'; ?></option>
                                <option value="ຕຽງຄູ່" <?php echo ($room['bed_type'] == 'ຕຽງຄູ່') ? 'selected' : ''; ?>><?php echo $lang['double_bed'] ?? 'Double Bed'; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['price_per_night']; ?> (<?php echo $lang['currency_symbol'] ?? '₭'; ?>)</label>
                            <input type="text" name="price" id="price" class="form-control number-format" value="<?php echo number_format((int)$room['price']); ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['status_label'] ?? $lang['status'] ?? 'ສະຖານະຫ້ອງ'; ?></label>
                            <select name="status" id="status" class="form-control">
                                <option value="Available" <?php echo ($room['status'] == 'Available') ? 'selected' : ''; ?>><?php echo $lang['available']; ?></option>
                                <option value="Booked" <?php echo ($room['status'] == 'Booked') ? 'selected' : ''; ?>><?php echo $lang['booked']; ?></option>
                                <option value="Occupied" <?php echo ($room['status'] == 'Occupied') ? 'selected' : ''; ?>><?php echo $lang['occupied']; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['housekeeping_status_label']; ?></label>
                            <select name="housekeeping_status" id="housekeeping_status" class="form-control">
                                <option value="ພ້ອມໃຊ້ງານ" <?php echo ($room['housekeeping_status'] == 'ພ້ອມໃຊ້ງານ' || $room['housekeeping_status'] == 'Ready') ? 'selected' : ''; ?>><?php echo $lang['ready']; ?></option>
                                <option value="Cleaning" <?php echo ($room['housekeeping_status'] == 'Cleaning') ? 'selected' : ''; ?>><?php echo $lang['cleaning']; ?></option>
                                <option value="Maintenance" <?php echo ($room['housekeeping_status'] == 'Maintenance') ? 'selected' : ''; ?>><?php echo $lang['maintenance']; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <button type="submit" name="update" class="btn btn-warning"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
                        <a href="select_rooms.php" class="btn btn-default"><i class="fas fa-arrow-left"></i> <?php echo $lang['back']; ?></a>
                    </div>
                </form>
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

<script>
$(document).ready(function() {
    // Number formatting with commas
    $('.number-format').on('input', function(e) {
        var value = $(this).val().replace(/[^0-9]/g, '');
        if (value !== '') {
            $(this).val(parseInt(value, 10).toLocaleString('en-US'));
        } else {
            $(this).val('');
        }
    });

    $('#editRoomForm').on('submit', function(e) {
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
});
</script>

</body>
</html>
