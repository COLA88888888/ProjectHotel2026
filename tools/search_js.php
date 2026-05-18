<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ JavaScript & Badges ໃນ ../services/room_service.php (JavaScript Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາຄຳສັບ 'prepend' ຫຼື 'badge'
// ເພື່ອກວດສອບ ແລະ ວິເຄາະການຂຽນ jQuery/JS ທີ່ໃຊ້ໃນການກຳນົດ Badge ຫຼື ຕື່ມ HTML ແບບໄດນາມິກ.
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນຄຳວ່າ 'prepend' ຫຼື 'badge' ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, 'prepend') !== false || strpos($line, 'badge') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
