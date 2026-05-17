<?php
session_start();
require_once 'config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

if (!isset($_GET['bill_id'])) {
    die("Invoice not found.");
}

$bill_id = $_GET['bill_id'];

// Fetch settings for hotel info
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while($row = $stmtSettings->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$tax_percent = (float)($settings['tax_percent'] ?? 0);

// Fetch default currency
$stmtCur = $pdo->query("SELECT * FROM currency WHERE is_default = 1 LIMIT 1");
$default_currency = $stmtCur->fetch();
$currency_symbol = $default_currency['symbol'] ?? '₭';
$currency_code = $default_currency['currency_code'] ?? 'LAK';

$hotel_name = $settings['hotel_name'] ?? 'Hotel System';
$hotel_phone = $settings['hotel_phone'] ?? '';
$hotel_address = $settings['hotel_address'] ?? '';
$footer_text = $settings['receipt_footer'] ?? 'Thank you!';

// Fetch order items with localized product name
$current_lang = $_SESSION['lang'] ?? 'la';
$prod_name_col = "prod_name_" . $current_lang;

$stmt = $pdo->prepare("SELECT o.*, p.prod_name, p.$prod_name_col as prod_name_localized, p.prod_code, p.sprice 
                       FROM orders o 
                       JOIN products p ON o.prod_id = p.prod_id 
                       WHERE o.bill_id = ?");
$stmt->execute([$bill_id]);
$items = $stmt->fetchAll();

if (!$items) {
    die("No items found for this invoice.");
}

$total = 0;
$date = date('d/m/Y H:i', strtotime($items[0]['created_at']));
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['receipt_label'] ?? 'ໃບບິນຮັບເງິນ'; ?> - <?php echo $bill_id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif; font-size: 12px; margin: 0; padding: 0; color: #000; background: #f4f4f4; overflow-x: hidden; }
        .receipt { width: 100%; max-width: 75mm; margin: 10px auto; background: #fff; padding: 4mm; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); page-break-inside: avoid; }
        .header { text-align: center; margin-bottom: 15px; }
        .hotel-name { font-size: 16px; font-weight: bold; margin-bottom: 5px; text-transform: uppercase; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; font-size: 11px; line-height: 1.3; }
        .info-row span:first-child { flex: 1; padding-right: 5px; color: #555; }
        .info-row span:last-child { text-align: right; font-weight: bold; color: #000; }
        .item-table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 12px; table-layout: fixed; }
        .item-table th { border-bottom: 1px dashed #000; text-align: left; padding: 4px 0; color: #333; }
        .item-table td { padding: 6px 0; vertical-align: top; border-bottom: 1px solid #eee; word-wrap: break-word; }
        .item-table tr:last-child td { border-bottom: none; }
        .text-right { text-align: right !important; }
        .total-section { margin-top: 8px; }
        .grand-total { font-size: 15px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }
        .footer { text-align: center; margin-top: 10px; font-size: 10px; font-style: italic; line-height: 1.2; color: #444; }
        
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { background: none; padding: 0; margin: 0; }
            .receipt { max-width: 100%; width: 100%; padding: 3mm; margin: 0; box-shadow: none; }
            .no-print { display: none; }
        }
        
        .btn-print {
            display: inline-block;
            padding: 8px 18px;
            background: #28a745;
            color: #fff;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Noto Sans Lao Looped', sans-serif;
        }
        
        @media (max-width: 400px) {
            .receipt { margin: 5px auto; padding: 3mm; }
            body { font-size: 11px; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin-top: 10px;">
    <button onclick="window.print()" class="btn-print" style="border: none; cursor: pointer; display: inline-block;"><i class="fas fa-print"></i> <?php echo $lang['print_receipt']; ?> (Print)</button>
    <button onclick="window.close()" style="border: 1px solid #ddd; background: #f8f9fa; padding: 8px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; margin-left: 10px; font-family: 'Noto Sans Lao Looped', sans-serif;"><i class="fas fa-times"></i> <?php echo $lang['close']; ?></button>
</div>

<div class="receipt">
    <div class="header">
        <?php if(!empty($settings['hotel_logo'])): ?>
            <img src="assets/img/logo/<?php echo $settings['hotel_logo']; ?>" style="width: 60px; height: 60px; object-fit: contain; margin-bottom: 5px;">
        <?php endif; ?>
        <div class="hotel-name"><?php echo htmlspecialchars($hotel_name); ?></div>
        <div style="font-size: 11px;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($hotel_address); ?></div>
        <div style="font-size: 11px;"><i class="fas fa-phone-alt"></i> Tel: <?php echo htmlspecialchars($hotel_phone); ?></div>
        <div class="divider"></div>
        <div style="font-weight: bold; font-size: 14px;"><i class="fas fa-file-invoice-dollar"></i> <?php echo $lang['receipt_label'] ?? 'ໃບບິນຮັບເງິນ'; ?> (RECEIPT)</div>
    </div>

    <div class="info-row">
        <span><i class="fas fa-hashtag"></i> <?php echo $lang['bill_no_label'] ?? 'ເລກທີບິນ'; ?>:</span>
        <span><?php echo $bill_id; ?></span>
    </div>
    <div class="info-row">
        <span><i class="fas fa-calendar-day"></i> <?php echo $lang['date_label'] ?? 'ວັນທີ'; ?>:</span>
        <span><?php echo $date; ?></span>
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th><?php echo $lang['item_label'] ?? 'ລາຍການ'; ?></th>
                <th class="text-right"><?php echo $lang['qty_label'] ?? 'ຈຳນວນ'; ?></th>
                <th class="text-right"><?php echo $lang['total_label'] ?? 'ລວມ'; ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): 
                $qty = (int)$item['o_qty'];
                $price = (float)$item['sprice'];
                $subtotal = $qty * $price;
                $total += $subtotal;
            ?>
                <td>
                    <?php echo htmlspecialchars($item['prod_name_localized'] ?: $item['prod_name']); ?>
                    <br><small><?php echo number_format($price); ?> x <?php echo $qty; ?></small>
                </td>
                <td class="text-right"><?php echo $qty; ?></td>
                <td class="text-right"><?php echo number_format($subtotal); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="divider"></div>

    <div class="total-section">
        <div class="info-row">
            <span><?php echo $lang['subtotal']; ?>:</span>
            <span><?php echo number_format($total); ?></span>
        </div>
        <?php if($tax_percent > 0): 
            $tax_amount = round($total * ($tax_percent / 100));
            $grand_total = $total + $tax_amount;
        ?>
            <div class="info-row" style="font-weight: normal; font-size: 13px;">
                <span><?php echo $lang['tax']; ?> (<?php echo $tax_percent; ?>%):</span>
                <span><?php echo number_format($tax_amount); ?></span>
            </div>
            <div class="info-row grand-total">
                <span><?php echo $lang['grand_total']; ?>:</span>
                <span><?php echo number_format($grand_total); ?> <?php echo $currency_symbol; ?></span>
            </div>
        <?php else: ?>
            <div class="info-row grand-total">
                <span><?php echo $lang['grand_total']; ?>:</span>
                <span><?php echo number_format($total); ?> <?php echo $currency_symbol; ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>
    
    <div class="info-row">
        <span><?php echo $lang['payment_method_label']; ?>:</span>
        <span><?php 
            $pm = $items[0]['payment_method'] ?? 'Cash';
            echo ($pm == 'Cash' || $pm == 'ເງິນສົດ') ? $lang['cash'] : $lang['transfer']; 
        ?></span>
    </div>
    <div class="info-row">
        <span><?php echo $lang['amount_received_label']; ?>:</span>
        <span><?php echo number_format($items[0]['received'] ?? 0); ?> <?php echo $currency_symbol; ?></span>
    </div>
    <div class="info-row">
        <span><?php echo $lang['change_amount_label']; ?>:</span>
        <span style="font-size: 14px;"><?php echo number_format($items[0]['change_amount'] ?? 0); ?> <?php echo $currency_symbol; ?></span>
    </div>

    <div class="footer">
        <?php if(!empty($settings['hotel_qr'])): ?>
            <div style="margin-top: 10px; text-align: center;">
                <p style="margin-bottom: 5px; font-weight: bold; font-size: 10px; color: #555;"><?php echo $lang['scan_to_pay_msg'] ?? 'SCAN TO PAY (ສະແກນເພື່ອຊຳລະ)'; ?></p>
                <img src="assets/img/QR/<?php echo $settings['hotel_qr']; ?>" style="width: 110px; height: 110px; border: 1px solid #eee; padding: 5px; background: #fff;">
            </div>
        <?php endif; ?>
        <br>
        <?php echo nl2br(htmlspecialchars($footer_text)); ?>
    </div>
</div>

<script>
    // Auto print when page loads
    window.onload = function() {
        window.print();
    }
</script>

</body>
</html>
