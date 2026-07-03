<?php
/**
 * stockcard.php — Detailed "Stock Card" report: per item, per day, showing
 * SI (stock in), SO (stock out) and BAL (running balance) across a month,
 * with an opening "Stock In-Hand" column. Mirrors the clinic's Excel layout.
 * Exports the same matrix to a print-friendly PDF (?export=pdf).
 */
require_once __DIR__ . '/config/config.php';
require_login();
if (agency_needs_account()) { require __DIR__ . '/includes/account_gate.php'; exit; }

$role       = $_SESSION['user_role'] ?? 'account_user';
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;

$acct = current_account_id(); // non-null = restrict branch list to this account; null = agency "all accounts"

/* The stock card is always scoped to ONE branch (items are branch-specific). */
if ($seesAll) {
    if ($acct) {
        $bs = $pdo->prepare('SELECT id, name FROM branches WHERE account_id = ? ORDER BY name ASC');
        $bs->execute([$acct]);
        $branches = $bs->fetchAll();
    } else {
        $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
    }
} else {
    $bs = $pdo->prepare('SELECT id, name FROM branches WHERE id = ?');
    $bs->execute([$userBranch]);
    $branches = $bs->fetchAll();
}
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);

if ($seesAll) {
    $branch = (isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true))
        ? (int) $_GET['branch'] : (int) ($validBranchIds[0] ?? 0);
} else {
    $branch = $userBranch;
}
$branchName = '';
foreach ($branches as $b) { if ((int) $b['id'] === $branch) { $branchName = $b['name']; break; } }

/* Month (YYYY-MM). */
$monthRaw = (string) ($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) { $monthRaw = date('Y-m'); }
$start = $monthRaw . '-01';
$end   = date('Y-m-t', strtotime($start));
$daysInMonth = (int) date('t', strtotime($start));
$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) { $days[] = sprintf('%s-%02d', $monthRaw, $d); }

/* Build the matrix: each item -> opening + per-day [si, so, bal]. */
$rows = [];
if ($branch) {
    $its = $pdo->prepare('SELECT id, name, quantity FROM items WHERE branch_id = ? ORDER BY category ASC, sort_order ASC, name ASC');
    $its->execute([$branch]);
    $items = $its->fetchAll();

    // Daily in/out totals per item within the month.
    $byItem = [];
    $mv = $pdo->prepare("SELECT item_id, movement_date md, type, SUM(quantity) q
                         FROM stock_movements WHERE branch_id = ? AND movement_date BETWEEN ? AND ?
                         GROUP BY item_id, movement_date, type");
    $mv->execute([$branch, $start, $end]);
    foreach ($mv->fetchAll() as $r) {
        $byItem[(int) $r['item_id']][$r['md']][$r['type']] = (int) $r['q'];
    }

    // Net movement from month start to now -> back out current qty to the opening balance.
    $netAfter = [];
    $na = $pdo->prepare("SELECT item_id, SUM(CASE WHEN type='in' THEN quantity ELSE -quantity END) n
                         FROM stock_movements WHERE branch_id = ? AND movement_date >= ? GROUP BY item_id");
    $na->execute([$branch, $start]);
    foreach ($na->fetchAll() as $r) { $netAfter[(int) $r['item_id']] = (int) $r['n']; }

    foreach ($items as $it) {
        $id      = (int) $it['id'];
        $opening = (int) $it['quantity'] - (int) ($netAfter[$id] ?? 0);
        $running = $opening;
        $cells   = [];
        foreach ($days as $day) {
            $si = (int) ($byItem[$id][$day]['in'] ?? 0);
            $so = (int) ($byItem[$id][$day]['out'] ?? 0);
            $running += $si - $so;
            $cells[] = ['si' => $si, 'so' => $so, 'bal' => $running];
        }
        $rows[] = ['name' => $it['name'], 'opening' => $opening, 'cells' => $cells];
    }
}

/* ---- PDF export (print-friendly page → browser "Save as PDF") ---- */
if (($_GET['export'] ?? '') === 'pdf') {
    require __DIR__ . '/includes/print_report.php';

    // Keep only days that had movement so the wide matrix stays readable in print.
    $activeDays = [];
    foreach ($days as $di => $day) {
        foreach ($rows as $row) {
            if (($row['cells'][$di]['si'] ?? 0) || ($row['cells'][$di]['so'] ?? 0)) { $activeDays[] = $di; break; }
        }
    }

    if (!$rows) {
        $tbl = '<p style="margin-top:16px;color:#6b7280">' . e(__('inv_empty')) . '</p>';
    } else {
        // A month with no movement has no per-day columns; show a single dated
        // balance snapshot ("as of" today, or month-end for a past month) so the
        // Stock Card is never dateless.
        $noMove = !$activeDays;
        $today  = date('Y-m-d');
        $asOf   = ($today >= $start && $today <= $end) ? $today : $end;
        $asOfL  = e(date('d/m/Y', strtotime($asOf)));

        $tbl = '<table class="rpt"><thead>';
        if ($noMove) {
            $tbl .= '<tr><th>' . e(__('sc_item')) . '</th><th class="r">' . e(__('sc_inhand')) . ' (' . $asOfL . ')</th></tr>';
        } else {
            $tbl .= '<tr><th rowspan="2">' . e(__('sc_item')) . '</th><th rowspan="2" class="r">' . e(__('sc_inhand')) . '</th>';
            foreach ($activeDays as $di) { $tbl .= '<th colspan="3" class="c">' . e(date('d/m', strtotime($days[$di]))) . '</th>'; }
            $tbl .= '</tr><tr>';
            foreach ($activeDays as $di) { $tbl .= '<th class="c">SI</th><th class="c">SO</th><th class="c">BAL</th>'; }
            $tbl .= '</tr>';
        }
        $tbl .= '</thead><tbody>';
        foreach ($rows as $row) {
            $openZ = (int) $row['opening'] <= 0 ? ' z' : '';
            $tbl .= '<tr><td>' . e($row['name']) . '</td><td class="r' . $openZ . '">' . (int) $row['opening'] . '</td>';
            foreach ($activeDays as $di) {
                $c    = $row['cells'][$di];
                $balZ = (int) $c['bal'] <= 0 ? ' z' : '';
                $tbl .= '<td class="c si">' . ($c['si'] ?: '') . '</td><td class="c so">' . ($c['so'] ?: '') . '</td><td class="c' . $balZ . '">' . (int) $c['bal'] . '</td>';
            }
            $tbl .= '</tr>';
        }
        $tbl .= '</tbody></table>';
    }

    $meta = [
        __('label_branch') . ': ' . ($branchName ?: '—'),
        __('sc_month') . ': ' . date('m/Y', strtotime($start)),
    ];
    render_print_report(__('sc_title'), $meta, $tbl, 'landscape');
}

/* Export link preserving current filters. */
$exportQuery = ['export' => 'pdf', 'month' => $monthRaw];
if ($branch) { $exportQuery['branch'] = $branch; }

$pageTitle = __('sc_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('sc_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('sc_sub')) ?></p>
        </div>
        <a href="stockcard.php?<?= e(http_build_query($exportQuery)) ?>" target="_blank" rel="noopener"
           class="inline-flex items-center gap-2 self-start rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors shadow-sm shadow-rose-600/20">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            <?= e(__('rep_export_pdf')) ?>
        </a>
    </div>

    <?php $reportNav = 'stockcard'; require __DIR__ . '/includes/report_nav.php'; ?>

    <!-- Filters -->
    <form method="get" action="stockcard.php" class="mt-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('sc_month')) ?></label>
            <input type="month" name="month" value="<?= e($monthRaw) ?>" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
        </div>
        <?php if ($seesAll): ?>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('label_branch')) ?></label>
                <select name="branch" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $branch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"><?= e(__('rep_apply')) ?></button>
    </form>

    <!-- Matrix -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <?php if (!$rows): ?>
                <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400"><?= e(__('inv_empty')) ?></p>
            <?php else: ?>
                <table class="text-xs border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900/60">
                            <th rowspan="2" class="sticky left-0 z-10 bg-gray-50 dark:bg-gray-900/60 px-3 py-2 text-left font-semibold text-gray-600 dark:text-gray-300 border-b border-r border-gray-200 dark:border-gray-700 min-w-[160px]"><?= e(__('sc_item')) ?></th>
                            <th rowspan="2" class="px-3 py-2 text-right font-semibold text-gray-600 dark:text-gray-300 border-b border-r border-gray-200 dark:border-gray-700"><?= e(__('sc_inhand')) ?></th>
                            <?php foreach ($days as $i => $day): ?>
                                <th colspan="3" class="px-2 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300 border-b border-r border-gray-200 dark:border-gray-700 whitespace-nowrap <?= $i % 2 ? 'bg-gray-50 dark:bg-gray-900/40' : 'bg-indigo-50/50 dark:bg-indigo-900/10' ?>"><?= e(date('d/m', strtotime($day))) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="bg-gray-50 dark:bg-gray-900/60 text-[10px]">
                            <?php foreach ($days as $i => $day): $tint = $i % 2 ? '' : 'bg-indigo-50/40 dark:bg-indigo-900/10'; ?>
                                <th class="px-2 py-1 text-center font-semibold text-emerald-600 dark:text-emerald-400 border-b border-gray-200 dark:border-gray-700 <?= $tint ?>">SI</th>
                                <th class="px-2 py-1 text-center font-semibold text-red-500 dark:text-red-400 border-b border-gray-200 dark:border-gray-700 <?= $tint ?>">SO</th>
                                <th class="px-2 py-1 text-center font-semibold text-gray-600 dark:text-gray-300 border-b border-r border-gray-200 dark:border-gray-700 <?= $tint ?>">BAL</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                <td class="sticky left-0 z-10 bg-white dark:bg-gray-800 px-3 py-1.5 font-medium text-gray-900 dark:text-white border-b border-r border-gray-100 dark:border-gray-700 whitespace-nowrap"><?= e($row['name']) ?></td>
                                <td class="px-3 py-1.5 text-right text-gray-700 dark:text-gray-200 border-b border-r border-gray-100 dark:border-gray-700"><?= (int) $row['opening'] ?></td>
                                <?php foreach ($row['cells'] as $c): $zero = $c['bal'] <= 0; ?>
                                    <td class="px-2 py-1.5 text-center text-emerald-600 dark:text-emerald-400 border-b border-gray-100 dark:border-gray-700"><?= $c['si'] ?: '' ?></td>
                                    <td class="px-2 py-1.5 text-center text-red-500 dark:text-red-400 border-b border-gray-100 dark:border-gray-700"><?= $c['so'] ?: '' ?></td>
                                    <td class="px-2 py-1.5 text-center font-semibold border-b border-r border-gray-100 dark:border-gray-700 <?= $zero ? 'bg-red-500 text-white' : 'text-gray-900 dark:text-white' ?>"><?= (int) $c['bal'] ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <p class="mt-3 text-xs text-gray-400">SI = <?= e(__('col_stock_in')) ?> &middot; SO = <?= e(__('col_stock_out')) ?> &middot; BAL = <?= e(__('col_balance')) ?></p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
