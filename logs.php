<?php
require_once 'config/session_check.php';
enforcePermission('report');
require_once 'config/db.php';

// --- ສ່ວນດຶງຂໍ້ມູນໄຟລ໌ແປພາສາຕາມ session ທີ່ຜູ້ໃຊ້ກຳລັງເປີດ (Lao, English, Chinese) ---
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "lang/la.php";
}
// --- ຟັງຊັນສຳລັບແປປະຫວັດການເຮັດວຽກຂອງລະບົບ (Log Translation Helper Function) ---
// ໜ້າທີ່: ເຮັດການແປປະຫວັດການເຮັດວຽກທີ່ຖືກບັນທຶກເປັນພາສາລາວໃນຖານຂໍ້ມູນ ໃຫ້ເປັນພາສາອັງກິດ ຫຼື ພາສາຈີນ ແບບອັດຕະໂນມັດກ່ອນສະແດງຜົນ
function translateLog($text, $current_lang) {
    if (empty($text)) return '';
    
    // ---ແປງຂໍ້ຄວາມພາສາອັງກິດໃຫ້ເປັນພາສາລາວເມື່ອເລືອກພາສາລາວ ---
    if ($current_lang === 'la') {
        $en_to_la = [
            'Booking ID:' => 'ເລກບິນ:',
            'Payment Method:' => 'ວິທີຊຳລະ:',
            'Transfer' => 'ໂອນເງິນ',
            'Cash' => 'ເງິນສົດ',
        ];
        foreach ($en_to_la as $en_word => $la_word) {
            $text = str_replace($en_word, $la_word, $text);
        }
        return $text;
    }

    // --- ພົດຈະນານຸກົມແປຄຳສັບ (Translation Dictionary) ສັງລວມທຸກປະເພດການເຄື່ອນໄຫວໃນລະບົບ ---
    $dictionary = [
        'en' => [
            'ເພີ່ມຜູ້ໃຊ້ໃໝ່' => 'Add New User',
            'ແກ້ໄຂຂໍ້ມູນຜູ້ໃຊ້' => 'Edit User Info',
            'ລຶບຜູ້ໃຊ້' => 'Delete User',
            'ແກ້ໄຂການຕັ້ງຄ່າ' => 'Edit Settings',
            'ເພີ່ມສິນຄ້າໃໝ່' => 'Add New Product',
            'ແກ້ໄຂສິນຄ້າ' => 'Edit Product',
            'ລຶບສິນຄ້າ' => 'Delete Product',
            'ເຕີມສະຕັອກສິນຄ້າ' => 'Restock Product',
            'ຍົກເລີກການຈອງ' => 'Cancel Booking',
            'ແກ້ໄຂການຈອງ' => 'Edit Booking',
            'ຈອງຫ້ອງພັກ' => 'Book Room',
            'ເພີ່ມຫ້ອງໃໝ່' => 'Add New Room',
            'ລົບຂໍ້ມູນຫ້ອງ' => 'Delete Room',
            'ອັບເດດສະຖານະຄວາມພ້ອມ' => 'Update Availability Status',
            'ຂາຍສິນຄ້າ (POS)' => 'Sell Product (POS)',
            'ອອກຈາກລະບົບ' => 'Logout',
            'ແກ້ໄຂລາຍຈ່າຍ' => 'Edit Expense',
            'Check-out ສຳເລັດ' => 'Check-out Completed',
            'Check-in (ຈອງ)' => 'Check-in (Reserved)',
            'ເຂົ້າສູ່ລະບົບ' => 'Login',
            'ເຂົ້າພັກ (Walk-in)' => 'Check-in (Walk-in)',
            'ເພີ່ມລາຍຈ່າຍ' => 'Add Expense',
            'ອັບເດດຂໍ້ມູນໂຮງແຮມ / ການຕັ້ງຄ່າລະບົບ' => 'Updated hotel info / settings',
            'ແກ້ໄຂຂໍ້ມູນຫ້ອງ' => 'Edit Room Info',
            'ເພີ່ມສະກຸນເງິນໃໝ່' => 'Add New Currency',
            'ແກ້ໄຂສະກຸນເງິນ' => 'Edit Currency',
            'ລຶບສະກຸນເງິນ' => 'Delete Currency',
            'ຕັ້ງສະກຸນເງິນຫຼັກ' => 'Set Default Currency',
            'ເພີ່ມປະເພດຫ້ອງໃໝ່' => 'Add New Room Type',
            'ລຶບປະເພດຫ້ອງ' => 'Delete Room Type',
            'ແກ້ໄຂປະເພດຫ້ອງ' => 'Edit Room Type',
            
            // Details terms
            'Username:' => 'Username:',
            'ຊື່ຜູ້ໃຊ້:' => 'Username:',
            'ຊື່:' => 'Name:',
            'ລູກຄ້າ:' => 'Customer:',
            'ຫ້ອງ:' => 'Room:',
            'ລຶບຜູ້ໃຊ້' => 'Deleted user',
            'ຈຳນວນ:' => 'Quantity:',
            'ເຕີມສະຕັອກ' => 'Restock',
            'ສິນຄ້າ:' => 'Product:',
            'ຍົກເລີກການຈອງຫ້ອງ' => 'Canceled booking for room',
            'ຂອງລູກຄ້າ' => 'for customer',
            'ຈອງຫ້ອງ' => 'Booked room',
            'ໃຫ້ລູກຄ້າ' => 'for customer',
            'ວັນທີ' => 'date',
            'ຫາ' => 'to',
            'ເລກຫ້ອງ:' => 'Room No:',
            'ປະເພດ:' => 'Type:',
            'ລົບຫ້ອງ' => 'Deleted room',
            'ອັບເດດສະຖານະຄວາມພ້ອມຂອງຫ້ອງ' => 'Updated availability status of room',
            'ເປັນ:' => 'to:',
            'ບິນເລກທີ:' => 'Bill No:',
            'ວິທີຊຳລະ:' => 'Payment Method:',
            'ເຂົ້າພັກຫ້ອງ' => 'Checked in room',
            'ແກ້ໄຂການຕັ້ງຄ່າ' => 'Edited settings',
            'ລາຄາ:' => 'Price:',
            'ກີບ' => 'Kip',
            'ສະກຸນເງິນ:' => 'Currency:',
            'ອັດຕາ:' => 'Rate:',
            'ລຶບສະກຸນເງິນ:' => 'Deleted currency:',
            'ປະເພດຫ້ອງ:' => 'Room Type:',
            'ລຶບປະເພດຫ້ອງ:' => 'Deleted room type:'
        ],
        'cn' => [
            'ເພີ່ມຜູ້ໃຊ້ໃໝ່' => '添加新用户',
            'ແກ້ໄຂຂໍ້ມູນຜູ້ໃຊ້' => '修改用户信息',
            'ລຶບຜູ້ໃຊ້' => '删除用户',
            'ແກ້ໄຂການຕັ້ງຄ່າ' => '修改系统设置',
            'ເພີ່ມສິນຄ້າໃໝ່' => '添加新产品',
            'ແກ້ໄຂສິນຄ້າ' => '修改产品',
            'ລຶບສິນຄ້າ' => '删除产品',
            'ເຕີມສະຕັອກສິນຄ້າ' => '补充库存',
            'ຍົກເລີກການຈອງ' => '取消预订',
            'ແກ້ໄຂການຈອງ' => '修改预订',
            'ຈອງຫ້ອງພັກ' => '客房预订',
            'ເພີ່ມຫ້ອງໃໝ່' => '新建客房',
            'ລົບຂໍ້ມູນຫ້ອງ' => '删除客房',
            'ອັບເດດສະຖານະຄວາມພ້ອມ' => '更新客房状态',
            'ຂາຍສິນຄ້າ (POS)' => '商品销售 (POS)',
            'ອອກຈາກລະບົບ' => '注销登录',
            'ແກ້ໄຂລາຍຈ່າຍ' => '修改支出',
            'Check-out ສຳເລັດ' => '退房完成',
            'Check-in (ຈອງ)' => '办理入住 (预订)',
            'ເຂົ້າສູ່ລະບົບ' => '登录',
            'ເຂົ້າພັກ (Walk-in)' => '办理入住 (直接进店)',
            'ເພີ່ມລາຍຈ່າຍ' => '添加支出',
            'ອັບເດດຂໍ້ມູນໂຮງແຮມ / ການຕັ້ງຄ່າລະບົບ' => '更新酒店信息 / 系统设置',
            'ແກ້ໄຂຂໍ້ມູນຫ້ອງ' => '修改客房信息',
            'ເພີ່ມສະກຸນເງິນໃໝ່' => '添加新货币',
            'ແກ້ໄຂສະກຸນເງິນ' => '修改货币',
            'ລຶບສະກຸນເງິນ' => '删除货币',
            'ຕັ້ງສະກຸນເງິນຫຼັກ' => '设置默认货币',
            'ເພີ່ມປະເພດຫ້ອງໃໝ່' => '新建客房类型',
            'ລຶບປະເພດຫ້ອງ' => '删除客房类型',
            'ແກ້ໄຂປະເພດຫ້ອງ' => '修改客房类型',
            
            // Details terms
            'Username:' => '用户名:',
            'ຊື່ຜູ້ໃຊ້:' => '用户名:',
            'ຊື່:' => '姓名:',
            'ລູກຄ້າ:' => '客户:',
            'ຫ້ອງ:' => '客房:',
            'ລຶບຜູ້ໃຊ້' => '已删除用户',
            'ຈຳນວນ:' => '数量:',
            'ເຕີມສະຕັອກ' => '补充库存',
            'ສິນຄ້າ:' => '产品:',
            'ຍົກເລີກການຈອງຫ້ອງ' => '已取消房间预订',
            'ຂອງລູກຄ້າ' => '客户为',
            'ຈອງຫ້ອງ' => '已预订房间',
            'ໃຫ້ລູກຄ້າ' => '给客户',
            'ວັນທີ' => '日期',
            'ຫາ' => '至',
            'ເລກຫ້ອງ:' => '房号:',
            'ປະເພດ:' => '类型:',
            'ລົບຫ້ອງ' => '已删除客房',
            'ອັບເດດສະຖານະຄວາມພ້ອມຂອງຫ້ອງ' => '已更新客房状态，客房：',
            'ເປັນ:' => '为:',
            'ບິນເລກທີ:' => '账单号:',
            'ວິທີຊຳລະ:' => '支付方式:',
            'ເຂົ້າພັກຫ້ອງ' => '已入住客房',
            'ແກ້ໄຂການຕັ້ງຄ່າ' => '修改设置',
            'ລາຄາ:' => '价格:',
            'ກີບ' => '基普',
            'ສະກຸນເງິນ:' => '货币:',
            'ອັດຕາ:' => '汇率:',
            'ລຶບສະກຸນເງິນ:' => '已删除货币:',
            'ປະເພດຫ້ອງ:' => '客房类型:',
            'ລຶບປະເພດຫ້ອງ:' => '已删除客房类型:'
        ]
    ];
    // ກົນໄກທີ 1: ການແປແບບກົງຕົວໂດຍກົງ (Direct Match Translation)
    // ລະບົບຈະກວດສອບວ່າຂໍ້ຄວາມ ຫຼື ປະເພດການເຄື່ອນໄຫວ (Action) ນັ້ນໆ
    // ກົງກັບ Key ພາສາລາວທີ່ມີຢູ່ໃນພົດຈະນານຸກົມ ($dictionary) ຂອງພາສາທີ່ເລືອກ ຫຼື ບໍ່.
    // ຫາກກົງກັນ, ຈະດຶງຄ່າຄຳແປ (ເຊັ່ນ: 'Add New User' ຫຼື '添加新用户') ສົ່ງກັບຄືນໄປສະແດງຜົນທັນທີ.
    // ==========================================
    if (isset($dictionary[$current_lang][$text])) {
        return $dictionary[$current_lang][$text];
    }
    
    // ==========================================
    // ກົນໄກທີ 2: การແປສ່ວນປະກອບໃນປະໂຫຍກ (Substring Replacements Fallback)
    // ໃຊ້ໃນກໍລະນີທີ່ຂໍ້ຄວາມມີຕົວແປໄດນາມິກ (Dynamic Variables) ປົນຢູ່ ເຊັ່ນ: ຊື່ລູກຄ້າ, ເລກຫ້ອງ, ວັນທີ ຫຼື ຈຳນວນເງິນ.
    // ຕົວຢ່າງຂໍ້ຄວາມ: "ຈອງຫ້ອງ 101 ໃຫ້ລູກຄ້າ Somchai"
    // ລະບົບຈະທຳການ Loop ຄົ້ນຫາຄຳສັບພາສາລາວໃນປະໂຫຍກ ແລ້ວທົດແທນ (Replace) ເທື່ອລະຄຳດ້ວຍຄຳແປ:
    //  - 'ຈອງຫ້ອງ' ➔ 'Booked room'
    //  - 'ໃຫ້ລູກຄ້າ' ➔ 'for customer'
    // ຜົນຮັບທີ່ໄດ້: "Booked room 101 for customer Somchai" ໂດຍທີ່ຕົວແປ ຫຼື ຕົວເລກຕ່າງໆຍັງຄົງຢູ່ຄົບຖ້ວນ ແລະ ຖືກຕ້ອງ!
    // ==========================================
    $translated = $text;
    if ($current_lang !== 'la' && isset($dictionary[$current_lang])) {
        foreach ($dictionary[$current_lang] as $la_word => $trans_word) {
            $translated = str_replace($la_word, $trans_word, $translated);
        }
    }
    
    return $translated;
}

// Fetch logs with user names
$stmt = $pdo->query("
    SELECT l.*, u.fname, u.lname, u.status as user_role 
    FROM system_logs l 
    LEFT JOIN users u ON l.user_id = u.user_id 
    ORDER BY l.created_at DESC 
    LIMIT 500
");
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['system_history']; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <!-- Noto Sans Lao Looped -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao+Looped:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao Looped', sans-serif !important; background-color: #f4f6f9; }
        .table th, .table td { font-size: 0.85rem !important; vertical-align: middle; }
        .dataTables_wrapper { font-size: 0.85rem !important; }
        .dataTables_empty { font-size: 0.85rem !important; }
        .log-action { font-weight: 700; color: #007bff; font-size: 0.85rem !important; }
        .log-details { font-size: 0.85rem !important; color: #666; }
        .log-time { font-size: 0.8rem !important; color: #888; }
        .user-badge { font-size: 0.7rem !important; vertical-align: middle; }
    </style>
</head>
<body class="p-3">

<div class="container-fluid">
    <div class="card card-outline card-primary shadow-sm">
        <div class="card-header bg-white">
            <h3 class="card-title font-weight-bold">
                <i class="fas fa-history mr-2 text-primary"></i> <?php echo $lang['action_history']; ?>
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" onclick="window.location.reload()"><i class="fas fa-sync-alt"></i></button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="logTable" class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="15%"><?php echo $lang['date_time']; ?></th>
                            <th width="15%"><?php echo $lang['user']; ?></th>
                            <th width="20%"><?php echo $lang['action']; ?></th>
                            <th><?php echo $lang['details']; ?></th>
                            <th width="12%"><?php echo $lang['ip_address']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td class="log-time"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['fname'] . ' ' . $log['lname']); ?></strong>
                                    <?php if (!empty($log['user_id'])): ?>
                                        <small class="text-muted">(ID: <?php echo $log['user_id']; ?>)</small>
                                    <?php endif; ?>
                                    <br>
                                    <span class="badge badge-secondary user-badge"><?php echo htmlspecialchars($log['user_role'] ?? 'System'); ?></span>
                                </td>
                                <td class="log-action"><?php echo htmlspecialchars(translateLog($log['action'], $current_lang)); ?></td>
                                <td class="log-details"><?php echo htmlspecialchars(translateLog($log['details'], $current_lang)); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
    $(document).ready(function() {
        $('#logTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": false,
            "info": true,
            "autoWidth": false,
            "responsive": false,
            "pageLength": 10,
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
    });
</script>

</body>
</html>
