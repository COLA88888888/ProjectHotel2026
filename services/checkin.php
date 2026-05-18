<?php
require_once '../config/session_check.php';
enforcePermission('walkin');
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
if (!$is_admin && !hasPermission('walkin_edit')) {
    $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການ Check-in ຫ້ອງພັກ!";
    header("Location: walkin.php");
    exit();
}
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// Check if accessing directly or via Walk-in
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$nights = isset($_GET['nights']) ? (int)$_GET['nights'] : 1;

$rt_name_col = "room_type_name_" . $current_lang;

if ($room_id > 0) {
    // Check if room is still available
    $stmt = $pdo->prepare("SELECT r.*, rt.room_type_name_la, rt.room_type_name_en, rt.room_type_name_cn 
                           FROM rooms r 
                           LEFT JOIN room_types rt ON r.room_type = rt.room_type_name 
                           WHERE r.id = ? AND r.status = 'Available'");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();

    if (!$room) {
        $_SESSION['error'] = $lang['room_not_available_msg'];
        header("Location: walkin.php");
        exit();
    }
} else {
    // If accessed directly from menu, just show list of available rooms to select for check-in
    header("Location: walkin.php");
    exit();
}

$base_room_price = (float)$room['price'];
$total_price = $base_room_price * $nights;
$check_in_date = date('Y-m-d');
$check_out_date = date('Y-m-d', strtotime("+$nights days"));

// Fetch Tax Percent
$stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
$tax_percent = (float)($stmtTax->fetchColumn() ?: 0);
$tax_amount = round($total_price * ($tax_percent / 100));
$grand_total = $total_price + $tax_amount;

// Fetch exchange rate and decimal check
$rate = (float)($defCurr['exchange_rate'] ?? 1);
if ($rate <= 0) $rate = 1;
$is_decimal_curr = in_array($defCurr['currency_code'] ?? 'LAK', ['USD', 'CNY', 'EUR']);

// Converted prices for display
$room_price_converted = $base_room_price / $rate;
$total_price_converted = $total_price / $rate;
$tax_amount_converted = $tax_amount / $rate;
$grand_total_converted = $grand_total / $rate;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkin'])) {
    $nights = isset($_POST['nights']) ? (int)$_POST['nights'] : $nights;
    if ($nights < 1) $nights = 1;
    $total_price = $room['price'] * $nights;
    $check_out_date = date('Y-m-d', strtotime("+$nights days"));

    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $passport_number = trim($_POST['passport_number']);
    $address = trim($_POST['address']);
    $guest_count = (int)$_POST['guest_count'];
    $deposit_amount = (float)str_replace(',', '', $_POST['deposit_amount']) * $rate;
    $payment_method = $_POST['payment_method'];
    $amount_received = 0.00;
    $change_amount = 0.00;
    if (isset($_POST['payment_status']) && $_POST['payment_status'] === 'Unpaid') {
        $deposit_amount = 0.00;
        $payment_method = 'ຍັງບໍ່ຈ່າຍ (Pay at Checkout)';
    } else {
        if ($payment_method === 'Cash') {
            $amount_received = isset($_POST['amount_received']) ? (float)str_replace(',', '', $_POST['amount_received']) * $rate : 0.00;
            $change_amount = isset($_POST['change_amount']) ? (float)str_replace(',', '', $_POST['change_amount']) * $rate : 0.00;
        } else {
            $amount_received = $deposit_amount;
            $change_amount = 0.00;
        }
    }
    
    // Generate Bill Number: YYYYMMDDXXX (e.g. 20260518001)
    $today_str = date('Ymd');
    $stmtLast = $pdo->prepare("SELECT bill_number FROM bookings WHERE bill_number LIKE ? AND bill_number REGEXP '^[0-9]+$' ORDER BY bill_number DESC LIMIT 1");
    $stmtLast->execute([$today_str . '%']);
    $lastBill = $stmtLast->fetchColumn();

    if ($lastBill) {
        $lastNum = (int)substr($lastBill, 8); // Extract sequence number after 8-digit date YYYYMMDD
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }
    $bill_number = $today_str . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

    // Save to bookings table
    $stmt = $pdo->prepare("INSERT INTO bookings (room_id, customer_name, customer_phone, passport_number, address, guest_count, check_in_date, check_out_date, total_price, deposit_amount, payment_method, amount_received, change_amount, status, bill_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Occupied', ?)");
    
    if ($stmt->execute([$room_id, $customer_name, $customer_phone, $passport_number, $address, $guest_count, $check_in_date, $check_out_date, $total_price, $deposit_amount, $payment_method, $amount_received, $change_amount, $bill_number])) {
        $booking_id = $pdo->lastInsertId();
        // Update room status to Occupied
        $updateRoom = $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
        $updateRoom->execute([$room_id]);
        
        $_SESSION['success'] = $lang['checkin_success'];
        if ($deposit_amount > 0) {
            $_SESSION['print_booking'] = $booking_id;
        }
        
        logActivity($pdo, $lang['log_checkin'], $lang['customer_label'] . ": $customer_name, " . $lang['room'] . ": " . $room['room_number']);
        
        header("Location: walkin.php");
        exit();
    } else {
        $error = $lang['error_occurred'];
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['check_in']; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free-5.15.3-web/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        @media (max-width: 768px) {
            body { padding: 10px; }
            h2 { font-size: 1.3rem !important; }
            h4 { font-size: 1rem !important; }
            .card-title { font-size: 1rem !important; }
            .card-body { padding: 10px; }
            .form-group label { font-size: 0.9rem; }
            .form-control { font-size: 0.9rem; height: calc(2rem + 2px); }
            .btn { font-size: 0.9rem; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Room Info Column -->
        <div class="col-md-4">
            <div class="card card-info card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-bed"></i> <?php echo $lang['room_details']; ?></h3>
                </div>
                <div class="card-body box-profile text-center">
                    <div class="display-3 text-info mb-3">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <h3 class="profile-username text-center"><?php echo $lang['room']; ?> <?php echo htmlspecialchars($room['room_number']); ?></h3>
                    <p class="text-muted text-center">
                        <?php 
                            $r_type = $room[$rt_name_col] ?: $room['room_type'];
                            $b_type_val = $room['bed_type'];
                            if ($b_type_val == 'ຕຽງດ່ຽວ' || strtolower($b_type_val) == 'single' || strtolower($b_type_val) == 'single bed') {
                                $b_type = $lang['single_bed'] ?? 'Single Bed';
                            } elseif ($b_type_val == 'ຕຽງຄູ່' || strtolower($b_type_val) == 'double' || strtolower($b_type_val) == 'double bed') {
                                $b_type = $lang['double_bed'] ?? 'Double Bed';
                            } else {
                                $b_type = $b_type_val;
                            }
                            echo htmlspecialchars($r_type) . " (" . htmlspecialchars($b_type) . ")";
                        ?>
                    </p>
                    
                    <ul class="list-group list-group-unbordered mb-3 text-left">
                        <li class="list-group-item">
                            <b><?php echo $lang['price']; ?> / <?php echo $lang['nights_count']; ?>:</b> <a class="float-right text-dark"><?php echo number_format($room_price_converted, $is_decimal_curr ? 2 : 0); ?> <?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></a>
                        </li>
                        <li class="list-group-item">
                            <b><?php echo $lang['nights']; ?>:</b> <a class="float-right text-dark"><span id="summary_nights"><?php echo $nights; ?></span> <?php echo $lang['nights_count']; ?></a>
                        </li>
                        <li class="list-group-item">
                            <b><?php echo $lang['checkin_date']; ?>:</b> <a class="float-right text-success"><?php echo date('d/m/Y', strtotime($check_in_date)); ?></a>
                        </li>
                        <li class="list-group-item">
                            <b><?php echo $lang['checkout_date']; ?>:</b> <a class="float-right text-danger" id="summary_checkout_date"><?php echo date('d/m/Y', strtotime($check_out_date)); ?></a>
                        </li>
                        <li class="list-group-item bg-light">
                            <b><?php echo $lang['subtotal']; ?>:</b> <a class="float-right text-dark" id="summary_subtotal"><?php echo number_format($total_price_converted, $is_decimal_curr ? 2 : 0); ?> <?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></a>
                        </li>
                        <?php if($tax_percent > 0): ?>
                        <li class="list-group-item">
                            <b><?php echo $lang['tax_percent'] ?? 'Tax'; ?> (<?php echo $tax_percent; ?>%):</b> <a class="float-right text-info" id="summary_tax_amount"><?php echo number_format($tax_amount_converted, $is_decimal_curr ? 2 : 0); ?> <?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></a>
                        </li>
                        <?php endif; ?>
                        <li class="list-group-item bg-dark">
                            <b><?php echo $lang['grand_total']; ?>:</b> <a class="float-right text-warning font-weight-bold" style="font-size: 1.1rem;" id="summary_grand_total"><?php echo number_format($grand_total_converted, $is_decimal_curr ? 2 : 0); ?> <?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Customer Form Column -->
        <div class="col-md-8">
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-edit"></i> <?php echo $lang['booking_info']; ?> (Check-in)</h3>
                </div>
                <form action="" method="post" id="checkinForm">
                    <div class="card-body">
                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?php echo $lang['full_name']; ?> <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        </div>
                                        <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="<?php echo $lang['enter_name']; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?php echo $lang['phone']; ?> <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                        </div>
                                        <input type="text" name="customer_phone" id="customer_phone" class="form-control" placeholder="<?php echo $lang['enter_phone']; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $lang['passport']; ?></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                        </div>
                                        <input type="text" name="passport_number" class="form-control" placeholder="<?php echo $lang['enter_passport']; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $lang['guests']; ?></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-users"></i></span>
                                        </div>
                                        <input type="number" name="guest_count" class="form-control" value="1" min="1">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $lang['nights'] ?? 'ຈຳນວນຄືນ'; ?> <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-moon"></i></span>
                                        </div>
                                        <input type="number" name="nights" id="nights_input" class="form-control" value="<?php echo $nights; ?>" min="1" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label><?php echo $lang['address']; ?></label>
                                    <textarea name="address" class="form-control" rows="2" placeholder="<?php echo $lang['enter_address']; ?>"></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-12 mt-2">
                                <h5 class="text-info border-bottom pb-2"><i class="fas fa-money-bill-wave"></i> <?php echo $lang['payment_info']; ?></h5>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $lang['payment_status'] ?? 'ສະຖານະການຊຳລະ'; ?> <span class="text-danger">*</span></label>
                                    <select name="payment_status" id="payment_status" class="form-control" required>
                                        <option value="Paid"><?php echo $lang['paid'] ?? 'ຈ່າຍແລ້ວ'; ?></option>
                                        <option value="Unpaid"><?php echo $lang['unpaid'] ?? 'ຍັງບໍ່ຈ່າຍ'; ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4" id="payment_method_group">
                                <div class="form-group">
                                    <label><?php echo $lang['payment_method_label']; ?> <span class="text-danger">*</span></label>
                                    <select name="payment_method" id="payment_method" class="form-control" required>
                                        <option value="Cash"><?php echo $lang['cash'] ?? 'Cash'; ?></option>
                                        <option value="Transfer"><?php echo $lang['transfer'] ?? 'Transfer'; ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><?php echo $lang['grand_total']; ?> (<?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?>) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></span>
                                        </div>
                                        <input type="text" name="deposit_amount" id="deposit_amount" class="form-control number-format" value="<?php echo number_format($grand_total_converted, $is_decimal_curr ? 2 : 0); ?>" required readonly>
                                    </div>
                                    <!-- <small class="text-muted">ລວມພາສີອາກອນແລ້ວ (<?php echo $nights; ?> ຄືນ)</small> -->
                                </div>
                            </div>
                            
                            <div class="col-md-12" id="cash_details_group" style="display: none;">
                                <div class="row bg-light p-3 rounded mb-3 border">
                                    <div class="col-md-6 col-12">
                                        <div class="form-group mb-md-0">
                                            <label class="text-success font-weight-bold"><i class="fas fa-hand-holding-usd"></i> <?php echo $lang['amount_received_label'] ?? 'ຮັບເງິນມາ'; ?> (<?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?>) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text bg-success text-white"><?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></span>
                                                </div>
                                                <input type="text" name="amount_received" id="received_amount_input" class="form-control number-format" style="font-size: 1.1rem; font-weight: bold;" placeholder="0">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="form-group mb-0">
                                            <label class="text-danger font-weight-bold"><i class="fas fa-coins"></i> <?php echo $lang['change_amount_label'] ?? 'ເງິນທອນ'; ?> (<?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?>)</label>
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text bg-danger text-white"><?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?></span>
                                                </div>
                                                <input type="text" name="change_amount" id="change_amount_display" class="form-control text-danger font-weight-bold" style="font-size: 1.1rem; background-color: #fff;" value="0" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-right bg-white border-top">
                        <a href="walkin.php" class="btn btn-default"><i class="fas fa-times"></i> <?php echo $lang['cancel']; ?></a>
                        <button type="submit" name="checkin" class="btn btn-success ml-2" style="padding-left: 30px; padding-right: 30px;">
                            <i class="fas fa-check"></i> <?php echo $lang['confirm_checkin']; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {
    var roomPrice = <?php echo $room_price_converted; ?>;
    var taxPercent = <?php echo (float)$tax_percent; ?>;
    var checkInDateStr = "<?php echo $check_in_date; ?>";
    var isDecimalCurr = <?php echo $is_decimal_curr ? 'true' : 'false'; ?>;

    function formatMoneyJS(amount) {
        if (isDecimalCurr) {
            return amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            return Math.round(amount).toLocaleString('en-US');
        }
    }

    function updateCalculations() {
        var nights = parseInt($('#nights_input').val()) || 1;
        if (nights < 1) nights = 1;
        
        var subtotal = roomPrice * nights;
        var taxAmount = Math.round(subtotal * (taxPercent / 100) * 100) / 100;
        var grandTotal = subtotal + taxAmount;
        
        // Calculate new checkout date
        var checkInDate = new Date(checkInDateStr);
        checkInDate.setDate(checkInDate.getDate() + nights);
        var day = ("0" + checkInDate.getDate()).slice(-2);
        var month = ("0" + (checkInDate.getMonth() + 1)).slice(-2);
        var year = checkInDate.getFullYear();
        var formattedCheckoutDate = day + '/' + month + '/' + year;

        // Update summary card
        var currSymbol = " <?php echo htmlspecialchars($defCurr['symbol'] ?? '₭'); ?>";
        $('#summary_nights').text(nights);
        $('#summary_checkout_date').text(formattedCheckoutDate);
        $('#summary_subtotal').text(formatMoneyJS(subtotal) + currSymbol);
        $('#summary_tax_amount').text(formatMoneyJS(taxAmount) + currSymbol);
        $('#summary_grand_total').text(formatMoneyJS(grandTotal) + currSymbol);
        
        // Update payment inputs
        if ($('#payment_status').val() === 'Paid') {
            $('#deposit_amount').val(formatMoneyJS(grandTotal));
        }
        calculateCheckinChange();
    }

    function calculateCheckinChange() {
        var grandTotal = parseFloat($('#deposit_amount').val().replace(/,/g, '')) || 0;
        var received = parseFloat($('#received_amount_input').val().replace(/,/g, '')) || 0;
        
        var change = received - grandTotal;
        if (change < 0) change = 0;
        
        $('#change_amount_display').val(formatMoneyJS(change));
    }

    function toggleCashDetails() {
        var status = $('#payment_status').val();
        var method = $('#payment_method').val();
        
        if (status === 'Paid' && method === 'Cash') {
            $('#cash_details_group').slideDown();
            calculateCheckinChange();
        } else {
            $('#cash_details_group').slideUp();
        }
    }

    // Toggle payment method and amount depending on payment status
    $('#payment_status').on('change', function() {
        if ($(this).val() === 'Unpaid') {
            $('#deposit_amount').val('0');
            $('#payment_method_group').hide();
        } else {
            updateCalculations();
            $('#payment_method_group').show();
        }
        toggleCashDetails();
    });

    $('#payment_method').on('change', function() {
        toggleCashDetails();
    });

    $('#received_amount_input').on('input', function() {
        calculateCheckinChange();
    });

    // Run toggle on load
    toggleCashDetails();

    $('#nights_input').on('input change', function() {
        updateCalculations();
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
                    $(this).val('0');
                }
            }
        } else {
            $(this).val('0');
        }
    });

    // Form validation
    $('#checkinForm').on('submit', function(e) {
        var name = $('#customer_name').val().trim();
        var phone = $('#customer_phone').val().trim();
        
        if (name === '' || phone === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: '<?php echo $lang['warning_label'] ?? 'ຂໍ້ມູນບໍ່ຄົບຖ້ວນ'; ?>',
                text: '<?php echo $lang['enter_name_phone_msg'] ?? 'ກະລຸນາປ້ອນຊື່ ແລະ ເບີໂທລູກຄ້າໃຫ້ຄົບຖ້ວນ!'; ?>',
                confirmButtonText: '<?php echo $lang['ok']; ?>'
            });
            return false;
        }
    });
});
</script>
</body>
</html>
