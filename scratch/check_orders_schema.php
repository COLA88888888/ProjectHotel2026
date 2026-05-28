<?php
require_once '../config/db.php';

// Check latest orders
echo "=== Latest 10 bill_ids in orders ===\n";
$stmt = $pdo->query("SELECT DISTINCT bill_id, COUNT(*) as item_count FROM orders GROUP BY bill_id ORDER BY bill_id DESC LIMIT 10");
$rows = $stmt->fetchAll();
foreach($rows as $r) {
    echo "bill_id: {$r['bill_id']}  |  items: {$r['item_count']}\n";
}

// Check if orders table has created_at column
echo "\n=== Orders table columns ===\n";
$stmt2 = $pdo->query("DESCRIBE orders");
$cols = $stmt2->fetchAll();
foreach($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
