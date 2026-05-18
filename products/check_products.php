<?php
// ==========================================
// ໄຟລ໌ກວດສອບລາຍການສິນຄ້າ (Check Products API)
// ------------------------------------------
// ໜ້າທີ່: ດຶງຂໍ້ມູນສິນຄ້າທັງໝົດໃນລະບົບ ແລະ ສົ່ງຄືນເປັນ JSON ທີ່ອ່ານງ່າຍ (Pretty Print)
// ==========================================

require_once '../config/db.php';

// ດຶງລາຍການສິນຄ້າທັງໝົດ
$stmt = $pdo->query("SELECT * FROM products");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ສົ່ງຄືນຂໍ້ມູນໃນຮູບແບບ JSON
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

