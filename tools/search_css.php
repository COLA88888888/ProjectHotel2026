<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ ແລະ ກວດສອບ CSS/ຄຳສັບໃນ ../services/room_service.php (Search Helper Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາແຖວທີ່ມີຄຳວ່າ '::before', '::after' ຫຼື 'cate'
// ເພື່ອກວດສອບ ແລະ ວິເຄາະຮູບແບບການແຕ່ງ CSS ຫຼື ການກຳນົດ Category (ປະເພດສິນຄ້າ).
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນຄຳສັບທີ່ກຳນົດ (::before, ::after ຫຼື cate), ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມໃນແຖວນັ້ນອອກມາ
    if (strpos($line, '::before') !== false || strpos($line, '::after') !== false || strpos($line, 'cate') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
