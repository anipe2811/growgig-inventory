<?php
/**
 * supplier.php — Supplier portal. A supplier login sees the purchase orders
 * placed with them (status pending/received) and their line items. Read-only.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!role_is_supplier($role)) {
    header('Location: dashboard.php');
    exit;
}

/* Which supplier record is this login tied to? */
$sidStmt = $pdo->prepare('SELECT supplier_id FROM users WHERE id = ?');
$sidStmt->execute([$_SESSION['user_id']]);
$supplierId = (int) ($sidStmt->fetchColumn() ?: 0);

/* Supplier marks an order as delivered ("Done · Goods Sent") -> notifies branch + managers. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_verify() && ($_POST['action'] ?? '') === 'mark_delivered' && $supplierId) {
        $oid = (int) ($_POST['id'] ?? 0);
        $upd = $pdo->prepare('UPDATE purchase_orders SET status = "delivered" WHERE id = ? AND supplier_id = ? AND status = "pending"');
        $upd->execute([$oid, $supplierId]);
        if ($upd->rowCount() === 1) {
            $info = $pdo->prepare('SELECT po.created_by, b.name AS branch FROM purchase_orders po LEFT JOIN branches b ON b.id = po.branch_id WHERE po.id = ?');
            $info->execute([$oid]);
            if ($row = $info->fetch()) {
                if ($row['created_by']) {
                    notify_user((int) $row['created_by'], 'order', __('notif_delivered_t') . ' #' . $oid, __('notif_delivered_b'), 'orders.php');
                }
                notify_roles(['account_admin', 'agency_admin', 'agency_user'], 'order', __('notif_delivered_t') . ' #' . $oid, trim(($row['branch'] ?? '') . ' — ' . __('notif_delivered_b')), 'orders.php');
            }
        }
    }
    header('Location: supplier.php');
    exit;
}

$sup = null;
if ($supplierId) {
    $s = $pdo->prepare('SELECT name, email, phone FROM suppliers WHERE id = ?');
    $s->execute([$supplierId]);
    $sup = $s->fetch();
}

/* Orders actually placed with this supplier (requested/rejected/cancelled are never shown). */
$orders = [];
if ($supplierId) {
    $os = $pdo->prepare(
        'SELECT po.id, po.status, po.created_at, po.received_at, b.name AS branch
         FROM purchase_orders po
         LEFT JOIN branches b ON b.id = po.branch_id
         WHERE po.supplier_id = ? AND po.status IN ("pending","delivered","received")
         ORDER BY po.id DESC LIMIT 100'
    );
    $os->execute([$supplierId]);
    $orders = $os->fetchAll();
}

/* Line items per order. */
$itemsByOrder = [];
if ($orders) {
    $ids = array_map(static fn($o) => (int) $o['id'], $orders);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $li  = $pdo->prepare("SELECT poi.order_id, i.name, poi.quantity FROM purchase_order_items poi JOIN items i ON i.id = poi.item_id WHERE poi.order_id IN ($in) ORDER BY i.name ASC");
    $li->execute($ids);
    foreach ($li->fetchAll() as $r) { $itemsByOrder[(int) $r['order_id']][] = $r; }
}

$statusBadge = [
    'pending'   => ['status_pending',   'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    'delivered' => ['status_delivered', 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'],
    'received'  => ['status_received',  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
];

$pageTitle = __('sup_portal_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('sup_portal_title')) ?></h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('sup_portal_sub')) ?></p>

    <?php if ($sup): ?>
        <div class="mt-6 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-5 text-white">
            <p class="text-lg font-semibold"><?= e($sup['name']) ?></p>
            <p class="text-sm text-white/80"><?= e($sup['email'] ?: '') ?><?= $sup['phone'] ? ' &middot; ' . e($sup['phone']) : '' ?></p>
        </div>
    <?php endif; ?>

    <div class="mt-6 space-y-4">
        <?php if (!$orders): ?>
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                <?= e(__('sup_no_orders')) ?>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $o): [$sk, $scls] = $statusBadge[$o['status']] ?? $statusBadge['pending']; ?>
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/40">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-gray-900 dark:text-white">#<?= (int) $o['id'] ?></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400"><?= e(date('d M Y', strtotime($o['created_at']))) ?></span>
                            <span class="text-sm text-gray-600 dark:text-gray-300"><?= e($o['branch'] ?: '-') ?></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $scls ?>"><?= e(__($sk)) ?></span>
                            <?php if ($o['status'] === 'pending'): ?>
                                <form method="post" onsubmit="return confirm('<?= e(__('btn_delivered')) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_delivered">
                                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <?= e(__('btn_delivered')) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="px-5 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2"><?= e(__('sup_order_items')) ?></p>
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php foreach (($itemsByOrder[(int) $o['id']] ?? []) as $ln): ?>
                                    <tr>
                                        <td class="py-1.5 text-gray-700 dark:text-gray-200"><?= e($ln['name']) ?></td>
                                        <td class="py-1.5 text-right font-medium text-gray-900 dark:text-white"><?= (int) $ln['quantity'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
