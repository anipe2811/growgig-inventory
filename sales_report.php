<?php
/**
 * sales_report.php — Sales Report: items that went OUT (used/sold) and their
 * value (qty out x unit price), over an optional date range. Branch-scoped; CSV.
 */
require_once __DIR__ . '/config/config.php';
require_login();
if (agency_needs_account()) { require __DIR__ . '/includes/account_gate.php'; exit; }

$role       = $_SESSION['user_role'] ?? 'account_user';
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;
$acct       = current_account_id(); // non-null = restrict to this account; null = agency "all accounts" (global)

if ($seesAll) {
    if ($acct) {
        $bst = $pdo->prepare('SELECT id, name FROM branches WHERE account_id = ? ORDER BY name ASC');
        $bst->execute([$acct]);
        $branches = $bst->fetchAll();
    } else {
        $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
    }
} else {
    $branches = [];
}
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);

$filterBranch = 0;
if ($seesAll && isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true)) {
    $filterBranch = (int) $_GET['branch'];
}
$scopeBranch = $seesAll ? $filterBranch : $userBranch;

/* Optional date range. */
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw   = trim((string) ($_GET['to'] ?? ''));
$fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) ? $fromRaw : null;
$toDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) ? $toRaw : null;
if (!$fromDate) { $fromRaw = ''; }
if (!$toDate) { $toRaw = ''; }

/* Stock-out grouped by item = "sales".
 * Mobile "on-the-go" trips (mobile.php) are tagged with stock_movements.trip_id:
 * the take is an 'out' (counted here) and the unsold return is an 'in' (subtracted),
 * so a trip nets to its SOLD quantity. Direct sales/restocks (trip_id IS NULL) are
 * unaffected — a normal restock stays excluded because it is type='in' with NULL trip_id.
 * Caveat: a trip's take is dated trip_date and its return is dated the settle date; if a
 * date filter splits the two, the net is exact only over a window covering both events.
 * For same-day trips (the clinic norm) it is always exact. HAVING drops zero/negative rows. */
$cond = ["(m.type = 'out' OR (m.type = 'in' AND m.trip_id IS NOT NULL))"]; $params = [];
if ($scopeBranch) { $cond[] = 'm.branch_id = ?';     $params[] = $scopeBranch; }
// Default (no single branch): restrict to the acting account's branches. $acct is an int, so inlining is injection-safe.
elseif ($acct)    { $cond[] = 'b.account_id = ' . (int) $acct; }
if ($fromDate)    { $cond[] = 'm.movement_date >= ?'; $params[] = $fromDate; }
if ($toDate)      { $cond[] = 'm.movement_date <= ?'; $params[] = $toDate; }
// Sales only lists items flagged "Mark as Sale" in Inventory; everything else is excluded.
$cond[] = 'i.mark_as_sale = 1';
$where = 'WHERE ' . implode(' AND ', $cond);
$stmt = $pdo->prepare(
    "SELECT i.name, i.category, i.price, b.name AS branch,
            SUM(CASE WHEN m.type = 'out' THEN m.quantity ELSE -m.quantity END) AS qty_out
     FROM stock_movements m JOIN items i ON i.id = m.item_id LEFT JOIN branches b ON b.id = m.branch_id
     $where
     GROUP BY m.item_id, i.name, i.category, i.price, i.sort_order, b.name
     HAVING qty_out > 0 ORDER BY b.name ASC, i.category ASC, i.sort_order ASC, i.name ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totQty = 0; $totValue = 0.0;
foreach ($rows as $r) { $totQty += (int) $r['qty_out']; $totValue += (int) $r['qty_out'] * (float) $r['price']; }

/* PDF export (print-friendly page → browser "Save as PDF"). */
if (($_GET['export'] ?? '') === 'pdf') {
    require __DIR__ . '/includes/print_report.php';
    $cols = [[__('col_name'), 'l'], [__('col_category'), 'l']];
    if ($seesAll) { $cols[] = [__('col_branch'), 'l']; }
    $cols[] = [__('col_qty_out'), 'r'];
    $cols[] = [__('col_unit_price'), 'r'];
    $cols[] = [__('col_sales_value'), 'r'];

    $body = [];
    foreach ($rows as $r) {
        $val  = (int) $r['qty_out'] * (float) $r['price'];
        $line = [$r['name'], $r['category'] ?: '-'];
        if ($seesAll) { $line[] = $r['branch'] ?: '-'; }
        $line[] = number_format((int) $r['qty_out']);
        $line[] = number_format((float) $r['price'], 2);
        $line[] = number_format($val, 2);
        $body[] = $line;
    }
    $foot = [__('rep_total'), ''];
    if ($seesAll) { $foot[] = ''; }
    $foot[] = number_format($totQty);
    $foot[] = '';
    $foot[] = 'RM ' . number_format($totValue, 2);

    $meta = [];
    if ($seesAll) {
        $bn = __('all_branches');
        foreach ($branches as $b) { if ((int) $b['id'] === $filterBranch) { $bn = $b['name']; break; } }
        $meta[] = __('filter_branch') . ': ' . $bn;
    }
    if ($fromRaw !== '' || $toRaw !== '') {
        $meta[] = __('rep_from') . ': ' . ($fromRaw ?: '—') . '   ' . __('rep_to') . ': ' . ($toRaw ?: '—');
    }
    render_print_report(__('salr_title'), $meta, print_table_html($cols, $body, $foot));
}

$exportQuery = ['export' => 'pdf'];
if ($filterBranch)   { $exportQuery['branch'] = $filterBranch; }
if ($fromRaw !== '') { $exportQuery['from'] = $fromRaw; }
if ($toRaw !== '')   { $exportQuery['to'] = $toRaw; }

$pageTitle = __('salr_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('salr_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('salr_sub')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($seesAll): ?>
                <form method="get" action="sales_report.php" class="flex items-center gap-2">
                    <?php if ($fromRaw !== ''): ?><input type="hidden" name="from" value="<?= e($fromRaw) ?>"><?php endif; ?>
                    <?php if ($toRaw !== ''): ?><input type="hidden" name="to" value="<?= e($toRaw) ?>"><?php endif; ?>
                    <label class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('filter_branch')) ?>:</label>
                    <select name="branch" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value="0"><?= e(__('all_branches')) ?></option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= $filterBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <a href="sales_report.php?<?= e(http_build_query($exportQuery)) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors shadow-sm shadow-rose-600/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <?= e(__('rep_export_pdf')) ?>
            </a>
        </div>
    </div>

    <?php $reportNav = 'sales'; require __DIR__ . '/includes/report_nav.php'; ?>

    <!-- Date range filter -->
    <form method="get" action="sales_report.php" class="mt-5 flex flex-wrap items-end gap-3">
        <?php if ($filterBranch): ?><input type="hidden" name="branch" value="<?= (int) $filterBranch ?>"><?php endif; ?>
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('rep_from')) ?></label>
            <input type="date" name="from" value="<?= e($fromRaw) ?>" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('rep_to')) ?></label>
            <input type="date" name="to" value="<?= e($toRaw) ?>" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"><?= e(__('rep_apply')) ?></button>
        <?php if ($fromRaw !== '' || $toRaw !== ''): ?>
            <a href="sales_report.php<?= $filterBranch ? '?branch=' . (int) $filterBranch : '' ?>" class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('rep_reset')) ?></a>
        <?php endif; ?>
    </form>

    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_category')) ?></th>
                        <?php if ($seesAll): ?><th class="px-4 py-3 font-semibold"><?= e(__('col_branch')) ?></th><?php endif; ?>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_qty_out')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_unit_price')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_sales_value')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= $seesAll ? 6 : 5 ?>" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('rep_none')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): $val = (int) $r['qty_out'] * (float) $r['price']; ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($r['name']) ?></td>
                                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300"><?= e($r['category'] ?: '-') ?></td>
                                <?php if ($seesAll): ?><td class="px-4 py-2.5 text-gray-600 dark:text-gray-300"><?= e($r['branch'] ?: '-') ?></td><?php endif; ?>
                                <td class="px-4 py-2.5 text-right text-red-500 dark:text-red-400"><?= (int) $r['qty_out'] ?></td>
                                <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-200"><?= e(number_format((float) $r['price'], 2)) ?></td>
                                <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(number_format($val, 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 dark:bg-gray-900/50 font-bold">
                            <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="<?= $seesAll ? 3 : 2 ?>"><?= e(__('rep_total')) ?></td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= (int) $totQty ?></td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">RM <?= e(number_format($totValue, 2)) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
