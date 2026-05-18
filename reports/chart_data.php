<?php
// ==========================================
// ໄຟລ໌ດຶງຂໍ້ມູນສະຖິຕິເພື່ອສະແດງຜົນໃນແຜນພູມ (Chart Data Provider API)
// ------------------------------------------
// ໜ້າທີ່: ປະມວນຜົນ ແລະ ສົ່ງຂໍ້ມູນລາຍຮັບຫ້ອງພັກ ແລະ POS ໃນຮູບແບບ JSON ໃຫ້ກັບ Chart.js
// ຮອງຮັບການສະແດງຜົນແຍກຕາມແຕ່ລະຊ່ວງເວລາ (ປະຈຳວັນ, ປະຈຳອາທິດ, ປະຈຳເດືອນ, ປະຈຳປີ)
// ==========================================

require_once '../config/db.php';

// ດຶງຊ່ວງເວລາທີ່ຕ້ອງການຄຳນວນ (ຄ່າເລີ່ມຕົ້ນແມ່ນ ປະຈຳວັນ 'daily')
$period = $_GET['period'] ?? 'daily';
$offset = (int)($_GET['offset'] ?? 0); // 0 = ປັດຈຸບັນ, 1 = ຊ່ວງເວລາກ່ອນໜ້າ, 2...

$labels = [];
$roomData = [];
$posData = [];

// --- 1. ສ່ວນດຶງຂໍ້ມູນອັດຕາພາສີ (Fetch Tax Percent) ---
$stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
$tax_percent = (float)($stmtTax->fetchColumn() ?: 0);
$tax_mult = 1 + ($tax_percent / 100);

// --- 2. ສ່ວນ Loop ຄຳນວນຫາລາຍຮັບແຍກຕາມຊ່ວງເວລາ (Switch Period Revenue Calculations) ---
switch ($period) {
    case 'daily':
        // ຄຳນວນລາຍຮັບຍ້ອນຫຼັງ 7 ວັນ (Last 7 Days)
        for ($i = 6; $i >= 0; $i--) {
            $dayOffset = $i + ($offset * 7);
            $date = date('Y-m-d', strtotime("-$dayOffset days"));
            $labels[] = date('d/m', strtotime("-$dayOffset days"));
            
            // Deposits received on this day
            $stmtRDep = $pdo->prepare("SELECT SUM(deposit_amount) as total FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (DATE(check_in_date) = ? OR DATE(created_at) = ?)");
            $stmtRDep->execute([$date, $date]);
            $r_dep = (float)($stmtRDep->fetch()['total'] ?? 0);

            // Checkout payments received on this day
            $stmtROut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) as total FROM bookings WHERE status = 'Completed' AND DATE(check_out_date) = ?");
            $stmtROut->execute([$date]);
            $r_out = (float)($stmtROut->fetch()['total'] ?? 0);

            $roomData[] = $r_dep + $r_out;
            
            // ລາຍຮັບ POS ລວມ
            $stmtP = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE DATE(o_date) = ?");
            $stmtP->execute([$date]);
            $posData[] = (float)($stmtP->fetch()['total'] ?? 0);
        }
        break;
        
    case 'weekly':
        // ຄຳນວນລາຍຮັບຍ້ອນຫຼັງ 8 ອາທິດ
        for ($i = 7; $i >= 0; $i--) {
            $weekOffset = $i + ($offset * 8);
            $weekStart = date('Y-m-d', strtotime("monday this week -$weekOffset weeks"));
            $weekEnd = date('Y-m-d', strtotime("sunday this week -$weekOffset weeks"));
            $labels[] = date('d/m', strtotime($weekStart));
            
            // Deposits received in this week
            $stmtRDep = $pdo->prepare("SELECT SUM(deposit_amount) as total FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND ((DATE(check_in_date) BETWEEN ? AND ?) OR (DATE(created_at) BETWEEN ? AND ?))");
            $stmtRDep->execute([$weekStart, $weekEnd, $weekStart, $weekEnd]);
            $r_dep = (float)($stmtRDep->fetch()['total'] ?? 0);

            // Checkout payments received in this week
            $stmtROut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) as total FROM bookings WHERE status = 'Completed' AND DATE(check_out_date) BETWEEN ? AND ?");
            $stmtROut->execute([$weekStart, $weekEnd]);
            $r_out = (float)($stmtROut->fetch()['total'] ?? 0);

            $roomData[] = $r_dep + $r_out;
            
            $stmtP = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE DATE(o_date) BETWEEN ? AND ?");
            $stmtP->execute([$weekStart, $weekEnd]);
            $posData[] = (float)($stmtP->fetch()['total'] ?? 0);
        }
        break;
        
    case 'monthly':
        // ຄຳນວນລາຍຮັບຍ້ອນຫຼັງ 12 ເດືອນ
        for ($i = 11; $i >= 0; $i--) {
            $monthOffset = $i + ($offset * 12);
            $month = date('Y-m', strtotime("-$monthOffset months"));
            $labels[] = date('m/Y', strtotime("-$monthOffset months"));
            
            // Deposits received in this month
            $stmtRDep = $pdo->prepare("SELECT SUM(deposit_amount) as total FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (DATE_FORMAT(check_in_date, '%Y-%m') = ? OR DATE_FORMAT(created_at, '%Y-%m') = ?)");
            $stmtRDep->execute([$month, $month]);
            $r_dep = (float)($stmtRDep->fetch()['total'] ?? 0);

            // Checkout payments received in this month
            $stmtROut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) as total FROM bookings WHERE status = 'Completed' AND DATE_FORMAT(check_out_date, '%Y-%m') = ?");
            $stmtROut->execute([$month]);
            $r_out = (float)($stmtROut->fetch()['total'] ?? 0);

            $roomData[] = $r_dep + $r_out;
            
            $stmtP = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE DATE_FORMAT(o_date, '%Y-%m') = ?");
            $stmtP->execute([$month]);
            $posData[] = (float)($stmtP->fetch()['total'] ?? 0);
        }
        break;
        
    case 'yearly':
        // ຄຳນວນລາຍຮັບຍ້ອນຫຼັງ 5 ປີ
        for ($i = 4; $i >= 0; $i--) {
            $yearOffset = $i + ($offset * 5);
            $year = date('Y', strtotime("-$yearOffset years"));
            $labels[] = $year;
            
            // Deposits received in this year
            $stmtRDep = $pdo->prepare("SELECT SUM(deposit_amount) as total FROM bookings WHERE status IN ('Completed', 'Checked In', 'Occupied', 'Booked') AND (YEAR(check_in_date) = ? OR YEAR(created_at) = ?)");
            $stmtRDep->execute([$year, $year]);
            $r_dep = (float)($stmtRDep->fetch()['total'] ?? 0);

            // Checkout payments received in this year
            $stmtROut = $pdo->prepare("SELECT SUM(GREATEST(0, (total_price + COALESCE(food_charge, 0)) * $tax_mult - deposit_amount)) as total FROM bookings WHERE status = 'Completed' AND YEAR(check_out_date) = ?");
            $stmtROut->execute([$year]);
            $r_out = (float)($stmtROut->fetch()['total'] ?? 0);

            $roomData[] = $r_dep + $r_out;
            
            $stmtP = $pdo->prepare("SELECT SUM(amount) as total FROM orders WHERE YEAR(o_date) = ?");
            $stmtP->execute([$year]);
            $posData[] = (float)($stmtP->fetch()['total'] ?? 0);
        }
        break;
}

// --- 3. ສ່ວນດຶງຂໍ້ມູນລາຍຮັບແຍກຕາມປະເພດຫ້ອງ (Fetch Room Type Revenue) ---
$roomTypeLabels = [];
$roomTypeRevenue = [];

$typeQuery = "SELECT r.room_type, SUM((b.total_price + COALESCE(b.food_charge, 0)) * $tax_mult) as total 
              FROM bookings b 
              JOIN rooms r ON b.room_id = r.id 
              WHERE b.status IN ('Completed', 'Checked In') ";

// ເພີ່ມເງື່ອນໄຂ Filter ຕາມຊ່ວງເວລາທີ່ເລືອກ
if ($period == 'daily') {
    $typeQuery .= "AND DATE(b.check_in_date) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ";
} elseif ($period == 'weekly') {
    $typeQuery .= "AND DATE(b.check_in_date) >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) ";
} elseif ($period == 'monthly') {
    $typeQuery .= "AND DATE(b.check_in_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) ";
} elseif ($period == 'yearly') {
    $typeQuery .= "AND DATE(b.check_in_date) >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR) ";
}

$typeQuery .= "GROUP BY r.room_type ORDER BY total DESC";
$stmtType = $pdo->query($typeQuery);
while ($row = $stmtType->fetch()) {
    $roomTypeLabels[] = $row['room_type'];
    $roomTypeRevenue[] = (float)$row['total'];
}

// --- 4. ສົ່ງຜົນຮັບກັບຄືນໃນຮູບແບບ JSON (Send Output JSON Response) ---
header('Content-Type: application/json');
echo json_encode([
    'labels' => $labels,
    'roomData' => $roomData,
    'posData' => $posData,
    'roomTypeLabels' => $roomTypeLabels,
    'roomTypeData' => $roomTypeRevenue
]);
