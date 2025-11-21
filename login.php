<?php
declare(strict_types=1);
session_start();
require_once 'db_conn.php';

$pdo = getPDO();

// Create the 'app_users' table if it doesn't exist.
// This schema is based on your user_management.php file.
$pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    email VARCHAR(190) NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'superadmin') NOT NULL DEFAULT 'admin',
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create a default superadmin if no users exist in the app_users table.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM app_users");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $hash = password_hash("Admin@12345", PASSWORD_DEFAULT);
    // Ensure the role column can accept 'superadmin' before inserting.
    try {
        $pdo->exec("ALTER TABLE app_users MODIFY role ENUM('admin', 'superadmin') NOT NULL DEFAULT 'admin'");
    } catch (PDOException $e) { /* Ignore error if column is already correct */
    }

    $pdo->prepare("INSERT INTO app_users (username, password, role) VALUES (?,?,?)")
        ->execute(["admin", $hash, "superadmin"]);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $msg = "Invalid CSRF token";
    } else {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        // UPDATED: Query the 'app_users' table
        $q = $pdo->prepare("SELECT * FROM app_users WHERE username=? LIMIT 1");
        $q->execute([$user]);
        $row = $q->fetch();

        // NEW: Check if the user account is blocked
        if ($row && (int) $row['is_blocked'] === 1) {
            $msg = "This account is blocked. Please contact an administrator.";
        }
        // UPDATED: Check against 'password_hash' column
        // Check against 'password' column
        elseif ($row && password_verify($pass, $row['password'])) {
            // Login successful, set the session
            $_SESSION['user'] = $row;
            header("Location: index.php"); // Redirect to the new dashboard
            exit;
        } else {
            $msg = "Invalid username or password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <title>Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body
    class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white flex items-center justify-center">
    <form method="post" class="bg-white/10 backdrop-blur p-8 rounded-2xl shadow-xl w-full max-w-sm">
        <h1 class="text-3xl font-bold mb-6 text-center text-sky-300">Admin Portal Login</h1>
        <?php if ($msg): ?>
            <div class="mb-4 p-3 bg-red-500/20 text-red-300 rounded-lg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <div class="mb-4">
            <label class="block mb-2 font-medium text-slate-300">Username</label>
            <input type="text" name="username" required
                class="w-full px-4 py-2 rounded-lg bg-slate-800 border border-slate-600 focus:outline-none focus:ring-2 focus:ring-sky-500">
        </div>
        <div class="mb-6">
            <label class="block mb-2 font-medium text-slate-300">Password</label>
            <input type="password" name="password" required
                class="w-full px-4 py-2 rounded-lg bg-slate-800 border border-slate-600 focus:outline-none focus:ring-2 focus:ring-sky-500">
        </div>
        <button
            class="w-full bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 font-bold py-2.5 rounded-lg transition-colors duration-300">Login</button>
        <p class="mt-4 text-xs text-center text-gray-400">Default: admin / Admin@12345</p>
    </form>
</body>

</html>