<?php
/**************************************************************
 * Companies & Employees-Rate Mirror (v3.0 — with Edit/Delete)
 * - Two tabs: Companies | Employees
 * - Add, Edit, Delete for companies (table: companies)
 * - Add, Edit, Delete for employees mirror (table: employees_payroll)
 * - PDO via db_conn.php::getPDO()
 * - CSRF protection, flash notifications, premium Tailwind UI
 **************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php';

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();

/* ----------------- Flash helpers ----------------- */
$flash = $_SESSION['notification'] ?? null;
unset($_SESSION['notification']);
function flash(string $type, string $msg): void {
    $_SESSION['notification'] = ['type' => $type, 'message' => $msg];
}

/* ----------------- CSRF helpers ------------------ */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check(?string $t): void {
    if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
        http_response_code(400);
        die('CSRF check failed. Refresh and try again.');
    }
}

/* ----------------- Sanitizers -------------------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) {
    if ($v === null) return null;
    $v = is_string($v) ? trim($v) : $v;
    if ($v === '' || $v === null) return null;
    return is_numeric($v) ? (float)$v : null;
}

/* ============================================================
   POST HANDLERS — ADD / UPDATE
   ============================================================ */

/* ADD: Company */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    try {
        csrf_check($_POST['csrf'] ?? null);

        $name = trim((string)($_POST['company_name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $contact_person = trim((string)($_POST['contact_person'] ?? ''));
        $contact_email = trim((string)($_POST['contact_email'] ?? ''));

        if ($name === '') {
            flash('error','Company name is required.');
            header("Location: ".$_SERVER['PHP_SELF']."#tab-companies");
            exit;
        }

        $dup = $pdo->prepare("SELECT id FROM companies WHERE name = ? LIMIT 1");
        $dup->execute([$name]);
        if ($dup->fetchColumn()) {
            flash('error','A company with that name already exists.');
            header("Location: ".$_SERVER['PHP_SELF']."#tab-companies");
            exit;
        }

        $sql = "INSERT INTO companies (
            name, address, contact_person, contact_email,
            default_rate_8hr, default_rate_12hr, default_sunday_rate, default_ph_rate,
            default_hostel_fee, default_utility_charges,
            default_consultant_fee_per_head, default_back_pay, default_special_allowance,
            default_night_shift_allowance, default_deduction, default_insurance,
            default_ot_rate_9hr, default_ot_rate_12hr, default_sunday_ph_ot_rate, default_ph_ot_rate
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            $name, $address ?: null, $contact_person ?: null, $contact_email ?: null,
            n($_POST['default_rate_8hr'] ?? null),
            n($_POST['default_rate_12hr'] ?? null),
            n($_POST['default_sunday_rate'] ?? null),
            n($_POST['default_ph_rate'] ?? null),
            n($_POST['default_hostel_fee'] ?? null),
            n($_POST['default_utility_charges'] ?? null),
            n($_POST['default_consultant_fee_per_head'] ?? null),
            n($_POST['default_back_pay'] ?? null),
            n($_POST['default_special_allowance'] ?? null),
            n($_POST['default_night_shift_allowance'] ?? null),
            n($_POST['default_deduction'] ?? null),
            n($_POST['default_insurance'] ?? null),
            n($_POST['default_ot_rate_9hr'] ?? null),
            n($_POST['default_ot_rate_12hr'] ?? null),
            n($_POST['default_sunday_ph_ot_rate'] ?? null),
            n($_POST['default_ph_ot_rate'] ?? null),
        ]);
        flash($ok ? 'success' : 'error', $ok ? 'Company "'.h($name).'" added.' : 'Insert failed.');
    } catch (Throwable $e) {
        error_log('add_company: '.$e->getMessage());
        flash('error','Database error while adding company.');
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-companies");
    exit;
}

/* UPDATE: Company */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    try {
        csrf_check($_POST['csrf'] ?? null);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid company id.');

        $name = trim((string)($_POST['company_name'] ?? ''));
        if ($name === '') throw new RuntimeException('Company name is required.');

        // prevent duplicate name (exclude self)
        $dup = $pdo->prepare("SELECT id FROM companies WHERE name = ? AND id <> ? LIMIT 1");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new RuntimeException('Another company already uses that name.');

        $sql = "UPDATE companies SET
            name=?, address=?, contact_person=?, contact_email=?,
            default_rate_8hr=?, default_rate_12hr=?, default_sunday_rate=?, default_ph_rate=?,
            default_hostel_fee=?, default_utility_charges=?,
            default_consultant_fee_per_head=?, default_back_pay=?, default_special_allowance=?,
            default_night_shift_allowance=?, default_deduction=?, default_insurance=?,
            default_ot_rate_9hr=?, default_ot_rate_12hr=?, default_sunday_ph_ot_rate=?, default_ph_ot_rate=?
            WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            $name,
            ($_POST['address'] ?? '') !== '' ? trim((string)$_POST['address']) : null,
            ($_POST['contact_person'] ?? '') !== '' ? trim((string)$_POST['contact_person']) : null,
            ($_POST['contact_email'] ?? '') !== '' ? trim((string)$_POST['contact_email']) : null,
            n($_POST['default_rate_8hr'] ?? null),
            n($_POST['default_rate_12hr'] ?? null),
            n($_POST['default_sunday_rate'] ?? null),
            n($_POST['default_ph_rate'] ?? null),
            n($_POST['default_hostel_fee'] ?? null),
            n($_POST['default_utility_charges'] ?? null),
            n($_POST['default_consultant_fee_per_head'] ?? null),
            n($_POST['default_back_pay'] ?? null),
            n($_POST['default_special_allowance'] ?? null),
            n($_POST['default_night_shift_allowance'] ?? null),
            n($_POST['default_deduction'] ?? null),
            n($_POST['default_insurance'] ?? null),
            n($_POST['default_ot_rate_9hr'] ?? null),
            n($_POST['default_ot_rate_12hr'] ?? null),
            n($_POST['default_sunday_ph_ot_rate'] ?? null),
            n($_POST['default_ph_ot_rate'] ?? null),
            $id
        ]);
        flash($ok ? 'success' : 'error', $ok ? 'Company updated.' : 'Update failed.');
    } catch (Throwable $e) {
        error_log('update_company: '.$e->getMessage());
        flash('error', $e->getMessage());
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-companies");
    exit;
}

/* ADD: Employees mirror row */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee_mirror'])) {
    try {
        csrf_check($_POST['csrf'] ?? null);

        $company_ref_id = (int)($_POST['company_ref_id'] ?? 0);
        $name           = trim((string)($_POST['company_name_mirror'] ?? ''));
        $address        = trim((string)($_POST['address'] ?? ''));
        $contact_person = trim((string)($_POST['contact_person'] ?? ''));
        $contact_email  = trim((string)($_POST['contact_email'] ?? ''));

        if ($company_ref_id <= 0 || $name === '') {
            flash('error','Select company and ensure name is present.');
            header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
            exit;
        }

        $chk = $pdo->prepare("SELECT id FROM companies WHERE id=?");
        $chk->execute([$company_ref_id]);
        if (!$chk->fetchColumn()) {
            flash('error','Referenced company not found.');
            header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
            exit;
        }

        // Unique mirror per company (optional enforced)
        $dup = $pdo->prepare("SELECT id FROM employees_payroll WHERE company_ref_id = ? LIMIT 1");
        $dup->execute([$company_ref_id]);
        if ($dup->fetchColumn()) {
            flash('error','Employee-rate row already exists for this company.');
            header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
            exit;
        }

        $sql = "INSERT INTO employees_payroll (
            company_ref_id, name, address, contact_person, contact_email,
            default_rate_8hr, default_rate_12hr, default_sunday_rate, default_ph_rate,
            default_hostel_fee, default_utility_charges,
            default_consultant_fee_per_head, default_back_pay, default_special_allowance,
            default_night_shift_allowance, default_deduction, default_insurance,
            default_ot_rate_9hr, default_ot_rate_12hr, default_sunday_ph_ot_rate, default_ph_ot_rate
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            $company_ref_id, $name, $address ?: null, $contact_person ?: null, $contact_email ?: null,
            n($_POST['default_rate_8hr'] ?? null),
            n($_POST['default_rate_12hr'] ?? null),
            n($_POST['default_sunday_rate'] ?? null),
            n($_POST['default_ph_rate'] ?? null),
            n($_POST['default_hostel_fee'] ?? null),
            n($_POST['default_utility_charges'] ?? null),
            n($_POST['default_consultant_fee_per_head'] ?? null),
            n($_POST['default_back_pay'] ?? null),
            n($_POST['default_special_allowance'] ?? null),
            n($_POST['default_night_shift_allowance'] ?? null),
            n($_POST['default_deduction'] ?? null),
            n($_POST['default_insurance'] ?? null),
            n($_POST['default_ot_rate_9hr'] ?? null),
            n($_POST['default_ot_rate_12hr'] ?? null),
            n($_POST['default_sunday_ph_ot_rate'] ?? null),
            n($_POST['default_ph_ot_rate'] ?? null),
        ]);
        flash($ok ? 'success' : 'error', $ok ? 'Employees-rate row saved.' : 'Insert failed.');
    } catch (Throwable $e) {
        error_log('add_employee_mirror: '.$e->getMessage());
        flash('error','Database error while saving employee rates.');
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
    exit;
}

/* UPDATE: Employees mirror row */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee_mirror'])) {
    try {
        csrf_check($_POST['csrf'] ?? null);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid mirror id.');

        $company_ref_id = (int)($_POST['company_ref_id'] ?? 0);
        $name           = trim((string)($_POST['company_name_mirror'] ?? ''));
        if ($company_ref_id <= 0 || $name === '') throw new RuntimeException('Company & name are required.');

        // Ensure company exists
        $chk = $pdo->prepare("SELECT id FROM companies WHERE id=?");
        $chk->execute([$company_ref_id]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Referenced company not found.');

        // Enforce unique mirror per company (exclude self)
        $dup = $pdo->prepare("SELECT id FROM employees_payroll WHERE company_ref_id = ? AND id <> ? LIMIT 1");
        $dup->execute([$company_ref_id, $id]);
        if ($dup->fetchColumn()) throw new RuntimeException('Another mirror already exists for this company.');

        $sql = "UPDATE employees_payroll SET
            company_ref_id=?, name=?, address=?, contact_person=?, contact_email=?,
            default_rate_8hr=?, default_rate_12hr=?, default_sunday_rate=?, default_ph_rate=?,
            default_hostel_fee=?, default_utility_charges=?,
            default_consultant_fee_per_head=?, default_back_pay=?, default_special_allowance=?,
            default_night_shift_allowance=?, default_deduction=?, default_insurance=?,
            default_ot_rate_9hr=?, default_ot_rate_12hr=?, default_sunday_ph_ot_rate=?, default_ph_ot_rate=?
            WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            $company_ref_id,
            $name,
            ($_POST['address'] ?? '') !== '' ? trim((string)$_POST['address']) : null,
            ($_POST['contact_person'] ?? '') !== '' ? trim((string)$_POST['contact_person']) : null,
            ($_POST['contact_email'] ?? '') !== '' ? trim((string)$_POST['contact_email']) : null,

            n($_POST['default_rate_8hr'] ?? null),
            n($_POST['default_rate_12hr'] ?? null),
            n($_POST['default_sunday_rate'] ?? null),
            n($_POST['default_ph_rate'] ?? null),

            n($_POST['default_hostel_fee'] ?? null),
            n($_POST['default_utility_charges'] ?? null),

            n($_POST['default_consultant_fee_per_head'] ?? null),
            n($_POST['default_back_pay'] ?? null),
            n($_POST['default_special_allowance'] ?? null),
            n($_POST['default_night_shift_allowance'] ?? null),
            n($_POST['default_deduction'] ?? null),
            n($_POST['default_insurance'] ?? null),

            n($_POST['default_ot_rate_9hr'] ?? null),
            n($_POST['default_ot_rate_12hr'] ?? null),
            n($_POST['default_sunday_ph_ot_rate'] ?? null),
            n($_POST['default_ph_ot_rate'] ?? null),
            $id
        ]);
        flash($ok ? 'success' : 'error', $ok ? 'Employees-rate row updated.' : 'Update failed.');
    } catch (Throwable $e) {
        error_log('update_employee_mirror: '.$e->getMessage());
        flash('error', $e->getMessage());
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
    exit;
}

/* ============================================================
   GET HANDLERS — DELETE / LOAD EDIT DATA
   ============================================================ */

/* DELETE: Company */
if (isset($_GET['delete_company'])) {
    try {
        $id = (int)$_GET['delete_company'];
        if ($id > 0) {
            // Optional safety: ensure no mirror rows? (We will allow delete regardless)
            $del = $pdo->prepare("DELETE FROM companies WHERE id=?");
            $ok  = $del->execute([$id]);
            flash($ok ? 'success' : 'error', $ok ? 'Company deleted.' : 'Delete failed.');
        }
    } catch (Throwable $e) {
        error_log('delete_company: '.$e->getMessage());
        flash('error','Error deleting company.');
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-companies");
    exit;
}

/* DELETE: Employees mirror */
if (isset($_GET['delete_employee_mirror'])) {
    try {
        $id = (int)$_GET['delete_employee_mirror'];
        if ($id > 0) {
            $del = $pdo->prepare("DELETE FROM employees_payroll WHERE id=?");
            $ok  = $del->execute([$id]);
            flash($ok ? 'success' : 'error', $ok ? 'Employees-rate row deleted.' : 'Delete failed.');
        }
    } catch (Throwable $e) {
        error_log('delete_employee_mirror: '.$e->getMessage());
        flash('error','Error deleting employees-rate row.');
    }
    header("Location: ".$_SERVER['PHP_SELF']."#tab-employees");
    exit;
}

/* LOAD: Edit objects */
$editingCompany = null;
if (isset($_GET['edit_company'])) {
    $eid = (int)$_GET['edit_company'];
    if ($eid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM companies WHERE id=?");
        $stmt->execute([$eid]);
        $editingCompany = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$editingMirror = null;
if (isset($_GET['edit_employee_mirror'])) {
    $mid = (int)$_GET['edit_employee_mirror'];
    if ($mid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM employees_payroll WHERE id=?");
        $stmt->execute([$mid]);
        $editingMirror = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

/* ============================================================
   PAGE DATA
   ============================================================ */
try {
    $companies = $pdo->query("
        SELECT c.*, (SELECT COUNT(ep.id) FROM employees_payroll ep WHERE ep.company_ref_id=c.id) AS mirror_has
        FROM companies c ORDER BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $employeeMirrors = $pdo->query("
        SELECT ep.*, c.name AS base_company_name
        FROM employees_payroll ep
        JOIN companies c ON c.id = ep.company_ref_id
        ORDER BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die("Database Error: ".$e->getMessage());
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Companies & Employees Rates</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white">

<header class="p-6 bg-slate-900/70 backdrop-blur border-b border-slate-800 sticky top-0 z-20">
  <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 items-center gap-4">
    <div class="flex items-center">
      <h1 class="text-2xl font-bold tracking-tight">Client Companies & Employees Rates</h1>
    </div>

    <div class="flex justify-center">
      <div class="inline-flex rounded-xl overflow-hidden border border-slate-700" role="tablist">
        <a id="tab-companies" href="#tab-companies" class="px-4 py-2 bg-sky-600 hover:bg-sky-500 transition">Companies</a>
        <a id="tab-employees" href="#tab-employees" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 transition">Employees</a>
      </div>
    </div>

    <div class="flex justify-end">
      <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90">Return to Dashboard</a>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8 space-y-10">
  <?php if ($flash): ?>
    <div class="p-4 rounded-xl <?= $flash['type']==='error' ? 'bg-red-500/15 text-red-200 border border-red-700/40' : 'bg-emerald-500/15 text-emerald-200 border border-emerald-700/40' ?>">
      <?= h($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- ===================== COMPANIES ===================== -->
  <section id="panel-companies" class="space-y-8">
    <div class="grid lg:grid-cols-3 gap-8">
      <!-- Add / Edit Company card -->
      <div class="lg:col-span-1 bg-white/5 p-6 rounded-2xl border border-slate-700/50">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-lg <?= $editingCompany ? 'text-amber-300' : 'text-sky-400' ?>">
            <?= $editingCompany ? 'Edit Company' : 'Add New Company' ?>
          </h3>
          <?php if ($editingCompany): ?>
            <a href="<?= h($_SERVER['PHP_SELF']) ?>#tab-companies" class="text-xs px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-600">Cancel</a>
          <?php endif; ?>
        </div>

        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <?php if ($editingCompany): ?>
            <input type="hidden" name="update_company" value="1" />
            <input type="hidden" name="id" value="<?= (int)$editingCompany['id'] ?>" />
          <?php else: ?>
            <input type="hidden" name="add_company" value="1" />
          <?php endif; ?>

          <label class="block text-sm">Company Name *</label>
          <input name="company_name" type="text" required value="<?= $editingCompany ? h($editingCompany['name']) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600" />

          <div class="grid grid-cols-2 gap-3 text-xs">
            <div><label>Address</label><input name="address" type="text" value="<?= $editingCompany ? h((string)($editingCompany['address'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>
            <div><label>Contact Person</label><input name="contact_person" type="text" value="<?= $editingCompany ? h((string)($editingCompany['contact_person'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>
            <div class="col-span-2"><label>Contact Email</label><input name="contact_email" type="email" value="<?= $editingCompany ? h((string)($editingCompany['contact_email'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>

            <?php
              $fields = [
                'default_rate_8hr' => '8hr Rate', 'default_rate_12hr' => '12hr Rate',
                'default_sunday_rate' => 'Sunday Rate', 'default_ph_rate' => 'PH Rate',
                'default_hostel_fee' => 'Hostel Fee', 'default_utility_charges' => 'Utility Charges',
                'default_consultant_fee_per_head' => 'Consultant Fee/Head', 'default_back_pay' => 'Back Pay',
                'default_special_allowance' => 'Special Allowance', 'default_night_shift_allowance' => 'Night Shift Allow.',
                'default_deduction' => 'Deduction', 'default_insurance' => 'Insurance', 'default_ot_rate_9hr' => 'OT Rate (9hr)',
                'default_ot_rate_12hr' => 'OT Rate (12hr)', 'default_sunday_ph_ot_rate' => 'Sun/PH OT Rate',
                'default_ph_ot_rate' => 'PH OT Rate'
              ];
              foreach ($fields as $key => $label):
                $val = $editingCompany ? (isset($editingCompany[$key]) && $editingCompany[$key] !== null ? (string)$editingCompany[$key] : '') : '';
            ?>
              <div>
                <label><?= h($label) ?></label>
                <input name="<?= h($key) ?>" step="0.01" type="number" value="<?= h($val) ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600">
              </div>
            <?php endforeach; ?>
          </div>

          <button class="w-full p-2.5 rounded-lg <?= $editingCompany ? 'bg-amber-600 hover:bg-amber-500' : 'bg-sky-600 hover:bg-sky-500' ?> font-semibold">
            <?= $editingCompany ? 'Update Company' : 'Add Company' ?>
          </button>
        </form>
      </div>

      <!-- Companies list -->
      <div class="lg:col-span-2 bg-white/10 p-6 rounded-2xl border border-slate-700/50">
        <h3 class="font-semibold text-lg text-slate-300 mb-4">Existing Companies</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-slate-800 text-slate-300 uppercase text-xs">
              <tr>
                <th class="p-3">Company</th>
                <th class="p-3">Contact</th>
                <th class="p-3 text-center">Has Emp-Rates?</th>
                <th class="p-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($companies as $c): ?>
                <tr class="border-b border-slate-700/70 hover:bg-slate-800/40">
                  <td class="p-3 font-medium"><?= h($c['name']) ?></td>
                  <td class="p-3 text-slate-300">
                    <?php
                      $bits = array_filter([ $c['contact_person'] ?? null, $c['contact_email'] ?? null ]);
                      echo $bits ? h(implode(' · ', $bits)) : '<span class="text-slate-500">—</span>';
                    ?>
                  </td>
                  <td class="p-3 text-center"><?= ((int)$c['mirror_has'] > 0 ? 'Yes' : 'No') ?></td>
                  <td class="p-3">
                    <div class="flex items-center gap-2 justify-end">
                      <a href="<?= h($_SERVER['PHP_SELF']) ?>?edit_company=<?= (int)$c['id'] ?>#tab-companies" class="px-3 py-1.5 rounded-md bg-amber-600 hover:bg-amber-500">Edit</a>
                      <a href="<?= h($_SERVER['PHP_SELF']) ?>?delete_company=<?= (int)$c['id'] ?>#tab-companies"
                         class="px-3 py-1.5 rounded-md bg-rose-600 hover:bg-rose-500"
                         onclick="return confirm('Delete this company? This cannot be undone.');">Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$companies): ?>
                <tr><td colspan="4" class="p-4 text-center text-slate-400">No companies yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================== EMPLOYEES (MIRROR) ===================== -->
  <section id="panel-employees" class="hidden space-y-8">
    <div class="grid lg:grid-cols-3 gap-8">
      <!-- Add / Edit Mirror -->
      <div class="lg:col-span-1 bg-white/5 p-6 rounded-2xl border border-slate-700/50">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-lg <?= $editingMirror ? 'text-amber-300' : 'text-emerald-400' ?>">
            <?= $editingMirror ? 'Edit Employees-Rate Row' : 'Add Employees-Rate Row' ?>
          </h3>
          <?php if ($editingMirror): ?>
            <a href="<?= h($_SERVER['PHP_SELF']) ?>#tab-employees" class="text-xs px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-600">Cancel</a>
          <?php endif; ?>
        </div>

        <form method="post" class="space-y-3" id="mirror-form">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <?php if ($editingMirror): ?>
            <input type="hidden" name="update_employee_mirror" value="1" />
            <input type="hidden" name="id" value="<?= (int)$editingMirror['id'] ?>" />
          <?php else: ?>
            <input type="hidden" name="add_employee_mirror" value="1" />
          <?php endif; ?>

          <label class="block text-sm">Select Base Company *</label>
          <select name="company_ref_id" id="company_ref_id" required class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600">
            <option value="">— Select company —</option>
            <?php foreach ($companies as $c): ?>
              <option
                value="<?= (int)$c['id'] ?>"
                data-name="<?= h($c['name']) ?>"
                data-address="<?= h((string)($c['address'] ?? '')) ?>"
                data-contact-person="<?= h((string)($c['contact_person'] ?? '')) ?>"
                data-contact-email="<?= h((string)($c['contact_email'] ?? '')) ?>"
                <?= $editingMirror && (int)$editingMirror['company_ref_id'] === (int)$c['id'] ? 'selected' : '' ?>
              ><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="grid grid-cols-2 gap-3 text-xs">
            <div class="col-span-2">
              <label>Company Name (kept same)</label>
              <input name="company_name_mirror" id="company_name_mirror" type="text" required
                     value="<?= $editingMirror ? h($editingMirror['name']) : '' ?>"
                     class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600">
            </div>
            <div><label>Address</label><input name="address" id="address_mirror" type="text" value="<?= $editingMirror ? h((string)($editingMirror['address'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>
            <div><label>Contact Person</label><input name="contact_person" id="cp_mirror" type="text" value="<?= $editingMirror ? h((string)($editingMirror['contact_person'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>
            <div class="col-span-2"><label>Contact Email</label><input name="contact_email" id="ce_mirror" type="email" value="<?= $editingMirror ? h((string)($editingMirror['contact_email'] ?? '')) : '' ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600"></div>

            <?php
              $mfields = [
                'default_rate_8hr' => '8hr Rate', 'default_rate_12hr' => '12hr Rate',
                'default_sunday_rate' => 'Sunday Rate', 'default_ph_rate' => 'PH Rate',
                'default_hostel_fee' => 'Hostel Fee', 'default_utility_charges' => 'Utility Charges',
                'default_consultant_fee_per_head' => 'Consultant Fee/Head', 'default_back_pay' => 'Back Pay',
                'default_special_allowance' => 'Special Allowance', 'default_night_shift_allowance' => 'Night Shift Allow.',
                'default_deduction' => 'Deduction', 'default_insurance' => 'Insurance', 'default_ot_rate_9hr' => 'OT Rate (9hr)',
                'default_ot_rate_12hr' => 'OT Rate (12hr)', 'default_sunday_ph_ot_rate' => 'Sun/PH OT Rate',
                'default_ph_ot_rate' => 'PH OT Rate'
              ];
              foreach ($mfields as $key => $label):
                $val = $editingMirror ? (isset($editingMirror[$key]) && $editingMirror[$key] !== null ? (string)$editingMirror[$key] : '') : '';
            ?>
              <div>
                <label><?= h($label) ?></label>
                <input name="<?= h($key) ?>" step="0.01" type="number" value="<?= h($val) ?>" class="w-full p-2 rounded-md bg-slate-900/60 border border-slate-600">
              </div>
            <?php endforeach; ?>
          </div>

          <button class="w-full p-2.5 rounded-lg <?= $editingMirror ? 'bg-amber-600 hover:bg-amber-500' : 'bg-emerald-600 hover:bg-emerald-500' ?> font-semibold">
            <?= $editingMirror ? 'Update Employees-Rate Row' : 'Save Employees-Rate Row' ?>
          </button>
        </form>
      </div>

      <!-- Mirror list -->
      <div class="lg:col-span-2 bg-white/10 p-6 rounded-2xl border border-slate-700/50">
        <h3 class="font-semibold text-lg text-slate-300 mb-4">Employees-Rate Rows</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-slate-800 text-slate-300 uppercase text-xs">
              <tr>
                <th class="p-3">Company (Mirror)</th>
                <th class="p-3">Base Company</th>
                <th class="p-3 text-center">8hr</th>
                <th class="p-3 text-center">12hr</th>
                <th class="p-3 text-center">Sun</th>
                <th class="p-3 text-center">PH</th>
                <th class="p-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($employeeMirrors as $r): ?>
                <tr class="border-b border-slate-700 hover:bg-slate-800/40">
                  <td class="p-3 font-medium"><?= h($r['name']) ?></td>
                  <td class="p-3"><?= h($r['base_company_name']) ?></td>
                  <td class="p-3 text-center"><?= $r['default_rate_8hr'] !== null ? h((string)$r['default_rate_8hr']) : '<span class="text-slate-500">—</span>' ?></td>
                  <td class="p-3 text-center"><?= $r['default_rate_12hr'] !== null ? h((string)$r['default_rate_12hr']) : '<span class="text-slate-500">—</span>' ?></td>
                  <td class="p-3 text-center"><?= $r['default_sunday_rate'] !== null ? h((string)$r['default_sunday_rate']) : '<span class="text-slate-500">—</span>' ?></td>
                  <td class="p-3 text-center"><?= $r['default_ph_rate'] !== null ? h((string)$r['default_ph_rate']) : '<span class="text-slate-500">—</span>' ?></td>
                  <td class="p-3">
                    <div class="flex items-center gap-2 justify-end">
                      <a href="<?= h($_SERVER['PHP_SELF']) ?>?edit_employee_mirror=<?= (int)$r['id'] ?>#tab-employees" class="px-3 py-1.5 rounded-md bg-amber-600 hover:bg-amber-500">Edit</a>
                      <a href="<?= h($_SERVER['PHP_SELF']) ?>?delete_employee_mirror=<?= (int)$r['id'] ?>#tab-employees"
                         class="px-3 py-1.5 rounded-md bg-rose-600 hover:bg-rose-500"
                         onclick="return confirm('Delete this employees-rate row? This cannot be undone.');">Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$employeeMirrors): ?>
                <tr><td colspan="7" class="p-4 text-center text-slate-400">No employees-rate rows yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</main>

<script>
/* Tab toggle */
const tabCompanies = document.getElementById('tab-companies');
const tabEmployees = document.getElementById('tab-employees');
const panelCompanies = document.getElementById('panel-companies');
const panelEmployees = document.getElementById('panel-employees');

function setTabs(which) {
  const compActive = which === 'companies';
  panelCompanies.classList.toggle('hidden', !compActive);
  panelEmployees.classList.toggle('hidden', compActive);

  if (compActive) {
    tabCompanies.classList.replace('bg-slate-800','bg-sky-600');
    tabEmployees.classList.replace('bg-sky-600','bg-slate-800');
  } else {
    tabEmployees.classList.replace('bg-slate-800','bg-sky-600');
    tabCompanies.classList.replace('bg-sky-600','bg-slate-800');
  }
}

tabCompanies.addEventListener('click', (e) => { e.preventDefault(); setTabs('companies'); history.replaceState(null,'','#tab-companies'); });
tabEmployees.addEventListener('click', (e) => { e.preventDefault(); setTabs('employees'); history.replaceState(null,'','#tab-employees'); });

/* Auto-fill mirror fields from selected company (for ADD mode) */
const refSelect = document.getElementById('company_ref_id');
const nameMirror = document.getElementById('company_name_mirror');
const addrMirror = document.getElementById('address_mirror');
const cpMirror   = document.getElementById('cp_mirror');
const ceMirror   = document.getElementById('ce_mirror');

function hydrateMirrorFields() {
  const opt = refSelect ? refSelect.options[refSelect.selectedIndex] : null;
  if (!opt || !opt.dataset) return;
  if (nameMirror && !nameMirror.value) nameMirror.value = opt.dataset.name || '';
  if (addrMirror && !addrMirror.value) addrMirror.value = opt.dataset.address || '';
  if (cpMirror && !cpMirror.value) cpMirror.value = opt.getAttribute('data-contact-person') || '';
  if (ceMirror && !ceMirror.value) ceMirror.value = opt.getAttribute('data-contact-email') || '';
}
if (refSelect) refSelect.addEventListener('change', hydrateMirrorFields);

/* Deep link + auto-switch if in edit states */
if (location.hash === '#tab-employees' || <?= $editingMirror ? 'true' : 'false' ?>) {
  setTabs('employees');
} else {
  setTabs('companies');
}
</script>
</body>
</html>
