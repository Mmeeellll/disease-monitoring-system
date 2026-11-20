<?php
/**
 * FILE: export.php
 * PURPOSE: Generates downloadable CSV exports of disease case records.
 * SYSTEM ROLE: Triggered from the Reports page to export either a specific
 *              filtered report (?type=report&id=X) or the entire case register.
 */

require_once 'config.php';
require_login(); // Restrict exports to authenticated personnel only

$type = $_GET['type'] ?? 'cases';

// Set HTTP headers to force a UTF-8 CSV file download
header('Content-Type: text/csv; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$filename = 'disease-monitoring-' . date('Y-m-d') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// Write UTF-8 Byte Order Mark (BOM) so Excel renders Filipino/Spanish characters (e.g., Pequeño, Niño) properly
fwrite($out, "\xEF\xBB\xBF");

if ($type === 'report' && isset($_GET['id'])) {
    // Export cases matching a saved report's date range, disease, and barangay filters
    $s = db()->prepare('SELECT * FROM reports WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    $r = $s->fetch();
    if (!$r) {
        http_response_code(404);
        exit('Report not found');
    }

    $sql = 'SELECT c.patient_code, c.report_date, b.name barangay, c.disease, c.case_count, c.risk_level, c.status 
            FROM cases c JOIN barangays b ON b.id=c.barangay_id 
            WHERE c.report_date BETWEEN ? AND ?';
    $args = [$r['date_from'], $r['date_to']];
    if ($r['disease']) {
        $sql .= ' AND c.disease=?';
        $args[] = $r['disease'];
    }
    if ($r['barangay_id']) {
        $sql .= ' AND c.barangay_id=?';
        $args[] = $r['barangay_id'];
    }
    $sql .= ' ORDER BY c.report_date DESC';

    $s = db()->prepare($sql);
    $s->execute($args);

    // Write report header metadata followed by tabular rows
    fputcsv($out, ['Report', $r['title']]);
    fputcsv($out, ['Date from', $r['date_from'], 'Date to', $r['date_to']]);
    fputcsv($out, []);
    fputcsv($out, ['Case code', 'Date reported', 'Barangay', 'Disease', 'Cases', 'Risk level', 'Status']);
    while ($row = $s->fetch()) fputcsv($out, $row);
} else {
    // Export all recorded disease cases across all barangays
    $s = db()->query('SELECT c.patient_code, c.report_date, b.name barangay, c.disease, c.case_count, c.risk_level, c.status 
                      FROM cases c JOIN barangays b ON b.id=c.barangay_id 
                      ORDER BY c.report_date DESC');
    fputcsv($out, ['Case code', 'Date reported', 'Barangay', 'Disease', 'Cases', 'Risk level', 'Status']);
    while ($row = $s->fetch()) fputcsv($out, $row);
}

fclose($out);
exit;