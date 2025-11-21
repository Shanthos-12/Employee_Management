<?php
session_start();
require_once 'db_conn.php';
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();

// ==========================================================
// THE FIX: Handle employee UPDATE only if update_id is NOT EMPTY
// We change isset() to !empty() to ensure the ID has a value.
// ==========================================================
if (!empty($_POST['update_id'])) {
    $id = (int)$_POST['update_id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $position = $_POST['position'];
    $department = $_POST['department'];
    $passport_no = $_POST['passport_no'];
    $join_year = $_POST['join_year'];
    $phone = $_POST['phone'];
    $employeeId = $_POST['employeeId'];

    $stmt = $pdo->prepare(
        "UPDATE employees SET name=?, email=?, position=?, department=?, passport_no=?, join_year=?, phone=? 
         WHERE id=?"
    );
    $stmt->execute([$name,$employeeId, $email, $position, $department, $passport_no, $join_year, $phone, $id]);
    header("Location: employees.php?updated=1");
    exit;
}


// Add employee (This will now work correctly)
if (isset($_POST['add'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $position = $_POST['position'];
    $department = $_POST['department'];
    $passport_no = $_POST['passport_no'];
    $join_year = $_POST['join_year'];
    $phone = $_POST['phone'];
    $employeeId = $_POST['employeeId'];

    $stmt = $pdo->prepare(
        "INSERT INTO employees (name, employeeId, email, position, department, passport_no, join_year, phone) 
         VALUES (?, ?, ?, ?, ?, ?, ?,?)"
    );
    $stmt->execute([$name, $employeeId, $email, $position, $department, $passport_no, $join_year, $phone]);

    header("Location: employees.php?success=1");
    exit;
}

// Delete employee (Existing logic)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
    header("Location: employees.php?deleted=1");
    exit;
}

// Fetch all employees (Existing logic)
$employees = $pdo->query("SELECT * FROM employees ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Employee Management</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white min-h-screen">

  <header class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-10">
    <h1 class="text-2xl font-bold flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <span>Employee Management</span>
    </h1>
    <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
        <span>Dashboard</span>
    </a>
  </header>

  <main class="p-6 max-w-7xl mx-auto space-y-6">

    <?php if (isset($_GET['success'])): ?>
      <div class="p-3 bg-emerald-500/20 text-emerald-300 rounded-lg flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span>Employee added successfully!</span>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
      <div class="p-3 bg-sky-500/20 text-sky-300 rounded-lg flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span>Employee updated successfully!</span>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <div class="p-3 bg-red-500/20 text-red-300 rounded-lg flex items-center gap-3">
         <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span>Employee deleted successfully!</span>
      </div>
    <?php endif; ?>

    <!-- Add/Edit Employee Form -->
    <div class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
      <h2 id="form-title" class="text-xl font-bold mb-4 flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span>Add New Employee</span>
      </h2>
      <form id="employeeForm" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="add" value="1" id="formAction">
        <input type="hidden" name="update_id" id="employeeId">

        <div><label class="text-sm">Name</label><input name="name" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" required></div>
        <div><label class="text-sm">Employee ID</label><input name="employeeId" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" required></div>
        <div><label class="text-sm">Email</label><input type="email" name="email" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none"></div>
        <div><label class="text-sm">Position</label><input name="position" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none"></div>
        <div><label class="text-sm">Department</label><input name="department" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none"></div>
        <div><label class="text-sm">Phone</label><input name="phone" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none"></div>
        <div><label class="text-sm">Passport No</label><input name="passport_no" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none"></div>
        <div><label class="text-sm">Join Year</label><input type="number" name="join_year" class="w-full mt-1 p-2 rounded bg-slate-800 border border-slate-600 focus:ring-2 focus:ring-sky-500 outline-none" placeholder="<?= date('Y') ?>"></div>
        
        <div class="md:col-span-3 flex items-center gap-4">
          <button id="saveButton" class="px-6 py-2 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-lg hover:opacity-90 font-semibold transition-all duration-300">Save Employee</button>
          <button type="button" id="cancelButton" onclick="resetForm()" class="px-6 py-2 bg-gray-600 hover:bg-gray-500 rounded-lg font-semibold hidden">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Employee List -->
    <div class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
            <span>Employee List</span>
        </h2>
      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left">
          <thead class="bg-slate-800 text-slate-300">
            <tr>
              <th class="p-3">Name</th><th class="p-3">Contact</th><th class="p-3">Position</th>
              <th class="p-3">Department</th><th class="p-3">Passport No</th><th class="p-3">Joined</th><th class="p-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($employees as $e): ?>
              <tr class="hover:bg-slate-700/40 border-b border-slate-700">
                <td class="p-3 align-top"><?= htmlspecialchars($e['name']) ?></td>
                <td class="p-3 align-top">
                    <div class="text-sm"><?= htmlspecialchars($e['email'] ?? '') ?></div>
                    <div class="text-xs text-slate-400"><?= htmlspecialchars($e['phone'] ?? '') ?></div>
                </td>
                <td class="p-3 align-top"><?= htmlspecialchars($e['position'] ?? '') ?></td>
                <td class="p-3 align-top"><?= htmlspecialchars($e['department'] ?? '') ?></td>
                <td class="p-3 align-top"><?= htmlspecialchars($e['passport_no'] ?? '') ?></td>
                <td class="p-3 align-top"><?= htmlspecialchars((string)($e['join_year'] ?? '')) ?></td>
                <td class="p-3 align-top">
                    <div class="flex items-center gap-2">
                        <a href="#" onclick='editEmployee(<?= json_encode($e) ?>); return false;' class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-sky-600 hover:bg-sky-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg>
                            Edit
                        </a>
                        <a href="employees.php?delete=<?= $e['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-md bg-red-600 hover:bg-red-700" onclick="return confirm('Are you sure you want to delete this employee?')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                            Delete
                        </a>
                    </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($employees)): ?>
              <tr><td colspan="7" class="p-4 text-center text-slate-400">No employees have been added yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <script>
    const form = document.getElementById('employeeForm');
    const formTitle = document.getElementById('form-title').querySelector('span');
    const formAction = document.getElementById('formAction');
    const employeeIdInput = document.getElementById('employeeId');
    const saveButton = document.getElementById('saveButton');
    const cancelButton = document.getElementById('cancelButton');

    function editEmployee(employeeData) {
        form.querySelector('[name="name"]').value = employeeData.name ?? '';
        form.querySelector('[name="email"]').value = employeeData.email ?? '';
        form.querySelector('[name="position"]').value = employeeData.position ?? '';
        form.querySelector('[name="department"]').value = employeeData.department ?? '';
        form.querySelector('[name="phone"]').value = employeeData.phone ?? '';
        form.querySelector('[name="passport_no"]').value = employeeData.passport_no ?? '';
        form.querySelector('[name="join_year"]').value = employeeData.join_year ?? '';

        formTitle.textContent = 'Edit Employee';
        // THE FIX IS HERE IN JAVASCRIPT TOO
        // We remove the 'add' input completely to avoid conflicts
        formAction.removeAttribute('name'); 
        employeeIdInput.value = employeeData.id;

        saveButton.textContent = 'Update Employee';
        saveButton.classList.remove('from-emerald-500', 'to-teal-500');
        saveButton.classList.add('from-sky-500', 'to-indigo-500');
        cancelButton.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function resetForm() {
        form.reset();
        formTitle.textContent = 'Add New Employee';
        // Restore the 'add' input
        formAction.setAttribute('name', 'add');
        employeeIdInput.value = '';

        saveButton.textContent = 'Save Employee';
        saveButton.classList.add('from-emerald-500', 'to-teal-500');
        saveButton.classList.remove('from-sky-500', 'to-indigo-500');
        cancelButton.classList.add('hidden');
    }
  </script>

</body>
</html>