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
// Add User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    if ($_SESSION['status'] !== 'ຜູ້ບໍລິຫານ') {
        $_SESSION['error'] = "ມີແຕ່ຜູ້ບໍລິຫານເທົ່ານັ້ນທີ່ສາມາດເພີ່ມຜູ້ໃຊ້ໄດ້!";
        header("Location: manage_users.php");
        exit();
    }
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $status = $_POST['status'];
    $profile_img = 'default_avatar.png';
    $permissions = json_encode($_POST['permissions'] ?? []);
    // Image Upload
    if (isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] == 0) {
        $ext = pathinfo($_FILES['profile_img']['name'], PATHINFO_EXTENSION);
        $profile_img = 'user_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['profile_img']['tmp_name'], '../UserImg/' . $profile_img);
    }
    $stmt = $pdo->prepare("INSERT INTO users (username, password, fname, lname, phone, email, address, status, profile_img, permissions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$username, $password, $fname, $lname, $phone, $email, $address, $status, $profile_img, $permissions])) {
        logActivity($pdo, "เปเบเบตเปเบกเบเบนเปเปเบเปเปเปเป", "Username: $username, fname: $fname");
        $_SESSION['success'] = $lang['add_user_success'];
    } else {
        $_SESSION['error'] = $lang['error_occurred'];
    }
    header("Location: manage_users.php");
    exit();
}
// Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    if ($_SESSION['status'] !== 'ຜູ້ບໍລິຫານ') {
        $_SESSION['error'] = "ມີແຕ່ຜູ້ບໍລິຫານເທົ່ານັ້ນທີ່ສາມາດແກ້ໄຂຂໍ້ມູນຜູ້ໃຊ້ໄດ້!";
        header("Location: manage_users.php");
        exit();
    }
    $id = (int)$_POST['id'];
    // Fetch old user details before updating
    $stmtOld = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch();
    $username = trim($_POST['username']);
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $status = $_POST['status'];
    $sql = "UPDATE users SET username=?, fname=?, lname=?, phone=?, email=?, address=?, status=? ";
    $params = [$username, $fname, $lname, $phone, $email, $address, $status];
    // Password Update
    if (!empty($_POST['password'])) {
        $sql .= ", password=? ";
        $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }
    // Image Update
    if (isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] == 0) {
        $ext = pathinfo($_FILES['profile_img']['name'], PATHINFO_EXTENSION);
        $profile_img = 'user_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['profile_img']['tmp_name'], '../assets/img/' . $profile_img)) {
            if (!empty($old['profile_img']) && $old['profile_img'] !== 'default_avatar.png' && $old['profile_img'] !== 'default.png' && file_exists('../assets/img/' . $old['profile_img'])) {
                unlink('../assets/img/' . $old['profile_img']);
            }
            $sql .= ", profile_img=? ";
            $params[] = $profile_img;
        }
    }
    $sql .= " WHERE user_id=?";
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute($params)) {
        $changes = [];
        if ($old['username'] !== $username) {
            $changes[] = "Username: '{$old['username']}' -> '{$username}'";
        }
        if ($old['fname'] !== $fname) {
            $changes[] = "เบเบทเป: '{$old['fname']}' -> '{$fname}'";
        }
        if ($old['lname'] !== $lname) {
            $changes[] = "เบเบฒเบกเบชเบฐเบเบธเบ: '{$old['lname']}' -> '{$lname}'";
        }
        if ($old['phone'] !== $phone) {
            $changes[] = "เปเบเบตเปเบ: '{$old['phone']}' -> '{$phone}'";
        }
        if ($old['email'] !== $email) {
            $changes[] = "เบญเบตเปเบกเบง: '{$old['email']}' -> '{$email}'";
        }
        if ($old['address'] !== $address) {
            $changes[] = "เบเบตเปเบขเบนเป: '{$old['address']}' -> '{$address}'";
        }
        if ($old['status'] !== $status) {
            $changes[] = "เบเบปเบเบเบฒเบ: '{$old['status']}' -> '{$status}'";
        }
        if (!empty($_POST['password'])) {
            $changes[] = "เบฅเบฐเบซเบฑเบเบเปเบฒเบ: เบเบทเบเบเปเบฝเบเปเบเบ";
        }
        $details = "เปเบเปเปเบเบเปเปเบกเบนเบเบเบนเปเปเบเป '$username'";
        if (!empty($changes)) {
            $details .= " (" . implode(', ', $changes) . ")";
        } else {
            $details .= " (เบเปเปเบกเบตเบเบฒเบเบเปเบฝเบเปเบเบเบเปเปเบกเบนเบ)";
        }
        logActivity($pdo, "เปเบเปเปเบเบเปเปเบกเบนเบเบเบนเปเปเบเป", $details);
        $_SESSION['success'] = $lang['edit_user_success'];
    }
    header("Location: manage_users.php");
    exit();
}
// Delete User
if (isset($_GET['delete'])) {
    if ($_SESSION['status'] !== 'ຜູ້ບໍລິຫານ') {
        $_SESSION['error'] = "ມີແຕ່ຜູ້ບໍລິຫານເທົ່ານັ້ນທີ່ສາມາດລຶບຜູ້ໃຊ້ໄດ້!";
        header("Location: manage_users.php");
        exit();
    }
    $id = (int)$_GET['delete'];
    // Prevent deleting the main admin
    if ($id == 1) {
        $_SESSION['error'] = $lang['cannot_delete_admin'];
    } else {
        // Fetch details before delete
        $stmtOld = $pdo->prepare("SELECT username, fname, profile_img FROM users WHERE user_id = ?");
        $stmtOld->execute([$id]);
        $u = $stmtOld->fetch();
        $old_user = $u['username'] ?? '';
        $old_fname = $u['fname'] ?? '';
        if (!empty($u['profile_img']) && $u['profile_img'] !== 'default_avatar.png' && $u['profile_img'] !== 'default.png' && file_exists('../assets/img/' . $u['profile_img'])) {
            unlink('../assets/img/' . $u['profile_img']);
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        if ($stmt->execute([$id])) {
            logActivity($pdo, "เบฅเบถเบเบเบนเปเปเบเป", "เบฅเบถเบเบเบนเปเปเบเป '@$old_user' (เบเบทเป: $old_fname)");
            $_SESSION['success'] = $lang['delete_user_success'];
        }
    }
    header("Location: manage_users.php");
    exit();
}
// Fetch all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY user_id DESC");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['user_management_title']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 10px; }
        .avatar { width: 50px; height: 50px; object-fit: cover; border-radius: 50%; border: 2px solid #ddd; }
        .avatar-lg { width: 100px; height: 100px; object-fit: cover; border-radius: 50%; border: 3px solid #007bff; margin-bottom: 15px; }
        .btn-edit { background: transparent !important; border: none !important; color: #ffc107 !important; font-size: 1.15rem; padding: 0 8px; box-shadow: none !important; }
        .btn-delete { background: transparent !important; border: none !important; color: #dc3545 !important; font-size: 1.15rem; padding: 0 8px; box-shadow: none !important; }
        .btn-edit:hover, .btn-delete:hover { opacity: 0.7; }
        /* Desktop table */
        .desktop-table { display: block; }
        .mobile-cards { display: none; }
        /* User Card for Mobile */
        .user-card { border-radius: 12px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 12px; overflow: hidden; transition: transform 0.2s; }
        .user-card:active { transform: scale(0.98); }
        .user-card .card-body { padding: 15px; }
        .user-card .user-card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .user-card .avatar-mobile { width: 55px; height: 55px; object-fit: cover; border-radius: 50%; border: 3px solid #5DADE2; }
        .user-card .user-info { flex: 1; min-width: 0; }
        .user-card .user-name { font-weight: 700; font-size: 1rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-card .user-username { color: #5DADE2; font-size: 0.85rem; font-weight: 600; }
        .user-card .detail-row { display: flex; align-items: flex-start; gap: 8px; padding: 5px 0; font-size: 0.88rem; color: #555; border-top: 1px solid #f0f0f0; }
        .user-card .detail-row i { width: 18px; text-align: center; margin-top: 3px; color: #5DADE2; }
        .user-card .card-actions { display: flex; gap: 15px; margin-top: 12px; justify-content: center; }
        .user-card .card-actions .btn { flex: none; border-radius: 0; font-size: 1.3rem; padding: 5px 15px; }
        .page-header h2 { font-size: 1.4rem; }
        /* Tablet & Mobile */
        @media (max-width: 991px) {
            .desktop-table { display: none !important; }
            .mobile-cards { display: block !important; }
            .page-header h2 { font-size: 1.15rem; }
            .page-header .btn { font-size: 0.85rem; padding: 6px 14px; }
        }
        @media (max-width: 576px) {
            body { padding: 6px; }
            .page-header { flex-direction: column; gap: 10px; }
            .page-header .col-text-right { text-align: left !important; }
            .page-header h2 { font-size: 1.05rem; }
            .modal-dialog { margin: 10px; }
            .modal-body .row .col-md-6 { margin-bottom: 0; }
            .avatar-lg { width: 80px; height: 80px; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: '<?php echo $lang['ok'] ?? 'Success'; ?>', text: '<?php echo $_SESSION['success']; ?>', showConfirmButton: false, timer: 1500 });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: '<?php echo $lang['error'] ?? 'Error'; ?>', text: '<?php echo $_SESSION['error']; ?>' });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>
    <div class="row mb-3 align-items-center page-header">
        <div class="col">
            <h2><i class="fas fa-users-cog text-primary"></i> <?php echo $lang['user_management_title']; ?></h2>
        </div>
        <div class="col-auto col-text-right">
            <button class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#addModal">
                <i class="fas fa-user-plus"></i> <?php echo $lang['add_user_btn']; ?>
            </button>
        </div>
    </div>
    <!-- ===== DESKTOP TABLE VIEW ===== -->
    <div class="desktop-table">
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover table-striped text-center mb-0">
                <thead class="bg-primary text-white">
                    <tr>
                        <th>ID</th>
                        <th><?php echo $lang['profile_label']; ?></th>
                        <th class="text-left"><?php echo $lang['full_name']; ?></th>
                        <th><?php echo $lang['username_label']; ?></th>
                        <th><?php echo $lang['phone']; ?></th>
                        <th><?php echo $lang['address']; ?></th>
                        <th><?php echo $lang['status']; ?></th>
                        <th><?php echo $lang['action']; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $u): ?>
                        <?php 
                            $img = !empty($u['profile_img']) ? $u['profile_img'] : 'default.png';
                            $img_path = '../assets/img/' . $img;
                            if (!file_exists($img_path)) {
                                $img_path = '../UserImg/default.png';
                            }
                        ?>
                        <tr>
                            <td class="align-middle"><strong>#<?php echo $u['user_id']; ?></strong></td>
                            <td><img src="<?php echo $img_path; ?>" class="avatar shadow-sm"></td>
                            <td class="text-left font-weight-bold align-middle">
                                <?php echo htmlspecialchars($u['fname'] . ' ' . $u['lname']); ?><br>
                                <small class="text-muted"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($u['email']); ?></small>
                            </td>
                            <td class="align-middle text-info font-weight-bold">@<?php echo htmlspecialchars($u['username']); ?></td>
                            <td class="align-middle"><?php echo htmlspecialchars($u['phone']); ?></td>
                            <td class="align-middle text-left"><small><?php echo htmlspecialchars($u['address'] ?? '-'); ?></small></td>
                            <td class="align-middle">
                                <?php if($u['status'] == 'ຜູ້ບໍລິຫານ'): ?>
                                    <span class="badge badge-danger px-3 py-2"><i class="fas fa-user-shield"></i> Admin</span>
                                <?php else: ?>
                                    <span class="badge badge-info px-3 py-2"><i class="fas fa-user"></i> Staff</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <?php if ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ'): ?>
                                    <button class="btn btn-sm btn-warning text-white btn-edit shadow-sm"
                                    data-id="<?php echo $u['user_id']; ?>"
                                    data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                    data-fname="<?php echo htmlspecialchars($u['fname']); ?>"
                                    data-lname="<?php echo htmlspecialchars($u['lname']); ?>"
                                    data-phone="<?php echo htmlspecialchars($u['phone']); ?>"
                                    data-email="<?php echo htmlspecialchars($u['email']); ?>"
                                    data-status="<?php echo htmlspecialchars($u['status']); ?>"
                                    data-address="<?php echo htmlspecialchars($u['address'] ?? ''); ?>"
                                    data-img="<?php echo $img_path; ?>"
                                    data-permissions='<?php echo $u['permissions'] ?? '[]'; ?>'
                                    title="<?php echo $lang['edit']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if($u['user_id'] != 1): ?>
                                    <a href="#" class="btn btn-sm btn-danger btn-delete shadow-sm" data-id="<?php echo $u['user_id']; ?>" title="<?php echo $lang['delete']; ?>"><i class="fas fa-trash-alt"></i></a>
                                <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">ເບິ່ງຢ່າງດຽວ</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
    <!-- ===== MOBILE / TABLET CARD VIEW ===== -->
    <div class="mobile-cards">
        <?php foreach($users as $u): ?>
            <?php 
                $img = !empty($u['profile_img']) ? $u['profile_img'] : 'default.png';
                $img_path = '../assets/img/' . $img;
                if (!file_exists($img_path)) {
                    $img_path = '../UserImg/default.png';
                }
            ?>
            <div class="card user-card">
                <div class="card-body">
                    <div class="user-card-header">
                        <img src="<?php echo $img_path; ?>" class="avatar-mobile shadow-sm">
                        <div class="user-info">
                            <p class="user-name"><?php echo htmlspecialchars($u['fname'] . ' ' . $u['lname']); ?> <small class="text-muted">(ID: <?php echo $u['user_id']; ?>)</small></p>
                            <span class="user-username">@<?php echo htmlspecialchars($u['username']); ?></span>
                        </div>
                        <?php if($u['status'] == 'ຜູ້ບໍລິຫານ'): ?>
                            <span class="badge badge-danger py-1 px-2"><i class="fas fa-user-shield"></i> Admin</span>
                        <?php else: ?>
                            <span class="badge badge-info py-1 px-2"><i class="fas fa-user"></i> Staff</span>
                        <?php endif; ?>
                    </div>
                    <div class="detail-row"><i class="fas fa-phone"></i> <span><?php echo htmlspecialchars($u['phone'] ?: '-'); ?></span></div>
                    <div class="detail-row"><i class="fas fa-envelope"></i> <span><?php echo htmlspecialchars($u['email'] ?: '-'); ?></span></div>
                    <div class="detail-row"><i class="fas fa-map-marker-alt"></i> <span><?php echo htmlspecialchars($u['address'] ?? '-'); ?></span></div>
                    <div class="card-actions">
                        <?php if ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ'): ?>
                        <button class="btn btn-warning text-white btn-edit"
                            data-id="<?php echo $u['user_id']; ?>"
                            data-username="<?php echo htmlspecialchars($u['username']); ?>"
                            data-fname="<?php echo htmlspecialchars($u['fname']); ?>"
                            data-lname="<?php echo htmlspecialchars($u['lname']); ?>"
                            data-phone="<?php echo htmlspecialchars($u['phone']); ?>"
                            data-email="<?php echo htmlspecialchars($u['email']); ?>"
                            data-status="<?php echo htmlspecialchars($u['status']); ?>"
                            data-address="<?php echo htmlspecialchars($u['address'] ?? ''); ?>"
                            data-img="<?php echo $img_path; ?>"
                            data-permissions='<?php echo $u['permissions'] ?? '[]'; ?>'
                            title="<?php echo $lang['edit']; ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if($u['user_id'] != 1): ?>
                            <a href="#" class="btn btn-danger btn-delete" data-id="<?php echo $u['user_id']; ?>" title="<?php echo $lang['delete']; ?>"><i class="fas fa-trash-alt"></i></a>
                        <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small">ເບິ່ງຢ່າງດຽວ</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<!-- Add Modal -->
<div class="modal fade" id="addModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> <?php echo $lang['add_user_btn']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <form action="" method="post" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="text-center">
                  <img id="preview_add" src="../UserImg/default.png" class="avatar-lg shadow-sm">
                  <div class="mb-3">
                      <label class="btn btn-sm btn-outline-primary cursor-pointer">
                          <i class="fas fa-camera"></i> <?php echo $lang['choose_profile_img']; ?>
                          <input type="file" name="profile_img" class="d-none" accept="image/*" onchange="document.getElementById('preview_add').src = window.URL.createObjectURL(this.files[0])">
                      </label>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['first_name']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="fname" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['last_name']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="lname" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['username_label']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="username" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['password_label']; ?> <span class="text-danger">*</span></label>
                      <input type="password" name="password" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['phone']; ?></label>
                      <input type="text" name="phone" class="form-control">
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['email_label']; ?></label>
                      <input type="email" name="email" class="form-control">
                      <div class="col-md-12 form-group">
                      <label><?php echo $lang['role_label']; ?> <span class="text-danger">*</span></label>
                      <select name="status" class="form-control mb-3" required>
                          <option value="ພະນັກງານ"><?php echo $lang['staff_role']; ?> (Staff)</option>
                          <option value="ຜູ້ບໍລິຫານ"><?php echo $lang['admin_role']; ?> (Admin)</option>
                      </select>
                  </div>
              </div>
          </div>
          <div class="modal-footer bg-light">
            <button type="submit" name="add_user" class="btn btn-primary px-4"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
          </div>
      </form>
    </div>
  </div>
</div>
<!-- Edit Modal -->
<div class="modal fade" id="editModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning text-white">
        <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit_user_title']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <form action="" method="post" enctype="multipart/form-data">
          <div class="modal-body">
              <input type="hidden" name="id" id="edit_id">
              <div class="text-center">
                  <img id="preview_edit" src="" class="avatar-lg shadow-sm">
                  <div class="mb-3">
                      <label class="btn btn-sm btn-outline-warning cursor-pointer">
                          <i class="fas fa-camera"></i> <?php echo $lang['change_profile_img']; ?>
                          <input type="file" name="profile_img" class="d-none" accept="image/*" onchange="document.getElementById('preview_edit').src = window.URL.createObjectURL(this.files[0])">
                      </label>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['first_name']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="fname" id="edit_fname" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['last_name']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="lname" id="edit_lname" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['username_label']; ?> <span class="text-danger">*</span></label>
                      <input type="text" name="username" id="edit_username" class="form-control" required>
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['password_label']; ?> (<?php echo $lang['password_placeholder_edit']; ?>)</label>
                      <input type="password" name="password" class="form-control" placeholder="****">
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['phone']; ?></label>
                      <input type="text" name="phone" id="edit_phone" class="form-control">
                  </div>
                  <div class="col-md-6 form-group">
                      <label><?php echo $lang['email_label']; ?></label>
                      <input type="email" name="email" id="edit_email" class="form-control">
                  </div>
                  <div class="col-md-12 form-group">
                      <label><i class="fas fa-map-marker-alt text-danger"></i> <?php echo $lang['address']; ?></label>
                      <textarea name="address" id="edit_address" class="form-control" rows="2" placeholder="<?php echo $lang['enter_address']; ?>"></textarea>
                  </div>
                  <div class="col-md-12 form-group">
                      <label><?php echo $lang['role_label']; ?> <span class="text-danger">*</span></label>
                      <select name="status" id="edit_status" class="form-control mb-3" required>
                          <option value="ພະນັກງານ"><?php echo $lang['staff_role']; ?> (Staff)</option>
                          <option value="ຜູ້ບໍລິຫານ"><?php echo $lang['admin_role']; ?> (Admin)</option>
                      </select>
                  </div>
              </div>
          </div>
          <div class="modal-footer bg-light">
            <button type="submit" name="edit_user" class="btn btn-warning text-white px-4"><i class="fas fa-save"></i> <?php echo $lang['save_changes']; ?></button>
          </div>
      </form>
    </div>
  </div>
</div>
<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<script>
$('.btn-edit').on('click', function() {
    $('#edit_id').val($(this).data('id'));
    $('#edit_fname').val($(this).data('fname'));
    $('#edit_lname').val($(this).data('lname'));
    $('#edit_username').val($(this).data('username'));
    $('#edit_phone').val($(this).data('phone'));
    $('#edit_email').val($(this).data('email'));
    $('#edit_address').val($(this).data('address'));
    $('#edit_status').val($(this).data('status'));
    $('#preview_edit').attr('src', $(this).data('img'));
    $('#editModal').modal('show');
});
$('.btn-delete').on('click', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    Swal.fire({
        title: '<?php echo $lang['delete_user_confirm']; ?>',
        text: '<?php echo $lang['delete_user_warning']; ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo $lang['delete']; ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = "?delete=" + id;
        }
    });
});
</script>
</body>
</html>
