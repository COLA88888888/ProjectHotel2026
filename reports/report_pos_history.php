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

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$prod_name_col = "prod_name_" . $current_lang;

$prod_name_col = "prod_name_" . $current_lang;

// Handle Delete POS Record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_pos_record'])) {
    if (!hasPermission('can_void')) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຍົກເລີກ ຫຼື ລຶບບິນຂາຍນີ້!";
        header("Location: report_pos_history.php?start_date=$start_date&end_date=$end_date");
        exit();
    }
    $bill_id = $_POST['bill_id_to_delete'];
    
    $stmtDelete = $pdo->prepare("DELETE FROM orders WHERE bill_id = ?");
    if ($stmtDelete->execute([$bill_id])) {
        logActivity($pdo, "ລຶບປະຫວັດການຂາຍ POS", "Bill ID: $bill_id");
        $_SESSION['success'] = "ລຶບຂໍ້ມູນການຂາຍສຳເລັດແລ້ວ!";
    } else {
        $_SESSION['error'] = "ເກີດຂໍ້ຜິດພາດໃນການລຶບຂໍ້ມູນ!";
    }
    
    header("Location: report_pos_history.php?start_date=$start_date&end_date=$end_date");
    exit();
}

// Query POS History in Date Range
$stmtRecentPos = $pdo->prepare("
    SELECT o.*, p.prod_code, p.sprice as prod_price, p.prod_name, p.$prod_name_col as prod_name_localized, p.category, u.fname, u.lname, u.username
    FROM orders o 
    JOIN products p ON o.prod_id = p.prod_id 
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.order_id DESC
");
$stmtRecentPos->execute([$start_date, $end_date]);
$all_records = $stmtRecentPos->fetchAll();

$grouped_bills = [];
foreach($all_records as $row) {
    $bill = $row['bill_id'] ?: 'ORDER-'.$row['order_id'];
    if (!isset($grouped_bills[$bill])) {
        $grouped_bills[$bill] = [
            'bill_id' => $row['bill_id'] ?? $row['order_id'],
            'total_items' => 0,
            'total_amount' => 0,
            'payment_method' => $row['payment_method'],
            'seller' => $row['fname'] ? ($row['fname'] . ' ' . $row['lname']) : ($row['username'] ?? '-'),
            'created_at' => $row['created_at'],
            'items' => []
        ];
    }
    $grouped_bills[$bill]['total_items'] += $row['o_qty'];
    $grouped_bills[$bill]['total_amount'] += $row['amount'];
    
    $cat_text = $row['category'];
    if($row['category'] == 'Food') $cat_text = $lang['food_cat'] ?? 'ອາຫານ';
    elseif($row['category'] == 'Drink') $cat_text = $lang['drink_cat'] ?? 'ເຄື່ອງດື່ມ';
    elseif($row['category'] == 'Service') $cat_text = $lang['service_cat'] ?? 'ບໍລິການ';

    $grouped_bills[$bill]['items'][] = [
        'prod_code' => $row['prod_code'],
        'prod_name' => $row['prod_name_localized'] ?: $row['prod_name'],
        'category' => $cat_text,
        'price' => $row['prod_price'],
        'qty' => $row['o_qty'],
        'subtotal' => $row['amount']
    ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['pos_history_report'] ?? 'ປະຫວັດການຂາຍ POS'; ?></title>
    <!-- Bootstrap 4 -->
    <link class="no-print" rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link class="no-print" rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #eef2f7; }
        .section-header h2 { margin: 0; font-weight: 800; color: #2c3e50; font-size: 1.5rem; }
        
        .card { border-radius: 16px !important; border: none !important; box-shadow: 0 10px 30px rgba(0,0,0,0.05) !important; }
        
        .card-header-custom {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%) !important;
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
            border-bottom: none !important;
        }
        .card-header-custom h3 { color: #fff !important; }

        .btn-gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%) !important;
            border: none !important;
            color: #fff !important;
            border-radius: 8px !important;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-gradient-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(56, 239, 125, 0.4);
            color: #fff !important;
        }

        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-weight: 700;
        }
        .table tbody td {
            vertical-align: middle !important;
        }
        
        /* Clean Plain Text Styles */
        .plain-bold { font-weight: 700; color: #2c3e50; }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 15px; padding-bottom: 10px; }
            .section-header h2 { font-size: 1.3rem; margin-bottom: 0; }
            .card-title { font-size: 1.05rem !important; }
            .card-body { padding: 10px !important; }
        }
        @media (max-width: 480px) {
            .section-header h2 { font-size: 1.15rem; }
            .card-title { font-size: 0.95rem !important; }
            .btn-gradient-success { padding: 6px 15px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="section-header no-print">
        <h2><i class="fas fa-shopping-cart mr-2 text-success"></i> <?php echo $lang['pos_history_report'] ?? 'ປະຫວັດການຂາຍ POS'; ?></h2>
    </div>

    <!-- Date Range Picker Form -->
    <div class="card p-3 mb-4 no-print">
        <form method="GET" action="report_pos_history.php">
            <div class="row align-items-end">
                <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted mb-1"><?php echo $lang['start_date'] ?? 'ວັນທີເລີ່ມຕົ້ນ'; ?></label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" style="border-radius: 8px;">
                </div>
                <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted mb-1"><?php echo $lang['end_date'] ?? 'ວັນທີສິ້ນສຸດ'; ?></label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" style="border-radius: 8px;">
                </div>
                <div class="col-md-4 col-sm-12">
                    <button type="submit" class="btn btn-gradient-success w-100"><i class="fas fa-search mr-2"></i> <?php echo $lang['search'] ?? 'ຄົ້ນຫາ'; ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- POS Sales History Table Card -->
    <div class="card mb-4">
        <div class="card-header-custom p-3">
            <h3 class="card-title mb-0" style="font-size: 1.2rem; font-weight: 700;">
                <i class="fas fa-list-alt mr-2"></i> <?php echo $lang['pos_history_title'] ?? 'ປະຫວັດການຂາຍ POS / ອາຫານ ແລະ ເຄື່ອງດື່ມ'; ?>
            </h3>
        </div>
        <div class="card-body p-2 p-md-3">
            <div class="table-responsive">
                <table id="posTable" class="table table-bordered table-striped table-hover text-center mb-0" style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th><?php echo $lang['bill_no'] ?? 'ເລກບິນ'; ?></th>
                            <th>ລາຍການສິນຄ້າ</th>
                            <th><?php echo $lang['subtotal'] ?? 'ຍອດລວມ'; ?></th>
                            <th><?php echo $lang['payment_method_label'] ?? 'ວິທີການຈ່າຍ'; ?></th>
                            <th>ຜູ້ຂາຍ</th>
                            <th><?php echo $lang['date'] ?? 'ວັນທີຂາຍ'; ?></th>
                            <th class="no-print"><?php echo $lang['action'] ?? 'ຈັດການ'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($grouped_bills) > 0):
                            foreach($grouped_bills as $b): 
                        ?>
                            <tr>
                                <td><span class="badge badge-info" style="font-size:0.85rem;"><?php echo htmlspecialchars($b['bill_id']); ?></span></td>
                                <td><strong><?php echo number_format($b['total_items']); ?></strong> <?php echo $lang['qty'] ?? 'ລາຍການ'; ?></td>
                                <td class="font-weight-bold text-success"><?php echo number_format($b['total_amount']); ?> <?php echo $currency_symbol; ?></td>
                                <td><?php echo htmlspecialchars($b['payment_method'] == 'Cash' ? 'ເງິນສົດ' : ($b['payment_method'] == 'Transfer' ? 'ເງິນໂອນ' : $b['payment_method'])); ?></td>
                                <td><?php echo htmlspecialchars($b['seller']); ?></td>
                                <td class="text-muted"><?php echo date('d/m/Y H:i', strtotime($b['created_at'])); ?></td>
                                <td class="no-print text-center">
                                    <button class="btn btn-sm btn-outline-primary btn-view-details" data-bill="<?php echo htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $lang['view'] ?? 'ເບິ່ງ'; ?>">
                                        <i class="fas fa-eye"></i> <?php echo $lang['view'] ?? 'ເບິ່ງ'; ?>
                                    </button>
                                     <?php if (hasPermission('can_void')): ?>
                                     <button class="btn btn-sm btn-outline-danger btn-delete-bill" data-bill="<?php echo htmlspecialchars($b['bill_id']); ?>" title="<?php echo $lang['delete'] ?? 'ລຶບ'; ?>">
                                         <i class="fas fa-trash-alt"></i>
                                     </button>
                                     <?php endif; ?>
                                </td>
                            </tr>
                        <?php 
                            endforeach; 
                        else: 
                        ?>
                            <tr>
                                <td colspan="7" class="py-4 text-muted"><?php echo $lang['table_zero_records'] ?? 'ບໍ່ມີຂໍ້ມູນ'; ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 12px; border: none;">
          <div class="modal-header bg-primary text-white" style="border-radius: 12px 12px 0 0;">
            <h5 class="modal-title font-weight-bold"><i class="fas fa-receipt"></i> <?php echo $lang['bill_details'] ?? 'ລາຍລະອຽດໃບບິນ'; ?>: <span id="modalBillId" class="badge badge-light text-primary"></span></h5>
            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body p-0">
            <div class="table-responsive">
                <table class="table table-striped text-center mb-0" style="font-size: 0.9rem; min-width: 550px;">
                    <thead class="bg-light">
                        <tr>
                            <th><?php echo $lang['product_code'] ?? 'ລະຫັດສິນຄ້າ'; ?></th>
                            <th><?php echo $lang['product_name'] ?? 'ຊື່ສິນຄ້າ'; ?></th>
                            <th><?php echo $lang['category'] ?? 'ໝວດໝູ່'; ?></th>
                            <th><?php echo $lang['price'] ?? 'ລາຄາ'; ?></th>
                            <th><?php echo $lang['qty'] ?? 'ຈຳນວນ'; ?></th>
                            <th><?php echo $lang['subtotal'] ?? 'ຍອດລວມ'; ?></th>
                        </tr>
                    </thead>
                    <tbody id="modalDetailsBody">
                    </tbody>
                </table>
            </div>
          </div>
          <div class="modal-footer bg-light" style="border-radius: 0 0 12px 12px;">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['close'] ?? 'ປິດ'; ?></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Scripts -->
    <script src="../plugins/jquery/jquery.min.js"></script>
    <script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
    <script src="../plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            var dtConfig = {
                "order": [[0, "desc"]],
                "language": {
                    "sSearch": "<?php echo $lang['dt_search'] ?? 'ຄົ້ນຫາ'; ?>:",
                    "sLengthMenu": "<?php echo $lang['dt_length'] ?? 'ສະແດງ _MENU_ ລາຍການ'; ?>",
                    "sInfo": "<?php echo $lang['dt_info'] ?? 'ສະແດງ _START_ ຫາ _END_ ຈາກທັງໝົດ _TOTAL_ ລາຍການ'; ?>",
                    "sInfoEmpty": "<?php echo $lang['dt_info_empty'] ?? 'ສະແດງ 0 ຫາ 0 ຈາກທັງໝົດ 0 ລາຍການ'; ?>",
                    "sZeroRecords": "<?php echo $lang['dt_zeroRecords'] ?? 'ບໍ່ພົບຂໍ້ມູນທີ່ຄົ້ນຫາ'; ?>",
                    "oPaginate": {
                        "sNext": "<?php echo $lang['dt_paginate_next'] ?? 'ຖັດໄປ'; ?>",
                        "sPrevious": "<?php echo $lang['dt_paginate_previous'] ?? 'ກ່ອນໜ້າ'; ?>"
                    }
                }
            };
            $('#posTable').DataTable(dtConfig);
            
            // View Details
            $('.btn-view-details').on('click', function() {
                var billData = $(this).data('bill');
                $('#modalBillId').text(billData.bill_id);
                var tbody = '';
                var currencySymbol = '<?php echo $currency_symbol; ?>';
                
                if (billData.items && billData.items.length > 0) {
                    billData.items.forEach(function(item) {
                        tbody += '<tr>' +
                            '<td>' + (item.prod_code ? item.prod_code : '-') + '</td>' +
                            '<td class="font-weight-bold">' + item.prod_name + '</td>' +
                            '<td>' + item.category + '</td>' +
                            '<td>' + Number(item.price).toLocaleString() + ' ' + currencySymbol + '</td>' +
                            '<td>' + item.qty + '</td>' +
                            '<td class="text-success font-weight-bold">' + Number(item.subtotal).toLocaleString() + ' ' + currencySymbol + '</td>' +
                            '</tr>';
                    });
                } else {
                    tbody = '<tr><td colspan="6" class="text-muted"><?php echo $lang['table_zero_records'] ?? 'ບໍ່ມີລາຍການ'; ?></td></tr>';
                }
                
                $('#modalDetailsBody').html(tbody);
                $('#detailsModal').modal('show');
            });

            // Delete Bill Record
            $('.btn-delete-bill').on('click', function(e) {
                e.preventDefault();
                var bill_id = $(this).data('bill');
                
                Swal.fire({
                    title: 'ຢືນຢັນການລຶບໃບບິນ?',
                    text: "ທ່ານຕ້ອງການລຶບໃບບິນ \"" + bill_id + "\" ພ້ອມລາຍການສິນຄ້າທັງໝົດແທ້ຫຼືບໍ່? ການກະທຳນີ້ບໍ່ສາມາດຍົກເລີກໄດ້!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'ຢືນຢັນ, ລຶບເລີຍ!',
                    cancelButtonText: 'ຍົກເລີກ'
                }).then((result) => {
                    if (result.isConfirmed) {
                        var form = $('<form action="" method="post">' +
                                     '<input type="hidden" name="delete_pos_record" value="1">' +
                                     '<input type="hidden" name="bill_id_to_delete" value="' + bill_id + '">' +
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
