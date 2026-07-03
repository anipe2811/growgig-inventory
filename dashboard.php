<?php
/**
 * dashboard.php — Unified secure dashboard (role-aware), with order analytics.
 */
require_once __DIR__ . '/config/config.php';
require_login();
if (agency_needs_account()) { require __DIR__ . '/includes/account_gate.php'; exit; }

$role     = $_SESSION['user_role'] ?? 'account_user';
$userName = $_SESSION['user_name'] ?? '';
$seesAll  = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : null;
$acct     = current_account_id(); // non-null = restrict stats to this account; null = agency "all accounts" (global)

// When an account is being acted on, limit every "all branches" aggregate to that
// account's branches. $acct is an int, so inlining the subquery is injection-safe.
$acctItemsWhere = $acct ? ' WHERE branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')' : '';
$acctItemsAnd   = $acct ? ' AND branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')' : '';

/* Live inventory statistics — scoped to the user's branch when applicable. */
$totalBranches  = 0;
$userBranchName = '';
if ($seesAll) {
    $totalItems    = (int) $pdo->query('SELECT COUNT(*) FROM items' . $acctItemsWhere)->fetchColumn();
    $totalQty      = (int) $pdo->query('SELECT COALESCE(SUM(quantity),0) FROM items' . $acctItemsWhere)->fetchColumn();
    $lowStock      = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level' . $acctItemsAnd)->fetchColumn();
    $totalBranches = (int) $pdo->query('SELECT COUNT(*) FROM branches' . ($acct ? ' WHERE account_id = ' . (int) $acct : ''))->fetchColumn();
} else {
    $s = $pdo->prepare('SELECT COUNT(*) FROM items WHERE branch_id = ?');                       $s->execute([$userBranch]); $totalItems = (int) $s->fetchColumn();
    $s = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM items WHERE branch_id = ?');       $s->execute([$userBranch]); $totalQty   = (int) $s->fetchColumn();
    $s = $pdo->prepare('SELECT COUNT(*) FROM items WHERE branch_id = ? AND quantity <= reorder_level'); $s->execute([$userBranch]); $lowStock = (int) $s->fetchColumn();
    $s = $pdo->prepare('SELECT name FROM branches WHERE id = ?');                                $s->execute([$userBranch]); $userBranchName = (string) ($s->fetchColumn() ?: '');
}

/* Agency-admin-only metric. When acting on a specific account, count only that
 * account's users; agency "all accounts" (null) keeps the global count. */
$totalUsers = 0;
if (role_is_super($role)) {
    if ($acct) {
        $us = $pdo->prepare('SELECT COUNT(*) FROM users WHERE account_id = ?');
        $us->execute([$acct]);
        $totalUsers = (int) $us->fetchColumn();
    } else {
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}

/* ---- Order analytics (scoped) for the charts + KPI chips. ---- */
if (!$seesAll) {
    $ordWhere  = 'WHERE branch_id = ?';
    $ordParams = [$userBranch];
} elseif ($acct) {
    // Default (all branches) but acting on an account: limit to that account's branches.
    $ordWhere  = 'WHERE branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')';
    $ordParams = [];
} else {
    $ordWhere  = '';
    $ordParams = [];
}
$statusCounts = ['requested' => 0, 'pending' => 0, 'delivered' => 0, 'forwarded' => 0, 'received' => 0, 'rejected' => 0, 'cancelled' => 0];
try {
    $q = $pdo->prepare("SELECT status, COUNT(*) c FROM purchase_orders $ordWhere GROUP BY status");
    $q->execute($ordParams);
    foreach ($q->fetchAll() as $r) { if (isset($statusCounts[$r['status']])) { $statusCounts[$r['status']] = (int) $r['c']; } }
} catch (Throwable $e) { /* table optional */ }
$ordersTotal = array_sum($statusCounts);

$statusOrder  = ['requested', 'pending', 'delivered', 'forwarded', 'received', 'rejected', 'cancelled'];
$statusLabels = array_map(static fn($s) => __('status_' . $s), $statusOrder);
$statusData   = array_map(static fn($s) => $statusCounts[$s], $statusOrder);

/* 6-month stock-in / stock-out trend (scoped). */
$months = [];
for ($i = 5; $i >= 0; $i--) { $months[] = date('Y-m', strtotime("first day of -$i month")); }
$inByMonth  = array_fill_keys($months, 0);
$outByMonth = array_fill_keys($months, 0);
try {
    if (!$seesAll) {
        $mvWhere  = 'AND branch_id = ?';
        $mvParams = [$userBranch];
    } elseif ($acct) {
        $mvWhere  = 'AND branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')';
        $mvParams = [];
    } else {
        $mvWhere  = '';
        $mvParams = [];
    }
    $rows = $pdo->prepare("SELECT DATE_FORMAT(movement_date,'%Y-%m') ym, type, SUM(quantity) q FROM stock_movements WHERE movement_date >= ? $mvWhere GROUP BY ym, type");
    $rows->execute(array_merge([$months[0] . '-01'], $mvParams));
    foreach ($rows->fetchAll() as $r) {
        if (!array_key_exists($r['ym'], $inByMonth)) { continue; }
        if ($r['type'] === 'in') { $inByMonth[$r['ym']] = (int) $r['q']; } else { $outByMonth[$r['ym']] = (int) $r['q']; }
    }
} catch (Throwable $e) { /* table optional */ }
$monthLabels = array_map(static fn($m) => date('M', strtotime($m . '-01')), $months);

/* Role badge colours. */
$roleStyles = [
    'agency_admin'  => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    'agency_user'   => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    'account_admin' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    'account_user'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
];
$roleClass = $roleStyles[$role] ?? $roleStyles['account_user'];

$pageTitle = __('nav_dashboard');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <!-- Hero greeting -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-purple-600 px-6 sm:px-8 py-7 sm:py-9 shadow-xl shadow-indigo-600/20">
        <div class="absolute -top-12 -right-10 w-52 h-52 rounded-full bg-white/10 blur-2xl"></div>
        <div class="absolute -bottom-16 -left-10 w-52 h-52 rounded-full bg-fuchsia-400/10 blur-2xl"></div>
        <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                    <?= e(__('welcome_back')) ?>, <?= e($userName) ?> 👋
                </h1>
                <p class="mt-1 text-sm text-indigo-100"><?= e(__('dashboard_overview')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm text-indigo-100"><?= e(__('your_role')) ?>:</span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-white/15 text-white ring-1 ring-white/30 backdrop-blur">
                    <?= e(role_label($role)) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Role scope banner -->
    <div class="mt-5 flex items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/70 dark:border-indigo-900/50 dark:bg-indigo-900/20 px-4 py-3">
        <svg class="w-5 h-5 mt-0.5 text-indigo-600 dark:text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-sm text-indigo-800 dark:text-indigo-200">
            <span class="font-semibold"><?= e(__('access_level')) ?>: <?= e(role_label($role)) ?></span>
            <span class="text-indigo-400">&middot;</span> <?= e(__('role_desc_' . $role)) ?>
            <?php if (!$seesAll && $userBranchName): ?>
                <span class="font-semibold">(<?= e(__('your_branch')) ?>: <?= e($userBranchName) ?>)</span>
            <?php endif; ?>
        </p>
    </div>

    <?php $subState = subscription_state(); if (!role_is_agency($role) && $subState['status'] !== 'active'): $isFz = $subState['frozen']; ?>
        <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 rounded-2xl border px-4 py-3 <?= $isFz ? 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-900/20' : 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-900/20' ?>">
            <p class="text-sm font-medium <?= $isFz ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-200' ?>">
                <?php if ($isFz): ?><?= e(__('stock_frozen')) ?><?php else: ?><?= e(__('status_trial')) ?>: <strong><?= (int) $subState['days_left'] ?> <?= e(__('bill_days_left')) ?></strong><?php endif; ?>
            </p>
            <?php if (role_can_manage_users($role)): ?>
                <a href="users.php" class="text-sm font-semibold underline shrink-0 <?= $isFz ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-200' ?>"><?= e(__('bill_upgrade')) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Inventory stat cards -->
    <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $stats = [
            ['card_total_items', $totalItems, 'from-indigo-500 to-violet-600', 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['card_total_qty',   $totalQty,   'from-blue-500 to-cyan-600',     'M9 17v-6h13M9 5h13M3 5h.01M3 12h.01M3 19h.01'],
            ['card_low_stock',   $lowStock,   'from-amber-500 to-orange-600',  'M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z'],
        ];
        if ($seesAll) {
            $stats[] = ['card_branches', $totalBranches, 'from-teal-500 to-emerald-600', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m2-14h2m-2 4h2m6-4h2m-2 4h2m-6 8h4v-4h-4v4z'];
        }
        if (role_is_super($role)) {
            $stats[] = ['card_total_users', $totalUsers, 'from-purple-500 to-fuchsia-600', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8z'];
        }
        foreach ($stats as [$labelKey, $value, $grad, $icon]): ?>
            <div class="p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400"><?= e(__($labelKey)) ?></p>
                    <span class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> text-white flex items-center justify-center shadow-md ring-1 ring-black/5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/></svg>
                    </span>
                </div>
                <p class="mt-3 text-3xl font-extrabold text-gray-900 dark:text-white"><?= e(number_format($value)) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Order activity KPIs -->
    <div class="mt-8">
        <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= e(__('dash_activity')) ?></h2>
        <div class="mt-3 grid gap-4 grid-cols-2 lg:grid-cols-4">
            <?php
            $kpis = [
                ['dash_kpi_total',    $ordersTotal,                 'text-indigo-600 dark:text-indigo-400'],
                ['dash_kpi_requests', $statusCounts['requested'],   'text-blue-600 dark:text-blue-400'],
                ['dash_kpi_pending',  $statusCounts['pending'],     'text-amber-600 dark:text-amber-400'],
                ['dash_kpi_received', $statusCounts['received'],     'text-emerald-600 dark:text-emerald-400'],
            ];
            foreach ($kpis as [$lk, $val, $tc]): ?>
                <div class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?= e(__($lk)) ?></p>
                    <p class="mt-1 text-2xl font-extrabold <?= $tc ?>"><?= e(number_format($val)) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Charts -->
    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <div class="p-5 sm:p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm">
            <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('dash_chart_orders')) ?></h3>
            <div class="mt-4 h-64">
                <?php if ($ordersTotal > 0): ?>
                    <canvas id="ordersStatusChart"></canvas>
                <?php else: ?>
                    <div class="h-full flex items-center justify-center text-sm text-gray-400"><?= e(__('ord_none')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-5 sm:p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm">
            <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('dash_chart_stock')) ?></h3>
            <div class="mt-4 h-64"><canvas id="stockTrendChart"></canvas></div>
        </div>
    </div>

    <!-- Quick action cards -->
    <div class="mt-8 grid gap-5 sm:grid-cols-2">
        <a href="inventory.php" class="group relative overflow-hidden p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-0.5 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('card_inventory_cta')) ?></h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('card_inventory_desc')) ?></p>
                </div>
                <span class="text-indigo-600 dark:text-indigo-400 group-hover:translate-x-1 transition-transform">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </span>
            </div>
        </a>
        <a href="profile.php" class="group relative overflow-hidden p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200/70 dark:border-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-0.5 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('card_profile_cta')) ?></h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('card_profile_desc')) ?></p>
                </div>
                <span class="text-indigo-600 dark:text-indigo-400 group-hover:translate-x-1 transition-transform">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </span>
            </div>
        </a>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var dark = document.documentElement.classList.contains('dark');
    Chart.defaults.color = dark ? '#9ca3af' : '#6b7280';
    Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    var gridc = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

    var statusEl = document.getElementById('ordersStatusChart');
    if (statusEl) {
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{ data: <?= json_encode($statusData) ?>, backgroundColor: ['#3b82f6', '#f59e0b', '#0ea5e9', '#8b5cf6', '#10b981', '#ef4444', '#9ca3af'], borderWidth: 0, hoverOffset: 6 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } } }
        });
    }

    var stockEl = document.getElementById('stockTrendChart');
    if (stockEl) {
        new Chart(stockEl, {
            type: 'bar',
            data: {
                labels: <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    { label: <?= json_encode(__('col_stock_in'), JSON_UNESCAPED_UNICODE) ?>,  data: <?= json_encode(array_values($inByMonth)) ?>,  backgroundColor: '#10b981', borderRadius: 6, maxBarThickness: 18 },
                    { label: <?= json_encode(__('col_stock_out'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode(array_values($outByMonth)) ?>, backgroundColor: '#ef4444', borderRadius: 6, maxBarThickness: 18 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: gridc }, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } } }
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
