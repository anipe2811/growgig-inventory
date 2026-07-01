<?php
/**
 * includes/print_report.php — render a clean, print-ready report document.
 *
 * The page styles a single report and immediately opens the browser print
 * dialog, where the user picks "Save as PDF". This gives every report a
 * professional PDF export with zero external libraries / dependencies.
 *
 * Helpers:
 *   print_table_html($cols, $rows, $foot)  -> build a simple <table> string
 *   render_print_report($title, $meta, $tableHtml, $orientation) -> output + exit
 */

/**
 * Build a simple report table.
 *   $cols = [[label, align], ...]   align = 'l' | 'r' | 'c'
 *   $rows = [[cell, cell, ...], ...]  cells are plain strings (already formatted)
 *   $foot = [cell, ...] | null        an optional bold totals row
 */
function print_table_html(array $cols, array $rows, ?array $foot = null): string
{
    $cls = static fn($a) => $a === 'r' ? 'r' : ($a === 'c' ? 'c' : 'l');

    $h = '<table class="rpt"><thead><tr>';
    foreach ($cols as [$label, $a]) {
        $h .= '<th class="' . $cls($a) . '">' . e($label) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    if (!$rows) {
        $h .= '<tr><td class="c" colspan="' . count($cols) . '">&mdash;</td></tr>';
    } else {
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($cols as $i => [$label, $a]) {
                $h .= '<td class="' . $cls($a) . '">' . e((string) ($r[$i] ?? '')) . '</td>';
            }
            $h .= '</tr>';
        }
    }
    $h .= '</tbody>';
    if ($foot) {
        $h .= '<tfoot><tr class="tot">';
        foreach ($cols as $i => [$label, $a]) {
            $h .= '<td class="' . $cls($a) . '">' . e((string) ($foot[$i] ?? '')) . '</td>';
        }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table>';
}

/**
 * Output a full print document for one report, then exit.
 *   $title       report title (e.g. "Sales Report")
 *   $metaLines   array of small grey lines under the title (branch, date range…)
 *   $tableHtml   the report body (use print_table_html() or a custom table)
 *   $orientation 'portrait' (default) | 'landscape' (wide tables, e.g. stock card)
 */
function render_print_report(string $title, array $metaLines, string $tableHtml, string $orientation = 'portrait'): void
{
    ini_set('display_errors', '0');
    $brand = current_brand();
    $gen   = date('d/m/Y H:i');
    $land  = $orientation === 'landscape';
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="<?= e($_SESSION['lang'] ?? 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<style>
    @page { size: A4 <?= $land ? 'landscape' : 'portrait' ?>; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        color: #111827;
        margin: 0;
        padding: 20px;
        background: #fff;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .bar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
    .btn { background: #4f46e5; color: #fff; border: 0; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn:hover { background: #4338ca; }
    .head { display: flex; align-items: center; gap: 10px; border-bottom: 2.5px solid #4f46e5; padding-bottom: 8px; }
    .head img { height: 36px; width: 36px; object-fit: contain; border-radius: 6px; }
    .brand { font-size: 16px; font-weight: 700; color: #4f46e5; line-height: 1.2; }
    h1 { font-size: 18px; margin: 10px 0 3px; }
    .meta { color: #6b7280; font-size: 11px; margin: 0.5px 0; line-height: 1.35; }
    table.rpt {
        width: 100%;
        border-collapse: collapse;
        font-size: 10.5px;
        margin-top: 10px;
    }
    table.rpt th {
        background: #eef2ff;
        color: #3730a3;
        text-align: left;
        padding: 4px 8px;
        border-bottom: 2px solid #c7d2fe;
        font-weight: 600;
        white-space: nowrap;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    table.rpt td {
        padding: 3.5px 8px;
        border-bottom: 1px solid #eee;
        line-height: 1.3;
    }
    table.rpt tbody tr:nth-child(even) td {
        background: #fafafa;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    table.rpt .r { text-align: right; }
    table.rpt .c { text-align: center; }
    table.rpt tfoot .tot td {
        font-weight: 700;
        background: #eef2ff;
        border-top: 2px solid #c7d2fe;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    /* Multi-page robustness: repeat the header (both rowspan rows) on every printed
       page, keep the totals row as a footer group on the last page, and never split
       a row across a page break. */
    table.rpt thead { display: table-header-group; }
    table.rpt tfoot { display: table-footer-group; }
    table.rpt tr { break-inside: avoid; page-break-inside: avoid; }
    .foot { margin-top: 16px; color: #9ca3af; font-size: 10px; text-align: center; line-height: 1.5; }
    .foot .gis { color: #6366f1; font-weight: 600; }
    @media print {
        .bar { display: none; }
        body { padding: 0; }
    }
</style>
</head>
<body>
    <div class="bar"><button class="btn" onclick="window.print()">&#11015; <?= e(__('rep_print')) ?></button></div>
    <div class="head">
        <img src="<?= e($brand['logo']) ?>" alt="">
        <div><div class="brand"><?= e($brand['nav_name']) ?></div></div>
    </div>
    <h1><?= e($title) ?></h1>
    <?php foreach ($metaLines as $m): if ($m === '') { continue; } ?>
        <div class="meta"><?= e($m) ?></div>
    <?php endforeach; ?>
    <div class="meta"><?= e(__('rep_generated')) ?>: <?= e($gen) ?></div>
    <?= $tableHtml ?>
    <div class="foot">
        <?= e($brand['nav_name']) ?> &middot; <?= e($title) ?><br>
        <span class="gis"><?= e(__('rep_gis')) ?></span>
    </div>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html><?php
    exit;
}
