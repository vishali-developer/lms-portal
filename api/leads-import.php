<?php
// api/leads-import.php — Bulk import leads from a CSV file
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

$uid = $_SESSION['user_id'];

$allowedTypes = ['Inbound Leads', 'Outbound Leads'];
$statuses     = ['Untouched','New','Contacted','Follow-up','Interested','Converted','Closed','Rejected'];
$priorities   = ['Low','Medium','High'];

$templateColumns = ['First Name','Last Name','Phone','Email','Company','Location',
                     'Service','Lead Type','Lead Source','Status','Priority','Assigned To','Message'];

/* ── Downloadable blank template ───────────────────────────────── */
if (($_GET['template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $templateColumns);
    fputcsv($out, ['Jane','Doe','9876543210','jane@example.com','Acme Co','Mumbai, India',
                   'Search Engine Optimization','Inbound Leads','Website','New','Medium','','Looking for an SEO audit']);
    fclose($out);
    exit;
}

$returnTo = $_POST['return_to'] ?? (APP_URL . '/admin/leads.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($returnTo);
}

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    setFlash('danger', 'CSRF token error.');
    redirect($returnTo);
}

// Set when imported from the Inbound or Outbound list page; blank on the all-leads page,
// in which case each row's own "Lead Type" column (if present) is used instead.
$typeOverride = $_POST['lead_type_override'] ?? '';
if ($typeOverride && !in_array($typeOverride, $allowedTypes, true)) {
    $typeOverride = '';
}

if (empty($_FILES['import_file']['tmp_name']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    setFlash('danger', 'Please choose a CSV file to import.');
    redirect($returnTo);
}

$ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    setFlash('danger', 'Only CSV files are supported. In Excel, use File → Save As → CSV, then upload that file.');
    redirect($returnTo);
}

$handle = fopen($_FILES['import_file']['tmp_name'], 'r');
if (!$handle) {
    setFlash('danger', 'Could not read the uploaded file.');
    redirect($returnTo);
}

// Read the header row (stripping a UTF-8 BOM if Excel added one)
$firstLine = fgets($handle);
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine ?: '');
$header    = array_map('trim', str_getcsv($firstLine));

$colIndex = [];
foreach ($header as $i => $h) {
    $colIndex[strtolower($h)] = $i;
}

function importCol(array $row, array $colIndex, string $name, string $default = ''): string {
    $key = strtolower($name);
    return isset($colIndex[$key], $row[$colIndex[$key]]) ? trim((string)$row[$colIndex[$key]]) : $default;
}

// Lookups for matching free-text Source / Assigned To values to existing rows
$employees = DB::fetchAll("SELECT id,name FROM users WHERE role='employee'");
$empByName = [];
foreach ($employees as $emp) { $empByName[strtolower($emp['name'])] = $emp['id']; }

$sources = DB::fetchAll("SELECT id,name FROM lead_sources");
$srcByName = [];
foreach ($sources as $src) { $srcByName[strtolower($src['name'])] = $src['id']; }

$imported = 0;
$skipped  = [];
$rowNum   = 1; // header row is row 1

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;

    if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
        continue; // skip blank lines
    }

    $firstName = importCol($row, $colIndex, 'First Name');
    $lastName  = importCol($row, $colIndex, 'Last Name');
    $phone     = importCol($row, $colIndex, 'Phone');
    $email     = importCol($row, $colIndex, 'Email');

    if (!$firstName || !$lastName || !$phone) {
        $skipped[] = "Row $rowNum: First Name, Last Name and Phone are required.";
        continue;
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped[] = "Row $rowNum: invalid email '$email'.";
        continue;
    }

    $company  = importCol($row, $colIndex, 'Company');
    $location = importCol($row, $colIndex, 'Location');
    $service  = importCol($row, $colIndex, 'Service');
    $message  = importCol($row, $colIndex, 'Message');

    $leadType = $typeOverride ?: importCol($row, $colIndex, 'Lead Type');
    if (!in_array($leadType, $allowedTypes, true)) {
        $leadType = '';
    }

    $statusVal = importCol($row, $colIndex, 'Status', 'New');
    if (!in_array($statusVal, $statuses, true)) {
        $statusVal = 'New';
    }

    $priorityVal = importCol($row, $colIndex, 'Priority', 'Medium');
    if (!in_array($priorityVal, $priorities, true)) {
        $priorityVal = 'Medium';
    }

    // Resolve (or create) the lead source by name
    $sourceId   = null;
    $sourceName = importCol($row, $colIndex, 'Lead Source');
    if ($sourceName) {
        $key = strtolower($sourceName);
        if (isset($srcByName[$key])) {
            $sourceId = $srcByName[$key];
        } else {
            DB::query("INSERT INTO lead_sources (name,status) VALUES (?, 'active')", [$sourceName]);
            $sourceId = DB::lastInsertId();
            $srcByName[$key] = $sourceId;
        }
    }
   
    // Resolve assigned employee by name (must match an existing employee)
    $assignedTo   = null;
    $assignedName = importCol($row, $colIndex, 'Assigned To');
    if ($assignedName && isset($empByName[strtolower($assignedName)])) {
        $assignedTo = $empByName[strtolower($assignedName)];
    }

    $fullName = trim("$firstName $lastName");

    DB::query(
        "INSERT INTO leads (first_name,last_name,name,phone,email,company,location,
         service,lead_type,message,source_id,status,priority,assigned_to,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$firstName,$lastName,$fullName,$phone,$email,$company,$location,
         $service,$leadType,$message,$sourceId,$statusVal,$priorityVal,$assignedTo,$uid]
    );
    $imported++;
}
fclose($handle);

logActivity($uid, 'Import Leads', "$imported lead(s) imported" . ($skipped ? ', ' . count($skipped) . ' row(s) skipped' : ''));

if ($imported > 0) {
    $msg = "$imported lead(s) imported successfully.";
    if ($skipped) {
        $msg .= ' ' . count($skipped) . ' row(s) skipped: ' . implode(' | ', array_slice($skipped, 0, 5));
        if (count($skipped) > 5) $msg .= ' …';
    }
    setFlash($skipped ? 'warning' : 'success', $msg);
} else {
    $msg = 'No leads were imported.';
    if ($skipped) $msg .= ' ' . implode(' | ', array_slice($skipped, 0, 5));
    setFlash('danger', $msg);
}

redirect($returnTo);