<?php
/**
 * FILE: index.php
 * PURPOSE: Main application controller, router, business logic engine, and view renderer.
 * SYSTEM ROLE:
 *   1. Seeds default RBAC user accounts (Administrator, Health Officer, Encoder).
 *   2. Enforces Role-Based Access Control (RBAC) across pages and actions.
 *   3. Handles all POST form submissions (Cases, Bulk Excel/CSV Imports, Barangays,
 *      Alerts, Recommendations, Reports, Users, Settings).
 *   4. Synchronizes case counts with Barangay GIS Hotspot risk levels in real time.
 *   5. Renders the active page view requested via ?page=...
 */

require_once 'config.php';
require_login();

// Helper: Fetch a single scalar value from a prepared SQL query
function scalar(string $sql, array $args = []) {
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s->fetchColumn();
}

// Helper: Fetch all rows as an associative array from a prepared SQL query
function rows(string $sql, array $args = []): array {
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}

// ========================================
// AUTO-SEED DEFAULT ROLE ACCOUNTS
// ========================================
// Ensures the 3 standard user roles exist in the database on first launch.
(function () {
    $defaultAccounts = [
        ['System Administrator', 'admin@disease.local',   'Administrator',  'Admin@2026'],
        ['City Health Officer',  'officer@disease.local', 'Health Officer', 'Officer@2026'],
        ['Surveillance Encoder', 'encoder@disease.local', 'Encoder',        'Encoder@2026'],
    ];
    $check = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $ins   = db()->prepare('INSERT INTO users(name, email, role, password_hash, active) VALUES(?,?,?,?,1)');
    foreach ($defaultAccounts as [$uName, $uEmail, $uRole, $uPass]) {
        $check->execute([$uEmail]);
        if (!$check->fetchColumn()) {
            $ins->execute([$uName, $uEmail, $uRole, password_hash($uPass, PASSWORD_DEFAULT)]);
        }
    }
})();

// ========================================
// EPIDEMIOLOGICAL RISK ENGINE
// ========================================
/**
 * Classifies risk severity based on case volume thresholds.
 */
function getCaseRiskLevel(int $caseCount): string {
    return match (true) {
        $caseCount < 5  => 'Low',
        $caseCount < 15 => 'Medium',
        $caseCount < 30 => 'High',
        default         => 'Very High',
    };
}

/**
 * Recalculates a barangay's overall GIS map risk level whenever its cases change.
 * Considers only 'Active' cases so resolved outbreaks lower the hotspot alert level.
 */
function syncBarangayRisk(int $barangayId): string {
    $activeCases = (int)scalar("SELECT COALESCE(SUM(case_count), 0) FROM cases WHERE barangay_id=? AND status='Active'", [$barangayId]);
    $riskLevel   = getCaseRiskLevel($activeCases);

    $highestActiveRisk = scalar(
        "SELECT risk_level FROM cases WHERE barangay_id=? AND status='Active' ORDER BY FIELD(risk_level,'Very High','High','Medium','Low') LIMIT 1",
        [$barangayId]
    );

    $rank = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];
    if ($highestActiveRisk && ($rank[$highestActiveRisk] ?? 0) > ($rank[$riskLevel] ?? 0)) {
        $riskLevel = $highestActiveRisk;
    }

    db()->prepare('UPDATE barangays SET risk_level=? WHERE id=?')->execute([$riskLevel, $barangayId]);
    return $riskLevel;
}

// ========================================
// FILE UPLOAD & SPREADSHEET PARSERS
// ========================================
/**
 * Validates and stores uploaded PDF official memos for Alerts and Recommendations.
 * Enforces strict MIME type checking and a 10 MB size cap.
 */
function handleMemoUpload(string $fieldName = 'memo_file'): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error uploading memo file.');
    }
    if ($_FILES[$fieldName]['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Memo PDF must be smaller than 10 MB.');
    }

    $origName = $_FILES[$fieldName]['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($_FILES[$fieldName]['tmp_name']);

    if ($ext !== 'pdf' || $mime !== 'application/pdf') {
        throw new RuntimeException('Invalid file format. Only PDF (.pdf) files are allowed for memos.');
    }

    $uploadDir = __DIR__ . '/uploads/memos';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $uploadDir . '/' . $safeName)) {
        throw new RuntimeException('Failed to save uploaded PDF memo.');
    }
    return 'uploads/memos/' . $safeName;
}

/**
 * Parses uploaded .csv or native .xlsx spreadsheets into a 2D array of rows.
 * Reads .xlsx internals (sharedStrings.xml + sheet1.xml) using native PHP ZipArchive & SimpleXML.
 */
function parseSpreadsheetRows(string $fullPath, string $ext): array {
    $parsedRows = [];
    if ($ext === 'csv') {
        $handle = fopen($fullPath, 'r');
        if (!$handle) {
            throw new RuntimeException('Unable to read the CSV file.');
        }
        // Auto-detect comma vs. semicolon delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = ($firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $parsedRows[] = $row;
        }
        fclose($handle);
    } elseif ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is not enabled in XAMPP. Please save your Excel file as CSV (.csv) or enable extension=zip in php.ini.');
        }
        $zip = new ZipArchive();
        if ($zip->open($fullPath) !== true) {
            throw new RuntimeException('Unable to open the Excel (.xlsx) file.');
        }

        // Extract shared text strings lookup table
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $xml = simplexml_load_string($ssXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Parse cell coordinates (e.g., A1, C4) and map values from worksheet 1
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('Could not find worksheet data inside the Excel file.');
        }

        $sheet = simplexml_load_string($sheetXml);
        foreach ($sheet->sheetData->row as $rowNode) {
            $rowArr = [];
            foreach ($rowNode->c as $cell) {
                $ref  = (string)$cell['r'];
                $colLetters = preg_replace('/[0-9]/', '', $ref);
                $colIdx = 0;
                for ($i = 0; $i < strlen($colLetters); $i++) {
                    $colIdx = $colIdx * 26 + (ord($colLetters[$i]) - 64);
                }
                $colIdx = max(0, $colIdx - 1);

                $type = (string)$cell['t'];
                $val  = isset($cell->v) ? (string)$cell->v : '';
                if ($type === 's' && isset($sharedStrings[(int)$val])) {
                    $val = $sharedStrings[(int)$val];
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $val = (string)$cell->is->t;
                }
                $rowArr[$colIdx] = $val;
            }
            if (!empty($rowArr)) {
                $maxKey = max(array_keys($rowArr));
                $fullRow = [];
                for ($k = 0; $k <= $maxKey; $k++) {
                    $fullRow[] = $rowArr[$k] ?? '';
                }
                $parsedRows[] = $fullRow;
            }
        }
    }
    return $parsedRows;
}

/**
 * Converts a 0-based column index into an Excel column header letter (0 -> A, 26 -> AA).
 */
function excelColLetter(int $index): string {
    $letter = '';
    while ($index >= 0) {
        $letter = chr($index % 26 + 65) . $letter;
        $index = intdiv($index, 26) - 1;
    }
    return $letter;
}

// ========================================
// ROUTING & ROLE-BASED ACCESS CONTROL (RBAC)
// ========================================
$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','cases','hotspots','analytics','reports','view_import','barangays','alerts','recommendations','users','settings'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';

$userRole = $_SESSION['role'] ?? 'Encoder';
$isFullAccess = in_array($userRole, ['Administrator', 'Health Officer'], true);
$encoderAllowedPages = ['dashboard', 'cases', 'recommendations', 'settings'];

// Block Encoders from accessing restricted administrative/analytical pages
if (!$isFullAccess && !in_array($page, $encoderAllowedPages, true)) {
    flash('Access denied: Encoders can only access Dashboard, Disease Cases, Recommendations, and Settings.', 'danger');
    header('Location: index.php?page=dashboard');
    exit;
}

// ========================================
// POST REQUEST HANDLERS (CRUD & ACTIONS)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        // --- CREATE OR UPDATE DISEASE CASE ---
        if ($action === 'save_case') {
            $id        = (int)($_POST['id'] ?? 0);
            $disease   = trim($_POST['disease'] ?? '');
            $caseYear  = trim($_POST['case_year'] ?? date('Y'));
            $code      = trim($_POST['patient_code'] ?? '');
            if ($code === '' && $disease !== '') {
                $code = $disease . ' - ' . $caseYear;
            }
            $brgyId    = (int)($_POST['barangay_id'] ?? 0);
            $date      = $_POST['report_date'] ?? date('Y-m-d');
            $caseCount = (int)($_POST['case_count'] ?? 0);
            $riskLevel = getCaseRiskLevel($caseCount);
            $status    = $_POST['status'] ?? 'Active';
            $reportId  = ($_POST['report_id'] ?? '') !== '' ? (int)$_POST['report_id'] : null;

            if (!$code || !$disease || $brgyId < 1 || $caseCount < 1) {
                throw new RuntimeException('Please complete all required fields (Disease, Barangay, and Number of cases >= 1).');
            }

            $oldBrgyId = 0;
            if ($id) {
                $existing = rows('SELECT barangay_id, created_at, report_date FROM cases WHERE id=?', [$id])[0] ?? null;
                if (!$existing) {
                    throw new RuntimeException('Case record not found.');
                }
                // Tamper protection: Lock records older than 1 month from modification
                $refDate = !empty($existing['created_at']) ? $existing['created_at'] : $existing['report_date'];
                if (strtotime($refDate) < strtotime('-1 month')) {
                    throw new RuntimeException('Data manipulation protection: Records older than 1 month can no longer be edited.');
                }

                $oldBrgyId = (int)$existing['barangay_id'];
                try {
                    $q = db()->prepare('UPDATE cases SET patient_code=?, disease=?, barangay_id=?, report_id=?, report_date=?, case_count=?, risk_level=?, status=? WHERE id=?');
                    $q->execute([$code, $disease, $brgyId, $reportId, $date, $caseCount, $riskLevel, $status, $id]);
                } catch (PDOException $e) {
                    $q = db()->prepare('UPDATE cases SET patient_code=?, disease=?, barangay_id=?, report_date=?, case_count=?, risk_level=?, status=? WHERE id=?');
                    $q->execute([$code, $disease, $brgyId, $date, $caseCount, $riskLevel, $status, $id]);
                }
            } else {
                try {
                    $q = db()->prepare('INSERT INTO cases(patient_code, disease, barangay_id, report_id, report_date, case_count, risk_level, status, created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                    $q->execute([$code, $disease, $brgyId, $reportId, $date, $caseCount, $riskLevel, $status, $_SESSION['user_id']]);
                } catch (PDOException $e) {
                    $q = db()->prepare('INSERT INTO cases(patient_code, disease, barangay_id, report_date, case_count, risk_level, status, created_by) VALUES(?,?,?,?,?,?,?,?)');
                    $q->execute([$code, $disease, $brgyId, $date, $caseCount, $riskLevel, $status, $_SESSION['user_id']]);
                }
            }

            // Re-sync GIS Hotspot risk levels for the affected barangay(s)
            $updatedMapRisk = syncBarangayRisk($brgyId);
            if ($oldBrgyId > 0 && $oldBrgyId !== $brgyId) {
                syncBarangayRisk($oldBrgyId);
            }

            flash('Disease case saved (Case Risk: ' . strtoupper($riskLevel) . ' | Hotspot Map updated to: ' . strtoupper($updatedMapRisk) . ').');
            $page = 'cases';

        // --- DELETE DISEASE CASE ---
        } elseif ($action === 'delete_case') {
            $id = (int)($_POST['id'] ?? 0);
            $existing = rows('SELECT barangay_id, created_at, report_date FROM cases WHERE id=?', [$id])[0] ?? null;
            if (!$existing) {
                throw new RuntimeException('Case record not found.');
            }
            $refDate = !empty($existing['created_at']) ? $existing['created_at'] : $existing['report_date'];
            if (strtotime($refDate) < strtotime('-1 month')) {
                throw new RuntimeException('Data manipulation protection: Records older than 1 month can no longer be deleted.');
            }

            $brgyId = (int)$existing['barangay_id'];
            db()->prepare('DELETE FROM cases WHERE id=?')->execute([$id]);
            if ($brgyId > 0) {
                syncBarangayRisk($brgyId);
            }
            flash('Disease case record deleted.');
            $page = 'cases';

        // --- BULK IMPORT HISTORICAL EXCEL / CSV DATA ---
        } elseif ($action === 'import_historical_data') {
            if (!isset($_FILES['data_file']) || $_FILES['data_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please select a valid Excel (.xlsx) or CSV (.csv) file to upload.');
            }

            $origName = $_FILES['data_file']['name'];
            $tmpPath  = $_FILES['data_file']['tmp_name'];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('Unsupported file format. Please upload an Excel (.xlsx) or CSV (.csv) file.');
            }

            $uploadDir = __DIR__ . '/uploads/imports';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName  = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $savedPath = 'uploads/imports/' . $safeName;
            $fullPath  = __DIR__ . '/' . $savedPath;

            if (!move_uploaded_file($tmpPath, $fullPath)) {
                throw new RuntimeException('Failed to save the uploaded spreadsheet.');
            }

            $parsedRows = parseSpreadsheetRows($fullPath, $ext);

            // Create an archive entry in the `reports` table for this spreadsheet
            db()->prepare('INSERT INTO reports(title, report_type, date_from, date_to, disease, barangay_id, file_path, generated_by) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([
                    $origName,
                    'Custom',
                    date('Y-m-d'),
                    date('Y-m-d'),
                    null,
                    null,
                    $savedPath,
                    $_SESSION['user_id']
                ]);
            $reportId = (int)db()->lastInsertId();

            // Build flexible lookup map for matching Barangay names (with or without "(Bgy. X)")
            $brgyMap = [];
            foreach (rows('SELECT id, name FROM barangays') as $b) {
                $full  = strtolower(trim($b['name']));
                $short = trim(preg_replace('/\s*\(.*?\)\s*/', '', $full));
                $brgyMap[$full] = (int)$b['id'];
                $brgyMap[$short] = (int)$b['id'];
                $brgyMap[str_replace(' ', '', $short)] = (int)$b['id'];
            }

            $insertStmt = db()->prepare(
                'INSERT INTO cases(patient_code, disease, barangay_id, report_id, report_date, case_count, risk_level, status, notes, created_by)
                 VALUES(?,?,?,?,?,?,?,?,?,?)'
            );

            $imported = 0;
            $skipped  = 0;
            $skipReasons = [];
            $affectedBrgys = [];
            $minDate = null;
            $maxDate = null;

            foreach ($parsedRows as $idx => $row) {
                // Skip header row if detected
                if ($idx === 0 && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
                    $h0 = strtolower(trim($row[0]));
                    if (str_contains($h0, 'case') || str_contains($h0, 'patient') || str_contains($h0, 'code') || str_contains($h0, 'disease name')) {
                        continue;
                    }
                }
                if (count($row) < 4 || trim(implode('', array_map('strval', $row))) === '') {
                    continue;
                }

                $code      = trim((string)($row[0] ?? ''));
                $disease   = trim((string)($row[1] ?? ''));
                $brgyRaw   = trim((string)($row[2] ?? ''));
                $brgyName  = strtolower($brgyRaw);
                $brgyClean = trim(preg_replace('/\s*\(.*?\)\s*/', '', $brgyName));
                $dateRaw   = trim((string)($row[3] ?? ''));
                $caseCount = max(1, (int)($row[4] ?? 1));
                $statusRaw = ucfirst(strtolower(trim((string)($row[5] ?? 'Active'))));
                $notes     = trim((string)($row[6] ?? ''));

                // Convert Excel numeric serial dates or text dates into YYYY-MM-DD
                if (is_numeric($dateRaw) && (float)$dateRaw > 25000) {
                    $unix = ((int)$dateRaw - 25569) * 86400;
                    $date = gmdate('Y-m-d', $unix);
                } elseif (preg_match('/^\d{4}$/', $dateRaw)) {
                    $date = $dateRaw . '-01-01';
                } else {
                    $ts   = strtotime($dateRaw);
                    $date = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
                }

                $brgyId = $brgyMap[$brgyName]
                    ?? $brgyMap[$brgyClean]
                    ?? $brgyMap[str_replace(' ', '', $brgyClean)]
                    ?? 0;

                $status = in_array($statusRaw, ['Active', 'Recovered', 'Deceased'], true) ? $statusRaw : 'Active';

                if (!$code || !$disease || $brgyId < 1) {
                    $skipped++;
                    $rowNum = $idx + 1;
                    if ($brgyId < 1) {
                        $skipReasons[] = "Row {$rowNum}: Unknown barangay '{$brgyRaw}'";
                    } else {
                        $skipReasons[] = "Row {$rowNum}: Missing Case Code or Disease";
                    }
                    continue;
                }

                if ($minDate === null || $date < $minDate) $minDate = $date;
                if ($maxDate === null || $date > $maxDate) $maxDate = $date;

                $riskLevel = getCaseRiskLevel($caseCount);
                $insertStmt->execute([
                    $code,
                    $disease,
                    $brgyId,
                    $reportId,
                    $date,
                    $caseCount,
                    $riskLevel,
                    $status,
                    $notes,
                    $_SESSION['user_id']
                ]);

                $affectedBrgys[$brgyId] = true;
                $imported++;
            }

            // Update GIS risk pins for all barangays touched by the import
            foreach (array_keys($affectedBrgys) as $bId) {
                syncBarangayRisk((int)$bId);
            }

            // Update report date span to reflect min/max dates found in the spreadsheet
            if ($minDate && $maxDate) {
                db()->prepare('UPDATE reports SET date_from=?, date_to=? WHERE id=?')
                    ->execute([$minDate, $maxDate, $reportId]);
            }

            $msg = "Imported {$imported} record(s) from '{$origName}' — added to Case Register, archived in Reports, and synced with Hotspot Map.";
            if ($skipped > 0) {
                $detail = implode('; ', array_slice($skipReasons, 0, 3));
                $msg .= " Skipped {$skipped} row(s) ({$detail}).";
            }
            flash($msg, $imported > 0 ? 'success' : 'warning');
            $page = 'cases';

        // --- DELETE REPORT OR IMPORTED FILE ---
        } elseif ($action === 'delete_report') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            $reportId = (int)($_POST['id'] ?? 0);
            $rep = rows('SELECT * FROM reports WHERE id=?', [$reportId])[0] ?? null;
            if (!$rep) {
                throw new RuntimeException('Report record not found.');
            }

            if (!empty($rep['file_path'])) {
                $fullPath = __DIR__ . '/' . $rep['file_path'];
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
                $affectedBrgys = array_column(
                    rows('SELECT DISTINCT barangay_id FROM cases WHERE report_id=?', [$reportId]),
                    'barangay_id'
                );
                db()->prepare('DELETE FROM cases WHERE report_id=?')->execute([$reportId]);
                foreach ($affectedBrgys as $bId) {
                    syncBarangayRisk((int)$bId);
                }
                flash('Imported file and its linked Disease Cases deleted. Hotspot Map updated.');
            } else {
                db()->prepare('UPDATE cases SET report_id=NULL WHERE report_id=?')->execute([$reportId]);
                flash('Generated report summary deleted.');
            }

            db()->prepare('DELETE FROM reports WHERE id=?')->execute([$reportId]);
            $page = 'reports';

        // --- SAVE OR UPDATE BARANGAY METADATA & GPS ---
} elseif ($action === 'save_barangay') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            $id         = (int)($_POST['id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $population = (int)($_POST['population'] ?? 0);
            $latRaw     = $_POST['latitude']  ?? '';
            $lngRaw     = $_POST['longitude'] ?? '';
            $lat = ($latRaw === '') ? null : (float)$latRaw;
            $lng = ($lngRaw === '') ? null : (float)$lngRaw;

            if (!$name) throw new RuntimeException('Barangay name is required.');
            if (($lat === null) xor ($lng === null)) throw new RuntimeException('Please provide both latitude and longitude, or leave both blank.');
            if ($lat !== null && ($lat < -90 || $lat > 90))  throw new RuntimeException('Latitude must be between -90 and 90.');
            if ($lng !== null && ($lng < -180 || $lng > 180)) throw new RuntimeException('Longitude must be between -180 and 180.');

            if ($id) {
                db()->prepare('UPDATE barangays SET name=?, population=?, latitude=?, longitude=? WHERE id=?')
                    ->execute([$name, $population, $lat, $lng, $id]);
                syncBarangayRisk($id);
            } else {
                db()->prepare('INSERT INTO barangays(name, population, risk_level, latitude, longitude) VALUES(?,?,?,?,?)')
                    ->execute([$name, $population, 'Low', $lat, $lng]);
            }
            flash('Barangay saved.');
            $page = 'barangays';

        // --- DELETE BARANGAY ---
        } elseif ($action === 'delete_barangay') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            $count = db()->prepare('SELECT COUNT(*) FROM cases WHERE barangay_id=?');
            $count->execute([(int)$_POST['id']]);
            if ($count->fetchColumn()) throw new RuntimeException('This barangay has case records and cannot be deleted.');
            db()->prepare('DELETE FROM barangays WHERE id=?')->execute([(int)$_POST['id']]);
            flash('Barangay deleted.');
            $page = 'barangays';

        // --- CREATE & UPDATE ALERTS ---
        } elseif ($action === 'save_alert') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            $title   = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $prio    = $_POST['priority'] ?? 'Medium';
            $brgyId  = ($_POST['barangay_id'] ?? '') !== '' ? (int)$_POST['barangay_id'] : null;
            $status  = $_POST['status'] ?? 'Open';

            if (!$title || !$message) {
                throw new RuntimeException('Title and message are required.');
            }

            $attachmentPath = handleMemoUpload('memo_file');

            db()->prepare('INSERT INTO alerts(title, message, attachment, priority, barangay_id, status) VALUES(?,?,?,?,?,?)')
                ->execute([$title, $message, $attachmentPath, $prio, $brgyId, $status]);

            flash('Alert created' . ($attachmentPath ? ' with memo attachment.' : '.'));
            $page = 'alerts';

        } elseif ($action === 'update_alert') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            db()->prepare('UPDATE alerts SET status=? WHERE id=?')->execute([$_POST['status'] ?? 'Open', (int)$_POST['id']]);
            flash('Alert status updated.');
            $page = 'alerts';

        // --- CREATE & UPDATE RECOMMENDATIONS ---
        } elseif ($action === 'save_recommendation') {
            $title   = trim($_POST['title'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            $brgyId  = ($_POST['barangay_id'] ?? '') !== '' ? (int)$_POST['barangay_id'] : null;
            $status  = $_POST['status'] ?? 'Pending';
            $dueDate = ($_POST['due_date'] ?? '') ?: null;

            if (!$title || !$desc) {
                throw new RuntimeException('Title and description are required.');
            }

            $attachmentPath = handleMemoUpload('memo_file');

            db()->prepare('INSERT INTO recommendations(title, description, attachment, barangay_id, status, due_date) VALUES(?,?,?,?,?,?)')
                ->execute([$title, $desc, $attachmentPath, $brgyId, $status, $dueDate]);

            flash('Recommendation created' . ($attachmentPath ? ' with memo attachment.' : '.'));
            $page = 'recommendations';

        } elseif ($action === 'update_recommendation') {
            db()->prepare('UPDATE recommendations SET status=? WHERE id=?')->execute([$_POST['status'] ?? 'Pending', (int)$_POST['id']]);
            flash('Recommendation status updated.');
            $page = 'recommendations';

        // --- GENERATE CUSTOM SURVEILLANCE REPORT ---
        } elseif ($action === 'create_report') {
            if (!$isFullAccess) throw new RuntimeException('Access denied.');
            $from    = $_POST['date_from'] ?? date('Y-m-01');
            $to      = $_POST['date_to'] ?? date('Y-m-d');
            $disease = trim($_POST['disease'] ?? '');
            $brgyId  = ($_POST['barangay_id'] ?? '') !== '' ? (int)$_POST['barangay_id'] : null;

            if ($from > $to) throw new RuntimeException('Start date must be before end date.');

            $d = [
                trim($_POST['title'] ?? 'Disease monitoring report'),
                $_POST['report_type'] ?? 'Custom',
                $from,
                $to,
                $disease !== '' ? $disease : null,
                $brgyId,
                $_SESSION['user_id']
            ];
            db()->prepare('INSERT INTO reports(title,report_type,date_from,date_to,disease,barangay_id,generated_by) VALUES(?,?,?,?,?,?,?)')->execute($d);

            $countSql  = 'SELECT COUNT(*) FROM cases WHERE report_date BETWEEN ? AND ?';
            $countArgs = [$from, $to];
            if ($disease !== '') {
                $countSql  .= ' AND disease LIKE ?';
                $countArgs[] = '%' . $disease . '%';
            }
            if ($brgyId !== null) {
                $countSql  .= ' AND barangay_id = ?';
                $countArgs[] = $brgyId;
            }
            $matched = (int)scalar($countSql, $countArgs);

            flash("Report generated with {$matched} connected case entries. Click View to inspect in Spreadsheet Net View.");
            $page = 'reports';

        // --- CREATE OR UPDATE STAFF USER ACCOUNT ---
        } elseif ($action === 'save_user') {
            if (!$isFullAccess) throw new RuntimeException('Only Administrators and Health Officers can manage users.');
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'Encoder';
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email.');
            if ($id) {
                if (!empty($_POST['password'])) {
                    db()->prepare('UPDATE users SET name=?,email=?,role=?,password_hash=? WHERE id=?')->execute([$name, $email, $role, password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                } else {
                    db()->prepare('UPDATE users SET name=?,email=?,role=? WHERE id=?')->execute([$name, $email, $role, $id]);
                }
            } else {
                if (strlen($_POST['password'] ?? '') < 8) throw new RuntimeException('New user password must be at least 8 characters.');
                db()->prepare('INSERT INTO users(name,email,role,password_hash,active) VALUES(?,?,?,?,1)')->execute([$name, $email, $role, password_hash($_POST['password'], PASSWORD_DEFAULT)]);
            }
            flash('User saved.');
            $page = 'users';

        // --- SAVE SYSTEM SETTINGS ---
        } elseif ($action === 'save_settings') {
            $city = trim($_POST['city_name'] ?? '');
            $sys = trim($_POST['system_name'] ?? 'Disease Monitoring');
            if (!$city) throw new RuntimeException('City/health unit name is required.');
            $q = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $q->execute(['city_name', $city]);
            $q->execute(['system_name', $sys]);
            flash('Settings updated.');
            $page = 'settings';
        }
    } catch (Throwable $ex) {
        flash($ex instanceof PDOException ? 'Database error: ' . $ex->getMessage() : $ex->getMessage(), 'danger');
    }
    // Post/Redirect/Get (PRG) pattern to prevent duplicate form submissions on refresh
    header('Location: index.php?page=' . $page);
    exit;
}

// Load persistent layout header (Sidebar + Topbar)
require 'includes/header.php';

// ========================================
// UI PRESENTATION HELPERS
// ========================================
/**
 * Renders color-coded status and risk badges with Bootstrap icons.
 */
function status_badge(string $s): string {
    $c = match ($s) {
        'Active','Open','High','Very High','Deceased' => 'danger',
        'Recovered','Resolved','Completed','Low' => 'success',
        'In Progress','Medium' => 'warning',
        default => 'secondary'
    };
    $ic = match ($s) {
        'Active' => 'bi-activity',
        'Open' => 'bi-exclamation-circle',
        'High' => 'bi-arrow-up-circle',
        'Very High' => 'bi-exclamation-triangle-fill',
        'Deceased' => 'bi-x-circle',
        'Recovered' => 'bi-heart-pulse',
        'Resolved' => 'bi-check-circle',
        'Completed' => 'bi-check2-circle',
        'Low' => 'bi-shield-check',
        'In Progress' => 'bi-hourglass-split',
        'Medium' => 'bi-dash-circle',
        default => 'bi-circle'
    };
    return '<span class="status-badge ' . $c . '"><i class="bi ' . $ic . '"></i>' . e($s) . '</span>';
}

/**
 * Renders standardized page titles and subtitles.
 */
function page_heading(string $title, string $sub, string $icon = 'bi-grid') {
    echo '<div class="page-heading interactive-heading"><div class="heading-icon"><i class="bi ' . e($icon) . '"></i></div><div><div class="section-kicker"><i class="bi bi-shield-check"></i> HEALTH SURVEILLANCE</div><h1>' . e($title) . '</h1><p>' . e($sub) . '</p></div></div>';
}

/**
 * Renders a fallback empty-state row when a database table query returns 0 records.
 */
function table_empty(int $span): void {
    echo '<tr><td colspan="' . $span . '" class="empty-state"><i class="bi bi-inbox"></i><br>No records found.</td></tr>';
}

$barangays = rows('SELECT id, name FROM barangays ORDER BY name ASC');
?>

<style>
/* Micro-interactions, card hover elevations, and Excel Net Grid styling */
.interactive-heading { display: flex; align-items: center; gap: 16px; animation: fadeSlideIn .5s ease; }
.interactive-heading .heading-icon {
  width: 56px; height: 56px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #2563eb, #8b5cf6); color: #fff; font-size: 1.6rem;
  box-shadow: 0 10px 24px rgba(37,99,235,.35); animation: iconFloat 3s ease-in-out infinite;
}
@keyframes iconFloat { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-4px) rotate(-3deg); } }
@keyframes fadeSlideIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.stat-card { position: relative; overflow: hidden; transition: transform .3s ease, box-shadow .3s ease; }
.stat-card::after {
  content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px;
  background: linear-gradient(90deg, #2563eb, #8b5cf6, #ec4899);
  transform: scaleX(0); transform-origin: left; transition: transform .4s ease;
}
.stat-card:hover { transform: translateY(-6px); box-shadow: 0 16px 32px rgba(15,23,42,.12); }
.stat-card:hover::after { transform: scaleX(1); }
.stat-icon { transition: transform .35s ease, box-shadow .35s ease; }
.stat-card:hover .stat-icon { transform: scale(1.12) rotate(-6deg); box-shadow: 0 6px 16px rgba(0,0,0,.15); }
.panel { transition: box-shadow .3s ease, border-color .3s ease, transform .3s ease; }
.panel:hover { box-shadow: 0 12px 30px rgba(15,23,42,.08); border-color: rgba(37,99,235,.15); }
.btn { transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(37,99,235,.18); }
.btn i { transition: transform .25s ease; }
.btn:hover i.bi-pencil { transform: rotate(-8deg) scale(1.1); }
.btn:hover i.bi-trash { transform: rotate(8deg) scale(1.1); }
.btn:hover i.bi-check2-circle { transform: scale(1.15); }
.btn:hover i.bi-download { transform: translateY(2px); }
.data-table tbody tr { transition: background .2s ease, transform .2s ease; }
.data-table tbody tr:hover { background: rgba(37,99,235,.04); transform: translateX(2px); }
.risk-item { transition: transform .25s ease, background .25s ease; border-radius: 10px; }
.risk-item:hover { transform: translateX(4px); background: rgba(37,99,235,.05); }
.risk-pin { transition: transform .3s ease; }
.risk-item:hover .risk-pin { transform: scale(1.15) rotate(-8deg); }
.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: .75rem; font-weight: 600; transition: transform .2s ease; }
.status-badge i { font-size: .8rem; }
.status-badge:hover { transform: translateY(-1px) scale(1.05); }
.form-control:focus, .form-select:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.15); transition: box-shadow .25s ease, border-color .25s ease; }
.form-label { font-weight: 600; color: #334155; transition: color .2s ease; }
.form-label:hover { color: #2563eb; }
.text-link { transition: transform .25s ease, color .25s ease; display: inline-flex; align-items: center; gap: 4px; }
.text-link:hover { transform: translateX(3px); color: #2563eb; }
.chart-wrap, .donut-wrap { transition: filter .3s ease; }
.panel:hover .chart-wrap, .panel:hover .donut-wrap { filter: brightness(1.02); }
.security-list > div { transition: transform .25s ease, background .25s ease; border-radius: 10px; }
.security-list > div:hover { transform: translateX(4px); background: rgba(37,99,235,.05); }
.security-list i { transition: transform .3s ease, color .3s ease; }
.security-list > div:hover i { transform: scale(1.15) rotate(-6deg); color: #2563eb; }

/* Excel-style Net Spreadsheet Viewer */
.excel-sheet-wrap { overflow: auto; max-height: 600px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
.excel-table { border-collapse: collapse; width: 100%; font-family: 'Calibri', 'Segoe UI', Arial, sans-serif; font-size: 13px; }
.excel-table th, .excel-table td { border: 1px solid #cbd5e1; padding: 6px 10px; white-space: nowrap; }
.excel-table thead th.excel-col-letter {
  background: #f1f5f9; color: #475569; font-weight: 700; text-align: center;
  position: sticky; top: 0; z-index: 3; border-bottom: 2px solid #94a3b8; font-size: 11px;
}
.excel-table thead tr.excel-header-row th {
  background: #1e3a8a; color: #ffffff; font-weight: 600;
  position: sticky; top: 27px; z-index: 2;
}
.excel-table td.excel-row-num, .excel-table th.excel-corner {
  background: #f1f5f9; color: #475569; font-weight: 600; text-align: center;
  width: 42px; min-width: 42px; position: sticky; left: 0; z-index: 2; border-right: 2px solid #94a3b8;
}
.excel-table th.excel-corner { z-index: 4; top: 0; }
.excel-table tbody tr:nth-child(even) td:not(.excel-row-num) { background: #f8fafc; }
.excel-table tbody tr:hover td:not(.excel-row-num) { background: #eff6ff; }
</style>

<!-- ======================================================================= -->
<!-- VIEW 1: DASHBOARD (KPIs, Trend Line, Disease Donut, Recent Cases)       -->
<!-- ======================================================================= -->
<?php if ($page === 'dashboard'):
    page_heading('Welcome back, ' . ($_SESSION['name'] ?? 'User'), 'Here is your disease surveillance overview for ' . (db()->query("SELECT setting_value FROM settings WHERE setting_key='city_name'")->fetchColumn() ?: 'your health unit') . '.', 'bi-grid-1x2-fill');
    $total     = (int)scalar('SELECT COALESCE(SUM(case_count), 0) FROM cases');
    $active    = (int)scalar("SELECT COALESCE(SUM(case_count), 0) FROM cases WHERE status='Active'");
    $recovered = (int)scalar("SELECT COALESCE(SUM(case_count), 0) FROM cases WHERE status='Recovered'");
    $high      = (int)scalar("SELECT COUNT(*) FROM barangays WHERE risk_level IN ('High','Very High')");
?>
 <div class="stats-grid">
   <div class="stat-card">
     <div class="stat-top"><span><i class="bi bi-clipboard2-pulse-fill me-1"></i> Total reported cases</span><span class="stat-icon blue"><i class="bi bi-clipboard2-pulse"></i></span></div>
     <div class="stat-value" data-count="<?= $total ?>"><?= number_format($total) ?></div>
     <div class="stat-foot"><span class="dot blue-dot"></span> All registered cases</div>
   </div>
   <div class="stat-card">
     <div class="stat-top"><span><i class="bi bi-activity me-1"></i> Active cases</span><span class="stat-icon orange"><i class="bi bi-activity"></i></span></div>
     <div class="stat-value" data-count="<?= $active ?>"><?= number_format($active) ?></div>
     <div class="stat-foot"><span class="dot orange-dot"></span> Require follow-up</div>
   </div>
   <div class="stat-card">
     <div class="stat-top"><span><i class="bi bi-geo-alt-fill me-1"></i> High-risk barangays</span><span class="stat-icon red"><i class="bi bi-geo-alt"></i></span></div>
     <div class="stat-value" data-count="<?= $high ?>"><?= number_format($high) ?></div>
     <div class="stat-foot"><span class="dot red-dot"></span> High or very high risk</div>
   </div>
   <div class="stat-card">
     <div class="stat-top"><span><i class="bi bi-heart-pulse-fill me-1"></i> Recovered cases</span><span class="stat-icon green"><i class="bi bi-heart-pulse"></i></span></div>
     <div class="stat-value" data-count="<?= $recovered ?>"><?= number_format($recovered) ?></div>
     <div class="stat-foot"><span class="dot green-dot"></span> Marked recovered</div>
   </div>
 </div>
 <div class="dashboard-grid">
   <div class="panel chart-panel">
     <div class="panel-head"><div><h3><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Cases over time</h3><p>Monthly reported cases</p></div><?php if ($isFullAccess): ?><a href="index.php?page=analytics" class="text-link">View analytics <i class="bi bi-arrow-up-right"></i></a><?php endif; ?></div>
     <div class="chart-wrap"><canvas id="casesChart"></canvas></div>
   </div>
   <div class="panel chart-panel">
     <div class="panel-head"><div><h3><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Disease distribution</h3><p>Recorded cases by disease</p></div></div>
     <div class="donut-wrap"><canvas id="diseaseChart"></canvas></div>
     <div class="mini-legend" id="diseaseLegend"></div>
   </div>
 </div>
 <div class="dashboard-grid lower-grid">
   <div class="panel">
     <div class="panel-head"><div><h3><i class="bi bi-clock-history me-2 text-primary"></i>Recent disease cases</h3><p>Latest case reports in the system</p></div><a href="index.php?page=cases" class="text-link">View all <i class="bi bi-arrow-right"></i></a></div>
     <div class="table-responsive"><table class="table data-table"><thead><tr><th>Case code</th><th>Date reported</th><th>Barangay</th><th>Disease</th><th>Cases</th><th>Status</th></tr></thead><tbody><?php $recent = rows('SELECT c.*,b.name barangay FROM cases c JOIN barangays b ON b.id=c.barangay_id ORDER BY report_date DESC,id DESC LIMIT 7'); if (!$recent) table_empty(6); foreach ($recent as $r): ?><tr><td><b><?= e($r['patient_code']) ?></b></td><td><?= e(date('M j, Y', strtotime($r['report_date']))) ?></td><td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($r['barangay']) ?></td><td><?= e($r['disease']) ?></td><td><b><?= (int)$r['case_count'] ?></b></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
   </div>
   <div class="panel">
     <div class="panel-head"><div><h3><i class="bi bi-shield-exclamation me-2 text-primary"></i>Top risk barangays</h3><p>By risk classification</p></div><?php if ($isFullAccess): ?><a href="index.php?page=hotspots" class="text-link">View map</a><?php endif; ?></div>
     <div class="risk-list"><?php $risk = rows("SELECT b.*, COALESCE(SUM(c.case_count), 0) case_count FROM barangays b LEFT JOIN cases c ON c.barangay_id=b.id GROUP BY b.id ORDER BY FIELD(b.risk_level,'Very High','High','Medium','Low'),case_count DESC LIMIT 6"); foreach ($risk as $r): ?><div class="risk-item"><div class="risk-pin <?= strtolower(str_replace(' ', '-', $r['risk_level'])) ?>"><i class="bi bi-geo-alt-fill"></i></div><div class="risk-name"><b><?= e($r['name']) ?></b><small><?= number_format($r['population']) ?> residents</small></div><div class="risk-end"><?= status_badge($r['risk_level']) ?><small><?= (int)$r['case_count'] ?> cases</small></div></div><?php endforeach; ?></div>
   </div>
 </div>
 <!-- Inject aggregated PHP datasets into window.dashboardData for assets/app.js Chart.js rendering -->
 <script>window.dashboardData={months:<?= json_encode(array_column(rows("SELECT DATE_FORMAT(report_date,'%b %Y') label, COALESCE(SUM(case_count), 0) value FROM cases WHERE report_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH) GROUP BY YEAR(report_date),MONTH(report_date) ORDER BY YEAR(report_date),MONTH(report_date)"), 'label')) ?>,values:<?= json_encode(array_map('intval', array_column(rows("SELECT DATE_FORMAT(report_date,'%b %Y') label, COALESCE(SUM(case_count), 0) value FROM cases WHERE report_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH) GROUP BY YEAR(report_date),MONTH(report_date) ORDER BY YEAR(report_date),MONTH(report_date)"), 'value'))) ?>,diseases:<?= json_encode(array_column(rows('SELECT disease, COALESCE(SUM(case_count), 0) total FROM cases GROUP BY disease ORDER BY total DESC LIMIT 6'), 'disease')) ?>,diseaseValues:<?= json_encode(array_map('intval', array_column(rows('SELECT disease, COALESCE(SUM(case_count), 0) total FROM cases GROUP BY disease ORDER BY total DESC LIMIT 6'), 'total'))) ?>};</script>

<!-- ======================================================================= -->
<!-- VIEW 2: DISEASE CASES (CRUD, Search/Filter, Bulk Excel/CSV Import)      -->
<!-- ======================================================================= -->
<?php elseif ($page === 'cases'):
    page_heading('Disease cases', 'Register, search, bulk-import, and update reported disease cases — automatically synced with Reports & Hotspot Map.', 'bi-clipboard2-pulse-fill');
    $search   = trim($_GET['q'] ?? '');
    $filter   = $_GET['status'] ?? '';
    $reportId = (int)($_GET['report_id'] ?? 0);

    $sql  = 'SELECT c.*, b.name barangay FROM cases c JOIN barangays b ON b.id=c.barangay_id WHERE (c.patient_code LIKE ? OR c.disease LIKE ? OR b.name LIKE ?)';
    $args = ["%$search%", "%$search%", "%$search%"];
    if (in_array($filter, ['Active', 'Recovered', 'Deceased'], true)) {
        $sql .= ' AND c.status=?';
        $args[] = $filter;
    }
    if ($reportId > 0) {
        $repFilter = rows('SELECT * FROM reports WHERE id=?', [$reportId])[0] ?? null;
        if ($repFilter && !empty($repFilter['file_path'])) {
            $sql .= ' AND c.report_id=?';
            $args[] = $reportId;
        } elseif ($repFilter) {
            $sql .= ' AND c.report_date BETWEEN ? AND ?';
            $args[] = $repFilter['date_from'];
            $args[] = $repFilter['date_to'];
            if (!empty($repFilter['disease'])) {
                $sql .= ' AND c.disease LIKE ?';
                $args[] = '%' . $repFilter['disease'] . '%';
            }
            if (!empty($repFilter['barangay_id'])) {
                $sql .= ' AND c.barangay_id=?';
                $args[] = (int)$repFilter['barangay_id'];
            }
        }
    }
    $sql .= ' ORDER BY c.report_date DESC, c.id DESC';
    $cases = rows($sql, $args);

    $edit = isset($_GET['edit']) ? rows('SELECT * FROM cases WHERE id=?', [(int)$_GET['edit']])[0] ?? null : null;
    if ($edit) {
        $editRefDate = !empty($edit['created_at']) ? $edit['created_at'] : $edit['report_date'];
        if (strtotime($editRefDate) < strtotime('-1 month')) {
            $edit = null;
        }
    }

    $selectedYear = date('Y');
    if ($edit && preg_match('/\b(20\d{2})\b/', (string)$edit['patient_code'], $m)) {
        $selectedYear = $m[1];
    } elseif ($edit && !empty($edit['report_date'])) {
        $selectedYear = date('Y', strtotime($edit['report_date']));
    }

    $currentCount = $edit ? (int)$edit['case_count'] : '';
    $detectedCaseRisk = ($edit && !empty($edit['barangay_id']) && (int)$currentCount >= 1)
        ? strtoupper(getCaseRiskLevel((int)$currentCount))
        : '';

    $reportsList = rows('SELECT id, title FROM reports ORDER BY created_at DESC');
    $oneMonthAgo = strtotime('-1 month');
?>
 <div class="panel mb-4">
   <div class="panel-head">
     <div>
       <h3><i class="bi <?= $edit ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2 text-primary"></i><?= $edit ? 'Edit case' : 'Add disease case' ?></h3>
       <p>Changes automatically update the Hotspot Map risk pins and connected Report totals. <b>Records lock after 1 month.</b></p>
     </div>
   </div>
   <form method="post" class="row g-3 form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
     <input type="hidden" name="action" value="save_case">
     <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-calendar3 me-1"></i>Case Year &amp; Code *</label>
       <div class="input-group">
         <select class="form-select" name="case_year" id="caseYearSelect" style="max-width: 105px; font-weight: 700;" required>
           <?php for ($y = (int)date('Y') + 5; $y >= 2018; $y--): ?>
             <option value="<?= $y ?>" <?= (string)$selectedYear === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
           <?php endfor; ?>
         </select>
         <input class="form-control" name="patient_code" id="caseCodePreview" readonly style="background:#f8fafc; font-weight:600;" value="<?= e($edit['patient_code'] ?? '') ?>" placeholder="Auto: Disease - Year">
       </div>
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-virus me-1"></i>Disease *</label>
       <input class="form-control" name="disease" id="diseaseInput" required value="<?= e($edit['disease'] ?? '') ?>" placeholder="e.g. Dengue">
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Barangay *</label>
       <select class="form-select" name="barangay_id" id="barangaySelect" required>
         <option value="">Select barangay</option>
         <?php foreach ($barangays as $b): ?>
           <option value="<?= (int)$b['id'] ?>" <?= ($edit['barangay_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
         <?php endforeach; ?>
       </select>
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-link-45deg me-1"></i>Linked Report / File</label>
       <select class="form-select" name="report_id">
         <option value="">None (Standalone Entry)</option>
         <?php foreach ($reportsList as $rep): ?>
           <option value="<?= (int)$rep['id'] ?>" <?= ($edit['report_id'] ?? '') == $rep['id'] ? 'selected' : '' ?>><?= e($rep['title']) ?></option>
         <?php endforeach; ?>
       </select>
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-calendar-event me-1"></i>Date reported *</label>
       <input class="form-control" type="date" name="report_date" required value="<?= e($edit['report_date'] ?? date('Y-m-d')) ?>">
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-123 me-1"></i>Number of cases *</label>
       <input class="form-control" type="number" min="1" name="case_count" id="caseCountInput" required value="<?= e($currentCount) ?>" placeholder="Enter number of cases">
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-shield-exclamation me-1"></i>Risk level (Auto)</label>
       <input class="form-control fw-bold" type="text" id="caseRiskDisplay" value="<?= e($detectedCaseRisk) ?>" placeholder="Select barangay & cases" readonly style="background:#f8fafc;">
     </div>

     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-activity me-1"></i>Status *</label>
       <select class="form-select" name="status">
         <?php foreach (['Active','Recovered','Deceased'] as $s): ?>
           <option <?= ($edit['status'] ?? 'Active') === $s ? 'selected' : '' ?>><?= $s ?></option>
         <?php endforeach; ?>
       </select>
     </div>

     <div class="col-12 d-flex gap-2">
       <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $edit ? 'Update case' : 'Save case' ?></button>
       <?php if ($edit): ?><a class="btn btn-light" href="index.php?page=cases"><i class="bi bi-x-circle me-1"></i>Cancel edit</a><?php endif; ?>
     </div>
   </form>
 </div>

 <div class="panel">
   <div class="panel-head flex-wrap gap-2">
     <div>
       <h3><i class="bi bi-table me-2 text-primary"></i>Case register</h3>
       <p><?= count($cases) ?> matching records · <span class="text-muted"><i class="bi bi-lock-fill"></i> Records older than 1 month are locked from editing or deletion</span></p>
     </div>
     <div class="d-flex flex-wrap gap-2 align-items-center">
       <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importModal">
         <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel / CSV
       </button>
       <form class="d-flex flex-wrap gap-2" method="get">
         <input type="hidden" name="page" value="cases">
         <?php if ($reportId > 0): ?>
           <a href="index.php?page=cases" class="btn btn-light"><i class="bi bi-x-circle me-1"></i>Clear Report Filter</a>
         <?php endif; ?>
         <input class="form-control" name="q" placeholder="Search cases..." value="<?= e($search) ?>" style="max-width: 190px;">
         <select class="form-select" name="status" style="max-width:150px">
           <option value="">All statuses</option>
           <?php foreach (['Active','Recovered','Deceased'] as $s): ?>
             <option <?= $filter === $s ? 'selected' : '' ?>><?= $s ?></option>
           <?php endforeach; ?>
         </select>
         <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
       </form>
     </div>
   </div>
   <div class="table-responsive">
     <table class="table data-table">
       <thead>
         <tr>
           <th>Case code</th>
           <th>Date</th>
           <th>Barangay</th>
           <th>Disease</th>
           <th>Cases</th>
           <th>Risk level</th>
           <th>Status</th>
           <th>Actions</th>
         </tr>
       </thead>
       <tbody>
         <?php if (!$cases) table_empty(8); foreach ($cases as $r):
           $refTimestamp = strtotime(!empty($r['created_at']) ? $r['created_at'] : $r['report_date']);
           $isLocked     = ($refTimestamp < $oneMonthAgo);
         ?>
           <tr>
             <td><b><?= e($r['patient_code']) ?></b></td>
             <td><?= e($r['report_date']) ?></td>
             <td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($r['barangay']) ?></td>
             <td><?= e($r['disease']) ?></td>
             <td><b><?= (int)$r['case_count'] ?></b></td>
             <td><?= status_badge($r['risk_level']) ?></td>
             <td><?= status_badge($r['status']) ?></td>
             <td>
               <?php if ($isLocked): ?>
                 <span class="badge bg-light text-secondary border" title="Locked after 1 month to prevent data manipulation">
                   <i class="bi bi-lock-fill me-1"></i>Locked
                 </span>
               <?php else: ?>
                 <a class="btn btn-sm btn-light" href="index.php?page=cases&edit=<?= $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                 <form method="post" class="d-inline" onsubmit="return confirm('Delete this case record?')">
                   <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                   <input type="hidden" name="action" value="delete_case">
                   <input type="hidden" name="id" value="<?= $r['id'] ?>">
                   <button class="btn btn-sm btn-light text-danger" title="Delete"><i class="bi bi-trash"></i></button>
                 </form>
               <?php endif; ?>
             </td>
           </tr>
         <?php endforeach; ?>
       </tbody>
     </table>
   </div>
 </div>

 <!-- Modal for bulk historical spreadsheet upload & CSV template generator -->
 <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg modal-dialog-centered">
     <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 45px rgba(15,23,42,.2);">
       <div class="modal-header border-bottom">
         <h5 class="modal-title fw-bold" id="importModalLabel">
           <i class="bi bi-file-earmark-spreadsheet-fill text-primary me-2"></i>Import Historical Data (Excel / CSV)
         </h5>
         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
       </div>
       <form method="post" enctype="multipart/form-data">
         <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
         <input type="hidden" name="action" value="import_historical_data">
         <div class="modal-body p-4">
           <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
             <div>
               <strong><i class="bi bi-info-circle-fill me-1"></i>Expected Columns (in order):</strong><br>
               <small><code>Case Code</code>, <code>Disease</code>, <code>Barangay</code>, <code>Date Reported (YYYY-MM-DD)</code>, <code>Number of Cases</code>, <code>Status</code>, <code>Notes</code></small>
             </div>
             <button type="button" class="btn btn-sm btn-outline-primary download-template-btn">
               <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Sample Template (.csv)
             </button>
           </div>
           <div class="mb-3">
             <label class="form-label fw-bold">Select Excel (.xlsx) or CSV (.csv) file *</label>
             <input class="form-control" type="file" name="data_file" accept=".xlsx,.csv" required>
             <div class="form-text">All valid rows will be added to the Case Register, archived in Reports, and automatically reflected on the Hotspot Map.</div>
           </div>
         </div>
         <div class="modal-footer border-top">
           <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
           <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import Historical Data</button>
         </div>
       </form>
     </div>
   </div>
 </div>

<!-- Client-side live preview for Case Code, Risk Level calculation, and Sample CSV download -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var yearSelect     = document.getElementById('caseYearSelect');
    var diseaseInput   = document.getElementById('diseaseInput');
    var codePreview    = document.getElementById('caseCodePreview');
    var barangaySelect = document.getElementById('barangaySelect');
    var countInput     = document.getElementById('caseCountInput');
    var riskDisplay    = document.getElementById('caseRiskDisplay');

    function updateCaseCode() {
        var disease = diseaseInput.value.trim();
        var year    = yearSelect.value;
        codePreview.value = disease ? (disease + ' - ' + year) : '';
    }

    function updateCaseRisk() {
        var hasBarangay = barangaySelect.value !== '';
        var rawCount    = countInput.value.trim();
        var count       = parseInt(rawCount, 10);

        if (!hasBarangay || rawCount === '' || isNaN(count) || count < 1) {
            riskDisplay.value = '';
            riskDisplay.style.color = '#64748b';
            return;
        }

        var level = 'LOW';
        var color = '#16a34a';

        if (count < 5) {
            level = 'LOW';
            color = '#16a34a';
        } else if (count < 15) {
            level = 'MEDIUM';
            color = '#d97706';
        } else if (count < 30) {
            level = 'HIGH';
            color = '#dc2626';
        } else {
            level = 'VERY HIGH';
            color = '#b91c1c';
        }

        riskDisplay.value = level;
        riskDisplay.style.color = color;
    }

    diseaseInput.addEventListener('input', updateCaseCode);
    yearSelect.addEventListener('change', updateCaseCode);
    barangaySelect.addEventListener('change', updateCaseRisk);
    countInput.addEventListener('input', updateCaseRisk);

    updateCaseCode();
    updateCaseRisk();

    document.querySelectorAll('.download-template-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var csvContent = "\uFEFF" +
                "Case Code,Disease,Barangay,Date Reported,Number of Cases,Status,Notes\n" +
                "Dengue - 2021,Dengue,Agao (Bgy. 3),2021-08-15,12,Recovered,Old record 2021\n" +
                "TB - 2022,Tuberculosis,Ampayon,2022-03-10,4,Active,Old record 2022\n";
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'disease_cases_import_template.csv';
            link.click();
        });
    });
});
</script>

<!-- ======================================================================= -->
<!-- VIEW 3: HOTSPOT MAP (Interactive Leaflet GIS Spatial Surveillance)      -->
<!-- ======================================================================= -->
<?php elseif ($page === 'hotspots'):
    page_heading('Barangay GIS hotspot map', 'Interactive spatial surveillance of disease cases, incidence rates, and risk zones.', 'bi-geo-alt-fill');

    $diseaseOptions = array_column(rows("
        SELECT DISTINCT disease FROM (
            SELECT TRIM(disease) AS disease FROM cases WHERE disease IS NOT NULL AND TRIM(disease) != ''
            UNION
            SELECT TRIM(disease) AS disease FROM reports WHERE disease IS NOT NULL AND TRIM(disease) != ''
        ) d ORDER BY disease ASC
    "), 'disease');

    $selectedDisease = trim($_GET['disease'] ?? '');

    foreach (rows('SELECT id FROM barangays') as $bRow) {
        syncBarangayRisk((int)$bRow['id']);
    }

    // Calculate barangay risk either for a specific selected disease or across all diseases combined
    if ($selectedDisease !== '') {
        $risk = rows("
            SELECT 
                b.id, b.name, b.population, b.latitude, b.longitude, b.notes,
                COALESCE(SUM(c.case_count), 0) AS case_count,
                COALESCE(SUM(CASE WHEN c.status = 'Active' THEN c.case_count ELSE 0 END), 0) AS active_cases,
                ? AS top_disease,
                (SELECT c2.risk_level FROM cases c2 WHERE c2.barangay_id = b.id AND c2.disease = ? AND c2.status = 'Active' ORDER BY FIELD(c2.risk_level,'Very High','High','Medium','Low') LIMIT 1) AS max_case_risk
            FROM barangays b
            LEFT JOIN cases c ON c.barangay_id = b.id AND c.disease = ?
            GROUP BY b.id
        ", [$selectedDisease, $selectedDisease, $selectedDisease]);

        $rank = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];
        foreach ($risk as &$r) {
            $calcRisk = getCaseRiskLevel((int)$r['active_cases']);
            $maxRisk  = $r['max_case_risk'] ?? 'Low';
            $r['risk_level'] = (($rank[$maxRisk] ?? 0) > ($rank[$calcRisk] ?? 0)) ? $maxRisk : $calcRisk;
            if ((int)$r['case_count'] === 0) {
                $r['top_disease'] = 'No ' . $selectedDisease . ' cases';
            }
        }
        unset($r);

        usort($risk, function ($a, $b) use ($rank) {
            $cmp = ($rank[$b['risk_level']] ?? 0) <=> ($rank[$a['risk_level']] ?? 0);
            return $cmp !== 0 ? $cmp : ((int)$b['active_cases'] <=> (int)$a['active_cases']);
        });
    } else {
        $risk = rows("
            SELECT 
                b.id, b.name, b.population, b.risk_level, b.latitude, b.longitude, b.notes,
                COALESCE(SUM(c.case_count), 0) AS case_count,
                COALESCE(SUM(CASE WHEN c.status = 'Active' THEN c.case_count ELSE 0 END), 0) AS active_cases,
                (SELECT c2.disease FROM cases c2 WHERE c2.barangay_id = b.id GROUP BY c2.disease ORDER BY SUM(c2.case_count) DESC LIMIT 1) AS top_disease
            FROM barangays b
            LEFT JOIN cases c ON c.barangay_id = b.id
            GROUP BY b.id
            ORDER BY FIELD(b.risk_level,'Very High','High','Medium','Low'), active_cases DESC, case_count DESC
        ");
    }

    // Prepare JSON coordinates and incidence rate (cases per 1,000 population) for Leaflet markers
    $mapData        = [];
    $unmapped       = [];
    $highRiskCount  = 0;
    $medRiskCount   = 0;
    $lowRiskMapped  = 0;

    foreach ($risk as $r) {
        if (in_array($r['risk_level'], ['High', 'Very High'], true)) {
            $highRiskCount++;
        } elseif ($r['risk_level'] === 'Medium') {
            $medRiskCount++;
        }

        if ($r['latitude'] === null || $r['longitude'] === null) {
            $unmapped[] = $r['name'];
            continue;
        }

        if ($r['risk_level'] === 'Low') {
            $lowRiskMapped++;
        }

        $pop = (int)$r['population'];
        $cases = (int)$r['case_count'];
        $incidence = $pop > 0 ? round(($cases / $pop) * 1000, 2) : 0;
        $mapData[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'lat'         => (float)$r['latitude'],
            'lng'         => (float)$r['longitude'],
            'risk'        => $r['risk_level'],
            'population'  => $pop,
            'cases'       => $cases,
            'active'      => (int)$r['active_cases'],
            'incidence'   => $incidence,
            'top_disease' => $r['top_disease'] ?: 'None recorded',
            'notes'       => $r['notes'] ?: ''
        ];
    }
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
.gis-layout { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 18px; }
@media (max-width: 992px) { .gis-layout { grid-template-columns: 1fr; } }
.gis-sidebar-list { max-height: 490px; overflow-y: auto; padding: 0 16px 16px; }
.gis-item { padding: 11px 12px; border: 1px solid #edf1f7; border-radius: 10px; margin-bottom: 9px; cursor: pointer; transition: all .2s ease; }
.gis-item:hover { border-color: #3168e0; background: #f8faff; transform: translateX(3px); }
.gis-item.unmapped { opacity: .65; cursor: default; background: #fafbfd; }
.gis-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.gis-filter-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>

<div class="stats-grid three-stats">
  <div class="stat-card">
    <div class="stat-top"><span><i class="bi bi-exclamation-triangle-fill me-1"></i> High / Very High risk</span><span class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></span></div>
    <div class="stat-value"><?= $highRiskCount ?></div>
    <div class="stat-foot"><?= $selectedDisease !== '' ? 'For ' . e($selectedDisease) : 'Priority intervention zones' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><span><i class="bi bi-shield-exclamation me-1"></i> Medium risk</span><span class="stat-icon orange"><i class="bi bi-shield-exclamation"></i></span></div>
    <div class="stat-value"><?= $medRiskCount ?></div>
    <div class="stat-foot"><?= $selectedDisease !== '' ? 'For ' . e($selectedDisease) : 'Enhanced surveillance' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><span><i class="bi bi-geo-alt-fill me-1"></i> Mapped barangays (Safe/Low)</span><span class="stat-icon green"><i class="bi bi-pin-map"></i></span></div>  
    <div class="stat-value"><?= $lowRiskMapped ?></div>
    <div class="stat-foot">Excludes <?= $highRiskCount + $medRiskCount ?> Medium/High risk · <?= count($unmapped) ?> unmapped</div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head" style="padding: 14px 22px;">
    <div>
      <h3><i class="bi bi-virus me-2 text-primary"></i>Custom Disease Display</h3>
      <p>Filter spatial risk zones by a specific disease from Disease Cases &amp; Reports.</p>
    </div>
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="page" value="hotspots">
      <select name="disease" class="form-select form-select-sm" style="min-width: 210px;" onchange="this.form.submit()">
        <option value="">All Diseases (Combined Surveillance)</option>
        <?php foreach ($diseaseOptions as $dName): ?>
          <option value="<?= e($dName) ?>" <?= $selectedDisease === $dName ? 'selected' : '' ?>><?= e($dName) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($selectedDisease !== ''): ?>
        <a href="index.php?page=hotspots" class="btn btn-sm btn-light"><i class="bi bi-x-circle me-1"></i>Reset</a>
        <a href="index.php?page=cases&q=<?= urlencode($selectedDisease) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard2-pulse me-1"></i>View Cases</a>
        <a href="index.php?page=reports&disease=<?= urlencode($selectedDisease) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-bar-graph me-1"></i>Generate Report</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="gis-layout">
  <div class="panel map-panel">
    <div class="panel-head">
      <div>
        <h3><i class="bi bi-map-fill me-2 text-primary"></i>Spatial Risk &amp; Case Density Map <?= $selectedDisease !== '' ? '(' . e($selectedDisease) . ')' : '' ?></h3>
        <p>Click any barangay zone to inspect epidemiological metrics.</p>
      </div>
      <div class="gis-toolbar">
        <div class="btn-group btn-group-sm" role="group" aria-label="Risk filter">
          <button type="button" class="btn btn-outline-secondary gis-filter-btn active" data-risk="ALL">All</button>
          <button type="button" class="btn btn-outline-secondary gis-filter-btn" data-risk="HIGH">High</button>
          <button type="button" class="btn btn-outline-secondary gis-filter-btn" data-risk="Medium">Medium</button>
          <button type="button" class="btn btn-outline-secondary gis-filter-btn" data-risk="Low">Low</button>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" id="fitMarkersBtn"><i class="bi bi-arrows-fullscreen me-1"></i>Fit View</button>
      </div>
    </div>

    <div style="padding: 0 20px;">
      <div id="hotspotMap" style="height: 500px; width: 100%; border-radius: 12px; border: 1px solid #e2e8f0;"></div>
    </div>

    <div class="map-legend mt-3">
      <span><i class="legend-dot high"></i> High / Very High Risk</span>
      <span><i class="legend-dot medium"></i> Medium Risk</span>
      <span><i class="legend-dot low"></i> Low Risk</span>
      <span class="ms-auto text-muted"><i class="bi bi-layers me-1"></i>Use top-right map icon to switch Satellite / Street view</span>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <div>
        <h3><i class="bi bi-list-check me-2 text-primary"></i>Barangay Directory (<?= count($risk) ?>)</h3>
        <p>Click a mapped barangay to fly to location</p>
      </div>
      <a class="btn btn-sm btn-light" href="index.php?page=barangays" title="Edit coordinates"><i class="bi bi-pencil"></i></a>
    </div>
    <div class="gis-sidebar-list">
      <?php foreach ($risk as $r): 
        $hasCoords = ($r['latitude'] !== null && $r['longitude'] !== null);
      ?>
        <div class="gis-item <?= $hasCoords ? '' : 'unmapped' ?>" <?= $hasCoords ? 'data-brgy-id="' . (int)$r['id'] . '"' : '' ?>>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <b style="font-size:12px;"><?= e($r['name']) ?></b>
            <?= status_badge($r['risk_level']) ?>
          </div>
          <div class="d-flex justify-content-between text-muted" style="font-size:11px;">
            <span><i class="bi bi-clipboard2-pulse me-1"></i><?= (int)$r['case_count'] ?> cases (<?= (int)$r['active_cases'] ?> active)</span>
            <?php if ($hasCoords): ?>
              <span class="text-primary fw-semibold"><i class="bi bi-cursor-fill"></i> Locate</span>
            <?php else: ?>
              <a href="index.php?page=barangays&edit=<?= (int)$r['id'] ?>" class="text-warning text-decoration-none"><i class="bi bi-exclamation-circle"></i> Add GPS</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Initialize Leaflet Map with Street/Satellite/Light layers, risk circles, and fly-to navigation -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var DEFAULT_CENTER = [8.9475, 125.5406]; // Butuan City coordinates
    var locations = <?= json_encode($mapData) ?>;

    var streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 });
    var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles &copy; Esri', maxZoom: 19 });
    var lightLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 19 });

    var map = L.map('hotspotMap', { center: DEFAULT_CENTER, zoom: 12, layers: [streetLayer] });

    L.control.layers({ 'Street Map': streetLayer, 'Satellite Imagery': satelliteLayer, 'Clean Light (GIS)': lightLayer }, null, { position: 'topright' }).addTo(map);
    L.control.scale({ imperial: false }).addTo(map);

    function riskColor(risk) {
        if (risk === 'Very High') return '#b91c1c';
        if (risk === 'High')      return '#dc2626';
        if (risk === 'Medium')    return '#d97706';
        if (risk === 'Low')       return '#16a34a';
        return '#64748b';
    }

    var layerGroup = L.layerGroup().addTo(map);
    var markerLookup = {};

    function renderMarkers(filter) {
        layerGroup.clearLayers();
        markerLookup = {};

        locations.forEach(function (loc) {
            if (filter === 'HIGH' && loc.risk !== 'High' && loc.risk !== 'Very High') return;
            if (filter === 'Medium' && loc.risk !== 'Medium') return;
            if (filter === 'Low' && loc.risk !== 'Low') return;

            var col = riskColor(loc.risk);
            var rad = 9;

            // Draw translucent containment radius circles around High / Very High risk barangays
            if (loc.risk === 'Very High' || loc.risk === 'High') {
                L.circle([loc.lat, loc.lng], {
                    radius: loc.risk === 'Very High' ? 650 : 450,
                    color: col, weight: 1, dashArray: '4, 4', fillColor: col, fillOpacity: 0.12
                }).addTo(layerGroup);
            }

            var marker = L.circleMarker([loc.lat, loc.lng], {
                radius: rad, fillColor: col, color: '#ffffff', weight: 2, opacity: 1, fillOpacity: 0.9
            }).addTo(layerGroup);

            var popupHtml =
                '<div style="min-width:210px;font-family:DM Sans,sans-serif;">' +
                '<div style="font-weight:800;font-size:14px;color:#1e293b;margin-bottom:4px;">Brgy. ' + loc.name + '</div>' +
                '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:' + col + '20;color:' + col + ';margin-bottom:8px;">' + loc.risk + ' Risk</span>' +
                '<table style="width:100%;font-size:12px;border-collapse:collapse;">' +
                '<tr><td style="color:#64748b;padding:2px 0;">Population:</td><td style="text-align:right;font-weight:700;">' + loc.population.toLocaleString() + '</td></tr>' +
                '<tr><td style="color:#64748b;padding:2px 0;">Total Cases:</td><td style="text-align:right;font-weight:700;">' + loc.cases + ' (' + loc.active + ' active)</td></tr>' +
                '<tr><td style="color:#64748b;padding:2px 0;">Incidence Rate:</td><td style="text-align:right;font-weight:700;">' + loc.incidence + ' / 1k</td></tr>' +
                '<tr><td style="color:#64748b;padding:2px 0;">Disease:</td><td style="text-align:right;font-weight:700;">' + loc.top_disease + '</td></tr>' +
                '</table>' +
                '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e2e8f0;text-align:right;">' +
                '<a href="index.php?page=cases&q=' + encodeURIComponent(loc.name) + '" style="font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;">View / Edit Cases &rarr;</a>' +
                '</div>' +
                '</div>';

            marker.bindPopup(popupHtml);
            marker.bindTooltip('<b>' + loc.name + '</b> (' + loc.cases + ' cases)', { direction: 'top' });
            markerLookup[loc.id] = marker;
        });
    }

    function fitToMarkers() {
        var markers = Object.values(markerLookup);
        if (!markers.length) {
            map.setView(DEFAULT_CENTER, 12);
            return;
        }
        var group = L.featureGroup(markers);
        map.fitBounds(group.getBounds(), { padding: [50, 50], maxZoom: 14 });
    }

    renderMarkers('ALL');
    fitToMarkers();

    document.querySelectorAll('.gis-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.gis-filter-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            renderMarkers(btn.getAttribute('data-risk'));
            fitToMarkers();
        });
    });

    document.getElementById('fitMarkersBtn')?.addEventListener('click', fitToMarkers);

    document.querySelectorAll('.gis-item[data-brgy-id]').forEach(function (item) {
        item.addEventListener('click', function () {
            var id = item.getAttribute('data-brgy-id');
            var m = markerLookup[id];
            if (m) {
                map.flyTo(m.getLatLng(), 15, { duration: 0.8 });
                setTimeout(function () { m.openPopup(); }, 850);
            }
        });
    });
});
</script>

<!-- ======================================================================= -->
<!-- VIEW 4: ANALYTICS (Disease Bar Chart, Status Donut, Monthly Trend Line) -->
<!-- ======================================================================= -->
<?php elseif ($page === 'analytics'):
    page_heading('Analytics', 'Review case patterns by disease, status, and barangay.', 'bi-graph-up-arrow');
    $diseases = rows('SELECT disease, COALESCE(SUM(case_count), 0) total FROM cases GROUP BY disease ORDER BY total DESC');
    $statuses = rows('SELECT status, COALESCE(SUM(case_count), 0) total FROM cases GROUP BY status');
    $trend    = rows("SELECT DATE_FORMAT(report_date,'%Y-%m') period, COALESCE(SUM(case_count), 0) total FROM cases GROUP BY period ORDER BY period");
?>
 <div class="dashboard-grid">
   <div class="panel chart-panel"><div class="panel-head"><div><h3><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Cases by disease</h3><p>All recorded reports</p></div></div><div class="chart-wrap"><canvas id="analyticsDisease"></canvas></div></div>
   <div class="panel chart-panel"><div class="panel-head"><div><h3><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Case status breakdown</h3><p>Current registered status</p></div></div><div class="donut-wrap"><canvas id="analyticsStatus"></canvas></div></div>
 </div>
 <div class="panel mt-4"><div class="panel-head"><div><h3><i class="bi bi-graph-up me-2 text-primary"></i>Monthly case trend</h3><p>Count of cases grouped by report month</p></div></div><div class="chart-wrap tall"><canvas id="analyticsTrend"></canvas></div></div>
 <script>window.analyticsData={diseases:<?= json_encode(array_column($diseases, 'disease')) ?>,diseaseValues:<?= json_encode(array_map('intval', array_column($diseases, 'total'))) ?>,statuses:<?= json_encode(array_column($statuses, 'status')) ?>,statusValues:<?= json_encode(array_map('intval', array_column($statuses, 'total'))) ?>,periods:<?= json_encode(array_column($trend, 'period')) ?>,trendValues:<?= json_encode(array_map('intval', array_column($trend, 'total'))) ?>};</script>

<!-- ======================================================================= -->
<!-- VIEW 5: REPORTS (Generate Custom Reports & Manage Archived Spreadsheets)  -->
<!-- ======================================================================= -->
<?php elseif ($page === 'reports'):
    page_heading('Reports', 'Generate surveillance reports, manage files, and export case records.', 'bi-file-earmark-bar-graph-fill');
    $list = rows("
        SELECT r.*, b.name barangay, u.name generator,
            CASE 
                WHEN r.file_path IS NOT NULL AND r.file_path != '' THEN
                    (SELECT COUNT(*) FROM cases c WHERE c.report_id = r.id)
                ELSE
                    (SELECT COUNT(*) FROM cases c 
                     WHERE c.report_date BETWEEN r.date_from AND r.date_to
                       AND (r.disease IS NULL OR r.disease = '' OR c.disease LIKE CONCAT('%', r.disease, '%'))
                       AND (r.barangay_id IS NULL OR c.barangay_id = r.barangay_id))
            END AS linked_rows,
            CASE 
                WHEN r.file_path IS NOT NULL AND r.file_path != '' THEN
                    (SELECT COALESCE(SUM(c.case_count), 0) FROM cases c WHERE c.report_id = r.id)
                ELSE
                    (SELECT COALESCE(SUM(c.case_count), 0) FROM cases c 
                     WHERE c.report_date BETWEEN r.date_from AND r.date_to
                       AND (r.disease IS NULL OR r.disease = '' OR c.disease LIKE CONCAT('%', r.disease, '%'))
                       AND (r.barangay_id IS NULL OR c.barangay_id = r.barangay_id))
            END AS linked_total_cases
        FROM reports r
        LEFT JOIN barangays b ON b.id=r.barangay_id
        LEFT JOIN users u ON u.id=r.generated_by
        ORDER BY r.created_at DESC
    ");
?>
 <div class="panel mb-4">
   <div class="panel-head"><div><h3><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Generate report</h3><p>Choose the date range and optional filters.</p></div></div>
   <form method="post" class="row g-3 form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_report">
     <div class="col-md-4"><label class="form-label"><i class="bi bi-card-heading me-1"></i>Report title</label><input class="form-control" name="title" value="Disease monitoring report" required></div>
     <div class="col-md-2"><label class="form-label"><i class="bi bi-tag me-1"></i>Report type</label><select class="form-select" name="report_type"><option>Weekly</option><option>Monthly</option><option selected>Custom</option></select></div>
     <div class="col-md-3"><label class="form-label"><i class="bi bi-calendar-event me-1"></i>Date from</label><input class="form-control" type="date" name="date_from" value="<?= date('Y-m-01') ?>" required></div>
     <div class="col-md-3"><label class="form-label"><i class="bi bi-calendar-check me-1"></i>Date to</label><input class="form-control" type="date" name="date_to" value="<?= date('Y-m-d') ?>" required></div>
     <div class="col-md-4"><label class="form-label"><i class="bi bi-virus me-1"></i>Disease</label><input class="form-control" name="disease" value="<?= e($_GET['disease'] ?? '') ?>" placeholder="All diseases if blank"></div>
     <div class="col-md-4">
       <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Barangay</label>
       <select class="form-select" name="barangay_id">
         <option value="">All barangays</option>
         <?php foreach ($barangays as $b): ?>
           <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
         <?php endforeach; ?>
       </select>
     </div>
     <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-file-earmark-plus me-1"></i>Generate report</button></div>
   </form>
 </div>

 <div class="panel">
   <div class="panel-head"><div><h3><i class="bi bi-file-earmark-bar-graph-fill me-2 text-primary"></i>Saved reports &amp; imported files</h3><p><?= count($list) ?> records (Live-connected to Disease Cases &amp; Hotspot Map)</p></div><a class="btn btn-outline-primary btn-sm" href="export.php?type=cases"><i class="bi bi-download me-1"></i>Export all cases CSV</a></div>
   <div class="table-responsive"><table class="table data-table"><thead><tr><th>Report / File</th><th>Type</th><th>Date range</th><th>Disease</th><th>Connected Cases</th><th>Generated by</th><th>Action</th></tr></thead><tbody>
   <?php if (!$list) table_empty(7); foreach ($list as $r): ?>
     <tr>
       <td>
         <b><?= e($r['title']) ?></b>
         <small class="d-block text-secondary"><i class="bi bi-clock me-1"></i><?= e(date('M j, Y', strtotime($r['created_at']))) ?></small>
       </td>
       <td><?= e($r['report_type']) ?></td>
       <td><?= e($r['date_from']) ?> – <?= e($r['date_to']) ?></td>
       <td><?= e($r['disease'] ?: 'All diseases') ?></td>
       <td>
         <a href="index.php?page=view_import&id=<?= (int)$r['id'] ?>" class="text-decoration-none fw-bold text-primary">
           <i class="bi bi-link-45deg"></i><?= (int)$r['linked_rows'] ?> entries (<?= (int)$r['linked_total_cases'] ?> cases)
         </a>
       </td>
       <td><i class="bi bi-person-circle me-1 text-muted"></i><?= e($r['generator'] ?: 'Unknown') ?></td>
       <td>
         <div class="d-flex flex-wrap gap-1">
           <a class="btn btn-sm btn-outline-primary" href="index.php?page=view_import&id=<?= (int)$r['id'] ?>" title="View Spreadsheet Net Grid">
             <i class="bi bi-table"></i> View
           </a>
           <?php if (!empty($r['file_path'])): ?>
             <a class="btn btn-sm btn-light" href="<?= e($r['file_path']) ?>" download title="Download Original File">
               <i class="bi bi-download"></i> File
             </a>
           <?php else: ?>
             <a class="btn btn-sm btn-light" href="export.php?type=report&id=<?= (int)$r['id'] ?>" title="Export CSV">
               <i class="bi bi-download"></i> CSV
             </a>
           <?php endif; ?>
           <form method="post" class="d-inline" onsubmit="return confirm('Delete this report/file?')">
             <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
             <input type="hidden" name="action" value="delete_report">
             <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
             <button class="btn btn-sm btn-light text-danger" title="Delete">
               <i class="bi bi-trash"></i>
             </button>
           </form>
         </div>
       </td>
     </tr>
   <?php endforeach; ?>
   </tbody></table></div>
 </div>

<!-- ======================================================================= -->
<!-- VIEW 6: SPREADSHEET NET VIEW (Excel-Style Grid & Live DB Sync)          -->
<!-- ======================================================================= -->
<?php elseif ($page === 'view_import'):
    $reportId = (int)($_GET['id'] ?? 0);
    $rep = rows('SELECT * FROM reports WHERE id=?', [$reportId])[0] ?? null;
    if (!$rep) {
        echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Report or imported file not found.</div>';
        echo '<a href="index.php?page=reports" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Back to Reports</a>';
    } else {
        page_heading('Spreadsheet Net View', $rep['title'], 'bi-file-earmark-spreadsheet-fill');

        $hasOriginalFile = (!empty($rep['file_path']) && is_file(__DIR__ . '/' . $rep['file_path']));

        if ($hasOriginalFile) {
            $fullPath = __DIR__ . '/' . $rep['file_path'];
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $previewRows = parseSpreadsheetRows($fullPath, $ext);

            $liveCases = rows('SELECT c.*, b.name AS barangay_name FROM cases c JOIN barangays b ON b.id=c.barangay_id WHERE c.report_id=? ORDER BY c.id ASC', [$reportId]);
            $liveTotalCases = (int)scalar('SELECT COALESCE(SUM(case_count), 0) FROM cases WHERE report_id=?', [$reportId]);
        } else {
            $sql  = 'SELECT c.*, b.name AS barangay_name FROM cases c JOIN barangays b ON b.id=c.barangay_id WHERE c.report_date BETWEEN ? AND ?';
            $args = [$rep['date_from'], $rep['date_to']];
            if (!empty($rep['disease'])) {
                $sql  .= ' AND c.disease LIKE ?';
                $args[] = '%' . $rep['disease'] . '%';
            }
            if (!empty($rep['barangay_id'])) {
                $sql  .= ' AND c.barangay_id = ?';
                $args[] = (int)$rep['barangay_id'];
            }
            $sql .= ' ORDER BY b.name ASC, c.report_date DESC';
            $liveCases = rows($sql, $args);
            $liveTotalCases = array_sum(array_column($liveCases, 'case_count'));

            $previewRows = [
                ['Case Code', 'Disease', 'Barangay', 'Date Reported', 'Number of Cases', 'Status', 'Notes']
            ];
            foreach ($liveCases as $lc) {
                $previewRows[] = [
                    $lc['patient_code'],
                    $lc['disease'],
                    $lc['barangay_name'],
                    $lc['report_date'],
                    (string)$lc['case_count'],
                    $lc['status'],
                    (string)($lc['notes'] ?? '')
                ];
            }
        }

        $maxCols = 0;
        foreach ($previewRows as $r) {
            if (count($r) > $maxCols) $maxCols = count($r);
        }
        $maxCols = max($maxCols, 7);
?>
  <div class="panel mb-4">
    <div class="panel-head">
      <div>
        <h3><i class="bi bi-grid-3x3 me-2 text-primary"></i>Excel Net Grid (<?= $hasOriginalFile ? 'Original Spreadsheet' : e($rep['title']) ?>)</h3>
        <p><?= max(0, count($previewRows) - 1) ?> rows · Live Connected Total: <b><?= number_format($liveTotalCases) ?> cases</b> across <?= count($liveCases) ?> active DB records</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=cases&report_id=<?= $reportId ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square me-1"></i>Edit Connected Cases</a>
        <a href="index.php?page=hotspots<?= !empty($rep['disease']) ? '&disease=' . urlencode($rep['disease']) : '' ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-geo-alt-fill me-1"></i>View on Hotspot Map</a>
        <a href="<?= $hasOriginalFile ? e($rep['file_path']) : 'export.php?type=report&id=' . $reportId ?>" <?= $hasOriginalFile ? 'download' : '' ?> class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Download</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Delete this report/file?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_report">
          <input type="hidden" name="id" value="<?= $reportId ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete File</button>
        </form>
        <a href="index.php?page=reports" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
      </div>
    </div>

    <div class="p-3">
      <div class="excel-sheet-wrap">
        <table class="excel-table">
          <?php if ($previewRows): ?>
            <thead>
              <tr>
                <th class="excel-corner"></th>
                <?php for ($c = 0; $c < $maxCols; $c++): ?>
                  <th class="excel-col-letter"><?= excelColLetter($c) ?></th>
                <?php endfor; ?>
              </tr>
              <tr class="excel-header-row">
                <td class="excel-row-num">1</td>
                <?php for ($c = 0; $c < $maxCols; $c++): ?>
                  <th><?= e(preg_replace('/^\xEF\xBB\xBF/', '', (string)($previewRows[0][$c] ?? ''))) ?></th>
                <?php endfor; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (count($previewRows) <= 1): ?>
                <tr>
                  <td class="excel-row-num">2</td>
                  <td colspan="<?= $maxCols ?>" class="text-center text-muted py-4">No matching cases found for this report's date range and filters.</td>
                </tr>
              <?php else: foreach (array_slice($previewRows, 1) as $rIdx => $row): ?>
                <tr>
                  <td class="excel-row-num"><?= $rIdx + 2 ?></td>
                  <?php for ($c = 0; $c < $maxCols; $c++): 
                    $val = (string)($row[$c] ?? '');
                    $isNum = is_numeric($val) && $val !== '';
                  ?>
                    <td style="<?= $isNum ? 'text-align:right;font-weight:600;' : '' ?>"><?= e($val) ?></td>
                  <?php endfor; ?>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          <?php else: ?>
            <tbody><tr><td class="p-4 text-center text-muted">Empty spreadsheet.</td></tr></tbody>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <div>
        <h3><i class="bi bi-arrow-repeat me-2 text-primary"></i>Live Connected Database Records (Synced with Disease Cases &amp; Hotspot Map)</h3>
        <p>Any edits or additions made in Disease Cases for this report appear here in real time.</p>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table data-table">
        <thead><tr><th>Case Code</th><th>Disease</th><th>Barangay</th><th>Date Reported</th><th>Cases</th><th>Risk Level</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!$liveCases) table_empty(8); foreach ($liveCases as $lc): ?>
            <tr>
              <td><b><?= e($lc['patient_code']) ?></b></td>
              <td><?= e($lc['disease']) ?></td>
              <td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($lc['barangay_name']) ?></td>
              <td><?= e($lc['report_date']) ?></td>
              <td><b><?= (int)$lc['case_count'] ?></b></td>
              <td><?= status_badge($lc['risk_level']) ?></td>
              <td><?= status_badge($lc['status']) ?></td>
              <td><a href="index.php?page=cases&edit=<?= (int)$lc['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i> Edit</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php } ?>

<!-- ======================================================================= -->
<!-- VIEW 7: BARANGAY MANAGEMENT (Population & Interactive GPS Coordinate Map) -->
<!-- ======================================================================= -->
<?php elseif ($page === 'barangays'):
    page_heading('Barangay management', 'Maintain barangay population and coordinates.', 'bi-buildings-fill');
    $edit = isset($_GET['edit']) ? rows('SELECT * FROM barangays WHERE id=?', [(int)$_GET['edit']])[0] ?? null : null;
    $list = rows('SELECT * FROM barangays ORDER BY name');
?>
 <div class="panel mb-4">
   <div class="panel-head"><div><h3><i class="bi <?= $edit ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2 text-primary"></i><?= $edit ? 'Edit barangay' : 'Add barangay' ?></h3><p>Enter barangay details and click the map to pin coordinates.</p></div></div>
   <form method="post" class="row g-3 form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_barangay"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
     <div class="col-md-3"><label class="form-label"><i class="bi bi-building me-1"></i>Barangay name *</label><input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
     <div class="col-md-3"><label class="form-label"><i class="bi bi-people me-1"></i>Population</label><input class="form-control" type="number" min="0" name="population" value="<?= e($edit['population'] ?? 0) ?>"></div>
     <div class="col-md-3"><label class="form-label"><i class="bi bi-compass me-1"></i>Latitude</label><input class="form-control" type="number" step="any" name="latitude" id="latitude" value="<?= e($edit['latitude'] ?? '') ?>" placeholder="e.g. 8.9475"></div>
     <div class="col-md-3"><label class="form-label"><i class="bi bi-compass me-1"></i>Longitude</label><input class="form-control" type="number" step="any" name="longitude" id="longitude" value="<?= e($edit['longitude'] ?? '') ?>" placeholder="e.g. 125.5406"></div>
     <div class="col-12"><label class="form-label"><i class="bi bi-map me-1"></i>Click on the map to set coordinates <small class="text-secondary">(or type them above)</small></label><div id="pickerMap" style="height:320px;border-radius:12px;"></div></div>
     <div class="col-12 d-flex gap-2">
       <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save barangay</button>
       <?php if ($edit): ?><a class="btn btn-light" href="index.php?page=barangays"><i class="bi bi-x-circle me-1"></i>Cancel edit</a><?php endif; ?>
     </div>
   </form>
 </div>
 <div class="panel">
   <div class="panel-head"><div><h3><i class="bi bi-list-ul me-2 text-primary"></i>Registered barangays</h3><p><?= count($list) ?> barangays</p></div></div>
   <div class="table-responsive"><table class="table data-table"><thead><tr><th>Barangay</th><th>Population</th><th>Actions</th></tr></thead><tbody><?php foreach ($list as $r): ?><tr><td><b><?= e($r['name']) ?></b></td><td><?= number_format($r['population']) ?></td><td><a class="btn btn-sm btn-light" href="index.php?page=barangays&edit=<?= $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a> <form method="post" class="d-inline" onsubmit="return confirm('Delete this barangay?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_barangay"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light text-danger" title="Delete"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div>
 </div>

<!-- Interactive Leaflet coordinate picker: clicking the map auto-fills Latitude & Longitude inputs -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var latEl = document.getElementById('latitude');
    var lngEl = document.getElementById('longitude');
    var start = (latEl.value && lngEl.value) ? [parseFloat(latEl.value), parseFloat(lngEl.value)] : [8.9475, 125.5406];
    var zoom  = (latEl.value && lngEl.value) ? 13 : 11;
    var map = L.map('pickerMap').setView(start, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    var marker = null;
    if (latEl.value && lngEl.value) marker = L.marker(start).addTo(map);
    map.on('click', function (e) {
        var lat = e.latlng.lat.toFixed(6), lng = e.latlng.lng.toFixed(6);
        latEl.value = lat; lngEl.value = lng;
        if (marker) marker.setLatLng(e.latlng);
        else marker = L.marker(e.latlng).addTo(map);
    });
});
</script>

<!-- ======================================================================= -->
<!-- VIEW 8: ALERTS & NOTIFICATIONS (Create Advisories & Attach PDF Memos)   -->
<!-- ======================================================================= -->
<?php elseif ($page === 'alerts'):
    page_heading('Alerts and notifications', 'Create alerts, attach memos, and track follow-up actions.', 'bi-bell-fill');
    $list = rows('SELECT a.*,b.name barangay FROM alerts a LEFT JOIN barangays b ON b.id=a.barangay_id ORDER BY a.created_at DESC');
?>
 <div class="panel mb-4">
   <div class="panel-head"><div><h3><i class="bi bi-plus-circle me-2 text-primary"></i>Create an alert</h3><p>Notify staff about risks, case increases, or attach an official memo.</p></div></div>
   <form method="post" enctype="multipart/form-data" class="row g-3 form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_alert">
     <div class="col-md-4"><label class="form-label"><i class="bi bi-card-heading me-1"></i>Alert title *</label><input class="form-control" name="title" required placeholder="e.g. Health Advisory Memo #12"></div>
     <div class="col-md-2"><label class="form-label"><i class="bi bi-flag me-1"></i>Priority</label><select class="form-select" name="priority"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Barangay</label>
       <select class="form-select" name="barangay_id">
         <option value="">All barangays</option>
         <?php foreach ($barangays as $b): ?>
           <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
         <?php endforeach; ?>
       </select>
     </div>
    <div class="col-md-3">
       <label class="form-label"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Attach Memo (PDF only)</label>
       <input class="form-control" type="file" name="memo_file" accept=".pdf,application/pdf">
     </div>
     <div class="col-12"><label class="form-label"><i class="bi bi-chat-left-text me-1"></i>Message *</label><textarea class="form-control" name="message" rows="2" required placeholder="Enter alert details or memo summary..."></textarea></div>
     <div class="col-12"><button class="btn btn-primary"><i class="bi bi-bell me-1"></i>Create alert</button></div>
   </form>
 </div>
 <div class="panel">
   <div class="panel-head"><div><h3><i class="bi bi-bell-fill me-2 text-primary"></i>Alert register</h3><p><?= count($list) ?> alerts</p></div></div>
   <div class="table-responsive"><table class="table data-table"><thead><tr><th>Alert</th><th>Memo</th><th>Barangay</th><th>Priority</th><th>Created</th><th>Status</th><th>Update</th></tr></thead><tbody>
   <?php if (!$list) table_empty(7); foreach ($list as $r): ?>
     <tr>
       <td><b><?= e($r['title']) ?></b><small class="d-block text-secondary"><?= e($r['message']) ?></small></td>
       <td>
         <?php if (!empty($r['attachment'])): ?>
           <a href="<?= e($r['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
             <i class="bi bi-file-earmark-text me-1"></i>View Memo
           </a>
         <?php else: ?>
           <span class="text-muted">—</span>
         <?php endif; ?>
       </td>
       <td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($r['barangay'] ?? 'All barangays') ?></td>
       <td><?= status_badge($r['priority']) ?></td>
       <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
       <td><?= status_badge($r['status']) ?></td>
       <td>
         <form method="post" class="d-flex gap-1">
           <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_alert"><input type="hidden" name="id" value="<?= $r['id'] ?>">
           <select class="form-select form-select-sm" name="status"><?php foreach (['Open','In Progress','Resolved'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
           <button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i></button>
         </form>
       </td>
     </tr>
   <?php endforeach; ?>
   </tbody></table></div>
 </div>

<!-- ======================================================================= -->
<!-- VIEW 9: RECOMMENDATIONS (Public Health Action Plans & PDF Memos)        -->
<!-- ======================================================================= -->
<?php elseif ($page === 'recommendations'):
    page_heading('Recommendations', 'Plan prevention activities, attach memos, and monitor implementation.', 'bi-lightbulb-fill');
    $list = rows('SELECT r.*,b.name barangay FROM recommendations r LEFT JOIN barangays b ON b.id=r.barangay_id ORDER BY r.created_at DESC');
?>
 <div class="panel mb-4">
   <div class="panel-head"><div><h3><i class="bi bi-plus-circle me-2 text-primary"></i>Add recommendation</h3><p>Record public health actions or attach an official memo for a barangay or the whole city.</p></div></div>
   <form method="post" enctype="multipart/form-data" class="row g-3 form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_recommendation">
     <div class="col-md-3"><label class="form-label"><i class="bi bi-lightbulb me-1"></i>Action title *</label><input class="form-control" name="title" required placeholder="e.g. Community clean-up drive"></div>
     <div class="col-md-3">
       <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Barangay</label>
       <select class="form-select" name="barangay_id">
         <option value="">All barangays</option>
         <?php foreach ($barangays as $b): ?>
           <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
         <?php endforeach; ?>
       </select>
     </div>
     <div class="col-md-2"><label class="form-label"><i class="bi bi-calendar-event me-1"></i>Due date</label><input class="form-control" type="date" name="due_date"></div>
     <div class="col-md-2"><label class="form-label"><i class="bi bi-activity me-1"></i>Status</label><select class="form-select" name="status"><option>Pending</option><option>In Progress</option><option>Completed</option></select></div>
     <div class="col-md-2">
       <label class="form-label"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Attach Memo (PDF)</label>
       <input class="form-control" type="file" name="memo_file" accept=".pdf,application/pdf">
     </div>
     <div class="col-12"><label class="form-label"><i class="bi bi-journal-text me-1"></i>Description *</label><textarea class="form-control" name="description" rows="2" required placeholder="Enter action details or memo summary..."></textarea></div>
     <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save recommendation</button></div>
   </form>
 </div>
 <div class="panel">
   <div class="panel-head"><div><h3><i class="bi bi-lightbulb-fill me-2 text-primary"></i>Recommended actions</h3><p><?= count($list) ?> action items</p></div></div>
   <div class="table-responsive"><table class="table data-table"><thead><tr><th>Action</th><th>Memo</th><th>Barangay</th><th>Due date</th><th>Status</th><th>Update status</th></tr></thead><tbody>
   <?php if (!$list) table_empty(6); foreach ($list as $r): ?>
     <tr>
       <td><b><?= e($r['title']) ?></b><small class="d-block text-secondary"><?= e($r['description']) ?></small></td>
       <td>
         <?php if (!empty($r['attachment'])): ?>
           <a href="<?= e($r['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
             <i class="bi bi-file-earmark-text me-1"></i>View Memo
           </a>
         <?php else: ?>
           <span class="text-muted">—</span>
         <?php endif; ?>
       </td>
       <td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($r['barangay'] ?? 'All barangays') ?></td>
       <td><i class="bi bi-calendar3 me-1 text-muted"></i><?= e($r['due_date'] ?: '—') ?></td>
       <td><?= status_badge($r['status']) ?></td>
       <td>
         <form method="post" class="d-flex gap-1">
           <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_recommendation"><input type="hidden" name="id" value="<?= $r['id'] ?>">
           <select class="form-select form-select-sm" name="status"><?php foreach (['Pending','In Progress','Completed'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
           <button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i></button>
         </form>
       </td>
     </tr>
   <?php endforeach; ?>
   </tbody></table></div>
 </div>

<!-- ======================================================================= -->
<!-- VIEW 10: USER MANAGEMENT (Staff Accounts & RBAC Role Assignment)        -->
<!-- ======================================================================= -->
<?php elseif ($page === 'users'):
    page_heading('User management', 'Manage staff access to the disease monitoring system.', 'bi-people-fill');
    if (!$isFullAccess): ?>
   <div class="panel p-4"><i class="bi bi-shield-lock me-2 text-warning"></i>Only Administrators and Health Officers can manage user accounts.</div>
 <?php else:
        $list = rows('SELECT id,name,email,role,active,created_at FROM users ORDER BY created_at DESC');
        $edit = isset($_GET['edit']) ? rows('SELECT id,name,email,role FROM users WHERE id=?', [(int)$_GET['edit']])[0] ?? null : null;
    ?>
   <div class="panel mb-4">
     <div class="panel-head"><div><h3><i class="bi <?= $edit ? 'bi-pencil-square' : 'bi-person-plus-fill' ?> me-2 text-primary"></i><?= $edit ? 'Edit user' : 'Create user account' ?></h3><p>Use strong passwords and grant only the access each staff member needs.</p></div></div>
     <form method="post" class="row g-3 form-pad">
       <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
       <div class="col-md-4"><label class="form-label"><i class="bi bi-person me-1"></i>Full name</label><input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
       <div class="col-md-4"><label class="form-label"><i class="bi bi-envelope me-1"></i>Email</label><input class="form-control" type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>"></div>
       <div class="col-md-4"><label class="form-label"><i class="bi bi-shield-check me-1"></i>Role</label><select class="form-select" name="role"><?php foreach (['Administrator','Health Officer','Encoder'] as $r): ?><option <?= ($edit['role'] ?? 'Encoder') === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></div>
       <div class="col-md-6"><label class="form-label"><i class="bi bi-key me-1"></i>Password <?= $edit ? '(leave blank to keep current)' : '(minimum 8 characters)' ?></label><input class="form-control" type="password" name="password" <?= $edit ? '' : 'required minlength="8"' ?> autocomplete="new-password"></div>
       <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save user</button><?php if ($edit): ?> <a class="btn btn-light" href="index.php?page=users"><i class="bi bi-x-circle me-1"></i>Cancel</a><?php endif; ?></div>
     </form>
   </div>
   <div class="panel">
     <div class="panel-head"><div><h3><i class="bi bi-people-fill me-2 text-primary"></i>System users</h3><p><?= count($list) ?> accounts</p></div></div>
     <div class="table-responsive"><table class="table data-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach ($list as $r): ?><tr><td><b><i class="bi bi-person-circle me-1 text-muted"></i><?= e($r['name']) ?></b></td><td><?= e($r['email']) ?></td><td><span class="status-badge secondary"><i class="bi bi-shield"></i><?= e($r['role']) ?></span></td><td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td><td><a class="btn btn-sm btn-light" href="index.php?page=users&edit=<?= $r['id'] ?>"><i class="bi bi-pencil"></i> Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
   </div>
 <?php endif; ?>

<!-- ======================================================================= -->
<!-- VIEW 11: SYSTEM SETTINGS (Health Unit Name & Deployment Security Notes) -->
<!-- ======================================================================= -->
<?php elseif ($page === 'settings'):
    page_heading('System settings', 'Configure the health unit name and system label.', 'bi-sliders');
    $settings = rows('SELECT setting_key,setting_value FROM settings');
    $settings = array_column($settings, 'setting_value', 'setting_key');
?>
 <div class="panel settings-panel">
   <div class="panel-head"><div><h3><i class="bi bi-gear-fill me-2 text-primary"></i>General settings</h3><p>These details are displayed in the application sidebar.</p></div></div>
   <form method="post" class="form-pad">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings">
     <div class="mb-3"><label class="form-label"><i class="bi bi-building me-1"></i>City / health unit name</label><input class="form-control" name="city_name" required value="<?= e($settings['city_name'] ?? 'Butuan City') ?>"></div>
     <div class="mb-3"><label class="form-label"><i class="bi bi-tag me-1"></i>System name</label><input class="form-control" name="system_name" required value="<?= e($settings['system_name'] ?? 'Disease Monitoring') ?>"></div>
     <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save settings</button>
   </form>
 </div>
 <div class="panel mt-4">
   <div class="panel-head"><div><h3><i class="bi bi-shield-lock-fill me-2 text-primary"></i>Security notes</h3><p>Recommended steps before deployment.</p></div></div>
   <div class="security-list">
     <div><i class="bi bi-shield-check"></i><span><b>Use HTTPS in production</b><small>Protect credentials and sensitive health information in transit.</small></span></div>
     <div><i class="bi bi-key"></i><span><b>Change demo credentials</b><small>Replace the seeded administrator password before using real data.</small></span></div>
     <div><i class="bi bi-database-lock"></i><span><b>Back up the database</b><small>Schedule regular database backups and restrict database access.</small></span></div>
   </div>
 </div>
<?php endif; ?>

<!-- Load closing footer tags and JavaScript bundles -->
<?php require 'includes/footer.php'; ?>