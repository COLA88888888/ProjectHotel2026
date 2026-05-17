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

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$prod_name_col = "prod_name_" . $current_lang;

// Query POS History in Date Range
$stmtRecentPos = $pdo->prepare("
    SELECT o.*, p.sprice as prod_price, p.prod_name, p.$prod_name_col as prod_name_localized, p.category, u.fname, u.lname, u.username
    FROM orders o 
    JOIN products p ON o.prod_id = p.prod_id 
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.order_id DESC
");
$stmtRecentPos->execute([$start_date, $end_date]);
$recent_pos = $stmtRecentPos->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['pos_history_report'] ?? 'ປະຫວັດການຂາຍ POS'; ?></title>
    <!-- Bootstrap 4 -->
    <link class="no-print" rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *:not(.fas):not(.far):not(.fab):not(.fa) { font-family: 'Noto Sans Lao Looped', sans-serif !important; }
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #eef2f7; }
        .section-header h2 { margin: 0; font-weight: 800; color: #2c3e50; font-size: 1.7rem; }
        
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
                            <th># (Order ID)</th>
                            <th><?php echo $lang['product_name'] ?? 'ຊື່ສິນຄ້າ'; ?></th>
                            <th><?php echo $lang['category'] ?? 'ໝວດໝູ່'; ?></th>
                            <th><?php echo $lang['price'] ?? 'ລາຄາ'; ?></th>
                            <th><?php echo $lang['qty'] ?? 'ຈຳນວນ'; ?></th>
                            <th><?php echo $lang['subtotal'] ?? 'ຍອດລວມ'; ?></th>
                            <th><?php echo $lang['payment_method_label'] ?? 'ວິທີການຈ່າຍ'; ?></th>
                            <th>ຜູ້ຂາຍ</th>
                            <th><?php echo $lang['date'] ?? 'ວັນທີຂາຍ'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $idx = 1;
                        if (count($recent_pos) > 0):
                            foreach($recent_pos as $row): 
                                $cat_text = $row['category'];
                                if($row['category'] == 'Food') $cat_text = $lang['food_cat'] ?? 'ອາຫານ';
                                elseif($row['category'] == 'Drink') $cat_text = $lang['drink_cat'] ?? 'ເຄື່ອງດື່ມ';
                                elseif($row['category'] == 'Service') $cat_text = $lang['service_cat'] ?? 'ບໍລິການ';
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['order_id']); ?></td>
                                <td class="plain-bold"><?php echo htmlspecialchars($row['prod_name_localized'] ?: $row['prod_name']); ?></td>
                                <td><?php echo htmlspecialchars($cat_text); ?></td>
                                <td><?php echo number_format($row['prod_price']); ?> <?php echo $currency_symbol; ?></td>
                                <td><?php echo htmlspecialchars($row['o_qty']); ?></td>
                                <td class="font-weight-bold text-success"><?php echo number_format($row['amount']); ?> <?php echo $currency_symbol; ?></td>
                                <td><?php echo htmlspecialchars($row['payment_method'] == 'Cash' ? 'ເງິນສົດ' : ($row['payment_method'] == 'Transfer' ? 'ເງິນໂອນ' : $row['payment_method'])); ?></td>
                                <td><?php echo htmlspecialchars($row['fname'] ? ($row['fname'] . ' ' . $row['lname']) : ($row['username'] ?? '-')); ?></td>
                                <td class="text-muted"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                            </tr>
                        <?php 
                            endforeach; 
                        else: 
                        ?>
                            <tr>
                                <td colspan="9" class="py-4 text-muted"><?php echo $lang['table_zero_records'] ?? 'ບໍ່ມີຂໍ້ມູນ'; ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
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
        });
    </script>
</body>
</html>
