<?php
session_start();
require_once 'config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

// Fetch default currency
$stmtCur = $pdo->query("SELECT symbol FROM currency WHERE is_default = 1 LIMIT 1");
$currency_symbol = $stmtCur->fetchColumn() ?: '₭';

$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of month
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch Tax Percent
$stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
$tax_percent = (float)($stmtTax->fetchColumn() ?: 0);
$tax_mult = 1 + ($tax_percent / 100);

// Handle Delete Booking Record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_booking_record'])) {
    $booking_id = (int)$_POST['booking_id'];
    
    // First retrieve booking room information to see if it's currently staying/occupied
    $stmtB = $pdo->prepare("SELECT room_id, status, customer_name FROM bookings WHERE id = ?");
    $stmtB->execute([$booking_id]);
    $booking = $stmtB->fetch();
    
    if ($booking) {
        $room_id = $booking['room_id'];
        $status = $booking['status'];
        
        // Delete related room services first to prevent foreign key errors
        $pdo->prepare("DELETE FROM room_services WHERE booking_id = ?")->execute([$booking_id]);
        
        // Delete the booking record
        $stmtDelete = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
        if ($stmtDelete->execute([$booking_id])) {
            
            // If the deleted record was active (e.g. Occupied or Checked In), reset the room status to Available
            if ($status == 'Occupied' || $status == 'Checked In' || $status == 'Booked') {
                $pdo->prepare("UPDATE rooms SET status = 'Available', housekeeping_status = 'Available' WHERE id = ?")->execute([$room_id]);
            }
            
            logActivity($pdo, "ລຶບລາຍການເຂົ້າພັກ", "Booking ID: $booking_id, ລູກຄ້າ: " . ($booking['customer_name'] ?? ''));
            $_SESSION['success'] = "ລຶບຂໍ້ມູນລາຍການເຂົ້າພັກສຳເລັດແລ້ວ!";
        } else {
            $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດໃນການລຶບຂໍ້ມູນ!";
        }
    }
    
    header("Location: report_checkin_checkout.php?start_date=$start_date&end_date=$end_date");
    exit();
}

$room_type_col = "room_type_name_" . $current_lang;

// Query all bookings in date range for DataTables
$stmtPayments = $pdo->prepare("
    SELECT b.*, r.room_number, rt.$room_type_col as room_type_localized, rt.room_type_name as room_type_base
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN :start_date AND :end_date
      AND b.status IN ('Occupied', 'Completed')
    ORDER BY b.id DESC
");
$stmtPayments->bindValue(':start_date', $start_date, PDO::PARAM_STR);
$stmtPayments->bindValue(':end_date', $end_date, PDO::PARAM_STR);
$stmtPayments->execute();
$payments_history = $stmtPayments->fetchAll();

// Calculate sums for all bookings in the selected date range
$stmtSum = $pdo->prepare("
    SELECT b.total_price, b.food_charge, b.deposit_amount, b.amount_received, b.status, b.payment_method
    FROM bookings b
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
      AND b.status IN ('Occupied', 'Completed')
");
$stmtSum->execute([$start_date, $end_date]);
$all_bookings = $stmtSum->fetchAll();

$total_paid_sum = 0;
$total_due_sum = 0;
$total_cash_sum = 0;
$total_transfer_sum = 0;
$completed_count = 0;
$deposit_count = 0;
$unpaid_count = 0;

foreach ($all_bookings as $b) {
    $subtotal = $b['total_price'] + $b['food_charge'];
    $row_tax = round($subtotal * ($tax_percent / 100));
    $total_due = $subtotal + $row_tax;
    
    $total_due_sum += $total_due;
    
    $paid_amount = 0;
    if ($b['status'] == 'Completed') {
        $paid_amount = $total_due;
        $completed_count++;
    } else {
        $paid_amount = $b['deposit_amount'];
        if ($paid_amount > 0) {
            $deposit_count++;
        } else {
            $unpaid_count++;
        }
    }
    
    $total_paid_sum += $paid_amount;
    
    // Payment method breakdown
    $p_method = $b['payment_method'];
    if ($paid_amount > 0) {
        if (stripos($p_method, 'Cash') !== false || stripos($p_method, 'ເງິນສົດ') !== false) {
            $total_cash_sum += $paid_amount;
        } elseif (stripos($p_method, 'Transfer') !== false || stripos($p_method, 'ເງິນໂອນ') !== false || stripos($p_method, 'โอน') !== false) {
            $total_transfer_sum += $paid_amount;
        } else {
            $total_cash_sum += $paid_amount;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['checkin_checkout_report'] ?? 'ລາຍງານການພັກ'; ?></title>
    <!-- Bootstrap 4 -->
    <link class="no-print" rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link class="no-print" rel="stylesheet" href="sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #eef2f7; }
        .section-header h2 { margin: 0; font-weight: 800; color: #2c3e50; font-size: 1.7rem; }
        
        .card { border-radius: 16px !important; border: none !important; box-shadow: 0 10px 30px rgba(0,0,0,0.05) !important; }
        
        /* High contrast header */
        .card-header-custom {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%) !important;
            border-radius: 16px 16px 0 0 !important;
            padding: 18px 24px !important;
        }

        .table thead th {
            background-color: #f8f9fa;
            color: #333;
            font-weight: 700;
            border-bottom: 2px solid #dee2e6;
        }

        .page-link {
            color: #1e3c72;
            border: 1px solid #dee2e6;
            margin: 0 2px;
            padding: 6px 12px;
            transition: all 0.2s;
        }
        .page-item.active .page-link {
            background-color: #1e3c72 !important;
            border-color: #1e3c72 !important;
            color: #fff !important;
        }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .section-header h2 { font-size: 1.2rem; }
            .filter-wrapper { 
                flex-direction: column; 
                width: 100%; 
                gap: 12px !important; 
                background: #fff;
                padding: 15px;
                border-radius: 12px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.03);
            }
            .filter-wrapper .input-group { width: 100% !important; margin: 0 !important; }
            .filter-wrapper button { width: 100% !important; border-radius: 8px !important; font-weight: 700; }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .card { box-shadow: none !important; }
            .card-header-custom { background: #333 !important; color: #fff !important; border-radius: 0 !important; }
            .table-responsive { overflow: visible !important; }
        }

        /* Compact Table Styles to prevent scrollbars */
        .table-compact th, .table-compact td {
            padding: 0.4rem 0.5rem !important;
            vertical-align: middle;
        }
        .table-compact {
            font-size: 0.85rem !important;
        }
        .btn-action-group {
            display: flex; 
            gap: 5px; 
            justify-content: center; 
            align-items: center;
        }
        .btn-action-group .btn {
            background: transparent; 
            border: none; 
            display: flex; 
            flex-direction: column; 
            align-items: center;
            padding: 2px 4px;
        }
        .btn-action-group .btn i {
            font-size: 1rem;
        }
        .btn-action-group .btn span {
            font-size: 0.6rem; 
            font-weight: 600; 
            margin-top: 2px;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="fas fa-list text-primary"></i> <?php echo $lang['checkin_checkout_report'] ?? 'ລາຍງານການພັກ'; ?></h2>
        </div>
    </div>

    <!-- Search Form -->
    <div class="card card-primary card-outline shadow-sm no-print">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-search"></i> <?php echo $lang['search'] ?? 'ຄົ້ນຫາ'; ?></h3></div>
        <form method="GET" action="">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 col-12">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt text-success"></i> <?php echo $lang['start_date'] ?? 'ວັນທີເລີ່ມຕົ້ນ'; ?></label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check text-danger"></i> <?php echo $lang['end_date'] ?? 'ວັນທີສິ້ນສຸດ'; ?></label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                        </div>
                    </div>
                    <div class="col-md-4 col-12 d-flex align-items-end">
                        <div class="form-group w-100">
                            <button type="submit" class="btn btn-primary btn-block text-white">
                                <i class="fas fa-search"></i> <span><?php echo $lang['search'] ?? 'ຄົ້ນຫາ'; ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="card card-outline card-warning shadow-sm">
        <div class="card-body p-2 p-md-3">
            <table id="checkinCheckoutTable" class="table table-bordered table-striped text-center mb-0 table-compact" style="width: 100%;">
                <thead class="bg-warning text-white" style="white-space: nowrap;">
                    <tr>
                        <th><?php echo $lang['room'] ?? 'ຫ້ອງ'; ?></th>
                        <th><?php echo $lang['customer'] ?? 'ລູກຄ້າ'; ?> / <?php echo $lang['guests'] ?? 'ຈຳນວນຄົນ'; ?></th>
                        <th><?php echo $lang['phone'] ?? 'ເບີໂທ'; ?></th>
                        <th><?php echo $lang['checkin_date'] ?? 'ວັນທີເຂົ້າ'; ?></th>
                        <th><?php echo $lang['checkout_date'] ?? 'ວັນທີອອກ'; ?></th>
                        <th><?php echo $lang['nights'] ?? 'ຈຳນວນຄືນ'; ?></th>
                        <th><?php echo $lang['additional_services'] ?? 'ຄ່າອາຫານ'; ?></th>
                        <th><?php echo $lang['total'] ?? 'ລວມ'; ?></th>
                        <th><?php echo $lang['payment_amount'] ?? 'ຍອດຊຳລະ'; ?></th>
                        <th class="no-print"><?php echo $lang['print'] ?? 'ພິມ'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (count($payments_history) > 0):
                        foreach($payments_history as $row): 
                            $subtotal = $row['total_price'] + $row['food_charge'];
                            $row_tax = round($subtotal * ($tax_percent / 100));
                            $total_due = $subtotal + $row_tax;

                            $paid_amount = 0;
                            if ($row['status'] == 'Completed') {
                                $paid_amount = $total_due;
                            } else {
                                $paid_amount = $row['deposit_amount'];
                            }
                            
                            $diff = date_diff(date_create($row['check_in_date']), date_create($row['check_out_date']));
                            $nights = $diff->format("%a");
                            if ($nights < 1) $nights = 1;
                            
                            $paid_status_badge = '';
                            $remaining = $total_due - $paid_amount;
                            $display_amount = 0;
                            
                            if ($paid_amount >= $total_due) {
                                $paid_status_badge = '<br><span class="badge badge-success mt-1" style="font-size: 0.75rem;">' . ($lang['paid'] ?? 'ຈ່າຍແລ້ວ') . '</span>';
                                $display_amount = $paid_amount; // Show total paid for completed bookings
                            } elseif ($paid_amount > 0) {
                                $paid_status_badge = '<br><span class="badge badge-warning mt-1" style="font-size: 0.75rem;">' . ($lang['room_fee_paid'] ?? 'ຈ່າຍຄ່າຫ້ອງ') . ': ' . number_format($paid_amount) . '</span>';
                                $paid_status_badge .= '<br><span class="badge badge-danger mt-1" style="font-size: 0.75rem;">ຄ້າງຊຳລະ: ' . number_format($remaining) . '</span>';
                                $display_amount = $remaining; // Show remaining balance for occupied bookings
                            } else {
                                $paid_status_badge = '<br><span class="badge badge-danger mt-1" style="font-size: 0.75rem;">' . ($lang['unpaid'] ?? 'ຍັງບໍ່ຈ່າຍ') . ': ' . number_format($remaining) . '</span>';
                                $display_amount = $remaining; // Show total due
                            }
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['room_number']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['room_type_localized'] ?: $row['room_type_base']); ?></small>
                                </td>
                                <td class="text-left">
                                    <div class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                    <div class="small text-muted"><i class="fas fa-users mr-1"></i> ຈຳນວນຄົນ: <strong><?php echo $row['guest_count']; ?></strong> ຄົນ</div>
                                </td>
                                <td><?php echo htmlspecialchars($row['customer_phone']); ?></td>
                                <td class="text-success font-weight-bold"><?php echo date('d/m/Y', strtotime($row['check_in_date'])); ?></td>
                                <td class="text-danger"><?php echo date('d/m/Y', strtotime($row['check_out_date'])); ?></td>
                                <td><?php echo $nights; ?></td>
                                <td class="text-right text-info"><?php echo number_format($row['food_charge']); ?></td>
                                <td class="text-right"><?php echo number_format($total_due); ?> <?php echo $currency_symbol; ?></td>
                                <td class="text-right text-success font-weight-bold">
                                    <?php echo number_format($display_amount); ?> <?php echo $currency_symbol; ?>
                                    <?php echo $paid_status_badge; ?>
                                </td>
                                <td class="no-print text-center align-middle">
                                    <?php if ($remaining <= 0): ?>
                                    <a href="print_room_receipt.php?booking_id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="ພິມບິນ">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="ຍັງບໍ່ທັນຊຳລະຄົບ">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="py-4 text-muted"><?php echo $lang['table_zero_records'] ?? 'ບໍ່ມີຂໍ້ມູນ'; ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="font-weight-bold" style="background-color: #f8f9fa; color: #333; border-top: 2px solid #dee2e6;">
                        <td colspan="7" class="text-center font-weight-bold text-uppercase" style="vertical-align: middle;"><?php echo $lang['total'] ?? 'TOTAL (ລວມທັງໝົດ)'; ?></td>
                        <td class="text-right font-weight-bold text-success" style="vertical-align: middle; font-size: 1rem;">
                            <?php echo number_format($total_paid_sum); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="text-center" style="vertical-align: middle; font-size: 0.9rem;">
                            <?php echo $lang['cash'] ?? 'ເງິນສົດ'; ?>: <?php echo number_format($total_cash_sum); ?> | <?php echo $lang['transfer'] ?? 'ໂອນ'; ?>: <?php echo number_format($total_transfer_sum); ?>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="sweetalert/dist/sweetalert2.all.min.js"></script>
<!-- DataTables -->
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#checkinCheckoutTable').DataTable({
        "language": {
            "search": "<?php echo $lang['dt_search'] ?? $lang['search']; ?>:",
            "lengthMenu": "<?php echo $lang['dt_length']; ?>",
            "info": "<?php echo $lang['dt_info']; ?>",
            "infoEmpty": "<?php echo $lang['dt_info_empty'] ?? $lang['table_info_empty']; ?>",
            "zeroRecords": "<?php echo $lang['dt_zeroRecords']; ?>",
            "paginate": {
                "next": "<?php echo $lang['dt_paginate_next'] ?? $lang['next']; ?>",
                "previous": "<?php echo $lang['dt_paginate_previous'] ?? $lang['previous']; ?>"
            }
        },
        "order": [],
        "pageLength": 10
    });

    // Delete Record Confirmation
    $('.btn-delete-booking').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var customer = $(this).data('customer');
        var room = $(this).data('room');
        
        Swal.fire({
            title: 'ຢືນຢັນການລຶບ?',
            text: "ທ່ານຕ້ອງການລຶບຂໍ້ມູນການພັກຂອງລູກຄ້າ \"" + customer + "\" ຫ້ອງ " + room + " ແທ້ຫຼືບໍ່? ການກະທຳນີ້ບໍ່ສາມາດຍົກເລີກໄດ້!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ຢືນຢັນ, ລຶບເລີຍ!',
            cancelButtonText: 'ຍົກເລີກ'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a temporary form to submit
                var form = $('<form action="" method="post">' +
                             '<input type="hidden" name="delete_booking_record" value="1">' +
                             '<input type="hidden" name="booking_id" value="' + id + '">' +
                             '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    // SweetAlert Session notifications
    <?php if(isset($_SESSION['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: '<?php echo $lang['success_label'] ?? 'ສຳເລັດ'; ?>',
        text: '<?php echo $_SESSION['success']; ?>',
        confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
    });
    <?php unset($_SESSION['success']); endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: '<?php echo $lang['error_label'] ?? 'ຜິດພາດ'; ?>',
        text: '<?php echo $_SESSION['error']; ?>',
        confirmButtonText: '<?php echo $lang['ok'] ?? 'ຕົກລົງ'; ?>'
    });
    <?php unset($_SESSION['error']); endif; ?>
});
</script>
</body>
</html>
