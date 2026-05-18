<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ Double Colon (::) ໃນ ../services/room_service.php (Double Colon Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາແຖວທີ່ມີເຄື່ອງໝາຍ "::" 
// ເພື່ອກວດສອບ Pseudo-elements ຫຼື Syntax ທີ່ມີການໃຊ້ງານ double colon.
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນເຄື່ອງໝາຍ "::" ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, '::') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
