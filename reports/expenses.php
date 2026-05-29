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

// Handle Add Expense
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_expense'])) {
    $expense_title = trim($_POST['expense_title']);
    $category = $_POST['category'] ?? 'Other';
    $amount = (float)str_replace(',', '', $_POST['amount']);
    $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
    
    if ($amount > 0 && !empty($expense_title)) {
        $stmt = $pdo->prepare("INSERT INTO expenses (expense_title, category, amount, expense_date) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$expense_title, $category, $amount, $expense_date])) {
            logActivity($pdo, $lang['log_add_expense'], "[$category] $expense_title, " . $lang['qty_label'] . ": $amount");
            $_SESSION['success'] = $lang['ok'];
        } else {
            $_SESSION['error'] = $lang['error_occurred'];
        }
    } else {
        $_SESSION['error'] = $lang['required_fields_msg'];
    }
    header("Location: expenses.php");
    exit();
}

// Handle Edit Expense
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_expense'])) {
    $id = (int)$_POST['expense_id'];
    
    // Fetch old details before update to compare what was edited
    $stmtOld = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch();

    $expense_title = trim($_POST['expense_title']);
    $category = $_POST['category'] ?? 'Other';
    $amount = (float)str_replace(',', '', $_POST['amount']);
    $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
    
    if ($id > 0 && $amount > 0 && !empty($expense_title)) {
        $stmt = $pdo->prepare("UPDATE expenses SET expense_title = ?, category = ?, amount = ?, expense_date = ? WHERE id = ?");
        if ($stmt->execute([$expense_title, $category, $amount, $expense_date, $id])) {
            $changes = [];
            if ($old['expense_title'] !== $expense_title) {
                $changes[] = "ຫົວຂໍ້: '{$old['expense_title']}' -> '{$expense_title}'";
            }
            if ($old['category'] !== $category) {
                $changes[] = "ໝວດໝູ່: '{$old['category']}' -> '{$category}'";
            }
            if ((float)$old['amount'] !== $amount) {
                $changes[] = "ມູນຄ່າ: '" . number_format($old['amount']) . "' -> '" . number_format($amount) . "'";
            }
            if ($old['expense_date'] !== $expense_date) {
                $changes[] = "ວັນທີ: '{$old['expense_date']}' -> '{$expense_date}'";
            }

            $details = "ແກ້ໄຂລາຍຈ່າຍ '{$old['expense_title']}'";
            if (!empty($changes)) {
                $details .= " (" . implode(', ', $changes) . ")";
            } else {
                $details .= " (ບໍ່ມີການປ່ຽນແປງຂໍ້ມູນ)";
            }

            logActivity($pdo, "ແກ້ໄຂລາຍຈ່າຍ", $details);
            $_SESSION['success'] = $lang['save_success'];
        } else {
            $_SESSION['error'] = $lang['error_occurred'];
        }
    }
    header("Location: expenses.php");
    exit();
}

// Fetch Expenses (Exclude Stock Auto-records for this view)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE (expense_date BETWEEN ? AND ?) AND expense_title NOT LIKE '[Stock]%' ORDER BY expense_date DESC, id DESC");
$stmt->execute([$start_date, $end_date]);
$expenses = $stmt->fetchAll();

// Total
$total_expenses = 0;
foreach($expenses as $ex) {
    $total_expenses += $ex['amount'];
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['expenses_management']; ?></title>
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/pages/expenses.css">
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ 
                    icon: 'success', 
                    title: '<?php echo $lang['save_success'] ?? 'ບັນທຶກຂໍ້ມູນສຳເລັດ'; ?>', 
                    showConfirmButton: false, 
                    timer: 2000 
                });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>

    <div class="row mb-3 header-flex align-items-center">
        <div class="col-sm-6">
            <h2><i class="fas fa-file-invoice-dollar mr-2"></i> <?php echo $lang['expenses_management']; ?></h2>
        </div>
        <div class="col-sm-6 text-md-right">
             <form class="filter-form form-inline justify-content-md-end">
                <input type="date" name="start_date" class="form-control form-control-sm mr-2" value="<?php echo $start_date; ?>">
                <span class="mr-2 d-none d-md-inline"><?php echo $lang['to'] ?? 'ຫາ'; ?></span>
                <input type="date" name="end_date" class="form-control form-control-sm mr-2" value="<?php echo $end_date; ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search mr-1"></i> <?php echo $lang['search']; ?></button>
             </form>
        </div>
    </div>

    <div class="row">
        <!-- Add Expense Form -->
        <div class="col-md-4">
            <div class="stat-card bg-danger shadow-sm">
                <div class="stat-card-label"><?php echo $lang['total_expenses_label']; ?></div>
                <div class="stat-card-value"><?php echo formatCurrency($total_expenses); ?></div>
                <div class="stat-card-icon"><i class="fas fa-wallet"></i></div>
            </div>

            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> <?php echo $lang['add_expense']; ?></h3>
                </div>
                <form action="" method="post">
                    <div class="card-body">
                        <div class="form-group">
                            <label><?php echo $lang['expense_title']; ?></label>
                            <input type="text" name="expense_title" class="form-control" placeholder="<?php echo $lang['expense_title_placeholder']; ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['category']; ?></label>
                            <select name="category" class="form-control" required>
                                <option value="Other"><?php echo $lang['cat_other']; ?></option>
                                <option value="Electricity"><?php echo $lang['cat_electricity']; ?></option>
                                <option value="Water"><?php echo $lang['cat_water']; ?></option>
                                <option value="Internet"><?php echo $lang['cat_internet']; ?></option>
                                <option value="Repair"><?php echo $lang['cat_repair']; ?></option>
                                <option value="Salary"><?php echo $lang['cat_salary']; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['expense_amount']; ?></label>
                            <input type="text" name="amount" class="form-control number-format" placeholder="0" required>
                        </div>
                        <div class="form-group">
                            <label><?php echo $lang['expense_date']; ?></label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-center">
                        <button type="submit" name="add_expense" class="btn btn-primary px-4 font-weight-bold" style="border-radius: 8px;"><i class="fas fa-save mr-1"></i> <?php echo $lang['save']; ?></button>
                        <button type="reset" class="btn btn-default px-4 font-weight-bold ml-2" style="border-radius: 8px;"><?php echo $lang['cancel']; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expense List -->
        <div class="col-md-8">
            <div class="card card-outline card-danger shadow-sm">
                <div class="card-header bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between py-3" style="gap: 15px;">
                    <h5 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-list text-danger mr-2"></i> <?php echo $lang['expenses_management'] ?? 'ຈັດການລາຍຈ່າຍ'; ?>
                    </h5>
                    <!-- Export Buttons -->
                    <div class="d-flex align-items-center no-print ml-auto" style="gap: 10px;">
                        <button type="button" class="btn btn-sm btn-success font-weight-bold shadow-sm" onclick="exportTableToExcel()" style="border-radius: 6px;">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-danger font-weight-bold shadow-sm" id="btnPdf" style="border-radius: 6px;">
                            <i class="fas fa-file-pdf mr-1"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="expenseTable" class="table table-hover text-center m-0">
                            <thead>
                                <tr class="bg-light">
                                    <th>#</th>
                                    <th class="text-left"><?php echo $lang['expense_title']; ?></th>
                                    <th><?php echo $lang['category']; ?></th>
                                    <th><?php echo $lang['expense_amount']; ?></th>
                                    <th><?php echo $lang['expense_date']; ?></th>
                                    <th><?php echo $lang['action']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($expenses as $index => $row): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="text-left font-weight-bold">
                                            <?php echo htmlspecialchars($row['expense_title']); ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $cat = $row['category'] ?? 'Other';
                                                $cat_label = $lang['cat_' . strtolower($cat)] ?? $cat;
                                                $color = 'secondary';
                                                if($cat == 'Electricity') $color = 'warning';
                                                if($cat == 'Water') $color = 'info';
                                                if($cat == 'Internet') $color = 'primary';
                                                if($cat == 'Repair') $color = 'dark';
                                                if($cat == 'Salary') $color = 'success';
                                                if(strpos($row['expense_title'], '[Stock]') !== false) $color = 'info';
                                                
                                                echo "<span class='badge badge-$color'>$cat_label</span>";
                                            ?>
                                        </td>
                                        <td class="text-danger font-weight-bold"><?php echo formatCurrency($row['amount']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['expense_date'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning text-white btn-edit" 
                                                data-id="<?php echo $row['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($row['expense_title']); ?>"
                                                data-category="<?php echo htmlspecialchars($row['category']); ?>"
                                                data-amount="<?php echo number_format($row['amount']); ?>"
                                                data-date="<?php echo $row['expense_date']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.15);">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-edit mr-2"></i> <?php echo $lang['edit'] ?? 'ແກ້ໄຂ'; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form action="" method="post">
                <input type="hidden" name="edit_expense" value="1">
                <input type="hidden" name="expense_id" id="edit_id">
                <div class="modal-body p-4 text-left">
                    <div class="form-group">
                        <label class="font-weight-bold"><?php echo $lang['expense_title']; ?></label>
                        <input type="text" name="expense_title" id="edit_title" class="form-control" required style="border-radius: 8px;">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?php echo $lang['category']; ?></label>
                        <select name="category" id="edit_category" class="form-control" required style="border-radius: 8px;">
                            <option value="Other"><?php echo $lang['cat_other']; ?></option>
                            <option value="Electricity"><?php echo $lang['cat_electricity']; ?></option>
                            <option value="Water"><?php echo $lang['cat_water']; ?></option>
                            <option value="Internet"><?php echo $lang['cat_internet']; ?></option>
                            <option value="Repair"><?php echo $lang['cat_repair']; ?></option>
                            <option value="Salary"><?php echo $lang['cat_salary']; ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?php echo $lang['expense_amount']; ?></label>
                        <input type="text" name="amount" id="edit_amount" class="form-control number-format" required style="border-radius: 8px;">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?php echo $lang['expense_date']; ?></label>
                        <input type="date" name="expense_date" id="edit_date" class="form-control" required style="border-radius: 8px;">
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0 d-flex justify-content-end p-3 px-4">
                    <button type="submit" class="btn btn-warning text-white px-4 font-weight-bold" style="border-radius: 8px; border: 2px solid #d39e00;"><i class="fas fa-save mr-1"></i> <?php echo $lang['save'] ?? 'ບັນທຶກ'; ?></button>
                    <button type="button" class="btn btn-default px-4 font-weight-bold ml-2" data-dismiss="modal" style="border-radius: 8px;"><?php echo $lang['cancel'] ?? 'ຍົກເລີກ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- PDF Export Hidden Container -->
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
                    border: 0.5pt solid #aaaaaa !important;
                    padding: 10px 8px !important;
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
            <div class="pdf-table-title" id="pdfReportTitle">ລາຍງານລາຍຈ່າຍທົ່ວໄປ</div>
            <div class="pdf-table-subtitle">ໄລຍະເວລາ: <?php echo date('d/m/Y', strtotime($start_date)); ?> ຫາ <?php echo date('d/m/Y', strtotime($end_date)); ?></div>
            <div id="pdfTablePlaceholder"></div>
        </div>
    </div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
$(document).ready(function() {
    $('#expenseTable').DataTable({
        "order": [[ 0, "asc" ]],
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
        }
    });

    $('.number-format').on('input', function(e) {
        var value = $(this).val().replace(/[^0-9]/g, '');
        if (value !== '') {
            $(this).val(parseInt(value, 10).toLocaleString('en-US'));
        } else {
            $(this).val('');
        }
    });

    $(document).on('click', '.btn-edit', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_title').val($(this).data('title'));
        $('#edit_category').val($(this).data('category'));
        $('#edit_amount').val($(this).data('amount'));
        $('#edit_date').val($(this).data('date'));
        $('#editExpenseModal').modal('show');
    });

    // PDF Export
    $('#btnPdf').click(function() {
        var pdfTitle = "ລາຍງານລາຍຈ່າຍ";
        var tableHtml = `
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="text-left">ຫົວຂໍ້ລາຍຈ່າຍ</th>
                        <th>ໝວດໝູ່</th>
                        <th class="text-right">ມູນຄ່າ</th>
                        <th>ວັນທີຈ່າຍ</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        var totalAmount = 0;
        
        $('#expenseTable tbody tr').each(function() {
            var cols = $(this).find('td');
            if (cols.length > 1) {
                var index = $(cols[0]).text().trim();
                var title = $(cols[1]).text().trim();
                var category = $(cols[2]).text().trim();
                var amountText = $(cols[3]).text().trim().replace(/[^\d]/g, '');
                var amountVal = parseInt(amountText) || 0;
                var amount = Number(amountVal).toLocaleString() + ' ₭';
                var date = $(cols[4]).text().trim();
                
                totalAmount += amountVal;
                
                tableHtml += `
                    <tr>
                        <td class="text-center">${index}</td>
                        <td class="text-left" style="font-weight:bold;">${title}</td>
                        <td class="text-center">${category}</td>
                        <td class="text-right" style="font-weight:bold; color:#d9534f;">${amount}</td>
                        <td class="text-center text-muted">${date}</td>
                    </tr>
                `;
            }
        });
        
        tableHtml += `
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background-color: #f9f9f9;">
                        <td class="text-right" colspan="3">ມູນຄ່າລວມ:</td>
                        <td class="text-right" style="color:#d9534f; font-weight:bold;">${totalAmount.toLocaleString()} ₭</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        `;
        
        $('#pdfTablePlaceholder').html(tableHtml);
        
        var opt = {
            margin: 12,
            filename: pdfTitle + '_' + new Date().toISOString().split('T')[0] + '.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2.5, useCORS: true, letterRendering: true },
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
    var filename = "ລາຍງານ_ລາຍຈ່າຍ_" + new Date().toISOString().split('T')[0] + ".xls";
    
    var excelHtml = `
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>ລາຍງານລາຍຈ່າຍ</x:Name>
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
    <tr class="title-row"><th colspan="5">ລາຍງານລາຍຈ່າຍທົ່ວໄປ (General Expenses Report)</th></tr>
    <tr class="title-row"><th colspan="5" style="font-size: 10pt; font-weight: normal; color: #555;">ໄລຍະເວລາ: <?php echo date('d/m/Y', strtotime($start_date)); ?> ຫາ <?php echo date('d/m/Y', strtotime($end_date)); ?></th></tr>
    <tr>
      <th>#</th>
      <th>ຫົວຂໍ້ລາຍຈ່າຍ</th>
      <th>ໝວດໝູ່</th>
      <th>ມູນຄ່າ</th>
      <th>ວັນທີຈ່າຍ</th>
    </tr>
  </thead>
  <tbody>
`;

    var totalAmount = 0;
    $('#expenseTable tbody tr').each(function() {
        var cols = $(this).find('td');
        if (cols.length > 1) {
            var index = $(cols[0]).text().trim();
            var title = $(cols[1]).text().trim();
            var category = $(cols[2]).text().trim();
            var amountText = $(cols[3]).text().trim().replace(/[^\d]/g, '');
            var amountVal = parseInt(amountText) || 0;
            var date = $(cols[4]).text().trim();
            
            totalAmount += amountVal;
            
            excelHtml += `
    <tr>
      <td class="text-center">${index}</td>
      <td class="text-left" style="font-weight:bold;">${title}</td>
      <td class="text-center">${category}</td>
      <td class="text-right">${amountVal}</td>
      <td class="text-center">${date}</td>
    </tr>`;
        }
    });

    excelHtml += `
    <tr class="total-row">
      <td colspan="3" class="text-right">ມູນຄ່າລວມ:</td>
      <td class="text-right" style="color:#d9534f;">${totalAmount}</td>
      <td></td>
    </tr>
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
</script>
</body>
</html>
