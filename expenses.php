<?php
session_start();
require_once 'config/db.php';
require_once 'config/session_check.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
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
    <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; padding: 20px; }
        .stat-card { border-radius: 15px; padding: 20px; color: white; position: relative; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .stat-card-label { font-size: 0.9rem; opacity: 0.9; }
        .stat-card-value { font-size: 1.8rem; font-weight: 700; margin-top: 5px; }
        .stat-card-icon { position: absolute; right: 20px; bottom: 20px; font-size: 3rem; opacity: 0.2; }
        @media (max-width: 768px) { 
            body { padding: 10px; }
            h2 { font-size: 1.25rem; margin-bottom: 15px !important; }
            .stat-card { padding: 15px; margin-bottom: 15px; }
            .stat-card-value { font-size: 1.4rem; }
            .stat-card-label { font-size: 0.8rem; }
            .stat-card-icon { font-size: 2.2rem; right: 15px; bottom: 15px; }
            .header-flex { flex-direction: column; align-items: flex-start !important; }
            .filter-form { width: 100%; display: flex; flex-direction: column; gap: 8px; }
            .filter-form input, .filter-form button { width: 100% !important; margin: 0 !important; }
            .table { font-size: 0.8rem !important; }
            .table th, .table td { padding: 8px 4px !important; }
            .card-title { font-size: 1rem !important; }
            .card-body { padding: 10px !important; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: '<?php echo $lang['ok']; ?>', timer: 1500, showConfirmButton: false });
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
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> <span class="d-none d-md-inline"><?php echo $lang['search']; ?></span></button>
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
                    <div class="card-footer">
                        <button type="submit" name="add_expense" class="btn btn-primary btn-block"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expense List -->
        <div class="col-md-8">
            <div class="card card-outline card-danger shadow-sm">
                <div class="card-body p-0">
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
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit'] ?? 'ແກ້ໄຂ'; ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form action="" method="post">
                <input type="hidden" name="edit_expense" value="1">
                <input type="hidden" name="expense_id" id="edit_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $lang['expense_title']; ?></label>
                        <input type="text" name="expense_title" id="edit_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo $lang['category']; ?></label>
                        <select name="category" id="edit_category" class="form-control" required>
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
                        <input type="text" name="amount" id="edit_amount" class="form-control number-format" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo $lang['expense_date']; ?></label>
                        <input type="date" name="expense_date" id="edit_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['cancel']; ?></button>
                    <button type="submit" class="btn btn-warning text-white"><?php echo $lang['save']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="sweetalert/dist/sweetalert2.all.min.js"></script>

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
});
</script>
</body>
</html>
