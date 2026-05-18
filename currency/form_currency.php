<?php
require_once '../config/session_check.php';
enforcePermission('settings');
require_once '../config/db.php';
require_once '../config/logger.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('settings_edit'));
$can_delete = ($is_admin || hasPermission('settings_delete'));

// Add new currency
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_currency'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການເພີ່ມສະກຸນເງິນ!";
        header("Location: form_currency.php");
        exit();
    }
    $code = trim($_POST['currency_code']);
    $name_la = trim($_POST['currency_name_la']);
    $name_en = trim($_POST['currency_name_en']);
    $name_cn = trim($_POST['currency_name_cn']);
    $rate = (float)str_replace(',', '', $_POST['exchange_rate']);
    $symbol_la = trim($_POST['symbol_la']);
    $symbol_en = trim($_POST['symbol_en']);
    $symbol_cn = trim($_POST['symbol_cn']);
    
    // Original columns
    $name = $name_la;
    $symbol = $symbol_la;

    if (!empty($code) && !empty($name_la)) {
        $stmt = $pdo->prepare("INSERT INTO currency (currency_code, currency_name, currency_name_la, currency_name_en, currency_name_cn, exchange_rate, symbol, symbol_la, symbol_en, symbol_cn) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$code, $name, $name_la, $name_en, $name_cn, $rate, $symbol, $symbol_la, $symbol_en, $symbol_cn])) {
            logActivity($pdo, "ເພີ່ມສະກຸນເງິນໃໝ່", "ສະກຸນເງິນ: $name_la ($code), ອັດຕາ: 1 $code = " . number_format($rate) . " Kip");
            $_SESSION['success'] = $lang['save_success'];
        } else {
            $_SESSION['error'] = $lang['error_occurred'];
        }
    }
    header("Location: form_currency.php");
    exit();
}

// Edit currency
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_currency'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂສະກຸນເງິນ!";
        header("Location: form_currency.php");
        exit();
    }
    $id = (int)$_POST['id'];
    $code = trim($_POST['currency_code']);
    $name_la = trim($_POST['currency_name_la']);
    $name_en = trim($_POST['currency_name_en']);
    $name_cn = trim($_POST['currency_name_cn']);
    $rate = (float)str_replace(',', '', $_POST['exchange_rate']);
    $symbol_la = trim($_POST['symbol_la']);
    $symbol_en = trim($_POST['symbol_en']);
    $symbol_cn = trim($_POST['symbol_cn']);

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE currency SET currency_code = ?, currency_name = ?, currency_name_la = ?, currency_name_en = ?, currency_name_cn = ?, exchange_rate = ?, symbol = ?, symbol_la = ?, symbol_en = ?, symbol_cn = ? WHERE id = ?");
        if ($stmt->execute([$code, $name_la, $name_la, $name_en, $name_cn, $rate, $symbol_la, $symbol_la, $symbol_en, $symbol_cn, $id])) {
            logActivity($pdo, "ແກ້ໄຂສະກຸນເງິນ", "ສະກຸນເງິນ: $name_la ($code), ອັດຕາ: 1 $code = " . number_format($rate) . " Kip");
            $_SESSION['success'] = "ອັບເດດສະກຸນເງິນສຳເລັດ! ສະກຸນເງິນ: " . htmlspecialchars($name_la) . " (" . htmlspecialchars($code) . ")";
        }
    }
    header("Location: form_currency.php");
    exit();
}

// Delete currency
if (isset($_GET['delete'])) {
    if (!$can_delete) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການລຶບສະກຸນເງິນ!";
        header("Location: form_currency.php");
        exit();
    }
    $id = (int)$_GET['delete'];
    
    // Prevent deleting default currency (LAK)
    $stmtCheck = $pdo->prepare("SELECT * FROM currency WHERE id = ?");
    $stmtCheck->execute([$id]);
    $curr = $stmtCheck->fetch();

    if ($curr && $curr['is_default'] == 1) {
        $_SESSION['error'] = $lang['cannot_delete_default_currency'] ?? 'ບໍ່ສາມາດລຶບສະກຸນເງິນຫຼັກໄດ້!';
    } else {
        $stmt = $pdo->prepare("DELETE FROM currency WHERE id = ?");
        if ($stmt->execute([$id])) {
            if ($curr) {
                logActivity($pdo, "ລຶບສະກຸນເງິນ", "ລຶບສະກຸນເງິນ: " . $curr['currency_name'] . " (" . $curr['currency_code'] . ")");
            }
            $_SESSION['success'] = $lang['delete_success'];
        }
    }
    header("Location: form_currency.php");
    exit();
}

// Set as Default Currency
if (isset($_GET['set_default'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການຕັ້ງສະກຸນເງິນຫຼັກ!";
        header("Location: form_currency.php");
        exit();
    }
    $id = (int)$_GET['set_default'];
    
    // Begin transaction
    $pdo->beginTransaction();
    try {
        // Reset all to 0
        $pdo->query("UPDATE currency SET is_default = 0");
        // Set selected to 1
        $stmt = $pdo->prepare("UPDATE currency SET is_default = 1 WHERE id = ?");
        $stmt->execute([$id]);
        
        // Fetch currency details for the success message
        $stmtGet = $pdo->prepare("SELECT currency_name, currency_code FROM currency WHERE id = ?");
        $stmtGet->execute([$id]);
        $selected_curr = $stmtGet->fetch();
        
        $pdo->commit();
        
        if ($selected_curr) {
            $c_name = htmlspecialchars($selected_curr['currency_name']);
            $c_code = htmlspecialchars($selected_curr['currency_code']);
            logActivity($pdo, "ຕັ້ງສະກຸນເງິນຫຼັກ", "ສະກຸນເງິນ: $c_name ($c_code)");
            $_SESSION['success'] = "ອັບເດດສະກຸນເງິນສຳເລັດ! ສະກຸນເງິນຫຼັກປັດຈຸບັນແມ່ນ: $c_name ($c_code)";
        } else {
            $_SESSION['success'] = $lang['save_success'];
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $lang['error_occurred'];
    }
    header("Location: form_currency.php");
    exit();
}

// Fetch all currencies
$stmt = $pdo->query("SELECT * FROM currency ORDER BY is_default DESC, id ASC");
$currencies = $stmt->fetchAll();

$name_col = "currency_name_" . $current_lang;
$symbol_col = "symbol_" . $current_lang;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['currency_management']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Noto Sans Lao Looped', sans-serif !important; 
            background-color: #f4f6f9; 
            padding: 20px; 
        }
        .card { border-radius: 8px; box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2); }
        .card-header { background-color: #fff; border-bottom: 1px solid rgba(0,0,0,.125); }
        .btn-sm { border-radius: 4px; }
        .table thead th { border-top: 0; border-bottom: 2px solid #dee2e6; background-color: #f8f9fa; }
        .badge { font-size: 0.9rem; padding: 0.4em 0.6em; }
        
        /* Action buttons style */
        .btn-action { margin: 0 2px; }
        
        @media (max-width: 576px) {
            body { padding: 10px; }
            h2 { font-size: 1.5rem; }
            .card-title { font-size: 1.1rem; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $lang['success_label'] ?? 'ສຳເລັດ'; ?>',
                    text: '<?php echo $_SESSION['success']; ?>',
                    showConfirmButton: false,
                    timer: 1500
                }).then(() => {
                    // Force refresh the entire parent window (the dashboard) 
                    // to reflect currency changes in the top bar and sidebar immediately.
                    if (window.top !== window.self) {
                        window.top.location.reload();
                    }
                });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: 'ຜິດພາດ', text: '<?php echo $_SESSION['error']; ?>' });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-4">
        <div class="col-12 border-bottom pb-2">
            <h2 class="m-0"><i class="fas fa-coins text-primary"></i> <?php echo $lang['exchange_rate_mgmt']; ?></h2>
        </div>
    </div>

    <div class="row">
        <!-- Add Form -->
        <?php if ($can_edit): ?>
        <div class="col-md-4">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold"><?php echo $lang['add_new_currency']; ?></h3>
                </div>
                <form id="addCurrencyForm" action="" method="post">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label><?php echo $lang['currency_code'] ?? 'Code'; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="currency_code" class="form-control" placeholder="LAK, USD..." required style="text-transform: uppercase;">
                            </div>
                            <div class="col-md-6 form-group">
                                <label><?php echo $lang['exchange_rate'] ?? 'Exchange Rate'; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="exchange_rate" class="form-control number-format" placeholder="1 = ? Kip" required>
                            </div>
                            <div class="col-md-8 form-group">
                                <label><?php echo $lang['currency_name_la']; ?> <span class="text-danger">*</span></label>
                                <input type="text" name="currency_name_la" class="form-control" placeholder="<?php echo $lang['currency_name_la']; ?>..." required>
                            </div>
                            <div class="col-md-4 form-group">
                                <label><?php echo $lang['symbol'] ?? 'Symbol'; ?></label>
                                <input type="text" name="symbol_la" class="form-control" placeholder="₭, $...">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" name="add_currency" class="btn btn-primary btn-block"><i class="fas fa-save"></i> <?php echo $lang['save']; ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="<?php echo $can_edit ? 'col-md-8' : 'col-md-12'; ?>">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold"><?php echo $lang['currency_list']; ?></h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover text-center mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="text-left"><?php echo $lang['currency']; ?></th>
                                    <th><?php echo $lang['currency_code'] ?? 'Code'; ?></th>
                                    <th class="text-right"><?php echo $lang['exchange_rate'] ?? 'Rate'; ?></th>
                                    <th><?php echo $lang['action']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($currencies as $index => $c): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="text-left">
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($c[$name_col] ?: $c['currency_name']); ?></div>
                                            <?php if($c['is_default']): ?>
                                                <span class="badge badge-success" style="font-size: 0.7rem;"><?php echo $lang['main_currency']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-secondary"><?php echo htmlspecialchars($c['currency_code']); ?></span>
                                            <div class="text-muted small">(<?php echo htmlspecialchars($c[$symbol_col] ?: $c['symbol']); ?>)</div>
                                        </td>
                                        <td class="text-right font-weight-bold pr-4">
                                            <?php if($c['is_default']): ?>
                                                -
                                            <?php else: ?>
                                                1 <?php echo htmlspecialchars($c['currency_code']); ?> = <?php echo number_format($c['exchange_rate']); ?> ₭
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($can_edit): ?>
                                                    <?php if(!$c['is_default']): ?>
                                                        <a href="?set_default=<?php echo $c['id']; ?>" class="btn btn-success btn-action" title="<?php echo $lang['set_as_main_currency'] ?? 'ຕັ້ງເປັນເງິນຫຼັກ'; ?>">
                                                            <i class="fas fa-check-circle"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <button class="btn btn-warning text-white btn-action btn-edit" 
                                                        data-id="<?php echo $c['id']; ?>"
                                                        data-code="<?php echo htmlspecialchars($c['currency_code']); ?>"
                                                        data-name-la="<?php echo htmlspecialchars($c['currency_name_la'] ?: $c['currency_name']); ?>"
                                                        data-name-en="<?php echo htmlspecialchars($c['currency_name_en'] ?? ''); ?>"
                                                        data-name-cn="<?php echo htmlspecialchars($c['currency_name_cn'] ?? ''); ?>"
                                                        data-rate="<?php echo number_format($c['exchange_rate']); ?>"
                                                        data-symbol-la="<?php echo htmlspecialchars($c['symbol_la'] ?: $c['symbol']); ?>"
                                                        data-symbol-en="<?php echo htmlspecialchars($c['symbol_en'] ?? ''); ?>"
                                                        data-symbol-cn="<?php echo htmlspecialchars($c['symbol_cn'] ?? ''); ?>"
                                                        data-default="<?php echo $c['is_default']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($can_delete && !$c['is_default']): ?>
                                                    <button class="btn btn-danger btn-action btn-delete" data-id="<?php echo $c['id']; ?>"><i class="fas fa-trash-alt"></i></button>
                                                <?php endif; ?>
                                                <?php if (!$can_edit && (!$can_delete || $c['is_default'])): ?>
                                                    <?php if($c['is_default']): ?>
                                                        <span class="text-success small font-weight-bold"><i class="fas fa-check-circle mr-1"></i> <?php echo $lang['main_currency'] ?? 'ເງິນຫຼັກ'; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">ເບິ່ງຢ່າງດຽວ</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($currencies)): ?>
                                    <tr><td colspan="5" class="text-muted py-4"><?php echo $lang['table_zero_records']; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning text-white">
        <h5 class="modal-title"><i class="fas fa-edit"></i> <?php echo $lang['edit_exchange_rate']; ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="editCurrencyForm" action="" method="post">
          <div class="modal-body">
            <input type="hidden" name="id" id="edit_id">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="font-weight-bold"><?php echo $lang['currency_code'] ?? 'Code'; ?></label>
                    <input type="text" name="currency_code" id="edit_code" class="form-control" required style="text-transform: uppercase;">
                </div>
                <div class="col-md-8 form-group" id="rate_group">
                    <label class="font-weight-bold"><?php echo $lang['exchange_rate'] ?? 'Rate'; ?></label>
                    <input type="text" name="exchange_rate" id="edit_rate" class="form-control number-format" required>
                </div>
                <div class="col-md-8 form-group">
                    <label class="font-weight-bold"><?php echo $lang['currency_name_la']; ?></label>
                    <input type="text" name="currency_name_la" id="edit_name_la" class="form-control" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="font-weight-bold"><?php echo $lang['symbol'] ?? 'Symbol'; ?></label>
                    <input type="text" name="symbol_la" id="edit_symbol_la" class="form-control">
                </div>
            </div>

            <!-- <button class="btn btn-link btn-sm p-0 mb-3 text-decoration-none" type="button" data-toggle="collapse" data-target="#editMoreOptions">
                <i class="fas fa-plus-circle mr-1"></i> <?php echo $lang['advanced_options'] ?? 'ຕົວເລືອກເພີ່ມເຕີມ'; ?> (Advanced)
            </button>

            <div class="collapse" id="editMoreOptions">
                <div class="card card-body bg-light border-0 p-3 mb-0">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold"><?php echo $lang['currency_name_en']; ?></label>
                            <input type="text" name="currency_name_en" id="edit_name_en" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold"><?php echo $lang['currency_name_cn']; ?></label>
                            <input type="text" name="currency_name_cn" id="edit_name_cn" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label class="small font-weight-bold"><?php echo $lang['symbol_en']; ?></label>
                            <input type="text" name="symbol_en" id="edit_symbol_en" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label class="small font-weight-bold"><?php echo $lang['symbol_cn']; ?></label>
                            <input type="text" name="symbol_cn" id="edit_symbol_cn" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
            </div> -->
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $lang['cancel']; ?></button>
            <button type="submit" name="edit_currency" class="btn btn-warning text-white"><?php echo $lang['save']; ?></button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<script>
$('.number-format').on('input', function() {
    var value = $(this).val().replace(/[^0-9]/g, '');
    if (value !== '') {
        $(this).val(parseInt(value, 10).toLocaleString('en-US'));
    } else {
        $(this).val('');
    }
});

// Client-side validation with SweetAlert2
$('#addCurrencyForm, #editCurrencyForm').on('submit', function(e) {
    var form = $(this);
    var requiredFields = form.find('[required]');
    var isValid = true;

    requiredFields.each(function() {
        if ($(this).val().trim() === '') {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    if (!isValid) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: '<?php echo $lang['warning_label'] ?? 'Warning'; ?>',
            text: '<?php echo $lang['form_required_msg'] ?? 'ກະລຸນາປ້ອນຂໍ້ມູນໃຫ້ຄົບຖ້ວນ!'; ?>',
            confirmButtonColor: '#3085d6'
        });
    }
});

$('.btn-edit').on('click', function() {
    $('#edit_id').val($(this).data('id'));
    $('#edit_code').val($(this).data('code'));
    $('#edit_name_la').val($(this).data('name-la'));
    $('#edit_name_en').val($(this).data('name-en'));
    $('#edit_name_cn').val($(this).data('name-cn'));
    $('#edit_rate').val($(this).data('rate'));
    $('#edit_symbol_la').val($(this).data('symbol-la'));
    $('#edit_symbol_en').val($(this).data('symbol-en'));
    $('#edit_symbol_cn').val($(this).data('symbol-cn'));
    
    if ($(this).data('default') == 1) {
        $('#edit_rate').prop('readonly', true);
        $('#rate_group .small-msg').remove();
        $('#rate_group').append('<small class="text-danger d-block mt-1 small-msg"><?php echo $lang['cannot_edit_default_rate_msg'] ?? 'ສະກຸນເງິນຫຼັກ ບໍ່ສາມາດປ່ຽນອັດຕາແລກປ່ຽນໄດ້.'; ?></small>');
    } else {
        $('#edit_rate').prop('readonly', false);
        $('#rate_group .small-msg').remove();
    }
    
    $('#editModal').modal('show');
});

$('.btn-delete').on('click', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    Swal.fire({
        title: '<?php echo $lang['confirm_delete']; ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo $lang['confirm'] ?? 'ຢືນຢັນ'; ?>',
        cancelButtonText: '<?php echo $lang['cancel']; ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = "?delete=" + id;
        }
    });
});
</script>
</body>
</html>

