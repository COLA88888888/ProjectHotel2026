<?php
require_once '../config/session_check.php';
enforcePermission('rooms');
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

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('rooms_edit'));
$can_delete = ($is_admin || hasPermission('rooms_delete'));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການເພີ່ມປະເພດຫ້ອງ!";
        header("Location: form_room_types.php");
        exit();
    }
    $room_type_name_la = $_POST['room_type_name_la'] ?? '';
    $room_type_name_en = $_POST['room_type_name_en'] ?? '';
    $room_type_name_cn = $_POST['room_type_name_cn'] ?? '';
    $room_type_code = $_POST['room_type_code'] ?? '';
    $description_la = $_POST['description_la'] ?? '';
    $description_en = $_POST['description_en'] ?? '';
    $description_cn = $_POST['description_cn'] ?? '';

    // Also update the original columns for backward compatibility
    $room_type_name = $room_type_name_la;
    $description = $description_la;

    $stmt = $pdo->prepare("INSERT INTO room_types (room_type_name, room_type_name_la, room_type_name_en, room_type_name_cn, room_type_code, description, description_la, description_en, description_cn) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$room_type_name, $room_type_name_la, $room_type_name_en, $room_type_name_cn, $room_type_code, $description, $description_la, $description_en, $description_cn])) {
        logActivity($pdo, "ເພີ່ມປະເພດຫ້ອງໃໝ່", "ປະເພດຫ້ອງ: $room_type_name_la ($room_type_code)");
        $_SESSION['success'] = $lang['save_success'];
        header("Location: form_room_types.php");
        exit();
    } else {
        $_SESSION['error'] = $lang['error_occurred'];
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບປະເພດຫ້ອງ!";
        header("Location: form_room_types.php");
        exit();
    }
    $id = $_GET['delete'];
    
    // First, check if this room type is being used by any rooms
    $stmtCheck = $pdo->prepare("SELECT room_type_name FROM room_types WHERE id = ?");
    $stmtCheck->execute([$id]);
    $type = $stmtCheck->fetch();
    
    if ($type) {
        $typeName = $type['room_type_name'];
        $stmtRoom = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_type = ?");
        $stmtRoom->execute([$typeName]);
        $count = $stmtRoom->fetchColumn();
        
        if ($count > 0) {
            $_SESSION['error'] = "ບໍ່ສາມາດລົບໄດ້! ເພາະມີຫ້ອງຈຳນວນ $count ຫ້ອງ ທີ່ກຳລັງໃຊ້ປະເພດນີ້ຢູ່.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM room_types WHERE id = ?");
            if ($stmt->execute([$id])) {
                logActivity($pdo, "ລຶບປະເພດຫ້ອງ", "ລຶບປະເພດຫ້ອງ: $typeName");
                $_SESSION['success'] = $lang['delete_success'];
            } else {
                $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດໃນການລົບ";
            }
        }
    }
    header("Location: form_room_types.php");
    exit();
}

$stmt = $pdo->query("SELECT * FROM room_types ORDER BY id DESC");
$room_types = $stmt->fetchAll();

$name_col = "room_type_name_" . $current_lang;
$desc_col = "description_" . $current_lang;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['room_types']; ?></title>
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
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; font-size: 0.9rem; }
        
        /* Table compact styles */
        .table { font-size: 0.82rem !important; }
        .table thead th { font-size: 0.78rem !important; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 8px !important; }
        .table tbody td { padding: 8px 8px !important; vertical-align: middle !important; }
        
        /* Prevent unnecessary scrollbar on desktop */
        @media (min-width: 768px) {
            .table-responsive { overflow-x: hidden !important; }
        }
        
        .btn-warning { background: transparent !important; border: none !important; color: #ffc107 !important; font-size: 1rem !important; padding: 0 6px !important; box-shadow: none !important; }
        .btn-danger { background: transparent !important; border: none !important; color: #dc3545 !important; font-size: 1rem !important; padding: 0 6px !important; box-shadow: none !important; }
        .btn-warning:hover, .btn-danger:hover { opacity: 0.7; }
    </style>
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
        <div class="col-md-4">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> <?php echo $lang['add_room_type']; ?></h3>
                </div>
                <form action="" method="post" id="roomTypeForm">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['room_type_code_label']; ?></label>
                            <input type="text" name="room_type_code" id="room_type_code" class="form-control" placeholder="<?php echo $lang['room_type_code_label']; ?>...">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['room_type_label']; ?> (Lao) <span class="text-danger">*</span></label>
                            <input type="text" name="room_type_name_la" id="room_type_name_la" class="form-control" placeholder="Lao..." required>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['details']; ?> (Lao)</label>
                            <textarea name="description_la" id="description_la" class="form-control" rows="2" placeholder="Lao..."></textarea>
                        </div>


                    </div>
                    <div class="card-footer bg-white border-top-0">
                        <button type="submit" name="save" class="btn btn-primary px-4"><i class="fas fa-save mr-1"></i> <?php echo $lang['save']; ?></button>
                        <button type="reset" class="btn btn-default px-4 ml-2"><?php echo $lang['cancel']; ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="<?php echo $can_edit ? 'col-md-8' : 'col-md-12'; ?>">
            <div class="card card-outline card-info shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-list"></i> <?php echo $lang['room_type_list']; ?></h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="roomTypeTable" class="table table-bordered table-striped table-hover text-center w-100">
                            <thead class="bg-light">
                                <tr>
                                    <th width="50">#</th>
                                    <th><?php echo $lang['room_type_code_label']; ?></th>
                                    <th class="text-left"><?php echo $lang['room_type_label']; ?></th>
                                    <th class="text-left"><?php echo $lang['details']; ?></th>
                                    <th width="120"><?php echo $lang['action']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($room_types) > 0): ?>
                                    <?php $i = 1; foreach ($room_types as $row): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><span class="badge badge-secondary py-1 px-2"><?php echo htmlspecialchars($row['room_type_code'] ?? '-'); ?></span></td>
                                            <td class="text-left"><strong class="text-primary"><?php echo htmlspecialchars($row[$name_col] ?: $row['room_type_name']); ?></strong></td>
                                            <td class="text-left text-muted small"><?php echo htmlspecialchars($row[$desc_col] ?: $row['description']); ?></td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <?php if ($can_edit): ?>
                                                    <a href="edit_room_type.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning" title="<?php echo $lang['edit']; ?>"><i class="fas fa-edit"></i></a>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete): ?>
                                                    <a href="#" class="btn btn-sm btn-danger btn-delete" data-id="<?php echo $row['id']; ?>" title="<?php echo $lang['delete']; ?>"><i class="fas fa-trash-alt"></i></a>
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
                                        <td colspan="5" class="text-center text-muted py-4"><?php echo $lang['table_zero_records']; ?></td>
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
<!-- DataTables  & Plugins -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="../plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="../plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable with dynamic language
    $('#roomTypeTable').DataTable({
        "language": {
            "sLengthMenu":   "<?php echo $lang['dt_length']; ?>",
            "sZeroRecords":  "<?php echo $lang['dt_zeroRecords']; ?>",
            "sInfo":         "<?php echo $lang['dt_info']; ?>",
            "sSearch":       "<?php echo $lang['dt_search']; ?>",
            "oPaginate": { "sPrevious": "<?php echo $lang['dt_paginate_previous']; ?>", "sNext": "<?php echo $lang['dt_paginate_next']; ?>" }
        },
        "autoWidth": false,
        "responsive": false,
        "pageLength": 10
    });

    $('#roomTypeForm').on('submit', function(e) {
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

    // Delete Confirmation
    $('.btn-delete').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({
            title: '<?php echo $lang['confirm_delete']; ?>',
            text: "<?php echo $lang['delete_warning']; ?>",
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
});
</script>
</body>
</html>
