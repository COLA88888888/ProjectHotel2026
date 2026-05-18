<?php
require_once '../config/session_check.php';
enforcePermission('walkin');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$rt_name_col = "room_type_name_" . $current_lang;

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('walkin_edit'));
$can_delete = ($is_admin || hasPermission('walkin_delete'));

// Fetch room types for dropdown
$stmtTypes = $pdo->query("SELECT * FROM room_types ORDER BY id DESC");
$room_types = $stmtTypes->fetchAll();

$available_rooms = [];
$nights = 1;
$selected_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $nights = (int)$_POST['nights'];
    $selected_type = $_POST['room_type'];

    if ($selected_type === 'all' || empty($selected_type)) {
        // Find all available rooms
        $stmt = $pdo->prepare("SELECT r.*, rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
                               FROM rooms r 
                               LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
                               WHERE r.status = 'Available' AND (r.housekeeping_status = 'ພ້ອມໃຊ້ງານ' OR r.housekeeping_status = 'Ready')
                               GROUP BY r.id");
        $stmt->execute();
    } else {
        // Find available rooms by type
        $stmt = $pdo->prepare("SELECT r.*, rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
                               FROM rooms r 
                               LEFT JOIN room_types rt ON r.room_type = rt.room_type_name
                               WHERE r.room_type = ? AND r.status = 'Available' AND (r.housekeeping_status = 'ພ້ອມໃຊ້ງານ' OR r.housekeeping_status = 'Ready')
                               GROUP BY r.id");
        $stmt->execute([$selected_type]);
    }
    
    $available_rooms = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['walkin_title']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <script>
        if (window.top === window.self) { window.location.href = '../menu_admin.php'; }
    </script>
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        .fas, .far, .fab, .fa { font-family: "Font Awesome 5 Free" !important; font-weight: 900 !important; }
        body { background-color: #f8f9fa; padding: 20px; }
        
        .room-card { 
            border: 1px solid #dee2e6 !important;
            border-radius: 12px !important;
            transition: all 0.2s;
            background: #fff;
            height: 100%;
        }
        .room-card:hover { 
            border-color: #007bff !important;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important;
        }
        
        .room-card-body {
            padding: 20px;
            text-align: center;
        }
        
        .room-icon-wrapper {
            font-size: 2.5rem;
            color: #28a745;
            margin-bottom: 10px;
        }
        
        .room-number {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .room-type {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .room-price { 
            font-size: 1.25rem; 
            font-weight: 700; 
            color: #28a745;
        }
        
        .btn-choose {
            border-radius: 0 0 12px 12px !important;
            padding: 10px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .room-header { padding: 20px 10px; }
            .room-icon { font-size: 2.2rem; }
            .room-number-badge { font-size: 1.2rem; }
            .room-price { font-size: 1.1rem; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $lang['ok']; ?>',
                    text: '<?php echo $_SESSION['success']; ?>',
                    showConfirmButton: <?php echo isset($_SESSION['print_booking']) ? 'true' : 'false'; ?>,
                    confirmButtonText: '<i class="fas fa-print"></i> <?php echo $lang['print_bill']; ?>',
                    showCancelButton: true,
                    cancelButtonText: '<?php echo $lang['close']; ?>',
                    confirmButtonColor: '#28a745'
                }).then((result) => {
                    if (result.isConfirmed) {
                        <?php if(isset($_SESSION['print_booking'])): ?>
                            window.open('../print/print_room_receipt.php?booking_id=<?php echo $_SESSION['print_booking']; ?>', '_blank', 'width=800,height=600');
                            <?php unset($_SESSION['print_booking']); ?>
                        <?php endif; ?>
                    }
                });
            });
        </script>
    <?php unset($_SESSION['success']); unset($_SESSION['print_booking']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: 'ຜິດພາດ',
                    text: '<?php echo $_SESSION['error']; ?>',
                    confirmButtonText: 'ຕົກລົງ'
                });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row">
        <div class="col-12">
            <h2 class="mb-4"><i class="fas fa-walking"></i> <?php echo $lang['walkin_title']; ?></h2>
        </div>
    </div>

    <!-- Search/Filter Form -->
    <div class="card card-primary card-outline">
        <div class="card-header">
            <h3 class="card-title"><?php echo $lang['inquiry_title']; ?></h3>
        </div>
        <form action="" method="post">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><?php echo $lang['stay_how_many_nights']; ?></label>
                            <input type="number" name="nights" class="form-control" value="<?php echo $nights; ?>" min="1" required>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label><?php echo $lang['what_room_type']; ?></label>
                            <select name="room_type" class="form-control">
                                <option value="all"><?php echo $lang['view_all_types']; ?></option>
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
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-group w-100">
                            <button type="submit" name="search" class="btn btn-primary btn-block">
                                <i class="fas fa-search"></i> <?php echo $lang['search_available_rooms']; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Results Section -->
    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])): ?>
    <div class="card card-success card-outline">
        <div class="card-header">
            <h3 class="card-title"><?php echo $lang['recommendation_title']; ?></h3>
        </div>
        <div class="card-body bg-light">
            <?php if (count($available_rooms) > 0): ?>
                <div class="row">
                    <?php foreach($available_rooms as $room): ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                            <div class="card room-card shadow-sm">
                                <div class="room-card-body">
                                    <div class="room-icon-wrapper">
                                        <i class="fas fa-door-closed"></i>
                                    </div>
                                    <div class="room-number"><?php echo $lang['room']; ?> <?php echo htmlspecialchars($room['room_number']); ?></div>
                                    <div class="room-type">
                                        <?php 
                                            // --- 1. ສ່ວນແປພາສາປະເພດຫ້ອງ (Room Type Translation) ---
                                            // ດຶງຊື່ປະເພດຫ້ອງຕາມຄໍລຳພາສາທີ່ເລືອກ ຫຼື ຖ້າບໍ່ມີໃຫ້ດຶງຄ່າເລີ່ມຕົ້ນ
                                            $r_type_mapped = $room[$rt_name_col] ?: $room['room_type'];
                                            
                                            // ສ້າງເງື່ອນໄຂ Map ກວດສອບຊື່ປະເພດຫ້ອງຫຼັກ ເພື່ອດຶງຄຳແປຈາກ Dictionary ພາສາ ($lang)
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

                                            // --- 2. ສ່ວນແປພາສາປະເພດຕຽງ (Bed Type Translation) ---
                                            // ກວດສອບຄ່າປະເພດຕຽງໃນຖານຂໍ້ມູນ ແລະ Map ແປພາສາໃຫ້ຖືກຕ້ອງຕາມທີ່ກຳນົດໃນ Dictionary
                                            $b_type_val = $room['bed_type'];
                                            if ($b_type_val == 'ຕຽງດ່ຽວ' || strtolower($b_type_val) == 'single' || strtolower($b_type_val) == 'single bed') {
                                                $b_type = $lang['single_bed'] ?? 'Single Bed';
                                            } elseif ($b_type_val == 'ຕຽງຄູ່' || strtolower($b_type_val) == 'double' || strtolower($b_type_val) == 'double bed') {
                                                $b_type = $lang['double_bed'] ?? 'Double Bed';
                                            } else {
                                                $b_type = $b_type_val;
                                            }
                                            
                                            // --- 3. ສ່ວນສະແດງຜົນຊື່ປະເພດຫ້ອງ ແລະ ປະເພດຕຽງ ---
                                            echo htmlspecialchars($r_type);
                                            if (strpos(strtoupper($r_type), 'VIP') !== false || (strpos($r_type, 'ຕຽງ') === false && strpos(strtolower($r_type), 'bed') === false)) {
                                                echo " (" . htmlspecialchars($b_type) . ")";
                                            }
                                        ?>
                                    </div>
                                    <div class="room-price"><?php echo formatCurrency($room['price']); ?> <span class="small text-muted">/ <?php echo $lang['nights_count']; ?></span></div>
                                    
                                    <?php if($nights > 1): ?>
                                        <div class="mt-2 text-primary font-weight-bold small">
                                            <?php echo $lang['subtotal']; ?>: <?php echo formatCurrency($room['price'] * $nights); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($can_edit): ?>
                                    <a href="checkin.php?room_id=<?php echo $room['id']; ?>&nights=<?php echo $nights; ?>" class="btn btn-success btn-choose btn-block">
                                        <i class="fas fa-check-circle"></i> <?php echo $lang['choose_this_room']; ?>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-choose btn-block" disabled>
                                        <i class="fas fa-lock"></i> ເບິ່ງ
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <h4 class="text-danger"><i class="fas fa-times-circle text-danger fa-3x mb-3 d-block"></i> <?php echo $lang['no_available_rooms_msg']; ?></h4>
                    <p class="text-muted"><?php echo $lang['try_another_type_msg']; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>



</body>
</html>
