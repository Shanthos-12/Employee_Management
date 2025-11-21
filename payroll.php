<?php
/**************************************************************
 * Payslip Payroll - v3.6 (Employees-Payroll Rates + Deductions Prefill)
 * - Prefill keeps DAYS from URL but fetches RATES + DEDUCTIONS from employees_payroll
 * - Edit/Insert/Listing preserved; totals include all deduction fields
 **************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php';

/* ---------- 1) AUTH ---------- */
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$userRole = $_SESSION['user']['role'] ?? 'guest';
$pdo = getPDO();

/* ---------- 2) STATE ---------- */
$is_editing   = false;
$form_values  = [];
$notification = null;

function getFormValue($field, $default = '') {
    global $form_values;
    $value = $form_values[$field] ?? $default;
    if ($value === null || $value === '') $value = $default;
    return (string)$value;
}
function getFormNumberValue($field, $default = 0) {
    global $form_values;
    $value = $form_values[$field] ?? $default;
    if ($value === null || $value === '') $value = $default;
    return (string)$value;
}

/* ---------- 3) DELETE ---------- */
if (isset($_GET['delete_id']) && strtolower($userRole) === 'superadmin') {
    $delete_id = (int)$_GET['delete_id'];
    if ($delete_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM payroll WHERE id=?");
            $stmt->execute([$delete_id]);
            $_SESSION['notification'] = $stmt->rowCount()
                ? ['type'=>'deleted','message'=>'Payslip deleted successfully.']
                : ['type'=>'error','message'=>'Error: Payslip not found or could not be deleted.'];
        } catch (PDOException $e) {
            $_SESSION['notification'] = ['type'=>'error','message'=>'Error: Failed to delete payslip.'];
        }
    }
    header("Location: payroll.php"); exit;
}

/* ---------- 4) EDIT ---------- */
if (isset($_GET['edit_id']) && strtolower($userRole) === 'superadmin') {
    $edit_id = (int)$_GET['edit_id'];
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*, e.name AS employee_name, e.position
            FROM payroll p
            LEFT JOIN employees e ON e.id = p.employee_id
            WHERE p.id = ?
        ");
        $stmt->execute([$edit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $is_editing = true;

            // Full schema defaults (strings for inputs)
            $form_values = [
                // identifiers
                'id'=>'','employee_id'=>'','employee_name'=>'','position'=>'',
                'month'=>'', 'year'=>date('Y'), 'currency'=>'MYR',
                // working days & rates (for derived basic)
                'working_days_8hr'=>'0','rate_8hr'=>'0','working_days_12hr'=>'0','rate_12hr'=>'0',
                'full_basic'=>'0','full_basics'=>'0',
                // earnings
                'basic'=>'0','oth_claim'=>'0','overtime_hours'=>'0','overtime_rate'=>'0','overtime_rm'=>'0',
                'overtime_12hr_hours'=>'0','overtime_rate_12hr'=>'0','overtime_12hr_rm'=>'0',
                'sunday_days'=>'0','sunday_rate'=>'0','sunday'=>'0',
                'ph_days'=>'0','ph_rate'=>'0','public_holiday'=>'0',
                'fixed_allowance'=>'0','special_allowance'=>'0',
                // deductions (old)
                'advance'=>'0','advance_count'=>'0','medical'=>'0','medical_count'=>'0',
                'npl_days'=>'0','npl_days_amount'=>'0.00',
                // deductions (new)
                'epf_deduction'=>'0','socso_deduction'=>'0','sip_deduction'=>'0',
                'hostel_fee'=>'0','utility_charges'=>'0','other_deductions'=>'0',
                // totals + misc
                'payment_date'=>'','account_no'=>'','earnings_total'=>'0','deductions_total'=>'0','net_pay'=>'0',
            ];

            foreach ($row as $k=>$v) {
                if (array_key_exists($k,$form_values)) {
                    $form_values[$k] = $v === null ? $form_values[$k] : (string)$v;
                }
            }
            $form_values['is_editing'] = true;
        } else {
            $_SESSION['notification'] = ['type'=>'error','message'=>'Error: The payslip record you are trying to edit does not exist.'];
            header("Location: payroll.php"); exit;
        }
    }
}

/* ---------- 5) PREFILL FROM employees_payroll ---------- */
if (isset($_GET['prefill'])) {
    $is_editing = false;

    $employee_id    = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
    $month          = $_GET['month'] ?? date('F');
    $year           = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

    // keep DAYS/HOURS from URL
    $working_days_8hr  = isset($_GET['working_days_8hr'])  ? (int)$_GET['working_days_8hr']  : 0;
    $working_days_12hr = isset($_GET['working_days_12hr']) ? (int)$_GET['working_days_12hr'] : 0;
    $sunday_days       = isset($_GET['sunday_days'])       ? (int)$_GET['sunday_days']       : 0;
    $ph_days           = isset($_GET['ph_days'])           ? (int)$_GET['ph_days']           : 0;
    $overtime_hours    = isset($_GET['overtime_hours'])    ? (float)$_GET['overtime_hours']  : 0.0;
    $overtime_12hr_hours = isset($_GET['overtime_12hr_hours']) ? (float)$_GET['overtime_12hr_hours'] : 0.0;
    $overtime_rate_12hr_url = isset($_GET['overtime_rate_12hr']) ? (float)$_GET['overtime_rate_12hr'] : null;

    // employee -> company
    $empRow = null;
    if ($employee_id > 0) {
        $stmt = $pdo->prepare("SELECT id,name,position,company_id FROM employees WHERE id=?");
        $stmt->execute([$employee_id]);
        $empRow = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // defaults
    $rate_8hr=0.0; $rate_12hr=0.0; $sunday_rate=0.0; $ph_rate=0.0; $overtime_rate=0.0; $overtime_rate_12hr=0.0;
    $hostel_fee=0.0; $utility_charges=0.0; $other_deductions=0.0; $special_allowance=0.0;

    if ($empRow && !empty($empRow['company_id'])) {
        $rStmt = $pdo->prepare("
            SELECT
                default_rate_8hr, default_rate_12hr,
                default_sunday_rate, default_ph_rate,
                default_ot_rate_9hr,
                default_hostel_fee, default_utility_charges,
                default_deduction,              -- generic/other deductions
                default_special_allowance
            FROM employees_payroll
            WHERE company_ref_id = ?
            LIMIT 1
        ");
        $rStmt->execute([(int)$empRow['company_id']]);
        if ($rates = $rStmt->fetch(PDO::FETCH_ASSOC)) {
            $rate_8hr         = (float)$rates['default_rate_8hr'];
            $rate_12hr        = (float)$rates['default_rate_12hr'];
            $sunday_rate      = (float)$rates['default_sunday_rate'];
            $ph_rate          = (float)$rates['default_ph_rate'];
            $overtime_rate    = (float)$rates['default_ot_rate_9hr'];
            $overtime_rate_12hr = $overtime_rate_12hr_url ?? (float)($rates['default_ot_rate_12hr'] ?? 0.0);

            // NEW: map default deductions/allowances
            $hostel_fee       = (float)$rates['default_hostel_fee'];
            $utility_charges  = (float)$rates['default_utility_charges'];
            $other_deductions = (float)$rates['default_deduction'];
            $special_allowance= (float)$rates['default_special_allowance'];
        }
    }

    // compute from rates + days
    $basic          = round($working_days_8hr*$rate_8hr + $working_days_12hr*$rate_12hr, 2);
    $overtime_rm    = round($overtime_hours * $overtime_rate, 2);
    $overtime_12hr_rm = round($overtime_12hr_hours * $overtime_rate_12hr, 2);
    $sunday         = round($sunday_days * $sunday_rate, 2);
    $public_holiday = round($ph_days * $ph_rate, 2);

    $oth_claim       = isset($_GET['oth_claim']) ? (float)$_GET['oth_claim'] : 0.00;
    $fixed_allowance = isset($_GET['fixed_allowance']) ? (float)$_GET['fixed_allowance'] : 0.00;

    $earnings_total = $basic + $overtime_rm + $overtime_12hr_rm + $sunday + $public_holiday + $oth_claim + $fixed_allowance + $special_allowance;
    $deductions_total = $hostel_fee + $utility_charges + $other_deductions; // EPF/SOCSO/SIP left as manual (0)

    $form_values = [
        'employee_id'=>$employee_id,
        'employee_name'=>$empRow['name'] ?? '',
        'position'=>$empRow['position'] ?? '',
        'month'=>$month, 'year'=>$year, 'currency'=>'MYR',

        'working_days_8hr'=>$working_days_8hr, 'rate_8hr'=>$rate_8hr,
        'working_days_12hr'=>$working_days_12hr,'rate_12hr'=>$rate_12hr,
        'full_basic'=>$basic, 'full_basics'=>$basic,

        'basic'=>$basic,
        'oth_claim'=>$oth_claim,
        'overtime_hours'=>$overtime_hours,
        'overtime_rate'=>$overtime_rate,
        'overtime_rm'=>$overtime_rm,
        'overtime_12hr_hours'=>$overtime_12hr_hours,
        'overtime_rate_12hr'=>$overtime_rate_12hr,
        'overtime_12hr_rm'=>$overtime_12hr_rm,

        'sunday_days'=>$sunday_days, 'sunday_rate'=>$sunday_rate, 'sunday'=>$sunday,
        'ph_days'=>$ph_days, 'ph_rate'=>$ph_rate, 'public_holiday'=>$public_holiday,

        'fixed_allowance'=>$fixed_allowance,
        'special_allowance'=>$special_allowance,

        // NEW: prefill deductions from employees_payroll
        'epf_deduction'=>0, 'socso_deduction'=>0, 'sip_deduction'=>0,
        'hostel_fee'=>$hostel_fee,
        'utility_charges'=>$utility_charges,
        'other_deductions'=>$other_deductions,

        // keep old ones manual
        'advance'=>0,'advance_count'=>0,'medical'=>0,'medical_count'=>0,
        'npl_days'=>0,'npl_days_amount'=>0.00,

        'payment_date'=>'','account_no'=>'',
        'earnings_total'=>$earnings_total,
        'deductions_total'=>$deductions_total,
        'net_pay'=>max(0, $earnings_total-$deductions_total),
    ];

    foreach ($form_values as $k=>$v) if (is_numeric($v)) $form_values[$k] = (string)$v;
}

/* carryback on validation error */
if (isset($_SESSION['form_data'])) {
    $form_values = array_merge($form_values, $_SESSION['form_data']);
    unset($_SESSION['form_data']);
}
/* notification */
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}

/* ---------- 6) SAVE (INSERT/UPDATE) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k,$d='') => trim((string)($_POST[$k] ?? $d));
    $n = fn($k,$d=0)  => isset($_POST[$k]) && $_POST[$k]!=='' ? (float)$_POST[$k] : (float)$d;
    $i = fn($k,$d=0)  => (int)($_POST[$k] ?? $d);

    $update_id_raw = $_POST['update_id'] ?? '';
    $update_id = (is_numeric($update_id_raw) && (int)$update_id_raw>0) ? (int)$update_id_raw : 0;

    $employee_id = $i('employee_id');
    $month       = ucfirst($f('month'));
    $year        = $i('year',(int)date('Y'));

    if (empty($employee_id) || empty($month)) {
        $_SESSION['form_data'] = $_POST;
        $_SESSION['notification'] = ['type'=>'error','message'=>'Error: Please select an employee and a month.'];
        header("Location: payroll.php".($update_id>0 ? "?edit_id=$update_id" : "")); exit;
    }

    // duplicate only for new
    if ($update_id===0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id=? AND month=? AND year=?");
        $stmt->execute([$employee_id,$month,$year]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['form_data'] = $_POST;
            $_SESSION['notification'] = ['type'=>'error','message'=>'Error: A payslip for this employee and period already exists.'];
            header("Location: payroll.php"); exit;
        }
    }

    // recompute on server
    $basic = $n('basic');
    $overtime_rm    = round($n('overtime_hours')*$n('overtime_rate'),2);
    $overtime_12hr_rm = round($n('overtime_12hr_hours')*$n('overtime_rate_12hr'),2);
    $sunday         = round($i('sunday_days')*$n('sunday_rate'),2);
    $public_holiday = round($i('ph_days')*$n('ph_rate'),2);

    $earnings_total = $basic + $overtime_rm + $overtime_12hr_rm + $sunday + $public_holiday
                    + $n('oth_claim') + $n('fixed_allowance') + $n('special_allowance');

    // NPL calculation: NPL Days × Daily Rate
    $working_days_8hr = intval($i('working_days_8hr', 0));
    $working_days_12hr = intval($i('working_days_12hr', 0));
    $total_working_days = $working_days_8hr + $working_days_12hr;
    $daily_rate = $total_working_days > 0 ? $basic / $total_working_days : 0;
    $npl_deduction = round($i('npl_days') * $daily_rate, 2);

    $deductions_total = $n('advance') + $n('medical') + $npl_deduction
                      + $n('epf_deduction') + $n('socso_deduction') + $n('sip_deduction')
                      + $n('hostel_fee') + $n('utility_charges') + $n('other_deductions') + $n('insurance');

    $net_pay = max(0, $earnings_total - $deductions_total);

    $params = [
        $employee_id, $month, $year, strtoupper($f('currency','MYR')),
        $basic, $n('overtime_hours'), $overtime_rm, $n('overtime_rate'),
        $n('overtime_12hr_hours'), $n('overtime_rate_12hr'), $overtime_12hr_rm,
        $sunday, $i('sunday_days'), $n('sunday_rate'),
        $public_holiday, $i('ph_days'), $n('ph_rate'),
        $n('oth_claim'), $n('fixed_allowance'), $n('special_allowance'),
        $n('advance'), $i('advance_count', $n('advance')>0 ? 1 : 0),
        $n('medical'), $i('medical_count', $n('medical')>0 ? 1 : 0),
        $i('npl_days'), $npl_deduction,
        $i('working_days_8hr'), $n('rate_8hr'),
        $i('working_days_12hr'), $n('rate_12hr'),
        $n('full_basics'),
        // NEW deduction params
        $n('epf_deduction'), $n('socso_deduction'), $n('sip_deduction'),
        $n('hostel_fee'), $n('utility_charges'), $n('other_deductions'), $n('insurance'),
        // misc
        $f('payment_date') ?: null, $f('account_no'),
        $earnings_total, $deductions_total, $net_pay
    ];

    try {
        if ($update_id > 0 && strtolower($userRole)==='superadmin') {
            $sql = "UPDATE payroll SET
                employee_id=?, month=?, year=?, currency=?, basic=?, overtime_hours=?, overtime_rm=?, overtime_rate=?,
                overtime_12hr_hours=?, overtime_rate_12hr=?, overtime_12hr_rm=?,
                sunday=?, sunday_days=?, sunday_rate=?, public_holiday=?, ph_days=?, ph_rate=?,
                oth_claim=?, fixed_allowance=?, special_allowance=?,
                advance=?, advance_count=?, medical=?, medical_count=?, npl_days=?, npl_days_amount=?,
                working_days_8hr=?, rate_8hr=?, working_days_12hr=?, rate_12hr=?, full_basics=?,
                epf_deduction=?, socso_deduction=?, sip_deduction=?, hostel_fee=?, utility_charges=?, other_deductions=?, insurance=?,
                payment_date=?, account_no=?, earnings_total=?, deductions_total=?, net_pay=?
                WHERE id=?";
            $p = $params; $p[] = $update_id;
            $stmt = $pdo->prepare($sql); $stmt->execute($p);
            $_SESSION['notification'] = ['type'=>'updated','message'=>'Payslip updated successfully.'];
        } else if ($update_id === 0) {
            $sql = "INSERT INTO payroll (
                employee_id, month, year, currency, basic, overtime_hours, overtime_rm, overtime_rate,
                overtime_12hr_hours, overtime_rate_12hr, overtime_12hr_rm,
                sunday, sunday_days, sunday_rate, public_holiday, ph_days, ph_rate,
                oth_claim, fixed_allowance, special_allowance,
                advance, advance_count, medical, medical_count, npl_days, npl_days_amount,
                working_days_8hr, rate_8hr, working_days_12hr, rate_12hr, full_basics,
                epf_deduction, socso_deduction, sip_deduction, hostel_fee, utility_charges, other_deductions, insurance,
                payment_date, account_no, earnings_total, deductions_total, net_pay
            ) VALUES (" . rtrim(str_repeat('?,', 43), ',') . ")";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $_SESSION['notification'] = ['type'=>'success','message'=>'Payslip saved successfully.'];
        } else {
            $_SESSION['notification'] = ['type'=>'error','message'=>'Error: You do not have permission to update payslips.'];
        }
    } catch (PDOException $e) {
        error_log("Payroll save/update error: ".$e->getMessage());
        error_log("SQL Error Code: ".$e->getCode());
        error_log("Parameter count: ".count($params));
        if ($update_id > 0) {
            error_log("UPDATE operation failed for payroll ID: ".$update_id);
        } else {
            error_log("INSERT operation failed for new payroll record");
        }
        $_SESSION['form_data'] = $_POST;
        $_SESSION['notification'] = ['type'=>'error','message'=>'Error: Database operation failed. Please check the server logs for details.'];
        if ($update_id>0) { header("Location: payroll.php?edit_id=$update_id"); exit; }
    }
    header("Location: payroll.php"); exit;
}

/* ---------- 7) DATA FOR UI ---------- */
try {
    $employees = $pdo->query("SELECT id,name,position FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
    $notification = ['type'=>'error','message'=>'Error: Failed to load employees list.'];
}

$filterMonth = $_GET['m'] ?? '';
$filterYear  = $_GET['y'] ?? '';
$search      = trim($_GET['q'] ?? '');

$queryParams = [];
$sql = "SELECT p.*, e.name, e.position
        FROM payroll p
        JOIN employees e ON e.id = p.employee_id
        WHERE 1=1";
if ($filterMonth!=='') { $sql.=" AND p.month=?"; $queryParams[]=$filterMonth; }
if ($filterYear!=='')  { $sql.=" AND p.year=?";  $queryParams[]=(int)$filterYear; }
if ($search!=='') {
    $sql.=" AND (e.name LIKE ? OR e.position LIKE ?)";
    $q="%$search%"; $queryParams[]=$q; $queryParams[]=$q;
}
$sql.=" ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($queryParams);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cost = (float)($pdo->query("SELECT COALESCE(SUM(net_pay),0) FROM payroll")->fetchColumn());
$total_emp  = (int)($pdo->query("SELECT COUNT(DISTINCT employee_id) FROM payroll")->fetchColumn());
$latest     = $pdo->query("SELECT month,year FROM payroll ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/jpg" href="assets/sfi_logo.jpg">
    <meta charset="UTF-8" />
    <title><?= $is_editing ? 'Edit' : 'Create' ?> Payslip</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
      input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{ -webkit-appearance:none;margin:0 }
      input[type=date]::-webkit-calendar-picker-indicator{ filter:invert(1) }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
<header class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-10">
  <h1 class="text-2xl font-bold flex items-center gap-3">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v.01M12 6v-1m0-1H9.342a4.002 4.002 0 00-3.182 1.561l-1.14 1.824A4.002 4.002 0 004.342 8H3v10h18V8h-1.342a4.002 4.002 0 00-3.182-1.561l-1.14-1.824A4.002 4.002 0 0012 4.01V4z" />
    </svg>
    <span>SFI PAYROLL MANAGEMENT</span>
    <?php if ($is_editing): ?><span class="text-lg text-amber-400 font-medium">(Editing Mode)</span><?php endif; ?>
  </h1>
  <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
    <span>Dashboard</span>
  </a>
</header>

<main class="max-w-7xl mx-auto px-6 py-6 space-y-6">

<?php if ($notification): $colors=['success'=>'bg-emerald-500/20 text-emerald-300','updated'=>'bg-sky-500/20 text-sky-300','deleted'=>'bg-red-500/20 text-red-300','error'=>'bg-red-500/20 text-red-300']; ?>
  <div class="p-4 <?= $colors[$notification['type']] ?? 'bg-gray-500/20' ?> rounded-lg backdrop-blur"><?= htmlspecialchars($notification['message']) ?></div>
<?php endif; ?>

<section class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
  <div class="bg-white/10 backdrop-blur p-5 rounded-2xl shadow-xl border border-slate-700/50">
    <p class="text-sm text-slate-400">Total Net Paid</p>
    <p class="text-3xl font-bold text-emerald-400 mt-1"><?= number_format($total_cost,2) ?> <span class="text-base text-slate-400">MYR</span></p>
  </div>
  <div class="bg-white/10 backdrop-blur p-5 rounded-2xl shadow-xl border border-slate-700/50">
    <p class="text-sm text-slate-400">Employees Paid</p>
    <p class="text-3xl font-bold text-sky-400 mt-1"><?= $total_emp ?></p>
  </div>
  <div class="bg-white/10 backdrop-blur p-5 rounded-2xl shadow-xl border border-slate-700/50">
    <p class="text-sm text-slate-400">Latest Period</p>
    <p class="text-3xl font-bold text-indigo-400 mt-1"><?= htmlspecialchars($latest['month'] ?? '—') ?> <?= htmlspecialchars((string)($latest['year'] ?? '')) ?></p>
  </div>
</section>

<section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl border <?= $is_editing ? 'border-amber-500/50' : 'border-slate-700/50' ?>">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-bold"><?= $is_editing ? 'Edit Payslip' : 'Add Payslip (Rates & Deductions from employees_payroll)' ?></h2>
    <?php if ($is_editing): ?>
      <div class="text-sm text-amber-400">Editing: <strong><?= htmlspecialchars(getFormValue('employee_name','...')) ?></strong> for <strong><?= htmlspecialchars(getFormValue('month')) ?> <?= htmlspecialchars(getFormValue('year')) ?></strong></div>
    <?php endif; ?>
  </div>

  <form method="post" id="slipForm" class="grid md:grid-cols-2 gap-x-6 gap-y-5">
    <input type="hidden" name="update_id" value="<?= htmlspecialchars(getFormValue('id')) ?>">

    <!-- Employee + period -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:col-span-2">
      <div>
        <label class="block text-sm text-slate-300 mb-1">Employee *</label>
        <div class="relative" id="employee-search-container">
          <input type="text" id="employeeSearchInput" placeholder="Type or click to select employee..." autocomplete="off" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormValue('employee_name','')) ?>">
          <input type="hidden" name="employee_id" id="employeeIdInput" value="<?= htmlspecialchars(getFormValue('employee_id','')) ?>">
          <div id="employeeSuggestions" class="absolute z-50 w-full bg-slate-800 border border-slate-600 rounded-b-lg mt-1 hidden max-h-60 overflow-y-auto"></div>
        </div>
      </div>
      <div>
        <label class="block text-sm text-slate-300 mb-1">Month *</label>
        <select name="month" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600">
          <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December'];
          foreach($months as $m): ?>
            <option value="<?= $m ?>" <?= (getFormValue('month',date('F'))==$m)?'selected':'' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm text-slate-300 mb-1">Year *</label>
        <input type="number" name="year" required class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormValue('year',date('Y'))) ?>">
      </div>
    </div>

    <!-- Working days & rates -->
    <div class="grid grid-cols-2 gap-4 md:col-span-2 border-t border-slate-700 pt-4">
      <div><label class="block text-sm text-slate-300 mb-1">Working Days (9hr)</label><input type="number" name="working_days_8hr" min="0" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('working_days_8hr')) ?>"></div>
      <div><label class="block text-sm text-slate-300 mb-1">Rate (9hr) RM</label><input type="number" step="0.01" name="rate_8hr" min="0" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('rate_8hr')) ?>"></div>
      <div><label class="block text-sm text-slate-300 mb-1">Working Days (12hr)</label><input type="number" name="working_days_12hr" min="0" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('working_days_12hr')) ?>"></div>
      <div><label class="block text-sm text-slate-300 mb-1">Rate (12hr) RM</label><input type="number" step="0.01" name="rate_12hr" min="0" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('rate_12hr')) ?>"></div>
      <div class="col-span-2"><label class="block text-sm text-slate-300 mb-1">Full Basic (Max Salary for Month)</label><input type="number" step="0.01" name="full_basic" min="0" class="w-full p-2.5 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('full_basic')) ?>"></div>
    </div>

    <!-- Earnings -->
    <div class="space-y-4 border-t border-slate-700/50 pt-4">
      <h3 class="text-slate-300 font-semibold">Earnings</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs text-slate-400">Basic *</label><input type="number" step="0.01" name="basic" required class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('basic')) ?>"></div>
        <div><label class="text-xs text-slate-400">OTH Claim</label><input type="number" step="0.01" name="oth_claim" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('oth_claim')) ?>"></div>
        <div><label class="text-xs text-slate-400">9HR Overtime Hours</label><input type="number" step="0.01" name="overtime_hours" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('overtime_hours')) ?>"></div>
        <div><label class="text-xs text-slate-400">9HR Overtime Rate (RM/hr)</label><input type="number" step="0.01" name="overtime_rate" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('overtime_rate')) ?>"></div>
        <div><label class="text-xs text-slate-400">12HR Overtime Hours</label><input type="number" step="0.01" name="overtime_12hr_hours" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('overtime_12hr_hours')) ?>"></div>
        <div><label class="text-xs text-slate-400">12HR Overtime Rate (RM/hr)</label><input type="number" step="0.01" name="overtime_rate_12hr" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('overtime_rate_12hr')) ?>"></div>
        <div><label class="text-xs text-slate-400">Sunday Days</label><input type="number" name="sunday_days" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('sunday_days')) ?>"></div>
        <div><label class="text-xs text-slate-400">Sunday Rate (RM/day)</label><input type="number" step="0.01" name="sunday_rate" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('sunday_rate')) ?>"></div>
        <div><label class="text-xs text-slate-400">PH Days</label><input type="number" name="ph_days" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('ph_days')) ?>"></div>
        <div><label class="text-xs text-slate-400">PH Rate (RM/day)</label><input type="number" step="0.01" name="ph_rate" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('ph_rate')) ?>"></div>
        <div><label class="text-xs text-slate-400">Fixed Allowance</label><input type="number" step="0.01" name="fixed_allowance" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('fixed_allowance')) ?>"></div>
        <div><label class="text-xs text-slate-400">Special Allowance</label><input type="number" step="0.01" name="special_allowance" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('special_allowance')) ?>"></div>
      </div>
    </div>

    <!-- Deductions -->
    <div class="space-y-4 border-t border-slate-700/50 pt-4">
      <h3 class="text-slate-300 font-semibold">Deductions</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs text-slate-400">EPF</label><input type="number" step="0.01" name="epf_deduction" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('epf_deduction')) ?>"></div>
        <div><label class="text-xs text-slate-400">SOCSO</label><input type="number" step="0.01" name="socso_deduction" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('socso_deduction')) ?>"></div>
        <div><label class="text-xs text-slate-400">SIP (EIS)</label><input type="number" step="0.01" name="sip_deduction" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('sip_deduction')) ?>"></div>
        <div><label class="text-xs text-slate-400">Hostel Fee</label><input type="number" step="0.01" name="hostel_fee" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('hostel_fee')) ?>"></div>
        <div><label class="text-xs text-slate-400">Utility Charges</label><input type="number" step="0.01" name="utility_charges" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('utility_charges')) ?>"></div>
        <div><label class="text-xs text-slate-400">Other Deductions</label><input type="number" step="0.01" name="other_deductions" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('other_deductions')) ?>"></div>
        <hr class="col-span-2 border-slate-700">
        <div><label class="text-xs text-slate-400">Advance</label><input type="number" step="0.01" name="advance" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('advance')) ?>"></div>
        <div><label class="text-xs text-slate-400">Advance Count</label><input type="number" name="advance_count" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" placeholder="Auto" value="<?= htmlspecialchars(getFormNumberValue('advance_count')) ?>"></div>
        <div><label class="text-xs text-slate-400">Medical</label><input type="number" step="0.01" name="medical" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('medical')) ?>"></div>
        <div><label class="text-xs text-slate-400">Medical Count</label><input type="number" name="medical_count" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" placeholder="Auto" value="<?= htmlspecialchars(getFormNumberValue('medical_count')) ?>"></div>
        <div><label class="text-xs text-slate-400">NPL (Days)</label><input type="number" name="npl_days" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('npl_days')) ?>"></div>
        <div><label class="text-xs text-slate-400">NPL Amount (RM)</label><input type="number" step="0.01" name="npl_days_amount" class="w-full p-2 rounded-lg bg-slate-700 border border-slate-600 font-bold" value="0.00" readonly></div>
        <div class="col-span-2"><label class="text-xs text-slate-400">Insurance</label><input type="number" step="0.01" name="insurance" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormNumberValue('insurance')) ?>"></div>
      </div>
    </div>

    <!-- Totals -->
    <div class="md:col-span-2 grid md:grid-cols-5 gap-3 border-t border-slate-700 pt-4">
      <div><label class="block text-xs text-slate-400 mb-1">Payment Date</label><input type="date" name="payment_date" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" value="<?= htmlspecialchars(getFormValue('payment_date')) ?>"></div>
      <div><label class="block text-xs text-slate-400 mb-1">Account No</label><input name="account_no" class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600" placeholder="e.g. 123456789" value="<?= htmlspecialchars(getFormValue('account_no')) ?>"></div>
      <div><label class="block text-xs text-slate-400 mb-1">Total Earnings</label><input id="earnTotal" class="w-full p-2 rounded-lg bg-slate-700/50 border border-slate-600 font-medium text-emerald-400" readonly></div>
      <div><label class="block text-xs text-slate-400 mb-1">Total Deductions</label><input id="deductTotal" class="w-full p-2 rounded-lg bg-slate-700/50 border border-slate-600 font-medium text-rose-400" readonly></div>
      <div><label class="block text-xs text-slate-400 mb-1">Net Pay</label><input id="netPay" class="w-full p-2 rounded-lg bg-slate-700/50 border border-slate-600 font-bold" readonly></div>

      <div class="md:col-span-5 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 pt-4 border-t border-slate-600">
        <?php if ($is_editing): ?>
          <button type="submit" class="flex-1 p-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 font-semibold flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Update Payslip
          </button>
          <a href="payroll.php" class="flex-1 sm:flex-initial px-6 py-3 text-center bg-slate-600 hover:bg-slate-500 border border-slate-500 rounded-xl font-semibold flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            Cancel Edit
          </a>
        <?php else: ?>
          <button type="submit" class="w-full p-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 font-semibold flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Save New Payslip
          </button>
        <?php endif; ?>
      </div>
    </div>
  </form>
</section>

<!-- Filter -->
<section class="bg-white/10 backdrop-blur p-4 rounded-2xl shadow-xl">
  <form class="grid md:grid-cols-4 gap-3">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, position…" class="p-2.5 rounded-lg bg-slate-800 border border-slate-600">
    <input type="text" name="m" value="<?= htmlspecialchars($filterMonth) ?>" placeholder="Month (e.g., January)" class="p-2.5 rounded-lg bg-slate-800 border border-slate-600">
    <input type="number" name="y" value="<?= htmlspecialchars($filterYear) ?>" placeholder="Year" class="p-2.5 rounded-lg bg-slate-800 border border-slate-600">
    <button type="submit" class="p-2.5 rounded-lg bg-slate-700 hover:bg-slate-600 border border-slate-600 font-medium">Filter</button>
  </form>
</section>

<!-- Records -->
<section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
  <h2 class="text-xl font-bold mb-4">Payslip Records</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm text-left">
      <thead class="bg-slate-800 text-slate-300 uppercase text-xs">
        <tr>
          <th class="p-3">Employee</th><th class="p-3">Period</th>
          <th class="p-3 text-right">Earnings</th><th class="p-3 text-right">Deductions</th>
          <th class="p-3 text-right">Net Pay</th><th class="p-3">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($records)): ?>
        <tr><td colspan="6" class="p-6 text-center text-slate-400">No payslips found.</td></tr>
      <?php else: foreach ($records as $r): ?>
        <tr class="hover:bg-slate-700/40 border-b border-slate-700">
          <td class="p-3">
            <div class="font-medium"><?= htmlspecialchars($r['name']) ?></div>
            <div class="text-xs text-slate-400"><?= htmlspecialchars($r['position'] ?? '') ?></div>
          </td>
          <td class="p-3"><?= htmlspecialchars($r['month']) ?> <?= (int)$r['year'] ?></td>
          <td class="p-3 text-right font-medium text-emerald-400"><?= number_format((float)$r['earnings_total'],2) ?></td>
          <td class="p-3 text-right font-medium text-rose-400"><?= number_format((float)$r['deductions_total'],2) ?></td>
          <td class="p-3 text-right font-bold text-emerald-300"><?= number_format((float)$r['net_pay'],2) ?></td>
          <td class="p-3">
            <div class="flex items-center gap-2 text-xs">
              <a href="payslip.php?id=<?= (int)$r['id'] ?>" class="text-sky-400 hover:underline">View PDF</a>
              <?php if (strtolower($userRole)==='superadmin'): ?>
                <span class="text-slate-600">|</span>
                <a href="payroll.php?edit_id=<?= (int)$r['id'] ?>" class="text-amber-400 hover:underline">Edit</a>
                <span class="text-slate-600">|</span>
                <a href="payroll.php?delete_id=<?= (int)$r['id'] ?>" class="text-red-400 hover:underline" onclick="return confirm('Delete this payslip for <?= htmlspecialchars($r['name']) ?>?')">Delete</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const slipForm = document.getElementById('slipForm');
  if (slipForm) {
    const allNumberInputs = slipForm.querySelectorAll('input[type="number"]');
    const v = (name, d=0) => {
      const el = slipForm.querySelector(`[name="${name}"]`);
      const num = parseFloat(el?.value ?? '');
      return isNaN(num) ? d : num;
    };

    function recalc() {
      const basic = (v('working_days_8hr')*v('rate_8hr')) + (v('working_days_12hr')*v('rate_12hr'));
      const basicInput = slipForm.querySelector('[name="basic"]'); if (basicInput) basicInput.value = basic.toFixed(2);

      // NPL calculation: NPL Days × Daily Rate
      const workingDays8hr = v('working_days_8hr') || 0;
      const workingDays12hr = v('working_days_12hr') || 0;
      const totalWorkingDays = workingDays8hr + workingDays12hr;
      const dailyRate = totalWorkingDays > 0 ? basic / totalWorkingDays : 0;
      const nplDeduction = (v('npl_days') || 0) * dailyRate;
      const nplAmountInput = slipForm.querySelector('[name="npl_days_amount"]');
      if (nplAmountInput) nplAmountInput.value = nplDeduction.toFixed(2);

      const earnings = basic
        + (v('overtime_hours')*v('overtime_rate'))
        + (v('overtime_12hr_hours')*v('overtime_rate_12hr'))
        + (v('sunday_days')*v('sunday_rate'))
        + (v('ph_days')*v('ph_rate'))
        + v('oth_claim') + v('fixed_allowance') + v('special_allowance');

      const deductions = v('advance') + v('medical') + nplDeduction
        + v('epf_deduction') + v('socso_deduction') + v('sip_deduction')
        + v('hostel_fee') + v('utility_charges') + v('other_deductions') + v('insurance');

      const net = Math.max(0, earnings - deductions);
      document.getElementById('earnTotal').value = earnings.toFixed(2);
      document.getElementById('deductTotal').value = deductions.toFixed(2);
      const np = document.getElementById('netPay'); np.value = net.toFixed(2);
      np.classList.toggle('text-emerald-400', net>0); np.classList.toggle('text-red-400', net<=0);
    }
    allNumberInputs.forEach(el => el.addEventListener('input', recalc));

    // auto count updates
    ['advance','medical'].forEach(k=>{
      const a=slipForm.querySelector(`[name="${k}"]`);
      const c=slipForm.querySelector(`[name="${k}_count"]`);
      a?.addEventListener('input',()=>{ if (v(k)>0 && v(`${k}_count`)==0) c.value='1'; else if(v(k)===0) c.value='0'; });
    });

    slipForm.addEventListener('submit', e=>{
      if (!slipForm.querySelector('[name="employee_id"]').value) { e.preventDefault(); alert('Please select an employee.'); return; }
      if (v('basic')<=0) { e.preventDefault(); alert('Basic salary must be greater than zero.'); return; }
      const btn = slipForm.querySelector('button[type="submit"]');
      if (btn) { btn.disabled=true; btn.innerHTML='<svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path></svg> Saving...'; }
    });

    recalc();
  }

  // searchable employee dropdown
  const container = document.getElementById('employee-search-container');
  if (container) {
    const search = document.getElementById('employeeSearchInput');
    const hidden = document.getElementById('employeeIdInput');
    const list   = document.getElementById('employeeSuggestions');
    const employees = <?= json_encode($employees) ?>;

    const render = (filter='')=>{
      list.innerHTML='';
      const items = employees.filter(e=>e.name.toLowerCase().includes(filter.toLowerCase()));
      if (!items.length) list.innerHTML='<div class="px-4 py-2 text-slate-400">No employees found</div>';
      else items.forEach(emp=>{
        const d=document.createElement('div');
        d.className='px-4 py-2 hover:bg-slate-700 cursor-pointer';
        d.textContent=emp.name; d.dataset.value=emp.id;
        d.addEventListener('mousedown',()=>select(emp.id,emp.name));
        list.appendChild(d);
      });
      list.classList.remove('hidden');
    };
    const select=(id,name)=>{ search.value=name; hidden.value=id; list.classList.add('hidden'); };
    search.addEventListener('focus',()=>render(search.value));
    search.addEventListener('input',()=>{ hidden.value=''; render(search.value); });
    document.addEventListener('click',e=>{
      if (!container.contains(e.target)) {
        list.classList.add('hidden');
        if (search.value && !hidden.value) {
          const match = employees.find(emp => emp.name.toLowerCase()===search.value.toLowerCase());
          if (match) select(match.id,match.name); else { search.value=''; hidden.value=''; }
        }
      }
    });
  }
});
</script>
</body>
</html>
