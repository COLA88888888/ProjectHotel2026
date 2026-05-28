<?php
require_once 'config/session_check.php';
enforcePermission('settings');
$is_admin = ($_SESSION['status'] === 'ຜູ້ບໍລິຫານ');
$can_edit = ($is_admin || hasPermission('settings_edit'));
require_once 'config/db.php';
require_once 'config/logger.php';

// --- ສ່ວນກວດສອບ ແລະ ໂຫຼດໄຟລ໌ແປພາສາ (Lao, English, Chinese) ຕາມ session ຂອງຜູ້ໃຊ້ ---
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}

// --- ດຶງຂໍ້ມູນການຕັ້ງຄ່າທັງໝົດຂອງໂຮງແຮມຈາກຖານຂໍ້ມູນມາເກັບໃນຕົວແປ $settings_data ---
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// --- ສ່ວນຈັດການເມື່ອມີການສົ່ງຟອມບັນທຶກການຕັ້ງຄ່າ (POST request) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    if (!$can_edit) {
        $_SESSION['error'] = "ທ່ານບໍ່ມີສິດໃນການແກ້ໄຂການຕັ້ງຄ່າ!";
        header("Location: settings.php");
        exit();
    }
    // ບັນທຶກຂໍ້ມູນທົ່ວໄປ (ຊື່ໂຮງແຮມ, ເບີໂທ, ທີ່ຢູ່, ທ້າຍບິນ, ອາກອນ, ແພັກເກັດ, ວັນໝົດອາຍຸ)
    $keys_to_update = ['hotel_name', 'hotel_phone', 'hotel_address', 'receipt_footer', 'tax_percent', 'package_name', 'package_expires'];
    foreach ($keys_to_update as $k) {
        if (isset($_POST[$k])) {
            $val = $_POST[$k];
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $val]);
        }
    }

    // --- ສ່ວນຈັດການອັບໂຫຼດ ໂລໂກ້ໂຮງແຮມ (Hotel Logo Upload) ---
    if (isset($_FILES['hotel_logo']) && $_FILES['hotel_logo']['error'] == 0) {
        $filesize = $_FILES['hotel_logo']['size'];
        if ($filesize > 2 * 1024 * 1024) {
            $_SESSION['error'] = 'ໂລໂກ້ໂຮງແຮມ: ຮູບພາບໃຫຍ່ເກີນໄປ! ອະນຸຍາດບໍ່ເກີນ 2MB';
            header("Location: settings.php");
            exit();
        }
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['hotel_logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newname = 'logo_' . time() . '.' . $ext;
            if (!is_dir('assets/img/logo/')) { mkdir('assets/img/logo/', 0777, true); }
            if (move_uploaded_file($_FILES['hotel_logo']['tmp_name'], 'assets/img/logo/' . $newname)) {
                // ດຶງຊື່ຮູບເກົ່າມາລຶບຖິ້ມ
                $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'hotel_logo'");
                $old_logo = $stmt->fetchColumn();
                if ($old_logo && $old_logo !== 'logo.png' && file_exists('assets/img/logo/' . $old_logo)) {
                    unlink('assets/img/logo/' . $old_logo);
                }
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('hotel_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$newname]);
            } else {
                $_SESSION['error'] = 'ບໍ່ສາມາດອັບໂຫຼດຮູບໂລໂກ້ໄດ້! ກວດສອບ Permissions Folder.';
            }
        }
    }
    // --- ສ່ວນຈັດການອັບໂຫຼດ QR ຮັບເງິນ (Payment QR Upload) ---
    if (isset($_FILES['hotel_qr']) && $_FILES['hotel_qr']['error'] == 0) {
        $filesize = $_FILES['hotel_qr']['size'];
        if ($filesize > 2 * 1024 * 1024) {
            $_SESSION['error'] = 'QR ຮັບເງິນ: ຮູບພາບໃຫຍ່ເກີນໄປ! ອະນຸຍາດບໍ່ເກີນ 2MB';
            header("Location: settings.php");
            exit();
        }
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['hotel_qr']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newname = 'qr_' . time() . '.' . $ext;
            if (!is_dir('assets/img/QR/')) { mkdir('assets/img/QR/', 0777, true); }
            if (move_uploaded_file($_FILES['hotel_qr']['tmp_name'], 'assets/img/QR/' . $newname)) {
                // ດຶງຊື່ຮູບເກົ່າມາລຶບຖິ້ມ
                $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'hotel_qr'");
                $old_qr = $stmt->fetchColumn();
                if ($old_qr && $old_qr !== 'qr.png' && file_exists('assets/img/QR/' . $old_qr)) {
                    unlink('assets/img/QR/' . $old_qr);
                }
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('hotel_qr', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$newname]);
            } else {
                $_SESSION['error'] = 'ບໍ່ສາມາດອັບໂຫຼດຮູບ QR ໄດ້! ກວດສອບ Permissions Folder.';
            }
        }
    }

    // --- ບັນທຶກປະຫວັດການແກ້ໄຂການຕັ້ງຄ່າລົງໃນລະບົບ System Logs ---
    logActivity($pdo, "ແກ້ໄຂການຕັ້ງຄ່າ", "ອັບເດດຂໍ້ມູນໂຮງແຮມ / ການຕັ້ງຄ່າລະບົບ");

    $_SESSION['success'] = $lang['save_success'] ?? 'ບັນທຶກສຳເລັດ!';
    header("Location: settings.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['settings']; ?></title>
    <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="assets/css/pages/settings.css">
</head>
<body>

<?php
    $logo_file = $settings_data['hotel_logo'] ?? 'logo.png';
    $logo_path = 'assets/img/logo/' . $logo_file;
    if (!file_exists($logo_path) || empty($logo_file)) {
        $logo_path = 'assets/img/image.jpg';
    }

    $qr_file = $settings_data['hotel_qr'] ?? 'qr.png';
    $qr_path = 'assets/img/QR/' . $qr_file;
    if (!file_exists($qr_path) || empty($qr_file)) {
        $qr_path = 'assets/img/image.jpg';
    }
?>

<div class="container-fluid">
    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i> <?php echo $_SESSION['success']; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <script>
            if (window.parent !== window) {
                var parentLogo = window.parent.document.querySelector('.brand-link img');
                if (parentLogo) {
                    parentLogo.src = '<?php echo $logo_path; ?>?t=' + new Date().getTime();
                }
                var parentName = window.parent.document.querySelector('.brand-text b');
                if (parentName) {
                    parentName.innerText = '<?php echo htmlspecialchars($settings_data['hotel_name'] ?? ''); ?>';
                }
            }
        </script>
    <?php unset($_SESSION['success']); endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $_SESSION['error']; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-4">
        <div class="col-12">
            <h3><i class="fas fa-cog mr-2"></i> <?php echo $lang['settings']; ?></h3>
        </div>
    </div>

    <div class="row">
        <div class="col-md-10 mx-auto">
            <div class="card card-primary card-outline card-outline-tabs shadow-sm">
                <div class="card-header p-0 border-bottom-0">
                    <ul class="nav nav-tabs" id="settingsTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="general-tab" data-toggle="pill" href="#general" role="tab"><i class="fas fa-hotel mr-1"></i> <?php echo $lang['hotel_info'] ?? 'ຂໍ້ມູນໂຮງແຮມ'; ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="qr-tab" data-toggle="pill" href="#qr" role="tab"><i class="fas fa-qrcode mr-1"></i> <?php echo $lang['qr_ordering'] ?? 'QR ສັ່ງອາຫານ'; ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="finance-tab" data-toggle="pill" href="#finance" role="tab"><i class="fas fa-coins mr-1"></i> <?php echo $lang['finance_tax'] ?? 'ການເງິນ & ພາສີ'; ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="network-tab" data-toggle="pill" href="#network" role="tab"><i class="fas fa-network-wired mr-1"></i> <?php echo $lang['system_access'] ?? 'ການເຂົ້າເຖິງ'; ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="package-tab" data-toggle="pill" href="#package" role="tab"><i class="fas fa-crown mr-1"></i> ແພັກເກັດການໃຊ້ງານ</a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="settingsTabContent">
                        
                        <!-- Tab 1: General -->
                        <div class="tab-pane fade show active" id="general" role="tabpanel">
                            <form action="" method="post" enctype="multipart/form-data">
                                <div class="row mb-4 text-center">
                                    <div class="col-sm-6 border-right">
                                        <label class="d-block"><?php echo $lang['hotel_logo'] ?? 'ໂລໂກ້ໂຮງແຮມ'; ?></label>
                                        <img id="prevLogo" src="<?php echo $logo_path; ?>" class="logo-preview mb-2 shadow-sm">
                                        <div class="mt-1">
                                            <?php if ($can_edit): ?>
                                                <label for="hotel_logo" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-image mr-1"></i> <?php echo $lang['change_logo'] ?? 'ປ່ຽນໂລໂກ້'; ?>
                                                </label>
                                                <input type="file" name="hotel_logo" id="hotel_logo" class="d-none" accept=".jpg,.jpeg,.png,.gif,.webp,.jfif,.avif,image/jpeg,image/png,image/gif,image/webp" onchange="previewImg(this, 'prevLogo')">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="d-block"><?php echo $lang['payment_qr'] ?? 'QR ຮັບເງິນ (Payment)'; ?></label>
                                        <img id="prevQR" src="<?php echo $qr_path; ?>" class="logo-preview mb-2 shadow-sm">
                                        <div class="mt-1">
                                            <?php if ($can_edit): ?>
                                                <label for="hotel_qr" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-qrcode mr-1"></i> <?php echo $lang['change_qr'] ?? 'ປ່ຽນ QR'; ?>
                                                </label>
                                                <input type="file" name="hotel_qr" id="hotel_qr" class="d-none" accept=".jpg,.jpeg,.png,.gif,.webp,.jfif,.avif,image/jpeg,image/png,image/gif,image/webp" onchange="previewImg(this, 'prevQR')">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label"><?php echo $lang['hotel_name'] ?? 'ຊື່ໂຮງແຮມ'; ?></label>
                                    <div class="col-sm-9"><input type="text" name="hotel_name" class="form-control" value="<?php echo htmlspecialchars($settings_data['hotel_name'] ?? ''); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>></div>
                                </div>
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label"><?php echo $lang['phone_number'] ?? 'ເບີໂທລະສັບ'; ?></label>
                                    <div class="col-sm-9"><input type="text" name="hotel_phone" class="form-control" value="<?php echo htmlspecialchars($settings_data['hotel_phone'] ?? ''); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>></div>
                                </div>
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label"><?php echo $lang['address'] ?? 'ທີ່ຢູ່'; ?></label>
                                    <div class="col-sm-9"><textarea name="hotel_address" class="form-control" rows="2" <?php echo $can_edit ? '' : 'readonly'; ?>><?php echo htmlspecialchars($settings_data['hotel_address'] ?? ''); ?></textarea></div>
                                </div>
                                <?php if ($can_edit): ?>
                                    <div class="text-right"><button type="submit" name="save_settings" class="btn btn-primary px-4"><?php echo $lang['save'] ?? 'ບັນທຶກ'; ?></button></div>
                                <?php else: ?>
                                    <div class="text-right"><span class="badge badge-secondary py-2 px-3 font-weight-bold" style="font-size: 0.82rem;"><i class="fas fa-lock mr-1"></i> ເບິ່ງຢ່າງດຽວ (View Only)</span></div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Tab 2: Ordering QR -->
                        <div class="tab-pane fade" id="qr" role="tabpanel">
                            <div class="text-center py-4">
                                <?php
                                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                    $host = $_SERVER['HTTP_HOST'];
                                    $qr_url = "$protocol://$host/ProjectHotel2026/services/customer_order.php";
                                    $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qr_url);
                                ?>
                                <h4 class="mb-4"><?php echo $lang['qr_desc_customer'] ?? 'QR Code ສຳລັບໃຫ້ລູກຄ້າສະແກນສັ່ງອາຫານ'; ?></h4>
                                <div id="printableQR" class="p-4 border d-inline-block bg-white shadow-sm mb-4">
                                    <h5 class="font-weight-bold mb-3"><?php echo htmlspecialchars($settings_data['hotel_name'] ?? 'Hotel Service'); ?></h5>
                                    <img src="<?php echo $qr_api; ?>" class="img-fluid mb-3">
                                    <p class="text-muted mb-0"><?php echo $lang['scan_to_order'] ?? 'ສະແກນເພື່ອສັ່ງອາຫານ'; ?></p>
                                </div>
                                <div><button class="btn btn-success btn-lg" onclick="printQR()"><i class="fas fa-print mr-2"></i> <?php echo $lang['print_qr_code'] ?? 'ພິມ QR Code'; ?></button></div>
                            </div>
                        </div>

                        <!-- Tab 3: Finance -->
                        <div class="tab-pane fade" id="finance" role="tabpanel">
                            <form action="" method="post">
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label text-right"><?php echo $lang['tax_percent'] ?? 'ອາກອນ (%)'; ?></label>
                                    <div class="col-sm-9"><input type="number" name="tax_percent" class="form-control" value="<?php echo htmlspecialchars($settings_data['tax_percent'] ?? '0'); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>></div>
                                </div>
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label text-right"><?php echo $lang['receipt_footer'] ?? 'ຂໍ້ຄວາມທ້າຍບິນ'; ?></label>
                                    <div class="col-sm-9"><input type="text" name="receipt_footer" class="form-control" value="<?php echo htmlspecialchars($settings_data['receipt_footer'] ?? ''); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>></div>
                                </div>
                                <?php if ($can_edit): ?>
                                    <div class="text-right"><button type="submit" name="save_settings" class="btn btn-primary px-4"><?php echo $lang['save'] ?? 'ບັນທຶກ'; ?></button></div>
                                <?php else: ?>
                                    <div class="text-right"><span class="badge badge-secondary py-2 px-3 font-weight-bold" style="font-size: 0.82rem;"><i class="fas fa-lock mr-1"></i> ເບິ່ງຢ່າງດຽວ (View Only)</span></div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Tab 4: Network -->
                        <div class="tab-pane fade" id="network" role="tabpanel">
                            <div class="alert alert-info border-0">
                                <h5><i class="fas fa-network-wired"></i> <?php echo $lang['system_access'] ?? 'ການເຂົ້າເຖິງ'; ?></h5>
                                <p><?php echo $lang['use_ip_access'] ?? 'ໃຊ້ IP ນີ້ເພື່ອເຂົ້າລະບົບຈາກເຄື່ອງອື່ນ:'; ?></p>
                                <h3 class="text-center font-weight-bold">http://<?php echo gethostbyname(gethostname()); ?>/ProjectHotel2026</h3>
                            </div>
                        </div>

                        <!-- Tab 5: Package -->
                        <div class="tab-pane fade" id="package" role="tabpanel">
                            <form action="" method="post">
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label text-right">ຊື່ແພັກເກັດ (Package Name)</label>
                                    <div class="col-sm-9">
                                        <input type="text" name="package_name" class="form-control" placeholder="ຕົວຢ່າງ: VIP, Premium, Standard..." value="<?php echo htmlspecialchars($settings_data['package_name'] ?? ''); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>>
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <label class="col-sm-3 col-form-label text-right">ວັນໝົດອາຍຸ (Expiration Date)</label>
                                    <div class="col-sm-9">
                                        <input type="date" name="package_expires" class="form-control" value="<?php echo htmlspecialchars($settings_data['package_expires'] ?? ''); ?>" <?php echo $can_edit ? '' : 'readonly'; ?>>
                                        <small class="form-text text-muted text-danger">** ໝາຍເຫດ: ປະຫວ່າງໄວ້ (ບໍ່ຕ້ອງເລືອກວັນທີ) ຫາກຕ້ອງການນຳໃຊ້ແບບບໍ່ມີກຳນົດ (ຕະຫຼອດຊີບ).</small>
                                    </div>
                                </div>
                                <?php if ($can_edit): ?>
                                    <div class="text-right">
                                        <button type="submit" name="save_settings" class="btn btn-primary px-4">ອັບເດດແພັກເກັດ</button>
                                    </div>
                                <?php else: ?>
                                    <div class="text-right">
                                        <span class="badge badge-secondary py-2 px-3 font-weight-bold" style="font-size: 0.82rem;"><i class="fas fa-lock mr-1"></i> ເບິ່ງຢ່າງດຽວ (View Only)</span>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function previewImg(input, id) {
    if (input.files && input.files[0]) {
        var fileSize = input.files[0].size / 1024 / 1024;
        if (fileSize > 2) {
            alert('ຂະໜາດຮູບພາບໃຫຍ່ເກີນໄປ! ກະລຸນາເລືອກຮູບທີ່ຂະໜາດບໍ່ເກີນ 2MB ເພື່ອບໍ່ໃຫ້ລະບົບໜັກ.');
            input.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) { $('#' + id).attr('src', e.target.result); }
        reader.readAsDataURL(input.files[0]);
    }
}
function printQR() {
    var content = document.getElementById('printableQR').innerHTML;
    var win = window.open('', '', 'height=600,width=800');
    win.document.write('<html><head><title>Print QR</title>');
    win.document.write('<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">');
    win.document.write('<style>body{text-align:center;padding-top:50px;font-family:"Noto Sans Lao Looped", sans-serif;} img{max-width:250px; border: 2px solid #eee; padding: 10px; border-radius: 10px;}</style>');
    win.document.write('</head><body>');
    win.document.write(content);
    win.document.write('<script>window.onload = function() { setTimeout(function(){ window.print(); window.close(); }, 500); };<\/script>');
    win.document.write('</body></html>');
    win.document.close();
}
</script>
</body>
</html>
