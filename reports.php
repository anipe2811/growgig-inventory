<?php
/**
 * reports.php - Stock reports by day / month / year (all roles).
 * Branch-scoped: account_user sees their branch; others can filter by branch.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role       = $_SESSION['user_role'] ?? 'account_user';
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : null;
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

// Default (no single-branch filter) restriction to the acting account's branches.
// $acct is an int, so inlining the subquery is injection-safe. Empty = agency "all accounts".
$acctBranchSql = $acct ? ' branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')' : '';

$filterBranch = 0;
if ($seesAll && isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true)) {
    $filterBranch = (int) $_GET['branch'];
}
$scopeBranch = $seesAll ? $filterBranch : (int) $userBranch; // 0 = all branches

$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['day', 'month', 'year'], true)) {
    $period = 'month';
}

/* Current inventory summary (scoped). */
if ($scopeBranch) {
    $wi = 'WHERE branch_id = ?';
    $pi = [$scopeBranch];
} elseif ($acctBranchSql) {
    // No single branch chosen: limit to the acting account's branches (default no-filter path).
    $wi = 'WHERE' . $acctBranchSql;
    $pi = [];
} else {
    $wi = '';
    $pi = [];
}
$sumStmt = $pdo->prepare("SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty,
    SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) AS low,
    COALESCE(SUM(quantity * price),0) AS value FROM items $wi");
$sumStmt->execute($pi);
$sum = $sumStmt->fetch();

/* Date-range filter — the raw value format adapts to the active period:
 *   day   -> YYYY-MM-DD   month -> YYYY-MM   year -> YYYY
 * rep_date_bounds() normalises them to real start/end dates for the query. */
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw   = trim((string) ($_GET['to'] ?? ''));
[$fromDate, $toDate] = rep_date_bounds($period, $fromRaw, $toRaw);
if ($fromDate === null) { $fromRaw = ''; } // drop malformed values so inputs stay clean
if ($toDate === null)   { $toRaw = ''; }

/* Movements grouped by period. */
if ($period === 'day') {
    $sel = "DATE_FORMAT(m.movement_date,'%d/%m/%Y')";
} elseif ($period === 'year') {
    $sel = "DATE_FORMAT(m.movement_date,'%Y')";
} else {
    $sel = "DATE_FORMAT(m.movement_date,'%b %Y')";
}

$cond = []; $pm = [];
if ($scopeBranch) { $cond[] = 'm.branch_id = ?';     $pm[] = $scopeBranch; }
// Default (no single branch): restrict to the acting account's branches.
elseif ($acct)    { $cond[] = 'm.branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')'; }
if ($fromDate)    { $cond[] = 'm.movement_date >= ?'; $pm[] = $fromDate; }
if ($toDate)      { $cond[] = 'm.movement_date <= ?'; $pm[] = $toDate; }
$wm = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

$exporting = (($_GET['export'] ?? '') === 'pdf');
$limit     = $exporting ? 5000 : 60; // export grabs the full range; on-screen stays compact
$rowsStmt = $pdo->prepare(
    "SELECT $sel AS label,
            SUM(CASE WHEN m.type='in'  THEN m.quantity ELSE 0 END) AS tin,
            SUM(CASE WHEN m.type='out' THEN m.quantity ELSE 0 END) AS tout,
            MIN(m.movement_date) AS mind
     FROM stock_movements m $wm
     GROUP BY label ORDER BY mind DESC LIMIT $limit"
);
$rowsStmt->execute($pm);
$rows = $rowsStmt->fetchAll();

$grandIn = 0; $grandOut = 0;
foreach ($rows as $r) { $grandIn += (int) $r['tin']; $grandOut += (int) $r['tout']; }

/* Year bounds for the "By year" range picker (scoped so it never reveals another account's history). */
$minYearSql = 'SELECT MIN(YEAR(movement_date)) FROM stock_movements';
if ($scopeBranch) {
    $mys = $pdo->prepare($minYearSql . ' WHERE branch_id = ?');
    $mys->execute([$scopeBranch]);
    $minYear = (int) ($mys->fetchColumn() ?: date('Y'));
} elseif ($acctBranchSql) {
    $minYear = (int) ($pdo->query($minYearSql . ' WHERE' . $acctBranchSql)->fetchColumn() ?: date('Y'));
} else {
    $minYear = (int) ($pdo->query($minYearSql)->fetchColumn() ?: date('Y'));
}
$maxYear = (int) date('Y');
if ($minYear > $maxYear) { $minYear = $maxYear; }

/* PDF export — render a print-friendly page and stop before the normal layout. */
if ($exporting) {
    require __DIR__ . '/includes/print_report.php';
    $cols = [[__('rep_period'), 'l'], [__('rep_total_in'), 'r'], [__('rep_total_out'), 'r'], [__('rep_net'), 'r']];
    $body = [];
    foreach ($rows as $r) {
        $net    = (int) $r['tin'] - (int) $r['tout'];
        $body[] = [$r['label'], '+' . (int) $r['tin'], '-' . (int) $r['tout'], (string) $net];
    }
    $foot = [__('rep_net'), '+' . $grandIn, '-' . $grandOut, (string) ($grandIn - $grandOut)];

    $meta = [];
    if ($seesAll) {
        $bn = __('all_branches');
        foreach ($branches as $b) { if ((int) $b['id'] === $filterBranch) { $bn = $b['name']; break; } }
        $meta[] = __('filter_branch') . ': ' . $bn;
    }
    $periodLabel = $period === 'day' ? __('rep_by_day') : ($period === 'year' ? __('rep_by_year') : __('rep_by_month'));
    $meta[] = __('rep_period') . ': ' . $periodLabel;
    if ($fromRaw !== '' || $toRaw !== '') {
        $meta[] = __('rep_from') . ': ' . ($fromRaw ?: '—') . '   ' . __('rep_to') . ': ' . ($toRaw ?: '—');
    }
    render_print_report(__('rep_title'), $meta, print_table_html($cols, $body, $foot));
}

/* Query string used by the "Download PDF" link (preserves current filters). */
$exportQuery = ['period' => $period, 'export' => 'pdf'];
if ($filterBranch)   { $exportQuery['branch'] = $filterBranch; }
if ($fromRaw !== '') { $exportQuery['from']   = $fromRaw; }
if ($toRaw !== '')   { $exportQuery['to']     = $toRaw; }

$pageTitle = __('nav_reports');
require __DIR__ . '/includes/header.php';

function rep_tab(string $p, string $current, string $label): string
{
    $active = $p === $current;
    $cls = $active ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700';
    // Switching period resets the date filter (from/to formats differ per period).
    $href = 'reports.php?period=' . $p . (isset($_GET['branch']) ? '&branch=' . (int) $_GET['branch'] : '');
    return '<a href="' . $href . '" class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors ' . $cls . '">' . e($label) . '</a>';
}

/**
 * Normalise raw from/to filter values into real SQL date boundaries.
 * Returns [fromDate|null, toDate|null] as Y-m-d strings (or null when blank/invalid).
 */
function rep_date_bounds(string $period, string $from, string $to): array
{
    $fromDate = $toDate = null;
    if ($period === 'day') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $fromDate = $from; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $toDate   = $to; }
    } elseif ($period === 'month') {
        if (preg_match('/^\d{4}-\d{2}$/', $from)) { $fromDate = $from . '-01'; }
        if (preg_match('/^\d{4}-\d{2}$/', $to))   { $toDate   = date('Y-m-t', strtotime($to . '-01')); }
    } else { // year
        if (preg_match('/^\d{4}$/', $from)) { $fromDate = $from . '-01-01'; }
        if (preg_match('/^\d{4}$/', $to))   { $toDate   = $to . '-12-31'; }
    }
    return [$fromDate, $toDate];
}
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('rep_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('rep_subtitle')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($seesAll): ?>
                <form method="get" action="reports.php" class="flex items-center gap-2">
                    <input type="hidden" name="period" value="<?= e($period) ?>">
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
            <a href="reports.php?<?= e(http_build_query($exportQuery)) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors shadow-sm shadow-rose-600/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <?= e(__('rep_export_pdf')) ?>
            </a>
        </div>
    </div>

    <?php $reportNav = 'summary'; require __DIR__ . '/includes/report_nav.php'; ?>

    <!-- Current inventory summary -->
    <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['card_total_items', number_format((int) $sum['items']), 'bg-indigo-600'],
            ['card_total_qty',   number_format((int) $sum['qty']),   'bg-blue-600'],
            ['card_low_stock',   number_format((int) $sum['low']),   'bg-amber-500'],
            ['rep_stock_value',  'RM ' . number_format((float) $sum['value'], 2), 'bg-emerald-600'],
        ];
        foreach ($cards as [$lk, $val, $bg]): ?>
            <div class="p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400"><?= e(__($lk)) ?></p>
                    <span class="w-8 h-8 rounded-lg <?= $bg ?>"></span>
                </div>
                <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white"><?= e($val) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Period tabs + date-range filter -->
    <div class="mt-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-500 dark:text-gray-400 mr-1"><?= e(__('rep_period')) ?>:</span>
            <?= rep_tab('day', $period, __('rep_by_day')) ?>
            <?= rep_tab('month', $period, __('rep_by_month')) ?>
            <?= rep_tab('year', $period, __('rep_by_year')) ?>
        </div>

        <?php
        // Inputs adapt to the active period: date picker (day), month picker, or year select.
        $inputCls = 'rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition';
        ?>
        <form method="get" action="reports.php" class="flex items-end gap-2 flex-wrap">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <?php if ($filterBranch): ?><input type="hidden" name="branch" value="<?= (int) $filterBranch ?>"><?php endif; ?>
            <?php foreach (['from' => 'rep_from', 'to' => 'rep_to'] as $field => $labelKey):
                $val = $field === 'from' ? $fromRaw : $toRaw; ?>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__($labelKey)) ?></label>
                    <?php if ($period === 'day'): ?>
                        <input type="date" name="<?= $field ?>" value="<?= e($val) ?>" class="<?= $inputCls ?>">
                    <?php elseif ($period === 'month'): ?>
                        <input type="month" name="<?= $field ?>" value="<?= e($val) ?>" class="<?= $inputCls ?>">
                    <?php else: ?>
                        <select name="<?= $field ?>" class="<?= $inputCls ?>">
                            <option value=""><?= e(__('rep_any')) ?></option>
                            <?php for ($y = $maxYear; $y >= $minYear; $y--): ?>
                                <option value="<?= $y ?>" <?= $val === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"><?= e(__('rep_apply')) ?></button>
            <?php if ($fromRaw !== '' || $toRaw !== ''): ?>
                <a href="reports.php?period=<?= e($period) ?><?= $filterBranch ? '&branch=' . (int) $filterBranch : '' ?>"
                   class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('rep_reset')) ?></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Movements table -->
    <div class="mt-4 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('rep_period')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right text-emerald-600 dark:text-emerald-400"><?= e(__('rep_total_in')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right text-red-500 dark:text-red-400"><?= e(__('rep_total_out')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('rep_net')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$rows): ?>
                        <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('rep_none')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): $net = (int) $r['tin'] - (int) $r['tout']; ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($r['label']) ?></td>
                                <td class="px-4 py-2.5 text-right text-emerald-600 dark:text-emerald-400">+<?= (int) $r['tin'] ?></td>
                                <td class="px-4 py-2.5 text-right text-red-500 dark:text-red-400">-<?= (int) $r['tout'] ?></td>
                                <td class="px-4 py-2.5 text-right font-semibold <?= $net < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' ?>"><?= $net ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 dark:bg-gray-900/50 font-bold">
                            <td class="px-4 py-3 text-gray-900 dark:text-white"><?= e(__('rep_net')) ?></td>
                            <td class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">+<?= $grandIn ?></td>
                            <td class="px-4 py-3 text-right text-red-500 dark:text-red-400">-<?= $grandOut ?></td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= $grandIn - $grandOut ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
