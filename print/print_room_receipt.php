<?php
require_once '../config/session_check.php';
if (!hasPermission('bookings') && !hasPermission('checkout') && !hasPermission('report')) {
    enforcePermission('checkout');
}
require_once '../config/db.php';

// ເພີ່ມຟັງຊັນສຳຮອງ ປ້ອງກັນ Fatal Error ຖ້າຫາກບໍ່ທັນມີຟັງຊັນນີ້ໃນລະບົບ
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return number_format($amount) . " ₭";
    }
}

if (!isset($_GET['booking_id'])) {
    die("Booking not found.");
}

$booking_id = $_GET['booking_id'];

// --- 1. ໂຫຼດການຕັ້ງຄ່າໃບບິນ (Receipt Configuration Loader) ---
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
$hotel_name = $settings['hotel_name'] ?? 'Hotel System';
$hotel_phone = $settings['hotel_phone'] ?? '';
$hotel_address = $settings['hotel_address'] ?? '';
$footer_text = $settings['receipt_footer'] ?? 'Thank you!';
$tax_percent = (float)($settings['tax_percent'] ?? 0);

// Fetch default currency
$stmtCur = $pdo->query("SELECT * FROM currency WHERE is_default = 1 LIMIT 1");
$default_currency = $stmtCur->fetch();
$currency_symbol = $default_currency['symbol'] ?? '₭';

// Fetch Booking details with localized room type
$current_lang = $_SESSION['lang'] ?? 'la';
$room_type_col = "room_type_name_" . $current_lang;

$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, rt.$room_type_col as room_type_localized, rt.room_type_name as room_type_base
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE b.id = ?
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    die("Booking details not found.");
}

// Fetch Room Services (Food/Drink) with localized names
$prod_name_col = "prod_name_" . $current_lang;
$svcStmt = $pdo->prepare("
    SELECT rs.item_name, rs.price, rs.qty, rs.total_price, 
           p.prod_code, p.$prod_name_col as prod_name_localized
    FROM room_services rs 
    LEFT JOIN products p ON rs.prod_id = p.prod_id 
    WHERE rs.booking_id = ?
    ORDER BY rs.id ASC
");
$svcStmt->execute([$booking_id]);
$services = $svcStmt->fetchAll();

$d1 = new DateTime($booking['check_in_date']);
$d2 = new DateTime($booking['check_out_date']);
$nights = $d1->diff($d2)->days ?: 1;

$subtotal = $booking['total_price'] + $booking['food_charge'];
$tax_amount = round($subtotal * ($tax_percent / 100));
$grand_total = $subtotal + $tax_amount;
$final_payable = max(0, $grand_total - $booking['deposit_amount']);
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ໃບບິນຄ່າທີ່ພັກ <?php echo $booking_id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;500;600;700&family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <style>
        body { 
            font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', 'Phetsarath OT', sans-serif; 
            font-size: 13px; 
            margin: 0; 
            padding: 0; 
            color: #000; 
            background: #f4f4f4; 
            overflow-x: hidden; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .receipt { 
            width: 100%; 
            max-width: 75mm; 
            margin: 10px auto; 
            background: #fff; 
            padding: 5mm; 
            box-sizing: border-box; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1); 
            page-break-inside: avoid; 
        }
        .header { text-align: center; margin-bottom: 12px; }
        .hotel-name { font-size: 18px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; color: #000; }
        .divider { border-top: 1.5px dashed #000; margin: 8px 0; }
        .info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; font-size: 12px; line-height: 1.4; color: #000; }
        .info-row span:first-child { flex: 1; padding-right: 5px; color: #000; font-weight: 500; }
        .info-row span:last-child { text-align: right; font-weight: 700; color: #000; }
        .item-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12px; table-layout: fixed; color: #000; }
        .item-table th { border-bottom: 1.5px dashed #000; text-align: left; padding: 6px 0; color: #000; font-weight: 700; }
        .item-table td { padding: 8px 0; vertical-align: top; border-bottom: 1px dashed #ddd; word-wrap: break-word; color: #000; }
        .item-table tr:last-child td { border-bottom: none; }
        .text-right { text-align: right !important; }
        .total-section { margin-top: 10px; }
        .grand-total { font-size: 16px; font-weight: 700; border-top: 1.5px solid #000; padding-top: 6px; margin-top: 6px; color: #000; }
        .footer { text-align: center; margin-top: 15px; font-size: 11px; line-height: 1.4; color: #000; font-weight: 500; }
        
        @page {
            margin: 0;
        }
        
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #000 !important;
                text-shadow: none !important;
                box-shadow: none !important;
            }
            body { background: none; padding: 0; margin: 0; }
            .receipt { 
                width: 300px !important; 
                max-width: 300px !important; 
                padding: 10px !important; 
                margin: 0 auto !important; 
                box-shadow: none !important; 
                display: block !important; 
            }
            .no-print { display: none !important; }
        }
        
        .btn-print {
            display: inline-block;
            padding: 8px 18px;
            background: #007bff;
            color: #fff;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', sans-serif;
        }

        @media (max-width: 400px) {
            .receipt { margin: 5px auto; padding: 3mm; }
            body { font-size: 11px; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin-top: 10px;">
    <button onclick="window.print()" class="btn-print"><i class="fas fa-print"></i> ພິມໃບບິນ (Print)</button>
    <button onclick="window.close()" style="border: 1px solid #ddd; background: #f8f9fa; padding: 8px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; margin-left: 10px; font-family: 'Noto Sans Lao Looped', sans-serif;"><i class="fas fa-times"></i> ປິດໜ້າຕ່າງ</button>
</div>

<div class="receipt">
    <div class="header">
        <?php 
            $logo_path = !empty($settings['hotel_logo']) ? '../assets/img/logo/' . $settings['hotel_logo'] : '../assets/img/image.jpg';
            if (!file_exists($logo_path)) { $logo_path = '../assets/img/image.jpg'; }
        ?>
        <img src="<?php echo $logo_path; ?>" style="width: 60px; height: 60px; object-fit: contain; margin-bottom: 5px;">
        <div class="hotel-name"><?php echo htmlspecialchars($hotel_name); ?></div>
        <div style="font-size: 11px;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($hotel_address); ?></div>
        <div style="font-size: 11px;"><i class="fas fa-phone-alt"></i> Tel: <?php echo htmlspecialchars($hotel_phone); ?></div>
        <div class="divider"></div>
        <div style="font-weight: bold; font-size: 13px; margin-bottom: 5px;">
            <i class="fas fa-bed mr-1"></i> <?php echo $lang['room_invoice'] ?? 'ໃບບິນຄ່າທີ່ພັກ'; ?><br>
        </div>
    </div>

    <div class="info-row">
        <span><i class="fas fa-hashtag"></i> ເລກທີບິນ:</span>
        <span><?php echo htmlspecialchars($booking['bill_number'] ?: (date('Ymd', strtotime($booking['created_at'])) . str_pad($booking['id'], 3, '0', STR_PAD_LEFT))); ?></span>
    </div>
    <div class="info-row">
        <span><i class="fas fa-user"></i> ລູກຄ້າ:</span>
        <span><?php echo htmlspecialchars($booking['customer_name']); ?></span>
    </div>
    <div class="info-row">
        <span>ຫ້ອງ:</span>
        <span><?php echo htmlspecialchars($booking['room_number']); ?> (<?php echo htmlspecialchars($booking['room_type_base']); ?>)</span>
    </div>
    <div class="info-row">
        <span>ເຂົ້າ:</span>
        <span><?php echo date('d/m/Y', strtotime($booking['check_in_date'])); ?></span>
    </div>
    <div class="info-row">
        <span>ອອກ:</span>
        <span><?php echo date('d/m/Y', strtotime($booking['check_out_date'])); ?></span>
    </div>
    <div class="info-row">
        <span>ຈຳນວນຄືນ:</span>
        <span><?php echo $nights; ?> ຄືນ</span>
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th>ລາຍການ</th>
                <th class="text-right">ລວມ</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>ຄ່າຫ້ອງພັກ (<?php echo $nights; ?> ຄືນ)</td>
                <td class="text-right"><?php echo formatCurrency($booking['total_price']); ?></td>
            </tr>
            <?php if(count($services) > 0): ?>
                <tr>
                    <td colspan="2" style="font-weight:bold; padding-top:8px;">ຄ່າບໍລິການ/ອາຫານ:</td>
                </tr>
                <?php foreach($services as $s): ?>
                <tr>
                    <td style="padding-left: 10px;">
                        <?php echo htmlspecialchars($s['prod_name_localized'] ?: $s['item_name']); ?> (x<?php echo $s['qty']; ?>)
                    </td>
                    <td class="text-right"><?php echo formatCurrency($s['total_price']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="total-section">
        <?php if($tax_percent > 0): ?>
        <div class="info-row" style="font-weight: normal; font-size: 12px;">
            <span>ພາສີ (<?php echo $tax_percent; ?>%):</span>
            <span><?php echo formatCurrency($tax_amount); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span><?php echo $lang['grand_total'] ?? 'ລວມທັງໝົດ'; ?>:</span>
            <span><?php echo formatCurrency($grand_total); ?></span>
        </div>
        
        <?php 
        $url_received = isset($_GET['received']) && $_GET['received'] !== '' ? (float)$_GET['received'] : null;
        $url_change = isset($_GET['change']) && $_GET['change'] !== '' ? (float)$_GET['change'] : null;
        
        $transaction_amount = 0;
        $is_deposit_receipt = false;
        $show_received_change = true;
        
        if ($url_received !== null) {
            $received_val = $url_received;
            $change_val = $url_change !== null ? $url_change : 0;
            $transaction_amount = $final_payable == 0 ? $grand_total : $final_payable;
        } elseif ($booking['status'] === 'Completed') {
            if ($final_payable == 0) {
                $transaction_amount = $grand_total;
                $received_val = ($booking['amount_received'] > 0) ? $booking['amount_received'] : $grand_total;
                $change_val = ($booking['amount_received'] > 0) ? $booking['change_amount'] : 0;
            } else {
                $transaction_amount = $final_payable;
                $received_val = ($booking['amount_received'] > 0) ? $booking['amount_received'] : $final_payable;
                $change_val = ($booking['amount_received'] > 0) ? $booking['change_amount'] : 0;
            }
        } else {
            if ($booking['deposit_amount'] > 0 && $booking['amount_received'] > 0 && $booking['food_charge'] == 0) {
                $is_deposit_receipt = true;
                $transaction_amount = $booking['deposit_amount'];
                $received_val = $booking['amount_received'];
                $change_val = $booking['change_amount'];
            } else {
                $transaction_amount = $final_payable == 0 ? $grand_total : $final_payable;
                $received_val = ($booking['amount_received'] > 0) ? $booking['amount_received'] : ($final_payable == 0 ? $grand_total : $final_payable);
                $change_val = ($booking['amount_received'] > 0) ? $booking['change_amount'] : 0;
                $show_received_change = true;
            }
        }
        ?>

        <?php if(!$is_deposit_receipt && $booking['deposit_amount'] > 0): ?>
        <div class="info-row" style="color: #28a745; font-weight: normal; font-size: 12px;">
            <span><?php echo $lang['room_fee_paid'] ?? 'ຊຳລະແລ້ວ'; ?>:</span>
            <span><?php echo formatCurrency($booking['deposit_amount']); ?></span>
        </div>
        <?php endif; ?>

        <div class="info-row" style="border-top: 1px solid #000; margin-top: 5px; padding-top: 5px; font-size: 14px; color: red;">
            <span><?php echo $lang['actual_paid'] ?? 'ຍອດຈ່າຍຕົວຈິງ'; ?>:</span>
            <span><?php echo number_format($transaction_amount); ?> <?php echo $currency_symbol; ?></span>
        </div>
        
        <?php if($is_deposit_receipt && $final_payable > 0): ?>
        <div class="info-row" style="color: #d33; font-weight: normal; font-size: 12px;">
            <span>ຍັງຄ້າງຊຳລະ:</span>
            <span><?php echo number_format($final_payable); ?> <?php echo $currency_symbol; ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>

    <?php if(isset($show_received_change) && $show_received_change): ?>
    <?php 
        $pm = $booking['payment_method'] ?? 'ເງິນສົດ';
        if (empty($pm) || strtolower($pm) == 'none') {
            $pm = 'ເງິນສົດ';
        }
        if (stripos($pm, 'Cash') !== false || stripos($pm, 'ເງິນສົດ') !== false) {
            $payment_method_la = 'ເງິນສົດ';
        } elseif (stripos($pm, 'Transfer') !== false || stripos($pm, 'ເງິນໂອນ') !== false) {
            $payment_method_la = 'ເງິນໂອນ';
        } else {
            $payment_method_la = $pm;
        }
    ?>
    <div class="info-row">
        <span>ວິທີຊຳລະ:</span>
        <span><?php echo htmlspecialchars($payment_method_la); ?></span>
    </div>
    <div class="info-row">
        <span>ຮັບເງິນມາ:</span>
        <span><?php echo number_format($received_val); ?> <?php echo $currency_symbol; ?></span>
    </div>
    <div class="info-row">
        <span>ເງິນທອນ:</span>
        <span style="color: #d9534f; font-size: 14px;"><?php echo number_format($change_val); ?> <?php echo $currency_symbol; ?></span>
    </div>
    <?php endif; ?>

    <div class="footer">
        <?php if(!empty($settings['hotel_qr'])): ?>
            <div style="margin-top: 10px; text-align: center;">
                <p style="margin-bottom: 5px; font-weight: bold; font-size: 10px; color: #555;">SCAN TO PAY (ສະແກນເພື່ອຊຳລະ)</p>
                <img src="../assets/img/QR/<?php echo $settings['hotel_qr']; ?>" style="width: 110px; height: 110px; border: 1px solid #eee; padding: 5px; background: #fff;">
            </div>
        <?php endif; ?>
        <br>
        <?php echo nl2br(htmlspecialchars($footer_text)); ?>
    </div>
</div>

<script>
    window.onload = function() {
        window.print();
    }
</script>

</body>
</html>