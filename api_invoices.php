<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

// Security: Only allow logged-in users to access this API
if (empty($_SESSION['user'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

require_once 'db_conn.php';
$pdo = getPDO();

$action = $_GET['action'] ?? '';
$response = [];

try {
    switch ($action) {
        // Action to get a list of unique companies
        case 'companies':
            $search = $_GET['search'] ?? '';
            $sql = "SELECT DISTINCT client_name FROM invoices WHERE client_name IS NOT NULL AND client_name != ''";
            $params = [];
            if ($search) {
                $sql .= " AND client_name LIKE ?";
                $params[] = '%' . $search . '%';
            }
            $sql .= " ORDER BY client_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;

        // Action to get departments for a specific company
        case 'departments':
            $company = $_GET['company'] ?? '';
            if (empty($company)) throw new Exception("Company name is required.");
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT department_name FROM invoices 
                WHERE client_name = ? AND department_name IS NOT NULL AND department_name != '' 
                ORDER BY department_name ASC
            ");
            $stmt->execute([$company]);
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;

        // Action to get invoices for a specific company and department
        case 'invoices':
            $company = $_GET['company'] ?? '';
            $department = $_GET['department'] ?? '';
            if (empty($company) || empty($department)) throw new Exception("Company and department names are required.");
            
            $stmt = $pdo->prepare("
                SELECT id, invoice_no, grand_total, invoice_date 
                FROM invoices 
                WHERE client_name = ? AND department_name = ? 
                ORDER BY invoice_date DESC
            ");
            $stmt->execute([$company, $department]);
            $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        default:
            http_response_code(400); // Bad Request
            $response = ['error' => 'Invalid action specified.'];
            break;
    }
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    $response = ['error' => $e->getMessage()];
}

echo json_encode($response);