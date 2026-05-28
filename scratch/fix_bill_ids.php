<?php
// Script to fix corrupted bill IDs in the database
// Renames them to the correct 4-digit sequential format per day

require_once '../config/db.php';

echo "=== Bill ID Fix Script ===\n\n";

// Get all corrupted S- bill IDs grouped by date
$stmt = $pdo->query("
    SELECT DISTINCT bill_id 
    FROM orders 
    WHERE bill_id REGEXP '^S-[0-9]+$'
    ORDER BY LEFT(bill_id, 10), bill_id ASC
");
$allBills = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Group by date prefix (first 10 chars: S-YYYYMMDD)
$byDate = [];
foreach ($allBills as $bid) {
    $prefix = substr($bid, 0, 10); // S-YYYYMMDD
    $byDate[$prefix][] = $bid;
}

$pdo->beginTransaction();
try {
    foreach ($byDate as $prefix => $bills) {
        $counter = 1;
        foreach ($bills as $oldBill) {
            $newBill = $prefix . str_pad($counter, 4, '0', STR_PAD_LEFT);
            if ($oldBill !== $newBill) {
                echo "Renaming: $oldBill  ->  $newBill\n";
                $upd = $pdo->prepare("UPDATE orders SET bill_id = ? WHERE bill_id = ?");
                $upd->execute([$newBill, $oldBill]);
            } else {
                echo "OK (no change): $oldBill\n";
            }
            $counter++;
        }
    }
    $pdo->commit();
    echo "\n=== Done! All bill IDs fixed successfully. ===\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
