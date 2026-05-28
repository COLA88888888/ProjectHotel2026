<?php
session_start();
require_once '../config/db.php';

// Get current server URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$base_url = "$protocol://$host/ProjectHotel2026/customer_order.php";

// Using a public API to generate QR Code
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($base_url);
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ພິມ QR Code ສຳລັບສັ່ງອາຫານ</title>
    <link rel="stylesheet" href="../assets/css/pages/generate_qr.css">
</head>
<body>

<div class="print-container">
    <div class="hotel-name">Hotel Room Service 🍽️</div>
    <div class="instruction">ສະແກນ QR Code ເພື່ອສັ່ງອາຫານ</div>
    
    <img src="<?= $qr_api ?>" alt="QR Code" class="qr-image">
    
    <div style="font-size: 0.9rem; color: #888; margin-bottom: 20px;">
        <i class="fas fa-info-circle"></i> ພິມບັດນີ້ໄປຕິດໄວ້ໃນຫ້ອງພັກ <br>
        ເພື່ອໃຫ້ລູກຄ້າສັ່ງອາຫານໄດ້ທັນທີ
    </div>

    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> ພິມດຽວນີ້
    </button>
</div>

</body>
</html>
