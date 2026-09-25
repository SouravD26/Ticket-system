<?php
/**
 * Export the signed-in person's daily task sheet for a date range, as CSV or PDF.
 * Employees and IT download their own sheet; nobody can pull anyone else's from here.
 */
require_once __DIR__ . '/includes/functions.php';
require_can('can_fill_tasks', 'the daily task sheet');
$me = user();

$format = get_('format') === 'pdf' ? 'pdf' : 'csv';
$from   = get_('from') ?: date('Y-m-d', strtotime('-13 days'));
$to     = get_('to')   ?: date('Y-m-d');

// Fall back to a sane window rather than exporting nothing on a malformed URL.
if (!strtotime($from)) $from = date('Y-m-d', strtotime('-13 days'));
if (!strtotime($to))   $to   = date('Y-m-d');
if ($from > $to)       [$from, $to] = [$to, $from];

$rows = q('SELECT d.*, t.code AS ticket_code
           FROM daily_tasks d
           LEFT JOIN tickets t ON t.id = d.ticket_id
           WHERE d.user_id = ? AND d.task_date BETWEEN ? AND ?
           ORDER BY d.task_date ASC, d.id ASC',
          [$me['id'], $from, $to])->fetchAll();

$totalHours = array_sum(array_map('floatval', array_column($rows, 'hours')));
$range      = date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to));
$slug       = preg_replace('/[^A-Za-z0-9]+/', '-', $me['username']) . '_' . $from . '_to_' . $to;

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="daily-tasks_' . $slug . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");            // BOM, so Excel reads UTF-8 correctly

    // The date range is stated in the file itself, not just the filename.
    fputcsv($out, ['Daily task sheet']);
    fputcsv($out, ['Person', $me['name'] . ' (' . $me['username'] . ')']);
    fputcsv($out, ['Role', ROLE_LABELS[$me['role']] ?? $me['role']]);
    fputcsv($out, ['Date range', $range]);
    fputcsv($out, ['Generated', date('M j, Y g:i a')]);
    fputcsv($out, []);

    fputcsv($out, ['Date', 'Task', 'Details', 'Time (h:mm)', 'Status', 'Ticket']);
    foreach ($rows as $r) {
        fputcsv($out, [
            date('Y-m-d', strtotime($r['task_date'])),
            $r['title'],
            $r['description'] ?? '',
            hm($r['hours']),
            TASK_STATUSES[$r['status']] ?? $r['status'],
            $r['ticket_code'] ?? '',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', 'Total entries', count($rows), hm($totalHours), 'h:mm']);
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/pdf.php';

$pdf = new SimplePdf(
    'Daily task sheet - ' . $me['name'],
    (ROLE_LABELS[$me['role']] ?? $me['role']) . '  |  ' . $range
        . '  |  ' . count($rows) . ' entr' . (count($rows) === 1 ? 'y' : 'ies')
        . '  |  ' . hm($totalHours) . ' h',
    [
        // A4 landscape less margins = 770pt of printable width; these must add up to it.
        ['DATE',     75, 'l'],
        ['TASK',    225, 'l'],
        ['DETAILS', 270, 'l'],
        ['STATUS',   75, 'l'],
        ['TICKET',   75, 'l'],
        ['H:MM',     50, 'r'],
    ]
);

foreach ($rows as $i => $r) {
    $pdf->row([
        date('M j, Y', strtotime($r['task_date'])),
        $r['title'],
        $r['description'] ?? '',
        TASK_STATUSES[$r['status']] ?? $r['status'],
        $r['ticket_code'] ?? '-',
        hm($r['hours']),
    ], $i % 2 === 1);
}

if (!$rows) {
    $pdf->row(['-', 'No task entries in this date range.', '', '', '', '']);
}

$pdf->totals(['', 'Total', '', '', '', rtrim(rtrim(number_format($totalHours, 2, '.', ''), '0'), '.') . ' h']);

$body = $pdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="daily-tasks_' . $slug . '.pdf"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
echo $body;
