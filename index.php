<?php
session_start();
// This is the new session guard for all protected pages.
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
    // Restoring your custom color palette for the dashboard theme
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'custom-light': '#EFFFFB',
            'custom-green': '#50D890',
            'custom-blue': '#4F98CA',
            'custom-dark': '#272727',
          }
        }
      }
    }
  </script>
  <style>
    /* Custom transition for smooth slide-in/out */
    #sidebar,
    #main-content,
    #sidebar-toggle {
      transition: all 350ms cubic-bezier(0.4, 0, 0.2, 1);
    }
  </style>
</head>

<body class="relative min-h-screen bg-custom-light overflow-x-hidden">
  <!-- Sidebar -->
  <aside id="sidebar"
    class="w-64 fixed top-0 left-0 h-full bg-gradient-to-b from-custom-dark to-gray-900 text-custom-light flex flex-col z-20">
    <div class="p-6 text-2xl font-bold border-b border-gray-700 flex items-center justify-center shrink-0">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-2 text-custom-green" fill="none" viewBox="0 0 24 24"
        stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
          d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
      </svg>
      <span class="sidebar-text">Admin Portal</span>
    </div>
    <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
      <a href="payroll.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white"><svg
          class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
          stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
        </svg><span class="ml-4 sidebar-text">Payroll</span></a>
      <a href="invoice.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white"><svg
          class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
          stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
        </svg><span class="ml-4 sidebar-text">Invoices</span></a>
      <a href="employees.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white"><svg
          class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
          stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
        </svg><span class="ml-4 sidebar-text">Employees</span></a>

      <!-- Conditional Link for User Management -->
      <?php if ($u['role'] === 'superadmin'): ?>
        <a href="user_management.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24"
            stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2" />
          </svg>
          <span class="ml-4 sidebar-text">User Management</span>
        </a>
      <?php endif; ?>
      <!-- NEW LINK FOR MONTHLY SUMMARY -->
      <a href="monthly_summary.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24"
          stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <span class="sidebar-text">Summary Report</span>
      </a>
      <!-- NEW LINK FOR GENERATE SUMMARY -->
      <a href="generate_summary.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24"
          stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2M9 12l2 2 4-4m5.586-3.586a2 2 0 012.828 0l1.414 1.414a2 2 0 010 2.828l-4.828 4.828a2 2 0 01-1.414.586H9v-4z" />
        </svg>
        <span class="sidebar-text">Generate Summary</span>
      </a>
      <!-- NEW LINK FOR ACCOUNTANT MASTER VIEW -->
      <a href="accountant_master.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24"
          stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9 7h6m0 10v-3m-3 3h3m-3-10h.01M9 17h.01M9 14h.01M12 7a1 1 0 110-2h.01a1 1 0 110 2H12zM9 10a1 1 0 110-2h.01a1 1 0 110 2H9zm3 4a1 1 0 110-2h.01a1 1 0 110 2H12zm3 3a1 1 0 110-2h.01a1 1 0 110 2H15zM6 21a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2v14a2 2 0 01-2 2H6z" />
        </svg>
        <span class="sidebar-text">Master View</span>
      </a>

            <a href="companies.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24"
          stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9 7h6m0 10v-3m-3 3h3m-3-10h.01M9 17h.01M9 14h.01M12 7a1 1 0 110-2h.01a1 1 0 110 2H12zM9 10a1 1 0 110-2h.01a1 1 0 110 2H9zm3 4a1 1 0 110-2h.01a1 1 0 110 2H12zm3 3a1 1 0 110-2h.01a1 1 0 110 2H15zM6 21a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2v14a2 2 0 01-2 2H6z" />
        </svg>
        <span class="sidebar-text">Companies Management</span>
      </a>

      <!-- CORRECTED LINK FOR E-INVOICING PORTAL -->
      <a href="e_invoice.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24"
          stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
        <span class="sidebar-text">E-Invoicing (LHDN)</span>
      </a>
      <!-- NEW LINK FOR CHECK INVOICES/RECONCILIATION -->
      <a href="reconcile.php"
        class="px-4 py-2 rounded-lg hover:bg-custom-blue hover:text-white flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2l4-4M7 17a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H7zm0 0v2a2 2 0 002 2h6a2 2 0 002-2v-2" />
        </svg>
        <span class="sidebar-text">Check Invoices</span>
      </a>
    </nav>
    <div class="p-4 border-t border-gray-700 shrink-0">
      <a href="login.php?logout=1"
        class="flex items-center justify-center px-4 py-2 bg-red-600 rounded text-center hover:bg-red-700">
        <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
          stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
        </svg>
        <span class="ml-4 sidebar-text">Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main id="main-content" class="ml-64">
    <header class="bg-gradient-to-r from-custom-blue to-custom-green text-white p-4 rounded-b-lg shadow-md mx-6 mt-6">
      <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <h1 class="text-2xl font-extrabold tracking-tight">Dashboard</h1>
          <span class="inline-block bg-white/20 text-sm px-3 py-1 rounded-full">Welcome,
            <span class="font-semibold"><?= htmlspecialchars($u['username']) ?></span>
            (<?= htmlspecialchars($u['role']) ?>)
          </span>
        </div>

        <div class="flex items-center gap-3">
          <div class="hidden md:block mr-6">
            <label class="relative block overflow-visible">
              <input id="dashboardSearch" type="search" placeholder="Search services, invoices..."
                class="pl-4 pr-14 py-2 rounded-full bg-white/20 placeholder-white/60 focus:outline-none focus:ring-2 focus:ring-white/40 w-72">
              <svg class="w-6 h-6 absolute right-6 top-1/2 -translate-y-1/2 text-white/90 z-10 pointer-events-none" xmlns="http://www.w3.org/2000/svg"
                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-4.35-4.35M16.65 16.65A7.5 7.5 0 1016.65 2.55a7.5 7.5 0 000 14.1z" />
              </svg>
            </label>
          </div>

          <a href="generate_summary.php" class="bg-white text-custom-dark px-4 py-2 rounded-lg font-semibold shadow hover:opacity-95">Generate</a>
          <a href="invoice.php"
            class="bg-white/20 border border-white/30 text-white px-4 py-2 rounded-lg hover:bg-white/10">New
            Invoice</a>
          <a href="employees.php"
            class="bg-white/20 border border-white/30 text-white px-4 py-2 rounded-lg hover:bg-white/10">New
            Employee</a>

          <div class="w-10 h-10 rounded-full bg-white/30 flex items-center justify-center text-sm font-bold">
            <?= strtoupper(substr($u['username'], 0, 1)) ?>
          </div>
        </div>
      </div>
    </header>

    <div class="p-6 max-w-7xl mx-auto -mt-3 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <!-- Card 1: Payroll Management -->
      <div data-label="Payroll Management"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg"><svg
                class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
              </svg></div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Payroll Management</h2>
              <p class="text-sm text-gray-500 mt-1">Manage salaries</p>
            </div>
          </div>
        </div>
        <a href="payroll.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span
            class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300"><svg
              xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
              stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg></span>
        </a>
      </div>

      <!-- Card 2: Invoice Management -->
      <div data-label="Invoice Management"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg"><svg
                class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
              </svg></div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Invoice Management</h2>
              <p class="text-sm text-gray-500 mt-1">Track and generate</p>
            </div>
          </div>
        </div>
        <a href="invoice.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span
            class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300"><svg
              xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
              stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg></span>
        </a>
      </div>

      <!-- Card 3: Employee Management -->
      <div data-label="Employee Management"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg"><svg
                class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
              </svg></div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Employee Management</h2>
              <p class="text-sm text-gray-500 mt-1">View, edit, and add</p>
            </div>
          </div>
        </div>
        <a href="employees.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span
            class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300"><svg
              xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
              stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg></span>
        </a>
      </div>

  <div data-label="Company Management" class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
  <div>
    <div class="flex items-start gap-4">
      <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
        <svg class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
          stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
        </svg>
      </div>
      <div>
        <h2 class="text-lg font-bold text-custom-dark">Company Management</h2>
        <p class="text-sm text-gray-500 mt-1">View, edit, and add</p>
      </div>
    </div>
  </div>
  <a href="companies.php" class="flex items-center font-bold text-custom-dark group mt-4">
    <span>Open Service</span>
    <span
      class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
        stroke="currentColor" stroke-width="2.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
      </svg>
    </span>
  </a>
</div>



      <!-- Card 4: Summary Report -->
      <div data-label="Summary Report"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-custom-dark" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Summary Report</h2>
              <p class="text-sm text-gray-500 mt-1">Monthly payroll summary</p>
            </div>
          </div>
        </div>
        <a href="monthly_summary.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </span>
        </a>
      </div>

       <!-- Card 4: Summary Report -->
      <div data-label="Generate Summary"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-custom-dark" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2M9 12l2 2 4-4" />
              </svg>
            </div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Generate Summary</h2>
              <p class="text-sm text-gray-500 mt-1">Create employee salary summaries</p>
            </div>
          </div>
        </div>
        <a href="generate_summary.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </span>
        </a>
      </div>

      <!-- Card 5: Master View -->
      <div data-label="Master View"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-custom-dark" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h3m-3-10h.01M9 17h.01M9 14h.01M12 7a1 1 0 110-2h.01a1 1 0 110 2H12zM9 10a1 1 0 110-2h.01a1 1 0 110 2H9zm3 4a1 1 0 110-2h.01a1 1 0 110 2H12zm3 3a1 1 0 110-2h.01a1 1 0 110 2H15zM6 21a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2v14a2 2 0 01-2 2H6z" />
              </svg>
            </div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Master View</h2>
              <p class="text-sm text-gray-500 mt-1">Accountant master data</p>
            </div>
          </div>
        </div>
        <a href="accountant_master.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </span>
        </a>
      </div>

      <!-- Card 6: Check Invoices -->
      <div data-label="Check Invoices"
        class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
        <div>
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-custom-dark" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2l4-4M7 17a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H7zm0 0v2a2 2 0 002 2h6a2 2 0 002-2v-2" />
              </svg>
            </div>
            <div>
              <h2 class="text-lg font-bold text-custom-dark">Check Invoices</h2>
              <p class="text-sm text-gray-500 mt-1">Reconcile and verify invoices</p>
            </div>
          </div>
        </div>
        <a href="reconcile.php" class="flex items-center font-bold text-custom-dark group mt-4">
          <span>Open Service</span>
          <span class="ml-3 w-8 h-8 flex items-center justify-center border-2 border-gray-300 rounded-full group-hover:bg-custom-green group-hover:border-custom-green transition-all duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </span>
        </a>
      </div>
    </div>
  </main>

  <button id="sidebar-toggle"
    class="fixed top-6 left-64 w-10 h-10 bg-custom-blue text-white rounded-full flex items-center justify-center z-30 -translate-x-1/2 shadow-md hover:bg-custom-green hover:text-custom-dark"><svg
      id="icon-collapse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
      stroke="currentColor" class="w-6 h-6">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg><svg id="icon-expand" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
      stroke="currentColor" class="w-6 h-6 hidden">
      <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
    </svg></button>

  <script>
    (function () {
      // Sidebar toggle (existing behavior)
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('main-content');
      const sidebarToggle = document.getElementById('sidebar-toggle');
      const sidebarTexts = document.querySelectorAll('.sidebar-text');
      const iconCollapse = document.getElementById('icon-collapse');
      const iconExpand = document.getElementById('icon-expand');
      if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
          sidebar.classList.toggle('w-64'); sidebar.classList.toggle('w-20');
          mainContent.classList.toggle('ml-64'); mainContent.classList.toggle('ml-20');
          sidebarToggle.classList.toggle('left-64'); sidebarToggle.classList.toggle('left-20');
          sidebarTexts.forEach(text => { text.classList.toggle('hidden'); });
          iconCollapse.classList.toggle('hidden'); iconExpand.classList.toggle('hidden');
        });
      }

      // Dashboard search: filters cards that have a data-label attribute
      const dashboardSearch = document.getElementById('dashboardSearch');
      const cards = Array.from(document.querySelectorAll('[data-label]'));

      function filterCards() {
        if (!dashboardSearch) return;
        const q = dashboardSearch.value.trim().toLowerCase();
        if (!q) {
          // show all
          cards.forEach(c => c.style.display = '');
          return;
        }
        cards.forEach(c => {
          const label = (c.getAttribute('data-label') || '').toLowerCase();
          const match = label.indexOf(q) !== -1;
          c.style.display = match ? '' : 'none';
        });
      }

      if (dashboardSearch) {
        dashboardSearch.addEventListener('input', filterCards);

        // Enter opens the first visible match (if any)
        dashboardSearch.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            const first = cards.find(c => c.style.display !== 'none');
            if (first) {
              const a = first.querySelector('a');
              if (a && a.href) {
                // navigate to the anchor href
                window.location.href = a.href;
              } else if (a) {
                a.click();
              }
            }
          } else if (ev.key === 'Escape') {
            dashboardSearch.value = '';
            filterCards();
          }
        });
      }
    })();
  </script>
</body>

</html>