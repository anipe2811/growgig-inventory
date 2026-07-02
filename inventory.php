<?php
/**
 * inventory.php — Inventory module with branch (cawangan) scoping.
 *
 *   agency_admin / agency_user / account_admin -> see & manage ALL branches
 *   account_user                               -> admin of ONE branch only
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role       = $_SESSION['user_role'] ?? 'account_user';
$canEdit    = role_can_manage_inventory($role);
$seesAll    = role_sees_all_branches($role);
$userBranch = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : null;

/* Branches this user may work with. */
if ($seesAll) {
    $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
} elseif ($userBranch) {
    $stmt = $pdo->prepare('SELECT id, name FROM branches WHERE id = ?');
    $stmt->execute([$userBranch]);
    $branches = $stmt->fetchAll();
} else {
    $branches = [];
}
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);
$userBranchName = (!$seesAll && $branches) ? $branches[0]['name'] : '';
$noBranch       = !$seesAll && !$branches; // account_user without a branch assigned

/* -------------------------------------------------------------------------
 * Handle write actions (POST). Branch scope is enforced server-side.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit || $noBranch || !csrf_verify()) {
        header('Location: inventory.php?msg=denied');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Resolve the branch this action applies to.
    if ($seesAll) {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        if (!in_array($branchId, $validBranchIds, true)) {
            header('Location: inventory.php?msg=denied');
            exit;
        }
    } else {
        $branchId = $userBranch; // account_user is locked to their own branch
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($seesAll) {
            $stmt = $pdo->prepare('DELETE FROM items WHERE id = ?');
            $stmt->execute([$id]);
        } else {
            // Can only delete items inside their own branch.
            $stmt = $pdo->prepare('DELETE FROM items WHERE id = ? AND branch_id = ?');
            $stmt->execute([$id, $userBranch]);
        }
        header('Location: inventory.php?msg=deleted');
        exit;
    }

    // Flip the "count this item in the Sales Report" flag (inline checkbox toggle).
    if ($action === 'toggle_sale') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($seesAll) {
            $pdo->prepare('UPDATE items SET mark_as_sale = 1 - mark_as_sale WHERE id = ?')->execute([$id]);
        } else {
            $pdo->prepare('UPDATE items SET mark_as_sale = 1 - mark_as_sale WHERE id = ? AND branch_id = ?')->execute([$id, $userBranch]);
        }
        $back = (ctype_digit((string) ($_POST['fb'] ?? '')) && (int) $_POST['fb'] > 0) ? '&branch=' . (int) $_POST['fb'] : '';
        header('Location: inventory.php?msg=updated' . $back);
        exit;
    }

    $name     = trim($_POST['name'] ?? '');
    $sku      = trim($_POST['sku'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $unit     = trim($_POST['unit'] ?? 'pcs');
    $reorder  = (int) ($_POST['reorder_level'] ?? 0);
    $price    = (float) ($_POST['price'] ?? 0);
    $markSale = isset($_POST['mark_as_sale']) ? 1 : 0;
    $notes    = trim($_POST['notes'] ?? '');

    if ($name !== '') {
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($seesAll) {
                $stmt = $pdo->prepare(
                    'UPDATE items SET branch_id=?, name=?, sku=?, category=?, quantity=?, unit=?, reorder_level=?, price=?, mark_as_sale=?, notes=? WHERE id=?'
                );
                $stmt->execute([$branchId, $name, $sku, $category, $quantity, $unit, $reorder, $price, $markSale, $notes, $id]);
            } else {
                // account_user: branch stays fixed; can only touch their own branch's items.
                $stmt = $pdo->prepare(
                    'UPDATE items SET name=?, sku=?, category=?, quantity=?, unit=?, reorder_level=?, price=?, mark_as_sale=?, notes=? WHERE id=? AND branch_id=?'
                );
                $stmt->execute([$name, $sku, $category, $quantity, $unit, $reorder, $price, $markSale, $notes, $id, $userBranch]);
            }
            header('Location: inventory.php?msg=updated');
            exit;
        }

        // add — append to the end of the branch list (new items go last)
        $so = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM items WHERE branch_id = ?');
        $so->execute([$branchId]);
        $nextOrder = (int) $so->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO items (branch_id, name, sku, category, quantity, unit, reorder_level, price, mark_as_sale, notes, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$branchId, $name, $sku, $category, $quantity, $unit, $reorder, $price, $markSale, $notes, $nextOrder]);
        header('Location: inventory.php?msg=added');
        exit;
    }

    header('Location: inventory.php');
    exit;
}

/* -------------------------------------------------------------------------
 * Editing: load the item to pre-fill (scope-checked).
 * ---------------------------------------------------------------------- */
$editItem = null;
if ($canEdit && !$noBranch && isset($_GET['edit'])) {
    if ($seesAll) {
        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ? AND branch_id = ?');
        $stmt->execute([(int) $_GET['edit'], $userBranch]);
    }
    $editItem = $stmt->fetch() ?: null;
}

/* Optional branch filter (only for users who see all branches). */
$filterBranch = 0;
if ($seesAll && isset($_GET['branch']) && ctype_digit((string) $_GET['branch'])) {
    $filterBranch = (int) $_GET['branch'];
}

/* Flash messages. */
$flashMap = [
    'added'   => ['inv_added',       'green'],
    'updated' => ['inv_updated',     'green'],
    'deleted' => ['inv_deleted',     'green'],
    'denied'  => ['inv_not_allowed', 'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

/* Fetch items within scope. */
$items = [];
if (!$noBranch) {
    $params = [];
    $where  = '';
    if (!$seesAll) {
        $where = 'WHERE i.branch_id = ?';
        $params[] = $userBranch;
    } elseif ($filterBranch && in_array($filterBranch, $validBranchIds, true)) {
        $where = 'WHERE i.branch_id = ?';
        $params[] = $filterBranch;
    }
    $sql  = "SELECT i.*, b.name AS branch_name FROM items i LEFT JOIN branches b ON b.id = i.branch_id $where ORDER BY b.name ASC, i.category ASC, i.sort_order ASC, i.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
}

$pageTitle = __('inv_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('inv_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('inv_subtitle')) ?></p>
        </div>
        <?php if (!$seesAll && $userBranchName): ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m2-14h2m-2 4h2m6-4h2m-2 4h2"/></svg>
                <?= e(__('your_branch')) ?>: <?= e($userBranchName) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <?php [$flashKey, $flashColor] = $flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm
            <?= $flashColor === 'green'
                ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
                : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>">
            <?= e(__($flashKey)) ?>
        </div>
    <?php endif; ?>

    <?php if ($noBranch): ?>
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-900/20 px-4 py-4 text-sm text-amber-800 dark:text-amber-200">
            <?= e(__('no_branch')) ?>
        </div>
    <?php else: ?>

        <?php if ($canEdit): ?>
            <!-- Toggle button (hidden while editing — the form is already open) -->
            <div class="mt-6" <?= $editItem ? 'style="display:none;"' : '' ?>>
                <button type="button" id="toggleAddItem" onclick="toggleAddForm()"
                        data-open="<?= e(__('inv_add_item')) ?>" data-close="<?= e(__('btn_cancel')) ?>"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span id="toggleAddLabel"><?= e(__('inv_add_item')) ?></span>
                </button>
            </div>

            <!-- Add / Edit form (collapsed by default; opens on button click or when editing) -->
            <div id="addItemForm" class="mt-4 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6 transition-colors <?= $editItem ? '' : 'hidden' ?>">
                <h2 class="font-semibold text-gray-900 dark:text-white mb-4">
                    <?= $editItem ? e(__('inv_edit_item')) : e(__('inv_add_item')) ?>
                </h2>
                <form method="post" action="inventory.php" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'add' ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?= (int) $editItem['id'] ?>">
                    <?php endif; ?>

                    <!-- Branch field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_branch')) ?></label>
                        <?php if ($seesAll): ?>
                            <select name="branch_id" required
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                <option value=""><?= e(__('select_branch')) ?></option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>" <?= ($editItem && (int) $editItem['branch_id'] === (int) $b['id']) ? 'selected' : '' ?>>
                                        <?= e($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="branch_id" value="<?= (int) $userBranch ?>">
                            <input type="text" value="<?= e($userBranchName) ?>" disabled
                                   class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-900 text-gray-500 dark:text-gray-400 px-3 py-2 cursor-not-allowed">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_name')) ?></label>
                        <input type="text" name="name" required value="<?= e($editItem['name'] ?? '') ?>" placeholder="<?= e(__('ph_item_name')) ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_sku')) ?></label>
                        <input type="text" name="sku" value="<?= e($editItem['sku'] ?? '') ?>" placeholder="<?= e(__('ph_item_sku')) ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_category')) ?></label>
                        <input type="text" name="category" value="<?= e($editItem['category'] ?? '') ?>" placeholder="<?= e(__('ph_item_category')) ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_qty')) ?></label>
                        <input type="number" name="quantity" min="0" value="<?= e($editItem['quantity'] ?? '0') ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_unit')) ?></label>
                        <input type="text" name="unit" value="<?= e($editItem['unit'] ?? 'pcs') ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_reorder')) ?></label>
                        <input type="number" name="reorder_level" min="0" value="<?= e($editItem['reorder_level'] ?? '0') ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_price')) ?></label>
                        <input type="number" step="0.01" min="0" name="price" value="<?= e($editItem['price'] ?? '0.00') ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('col_mark_sale')) ?></label>
                        <label class="flex items-center gap-2 mt-2 cursor-pointer select-none">
                            <input type="checkbox" name="mark_as_sale" value="1" <?= (int) ($editItem['mark_as_sale'] ?? 0) === 1 ? 'checked' : '' ?>
                                   class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('inv_mark_sale_hint')) ?></span>
                        </label>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_notes')) ?></label>
                        <input type="text" name="notes" value="<?= e($editItem['notes'] ?? '') ?>" placeholder="<?= e(__('ph_item_notes')) ?>"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3 flex items-center gap-3">
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                            <?= $editItem ? e(__('btn_update')) : e(__('btn_save')) ?>
                        </button>
                        <?php if ($editItem): ?>
                            <a href="inventory.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                <?= e(__('btn_cancel')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Branch filter (only for users who see all branches) -->
        <?php if ($seesAll): ?>
            <form method="get" action="inventory.php" class="mt-6 flex items-center gap-2">
                <label for="branch" class="text-sm font-medium text-gray-600 dark:text-gray-400"><?= e(__('filter_branch')) ?>:</label>
                <select id="branch" name="branch" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <option value="0"><?= e(__('all_branches')) ?></option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $filterBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <!-- Items table -->
        <div class="mt-4 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-colors">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_branch')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_sku')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_category')) ?></th>
                            <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_qty')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_unit')) ?></th>
                            <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_reorder')) ?></th>
                            <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_price')) ?></th>
                            <th class="px-4 py-3 font-semibold text-center"><?= e(__('col_mark_sale')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_status')) ?></th>
                            <?php if ($canEdit): ?><th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php if (!$items): ?>
                            <tr>
                                <td colspan="<?= $canEdit ? 11 : 10 ?>" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    <?= e(__('inv_empty')) ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $lastGroup = null; $grpColspan = $canEdit ? 11 : 10; ?>
                            <?php foreach ($items as $item):
                                $isLow = (int) $item['quantity'] <= (int) $item['reorder_level'];
                                $groupKey = ($item['branch_name'] ?? '') . '|' . (string) ($item['category'] ?? '');
                            ?>
                                <?php if ($groupKey !== $lastGroup): $lastGroup = $groupKey;
                                    $grpLabel = trim((string) ($item['category'] ?? '')) !== '' ? $item['category'] : __('uncategorized'); ?>
                                    <tr class="bg-gray-100/80 dark:bg-gray-900/60">
                                        <td colspan="<?= $grpColspan ?>" class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            <?php if ($seesAll): ?><span class="text-gray-400 dark:text-gray-500"><?= e($item['branch_name'] ?: '-') ?> &middot; </span><?php endif; ?><?= e($grpLabel) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($item['branch_name'] ?: '-') ?></td>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($item['name']) ?></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($item['sku'] ?: '-') ?></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($item['category'] ?: '-') ?></td>
                                    <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= e($item['quantity']) ?></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($item['unit']) ?></td>
                                    <td class="px-4 py-3 text-right text-gray-500 dark:text-gray-400"><?= e($item['reorder_level']) ?></td>
                                    <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= e(number_format((float) $item['price'], 2)) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <?php $isSale = (int) ($item['mark_as_sale'] ?? 0) === 1; ?>
                                        <?php if ($canEdit): ?>
                                            <form method="post" action="inventory.php" class="inline-flex">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_sale">
                                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                <?php if ($seesAll): ?><input type="hidden" name="branch_id" value="<?= (int) $item['branch_id'] ?>"><?php endif; ?>
                                                <input type="hidden" name="fb" value="<?= (int) $filterBranch ?>">
                                                <input type="checkbox" title="<?= e(__('inv_mark_sale_hint')) ?>" onchange="this.form.submit()" <?= $isSale ? 'checked' : '' ?>
                                                       class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                            </form>
                                        <?php else: ?>
                                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold <?= $isSale ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'text-gray-300 dark:text-gray-600' ?>"><?= $isSale ? '&check;' : '&minus;' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                            <?= $isLow
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                                : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' ?>">
                                            <?= $isLow ? e(__('status_low')) : e(__('status_ok')) ?>
                                        </span>
                                    </td>
                                    <?php if ($canEdit): ?>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="inventory.php?edit=<?= (int) $item['id'] ?>" class="px-2.5 py-1 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors">
                                                    <?= e(__('btn_edit')) ?>
                                                </a>
                                                <form method="post" action="inventory.php" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                                        <?= e(__('btn_delete')) ?>
                                                    </button>
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
    <?php endif; ?>
</section>

<script>
    // Collapse/expand the Add Item form.
    function toggleAddForm() {
        var f = document.getElementById('addItemForm');
        var b = document.getElementById('toggleAddItem');
        var lbl = document.getElementById('toggleAddLabel');
        if (!f) return;
        var nowHidden = f.classList.toggle('hidden');
        if (lbl && b) { lbl.textContent = nowHidden ? b.dataset.open : b.dataset.close; }
        if (!nowHidden) { var n = f.querySelector('input[name="name"]'); if (n) n.focus(); }
    }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
