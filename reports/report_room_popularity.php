<?php
require_once '../config/session_check.php';
enforcePermission('report');
require_once '../config/db.php';

// Language Selection Logic
$current_lang = $_SESSION['lang'] ?? 'la';
$lang_file = "../lang/{$current_lang}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include "../lang/la.php";
}

$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of month
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$room_type_col = "room_type_name_" . $current_lang;

// --- 1. Stat Cards Data ---
// A. Most Popular Room Type
$stmtPopType = $pdo->prepare("
    SELECT rt.{$room_type_col} as room_type, rt.room_type_name, COUNT(b.id) as cnt 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name
    ORDER BY cnt DESC
    LIMIT 1
");
$stmtPopType->execute([$start_date, $end_date]);
$pop_type_row = $stmtPopType->fetch();
$most_popular_type = $pop_type_row ? ($pop_type_row['room_type'] ?: $pop_type_row['room_type_name']) : 'N/A';

// B. Today's Revenue
$today_str = date('Y-m-d');
$stmtTodayRev = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE DATE(check_in_date) = ?");
$stmtTodayRev->execute([$today_str]);
$today_revenue = $stmtTodayRev->fetchColumn() ?: 0;

// C. Total Revenue in Period
$stmtTotRev = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE DATE(check_in_date) BETWEEN ? AND ?");
$stmtTotRev->execute([$start_date, $end_date]);
$total_revenue_period = $stmtTotRev->fetchColumn() ?: 0;


// --- 2. Chart Data (Bookings by Room Type) ---
$room_type_labels = [];
$room_type_booking_count = [];
$top_room_types_data = [];

$stmtRT = $pdo->prepare("
    SELECT rt.{$room_type_col} as room_type, rt.room_type_name, COUNT(b.id) as total 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_types rt ON r.room_type = rt.room_type_name
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name
    ORDER BY total DESC
");
$stmtRT->execute([$start_date, $end_date]);
while($row = $stmtRT->fetch()) {
    $room_type_labels[] = $row['room_type'] ?: $row['room_type_name'] ?: 'Unknown';
    $room_type_booking_count[] = (int)$row['total'];
    $top_room_types_data[] = [
        'room_type' => $row['room_type'] ?: $row['room_type_name'] ?: 'Unknown',
        'total' => (int)$row['total']
    ];
}


// --- 3. Detailed stats by Room Type ---
$stmtRoomDetails = $pdo->prepare("
    SELECT 
        rt.{$room_type_col} as room_type_lang,
        rt.room_type_name,
        COUNT(b.id) as booking_count,
        COALESCE(SUM(b.total_price), 0) as total_revenue
    FROM room_types rt
    LEFT JOIN rooms r ON r.room_type = rt.room_type_name
    LEFT JOIN bookings b ON b.room_id = r.id AND DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rt.room_type_name, rt.{$room_type_col}
    ORDER BY booking_count DESC
");
$stmtRoomDetails->execute([$start_date, $end_date]);
$room_details = $stmtRoomDetails->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['popular_room_report_label'] ?? 'ປະເພດຫ້ອງຈອງຫຼາຍ'; ?></title>
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="../sweetalert/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;700&family=Noto+Sans+Lao+Looped:wght@400;700&display=swap" rel="stylesheet">
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="css/report_room_popularity.css">
</head>
<body>

<div class="container-fluid">
    <!-- Header -->
    <div class="section-header no-print d-flex flex-wrap align-items-center justify-content-between mb-4" style="gap: 15px;">
        <h2 class="m-0 font-weight-bold" style="font-size: 1.4rem;"><i class="fas fa-chart-pie text-primary mr-2"></i> <?php echo $lang['popular_rooms_report_title'] ?? 'ຈັດການປະເພດຈອງ ແລະ ຫ້ອງພັກຍອດນິຍົມ'; ?></h2>
        
        <!-- Search Filter Form -->
        <form method="GET" class="form-inline">
            <div class="filter-wrapper d-flex align-items-center" style="gap: 10px;">
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><i class="fas fa-calendar-alt text-success"></i></span>
                    </div>
                    <input type="date" name="start_date" class="form-control border-left-0" value="<?php echo $start_date; ?>">
                </div>
                <div class="input-group input-group-sm" style="width: 180px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><?php echo $lang['to'] ?? 'ຫາ'; ?></span>
                    </div>
                    <input type="date" name="end_date" class="form-control border-left-0" value="<?php echo $end_date; ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold">
                    <i class="fas fa-search mr-1"></i> <?php echo $lang['search'] ?? 'ຄົ້ນຫາ'; ?>
                </button>
            </div>
        </form>
    </div>


    <div class="stat-cards-row mb-4 no-print">
        <!-- 1. Most Popular Room Type -->
        <div class="stat-card-premium orange">
            <div>
                <div class="stat-card-label"><?php echo $lang['popular_room_type'] ?? 'ປະເພດຫ້ອງຍອດນິຍົມ'; ?></div>
                <div class="stat-card-value" style="font-size: 1.35rem; font-weight: 700; word-break: break-word;"><?php echo htmlspecialchars($most_popular_type); ?></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-star mr-1"></i> <?php echo $lang['most_booked'] ?? 'ຈອງຫຼາຍທີ່ສຸດ'; ?></div>
            <i class="fas fa-door-open stat-card-icon"></i>
        </div>

        <!-- 2. Today's Revenue -->
        <div class="stat-card-premium green">
            <div>
                <div class="stat-card-label"><?php echo $lang['daily_revenue_label'] ?? 'ລາຍຮັບມື້ນີ້'; ?></div>
                <div class="stat-card-value"><?php echo number_format($today_revenue); ?> <span style="font-size: 1rem; font-weight: 500;">₭</span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-money-bill-wave mr-1"></i> <?php echo $lang['today_revenue_desc'] ?? 'ລາຍຮັບປະຈຳວັນນີ້'; ?></div>
            <i class="fas fa-money-bill-wave stat-card-icon"></i>
        </div>

        <!-- 3. Total Revenue -->
        <div class="stat-card-premium blue">
            <div>
                <div class="stat-card-label"><?php echo $lang['total_revenue_label'] ?? 'ລາຍຮັບລວມທັງໝົດ'; ?></div>
                <div class="stat-card-value"><?php echo number_format($total_revenue_period); ?> <span style="font-size: 1rem; font-weight: 500;">₭</span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-wallet mr-1"></i> <?php echo $lang['total_revenue_period_desc'] ?? 'ລາຍຮັບລວມໃນໄລຍະເວລາ'; ?></div>
            <i class="fas fa-wallet stat-card-icon"></i>
        </div>
    </div>

    <div class="row mb-4 no-print">
        <!-- Doughnut Chart Column -->
        <div class="col-lg-5 col-12 mb-3">
            <div class="card glass-card h-100">
                <div class="card-header bg-transparent border-0 d-flex align-items-center">
                    <h3 class="card-title m-0 font-weight-bold"><i class="fas fa-chart-pie text-danger mr-2"></i> <?php echo $lang['booking_share_by_type'] ?? 'ອັດຕາສ່ວນການຈອງແຕ່ລະປະເພດຫ້ອງ'; ?></h3>
                </div>
                <div class="card-body d-flex flex-column justify-content-center align-items-center p-3">
                    <?php if (count($room_type_booking_count) > 0): ?>
                        <div style="position: relative; height:280px; width:100%;">
                            <canvas id="roomTypeDoughnutChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="py-5 text-center text-muted">
                            <i class="fas fa-chart-pie fa-3x mb-3 opacity-30"></i>
                            <p class="m-0"><?php echo $lang['no_bookings_period'] ?? 'ບໍ່ມີຂໍ້ມູນການຈອງໃນໄລຍະເວລານີ້'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 10 Popular Room Types Bar Chart Card -->
        <div class="col-lg-7 col-12 mb-3">
            <div class="card glass-card h-100">
                <div class="card-header bg-transparent border-0 py-3">
                    <h5 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-chart-bar text-primary mr-2"></i> <?php echo $lang['bar_chart_booking_ranking'] ?? 'ກາຟແທ່ງ ຈັດອັນດັບປະເພດຫ້ອງທີ່ຖືກຈອງຫຼາຍທີ່ສຸດ'; ?>
                    </h5>
                </div>
                <div class="card-body p-2 d-flex flex-column justify-content-center">
                    <?php if (count($room_type_booking_count) > 0): ?>
                        <div style="position: relative; height: 280px; max-width: 650px; margin: 0 auto; width: 100%;">
                            <canvas id="topRoomTypesChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="py-5 text-center text-muted">
                            <i class="fas fa-chart-bar fa-3x mb-3 opacity-30"></i>
                            <p class="m-0"><?php echo $lang['no_bookings_period'] ?? 'ບໍ່ມີຂໍ້ມູນການຈອງໃນໄລຍະເວລານີ້'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Single Table Details Card (Organized by Room Type) -->
    <div class="card glass-card mb-5">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between py-3" style="gap: 15px;">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-bed text-primary mr-2"></i> <?php echo $lang['booking_details_by_type'] ?? 'ລາຍລະອຽດການຈອງ ຈັດຕາມປະເພດຫ້ອງພັກ'; ?>
            </h5>
            
            <!-- Export Buttons -->
            <div class="d-flex align-items-center no-print ml-auto" style="gap: 10px;">
                <button type="button" class="btn btn-export btn-export-excel" id="btnExcel">
                    <i class="fas fa-file-excel mr-1"></i> Excel
                </button>
                <button type="button" class="btn btn-export btn-export-pdf" id="btnPdf">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </button>
            </div>
        </div>

        <div class="card-body p-2 p-md-4">
            <div class="table-responsive">
                <table id="roomNumberTable" class="table table-premium text-center" style="width: 100%;">
                    <thead>
                        <tr>
                            <th><?php echo $lang['room_type'] ?? 'ປະເພດຫ້ອງ'; ?></th>
                            <th><?php echo $lang['bookings_count_label'] ?? 'ຈຳນວນການຈອງ (Bookings)'; ?></th>
                            <th><?php echo $lang['total_revenue_label'] ?? 'ລາຍຮັບລວມ'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($room_details) > 0):
                            foreach($room_details as $row): 
                                $type_name = htmlspecialchars($row['room_type_lang'] ?: $row['room_type_name'] ?: 'Unknown');
                        ?>
                                <tr>
                                    <td class="text-left font-weight-bold text-dark" style="font-size: 1.05rem;"><?php echo $type_name; ?></td>
                                    <td><span class="badge px-3 py-2"><?php echo number_format($row['booking_count']); ?> <?php echo $lang['bookings_unit'] ?? 'ຄັ້ງ'; ?></span></td>
                                    <td class="text-right font-weight-bold text-success" style="font-size: 1.05rem;"><?php echo number_format($row['total_revenue']); ?> ₭</td>
                                </tr>
                        <?php 
                            endforeach;
                        else: 
                        ?>
                            <tr>
                                <td colspan="3" class="py-4 text-muted"><?php echo $lang['no_data'] ?? 'ບໍ່ມີຂໍ້ມູນ'; ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="../sweetalert/dist/sweetalert2.all.min.js"></script>
<!-- DataTables -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<!-- ChartJS -->
<script src="../plugins/chart.js/Chart.min.js"></script>
<!-- HTML2PDF CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // ----------------------------------------------------
    // 1. ChartJS Default Font
    // ----------------------------------------------------
    Chart.defaults.global.defaultFontFamily = "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif";
    
    // ----------------------------------------------------
    // 2. ChartJS Doughnut Initialization
    // ----------------------------------------------------
    <?php if (count($room_type_booking_count) > 0): ?>
    var ctxDoughnut = document.getElementById('roomTypeDoughnutChart').getContext('2d');
    var roomTypeDoughnut = new Chart(ctxDoughnut, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($room_type_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($room_type_booking_count); ?>,
                backgroundColor: [
                    '#3a7bd5', // Blue
                    '#11998e', // Emerald
                    '#f857a6', // Dark Pink/Magenta
                    '#6441a5', // Royal Purple
                    '#fd7e14', // Vibrant Amber/Orange
                    '#17a2b8', // Teal
                    '#dc3545', // Crimson Red
                    '#6c757d'  // Charcoal
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 15,
                    padding: 15,
                    fontStyle: 'bold',
                    fontSize: 11
                }
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var dataset = data.datasets[tooltipItem.datasetIndex];
                        var total = dataset.data.reduce(function(previousValue, currentValue, currentIndex, array) {
                            return previousValue + currentValue;
                        }, 0);
                        var currentValue = dataset.data[tooltipItem.index];
                        var percentage = Math.floor(((currentValue/total) * 100)+0.5);         
                        return data.labels[tooltipItem.index] + ": " + currentValue.toLocaleString() + " <?php echo $lang['bookings_unit'] ?? 'ຄັ້ງ'; ?> (" + percentage + "%)";
                    }
                }
            },
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            }
        }
    });
    <?php endif; ?>

    // ----------------------------------------------------
    // 3. ChartJS Bar Chart Initialization (Ranking)
    // ----------------------------------------------------
    const topRoomTypesData = <?php echo json_encode($top_room_types_data); ?>;
    
    if (topRoomTypesData.length > 0) {
        const barLabels = topRoomTypesData.map(rt => rt.room_type);
        const barValues = topRoomTypesData.map(rt => rt.total);
        
        const ctxBar = document.getElementById('topRoomTypesChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{
                    label: '<?php echo $lang['bookings_times'] ?? "ຈຳນວນການຈອງ (ຄັ້ງ)"; ?>',
                    data: barValues,
                    backgroundColor: [
                        'rgba(58, 123, 213, 0.85)',
                        'rgba(17, 153, 142, 0.85)',
                        'rgba(248, 87, 166, 0.85)',
                        'rgba(100, 65, 165, 0.85)',
                        'rgba(253, 126, 20, 0.85)',
                        'rgba(23, 162, 184, 0.85)',
                        'rgba(220, 53, 69, 0.85)',
                        'rgba(108, 117, 125, 0.85)'
                    ],
                    borderColor: [
                        '#3a7bd5',
                        '#11998e',
                        '#f857a6',
                        '#6441a5',
                        '#fd7e14',
                        '#17a2b8',
                        '#dc3545',
                        '#6c757d'
                    ],
                    borderWidth: 1.5,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    display: false
                },
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            fontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 11
                        },
                        gridLines: {
                            color: 'rgba(226, 232, 240, 0.6)'
                        }
                    }],
                    xAxes: [{
                        ticks: {
                            fontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 10
                        },
                        gridLines: {
                            display: false
                        }
                    }]
                },
                tooltips: {
                    titleFontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                    bodyFontFamily: "'Noto Sans Lao Looped', 'Noto Sans Lao', sans-serif",
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleFontSize: 12,
                    bodyFontSize: 12,
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(tooltipItem) {
                            return '<?php echo $lang['bookings_qty'] ?? "ຈຳນວນ: "; ?>' + Number(tooltipItem.yLabel).toLocaleString('en-US') + ' <?php echo $lang['bookings_unit'] ?? "ຄັ້ງ"; ?>';
                        }
                    }
                }
            }
        });
    }

    // ----------------------------------------------------
    // 4. DataTables Configuration (Lao Localized)
    // ----------------------------------------------------
    var datatableLaoLang = {
        "search": "ຄົ້ນຫາ:",
        "lengthMenu": "ສະແດງ _MENU_ ລາຍການ",
        "info": "ສະແດງ _START_ ຫາ _END_ ຈາກທັງໝົດ _TOTAL_ ລາຍການ",
        "infoEmpty": "ສະແດງ 0 ຫາ 0 ຈາກທັງໝົດ 0 ລາຍການ",
        "zeroRecords": "ບໍ່ພົບຂໍ້ມູນທີ່ຄົ້ນຫາ",
        "paginate": {
            "first": "ໜ້າທຳອິດ",
            "last": "ໜ້າສຸດທ້າຍ",
            "next": "ຖັດໄປ",
            "previous": "ກ່ອນໜ້າ"
        }
    };

    var rnTable = $('#roomNumberTable').DataTable({
        "language": datatableLaoLang,
        "order": [[1, "desc"]], // Sort by Booking count by default
        "pageLength": 10,
        "lengthMenu": [5, 10, 25, 50]
    });

    // ----------------------------------------------------
    // 5. Excel Export Click Handler (xls)
    // ----------------------------------------------------
    $('#btnExcel').click(function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> <?php echo $lang['generating_excel'] ?? 'ກຳລັງສ້າງ Excel...'; ?>');

        var reportTitle = "<?php echo $lang['popular_rooms_report_pdf'] ?? 'ລາຍງານຫ້ອງພັກຍອດນິຍົມ'; ?>";
        var dateRange = "<?php echo date('d/m/Y', strtotime($start_date)); ?> <?php echo $lang['to'] ?? 'ຫາ'; ?> <?php echo date('d/m/Y', strtotime($end_date)); ?>";

        var excelStyle = `
            <style>
                body {
                    font-family: 'Noto Sans Lao', 'Saysettha OT', sans-serif;
                }
                table {
                    border-collapse: collapse;
                    width: 100%;
                }
                th {
                    background-color: #28a745;
                    color: #ffffff;
                    font-weight: bold;
                    border: 1px solid #dee2e6;
                    text-align: center;
                    padding: 8px;
                }
                td {
                    border: 1px solid #dee2e6;
                    padding: 8px;
                    vertical-align: middle;
                }
                .text-left { text-align: left; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .header-title {
                    text-align: center;
                    font-size: 16pt;
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                .header-subtitle {
                    text-align: center;
                    font-size: 11pt;
                    color: #555555;
                    margin-bottom: 15px;
                }
            </style>
        `;

        var theadHtml = '<thead><tr>';
        var headers = $('#roomNumberTable thead th');
        headers.each(function() {
            theadHtml += '<th>' + $(this).text().trim() + '</th>';
        });
        theadHtml += '</tr></thead>';

        var tbodyHtml = '<tbody>';
        var rows;
        if (typeof rnTable !== 'undefined' && rnTable !== null) {
            rows = rnTable.rows({ search: 'applied' }).nodes();
        } else {
            rows = $('#roomNumberTable tbody tr');
        }

        $(rows).each(function() {
            tbodyHtml += '<tr>';
            var cells = $(this).find('td');
            cells.each(function(i) {
                var alignClass = 'text-center';
                if (i === 0) alignClass = 'text-left'; // Room type
                if (i === 2) alignClass = 'text-right'; // Revenue
                
                var cellText = $(this).text().trim();
                cellText = cellText.replace(/\s+/g, ' '); // clean whitespace
                
                tbodyHtml += '<td class="' + alignClass + '">' + cellText + '</td>';
            });
            tbodyHtml += '</tr>';
        });
        tbodyHtml += '</tbody>';

        var tableHtml = '<table>' + theadHtml + tbodyHtml + '</table>';

        var excelTemplate = `
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
                <!--[if gte mso 9]>
                <xml>
                    <x:ExcelWorkbook>
                        <x:ExcelWorksheets>
                            <x:ExcelWorksheet>
                                <x:Name>Room Popularity</x:Name>
                                <x:WorksheetOptions>
                                    <x:DisplayGridlines/>
                                </x:WorksheetOptions>
                            </x:ExcelWorksheet>
                        </x:ExcelWorksheets>
                    </x:ExcelWorkbook>
                </xml>
                <![endif]-->
                ${excelStyle}
            </head>
            <body>
                <div class="header-title">${reportTitle}</div>
                <div class="header-subtitle"><?php echo $lang['period_label'] ?? 'ໄລຍະເວລາ:'; ?> ${dateRange}</div>
                ${tableHtml}
            </body>
            </html>
        `;

        var blob = new Blob(['\ufeff' + excelTemplate], {
            type: 'application/vnd.ms-excel;charset=utf-8;'
        });

        var filename = reportTitle + '_<?php echo $start_date; ?>_<?php echo $lang['to'] ?? 'ຫາ'; ?>_<?php echo $end_date; ?>.xls';

        if (navigator.msSaveBlob) {
            navigator.msSaveBlob(blob, filename);
        } else {
            var link = document.createElement("a");
            if (link.download !== undefined) {
                var url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        btn.prop('disabled', false).html(originalHtml);
    });

    // ----------------------------------------------------
    // 6. Original PDF Export Click Handler (html2pdf)
    // ----------------------------------------------------
    $('#btnPdf').click(function() {
        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> <?php echo $lang['generating_pdf'] ?? 'ກຳລັງສ້າງ PDF...'; ?>');

        var reportTitle = "<?php echo $lang['popular_rooms_report_pdf'] ?? 'ລາຍງານຫ້ອງພັກຍອດນິຍົມ'; ?>";
        var targetTableId = "#roomNumberTable";

        var dateRange = "<?php echo date('d/m/Y', strtotime($start_date)); ?> <?php echo $lang['to'] ?? 'ຫາ'; ?> <?php echo date('d/m/Y', strtotime($end_date)); ?>";

        var theadHtml = '<thead><tr style="background-color:#007bff;color:#fff;">';
        var headers = $(targetTableId + ' thead th');
        headers.each(function() {
            theadHtml += '<th style="border:1px solid #dee2e6;padding:8px 6px;text-align:center;">' + $(this).text().trim() + '</th>';
        });
        theadHtml += '</tr></thead>';

        var tbodyHtml = '<tbody>';
        var rows = rnTable.rows({ search: 'applied' }).nodes();
        $(rows).each(function() {
            tbodyHtml += '<tr>';
            var cells = $(this).find('td');
            cells.each(function(i) {
                var align = 'center';
                if (i === 0) align = 'left';
                if (i === headers.length - 1) align = 'right';
                tbodyHtml += '<td style="border:1px solid #dee2e6;padding:7px 6px;text-align:' + align + ';color:#333;vertical-align:middle;">' + $(this).text().trim() + '</td>';
            });
            tbodyHtml += '</tr>';
        });
        tbodyHtml += '</tbody>';

        var tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:11px;font-family:\'Noto Sans Lao Looped\',\'Noto Sans Lao\',sans-serif;">' + theadHtml + tbodyHtml + '</table>';

        var fullHtml = '<div style="padding:20px;background:#fff;font-family:\'Noto Sans Lao Looped\',\'Noto Sans Lao\',sans-serif;">'
            + '<div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #333;padding-bottom:10px;">'
            + '<h2 style="margin:0;font-size:18px;font-weight:bold;color:#333;">' + reportTitle + '</h2>'
            + '<p style="margin:6px 0 0 0;font-size:12px;color:#555;"><?php echo $lang['period_label'] ?? 'ໄລຍະເວລາ:'; ?> <strong>' + dateRange + '</strong></p>'
            + '</div>'
            + tableHtml
            + '</div>';

        var opt = {
            margin: 8,
            filename: reportTitle + '_<?php echo $start_date; ?>_<?php echo $lang['to'] ?? 'ຫາ'; ?>_<?php echo $end_date; ?>.pdf',
            image: { type: 'jpeg', quality: 0.97 },
            html2canvas: { scale: 2, useCORS: true, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };

        html2pdf().set(opt).from(fullHtml).save().then(function() {
            btn.prop('disabled', false).html(originalHtml);
        }).catch(function(err) {
            btn.prop('disabled', false).html(originalHtml);
            console.error('PDF Error:', err);
        });
    });

});
</script>
</body>
</html>
