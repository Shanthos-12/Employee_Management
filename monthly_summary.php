<?php
/**************************************************************
 * Monthly Salary Summary - v2.0 (Hybrid FE + PDF Approach)
 **************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php';
require_once 'fpdf/fpdf.php'; // Include the FPDF library

// --- 1. INITIALIZATION AND USER AUTHENTICATION ---
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$userRole = $_SESSION['user']['role'] ?? 'guest';
$pdo = getPDO();

// --- 2. PDF GENERATION LOGIC (No changes needed here) ---
class SummaryPDF extends FPDF
{
    private $month;
    private $year;
    private $employeeType;
    private $companyName;

    function __construct($month, $year, $employeeType, $companyName = null)
    {
    // Use extra-wide custom landscape page for full table visibility
    parent::__construct('L', 'mm', [600, 297]);
        $this->month = $month;
        $this->year = $year;
        $this->employeeType = ucfirst($employeeType);
        $this->companyName = $companyName;
    }

    function Header()
    {
        if (file_exists('assets/sfi_logo.png')) {
            $this->Image('assets/sfi_logo.png', 10, 6, 20);
        }
        $this->SetFont('Arial', 'B', 15);
        $title = 'Monthly Salary Summary - ' . $this->employeeType . ' Staff';
        if ($this->companyName) {
            $title .= ' (' . $this->companyName . ')';
        }
        $this->Cell(0, 10, $title, 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'For the Period of: ' . $this->month . ' ' . $this->year, 0, 1, 'C');
        $this->Ln(10);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function SummaryTable($data) {
    // Dynamically calculate column widths for extra-wide page with 12hr overtime columns
    $numCols = 19; // Increased from 16 to 19 for separate 12hr overtime
    $usableWidth = 580; // 600mm page minus margins
    $baseWidths = [20, 55, 40, 28, 28, 35, 28, 28, 35, 28, 28, 35, 28, 28, 35, 28, 28, 35, 55];
    $totalBase = array_sum($baseWidths);
    $scale = $usableWidth / $totalBase;
    $w = array_map(fn($bw) => $bw * $scale, $baseWidths);
    $this->SetLeftMargin(10);
    $this->SetRightMargin(10);
    $this->SetFont('Arial', '', 8); // Reduce font size for more data
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(180, 200, 230);

        // Main header row
        $this->Cell($w[0], 14, 'NO', 1, 0, 'C', true);
        $this->Cell($w[1], 14, 'NAME', 1, 0, 'C', true);
        $this->Cell($w[2], 14, 'BASIC (RM)', 1, 0, 'C', true);
        // WORKING DAYS (12HR)
        $this->Cell($w[3]+$w[4]+$w[5], 7, 'WORKING DAYS (12HR)', 1, 0, 'C', true);
        // WORKING DAYS (9HR)
        $this->Cell($w[6]+$w[7]+$w[8], 7, 'WORKING DAYS (9HR)', 1, 0, 'C', true);
        // 9HR OT
        $this->Cell($w[9]+$w[10]+$w[11], 7, '9HR OT', 1, 0, 'C', true);
        // 12HR OT
        $this->Cell($w[12]+$w[13]+$w[14], 7, '12HR OT', 1, 0, 'C', true);
        // SUNDAY / PH OT
        $this->Cell($w[15]+$w[16]+$w[17], 7, 'SUNDAY / PH OT', 1, 0, 'C', true);
        $this->Cell($w[18], 14, 'GRAND TOTAL (RM)', 1, 1, 'C', true);

        // Sub-header row
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($w[0], 7, '', 0, 0);
        $this->Cell($w[1], 7, '', 0, 0);
        $this->Cell($w[2], 7, '', 0, 0);
        $this->Cell($w[3], 7, 'DAYS', 1, 0, 'C', true);
        $this->Cell($w[4], 7, 'RATE (RM)', 1, 0, 'C', true);
        $this->Cell($w[5], 7, 'AMOUNT (RM)', 1, 0, 'C', true);
        $this->Cell($w[6], 7, 'DAYS', 1, 0, 'C', true);
        $this->Cell($w[7], 7, 'RATE (RM)', 1, 0, 'C', true);
        $this->Cell($w[8], 7, 'AMOUNT (RM)', 1, 0, 'C', true);
        $this->Cell($w[9], 7, 'HOURS', 1, 0, 'C', true);
        $this->Cell($w[10], 7, 'RATE (RM)', 1, 0, 'C', true);
        $this->Cell($w[11], 7, 'AMOUNT (RM)', 1, 0, 'C', true);
        $this->Cell($w[12], 7, 'HOURS', 1, 0, 'C', true);
        $this->Cell($w[13], 7, 'RATE (RM)', 1, 0, 'C', true);
        $this->Cell($w[14], 7, 'AMOUNT (RM)', 1, 0, 'C', true);
        $this->Cell($w[15], 7, 'HOURS', 1, 0, 'C', true);
        $this->Cell($w[16], 7, 'RATE (RM)', 1, 0, 'C', true);
        $this->Cell($w[17], 7, 'AMOUNT (RM)', 1, 0, 'C', true);
        $this->Cell($w[18], 7, '', 0, 1);

        // Data rows
        $this->SetFont('Arial', '', 8);
        $fill = false;
        $no = 1;
        $totals = [
            'basic' => 0,
            'wd12_days' => 0,
            'wd12_amount' => 0,
            'wd8_days' => 0,
            'wd8_amount' => 0,
            'ot_hours' => 0,
            'ot_amount' => 0,
            'ot_12hr_hours' => 0,
            'ot_12hr_amount' => 0,
            'sunday_hours' => 0,
            'sunday_amount' => 0,
            'grand_total' => 0
        ];
        foreach ($data as $row) {
            $full_basics = $row['full_basics']; // Fetch full_basics from the database
            $basic = $row['basic']; // Fetch basic for table logic
            // Fetch actual working days/rates from DB
            $wd12_days = isset($row['working_days_12hr']) ? (float)$row['working_days_12hr'] : 0;
            $wd12_rate = isset($row['rate_12hr']) ? (float)$row['rate_12hr'] : 0;
            $wd12_amount = $wd12_days * $wd12_rate;
            $wd8_days = isset($row['working_days_8hr']) ? (float)$row['working_days_8hr'] : 0;
            $wd8_rate = isset($row['rate_8hr']) ? (float)$row['rate_8hr'] : 0;
            $wd8_amount = $wd8_days * $wd8_rate;
            $ot_hours = (float)($row['overtime_hours'] ?? 0);
            $ot_rate = (float)($row['overtime_rate'] ?? 0);
            $ot_amount = $ot_hours * $ot_rate;
            $ot_12hr_hours = (float)($row['overtime_12hr_hours'] ?? 0);
            $ot_12hr_rate = (float)($row['overtime_rate_12hr'] ?? 0);
            $ot_12hr_amount = $ot_12hr_hours * $ot_12hr_rate;
            $sunday_hours = (float)($row['sunday_days'] ?? 0);
            $sunday_rate = (float)($row['sunday_rate'] ?? 0);
            $sunday_amount = $sunday_hours * $sunday_rate;
            $grand_total = (float)($row['net_pay'] ?? 0);

            $this->Cell($w[0], 7, $no++, 1, 0, 'C', $fill);
            $this->Cell($w[1], 7, $row['name'], 1, 0, 'L', $fill);
            $this->Cell($w[2], 7, number_format((float)$full_basics, 2), 1, 0, 'R', $fill);
            $this->Cell($w[3], 7, number_format($wd12_days,2), 1, 0, 'R', $fill);
            $this->Cell($w[4], 7, number_format($wd12_rate,2), 1, 0, 'R', $fill);
            $this->Cell($w[5], 7, number_format($wd12_amount,2), 1, 0, 'R', $fill);
            $this->Cell($w[6], 7, number_format($wd8_days,2), 1, 0, 'R', $fill);
            $this->Cell($w[7], 7, number_format($wd8_rate,2), 1, 0, 'R', $fill);
            $this->Cell($w[8], 7, number_format($wd8_amount,2), 1, 0, 'R', $fill);
            $this->Cell($w[9], 7, number_format($ot_hours,2), 1, 0, 'R', $fill);
            $this->Cell($w[10], 7, number_format($ot_rate,2), 1, 0, 'R', $fill);
            $this->Cell($w[11], 7, number_format($ot_amount,2), 1, 0, 'R', $fill);
            $this->Cell($w[12], 7, number_format($ot_12hr_hours,2), 1, 0, 'R', $fill);
            $this->Cell($w[13], 7, number_format($ot_12hr_rate,2), 1, 0, 'R', $fill);
            $this->Cell($w[14], 7, number_format($ot_12hr_amount,2), 1, 0, 'R', $fill);
            $this->Cell($w[15], 7, number_format($sunday_hours,2), 1, 0, 'R', $fill);
            $this->Cell($w[16], 7, number_format($sunday_rate,2), 1, 0, 'R', $fill);
            $this->Cell($w[17], 7, number_format($sunday_amount,2), 1, 0, 'R', $fill);
            $this->Cell($w[18], 7, number_format($grand_total,2), 1, 1, 'R', $fill);

            $totals['basic'] += $full_basics; // Use full_basics for the basics total
            $totals['wd12_days'] += $wd12_days;
            $totals['wd12_amount'] += $wd12_amount;
            $totals['wd8_days'] += $wd8_days;
            $totals['wd8_amount'] += $wd8_amount;
            $totals['ot_hours'] += $ot_hours;
            $totals['ot_amount'] += $ot_amount;
            $totals['ot_12hr_hours'] += $ot_12hr_hours;
            $totals['ot_12hr_amount'] += $ot_12hr_amount;
            $totals['sunday_hours'] += $sunday_hours;
            $totals['sunday_amount'] += $sunday_amount;
            $totals['grand_total'] += $grand_total;
        }
        // Totals row
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(255,255,150);
        $this->Cell($w[0]+$w[1], 7, '', 0, 0);
        $this->Cell($w[2], 7, number_format($totals['basic'],2), 1, 0, 'R', true);
        $this->Cell($w[3], 7, number_format($totals['wd12_days'],2), 1, 0, 'R', true);
        $this->Cell($w[4], 7, '', 0, 0);
        $this->Cell($w[5], 7, number_format($totals['wd12_amount'],2), 1, 0, 'R', true);
        $this->Cell($w[6], 7, number_format($totals['wd8_days'],2), 1, 0, 'R', true);
        $this->Cell($w[7], 7, '', 0, 0);
        $this->Cell($w[8], 7, number_format($totals['wd8_amount'],2), 1, 0, 'R', true);
        $this->Cell($w[9], 7, number_format($totals['ot_hours'],2), 1, 0, 'R', true);
        $this->Cell($w[10], 7, '', 0, 0);
        $this->Cell($w[11], 7, number_format($totals['ot_amount'],2), 1, 0, 'R', true);
        $this->Cell($w[12], 7, number_format($totals['ot_12hr_hours'],2), 1, 0, 'R', true);
        $this->Cell($w[13], 7, '', 0, 0);
        $this->Cell($w[14], 7, number_format($totals['ot_12hr_amount'],2), 1, 0, 'R', true);
        $this->Cell($w[15], 7, number_format($totals['sunday_hours'],2), 1, 0, 'R', true);
        $this->Cell($w[16], 7, '', 0, 0);
        $this->Cell($w[17], 7, number_format($totals['sunday_amount'],2), 1, 0, 'R', true);
        $this->Cell($w[18], 7, number_format($totals['grand_total'],2), 1, 1, 'R', true);
    }
}

// --- 3. HANDLE PDF GENERATION REQUEST ---
if (isset($_GET['action']) && $_GET['action'] == 'generate_pdf') {
    $month = $_GET['month'] ?? date('F');
    $year = $_GET['year'] ?? date('Y');
    $type = $_GET['type'] ?? 'local';
    $company_id = $_GET['company_id'] ?? null;

    // Change 'international' to 'foreign'
    $type = $type === 'foreign' ? 'foreign' : 'local';

    // Add company filtering to PDF generation
    $company_filter = $company_id ? "AND ss.company_id = ?" : "";
    
    $sql = "SELECT ss.*, e.name, e.employee_type, e.identity_card_no, e.passport_no, c.name AS company_name 
            FROM salary_summaries ss 
            JOIN employees e ON ss.employee_id = e.id 
            JOIN companies c ON ss.company_id = c.id
            WHERE ss.month = ? AND ss.year = ? AND e.employee_type = ? $company_filter
            ORDER BY e.name ASC";
    $stmt = $pdo->prepare($sql);
    $params = [$month, $year, ucfirst($type)];
    if ($company_id) {
        $params[] = $company_id;
    }
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($data)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => "No payslip data found for " . ucfirst($type) . " employees for $month $year."];
        header("Location: monthly_summary.php?month=$month&year=$year&show_summary=1");
        exit;
    }

    // Get company name if filtering by company
    $companyName = null;
    if ($company_id && !empty($data)) {
        $companyName = $data[0]['company_name'] ?? null;
    }

    $pdf = new SummaryPDF($month, $year, $type, $companyName);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SummaryTable($data);
    
    // Update filename to include company name if filtered
    $filename = "Salary_Summary_{$type}_{$month}_{$year}";
    if ($companyName) {
        $filename .= "_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $companyName);
    }
    $pdf->Output('D', "{$filename}.pdf");
    exit;
}

// --- 4. DATA FETCHING FOR FRONT-END DISPLAY ---
$local_data = [];
$foreign_data = []; // Rename 'international_data' to 'foreign_data'
$show_summary = isset($_GET['show_summary']);
$selectedMonth = $_GET['month'] ?? date('F');
$selectedYear = $_GET['year'] ?? date('Y');
$selected_company_id = $_GET['company_id'] ?? null;

// Initialize totals to prevent undefined variable errors
$local_totals = array_fill_keys(['basic', 'overtime_rm', 'sun_ph_ot', 'allowance', 'earnings_total', 'deductions_total', 'net_pay'], 0);
$intl_totals = array_fill_keys(['basic', 'overtime_rm', 'sun_ph_ot', 'allowance', 'earnings_total', 'deductions_total', 'net_pay'], 0);

// Fetch companies for the filter dropdown
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($show_summary) {
    $company_filter = $selected_company_id ? "AND ss.company_id = ?" : "";

    // Fetch Local Data
    $sql_local = "SELECT ss.*, e.name, e.employee_type, e.identity_card_no, e.passport_no, c.name AS company_name, ss.employee_id
                 FROM salary_summaries ss
                 JOIN employees e ON ss.employee_id = e.id
                 JOIN companies c ON ss.company_id = c.id
                 WHERE ss.month = ? AND ss.year = ? AND e.employee_type = 'Local' $company_filter
                 ORDER BY e.name ASC";
    $stmt_local = $pdo->prepare($sql_local);
    $params_local = [$selectedMonth, $selectedYear];
    if ($selected_company_id) {
        $params_local[] = $selected_company_id;
    }
    $stmt_local->execute($params_local);
    $local_data = $stmt_local->fetchAll(PDO::FETCH_ASSOC);

    // Calculate local totals (sum per-row 'basic' from table rows — do NOT use full_basics here)
    $local_totals = array_reduce($local_data, function ($carry, $item) {
        $carry['basic'] += (float)($item['basic'] ?? 0);
        $carry['overtime_rm'] += (float)($item['overtime_rm'] ?? 0) + (float)($item['overtime_12hr_rm'] ?? 0);
        $carry['sun_ph_ot'] += (float)($item['sunday'] ?? 0) + (float)($item['public_holiday'] ?? 0) + (float)($item['sunday_ph_ot'] ?? 0);
    // NOTE: fixed_allowance (consultant fee) is now treated as an allowance (earning)
    $carry['allowance'] += (float)($item['special_allowance'] ?? 0) + (float)($item['back_pay'] ?? 0) + (float)($item['night_shift_allowance'] ?? 0) + (float)($item['fixed_allowance'] ?? 0);
        $carry['earnings_total'] += (float)($item['earnings_total'] ?? 0);
        $carry['deductions_total'] += (float)($item['deductions_total'] ?? 0);
        $carry['net_pay'] += (float)($item['net_pay'] ?? 0);
        return $carry;
    }, array_fill_keys(['basic', 'overtime_rm', 'sun_ph_ot', 'allowance', 'earnings_total', 'deductions_total', 'net_pay'], 0));

    // Fetch Foreign Data
    $sql_foreign = "SELECT ss.*, e.name, e.employee_type, e.identity_card_no, e.passport_no, c.name AS company_name, ss.employee_id
                   FROM salary_summaries ss
                   JOIN employees e ON ss.employee_id = e.id
                   JOIN companies c ON ss.company_id = c.id
                   WHERE ss.month = ? AND ss.year = ? AND e.employee_type = 'Foreign' $company_filter
                   ORDER BY e.name ASC";
    $stmt_foreign = $pdo->prepare($sql_foreign);
    $params_foreign = [$selectedMonth, $selectedYear];
    if ($selected_company_id) {
        $params_foreign[] = $selected_company_id;
    }
    $stmt_foreign->execute($params_foreign);
    $foreign_data = $stmt_foreign->fetchAll(PDO::FETCH_ASSOC);

    // Calculate foreign totals (sum per-row 'basic' from table rows — do NOT use full_basics here)
    $intl_totals = array_reduce($foreign_data, function ($carry, $item) {
        $carry['basic'] += (float)($item['basic'] ?? 0);
        $carry['overtime_rm'] += (float)($item['overtime_rm'] ?? 0) + (float)($item['overtime_12hr_rm'] ?? 0);
        $carry['sun_ph_ot'] += (float)($item['sunday'] ?? 0) + (float)($item['public_holiday'] ?? 0) + (float)($item['sunday_ph_ot'] ?? 0);
    // NOTE: fixed_allowance (consultant fee) is now treated as an allowance (earning)
    $carry['allowance'] += (float)($item['special_allowance'] ?? 0) + (float)($item['back_pay'] ?? 0) + (float)($item['night_shift_allowance'] ?? 0) + (float)($item['fixed_allowance'] ?? 0);
        $carry['earnings_total'] += (float)($item['earnings_total'] ?? 0);
        $carry['deductions_total'] += (float)($item['deductions_total'] ?? 0);
        $carry['net_pay'] += (float)($item['net_pay'] ?? 0);
        return $carry;
    }, array_fill_keys(['basic', 'overtime_rm', 'sun_ph_ot', 'allowance', 'earnings_total', 'deductions_total', 'net_pay'], 0));
}

// --- 5. DISPLAY THE UI PAGE ---
$notification = $_SESSION['notification'] ?? null;
unset($_SESSION['notification']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <meta charset="UTF-8" />
    <title>Monthly Salary Summary</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
    <header
        class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-10">
        <h1 class="text-2xl font-bold flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg"
                class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg><span>Monthly Salary Summary</span></h1>
        <a href="index.php?dashboard=1"
            class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2"><svg
                xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                    d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                    clip-rule="evenodd" />
            </svg><span>Dashboard</span></a>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-10 space-y-8">
        <?php if ($notification): ?>
            <div
                class="p-4 <?= ($notification['type'] === 'error') ? 'bg-red-500/20 text-red-300' : 'bg-sky-500/20 text-sky-300' ?> rounded-lg">
                <?= htmlspecialchars($notification['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border border-slate-700/50">
            <h2 class="text-xl font-bold mb-4">View Summary Report</h2>
            <p class="text-slate-400 mb-6">Select a period and click "View Summary" to display the reports on this page.
            </p>

            <form method="GET" class="grid sm:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm text-slate-300 mb-1">Month *</label>
                    <select name="month" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                        <?php $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                        foreach ($months as $m): ?>
                            <option value="<?= $m ?>" <?= ($selectedMonth == $m) ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-300 mb-1">Year *</label>
                    <input type="number" name="year" required
                        class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600"
                        value="<?= htmlspecialchars($selectedYear ?? '') ?>">
                </div>
                <div>
                    <label for="company_id" class="text-sm">Company</label>
                    <select name="company_id" id="company_id" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="">-- All Companies --</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= $company['id'] ?>" <?= ($selected_company_id ?? '') == $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Add the hidden input field -->
                <input type="hidden" name="show_summary" value="1">
                <div class="sm:col-span-3 flex justify-center mt-4">
                    <button type="submit"
                        class="w-full p-2.5 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 font-semibold transition-opacity">
                        View Summary
                    </button>
                </div>
            </form>
        </section>

        <?php if ($show_summary): ?>
            <!-- Local Employees Summary -->
            <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border border-slate-700/50">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-emerald-400">Local Employee Summary</h3>
                    <div class="flex gap-2">
                    <a href="?action=generate_pdf&type=local&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?><?= $selected_company_id ? '&company_id=' . urlencode($selected_company_id) : '' ?>"
                        class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-500 font-semibold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download PDF
                    </a>
                    <a href="#" data-invoice-type="local" class="go-to-invoice px-4 py-2 text-sm rounded-lg bg-indigo-600 hover:bg-indigo-500 font-semibold flex items-center gap-2"
                       data-url="invoice.php?type=local&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>&basic_amount=<?= urlencode((string)$local_totals['basic']) ?>&overtime_amount=<?= urlencode((string)$local_totals['overtime_rm']) ?>&sunday_ph_amount=<?= urlencode((string)$local_totals['sun_ph_ot']) ?>&allowance_amount=<?= urlencode((string)$local_totals['allowance']) ?>&deduction_amount=<?= urlencode((string)$local_totals['deductions_total']) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        Go to Invoice
                    </a>
                    </div>
                </div>
                <?php if (empty($local_data)): ?>
                    <p class="text-slate-400 text-center py-4">No records found for local employees for this period.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-800 text-slate-300 uppercase text-xs">
                                <tr>
                                    <th class="p-2">#</th>
                                    <th class="p-2">Name</th>
                                    <th class="p-2">IC Number</th>
                                    <th class="p-2 text-right">Basic</th>
                                    <th class="p-2 text-right">Overtime</th>
                                    <th class="p-2 text-right">Sun/PH OT</th>
                                    <th class="p-2 text-right">Allowance</th>
                                    <th class="p-2 text-right">Gross Pay</th>
                                    <th class="p-2 text-right">Deduction</th>
                                    <th class="p-2 text-right">Net Pay</th>
                                    <th class="p-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($local_data as $i => $row): ?>
                                    <tr class="hover:bg-slate-700/40 border-b border-slate-700">
                                        <td class="p-2"><?= $i + 1 ?></td>
                                        <td class="p-2"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                        <td class="p-2"><?= htmlspecialchars($row['identity_card_no'] ?? '') ?></td>
                                        <td class="p-2 text-right"><?= number_format((float) $row['basic'], 2) ?></td>
                                        <td class="p-2 text-right"><?= number_format((float) $row['overtime_rm'] + (float) ($row['overtime_12hr_rm'] ?? 0), 2) ?></td>
                                        <td class="p-2 text-right">
                                            <?= number_format((float) ($row['sunday'] + $row['public_holiday'] + ($row['sunday_ph_ot'] ?? 0)), 2) ?></td>
                                        <td class="p-2 text-right">
                                            <?= number_format((float) (($row['special_allowance'] ?? 0) + ($row['back_pay'] ?? 0) + ($row['night_shift_allowance'] ?? 0)), 2) ?>
                                        </td>
                                        <td class="p-2 text-right font-medium text-sky-400">
                                            <?= number_format((float) $row['earnings_total'], 2) ?></td>
                                        <td class="p-2 text-right font-medium text-rose-400">
                                            <?= number_format((float) $row['deductions_total'], 2) ?></td>
                                        <td class="p-2 text-right font-bold text-emerald-400">
                                            <?= number_format((float) $row['net_pay'], 2) ?></td>
                                        <td class="p-2">
                                            <a href="payroll.php?prefill=1&employee_id=<?= urlencode((string)$row['employee_id']) ?>&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>&basic=<?= urlencode((string)$row['basic']) ?>&overtime_hours=<?= urlencode((string)($row['overtime_hours'] ?? '0')) ?>&overtime_rate=<?= urlencode((string)($row['overtime_rate'] ?? '0')) ?>&overtime_rm=<?= urlencode((string)($row['overtime_rm'] ?? '0')) ?>&overtime_12hr_hours=<?= urlencode((string)($row['overtime_12hr_hours'] ?? '0')) ?>&overtime_rate_12hr=<?= urlencode((string)($row['overtime_rate_12hr'] ?? '0')) ?>&overtime_12hr_rm=<?= urlencode((string)($row['overtime_12hr_rm'] ?? '0')) ?>&sunday_days=<?= urlencode((string)$row['sunday_days']) ?>&sunday_rate=<?= urlencode((string)$row['sunday_rate']) ?>&sunday=<?= urlencode((string)$row['sunday']) ?>&ph_days=<?= urlencode((string)$row['ph_days']) ?>&ph_rate=<?= urlencode((string)$row['ph_rate']) ?>&public_holiday=<?= urlencode((string)$row['public_holiday']) ?>&fixed_allowance=<?= urlencode((string)$row['fixed_allowance']) ?>&special_allowance=<?= urlencode((string)$row['special_allowance']) ?>&deductions_total=<?= urlencode((string)$row['deductions_total']) ?>&net_pay=<?= urlencode((string)$row['net_pay']) ?>&working_days_8hr=<?= urlencode((string)$row['working_days_8hr']) ?>&rate_8hr=<?= urlencode((string)$row['rate_8hr']) ?>&working_days_12hr=<?= urlencode((string)$row['working_days_12hr']) ?>&rate_12hr=<?= urlencode((string)$row['rate_12hr']) ?>&full_basic=<?= urlencode((string)$row['full_basics']) ?>"
                                               class="text-indigo-400 hover:underline">Go to Payroll</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-800 font-bold">
                                <tr>
                                    <td colspan="3" class="p-2 text-center">GRAND TOTAL</td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['basic'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['overtime_rm'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['sun_ph_ot'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['allowance'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['earnings_total'], 2) ?>
                                    </td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['deductions_total'], 2) ?>
                                    </td>
                                    <td class="p-2 text-right"><?= number_format((float) $local_totals['net_pay'], 2) ?></td>
                                    <td class="p-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <!-- Foreign Employees Summary -->
            <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border border-slate-700/50">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-sky-400">Foreign Employee Summary</h3>
                    <div class="flex gap-2">
                    <a href="?action=generate_pdf&type=foreign&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?><?= $selected_company_id ? '&company_id=' . urlencode($selected_company_id) : '' ?>"
                        class="px-4 py-2 text-sm rounded-lg bg-sky-600 hover:bg-sky-500 font-semibold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download PDF
                    </a>
                    <a href="#" data-invoice-type="foreign" class="go-to-invoice px-4 py-2 text-sm rounded-lg bg-indigo-600 hover:bg-indigo-500 font-semibold flex items-center gap-2"
                       data-url="invoice.php?type=foreign&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>&basic_amount=<?= urlencode((string)$intl_totals['basic']) ?>&overtime_amount=<?= urlencode((string)$intl_totals['overtime_rm']) ?>&sunday_ph_amount=<?= urlencode((string)$intl_totals['sun_ph_ot']) ?>&allowance_amount=<?= urlencode((string)$intl_totals['allowance']) ?>&deduction_amount=<?= urlencode((string)$intl_totals['deductions_total']) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        Go to Invoice
                    </a>
                    </div>
                </div>
                <?php if (empty($foreign_data)): ?>
                    <p class="text-slate-400 text-center py-4">No records found for international employees for this period.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-800 text-slate-300 uppercase text-xs">
                                <tr>
                                    <th class="p-2">#</th>
                                    <th class="p-2">Name</th>
                                    <th class="p-2">Passport No</th>
                                    <th class="p-2 text-right">Basic</th>
                                    <th class="p-2 text-right">Overtime</th>
                                    <th class="p-2 text-right">Sun/PH OT</th>
                                    <th class="p-2 text-right">Allowance</th>
                                    <th class="p-2 text-right">Gross Pay</th>
                                    <th class="p-2 text-right">Deduction</th>
                                    <th class="p-2 text-right">Net Pay</th>
                                    <th class="p-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($foreign_data as $i => $row): ?>
                                    <tr class="hover:bg-slate-700/40 border-b border-slate-700">
                                        <td class="p-2"><?= $i + 1 ?></td>
                                        <td class="p-2"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                        <td class="p-2"><?= htmlspecialchars($row['passport_no'] ?? '') ?></td>
                                        <td class="p-2 text-right"><?= number_format((float)$row['basic'], 2) ?></td>
                                        <td class="p-2 text-right"><?= number_format((float)$row['overtime_rm'] + (float)($row['overtime_12hr_rm'] ?? 0), 2) ?></td>
                                        <td class="p-2 text-right"><?= number_format((float)$row['sunday'] + (float)$row['public_holiday'] + (float)($row['sunday_ph_ot'] ?? 0), 2) ?>
                                        </td>
                                        <td class="p-2 text-right">
                                            <?= number_format((float)($row['special_allowance'] ?? 0) + (float)($row['back_pay'] ?? 0) + (float)($row['night_shift_allowance'] ?? 0), 2) ?></td>
                                        <td class="p-2 text-right font-medium text-sky-400">
                                            <?= number_format((float)$row['earnings_total'], 2) ?></td>
                                        <td class="p-2 text-right font-medium text-rose-400">
                                            <?= number_format((float)$row['deductions_total'], 2) ?></td>
                                        <td class="p-2 text-right font-bold text-emerald-400">
                                            <?= number_format((float)$row['net_pay'], 2) ?></td>
                                        <td class="p-2">
                                            <a href="payroll.php?prefill=1&employee_id=<?= urlencode((string)$row['employee_id']) ?>&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>&basic=<?= urlencode((string)$row['basic']) ?>&overtime_hours=<?= urlencode((string)($row['overtime_hours'] ?? '0')) ?>&overtime_rate=<?= urlencode((string)($row['overtime_rate'] ?? '0')) ?>&overtime_rm=<?= urlencode((string)($row['overtime_rm'] ?? '0')) ?>&overtime_12hr_hours=<?= urlencode((string)($row['overtime_12hr_hours'] ?? '0')) ?>&overtime_rate_12hr=<?= urlencode((string)($row['overtime_rate_12hr'] ?? '0')) ?>&overtime_12hr_rm=<?= urlencode((string)($row['overtime_12hr_rm'] ?? '0')) ?>&sunday_days=<?= urlencode((string)$row['sunday_days']) ?>&sunday_rate=<?= urlencode((string)$row['sunday_rate']) ?>&sunday=<?= urlencode((string)$row['sunday']) ?>&ph_days=<?= urlencode((string)$row['ph_days']) ?>&ph_rate=<?= urlencode((string)$row['ph_rate']) ?>&public_holiday=<?= urlencode((string)$row['public_holiday']) ?>&fixed_allowance=<?= urlencode((string)$row['fixed_allowance']) ?>&special_allowance=<?= urlencode((string)$row['special_allowance']) ?>&deductions_total=<?= urlencode((string)$row['deductions_total']) ?>&net_pay=<?= urlencode((string)$row['net_pay']) ?>&working_days_8hr=<?= urlencode((string)$row['working_days_8hr']) ?>&rate_8hr=<?= urlencode((string)$row['rate_8hr']) ?>&working_days_12hr=<?= urlencode((string)$row['working_days_12hr']) ?>&rate_12hr=<?= urlencode((string)$row['rate_12hr']) ?>&full_basic=<?= urlencode((string)$row['full_basics']) ?>"
                                               class="text-indigo-400 hover:underline">Go to Payroll</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-800 font-bold">
                                <tr>
                                    <td colspan="3" class="p-2 text-center">GRAND TOTAL</td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['basic'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['overtime_rm'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['sun_ph_ot'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['allowance'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['earnings_total'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['deductions_total'], 2) ?></td>
                                    <td class="p-2 text-right"><?= number_format((float)$intl_totals['net_pay'], 2) ?></td>
                                    <td class="p-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script>
        // Debug error message for "Go to Invoice" buttons
        document.addEventListener('DOMContentLoaded', () => {
            const invoiceButtons = document.querySelectorAll('.go-to-invoice');
            invoiceButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault(); // Prevent default navigation
                    const type = button.getAttribute('data-invoice-type');
                    const url = button.getAttribute('data-url');

                    // Extract parameters from URL for validation
                    const params = new URLSearchParams(url.split('?')[1]);
                    const totals = {
                        basic_amount: parseFloat(params.get('basic_amount')) || 0,
                        overtime_amount: parseFloat(params.get('overtime_amount')) || 0,
                        sunday_ph_amount: parseFloat(params.get('sunday_ph_amount')) || 0,
                        allowance_amount: parseFloat(params.get('allowance_amount')) || 0,
                        deduction_amount: parseFloat(params.get('deduction_amount')) || 0
                    };

                    // Check if totals are valid (non-zero for at least one field)
                    const hasData = totals.basic_amount > 0 || totals.overtime_amount > 0 ||
                                    totals.sunday_ph_amount > 0 || totals.allowance_amount > 0 ||
                                    totals.deduction_amount > 0;

                    if (!hasData) {
                        alert(`Error: No valid data available for ${type} employee invoice for ${params.get('month')} ${params.get('year')}. Please ensure salary data exists for this period.`);
                        return;
                    }

                    // If valid, navigate to the invoice page
                    window.location.href = url;
                });
            });
        });
    </script>
</body>

</html>
