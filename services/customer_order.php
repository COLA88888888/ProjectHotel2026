<?php
session_start();
require_once '../config/db.php';

// Fetch Categories
$categories = $pdo->query("SELECT * FROM product_categories ORDER BY id ASC")->fetchAll();

// Fetch Currency Info
$stmtCur = $pdo->query("SELECT * FROM currency WHERE is_default = 1 LIMIT 1");
$defCurr = $stmtCur->fetch();

// Fetch Products
$products = $pdo->query("SELECT p.*, c.category_name 
                         FROM products p 
                         JOIN product_categories c ON p.category_id = c.id 
                         WHERE p.status = 'Available' 
                         ORDER BY c.category_name ASC")->fetchAll();

// Handle Order Submission via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_order'])) {
    $room_number = $_POST['room_number'];
    $cart = json_decode($_POST['cart_data'], true);
    
    if (!empty($room_number) && !empty($cart)) {
        try {
            $pdo->beginTransaction();
            
            // We need a dummy or special booking_id if we don't know it, 
            // but for Room Service, we can search for the active booking of this room.
            $stmtBooking = $pdo->prepare("SELECT b.booking_id FROM bookings b 
                                          JOIN rooms r ON b.room_id = r.id 
                                          WHERE r.room_number = ? AND b.status IN ('Occupied', 'Checked In') LIMIT 1");
            $stmtBooking->execute([$room_number]);
            $booking = $stmtBooking->fetch();
            
            $bid = $booking ? $booking['booking_id'] : 0; // 0 if not found, admin will see room number anyway

            foreach ($cart as $item) {
                $stmt = $pdo->prepare("INSERT INTO room_services (booking_id, item_name, price, qty, total_price, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
                $total = $item['price'] * $item['qty'];
                $stmt->execute([$bid, $item['name'] . " (ຫ້ອງ $room_number)", $item['price'], $item['qty'], $total]);
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success']);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ສັ່ງອາຫານ - Room Service</title>
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif; background-color: #f8f9fa; }
        .menu-header { background: linear-gradient(135deg, #2c3e50, #34495e); color: white; padding: 30px 20px; text-align: center; border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; margin-bottom: 20px; }
        .product-card { border: none; border-radius: 15px; overflow: hidden; transition: transform 0.2s; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .product-card:active { transform: scale(0.98); }
        .product-img { height: 120px; object-fit: cover; background: #eee; width: 100%; }
        .price-tag { color: #e67e22; font-weight: bold; font-size: 1.1rem; }
        .cart-fab { position: fixed; bottom: 30px; right: 20px; width: 60px; height: 60px; border-radius: 50%; background: #27ae60; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: 0 5px 20px rgba(39, 174, 96, 0.4); z-index: 1000; border: none; }
        .cart-count { position: absolute; top: -5px; right: -5px; background: #c0392b; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; }
        .category-tab { padding: 8px 20px; border-radius: 20px; background: white; margin-right: 10px; display: inline-block; white-space: nowrap; box-shadow: 0 2px 5px rgba(0,0,0,0.05); color: #555; }
        .category-tab.active { background: #3498db; color: white; }
        .category-scroll { overflow-x: auto; white-space: nowrap; padding: 10px 0; -webkit-overflow-scrolling: touch; }
        .category-scroll::-webkit-scrollbar { display: none; }
    </style>
</head>
<body>

<div class="menu-header">
    <h2 class="font-weight-bold">Room Service 🍽️</h2>
    <p class="mb-0 opacity-75">ເລືອກອາຫານທີ່ທ່ານມັກ ແລ້ວພວກເຮົາຈະໄປສົ່ງໃຫ້ເຖິງຫ້ອງ</p>
</div>

<div class="container pb-5">
    <!-- Category Filter -->
    <div class="category-scroll px-2 mb-3">
        <div class="category-tab active" data-filter="all">ທັງໝົດ</div>
        <?php foreach($categories as $cat): ?>
            <div class="category-tab" data-filter="cat-<?= $cat['id'] ?>"><?= $cat['category_name'] ?></div>
        <?php endforeach; ?>
    </div>

    <!-- Product Grid -->
    <div class="row px-2">
        <?php foreach($products as $p): ?>
            <div class="col-6 col-md-4 mb-3 product-item cat-<?= $p['category_id'] ?>">
                <div class="card product-card h-100" onclick="addToCart(<?= $p['id'] ?>, '<?= addslashes($p['prod_name']) ?>', <?= $p['price'] ?>)">
                    <?php if($p['image']): ?>
                        <img src="uploads/products/<?= $p['image'] ?>" class="product-img">
                    <?php else: ?>
                        <div class="product-img d-flex align-items-center justify-content-center bg-light"><i class="fas fa-utensils fa-2x text-muted"></i></div>
                    <?php endif; ?>
                    <div class="card-body p-2">
                        <h6 class="font-weight-bold mb-1 text-truncate"><?= $p['prod_name'] ?></h6>
                        <div class="price-tag"><?= formatCurrency($p['price']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Cart Floating Button -->
<button class="cart-fab" onclick="showCart()">
    <i class="fas fa-shopping-basket"></i>
    <span class="cart-count" id="cartBadge" style="display:none">0</span>
</button>

<!-- Order Modal -->
<div class="modal fade" id="cartModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header border-0">
                <h5 class="modal-title font-weight-bold">ລາຍການສັ່ງອາຫານ</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-4">
                    <label class="text-primary"><i class="fas fa-door-open mr-2"></i> ກະລຸນາປ້ອນເບີຫ້ອງຂອງທ່ານ:</label>
                    <input type="text" id="room_number" class="form-control form-control-lg" placeholder="ຕົວຢ່າງ: 205" style="border-radius: 10px;">
                </div>
                
                <div id="cartList" class="mb-3">
                    <!-- Items here -->
                </div>
                
                <div class="d-flex justify-content-between align-items-center border-top pt-3">
                    <h5 class="font-weight-bold">ລວມທັງໝົດ:</h5>
                    <h4 class="text-orange font-weight-bold" id="cartTotal">0</h4>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-success btn-lg btn-block" onclick="submitOrder()" style="border-radius: 15px;">
                    <i class="fas fa-paper-plane mr-2"></i> ສັ່ງອາຫານດຽວນີ້
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let cart = [];

function addToCart(id, name, price) {
    let item = cart.find(i => i.id === id);
    if (item) {
        item.qty++;
    } else {
        cart.push({ id, name, price, qty: 1 });
    }
    updateBadge();
    
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1000
    });
    Toast.fire({ icon: 'success', title: 'ເພີ່ມແລ້ວ' });
}

function updateBadge() {
    let totalQty = cart.reduce((sum, i) => sum + i.qty, 0);
    if (totalQty > 0) {
        $('#cartBadge').text(totalQty).show();
    } else {
        $('#cartBadge').hide();
    }
}

function showCart() {
    if (cart.length === 0) {
        Swal.fire('ກະຕ່າຫວ່າງເປົ່າ', 'ກະລຸນາເລືອກອາຫານກ່ອນຄັບ', 'info');
        return;
    }
    
    let html = '';
    let total = 0;
    cart.forEach((item, index) => {
        let lineTotal = item.price * item.qty;
        total += lineTotal;
        html += `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="font-weight-bold">${item.name}</div>
                    <div class="small text-muted">${item.qty} x ${item.price.toLocaleString()}</div>
                </div>
                <div class="d-flex align-items-center">
                    <div class="font-weight-bold mr-3">${lineTotal.toLocaleString()}</div>
                    <button class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${index})"><i class="fas fa-times"></i></button>
                </div>
            </div>
        `;
    });
    
    $('#cartList').html(html);
    $('#cartTotal').text(total.toLocaleString() + ' <?= $defCurr['currency_name'] ?>');
    $('#cartModal').modal('show');
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateBadge();
    if (cart.length > 0) {
        showCart();
    } else {
        $('#cartModal').modal('hide');
    }
}

function submitOrder() {
    let room = $('#room_number').val().trim();
    if (!room) {
        Swal.fire('ຂໍ້ຜິດພາດ', 'ກະລຸນາປ້ອນເບີຫ້ອງຂອງທ່ານກ່ອນຄັບ', 'warning');
        return;
    }
    
    $.post('customer_order.php', {
        submit_order: 1,
        room_number: room,
        cart_data: JSON.stringify(cart)
    }, function(res) {
        let data = JSON.parse(res);
        if (data.status === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'ສັ່ງອາຫານສຳເລັດ!',
                text: 'ພະນັກງານຈະນຳອາຫານໄປສົ່ງໃຫ້ທ່ານເຖິງຫ້ອງໂດຍໄວຄັບ',
                confirmButtonText: 'ຕົກລົງ'
            }).then(() => {
                cart = [];
                updateBadge();
                $('#cartModal').modal('hide');
                $('#room_number').val('');
            });
        }
    });
}

$('.category-tab').on('click', function() {
    $('.category-tab').removeClass('active');
    $(this).addClass('active');
    let filter = $(this).data('filter');
    if (filter === 'all') {
        $('.product-item').fadeIn();
    } else {
        $('.product-item').hide();
        $('.' + filter).fadeIn();
    }
});
</script>

</body>
</html>
