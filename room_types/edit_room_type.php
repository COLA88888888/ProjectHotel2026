<?php
session_start();
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

if (!isset($_GET['id'])) {
    header("Location: form_room_types.php");
    exit();
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM room_types WHERE id = ?");
$stmt->execute([$id]);
$room_type = $stmt->fetch();

if (!$room_type) {
    header("Location: form_room_types.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $room_type_name_la = $_POST['room_type_name_la'] ?? '';
    $room_type_name_en = $_POST['room_type_name_en'] ?? '';
    $room_type_name_cn = $_POST['room_type_name_cn'] ?? '';
    $room_type_code = $_POST['room_type_code'] ?? '';
    $description_la = $_POST['description_la'] ?? '';
    $description_en = $_POST['description_en'] ?? '';
    $description_cn = $_POST['description_cn'] ?? '';

    // Also update original columns for backward compatibility
    $room_type_name = $room_type_name_la;
    $description = $description_la;

    $stmt = $pdo->prepare("UPDATE room_types SET room_type_name = ?, room_type_name_la = ?, room_type_name_en = ?, room_type_name_cn = ?, room_type_code = ?, description = ?, description_la = ?, description_en = ?, description_cn = ? WHERE id = ?");
    if ($stmt->execute([$room_type_name, $room_type_name_la, $room_type_name_en, $room_type_name_cn, $room_type_code, $description, $description_la, $description_en, $description_cn, $id])) {
        logActivity($pdo, "ແກ້ໄຂປະເພດຫ້ອງ", "ປະເພດຫ້ອງ: $room_type_name_la ($room_type_code)");
        $_SESSION['success'] = $lang['save_success'];
        header("Location: form_room_types.php");
        exit();
    } else {
        $_SESSION['error'] = $lang['error_occurred'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['edit'] . ' ' . $lang['room_types']; ?></title>
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
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; overflow: hidden; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card card-warning card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-edit mr-1"></i> <?php echo $lang['edit'] . ' ' . $lang['room_types']; ?></h3>
                </div>
                <form action="" method="post" id="editRoomTypeForm">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['room_type_code_label']; ?></label>
                            <input type="text" name="room_type_code" id="room_type_code" class="form-control" value="<?php echo htmlspecialchars($room_type['room_type_code'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_type_label']; ?> (Lao) <span class="text-danger">*</span></label>
                            <input type="text" name="room_type_name_la" id="room_type_name_la" class="form-control" value="<?php echo htmlspecialchars($room_type['room_type_name_la'] ?: $room_type['room_type_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><?php echo $lang['details']; ?> (Lao)</label>
                            <textarea name="description_la" id="description_la" class="form-control" rows="2"><?php echo htmlspecialchars($room_type['description_la'] ?: $room_type['description']); ?></textarea>
                        </div>

                    </div>
                    <div class="card-footer bg-white text-center">
                        <button type="submit" name="update" class="btn btn-warning px-5"><i class="fas fa-save mr-1"></i> <?php echo $lang['save']; ?></button>
                        <a href="form_room_types.php" class="btn btn-default px-5 ml-2"><?php echo $lang['cancel']; ?></a>
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
    $('#editRoomTypeForm').on('submit', function(e) {
        var roomTypeNameLa = $('#room_type_name_la').val().trim();
        
        if (roomTypeNameLa === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: '<?php echo $lang['warning_label'] ?? 'ແຈ້ງເຕືອນ'; ?>',
                text: '<?php echo $lang['room_type_required_msg'] ?? 'ກະລຸນາປ້ອນຊື່ປະເພດຫ້ອງ (ພາສາລາວ)!'; ?>',
                confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
            });
            return false;
        }
    });
});
</script>

</body>
</html>
