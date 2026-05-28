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

// Handle update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂປະເພດຫ້ອງ!";
        header("Location: form_room_types.php");
        exit();
    }
    $id = $_POST['id'];
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
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../assets/css/pages/form_room_types.css">
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
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-center">
                        <button type="submit" name="save" class="btn btn-primary px-4 font-weight-bold" style="border-radius: 8px;"><i class="fas fa-save mr-1"></i> <?php echo $lang['save']; ?></button>
                        <button type="reset" class="btn btn-default px-4 font-weight-bold ml-2" style="border-radius: 8px;"><?php echo $lang['cancel']; ?></button>
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
                                                     <a href="#" class="btn btn-sm btn-warning text-white btn-edit-room-type" title="<?php echo $lang['edit']; ?>"
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-code="<?php echo htmlspecialchars($row['room_type_code'] ?? ''); ?>"
                                                        data-name-la="<?php echo htmlspecialchars($row['room_type_name_la'] ?: $row['room_type_name']); ?>"
                                                        data-desc-la="<?php echo htmlspecialchars($row['description_la'] ?: $row['description']); ?>"><i class="fas fa-edit"></i></a>
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

<!-- Edit Room Type Modal -->
<div class="modal fade" id="editRoomTypeModal" tabindex="-1" role="dialog" aria-labelledby="editRoomTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.15);">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title font-weight-bold" id="editRoomTypeModalLabel"><i class="fas fa-edit mr-2"></i> <?php echo $lang['edit'] . ' ' . $lang['room_types']; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="post" id="editRoomTypeModalForm">
                <div class="modal-body p-4 text-left">
                    <input type="hidden" name="id" id="modal_type_id">
                    
                    <div class="row">
                        <!-- Left Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['room_type_code_label'] ?? 'ລະຫັດປະເພດຫ້ອງ'; ?></label>
                                <input type="text" name="room_type_code" id="modal_type_code" class="form-control" style="border-radius: 8px;">
                            </div>
                        </div>
                        
                        <!-- Right Column (col-md-6) -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['room_type_label'] ?? 'ປະເພດຫ້ອງ'; ?> (Lao) <span class="text-danger">*</span></label>
                                <input type="text" name="room_type_name_la" id="modal_type_name_la" class="form-control" required style="border-radius: 8px;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <!-- Full Width Column (col-md-12) for description -->
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="font-weight-bold"><?php echo $lang['details'] ?? 'ລາຍລະອຽດ'; ?> (Lao)</label>
                                <textarea name="description_la" id="modal_description_la" class="form-control" rows="3" style="border-radius: 8px;"></textarea>
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

    // Edit room type modal pop-up and data populating
    $(document).on('click', '.btn-edit-room-type', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var code = $(this).data('code');
        var nameLa = $(this).data('name-la');
        var descLa = $(this).data('desc-la');
        
        $('#modal_type_id').val(id);
        $('#modal_type_code').val(code);
        $('#modal_type_name_la').val(nameLa);
        $('#modal_description_la').val(descLa);
        
        $('#editRoomTypeModal').modal('show');
    });

    // Validate Edit Room Type Modal Form
    $('#editRoomTypeModalForm').on('submit', function(e) {
        var roomTypeNameLa = $('#modal_type_name_la').val().trim();
        
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
