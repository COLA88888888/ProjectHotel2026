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

header('Content-Type: application/json');

if (!isset($_SESSION['checked']) || $_SESSION['checked'] !== 1) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$notifications = [];
$total_count = 0;

// 1. Low Stock Products
$stmtLow = $pdo->query("SELECT prod_name, qty FROM products WHERE qty <= 5 LIMIT 5");
while ($row = $stmtLow->fetch()) {
    $notifications[] = [
        'type' => 'low_stock',
        'title' => 'ສິນຄ້າໃກ້ຈະໝົດ!',
        'text' => $row['prod_name'] . ' ເຫຼືອພຽງ ' . $row['qty'],
        'icon' => 'fas fa-box-open',
        'color' => 'text-warning',
        'link' => 'stock.php'
    ];
    $total_count++;
}

// 2. New Room Service Orders (Last 15 minutes)
$stmtService = $pdo->query("
    SELECT MAX(id) as max_id, 
           MAX(created_at) as created_at, 
           GROUP_CONCAT(CONCAT(item_name, ' (x', qty, ')') SEPARATOR '<br>') as grouped_items 
    FROM room_services 
    WHERE created_at >= NOW() - INTERVAL 15 MINUTE 
    GROUP BY booking_id, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
    ORDER BY max_id DESC LIMIT 5
");
$latest_order_id = 0;
$first = true;
while ($row = $stmtService->fetch()) {
    if ($first) {
        $latest_order_id = $row['max_id'];
        $first = false;
    }
    $notifications[] = [
        'type' => 'room_service',
        'title' => 'ມີລາຍການສັ່ງໃໝ່!',
        'text' => $row['grouped_items'] . ' (' . date('H:i', strtotime($row['created_at'])) . ')',
        'icon' => 'fas fa-utensils',
        'color' => 'text-info',
        'link' => 'room_service.php'
    ];
    $total_count++;
}

// 3. Today's Checkouts
$stmtCheckout = $pdo->query("SELECT r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.status = 'Occupied' AND b.check_out_date = CURDATE() LIMIT 5");
while ($row = $stmtCheckout->fetch()) {
    $notifications[] = [
        'type' => 'checkout',
        'title' => 'ຮອດກຳນົດ Check-out!',
        'text' => 'ຫ້ອງ ' . $row['room_number'] . ' ຮອດກຳນົດອອກມື້ນີ້',
        'icon' => 'fas fa-door-closed',
        'color' => 'text-danger',
        'link' => 'checkout.php'
    ];
    $total_count++;
}

// 3. New Payment Confirmations (Last 15 minutes)
$stmtPay = $pdo->query("SELECT id, room_number, amount, created_at FROM payment_notifications WHERE status = 'New' AND created_at >= NOW() - INTERVAL 15 MINUTE ORDER BY id DESC");
while ($row = $stmtPay->fetch()) {
    $notifications[] = [
        'id' => 'pay_' . $row['id'],
        'title' => ($lang['payment_confirmed'] ?? 'ຮັບເງິນໂອນແລ້ວ'),
        'text' => ($lang['room'] ?? 'ຫ້ອງ') . ' ' . $row['room_number'] . ': ' . formatCurrency($row['amount']),
        'icon' => 'fas fa-hand-holding-usd',
        'color' => 'text-success',
        'link' => 'report.php', 
        'time' => $row['created_at']
    ];
    $total_count++;
}

// Get max timestamp for tracking updates
$stmtMaxTime = $pdo->query("SELECT GREATEST(
    IFNULL((SELECT MAX(created_at) FROM room_services WHERE created_at >= NOW() - INTERVAL 15 MINUTE), '2000-01-01 00:00:00'),
    IFNULL((SELECT MAX(created_at) FROM payment_notifications WHERE created_at >= NOW() - INTERVAL 15 MINUTE), '2000-01-01 00:00:00')
)");
$max_time = $stmtMaxTime->fetchColumn() ?: '';

echo json_encode([
    'count' => $total_count,
    'latest_order_id' => $latest_order_id,
    'max_time' => $max_time,
    'items' => $notifications
]);
