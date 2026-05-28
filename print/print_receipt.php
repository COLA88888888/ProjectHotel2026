<?php
require_once '../config/session_check.php';
if (!hasPermission('pos') && !hasPermission('report')) {
    enforcePermission('pos');
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
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;500;600;700&family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/pages/print_receipt.css">
</head>
<body>

<div class="no-print" style="text-align: center; margin-top: 10px;">
    <button onclick="window.print()" class="btn-print" style="border: none; cursor: pointer; display: inline-block;"><i class="fas fa-print"></i> <?php echo $lang['print_receipt']; ?> (Print)</button>
    <button onclick="window.close()" style="border: 1px solid #ddd; background: #f8f9fa; padding: 8px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; margin-left: 10px; font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', sans-serif;"><i class="fas fa-times"></i> <?php echo $lang['close']; ?></button>
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
            <?php 
            $total_original_subtotal = 0;
            $total_line_discount = 0;
            $total_bill_discount_pre_tax = 0;
            
            foreach($items as $item): 
                $qty = (int)$item['o_qty'];
                $price = (float)$item['sprice'];
                $subtotal = $qty * $price;
                $total_original_subtotal += $subtotal;
                
                // Line item discount calculation directly on the unit price
                $disc_val = (float)($item['discount_value'] ?? 0);
                $disc_type = $item['discount_type'] ?? 'cash';
                $unit_discount = 0;
                if ($disc_val > 0) {
                    if ($disc_type === 'percent') {
                        $unit_discount = round($price * ($disc_val / 100));
                    } else {
                        $unit_discount = $disc_val;
                    }
                }
                $row_discount = $unit_discount * $qty;
                $total_line_discount += $row_discount;
                $item_net_subtotal = $subtotal - $row_discount;
                if ($item_net_subtotal < 0) $item_net_subtotal = 0;
                
                // Pre-tax bill discount share calculation
                $item_bill_discount_pre_tax = (float)($item['bill_discount'] ?? 0);
                $total_bill_discount_pre_tax += $item_bill_discount_pre_tax;
            ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($item['prod_name_localized'] ?: $item['prod_name']); ?></strong>
                    <br><small><?php echo number_format($price); ?> x <?php echo $qty; ?></small>
                    <?php if ($row_discount > 0): ?>
                        <br><span style="font-size: 11px; color: #d9534f; font-weight: bold;">
                            <?php if ($disc_type === 'percent'): ?>
                                <i class="fas fa-percentage"></i> ສ່ວນຫຼຸດ -<?php echo $disc_val; ?>% (-<?php echo number_format($row_discount); ?>)
                            <?php else: 
                                $item_disc_pct = ($subtotal > 0) ? round(($row_discount / $subtotal) * 100, 1) : 0;
                                if (floor($item_disc_pct) == $item_disc_pct) {
                                    $item_disc_pct = (int)$item_disc_pct;
                                }
                            ?>
                                <i class="fas fa-tag"></i> ສ່ວນຫຼຸດ -<?php echo number_format($disc_val); ?>/ຊິ້ນ (-<?php echo number_format($row_discount); ?> / <?php echo $item_disc_pct; ?>%)
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="text-right"><?php echo $qty; ?></td>
                <td class="text-right">
                    <?php if ($row_discount > 0): ?>
                        <span style="text-decoration: line-through; color: #888; font-size: 11px;"><?php echo number_format($subtotal); ?></span><br>
                    <?php endif; ?>
                    <?php echo number_format($item_net_subtotal); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="divider"></div>

    <div class="total-section">
        <div class="info-row">
            <span>ລວມມູນຄ່າ:</span>
            <span><?php echo number_format($total_original_subtotal); ?></span>
        </div>
        
        <?php if($total_line_discount > 0): ?>
            <div class="info-row" style="font-weight: normal; font-size: 12px; color: #d9534f;">
                <span>ສ່ວນຫຼຸດລາຍການ:</span>
                <span>-<?php echo number_format($total_line_discount); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if($total_bill_discount_pre_tax > 0): 
            $net_before_bill = $total_original_subtotal - $total_line_discount;
            $bill_disc_pct = ($net_before_bill > 0) ? round(($total_bill_discount_pre_tax / $net_before_bill) * 100, 1) : 0;
            if (floor($bill_disc_pct) == $bill_disc_pct) {
                $bill_disc_pct = (int)$bill_disc_pct;
            }
        ?>
            <!-- <div class="info-row" style="font-weight: normal; font-size: 12px; color: #d9534f;">
                <span>ສ່ວນຫຼຸດ: (<?php echo $bill_disc_pct; ?>%):</span>
                <span>-<?php echo number_format($total_bill_discount_pre_tax); ?></span>
            </div> -->
        <?php endif; ?>
        
        <?php 
        $net_before_tax = $total_original_subtotal - $total_line_discount - $total_bill_discount_pre_tax;
        if ($net_before_tax < 0) $net_before_tax = 0;
        
        $tax_amount = round($net_before_tax * ($tax_percent / 100));
        
        // Sum exact order amounts from db to ensure 100% consistency with database
        $db_grand_total = 0;
        foreach($items as $item) {
            $db_grand_total += (float)$item['amount'];
        }
        $grand_total = ($db_grand_total > 0) ? $db_grand_total : ($net_before_tax + $tax_amount);
        ?>

        <!-- <div class="info-row" style="font-weight: normal; font-size: 12px;">
            <span>ມູນຄ່າຫຼັງຫຼຸດ:</span>
            <span><?php echo number_format($net_before_tax); ?></span>
        </div> -->

        <?php if($tax_percent > 0): ?>
            <div class="info-row" style="font-weight: normal; font-size: 12px;">
                <span><?php echo $lang['tax']; ?> (<?php echo $tax_percent; ?>%):</span>
                <span><?php echo number_format($tax_amount); ?></span>
            </div>
        <?php endif; ?>

        <div class="info-row grand-total">
            <span><?php echo $lang['grand_total']; ?>:</span>
            <span><?php echo number_format($grand_total); ?> <?php echo $currency_symbol; ?></span>
        </div>
    </div>

    <div class="divider"></div>
    
    <div class="info-row">
        <span><?php echo $lang['payment_method_label']; ?>:</span>
        <span><?php 
            $pm = $items[0]['payment_method'] ?? 'Cash';
            echo ($pm == 'Cash' || $pm == 'ເງິນສົດ') ? $lang['cash'] : $lang['transfer']; 
        ?></span>
    </div>
    <?php 
    $db_bill_discount_total = 0;
    foreach($items as $item) {
        $db_bill_discount_total += (float)$item['bill_discount'];
    }
    if($db_bill_discount_total > 0): 
        $net_before_bill = $total_original_subtotal - $total_line_discount;
        $bill_disc_pct = ($net_before_bill > 0) ? round(($total_bill_discount_pre_tax / $net_before_bill) * 100, 1) : 0;
        if (floor($bill_disc_pct) == $bill_disc_pct) {
            $bill_disc_pct = (int)$bill_disc_pct;
        }
    ?>
        <div class="info-row" style="color: #d9534f; font-weight: bold;">
            <span>ສ່ວນຫຼຸດ (<?php echo $bill_disc_pct; ?>%):</span>
            <span>-<?php echo number_format($db_bill_discount_total); ?> <?php echo $currency_symbol; ?></span>
        </div>
    <?php endif; ?>
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
                <img src="../assets/img/QR/<?php echo $settings['hotel_qr']; ?>" style="width: 110px; height: 110px; border: 1px solid #eee; padding: 5px; background: #fff;">
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
