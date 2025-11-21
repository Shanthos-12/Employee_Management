<?php
declare(strict_types=1);
session_start();

require __DIR__.'/FPDF/fpdf.php';
require_once __DIR__ . '/FPDI/src/autoload.php';
use setasign\Fpdi\Fpdi;

require_once 'db_conn.php';
$pdo = getPDO();

/* ---------- helpers ---------- */
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function money2($v){ return number_format((float)$v,2); }
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

class PDF extends Fpdi {
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

/* ---------- Collect Data from Form POST ---------- */
$P = fn($k,$d='') => trim((string)($_POST[$k] ?? $d));
$N = fn($k) => (float)($_POST[$k] ?? 0);

$basic = $N('basic_amount'); $ot = $N('overtime_amount'); $sunph = $N('sunday_ph_amount');
$tr = $N('transport_amount'); $alw = $N('allowance_amount'); $total = $basic+$ot+$sunph+$tr+$alw;
$ded = $N('deduction_amount'); $grand = max(0, $total - $ded);

$postedFinal = isset($_POST['final_amount']) && $_POST['final_amount'] !== '' ? (float) $_POST['final_amount'] : null;
// Determine SST and final amount. SST should be added (included on top of grand).
// Prefer posted final_amount if present (from invoice.php JS), otherwise compute here as grand + 8% SST.
if ($postedFinal !== null) {
  // If JS posted a final amount, accept it. Infer sst as final - grand (could be negative if mismatch).
  $final = (float) $postedFinal;
  $sst_amount = round($final - $grand, 2);
} else {
  $sst_amount = round($grand * 0.08, 2);
  // Final is grand plus SST (SST is added, not deducted)
  $final = round($grand + $sst_amount, 2);
}
$amount_words = words_myr(max(0, $final));

/* ---------- Generate PDF ---------- */
$pdf = new PDF('P','mm','A4');
$pdf->templatePath = __DIR__ . '/Sfiglobal_letter_head.pdf';
$pdf->SetMargins(12,10,12);
$pdf->AddPage();
$pdf->SetTextColor(0);

/* Title */
$pdf->SetFont('Arial','B',16);
$pdf->SetY(38);
$pdf->Cell(0,0,'INVOICE',0,1,'C');

/* Bill to + meta */
$pdf->SetFont('Arial','B',10);
// Position the left "BILL TO" block and the right meta block on the same Y so they are parallel
$leftX = 12;
$rightX = 122;
$blockY = 58; // vertical position for both blocks (tweak this to move up/down)

$pdf->SetXY($leftX, $blockY);
$pdf->Cell(100,6,'BILL TO :',0,1,'L');

$pdf->SetFont('Arial','B',11);
$pdf->MultiCell(100,6, $P('client_name'),0,'L');
$pdf->SetFont('Arial','',10);
$pdf->MultiCell(100,5, $P('client_address'),0,'L');

// Department (stays under the left block)
if ($P('department_name')) {
  $pdf->SetFont('Arial','B',10);
  $pdf->Cell(20, 6, 'Department:', 0, 0, 'L');
  $pdf->SetFont('Arial','',10);
  $pdf->Cell(80, 6, $P('department_name'), 0, 1, 'L');
}

$metaY = $blockY; // align the meta column to the left block
$pdf->SetXY($rightX,$metaY);
$date = $P('invoice_date') ?: date('Y-m-d');
$pdf->Cell(76,6,'DATE        : '.date('d M Y', strtotime($date)),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6,'ATTENTION   : '.$P('attention'),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6,'REF NO.     : '.$P('ref_no'),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6,'SST NO.     : '.($P('sst_no') ?: 'B16-2509-32000225'),0,1,'L');
$pdf->SetX(122); $pdf->Cell(76,6,'TAX NO.     : '.($P('tax_no') ?: 'C24059437010'),0,1,'L');

$pdf->Ln(4);

/* Section heading */
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,7,'LABOUR CHARGES '.$P('client_name').' - FOR THE MONTH OF '.strtoupper(date('M Y', strtotime($date))),0,1,'L');
$pdf->SetLineWidth(0.4);
$pdf->Line(12,$pdf->GetY(),198,$pdf->GetY());
$pdf->Ln(2);

/* rows */
function rowL($pdf,$label,$amt,$bold=false){
  $pdf->SetFont('Arial',$bold?'B':'',10);
  $pdf->Cell(140,8,$label,0,0,'L');
  $pdf->Cell(10,8,'RM',0,0,'R');
  $pdf->Cell(36,8,money2($amt),0,1,'R');
}
rowL($pdf,'TOTAL BASIC AMOUNT', $basic);
rowL($pdf,'TOTAL OVERTIME',     $ot);
rowL($pdf,'TOTAL SUNDAY / PH OT',$sunph);
rowL($pdf,'TRANSPORT',           $tr); // Corrected typo from TRASPORT
rowL($pdf,'ALLOWANCE (Consultant Fee,Utility,Hostel, Other Allowances)',          $alw);
rowL($pdf,'TOTAL',              $total, true);
rowL($pdf,'DEDUCTION',          $ded);

$pdf->SetFont('Arial','B',11);
$pdf->Cell(140,9,'GRAND TOTAL',0,0,'L');
$pdf->Cell(10,9,'RM',0,0,'R');
$y=$pdf->GetY();
$pdf->Rect(171,$y+1.2,30,7.6);
$pdf->Cell(36,9,money2($grand),0,1,'R');

// Show SST and Final Amount
$pdf->Ln(2);
$pdf->SetFont('Arial','',10);
$pdf->Cell(140,7,'SST (8%)',0,0,'L'); $pdf->Cell(10,7,'RM',0,0,'R'); $pdf->Cell(36,7,money2($sst_amount),0,1,'R');
$pdf->SetFont('Arial','B',11);
$pdf->Cell(140,9,'FINAL AMOUNT',0,0,'L'); $pdf->Cell(10,9,'RM',0,0,'R');
$yFinal = $pdf->GetY();
// draw a rectangle like grand total box around final amount
$pdf->Rect(171,$yFinal+1.2,30,7.6);
$pdf->Cell(36,9,money2($final),0,1,'R');

$pdf->Ln(4);
/* words + bank */
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,6,'TOTAL AMOUNT OF RINGGIT MALAYSIA :',0,1,'L');
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,7, strtoupper($amount_words),0,1,'L');
$pdf->Ln(3);

$pdf->SetFont('Arial','',10);
$boxY=$pdf->GetY();
$pdf->Rect(12,$boxY,100,28);
$pdf->SetXY(16,$boxY+4);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,5, $_POST['bank_beneficiary'] ?? 'SFI GLOBAL (M) SDN BHD',0,1,'L');
$pdf->SetFont('Arial','',10);
$pdf->SetX(16); $pdf->Cell(0,5,'A/C NO '.($_POST['bank_account'] ?? '512781055395'),0,1,'L');
$pdf->SetX(16); $pdf->Cell(0,5, $_POST['bank_name'] ?? 'MAYBANK KOTA KEMUNING',0,1,'L');

$y2=$boxY+34;
$pdf->Rect(12,$y2,90,18); $pdf->Rect(112,$y2,86,18);
$pdf->SetXY(14,$y2+14); $pdf->Cell(0,4,'SFI',0,0,'L');
$pdf->SetXY(114,$y2+14); $pdf->Cell(0,4,'RECEIVED BY',0,0,'L');

/* stream as PDF */
$bin = $pdf->Output('S');
header('Content-Type: application/pdf');
header('Content-Length: '.strlen($bin));
header('Cache-Control: no-store');
echo $bin;