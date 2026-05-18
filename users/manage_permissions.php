<?php
require_once '../config/session_check.php';
enforcePermission('users');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// Handle Permission Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_permissions'])) {
    if ($_SESSION['status'] !== 'ຜູ້ບໍລິຫານ') {
        $_SESSION['error'] = "ມີແຕ່ຜູ້ບໍລິຫານເທົ່ານັ້ນທີ່ສາມາດກຳນົດສິດທິໄດ້!";
        header("Location: manage_permissions.php");
        exit();
    }
    $target_user_id = (int)$_POST['target_user_id'];
    $selected_permissions = $_POST['permissions'] ?? [];
    
    // Convert to JSON array
    $permissions_json = json_encode($selected_permissions);

    // Fetch user details for logging
    $stmtUser = $pdo->prepare("SELECT username, fname, lname FROM users WHERE user_id = ?");
    $stmtUser->execute([$target_user_id]);
    $u = $stmtUser->fetch();

    if ($u) {
        // Enforce safety: Prevent updating the primary administrator (ID 1)
        if ($target_user_id === 1) {
            $_SESSION['error'] = "ບໍ່ສາມາດແກ້ໄຂສິດທິຂອງຜູ້ບໍລິຫານສູງສຸດໄດ້!";
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE users SET permissions = ? WHERE user_id = ?");
            if ($stmtUpdate->execute([$permissions_json, $target_user_id])) {
                logActivity($pdo, "ກຳນົດສິດການໃຊ້ງານ", "ກຳນົດສິດໃຫ້ '@" . $u['username'] . "' (ຊື່: " . $u['fname'] . "): " . $permissions_json);
                $_SESSION['success'] = "ກຳນົດສິດທິໃຫ້ " . $u['fname'] . " ສຳເລັດແລ້ວ!";
            } else {
                $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດໃນການບັນທຶກສິດທິ!";
            }
        }
    } else {
        $_SESSION['error'] = "ບໍ່ພົບຜູ້ໃຊ້ນີ້!";
    }
    
    header("Location: manage_permissions.php?user_id=" . $target_user_id);
    exit();
}

// Fetch all users except the primary admin (ID 1)
$stmt = $pdo->query("SELECT * FROM users WHERE user_id != 1 ORDER BY user_id DESC");
$users = $stmt->fetchAll();

// Get target user ID from URL if any
$selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$selected_user = null;
$user_perms = [];

if ($selected_user_id > 0) {
    $stmtSelect = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND user_id != 1");
    $stmtSelect->execute([$selected_user_id]);
    $selected_user = $stmtSelect->fetch();
    if ($selected_user) {
        $user_perms = json_decode($selected_user['permissions'] ?? '[]', true);
    }
}

function renderModulePermissions($key, $title, $icon, $color, $desc, $has_edit_delete = true, $user_perms = [], $custom_actions = []) {
    $checked = in_array($key, $user_perms) ? 'checked' : '';
    $edit_checked = in_array($key . '_edit', $user_perms) ? 'checked' : '';
    $delete_checked = in_array($key . '_delete', $user_perms) ? 'checked' : '';
    $disabled_attr = $checked ? '' : 'disabled';
    
    echo '
    <div class="col-md-6 mb-3">
        <div class="card shadow-sm border" style="border-radius: 10px; overflow: hidden; background: #fff; border-color: #e9ecef !important;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="' . $icon . ' ' . $color . ' mr-2" style="font-size: 1.2rem; width: 24px; text-align: center;"></i>
                        <div>
                            <h6 class="m-0 font-weight-bold text-dark" style="font-size: 0.88rem;">' . $title . '</h6>
                            <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1.2;">' . $desc . '</small>
                        </div>
                    </div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" name="permissions[]" value="' . $key . '" class="custom-control-input main-switch" id="p_' . $key . '" ' . $checked . ' data-target="' . $key . '">
                        <label class="custom-control-label" for="p_' . $key . '"></label>
                    </div>
                </div>';
                
    if ($has_edit_delete || !empty($custom_actions)) {
        echo '<div class="sub-permissions-container ' . $key . '-sub mt-2" style="padding-left: 12px; border-left: 3px solid #007bff; margin-left: 5px; ' . ($checked ? '' : 'opacity: 0.5;') . '">';
        
        if ($has_edit_delete) {
            echo '
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="text-warning font-weight-bold" style="font-size: 0.75rem;"><i class="fas fa-edit mr-1"></i> ສາມາດແກ້ໄຂ (Can Edit)</span>
                <div class="custom-control custom-switch">
                    <input type="checkbox" name="permissions[]" value="' . $key . '_edit" class="custom-control-input sub-switch ' . $key . '-switch" id="p_' . $key . '_edit" ' . $edit_checked . ' ' . $disabled_attr . '>
                    <label class="custom-control-label" for="p_' . $key . '_edit"></label>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="text-danger font-weight-bold" style="font-size: 0.75rem;"><i class="fas fa-trash-alt mr-1"></i> ສາມາດລຶບ (Can Delete)</span>
                <div class="custom-control custom-switch">
                    <input type="checkbox" name="permissions[]" value="' . $key . '_delete" class="custom-control-input sub-switch ' . $key . '-switch" id="p_' . $key . '_delete" ' . $delete_checked . ' ' . $disabled_attr . '>
                    <label class="custom-control-label" for="p_' . $key . '_delete"></label>
                </div>
            </div>';
        }
        
        if (!empty($custom_actions)) {
            foreach ($custom_actions as $c_key => $c_label) {
                $c_checked = in_array($c_key, $user_perms) ? 'checked' : '';
                echo '
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-info font-weight-bold" style="font-size: 0.75rem;"><i class="fas fa-check-circle mr-1"></i> ' . $c_label . '</span>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" name="permissions[]" value="' . $c_key . '" class="custom-control-input sub-switch ' . $key . '-switch" id="p_' . $c_key . '" ' . $c_checked . ' ' . $disabled_attr . '>
                        <label class="custom-control-label" for="p_' . $c_key . '"></label>
                    </div>
                </div>';
            }
        }
        
        echo '</div>';
    }
    
    echo '
            </div>
        </div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ກຳນົດສິດການໃຊ້ງານ - Permissions</title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Google Fonts Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; font-size: 0.85rem; }
        h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 0; }
        .permission-card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: all 0.3s ease; }
        .permission-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
        .user-item { border-left: 4px solid transparent; cursor: pointer; transition: all 0.25s ease; border-bottom: 1px solid #f2f2f2; }
        .user-item:hover { background-color: rgba(93,173,226,0.08); }
        .user-item.active { background-color: rgba(93,173,226,0.12); border-left-color: #007bff; }
        .user-avatar { width: 34px; height: 34px; object-fit: cover; border-radius: 50%; border: 2px solid #ddd; }
        
        /* Modern Custom Checkbox Switches */
        .switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background-color: #fff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.2s;
        }
        .switch-container:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.05);
        }
        .switch-title {
            font-weight: 600;
            font-size: 0.82rem;
            color: #333;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .switch-desc {
            font-size: 0.72rem;
            color: #777;
            line-height: 1.3;
        }
        
        /* Switch styling */
        .custom-switch .custom-control-label::before {
            height: 1.25rem;
            width: 2.25rem;
            border-radius: 1rem;
        }
        .custom-switch .custom-control-label::after {
            width: calc(1.25rem - 4px);
            height: calc(1.25rem - 4px);
            border-radius: 1rem;
            background-color: #adb5bd;
            transition: transform .25s ease-in-out,background-color .25s ease-in-out,border-color .25s ease-in-out,box-shadow .25s ease-in-out;
        }
        .custom-switch .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(1rem);
            background-color: #fff;
        }
        .custom-switch .custom-control-input:checked ~ .custom-control-label::before {
            background-color: #28a745;
            border-color: #28a745;
        }
        .custom-switch .custom-control-label {
            padding-left: 1.6rem;
            cursor: pointer;
        }
        .perm-section-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0056b3;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 6px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Responsive scaling */
        @media (max-width: 768px) {
            h2 { font-size: 1.05rem !important; }
            .perm-section-title { font-size: 0.85rem !important; }
            .switch-title { font-size: 0.78rem !important; }
            .switch-desc { font-size: 0.65rem !important; }
            .card-header h5 { font-size: 0.85rem !important; }
            .user-item h6 { font-size: 0.78rem !important; }
            .btn-sm-responsive { padding: 4px 8px; font-size: 0.75rem !important; }
            .user-avatar { width: 30px; height: 30px; }
            .user-item { padding: 8px 12px !important; }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper p-3">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: 'ສຳເລັດ', text: '<?php echo $_SESSION['success']; ?>', showConfirmButton: false, timer: 1500 });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: 'ຂໍ້ຜິດພາດ', text: '<?php echo $_SESSION['error']; ?>' });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="container-fluid">
        <!-- Page Title -->
        <div class="row mb-3">
            <div class="col-12 d-flex justify-content-between align-items-center">
                <h2><i class="fas fa-user-shield text-primary"></i> <?php echo $lang['permissions_setting_title']; ?></h2>
                <a href="manage_users.php" class="btn btn-secondary btn-sm shadow-sm btn-sm-responsive">
                    <i class="fas fa-users"></i> <?php echo $lang['users']; ?>
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Left Side: User List Card -->
            <div class="col-md-4 mb-4">
                <div class="card permission-card h-100">
                    <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between py-2">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-users"></i> <?php echo $lang['select_user_label']; ?></h6>
                        <span class="badge badge-light" style="font-size: 0.75rem;"><?php echo count($users); ?> <?php echo $lang['user_unit']; ?></span>
                    </div>
                    <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                        <?php if(count($users) > 0): ?>
                            <?php foreach($users as $u): ?>
                                <?php 
                                    $img = !empty($u['profile_img']) ? $u['profile_img'] : 'default.png';
                                    $img_path = '../assets/img/' . $img;
                                    if (!file_exists($img_path)) {
                                        $img_path = '../UserImg/default.png';
                                    }
                                    $active_class = ($u['user_id'] == $selected_user_id) ? 'active' : '';
                                ?>
                                <div class="user-item p-3 d-flex align-items-center gap-3 <?php echo $active_class; ?>" onclick="location.href='?user_id=<?php echo $u['user_id']; ?>'">
                                    <img src="<?php echo $img_path; ?>" class="user-avatar shadow-sm mr-3">
                                    <div class="flex-grow-1">
                                        <h6 class="m-0 font-weight-bold"><?php echo htmlspecialchars($u['fname'] . ' ' . $u['lname']); ?></h6>
                                        <small class="text-info font-weight-bold">@<?php echo htmlspecialchars($u['username']); ?></small>
                                    </div>
                                    <div>
                                        <?php if($u['status'] == 'ຜູ້ບໍລິຫານ'): ?>
                                            <span class="badge badge-danger">Admin</span>
                                        <?php else: ?>
                                            <span class="badge badge-info">Staff</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-user-slash fa-3x mb-3"></i>
                                <p><?php echo $lang['no_users_found']; ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Side: Permissions Configurations Grid -->
            <div class="col-md-8 mb-4">
                <?php if($selected_user): ?>
                    <?php 
                        $img = !empty($selected_user['profile_img']) ? $selected_user['profile_img'] : 'default.png';
                        $img_path = '../assets/img/' . $img;
                        if (!file_exists($img_path)) {
                            $img_path = '../UserImg/default.png';
                        }
                    ?>
                    <form action="" method="post">
                        <input type="hidden" name="target_user_id" value="<?php echo $selected_user['user_id']; ?>">
                        
                        <div class="card permission-card">
                            <div class="card-header bg-warning text-white py-2 d-flex align-items-center gap-2">
                                <img src="<?php echo $img_path; ?>" class="user-avatar mr-2 shadow-sm" style="width: 32px; height: 32px;">
                                <div>
                                    <h6 class="m-0 font-weight-bold"><?php echo $lang['setting_permissions_for']; ?>: <?php echo htmlspecialchars($selected_user['fname'] . ' ' . $selected_user['lname']); ?></h6>
                                    <small class="text-white-50" style="font-size: 0.72rem;">Username: @<?php echo htmlspecialchars($selected_user['username']); ?> | Role: <?php echo htmlspecialchars($selected_user['status']); ?></small>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <div class="row">
                                    <?php
                                    // 1. Bookings
                                    renderModulePermissions('bookings', $lang['bookings'], 'fas fa-calendar-alt', 'text-primary', $lang['perm_bookings_desc'], true, $user_perms);
                                    
                                    // 2. Check-in
                                    renderModulePermissions('walkin', $lang['check_in'], 'fas fa-door-open', 'text-success', $lang['perm_walkin_desc'], true, $user_perms);
                                    
                                    // 3. Check-out
                                    renderModulePermissions('checkout', $lang['check_out'], 'fas fa-receipt', 'text-danger', $lang['perm_checkout_desc'], true, $user_perms);
                                    
                                    // 4. Room Service
                                    renderModulePermissions('room_service', $lang['room_service'], 'fas fa-bell', 'text-info', $lang['perm_room_service_desc'], true, $user_perms);
                                    
                                    // 5. POS Sales
                                    renderModulePermissions('pos', $lang['pos'], 'fas fa-cash-register', 'text-warning', $lang['perm_pos_desc'], false, $user_perms, [
                                        'can_sell' => 'ສາມາດກົດຂາຍສິນຄ້າ (Can Sell)',
                                        'can_void' => 'ສາມາດຍົກເລີກບິນຂາຍ (Can Void Bills)'
                                    ]);
                                    
                                    // 6. Product Stock
                                    renderModulePermissions('stock', $lang['stock'], 'fas fa-boxes', 'text-purple', $lang['perm_stock_desc'], true, $user_perms);
                                    
                                    // 7. Manage Rooms
                                    renderModulePermissions('rooms', $lang['manage_rooms'], 'fas fa-hotel', 'text-cyan', $lang['perm_rooms_desc'], true, $user_perms);
                                    
                                    // 8. Financial Reports
                                    renderModulePermissions('report', $lang['financial_report'], 'fas fa-chart-bar', 'text-maroon', $lang['perm_report_desc'], false, $user_perms);
                                    
                                    // 9. User & Permissions Management
                                    renderModulePermissions('users', $lang['users'], 'fas fa-users-cog', 'text-indigo', $lang['perm_users_desc'], true, $user_perms);
                                    
                                    // 10. General Settings
                                    renderModulePermissions('settings', $lang['settings'], 'fas fa-cogs', 'text-secondary', $lang['perm_settings_desc'], true, $user_perms);
                                    ?>
                                </div>
                            </div>
                            
                            <div class="card-footer bg-light py-3 d-flex justify-content-end">
                                <button type="submit" name="update_permissions" class="btn btn-success px-5 font-weight-bold shadow-sm">
                                    <i class="fas fa-save mr-2"></i><?php echo $lang['save_permissions_btn']; ?>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="card permission-card h-100 d-flex flex-column justify-content-center align-items-center text-center p-5 bg-white">
                        <div class="my-5">
                            <i class="fas fa-shield-alt fa-5x text-muted mb-4"></i>
                            <h4 class="text-muted font-weight-bold"><?php echo $lang['select_user_first_msg']; ?></h4>
                            <p class="text-muted col-md-8 mx-auto"><?php echo $lang['select_user_first_desc']; ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<script>
$(function() {
    $('.main-switch').on('change', function() {
        let moduleKey = $(this).data('target');
        let isChecked = $(this).is(':checked');
        let $subContainer = $('.' + moduleKey + '-sub');
        let $subSwitches = $('.' + moduleKey + '-switch');
        
        if (isChecked) {
            $subContainer.css('opacity', '1');
            $subSwitches.prop('disabled', false);
        } else {
            $subContainer.css('opacity', '0.5');
            $subSwitches.prop('disabled', true).prop('checked', false);
        }
    });
});
</script>
</body>
</html>
