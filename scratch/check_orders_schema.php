<?php
require_once 'd:/xampp/htdocs/ProjectHotel2026/config/db.php';
$stmt = $pdo->query("DESCRIBE orders");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}
?>
