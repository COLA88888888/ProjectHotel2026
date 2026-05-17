<?php
require_once 'd:/xampp/htdocs/ProjectHotel2026/config/db.php';
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN user_id INT(11) NULL AFTER order_id");
    echo "Column user_id added successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
