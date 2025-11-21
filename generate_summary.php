<?php
/**************************************************************
 * Salary Summary Generation - v3.2 (Bugfix)
 * - Corrected the column vs. value count mismatch in the SQL INSERT statement.
 **************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php';

// --- INITIALIZATION AND AUTHENTICATION ---
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$pdo = getPDO();
$notification = $_SESSION['notification'] ?? null;
unset($_SESSION['notification']);

// --- HANDLE SALARY SUMMARY FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_summary'])) {
    $f = fn($k, $d = '') => trim((string) ($_POST[$k] ?? $d));
    $n = fn($k, $d = 0) => isset($_POST[$k]) && $_POST[$k] !== '' ? (float) $_POST[$k] : (float) $d;
    $i = fn($k, $d = 0) => (int) ($_POST[$k] ?? $d);

    $company_id = $i('company_id');
    $employee_id = $i('employee_id');
    $month = ucfirst($f('month'));
    $year = $i('year', (int) date('Y'));

    if (empty($company_id) || empty($employee_id) || empty($month)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Please select a company, employee, and month.'];
        header("Location: generate_summary.php");
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM salary_summaries WHERE employee_id = ? AND month = ? AND year = ?");
    $stmt->execute([$employee_id, $month, $year]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error: A summary for this employee for the selected period already exists.'];
        header("Location: generate_summary.php");
        exit;
    }

    // --- FULL CALCULATION LOGIC ---
    $overtime_rm = round($n('overtime_hours') * $n('overtime_rate'), 2);
    $overtime_12hr_rm = round($n('overtime_12hr_hours') * $n('overtime_rate_12hr'), 2);
    $sunday = round($i('sunday_days') * $n('sunday_rate'), 2);
    $public_holiday = round($i('ph_days') * $n('ph_rate'), 2);
    $sunday_ph_ot = round($n('sunday_ph_ot_hours') * $n('sunday_ph_ot_rate'), 2);
    
    // Calculate NPL deduction: NPL Days × Daily Rate (calculate based on basic daily rate)
    $basic_amount = $n('basic');
    $working_days_total = $i('working_days_8hr') + $i('working_days_12hr');
    $daily_rate = $working_days_total > 0 ? $basic_amount / $working_days_total : 0;
    $npl_deduction = round($i('npl_days') * $daily_rate, 2);
    
    // NOTE: Consultant fee (fixed_allowance) is treated as an earning (not a deduction)
    $earnings_total = $n('basic') + $overtime_rm + $overtime_12hr_rm + $sunday + $public_holiday + $sunday_ph_ot +
        $n('back_pay') + $n('special_allowance') + $n('night_shift_allowance') + $n('fixed_allowance');
    
    // NPL is a deduction (loss of pay), using calculated npl_deduction instead of manual npl_days_amount
    $deductions_total = $n('epf_deduction') + $n('socso_deduction') + $n('sip_deduction') +
        $n('hostel_fee') + $n('utility_charges') + $n('other_deductions') +
        $n('advance') + $n('medical') + $npl_deduction + $n('default_deduction') + $n('insurance');
    
    $net_pay = max(0, $earnings_total - $deductions_total);

    // --- Database Insertion ---
    try {
        // Fetch company name and defaults to provide server-side fallbacks
        $stmt_company = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt_company->execute([$company_id]);
        $company = $stmt_company->fetch(PDO::FETCH_ASSOC) ?: [];
        $company_name = $company['name'] ?? '';

        // Server-side fallback: if certain summary inputs are missing, use company defaults
        $companyFieldMap = [
            'rate_8hr' => 'default_rate_8hr',
            'rate_12hr' => 'default_rate_12hr',
            'sunday_rate' => 'default_sunday_rate',
            'ph_rate' => 'default_ph_rate',
            'hostel_fee' => 'default_hostel_fee',
            'utility_charges' => 'default_utility_charges',
            // overrides / non-equal names
            'overtime_rate' => 'default_ot_rate_9hr',
            'overtime_rate_12hr' => 'default_ot_rate_12hr',
            'sunday_ph_ot_rate' => 'default_sunday_ph_ot_rate',
            'fixed_allowance' => 'default_consultant_fee_per_head',
            'back_pay' => 'default_back_pay',
            'special_allowance' => 'default_special_allowance',
            'night_shift_allowance' => 'default_night_shift_allowance',
            'default_deduction' => 'default_deduction',
            'insurance' => 'default_insurance'
        ];

        foreach ($companyFieldMap as $postKey => $companyKey) {
            if ((!isset($_POST[$postKey]) || $_POST[$postKey] === '') && isset($company[$companyKey])) {
                // cast to string so later $n/$i closures pick it up correctly
                $_POST[$postKey] = (string) $company[$companyKey];
            }
        }

        $params = [
            $company_id, $company_name, $employee_id, $month, $year, 'MYR',
            $n('basic'), $n('overtime_hours'), $n('overtime_rate'), $overtime_rm,
            $n('overtime_12hr_hours'), $n('overtime_rate_12hr'), $overtime_12hr_rm,
            $i('sunday_days'), $n('sunday_rate'), $sunday, $i('ph_days'), $n('ph_rate'), $public_holiday,
            $n('sunday_ph_ot_hours'), $n('sunday_ph_ot_rate'), $sunday_ph_ot,
            $n('fixed_allowance'), $n('back_pay'), $n('special_allowance'), $n('night_shift_allowance'),
            $n('epf_deduction'), $n('socso_deduction'), $n('sip_deduction'),
            $n('hostel_fee'), $n('utility_charges'), $n('other_deductions'), $n('default_deduction'),
            $n('advance'), $n('medical'), $i('npl_days'), $npl_deduction, $n('insurance'),
            $i('working_days_8hr'), $n('rate_8hr'), $i('working_days_12hr'), $n('rate_12hr'), $n('full_basics'),
            $earnings_total, $deductions_total, $net_pay
        ];
        // Validation: net pay must not exceed full basic reference
        $full_basics_val = $n('full_basics');
        if ($net_pay > $full_basics_val) {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Validation error: Net Pay (RM ' . number_format($net_pay,2) . ") exceeds Full Basic reference (RM " . number_format($full_basics_val,2) . '). Please correct the inputs.'];
            header("Location: generate_summary.php");
            exit;
        }
        // Build SQL with exact number of placeholders based on $params
        $columns = [
            'company_id','company_name','employee_id','month','year','currency',
            'basic','overtime_hours','overtime_rate','overtime_rm',
            'overtime_12hr_hours','overtime_rate_12hr','overtime_12hr_rm',
            'sunday_days','sunday_rate','sunday','ph_days','ph_rate','public_holiday',
            'sunday_ph_ot_hours','sunday_ph_ot_rate','sunday_ph_ot',
            'fixed_allowance','back_pay','special_allowance','night_shift_allowance',
            'epf_deduction','socso_deduction','sip_deduction',
            'hostel_fee','utility_charges','other_deductions','default_deduction',
            'advance','medical','npl_days','npl_days_amount','insurance',
            'working_days_8hr','rate_8hr','working_days_12hr','rate_12hr','full_basics',
            'earnings_total','deductions_total','net_pay'
        ];

        $placeholders = implode(',', array_fill(0, count($params), '?'));
        $sql = "INSERT INTO salary_summaries (" . implode(',', $columns) . ") VALUES (" . $placeholders . ")";

        // Defensive check: ensure the number of placeholders matches the number of params
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount !== count($params)) {
            // log both counts for debugging
            error_log("[generate_summary] placeholderCount={$placeholderCount}, paramsCount=" . count($params));
            $_SESSION['notification'] = ['type' => 'error', 'message' => "Internal error: SQL placeholder count ({$placeholderCount}) does not match provided parameters (" . count($params) . "). Please contact the administrator."];
            header("Location: generate_summary.php");
            exit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_SESSION['notification'] = ['type' => 'success', 'message' => 'Salary summary generated successfully.'];
    } catch (PDOException $e) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }

    header("Location: generate_summary.php");
    exit;
}

// --- DATA FETCHING FOR THE PAGE ---
try {
    $companies = $pdo->query("SELECT * FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $employees = $pdo->query("SELECT id, name, company_id FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: Could not fetch page data. " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<!-- The entire HTML and JavaScript part remains exactly the same as the previous version. -->
<!-- For brevity, I will not repeat it here, but you should use the full HTML from the previous response. -->
<!-- The only required change was in the PHP section above. -->
<head>
    <meta charset="UTF-8" />
    <title>Generate Salary Summary</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        select:disabled { background-color: #374151; cursor: not-allowed; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
    <header class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-20">
        <h1 class="text-2xl font-bold">Generate Salary Summary</h1>
         <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90">
            Dashboard
        </a>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-10">
        <?php if ($notification): ?>
            <div class="p-4 mb-6 <?= $notification['type'] == 'error' ? 'bg-red-500/20 text-red-300' : 'bg-emerald-500/20 text-emerald-300' ?> rounded-lg">
                <?= htmlspecialchars($notification['message']) ?>
            </div>
        <?php endif; ?>

        <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border border-slate-700/50">
            <form method="post" id="summaryForm">
                <input type="hidden" name="generate_summary" value="1">
                <div class="grid md:grid-cols-4 gap-4 mb-6">
                    <div>
                        <label class="block text-sm text-slate-300 mb-1">1. Select Company *</label>
                        <select name="company_id" id="company_select" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                            <option value="">-- Select --</option>
                            <?php foreach ($companies as $company) echo "<option value='{$company['id']}'>" . htmlspecialchars($company['name']) . "</option>"; ?>
                        </select>
                    </div>
                     <div>
                        <label class="block text-sm text-slate-300 mb-1">2. Select Employee *</label>
                        <select name="employee_id" id="employee_select" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" disabled>
                            <option value="">-- Waiting --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-300 mb-1">Month *</label>
                        <select name="month" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
                            <?php $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach ($months as $m) echo "<option value='$m' " . (date('F') == $m ? 'selected' : '') . ">$m</option>"; ?>
                        </select>
                    </div>
                     <div>
                        <label class="block text-sm text-slate-300 mb-1">Year *</label>
                        <input type="number" name="year" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= date('Y') ?>">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-8 border-t border-slate-700 pt-6">
                    <!-- Earnings Column -->
                    <div class="space-y-4">
                        <h3 class="font-semibold text-lg text-emerald-400">Earnings</h3>
                        <div class="grid grid-cols-2 gap-4">
                             <div><label class="text-xs text-slate-400">Working Days (9hr)</label><input type="number" name="working_days_8hr" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Rate (9hr)</label><input type="number" step="0.01" id="rate_8hr" name="rate_8hr" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">Working Days (12hr)</label><input type="number" name="working_days_12hr" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Rate (12hr)</label><input type="number" step="0.01" id="rate_12hr" name="rate_12hr" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div class="col-span-2"><label class="text-xs text-slate-400">Full Basic (Reference)</label><input type="number" step="0.01" name="full_basics" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <hr class="col-span-2 border-slate-700">
                            <div class="col-span-2"><label class="text-xs text-slate-400">Calculated Basic *</label><input type="number" step="0.01" name="basic" readonly class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500 font-bold" value="0.00"></div>
                            <hr class="col-span-2 border-slate-700">
                            <div><label class="text-xs text-slate-400">OT Hours (9hr)</label><input type="number" step="0.01" name="overtime_hours" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">OT Rate (9hr)</label><input type="number" step="0.01" name="overtime_rate" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">OT Hours (12hr)</label><input type="number" step="0.01" name="overtime_12hr_hours" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">OT Rate (12hr)</label><input type="number" step="0.01" id="overtime_rate_12hr" name="overtime_rate_12hr" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">Sunday Days</label><input type="number" name="sunday_days" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Sunday Rate</label><input type="number" step="0.01" id="sunday_rate" name="sunday_rate" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">PH Days</label><input type="number" name="ph_days" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">PH Rate</label><input type="number" step="0.01" id="ph_rate" name="ph_rate" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">Sunday/PH OT Hours</label><input type="number" step="0.01" name="sunday_ph_ot_hours" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Sunday/PH OT Rate</label><input type="number" step="0.01" id="sunday_ph_ot_rate" name="sunday_ph_ot_rate" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <hr class="col-span-2 border-slate-700">
                            <div><label class="text-xs text-slate-400">Consultant Fee</label><input type="number" step="0.01" name="fixed_allowance" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Back Pay</label><input type="number" step="0.01" name="back_pay" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Room Allowance</label><input type="number" step="0.01" id="hostel_fee" name="hostel_fee" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">Special Allowance</label><input type="number" step="0.01" name="special_allowance" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Night Shift Allowance</label><input type="number" step="0.01" id="night_shift_allowance" name="night_shift_allowance" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                        </div>
                    </div>
                    <!-- Deductions Column -->
                     <div class="space-y-4">
                        <h3 class="font-semibold text-lg text-rose-400">Deductions</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-xs text-slate-400">EPF</label><input type="number" step="0.01" name="epf_deduction" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">SOCSO</label><input type="number" step="0.01" name="socso_deduction" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">SIP (EIS)</label><input type="number" step="0.01" name="sip_deduction" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <hr class="col-span-2 border-slate-700">
                            <div><label class="text-xs text-slate-400">Utility Charges</label><input type="number" step="0.01" id="utility_charges" name="utility_charges" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <div><label class="text-xs text-slate-400">Other Deductions</label><input type="number" step="0.01" name="other_deductions" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Default Deduction</label><input type="number" step="0.01" id="default_deduction" name="default_deduction" class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500"></div>
                            <hr class="col-span-2 border-slate-700">
                            <div><label class="text-xs text-slate-400">Advance</label><input type="number" step="0.01" name="advance" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">Medical</label><input type="number" step="0.01" name="medical" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">NPL Days</label><input type="number" name="npl_days" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                            <div><label class="text-xs text-slate-400">NPL Amount (RM)</label><input type="number" step="0.01" name="npl_days_amount" readonly class="w-full p-2 mt-1 rounded-md bg-slate-700 border-slate-500 font-bold" value="0.00"></div>
                            <div class="col-span-2"><label class="text-xs text-slate-400">Insurance</label><input type="number" step="0.01" name="insurance" class="w-full p-2 mt-1 rounded-md bg-slate-800 border-slate-600"></div>
                        </div>
                    </div>
                </div>
                 <div class="mt-8 pt-6 border-t border-slate-600">
                     <div class="grid sm:grid-cols-3 gap-6 mb-6">
                        <div>
                            <label class="block text-sm text-slate-300 mb-1">Total Earnings</label>
                            <input type="text" id="earnTotal" class="w-full p-2.5 rounded-lg bg-slate-700/50 border border-slate-600 font-medium text-emerald-400" readonly value="0.00">
                        </div>
                        <div>
                            <label class="block text-sm text-slate-300 mb-1">Total Deductions</label>
                            <input type="text" id="deductTotal" class="w-full p-2.5 rounded-lg bg-slate-700/50 border border-slate-600 font-medium text-rose-400" readonly value="0.00">
                        </div>
                        <div>
                            <label class="block text-sm text-slate-300 mb-1">Net Pay</label>
                            <input type="text" id="netPay" class="w-full p-2.5 rounded-lg bg-slate-700/50 border border-slate-600 font-bold" readonly value="0.00">
                        </div>
                    </div>
                     <button type="submit" class="w-full p-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:opacity-90 font-semibold text-base">Generate & Save Summary</button>
                </div>
            </form>
        </section>

        <div class="mt-8 text-center">
            <a href="monthly_summary.php" class="inline-block px-6 py-3 rounded-xl bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 font-semibold">
                View All Summaries & Generate Invoices
            </a>
        </div>
    </main>

    <script>
        // This script is now the correct, full-featured version from payroll.php
        document.addEventListener('DOMContentLoaded', function() {
            const companiesData = JSON.parse('<?= json_encode($companies, JSON_HEX_APOS) ?>');
            const employeesData = JSON.parse('<?= json_encode($employees, JSON_HEX_APOS) ?>');
            
            const summaryForm = document.getElementById('summaryForm');
            const companySelect = document.getElementById('company_select');
            const employeeSelect = document.getElementById('employee_select');
            const allNumberInputs = summaryForm.querySelectorAll('input[type="number"]');

            // Inputs we want to auto-populate when a company is selected.
            // Keys are the summary form field names (or IDs). Values are the DOM elements.
            const autoPopulateInputs = {
                rate_8hr: document.getElementById('rate_8hr'),
                rate_12hr: document.getElementById('rate_12hr'),
                sunday_rate: document.getElementById('sunday_rate'),
                ph_rate: document.getElementById('ph_rate'),
                hostel_fee: document.getElementById('hostel_fee'),
                utility_charges: document.getElementById('utility_charges'),
                overtime_rate_12hr: document.getElementById('overtime_rate_12hr'),
                sunday_ph_ot_rate: document.getElementById('sunday_ph_ot_rate'),
                night_shift_allowance: document.getElementById('night_shift_allowance'),
                default_deduction: document.getElementById('default_deduction'),
                // fields without IDs — we'll resolve them via querySelector by name when needed
                overtime_rate: summaryForm.querySelector('[name="overtime_rate"]'),
                fixed_allowance: summaryForm.querySelector('[name="fixed_allowance"]'),
                back_pay: summaryForm.querySelector('[name="back_pay"]'),
                special_allowance: summaryForm.querySelector('[name="special_allowance"]'),
                insurance: summaryForm.querySelector('[name="insurance"]')
            };

            // Some summary field names don't map 1:1 to company column names.
            // Provide explicit mapping: summaryField -> company column name (without the leading 'default_').
            const companyKeyOverrides = {
                overtime_rate: 'ot_rate_9hr', // company.default_ot_rate_9hr -> summary overtime_rate
                overtime_rate_12hr: 'ot_rate_12hr', // company.default_ot_rate_12hr -> summary overtime_rate_12hr
                sunday_ph_ot_rate: 'sunday_ph_ot_rate', // company.default_sunday_ph_ot_rate -> summary sunday_ph_ot_rate
                fixed_allowance: 'consultant_fee_per_head', // company.default_consultant_fee_per_head
                back_pay: 'back_pay', // company.default_back_pay -> summary back_pay
                special_allowance: 'special_allowance', // company.default_special_allowance
                night_shift_allowance: 'night_shift_allowance', // company.default_night_shift_allowance
                default_deduction: 'deduction' // company.default_deduction -> summary default_deduction
            };

            companySelect.addEventListener('change', function() {
                const companyId = this.value;
                const selectedCompany = companiesData.find(c => c.id == companyId);

                if (selectedCompany) {
                    Object.keys(autoPopulateInputs).forEach(key => {
                        const input = autoPopulateInputs[key];
                        // Determine the company column name. Use override map if present.
                        const companyFieldName = companyKeyOverrides[key] ? `default_${companyKeyOverrides[key]}` : `default_${key}`;
                        const val = selectedCompany[companyFieldName];
                        if (input) {
                            // If the element exists, set its value (or empty string if undefined/null)
                            input.value = (val !== undefined && val !== null && val !== '') ? val : '';
                        }
                    });
                } else {
                    Object.values(autoPopulateInputs).forEach(input => { if (input) input.value = ''; });
                }
                
                calculateTotals();

                employeeSelect.innerHTML = '<option value="">-- Select Employee --</option>';
                if (companyId) {
                    employeeSelect.disabled = false;
                    const filteredEmployees = employeesData.filter(emp => emp.company_id == companyId);
                    if (filteredEmployees.length > 0) {
                        filteredEmployees.forEach(emp => employeeSelect.add(new Option(emp.name, emp.id)));
                    } else {
                        employeeSelect.innerHTML = '<option value="">-- No Employees Assigned --</option>';
                    }
                } else {
                    employeeSelect.disabled = true;
                    employeeSelect.innerHTML = '<option value="">-- Waiting for Company --</option>';
                }
            });

            function val(name, def = 0) {
                const el = summaryForm.querySelector(`[name="${name}"]`);
                if (!el) return def;
                const v = parseFloat(el.value);
                return isNaN(v) ? def : v;
            }

            function calculateTotals() {
                try {
                    const basic = (val('working_days_8hr') * val('rate_8hr')) + (val('working_days_12hr') * val('rate_12hr'));
                    const basicInput = summaryForm.querySelector('[name="basic"]');
                    if (basicInput) basicInput.value = basic.toFixed(2);

                    // Calculate NPL deduction: NPL Days × Daily Rate
                    const workingDaysTotal = val('working_days_8hr') + val('working_days_12hr');
                    const dailyRate = workingDaysTotal > 0 ? basic / workingDaysTotal : 0;
                    const nplDeduction = val('npl_days') * dailyRate;
                    
                    // Auto-update NPL amount field to show calculated value
                    const nplAmountInput = summaryForm.querySelector('[name="npl_days_amount"]');
                    if (nplAmountInput) nplAmountInput.value = nplDeduction.toFixed(2);

                    // Consultant Fee (fixed_allowance) is an earning and must be counted in earnings
                    const totalEarnings = basic
                        + (val('overtime_hours') * val('overtime_rate'))
                        + (val('overtime_12hr_hours') * val('overtime_rate_12hr'))
                        + (val('sunday_days') * val('sunday_rate'))
                        + (val('ph_days') * val('ph_rate'))
                        + (val('sunday_ph_ot_hours') * val('sunday_ph_ot_rate'))
                        + val('back_pay')
                        + val('special_allowance')
                        + val('night_shift_allowance')
                        + val('fixed_allowance');

                    const totalDeductions = val('advance') + val('medical') + nplDeduction
                        + val('epf_deduction') + val('socso_deduction') + val('sip_deduction')
                        + val('hostel_fee') + val('utility_charges') + val('other_deductions')
                        + val('default_deduction') + val('insurance');

                    const netPay = Math.max(0, totalEarnings - totalDeductions);

                    document.getElementById('earnTotal').value = totalEarnings.toFixed(2);
                    document.getElementById('deductTotal').value = totalDeductions.toFixed(2);
                    document.getElementById('netPay').value = netPay.toFixed(2);
                    
                    const netPayEl = document.getElementById('netPay');
                    netPayEl.classList.toggle('text-emerald-400', netPay > 0);
                    netPayEl.classList.toggle('text-red-400', netPay <= 0);

                } catch (e) { console.error("Calculation Error:", e); }
            }

            allNumberInputs.forEach(el => el.addEventListener('input', calculateTotals));
            calculateTotals();
        });
    </script>
</body>
</html>