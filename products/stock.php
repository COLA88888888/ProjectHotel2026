<?php
require_once '../config/session_check.php';
enforcePermission('stock');
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
$can_edit = ($is_admin || hasPermission('stock_edit'));
$can_delete = ($is_admin || hasPermission('stock_delete'));

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $prod_code = trim($_POST['prod_code']);
    $prod_name_la = trim($_POST['prod_name_la'] ?? '');
    $prod_name_en = trim($_POST['prod_name_en'] ?? '');
    $prod_name_cn = trim($_POST['prod_name_cn'] ?? '');
    $category = $_POST['category'];
    $qty = (int)$_POST['qty'];
    $unit = $_POST['unit'];
    $bprice = (float)str_replace(',', '', $_POST['bprice']);
    $sprice = (float)str_replace(',', '', $_POST['sprice']);
    
    // Original column for compatibility
    $prod_name = $prod_name_la;

    $image = '';
    // ... (image upload code remains same) ...
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $filesize = $_FILES['image']['size'];
        if ($filesize > 2 * 1024 * 1024) {
            $_SESSION['error'] = "ຂະໜາດຮູບພາບໃຫຍ່ເກີນໄປ! ກະລຸນາເລືອກຮູບທີ່ຂະໜາດບໍ່ເກີນ 2MB";
            header("Location: stock.php");
            exit();
        }
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif', 'avif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newname = uniqid() . '.' . $ext;
            $upload_dir = '../assets/img/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $newname)) {
                $image = $newname;
            } else {
                $_SESSION['error'] = $lang['error_label'] ?? "ບໍ່ສາມາດຍ້າຍໄຟລ໌ໄປຍັງ Folder ໄດ້! ກວດສອບ Permissions.";
            }
        } else {
            $_SESSION['error'] = $lang['error_label'] ?? "ນາມສະກຸນໄຟລ໌ (.$ext) ບໍ່ໄດ້ຮັບອະນຸຍາດ! (ອະນຸຍາດ: jpg, png, webp, jfif)";
        }
    }

    $stmt = $pdo->prepare("INSERT INTO products (prod_code, prod_name, prod_name_la, prod_name_en, prod_name_cn, category, image, qty, unit, bprice, sprice) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$prod_code, $prod_name, $prod_name_la, $prod_name_en, $prod_name_cn, $category, $image, $qty, $unit, $bprice, $sprice])) {
        // Record Expense
        $expense_amount = $qty * $bprice;
        if ($expense_amount > 0) {
            $stmtExp = $pdo->prepare("INSERT INTO expenses (expense_title, amount, expense_date) VALUES (?, ?, CURDATE())");
            $stmtExp->execute(["[Stock] ຊື້ສິນຄ້າໃໝ່: " . $prod_name_la, $expense_amount]);
        }
        
        logActivity($pdo, "ເພີ່ມສິນຄ້າໃໝ່", "ຊື່: $prod_name_la, ຈຳນວນ: $qty $unit");
        
        $_SESSION['success'] = $lang['ok'];
        header("Location: stock.php");
        exit();
    } else {
        $_SESSION['error'] = $lang['error_label'] ?? "ເກີດຂໍ້ຜິດພາດໃນການເພີ່ມຂໍ້ມູນ!";
    }
}

// Handle Edit Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂຂໍ້ມູນສິນຄ້າ!";
        header("Location: stock.php");
        exit();
    }
    $prod_id = (int)$_POST['prod_id'];
    
    // Fetch old details before update to compare what was edited
    $stmtOld = $pdo->prepare("SELECT * FROM products WHERE prod_id = ?");
    $stmtOld->execute([$prod_id]);
    $old = $stmtOld->fetch();
    $prod_code = trim($_POST['prod_code']);
    $prod_name_la = trim($_POST['prod_name_la'] ?? '');
    $prod_name_en = trim($_POST['prod_name_en'] ?? '');
    $prod_name_cn = trim($_POST['prod_name_cn'] ?? '');
    $category = $_POST['category'];
    $unit = $_POST['unit'];
    $bprice = (float)str_replace(',', '', $_POST['bprice']);
    $sprice = (float)str_replace(',', '', $_POST['sprice']);
    
    // Original column
    $prod_name = $prod_name_la;

    // Check if new image is uploaded
    $image_query = "";
    $params = [$prod_code, $prod_name, $prod_name_la, $prod_name_en, $prod_name_cn, $category, $unit, $bprice, $sprice];
    
    if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] == 0) {
        $filesize = $_FILES['edit_image']['size'];
        if ($filesize > 2 * 1024 * 1024) {
            $_SESSION['error'] = "ຂະໜາດຮູບພາບໃຫຍ່ເກີນໄປ! ກະລຸນາເລືອກຮູບທີ່ຂະໜາດບໍ່ເກີນ 2MB";
            header("Location: stock.php");
            exit();
        }
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif', 'avif'];
        $filename = $_FILES['edit_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newname = uniqid() . '.' . $ext;
            $upload_dir = '../assets/img/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            if (move_uploaded_file($_FILES['edit_image']['tmp_name'], $upload_dir . $newname)) {
                
                // Get old image to delete
                $stmtOld = $pdo->prepare("SELECT image FROM products WHERE prod_id = ?");
                $stmtOld->execute([$prod_id]);
                $oldProd = $stmtOld->fetch();
                if ($oldProd && !empty($oldProd['image'])) {
                    $oldPath = $upload_dir . $oldProd['image'];
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }

                $image_query = ", image = ?";
                $params[] = $newname;
            }
        }
    }
    
    $params[] = $prod_id;
    $stmt = $pdo->prepare("UPDATE products SET prod_code = ?, prod_name = ?, prod_name_la = ?, prod_name_en = ?, prod_name_cn = ?, category = ?, unit = ?, bprice = ?, sprice = ? $image_query WHERE prod_id = ?");
    if ($stmt->execute($params)) {
        $changes = [];
        if ($old['prod_code'] !== $prod_code) {
            $changes[] = "ລະຫັດ: '{$old['prod_code']}' -> '{$prod_code}'";
        }
        if ($old['prod_name_la'] !== $prod_name_la) {
            $changes[] = "ຊື່ (LA): '{$old['prod_name_la']}' -> '{$prod_name_la}'";
        }

        if ($old['category'] !== $category) {
            $changes[] = "ໝວດໝູ່: '{$old['category']}' -> '{$category}'";
        }
        if ($old['unit'] !== $unit) {
            $changes[] = "ຫົວໜ່ວຍ: '{$old['unit']}' -> '{$unit}'";
        }
        if ((float)$old['bprice'] !== $bprice) {
            $changes[] = "ລາຄາຊື້: '" . number_format($old['bprice']) . "' -> '" . number_format($bprice) . "'";
        }
        if ((float)$old['sprice'] !== $sprice) {
            $changes[] = "ລາຄາຂາຍ: '" . number_format($old['sprice']) . "' -> '" . number_format($sprice) . "'";
        }

        $details = "ແກ້ໄຂສິນຄ້າ '{$old['prod_name_la']}'";
        if (!empty($changes)) {
            $details .= " (" . implode(', ', $changes) . ")";
        } else {
            $details .= " (ບໍ່ມີການປ່ຽນແປງຂໍ້ມູນ)";
        }

        logActivity($pdo, "ແກ້ໄຂສິນຄ້າ", $details);
        $_SESSION['success'] = $lang['ok'];
    } else {
        $_SESSION['error'] = $lang['error_label'] ?? "ບໍ່ສາມາດແກ້ໄຂໄດ້!";
    }
    header("Location: stock.php");
    exit();
}

// Handle Delete Product
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບຂໍ້ມູນສິນຄ້າ!";
        header("Location: stock.php");
        exit();
    }
    $id = (int)$_GET['delete'];
    
    // Fetch details before delete
    $stmtOld = $pdo->prepare("SELECT prod_name_la, qty, unit, image FROM products WHERE prod_id = ?");
    $stmtOld->execute([$id]);
    $prod = $stmtOld->fetch();
    
    if ($prod && !empty($prod['image'])) {
        $imgPath = '../assets/img/products/' . $prod['image'];
        if (file_exists($imgPath)) {
            unlink($imgPath);
        }
    }
    
    $stmt = $pdo->prepare("DELETE FROM products WHERE prod_id = ?");
    if ($stmt->execute([$id])) {
        $prod_name = $prod['prod_name_la'] ?? '';
        $prod_qty = $prod['qty'] ?? 0;
        $prod_unit = $prod['unit'] ?? '';
        logActivity($pdo, "ລຶບສິນຄ້າ", "ລຶບສິນຄ້າ '{$prod_name}' (ຈຳນວນເຫຼືອຫຼ້າສຸດ: {$prod_qty} {$prod_unit})");
        $_SESSION['success'] = $lang['ok'];
    } else {
        $_SESSION['error'] = $lang['error_label'] ?? "ບໍ່ສາມາດລຶບໄດ້!";
    }
    header("Location: stock.php");
    exit();
}

// Handle Add Stock (Restock)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restock'])) {
    $prod_id = (int)$_POST['prod_id'];
    $add_qty = (int)$_POST['add_qty'];
    
    $stmt = $pdo->prepare("UPDATE products SET qty = qty + ? WHERE prod_id = ?");
    if ($stmt->execute([$add_qty, $prod_id])) {
        
        // Record Expense for Restock
        $stmtProd = $pdo->prepare("SELECT prod_name, bprice FROM products WHERE prod_id = ?");
        $stmtProd->execute([$prod_id]);
        $prod = $stmtProd->fetch();
        if ($prod) {
            $expense_amount = $add_qty * $prod['bprice'];
            if ($expense_amount > 0) {
                $stmtExp = $pdo->prepare("INSERT INTO expenses (expense_title, amount, expense_date) VALUES (?, ?, CURDATE())");
                $stmtExp->execute(["[Stock] ເຕີມສະຕັອກ: " . $prod['prod_name'], $expense_amount]);
            }
        }

        $_SESSION['success'] = $lang['ok'];
        logActivity($pdo, "ເຕີມສະຕັອກສິນຄ້າ", "ສິນຄ້າ: " . ($prod['prod_name'] ?? $prod_id) . ", ຈຳນວນ: +$add_qty");
        header("Location: stock.php");
        exit();
    }
}

// --- ສ່ວນດຶງຂໍ້ມູນສິນຄ້າທັງໝົດ ພ້ອມແປພາສາ ໝວດໝູ່ ແລະ ຫົວໜ່ວຍ (Fetch Localized Products) ---
// 1. ກຳນົດຄຳຕໍ່ທ້າຍຊື່ຄໍລຳ (Column Suffix) ຕາມພາສາທີ່ເລືອກໃນ Session ເພື່ອດຶງຄຳແປຢ່າງຖືກຕ້ອງ
$current_lang = $_SESSION['lang'] ?? 'la';
$prod_name_col = "prod_name_" . $current_lang; // ເຊັ່ນ: prod_name_la, prod_name_en, prod_name_cn
$cat_name_col = "name_" . $current_lang;      // ເຊັ່ນ: name_la, name_en, name_cn
$unit_name_col = "unit_name_" . $current_lang; // ເຊັ່ນ: unit_name_la, unit_name_en, unit_name_cn

// 2. ສ້າງຄຳສັ່ງ SQL Join ຖານຂໍ້ມູນ ເພື່ອດຶງຂໍ້ມູນສິນຄ້າ ພ້ອມທັງແປພາສາໝວດໝູ່ (Product Categories) ແລະ ຫົວໜ່ວຍ (Units) ທັນທີ
$stmt = $pdo->query("SELECT p.*, pc.name_la as cat_la, pc.name_en as cat_en, pc.name_cn as cat_cn,
                            pu.unit_name_la as u_la, pu.unit_name_en as u_en, pu.unit_name_cn as u_cn
                     FROM products p 
                     LEFT JOIN product_categories pc ON p.category = pc.name 
                     LEFT JOIN product_units pu ON p.unit = pu.unit_name
                     ORDER BY p.prod_id DESC");
$products = $stmt->fetchAll();

// Fetch all categories
$stmtCat = $pdo->query("SELECT * FROM product_categories ORDER BY name ASC");
$categories = $stmtCat->fetchAll();

// Fetch all units
$stmtUnit = $pdo->query("SELECT * FROM product_units ORDER BY unit_name ASC");
$units_list = $stmtUnit->fetchAll();

// Low stock report
$stmtLow = $pdo->query("SELECT COUNT(*) as low_stock_count FROM products WHERE qty <= 10");
$low_stock_count = $stmtLow->fetch()['low_stock_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['stock_management_title']; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        .table th, .table td { font-size: 0.85rem !important; vertical-align: middle; }
        .dataTables_wrapper { font-size: 0.85rem !important; }
        .dataTables_empty { font-size: 0.85rem !important; }
        .btn-edit { background: transparent !important; border: none !important; color: #ffc107 !important; font-size: 1.15rem; padding: 0 8px; }
        .btn-restock { background: transparent !important; border: none !important; color: #17a2b8 !important; font-size: 1.15rem; padding: 0 8px; }
        .btn-delete { background: transparent !important; border: none !important; color: #dc3545 !important; font-size: 1.15rem; padding: 0 8px; }
        .btn-edit:hover, .btn-restock:hover, .btn-delete:hover { opacity: 0.7; }
        @media (max-width: 768px) {
            body { padding: 10px; }
            h2 { font-size: 1.2rem; }
            .card-title { font-size: 1rem; }
            .alert { font-size: 0.85rem; padding: 0.5rem 0.75rem !important; }
            .table th, .table td { padding: 0.6rem 0.4rem !important; font-size: 0.8rem !important; }
            .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { font-size: 0.75rem; text-align: center !important; }
            .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter { text-align: left !important; margin-bottom: 10px; }
            .card-body { padding: 0.75rem; }
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
                });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-3 align-items-center">
        <div class="col-sm-6 col-12">
            <h2 class="mb-2"><i class="fas fa-boxes"></i> <?php echo $lang['stock_management_title']; ?></h2>
        </div>
        <div class="col-sm-6 col-12 text-md-right">
            <div class="btn-group shadow-sm mb-2">
                <a href="form_product_categories.php" class="btn btn-outline-primary bg-white"><i class="fas fa-tags"></i> <?php echo $lang['category'] ?? 'Category'; ?></a>
                <a href="form_product_units.php" class="btn btn-outline-info bg-white"><i class="fas fa-balance-scale"></i> <?php echo $lang['unit'] ?? 'Unit'; ?></a>
            </div>
            <?php if($low_stock_count > 0): ?>
                <div class="alert alert-danger d-inline-block py-2 px-3 mb-2 ml-md-2 shadow-sm">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $lang['low_stock_warning']; ?> <strong><?php echo $low_stock_count; ?></strong> <?php echo $lang['total'] ?? 'items'; ?>!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Add Product Form -->
        <div class="col-md-4 col-12 mb-4">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> <?php echo $lang['add_new_product']; ?></h3>
                </div>
                <form action="" method="post" enctype="multipart/form-data">
                    <div class="card-body">
                        <div class="form-group text-center">
                            <label><?php echo $lang['product_image']; ?></label>
                            <input type="file" name="image" id="image" class="form-control-file border p-2" accept=".jpg,.jpeg,.png,.gif,.webp,.jfif,.avif,image/jpeg,image/png,image/gif,image/webp" onchange="previewImage(this)">
                            <img id="preview" src="" alt="Preview" style="max-height: 100px; display: none; margin-top: 10px; border-radius: 4px;" class="shadow-sm">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['product_code']; ?></label>
                            <input type="text" name="prod_code" class="form-control" placeholder="<?php echo $lang['product_code']; ?>...">
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['product_name_la']; ?></label>
                            <input type="text" name="prod_name_la" class="form-control" placeholder="Lao..." required>
                        </div>

                        <div class="form-group">
                            <label><?php echo $lang['category']; ?></label>
                            <select name="category" class="form-control" required>
                                <option value=""><?php echo $lang['select_category']; ?></option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat[$cat_name_col] ?: $cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label><?php echo $lang['stock_in_qty']; ?></label>
                                    <input type="number" name="qty" class="form-control" value="0" min="0" required>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label><?php echo $lang['unit']; ?></label>
                                    <select name="unit" class="form-control" required>
                                        <option value="">-- <?php echo $lang['unit']; ?> --</option>
                                        <?php foreach($units_list as $u): ?>
                                            <option value="<?php echo htmlspecialchars($u['unit_name']); ?>"><?php echo htmlspecialchars($u[$unit_name_col] ?: $u['unit_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label><?php echo $lang['buy_price']; ?></label>
                                    <input type="text" name="bprice" class="form-control number-format" placeholder="0">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label><?php echo $lang['sell_price']; ?></label>
                                    <input type="text" name="sprice" class="form-control number-format" placeholder="0" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" name="add_product" class="btn btn-primary btn-block"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Products List -->
        <div class="col-md-8 col-12">
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-clipboard-list"></i> <?php echo $lang['stock_report_all']; ?></h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="stockTable" class="table table-bordered table-hover text-center w-100">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th><?php echo $lang['image_label']; ?></th>
                                    <th><?php echo $lang['product_code_label']; ?></th>
                                    <th class="text-left"><?php echo $lang['product_name']; ?></th>
                                    <th><?php echo $lang['category']; ?></th>
                                    <th><?php echo $lang['sale_price']; ?></th>
                                    <th><?php echo $lang['stock_remaining'] ?? 'Stock'; ?></th>
                                    <th><?php echo $lang['action']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($products as $index => $row): ?>
                                    <?php 
                                        $profit = $row['sprice'] - $row['bprice']; 
                                        $badgeClass = ($row['qty'] <= 10) ? 'badge-danger' : 'badge-success';
                                    ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <?php if($row['image'] && file_exists('../assets/img/products/' . $row['image'])): ?>
                                                <img src="../assets/img/products/<?php echo htmlspecialchars($row['image']); ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 8px;" class="border shadow-sm">
                                            <?php else: ?>
                                                <img src="../assets/img/image.jpg" style="width: 45px; height: 45px; object-fit: cover; border-radius: 8px;" class="border shadow-sm">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-info shadow-sm py-1 px-2" style="font-size: 0.85rem;">
                                                <i class="fas fa-barcode mr-1"></i> <?php echo htmlspecialchars($row['prod_code'] ?: '-'); ?>
                                            </span>
                                        </td>
                                        <td class="text-left">
                                            <span class="font-weight-bold text-dark"><?php echo htmlspecialchars($row[$prod_name_col] ?: $row['prod_name']); ?></span>
                                        </td>
                                        <td><span class="badge badge-secondary"><?php echo htmlspecialchars($row['cat_'.$current_lang] ?? $row['category'] ?? 'ອື່ນໆ'); ?></span></td>
                                        <td class="text-primary font-weight-bold"><?php echo number_format($row['sprice']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?> p-2" style="min-width: 60px;">
                                                <?php echo $row['qty']; ?> <?php echo htmlspecialchars($row['u_'.$current_lang] ?? $row['unit'] ?? 'ປ໋ອງ'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($can_edit): ?>
                                                <button class="btn btn-warning text-white btn-edit" 
                                                    data-id="<?php echo $row['prod_id']; ?>" 
                                                    data-code="<?php echo htmlspecialchars($row['prod_code']); ?>" 
                                                    data-name-la="<?php echo htmlspecialchars($row['prod_name_la'] ?: $row['prod_name']); ?>" 
                                                    data-name-en="<?php echo htmlspecialchars($row['prod_name_en'] ?? ''); ?>" 
                                                    data-name-cn="<?php echo htmlspecialchars($row['prod_name_cn'] ?? ''); ?>" 
                                                    data-cat="<?php echo htmlspecialchars($row['category']); ?>" 
                                                    data-unit="<?php echo htmlspecialchars($row['unit'] ?? 'ປ໋ອງ'); ?>" 
                                                    data-image="<?php echo htmlspecialchars($row['image']); ?>"
                                                    data-bprice="<?php echo $row['bprice']; ?>" 
                                                    data-sprice="<?php echo $row['sprice']; ?>"
                                                    title="<?php echo $lang['edit']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn btn-info btn-restock" 
                                                    data-id="<?php echo $row['prod_id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($row[$prod_name_col] ?: $row['prod_name']); ?>"
                                                    title="<?php echo $lang['restock_btn']; ?>">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                                <?php if ($can_delete): ?>
                                                <a href="#" class="btn btn-danger btn-delete" 
                                                    data-id="<?php echo $row['prod_id']; ?>"
                                                    title="<?php echo $lang['delete']; ?>">
                                                    <i class="fas fa-trash-alt"></i>
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
        </div>
    </div>
</div>

<!-- Restock Modal -->
<div class="modal fade" id="restockModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><?php echo $lang['restock_title']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form action="" method="post">
          <div class="modal-body">
              <input type="hidden" name="prod_id" id="restock_prod_id">
              <p><?php echo $lang['product_info']; ?>: <strong id="restock_prod_name" class="text-primary"></strong></p>
              <div class="form-group">
                  <label><?php echo $lang['restock_qty']; ?></label>
                  <input type="number" name="add_qty" class="form-control" value="1" min="1" required>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['cancel']; ?></button>
            <button type="submit" name="restock" class="btn btn-info"><?php echo $lang['confirm_restock']; ?></button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning text-white">
        <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form action="" method="post" enctype="multipart/form-data">
          <div class="modal-body">
              <input type="hidden" name="prod_id" id="edit_prod_id">
              <div class="form-group text-center">
                  <label><?php echo $lang['product_image']; ?></label>
                  <input type="file" name="edit_image" id="edit_image" class="form-control-file border p-2" accept=".jpg,.jpeg,.png,.gif,.webp,.jfif,.avif,image/jpeg,image/png,image/gif,image/webp" onchange="previewEditImage(this)">
                  <img id="edit_preview" src="" style="max-height: 120px; display: none; margin-top: 10px; border-radius: 5px;" class="shadow-sm">
              </div>
               <div class="form-group">
                  <label><?php echo $lang['product_code']; ?></label>
                  <input type="text" name="prod_code" id="edit_prod_code" class="form-control">
              </div>
              <div class="form-group">
                  <label><?php echo $lang['product_name_la']; ?></label>
                  <input type="text" name="prod_name_la" id="edit_prod_name_la" class="form-control" required>
              </div>

              <div class="form-group">
                  <label><?php echo $lang['category']; ?></label>
                  <select name="category" id="edit_category" class="form-control" required>
                      <option value=""><?php echo $lang['select_category']; ?></option>
                       <?php foreach($categories as $cat): ?>
                           <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat[$cat_name_col] ?: $cat['name']); ?></option>
                       <?php endforeach; ?>
                  </select>
              </div>
              <div class="form-group">
                  <label><?php echo $lang['unit']; ?></label>
                  <select name="unit" id="edit_unit" class="form-control" required>
                      <option value="">-- <?php echo $lang['unit']; ?> --</option>
                      <?php foreach($units_list as $u): ?>
                          <option value="<?php echo htmlspecialchars($u['unit_name']); ?>"><?php echo htmlspecialchars($u[$unit_name_col] ?: $u['unit_name']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="row">
                  <div class="col-6">
                      <div class="form-group">
                          <label><?php echo $lang['buy_price']; ?></label>
                          <input type="text" name="bprice" id="edit_bprice" class="form-control number-format">
                      </div>
                  </div>
                  <div class="col-6">
                      <div class="form-group">
                          <label><?php echo $lang['sell_price']; ?></label>
                          <input type="text" name="sprice" id="edit_sprice" class="form-control number-format" required>
                      </div>
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['cancel']; ?></button>
            <button type="submit" name="edit_product" class="btn btn-warning text-white"><?php echo $lang['save']; ?></button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {
    $('#stockTable').DataTable({
        "language": {
            "sLengthMenu":   "<?php echo $lang['dt_length']; ?>",
            "sZeroRecords":  "<?php echo $lang['dt_zeroRecords']; ?>",
            "sInfo":         "<?php echo $lang['dt_info']; ?>",
            "sSearch":       "<?php echo $lang['dt_search']; ?>",
            "oPaginate": { "sPrevious": "<?php echo $lang['dt_paginate_previous']; ?>", "sNext": "<?php echo $lang['dt_paginate_next']; ?>" }
        }
    });

    $('.number-format').on('input', function(e) {
        var value = $(this).val().replace(/[^0-9]/g, '');
        if (value !== '') {
            $(this).val(parseInt(value, 10).toLocaleString('en-US'));
        } else {
            $(this).val('');
        }
    });

    $('.btn-restock').on('click', function() {
        $('#restock_prod_id').val($(this).data('id'));
        $('#restock_prod_name').text($(this).data('name'));
        $('#restockModal').modal('show');
    });

    $('.btn-edit').on('click', function() {
        var id = $(this).data('id');
        var code = $(this).data('code');
        var name = $(this).data('name');
        var cat = $(this).data('cat');
        var unit = $(this).data('unit');
        var bprice = parseInt($(this).data('bprice')).toLocaleString('en-US');
        var sprice = parseInt($(this).data('sprice')).toLocaleString('en-US');
        
        $('#edit_prod_id').val(id);
        $('#edit_prod_code').val(code);
        $('#edit_prod_name_la').val($(this).data('name-la'));
        $('#edit_prod_name_en').val($(this).data('name-en'));
        $('#edit_prod_name_cn').val($(this).data('name-cn'));
        $('#edit_unit').val(unit);
        
        // Handle Category Selection Safely
        if ($("#edit_category option[value='" + cat + "']").length > 0) {
            $('#edit_category').val(cat);
        } else if (cat !== '') {
            $('#edit_category').append('<option value="'+cat+'">'+cat+'</option>');
            $('#edit_category').val(cat);
        } else {
            $('#edit_category').val('');
        }
        
        $('#edit_bprice').val(bprice !== 'NaN' ? bprice : '0');
        $('#edit_sprice').val(sprice !== 'NaN' ? sprice : '0');
        
        var currentImg = $(this).data('image');
        if (currentImg) {
            $('#edit_preview').attr('src', '../assets/img/products/' + currentImg).show();
        } else {
            $('#edit_preview').hide();
        }
        
        $('#editProductModal').modal('show');
    });

    $('.btn-delete').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({
            title: '<?php echo $lang['confirm_delete'] ?? 'Confirm?'; ?>',
            text: "<?php echo $lang['delete_user_warning'] ?? 'Are you sure you want to delete this item?'; ?>",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<?php echo $lang['delete']; ?>',
            cancelButtonText: '<?php echo $lang['cancel'] ?? 'Cancel'; ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "?delete=" + id;
            }
        });
    });
});

function previewImage(input) {
    if (input.files && input.files[0]) {
        var fileSize = input.files[0].size / 1024 / 1024;
        if (fileSize > 2) {
            alert('ຂະໜາດຮູບພາບໃຫຍ່ເກີນໄປ! ກະລຸນາເລືອກຮູບທີ່ຂະໜາດບໍ່ເກີນ 2MB ເພື່ອບໍ່ໃຫ້ລະບົບໜັກ.');
            input.value = '';
            $('#preview').hide();
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#preview').attr('src', e.target.result).show();
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function previewEditImage(input) {
    if (input.files && input.files[0]) {
        var fileSize = input.files[0].size / 1024 / 1024;
        if (fileSize > 2) {
            alert('ຂະໜາດຮູບພາບໃຫຍ່ເກີນໄປ! ກະລຸນາເລືອກຮູບທີ່ຂະໜາດບໍ່ເກີນ 2MB ເພື່ອບໍ່ໃຫ້ລະບົບໜັກ.');
            input.value = '';
            $('#edit_preview').hide();
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#edit_preview').attr('src', e.target.result).show();
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>
