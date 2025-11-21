<?php
declare(strict_types=1);
/************************************************************
 * Bank Reconciliation — Split Screen (PDF Bank Statement × Invoices)
 * - Left pane: upload/select bank statement PDF (by batch)
 * - Right pane: invoices from your `invoices` table
 * - "Mark Paid + Stamp": inserts into `reconciliations` and stamps the PDF
 *
 * Tables used:
 *   invoices(id, invoice_no, invoice_date, client_name, grand_total, ref_no, ...)
 *   reconciliations(id, invoice_id, bank_row_id NULL, matched_amount, method, created_at)
 *   bank_files(id, batch_id, original_name, stored_path, stamped_path NULL, uploaded_at)
 *
 * Stamp notes:
 *   - Uses FPDI to draw a red "CHECKED" stamp on every page (top-right).
 *   - Stores stamped copy separately; original remains untouched.
 ************************************************************/
session_start();
ini_set('display_errors','1'); error_reporting(E_ALL);

/* ====== EDIT THESE ====== */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'rbac_app';
const DB_USER = 'root';
const DB_PASS = '';

/* Storage */
const BANK_PDF_DIR = __DIR__ . '/uploads/bankpdf';
const BANK_PDF_PUBLIC = 'uploads/bankpdf';
const STAMPED_SUBDIR = 'stamped';

/* CSRF */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf']).'">'; }
function csrf_check(): void {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403); exit('Invalid CSRF token');
  }
}

/* DB */
function db(): PDO {
  static $pdo=null;
  if ($pdo) return $pdo;
  $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

/* Utils */
function h(?string $s): string { return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 2, '.', ','); }

/* Ensure storage dirs */
@is_dir(BANK_PDF_DIR) || @mkdir(BANK_PDF_DIR, 0775, true);
@is_dir(BANK_PDF_DIR.'/'.STAMPED_SUBDIR) || @mkdir(BANK_PDF_DIR.'/'.STAMPED_SUBDIR, 0775, true);

/* ====== Ensure auxiliary table exists (idempotent) ====== */
try {
  $pdo = db();
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS bank_files (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      batch_id VARCHAR(40) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      stored_path VARCHAR(255) NOT NULL,
      stamped_path VARCHAR(255) DEFAULT NULL,
      uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  // reconciliations.created_at if missing
  $pdo->exec("ALTER TABLE reconciliations ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
} catch (Throwable $e) {
  // Soft fail — page can still render, but uploads/marking will fail until fixed
}

/* ====== Actions ====== */
$flash = '';
$currentBatch = $_GET['batch'] ?? '';

/* Upload PDF -> bank_files */
if (isset($_POST['action']) && $_POST['action']==='upload_pdf') {
  csrf_check();
  if (!isset($_FILES['bank_pdf']) || $_FILES['bank_pdf']['error']!==UPLOAD_ERR_OK) {
    $flash = 'Upload failed.';
  } else {
    $name = $_FILES['bank_pdf']['name'];
    $tmp  = $_FILES['bank_pdf']['tmp_name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext!=='pdf') {
      $flash = 'Only PDF files are allowed.';
    } else {
      $batch = 'B'.date('YmdHis').bin2hex(random_bytes(2));
      $safe  = $batch.'-'.preg_replace('~[^a-zA-Z0-9._-]+~','_',$name);
      $dest  = BANK_PDF_DIR . '/'.$safe;
      if (!@move_uploaded_file($tmp, $dest)) {
        $flash = 'Could not store uploaded PDF.';
      } else {
        $st = db()->prepare("INSERT INTO bank_files (batch_id, original_name, stored_path) VALUES (:b,:o,:p)");
        $st->execute([':b'=>$batch, ':o'=>$name, ':p'=>$safe]);
        $flash = "Uploaded PDF (Batch: $batch)";
        $currentBatch = $batch;
      }
    }
  }
}

/* Mark invoice paid + stamp PDF */
if (isset($_POST['action']) && $_POST['action']==='mark_paid_stamp') {
  csrf_check();
  $invoiceId = (int)($_POST['invoice_id'] ?? 0);
  $amount    = (float)($_POST['amount'] ?? 0);
  $batch     = trim((string)($_POST['batch'] ?? ''));
  if ($invoiceId>0 && $amount>0) {
    $pdo = db();
    $st = $pdo->prepare("INSERT INTO reconciliations (invoice_id, bank_row_id, matched_amount, method) VALUES (:i, NULL, :a, 'manual-pdf')");
    $st->execute([':i'=>$invoiceId, ':a'=>$amount]);
    $st2 = $pdo->prepare("SELECT pdf_path FROM invoices WHERE id=:id LIMIT 1");
    $st2->execute([':id'=>$invoiceId]);
    $inv = $st2->fetch();
    if ($inv && !empty($inv['pdf_path'])) {
      $src = __DIR__ . '/' . $inv['pdf_path'];
      $dst = preg_replace('~\.pdf$~i', '_checked.pdf', $src);
      $ok = stamp_pdf($src, $dst, 'CHECKED');
      if ($ok) {
        // Update pdf_path to the stamped version
        $stampedPath = preg_replace('~\.pdf$~i', '_checked.pdf', $inv['pdf_path']);
        $pdo->prepare("UPDATE invoices SET pdf_path = ? WHERE id = ?")->execute([$stampedPath, $invoiceId]);
        $flash = "Invoice #$invoiceId marked PAID and PDF stamped.";
      } else {
        $flash = "Invoice #$invoiceId marked PAID, but stamping failed (see server logs).";
      }
    } else {
      $flash = "Invoice #$invoiceId marked PAID. (No invoice PDF found.)";
    }
    $currentBatch = $batch;
  }
}
function stamp_pdf_with_image(string $src, string $dst): bool {
  if (!is_file($src)) return false;
  $img = __DIR__ . '/assets/checked_mark.png';
  if (!is_file($img)) return false;
  if (!class_exists('FPDF')) require_once __DIR__.'/FPDF/fpdf.php';
  if (!class_exists('setasign\Fpdi\Fpdi')) require_once __DIR__.'/FPDI/src/autoload.php';
  try {
    $pdf = new setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($src);
    for ($i=1; $i<=$pageCount; $i++) {
      $tplId = $pdf->importPage($i);
      $size = $pdf->getTemplateSize($tplId);
      $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
      $pdf->useTemplate($tplId);
      $pdf->Image($img, $size['width']-50, 10, 40, 0, 'PNG');
    }
    $pdf->Output($dst, 'F');
    return true;
  } catch (Throwable $e) {
    error_log('stamp_pdf_with_image error: '.$e->getMessage());
    return false;
  }
}

/* ====== FPDI stamping ====== */
/*
 * Requires FPDF + FPDI.
 * Preferred: Composer:
 *   composer require setasign/fpdf setasign/fpdi
 * If you don’t use Composer, adjust the require paths to your libs.
 */
function stamp_pdf(string $src, string $dst, string $text): bool {
  if (!is_file($src)) return false;

  // Always require FPDF before FPDI
  if (!class_exists('FPDF')) {
    $fpdfPaths = [
      __DIR__.'/FPDF/fpdf.php',
      __DIR__.'/fpdf186/fpdf.php',
      __DIR__.'/fpdf182/fpdf.php',
    ];
    foreach ($fpdfPaths as $fpdf) {
      if (is_file($fpdf)) { require_once $fpdf; break; }
    }
  }
  // Now require FPDI (autoload or manual)
  if (!class_exists('setasign\Fpdi\Fpdi')) {
    $fpdiPaths = [
      __DIR__.'/vendor/autoload.php',
      __DIR__.'/FPDI/src/autoload.php',
      __DIR__.'/fpdi/src/autoload.php',
      __DIR__.'/fpdi2/src/autoload.php',
      __DIR__.'/setasign/autoload.php',
    ];
    foreach ($fpdiPaths as $fpdi) {
      if (is_file($fpdi)) { require_once $fpdi; break; }
    }
  }
  if (!class_exists('setasign\Fpdi\Fpdi')) {
    error_log('FPDI not available. Install via composer or fix includes.');
    return false;
  }

  try {
    $pdf = new setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($src);

    for ($i=1; $i<=$pageCount; $i++) {
      $tplId = $pdf->importPage($i);
      $size = $pdf->getTemplateSize($tplId);
      $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
      $pdf->useTemplate($tplId);

      // Simple red stamp on top-right
      $pdf->SetFont('Helvetica','B', 16);
      $pdf->SetTextColor(220, 38, 38); // red-600
      $margin = 12;
      $w = $pdf->GetStringWidth($text);
      $x = max($margin, $size['width'] - $w - $margin);
      $y = 18;

      // Optional: light bounding box
      // $pdf->SetDrawColor(220,38,38);
      // $pdf->Rect($x-4, $y-6, $w+8, 10);

      $pdf->Text($x, $y, $text);
    }
    $pdf->Output($dst, 'F');
    return true;
  } catch (Throwable $e) {
    error_log('stamp_pdf error: '.$e->getMessage());
    return false;
  }
}

/* ====== Data for UI ====== */
$pdo = db();

// batches list (from bank_files)
$batches = $pdo->query("SELECT batch_id, original_name, stored_path, stamped_path, uploaded_at
                        FROM bank_files ORDER BY id DESC LIMIT 100")->fetchAll();

// selected batch record
$selected = null;
if ($currentBatch) {
  $s = $pdo->prepare("SELECT * FROM bank_files WHERE batch_id=:b LIMIT 1");
  $s->execute([':b'=>$currentBatch]);
  $selected = $s->fetch();
}

// invoice filters
$q = trim((string)($_GET['q'] ?? ''));
$status = $_GET['status'] ?? 'unpaid';

// Build invoice query with derived status
$sql = "
  SELECT i.*,
         CASE WHEN EXISTS (SELECT 1 FROM reconciliations r WHERE r.invoice_id=i.id)
              THEN 'paid' ELSE 'unpaid' END AS derived_status
  FROM invoices i
  WHERE 1=1
";
$params = [];
if ($q!=='') {
  $sql .= " AND (i.invoice_no LIKE :q OR i.client_name LIKE :q OR i.ref_no LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}
if ($status !== 'all') {
  if ($status === 'paid') {
    $sql .= " AND EXISTS (SELECT 1 FROM reconciliations r WHERE r.invoice_id=i.id)";
  } elseif ($status === 'unpaid') {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM reconciliations r WHERE r.invoice_id=i.id)";
  }
}
if (!empty($_GET['month']) && !empty($_GET['year'])) {
  $sql .= " AND MONTH(i.invoice_date) = :month AND YEAR(i.invoice_date) = :year";
  $params[':month'] = (int)$_GET['month'];
  $params[':year'] = (int)$_GET['year'];
}
$sql .= " ORDER BY i.invoice_date DESC, i.id DESC LIMIT 300";
$st = $pdo->prepare($sql); $st->execute($params);
$invoices = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reconcile Invoices — PDF Split View</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100">
  <div class="max-w-7xl mx-auto p-4 sm:p-6">
    <header class="mb-4 flex items-center justify-between">
      <h1 class="text-xl sm:text-2xl font-semibold">Bank Reconciliation (PDF)</h1>
      <div class="flex items-center gap-2">
        <a href="index.php" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2 text-white"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg><span>Dashboard</span></a>
        <form method="get" action="reconcile.php" onsubmit="return true;">
          <button type="submit" class="text-xs sm:text-sm px-3 py-2 rounded-lg border border-white/10 hover:border-white/30 bg-sky-800 hover:bg-sky-700 text-white">Refresh</button>
        </form>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="mb-4 rounded-lg bg-emerald-900/30 border border-emerald-500/30 px-4 py-3 text-sm"><?=h($flash)?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- LEFT: Upload & PDF Viewer -->
      <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur p-4">
        <h2 class="text-lg font-semibold mb-3">1) Bank Statement (PDF)</h2>

        <form method="post" enctype="multipart/form-data" class="space-y-3">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="upload_pdf">
          <input type="file" name="bank_pdf" accept="application/pdf,.pdf"
                 class="block w-full text-sm file:mr-4 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-sky-600 file:text-white hover:file:bg-sky-700" required>
          <button class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-700 text-sm">Upload PDF</button>
        </form>

        <hr class="my-4 border-white/10">

        <!-- Batch selector and Load button inside the box, aligned horizontally -->
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-3">
          <label class="text-sm opacity-80 sm:mr-2 sm:mb-0 mb-1">Batch:</label>
          <form method="get" class="flex gap-2 items-center">
            <select name="batch" class="w-auto max-w-xs bg-slate-900 border border-white/10 rounded-lg px-3 py-2 text-sm">
              <option value="">— Select a batch —</option>
              <?php foreach ($batches as $b): ?>
                <option value="<?=h($b['batch_id'])?>" <?= $currentBatch===$b['batch_id']?'selected':'' ?>>
                  <?=h($b['batch_id'])?> — <?=h($b['original_name'])?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="q" value="<?=h($q)?>">
            <input type="hidden" name="status" value="<?=h($status)?>">
            <button class="px-3 py-2 rounded-lg border border-white/10 text-sm whitespace-nowrap bg-sky-600 hover:bg-sky-700 text-white">Load</button>
          </form>
        </div>

        <?php
          $viewerPath = '';
          if ($selected) {
            // Prefer stamped if exists
            $viewerPath = $selected['stamped_path'] ? (BANK_PDF_PUBLIC.'/'.$selected['stamped_path']) : (BANK_PDF_PUBLIC.'/'.$selected['stored_path']);
          }
        ?>

        <div class="rounded-lg border border-white/10 overflow-hidden">
          <?php if ($viewerPath): ?>
            <iframe
              src="<?=h($viewerPath)?>#view=FitH"
              class="w-full h-[70vh] bg-slate-900"
              title="Bank Statement PDF"></iframe>
          <?php else: ?>
            <div class="p-8 text-center opacity-70">No PDF selected. Upload or choose a batch.</div>
          <?php endif; ?>
        </div>

        <?php if ($selected && $selected['stamped_path']): ?>
          <p class="mt-2 text-xs opacity-70">Showing <span class="font-semibold">stamped</span> copy. Original kept at upload time.</p>
        <?php endif; ?>
      </section>

      <!-- RIGHT: Invoices -->
      <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur p-4">
        <h2 class="text-lg font-semibold mb-3">2) Invoices</h2>

        <form method="get" class="flex flex-wrap items-center gap-2 mb-3">
          <input type="hidden" name="batch" value="<?=h($currentBatch)?>">
          <input type="text" name="q" placeholder="Search invoice no / client / ref no" value="<?=h($q)?>" class="px-3 py-2 rounded-lg bg-slate-900 border border-white/10 text-sm w-60">
          <select name="status" class="px-3 py-2 rounded-lg bg-slate-900 border border-white/10 text-sm">
            <option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>>Unpaid</option>
            <option value="paid"   <?= $status==='paid'  ?'selected':'' ?>>Paid</option>
            <option value="all"    <?= $status==='all'   ?'selected':'' ?>>All</option>
          </select>
          <select name="month" class="px-3 py-2 rounded-lg bg-slate-900 border border-white/10 text-sm">
            <option value="">Select Month</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= (int)($_GET['month'] ?? '') === $m ? 'selected' : '' ?>>
                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
              </option>
            <?php endfor; ?>
          </select>
          <select name="year" class="px-3 py-2 rounded-lg bg-slate-900 border border-white/10 text-sm">
            <option value="">Select Year</option>
            <?php for ($y = date('Y') - 5; $y <= date('Y'); $y++): ?>
              <option value="<?= $y ?>" <?= (int)($_GET['year'] ?? '') === $y ? 'selected' : '' ?>>
                <?= $y ?>
              </option>
            <?php endfor; ?>
          </select>
          <button class="px-3 py-2 rounded-lg border border-white/10 text-sm">Filter</button>
        </form>

        <div class="max-h-[70vh] overflow-auto rounded-lg border border-white/10">
          <table class="min-w-full text-sm align-middle">
            <thead class="bg-white/10 sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left">Invoice</th>
                <th class="px-3 py-2 text-left">Client</th>
                <th class="px-3 py-2 text-left">Date</th>
                <th class="px-3 py-2 text-right">Grand Total</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Checked</th>
                <th class="px-3 py-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$invoices): ?>
              <tr><td colspan="7" class="px-3 py-6 text-center opacity-70">No invoices found.</td></tr>
            <?php else: foreach ($invoices as $inv): ?>
              <?php
                // Determine PDF view URL
                $pdfPath = 'invoice_pdf.php?id=' . $inv['id'];
                if ($inv['derived_status'] === 'paid') {
                  $stamped = preg_replace('~\.pdf$~i', '_checked.pdf', $inv['pdf_path'] ?? ('uploads/invoices/Invoice_' . $inv['id'] . '.pdf'));
                  if (file_exists(__DIR__ . '/' . $stamped)) {
                    $pdfPath = $stamped;
                  }
                }
              ?>
              <tr class="odd:bg-white/5 invoice-row cursor-pointer" data-pdf-path="<?=h($pdfPath)?>" data-invoice-no="<?=h($inv['invoice_no'])?>">
                <td class="px-3 py-2">
                  <div class="font-medium text-sky-400 underline"><?=h($inv['invoice_no'])?></div>
                  <div class="text-xs opacity-70">#<?= (int)$inv['id']?></div>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium"><?=h($inv['client_name'])?></div>
                  <?php if (!empty($inv['ref_no'])): ?>
                    <div class="text-xs opacity-70">Ref: <?=h($inv['ref_no'])?></div>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2"><?=h($inv['invoice_date'] ?? '')?></td>
                <td class="px-3 py-2 text-right"><?=money($inv['grand_total'])?></td>
                <td class="px-3 py-2">
                  <span class="text-xs px-2 py-1 rounded-full
                    <?= ($inv['derived_status']==='paid')
                        ? 'bg-emerald-600/30 border border-emerald-400/30'
                        : 'bg-rose-600/30 border border-rose-400/30' ?>">
                    <?=h($inv['derived_status'])?>
                  </span>
                </td>
                <td class="px-3 py-2 text-center align-middle">
                  <?php if ($inv['derived_status']==='paid'): ?>
                    <span title="Checked" style="display:inline-flex;align-items:center;justify-content:center;">
                      <svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="10" cy="10" r="10" fill="#059669"/>
                        <path d="M6 10.5L9 13.5L14 8.5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <?php if ($inv['derived_status']!=='paid'): ?>
                    <form method="post" class="inline">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="mark_paid_stamp">
                      <input type="hidden" name="invoice_id" value="<?=$inv['id']?>">
                      <input type="hidden" name="amount" value="<?=$inv['grand_total']?>">
                      <input type="hidden" name="batch" value="<?=h($currentBatch)?>">
                      <button class="px-3 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-xs w-full block text-center"
                              <?= $currentBatch ? '' : 'disabled title="Upload/select a PDF batch first"' ?>>
                        Mark Paid + Stamp
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs opacity-60">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <p class="mt-4 text-xs opacity-70">
      Flow: Upload/select a bank PDF on the left → filter invoices → use <em>Mark Paid + Stamp</em> to log payment and stamp the PDF.
    </p>
  </div>

  <!-- Invoice PDF Modal -->
  <div id="pdfModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-slate-900 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] flex flex-col">
      <div class="flex justify-between items-center p-4 border-b border-slate-700">
        <span class="font-bold text-lg text-sky-400" id="pdfModalTitle">Invoice PDF</span>
        <button onclick="closePdfModal()" class="text-white text-2xl">&times;</button>
      </div>
      <div class="flex-1 overflow-auto">
        <iframe id="pdfModalFrame" src="" class="w-full h-[70vh] bg-black"></iframe>
      </div>
    </div>
  </div>
  <script>
  function closePdfModal() {
    document.getElementById('pdfModal').classList.add('hidden');
    document.getElementById('pdfModalFrame').src = '';
  }
  document.querySelectorAll('.invoice-row').forEach(function(row) {
    row.addEventListener('click', function(e) {
      if (e.target.tagName === 'BUTTON' || e.target.closest('form')) return;
      var pdfPath = row.getAttribute('data-pdf-path');
      var invoiceNo = row.getAttribute('data-invoice-no');
      if (pdfPath) {
        document.getElementById('pdfModalTitle').textContent = 'Invoice ' + invoiceNo + ' PDF';
        document.getElementById('pdfModalFrame').src = pdfPath;
        document.getElementById('pdfModal').classList.remove('hidden');
      } else {
        alert('No PDF available for this invoice.');
      }
    });
  });
  </script>
</body>
</html>
