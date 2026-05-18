<?php
// ==========================================
// ໄຟລ໌ຕົວຊ່ວຍຄົ້ນຫາໂຄ້ດ (Code Search Helper Utility)
// ------------------------------------------
// ໜ້າທີ່: ສະແກນ ແລະ ພິມແຖວໂຄ້ດໃນໄຟລ໌ ../services/room_service.php ທີ່ກ່ຽວຂ້ອງກັບ 'product-img-wrapper'
// ເປັນເຄື່ອງມືຊ່ວຍໃຫ້ນັກພັດທະນາຊອກຫາຈຸດແກ້ໄຂໂຄ້ດໄດ້ໄວຂຶ້ນ
// ==========================================

$lines = file('../services/room_service.php');
$found = false;
foreach ($lines as $i => $line) {
    if (strpos($line, 'product-img-wrapper') !== false) {
        echo "=== Line " . ($i + 1) . " ===" . PHP_EOL;
        for ($j = 0; $j < 15; $j++) {
            echo ($i + 1 + $j) . ": " . $lines[$i + $j];
        }
        echo "=================" . PHP_EOL;
    }
}

