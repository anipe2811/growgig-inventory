<?php
/**
 * stock.php — Daily Stock In / Stock Out entry (movements).
 *
 * Admin keys in IN (SI) and OUT (SO) quantities per item; the system updates
 * each item's balance and logs every movement. Item names are NOT editable here.
 * Branch scope follows the same rules as inventory.php.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role       = $_SESSION['user_role'] ?? 'account_user';
// Stock In/Out is keyed in by the branch operator only (account_user).
// Everyone else (account_admin, agency_*) has read-only access here.
// After the free trial ends (or subscription frozen), stock entry is locked for account roles.
$frozen     = stock_frozen_for($role);
$canEdit    = ($role === 'account_user') && !$frozen;
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : null;

/* Branches available to this user. */
if ($seesAll) {
    $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
} elseif ($userBranch) {
    $st = $pdo->prepare('SELECT id, name FROM branches WHERE id = ?');
    $st->execute([$userBranch]);
    $branches = $st->fetchAll();
} else {
    $branches = [];
}
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);
$noBranch       = !$seesAll && !$branches;

/* Selected branch (all-branch roles can switch; account_user is fixed). */
if ($seesAll) {
    $selectedBranch = (isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true))
        ? (int) $_GET['branch']
        : (int) ($validBranchIds[0] ?? 0);
} else {
    $selectedBranch = $userBranch;
}
$selectedBranchName = '';
foreach ($branches as $b) {
    if ((int) $b['id'] === $selectedBranch) { $selectedBranchName = $b['name']; break; }
}

/* -------------------------------------------------------------------------
 * Apply movements (POST).
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($frozen) {
        header('Location: stock.php?msg=frozen');
        exit;
    }
    if (!$canEdit || $noBranch || !csrf_verify()) {
        header('Location: stock.php?msg=denied');
        exit;
    }
    $branchId = $seesAll ? (int) ($_POST['branch_id'] ?? 0) : $userBranch;
    if (!in_array($branchId, $validBranchIds, true)) {
        header('Location: stock.php?msg=denied');
        exit;
    }

    /* Edit one movement's quantity (fix a wrong entry); rebalances the item's ledger. */
    if (($_POST['action'] ?? '') === 'edit_movement') {
        $mid    = (int) ($_POST['mv_id'] ?? 0);
        $newQty = (int) ($_POST['mv_qty'] ?? 0);
        if ($mid > 0 && $newQty > 0) {
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare('SELECT id, item_id, type, quantity FROM stock_movements WHERE id = ? AND branch_id = ? FOR UPDATE');
                $sel->execute([$mid, $branchId]);
                $row = $sel->fetch();
                if ($row && (int) $row['quantity'] !== $newQty) {
                    $iid    = (int) $row['item_id'];
                    $signed = ($row['type'] === 'in' ? 1 : -1) * ($newQty - (int) $row['quantity']);
                    $pdo->prepare('UPDATE stock_movements SET quantity = ? WHERE id = ?')->execute([$newQty, $mid]);
                    $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ? AND branch_id = ?')->execute([$signed, $iid, $branchId]);
                    // Rebuild balance_after for the item's whole ledger (opening = current − net change).
                    $q = $pdo->prepare('SELECT quantity FROM items WHERE id = ? AND branch_id = ?');
                    $q->execute([$iid, $branchId]);
                    $running = (int) $q->fetchColumn();
                    $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='in' THEN quantity ELSE -quantity END),0) FROM stock_movements WHERE item_id = ? AND branch_id = ?");
                    $q->execute([$iid, $branchId]);
                    $running -= (int) $q->fetchColumn(); // opening balance
                    $rows = $pdo->prepare('SELECT id, type, quantity FROM stock_movements WHERE item_id = ? AND branch_id = ? ORDER BY id ASC');
                    $rows->execute([$iid, $branchId]);
                    $ub = $pdo->prepare('UPDATE stock_movements SET balance_after = ? WHERE id = ?');
                    foreach ($rows->fetchAll() as $m) {
                        $running += ($m['type'] === 'in' ? 1 : -1) * (int) $m['quantity'];
                        $ub->execute([$running, (int) $m['id']]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); }
        }
        // Preserve the history filter on redirect so the user stays on the same view.
        $hp = in_array($_POST['hp'] ?? '', ['day', 'month', 'year'], true) ? $_POST['hp'] : 'day';
        $hv = preg_replace('/[^0-9\-]/', '', (string) ($_POST['hv'] ?? ''));
        $hn = in_array($_POST['hn'] ?? '', ['10', '100', 'all'], true) ? $_POST['hn'] : '10';
        header('Location: stock.php?hp=' . $hp . '&hv=' . urlencode($hv) . '&hn=' . $hn . '&msg=mv_edited');
        exit;
    }

    /* Delete one movement (remove a wrong entry); reverses its effect and rebalances the ledger. */
    if (($_POST['action'] ?? '') === 'delete_movement') {
        $mid = (int) ($_POST['mv_id'] ?? 0);
        if ($mid > 0) {
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare('SELECT id, item_id, type, quantity FROM stock_movements WHERE id = ? AND branch_id = ? FOR UPDATE');
                $sel->execute([$mid, $branchId]);
                $row = $sel->fetch();
                if ($row) {
                    $iid    = (int) $row['item_id'];
                    // Reverse this movement's effect on the item balance, then drop the row.
                    $revert = ($row['type'] === 'in' ? -1 : 1) * (int) $row['quantity'];
                    $pdo->prepare('DELETE FROM stock_movements WHERE id = ?')->execute([$mid]);
                    $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ? AND branch_id = ?')->execute([$revert, $iid, $branchId]);
                    // Rebuild balance_after for the item's remaining ledger (opening = current − net change).
                    $q = $pdo->prepare('SELECT quantity FROM items WHERE id = ? AND branch_id = ?');
                    $q->execute([$iid, $branchId]);
                    $running = (int) $q->fetchColumn();
                    $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='in' THEN quantity ELSE -quantity END),0) FROM stock_movements WHERE item_id = ? AND branch_id = ?");
                    $q->execute([$iid, $branchId]);
                    $running -= (int) $q->fetchColumn(); // opening balance
                    $rows = $pdo->prepare('SELECT id, type, quantity FROM stock_movements WHERE item_id = ? AND branch_id = ? ORDER BY id ASC');
                    $rows->execute([$iid, $branchId]);
                    $ub = $pdo->prepare('UPDATE stock_movements SET balance_after = ? WHERE id = ?');
                    foreach ($rows->fetchAll() as $m) {
                        $running += ($m['type'] === 'in' ? 1 : -1) * (int) $m['quantity'];
                        $ub->execute([$running, (int) $m['id']]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); }
        }
        $hp = in_array($_POST['hp'] ?? '', ['day', 'month', 'year'], true) ? $_POST['hp'] : 'day';
        $hv = preg_replace('/[^0-9\-]/', '', (string) ($_POST['hv'] ?? ''));
        $hn = in_array($_POST['hn'] ?? '', ['10', '100', 'all'], true) ? $_POST['hn'] : '10';
        header('Location: stock.php?hp=' . $hp . '&hv=' . urlencode($hv) . '&hn=' . $hn . '&msg=mv_deleted');
        exit;
    }

    // Validate the movement date (default: today).
    $dateIn = (string) ($_POST['movement_date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $dateIn);
    $movementDate = ($d && $d->format('Y-m-d') === $dateIn) ? $dateIn : date('Y-m-d');

    $si = is_array($_POST['si'] ?? null) ? $_POST['si'] : [];
    $so = is_array($_POST['so'] ?? null) ? $_POST['so'] : [];

    // Current quantities for items in this branch (authoritative set of ids).
    $istmt = $pdo->prepare('SELECT id, quantity FROM items WHERE branch_id = ?');
    $istmt->execute([$branchId]);
    $current = [];
    foreach ($istmt->fetchAll() as $r) { $current[(int) $r['id']] = (int) $r['quantity']; }

    $mv  = $pdo->prepare('INSERT INTO stock_movements (item_id, branch_id, type, quantity, balance_after, movement_date, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE items SET quantity = ? WHERE id = ? AND branch_id = ?');

    $applied = 0;
    $pdo->beginTransaction();
    try {
        foreach ($current as $id => $qty) {
            $in  = max(0, (int) ($si[$id] ?? 0));
            $out = max(0, (int) ($so[$id] ?? 0));
            if ($in === 0 && $out === 0) { continue; }
            $bal = $qty;
            if ($in > 0)  { $bal += $in;  $mv->execute([$id, $branchId, 'in',  $in,  $bal, $movementDate, $_SESSION['user_id']]); }
            if ($out > 0) { $bal -= $out; $mv->execute([$id, $branchId, 'out', $out, $bal, $movementDate, $_SESSION['user_id']]); }
            $upd->execute([$bal, $id, $branchId]);
            $applied++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        header('Location: stock.php?' . ($seesAll ? 'branch=' . $branchId . '&' : '') . 'msg=denied');
        exit;
    }
    $q = $seesAll ? ('branch=' . $branchId . '&') : '';
    header('Location: stock.php?' . $q . 'msg=' . ($applied > 0 ? 'saved' : 'none'));
    exit;
}

/* Items for the selected branch (custom order + grouping). */
$items = [];
if (!$noBranch && $selectedBranch) {
    $st = $pdo->prepare('SELECT * FROM items WHERE branch_id = ? ORDER BY category ASC, sort_order ASC, name ASC');
    $st->execute([$selectedBranch]);
    $items = $st->fetchAll();
}

/* Recent Movements — filter by day / month / year (default: by day, today). */
$histPeriod = in_array($_GET['hp'] ?? '', ['day', 'month', 'year'], true) ? $_GET['hp'] : 'day';
$histRaw    = trim((string) ($_GET['hv'] ?? ''));
if ($histPeriod === 'day') {
    $histVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $histRaw) ? $histRaw : date('Y-m-d');
    $hFrom   = $hTo = $histVal;
} elseif ($histPeriod === 'month') {
    $histVal = preg_match('/^\d{4}-\d{2}$/', $histRaw) ? $histRaw : date('Y-m');
    $hFrom   = $histVal . '-01';
    $hTo     = date('Y-m-t', strtotime($hFrom));
} else { // year
    $histVal = preg_match('/^\d{4}$/', $histRaw) ? $histRaw : date('Y');
    $hFrom   = $histVal . '-01-01';
    $hTo     = $histVal . '-12-31';
}

/* How many recent rows to show: 10 (default) | 100 | all. */
$histCount = in_array($_GET['hn'] ?? '', ['10', '100', 'all'], true) ? $_GET['hn'] : '10';
$histLimit = $histCount === 'all' ? 100000 : (int) $histCount;

$history = [];
if (!$noBranch && $selectedBranch) {
    $st = $pdo->prepare(
        'SELECT m.id, m.item_id, m.type, m.quantity, m.balance_after, m.movement_date, i.name
         FROM stock_movements m JOIN items i ON i.id = m.item_id
         WHERE m.branch_id = ? AND m.movement_date BETWEEN ? AND ?
         ORDER BY m.id DESC LIMIT ' . $histLimit
    );
    $st->execute([$selectedBranch, $hFrom, $hTo]);
    $history = $st->fetchAll();
}

$flashMap = [
    'saved'      => ['stock_saved',      'green'],
    'none'       => ['stock_nothing',    'red'],
    'mv_edited'  => ['stock_mv_edited',   'green'],
    'mv_deleted' => ['stock_mv_deleted',  'green'],
    'denied'     => ['inv_not_allowed',  'red'],
    'frozen'     => ['stock_frozen',     'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('nav_stock');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('nav_stock')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('stock_subtitle')) ?></p>
        </div>
        <div class="flex items-center gap-2">
            <?php if (!$canEdit): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <?= e(__('readonly')) ?>
                </span>
            <?php endif; ?>
            <?php if (!$seesAll && $selectedBranchName): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                    <?= e(__('your_branch')) ?>: <?= e($selectedBranchName) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php [$fk, $fc] = $flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm
            <?= $fc === 'green'
                ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
                : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>">
            <?= e(__($fk)) ?>
        </div>
    <?php endif; ?>

    <?php if ($frozen): ?>
        <div class="mt-5 rounded-xl border border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-900/20 px-4 py-4 flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
            <div>
                <p class="text-sm font-semibold text-red-700 dark:text-red-300"><?= e(__('stock_frozen')) ?></p>
                <?php if (role_can_manage_users($role)): ?>
                    <a href="users.php" class="mt-1 inline-block text-sm font-semibold text-red-700 dark:text-red-300 underline"><?= e(__('bill_upgrade')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($noBranch): ?>
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-900/20 px-4 py-4 text-sm text-amber-800 dark:text-amber-200">
            <?= e(__('no_branch')) ?>
        </div>
    <?php else: ?>

        <!-- Branch switcher (all-branch roles) -->
        <?php if ($seesAll): ?>
            <form method="get" action="stock.php" class="mt-6 flex items-center gap-2">
                <label for="branch" class="text-sm font-medium text-gray-600 dark:text-gray-400"><?= e(__('label_branch')) ?>:</label>
                <select id="branch" name="branch" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $selectedBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <!-- Add Stock In/Out toggle (collapsed by default for the branch operator) -->
        <?php if ($canEdit): ?>
            <div class="mt-6">
                <button type="button" id="toggleStockEntry" onclick="toggleStockEntry()"
                        data-open="<?= e(__('stock_add_btn')) ?>" data-close="<?= e(__('btn_cancel')) ?>"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span id="toggleStockLabel"><?= e(__('stock_add_btn')) ?></span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Stock movement table — editable for account_user; read-only with date filter for others -->
        <form method="<?= $canEdit ? 'post' : 'get' ?>" action="stock.php" class="mt-2">
            <?php if ($canEdit): ?>
                <?= csrf_field() ?>
                <input type="hidden" name="branch_id" value="<?= (int) $selectedBranch ?>">
            <?php else: ?>
                <input type="hidden" name="branch" value="<?= (int) $selectedBranch ?>">
            <?php endif; ?>

            <div id="stockEntryCard" class="<?= $canEdit ? 'hidden ' : '' ?>bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 transition-colors">
                <?php if ($canEdit): ?>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <label for="movement_date" class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= e(__('label_date')) ?>:</label>
                        <input type="date" id="movement_date" name="movement_date" value="<?= e(date('Y-m-d')) ?>"
                               class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                        <?= e(__('btn_apply')) ?>
                    </button>
                </div>
                <?php endif; ?>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_balance')) ?></th>
                                <th class="px-4 py-3 font-semibold text-center text-green-600 dark:text-green-400"><?= e(__('col_stock_in')) ?></th>
                                <th class="px-4 py-3 font-semibold text-center text-red-600 dark:text-red-400"><?= e(__('col_stock_out')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_new_balance')) ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!$items): ?>
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('inv_empty')) ?></td></tr>
                            <?php else: ?>
                                <?php $lastGroup = null; ?>
                                <?php foreach ($items as $item):
                                    $bal = (int) $item['quantity'];
                                    $cat = trim((string) ($item['category'] ?? ''));
                                    if ($cat !== $lastGroup): $lastGroup = $cat; ?>
                                        <tr class="bg-gray-100/80 dark:bg-gray-900/60">
                                            <td colspan="5" class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                <?= e($cat !== '' ? $cat : __('uncategorized')) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($item['name']) ?></td>
                                        <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300 cur-bal" data-bal="<?= $bal ?>"><?= e($bal) ?></td>
                                        <td class="px-4 py-2.5 text-center">
                                            <input type="number" min="0" name="si[<?= (int) $item['id'] ?>]" placeholder="0" <?= $canEdit ? '' : 'disabled' ?>
                                                   class="si-in w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-green-500 outline-none transition disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed dark:disabled:bg-gray-800/60">
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            <input type="number" min="0" name="so[<?= (int) $item['id'] ?>]" placeholder="0" <?= $canEdit ? '' : 'disabled' ?>
                                                   class="so-in w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-red-500 outline-none transition disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed dark:disabled:bg-gray-800/60">
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white new-bal"><?= e($bal) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- Recent movements — filter by day / month / year (default: today) -->
        <div class="mt-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                <h2 class="font-semibold text-gray-900 dark:text-white"><?= e(__('stock_history')) ?></h2>
                <form method="get" action="stock.php" class="flex flex-wrap items-center gap-2">
                    <?php if ($seesAll): ?><input type="hidden" name="branch" value="<?= (int) $selectedBranch ?>"><?php endif; ?>
                    <select name="hp" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value="day"   <?= $histPeriod === 'day'   ? 'selected' : '' ?>><?= e(__('rep_by_day')) ?></option>
                        <option value="month" <?= $histPeriod === 'month' ? 'selected' : '' ?>><?= e(__('rep_by_month')) ?></option>
                        <option value="year"  <?= $histPeriod === 'year'  ? 'selected' : '' ?>><?= e(__('rep_by_year')) ?></option>
                    </select>
                    <?php if ($histPeriod === 'day'): ?>
                        <input type="date" name="hv" value="<?= e($histVal) ?>" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php elseif ($histPeriod === 'month'): ?>
                        <input type="month" name="hv" value="<?= e($histVal) ?>" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php else: ?>
                        <input type="number" name="hv" value="<?= e($histVal) ?>" min="2000" max="2100" onchange="this.form.submit()" class="w-28 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php endif; ?>
                    <select name="hn" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value="10"  <?= $histCount === '10'  ? 'selected' : '' ?>><?= e(__('opt_show')) ?> 10</option>
                        <option value="100" <?= $histCount === '100' ? 'selected' : '' ?>><?= e(__('opt_show')) ?> 100</option>
                        <option value="all" <?= $histCount === 'all' ? 'selected' : '' ?>><?= e(__('opt_all')) ?></option>
                    </select>
                </form>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-colors">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 font-semibold"><?= e(__('label_date')) ?></th>
                                <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                                <th class="px-4 py-3 font-semibold"><?= e(__('col_type')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_qty')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_balance')) ?></th>
                                <?php if ($canEdit): ?><th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!$history): ?>
                                <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('rep_none')) ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): $isIn = $h['type'] === 'in'; ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400"><?= e(date('d/m/Y', strtotime($h['movement_date']))) ?></td>
                                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($h['name']) ?></td>
                                        <td class="px-4 py-2.5">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $isIn ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' ?>">
                                                <?= $isIn ? e(__('type_in')) : e(__('type_out')) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right <?= $isIn ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' ?>"><?= $isIn ? '+' : '−' ?><?= e($h['quantity']) ?></td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white"><?= e($h['balance_after']) ?></td>
                                        <?php if ($canEdit): ?>
                                            <td class="px-4 py-2.5 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <form method="post" action="stock.php" class="inline"
                                                          onsubmit="var q=prompt('<?= e(__('stock_edit_prompt')) ?>','<?= (int) $h['quantity'] ?>'); if(q===null){return false;} q=parseInt(q,10); if(isNaN(q)||q<1){alert('<?= e(__('stock_edit_invalid')) ?>');return false;} this.mv_qty.value=q;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="edit_movement">
                                                        <input type="hidden" name="mv_id" value="<?= (int) $h['id'] ?>">
                                                        <input type="hidden" name="mv_qty" value="">
                                                        <input type="hidden" name="hp" value="<?= e($histPeriod) ?>">
                                                        <input type="hidden" name="hv" value="<?= e($histVal) ?>">
                                                        <input type="hidden" name="hn" value="<?= e($histCount) ?>">
                                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_edit')) ?></button>
                                                    </form>
                                                    <form method="post" action="stock.php" class="inline" onsubmit="return confirm('<?= e(__('stock_del_confirm')) ?>');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_movement">
                                                        <input type="hidden" name="mv_id" value="<?= (int) $h['id'] ?>">
                                                        <input type="hidden" name="hp" value="<?= e($histPeriod) ?>">
                                                        <input type="hidden" name="hv" value="<?= e($histVal) ?>">
                                                        <input type="hidden" name="hn" value="<?= e($histCount) ?>">
                                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
</section>

<script>
    // Collapse / expand the Stock In/Out entry card.
    function toggleStockEntry() {
        var c = document.getElementById('stockEntryCard');
        var b = document.getElementById('toggleStockEntry');
        var lbl = document.getElementById('toggleStockLabel');
        if (!c) { return; }
        var nowHidden = c.classList.toggle('hidden');
        if (lbl && b) { lbl.textContent = nowHidden ? b.dataset.open : b.dataset.close; }
        if (!nowHidden) { c.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    }

    // Live "new balance" = current + IN − OUT.
    document.querySelectorAll('#movement_date') && document.addEventListener('input', function (ev) {
        var t = ev.target;
        if (!t.classList || (!t.classList.contains('si-in') && !t.classList.contains('so-in'))) return;
        var row = t.closest('tr');
        if (!row) return;
        var bal = parseInt(row.querySelector('.cur-bal')?.dataset.bal || '0', 10);
        var si  = parseInt(row.querySelector('.si-in')?.value || '0', 10) || 0;
        var so  = parseInt(row.querySelector('.so-in')?.value || '0', 10) || 0;
        var cell = row.querySelector('.new-bal');
        if (cell) {
            var nb = bal + si - so;
            cell.textContent = nb;
            cell.classList.toggle('text-amber-600', nb < 0);
        }
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
