<?php
require_once '../config/session_check.php';
enforcePermission('checkout');
require_once '../config/db.php';
$is_decimal_curr = in_array($defCurr['currency_code'] ?? 'LAK', ['USD', 'CNY', 'EUR']);

// --- 1. ສ່ວນໂຫຼດໄຟລ໌ພາສາ ແລະ ຕັ້ງຄ່າ Session (Checkout Language Loader) ---
// ກວດສອບ ແລະ ດຶງພາສາປັດຈຸບັນຂອງລະບົບຈາກ Session, ຫາກບໍ່ມີໃຫ້ເລືອກພາສາລາວ 'la' ເປັນພາສາເລີ່ມຕົ້ນ
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('checkout_edit'));
$can_delete = ($is_admin || hasPermission('checkout_delete'));

// Handle Checkout Confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_checkout'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການ Check-out!";
        header("Location: checkout.php");
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $room_id = (int)$_POST['room_id'];
    $payment_method = $_POST['payment_method'];
    
    // Fetch rate
    $rate = (float)($defCurr['exchange_rate'] ?? 1);
    if ($rate <= 0) $rate = 1;
    
    $amount_received = (float)str_replace(',', '', $_POST['amount_received']) * $rate;
    $change_amount = (float)str_replace(',', '', $_POST['change_amount']) * $rate;
    
    // Update booking status and payment info
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'Completed', payment_method = ?, amount_received = ?, change_amount = ? WHERE id = ?");
    if ($stmt->execute([$payment_method, $amount_received, $change_amount, $booking_id])) {
        // Update room status to Available and Needs Cleaning
        $pdo->prepare("UPDATE rooms SET status = 'Available', housekeeping_status = 'Cleaning' WHERE id = ?")->execute([$room_id]);
        
        $_SESSION['print_booking'] = $booking_id;
        
        logActivity($pdo, "Check-out ສຳເລັດ", "Booking ID: $booking_id, ວິທີຊຳລະ: $payment_method");
        
        header("Location: checkout.php?status=success");
        exit();
    } else {
        $_SESSION['error'] = $lang['checkout_error'] ?? "ເກີດຂໍ້ຜິດພາດໃນການ Check-out!";
    }
}

// Fetch active bookings for the list with localized room type
$current_lang = $_SESSION['lang'] ?? 'la';
$room_type_col = "room_type_name_" . $current_lang;

$stmt = $pdo->query("
    SELECT b.*, r.room_number, rt.$room_type_col as room_type_localized, rt.room_type_name as room_type_base
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE b.status = 'Occupied'
    GROUP BY b.id
    ORDER BY r.room_number ASC
");
$active_bookings = $stmt->fetchAll();

$selected_booking = null;
if (isset($_GET['booking_id'])) {
    $bid = (int)$_GET['booking_id'];
    foreach ($active_bookings as $b) {
        if ($b['id'] == $bid) {
            $selected_booking = $b;
            break;
        }
    }
    
    // If selected booking found, get detailed room services
    if ($selected_booking) {
        $svcStmt = $pdo->prepare("
            SELECT rs.item_name, rs.price, SUM(rs.qty) as qty, SUM(rs.total_price) as total_price,
                   p.prod_name_la, p.prod_name_en, p.prod_name_cn
            FROM room_services rs 
            LEFT JOIN products p ON rs.prod_id = p.prod_id
            WHERE rs.booking_id = ? 
            GROUP BY rs.prod_id, rs.item_name, rs.price 
            ORDER BY rs.id ASC
        ");
        $svcStmt->execute([$bid]);
        $room_services = $svcStmt->fetchAll();
        
        // Fetch Tax Percent
        $stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
        $tax_percent = (float)($stmtTax->fetchColumn() ?: 0);

        // Current lang cols
        $prod_name_col = "prod_name_" . $current_lang;
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['checkout_system_title']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/css/pages/checkout.css">
    <script>
        // Guard: If not in iframe, redirect to menu_admin
        if (window.top === window.self) {
            window.location.href = '../menu_admin.php';
        }
    </script>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_GET['status']) && $_GET['status'] == 'success' && isset($_SESSION['print_booking'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $lang['checkout_success'] ?? 'Check-out Success!'; ?>',
                    text: '<?php echo $lang['print_bill_question'] ?? 'Do you want to print the receipt?'; ?>',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-print"></i> <?php echo $lang['print_bill']; ?>',
                    cancelButtonText: '<?php echo $lang['close']; ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        var printUrl = '../print/print_room_receipt.php?booking_id=<?php echo $_SESSION['print_booking']; ?>';
                        var printFrame = document.createElement('iframe');
                        printFrame.style.display = 'none';
                        printFrame.src = printUrl;
                        document.body.appendChild(printFrame);
                    }
                });
            });
        </script>
    <?php unset($_SESSION['print_booking']); endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>',
                    text: '<?php echo $_SESSION['error']; ?>',
                    confirmButtonText: '<?php echo $lang['ok']; ?>'
                });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="fas fa-sign-out-alt"></i> <?php echo $lang['checkout_system_title']; ?></h2>
        </div>
    </div>

    <div class="row">
        <!-- List of Occupied Rooms -->
        <div class="col-md-4">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header p-2">
                    <div class="input-group">
                        <input type="text" id="room_search_input" class="form-control form-control-sm" placeholder="<?php echo $lang['search_room_guest']; ?>">
                        <div class="input-group-append">
                            <span class="input-group-text"><i class="fas fa-search text-primary"></i></span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column">
                        <?php if (count($active_bookings) > 0): ?>
                            <div id="room_list_container">
                            <?php foreach($active_bookings as $b): ?>
                                <li class="nav-item room-item">
                                    <a href="?booking_id=<?php echo $b['id']; ?>" class="nav-link <?php echo (isset($_GET['booking_id']) && $_GET['booking_id'] == $b['id']) ? 'active bg-primary' : ''; ?>" style="border-bottom: 1px solid #eee;">
                                        <i class="fas fa-door-closed mr-2"></i> <?php echo $lang['room']; ?> <strong class="room-num"><?php echo htmlspecialchars($b['room_number']); ?></strong>
                                        <span class="float-right badge <?php echo (isset($_GET['booking_id']) && $_GET['booking_id'] == $b['id']) ? 'badge-light' : 'badge-primary'; ?>"><?php echo $lang['select'] ?? 'Select'; ?></span>
                                        <div class="small mt-1 text-muted <?php echo (isset($_GET['booking_id']) && $_GET['booking_id'] == $b['id']) ? 'text-white' : ''; ?>">
                                            <i class="fas fa-user"></i> <span class="guest-name"><?php echo htmlspecialchars($b['customer_name']); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <li class="nav-item p-3 text-center text-muted">
                                <?php echo $lang['no_occupied_rooms_msg']; ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Checkout Invoice Details -->
        <div class="col-md-8">
            <?php if ($selected_booking): ?>
                <div class="card shadow-sm border-success">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="card-title text-success font-weight-bold" style="font-size: 1.5rem;">
                                <i class="fas fa-file-invoice-dollar mr-2"></i> <?php echo $lang['payment_info'] ?? 'ສະຫຼຸບການຊຳລະເງິນ'; ?>
                            </h3>
                            <span class="text-muted small"><?php echo $lang['date_label']; ?>: <?php echo date('d/m/Y'); ?></span>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-6 col-sm-6 mb-3">
                                <div class="text-muted small text-uppercase font-weight-bold mb-1"><?php echo $lang['customer_info']; ?></div>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($selected_booking['customer_name']); ?></div>
                                <div class="small text-muted"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($selected_booking['customer_phone']); ?></div>
                                <?php if($selected_booking['address']): ?>
                                    <div class="small text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_booking['address']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-6 col-sm-6 text-right mb-3">
                                <div class="text-muted small text-uppercase font-weight-bold mb-1"><?php echo $lang['stay_info']; ?></div>
                                <div class="font-weight-bold text-success"><i class="fas fa-door-open"></i> <?php echo $lang['room']; ?> <?php echo htmlspecialchars($selected_booking['room_number']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($selected_booking['room_type_localized'] ?: $selected_booking['room_type_base']); ?></div>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <div class="d-flex justify-content-between small">
                                    <span>Check-in: <span class="font-weight-bold text-dark"><?php echo date('d/m/Y', strtotime($selected_booking['check_in_date'])); ?></span></span>
                                    <span>Check-out: <span class="font-weight-bold text-danger"><?php echo date('d/m/Y', strtotime($selected_booking['check_out_date'])); ?></span></span>
                                </div>
                                <?php 
                                    $today = date('Y-m-d');
                                    $checkout_date = $selected_booking['check_out_date'];
                                    if ($today < $checkout_date): ?>
                                        <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small"><i class="fas fa-exclamation-triangle"></i> <?php echo $lang['not_due_yet']; ?></div>
                                    <?php elseif ($today > $checkout_date): ?>
                                        <div class="alert alert-danger py-1 px-2 mt-2 mb-0 small"><i class="fas fa-clock"></i> <?php echo $lang['overdue']; ?></div>
                                    <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered mb-0" style="font-size: 0.9rem;">
                                <thead class="bg-light">
                                    <tr style="font-size: 0.8rem; color: #555;">
                                        <th width="50%"><?php echo $lang['item_label'] ?? 'ລາຍການ'; ?></th>
                                        <th class="text-center"><?php echo $lang['qty_label'] ?? 'ຈຳນວນ'; ?></th>
                                        <th class="text-right"><?php echo $lang['price']; ?></th>
                                        <th class="text-right"><?php echo $lang['total']; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                        $d1 = new DateTime($selected_booking['check_in_date']);
                                        $d2 = new DateTime($selected_booking['check_out_date']);
                                        $nights = $d1->diff($d2)->days ?: 1;
                                        $price_per_night = $selected_booking['total_price'] / $nights;
                                    ?>
                                    <!-- Room Charge -->
                                    <tr class="bg-light-yellow">
                                        <td>
                                            <strong><?php echo $lang['room']; ?> <?php echo $selected_booking['room_number']; ?></strong> (<?php echo $nights; ?> <?php echo $lang['nights_label'] ?? 'ຄືນ'; ?>)
                                        </td>
                                        <td class="text-center">-</td>
                                        <td class="text-right"><?php echo formatCurrency($price_per_night); ?></td>
                                        <td class="text-right font-weight-bold text-primary"><?php echo formatCurrency($selected_booking['total_price']); ?></td>
                                    </tr>
                                    
                                    <!-- Food & Services -->
                                    <?php if(count($room_services) > 0): ?>
                                        <tr class="bg-light">
                                            <td colspan="4" class="py-1"><strong><i class="fas fa-utensils mr-1"></i> <?php echo $lang['additional_services'] ?? 'ຄ່າອາຫານ/ບໍລິການ'; ?></strong></td>
                                        </tr>
                                        <?php foreach($room_services as $svc): ?>
                                            <tr style="font-size: 0.85rem;">
                                                <td class="pl-3"><?php echo htmlspecialchars($svc[$prod_name_col] ?: $svc['item_name']); ?></td>
                                                <td class="text-center"><?php echo $svc['qty']; ?></td>
                                                <td class="text-right"><?php echo formatCurrency($svc['price']); ?></td>
                                                <td class="text-right"><?php echo formatCurrency($svc['total_price']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <?php 
                                        $subtotal = $selected_booking['total_price'] + $selected_booking['food_charge'];
                                        $tax_amount = round($subtotal * ($tax_percent / 100));
                                        $total_after_tax = $subtotal + $tax_amount;
                                        $grand_total = $total_after_tax - ($selected_booking['deposit_amount'] ?? 0);
                                        if ($grand_total < 0) $grand_total = 0;
                                        
                                        $rate = (float)($defCurr['exchange_rate'] ?? 1);
                                        if ($rate <= 0) $rate = 1;
                                        $is_decimal_curr = in_array($defCurr['currency_code'] ?? 'LAK', ['USD', 'CNY', 'EUR']);
                                        
                                        $grand_total_converted = $grand_total / $rate;
                                        $total_after_tax_converted = $total_after_tax / $rate;
                                    ?>
                                    
                                    <!-- Subtotal -->
                                    <tr style="border-top: 2px solid #dee2e6;">
                                        <td colspan="3" class="text-right"><?php echo $lang['subtotal'] ?? 'ລວມຍ່ອຍ'; ?>:</td>
                                        <td class="text-right"><strong><?php echo formatCurrency($subtotal); ?></strong></td>
                                    </tr>
                                    
                                    <!-- Tax Row -->
                                    <?php if($tax_percent > 0): ?>
                                    <tr class="text-muted" style="font-size: 0.85rem;">
                                        <td colspan="3" class="text-right"><?php echo $lang['tax_percent'] ?? 'ພາສີ'; ?> (<?php echo $tax_percent; ?>%):</td>
                                        <td class="text-right"><?php echo formatCurrency($tax_amount); ?></td>
                                    </tr>
                                    <?php endif; ?>

                                    <!-- Total after Tax -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="3" class="text-right"><?php echo $lang['total_after_tax'] ?? 'ລາຄາລວມ'; ?>:</td>
                                        <td class="text-right text-primary"><strong><?php echo formatCurrency($total_after_tax); ?></strong></td>
                                    </tr>
                                    
                                    <!-- Deduct Paid Deposit / Prepayment -->
                                    <?php if(isset($selected_booking['deposit_amount']) && $selected_booking['deposit_amount'] > 0): ?>
                                        <tr class="bg-light text-success font-weight-bold">
                                            <td colspan="3" class="text-right">
                                                <i class="fas fa-check-circle text-success mr-1"></i> <?php echo $lang['room_fee_paid'] ?? 'ຄ່າຫ້ອງຊຳລະແລ້ວ'; ?>:
                                            </td>
                                            <td class="text-right text-success"><?php echo formatCurrency($selected_booking['deposit_amount']); ?></td>
                                        </tr>
                                    <?php endif; ?>

                                    <tr class="bg-dark text-white">
                                        <td colspan="3" class="text-right py-2"><?php echo $lang['total_due']; ?>:</td>
                                        <td class="text-right py-2 font-weight-bold" style="font-size: 1.3rem;"><?php echo formatCurrency($grand_total); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <form action="" method="post" id="checkoutForm">
                            <input type="hidden" name="confirm_checkout" value="1">
                            <input type="hidden" name="booking_id" value="<?php echo $selected_booking['id']; ?>">
                            <input type="hidden" name="room_id" value="<?php echo $selected_booking['room_id']; ?>">
                            <input type="hidden" id="grand_total_val" value="<?php echo number_format($grand_total_converted, $is_decimal_curr ? 2 : 0, '.', ''); ?>">
                            <input type="hidden" id="total_after_tax_val" value="<?php echo number_format($total_after_tax_converted, $is_decimal_curr ? 2 : 0, '.', ''); ?>">
                            <input type="hidden" id="checkout_status_msg" value="<?php 
                                if ($today < $checkout_date) echo $lang['not_due_yet'] . "! ";
                                elseif ($today > $checkout_date) echo $lang['overdue'] . "! ";
                                else echo "";
                            ?>">
                            
                            <?php if ($grand_total > 0): ?>
                            <div class="row bg-light p-3 rounded mb-4">
                                <div class="col-md-12 mb-3 border-bottom pb-2">
                                    <h5 class="text-success"><i class="fas fa-hand-holding-usd"></i> <?php echo $lang['payment_info']; ?> (<?php echo $lang['receive'] ?? 'Receive'; ?> <?php echo $defCurr['currency_name']; ?>)</h5>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?php echo $lang['payment_method_label']; ?></label>
                                        <select name="payment_method" id="payment_method" class="form-control">
                                            <option value="Cash"><?php echo $lang['cash']; ?></option>
                                            <option value="Transfer"><?php echo $lang['transfer']; ?></option>
                                        </select>
                                    </div>
                                    <button type="button" id="btn_notify_transfer" class="btn btn-outline-info btn-block d-none">
                                        <i class="fas fa-paper-plane"></i> <?php echo $lang['notify_transfer'] ?? 'ແຈ້ງ Admin ວ່າໂອນແລ້ວ'; ?>
                                    </button>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?php echo $lang['amount_received_label']; ?> (<?php echo $defCurr['currency_name']; ?>)</label>
                                        <div class="input-group">
                                            <input type="text" name="amount_received" id="amount_received" class="form-control number-format text-right font-weight-bold" placeholder="0">
                                            <div class="input-group-append">
                                                <button type="button" id="btn_full_pay" class="btn btn-primary btn-sm px-3" style="font-size: 0.8rem;"><?php echo $lang['full_pay_btn']; ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?php echo $lang['change_amount_label']; ?> (<?php echo $defCurr['currency_name']; ?>)</label>
                                        <input type="text" name="change_amount" id="change_amount" class="form-control text-right text-danger font-weight-bold" value="0" readonly>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($selected_booking['payment_method'] ?: 'Cash'); ?>">
                            <input type="hidden" name="amount_received" value="<?php echo number_format(($selected_booking['amount_received'] > 0 ? $selected_booking['amount_received'] : $selected_booking['deposit_amount']), $is_decimal_curr ? 2 : 0, '.', ''); ?>">
                            <input type="hidden" name="change_amount" value="<?php echo number_format($selected_booking['change_amount'] ?: 0, $is_decimal_curr ? 2 : 0, '.', ''); ?>">
                            <div class="alert alert-success p-3 rounded mb-4 text-center" style="background-color: #d4edda; border-color: #c3e6cb; color: #155724;">
                                <h5 class="font-weight-bold"><i class="fas fa-check-circle text-success mr-2"></i> <?php echo $lang['no_balance_due'] ?? 'ຊຳລະຄ່າຫ້ອງຄົບຖ້ວນແລ້ວ (ບໍ່ມີຍອດຄ້າງຊຳລະ)'; ?></h5>
                                <p class="mb-0 text-muted" style="font-size: 0.95rem; color: #155724 !important;"><?php echo $lang['ready_for_checkout'] ?? 'ສາມາດທຳການ Check-out ໄດ້ທັນທີໂດຍບໍ່ມີຄ່າໃຊ້ຈ່າຍເພີ່ມເຕີມ.'; ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-12 text-right">
                                    <button type="button" id="btnPrintBill" class="btn btn-default mr-2"><i class="fas fa-print"></i> <?php echo $lang['print_bill']; ?></button>
                                    <?php if ($can_edit): ?>
                                        <button type="submit" name="confirm_checkout" class="btn btn-success btn-lg">
                                            <i class="fas fa-check-double"></i> <?php echo $lang['checkout'] ?? 'Check-out'; ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary btn-lg" disabled>
                                            <i class="fas fa-lock"></i> ເບິ່ງຢ່າງດຽວ
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-hand-pointer text-muted fa-4x mb-3"></i>
                        <h4 class="text-muted"><?php echo $lang['checkout_prompt']; ?></h4>
                    </div>
                </div>
            <?php endif; ?>
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
    var grandTotal = parseFloat($('#grand_total_val').val()) || 0;
    var isDecimalCurr = <?php echo $is_decimal_curr ? 'true' : 'false'; ?>;

    function formatMoneyJS(amount) {
        if (isDecimalCurr) {
            return amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            return Math.round(amount).toLocaleString('en-US');
        }
    }

    // Full Payment Shortcut
    $('#btn_full_pay').on('click', function() {
        $('#amount_received').val(formatMoneyJS(grandTotal));
        calculateChange();
    });

    // Number formatting
    $('.number-format').on('input', function(e) {
        var cleanRegex = isDecimalCurr ? /[^0-9.]/g : /[^0-9]/g;
        var value = $(this).val().replace(cleanRegex, '');
        
        // Prevent multiple dots
        if (isDecimalCurr) {
            var parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
        }
        
        if (value !== '') {
            if (isDecimalCurr && (value.endsWith('.') || (value.includes('.') && value.split('.')[1] === ''))) {
                $(this).val(value);
            } else {
                var num = parseFloat(value);
                if (!isNaN(num)) {
                    if (isDecimalCurr) {
                        var decs = value.includes('.') ? '.' + value.split('.')[1] : '';
                        $(this).val(Math.floor(num).toLocaleString('en-US') + decs);
                    } else {
                        $(this).val(Math.round(num).toLocaleString('en-US'));
                    }
                } else {
                    $(this).val('');
                }
            }
        } else {
            $(this).val('');
        }
        calculateChange();
    });

    $('#payment_method').on('change', function() {
        var method = $(this).val();
        if (method === 'Transfer') {
            $('#amount_received').val(formatMoneyJS(grandTotal));
            $('#amount_received').prop('readonly', true);
            calculateChange();
        } else {
            $('#amount_received').val('');
            $('#amount_received').prop('readonly', false);
            calculateChange();
        }
    });

    function calculateChange() {
        var received = parseFloat($('#amount_received').val().replace(/,/g, '')) || 0;
        var change = received - grandTotal;
        if (change < 0) change = 0;
        $('#change_amount').val(formatMoneyJS(change));
    }

    // Show/Hide notify button
    $('#payment_method').change(function() {
        if($(this).val() === 'Transfer') {
            $('#btn_notify_transfer').removeClass('d-none');
        } else {
            $('#btn_notify_transfer').addClass('d-none');
        }
    }).trigger('change');

    // Handle Notify Button
    $('#btn_notify_transfer').click(function() {
        const bid = <?php echo $selected_booking['id'] ?? 0; ?>;
        const rnum = '<?php echo $selected_booking['room_number'] ?? ''; ?>';
        const amt = parseFloat($('#total_after_tax_val').val()) || 0;
        
        if(bid === 0) return;

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ກຳລັງແຈ້ງ...');

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
                    text: 'Admin ຈະໄດ້ຮັບການແຈ້ງເຕືອນທັນທີ',
                    timer: 2000,
                    showConfirmButton: false
                });
                $('#btn_notify_transfer').html('<i class="fas fa-check"></i> ແຈ້ງແລ້ວ').addClass('btn-success').removeClass('btn-outline-info');
            } else {
                Swal.fire('Error', 'ບໍ່ສາມາດແຈ້ງໄດ້', 'error');
                $('#btn_notify_transfer').prop('disabled', false).html('<i class="fas fa-paper-plane"></i> ແຈ້ງ Admin ວ່າໂອນແລ້ວ');
            }
        });
    });

    $('#checkoutForm').on('submit', function(e) {
        e.preventDefault();
        var amtReceivedEl = $('#amount_received');
        var received = amtReceivedEl.length ? (parseFloat(amtReceivedEl.val().replace(/,/g, '')) || 0) : 0;
        var method = $('#payment_method').val() || 'None';
        var statusMsg = $('#checkout_status_msg').val();

        if (received < (grandTotal - 0.001) && method === 'Cash') {
            Swal.fire({
                icon: 'error',
                title: '<?php echo $lang['insufficient_balance']; ?>',
                text: '<?php echo $lang['insufficient_balance_msg']; ?>',
                confirmButtonText: '<?php echo $lang['ok']; ?>'
            });
            return false;
        }

        Swal.fire({
            title: statusMsg ? '<?php echo $lang['warning_label']; ?>: ' + statusMsg + ' <?php echo $lang['confirm_checkout_question']; ?>' : '<?php echo $lang['confirm_checkout_question']; ?>',
            text: statusMsg ? '<?php echo $lang['warning_label']; ?>: ' + statusMsg + ' <?php echo $lang['confirm_checkout_msg']; ?>' : '<?php echo $lang['confirm_checkout_msg']; ?>',
            icon: statusMsg ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Check-out',
            cancelButtonText: '<?php echo $lang['cancel']; ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#checkoutForm')[0].submit();
            }
        });
    });

    // Handle Print Bill before checkout
    $('#btnPrintBill').on('click', function() {
        var bookingId = <?php echo $selected_booking['id'] ?? 0; ?>;
        if (bookingId > 0) {
            window.open('../print/print_room_receipt.php?booking_id=' + bookingId, '_blank', 'width=800,height=600');
        }
    });

    // Room List Live Search
    $('#room_search_input').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        var visibleCount = 0;
        
        $(".room-item").each(function() {
            var isVisible = $(this).text().toLowerCase().indexOf(value) > -1;
            $(this).toggle(isVisible);
            if (isVisible) visibleCount++;
        });

        $('#no_room_msg').remove();
        if (visibleCount === 0) {
            $('#room_list_container').append('<li id="no_room_msg" class="nav-item p-3 text-center text-muted"><?php echo $lang['no_data']; ?></li>');
        }
    });
});
</script>
</body>
</html>
