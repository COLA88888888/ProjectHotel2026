<?php
require_once __DIR__ . '/../config/db.php';
$q = $pdo->query("DESCRIBE orders");
$cols = $q->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_PRETTY_PRINT);
