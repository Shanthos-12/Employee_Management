<?php
/************************************************************
 * USER MANAGEMENT — Superadmin Only (Mobile-First Premium UI)
 * New in this version:
 *  - Email field (store & edit)
 *  - Block/Unblock user (prevents login)
 *  - Light auto-migrations to add columns if missing
 *
 * Fixes:
 *  - Removed nested forms that caused "Save" to delete user
 *  - Each action now uses its own separate form (Update/Delete/Block)
 *
 * Features:
 *  - Create Role (adds to users.role ENUM safely)
 *  - Add User (username, email, role, password)
 *  - Edit User (username, email, role, optional password)
 *  - Delete User (guards: not yourself, not last superadmin)
 *  - Toggle Block/Unblock (not yourself, not last superadmin)
 * Security:
 *  - Secure session, CSRF, prepared statements, bcrypt
 ************************************************************/
declare(strict_types=1);

/* ================= Session & Guard ================= */
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_samesite', 'Lax');
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
  }
  session_start();
}
if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];
date_default_timezone_set('Asia/Colombo');

/* Superadmin gate (compatible with your login file) */
function require_superadmin_or_die(): void
{
  $role = $_SESSION['user']['role'] ?? null;
  $adminCompat = !empty($_SESSION['admin_logged_in']); // legacy superadmin flag
  if ($role !== 'superadmin' && !$adminCompat) {
    http_response_code(403);
    echo 'Forbidden: superadmin only.';
    exit;
  }
}
require_superadmin_or_die();

// ... after require_superadmin_or_die(); ...

// ==========================================================
// NEW: Use your existing database connection
// ==========================================================
require_once 'db_conn.php';
$pdo = getPDO();

/* ================= Helpers ================= */
function h(string $v): string
{
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function bad_request(): void
{
  http_response_code(400);
  echo 'Bad Request';
  exit;
}
function superadmin_count(PDO $pdo): int
{
  return (int) $pdo->query("SELECT COUNT(*) FROM app_users WHERE role='superadmin'")->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool
{
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$col]);
  return (bool) $st->fetch();
}
/* Read app_users.role ENUM values */
function get_enum_roles(PDO $pdo): array
{
  $col = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'role'")->fetch();
  if (!$col || empty($col['Type']))
    return [];
  if (preg_match('/^enum\((.*)\)$/i', $col['Type'], $m)) {
    $inner = $m[1];
    $vals = [];
    foreach (explode(',', $inner) as $part) {
      $vals[] = trim(stripslashes(trim($part, "' ")));
    }
    return $vals;
  }
  return [];
}
/* Add a role safely into ENUM (duplicates ignored) */
function add_enum_role(PDO $pdo, string $newRole): void
{
  $newRole = strtolower($newRole);
  $roles = get_enum_roles($pdo);
  if (in_array($newRole, $roles, true))
    return;
  $roles[] = $newRole;
  $quoted = array_map(fn($r) => $pdo->quote($r), $roles);
  $sql = 'ALTER TABLE app_users MODIFY role ENUM(' . implode(',', $quoted) . ') NOT NULL';
  $pdo->exec($sql);
}
/* Ensure new columns exist (email, is_blocked, created_at) */
function ensure_columns(PDO $pdo): void
{
  // email (nullable, indexed but not unique to avoid migration failures on duplicates)
  if (!col_exists($pdo, 'app_users', 'email')) {
    $pdo->exec("ALTER TABLE app_users ADD COLUMN email VARCHAR(190) NULL DEFAULT NULL AFTER username");
    $pdo->exec("CREATE INDEX idx_users_email ON app_users(email)");
  }
  // is_blocked
  if (!col_exists($pdo, 'app_users', 'is_blocked')) {
    $pdo->exec("ALTER TABLE app_users ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
  }
  // created_at
  if (!col_exists($pdo, 'app_users', 'created_at')) {
    $pdo->exec("ALTER TABLE app_users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
  }
}
try {
  ensure_columns($pdo);
} catch (Throwable $e) { /* best-effort */
}

/* ================= Actions ================= */
$flash = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''))
    bad_request();

  if ($action === 'add_role') {
    $role = strtolower(trim((string) ($_POST['role'] ?? '')));
    if ($role === '' || !preg_match('/^[a-z][a-z0-9_]{1,30}$/', $role)) {
      $flash = ['type' => 'err', 'text' => 'Invalid role. Use lowercase letters, numbers, underscore (2–31 chars).'];
    } else {
      try {
        add_enum_role($pdo, $role);
        $flash = ['type' => 'ok', 'text' => "Role '{$role}' added."];
      } catch (Throwable $e) {
        $flash = ['type' => 'err', 'text' => 'Error adding role: ' . $e->getMessage()];
      }
    }
  }

  if ($action === 'add_user') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? '');
    $roles = get_enum_roles($pdo);

    $emailOk = ($email === '') || (bool) filter_var($email, FILTER_VALIDATE_EMAIL);

    if ($username === '' || $password === '' || !in_array($role, $roles, true) || !$emailOk) {
      $flash = ['type' => 'err', 'text' => 'Please provide username, valid email (or leave empty), password, and a valid role.'];
    } else {
      try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO app_users (username, email, password, role) VALUES (?,?,?,?)');
        $stmt->execute([$username, ($email ?: null), $hash, $role]);
        $flash = ['type' => 'ok', 'text' => "User '{$username}' created."];
      } catch (PDOException $e) {
        $flash = ($e->getCode() === '23000')
          ? ['type' => 'err', 'text' => 'Username already exists.']
          : ['type' => 'err', 'text' => 'DB error: ' . $e->getMessage()];
      }
    }
  }

  if ($action === 'update_user') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $uname = trim((string) ($_POST['u_username'] ?? ''));
    $uemail = trim((string) ($_POST['u_email'] ?? ''));
    $urole = (string) ($_POST['u_role'] ?? '');
    $upass = (string) ($_POST['u_password'] ?? '');
    $roles = get_enum_roles($pdo);

    $emailOk = ($uemail === '') || (bool) filter_var($uemail, FILTER_VALIDATE_EMAIL);

    if ($uid <= 0 || $uname === '' || !in_array($urole, $roles, true) || !$emailOk) {
      $flash = ['type' => 'err', 'text' => 'Invalid update input.'];
    } else {
      try {
        // Prevent demoting the last superadmin
        $cur = $pdo->prepare('SELECT role FROM app_users WHERE id=?');
        $cur->execute([$uid]);
        $oldRole = $cur->fetch()['role'] ?? null;
        if ($oldRole === 'superadmin' && $urole !== 'superadmin' && superadmin_count($pdo) <= 1) {
          throw new RuntimeException('Cannot change role: this is the last superadmin.');
        }

        if ($upass !== '') {
          $hash = password_hash($upass, PASSWORD_DEFAULT);
          $stmt = $pdo->prepare('UPDATE app_users SET username=?, email=?, role=?, password=? WHERE id=?');
          $stmt->execute([$uname, ($uemail ?: null), $urole, $hash, $uid]);
        } else {
          $stmt = $pdo->prepare('UPDATE app_users SET username=?, email=?, role=? WHERE id=?');
          $stmt->execute([$uname, ($uemail ?: null), $urole, $uid]);
        }
        $flash = ['type' => 'ok', 'text' => "User #{$uid} updated."];
      } catch (RuntimeException $e) {
        $flash = ['type' => 'err', 'text' => $e->getMessage()];
      } catch (PDOException $e) {
        $flash = ($e->getCode() === '23000')
          ? ['type' => 'err', 'text' => 'Username already exists.']
          : ['type' => 'err', 'text' => 'DB error: ' . $e->getMessage()];
      }
    }
  }

  if ($action === 'delete_user') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    try {
      if ($uid <= 0)
        throw new RuntimeException('Invalid user id.');
      // Cannot delete yourself
      $me = (int) ($_SESSION['user']['id'] ?? 0);
      if ($uid === $me)
        throw new RuntimeException('You cannot delete your own account.');

      $roleStmt = $pdo->prepare('SELECT role FROM app_users WHERE id=?');
      $roleStmt->execute([$uid]);
      $r = $roleStmt->fetch();
      if (!$r)
        throw new RuntimeException('User not found.');

      if ($r['role'] === 'superadmin' && superadmin_count($pdo) <= 1) {
        throw new RuntimeException('Cannot delete the last superadmin.');
      }
      $del = $pdo->prepare('DELETE FROM app_users WHERE id=?');
      $del->execute([$uid]);
      $flash = ['type' => 'ok', 'text' => "User #{$uid} deleted."];
    } catch (RuntimeException $e) {
      $flash = ['type' => 'err', 'text' => $e->getMessage()];
    } catch (Throwable $e) {
      $flash = ['type' => 'err', 'text' => 'Error deleting user: ' . $e->getMessage()];
    }
  }

  if ($action === 'toggle_block') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $new = (int) ($_POST['new_status'] ?? 0) ? 1 : 0;
    try {
      if ($uid <= 0)
        throw new RuntimeException('Invalid user id.');
      // Cannot block yourself
      $me = (int) ($_SESSION['user']['id'] ?? 0);
      if ($uid === $me)
        throw new RuntimeException('You cannot block your own account.');

      // last superadmin guard
      $roleStmt = $pdo->prepare('SELECT role FROM app_users WHERE id=?');
      $roleStmt->execute([$uid]);
      $r = $roleStmt->fetch();
      if (!$r)
        throw new RuntimeException('User not found.');
      if ($r['role'] === 'superadmin' && superadmin_count($pdo) <= 1) {
        throw new RuntimeException('Cannot block the last superadmin.');
      }

      $st = $pdo->prepare('UPDATE app_users SET is_blocked=? WHERE id=?');
      $st->execute([$new, $uid]);
      $flash = ['type' => 'ok', 'text' => $new ? 'User blocked.' : 'User unblocked.'];
    } catch (RuntimeException $e) {
      $flash = ['type' => 'err', 'text' => $e->getMessage()];
    } catch (Throwable $e) {
      $flash = ['type' => 'err', 'text' => 'Error updating block status: ' . $e->getMessage()];
    }
  }
}

/* ================= Data ================= */
$roles = get_enum_roles($pdo);
$users = $pdo->query('SELECT id, username, email, role, is_blocked, created_at FROM app_users ORDER BY id DESC')->fetchAll();
$userMe = $_SESSION['user']['username'] ?? ($_SESSION['admin_username'] ?? 'Superadmin');

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <link rel="icon" type="image/png" href="assets/sfi_logo.png">
  <title>User Management GREEN TON</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    html,
    body {
      height: 100%;
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans';
    }

    @media (max-width: 767px) {
      .md-only {
        display: none !important;
      }
    }

    @media (min-width: 768px) {
      .sm-only {
        display: none !important;
      }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
  <!-- Header -->
  <header
    class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-10">
    <h1 class="text-2xl font-bold flex items-center gap-3">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24"
        stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <span>User Management Green Ton</span>
    </h1>
    <a href="index.php?dashboard=1"
      class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd"
          d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
          clip-rule="evenodd" />
      </svg>
      <span>Dashboard</span>
    </a>
  </header>

  <main class="px-3 sm:px-6 md:px-8 pb-20 pt-4 sm:pt-6">
    <div class="max-w-7xl mx-auto space-y-6">

      <?php if (!empty($flash)): ?>
        <div
          class="rounded-xl border <?= $flash['type'] === 'ok' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> p-3 text-sm">
          <?= h($flash['text']) ?>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
        <!-- Create Role -->
        <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
          <h2 class="text-lg sm:text-xl font-extrabold">Create Role</h2>
          <p class="text-slate-600 text-xs sm:text-sm mb-3 sm:mb-4">Add a new role (lowercase, numbers, underscore).</p>
          <form method="post" class="grid gap-2 sm:gap-3">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="add_role">
            <div>
              <label class="block text-sm font-semibold mb-1">Role name</label>
              <input name="role" required placeholder="e.g., recruiter, qa_lead"
                class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600 text-white focus:ring-2 focus:ring-sky-500 outline-none">
            </div>
            <button type="submit"
              class="w-full rounded-xl bg-indigo-600 py-2.5 font-semibold text-white hover:bg-indigo-700">Add
              Role</button>
          </form>
          <div class="mt-3 sm:mt-4">
            <h3 class="text-sm font-semibold mb-1">Current Roles</h3>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($roles as $r): ?>
                <span
                  class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 px-3 py-1 text-xs font-semibold ring-1 ring-indigo-200"><?= h($r) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <!-- Add User -->
        <section class="bg-white/10 backdrop-blur p-6 rounded-2xl shadow-xl">
          <h2 class="text-lg sm:text-xl font-extrabold">Add User</h2>
          <p class="text-slate-600 text-xs sm:text-sm mb-3 sm:mb-4">Create a user with email, role & password.</p>
          <form method="post" class="grid gap-2 sm:gap-3">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="add_user">
            <div>
              <label class="block text-sm font-semibold mb-1">Username</label>
              <input name="username" required
                class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600 text-white focus:ring-2 focus:ring-sky-500 outline-none">
            </div>
            <div>
              <label class="block text-sm font-semibold mb-1">Email (optional)</label>
              <input name="email" type="email" placeholder="name@example.com"
                class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600 text-white focus:ring-2 focus:ring-sky-500 outline-none">
            </div>
            <!-- THIS IS THE NEW, CORRECTED BLOCK -->
            <div>
              <label class="block text-sm font-semibold mb-1">Password</label>
              <!-- 1. Add a container with 'relative' class -->
              <div class="relative">
                <!-- 2. Add padding to the right of the input to make space for the icon -->
                <input id="password" name="password" type="password" required
                  class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600 text-white focus:ring-2 focus:ring-sky-500 outline-none pr-10">

                <!-- 3. The button is now inside the relative container -->
                <button type="button" onclick="togglePassword()" id="togglePassword"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
                  <!-- Eye Icon -->
                  <svg xmlns="http://www.w3.org/2000/svg" id="eyeIcon" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                  <!-- Eye Off Icon (hidden by default) -->
                  <svg xmlns="http://www.w3.org/2000/svg" id="eyeOffIcon" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" class="h-5 w-5 hidden">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.956 9.956 0 012.042-3.362M9.88 9.88A3 3 0 0114.12 14.12M6.1 6.1l11.8 11.8" />
                  </svg>
                </button>
              </div>
            </div>
            <div>
              <label class="block text-sm font-semibold mb-1">Role</label>
              <select name="role" required
                class="w-full p-2 rounded-lg bg-slate-800 border border-slate-600 text-white focus:ring-2 focus:ring-sky-500 outline-none">
                <option value="">Select role…</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= h($r) ?>"><?= h($r) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit"
              class="w-full rounded-xl bg-slate-900 py-2.5 font-semibold text-white hover:bg-black">Create User</button>
          </form>
        </section>

        <!-- Tips -->
        <section class="rounded-2xl bg-white/20 ring-1 ring-white/30 text-white p-4 sm:p-6">
          <h2 class="text-lg sm:text-xl font-extrabold">Safety Tips</h2>
          <ul class="list-disc list-inside mt-2 space-y-1 text-white/90 text-xs sm:text-sm">
            <li>Change default passwords immediately.</li>
            <li>Don’t delete or block the last superadmin.</li>
            <li>Blocked users cannot log in.</li>
          </ul>
        </section>
      </div>

      <!-- Search -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <h2 class="text-white text-base sm:text-lg font-semibold">All Users</h2>
        <input id="tableSearch" placeholder="Search username/email/role…"
          class="rounded-xl border border-slate-300 px-3 sm:px-4 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
      </div>

      <!-- MOBILE: Card list -->
      <section class="sm-only grid grid-cols-1 gap-3">
        <?php if (count($users) === 0): ?>
          <p class="text-sm text-slate-500">No users yet.</p>
        <?php else:
          foreach ($users as $u):
            $rid = (int) $u['id'];
            $blocked = (int) $u['is_blocked'] === 1; ?>
            <div class="rounded-2xl bg-white/90 backdrop-blur shadow-2xl ring-1 ring-white/40 p-4" data-card>
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm text-slate-500">#<?= $rid ?></div>
                  <div class="text-lg font-bold" data-u><?= h($u['username']) ?></div>
                  <div class="text-sm text-slate-600 break-words" data-e><?= h($u['email'] ?? '') ?></div>
                  <div class="text-sm text-slate-600">Role: <span class="font-medium" data-r><?= h($u['role']) ?></span>
                  </div>
                  <div class="text-xs text-slate-500">Created: <?= h($u['created_at']) ?></div>
                </div>
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= $blocked ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200' ?>"><?= $blocked ? 'Blocked' : 'Active' ?></span>
              </div>

              <details class="mt-3">
                <summary
                  class="cursor-pointer inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                  Edit</summary>
                <div class="mt-3">
                  <!-- UPDATE (mobile) -->
                  <form method="post" class="grid grid-cols-1 gap-2">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= $rid ?>">

                    <div>
                      <label class="block text-xs text-slate-600 mb-1">Username</label>
                      <input name="u_username" value="<?= h($u['username']) ?>" required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <div>
                      <label class="block text-xs text-slate-600 mb-1">Email</label>
                      <input name="u_email" type="email" value="<?= h((string) ($u['email'] ?? '')) ?>"
                        placeholder="name@example.com"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <div>
                      <label class="block text-xs text-slate-600 mb-1">Role</label>
                      <select name="u_role"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                        required>
                        <?php foreach ($roles as $r): ?>
                          <option value="<?= h($r) ?>" <?= $r === $u['role'] ? 'selected' : ''; ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs text-slate-600 mb-1">New Password (optional)</label>
                      <input name="u_password" type="password" placeholder="Leave blank to keep"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <button type="submit"
                      class="w-full rounded-lg bg-slate-900 py-2 text-white font-semibold hover:bg-black">Save</button>
                  </form>

                  <!-- DELETE + BLOCK (mobile) -->
                  <div class="grid grid-cols-2 gap-2 mt-2">
                    <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="user_id" value="<?= $rid ?>">
                      <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-rose-600 text-white font-semibold hover:bg-rose-700">Delete</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="toggle_block">
                      <input type="hidden" name="user_id" value="<?= $rid ?>">
                      <input type="hidden" name="new_status" value="<?= $blocked ? 0 : 1 ?>">
                      <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg <?= $blocked ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600' ?> text-white font-semibold"><?= $blocked ? 'Unblock' : 'Block' ?></button>
                    </form>
                  </div>
                </div>
              </details>
            </div>
          <?php endforeach; endif; ?>
      </section>

      <!-- DESKTOP/TABLET: Table -->
      <section class="md-only rounded-2xl bg-white/90 backdrop-blur shadow-2xl ring-1 ring-white/40 p-4 sm:p-6">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm" id="usersTable">
            <thead>
              <tr class="text-left text-slate-600">
                <th class="py-2 pr-4">ID</th>
                <th class="py-2 pr-4">Username</th>
                <th class="py-2 pr-4">Email</th>
                <th class="py-2 pr-4">Role</th>
                <th class="py-2 pr-4">Status</th>
                <th class="py-2 pr-4">Created</th>
                <th class="py-2 pr-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
              <?php foreach ($users as $u):
                $rid = (int) $u['id'];
                $blocked = (int) $u['is_blocked'] === 1; ?>
                <tr data-row>
                  <td class="py-2 pr-4"><?= $rid ?></td>
                  <td class="py-2 pr-4"><span data-u><?= h($u['username']) ?></span></td>
                  <td class="py-2 pr-4 max-w-[240px] break-words"><span
                      data-e><?= h((string) ($u['email'] ?? '')) ?></span></td>
                  <td class="py-2 pr-4"><span data-r><?= h($u['role']) ?></span></td>
                  <td class="py-2 pr-4">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= $blocked ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200' ?>"><?= $blocked ? 'Blocked' : 'Active' ?></span>
                  </td>
                  <td class="py-2 pr-4"><?= h($u['created_at']) ?></td>
                  <td class="py-2 pr-4">
                    <details>
                      <summary
                        class="cursor-pointer inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                        Edit</summary>
                      <div class="mt-3 space-y-2">
                        <!-- UPDATE (desktop) — its own form ONLY -->
                        <form method="post" class="grid md:grid-cols-5 gap-2 items-end">
                          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                          <input type="hidden" name="action" value="update_user">
                          <input type="hidden" name="user_id" value="<?= $rid ?>">

                          <div>
                            <label class="block text-xs mb-1">Username</label>
                            <input name="u_username" value="<?= h($u['username']) ?>" required
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                          </div>
                          <div>
                            <label class="block text-xs mb-1">Email</label>
                            <input name="u_email" type="email" value="<?= h((string) ($u['email'] ?? '')) ?>"
                              placeholder="name@example.com"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                          </div>
                          <div>
                            <label class="block text-xs mb-1">Role</label>
                            <select name="u_role"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                              required>
                              <?php foreach ($roles as $r): ?>
                                <option value="<?= h($r) ?>" <?= $r === $u['role'] ? 'selected' : ''; ?>><?= h($r) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <label class="block text-xs mb-1">New Password (optional)</label>
                            <input name="u_password" type="password" placeholder="Leave blank to keep"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                          </div>
                          <div class="md:col-span-1">
                            <button type="submit"
                              class="w-full rounded-lg bg-slate-900 py-2 text-white font-semibold hover:bg-black">Save</button>
                          </div>
                        </form>

                        <!-- ACTION ROW: Delete + Block — SEPARATE forms, NOT nested -->
                        <div class="grid grid-cols-2 gap-2">
                          <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $rid ?>">
                            <button type="submit"
                              class="rounded-lg bg-rose-600 px-3 py-2 text-white font-semibold hover:bg-rose-700 w-full">Delete</button>
                          </form>

                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="action" value="toggle_block">
                            <input type="hidden" name="user_id" value="<?= $rid ?>">
                            <input type="hidden" name="new_status" value="<?= $blocked ? 0 : 1 ?>">
                            <button type="submit"
                              class="rounded-lg <?= $blocked ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600' ?> px-3 py-2 text-white font-semibold w-full"><?= $blocked ? 'Unblock' : 'Block' ?></button>
                          </form>
                        </div>
                      </div>
                    </details>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($users) === 0): ?>
            <p class="text-sm text-slate-500 mt-3">No users yet.</p>
          <?php endif; ?>
        </div>
      </section>

      <p class="text-center text-xs text-white/60">Tip: Add roles anytime—new roles appear instantly in the dropdowns.
      </p>
    </div>
  </main>

  <script>
    // Client-side search for BOTH table (md+) and cards (sm)
    (function () {
      const q = document.getElementById('tableSearch');
      const tableRows = Array.from(document.querySelectorAll('#usersTable tbody [data-row]'));
      const cards = Array.from(document.querySelectorAll('[data-card]'));
      function filter() {
        const v = (q?.value || '').toLowerCase();
        // Table rows
        tableRows.forEach(r => {
          const u = (r.querySelector('[data-u]')?.textContent || '').toLowerCase();
          const e = (r.querySelector('[data-e]')?.textContent || '').toLowerCase();
          const ro = (r.querySelector('[data-r]')?.textContent || '').toLowerCase();
          r.style.display = (u.includes(v) || e.includes(v) || ro.includes(v)) ? '' : 'none';
        });
        // Cards
        cards.forEach(c => {
          const u = (c.querySelector('[data-u]')?.textContent || '').toLowerCase();
          const e = (c.querySelector('[data-e]')?.textContent || '').toLowerCase();
          const ro = (c.querySelector('[data-r]')?.textContent || '').toLowerCase();
          c.style.display = (u.includes(v) || e.includes(v) || ro.includes(v)) ? '' : 'none';
        });
      }
      q?.addEventListener('input', filter);
    })();
    function togglePassword() {
      const input = document.getElementById('password');
      const eye = document.getElementById('eyeIcon');
      const eyeOff = document.getElementById('eyeOffIcon');

      if (input.type === 'password') {
        input.type = 'text';
        eye.classList.add('hidden');
        eyeOff.classList.remove('hidden');
      } else {
        input.type = 'password';
        eye.classList.remove('hidden');
        eyeOff.classList.add('hidden');
      }
    }
  </script>

  <!--
  ====================
  Login integration tip
  ====================
  In your login script, deny access for blocked accounts before verifying password:

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_blocked FROM users WHERE username=?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_blocked'] === 1) {
        $error = 'Invalid credentials.'; // or 'Account is blocked. Contact admin.'
        // do NOT proceed to password_verify
    }
  -->
</body>

</html>