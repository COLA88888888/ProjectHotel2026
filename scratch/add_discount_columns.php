<?php
require_once __DIR__ . '/../config/db.php';
try {
    // Add discount columns to orders table
    $pdo->exec("ALTER TABLE orders ADD COLUMN discount_type VARCHAR(10) DEFAULT NULL AFTER amount");
    $pdo->exec("ALTER TABLE orders ADD COLUMN discount_value DECIMAL(15,2) DEFAULT 0.00 AFTER discount_type");
    $pdo->exec("ALTER TABLE orders ADD COLUMN bill_discount DECIMAL(15,2) DEFAULT 0.00 AFTER discount_value");
    echo "SUCCESS";
} catch (Exception $e) {
    echo "EXIST_OR_ERR: " . $e->getMessage();
}
