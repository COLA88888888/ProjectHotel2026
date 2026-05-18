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
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;500;600;700&family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', 'Phetsarath OT', sans-serif; 
            background-color: #f0f2f5; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .print-container { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 90%; }
        .hotel-name { font-size: 1.5rem; font-weight: bold; color: #2c3e50; margin-bottom: 10px; }
        .qr-image { width: 100%; max-width: 250px; border: 10px solid #f8f9fa; border-radius: 15px; margin: 20px 0; }
        .instruction { font-size: 1.1rem; color: #333; font-weight: 600; margin-bottom: 20px; }
        .btn-print { background: #3498db; color: white; border: none; padding: 12px 30px; border-radius: 10px; font-size: 1rem; cursor: pointer; transition: 0.3s; font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', sans-serif; font-weight: bold; }
        .btn-print:hover { background: #2980b9; }
        @media print {
            * {
                color: #000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .btn-print { display: none; }
            body { background: white; }
            .print-container { box-shadow: none; border: 2px solid #eee; }
        }
    </style>
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
