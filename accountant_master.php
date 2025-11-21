<?php
/**************************************************************
 * Accountant Master View - v3.1 (Profit/Loss + Company Filter)
 * NOTE: Fetching method UNCHANGED:
 *   - Invoices:  SELECT * FROM invoices ...
 *   - Payslips:  SELECT p.*, e.name AS employee_name FROM payroll p JOIN employees e ...
 **************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php';

/* --- 1. AUTH --- */
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$userRole = $_SESSION['user']['role'] ?? 'guest';
$pdo = getPDO();

/* --- 2. FILTERS & SORT --- */
$filterMonth = $_GET['month'] ?? '';
$filterYear  = $_GET['year']  ?? '';
$sort        = $_GET['sort'] ?? '';
$sortDir     = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$isFiltered  = !empty($filterMonth) && !empty($filterYear);

/* NEW: Company filter (kept separate from original set) */
$filterCompany = trim($_GET['company'] ?? ''); // empty => all companies

/* --- 3. FETCH (UNCHANGED QUERIES) --- */
// INVOICES
try {
    $invoiceOrder = 'ORDER BY invoice_date DESC';
    if ($sort === 'invoice_date') $invoiceOrder = "ORDER BY invoice_date $sortDir";
    elseif ($sort === 'grand_total') $invoiceOrder = "ORDER BY grand_total $sortDir";
    $stmtInvoices  = $pdo->query("SELECT * FROM invoices $invoiceOrder");
    $allInvoices   = $stmtInvoices->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allInvoices = [];
    $invoiceError = "Could not fetch invoice data. Please ensure the 'invoices' table exists.";
}

// PAYSLIPS
try {
    $payslipOrder = "ORDER BY p.year DESC, STR_TO_DATE(CONCAT('01 ', p.month, ' ', p.year), '%d %M %Y') DESC";
    if ($sort === 'period') $payslipOrder = "ORDER BY p.year $sortDir, STR_TO_DATE(CONCAT('01 ', p.month, ' ', p.year), '%d %M %Y') $sortDir";
    elseif ($sort === 'net_pay') $payslipOrder = "ORDER BY p.net_pay $sortDir";
    $stmtPayslips  = $pdo->query("
        SELECT p.*, e.name as employee_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        $payslipOrder
    ");
    $allPayslips = $stmtPayslips->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allPayslips = [];
    $payslipError = "Could not fetch payslip data.";
}

/* --- 4. PHP FILTERS (Month/Year + Company) --- */
$filteredInvoices = [];
$filteredPayslips = [];

$byMonthYear = function($dateStr, $m, $y): bool {
    if (!$dateStr) return false;
    $ts = strtotime($dateStr);
    if (!$ts) return false;
    return (date('F', $ts) === $m && date('Y', $ts) === $y);
};

/** Build a flexible "company" detector WITHOUT changing SQL:
 *  - For invoices: try client_name → bill_to → company_name
 *  - For payslips: prefer p.company_name if it exists in p.* (since SQL is unchanged)
 */
$invoiceCompanyOf = function(array $inv): string {
    foreach (['client_name','bill_to','company_name'] as $k) {
        if (isset($inv[$k]) && trim((string)$inv[$k]) !== '') return trim((string)$inv[$k]);
    }
    return '';
};
$payslipCompanyOf = function(array $ps): string {
    // Only what p.* already has — cannot pull extra employee columns without changing SQL
    if (isset($ps['company_name']) && trim((string)$ps['company_name']) !== '') return trim((string)$ps['company_name']);
    // some schemas keep company in payroll as 'company' or 'employer'
    foreach (['company','employer'] as $alt) {
        if (isset($ps[$alt]) && trim((string)$ps[$alt]) !== '') return trim((string)$ps[$alt]);
    }
    return '';
};

foreach ($allInvoices as $inv) {
    $ok = true;
    if ($isFiltered) {
        $ok = $ok && $byMonthYear($inv['invoice_date'] ?? null, $filterMonth, $filterYear);
    }
    if ($filterCompany !== '') {
        $co = $invoiceCompanyOf($inv);
        $ok = $ok && strcasecmp($co, $filterCompany) === 0;
    }
    if ($ok) $filteredInvoices[] = $inv;
}

foreach ($allPayslips as $ps) {
    $ok = true;
    if ($isFiltered) {
        $ok = $ok && (($ps['month'] ?? '') === $filterMonth) && ((string)($ps['year'] ?? '') === $filterYear);
    }
    if ($filterCompany !== '') {
        $co = $payslipCompanyOf($ps);
        $ok = $ok && strcasecmp($co, $filterCompany) === 0;
    }
    if ($ok) $filteredPayslips[] = $ps;
}

/* --- 5. COUNTS --- */
$totalInvoiceCount     = count($allInvoices);
$filteredInvoiceCount  = count($filteredInvoices);
$totalPayslipCount     = count($allPayslips);
$filteredPayslipCount  = count($filteredPayslips);

/* --- 6. COMPANY DROPDOWN OPTIONS (from fetched rows only) --- */
$companySet = [];
foreach ($allInvoices as $inv) {
    $co = $invoiceCompanyOf($inv);
    if ($co !== '') $companySet[$co] = true;
}
foreach ($allPayslips as $ps) {
    $co = $payslipCompanyOf($ps);
    if ($co !== '') $companySet[$co] = true;
}
$companyList = array_keys($companySet);
natcasesort($companyList);

/* --- 7. MONTHLY AGG (for charts, all time) --- */
// Invoices monthly totals (grand_total fallback to total)
$invoiceMonthly = [];
foreach ($allInvoices as $invoice) {
    if (!empty($invoice['invoice_date'])) {
        $d = new DateTime($invoice['invoice_date']);
        $key = $d->format('Y-m');
        $label = $d->format('M Y');
        $amount = (float)($invoice['grand_total'] ?? ($invoice['total'] ?? 0));
        if (!isset($invoiceMonthly[$key])) $invoiceMonthly[$key] = ['label'=>$label, 'amount'=>0];
        $invoiceMonthly[$key]['amount'] += $amount;
    }
}
ksort($invoiceMonthly);
$invoiceLabels  = array_column($invoiceMonthly, 'label');
$invoiceAmounts = array_column($invoiceMonthly, 'amount');

// Payslips monthly totals (earnings_total)
$payslipMonthly = [];
foreach ($allPayslips as $payslip) {
    $m = $payslip['month'] ?? '';
    $y = $payslip['year'] ?? '';
    if ($m && $y) {
        $d = DateTime::createFromFormat('d F Y', "01 $m $y");
        if ($d) {
            $key = $d->format('Y-m');
            $label = $d->format('M Y');
            $amount = (float)($payslip['earnings_total'] ?? 0);
            if (!isset($payslipMonthly[$key])) $payslipMonthly[$key] = ['label'=>$label, 'amount'=>0];
            $payslipMonthly[$key]['amount'] += $amount;
        }
    }
}
ksort($payslipMonthly);
$payslipLabels  = array_column($payslipMonthly, 'label');
$payslipAmounts = array_column($payslipMonthly, 'amount');

/* --- DETAILED MONTHLY BREAKDOWN FOR INVOICES & PAYSLIPS --- */
$invoiceMonthlyDetailed = [];
foreach ($allInvoices as $inv) {
    if (empty($inv['invoice_date'])) continue;
    $d = new DateTime($inv['invoice_date']);
    $key = $d->format('Y-m');
    $label = $d->format('M Y');
    if (!isset($invoiceMonthlyDetailed[$key])) {
        $invoiceMonthlyDetailed[$key] = ['label'=>$label,'basic'=>0,'overtime'=>0,'sunday_ph'=>0,'transport'=>0,'allowance'=>0,'total'=>0,'deduction'=>0,'grand'=>0];
    }
    $invoiceMonthlyDetailed[$key]['basic']     += (float)($inv['basic_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['overtime']  += (float)($inv['overtime_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['sunday_ph'] += (float)($inv['sunday_ph_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['transport'] += (float)($inv['transport_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['allowance'] += (float)($inv['allowance_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['total']     += (float)($inv['total_amount'] ?? ($inv['total'] ?? 0));
    $invoiceMonthlyDetailed[$key]['deduction'] += (float)($inv['deduction_amount'] ?? 0);
    $invoiceMonthlyDetailed[$key]['grand']     += (float)($inv['grand_total'] ?? ($inv['total'] ?? 0));
}
ksort($invoiceMonthlyDetailed);

$payslipMonthlyDetailed = [];
foreach ($allPayslips as $ps) {
    $m = $ps['month'] ?? '';
    $y = $ps['year'] ?? '';
    if (!$m || !$y) continue;
    $d = DateTime::createFromFormat('d F Y', "01 $m $y");
    if (!$d) continue;
    $key = $d->format('Y-m');
    $label = $d->format('M Y');
    if (!isset($payslipMonthlyDetailed[$key])) {
        $payslipMonthlyDetailed[$key] = ['label'=>$label,'basic'=>0,'overtime'=>0,'sunday_ph'=>0,'transport'=>0,'allowance'=>0,'total'=>0,'deduction'=>0,'grand'=>0];
    }
    $payslipMonthlyDetailed[$key]['basic']     += (float)($ps['basic'] ?? 0);
    // overtime includes both 9hr and 12hr OT stored as separate columns
    $payslipMonthlyDetailed[$key]['overtime']  += (float)($ps['overtime_rm'] ?? 0) + (float)($ps['overtime_12hr_rm'] ?? 0);
    // sunday/ph ot may be stored as sunday, public_holiday and sunday_ph_ot
    $payslipMonthlyDetailed[$key]['sunday_ph'] += (float)($ps['sunday'] ?? 0) + (float)($ps['public_holiday'] ?? 0) + (float)($ps['sunday_ph_ot'] ?? 0);
    // transport may not exist on payslip — keep as 0 if missing
    $payslipMonthlyDetailed[$key]['transport'] += (float)($ps['transport'] ?? 0);
    $payslipMonthlyDetailed[$key]['allowance'] += (float)($ps['fixed_allowance'] ?? 0) + (float)($ps['special_allowance'] ?? 0) + (float)($ps['back_pay'] ?? 0) + (float)($ps['night_shift_allowance'] ?? 0);
    $payslipMonthlyDetailed[$key]['total']     += (float)($ps['earnings_total'] ?? 0);
    $payslipMonthlyDetailed[$key]['deduction'] += (float)($ps['deductions_total'] ?? 0);
    $payslipMonthlyDetailed[$key]['grand']     += (float)($ps['net_pay'] ?? 0);
}
ksort($payslipMonthlyDetailed);

// Aggregate overall totals for display headers
$invoiceMonthlyAggregate = ['basic'=>0,'overtime'=>0,'sunday_ph'=>0,'transport'=>0,'allowance'=>0,'total'=>0,'deduction'=>0,'grand'=>0];
foreach ($invoiceMonthlyDetailed as $km) {
    foreach ($invoiceMonthlyAggregate as $k => $_) $invoiceMonthlyAggregate[$k] += (float)$km[$k];
}
$payslipMonthlyAggregate = ['basic'=>0,'overtime'=>0,'sunday_ph'=>0,'transport'=>0,'allowance'=>0,'total'=>0,'deduction'=>0,'grand'=>0];
foreach ($payslipMonthlyDetailed as $km) {
    foreach ($payslipMonthlyAggregate as $k => $_) $payslipMonthlyAggregate[$k] += (float)$km[$k];
}

/* --- 8. PROFIT / LOSS for CURRENT FILTER SELECTION --- */
$incomeTotal  = 0.0; // invoices income
foreach ($filteredInvoices as $inv) {
    $incomeTotal += (float)($inv['grand_total'] ?? ($inv['total'] ?? 0));
}
$expenseTotal = 0.0; // payslips expense (gross)
foreach ($filteredPayslips as $ps) {
    $expenseTotal += (float)($ps['earnings_total'] ?? 0);
}
$netProfit = $incomeTotal - $expenseTotal;

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rangeBits = [];
if ($filterMonth && $filterYear) $rangeBits[] = "$filterMonth $filterYear";
$rangeBits[] = $filterCompany ? "Company: $filterCompany" : "All Companies";
$rangeLabel = implode(' • ', $rangeBits);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <meta charset="UTF-8" />
    <title>Accountant Master View</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
<header class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-20">
    <h1 class="text-2xl font-bold flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h3m-3-10h.01M9 17h.01M9 14h.01M12 7a1 1 0 110-2h.01a1 1 0 110 2H12zM9 10a1 1 0 110-2h.01a1 1 0 110 2H9zm3 4a1 1 0 110-2h.01a1 1 0 110 2H12zm3 3a1 1 0 110-2h.01a1 1 0 110 2H15zM6 21a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2v14a2 2 0 01-2 2H6z" /></svg>
        <span>Accountant Master View</span>
    </h1>
    <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        <span>Dashboard</span>
    </a>
</header>

<main class="max-w-screen-2xl mx-auto px-6 py-10 space-y-8">
    <!-- Filters -->
    <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border border-slate-700/50">
        <form method="GET" class="grid md:grid-cols-8 gap-4 items-end">
            <div>
                <label class="block text-sm text-slate-300 mb-1">Month</label>
                <select name="month" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                    <option value="">All Months</option>
                    <?php $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                    foreach ($months as $m): ?>
                        <option value="<?= h($m) ?>" <?= ($filterMonth===$m)?'selected':''; ?>><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">Year</label>
                <input type="number" name="year" placeholder="e.g. <?= date('Y') ?>" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= h($filterYear) ?>">
            </div>

            <!-- NEW: Company -->
            <div class="md:col-span-3">
                <label class="block text-sm text-slate-300 mb-1">Company</label>
                <select name="company" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                    <option value="">All Companies</option>
                    <?php foreach ($companyList as $co): ?>
                        <option value="<?= h($co) ?>" <?= (strcasecmp($filterCompany,$co)===0?'selected':''); ?>><?= h($co) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-300 mb-1">Sort Invoices</label>
                <select name="sort" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                    <option value="">Default</option>
                    <option value="invoice_date" <?= $sort==='invoice_date'?'selected':''; ?>>Date</option>
                    <option value="grand_total"  <?= $sort==='grand_total'?'selected':'';  ?>>Grand Total</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">Sort Payslips</label>
                <select name="sort" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                    <option value="">Default</option>
                    <option value="period"  <?= $sort==='period'?'selected':'';  ?>>Period</option>
                    <option value="net_pay" <?= $sort==='net_pay'?'selected':''; ?>>Net Pay</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-300 mb-1">Direction</label>
                <select name="dir" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                    <option value="desc" <?= $sortDir==='desc'?'selected':''; ?>>Descending</option>
                    <option value="asc"  <?= $sortDir==='asc'?'selected':'';  ?>>Ascending</option>
                </select>
            </div>

            <div class="md:col-span-8 flex flex-wrap gap-3 pt-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 font-semibold">Apply Filter</button>
                <a href="accountant_master.php" class="px-4 py-2 rounded-lg bg-slate-600 hover:bg-slate-500 font-semibold">Clear Filter</a>
                <span class="ml-auto text-slate-300 text-sm flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-emerald-400" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zM9 11h6v2H9v-2z"/></svg>
                    <?= h($rangeLabel) ?>
                </span>
            </div>
        </form>
    </section>

    <!-- Detailed Monthly Summaries removed from here and moved below the tables as a listed format -->

    <!-- KPI: Income / Expense / Net -->
    <section class="grid md:grid-cols-3 gap-6">
        <div class="bg-white/10 p-5 rounded-2xl border border-white/10">
            <p class="text-sm text-slate-300">Income (Invoices)</p>
            <p class="text-3xl font-extrabold mt-1 text-sky-400"><?= number_format($incomeTotal, 2) ?></p>
        </div>
        <div class="bg-white/10 p-5 rounded-2xl border border-white/10">
            <p class="text-sm text-slate-300">Expense (Payslips Gross)</p>
            <p class="text-3xl font-extrabold mt-1 text-rose-400"><?= number_format($expenseTotal, 2) ?></p>
        </div>
        <div class="bg-white/10 p-5 rounded-2xl border border-white/10">
            <p class="text-sm text-slate-300">Net (Profit/Loss)</p>
            <?php $netColor = $netProfit>=0?'text-emerald-400':'text-rose-400'; $netLabel = $netProfit>=0?'Profit':'Loss'; ?>
            <p class="text-3xl font-extrabold mt-1 <?= $netColor ?>"><?= number_format($netProfit, 2) ?> <span class="text-sm font-semibold text-slate-300">(<?= $netLabel ?>)</span></p>
        </div>
    </section>

    <!-- Charts -->
    <div class="grid grid-cols-1 gap-8">
        <div class="space-y-6">
            <div class="bg-white/5 p-4 rounded-xl border border-white/10">
                <canvas id="summaryChart" height="220"></canvas>
            </div>
        </div>
    </div>

    <!-- Tables -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Invoices -->
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white/5 p-4 rounded-xl border border-white/10"><p class="text-sm text-slate-400">Total Invoices (All Time)</p><p class="text-2xl font-bold mt-1"><?= $totalInvoiceCount ?></p></div>
                <div class="bg-white/5 p-4 rounded-xl border border-white/10"><p class="text-sm text-slate-400">Invoices (Filter)</p><p class="text-2xl font-bold mt-1 text-sky-400"><?= $filteredInvoiceCount ?></p></div>
            </div>
            <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
                <h2 class="text-xl font-bold mb-4">Invoices</h2>
                <div class="overflow-y-auto max-h-[60vh]">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-800 text-slate-300 uppercase text-xs sticky top-0">
                            <tr><th class="p-3">#</th><th class="p-3">Company</th><th class="p-3">Date</th><th class="p-3 text-right">Grand Total</th></tr>
                        </thead>
                        <tbody>
                        <?php if (isset($invoiceError)): ?>
                            <tr><td colspan="4" class="p-6 text-center text-red-400"><?= h($invoiceError) ?></td></tr>
                        <?php elseif (empty($filteredInvoices)): ?>
                            <tr><td colspan="4" class="p-6 text-center text-slate-400">No invoices found.</td></tr>
                        <?php else: 
                            $invoiceGrossTotal = 0;
                            foreach ($filteredInvoices as $invoice):
                            $co = $invoiceCompanyOf($invoice);
                            $grand = (float)($invoice['grand_total'] ?? ($invoice['total'] ?? 0));
                            $invoiceGrossTotal += $grand;
                        ?>
                            <tr class="hover:bg-slate-700/40 border-b border-slate-700 cursor-pointer clickable-row"
                                data-type="invoice"
                                data-details='<?= htmlspecialchars(json_encode($invoice), ENT_QUOTES, "UTF-8") ?>'>
                                <td class="p-3 font-mono"><?= h((string)$invoice['id']) ?></td>
                                <td class="p-3"><?= h($co) ?></td>
                                <td class="p-3"><?= isset($invoice['invoice_date']) && $invoice['invoice_date'] ? date('d M Y', strtotime($invoice['invoice_date'])) : '' ?></td>
                                <td class="p-3 text-right font-medium"><?= number_format($grand, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Gross Total Row -->
                        <tr class="bg-sky-500/20 border-t-2 border-sky-400 font-bold">
                            <td class="p-3 font-bold">TOTAL</td>
                            <td class="p-3 text-center font-bold"><?= $filteredInvoiceCount ?> Invoice(s)</td>
                            <td class="p-3"></td>
                            <td class="p-3 text-right font-bold text-sky-400 text-lg"><?= number_format($invoiceGrossTotal, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Payslips -->
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white/5 p-4 rounded-xl border border-white/10"><p class="text-sm text-slate-400">Total Payslips (All Time)</p><p class="text-2xl font-bold mt-1"><?= $totalPayslipCount ?></p></div>
                <div class="bg-white/5 p-4 rounded-xl border border-white/10"><p class="text-sm text-slate-400">Payslips (Filter)</p><p class="text-2xl font-bold mt-1 text-emerald-400"><?= $filteredPayslipCount ?></p></div>
            </div>
            <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
                <h2 class="text-xl font-bold mb-4">Payslips</h2>
                <div class="overflow-y-auto max-h-[60vh]">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-800 text-slate-300 uppercase text-xs sticky top-0">
                            <tr><th class="p-3">#</th><th class="p-3">Employee</th><th class="p-3">Company</th><th class="p-3">Period</th><th class="p-3 text-right">Gross Pay</th><th class="p-3 text-right">Net Pay</th></tr>
                        </thead>
                        <tbody>
                        <?php if (isset($payslipError)): ?>
                            <tr><td colspan="6" class="p-6 text-center text-red-400"><?= h($payslipError) ?></td></tr>
                        <?php elseif (empty($filteredPayslips)): ?>
                            <tr><td colspan="6" class="p-6 text-center text-slate-400">No payslips found.</td></tr>
                        <?php else: 
                            $payslipGrossTotal = 0;
                            $payslipNetTotal = 0;
                            foreach ($filteredPayslips as $payslip):
                            $co = $payslipCompanyOf($payslip);
                            $grossPay = (float)($payslip['earnings_total'] ?? 0);
                            $netPay = (float)($payslip['net_pay'] ?? 0);
                            $payslipGrossTotal += $grossPay;
                            $payslipNetTotal += $netPay;
                        ?>
                            <tr class="hover:bg-slate-700/40 border-b border-slate-700 cursor-pointer clickable-row"
                                data-type="payslip"
                                data-details='<?= htmlspecialchars(json_encode($payslip), ENT_QUOTES, "UTF-8") ?>'>
                                <td class="p-3 font-mono"><?= h((string)$payslip['id']) ?></td>
                                <td class="p-3"><?= h($payslip['employee_name']) ?></td>
                                <td class="p-3"><?= h($co) ?></td>
                                <td class="p-3"><?= h(($payslip['month'] ?? '').' '.(string)($payslip['year'] ?? '')) ?></td>
                                <td class="p-3 text-right font-medium text-sky-400"><?= number_format($grossPay, 2) ?></td>
                                <td class="p-3 text-right font-bold text-emerald-400"><?= number_format($netPay, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Gross Total Row -->
                        <tr class="bg-emerald-500/20 border-t-2 border-emerald-400 font-bold">
                            <td class="p-3 font-bold">TOTAL</td>
                            <td class="p-3 text-center font-bold"><?= $filteredPayslipCount ?> Payslip(s)</td>
                            <td class="p-3"></td>
                            <td class="p-3"></td>
                            <td class="p-3 text-right font-bold text-sky-400 text-lg"><?= number_format($payslipGrossTotal, 2) ?></td>
                            <td class="p-3 text-right font-bold text-emerald-400 text-lg"><?= number_format($payslipNetTotal, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>

<!-- MODAL -->
    <!-- Listed-format Monthly Summaries (compact, collapsible) -->
    <section class="max-w-7xl mx-auto px-6 py-6 grid md:grid-cols-2 gap-6">
        <!-- Invoices (Claimed) Card -->
        <div class="bg-white/5 p-4 rounded-2xl border border-slate-800">
            <h4 class="text-lg font-bold text-sky-400">Invoices (Claimed)</h4>
            <div class="mt-3 grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL BASIC AMOUNT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($invoiceMonthlyAggregate['basic'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL OVERTIME</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($invoiceMonthlyAggregate['overtime'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL SUNDAY / PH OT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($invoiceMonthlyAggregate['sunday_ph'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TRANSPORT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($invoiceMonthlyAggregate['transport'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">ALLOWANCE</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($invoiceMonthlyAggregate['allowance'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL</div>
                    <div class="text-2xl font-bold text-sky-400">RM <?= number_format($invoiceMonthlyAggregate['total'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">DEDUCTION</div>
                    <div class="text-2xl font-bold text-rose-400">RM <?= number_format($invoiceMonthlyAggregate['deduction'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">GRAND TOTAL</div>
                    <div class="text-2xl font-bold text-emerald-400">RM <?= number_format($invoiceMonthlyAggregate['grand'],2) ?></div>
                </div>
            </div>
            <div class="mt-4">
                <details class="text-sm text-slate-300">
                    <summary class="cursor-pointer">Show monthly details</summary>
                    <div class="mt-3 space-y-3">
                        <?php foreach ($invoiceMonthlyDetailed as $m): ?>
                            <div class="p-3 rounded-lg bg-slate-900/50 border border-slate-700">
                                <div class="flex justify-between text-sm text-slate-400"><div><?= h($m['label']) ?></div><div class="font-medium text-white">RM <?= number_format($m['total'],2) ?></div></div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-300">
                                    <div>Basic: RM <?= number_format($m['basic'],2) ?></div>
                                    <div>Overtime: RM <?= number_format($m['overtime'],2) ?></div>
                                    <div>Sun/PH: RM <?= number_format($m['sunday_ph'],2) ?></div>
                                    <div>Transport: RM <?= number_format($m['transport'],2) ?></div>
                                    <div>Allowance: RM <?= number_format($m['allowance'],2) ?></div>
                                    <div>Deduction: RM <?= number_format($m['deduction'],2) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>

        <!-- Payslips Card -->
        <div class="bg-white/5 p-4 rounded-2xl border border-slate-800">
            <h4 class="text-lg font-bold text-emerald-400">Salary Slips (Payroll - Spent)</h4>
            <div class="mt-3 grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL BASIC AMOUNT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($payslipMonthlyAggregate['basic'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL OVERTIME</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($payslipMonthlyAggregate['overtime'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL SUNDAY / PH OT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($payslipMonthlyAggregate['sunday_ph'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TRANSPORT</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($payslipMonthlyAggregate['transport'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">ALLOWANCE</div>
                    <div class="text-2xl font-bold text-white">RM <?= number_format($payslipMonthlyAggregate['allowance'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">TOTAL</div>
                    <div class="text-2xl font-bold text-sky-400">RM <?= number_format($payslipMonthlyAggregate['total'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">DEDUCTION</div>
                    <div class="text-2xl font-bold text-rose-400">RM <?= number_format($payslipMonthlyAggregate['deduction'],2) ?></div>
                </div>
                <div class="space-y-2">
                    <div class="text-sm text-slate-400">GRAND TOTAL</div>
                    <div class="text-2xl font-bold text-emerald-400">RM <?= number_format($payslipMonthlyAggregate['grand'],2) ?></div>
                </div>
            </div>
            <div class="mt-4">
                <details class="text-sm text-slate-300">
                    <summary class="cursor-pointer">Show monthly details</summary>
                    <div class="mt-3 space-y-3">
                        <?php foreach ($payslipMonthlyDetailed as $m): ?>
                            <div class="p-3 rounded-lg bg-slate-900/50 border border-slate-700">
                                <div class="flex justify-between text-sm text-slate-400"><div><?= h($m['label']) ?></div><div class="font-medium text-white">RM <?= number_format($m['total'],2) ?></div></div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-300">
                                    <div>Basic: RM <?= number_format($m['basic'],2) ?></div>
                                    <div>Overtime: RM <?= number_format($m['overtime'],2) ?></div>
                                    <div>Sun/PH: RM <?= number_format($m['sunday_ph'],2) ?></div>
                                    <div>Transport: RM <?= number_format($m['transport'],2) ?></div>
                                    <div>Allowance: RM <?= number_format($m['allowance'],2) ?></div>
                                    <div>Deduction: RM <?= number_format($m['deduction'],2) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>
    </section>
<div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-70 backdrop-blur-sm flex items-center justify-center p-4 hidden z-50">
    <div id="modalContent" class="bg-slate-800 rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] flex flex-col border border-slate-700">
        <header class="p-4 flex justify-between items-center border-b border-slate-700">
            <h3 id="modalTitle" class="text-xl font-bold text-sky-400">Details</h3>
            <button id="modalClose" class="text-slate-400 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </header>
        <main id="modalBody" class="p-6 overflow-y-auto"></main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('detailsModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    const modalClose = document.getElementById('modalClose');
    const openModal = (title, html) => { modalTitle.textContent = title; modalBody.innerHTML = html; modal.classList.remove('hidden'); };
    const closeModal = () => modal.classList.add('hidden');
    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    const money = (v) => (parseFloat(v||0)).toFixed(2);
    const row = (label, value, color='text-white') => `
        <div class="grid grid-cols-3 gap-4 py-2 border-b border-slate-700/50">
            <dt class="text-sm font-medium text-slate-400 col-span-1">${label}</dt>
            <dd class="text-sm ${color} col-span-2">${value || '—'}</dd>
        </div>`;

    const invoiceDetails = (d) => {
        const grand = d.grand_total ?? d.total ?? 0;
        const company = d.client_name || d.bill_to || d.company_name || '';
        const dateText = d.invoice_date ? new Date(d.invoice_date).toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'}) : '';
        return `<dl>
            ${row('Invoice ID', d.id)}
            ${row('Reference No', d.ref_no)}
            ${row('Company', company)}
            ${row('Date', dateText)}
            <div class="mt-4 pt-4 border-t border-slate-600">
                ${row('Sub Total', '<strong>'+money(d.total)+'</strong>')}
                ${row('Deduction', '-'+money(d.deduction), 'text-rose-400')}
                ${row('Grand Total', '<strong class="text-xl text-emerald-400">'+money(grand)+'</strong>')}
            </div>
        </dl>`;
    };

    const payslipDetails = (d) => {
        const sunday = parseFloat(d.sunday||0);
        const ph = parseFloat(d.public_holiday||0);
        const allowances = parseFloat(d.fixed_allowance||0) + parseFloat(d.special_allowance||0);
        const hostel = parseFloat(d.hostel_fee||0) + parseFloat(d.utility_charges||0);
        const advmed = parseFloat(d.advance||0) + parseFloat(d.medical||0);
        return `<div class="grid md:grid-cols-2 gap-x-8 gap-y-4">
            <div class="space-y-2">
                <h4 class="font-bold text-emerald-400 border-b border-emerald-500/30 pb-1 mb-2">Earnings</h4>
                <dl>
                    ${row('Basic Salary', money(d.basic))}
                    ${row('Overtime ('+(d.overtime_hours || 0)+' hrs)', money(d.overtime_rm))}
                    ${row('Sunday/PH OT', money(sunday + ph))}
                    ${row('Allowances', money(allowances))}
                    ${row('Other Claims', money(d.oth_claim))}
                </dl>
                <div class="pt-2 mt-2 border-t border-slate-600">
                    ${row('<strong>Gross Pay</strong>', '<strong>'+money(d.earnings_total)+'</strong>', 'text-lg text-sky-400')}
                </div>
            </div>
            <div class="space-y-2">
                <h4 class="font-bold text-rose-400 border-b border-rose-500/30 pb-1 mb-2">Deductions</h4>
                <dl>
                    ${row('EPF', money(d.epf_deduction))}
                    ${row('SOCSO', money(d.socso_deduction))}
                    ${row('SIP (EIS)', money(d.sip_deduction))}
                    ${row('Hostel/Utilities', money(hostel))}
                    ${row('Advance/Medical', money(advmed))}
                    ${row('Other Deductions', money(d.other_deductions))}
                </dl>
                <div class="pt-2 mt-2 border-t border-slate-600">
                    ${row('<strong>Total Deductions</strong>', '<strong>-'+money(d.deductions_total)+'</strong>', 'text-lg text-rose-400')}
                </div>
            </div>
        </div>
        <div class="mt-6 pt-4 border-t border-slate-600">
            ${row('<strong>Net Pay</strong>', '<strong class="text-2xl text-emerald-400">'+money(d.net_pay)+'</strong>')}
        </div>`;
    };

    document.querySelectorAll('.clickable-row').forEach(rowEl => {
        rowEl.addEventListener('click', () => {
            const type = rowEl.dataset.type;
            const details = JSON.parse(rowEl.dataset.details);
            if (type === 'invoice') {
                const title = `Invoice Details: #${details.id}`;
                return openModal(title, invoiceDetails(details));
            }
            if (type === 'payslip') {
                const title = `Payslip Details: ${details.employee_name || ''}`;
                return openModal(title, payslipDetails(details));
            }
        });
    });

    // Summary chart values from PHP
    const incomeTotal  = <?= json_encode($incomeTotal) ?>;
    const expenseTotal = <?= json_encode($expenseTotal) ?>;
    const netTotal     = <?= json_encode($netProfit) ?>;

    // Charts
    const summaryCtx = document.getElementById('summaryChart').getContext('2d');
    new Chart(summaryCtx, {
        type: 'bar',
        data: { labels: ['Income', 'Expense', 'Net'], datasets: [{ label: 'Amount', data: [incomeTotal, expenseTotal, netTotal] }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
});
</script>
</body>
</html>
