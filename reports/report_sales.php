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

// Default to start of month to today
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Helper to pre-calculate quick dates
$today = date('Y-m-d');
$monday_this_week = date('Y-m-d', strtotime('monday this week'));
$first_day_this_month = date('Y-m-01');

$prod_name_col = "prod_name_" . $current_lang;
$cat_name_col = "name_" . $current_lang;

// --- 1. POS Sales: Cash / Transfer / Today / Month ---
$today_date      = date('Y-m-d');
$first_of_month  = date('Y-m-01');

// Cash collected (in filtered period)
$stmtCash = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM orders WHERE DATE(o_date) BETWEEN ? AND ? AND payment_method = 'Cash'");
$stmtCash->execute([$start_date, $end_date]);
$pos_cash = (float)$stmtCash->fetchColumn();

// Transfer collected (in filtered period)
$stmtTransfer = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM orders WHERE DATE(o_date) BETWEEN ? AND ? AND payment_method = 'Transfer'");
$stmtTransfer->execute([$start_date, $end_date]);
$pos_transfer = (float)$stmtTransfer->fetchColumn();

// Total revenue for the filtered period
$total_pos_revenue = $pos_cash + $pos_transfer;

// Today POS total
$stmtToday = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM orders WHERE DATE(o_date) = ?");
$stmtToday->execute([$today_date]);
$pos_today = (float)$stmtToday->fetchColumn();

// This month POS total
$stmtMonth = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM orders WHERE DATE(o_date) BETWEEN ? AND ?");
$stmtMonth->execute([$first_of_month, $today_date]);
$pos_month = (float)$stmtMonth->fetchColumn();

// Fetch default currency
$stmtCur = $pdo->query("SELECT symbol FROM currency WHERE is_default = 1 LIMIT 1");
$currency_symbol = $stmtCur->fetchColumn() ?: '₭';

// --- 2. Top 10 Best Selling Products (POS) ---
$stmtTopProd = $pdo->prepare("
    SELECT 
        p.prod_code,
        p.sprice as prod_price,
        p.{$prod_name_col} as product_name_localized,
        p.prod_name,
        pc.{$cat_name_col} as category_name_localized,
        pc.name as category_name,
        SUM(o.o_qty) as total_qty,
        SUM(o.amount) as total_revenue
    FROM orders o
    JOIN products p ON o.prod_id = p.prod_id
    LEFT JOIN product_categories pc ON p.category = pc.name
    WHERE DATE(o.o_date) BETWEEN ? AND ?
    GROUP BY o.prod_id
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 10
");
$stmtTopProd->execute([$start_date, $end_date]);
$top_products = $stmtTopProd->fetchAll();

$top_products_total_qty = 0;
$top_products_total_revenue = 0;
foreach ($top_products as $p) {
    $top_products_total_qty += $p['total_qty'];
    $top_products_total_revenue += $p['total_revenue'];
}


// --- 3. Best Selling Categories (POS) ---
$stmtTopCat = $pdo->prepare("
    SELECT 
        pc.{$cat_name_col} as category_name_localized,
        pc.name as category_name,
        SUM(o.o_qty) as total_qty,
        SUM(o.amount) as total_revenue
    FROM orders o
    JOIN products p ON o.prod_id = p.prod_id
    JOIN product_categories pc ON p.category = pc.name
    WHERE DATE(o.o_date) BETWEEN ? AND ?
    GROUP BY pc.name
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 10
");
$stmtTopCat->execute([$start_date, $end_date]);
$top_categories = $stmtTopCat->fetchAll();


// --- 4. Top 10 Additional Services (Services) ---
$stmtTopServ = $pdo->prepare("
    SELECT 
        rs.item_name,
        SUM(rs.qty) as total_qty,
        SUM(rs.total_price) as total_revenue
    FROM room_services rs
    JOIN bookings b ON rs.booking_id = b.id
    WHERE DATE(b.check_in_date) BETWEEN ? AND ?
    GROUP BY rs.item_name
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 10
");
$stmtTopServ->execute([$start_date, $end_date]);
$top_services = $stmtTopServ->fetchAll();

// Fetch default currency — already fetched above

?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລາຍງານການຂາຍສິນຄ້າ ແລະ ບໍລິການ</title>
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
    <link rel="stylesheet" href="css/report_sales.css">
</head>
<body>

<div class="container-fluid">
    <!-- Header -->
    <div class="section-header no-print d-flex flex-wrap align-items-center justify-content-between mb-4" style="gap: 15px;">
        <h2><i class="fas fa-chart-line text-primary mr-2"></i> ລາຍການສິນຄ້າຂາຍດີ</h2>
        
        <!-- Search Filter Form -->
        <form method="GET" class="form-inline d-flex flex-wrap align-items-center" style="gap: 10px;" id="filterForm">
            <!-- Period Quick Links -->
            <div class="btn-group btn-group-sm mr-2 shadow-sm" role="group">
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $today; ?>', '<?php echo $today; ?>')">ມື້ນີ້</button>
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $monday_this_week; ?>', '<?php echo $today; ?>')">ອາທິດນີ້</button>
                <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="setPeriod('<?php echo $first_day_this_month; ?>', '<?php echo $today; ?>')">ເດືອນນີ້</button>
            </div>

            <div class="input-group input-group-sm" style="width: 170px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0"><i class="fas fa-calendar-alt text-success"></i></span>
                </div>
                <input type="date" name="start_date" id="start_date" class="form-control border-left-0" value="<?php echo $start_date; ?>">
            </div>
            <div class="input-group input-group-sm" style="width: 170px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-right-0">ຫາ</span>
                </div>
                <input type="date" name="end_date" id="end_date" class="form-control border-left-0" value="<?php echo $end_date; ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold">
                <i class="fas fa-search mr-1"></i> ຄົ້ນຫາ
            </button>
        </form>
    </div>

    <!-- Premium Stat Cards Grid -->
    <div class="stat-cards-row mb-4 no-print">
        <!-- 1. Cash -->
        <div class="stat-card-premium blue">
            <div>
                <div class="stat-card-label">ເງິນສົດ (Cash)</div>
                <div class="stat-card-value"><?php echo number_format($pos_cash); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-money-bill-wave mr-1"></i> ຮັບເງິນສົດໃນຊ່ວງເວລານີ້</div>
            <i class="fas fa-wallet stat-card-icon"></i>
        </div>

        <!-- 2. Transfer -->
        <div class="stat-card-premium green">
            <div>
                <div class="stat-card-label">ເງິນໂອນ (Transfer)</div>
                <div class="stat-card-value"><?php echo number_format($pos_transfer); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-exchange-alt mr-1"></i> ໂອນເງິນໃນຊ່ວງເວລານີ້</div>
            <i class="fas fa-university stat-card-icon"></i>
        </div>

        <!-- 3. Today Total -->
        <div class="stat-card-premium orange">
            <div>
                <div class="stat-card-label">ລວມລາຍຮັບມື້ນີ້</div>
                <div class="stat-card-value"><?php echo number_format($pos_today); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-sun mr-1"></i> <?php echo date('d/m/Y'); ?></div>
            <i class="fas fa-cash-register stat-card-icon"></i>
        </div>

        <!-- 4. Month Total -->
        <div class="stat-card-premium purple">
            <div>
                <div class="stat-card-label">ລວມລາຍຮັບເດືອນນີ້</div>
                <div class="stat-card-value"><?php echo number_format($pos_month); ?> <span style="font-size:1rem;font-weight:500;"><?php echo $currency_symbol; ?></span></div>
            </div>
            <div class="stat-card-sub"><i class="fas fa-calendar-alt mr-1"></i> <?php echo date('m/Y'); ?></div>
            <i class="fas fa-chart-bar stat-card-icon"></i>
        </div>
    </div>



    <!-- Top 10 Best Selling Products Chart Card -->
    <div class="card glass-card mb-4 no-print">
        <div class="card-header bg-transparent border-0 py-3">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-chart-bar text-warning mr-2"></i> ກາຟແທ່ງ 10 ອັນດັບສິນຄ້າຂາຍດີທີ່ສຸດ
            </h5>
        </div>
        <div class="card-body p-2">
            <div style="position: relative; height: 220px; max-width: 650px; margin: 0 auto;">
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- POS Sales Table Card -->
    <div class="card glass-card mb-5">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between py-3" style="gap: 15px;">
            <!-- <h5 class="mb-0 font-weight-bold text-dark">
                ລາຍງານສິນຄ້າຂາຍດີ
            </h5> -->
            <!-- Export Buttons -->
            <div class="d-flex align-items-center no-print ml-auto" style="gap: 10px;">
                <button type="button" class="btn btn-export btn-export-excel" onclick="exportTableToExcel()">
                    <i class="fas fa-file-excel mr-1"></i> Excel
                </button>
                <button type="button" class="btn btn-export btn-export-pdf" id="btnPdf">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </button>
            </div>
        </div>

        <div class="card-body p-2 p-md-4">
            <div class="table-responsive">
                <table id="topProductsTable" class="table table-premium text-center" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ລະຫັດສິນຄ້າ</th>
                            <th>ປະເພດ</th>
                            <th class="text-left">ລາຍການສິນຄ້າ</th>
                            <th>ຈຳນວນ</th>
                            <th>ລາຄາ</th>
                            <th class="text-right">ລວມເປັນເງິນ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($top_products) > 0):
                            foreach($top_products as $p): 
                        ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['prod_code']); ?></td>
                                    <td><?php echo htmlspecialchars($p['category_name_localized'] ?: $p['category_name'] ?: 'Unknown'); ?></td>
                                    <td class="text-left font-weight-bold text-dark"><?php echo htmlspecialchars($p['product_name_localized'] ?: $p['prod_name']); ?></td>
                                    <td><strong><?php echo number_format($p['total_qty']); ?></strong></td>
                                    <td><?php echo number_format($p['prod_price']); ?> ₭</td>
                                    <td class="text-right font-weight-bold text-success" style="font-size: 1.02rem;"><?php echo number_format($p['total_revenue']); ?> ₭</td>
                                </tr>
                        <?php 
                            endforeach;
                        else: 
                        ?>
                            <tr><td colspan="6" class="py-4 text-muted">ບໍ່ມີຂໍ້ມູນ</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light font-weight-bold" style="border-top: 2px solid #dee2e6;">
                            <td colspan="3" class="text-right text-dark">ມູນຄ່າລວມສິນຄ້າທີ່ຂາຍດີ:</td>
                            <td><strong><?php echo number_format($top_products_total_qty); ?></strong></td>
                            <td>-</td>
                            <td class="text-right text-success" style="font-size: 1.1rem;"><?php echo number_format($top_products_total_revenue); ?> ₭</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================
     HIDDEN PRINT TARGET FOR A4 LANDSCAPE PDF EXPORT
     ============================================== -->
<div id="pdfExportContainer" style="display: none;">
    <div class="pdf-table-container">
        <style>
            .pdf-table-container {
                font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', 'Segoe UI', sans-serif !important;
                padding: 15px;
            }
            .pdf-table-title {
                font-size: 16px;
                font-weight: bold;
                text-align: center;
                margin-bottom: 5px;
                color: #2c3e50;
            }
            .pdf-table-subtitle {
                font-size: 11px;
                text-align: center;
                margin-bottom: 20px;
                color: #7f8c8d;
            }
            .pdf-table-container table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-bottom: 10px !important;
            }
            .pdf-table-container th, .pdf-table-container td {
                border: 0.5pt solid #aaaaaa !important;
                padding: 10px 8px !important;
                font-size: 10px !important;
                line-height: 1.5 !important;
                word-break: break-word !important;
                white-space: normal !important;
                vertical-align: middle !important;
            }
            .pdf-table-container th {
                background-color: #2c3e50 !important;
                color: #ffffff !important;
                font-weight: bold !important;
                text-align: center !important;
            }
            .pdf-table-container .text-right {
                text-align: right !important;
            }
            .pdf-table-container .text-left {
                text-align: left !important;
            }
            .pdf-table-container .text-center {
                text-align: center !important;
            }
        </style>
        <div class="pdf-table-title" id="pdfReportTitle">ລາຍງານການຂາຍສິນຄ້າ POS (Top 10)</div>
        <div class="pdf-table-subtitle">ໄລຍະເວລາ: <?php echo date('d/m/Y', strtotime($start_date)); ?> ຫາ <?php echo date('d/m/Y', strtotime($end_date)); ?></div>
        <div id="pdfTablePlaceholder"></div>
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
    // 1. Chart.js Config for Top 10 Best Selling Products
    // ----------------------------------------------------
    const topProductsData = <?php echo json_encode($top_products); ?>;
    
    if (topProductsData.length > 0) {
        const labels = topProductsData.map(p => p.product_name_localized || p.prod_name);
        const dataValues = topProductsData.map(p => parseInt(p.total_qty) || 0);
        
        const ctx = document.getElementById('topProductsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'ຈຳນວນທີ່ຂາຍໄດ້ (ຊິ້ນ)',
                    data: dataValues,
                    backgroundColor: [
                        'rgba(26, 115, 232, 0.85)',
                        'rgba(40, 167, 69, 0.85)',
                        'rgba(253, 126, 20, 0.85)',
                        'rgba(111, 66, 193, 0.85)',
                        'rgba(23, 162, 184, 0.85)',
                        'rgba(224, 86, 36, 0.85)',
                        'rgba(230, 47, 90, 0.85)',
                        'rgba(74, 186, 162, 0.85)',
                        'rgba(255, 193, 7, 0.85)',
                        'rgba(108, 117, 125, 0.85)'
                    ],
                    borderColor: [
                        'rgba(26, 115, 232, 1)',
                        'rgba(40, 167, 69, 1)',
                        'rgba(253, 126, 20, 1)',
                        'rgba(111, 66, 193, 1)',
                        'rgba(23, 162, 184, 1)',
                        'rgba(224, 86, 36, 1)',
                        'rgba(230, 47, 90, 1)',
                        'rgba(74, 186, 162, 1)',
                        'rgba(255, 193, 7, 1)',
                        'rgba(108, 117, 125, 1)'
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
                            fontFamily: "'Noto Sans Lao', 'Segoe UI', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 11
                        },
                        gridLines: {
                            color: 'rgba(226, 232, 240, 0.6)'
                        }
                    }],
                    xAxes: [{
                        ticks: {
                            fontFamily: "'Noto Sans Lao', 'Segoe UI', sans-serif",
                            fontColor: '#4a5568',
                            fontSize: 10,
                            maxRotation: 45,
                            minRotation: 15
                        },
                        gridLines: {
                            display: false
                        }
                    }]
                },
                tooltips: {
                    titleFontFamily: "'Noto Sans Lao', 'Segoe UI', sans-serif",
                    bodyFontFamily: "'Noto Sans Lao', 'Segoe UI', sans-serif",
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    titleFontSize: 12,
                    bodyFontSize: 12,
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(tooltipItem) {
                            return 'ຈຳນວນ: ' + Number(tooltipItem.yLabel).toLocaleString('en-US') + ' ຊິ້ນ';
                        }
                    }
                }
            }
        });
    }

    // ----------------------------------------------------
    // 2. DataTables Configuration (Lao Localized)
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

    $('#topProductsTable').DataTable({
        "language": datatableLaoLang,
        "order": [[3, "desc"]],
        "pageLength": 10,
        "lengthMenu": [5, 10, 20]
    });

    // ----------------------------------------------------
    // 3. PDF Export Click Handler (POS only)
    // ----------------------------------------------------
    $('#btnPdf').click(function() {
        var pdfTitle = "ລາຍງານການຂາຍສິນຄ້າ POS (Top 10)";
        
        var tableHtml = `
            <table>
                <thead>
                    <tr>
                        <th>ລະຫັດສິນຄ້າ</th>
                        <th>ປະເພດ</th>
                        <th class="text-left">ລາຍການສິນຄ້າ</th>
                        <th>ຈຳນວນ</th>
                        <th>ລາຄາ</th>
                        <th class="text-right">ລວມເປັນເງິນ</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        $('#topProductsTable tbody tr').each(function() {
            var cols = $(this).find('td');
            if (cols.length > 1) {
                tableHtml += `
                    <tr>
                        <td>${$(cols[0]).text().trim()}</td>
                        <td>${$(cols[1]).text().trim()}</td>
                        <td class="text-left" style="font-weight: bold;">${$(cols[2]).text().trim()}</td>
                        <td class="text-center" style="font-weight: bold;">${$(cols[3]).text().trim()}</td>
                        <td class="text-right">${$(cols[4]).text().trim()}</td>
                        <td class="text-right" style="font-weight: bold; color: #2c3e50;">${$(cols[5]).text().trim()}</td>
                    </tr>
                `;
            }
        });
        
        var footerCols = $('#topProductsTable tfoot tr').find('td');
        if (footerCols.length > 0) {
            tableHtml += `
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background-color: #f9f9f9;">
                        <td colspan="3" class="text-right">ມູນຄ່າລວມສິນຄ້າທີ່ຂາຍດີ:</td>
                        <td class="text-center">${$(footerCols[1]).text().trim()}</td>
                        <td>-</td>
                        <td class="text-right" style="color: #d9534f;">${$(footerCols[3]).text().trim()}</td>
                    </tr>
                </tfoot>
            </table>
            `;
        } else {
            tableHtml += `</tbody></table>`;
        }

        $('#pdfReportTitle').text(pdfTitle);
        $('#pdfTablePlaceholder').html(tableHtml);

        var opt = {
            margin:       12,
            filename:     pdfTitle + '_' + new Date().toISOString().split('T')[0] + '.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2.5, useCORS: true, letterRendering: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };

        var element = document.getElementById('pdfExportContainer');
        element.style.display = 'block';
        
        html2pdf().set(opt).from(element).save().then(function() {
            element.style.display = 'none';
            Swal.fire({
                icon: 'success',
                title: 'ດາວໂຫຼດ PDF ສຳເລັດ',
                text: 'ລາຍງານນີ້ໄດ້ຖືກ Export ອອກເປັນ PDF ຮຽບຮ້ອຍແລ້ວ!',
                confirmButtonColor: '#28a745',
                confirmButtonText: 'ຕົກລົງ'
            });
        }).catch(function(err) {
            element.style.display = 'none';
            Swal.fire({
                icon: 'error',
                title: 'ຜິດພາດ',
                text: 'ບໍ່ສາມາດສ້າງ PDF ໄດ້: ' + err,
                confirmButtonColor: '#d33',
                confirmButtonText: 'ຕົກລົງ'
            });
        });
    });
});

// Helper to set start and end date from quick period buttons
function setPeriod(start, end) {
    $('#start_date').val(start);
    $('#end_date').val(end);
    $('#filterForm').submit();
}

// ----------------------------------------------------
// 4. Excel Export (POS only - Lao Styled XLS)
// ----------------------------------------------------
function exportTableToExcel() {
    var filename = "ລາຍງານ_ການຂາຍ_POS_" + new Date().toISOString().split('T')[0] + ".xls";
    
    // Build HTML spreadsheet with custom Lao font support
    var excelHtml = `
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
<!--[if gte mso 9]>
<xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>ລາຍງານການຂາຍ</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body, table, td, th {
    font-family: 'Noto Sans Lao', 'Noto Sans Lao Looped', 'Segoe UI', sans-serif;
  }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 0.5pt solid #cccccc; padding: 6px; font-size: 11pt; }
  th { background-color: #2c3e50; color: #ffffff; font-weight: bold; text-align: center; }
  .text-right { text-align: right; }
  .text-left { text-align: left; }
  .text-center { text-align: center; }
  .title-row { font-size: 14pt; font-weight: bold; height: 35px; text-align: center; }
  .total-row { font-weight: bold; background-color: #f9f9f9; }
</style>
</head>
<body>
<table>
  <thead>
    <tr class="title-row"><th colspan="6">ອັນດັບສິນຄ້າຂາຍດີ (POS Sales Report)</th></tr>
    <tr class="title-row"><th colspan="6" style="font-size: 10pt; font-weight: normal; color: #555;">ໄລຍະເວລາ: <?php echo date('d/m/Y', strtotime($start_date)); ?> ຫາ <?php echo date('d/m/Y', strtotime($end_date)); ?></th></tr>
    <tr>
      <th>ລະຫັດສິນຄ້າ</th>
      <th>ປະເພດ</th>
      <th>ລາຍການສິນຄ້າ</th>
      <th>ຈຳນວນ</th>
      <th>ລາຄາ</th>
      <th>ລວມເປັນເງິນ</th>
    </tr>
  </thead>
  <tbody>
`;

    $('#topProductsTable tbody tr').each(function() {
        var cols = $(this).find('td');
        if (cols.length > 1) {
            excelHtml += `
    <tr>
      <td class="text-center">${$(cols[0]).text().trim()}</td>
      <td class="text-center">${$(cols[1]).text().trim()}</td>
      <td class="text-left">${$(cols[2]).text().trim()}</td>
      <td class="text-center">${$(cols[3]).text().trim()}</td>
      <td class="text-right">${$(cols[4]).text().trim().replace(/[₭,]/g, '')}</td>
      <td class="text-right">${$(cols[5]).text().trim().replace(/[₭,]/g, '')}</td>
    </tr>`;
        }
    });

    var footerCols = $('#topProductsTable tfoot tr').find('td');
    if (footerCols.length > 0) {
        excelHtml += `
    <tr class="total-row">
      <td colspan="3" class="text-right">${$(footerCols[0]).text().trim()}</td>
      <td class="text-center">${$(footerCols[1]).text().trim()}</td>
      <td class="text-center">-</td>
      <td class="text-right">${$(footerCols[3]).text().trim().replace(/[₭,]/g, '')}</td>
    </tr>`;
    }

    excelHtml += `
  </tbody>
</table>
</body>
</html>`;

    var blob = new Blob([excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var link = document.createElement("a");
    if (link.download !== undefined) { 
        var url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        Swal.fire({
            icon: 'success',
            title: 'Export Excel ສຳເລັດ',
            text: 'ດາວໂຫຼດຟາຍ Excel ສຳເລັດ!',
            confirmButtonColor: '#28a745',
            confirmButtonText: 'ຕົກລົງ'
        });
    }
}
</script>
</body>
</html>
