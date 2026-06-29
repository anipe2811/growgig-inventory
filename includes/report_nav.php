<?php
/**
 * includes/report_nav.php — shared report view switcher.
 * Set $reportNav to one of: summary | stockcard | inventory | sales before including.
 */
$reportNav  = $reportNav ?? 'summary';
$reportTabs = [
    ['summary',   'reports.php',          __('rep_view_summary')],
    ['stockcard', 'stockcard.php',        __('rep_view_card')],
    ['inventory', 'inventory_report.php', __('rep_view_inventory')],
    ['sales',     'sales_report.php',     __('rep_view_sales')],
];
?>
<div class="mt-6 inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 dark:bg-gray-800 p-1">
    <?php foreach ($reportTabs as [$key, $href, $label]): ?>
        <a href="<?= e($href) ?>" class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors <?= $reportNav === $key ? 'bg-indigo-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-700' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
