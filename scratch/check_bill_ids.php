<?php
require_once '../config/db.php';
$today = 'S-' . date('Ymd');
$stmt = $pdo->prepare('SELECT DISTINCT bill_id FROM orders WHERE bill_id LIKE ? ORDER BY bill_id DESC LIMIT 30');
$stmt->execute([$today . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "=== Today's Bill IDs ===\n";
foreach($rows as $r) { echo $r . "\n"; }

// Also check all recent bill IDs regardless of date
$stmt2 = $pdo->query('SELECT DISTINCT bill_id FROM orders WHERE bill_id LIKE "S-%" ORDER BY bill_id DESC LIMIT 30');
$rows2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== All Recent S- Bill IDs ===\n";
foreach($rows2 as $r) { echo $r . "\n"; }
