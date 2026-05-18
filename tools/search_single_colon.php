<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາ Single Colon (:before, :after, attr) ໃນ ../services/room_service.php (Single Colon Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ເຮັດໜ້າທີ່ອ່ານທຸກແຖວໃນໄຟລ໌ ../services/room_service.php ແລ້ວຄົ້ນຫາແຖວທີ່ມີຄຳວ່າ ':before', ':after' ຫຼື 'attr'
// ເພື່ອກວດສອບ ແລະ ວິເຄາະ CSS pseudo-classes ຫຼື syntax ທີ່ເປັນການດຶງ Attribute.
// ==========================================

// ໂຫຼດທຸກໆແຖວຂອງໄຟລ໌ ../services/room_service.php ມາເກັບໄວ້ເປັນ Array
$lines = file('../services/room_service.php');

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນຄຳວ່າ ':before', ':after' ຫຼື 'attr' ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, ':before') !== false || strpos($line, ':after') !== false || strpos($line, 'attr') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
