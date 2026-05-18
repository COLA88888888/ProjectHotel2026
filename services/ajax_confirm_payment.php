<?php
session_start();
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
    $amount = (float)$_POST['amount'];
    $room_number = $_POST['room_number'];
    
    $stmt = $pdo->prepare("INSERT INTO payment_notifications (booking_id, room_number, amount, status) VALUES (?, ?, ?, 'New')");
    if ($stmt->execute([$booking_id, $room_number, $amount])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
}
?>
