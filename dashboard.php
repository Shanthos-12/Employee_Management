<?php
session_start();
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$u = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link rel="icon" type="image/png" href="assets/sfi_logo.png">
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'custom-dark': '#272727',
            'custom-green': '#50D890',
            'custom-blue': '#4F98CA',
            'custom-light': '#EFFFFB',
          }
        }
      }
    }
  </script>
</head>
<body class="flex h-screen bg-custom-light">
  <!-- Sidebar -->
  <aside class="w-64 bg-custom-dark text-white flex flex-col">
    <div class="p-6 text-2xl font-bold border-b border-gray-700">Dashboard GREEN TON</div>
    <nav class="flex-1 p-4 space-y-2">
      <a href="#payroll" class="block px-4 py-2 rounded hover:bg-custom-blue">💰 Pay</a>
      <a href="#invoices" class="block px-4 py-2 rounded hover:bg-custom-blue">📑 Invoices</a>
      <a href="#employees" class="block px-4 py-2 rounded hover:bg-custom-blue">👨‍💼 Employees</a>
    </nav>
    <div class="p-4 border-t border-gray-700">
      <a href="logout.php" class="block px-4 py-2 bg-red-500 rounded text-center hover:bg-red-600">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 overflow-y-auto">
    <header class="bg-white shadow p-4 flex justify-between items-center">
      <h1 class="text-xl font-semibold text-custom-dark">Dashboard GREEN TON</h1>
      <span class="text-sm text-gray-600">Welcome, <?=$u['username']?> (<?=$u['role']?>)</span>
    </header>

    <!-- Content -->
    <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
      <div id="payroll" class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-lg font-bold mb-2 text-custom-dark">💰 Payroll Management</h2>
        <p class="text-gray-600 mb-4">Manage salaries and staff payments</p>
        <a href="payroll.php" class="px-4 py-2 bg-custom-green text-custom-dark font-semibold rounded hover:bg-opacity-80">Open</a>
      </div>

      <div id="invoices" class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-lg font-bold mb-2 text-custom-dark">📑 Invoice Management</h2>
        <p class="text-gray-600 mb-4">Track and generate </p>
        <a href="invoices.php" class="px-4 py-2 bg-custom-green text-custom-dark font-semibold rounded hover:bg-opacity-80">Open</a>
      </div>

      

      <div id="employees" class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-lg font-bold mb-2 text-custom-dark">👨‍💼 Employee Management</h2>
        <p class="text-gray-600 mb-4">View, edit, and add employees</p>
        <a href="employees.php" class="px-4 py-2 bg-custom-green text-custom-dark font-semibold rounded hover:bg-opacity-80">Open</a>
      </div>
    </div>
  </main>
</body>
</html>