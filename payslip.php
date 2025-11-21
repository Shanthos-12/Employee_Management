<?php
require __DIR__ . '/FPDF/fpdf.php';

session_start();
require_once 'db_conn.php';
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$pdo = getPDO();

if (!isset($_GET['id'])) die("No payroll ID provided");
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("
  SELECT p.*,
         e.name, e.email, e.position, e.passport_no, e.department, e.join_year, e.phone
    FROM payroll p
    JOIN employees e ON e.id = p.employee_id
   WHERE p.id = ? LIMIT 1
");
$stmt->execute([$id]);
$payroll = $stmt->fetch();
if (!$payroll) die("Payroll record not found");

/* ====== Helpers ====== */
function moneyv($v) { return is_null($v) ? 0 : (float)$v; }
function rm($v) { return number_format((float)$v, 2); }
function safe($s) { return $s !== null ? (string)$s : ''; }

/* ====== Derived fields for the formatted slip ====== */
$periodShort = date('M-y', strtotime(($payroll['year'] ?? date('Y')) . '-' . ($payroll['month'] ?? date('m')) . '-01'));
$CURRENCY = strtoupper(trim($payroll['currency'] ?? 'MYR'));

// Calculate combined working days for Basic
$workingDays8hr = (int)($payroll['working_days_8hr'] ?? 0);
$workingDays12hr = (int)($payroll['working_days_12hr'] ?? 0);
$totalWorkingDays = $workingDays8hr + $workingDays12hr;

// description left column (RM + Day/Hrs)
$descRows = [
    ['Basic',   0, $totalWorkingDays > 0 ? $totalWorkingDays : '', moneyv($payroll['basic'] ?? 0)],
];

// Add 9hr overtime if exists
if (moneyv($payroll['overtime_rm'] ?? 0) > 0 || moneyv($payroll['overtime_hours'] ?? 0) > 0) {
    $descRows[] = ['9HR Over Time', moneyv($payroll['overtime_rm'] ?? 0) / (moneyv($payroll['overtime_hours'] ?? 1) ?: 1), moneyv($payroll['overtime_hours'] ?? 0), moneyv($payroll['overtime_rm'] ?? 0)];
}

// Add 12hr overtime if exists
if (moneyv($payroll['overtime_12hr_rm'] ?? 0) > 0 || moneyv($payroll['overtime_12hr_hours'] ?? 0) > 0) {
    $descRows[] = ['12HR Over Time', moneyv($payroll['overtime_12hr_rm'] ?? 0) / (moneyv($payroll['overtime_12hr_hours'] ?? 1) ?: 1), moneyv($payroll['overtime_12hr_hours'] ?? 0), moneyv($payroll['overtime_12hr_rm'] ?? 0)];
}

// Add other earnings
$descRows[] = ['Sunday', moneyv($payroll['sunday'] ?? 0) / (moneyv($payroll['sunday_days'] ?? 1) ?: 1), moneyv($payroll['sunday_days'] ?? 0), moneyv($payroll['sunday'] ?? 0)];
$descRows[] = ['Public Holiday', moneyv($payroll['public_holiday'] ?? 0) / (moneyv($payroll['ph_days'] ?? 1) ?: 1), moneyv($payroll['ph_days'] ?? 0), moneyv($payroll['public_holiday'] ?? 0)];

// Add Sunday/PH overtime if exists
if (moneyv($payroll['sunday_ph_ot'] ?? 0) > 0 || moneyv($payroll['sunday_ph_ot_hours'] ?? 0) > 0) {
    $descRows[] = ['Sunday/PH OT', moneyv($payroll['sunday_ph_ot'] ?? 0) / (moneyv($payroll['sunday_ph_ot_hours'] ?? 1) ?: 1), moneyv($payroll['sunday_ph_ot_hours'] ?? 0), moneyv($payroll['sunday_ph_ot'] ?? 0)];
}

// Add allowances only if they exist
if (moneyv($payroll['oth_claim'] ?? 0) > 0) {
    $descRows[] = ['OTH Claim', 0, 0, moneyv($payroll['oth_claim'])];
}
if (moneyv($payroll['fixed_allowance'] ?? 0) > 0) {
    $descRows[] = ['Fixed Allowance', 0, 0, moneyv($payroll['fixed_allowance'])];
}
if (moneyv($payroll['special_allowance'] ?? 0) > 0) {
    $descRows[] = ['Special Allowance', 0, 0, moneyv($payroll['special_allowance'])];
}
if (moneyv($payroll['back_pay'] ?? 0) > 0) {
    $descRows[] = ['Back Pay', 0, 0, moneyv($payroll['back_pay'])];
}
if (moneyv($payroll['night_shift_allowance'] ?? 0) > 0) {
    $descRows[] = ['Night Shift Allow.', 0, 0, moneyv($payroll['night_shift_allowance'])];
}

$earningsTotal = moneyv($payroll['basic'] ?? 0) + 
                 moneyv($payroll['overtime_rm'] ?? 0) + 
                 moneyv($payroll['overtime_12hr_rm'] ?? 0) +
                 moneyv($payroll['sunday'] ?? 0) + 
                 moneyv($payroll['public_holiday'] ?? 0) +
                 moneyv($payroll['sunday_ph_ot'] ?? 0) +
                 moneyv($payroll['oth_claim'] ?? 0) + 
                 moneyv($payroll['fixed_allowance'] ?? 0) + 
                 moneyv($payroll['special_allowance'] ?? 0) +
                 moneyv($payroll['back_pay'] ?? 0) +
                 moneyv($payroll['night_shift_allowance'] ?? 0);

// Complete deduction rows including all deduction fields
$deductionRows = [];

// Standard deductions
if (moneyv($payroll['epf_deduction'] ?? 0) > 0) {
    $deductionRows[] = ['EPF', moneyv($payroll['epf_deduction']), 1];
}
if (moneyv($payroll['socso_deduction'] ?? 0) > 0) {
    $deductionRows[] = ['SOCSO', moneyv($payroll['socso_deduction']), 1];
}
if (moneyv($payroll['sip_deduction'] ?? 0) > 0) {
    $deductionRows[] = ['SIP (EIS)', moneyv($payroll['sip_deduction']), 1];
}
if (moneyv($payroll['hostel_fee'] ?? 0) > 0) {
    $deductionRows[] = ['Hostel Fee', moneyv($payroll['hostel_fee']), 1];
}
if (moneyv($payroll['utility_charges'] ?? 0) > 0) {
    $deductionRows[] = ['Utility Charges', moneyv($payroll['utility_charges']), 1];
}
if (moneyv($payroll['other_deductions'] ?? 0) > 0) {
    $deductionRows[] = ['Other Deductions', moneyv($payroll['other_deductions']), 1];
}
if (moneyv($payroll['insurance'] ?? 0) > 0) {
    $deductionRows[] = ['Insurance', moneyv($payroll['insurance']), 1];
}

// Legacy deductions (if they exist)
if (moneyv($payroll['advance'] ?? 0) > 0) {
    $deductionRows[] = ['ADVANCE', moneyv($payroll['advance']), isset($payroll['advance_count']) ? (int)$payroll['advance_count'] : 1];
}
if (moneyv($payroll['medical'] ?? 0) > 0) {
    $deductionRows[] = ['MEDICAL', moneyv($payroll['medical']), isset($payroll['medical_count']) ? (int)$payroll['medical_count'] : 1];
}
if (moneyv($payroll['npl_days_amount'] ?? 0) > 0) {
    $deductionRows[] = ['NPL (DAYS)', moneyv($payroll['npl_days_amount']), isset($payroll['npl_days']) ? (int)$payroll['npl_days'] : 0];
}

// Calculate total deductions from all fields
$deductionsTotal = moneyv($payroll['epf_deduction'] ?? 0) + moneyv($payroll['socso_deduction'] ?? 0) + moneyv($payroll['sip_deduction'] ?? 0) +
                   moneyv($payroll['hostel_fee'] ?? 0) + moneyv($payroll['utility_charges'] ?? 0) + moneyv($payroll['other_deductions'] ?? 0) +
                   moneyv($payroll['insurance'] ?? 0) + moneyv($payroll['advance'] ?? 0) + moneyv($payroll['medical'] ?? 0) + 
                   moneyv($payroll['npl_days_amount'] ?? 0);

$netPay = ($payroll['net_pay'] ?? null) !== null
    ? moneyv($payroll['net_pay'])
    : max(0, $earningsTotal - $deductionsTotal);

/* ====== PDF ====== */
class PayslipPDF extends FPDF {
    public string $periodShort = '';
    public string $companyName = 'SFI GLOBAL(M) SDN BHD';
    public string $companyReg  = '(971184-U)';
    public string $confidential = 'CONFIDENTIAL';
    public string $logoPath = __DIR__ . '/assets/sfi_logo.png'; // optional, updated from Greenton_logo.jpg to sfi_logo.png

    function Header() {
        // top band border
        $this->SetLineWidth(0.4);

        // 1. Logo (left)
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 12, 10, 18);
        }

        // 2. Company text next to logo
        $this->SetXY(34, 10);
        $this->SetTextColor(200, 0, 0);
        $this->SetFont('Arial','B',14);
        $this->Cell(100, 7, $this->companyName, 0, 2, 'L');

        $this->SetXY(34, 17);
        $this->SetFont('Arial','',9);
        $this->SetTextColor(0,0,0);
        $this->Cell(100, 5, $this->companyReg, 0, 0, 'L');

        // 3. Center title
        $this->SetXY(10, 15);
        $this->SetFont('Arial','B',14);
        $this->Cell(190, 7, 'SALARY SLIP', 0, 2, 'C');

        $this->SetXY(10, 20);
        $this->SetFont('Arial','',9);
        $this->Cell(190, 5, $this->periodShort, 0, 0, 'C');

        // 4. Right text
        $this->SetXY(150, 10);
        $this->SetFont('Arial','B',12);
        $this->Cell(48, 12, $this->confidential, 0, 0, 'R');

        // 5. Border
        $this->Rect(10, 8, 190, 20);
        $this->Ln(22);
    }
    function Footer() {
        $this->SetY(-13);
        $this->SetFont('Arial','I',8);
        $this->Cell(0, 8, 'This is a computer-generated payslip.', 0, 0, 'C');
    }
}

$pdf = new PayslipPDF('P','mm','A4');
$pdf->SetMargins(10, 8, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->periodShort = $periodShort;
$pdf->companyName = 'SFI GLOBAL(M) SDN BHD';
$pdf->companyReg  = '(971184-U)';
$pdf->logoPath    = __DIR__ . '/assets/sfi_logo.png';
$pdf->AddPage();

/* ====== Employee info block ====== */
$pdf->SetLineWidth(0.3);
$pdf->Rect(10, 30, 190, 24);
$pdf->SetFont('Arial','',10);

// Left column
$left = [
    ['NAME', safe($payroll['name'])],
    ['PASSPORT NO', safe($payroll['passport_no'] ?? '')],
];
$right = [
    ['DEPARTMENT', safe($payroll['department'] ?? safe($payroll['position']))],
    ['YEAR OF JOIN', safe($payroll['join_year'] ?? '')],
    ['TEL', safe($payroll['phone'] ?? '')],
];

$x = 12; $y = 32;
foreach ($left as $row) {
    $pdf->SetXY($x, $y);
    $pdf->Cell(28, 6, $row[0], 0, 0, 'L');
    $pdf->Cell(4, 6, ':', 0, 0, 'C');
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(60, 6, $row[1], 0, 1, 'L');
    $pdf->SetFont('Arial','',10);
    $y += 6;
}

$x = 110; $y = 32;
foreach ($right as $row) {
    $pdf->SetXY($x, $y);
    $pdf->Cell(34, 6, $row[0], 0, 0, 'L');
    $pdf->Cell(4, 6, ':', 0, 0, 'C');
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(50, 6, $row[1], 0, 1, 'L');
    $pdf->SetFont('Arial','',10);
    $y += 6;
}

/* ====== Main table ====== */
$top = 56;
$pdf->SetLineWidth(0.4);
$pdf->Rect(10, $top, 190, 90);

// Section headers
$pdf->SetFillColor(0,0,0);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',9);

// Description (left block)
$pdf->Rect(10, $top, 95, 8, 'F');
$pdf->SetXY(12, $top+2);
$pdf->Cell(60, 4, 'Description', 0, 0, 'L');
$pdf->SetXY(68, $top+2);
$pdf->Cell(16, 4, 'RM', 0, 0, 'L');
$pdf->SetXY(86, $top+2);
$pdf->Cell(16, 4, 'Day/Hrs', 0, 0, 'L');

// Earnings (middle)
$pdf->Rect(105, $top, 47, 8, 'F');
$pdf->SetXY(105, $top+2);
$pdf->Cell(47, 4, 'Earnings', 0, 0, 'C');

// Deductions (right)
$pdf->Rect(152, $top, 48, 8, 'F');
$pdf->SetXY(152, $top+2);
$pdf->Cell(48, 4, 'Deductions', 0, 0, 'C');

// Body text
$pdf->SetTextColor(0,0,0);
$pdf->SetFont('Arial','',9);

// Row gridlines for left panel
$rowH = 10;
$rows = max(count($descRows), count($deductionRows), 7); // Ensure minimum 7 rows for clean layout

for ($i = 0; $i < $rows; $i++) {
    $y = $top + 8 + ($i * $rowH);
    // Left grid
    $pdf->Rect(10, $y, 58, $rowH);
    $pdf->Rect(68, $y, 17, $rowH);
    $pdf->Rect(85, $y, 20, $rowH);
    // Middle earnings
    $pdf->Rect(105, $y, 47, $rowH);
    // Right deductions
    $pdf->Rect(152, $y, 48, $rowH);
}

// Fill data LEFT + EARNINGS
for ($i = 0; $i < count($descRows); $i++) {
    $y = $top + 8 + ($i * $rowH);
    [$label, $rmUnit, $qty, $amount] = $descRows[$i];

    $pdf->SetXY(12, $y+3);
    $pdf->Cell(54, 4, $label, 0, 0, 'L');

    $pdf->SetXY(68, $y+3);
    $pdf->Cell(17, 4, $rmUnit > 0 ? rm($rmUnit) : '-', 0, 0, 'R');

    $pdf->SetXY(85, $y+3);
    $pdf->Cell(20, 4, $qty > 0 ? (string)$qty : '-', 0, 0, 'C');

    $pdf->SetXY(105, $y+3);
    $pdf->Cell(47, 4, $amount > 0 ? rm($amount) : '-', 0, 0, 'R');
}

// Fill data DEDUCTIONS
for ($i = 0; $i < count($deductionRows); $i++) {
    $y = $top + 8 + ($i * $rowH);
    [$label, $amt, $cnt] = $deductionRows[$i];

    // Adjust label length to fit in the cell better
    $labelDisplay = strlen($label) > 14 ? substr($label, 0, 11) . '...' : $label;

    $pdf->SetXY(154, $y+3);
    $pdf->Cell(20, 4, $labelDisplay, 0, 0, 'L');

    $pdf->SetFont('Arial','',8);
    $pdf->SetXY(180, $y+3);
    $pdf->Cell(8, 4, $cnt > 0 ? (string)$cnt : '', 0, 0, 'C');
    $pdf->SetFont('Arial','',9);

    $pdf->SetXY(152, $y+3);
    $pdf->Cell(46, 4, $amt > 0 ? rm($amt) : '-', 0, 0, 'R');
}

/* Totals strip at bottom of main box */
$yTotals = $top + 8 + ($rows * $rowH);
if ($yTotals > $top + 8) {
    $pdf->Rect(10, $yTotals, 190, 8);
    $pdf->Rect(10, $yTotals, 95, 8);
    $pdf->Rect(105, $yTotals, 47, 8);
    $pdf->Rect(152, $yTotals, 48, 8);

    $pdf->SetFont('Arial','B',9);
    $pdf->SetXY(107, $yTotals+2);
    $pdf->Cell(43, 4, rm($earningsTotal), 0, 0, 'R');

    $pdf->SetXY(152, $yTotals+2);
    $pdf->Cell(46, 4, rm($deductionsTotal), 0, 0, 'R');
}

/* ====== Gross Paid + Net Pay Band ====== */
$yBand = $yTotals + 10;
$pdf->SetLineWidth(0.4);

// Left: Gross Paid
$pdf->Rect(10, $yBand, 120, 10);
$pdf->SetFont('Arial','B',10);
$pdf->SetXY(12, $yBand+2);
$pdf->Cell(50, 6, 'Gross Paid', 0, 0, 'L');
$pdf->SetXY(110, $yBand+2);
$pdf->Cell(20, 6, rm($earningsTotal), 0, 0, 'R');

// Right: Net Pay
$pdf->Rect(130, $yBand, 70, 10);
$pdf->SetXY(132, $yBand+2);
$pdf->Cell(40, 6, 'NET PAY RM', 0, 0, 'L');
$pdf->SetXY(130, $yBand+2);
$pdf->Cell(68, 6, rm($netPay), 0, 0, 'R');

/* ====== Payment footer block ====== */
$yFoot = $yBand + 12;
$pdf->Rect(10, $yFoot, 120, 22); // left block
$pdf->Rect(130, $yFoot, 70, 22); // right block

$pdf->SetFont('Arial','',9);
$footRows = [
    ['Payment Date', safe($payroll['payment_date'] ? date('d-M-y', strtotime($payroll['payment_date'])) : '')],
    ['Name', safe($payroll['name'])],
    ['Acc No', safe($payroll['account_no'] ?? '')],
    ['Total RM', rm($earningsTotal)],
];
$y = $yFoot + 2;
foreach ($footRows as $r) {
    $pdf->SetXY(12, $y);
    $pdf->Cell(28, 5, $r[0], 0, 0, 'L');
    $pdf->Cell(4, 5, ':', 0, 0, 'C');
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(70, 5, $r[1], 0, 1, 'L');
    $pdf->SetFont('Arial','',9);
    $y += 5.2;
}

// Currency tag (right)
$pdf->SetFont('Arial','B',10);
$pdf->SetXY(132, $yFoot + 16);
$pdf->Cell(20, 5, $CURRENCY, 0, 0, 'L');
$pdf->SetXY(130, $yFoot + 16);
$pdf->Cell(68, 5, rm($netPay), 0, 0, 'R');

/* ====== NEW: Signatures Section (3 columns) ====== */
$ySign = $yFoot + 24 + 6;          // margin below payment block
$signHeight = 32;                  // height of signature area
$signX = 10; $signW = 190;

// Outer box
$pdf->Rect($signX, $ySign, $signW, $signHeight);

// Column widths (3 equal columns)
$colW = round($signW / 3, 2);      // ~63.33mm each
$x1 = $signX + $colW;
$x2 = $signX + 2*$colW;

// Vertical separators
$pdf->Line($x1, $ySign, $x1, $ySign + $signHeight);
$pdf->Line($x2, $ySign, $x2, $ySign + $signHeight);

// Headings
$pdf->SetFont('Arial','B',9);
$pdf->SetXY($signX, $ySign + 3);
$pdf->Cell($colW, 5, 'Employee Signature', 0, 0, 'C');

$pdf->SetXY($x1, $ySign + 3);
$pdf->Cell($colW, 5, 'HR / Admin', 0, 0, 'C');

$pdf->SetXY($x2, $ySign + 3);
$pdf->Cell($colW, 5, 'Authorized Signatory', 0, 0, 'C');

// Signature lines (about 18mm from top of box)
$lineY = $ySign + 20;
$pdf->Line($signX + 10, $lineY, $signX + $colW - 10, $lineY);
$pdf->Line($x1 + 10,     $lineY, $x1 + $colW - 10,    $lineY);
$pdf->Line($x2 + 10,     $lineY, $x2 + $colW - 10,    $lineY);

// "Name / Date" labels under lines
$pdf->SetFont('Arial','',8);
$pdf->SetXY($signX, $lineY + 2);
$pdf->Cell($colW, 4, '', 0, 0, 'C');

$pdf->SetXY($x1, $lineY + 2);
$pdf->Cell($colW, 4, '', 0, 0, 'C');

$pdf->SetXY($x2, $lineY + 2);
$pdf->Cell($colW, 4, '', 0, 0, 'C');

/* ====== Output ====== */
$fname = sprintf('Payslip_%s_%s.pdf', preg_replace('/\s+/', '', safe($payroll['name'])), $periodShort);
$pdf->Output('I', $fname);
