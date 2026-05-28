<?php
require_once '../config/session_check.php';
enforcePermission('pos');
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_sell = ($is_admin || hasPermission('can_sell'));
$can_void = ($is_admin || hasPermission('can_void'));
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// Handle Checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['checkout_pos']) || isset($_POST['cart_prod_id']))) {
    if (!$can_sell) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຂາຍສິນຄ້າ!";
        header("Location: pos.php");
        exit();
    }
            $prod_ids = $_POST['cart_prod_id'];
            $qtys = $_POST['cart_qty'];
            $prices = $_POST['cart_price'];
            $disc_types = $_POST['cart_discount_type'] ?? [];
            $disc_vals = $_POST['cart_discount_value'] ?? [];
            $bill_discs = $_POST['cart_bill_discount'] ?? [];
            $final_amounts = $_POST['cart_final_amount'] ?? [];
            
            $pdo->beginTransaction();
            try {
                // Get current tax percent
                $stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
                $tax_p = (float)($stmtTax->fetchColumn() ?: 0);
 
                $payment_method = $_POST['payment_method'] ?? 'ເງິນສົດ';
                $received = (float)($_POST['received'] ?? 0);
                $change_amount = (float)($_POST['change_amount'] ?? 0);
 
                // Generate Bill ID: S + YYYYMMDD + NNNN (e.g. S-202605280001)
                $datePrefix = 'S-' . date('Ymd');
                $stmtLast = $pdo->prepare("SELECT bill_id FROM orders WHERE bill_id LIKE ? AND bill_id REGEXP '^S-[0-9]+$' ORDER BY CAST(SUBSTRING(bill_id, 11) AS UNSIGNED) DESC LIMIT 1");
                $stmtLast->execute([$datePrefix . '%']);
                $lastBill = $stmtLast->fetchColumn();
 
                if ($lastBill) {
                    $lastNum = (int)substr($lastBill, 10);
                    $nextNum = $lastNum + 1;
                } else {
                    $nextNum = 1;
                }
                $bill_id = $datePrefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

                // Guard: prevent committing an empty bill with no items
                if (count($prod_ids) === 0) {
                    throw new Exception("ບໍ່ມີລາຍການສິນຄ້າໃນບິນ");
                }

                for ($i = 0; $i < count($prod_ids); $i++) {
                    $pid = (int)$prod_ids[$i];
                    $q = (int)$qtys[$i];
                    $p = (float)$prices[$i];
                    
                    $disc_type = !empty($disc_types[$i]) ? $disc_types[$i] : null;
                    $disc_val = isset($disc_vals[$i]) ? (float)$disc_vals[$i] : 0.0;
                    $bill_disc = isset($bill_discs[$i]) ? (float)$bill_discs[$i] : 0.0;
                    $final_amount = isset($final_amounts[$i]) ? (float)$final_amounts[$i] : ($q * $p);
                    
                    $user_id = $_SESSION['user_id'] ?? null;
                    $stmt = $pdo->prepare("INSERT INTO orders (bill_id, prod_id, o_qty, amount, discount_type, discount_value, bill_discount, received, change_amount, payment_method, o_date, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
                    $stmt->execute([$bill_id, $pid, $q, $final_amount, $disc_type, $disc_val, $bill_disc, $received, $change_amount, $payment_method, $user_id]);
                    
                    $upd = $pdo->prepare("UPDATE products SET qty = qty - ? WHERE prod_id = ?");
                    $upd->execute([$q, $pid]);
                }
            $pdo->commit();
            logActivity($pdo, "ຂາຍສິນຄ້າ (POS)", "ບິນເລກທີ: $bill_id");
            $_SESSION['print_bill'] = $bill_id;
            header("Location: pos.php?status=success");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດ: " . $e->getMessage();
        }
        header("Location: pos.php");
        exit();
    }

// Fetch available products with localized names
$prod_name_col = "prod_name_" . $current_lang;
$cat_name_col = "name_" . $current_lang;

$stmt = $pdo->query("SELECT p.*, pc.name_la as cat_la, pc.name_en as cat_en, pc.name_cn as cat_cn 
                     FROM products p 
                     LEFT JOIN product_categories pc ON p.category = pc.name 
                     ORDER BY p.category ASC, p.prod_name ASC");
$products = $stmt->fetchAll();

// Fetch categories
$stmtCat = $pdo->query("SELECT * FROM product_categories ORDER BY name ASC");
$categories = $stmtCat->fetchAll();

// Fetch settings
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
$tax_percent = (float)($settings['tax_percent'] ?? 0);

// Fetch default currency
$stmtCur = $pdo->query("SELECT * FROM currency WHERE is_default = 1 LIMIT 1");
$default_currency = $stmtCur->fetch();
$currency_symbol = $default_currency['symbol'] ?? '₭';

// Group products by category for counting and define icons
$catIcons = [
    'ເຄື່ອງດື່ມ' => 'fa-glass-cheers',
    'ອາຫານ' => 'fa-utensils',
    'ຂະໜົມ' => 'fa-cookie-bite',
];
$catCounts = [];
foreach ($products as $p) {
    $cat = $p['category'] ?: 'ອື່ນໆ';
    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['pos']; ?> - POS</title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        if (window.top === window.self) { window.location.href = '../menu_admin.php'; }
    </script>
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        .fas, .far, .fab, .fa { font-family: "Font Awesome 5 Free" !important; font-weight: 900 !important; }
        body { background-color: #f8f9fa; padding: 8px; }
        
        /* Category Buttons */
        .cat-btn {
            white-space: nowrap;
            padding: 8px 22px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.95rem;
            color: #333;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .cat-btn:hover {
            border-color: #bbb;
            background-color: #fafdff;
        }
        .cat-btn.active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.35);
        }
        .cat-btn .badge { font-size: 0.65rem; padding: 3px 6px; }
        .cat-scroll { 
            display: flex; 
            overflow-x: auto; 
            gap: 10px; 
            padding: 8px 4px; 
            scrollbar-width: none; 
            -ms-overflow-style: none; 
        }
        .cat-scroll::-webkit-scrollbar { display: none; }
        
        /* Product Cards */
        .product-card {
            border: 1px solid #eaeaea !important;
            border-radius: 12px !important;
            overflow: hidden;
            background: #fff;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.03) !important;
        }
        .product-card:active {
            transform: scale(0.97);
            background: #fdfdfd;
        }
        .qty-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ff3838;
            color: white;
            min-width: 28px;
            height: 28px;
            padding: 0 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.95rem;
            z-index: 30;
            border-radius: 50%;
            box-shadow: 0 4px 8px rgba(255, 56, 56, 0.45);
            border: 2px solid white;
        }
        .product-img-wrapper {
            position: relative;
            width: 100%;
            height: 155px;
            overflow: hidden;
            background: #f8f9fa;
        }
        .product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-placeholder {
            width: 100%;
            height: 100%;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bdc3c7;
        }
        .product-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.25;
            min-height: auto;
            margin-bottom: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            white-space: normal;
            overflow: hidden;
            padding: 0 4px;
        }
        .product-price {
            font-size: 0.98rem;
            font-weight: 800;
            color: #27ae60;
        }
        .remaining-badge {
            position: absolute;
            bottom: 8px;
            left: 8px;
            background: #dc3545;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 3px 6px;
            border-radius: 4px;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(220,53,69,0.3);
        }
        .stock-badge {
            position: absolute;
            bottom: 8px;
            right: 8px;
            font-size: 0.85rem !important;
            font-weight: 800 !important;
            padding: 4px 10px !important;
            border-radius: 4px !important;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(220,53,69,0.3);
        }
        .sold-out-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 20;
        }
        .sold-out-badge {
            background: #dc3545;
            color: white;
            font-size: 0.82rem;
            font-weight: 800;
            padding: 5px 12px;
            border-radius: 4px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.25);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Mobile Adjustments */
        @media (max-width: 768px) {
            body { padding: 5px; }
            h2 { font-size: 1.1rem !important; }
            .product-name { font-size: 0.72rem !important; min-height: auto !important; margin-bottom: 1px !important; }
            .product-price { font-size: 0.8rem !important; }
            .remaining-badge { font-size: 0.58rem !important; padding: 2px 5px !important; bottom: 6px !important; left: 6px !important; }
            .stock-badge { font-size: 0.72rem !important; padding: 3px 7px !important; bottom: 6px !important; right: 6px !important; }
            .sold-out-badge { font-size: 0.65rem !important; padding: 4px 8px !important; }
            .product-img-wrapper { height: 110px !important; }
            .cat-btn { padding: 6px 15px; font-size: 0.85rem; }
            .cart-container { height: 38vh; }
            #mainSearch { height: 42px !important; font-size: 0.9rem !important; }
        }

        /* Barcode Input Styling */
        .barcode-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e0e6ed;
            box-shadow: 0 4px 10px rgba(0,0,0,0.03) !important;
        }
        .barcode-group .input-group-text {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border: none;
            color: #fff;
            padding: 0 15px;
        }
        #mainSearch {
            height: 45px;
            border: none;
            font-size: 0.95rem;
            font-weight: 500;
        }
        #mainSearch:focus {
            box-shadow: none;
            background-color: #fff;
        }
    </style>
    <audio id="successSound" src="https://assets.mixkit.co/active_storage/sfx/2868/2868-preview.mp3" preload="auto"></audio>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_GET['status']) && $_GET['status'] == 'success' && isset($_SESSION['print_bill'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $lang['payment_success']; ?>',
                    text: '<?php echo $lang['print_receipt_question']; ?>',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-print"></i> <?php echo $lang['print_receipt']; ?>',
                    cancelButtonText: '<?php echo $lang['close']; ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.open('../print/print_receipt.php?bill_id=<?php echo $_SESSION['print_bill']; ?>', '_blank');
                    }
                });
            });
        </script>
    <?php unset($_SESSION['print_bill']); endif; ?>

    <div class="row mb-3 align-items-center">
        <div class="col-12">
            <h2 class="mb-0"><i class="fas fa-cash-register text-primary"></i> <?php echo $lang['pos']; ?></h2>
        </div>
    </div>

    <div class="row">
        <!-- Products Grid (Left) -->
        <div class="col-lg-8 col-md-7 mb-3">
            <!-- Search & Barcode Area -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="input-group barcode-group shadow-sm h-100">
                        <div class="input-group-prepend">
                            <span class="input-group-text text-white"><i class="fas fa-search"></i></span>
                        </div>
                        <input type="text" id="mainSearch" class="form-control" placeholder="<?php echo $lang['search_scan_barcode']; ?>" autofocus autocomplete="off" style="height: 50px; font-size: 1.1rem;">
                    </div>
                </div>
            </div>

            <!-- Category Filter Buttons -->
            <div class="mb-3">
                <div class="cat-scroll">
                    <div class="cat-btn active" data-cat="all">
                        <?php echo $lang['all_categories'] ?? 'ໝວດໝູ່ທັງໝົດ'; ?>
                    </div>
                    <?php 
                    foreach($categories as $cat): 
                        $catName = $cat['name'];
                        $count = $catCounts[$catName] ?? 0;
                        if ($count == 0) continue;
                    ?>
                        <div class="cat-btn" data-cat="<?php echo htmlspecialchars($catName); ?>">
                            <?php 
                                $catDisp = $cat[$cat_name_col];
                                if (empty($catDisp)) {
                                    if ($catName == 'ອາຫານ') $catDisp = $lang['cat_food_label'] ?? 'Food';
                                    elseif ($catName == 'ເຄື່ອງດື່ມ') $catDisp = $lang['cat_drinks_label'] ?? 'Drinks';
                                    elseif ($catName == 'ເຂົ້າໜົມ' || $catName == 'ຂະໜົມ') $catDisp = $lang['cat_snacks_label'] ?? 'Snacks';
                                    else $catDisp = $catName;
                                }
                                echo htmlspecialchars($catDisp); 
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Products Grid -->
            <div class="card border-0 shadow-sm" style="border-radius: 4px;">
                <div class="card-body p-2" style="max-height: 70vh; overflow-y: auto;" id="productGrid">
                    <div class="row" id="productList">
                        <div id="noResultsMsg" class="col-12 text-center py-5 text-muted" style="display: none;">
                            <i class="fas fa-search fa-3x mb-3 d-block" style="color: #ddd;"></i>
                            <h5><?php echo $lang['no_products_found']; ?></h5>
                        </div>
                        <?php foreach($products as $p): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 col-6 mb-3 product-item" data-category="<?php echo htmlspecialchars($p['category']); ?>">
                                <div class="card product-card shadow-sm h-100" id="prod-card-<?php echo $p['prod_id']; ?>" onclick="addToCart(<?php echo $p['prod_id']; ?>, '<?php echo htmlspecialchars(addslashes($p[$prod_name_col] ?: $p['prod_name'])); ?>', <?php echo $p['sprice']; ?>, <?php echo $p['qty']; ?>, '<?php echo $p['image']; ?>', '<?php echo htmlspecialchars($p['prod_code']); ?>')">
                                    
                                    <div class="product-img-wrapper">
                                        <span class="qty-badge" id="qty-badge-<?php echo $p['prod_id']; ?>" style="display: none;">0</span>
                                        
                                        <?php if($p['qty'] <= 0): ?>
                                            <!-- Sold Out Overlay -->
                                            <div class="sold-out-overlay">
                                                <span class="sold-out-badge"><?php echo $lang['out_of_stock'] ?? 'ສິນຄ້າໝົດແລ້ວ'; ?></span>
                                            </div>
                                        <?php else: ?>
                                            <!-- Remaining Stock Badge (Bottom-Left) -->
                                            <span class="remaining-badge" id="remaining-badge-<?php echo $p['prod_id']; ?>">
                                                <?php echo $lang['remaining'] ?? 'ເຫຼືອ'; ?>: <span class="stock-qty"><?php echo number_format($p['qty']); ?></span>
                                            </span>
                                            <!-- Stock Badge (Bottom-Right) -->
                                            <?php if($p['qty'] <= 10): ?>
                                                <span class="stock-badge badge badge-danger"><?php echo $lang['low_stock']; ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php 
                                            $prod_img_src = !empty($p['image']) ? '../assets/img/products/' . htmlspecialchars($p['image']) : '../assets/img/image.jpg';
                                        ?>
                                        <img src="<?php echo $prod_img_src; ?>" class="product-img" alt="<?php echo htmlspecialchars($p['prod_name']); ?>">
                                    </div>
                                    <div class="card-body text-center p-2">
                                        <div class="product-name"><?php echo htmlspecialchars($p[$prod_name_col] ?: $p['prod_name']); ?></div>
                                        <div class="product-price"><?php echo number_format($p['sprice']); ?> <?php echo $currency_symbol; ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($products)): ?>
                            <div class="col-12 text-center py-5 text-muted">
                                <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                                Safely Localizing pos.php<h5><?php echo $lang['no_products_available']; ?></h5>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cart (Right) -->
        <div class="col-lg-4 col-md-5">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 4px; display: flex; flex-direction: column;">
                <div class="card-header bg-white d-flex align-items-center" style="border-radius: 4px 4px 0 0;">
                    <h5 class="m-0 font-weight-bold"><i class="fas fa-shopping-cart text-primary"></i> <?php echo $lang['cart']; ?></h5>
                    <button class="btn btn-xs btn-outline-danger ml-auto" onclick="clearCart()" style="font-size: 0.75rem;"><i class="fas fa-trash-alt"></i> <?php echo $lang['clear_all_btn']; ?></button>
                </div>
                <div class="card-body p-0 cart-container" id="cartItems" style="flex: 1;">
                    <div class="text-center text-muted py-5" id="emptyCartMsg">
                        <i class="fas fa-shopping-basket fa-3x mb-3 d-block" style="color: #ddd;"></i>
                        <p class="mb-0"><?php echo $lang['click_to_add']; ?></p>
                    </div>
                </div>
                <div class="card-footer bg-white border-top" style="border-radius: 0 0 4px 4px;">
                    <div class="px-2 mb-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted"><?php echo $lang['subtotal']; ?>:</span>
                            <span class="font-weight-bold"><span id="cartSubtotal">0</span> <?php echo $currency_symbol; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted"><?php echo $lang['tax']; ?> (<?php echo $tax_percent; ?>%):</span>
                            <span class="font-weight-bold text-info"><span id="cartTax">0</span> <?php echo $currency_symbol; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 align-items-center">
                            <span class="text-muted">ສ່ວນຫຼຸດ:</span>
                            <div class="d-flex flex-column align-items-end" style="gap: 2px;">
                                <div class="d-flex align-items-center" style="gap: 5px;">
                                    <input type="text" id="billDiscountInput" class="form-control form-control-sm text-right font-weight-bold" style="width: 120px; height: 28px;" placeholder="0" value="0">
                                    <span class="font-weight-bold text-dark" style="font-size: 1rem; width: 20px; text-align: center;">₭</span>
                                </div>
                                <small id="billDiscountEquivalent" class="text-muted font-weight-bold" style="font-size: 0.72rem; display: none; margin-right: 25px;"></small>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                            <span class="font-weight-bold text-dark" style="font-size: 1.1rem;"><?php echo $lang['grand_total']; ?>:</span>
                            <span class="font-weight-bold text-danger" style="font-size: 1.4rem;"><span id="cartTotal">0</span> <?php echo $currency_symbol; ?></span>
                        </div>
                    </div>
                    <form action="" method="post" id="posForm">
                        <div id="hiddenInputs"></div>
                        <button type="submit" name="checkout_pos" class="btn btn-success btn-lg btn-block" id="btnCheckout" disabled style="border-radius: 4px; font-size: 1rem;">
                            <i class="fas fa-money-bill-wave"></i> <?php echo $lang['pay'] ?? 'ຊຳລະເງິນ'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
let cart = JSON.parse(localStorage.getItem('pos_cart')) || {};
const taxPercent = <?php echo $tax_percent; ?>;
const currencySymbol = '<?php echo $currency_symbol; ?>';
const currentLang = '<?php echo $current_lang; ?>';
const allProducts = <?php 
    // Prepare products with localized names for JS
    $jsProducts = [];
    foreach($products as $p) {
        $p['display_name'] = $p[$prod_name_col] ?: $p['prod_name'];
        $jsProducts[] = $p;
    }
    echo json_encode($jsProducts); 
?>;

$(document).ready(function() {
    renderCart(); // Restore cart from localStorage

    // Bind event listener for the final bill discount input with real-time comma formatting
    $('#billDiscountInput').on('input', function() {
        let inputVal = $(this).val();
        // Remove commas and strip any non-digit character
        let rawDigits = inputVal.replace(/[^0-9]/g, '');
        
        if (rawDigits === '') {
            $(this).val('');
            renderCart();
            return;
        }
        
        let numVal = parseInt(rawDigits, 10) || 0;
        $(this).val(numVal.toLocaleString('en-US'));
        renderCart();
    });

    // Fall back to 0 on blur if empty
    $('#billDiscountInput').on('blur', function() {
        if ($(this).val().trim() === '') {
            $(this).val('0');
            renderCart();
        }
    });

    // Combined Search & Barcode Logic
    $('#mainSearch').focus();
    $(document).on('click', function() {
        if ($('.modal.show').length === 0 && !$(event.target).is('input, textarea, select')) {
            $('#mainSearch').focus();
        }
    });

    $('#mainSearch').on('input', function() {
        let val = $(this).val().trim();
        
        if (val === '') {
            $('.product-item').show();
            $('#noResultsMsg').hide();
            return;
        }

        // 1. Try barcode match first (Exact match)
        let product = allProducts.find(p => p.prod_code === val);
        if (product) {
            addToCart(product.prod_id, product.display_name, product.sprice, product.qty, product.image, product.prod_code);
            $(this).val(''); // Clear for next scan
            $('.product-item').show(); // Reset filter
            $('#noResultsMsg').hide();
            return;
        }

        // 2. Otherwise treat as name search filter
        var visibleCount = 0;
        var searchVal = val.toLowerCase();
        $('.product-item').each(function() {
            var name = $(this).find('.product-name').text().toLowerCase();
            if (name.indexOf(searchVal) > -1) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });

        if (visibleCount === 0) {
            $('#noResultsMsg').show();
        } else {
            $('#noResultsMsg').hide();
        }
    });

    // Also handle Enter key for barcode scanners that append Enter
    $('#mainSearch').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).trigger('input');
        }
    });
});

// Category filter
$(document).on('click', '.cat-btn', function() {
    $('.cat-btn').removeClass('active');
    $(this).addClass('active');
    var cat = $(this).data('cat');
    
    if (cat === 'all') {
        $('.product-item').show();
    } else {
        $('.product-item').hide();
        $('.product-item[data-category="' + cat + '"]').show();
    }
});

function addToCart(id, name, price, maxQty, image, code) {
    if (maxQty <= 0) {
        Swal.fire({ 
            icon: 'error', 
            title: 'ສິນຄ້າໝົດແລ້ວ!', 
            text: 'ຂໍອະໄພ, ສິນຄ້ານີ້ໝົດສະຕັອກແລ້ວ ບໍ່ສາມາດຂາຍໄດ້', 
            timer: 2000, 
            showConfirmButton: false 
        });
        return;
    }
    if (cart[id]) {
        if (cart[id].qty < maxQty) {
            cart[id].qty++;
        } else {
            Swal.fire({ icon: 'warning', title: '<?php echo $lang['out_of_stock']; ?>', text: '<?php echo $lang['stock_only_has']; ?> ' + maxQty + ' <?php echo $lang['unit'] ?? 'ຊິ້ນ'; ?>', timer: 1500, showConfirmButton: false });
            return;
        }
    } else {
        if (maxQty > 0) {
            cart[id] = { name: name, price: price, qty: 1, maxQty: maxQty, image: image, code: code };
        }
    }
    
    // Warning Alert when adding low stock items (qty <= 10)
    if (maxQty <= 10) {
        let remaining = maxQty - cart[id].qty;
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true
        });
        Toast.fire({
            icon: 'warning',
            title: `ສິນຄ້າໃກ້ໝົດ! ${name}`,
            text: `ເຫຼືອພຽງ ${remaining} ຊິ້ນເທົ່ານັ້ນ`
        });
    }
    
    renderCart();
}

function updateQty(id, delta) {
    if (cart[id]) {
        let newQty = cart[id].qty + delta;
        if (newQty <= 0) {
            delete cart[id];
        } else if (newQty > cart[id].maxQty) {
            Swal.fire({ icon: 'warning', title: '<?php echo $lang['out_of_stock']; ?>', timer: 1200, showConfirmButton: false });
        } else {
            cart[id].qty = newQty;
            
            // Warn when increasing qty of a low stock item
            if (delta > 0 && cart[id].maxQty <= 10) {
                let remaining = cart[id].maxQty - newQty;
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'warning',
                    title: `ສິນຄ້າໃກ້ໝົດ! ${cart[id].name}`,
                    text: `ເຫຼືອພຽງ ${remaining} ຊິ້ນເທົ່ານັ້ນ`
                });
            }
        }
        renderCart();
    }
}

function removeItem(id) {
    delete cart[id];
    renderCart();
}

function clearCart() {
    if (Object.keys(cart).length === 0) return;
    Swal.fire({
        title: '<?php echo $lang['clear_cart_question']; ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: '<?php echo $lang['clear_now']; ?>',
        cancelButtonText: '<?php echo $lang['cancel']; ?>'
    }).then((result) => {
        if (result.isConfirmed) { cart = {}; localStorage.removeItem('pos_cart'); renderCart(); }
    });
}

function renderCart() {
    // Save to localStorage for persistence
    localStorage.setItem('pos_cart', JSON.stringify(cart));
    let html = '';
    let total = 0; // Net subtotal after line item discounts
    let originalSubtotalSum = 0; // Subtotal before line item discounts
    let hasItems = false;
    let hiddenHtml = '';
    let itemCount = 0;

    // Reset all badges first
    $('.qty-badge').hide().text('0');

    // Array to temporarily hold processed cart item rows to calculate proportionate final bill discount
    let processedItems = [];

    for (let id in cart) {
        hasItems = true;
        itemCount++;
        let item = cart[id];
        let original_subtotal = item.price * item.qty;
        originalSubtotalSum += original_subtotal;

        // Calculate line item discount directly on the unit price
        let disc_val = item.discount_value || 0;
        let disc_type = item.discount_type || 'cash';
        let unit_discount = 0;
        if (disc_val > 0) {
            if (disc_type === 'percent') {
                unit_discount = Math.round(item.price * (disc_val / 100));
            } else {
                unit_discount = disc_val;
            }
        }
        let row_discount = unit_discount * item.qty;
        let item_net_subtotal = original_subtotal - row_discount;
        if (item_net_subtotal < 0) item_net_subtotal = 0;
        total += item_net_subtotal;

        // Update badge on product card
        $('#qty-badge-' + id).text(item.qty).show();

        processedItems.push({
            id: id,
            item: item,
            original_subtotal: original_subtotal,
            row_discount: row_discount,
            item_net_subtotal: item_net_subtotal
        });
    }

    // Expose net subtotal globally for payment checkout modal
    window.cartSubtotalVal = total;

    // Retrieve and calculate bill-level discount (remove commas first)
    let bill_disc_raw = $('#billDiscountInput').val() || '0';
    let bill_disc_val = parseFloat(bill_disc_raw.replace(/,/g, '')) || 0;
    let bill_discount_amount = bill_disc_val;
    if (bill_discount_amount > total) bill_discount_amount = total;

    // Total net subtotal after both discounts
    let net_total_before_tax = total - bill_discount_amount;
    let totalTaxAmount = Math.round(net_total_before_tax * (taxPercent / 100));
    let grandTotal = net_total_before_tax + totalTaxAmount;

    // Now, render the HTML and calculate the exact proportionate amount for each item
    processedItems.forEach(processed => {
        let id = processed.id;
        let item = processed.item;
        let subtotal = processed.original_subtotal;
        let row_discount = processed.row_discount;
        let item_net_subtotal = processed.item_net_subtotal;

        // Proportionate share of the final bill discount for this item row
        let item_tax = Math.round(item_net_subtotal * (taxPercent / 100));
        let item_total_with_tax = item_net_subtotal + item_tax;
        
        let share_ratio = total > 0 ? (item_net_subtotal / total) : 0;
        let proportionate_bill_discount = bill_discount_amount * share_ratio;
        let proportionate_bill_discount_tax = Math.round(proportionate_bill_discount * (taxPercent / 100));
        
        // Final net amount including tax, after line discount and bill discount
        let item_final_amount = item_total_with_tax - (proportionate_bill_discount + proportionate_bill_discount_tax);
        if (item_final_amount < 0) item_final_amount = 0;

        let imageHtml = item.image 
            ? `<img src="../assets/img/products/${item.image}" style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; margin-right: 10px;" class="border shadow-sm">`
            : `<img src="../assets/img/image.jpg" style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; margin-right: 10px;" class="border shadow-sm">`;

        // Style the row discount display
        let discText = '';
        if (item.discount_value && item.discount_value > 0) {
            if (item.discount_type === 'percent') {
                discText = `<span class="badge badge-warning text-dark ml-1 font-weight-bold" style="font-size: 0.7rem;"><i class="fas fa-percentage"></i> -${item.discount_value}%</span>`;
            } else {
                discText = `<span class="badge badge-warning text-dark ml-1 font-weight-bold" style="font-size: 0.7rem;"><i class="fas fa-tags"></i> -${item.discount_value.toLocaleString('en-US')}${currencySymbol}</span>`;
            }
        }

        html += `
        <div class="cart-item px-2 py-2 border-bottom">
            <div class="d-flex align-items-center justify-content-between">
                <!-- Left: Product Image & Name & Price info -->
                <div class="d-flex align-items-center" style="flex: 1; min-width: 0; margin-right: 8px;">
                    ${imageHtml}
                    <div style="min-width: 0; line-height: 1.2;">
                        <strong style="font-size: 0.82rem; color: #333;" class="text-truncate d-block" title="${item.name}">${item.name}</strong>
                        <div class="text-success font-weight-bold" style="font-size: 0.85rem; margin-top: 2px;">
                            ${(item_net_subtotal).toLocaleString('en-US')} ${currencySymbol}
                            ${row_discount > 0 ? `<span class="text-muted text-decoration-line-through small font-weight-normal" style="text-decoration: line-through; font-size: 0.75rem; margin-left: 5px;">${subtotal.toLocaleString('en-US')}</span>` : ''}
                        </div>
                        <div class="text-muted d-flex align-items-center flex-wrap" style="font-size: 0.68rem; margin-top: 2px; gap: 4px;">
                            <span>${item.price.toLocaleString('en-US')} × ${item.qty}</span>
                            ${discText}
                        </div>
                    </div>
                </div>
                <!-- Right: Quantity selector and delete button in the same row -->
                <div class="d-flex align-items-center" style="flex-shrink: 0;">
                    <div class="btn-group btn-group-sm mr-2 shadow-sm">
                        <button class="btn btn-outline-danger px-2 bg-white" onclick="updateQty(${id}, -1)" style="border-color: #dc3545; transition: all 0.2s;"><i class="fas fa-minus"></i></button>
                        <button class="btn btn-light px-2 font-weight-bold" style="min-width: 32px;" disabled>${item.qty}</button>
                        <button class="btn btn-outline-success px-2 bg-white" onclick="updateQty(${id}, 1)" style="border-color: #28a745; transition: all 0.2s;"><i class="fas fa-plus"></i></button>
                    </div>
                    <button class="btn btn-sm p-0 text-danger" onclick="removeItem(${id})" style="font-size: 1.2rem; line-height: 1; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'"><i class="fas fa-times-circle"></i></button>
                </div>
            </div>
        </div>
        `;

        hiddenHtml += `
            <input type="hidden" name="cart_prod_id[]" value="${id}">
            <input type="hidden" name="cart_qty[]" value="${item.qty}">
            <input type="hidden" name="cart_price[]" value="${item.price}">
            <input type="hidden" name="cart_discount_type[]" value="${item.discount_type || 'cash'}">
            <input type="hidden" name="cart_discount_value[]" value="${item.discount_value || 0}">
            <input type="hidden" name="cart_bill_discount[]" value="${proportionate_bill_discount}">
            <input type="hidden" name="cart_final_amount[]" value="${item_final_amount}">
        `;
    });

    if (!hasItems) {
        $('#cartItems').html(`
            <div class="text-center text-muted py-5">
                <i class="fas fa-shopping-basket fa-3x mb-3 d-block" style="color: #ddd;"></i>
                <p class="mb-0"><?php echo $lang['click_to_add']; ?></p>
            </div>
        `);
        $('#btnCheckout').prop('disabled', true).html('<i class="fas fa-money-bill-wave"></i> <?php echo $lang['pay'] ?? 'ຊຳລະເງິນ'; ?>');
    } else {
        $('#cartItems').html(html);
        var canSell = <?php echo $can_sell ? 'true' : 'false'; ?>;
        if (canSell) {
            $('#btnCheckout').prop('disabled', false).html('<i class="fas fa-money-bill-wave"></i> <?php echo $lang['pay'] ?? 'ຊຳລະເງິນ'; ?> (' + itemCount + ' <?php echo $lang['item_label'] ?? 'ລາຍການ'; ?>)');
        } else {
            $('#btnCheckout').prop('disabled', true).html('<i class="fas fa-lock"></i> ເບິ່ງຢ່າງດຽວ');
        }
    }

    $('#cartSubtotal').text(net_total_before_tax.toLocaleString('en-US'));
    $('#cartTax').text(totalTaxAmount.toLocaleString('en-US'));
    $('#cartTotal').text(grandTotal.toLocaleString('en-US'));
    // Safety: only replace hiddenInputs if cart actually has items
    // This prevents form submitting with empty cart_prod_id[] arrays
    if (hasItems) {
        $('#hiddenInputs').html(hiddenHtml);
    }

    // Update bill discount equivalent text dynamically (always cash-to-percent)
    let eqSpan = $('#billDiscountEquivalent');
    if (hasItems && total > 0 && bill_disc_val > 0) {
        let eqPercent = ((bill_disc_val / total) * 100).toFixed(1);
        if (eqPercent.endsWith('.0')) eqPercent = eqPercent.slice(0, -2);
        eqSpan.text(`(ເທົ່າກັບ ${eqPercent}%)`).show();
    } else {
        eqSpan.hide().text('');
    }
}

// Function to open item discount modal
function openItemDiscountModal(id) {
    let item = cart[id];
    let currentType = item.discount_type || 'cash';
    let currentValue = item.discount_value || '';

    Swal.fire({
        title: 'ປ້ອນສ່ວນຫຼຸດລາຍການ',
        html: `
            <div class="text-left">
                <p class="mb-2 font-weight-bold text-dark">${item.name}</p>
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">ປະເພດສ່ວນຫຼຸດ</label>
                    <select id="swal_item_disc_type" class="form-control">
                        <option value="cash" ${currentType === 'cash' ? 'selected' : ''}>ເປັນເງິນ (₭)</option>
                        <option value="percent" ${currentType === 'percent' ? 'selected' : ''}>ເປັນເປີເຊັນ (%)</option>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="small font-weight-bold">ມູນຄ່າສ່ວນຫຼຸດ</label>
                    <input type="number" id="swal_item_disc_value" class="form-control text-right font-weight-bold" value="${currentValue}" placeholder="0" min="0">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#007bff',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ຕົກລົງ',
        cancelButtonText: 'ຍົກເລີກ',
        didOpen: () => {
            const popup = Swal.getPopup();
            const typeSelect = popup.querySelector('#swal_item_disc_type');
            const valInput = popup.querySelector('#swal_item_disc_value');
            
            // Create helper span for live equivalent calculation
            const helperDiv = document.createElement('div');
            helperDiv.id = 'swal_item_disc_helper';
            helperDiv.className = 'text-muted font-weight-bold text-right mt-1';
            helperDiv.style.fontSize = '0.78rem';
            valInput.parentNode.appendChild(helperDiv);
            
            function updateSwalHelper() {
                const type = typeSelect.value;
                const value = parseFloat(valInput.value) || 0;
                const price = item.price;
                
                if (value > 0 && price > 0) {
                    if (type === 'cash') {
                        let pct = ((value / price) * 100).toFixed(1);
                        if (pct.endsWith('.0')) pct = pct.slice(0, -2);
                        helperDiv.textContent = `(ເທົ່າກັບ ${pct}%)`;
                    } else {
                        let cash = Math.round(price * (value / 100));
                        helperDiv.textContent = `(ເທົ່າກັບ ${cash.toLocaleString('en-US')}${currencySymbol})`;
                    }
                } else {
                    helperDiv.textContent = '';
                }
            }
            
            typeSelect.addEventListener('change', updateSwalHelper);
            valInput.addEventListener('input', updateSwalHelper);
            updateSwalHelper(); // Initialize helper value
        },
        preConfirm: () => {
            const popup = Swal.getPopup();
            const type = popup.querySelector('#swal_item_disc_type').value;
            const value = parseFloat(popup.querySelector('#swal_item_disc_value').value) || 0;
            if (value < 0) {
                Swal.showValidationMessage('ມູນຄ່າສ່ວນຫຼຸດຕ້ອງຫຼາຍກວ່າ ຫຼື ເທົ່າກັບ 0');
                return false;
            }
            if (type === 'percent' && value > 100) {
                Swal.showValidationMessage('ສ່ວນຫຼຸດເປັນເປີເຊັນບໍ່ສາມາດເກີນ 100%');
                return false;
            }
            return { type, value };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            cart[id].discount_type = result.value.type;
            cart[id].discount_value = result.value.value;
            renderCart();
        }
    });
}

// Payment confirmation
$('#posForm').on('submit', function(e) {
    e.preventDefault();
    var canSell = <?php echo $can_sell ? 'true' : 'false'; ?>;
    if (!canSell) {
        Swal.fire({
            icon: 'error',
            title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>',
            text: 'ທ່ານບໍ່ມີສິດໃນການຂາຍສິນຄ້າ!',
            confirmButtonText: '<?php echo $lang['ok']; ?>'
        });
        return false;
    }
    
    // Get subtotal and tax before bill discount
    var subtotalVal = window.cartSubtotalVal || 0;
    var taxVal = Math.round(subtotalVal * (taxPercent / 100));
    var totalValBeforeBillDiscount = subtotalVal + taxVal;
    
    // Get current bill discount from input
    var initialBillDiscountRaw = $('#billDiscountInput').val() || '0';
    var initialBillDiscount = parseFloat(initialBillDiscountRaw.replace(/,/g, '')) || 0;
    
    Swal.fire({
        title: '<?php echo $lang['pay'] ?? 'ຊຳລະເງິນ'; ?>',
        html: `
            <div class="text-left mb-3" style="font-family: 'Noto Sans Lao', sans-serif;">
                <div class="d-flex justify-content-between mb-1" style="font-size: 0.9rem;">
                    <span class="text-muted"><?php echo $lang['subtotal']; ?>:</span>
                    <span class="font-weight-bold" id="swal_subtotal_display">${subtotalVal.toLocaleString('en-US')} ${currencySymbol}</span>
                </div>
                <div class="d-flex justify-content-between mb-1 border-bottom pb-2" style="font-size: 0.9rem;">
                    <span class="text-muted"><?php echo $lang['tax']; ?> (${taxPercent}%):</span>
                    <span class="font-weight-bold text-info" id="swal_tax_display">${taxVal.toLocaleString('en-US')} ${currencySymbol}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 mb-3">
                    <span class="font-weight-bold text-dark" style="font-size: 1.1rem;"><?php echo $lang['total_amount_label']; ?>:</span>
                    <strong class="text-danger" id="swal_total_display" style="font-size: 1.4rem;">${totalValBeforeBillDiscount.toLocaleString('en-US')} ${currencySymbol}</strong>
                </div>
                
                <div class="form-group mb-2">
                    <label class="small font-weight-bold"><?php echo $lang['payment_method_label']; ?></label>
                    <select id="swal_payment_method" class="form-control form-control-sm">
                        <option value="Cash"><?php echo $lang['cash']; ?></option>
                        <option value="Transfer"><?php echo $lang['transfer']; ?></option>
                    </select>
                </div>
                
                <div class="form-group mb-2">
                    <label class="small font-weight-bold"><?php echo $lang['amount_received_label']; ?></label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="swal_received" class="form-control text-right font-weight-bold" placeholder="0" style="font-size: 1rem; height: 34px;">
                        <div class="input-group-append">
                            <button type="button" id="swal_btn_full" class="btn btn-primary btn-sm px-2 font-weight-bold"><?php echo $lang['receive_full']; ?></button>
                        </div>
                    </div>
                </div>
                
                <!-- Bill Discount Field right under/after Amount Received -->
                <div class="form-group mb-2">
                    <label class="small font-weight-bold">ສ່ວນຫຼຸດທ້າຍບິນ (₭)</label>
                    <input type="text" id="swal_bill_discount" class="form-control form-control-sm text-right font-weight-bold text-success" placeholder="0" style="font-size: 1rem; height: 34px;" value="${initialBillDiscount.toLocaleString('en-US')}">
                </div>

                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top mb-2">
                    <span class="font-weight-bold text-dark" style="font-size: 1rem;">ມູນຄ່າຕ້ອງຊຳລະຕົວຈິງ (Net Payable):</span>
                    <span class="font-weight-bold text-danger" id="swal_net_payable" style="font-size: 1.25rem;">0 ${currencySymbol}</span>
                </div>

                <div class="form-group mb-0">
                    <label class="small font-weight-bold"><?php echo $lang['change_amount_label']; ?></label>
                    <input type="text" id="swal_change" class="form-control form-control-sm text-right text-danger font-weight-bold" style="font-size: 1.2rem; height: 36px; background-color: #fff3f3;" value="0" readonly>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#d33',
        confirmButtonText: '<?php echo $lang['confirm_sale']; ?>',
        cancelButtonText: '<?php echo $lang['cancel']; ?>',
        didOpen: () => {
            const popup = Swal.getPopup();
            const receivedInput = popup.querySelector('#swal_received');
            const changeInput = popup.querySelector('#swal_change');
            const methodSelect = popup.querySelector('#swal_payment_method');
            const fullBtn = popup.querySelector('#swal_btn_full');
            const swalBillDiscountInput = popup.querySelector('#swal_bill_discount');
            const swalNetPayable = popup.querySelector('#swal_net_payable');
            
            const calc = () => {
                let subtotal = window.cartSubtotalVal || 0;
                
                // Parse bill discount
                let billDiscRaw = swalBillDiscountInput.value.replace(/,/g, '') || '0';
                let billDiscount = parseFloat(billDiscRaw) || 0;
                
                // Limit bill discount to subtotal
                if (billDiscount > subtotal) {
                    billDiscount = subtotal;
                    swalBillDiscountInput.value = subtotal.toLocaleString('en-US');
                }
                
                // Calculate Net Payable after discount
                let netSubtotal = subtotal - billDiscount;
                if (netSubtotal < 0) netSubtotal = 0;
                let netTax = Math.round(netSubtotal * (taxPercent / 100));
                let netTotal = netSubtotal + netTax;
                
                swalNetPayable.textContent = netTotal.toLocaleString('en-US') + ' ' + currencySymbol;
                
                // Calculate Change
                let receivedRaw = receivedInput.value.replace(/,/g, '') || '0';
                let received = parseFloat(receivedRaw) || 0;
                let change = received - netTotal;
                if (change < 0) change = 0;
                changeInput.value = change.toLocaleString('en-US');
                
                // Store calculated values in temporary window properties
                window.swalCalculatedNetTotal = netTotal;
                window.swalCalculatedDiscount = billDiscount;
            };

            swalBillDiscountInput.addEventListener('input', (e) => {
                let val = e.target.value.replace(/[^0-9]/g, '');
                if (val !== '') {
                    e.target.value = parseInt(val).toLocaleString('en-US');
                } else {
                    e.target.value = '';
                }
                calc();
            });

            swalBillDiscountInput.addEventListener('blur', (e) => {
                if (e.target.value.trim() === '') {
                    e.target.value = '0';
                }
                calc();
            });

            receivedInput.addEventListener('input', (e) => {
                let val = e.target.value.replace(/[^0-9]/g, '');
                if (val !== '') {
                    e.target.value = parseInt(val).toLocaleString('en-US');
                } else {
                    e.target.value = '';
                }
                calc();
            });

            methodSelect.addEventListener('change', (e) => {
                if (e.target.value === 'Transfer') {
                    calc(); // Ensure active window.swalCalculatedNetTotal is set
                    receivedInput.value = window.swalCalculatedNetTotal.toLocaleString('en-US');
                    receivedInput.readOnly = true;
                    fullBtn.disabled = true;
                } else {
                    receivedInput.value = '';
                    receivedInput.readOnly = false;
                    fullBtn.disabled = false;
                }
                calc();
            });

            fullBtn.addEventListener('click', () => {
                calc(); // Ensure active window.swalCalculatedNetTotal is set
                receivedInput.value = window.swalCalculatedNetTotal.toLocaleString('en-US');
                calc();
            });

            // Initial calc
            calc();
            receivedInput.focus();
        },
        preConfirm: () => {
            const popup = Swal.getPopup();
            const receivedStr = popup.querySelector('#swal_received').value;
            const received = parseFloat(receivedStr.replace(/,/g, '')) || 0;
            const method = popup.querySelector('#swal_payment_method').value;
            const change = parseFloat(popup.querySelector('#swal_change').value.replace(/,/g, '')) || 0;
            const billDiscount = window.swalCalculatedDiscount || 0;
            const netTotal = window.swalCalculatedNetTotal || 0;

            if (received < netTotal && method === 'Cash') {
                Swal.showValidationMessage('<?php echo $lang['insufficient_balance_msg']; ?>');
                return false;
            }
            return { received, method, change, billDiscount };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Safety guard: abort if cart is somehow empty (prevents empty bill)
            if (Object.keys(cart).length === 0) {
                Swal.fire({ icon: 'error', title: 'ຜິດພາດ', text: 'ກະຕ່າສິນຄ້າຫວ່າງ! ກະລຸນາເພີ່ມສິນຄ້າກ່ອນ.', confirmButtonColor: '#d33' });
                return;
            }

            // Write confirmed bill discount from modal back to main page input
            $('#billDiscountInput').val(result.value.billDiscount.toLocaleString('en-US'));
            
            // Re-render cart to update all hidden inputs with correct pre-tax discount values
            renderCart();
            
            // Append payment fields and proceed with submit
            $('#hiddenInputs').append(`
                <input type="hidden" name="payment_method" value="${result.value.method}">
                <input type="hidden" name="received" value="${result.value.received}">
                <input type="hidden" name="change_amount" value="${result.value.change}">
                <input type="hidden" name="checkout_pos" value="1">
            `);
            localStorage.removeItem('pos_cart'); // Clear after success
            $('#posForm')[0].submit();
        }
    });
});

// Print trigger on success
<?php if(isset($_GET['status']) && $_GET['status'] == 'success' && isset($_SESSION['print_bill'])): ?>
    var printUrl = '../print/print_receipt.php?bill_id=<?php echo $_SESSION['print_bill']; ?>';
    
    // Create hidden iframe for printing
    var printFrame = document.createElement('iframe');
    printFrame.style.display = 'none';
    printFrame.src = printUrl;
    document.body.appendChild(printFrame);
    
    let sound = document.getElementById('successSound');
    sound.pause();
    sound.currentTime = 0;
    sound.play().catch(e => console.log("Sound play blocked"));
    Swal.fire({
        title: '<?php echo $lang['sale_success']; ?>',
        text: '<?php echo $lang['printing_receipt_msg']; ?>',
        confirmButtonColor: '#28a745',
        padding: '2rem',
        timer: 3000,
        showConfirmButton: false,
        customClass: {
            title: 'text-success font-weight-bold',
            popup: 'rounded-lg'
        }
    });
    <?php unset($_SESSION['print_bill']); ?>
<?php endif; ?>
</script>
</body>
</html>
