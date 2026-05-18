<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ Style HTML Tags ໃນ ../services/room_service.php (Style Tags Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາແຖວທີ່ມີແທັກ '<style' ຫຼື '</style'
// ເພື່ອວິເຄາະ ແລະ ລະບຸຕຳແໜ່ງຂອງບລັອກການຂຽນ CSS Styles ພາຍໃນໄຟລ໌.
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນແທັກ HTML '<style' ຫຼື '</style' ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, '<style') !== false || strpos($line, '</style') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
