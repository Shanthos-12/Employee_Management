<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user'])) { header("Location: login.php"); exit; }

require_once 'db_conn.php';
require __DIR__ . '/FPDF/fpdf.php';
require_once __DIR__ . '/FPDI/src/autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getPDO();
if (!isset($_GET['id'])) die("No invoice id provided");
$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) die("Invoice not found");

/* -------- helpers -------- */
function money2($v): string { return number_format((float)$v, 2); }
function safe($v, $d=''){ return ($v===null || $v==='') ? $d : (string)$v; }

function words_myr(float $n): string {
  $u=["","ONE","TWO","THREE","FOUR","FIVE","SIX","SEVEN","EIGHT","NINE","TEN","ELEVEN","TWELVE","THIRTEEN","FOURTEEN","FIFTEEN","SIXTEEN","SEVENTEEN","EIGHTEEN","NINETEEN"];
  $t=["","","TWENTY","THIRTY","FORTY","FIFTY","SIXTY","SEVENTY","EIGHTY","NINETY"];
  $f=function($x) use (&$f,$u,$t){ if($x<20)return $u[$x];
    if($x<100)return $t[intval($x/10)].($x%10?" ".$u[$x%10]:"");
    if($x<1000)return $u[intval($x/100)]." HUNDRED".($x%100?" ".$f($x%100):"");
    if($x<1e6)return $f(intval($x/1e3))." THOUSAND".($x%1e3?" ".$f($x%1e3):"");
    if($x<1e9)return $f(intval($x/1e6))." MILLION".($x%1e6?" ".$f($x%1e6):"");
    return (string)$x; };
  $r=floor($n); $s=round(($n-$r)*100);
  $out=($r?$f((int)$r):"ZERO")." RINGGIT"; if($s)$out.=" ".$f((int)$s)." SEN"; return $out." ONLY";
}
// Compute grand, SST and final amount. If 'final_amount' was saved use it, otherwise compute by adding 8% SST.
$grandStored = (float)($inv['grand_total'] ?? 0);
$postedFinal = isset($inv['final_amount']) && $inv['final_amount'] !== null && $inv['final_amount'] !== '' ? (float)$inv['final_amount'] : null;
if ($postedFinal !== null) {
  $finalAmount = $postedFinal;
  // If final was stored, infer SST as final - grand (since SST is added on top)
  $sst_amount = round($finalAmount - $grandStored, 2);
} else {
  $sst_amount = round($grandStored * 0.08, 2);
  // Final amount is grand + SST (SST is added, not subtracted)
  $finalAmount = round($grandStored + $sst_amount, 2);
}

$amountWords = safe($inv['amount_words'], words_myr((float)$finalAmount));

/* -------- PDF class with letterhead -------- */
class InvoicePDF extends Fpdi {
    public string $templatePath = '';
    function Header(){
        if ($this->templatePath && file_exists($this->templatePath)) {
            $this->setSourceFile($this->templatePath);
            $templateId = $this->importPage(1);
            $this->useTemplate($templateId, ['x' => 0, 'y' => 0, 'width' => 210]);
            $this->Ln(45);
        }
    }
}

/* -------- build the PDF -------- */
$pdf = new InvoicePDF('P','mm','A4');
$pdf->templatePath = __DIR__ . '/Sfiglobal_letter_head.pdf';
$pdf->SetMargins(12, 10, 12);
$pdf->AddPage();
$pdf->SetTextColor(0,0,0);

/* INVOICE centered */
$pdf->SetFont('Arial','B',16);
$pdf->SetY(45);
$pdf->SetX(0);
$pdf->Cell(0,10,'INVOICE',0,1,'C');

/* Two-column: BILL TO (left) and meta (right) - align vertically */
$pdf->SetFont('Arial','B',10);
$leftX = 12; $rightX = 122; $blockY = 58;
$pdf->SetXY($leftX, $blockY);
$pdf->Cell(100,6,'BILL TO :',0,1,'L');
$pdf->SetFont('Arial','B',11);
$pdf->MultiCell(100,6, safe($inv['client_name']), 0, 'L');
$pdf->SetFont('Arial','',10);
$pdf->MultiCell(100,5, safe($inv['client_address']), 0, 'L');

// ==========================================================
// NEW: Add Department to the Bill To section
// ==========================================================
if (!empty($inv['department_name'])) {
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(20, 6, 'Department:', 0, 0, 'L');
    $pdf->Cell(2, 6, '', 0, 0);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(80, 6, safe($inv['department_name']), 0, 1, 'L');
}

$metaY = 55; // Fixed Y position
$pdf->SetXY(122, $metaY);
$pdf->SetFont('Arial','',10);
$pdf->Cell(76,6, 'DATE        : '.date('d M Y', strtotime(safe($inv['invoice_date'], date('Y-m-d')))),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6, 'ATTENTION   : '.safe($inv['attention']),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6, 'REF NO.     : '.safe($inv['ref_no']),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6, 'SST NO.     : '.safe($inv['sst_no'], 'B16-2509-32000225'),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6, 'TAX NO.     : '.safe($inv['tax_no'], 'C24059437010'),0,1,'L');

$pdf->Ln(4);

/* Section heading + line */
$pdf->SetFont('Arial','B',10);
$monthStr = strtoupper(date('M Y', strtotime(safe($inv['invoice_date'], date('Y-m-d')))));
$pdf->Cell(0,7,'LABOUR CHARGES '.safe($inv['client_name']).' - FOR THE MONTH OF '.$monthStr,0,1,'L');
$pdf->SetLineWidth(0.4);
$pdf->Line(12,$pdf->GetY(),198,$pdf->GetY());
$pdf->Ln(2);

/* Row helper */
function row_line($pdf, $label, $amount, $bold=false) {
  $pdf->SetFont('Arial', $bold?'B':'', 10);
  $pdf->Cell(140,8, $label, 0, 0, 'L');
  $pdf->Cell(10,8, 'RM', 0, 0, 'R');
  $pdf->Cell(36,8, money2($amount), 0, 1, 'R');
}

/* Rows */
row_line($pdf,'TOTAL BASIC AMOUNT',  $inv['basic_amount']);
row_line($pdf,'TOTAL OVERTIME',      $inv['overtime_amount']);
row_line($pdf,'TOTAL SUNDAY / PH OT',$inv['sunday_ph_amount']);
row_line($pdf,'TRANSPORT',           $inv['transport_amount']);
row_line($pdf,'ALLOWANCE (Consultant Fee,Utility,Hostel, Other Allowances)',           $inv['allowance_amount']);
row_line($pdf,'TOTAL',               $inv['total_amount'], true);
row_line($pdf,'DEDUCTION',           $inv['deduction_amount']);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(140,9,'GRAND TOTAL',0,0,'L');
$pdf->Cell(10,9,'RM',0,0,'R');
$yBox = $pdf->GetY();
$pdf->Rect(172, $yBox+1.2, 30, 7.6);
$pdf->Cell(36,9, money2($inv['grand_total']),0,1,'R');

$pdf->Ln(4);
$pdf->Ln(2);
// Show SST and Final Amount (SST is subtracted)
$pdf->SetFont('Arial','',10);
$pdf->Cell(140,7,'SST (8%)',0,0,'L'); $pdf->Cell(10,7,'RM',0,0,'R'); $pdf->Cell(36,7,money2($sst_amount),0,1,'R');
$pdf->SetFont('Arial','B',11);
$pdf->Cell(140,9,'FINAL AMOUNT',0,0,'L'); $pdf->Cell(10,9,'RM',0,0,'R');
$yFinalBox = $pdf->GetY();
$pdf->Rect(172, $yFinalBox+1.2, 30, 7.6);
$pdf->Cell(36,9, money2($finalAmount),0,1,'R');

/* Amount in words */
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,6,'TOTAL AMOUNT OF RINGGIT MALAYSIA :',0,1,'L');
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,7, strtoupper($amountWords), 0, 1, 'L');
$pdf->Ln(3);

/* Bank box + signature boxes */
$pdf->SetFont('Arial','',10);
$boxY = $pdf->GetY();
$pdf->Rect(12, $boxY, 100, 28);
$pdf->SetXY(16, $boxY+4);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,5, safe($inv['bank_beneficiary'], 'SFI GLOBAL (M) SDN BHD'), 0, 1, 'L');
$pdf->SetFont('Arial','',10);
$pdf->SetX(16); $pdf->Cell(0,5, 'A/C NO '.safe($inv['bank_account'],'512781055395'), 0, 1, 'L');
$pdf->SetX(16); $pdf->Cell(0,5, safe($inv['bank_name'],'MAYBANK KOTA KEMUNING'), 0, 1, 'L');

$y2 = $boxY + 34;
$pdf->Rect(12,  $y2, 90, 18);
$pdf->Rect(112, $y2, 86, 18);
$pdf->SetXY(14,  $y2+14); $pdf->Cell(0,4,'SFI',0,0,'L');
$pdf->SetXY(114, $y2+14); $pdf->Cell(0,4,'RECEIVED BY',0,0,'L');

/* Output */
$fname = 'Invoice_'.preg_replace('/[^A-Za-z0-9_\-]/','_', safe($inv['invoice_no'],'INV')).'.pdf';
$dir = __DIR__ . '/uploads/invoices/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$path = $dir . $fname;
$pdf->Output('F', $path);

// Update pdf_path in database
$relativePath = 'uploads/invoices/' . $fname;
$stmt = $pdo->prepare("UPDATE invoices SET pdf_path = ? WHERE id = ?");
$stmt->execute([$relativePath, $id]);

// Now output to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fname . '"');
readfile($path);