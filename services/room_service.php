<?php
require_once '../config/session_check.php';
enforcePermission('room_service');
require_once '../config/db.php';

// --- 1. ສ່ວນໂຫຼດໄຟລ໌ພາສາ ແລະ ຕັ້ງຄ່າ Session (POS Language Loader) ---
// ກວດສອບ ແລະ ດຶງພາສາປັດຈຸບັນຂອງລະບົບ POS ຈາກ Session, ຫາກບໍ່ມີໃຫ້ເລືອກພາສາລາວ 'la'
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('room_service_edit'));
$can_delete = ($is_admin || hasPermission('room_service_delete'));

// Get active bookings (Occupied rooms)
$stmt = $pdo->query("
    SELECT b.id as booking_id, r.room_number, b.customer_name, b.customer_phone, r.room_type
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    WHERE b.status = 'Occupied'
    ORDER BY r.room_number ASC
");
$active_bookings = $stmt->fetchAll();

$selected_booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (count($active_bookings) > 0 ? $active_bookings[0]['booking_id'] : 0);

// Fetch categories for filtering with localized names
$current_lang = $_SESSION['lang'] ?? 'la';
$prod_name_col = "prod_name_" . $current_lang;
$cat_name_col = "name_" . $current_lang;

$stmtCate = $pdo->query("SELECT id, name, name_la, name_en, name_cn FROM product_categories ORDER BY name ASC");
$categories = $stmtCate->fetchAll();

// Fetch available products for selection with localized names
$stmtProd = $pdo->query("SELECT p.*, pc.name_la as cat_la, pc.name_en as cat_en, pc.name_cn as cat_cn 
                         FROM products p 
                         LEFT JOIN product_categories pc ON p.category = pc.name 
                         WHERE p.qty > 0 
                         ORDER BY p.prod_name ASC");
$products_list = $stmtProd->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_service'])) {
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] == 1;
    if (!$can_edit) {
        if ($is_ajax) {
            echo json_encode(['status' => 'error', 'message' => 'ທ່ານບໍ່ມີສິດໃນການສັ່ງອາຫານ/ບໍລິການ!']);
            exit();
        }
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການສັ່ງອາຫານ/ບໍລິການ!";
        header("Location: room_service.php");
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $item_name = trim($_POST['item_name']);
    $price = (float)str_replace(',', '', $_POST['price']);
    $qty = (int)$_POST['qty'];
    $total_price = $price * $qty;
    $prod_id = isset($_POST['prod_id']) && !empty($_POST['prod_id']) ? (int)$_POST['prod_id'] : null;

    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] == 1;

    $pdo->beginTransaction();
    try {
        // Check if this product already exists in the cart for this booking
        $checkExisting = $pdo->prepare("SELECT id, qty, total_price FROM room_services WHERE booking_id = ? AND prod_id = ? AND prod_id IS NOT NULL");
        $checkExisting->execute([$booking_id, $prod_id]);
        $existingItem = $checkExisting->fetch();

        if ($existingItem) {
            // Update existing row
            $newQty = $existingItem['qty'] + $qty;
            $newTotalPrice = $existingItem['total_price'] + $total_price;
            $stmt = $pdo->prepare("UPDATE room_services SET qty = ?, total_price = ?, created_at = NOW() WHERE id = ?");
            $stmt->execute([$newQty, $newTotalPrice, $existingItem['id']]);
        } else {
            // Insert new row
            $stmt = $pdo->prepare("INSERT INTO room_services (booking_id, prod_id, item_name, price, qty, total_price) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$booking_id, $prod_id, $item_name, $price, $qty, $total_price]);
        }

        // Update food_charge in bookings
        $updateBooking = $pdo->prepare("UPDATE bookings SET food_charge = food_charge + ? WHERE id = ?");
        $updateBooking->execute([$total_price, $booking_id]);

        // Reduce stock if it's a product
        if ($prod_id) {
            $updateStock = $pdo->prepare("UPDATE products SET qty = qty - ? WHERE prod_id = ?");
            $updateStock->execute([$qty, $prod_id]);
        }

        $pdo->commit();

        if ($is_ajax) {
            echo json_encode(['status' => 'success', 'message' => $lang['ok']]);
            exit();
        }

        $_SESSION['success'] = $lang['ok'];
        header("Location: room_service.php?booking_id=" . $booking_id);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        if ($is_ajax) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }
        $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດ: " . $e->getMessage();
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບລາຍການອາຫານ/ບໍລິການ!";
        header("Location: room_service.php?booking_id=" . (int)$_GET['booking_id']);
        exit();
    }
    $id = (int)$_GET['delete'];
    $booking_id = (int)$_GET['booking_id'];
    
    $pdo->beginTransaction();
    try {
        // Get service info to restore values
        $stmt = $pdo->prepare("SELECT total_price, prod_id, qty FROM room_services WHERE id = ?");
        $stmt->execute([$id]);
        $service = $stmt->fetch();
        
        if ($service) {
            // Delete record
            $delStmt = $pdo->prepare("DELETE FROM room_services WHERE id = ?");
            $delStmt->execute([$id]);

            // Restore food_charge
            $updateBooking = $pdo->prepare("UPDATE bookings SET food_charge = food_charge - ? WHERE id = ?");
            $updateBooking->execute([$service['total_price'], $booking_id]);

            // Restore stock if it was a product
            if ($service['prod_id']) {
                $restoreStock = $pdo->prepare("UPDATE products SET qty = qty + ? WHERE prod_id = ?");
                $restoreStock->execute([$service['qty'], $service['prod_id']]);
            }
        }
        $pdo->commit();
        $_SESSION['success'] = $lang['ok'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດ: " . $e->getMessage();
    }
    header("Location: room_service.php?booking_id=" . $booking_id);
    exit();
}

// Handle Clear All
if (isset($_GET['clear_all'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບລາຍການອາຫານ/ບໍລິການ!";
        header("Location: room_service.php?booking_id=" . (int)$_GET['clear_all']);
        exit();
    }
    $booking_id = (int)$_GET['clear_all'];
    
    $pdo->beginTransaction();
    try {
        // 1. Get all items to restore stock
        $stmt = $pdo->prepare("SELECT prod_id, qty FROM room_services WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        $items = $stmt->fetchAll();
        
        foreach($items as $item) {
            if ($item['prod_id']) {
                $restoreStock = $pdo->prepare("UPDATE products SET qty = qty + ? WHERE prod_id = ?");
                $restoreStock->execute([$item['qty'], $item['prod_id']]);
            }
        }

        // 2. Delete all records
        $delStmt = $pdo->prepare("DELETE FROM room_services WHERE booking_id = ?");
        $delStmt->execute([$booking_id]);

        // 3. Reset food_charge in bookings
        $resetBooking = $pdo->prepare("UPDATE bookings SET food_charge = 0 WHERE id = ?");
        $resetBooking->execute([$booking_id]);

        $pdo->commit();
        $_SESSION['success'] = $lang['ok'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດ: " . $e->getMessage();
    }
    header("Location: room_service.php?booking_id=" . $booking_id);
    exit();
}

// Fetch services for selected booking
$services = [];
$total_accumulated = 0;
if ($selected_booking_id > 0) {
    $stmt = $pdo->prepare("
        SELECT rs.item_name, rs.price, SUM(rs.qty) as qty, SUM(rs.total_price) as total_price, 
               MAX(rs.id) as id, rs.prod_id, MAX(p.image) as prod_image, MAX(p.category) as prod_category, MAX(p.prod_code) as prod_code,
               MAX(p.prod_name_la) as prod_name_la, MAX(p.prod_name_en) as prod_name_en, MAX(p.prod_name_cn) as prod_name_cn
        FROM room_services rs 
        LEFT JOIN products p ON rs.prod_id = p.prod_id 
        WHERE rs.booking_id = ? 
        GROUP BY rs.prod_id, rs.item_name, rs.price
        ORDER BY id ASC
    ");
    $stmt->execute([$selected_booking_id]);
    $services = $stmt->fetchAll();
    
    $prod_counts = [];
    foreach ($services as $s) {
        $total_accumulated += $s['total_price'];
        $pid = $s['prod_id'];
        if ($pid) {
            $prod_counts[$pid] = ($prod_counts[$pid] ?? 0) + $s['qty'];
        }
    }
} else {
    $prod_counts = [];
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['pos_title']; ?></title>
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
    <!-- Select2 -->
    <link rel="stylesheet" href="../plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="../plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
    <link rel="stylesheet" href="../assets/css/pages/room_service.css">
</head>
<body>
    <audio id="orderSound" src="https://assets.mixkit.co/active_storage/sfx/2868/2868-preview.mp3" preload="auto"></audio>

<div class="header-section d-flex justify-content-between align-items-center">
    <h4 class="m-0"><i class="fas fa-concierge-bell mr-2"></i> <?php echo $lang['pos_title']; ?></h4>
    <div class="d-flex align-items-center">
        <span class="mr-3"><i class="fas fa-calendar-day mr-1"></i> <?php echo date('d/m/Y'); ?></span>
        <a href="../Homepage.php" class="btn btn-light btn-sm rounded-pill px-3"><i class="fas fa-home"></i> <?php echo $lang['home']; ?></a>
    </div>
</div>

<div class="main-container">
    <!-- Products Panel -->
    <div class="product-column">
        <div class="search-area">
            <div class="row">
                <div class="col-12">
                    <div class="input-group barcode-group h-100">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                        </div>
                        <input type="text" id="mainSearch" class="form-control" placeholder="<?php echo $lang['search_product_placeholder']; ?>" autofocus autocomplete="off" style="height: 50px; font-size: 1.1rem;">
                    </div>
                </div>
            </div>
        </div>

        <div class="category-bar">
            <div class="cate-pill active" data-cate="all"><?php echo $lang['all_categories']; ?></div>
            <?php foreach($categories as $c): ?>
                <?php
                    $c_disp = $c[$cat_name_col];
                    if (empty($c_disp)) {
                        if ($c['name'] == 'ອາຫານ') $c_disp = $lang['cat_food_label'] ?? 'Food';
                        elseif ($c['name'] == 'ເຄື່ອງດື່ມ') $c_disp = $lang['cat_drinks_label'] ?? 'Drinks';
                        elseif ($c['name'] == 'ເຂົ້າໜົມ' || $c['name'] == 'ຂະໜົມ') $c_disp = $lang['cat_snacks_label'] ?? 'Snacks';
                        else $c_disp = $c['name'];
                    }
                ?>
                <div class="cate-pill" data-cate="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c_disp); ?></div>
            <?php endforeach; ?>
        </div>

        <div class="product-scroll" id="prodGrid">
            <div id="noProductsMsg" class="col-12 text-center py-5 text-muted w-100" style="display: none; grid-column: 1 / -1;">
                <i class="fas fa-search fa-3x mb-3 d-block" style="color: #ddd;"></i>
                <h5><?php echo $lang['no_products_found']; ?></h5>
            </div>
            <?php foreach($products_list as $p): ?>
                <div class="product-card" 
                     data-id="<?php echo $p['prod_id']; ?>" 
                     data-name="<?php echo htmlspecialchars($p[$prod_name_col] ?: $p['prod_name']); ?>" 
                     data-price="<?php echo $p['sprice']; ?>"
                     data-cate="<?php echo htmlspecialchars($p['category']); ?>">
                    
                    <div class="product-img-wrapper">
                        <span class="qty-badge" id="qty-badge-<?php echo $p['prod_id']; ?>" style="display: none;">0</span>
                        <?php 
                            $prod_img_src = (!empty($p['image']) && file_exists('../assets/img/products/'.$p['image'])) ? '../assets/img/products/' . htmlspecialchars($p['image']) : '../assets/img/image.jpg';
                        ?>
                        <img src="<?php echo $prod_img_src; ?>" alt="<?php echo htmlspecialchars($p[$prod_name_col] ?: $p['prod_name']); ?>">
                    </div>

                    <div class="product-card-body">
                        <div class="product-name"><?php echo htmlspecialchars($p[$prod_name_col] ?: $p['prod_name']); ?></div>
                        <div class="product-price"><?php echo formatCurrency($p['sprice']); ?></div>
                        <div class="product-stock"><?php echo $lang['remaining']; ?>: <?php echo $p['qty']; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Order Panel -->
    <div class="order-column" id="orderContainer">
        <div class="room-selector-area">
            <label class="text-muted small text-uppercase font-weight-bold mb-2 d-block"><i class="fas fa-search mr-1"></i> <?php echo $lang['select_room_to_order']; ?></label>
            <div class="d-flex align-items-center" style="gap: 8px;">
                <div style="flex: 1;">
                    <select id="roomSelect" class="form-control form-control-lg border-primary select2">
                        <?php foreach($active_bookings as $b): ?>
                            <option value="<?php echo $b['booking_id']; ?>" 
                                    data-room="<?php echo htmlspecialchars($b['room_number']); ?>"
                                    data-cust="<?php echo htmlspecialchars($b['customer_name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($b['customer_phone'] ?: '-'); ?>"
                                    data-type="<?php echo htmlspecialchars($b['room_type'] ?: '-'); ?>"
                                    <?php echo ($selected_booking_id == $b['booking_id']) ? 'selected' : ''; ?>>
                                <?php echo $lang['room']; ?> <?php echo htmlspecialchars($b['room_number']); ?> - <?php echo htmlspecialchars($b['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="btnShowRoomGrid" title="<?php echo $lang['view_room_grid']; ?>">
                    <i class="fas fa-th fa-lg"></i>
                </button>
            </div>
            <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle text-info"></i> <?php echo $lang['click_blue_to_view_all']; ?></p>
        </div>

        <div class="order-list">
            <?php if (count($services) > 0): ?>
                <?php foreach ($services as $row): ?>
                    <div class="order-item" data-prod-id="<?php echo $row['prod_id']; ?>" data-qty="<?php echo $row['qty']; ?>">
                        <div class="order-item-thumb">
                            <?php if(!empty($row['prod_image']) && file_exists('../assets/img/products/'.$row['prod_image'])): ?>
                                <img src="../assets/img/products/<?php echo $row['prod_image']; ?>" alt="">
                            <?php else: ?>
                                <?php 
                                    $icon = 'fas fa-box';
                                    $c = strtolower($row['prod_category'] ?? '');
                                    if(strpos($c, 'ອາຫານ') !== false || strpos($c, 'food') !== false) $icon = 'fas fa-utensils';
                                    if(strpos($c, 'ເຄື່ອງດື່ມ') !== false || strpos($c, 'drink') !== false) $icon = 'fas fa-glass-whiskey';
                                    if(strpos($c, 'ເຂົ້າໜົມ') !== false || strpos($c, 'snack') !== false) $icon = 'fas fa-cookie';
                                ?>
                                <i class="<?php echo $icon; ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="order-item-info">
                            <div class="order-item-name"><?php echo htmlspecialchars($row['prod_id'] ? ($row[$prod_name_col] ?: $row['item_name']) : $row['item_name']); ?></div>
                            <?php if(!empty($row['prod_code'])): ?>
                                <div class="text-muted small" style="font-size: 0.7rem;">Code: <?php echo htmlspecialchars($row['prod_code']); ?></div>
                            <?php endif; ?>
                            <div class="order-item-price text-success font-weight-bold"><?php echo formatCurrency($row['price']); ?> x <?php echo $row['qty']; ?></div>
                        </div>
                        <div class="text-right mr-3">
                            <div class="font-weight-bold"><?php echo formatCurrency($row['total_price']); ?></div>
                        </div>
                        <?php if ($can_delete): ?>
                        <div class="delete-item" onclick="confirmDelete(<?php echo $row['id']; ?>, <?php echo $selected_booking_id; ?>)">
                            <i class="fas fa-times-circle fa-lg"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-shopping-cart fa-3x mb-3 opacity-2"></i>
                    <p><?php echo $lang['no_data']; ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="order-footer">
            <div class="total-box">
                <span class="total-label"><?php echo $lang['grand_total']; ?>:</span>
                <span class="total-amount"><?php echo formatCurrency($total_accumulated); ?></span>
            </div>
            <?php if ($can_delete): ?>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-danger btn-block rounded-pill" onclick="clearAllItems(<?php echo $selected_booking_id; ?>)">
                    <i class="fas fa-trash-alt mr-1"></i> <?php echo $lang['clear_all_btn']; ?>
                </button>
            </div>
            <?php endif; ?>
            <p class="text-center text-muted small mt-3 mb-0"><?php echo $lang['pos_help_msg'] ?? 'Click on product to add to cart'; ?></p>
        </div>
    </div>
</div>

<!-- Manual Entry Modal -->
<!-- <div class="modal fade" id="manualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="" method="post">
                <input type="hidden" name="booking_id" id="modal_booking_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><?php echo $lang['add_manually'] ?? 'Add Manually'; ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $lang['item_label']; ?></label>
                        <input type="text" name="item_name" class="form-control" required placeholder="...">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['price']; ?></label>
                                <input type="text" name="price" class="form-control number-format" required value="0">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label><?php echo $lang['total'] ?? 'Qty'; ?></label>
                                <input type="number" name="qty" class="form-control" value="1" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $lang['close']; ?></button>
                    <button type="submit" name="add_service" class="btn btn-primary px-4"><?php echo $lang['save']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div> -->

<!-- Hidden Quick Add Form -->
<form id="quickAddForm" action="" method="post" style="display:none;">
    <input type="hidden" name="add_service" value="1">
    <input type="hidden" name="booking_id" id="quick_booking_id">
    <input type="hidden" name="prod_id" id="quick_prod_id">
    <input type="hidden" name="item_name" id="quick_item_name">
    <input type="hidden" name="price" id="quick_price">
    <input type="hidden" name="qty" value="1">
</form>

<!-- Room Selection Modal (Visual Grid) -->
<div class="modal fade" id="roomGridModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-th-large mr-2"></i> <?php echo $lang['select_room_guest']; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body bg-light">
                <div class="mb-3">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                        </div>
                        <input type="text" id="gridRoomSearch" class="form-control" placeholder="<?php echo $lang['search_room_guest']; ?>">
                    </div>
                </div>
                <div class="room-grid-container" id="roomGridItems">
                    <?php foreach($active_bookings as $b): ?>
                        <div class="room-item-card <?php echo ($selected_booking_id == $b['booking_id']) ? 'active' : ''; ?>" 
                             data-booking-id="<?php echo $b['booking_id']; ?>"
                             data-room-no="<?php echo htmlspecialchars($b['room_number']); ?>"
                             data-cust-name="<?php echo htmlspecialchars($b['customer_name']); ?>">
                            <div class="room-no"><?php echo htmlspecialchars($b['room_number']); ?></div>
                            <div class="cust-name"><?php echo htmlspecialchars($b['customer_name']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../plugins/select2/js/select2.full.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
const allProducts = <?php echo json_encode($products_list); ?>;
const initialCounts = <?php echo json_encode($prod_counts); ?>;

$(function() {
    // Keep barcode input focused
    $('#barcodeInput').focus();
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
            $('.product-card').show();
            $('#noProductsMsg').hide();
            return;
        }

        // 1. Try barcode match first (Exact match)
        let product = allProducts.find(p => p.prod_code === val);
        if (product) {
            quickAdd(product.prod_id, product.prod_name, product.sprice);
            $(this).val(''); // Clear for next scan
            $('.product-card').show(); // Reset filter
            $('#noProductsMsg').hide();
            return;
        }

        // 2. Otherwise treat as name search filter
        var visibleCount = 0;
        var searchVal = val.toLowerCase();
        $('.product-card').each(function() {
            var name = $(this).data('name').toLowerCase();
            if (name.indexOf(searchVal) > -1) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });

        if (visibleCount === 0) {
            $('#noProductsMsg').show();
        } else {
            $('#noProductsMsg').hide();
        }
    });

    // Also handle Enter key for barcode scanners that append Enter
    $('#mainSearch').on('keypress', function(e) {
        if (e.which === 13) {
            // Trigger input logic just in case, then clear
            $(this).trigger('input');
        }
    });

    $('.cate-pill').on('click', function() {
        $('.cate-pill').removeClass('active');
        $(this).addClass('active');
        $('#mainSearch').val(''); // Clear search when changing category
        var cate = $(this).data('cate');
        if (cate === 'all') {
            $('.product-card').show();
        } else {
            $('.product-card').hide();
            $('.product-card[data-cate="' + cate + '"]').show();
        }
        $('#noProductsMsg').hide();
    });

    // Restore missing logic
    initSelect2();

    $(document).on('change', '#roomSelect', function() {
        var bid = $(this).val();
        if(bid) {
            // Use AJAX load to prevent page jump/reload
            $('#orderContainer').load('room_service.php?booking_id=' + bid + ' #orderContainer > *', function() {
                initSelect2();
                updateProductBadges();
                // Update URL without refreshing the page
                history.pushState({bid: bid}, '', 'room_service.php?booking_id=' + bid);

                // On mobile, scroll to food items after selecting a room
                if ($(window).width() < 992) {
                    $('html, body').animate({
                        scrollTop: $(".products-column").offset().top - 20
                    }, 500);
                }
            });
        }
    });

    $(document).on('click', '#btnShowRoomGrid', function() {
        $('#roomGridModal').modal('show');
    });

    $(document).on('click', '.room-item-card', function() {
        var bid = $(this).data('booking-id');
        $('#roomGridModal').modal('hide');
        
        // Use AJAX load to prevent page jump/reload
        $('#orderContainer').load('room_service.php?booking_id=' + bid + ' #orderContainer > *', function() {
            initSelect2();
            updateProductBadges();
            history.pushState({bid: bid}, '', 'room_service.php?booking_id=' + bid);

            // On mobile, scroll to food items after selecting a room
            if ($(window).width() < 992) {
                $('html, body').animate({
                    scrollTop: $(".products-column").offset().top - 20
                }, 500);
            }
        });
    });

    $('#gridRoomSearch').on('keyup', function() {
        var val = $(this).val().toLowerCase();
        $('.room-item-card').each(function() {
            var roomNo = String($(this).data('room-no')).toLowerCase();
            var custName = $(this).data('cust-name').toLowerCase();
            if (roomNo.indexOf(val) > -1 || custName.indexOf(val) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    $('.product-card').on('click', function() {
        quickAdd($(this).data('id'), $(this).data('name'), $(this).data('price'));
    });

    function quickAdd(id, name, price) {
        var canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
        if (!canEdit) {
            Swal.fire('<?php echo $lang['error_label']; ?>', 'ທ່ານບໍ່ມີສິດໃນການສັ່ງອາຫານ/ບໍລິການ!', 'error');
            return;
        }
        var bookingId = $('#roomSelect').val();

        if(!bookingId) {
            Swal.fire('<?php echo $lang['error_label']; ?>', '<?php echo $lang['select_room_before_msg']; ?>', 'error');
            return;
        }

        $.ajax({
            url: 'room_service.php',
            method: 'POST',
            data: {
                add_service: 1,
                ajax: 1,
                booking_id: bookingId,
                prod_id: id,
                item_name: name,
                price: price,
                qty: 1
            },
            success: function(response) {
                // Refresh only the order list part
                $('#orderContainer').load('room_service.php?booking_id=' + bookingId + ' #orderContainer > *', function() {
                    initSelect2();
                    updateProductBadges();
                });
                
                /* 
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: '<?php echo str_replace('%s', "' + name + '", $lang['added_msg']); ?>'
                });
                */
                
                // Play sound immediately on success
                let sound = document.getElementById('orderSound');
                sound.pause();
                sound.currentTime = 0;
                sound.play().catch(e => console.log("Sound play blocked"));
            }
        });
    }

    function initSelect2() {
        if ($.fn.select2) {
            $('.select2').select2({
                theme: 'bootstrap4',
                placeholder: "<?php echo $lang['select_room'] ?? 'Select Room...'; ?>",
                minimumResultsForSearch: Infinity
            });
        }
    }

    function updateProductBadges(countsObj) {
        // Reset all badges
        $('.qty-badge').hide().text('0');
        
        var counts = countsObj || {};
        
        if (!countsObj) {
            // Count items from the DOM if no counts object provided
            $('.order-item').each(function() {
                var pid = $(this).data('prod-id');
                var q = parseInt($(this).data('qty')) || 0;
                if (pid) {
                    counts[pid] = (counts[pid] || 0) + q;
                }
            });
        }
        
        // Apply counts to badges
        for (var pid in counts) {
            if (counts[pid] > 0) {
                $('#qty-badge-' + pid).text(counts[pid]).show();
            }
        }
    }
    
    // Initial call with server-side counts for instant rendering
    updateProductBadges(initialCounts);
});

function confirmDelete(id, booking_id) {
    Swal.fire({
        title: '<?php echo $lang['confirm_delete_question'] ?? 'Confirm Delete?'; ?>',
        text: "<?php echo $lang['delete_confirm_msg']; ?>",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo $lang['confirm']; ?>',
        cancelButtonText: '<?php echo $lang['cancel']; ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'room_service.php?delete=' + id + '&booking_id=' + booking_id;
        }
    });
}

function clearAllItems(booking_id) {
    Swal.fire({
        title: '<?php echo $lang['confirm_clear_all_question']; ?>',
        text: "<?php echo $lang['clear_all_warning_msg']; ?>",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo $lang['confirm_cancel']; ?>',
        cancelButtonText: '<?php echo $lang['back']; ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'room_service.php?clear_all=' + booking_id;
        }
    });
}
    function notifyTransfer(bid, rnum, amt) {
        if(bid === 0) return;
        
        Swal.fire({
            title: 'ແຈ້ງ Admin ວ່າໂອນແລ້ວ?',
            text: 'ຈຳນວນ: ' + amt.toLocaleString() + ' ' + '<?php echo $defCurr['currency_name']; ?>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ແຈ້ງເລີຍ',
            cancelButtonText: 'ຍົກເລີກ'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('ajax_confirm_payment.php', {
                    booking_id: bid,
                    room_number: rnum,
                    amount: amt
                }, function(res) {
                    const data = JSON.parse(res);
                    if(data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'ແຈ້ງ Admin ສຳເລັດ',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                });
            }
        });
    }
</script>

<!-- Hidden form for Quick Add -->
<form id="formAddService" action="" method="post" style="display:none;">
    <input type="hidden" name="add_service" value="1">
    <input type="hidden" name="booking_id" id="form_booking_id">
    <input type="hidden" name="prod_id" id="form_prod_id">
    <input type="hidden" name="item_name" id="form_item_name">
    <input type="hidden" name="price" id="form_price">
    <input type="hidden" name="qty" value="1">
</form>

</body>
</html>
