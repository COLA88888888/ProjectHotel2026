<?php
// ==========================================
// ໄຟລ໌ຄົ້ນຫາຄຳສັບພາສາລາວ ແລະ Category ໃນ ../services/room_service.php (Lao Word Search Tool)
// ------------------------------------------
// ໜ້າທີ່: ອ່ານເນື້ອຫາທັງໝົດໃນ ../services/room_service.php ແລ້ວກວດສອບແຕ່ລະແຖວທີ່ມີຄຳວ່າ 'ອາຫານ', 'ເຄື່ອງດື່ມ' ຫຼື 'category'
// ເພື່ອກວດຫາ ແລະ ແປງປ້າຍຊື່ສິນຄ້າ ຫຼື ໝວດໝູ່ທີ່ຍັງເປັນພາສາລາວຢູ່.
// ==========================================

// ໂຫຼດເນື້ອຫາທັງໝົດຂອງໄຟລ໌ ../services/room_service.php
$content = file_get_contents('../services/room_service.php');
// ແຍກຂໍ້ຄວາມອອກເປັນແຖວໆ ໂດຍໃຊ້ເຄື່ອງໝາຍຂຶ້ນແຖວໃໝ່ (\n)
$lines = explode("\n", $content);

// Loop ກວດສອບແຕ່ລະແຖວ
foreach ($lines as $i => $line) {
    // ຫາກພົບເຫັນຄຳວ່າ 'ອາຫານ', 'ເຄື່ອງດື່ມ' ຫຼື 'category' ໃຫ້ສະແດງເລກແຖວ ແລະ ຂໍ້ຄວາມນັ້ນອອກມາ
    if (strpos($line, 'ອາຫານ') !== false || strpos($line, 'ເຄື່ອງດື່ມ') !== false || strpos($line, 'category') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . PHP_EOL;
    }
}
