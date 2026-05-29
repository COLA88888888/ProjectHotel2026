<?php
require_once '../config/session_check.php';
enforcePermission('report');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

// Fetch default currency
$stmtCur = $pdo->query("SELECT symbol FROM currency WHERE is_default = 1 LIMIT 1");
$currency_symbol = $stmtCur->fetchColumn() ?: '₭';

$today = date('Y-m-d');
$monday_this_week = date('Y-m-d', strtotime('monday this week'));
$first_day_this_month = date('Y-m-01');

$start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Default to start of month
$end_date = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$start_date_formatted = date('d/m/Y', strtotime($start_date));
$end_date_formatted = date('d/m/Y', strtotime($end_date));

// Fetch Tax Percent
$stmtTax = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_percent'");
$tax_percent = (float)($stmtTax->fetchColumn() ?: 0);
$tax_mult = 1 + ($tax_percent / 100);

// Handle Delete Booking Record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_booking_record'])) {
    if (!hasPermission('bookings_delete')) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບລາຍການນີ້!";
        header("Location: report_checkin_checkout.php?start_date=$start_date&end_date=$end_date");
        exit();
    }
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
    <link class="no-print" rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link class="no-print" rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/report_checkin_checkout.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="container-fluid">
    <!-- Header with Inline Filter Form -->
    <div class="section-header no-print d-flex flex-wrap align-items-center justify-content-between mb-4" style="gap: 15px;">
        <h2><i class="fas fa-list text-primary mr-2"></i> <?php echo $lang['checkin_checkout_report'] ?? 'ລາຍງານການພັກ'; ?></h2>
        
        <!-- Search Filter Form -->
        <form method="GET" class="form-inline d-flex flex-wrap align-items-center" style="gap: 10px;" id="searchForm">
            <!-- Period Quick Links -->
            <div class="btn-group btn-group-sm mr-2 shadow-sm" role="group">
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $today; ?>', '<?php echo $today; ?>')">ມື້ນີ້</button>
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $monday_this_week; ?>', '<?php echo $today; ?>')">ອາທິດນີ້</button>
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $first_day_this_month; ?>', '<?php echo $today; ?>')">ເດືອນນີ້</button>
            </div>

            <!-- Date Fields -->
            <div class="input-group input-group-sm" style="width: 170px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0"><i class="fas fa-calendar-alt text-success"></i></span>
                </div>
                <input type="date" name="start_date" id="start_date" class="form-control border-left-0" value="<?php echo $start_date; ?>">
            </div>
            <div class="input-group input-group-sm" style="width: 170px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0">ຫາ</span>
                </div>
                <input type="date" name="end_date" id="end_date" class="form-control border-left-0" value="<?php echo $end_date; ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold">
                <i class="fas fa-search mr-1"></i> ຄົ້ນຫາ
            </button>
        </form>
    </div>

    <!-- Premium Stat Cards Grid -->
    <?php
    $total_pending_sum = $total_due_sum - $total_paid_sum;
    if ($total_pending_sum < 0) $total_pending_sum = 0;
    ?>
    <div class="stat-cards-row mb-4 no-print">
        <!-- 1. Total Received -->
        <div class="stat-card-premium blue">
            <div>
                <div class="stat-card-label"><?php echo $lang['total_revenue'] ?? 'ລາຍຮັບທີ່ໄດ້ຮັບທັງໝົດ'; ?></div>
                <div class="stat-card-value"><?php echo number_format($total_paid_sum); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-check-circle mr-1"></i> <?php echo $lang['paid_in_period'] ?? 'ຮັບແລ້ວໃນຊ່ວງເວລານີ້'; ?></div>
            <i class="fas fa-wallet stat-card-icon"></i>
        </div>

        <!-- 2. Cash -->
        <div class="stat-card-premium green">
            <div>
                <div class="stat-card-label"><?php echo $lang['cash'] ?? 'ເງິນສົດ'; ?> (Cash)</div>
                <div class="stat-card-value"><?php echo number_format($total_cash_sum); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-money-bill-wave mr-1"></i> ຮັບດ້ວຍເງິນສົດ</div>
            <i class="fas fa-money-bill-wave stat-card-icon"></i>
        </div>

        <!-- 3. Transfer -->
        <div class="stat-card-premium purple">
            <div>
                <div class="stat-card-label"><?php echo $lang['transfer'] ?? 'ເງິນໂອນ'; ?> (Transfer)</div>
                <div class="stat-card-value"><?php echo number_format($total_transfer_sum); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-university mr-1"></i> ຮັບດ້ວຍເງິນໂອນ</div>
            <i class="fas fa-university stat-card-icon"></i>
        </div>

        <!-- 4. Unpaid/Pending -->
        <div class="stat-card-premium orange">
            <div>
                <div class="stat-card-label"><?php echo $lang['pending'] ?? 'ຍັງຄ້າງຊຳລະ'; ?></div>
                <div class="stat-card-value"><?php echo number_format($total_pending_sum); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-clock mr-1"></i> ຍອດຄ້າງຊຳລະທີ່ຍັງເຫຼືອ</div>
            <i class="fas fa-clock stat-card-icon"></i>
        </div>
    </div>

    <!-- Table -->
    <div class="card card-outline card-warning shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between py-3" style="gap: 15px;">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-list text-warning mr-2"></i> <?php echo $lang['checkin_checkout_report'] ?? 'ລາຍງານການພັກ'; ?>
            </h5>
            <!-- Export Buttons -->
            <div class="d-flex align-items-center no-print ml-auto" style="gap: 10px;">
                <button type="button" class="btn btn-export btn-export-excel" onclick="exportTableToExcel()">
                    <i class="fas fa-file-excel mr-1"></i> Excel
                </button>
                <button type="button" class="btn btn-export btn-export-pdf" id="btnPdf">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </button>
            </div>
        </div>
        <div class="card-body p-2 p-md-3">
            <div class="table-responsive">
                <table id="checkinCheckoutTable" class="table table-bordered table-striped text-center mb-0 table-compact" style="width: 100%;">
                    <thead class="bg-warning text-white">
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
                            <th class="no-print"><?php echo $lang['action'] ?? 'ຈັດການ'; ?></th>
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
                                        <div class="btn-action-group">
                                            <?php if ($remaining <= 0): ?>
                                            <a href="../print/print_room_receipt.php?booking_id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm text-primary" title="ພິມບິນ">
                                                <i class="fas fa-print"></i> <span>ພິມ</span>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-sm text-secondary" disabled title="ຍັງບໍ່ທັນຊຳລະຄົບ">
                                                <i class="fas fa-print"></i> <span>ພິມ</span>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <?php if (hasPermission('bookings_delete')): ?>
                                            <button class="btn btn-sm text-danger btn-delete-booking" data-id="<?php echo $row['id']; ?>" data-customer="<?php echo htmlspecialchars($row['customer_name']); ?>" data-room="<?php echo htmlspecialchars($row['room_number']); ?>" title="ລຶບລາຍການ">
                                                <i class="fas fa-trash-alt"></i> <span>ລຶບ</span>
                                            </button>
                                            <?php endif; ?>
                                        </div>
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
                            <td colspan="7" class="text-center font-weight-bold text-uppercase" style="vertical-align: middle;"><?php echo $lang['total'] ?? 'TOTAL (ລວມທັງໝົດ)'; ?>:</td>
                            <td class="text-right text-success" style="vertical-align: middle; font-size: 1.1rem;">
                                <?php echo number_format($total_due_sum); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="text-right text-muted" style="vertical-align: middle; font-size: 0.8rem; font-weight: normal;">
                                ຮັບແລ້ວ: <span class="text-success font-weight-bold"><?php echo number_format($total_paid_sum); ?></span><br>
                                ຄ້າງຊຳລະ: <span class="text-danger font-weight-bold"><?php echo number_format($total_pending_sum); ?></span>
                            </td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================
     HIDDEN PRINT TARGET FOR A4 LANDSCAPE PDF EXPORT
     ============================================== -->
<div id="pdfExportContainer" style="display: none;">
    <div class="pdf-table-container">
        <style>
            .pdf-table-container {
                font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', 'Segoe UI', sans-serif !important;
                padding: 15px;
            }
            .pdf-table-title {
                font-size: 16px;
                font-weight: bold;
                text-align: center;
                margin-bottom: 5px;
                color: #2c3e50;
            }
            .pdf-table-subtitle {
                font-size: 11px;
                text-align: center;
                margin-bottom: 20px;
                color: #7f8c8d;
            }
            .pdf-table-container table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-bottom: 10px !important;
            }
            .pdf-table-container th, .pdf-table-container td {
                border: 1px solid #666666 !important;
                padding: 8px 6px !important;
                font-size: 10px !important;
                line-height: 1.5 !important;
                word-break: break-word !important;
                white-space: normal !important;
                vertical-align: middle !important;
            }
            .pdf-table-container th {
                background-color: #2c3e50 !important;
                color: #ffffff !important;
                font-weight: bold !important;
                text-align: center !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .pdf-table-container .text-right {
                text-align: right !important;
            }
            .pdf-table-container .text-left {
                text-align: left !important;
            }
            .pdf-table-container .text-center {
                text-align: center !important;
            }
        </style>
        <div class="pdf-table-title" id="pdfReportTitle">ລາຍງານລາຍຮັບ ຈາກການພັກ</div>
        <div class="pdf-table-subtitle">ໄລຍະເວລາ: <?php echo $start_date_formatted; ?> ຫາ <?php echo $end_date_formatted; ?></div>
        <div id="pdfTablePlaceholder"></div>
    </div>
</div>

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<!-- DataTables -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<!-- Local HTML2PDF Library -->
<script src="../plugins/html2pdf/html2pdf.bundle.min.js?v=<?php echo time(); ?>"></script>

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

    // PDF Export Click Handler
    $('#btnPdf').click(function() {
        var pdfTitle = "ລາຍງານລາຍຮັບ ຈາກການພັກ";
        var tableHtml = `
            <table>
                <thead>
                    <tr>
                        <th>ຫ້ອງ</th>
                        <th class="text-left">ລູກຄ້າ / ຈຳນວນຄົນ</th>
                        <th>ເບີໂທ</th>
                        <th>ວັນທີເຂົ້າ</th>
                        <th>ວັນທີອອກ</th>
                        <th>ຈຳນວນຄືນ</th>
                        <th class="text-right">ຄ່າອາຫານ</th>
                        <th class="text-right">ລວມ</th>
                        <th class="text-right">ຍອດຊຳລະ</th>
                    </tr>
                </thead>
                <tbody>
        `;
        $('#checkinCheckoutTable tbody tr').each(function() {
            var cols = $(this).find('td');
            if (cols.length > 1) {
                var room = $(cols[0]).text().replace(/\s+/g, ' ').trim();
                var customer = $(cols[1]).text().replace(/\s+/g, ' ').trim();
                var phone = $(cols[2]).text().trim();
                var checkin = $(cols[3]).text().trim();
                var checkout = $(cols[4]).text().trim();
                var nights = $(cols[5]).text().trim();
                var food = $(cols[6]).text().trim();
                var total = $(cols[7]).text().trim();
                var payment = $(cols[8]).text().replace(/\s+/g, ' ').trim();

                tableHtml += `
                    <tr>
                        <td class="text-center" style="font-weight:bold;">${room}</td>
                        <td class="text-left">${customer}</td>
                        <td class="text-center">${phone}</td>
                        <td class="text-center" style="font-weight:bold; color: #2c3e50;">${checkin}</td>
                        <td class="text-center" style="color:#d9534f;">${checkout}</td>
                        <td class="text-center">${nights}</td>
                        <td class="text-right">${food}</td>
                        <td class="text-right" style="font-weight:bold;">${total}</td>
                        <td class="text-right" style="font-weight:bold; color: #2c3e50;">${payment}</td>
                    </tr>
                `;
            }
        });

        // Add footer
        var footerCols = $('#checkinCheckoutTable tfoot tr').find('td');
        if (footerCols.length > 0) {
            var totalLabel = $(footerCols[0]).text().replace(/\s+/g, ' ').trim();
            var totalPaid = $(footerCols[1]).text().trim();
            var breakdown = $(footerCols[2]).text().replace(/\s+/g, ' ').trim();
            
            tableHtml += `
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background-color: #f9f9f9;">
                        <td colspan="7" class="text-right">${totalLabel}:</td>
                        <td class="text-right" style="color:#2c3e50; font-weight:bold;">${totalPaid}</td>
                        <td class="text-right" style="font-size:0.8rem; color: #7f8c8d;">${breakdown}</td>
                    </tr>
                </tfoot>
            </table>`;
        } else {
            tableHtml += `</tbody></table>`;
        }

        $('#pdfTablePlaceholder').html(tableHtml);
        
        var opt = {
            margin: 12,
            filename: pdfTitle + '_' + new Date().toISOString().split('T')[0] + '.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2.5, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };
        var element = document.getElementById('pdfExportContainer');
        element.style.display = 'block';
        html2pdf().set(opt).from(element).save().then(function() {
            element.style.display = 'none';
            Swal.fire({ icon: 'success', title: 'ດາວໂຫຼດ PDF ສຳເລັດ', confirmButtonColor: '#28a745', confirmButtonText: 'ຕົກລົງ' });
        }).catch(function(err) {
            element.style.display = 'none';
            Swal.fire({ icon: 'error', title: 'ຜິດພາດ', text: err, confirmButtonColor: '#d33', confirmButtonText: 'ຕົກລົງ' });
        });
    });
});

// Excel Export Function
function exportTableToExcel() {
    var filename = "ລາຍງານການພັກ_" + new Date().toISOString().split('T')[0] + ".xls";
    
    // Build HTML spreadsheet with custom Lao font support
    var excelHtml = `
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>ລາຍງານການພັກ</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml><![endif]-->
<style>
  body, table, td, th {
    font-family: 'Phetsarath OT', 'Saysettha OT', 'Noto Sans Lao', Arial Unicode MS, sans-serif;
    mso-number-format: '@';
  }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1pt solid #999999; padding: 6px; font-size: 11pt; mso-number-format: '@'; }
  th { background-color: #2c3e50; color: #ffffff; font-weight: bold; text-align: center; }
  .text-right { text-align: right; }
  .text-left { text-align: left; }
  .text-center { text-align: center; }
  .title-row { font-size: 14pt; font-weight: bold; height: 35px; text-align: center; }
  .total-row { font-weight: bold; background-color: #f9f9f9; }
</style>
</head>
<body>
<table>
  <thead>
    <tr class="title-row"><th colspan="9">ລາຍງານລາຍຮັບການເຂົ້າພັກ (Room Stays Revenue Report)</th></tr>
    <tr class="title-row"><th colspan="9" style="font-size: 10pt; font-weight: normal; color: #555;">ໄລຍະເວລາ: <?php echo $start_date_formatted; ?> ຫາ <?php echo $end_date_formatted; ?></th></tr>
    <tr>
      <th>ຫ້ອງ</th>
      <th>ລູກຄ້າ / ຈຳນວນຄົນ</th>
      <th>ເບີໂທ</th>
      <th>ວັນທີເຂົ້າ</th>
      <th>ວັນທີອອກ</th>
      <th>ຈຳນວນຄືນ</th>
      <th>ຄ່າອາຫານ</th>
      <th>ລວມ</th>
      <th>ຍອດຊຳລະ</th>
    </tr>
  </thead>
  <tbody>
`;

    $('#checkinCheckoutTable tbody tr').each(function() {
        var cols = $(this).find('td');
        if (cols.length > 1) {
            var roomText = $(cols[0]).text().replace(/\s+/g, ' ').trim();
            var customerText = $(cols[1]).text().replace(/\s+/g, ' ').trim();
            var phoneText = $(cols[2]).text().trim();
            var checkinText = $(cols[3]).text().trim();
            var checkoutText = $(cols[4]).text().trim();
            var nightsText = $(cols[5]).text().trim();
            var foodText = $(cols[6]).text().trim().replace(/[₭,]/g, '');
            var totalText = $(cols[7]).text().trim().replace(/[₭,]/g, '');
            var paymentText = $(cols[8]).text().replace(/\s+/g, ' ').trim();

            excelHtml += `
    <tr>
      <td class="text-center" style="font-weight:bold;">${roomText}</td>
      <td class="text-left">${customerText}</td>
      <td class="text-center">${phoneText}</td>
      <td class="text-center">${checkinText}</td>
      <td class="text-center">${checkoutText}</td>
      <td class="text-center">${nightsText}</td>
      <td class="text-right">${foodText}</td>
      <td class="text-right">${totalText}</td>
      <td class="text-right">${paymentText}</td>
    </tr>`;
        }
    });

    var footerCols = $('#checkinCheckoutTable tfoot tr').find('td');
    if (footerCols.length > 0) {
        var totalLabel = $(footerCols[0]).text().replace(/\s+/g, ' ').trim();
        var totalPaid = $(footerCols[1]).text().trim().replace(/[₭,]/g, '');
        var breakdown = $(footerCols[2]).text().replace(/\s+/g, ' ').trim();
        
        excelHtml += `
    <tr class="total-row">
      <td colspan="7" class="text-right">${totalLabel}:</td>
      <td class="text-right">${totalPaid}</td>
      <td class="text-right" style="font-size:9.5pt; font-weight:normal; color:#555;">${breakdown}</td>
    </tr>`;
    }

    excelHtml += `
  </tbody>
</table>
</body>
</html>`;

    var blob = new Blob(["\ufeff", excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var link = document.createElement("a");
    if (link.download !== undefined) {
        var url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        Swal.fire({ icon: 'success', title: 'Export Excel ສຳເລັດ', confirmButtonColor: '#28a745', confirmButtonText: 'ຕົກລົງ' });
    }
}

// Quick Period Selector Function
function setPeriod(start, end) {
    $('#start_date').val(start);
    $('#end_date').val(end);
    $('#searchForm').submit();
}
</script>
</body>
</html>
