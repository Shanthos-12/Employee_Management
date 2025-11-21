<?php
/******************************************************************
 * Employee Management — v2.0 with Local/Foreign Employee Type
 ******************************************************************/
session_start();
require_once 'db_conn.php';

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$userRole = $_SESSION['user']['role'] ?? 'guest';
$pdo = getPDO();

// Retrieve and clear session-based form data and errors
$form_errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);


/* ---------- CONFIG: uploads ---------- */
define('EMP_UPLOAD_DIR', __DIR__ . '/uploads/employees');
define('EMP_PUBLIC_PREFIX', 'uploads/employees');
if (!is_dir(EMP_UPLOAD_DIR)) {
    @mkdir(EMP_UPLOAD_DIR, 0775, true);
}

/* ---------- helpers ---------- */
function v($key, $default = null)
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}
function nullIfEmpty($s)
{
    $s = is_string($s) ? trim($s) : $s;
    return ($s === '' ? null : $s);
}
function safeFileName(string $ext): string
{
    return date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}
function deletePhotoIfExists(?string $path): void
{
    if (!$path) return;
    $full = __DIR__ . '/' . ltrim($path, '/');
    if (is_file($full)) @unlink($full);
}

function handleEmployeePhotoUpload(?string $existingPath = null): array
{
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, $existingPath];
    }
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload failed (error code ' . $file['error'] . ').'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [false, 'Image too large. Max size is 5MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return [false, 'Invalid image type. Allowed: PNG, JPG, WEBP.'];
    }
    $ext = $allowed[$mime];
    $name = safeFileName($ext);
    $target = rtrim(EMP_UPLOAD_DIR, '/') . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return [false, 'Failed to save uploaded image.'];
    }
    if ($existingPath && $existingPath !== (EMP_PUBLIC_PREFIX . '/' . $name)) {
        deletePhotoIfExists($existingPath);
    }
    return [true, EMP_PUBLIC_PREFIX . '/' . $name];
}

function formatToMalaysianTime($dateStr)
{
    if (!$dateStr) return '—';
    try {
        $date = new DateTime($dateStr, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
        return $date->format('d-m-Y h:i A');
    } catch (Exception $e) {
        return '—';
    }
}

function validateEmployeePhoto(string $fileKey): ?string
{
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fileKey];
    $fileName = htmlspecialchars($file['name']);
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "Upload failed for '{$fileName}' (error code: {$file['error']}).";
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return "File '{$fileName}' is too large. Max size is 5MB.";
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'])) {
        return "Invalid file type for '{$fileName}'. Allowed types: PNG, JPG, WEBP.";
    }
    return null;
}


/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = v('name');
    $email = nullIfEmpty(v('email'));
    $position = nullIfEmpty(v('position'));
    $department = nullIfEmpty(v('department'));
    $employee_type = nullIfEmpty(v('employee_type'));
    if ($employee_type === 'Foreign') {
        $employee_type = 'Foreign'; // Ensure consistency with dropdown
    }
    $passport_no = nullIfEmpty(v('passport_no'));
    $identity_card_no = nullIfEmpty(v('identity_card_no'));
    $phone = nullIfEmpty(v('phone'));
    $join_year = v('join_year') === '' ? null : (int) v('join_year');
    $company_id = (int) ($_POST['company_id'] ?? 0); // New field for company ID

    // ==========================================================
    // Validation Logic
    // ==========================================================
    $errors = [];
    $id = (int) ($_POST['update_id'] ?? 0);

    // --- Duplicate Checks ---
    if ($email) {
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) { $errors[] = "This email address is already in use."; }
    }
    if ($phone) {
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE phone = ? AND id != ?");
        $stmt->execute([$phone, $id]);
        if ($stmt->fetch()) { $errors[] = "This phone number is already in use."; }
    }

    // --- Field format and length validation ---
    if (empty($name)) { $errors[] = "Name is required."; }
    if (empty($employee_type)) { $errors[] = "Employee Type (Local/Foreign) is required."; }
    if (!in_array($employee_type, ['Local', 'Foreign'])) { $errors[] = "Invalid Employee Type selected."; }
    
    // --- Conditional Validation for ID/Passport ---
    if ($employee_type === 'Local' && empty($identity_card_no)) { $errors[] = "Identity Card No is required for Local employees."; }
    if ($employee_type === 'Foreign' && empty($passport_no)) { $errors[] = "Passport No is required for Foreign employees."; }

    if (strlen($name) > 100) { $errors[] = "Name cannot be longer than 100 characters."; }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "A valid email address is required."; }
    if ($phone && !preg_match('/^[+\d\s-]{7,15}$/', $phone)) { $errors[] = "A valid phone number is required (7-15 digits)."; }
    if ($join_year && ($join_year < 1950 || $join_year > (int) date('Y'))) { $errors[] = "Please enter a valid 4-digit join year."; }
    if (strlen($position ?? '') > 100) { $errors[] = "Position cannot be longer than 100 characters."; }
    if (strlen($department ?? '') > 100) { $errors[] = "Department cannot be longer than 100 characters."; }
    if (strlen($passport_no ?? '') > 50) { $errors[] = "Passport No cannot be longer than 50 characters."; }
    if (strlen($identity_card_no ?? '') > 50) { $errors[] = "Identity Card No cannot be longer than 50 characters."; }

    if ($error = validateEmployeePhoto('photo')) { $errors[] = "Employee Photo: " . $error; }
    if ($company_id <= 0) { $errors[] = "Please select a valid company."; }

    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: employees.php");
        exit;
    }

    // --- (Logic only runs if validation passes) ---
    if ($action === 'update') {
        if ($id > 0) {
            $stmt0 = $pdo->prepare("SELECT photo_path FROM employees WHERE id=?");
            $stmt0->execute([$id]);
            $existingPhoto = ($stmt0->fetch(PDO::FETCH_ASSOC)['photo_path']) ?? null;

            [$ok, $photoOrErr] = handleEmployeePhotoUpload($existingPhoto);
            if (!$ok) { header("Location: employees.php?error=" . urlencode($photoOrErr)); exit; }
            $photo_path = $photoOrErr;

            $stmt = $pdo->prepare(
                "UPDATE employees SET name=?, email=?, position=?, department=?, employee_type=?, passport_no=?, identity_card_no=?, join_year=?, phone=?, photo_path=?, company_id=? WHERE id=?"
            );
            $stmt->execute([$name, $email, $position, $department, $employee_type, $passport_no, $identity_card_no, $join_year, $phone, $photo_path, $company_id, $id]);

            header("Location: employees.php?updated=1");
            exit;
        }
    } elseif ($action === 'add') {
        [$ok, $photoOrErr] = handleEmployeePhotoUpload(null);
        if (!$ok) { header("Location: employees.php?error=" . urlencode($photoOrErr)); exit; }
        $photo_path = $photoOrErr;

        $stmt = $pdo->prepare(
            "INSERT INTO employees (name, email, position, department, employee_type, passport_no, identity_card_no, join_year, phone, photo_path, company_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$name, $email, $position, $department, $employee_type, $passport_no, $identity_card_no, $join_year, $phone, $photo_path, $company_id]);
        
        header("Location: employees.php?success=1");
        exit;
    }
}

/* ---------- GET delete ---------- */
if (isset($_GET['delete'])) {
    if ($userRole === 'superadmin') {
        $id = (int) $_GET['delete'];
        if ($id > 0) {
            $stmt0 = $pdo->prepare("SELECT photo_path FROM employees WHERE id=?");
            $stmt0->execute([$id]);
            $photoPath = ($stmt0->fetch(PDO::FETCH_ASSOC)['photo_path']) ?? null;
            
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            deletePhotoIfExists($photoPath);

            header("Location: employees.php?deleted=1");
            exit;
        }
    } else {
        header("Location: employees.php?error=permission_denied");
        exit;
    }
}

/* ---------- Page Data ---------- */
$metrics = ['total' => (int) $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(), 'this_year' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE join_year = YEAR(CURDATE())")->fetchColumn()];
$deptCounts = $pdo->query("SELECT COALESCE(department,'(Unassigned)') as dept, COUNT(*) as c FROM employees GROUP BY COALESCE(department,'(Unassigned)') ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
$employees = $pdo->query("SELECT * FROM employees ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch companies for the dropdown
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
  <meta charset="UTF-8">
  <title>SFI GLOBAL EMPLOYEE MANAGEMENT</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white min-h-screen">
  <header class="p-6 flex justify-between items-center bg-slate-900/70 backdrop-blur border-b border-slate-800 sticky top-0 z-10">
    <h1 class="text-2xl font-bold flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg><span>SFI GLOBAL EMPLOYEE MANAGEMENT</span></h1>
    <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg><span>Dashboard</span></a>
  </header>
  <main class="p-6 max-w-7xl mx-auto space-y-8">
    <!-- Alerts -->
    <?php if (isset($_GET['success'])): ?><div class="p-3 bg-emerald-500/20 text-emerald-300 rounded-lg flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span>Employee added successfully!</span></div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="p-3 bg-sky-500/20 text-sky-300 rounded-lg flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span>Employee updated successfully!</span></div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="p-3 bg-red-500/20 text-red-300 rounded-lg flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span>Employee deleted successfully!</span></div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="p-3 bg-amber-500/20 text-amber-200 rounded-lg">Error: <?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
    <?php if (!empty($form_errors)): ?>
      <div class="p-4 bg-red-500/20 text-red-300 rounded-lg space-y-1">
        <p class="font-semibold">Please fix the following errors:</p>
        <ul class="list-disc list-inside text-sm">
          <?php foreach ($form_errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Dashboard Widgets -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-white/5 border border-white/10 p-5 shadow-glass"><div class="text-sm text-slate-400">Total Employees</div><div class="mt-2 text-3xl font-bold"><?= (int) $metrics['total'] ?></div></div>
      <div class="rounded-2xl bg-white/5 border border-white/10 p-5 shadow-glass"><div class="text-sm text-slate-400">Joined This Year (<?= date('Y') ?>)</div><div class="mt-2 text-3xl font-bold"><?= (int) $metrics['this_year'] ?></div></div>
      <div class="rounded-2xl bg-white/5 border border-white/10 p-5 shadow-glass"><div class="text-sm text-slate-400">Top Departments</div><div class="mt-2 flex flex-wrap gap-2"><?php foreach ($deptCounts as $d): ?><span class="px-3 py-1 rounded-full bg-slate-800/70 border border-slate-700 text-sm"><?= htmlspecialchars($d['dept']) ?> <span class="text-slate-400">(<?= (int) $d['c'] ?>)</span></span><?php endforeach; ?><?php if (!$deptCounts): ?><span class="text-slate-400">No data</span><?php endif; ?></div></div>
    </section>

    <!-- Add/Edit Form -->
    <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
      <h2 id="form-title" class="text-xl font-bold mb-4 flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span>Add New Employee</span></h2>
      <form id="employeeForm" method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="action" value="add" id="formAction"><input type="hidden" name="update_id" id="employeeId" value=""><input type="hidden" name="existing_photo" id="existingPhoto" value="">

        <div><label class="text-sm">Name *</label><input name="name" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" required value="<?= htmlspecialchars($form_data['name'] ?? '') ?>"></div>
        <div><label class="text-sm">Email</label><input type="email" name="email" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['email'] ?? '') ?>"></div>
        <div><label class="text-sm">Position</label><input name="position" maxlength="100" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['position'] ?? '') ?>"></div>
        <div><label class="text-sm">Department</label><input name="department" maxlength="100" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['department'] ?? '') ?>"></div>
        
        <!-- NEW Employee Type Dropdown -->
        <div>
            <label for="employee_type" class="text-sm">Employee Type *</label>
            <select name="employee_type" id="employee_type" required class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none">
                <option value="">-- Select Type --</option>
                <option value="Local" <?= (($form_data['employee_type'] ?? 'Local') === 'Local') ? 'selected' : '' ?>>Local</option>
                <option value="Foreign" <?= (($form_data['employee_type'] ?? '') === 'Foreign') ? 'selected' : '' ?>>Foreign</option>
            </select>
        </div>

        <div><label class="text-sm">Phone</label><input type="tel" name="phone" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['phone'] ?? '') ?>"></div>
        
        <!-- Conditional Identity Card No -->
        <div id="identity_card_no_field"><label class="text-sm">Identity Card No *</label><input name="identity_card_no" maxlength="50" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['identity_card_no'] ?? '') ?>"></div>
        
        <!-- Conditional Passport No -->
        <div id="passport_no_field" class="<?= ($form_data['employee_type'] ?? '') === 'Foreign' ? '' : 'hidden' ?>">
            <label class="text-sm">Passport No *</label>
            <input name="passport_no" maxlength="50" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" value="<?= htmlspecialchars($form_data['passport_no'] ?? '') ?>">
        </div>

        <div><label class="text-sm">Join Year</label><input type="number" name="join_year" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" placeholder="<?= date('Y') ?>" value="<?= htmlspecialchars($form_data['join_year'] ?? '') ?>"></div>
        
        <!-- NEW Company Dropdown -->
        <div>
            <label for="company_id" class="text-sm">Company *</label>
            <select name="company_id" id="company_id" required class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none">
                <option value="">-- Select Company --</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= $company['id'] ?>" <?= ($form_data['company_id'] ?? '') == $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="md:col-span-3"><label class="text-sm">Employee Photo (PNG/JPG/WEBP, max 5MB)</label><div class="mt-2 flex items-center gap-4"><img id="photoPreview" src="" alt="Preview" class="w-20 h-20 rounded-full object-cover border border-slate-700 hidden"><input type="file" name="photo" accept="image/png,image/jpeg,image/webp" class="file:mr-3 file:px-3 file:py-2 file:rounded file:border-0 file:bg-sky-600 file:text-white file:cursor-pointer bg-slate-800 border border-slate-600 rounded p-2 w-full" onchange="previewPhoto(this)"></div></div>
        <div class="md:col-span-3 flex items-center gap-4"><button type="submit" id="saveButton" class="px-6 py-2 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-lg hover:opacity-90 font-semibold transition-all duration-300">Save Employee</button><button type="button" id="cancelButton" onclick="resetForm()" class="px-6 py-2 bg-gray-600 hover:bg-gray-500 rounded-lg font-semibold hidden">Cancel</button></div>
      </form>
    </section>

    <!-- Card View & Table View -->
    <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 12h14M5 16h14" /></svg><span>Team Directory</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($employees as $e):
            // Debugging: Let's see what we actually have in the database
            $rawType = $e['employee_type'] ?? 'NULL';
            $isForeign = (strtoupper($rawType) === 'FOREIGN');
            $idLabel = $isForeign ? 'Passport No' : 'Identity Card No';
            $idValue = $isForeign ? ($e['passport_no'] ?? '—') : ($e['identity_card_no'] ?? '—');
            
            // Debug output (remove this after testing)
            echo "<!-- Debug: Name={$e['name']}, Type={$rawType}, IsForeign=" . ($isForeign ? 'YES' : 'NO') . ", PassportNo={$e['passport_no']}, IdCardNo={$e['identity_card_no']} -->";
            
            $img = $e['photo_path'] ?: 'https://api.dicebear.com/7.x/initials/svg?seed=' . urlencode($e['name'] ?? 'User'); ?>
            <div class="rounded-2xl bg-slate-900/60 border border-slate-800 p-5 hover:border-slate-700 transition">
                <div class="flex items-center gap-4"><img src="<?= htmlspecialchars($img) ?>" class="w-16 h-16 rounded-full object-cover border border-slate-700" alt="photo">
                <div>
                    <div class="text-lg font-semibold"><?= htmlspecialchars($e['name']) ?></div>
                    <div class="text-slate-400 text-sm"><?= htmlspecialchars($e['position'] ?? '—') ?></div>
                    <div class="text-xs px-2 py-0.5 mt-1 rounded-full inline-block <?= $isForeign ? 'bg-sky-500/20 text-sky-300' : 'bg-emerald-500/20 text-emerald-300' ?>"><?= htmlspecialchars($e['employee_type'] ?? 'Local') ?></div>
                </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div class="p-3 rounded-xl bg-white/5 border border-white/10"><div class="text-slate-400">Department</div><div class="font-medium"><?= htmlspecialchars($e['department'] ?? '—') ?></div></div>
                    <div class="p-3 rounded-xl bg-white/5 border border-white/10"><div class="text-slate-400">Joined</div><div class="font-medium"><?= htmlspecialchars((string) ($e['join_year'] ?? '—')) ?></div></div>
                    <div class="p-3 rounded-xl bg-white/5 border border-white/10 col-span-2"><div class="text-slate-400">Contact</div><div class="font-medium"><?= htmlspecialchars($e['email'] ?? '—') ?></div><div class="text-slate-400 text-xs"><?= htmlspecialchars($e['phone'] ?? '') ?></div></div>
                    <div class="p-3 rounded-xl bg-white/5 border border-white/10 col-span-2"><div class="text-slate-400"><?= htmlspecialchars($idLabel) ?></div><div class="font-medium"><?= htmlspecialchars($idValue) ?></div></div>
                </div>
                <div class="mt-4 flex items-center gap-2">
                <a href="#" onclick='editEmployee(<?= json_encode($e, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>); return false;' class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-sky-600 hover:bg-sky-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg>Edit</a>
                <?php if ($userRole === 'superadmin'): ?><a href="employees.php?delete=<?= (int) $e['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-red-600 hover:bg-red-700" onclick="return confirm('Delete this employee? Photo file will also be removed.')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>Delete</a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?><?php if (empty($employees)): ?><div class="text-slate-400">No employees yet.</div><?php endif; ?>
        </div>
    </section>

    <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
      <h2 class="text-xl font-bold mb-4 flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg><span>Employee List</span></h2>
      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left">
          <thead class="bg-slate-800 text-slate-300">
            <tr>
              <th class="p-3">Photo</th><th class="p-3">Name</th><th class="p-3">Contact</th>
              <th class="p-3">Position</th><th class="p-3">ID / Passport No</th><th class="p-3">Joined</th><th class="p-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $e):
              // Determine ID values for this employee in the table view
              $isForeign = (strtoupper($e['employee_type'] ?? '') === 'FOREIGN');
              $idValue = $isForeign ? ($e['passport_no'] ?? '') : ($e['identity_card_no'] ?? '');
              $img = $e['photo_path'] ?: 'https://api.dicebear.com/7.x/initials/svg?seed=' . urlencode($e['name'] ?? 'User'); ?>
              <tr class="hover:bg-slate-700/40 border-b border-slate-700">
                <td class="p-3 align-top"><img src="<?= htmlspecialchars($img) ?>" class="w-10 h-10 rounded-full object-cover border border-slate-700" alt="photo"></td>
                <td class="p-3 align-top">
                    <?= htmlspecialchars($e['name']) ?>
                    <div class="text-xs px-2 py-0.5 mt-1 rounded-full inline-block <?= $isForeign ? 'bg-sky-500/20 text-sky-300' : 'bg-emerald-500/20 text-emerald-300' ?>">
                        <?= htmlspecialchars($e['employee_type'] ?? 'Local') ?>
                    </div>
                </td>
                <td class="p-3 align-top"><div class="text-sm"><?= htmlspecialchars($e['email'] ?? '') ?></div><div class="text-xs text-slate-400"><?= htmlspecialchars($e['phone'] ?? '') ?></div></td>
                <td class="p-3 align-top"><?= htmlspecialchars($e['position'] ?? '') ?></td>
                <td class="p-3 align-top"><?= htmlspecialchars($idValue) ?></td>
                <td class="p-3 align-top"><?= htmlspecialchars((string) ($e['join_year'] ?? '')) ?></td>
                <td class="p-3 align-top">
                  <div class="flex items-center gap-2">
                    <a href="#" onclick='editEmployee(<?= json_encode($e, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>); return false;' class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-sky-600 hover:bg-sky-700"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg>Edit</a>
                    <?php if ($userRole === 'superadmin'): ?><a href="employees.php?delete=<?= (int) $e['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-red-600 hover:bg-red-700" onclick="return confirm('Are you sure you want to delete this employee?')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>Delete</a><?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($employees)): ?><tr><td colspan="7" class="p-4 text-center text-slate-400">No employees have been added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script>
    // --- DOM Elements ---
    const form = document.getElementById('employeeForm');
    const formTitle = document.getElementById('form-title').querySelector('span');
    const formAction = document.getElementById('formAction');
    const employeeIdInput = document.getElementById('employeeId');
    const existingPhotoInput = document.getElementById('existingPhoto');
    const saveButton = document.getElementById('saveButton');
    const cancelButton = document.getElementById('cancelButton');
    const photoPreview = document.getElementById('photoPreview');
    const employeeTypeSelect = document.getElementById('employee_type');
    const idCardField = document.getElementById('identity_card_no_field');
    const passportField = document.getElementById('passport_no_field');
    const idCardInput = form.querySelector('[name="identity_card_no"]');
    const passportInput = form.querySelector('[name="passport_no"]');

    // --- Functions ---
    function previewPhoto(input) {
        const file = input.files?.[0];
        if (!file) { photoPreview.classList.add('hidden'); return; }
        const allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!allowed.includes(file.type)) { alert('Allowed: PNG, JPG, WEBP'); input.value = ''; return; }
        if (file.size > 5 * 1024 * 1024) { alert('Max file size is 5MB'); input.value = ''; return; }
        const url = URL.createObjectURL(file);
        photoPreview.src = url;
        photoPreview.classList.remove('hidden');
    }

    function updateIdFieldsVisibility() {
        const selectedType = employeeTypeSelect.value;
        if (selectedType === 'Local') {
            idCardField.classList.remove('hidden');
            passportField.classList.add('hidden');
            passportInput.value = ''; // Clear the hidden field
        } else if (selectedType === 'Foreign') {
            idCardField.classList.add('hidden');
            passportField.classList.remove('hidden');
            idCardInput.value = ''; // Clear the hidden field
        } else { // Handle default case (nothing selected)
            idCardField.classList.remove('hidden');
            passportField.classList.add('hidden');
        }
    }

    function editEmployee(employeeData) {
        form.querySelector('[name="name"]').value = employeeData.name ?? '';
        form.querySelector('[name="email"]').value = employeeData.email ?? '';
        form.querySelector('[name="position"]').value = employeeData.position ?? '';
        form.querySelector('[name="department"]').value = employeeData.department ?? '';
        form.querySelector('[name="phone"]').value = employeeData.phone ?? '';
        form.querySelector('[name="passport_no"]').value = employeeData.passport_no ?? '';
        form.querySelector('[name="identity_card_no"]').value = employeeData.identity_card_no ?? '';
        form.querySelector('[name="join_year"]').value = employeeData.join_year ?? '';
        
        // Set employee type and trigger visibility update
        employeeTypeSelect.value = employeeData.employee_type ?? 'Local';
        updateIdFieldsVisibility();

        const img = employeeData.photo_path || '';
        if (img) {
            photoPreview.src = img;
            photoPreview.classList.remove('hidden');
            existingPhotoInput.value = img;
        } else {
            photoPreview.classList.add('hidden');
            existingPhotoInput.value = '';
        }
        form.querySelector('input[type="file"][name="photo"]').value = '';
        
        formTitle.textContent = 'Edit Employee';
        formAction.value = 'update';
        employeeIdInput.value = employeeData.id;
        saveButton.textContent = 'Update Employee';
        saveButton.classList.remove('from-emerald-500', 'to-teal-500');
        saveButton.classList.add('from-sky-500', 'to-indigo-500');
        cancelButton.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function resetForm() {
        form.reset();
        photoPreview.classList.add('hidden');
        existingPhotoInput.value = '';
        formTitle.textContent = 'Add New Employee';
        formAction.value = 'add';
        employeeIdInput.value = '';
        saveButton.textContent = 'Save Employee';
        saveButton.classList.add('from-emerald-500', 'to-teal-500');
        saveButton.classList.remove('from-sky-500', 'to-indigo-500');
        cancelButton.classList.add('hidden');
        
        // Reset to default visibility
        employeeTypeSelect.value = 'Local';
        updateIdFieldsVisibility();
    }

    // --- Event Listeners ---
    employeeTypeSelect.addEventListener('change', updateIdFieldsVisibility);

    // --- Initial Run ---
    document.addEventListener('DOMContentLoaded', updateIdFieldsVisibility);
  </script>
</body>
</html>