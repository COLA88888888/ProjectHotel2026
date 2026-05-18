<?php
require_once 'd:/xampp/htdocs/ProjectHotel2026/config/db.php';

echo "=== Columns in bookings ===\n";
$stmt = $pdo->query("DESCRIBE bookings");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== Columns in expenses ===\n";
$stmt = $pdo->query("DESCRIBE expenses");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
