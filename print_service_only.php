<?php
session_start();
require_once 'config/db.php';

if (!isset($_GET['booking_id'])) {
    die("Booking not found.");
}

$booking_id = $_GET['booking_id'];

// Fetch settings
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

// Fetch Booking details
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

$stmt = $pdo->prepare("
    SELECT b.*, r.room_number 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    WHERE b.id = ?
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    die("Booking details not found.");
}

// Fetch Room Services (Only Food/Drink) - REMOVED GROUP BY to show ALL items individually
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

if (!$services) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h3>ບໍ່ມີລາຍການອາຫານໃນຫ້ອງນີ້</h3><button onclick='window.close()'>ປິດ</button></div>");
}

$food_subtotal = $booking['food_charge'];
$tax_amount = round($food_subtotal * ($tax_percent / 100));
$grand_total = $food_subtotal + $tax_amount;
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ບິນຄ່າອາຫານ - Room <?php echo $booking['room_number']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif; font-size: 12px; margin: 0; padding: 0; color: #000; background: #f4f4f4; }
        .receipt { width: 100%; max-width: 75mm; margin: 10px auto; background: #fff; padding: 4mm; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 10px; }
        .hotel-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 11px; }
        .item-table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 11px; }
        .item-table th { border-bottom: 1px dashed #000; text-align: left; padding: 4px 0; }
        .item-table td { padding: 5px 0; vertical-align: top; border-bottom: 1px solid #eee; }
        .text-right { text-align: right; }
        .grand-total { font-size: 15px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }
        .footer { text-align: center; margin-top: 15px; font-size: 10px; font-style: italic; }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { background: none; }
            .receipt { max-width: 100%; width: 100%; padding: 0; margin: 0; box-shadow: none; }
            .no-print { display: none; }
        }
        .btn-print {
            display: inline-block;
            padding: 8px 20px;
            background: #28a745;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px;
            cursor: pointer;
            border: none;
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center;">
    <button onclick="window.print()" class="btn-print"><i class="fas fa-print"></i> ພິມບິນຄ່າອາຫານ</button>
    <button onclick="window.close()" style="padding: 8px 20px; border-radius: 5px; cursor: pointer;">ປິດ</button>
</div>

<div class="receipt">
    <div class="header">
        <div class="hotel-name"><?php echo $hotel_name; ?></div>
        <div style="font-size: 10px;"><?php echo $hotel_address; ?></div>
        <div style="font-size: 10px;">Tel: <?php echo $hotel_phone; ?></div>
        <div class="divider"></div>
        <div style="font-weight: bold; font-size: 13px;">ບິນຄ່າອາຫານ (FOOD BILL)</div>
        <div style="font-size: 11px;">ຫ້ອງ: <?php echo $booking['room_number']; ?></div>
    </div>

    <div class="info-row">
        <span>ວັນທີ: <?php echo date('d/m/Y H:i'); ?></span>
    </div>
    <div class="info-row">
        <span>ລູກຄ້າ: <?php echo htmlspecialchars($booking['customer_name']); ?></span>
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th width="50%"><?php echo $lang['item_label']; ?></th>
                <th class="text-right" width="20%"><?php echo $lang['qty_label'] ?? 'Qty'; ?></th>
                <th class="text-right" width="30%"><?php echo $lang['total']; ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($services as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['prod_name_localized'] ?: $item['item_name']); ?></td>
                    <td class="text-right"><?php echo $item['qty']; ?></td>
                    <td class="text-right"><?php echo number_format($item['total_price']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="divider"></div>
    <div class="info-row">
        <span><?php echo $lang['subtotal']; ?>:</span>
        <span class="text-right"><?php echo number_format($food_subtotal); ?> <?php echo $currency_symbol; ?></span>
    </div>
    <?php if($tax_percent > 0): ?>
    <div class="info-row">
        <span><?php echo $lang['tax_percent'] ?? 'Tax'; ?> (<?php echo $tax_percent; ?>%):</span>
        <span class="text-right"><?php echo number_format($tax_amount); ?> <?php echo $currency_symbol; ?></span>
    </div>
    <?php endif; ?>
    <div class="info-row grand-total">
        <span><?php echo $lang['total_due']; ?>:</span>
        <span class="text-right"><?php echo number_format($grand_total); ?> <?php echo $currency_symbol; ?></span>
    </div>

    <div class="footer">
        <?php if(!empty($settings['hotel_qr'])): ?>
            <div style="margin-top: 10px; text-align: center;">
                <p style="margin-bottom: 5px; font-weight: bold; font-size: 10px; color: #555;">SCAN TO PAY (ສະແກນເພື່ອຊຳລະ)</p>
                <img src="assets/img/QR/<?php echo $settings['hotel_qr']; ?>" style="width: 110px; height: 110px; border: 1px solid #eee; padding: 5px; background: #fff;">
            </div>
        <?php endif; ?>
        <br>
        <?php echo nl2br(htmlspecialchars($footer_text)); ?>
        <p>*** ກະລຸນາກວດສອບລາຍການກ່ອນຊຳລະເງິນ ***</p>
    </div>
</div>

<script>
    window.onload = function() { window.print(); }
</script>

</body>
</html>
