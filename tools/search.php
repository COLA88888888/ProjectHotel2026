<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ Category ແລະ ຄຳສັບພາສາລາວ ໃນ ../services/room_service.php (General Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາຄຳສັບ 'category', 'ອາຫານ' ຫຼື 'ເຄື່ອງດື່ມ'
// ເພື່ອກວດຫາ ແລະ ແຍກປະເພດສິນຄ້າທີ່ເປັນອາຫານ ແລະ ເຄື່ອງດື່ມ ພາຍໃນໂຄ້ດ.
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນຄຳສັບ 'category', 'ອາຫານ' ຫຼື 'ເຄື່ອງດື່ມ' ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, 'category') !== false || strpos($line, 'ອາຫານ') !== false || strpos($line, 'ເຄື່ອງດື່ມ') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
