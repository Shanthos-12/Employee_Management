<?php
session_start();
require_once 'db_conn.php';

// --- 1. INPUT & PERMISSIONS ---
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$userRole = $_SESSION['user']['role'] ?? 'guest';

$client_name = $_GET['id'] ?? '';
if (empty($client_name)) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error: Company name not provided.'];
    header("Location: invoice.php?error=company_not_found");
    exit;
}
$selected_department = $_GET['department'] ?? 'all';
$pdo = getPDO();

// --- OPTIONAL: Handle deletion request ---
if (isset($_GET['delete_id']) && $userRole === 'superadmin') {
    $delete_id = (int)$_GET['delete_id'];
    if ($delete_id > 0) {
        try {
            // fetch pdf_path so we can remove file
            $pstmt = $pdo->prepare("SELECT pdf_path FROM invoices WHERE id = ? LIMIT 1");
            $pstmt->execute([$delete_id]);
            $row = $pstmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['pdf_path'])) {
                $file = __DIR__ . '/' . $row['pdf_path'];
                if (is_file($file)) @unlink($file);
            }
            $dstmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? LIMIT 1");
            $dstmt->execute([$delete_id]);
            // redirect back to this page to refresh list with a flag
            header('Location: invoice_details.php?id=' . urlencode($client_name) . '&department=' . urlencode($selected_department) . '&deleted=1');
            exit;
        } catch (PDOException $e) {
            // swallow and continue to show page with an error
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not delete invoice.'];
        }
    }
}

// --- 2. DATA FETCHING ---
try {
    // Try exact match first
    $stmt = $pdo->prepare("SELECT client_name as name FROM invoices WHERE client_name = ? LIMIT 1");
    $stmt->execute([$client_name]);
    $company = $stmt->fetch();

    // If no exact match, try a case-insensitive trimmed match
    if (!$company) {
        $stmt = $pdo->prepare("SELECT client_name as name FROM invoices WHERE LOWER(TRIM(client_name)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$client_name]);
        $company = $stmt->fetch();
    }

    // If still not found, try a LIKE match (useful if the GET param was a shortened or encoded form)
    if (!$company) {
        $stmt = $pdo->prepare("SELECT client_name as name FROM invoices WHERE client_name LIKE ? LIMIT 1");
        $stmt->execute(['%'.$client_name.'%']);
        $company = $stmt->fetch();
    }

    if (!$company) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error: The requested company could not be found.'];
        header("Location: invoice.php?error=company_not_found");
        exit;
    }

    // Use the canonical company name from DB to avoid mismatches later
    $client_name = $company['name'];

    $dept_stmt = $pdo->prepare("SELECT DISTINCT department_name FROM invoices WHERE client_name = ? AND department_name IS NOT NULL AND department_name != '' ORDER BY department_name ASC");
    $dept_stmt->execute([$client_name]);
    $available_departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "
        SELECT 
            id as invoice_id,
            invoice_no as invoice_number,
            invoice_date,
            department_name,
            grand_total as total_amount
        FROM invoices
        WHERE client_name = :client_name
    ";
    $params = [':client_name' => $client_name];

    if ($selected_department !== 'all') {
        $sql .= " AND department_name = :department_name";
        $params[':department_name'] = $selected_department;
    }
    $sql .= " ORDER BY invoice_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // If there are no invoices for this company, redirect back to the main invoice page.
    // This keeps UX consistent (e.g., after deleting the last invoice for a company).
    if (empty($invoices_list)) {
        header('Location: invoice.php?last_deleted=1');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: Could not retrieve invoice data. Please try again later.");
}

// --- 3. DATA PROCESSING FOR TIMELINE VIEW ---
$invoicesByMonth = [];
foreach ($invoices_list as $invoice) {
    $monthYear = date('F Y', strtotime($invoice['invoice_date']));
    if (!isset($invoicesByMonth[$monthYear])) {
        $invoicesByMonth[$monthYear] = [];
    }
    $invoicesByMonth[$monthYear][] = $invoice;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoices for <?= htmlspecialchars($company['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <style>
        .glass { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(10px); }
        .timeline-item:not(:last-child)::before { content: ''; position: absolute; left: 0.375rem; top: 1.5rem; bottom: -2rem; width: 2px; background-color: #374151; }
        .timeline-dot { position: absolute; left: 0; top: 0.5rem; width: 0.875rem; height: 0.875rem; border-radius: 9999px; background-color: #38bdf8; border: 2px solid #1e293b; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white min-h-screen">
    <header class="p-6 bg-slate-900/70 backdrop-blur border-b border-slate-800 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
             <h1 class="text-2xl font-bold truncate">
                <span class="text-slate-400">Company:</span> 
                <span class="text-sky-400"><?= htmlspecialchars($company['name']) ?></span>
            </h1>
            <a href="invoice.php" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 flex-shrink-0 flex items-center gap-2 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                <span>Back to Studio</span>
            </a>
        </div>
    </header>
        <?php if (!empty($_GET['deleted'])): ?>
            <div class="max-w-7xl mx-auto mt-4 px-6"><div class="p-3 rounded-xl bg-emerald-600/15 border border-emerald-500/30 text-emerald-300">Invoice deleted successfully.</div></div>
        <?php endif; ?>

    <main class="p-6 max-w-7xl mx-auto space-y-8">
        <!-- DEPARTMENT FILTER FORM -->
        <section class="glass rounded-2xl border border-slate-800 p-6">
            <form method="GET" action="invoice_details.php" class="flex flex-col sm:flex-row items-center gap-4">
                <input type="hidden" name="id" value="<?= htmlspecialchars($client_name) ?>">
                <label for="department_filter" class="text-lg font-semibold text-slate-300">Filter by Department:</label>
                <select id="department_filter" name="department" class="flex-grow p-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:ring-2 focus:ring-sky-500 outline-none transition">
                    <option value="all" <?= ($selected_department === 'all') ? 'selected' : '' ?>>All Departments</option>
                    <?php foreach ($available_departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= ($selected_department === $dept) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full sm:w-auto px-6 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 font-semibold transition-colors">Apply Filter</button>
            </form>
        </section>

        <!-- INVOICE TIMELINE SECTION -->
        <section class="glass rounded-2xl border border-slate-800 p-6">
            <?php if (empty($invoicesByMonth)): ?>
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    <h2 class="mt-4 text-2xl font-bold text-slate-400">No Invoices Found</h2>
                    <p class="text-slate-500 mt-2">There are no invoices matching the current filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="relative pl-4">
                    <?php foreach ($invoicesByMonth as $month => $invoices): ?>
                        <div class="timeline-item pl-8 relative mb-10">
                            <div class="timeline-dot"></div>
                            <h3 class="text-2xl font-semibold text-indigo-400 mb-6"><?= $month ?></h3>
                            <div class="space-y-4">
                                <?php foreach ($invoices as $inv): ?>
                                    <div class="p-4 bg-slate-800/50 rounded-lg border border-slate-700 hover:border-sky-500 transition-all duration-200 shadow-lg hover:shadow-sky-500/10">
                                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center">
                                            <div>
                                                <p class="font-bold text-lg text-white">Invoice #<?= htmlspecialchars($inv['invoice_number']) ?></p>
                                                <p class="text-sm text-slate-400">Issued on: <?= date('d M, Y', strtotime($inv['invoice_date'])) ?></p>
                                                <?php if (!empty($inv['department_name'])): ?>
                                                     <p class="text-sm text-slate-400">Department: <span class="font-medium text-slate-300"><?= htmlspecialchars($inv['department_name']) ?></span></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-left sm:text-right mt-3 sm:mt-0"><p class="font-semibold text-xl text-emerald-400">RM <?= number_format((float)$inv['total_amount'], 2) ?></p></div>
                                        </div>
                                        
                                        <!-- ========================================================== -->
                                        <!-- REVISED ACTION BAR WITH SPLIT LAYOUT -->
                                        <!-- ========================================================== -->
                                        <div class="border-t border-slate-700 mt-3 pt-3 flex items-center justify-between">
                                            <!-- Left Side Actions: Public -->
                                            <div class="flex items-center gap-3 text-sm">
                                                <a href="invoice_pdf.php?id=<?= (int)$inv['invoice_id'] ?>" target="_blank" class="text-green-400 hover:text-green-300 font-semibold flex items-center gap-1.5 transition-colors">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                                    View PDF
                                                </a>
                                                <span class="text-slate-600">|</span>
                                                <a href="invoice_pdf.php?id=<?= (int)$inv['invoice_id'] ?>&action=download" class="text-green-400 hover:text-green-300 font-semibold flex items-center gap-1.5 transition-colors">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                                    Download
                                                </a>
                                            </div>

                                            <!-- Right Side Actions: Superadmin Only -->
                                            <?php if ($userRole === 'superadmin'): ?>
                                                <div class="flex items-center gap-3 text-sm">
                                                    <a href="invoice.php?edit_id=<?= (int)$inv['invoice_id'] ?>" class="text-sky-400 hover:text-sky-300 font-semibold flex items-center gap-1.5 transition-colors">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg>
                                                        Edit
                                                    </a>
                                                    <span class="text-slate-600">|</span>
                                                    <a href="invoice_details.php?id=<?= urlencode($client_name) ?>&department=<?= urlencode($selected_department) ?>&delete_id=<?= (int)$inv['invoice_id'] ?>" class="text-red-400 hover:text-red-300 font-semibold flex items-center gap-1.5 transition-colors" onclick="return confirm('Are you sure you want to permanently delete invoice #<?= htmlspecialchars($inv['invoice_number']) ?>? This action cannot be undone.')">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                        Delete
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>