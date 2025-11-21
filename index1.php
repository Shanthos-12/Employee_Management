<?php
/************************************************************
 * One-Page Admin Login + Dashboard (PHP + MySQL)
 ************************************************************/
declare(strict_types=1);
session_start();
require_once 'db_conn.php'; // Assuming db_conn.php contains your getPDO() function

/* ==== BACKEND PHP LOGIC (UNCHANGED) ==== */
$pdo = getPDO();
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username='admin'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
  $hash = password_hash("Admin@12345", PASSWORD_DEFAULT);
  $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)")
    ->execute(["admin", $hash, "superadmin"]);
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $msg = "Invalid CSRF token";
  } else {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $q = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $q->execute([$user]);
    $row = $q->fetch();
    if ($row && password_verify($pass, $row['password_hash'])) {
      $_SESSION['user'] = $row;
      header("Location: " . $_SERVER['PHP_SELF'] . "?dashboard=1");
      exit;
    } else {
      $msg = "Invalid username or password";
    }
  }
}

/* ==== DASHBOARD ==== */
if (isset($_GET['dashboard']) && !empty($_SESSION['user'])) {
  $u = $_SESSION['user'];
  ?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
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
      <!-- Logo -->
      <div class="p-6 text-2xl font-bold border-b border-gray-700 flex items-center justify-center shrink-0">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
          class="w-8 h-8 mr-2 text-custom-green">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M2.25 21h19.5m-18-18h18a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0121.75 21H2.25A2.25 2.25 0 010 18.75V5.25A2.25 2.25 0 012.25 3z" />
        </svg>
        <span class="sidebar-text">Admin Portal</span>
      </div>
      <!-- Navigation -->
      <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
        <a href="payroll.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white">
          <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
          </svg>
          <span class="ml-4 sidebar-text">Payroll</span>
        </a>
        <a href="invoice.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white">
          <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
          </svg>
          <span class="ml-4 sidebar-text">Invoices</span>
        </a>
        <a href="employees.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white">
          <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
          </svg>
          <span class="ml-4 sidebar-text">Employees</span>
        </a>
        <?php if ($_SESSION['user']['role'] === 'superadmin'): ?>
    <a href="user_management.php" class="flex items-center px-4 py-2 rounded hover:bg-custom-blue hover:text-white">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2" />
      </svg>
      <span class="ml-4 sidebar-text">User Management</span>
    </a>
  <?php endif; ?>
      </nav>
      <!-- Logout Button -->
      <div class="p-4 border-t border-gray-700 shrink-0">
        <a href="?logout=1"
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
      <header class="bg-white shadow p-4 flex justify-between items-center">
        <h1 class="text-xl font-semibold text-custom-dark pl-4">Dashboard</h1>
        <span class="text-sm text-gray-600">Welcome, <span
            class="font-medium"><?= htmlspecialchars($u['username']) ?></span> (<?= htmlspecialchars($u['role']) ?>)</span>
      </header>

      <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <!-- Card 1: Payroll Management -->
        <div
          class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
          <div>
            <div class="flex items-start gap-4">
              <!-- Icon -->
              <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
                <svg class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                  stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                </svg>
              </div>
              <!-- Title and Subtitle -->
              <div>
                <h2 class="text-lg font-bold text-custom-dark">Payroll Management</h2>
                <p class="text-sm text-gray-500 mt-1">Manage salaries</p>
              </div>
            </div>
          </div>
          <a href="payroll.php" class="flex items-center font-bold text-custom-dark group mt-4">
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

        <!-- Card 2: Invoice Management -->
        <div
          class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
          <div>
            <div class="flex items-start gap-4">
              <!-- Icon -->
              <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
                <svg class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                  stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
              </div>
              <!-- Title and Subtitle -->
              <div>
                <h2 class="text-lg font-bold text-custom-dark">Invoice Management</h2>
                <p class="text-sm text-gray-500 mt-1">Track and generate</p>
              </div>
            </div>
          </div>
          <a href="invoice.php" class="flex items-center font-bold text-custom-dark group mt-4">
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

        <!-- Card 3: Employee Management -->
        <div
          class="bg-white p-6 rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between min-h-[240px]">
          <div>
            <div class="flex items-start gap-4">
              <!-- Icon -->
              <div class="w-12 h-12 bg-custom-light flex items-center justify-center rounded-lg">
                <svg class="w-7 h-7 text-custom-dark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                  stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z" />
                </svg>
              </div>
              <!-- Title and Subtitle -->
              <div>
                <h2 class="text-lg font-bold text-custom-dark">Employee Management</h2>
                <p class="text-sm text-gray-500 mt-1">View, edit, and add</p>
              </div>
            </div>
          </div>
          <a href="employees.php" class="flex items-center font-bold text-custom-dark group mt-4">
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
      </div>
    </main>

    <!-- Sidebar Toggle Button -->
    <button id="sidebar-toggle"
      class="fixed top-6 left-64 w-10 h-10 bg-custom-blue text-white rounded-full flex items-center justify-center z-30 -translate-x-1/2 shadow-md hover:bg-custom-green hover:text-custom-dark">
      <svg id="icon-collapse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
        stroke="currentColor" class="w-6 h-6">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
      </svg>
      <svg id="icon-expand" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
        stroke="currentColor" class="w-6 h-6 hidden">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
      </svg>
    </button>


    <script>
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('main-content');
      const sidebarToggle = document.getElementById('sidebar-toggle');
      const sidebarTexts = document.querySelectorAll('.sidebar-text');
      const iconCollapse = document.getElementById('icon-collapse');
      const iconExpand = document.getElementById('icon-expand');

      sidebarToggle.addEventListener('click', () => {
        // Toggle sidebar width and related classes
        sidebar.classList.toggle('w-64');
        sidebar.classList.toggle('w-20');

        // Toggle main content margin
        mainContent.classList.toggle('ml-64');
        mainContent.classList.toggle('ml-20');

        // Toggle toggle button position
        sidebarToggle.classList.toggle('left-64');
        sidebarToggle.classList.toggle('left-20');

        // Hide/show text labels and adjust padding
        sidebarTexts.forEach(text => {
          text.classList.toggle('hidden');
        });

        // Swap icons
        iconCollapse.classList.toggle('hidden');
        iconExpand.classList.toggle('hidden');
      });
    </script>
  </body>

  </html>
  <?php
  exit;
}

/* ==== LOGIN FORM (Your existing login form code remains here) ==== */
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
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
</head>

<body class="min-h-screen bg-custom-dark flex items-center justify-center">
  <form method="post"
    class="bg-gray-900/50 backdrop-blur-md p-8 rounded-2xl shadow-lg w-full max-w-sm text-custom-light">
    <h1 class="text-3xl font-bold mb-6 text-center text-custom-green">Admin Login</h1>
    <?php if ($msg): ?>
      <div class="mb-4 p-3 bg-red-500/20 border border-red-500 text-red-300 rounded-lg"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <div class="mb-4">
      <label class="block mb-2 font-medium">Username</label>
      <input type="text" name="username" required
        class="w-full px-4 py-2 rounded-lg bg-gray-800 border border-gray-700 focus:outline-none focus:ring-2 focus:ring-custom-green transition-all duration-200">
    </div>
    <div class="mb-6">
      <label class="block mb-2 font-medium">Password</label>
      <input type="password" name="password" required
        class="w-full px-4 py-2 rounded-lg bg-gray-800 border border-gray-700 focus:outline-none focus:ring-2 focus:ring-custom-green transition-all duration-200">
    </div>
    <button
      class="w-full bg-custom-green hover:bg-custom-blue text-custom-dark font-bold py-2 rounded-lg transition-colors duration-300">Login</button>
    <p class="mt-4 text-xs text-center text-gray-400">Default: admin / Admin@12345</p>
  </form>
</body>

</html>