<?php
require_once 'd:/xampp/htdocs/ProjectHotel2026/config/db.php';
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo $t . "\n";
}
?>
