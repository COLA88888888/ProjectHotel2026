<?php
require_once '../config/session_check.php';
enforcePermission('stock');
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('stock_edit'));
$can_delete = ($is_admin || hasPermission('stock_delete'));
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການເພີ່ມປະເພດສິນຄ້າ!";
        header("Location: form_product_categories.php");
        exit();
    }
    $name_la = trim($_POST['name_la'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $name_cn = trim($_POST['name_cn'] ?? '');
    $category_code = trim($_POST['category_code'] ?? '');
    
    // Original column for compatibility
    $name = $name_la;

    if (!empty($name_la)) {
        $stmt = $pdo->prepare("INSERT INTO product_categories (name, name_la, name_en, name_cn, category_code) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $name_la, $name_en, $name_cn, $category_code])) {
            $_SESSION['success'] = $lang['success_label'] ?? "ເພີ່ມປະເພດສິນຄ້າສຳເລັດແລ້ວ!";
        } else {
            $_SESSION['error'] = $lang['error_label'] ?? "ເກີດຂໍ້ຜິດພາດ!";
        }
    }
    header("Location: form_product_categories.php");
    exit();
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂປະເພດສິນຄ້າ!";
        header("Location: form_product_categories.php");
        exit();
    }
    $id = (int)$_POST['id'];
    $name_la = trim($_POST['name_la'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $name_cn = trim($_POST['name_cn'] ?? '');
    $old_name = trim($_POST['old_name'] ?? '');
    $category_code = trim($_POST['category_code'] ?? '');
    
    // Original column for compatibility
    $name = $name_la;
    
    if (!empty($name_la)) {
        $stmt = $pdo->prepare("UPDATE product_categories SET name = ?, name_la = ?, name_en = ?, name_cn = ?, category_code = ? WHERE id = ?");
        if ($stmt->execute([$name, $name_la, $name_en, $name_cn, $category_code, $id])) {
            // Update all products that use this category only if name changed
            if ($name !== $old_name) {
                $updateProducts = $pdo->prepare("UPDATE products SET category = ? WHERE category = ?");
                $updateProducts->execute([$name, $old_name]);
            }
            $_SESSION['success'] = $lang['success_label'] ?? "ແກ້ໄຂປະເພດສິນຄ້າສຳເລັດແລ້ວ!";
        } else {
            $_SESSION['error'] = $lang['error_label'] ?? "ເກີດຂໍ້ຜິດພາດ!";
        }
    }
    header("Location: form_product_categories.php");
    exit();
}

// Handle Delete Category
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບປະເພດສິນຄ້າ!";
        header("Location: form_product_categories.php");
        exit();
    }
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM product_categories WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['success'] = $lang['success_label'] ?? "ລຶບປະເພດສຳເລັດແລ້ວ!";
    }
    header("Location: form_product_categories.php");
    exit();
}

$stmt = $pdo->query("SELECT * FROM product_categories ORDER BY id DESC");
$categories = $stmt->fetchAll();

$name_col = "name_" . $current_lang;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo $lang['manage_product_categories']; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/pages/form_product_categories.css">
</head>
<body>
<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: '<?php echo $lang['success_label'] ?? 'ສຳເລັດ'; ?>', text: '<?php echo $_SESSION['success']; ?>', showConfirmButton: false, timer: 1500 });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>', text: '<?php echo $_SESSION['error']; ?>' });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="fas fa-tags"></i> <?php echo $lang['manage_product_categories']; ?></h2>
        </div>
    </div>

    <div class="row">
        <?php if ($can_edit): ?>
        <div class="col-md-4">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header"><h3 class="card-title"><?php echo $lang['add_new_category']; ?></h3></div>
                <form action="" method="post">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['category_code_label']; ?></label>
                            <input type="text" name="category_code" class="form-control" placeholder="<?php echo $lang['category_code_label']; ?>...">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['category_name_la']; ?></label>
                            <input type="text" name="name_la" class="form-control" placeholder="Lao..." required>
                        </div>

                    </div>
                    <div class="card-footer">
                        <button type="submit" name="add_category" class="btn btn-primary btn-block"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?php echo $can_edit ? 'col-md-8' : 'col-md-12'; ?>">
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header"><h3 class="card-title"><?php echo $lang['category_list']; ?></h3></div>
                <div class="card-body p-0">
                    <table class="table table-striped table-hover text-center">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?php echo $lang['category_code_label']; ?></th>
                                <th class="text-left"><?php echo $lang['category'] ?? 'Category'; ?></th>
                                <th><?php echo $lang['action']; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($categories) > 0): ?>
                                <?php foreach($categories as $index => $c): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($c['category_code'] ?? '-'); ?></span></td>
                                    <td class="text-left font-weight-bold text-primary"><?php echo htmlspecialchars($c[$name_col] ?: $c['name']); ?></td>
                                    <td>
                                        <?php if ($can_edit): ?>
                                            <button class="btn btn-sm btn-warning text-white btn-edit" 
                                                data-id="<?php echo $c['id']; ?>" 
                                                data-name-la="<?php echo htmlspecialchars($c['name_la'] ?: $c['name']); ?>"
                                                data-name-en="<?php echo htmlspecialchars($c['name_en'] ?? ''); ?>"
                                                data-name-cn="<?php echo htmlspecialchars($c['name_cn'] ?? ''); ?>"
                                                data-code="<?php echo htmlspecialchars($c['category_code'] ?? ''); ?>"
                                                title="<?php echo $lang['edit']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                            <a href="#" class="btn btn-sm btn-danger btn-delete" data-id="<?php echo $c['id']; ?>" title="<?php echo $lang['delete']; ?>"><i class="fas fa-trash-alt"></i></a>
                                        <?php endif; ?>
                                        <?php if (!$can_edit && !$can_delete): ?>
                                            <span class="text-muted small"><i class="fas fa-lock"></i> ເບິ່ງຢ່າງດຽວ</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4"><?php echo $lang['no_data']; ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning text-white">
        <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form action="" method="post">
          <div class="modal-body">
              <input type="hidden" name="id" id="edit_id">
              <input type="hidden" name="old_name" id="edit_old_name">
              <div class="form-group">
                  <label><?php echo $lang['category_code_label']; ?></label>
                  <input type="text" name="category_code" id="edit_code" class="form-control">
              </div>
              <div class="form-group">
                  <label><?php echo $lang['category_name_la']; ?></label>
                  <input type="text" name="name_la" id="edit_name_la" class="form-control" required>
              </div>

          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['cancel']; ?></button>
            <button type="submit" name="edit_category" class="btn btn-warning text-white"><?php echo $lang['save']; ?></button>
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
    var id = $(this).data('id');
    var nameLa = $(this).data('name-la');
    var nameEn = $(this).data('name-en');
    var nameCn = $(this).data('name-cn');
    var code = $(this).data('code');
    
    $('#edit_id').val(id);
    $('#edit_name_la').val(nameLa);
    $('#edit_name_en').val(nameEn);
    $('#edit_name_cn').val(nameCn);
    $('#edit_old_name').val(nameLa);
    $('#edit_code').val(code);
    
    $('#editCategoryModal').modal('show');
});

$('.btn-delete').on('click', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    Swal.fire({
        title: '<?php echo $lang['confirm_delete'] ?? 'Confirm?'; ?>',
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
