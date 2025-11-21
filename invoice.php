<?php
declare(strict_types=1);
session_start();
require_once 'db_conn.php';
if (empty($_SESSION['user'])) {
  header("Location: login.php");
  exit;
}
$pdo = getPDO();

/* ---------- helpers ---------- */
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function words_myr(float $n): string { /* ...your function is unchanged... */ $u=["","ONE","TWO","THREE","FOUR","FIVE","SIX","SEVEN","EIGHT","NINE","TEN","ELEVEN","TWELVE","THIRTEEN","FOURTEEN","FIFTEEN","SIXTEEN","SEVENTEEN","EIGHTEEN","NINETEEN"];$t=["","","TWENTY","THIRTY","FORTY","FIFTY","SIXTY","SEVENTY","EIGHTY","NINETY"];$f=function($x)use(&$f,$u,$t){if($x<20)return $u[$x];if($x<100)return $t[intval($x/10)].($x%10?" ".$u[$x%10]:"");if($x<1000)return $u[intval($x/100)]." HUNDRED".($x%100?" ".$f($x%100):"");if($x<1e6)return $f(intval($x/1e3))." THOUSAND".($x%1e3?" ".$f($x%1e3):"");if($x<1e9)return $f(intval($x/1e6))." MILLION".($x%1e6?" ".$f($x%1e6):"");return(string)$x;};$r=floor($n);$s=round(($n-$r)*100);$out=($r?$f((int)$r):"ZERO")." RINGGIT";if($s)$out.=" ".$f((int)$s)." SEN";return $out." ONLY"; }

/* ---------- EDIT & UPDATE LOGIC ---------- */
$is_editing = false;
$edit_data = [];
if (isset($_GET['edit_id'])) {
    $is_editing = true;
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_id']]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_data) {
        die("Invoice not found.");
    }
}

// Compute a safe render value for salary_month used in the form hidden input
$render_salary_month = '';
if (!empty($_POST['salary_month'])) {
  $render_salary_month = trim((string)$_POST['salary_month']);
} elseif (!empty($_GET['month']) && !empty($_GET['year'])) {
  $render_salary_month = trim((string)($_GET['month'] . ' ' . $_GET['year']));
} else {
  $render_salary_month = $edit_data['salary_month'] ?? '';
}

/* ---------- SAVE & UPDATE INVOICE LOGIC ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $G = fn($k, $d = '') => trim((string) ($_POST[$k] ?? $d));
  $invoice_no = $G('invoice_no');
  $invoice_date = $G('invoice_date') ?: date('Y-m-d');
  $client_name = $G('client_name');
  $client_addr = $G('client_address');
  $attention = $G('attention');
  $ref_no = $G('ref_no');
  $tax_no = $G('tax_no');
  $currency = strtoupper($G('currency', 'MYR'));
    $basic = (float) $G('basic_amount', 0);
    $overtime = (float) $G('overtime_amount', 0);
    $sundayph = (float) $G('sunday_ph_amount', 0);
    $transport = (float) $G('transport_amount', 0);
    $allowance = (float) $G('allowance_amount', 0);
    $total = $basic + $overtime + $sundayph + $transport + $allowance;
    $deduction = (float) $G('deduction_amount', 0);
    $grand = max(0, $total - $deduction);
  // Server-side compute SST and final amount (SST is added on top of grand)
  $sst = round($grand * 0.08, 2);
  $final_amount = round($grand + $sst, 2);
    // Use final amount for the amount in words
    $amount_words = words_myr($final_amount);
    $bank_name = $G('bank_name'); $bank_beneficiary = $G('bank_beneficiary'); $bank_account = $G('bank_account'); $ssd_no = $G('ssd_no'); $department_name = $G('department_name');
    // Get salary month from POST (hidden field) or construct from GET parameters (month + year)
    $salary_month = $G('salary_month', '');
    if (empty($salary_month)) {
        // Construct from GET parameters: month=September&year=2025 -> "September 2025"
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? '';
        if ($month && $year) {
            $salary_month = $month . ' ' . $year;
        } else {
            $salary_month = $edit_data['salary_month'] ?? '';
        }
    }
    
  // salary_month determined from POST/GET/edit data
    
    $pdf_path = $is_editing ? ($edit_data['pdf_path'] ?? NULL) : NULL;
    // include salary_month and final_amount before amount_words in args
    $args = [$invoice_no, $invoice_date, $client_name, $client_addr, $attention, $ref_no, $tax_no, $basic, $overtime, $sundayph, $transport, $allowance, $total, $deduction, $grand, $final_amount, $amount_words, $currency, $bank_name, $bank_beneficiary, $bank_account, $ssd_no, $department_name, $salary_month, $pdf_path];    if (!empty($_POST['update_id'])) {
        $update_id = (int)$_POST['update_id'];
  $sql = "UPDATE invoices SET invoice_no=?, invoice_date=?, client_name=?, client_address=?, attention=?, ref_no=?, tax_no=?, basic_amount=?, overtime_amount=?, sunday_ph_amount=?, transport_amount=?, allowance_amount=?, total_amount=?, deduction_amount=?, grand_total=?, final_amount=?, amount_words=?, currency=?, bank_name=?, bank_beneficiary=?, bank_account=?, ssd_no=?, department_name=?, salary_month=?, pdf_path=? WHERE id=?";
        $args[] = $update_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        header("Location: invoice.php?updated=1&id=" . $update_id);
    } else {
  $sql = "INSERT INTO invoices (invoice_no,invoice_date,client_name,client_address,attention,ref_no,tax_no, basic_amount,overtime_amount,sunday_ph_amount,transport_amount,allowance_amount,total_amount, deduction_amount,grand_total,final_amount,amount_words,currency,bank_name,bank_beneficiary,bank_account, ssd_no, department_name, salary_month, pdf_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $newId = (int) $pdo->lastInsertId();
        header("Location: invoice.php?saved=1&id=" . $newId);
    }
    exit;
}

/* ---------- Page Data ---------- */
$invoice_count = (int)($pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn());
$total_invoiced = (float)($pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices")->fetchColumn());
$stmt = $pdo->query("SELECT DISTINCT client_name as name, client_name as id FROM invoices WHERE client_name IS NOT NULL AND client_name != '' ORDER BY client_name ASC");
$companies_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$companies_json = json_encode($companies_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <meta charset="utf-8" /><title><?= $is_editing ? 'Edit Invoice' : 'SFI GLOBAL INVOICE' ?></title><meta name="viewport" content="width=device-width, initial-scale=1" /><script src="https://cdn.tailwindcss.com"></script>
    <style>.glass{background:linear-gradient(180deg,rgba(255,255,255,.1),rgba(255,255,255,.06));backdrop-filter:blur(8px)}input[type=date]::-webkit-calendar-picker-indicator{filter:invert(1)}</style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white min-h-screen">
  <header class="sticky top-0 z-40 bg-slate-900/60 backdrop-blur border-b border-slate-800">
    <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
      <h1 class="text-xl md:text-2xl font-semibold flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg><span><?= $is_editing ? 'Edit Invoice #'.h($edit_data['invoice_no']) : 'SFI GLOBAL INVOICE' ?></span></h1>
      <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg><span>Dashboard</span></a>
    </div>
  </header>
  <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
    <!-- Message Blocks -->
    <?php if (!empty($_GET['saved'])): ?>
      <div class="p-3 rounded-xl bg-emerald-600/15 border border-emerald-500/30 text-emerald-300 flex items-center gap-3"><span>Invoice saved successfully.</span><a class="underline ml-auto" href="invoice_pdf.php?id=<?= (int)$_GET['id'] ?>">Open final PDF</a></div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
      <div class="p-3 rounded-xl bg-sky-600/15 border border-sky-500/30 text-sky-300 flex items-center gap-3"><span>Invoice updated successfully.</span><a class="underline ml-auto" href="invoice_pdf.php?id=<?= (int)$_GET['id'] ?>">Open final PDF</a></div>
    <?php endif; ?>
    <?php if (!empty($_GET['last_deleted'])): ?>
      <div class="p-3 rounded-xl bg-red-600/15 border border-red-500/30 text-red-300">The last invoice for the company was deleted. It has been removed from the browser below.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'company_not_found'): ?>
      <div class="p-3 rounded-xl bg-amber-600/15 border border-amber-500/30 text-amber-300">Error: The requested company could not be found.</div>
    <?php endif; ?>
    
    <!-- Metric Cards -->
    <section class="grid sm:grid-cols-2 gap-6">
      <div class="bg-white/10 backdrop-blur p-5 rounded-2xl shadow-xl border border-slate-700/50"><p class="text-sm text-slate-400">Total Amount Invoiced</p><p class="text-3xl font-bold text-emerald-400 mt-1"><?= number_format($total_invoiced, 2) ?> <span class="text-base text-slate-400">MYR</span></p></div>
      <div class="bg-white/10 backdrop-blur p-5 rounded-2xl shadow-xl border border-slate-700/50"><p class="text-sm text-slate-400">Total Invoices Issued</p><p class="text-3xl font-bold text-sky-400 mt-1"><?= $invoice_count ?></p></div>
    </section>

    <div class="grid lg:grid-cols-2 gap-6">
      <!-- Left: Form -->
      <section class="glass rounded-2xl border border-slate-800 p-6">
        <form id="invForm" method="post" class="space-y-6">
          <input type="hidden" name="update_id" value="<?= h($edit_data['id'] ?? '') ?>">
          
          <div class="grid md:grid-cols-4 gap-4">
            <div><label class="text-xs text-slate-400">Invoice No.</label><input name="invoice_no" required class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="<?= h($edit_data['invoice_no'] ?? '') ?>"></div>
            <div><label class="text-xs text-slate-400">Date</label><input type="date" name="invoice_date" value="<?= h($edit_data['invoice_date'] ?? date('Y-m-d')) ?>" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700"></div>
            <div><label class="text-xs text-slate-400">Ref No.</label><input name="ref_no" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="<?= h($edit_data['ref_no'] ?? '') ?>"></div>
            <div><label class="text-xs text-slate-400">Currency</label><input name="currency" value="<?= h($edit_data['currency'] ?? 'MYR') ?>" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700"></div>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="text-xs text-slate-400">Bill To (Client)</label>
              <input name="client_name" required class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="<?= h($edit_data['client_name'] ?? (isset($_GET['type']) ? ucfirst($_GET['type']) . ' Employees' : '')) ?>">
              <textarea name="client_address" rows="6" class="mt-2 w-full p-3 rounded-xl bg-slate-900 border border-slate-700"><?= h($edit_data['client_address'] ?? '') ?></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="text-xs text-slate-400">Attention</label><input name="attention" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="<?= h($edit_data['attention'] ?? '') ?>"></div>
              <div><label class="text-xs text-slate-400">SST No.</label><input readonly class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="B16-2509-32000225"></div>
              <div><label class="text-xs text-slate-400">Tax No.</label><input readonly class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700" value="C24059437010"></div>
              <!-- hidden fields so invoice_preview.php receives these values -->
              <input type="hidden" name="sst_no" value="B16-2509-32000225">
              <input type="hidden" name="tax_no" value="C24059437010">
        <input type="hidden" name="final_amount" value="<?= h($edit_data['final_amount'] ?? '') ?>">
        <input type="hidden" name="salary_month" value="<?= h($render_salary_month) ?>">
              <!-- salary_month debug removed -->
            </div>
          </div>
          <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="text-xs text-slate-400">Beneficiary</label><input name="bank_beneficiary" value="<?= h($edit_data['bank_beneficiary'] ?? 'SFI GLOBAL (M) SDN BHD') ?>" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700"></div>
            <div><label class="text-xs text-slate-400">A/C No</label><input name="bank_account" value="<?= h($edit_data['bank_account'] ?? '512781055395') ?>" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700"></div>
            <div><label class="text-xs text-slate-400">Bank</label><input name="bank_name" value="<?= h($edit_data['bank_name'] ?? 'MAYBANK KOTA KEMUNING') ?>" class="w-full p-3 rounded-xl bg-slate-900 border border-slate-700"></div>
          </div>
          <div class="rounded-xl border border-slate-800 overflow-hidden">
            <div class="px-4 py-2 bg-slate-900/60 border-b border-slate-800 font-semibold">LABOUR CHARGES</div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
              <?php $rowsCfg = [['TOTAL BASIC AMOUNT', 'basic_amount', false, $edit_data['basic_amount'] ?? (isset($_GET['basic_amount']) ? (float)$_GET['basic_amount'] : 0)], ['TOTAL OVERTIME', 'overtime_amount', false, $edit_data['overtime_amount'] ?? (isset($_GET['overtime_amount']) ? (float)$_GET['overtime_amount'] : 0)],['TOTAL SUNDAY / PH OT', 'sunday_ph_amount', false, $edit_data['sunday_ph_amount'] ?? (isset($_GET['sunday_ph_amount']) ? (float)$_GET['sunday_ph_amount'] : 0)], ['TRANSPORT', 'transport_amount', false, $edit_data['transport_amount'] ?? 0],['ALLOWANCE', 'allowance_amount', false, $edit_data['allowance_amount'] ?? (isset($_GET['allowance_amount']) ? (float)$_GET['allowance_amount'] : 0)], ['TOTAL', 'total_amount', true, $edit_data['total_amount'] ?? 0],['DEDUCTION', 'deduction_amount', false, $edit_data['deduction_amount'] ?? (isset($_GET['deduction_amount']) ? (float)$_GET['deduction_amount'] : 0)], ['GRAND TOTAL', 'grand_total', true, $edit_data['grand_total'] ?? 0]];
              foreach ($rowsCfg as $r): [$label, $name, $bold, $val] = $r; ?>
                <div class="grid grid-cols-2 items-center gap-4"><label class="text-sm text-slate-300"><?= h($label) ?></label><div class="flex items-center rounded-lg bg-slate-900 border border-slate-700 focus-within:ring-2 focus-within:ring-sky-500 transition"><span class="pl-3 text-sm text-slate-400">RM</span><input type="number" step="0.01" name="<?= h($name) ?>" class="w-full p-2 bg-transparent text-white text-right outline-none <?= $bold ? 'font-bold text-emerald-300' : '' ?>" value="<?= h($val) ?>"></div></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="flex flex-wrap gap-3 items-center">
            <button class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M7.5 9.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" /><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm2 0h10v9H5V4z" clip-rule="evenodd" /></svg><span><?= $is_editing ? 'Update Invoice' : 'Save Invoice' ?></span></button>
            <button type="button" id="downloadDraft" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" /></svg><span>Download draft PDF</span></button>
            <?php if ($is_editing): ?>
                <a href="invoice.php" class="text-sm text-slate-400 hover:text-white">Cancel Edit</a>
            <?php endif; ?>
          </div>
        </form>
      </section>
      
      <!-- Right: Live PDF Preview -->
      <section class="glass rounded-2xl border border-slate-800 p-3 flex flex-col"><h2 class="text-lg font-semibold px-2 mb-2">Live PDF Preview</h2><iframe id="pdfFrame" class="w-full h-full min-h-[50vh] rounded-xl border border-slate-800 bg-white"></iframe></section>
    </div>

    <!-- Invoice Browser -->
    <section class="glass rounded-2xl border border-slate-800 p-6">
      <div id="invoice-browser"><div id="company-view"><h2 class="text-xl font-bold mb-4">Invoice Browser</h2><input id="companySearch" type="text" placeholder="Search for a company..." class="w-full p-2 mb-4 rounded-lg bg-slate-800 border border-slate-600"><div id="company-list" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6"></div></div></div>
    </section>
  </main>
  
  <script>
    document.addEventListener('DOMContentLoaded', () => {
        // ==========================================================
        // SECTION 1: PDF PREVIEW LOGIC (FULLY RESTORED)
        // ==========================================================
        const form = document.getElementById('invForm');
        const iframe = document.getElementById('pdfFrame');
        const inputs = Array.from(form.querySelectorAll('input, textarea, select'));
        const calcFields = ['basic_amount', 'overtime_amount', 'sunday_ph_amount', 'transport_amount', 'allowance_amount', 'deduction_amount'];
        let previewTimeout;

        function recalcTotals() {
      const v = id => parseFloat((form.querySelector(`[name="${id}"]`)?.value || '0')) || 0;
      const total = v('basic_amount') + v('overtime_amount') + v('sunday_ph_amount') + v('transport_amount') + v('allowance_amount');
      const deduction = v('deduction_amount');
      const grand = Math.max(0, total - deduction);
  // SST is 8% and is added on top of grand total
  const sst = +(grand * 0.08);
  const finalAmount = grand + sst;
      form.querySelector('[name="total_amount"]').value = total.toFixed(2);
      form.querySelector('[name="grand_total"]').value = grand.toFixed(2);
      // write into the hidden final_amount field so invoice_preview.php uses the same value
      const finalEl = form.querySelector('[name="final_amount"]');
      if (finalEl) finalEl.value = finalAmount.toFixed(2);
        }

        function updatePreview(download = false) {
            const fd = new FormData(form);
            fetch('invoice_preview.php', { method: 'POST', body: fd })
                .then(r => r.blob())
                .then(blob => {
                    const url = URL.createObjectURL(blob);
                    if (download) {
                        const a = document.createElement('a'); a.href = url; a.download = 'Invoice_Draft.pdf'; document.body.appendChild(a); a.click(); document.body.removeChild(a); setTimeout(() => URL.revokeObjectURL(url), 100);
                    } else { iframe.src = url; }
                }).catch(console.error);
        }

        // --- THIS IS THE RESTORED LIVE UPDATE LOGIC ---
        // Recalculate totals whenever a number input changes
        calcFields.forEach(name => form.querySelector(`[name="${name}"]`).addEventListener('input', recalcTotals));

        // Update the PDF preview whenever ANY form field changes (with a 400ms delay)
        inputs.forEach(el => {
            el.addEventListener('input', () => {
                clearTimeout(previewTimeout);
                previewTimeout = setTimeout(() => updatePreview(false), 400);
            });
        });

        // Add listener for the download button
        document.getElementById('downloadDraft').addEventListener('click', () => updatePreview(true));

        // Trigger an initial calculation and preview when the page loads
        // This is crucial for showing the correct data when in edit mode
        recalcTotals();
        updatePreview(false);


        // ==========================================================
        // SECTION 2: INVOICE BROWSER (FLIPPING CARDS) LOGIC
        // ==========================================================
        const companyList = document.getElementById('company-list');
        const companySearch = document.getElementById('companySearch');
        const allCompanies = <?= $companies_json ?>;

        function displayCompanies(companies) {
            companyList.innerHTML = '';
            if (companies.length === 0) { companyList.innerHTML = '<p class="text-slate-400 col-span-full">No companies found.</p>'; return; }
            companies.forEach(company => {
                const cardHtml = `<div class="group h-48 [perspective:1000px]"><div class="relative w-full h-full transition-transform duration-500 [transform-style:preserve-3d] group-hover:[transform:rotateY(180deg)]"><div class="absolute w-full h-full [backface-visibility:hidden] flex flex-col items-center justify-center text-center p-4 rounded-lg bg-slate-900 border border-slate-700"><h3 class="text-lg font-bold text-sky-400">${escapeHtml(company.name)}</h3><p class="text-slate-400 mt-2">Hover to see options</p></div><div class="absolute w-full h-full [backface-visibility:hidden] flex items-center justify-center rounded-lg bg-indigo-600 text-white [transform:rotateY(180deg)]"><a href="invoice_details.php?id=${encodeURIComponent(company.id)}" class="px-6 py-3 bg-indigo-700 hover:bg-indigo-800 rounded-lg font-semibold transition-colors">View Invoices</a></div></div></div>`;
                companyList.insertAdjacentHTML('beforeend', cardHtml);
            });
        }
        
        companySearch.addEventListener('input', () => {
            const searchTerm = companySearch.value.toLowerCase();
            const filteredCompanies = allCompanies.filter(c => c.name.toLowerCase().includes(searchTerm));
            displayCompanies(filteredCompanies);
        });

        function escapeHtml(str) { return (str ?? '').replace(/[&<>"']/g, match => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[match])); }

        // Initial display of companies
        displayCompanies(allCompanies);
    });
</script>
</body>
</html>