<?php
/**
 * inventory_report.php — Inventory Report: current stock level + value per item.
 * Branch-scoped; exports to a print-friendly PDF (?export=pdf).
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role       = $_SESSION['user_role'] ?? 'account_user';
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;

$branches = $seesAll ? $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll() : [];
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);

$filterBranch = 0;
if ($seesAll && isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true)) {
    $filterBranch = (int) $_GET['branch'];
}
$scopeBranch = $seesAll ? $filterBranch : $userBranch; // 0 = all branches (managers)

/* Items with value. */
$wi = $scopeBranch ? 'WHERE i.branch_id = ?' : '';
$pi = $scopeBranch ? [$scopeBranch] : [];
$stmt = $pdo->prepare(
    "SELECT i.name, i.category, i.quantity, i.unit, i.reorder_level, i.price, b.name AS branch
     FROM items i LEFT JOIN branches b ON b.id = i.branch_id $wi
     ORDER BY b.name ASC, i.category ASC, i.sort_order ASC, i.name ASC"
);
$stmt->execute($pi);
$rows = $stmt->fetchAll();

$totQty = 0; $totValue = 0.0;
foreach ($rows as $r) { $totQty += (int) $r['quantity']; $totValue += (int) $r['quantity'] * (float) $r['price']; }

/* PDF export (print-friendly page → browser "Save as PDF"). */
if (($_GET['export'] ?? '') === 'pdf') {
    require __DIR__ . '/includes/print_report.php';
    $cols = [[__('col_name'), 'l'], [__('col_category'), 'l']];
    if ($seesAll) { $cols[] = [__('col_branch'), 'l']; }
    $cols[] = [__('col_qty'), 'r'];
    $cols[] = [__('col_price'), 'r'];
    $cols[] = [__('rep_stock_value'), 'r'];
    $cols[] = [__('col_status'), 'c'];

    $body = [];
    foreach ($rows as $r) {
        $val  = (int) $r['quantity'] * (float) $r['price'];
        $stat = (int) $r['quantity'] <= (int) $r['reorder_level'] ? __('status_low') : __('status_ok');
        $line = [$r['name'], $r['category'] ?: '-'];
        if ($seesAll) { $line[] = $r['branch'] ?: '-'; }
        $line[] = number_format((int) $r['quantity']);
        $line[] = number_format((float) $r['price'], 2);
        $line[] = number_format($val, 2);
        $line[] = $stat;
        $body[] = $line;
    }
    $foot = [__('rep_total'), ''];
    if ($seesAll) { $foot[] = ''; }
    $foot[] = number_format($totQty);
    $foot[] = '';
    $foot[] = 'RM ' . number_format($totValue, 2);
    $foot[] = '';

    $meta = [];
    if ($seesAll) {
        $bn = __('all_branches');
        foreach ($branches as $b) { if ((int) $b['id'] === $filterBranch) { $bn = $b['name']; break; } }
        $meta[] = __('filter_branch') . ': ' . $bn;
    }
    render_print_report(__('invr_title'), $meta, print_table_html($cols, $body, $foot));
}

$exportQuery = ['export' => 'pdf'];
if ($filterBranch) { $exportQuery['branch'] = $filterBranch; }

$pageTitle = __('invr_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('invr_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('invr_sub')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($seesAll): ?>
                <form method="get" action="inventory_report.php" class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('filter_branch')) ?>:</label>
                    <select name="branch" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value="0"><?= e(__('all_branches')) ?></option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= $filterBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <a href="inventory_report.php?<?= e(http_build_query($exportQuery)) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors shadow-sm shadow-rose-600/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <?= e(__('rep_export_pdf')) ?>
            </a>
        </div>
    </div>

    <?php $reportNav = 'inventory'; require __DIR__ . '/includes/report_nav.php'; ?>

    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_category')) ?></th>
                        <?php if ($seesAll): ?><th class="px-4 py-3 font-semibold"><?= e(__('col_branch')) ?></th><?php endif; ?>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_qty')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_price')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('rep_stock_value')) ?></th>
                        <th class="px-4 py-3 font-semibold text-center"><?= e(__('col_status')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= $seesAll ? 7 : 6 ?>" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('inv_empty')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): $low = (int) $r['quantity'] <= (int) $r['reorder_level']; $val = (int) $r['quantity'] * (float) $r['price']; ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($r['name']) ?></td>
                                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300"><?= e($r['category'] ?: '-') ?></td>
                                <?php if ($seesAll): ?><td class="px-4 py-2.5 text-gray-600 dark:text-gray-300"><?= e($r['branch'] ?: '-') ?></td><?php endif; ?>
                                <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-200"><?= (int) $r['quantity'] ?></td>
                                <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-200"><?= e(number_format((float) $r['price'], 2)) ?></td>
                                <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(number_format($val, 2)) ?></td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $low ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' ?>"><?= e($low ? __('status_low') : __('status_ok')) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 dark:bg-gray-900/50 font-bold">
                            <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="<?= $seesAll ? 3 : 2 ?>"><?= e(__('rep_total')) ?></td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= (int) $totQty ?></td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">RM <?= e(number_format($totValue, 2)) ?></td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
