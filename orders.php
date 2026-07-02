<?php
/**
 * orders.php - Order Stock to Supplier (purchase orders) with an approval flow.
 *
 * Roles:
 *   account_admin / agency : place orders directly, approve/reject branch
 *                            requests, verify receipts, manage suppliers.
 *   account_user (branch)  : REQUEST an order (needs admin approval) and verify
 *                            receipt for their own branch only.
 *
 * Lifecycle:
 *   requested --approve--> pending --verify--> received
 *      \--reject--> rejected      \--cancel--> cancelled
 *   (an admin's direct order starts at "pending")
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? 'account_user';
if (!role_can_use_orders($role)) {
    header('Location: dashboard.php');
    exit;
}
$canManage    = role_can_order($role);          // place/approve/reject/verify any branch + suppliers
$isBranchUser = role_can_request_order($role);  // request + verify own branch only
$userBranch   = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;
$acct         = current_account_id(); // non-null = restrict to this account; null = agency "all accounts" (global)

// Extra guard fragment restricting manager order mutations to the acting account's
// branches (empty = agency "all accounts"). $acct is an int, so inlining is injection-safe.
$acctOrderClause = $acct ? ' AND branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')' : '';

/* Branch scope: managers see all branches; a branch user is locked to their own. */
if ($canManage) {
    if ($acct) {
        $bq = $pdo->prepare('SELECT id, name FROM branches WHERE account_id = ? ORDER BY name ASC');
        $bq->execute([$acct]);
        $branches = $bq->fetchAll();
    } else {
        $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
    }
} else {
    $bs = $pdo->prepare('SELECT id, name FROM branches WHERE id = ?');
    $bs->execute([$userBranch]);
    $branches = $bs->fetchAll();
}
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);
$suppliers      = $pdo->query('SELECT id, name, email, phone FROM suppliers ORDER BY name ASC')->fetchAll();

$editSupplier = null;
if ($canManage && isset($_GET['edit_supplier'])) {
    $es = $pdo->prepare('SELECT id, name, email, phone FROM suppliers WHERE id = ?');
    $es->execute([(int) $_GET['edit_supplier']]);
    $editSupplier = $es->fetch() ?: null;
}

if ($isBranchUser) {
    $selectedBranch = $userBranch;
} else {
    $selectedBranch = (isset($_GET['branch']) && ctype_digit((string) $_GET['branch']) && in_array((int) $_GET['branch'], $validBranchIds, true))
        ? (int) $_GET['branch'] : (int) ($validBranchIds[0] ?? 0);
}
$redirectBase = 'orders.php?branch=' . $selectedBranch;

/* Only "Produk Terapi" items may be ordered from suppliers. */
const ORDER_CATEGORY = 'Produk Terapi';

/* Validate a posted qty[] map against a branch's orderable items -> [item_id => qty]. */
$buildLines = static function (int $branchId, $qtyArr) use ($pdo): array {
    $istmt = $pdo->prepare('SELECT id FROM items WHERE branch_id = ? AND category = ?');
    $istmt->execute([$branchId, ORDER_CATEGORY]);
    $valItems = array_map(static fn($r) => (int) $r['id'], $istmt->fetchAll());
    $lines = [];
    foreach ((is_array($qtyArr) ? $qtyArr : []) as $iid => $q) {
        $iid = (int) $iid; $q = (int) $q;
        if ($q > 0 && in_array($iid, $valItems, true)) { $lines[$iid] = $q; }
    }
    return $lines;
};

/* -------------------------------------------------------------------------
 * POST actions
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ' . $redirectBase . '&msg=denied'); exit; }
    $action = $_POST['action'] ?? '';

    /* Manager: add supplier. */
    if ($action === 'add_supplier' && $canManage) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO suppliers (name, email, phone) VALUES (?, ?, ?)')
                ->execute([$name, trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? '')]);
        }
        header('Location: ' . $redirectBase . '&msg=sup_added'); exit;
    }

    /* Manager: edit / delete a supplier. */
    if ($action === 'update_supplier' && $canManage) {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $pdo->prepare('UPDATE suppliers SET name=?, email=?, phone=? WHERE id=?')
                ->execute([$name, trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''), $id]);
        }
        header('Location: ' . $redirectBase . '&msg=sup_updated'); exit;
    }
    if ($action === 'delete_supplier' && $canManage) {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: ' . $redirectBase . '&msg=sup_removed'); exit;
    }

    /* Create: managers place directly (pending); branch users request (requested). */
    if ($action === 'create_order' || $action === 'request_order') {
        $requesting = ($action === 'request_order');
        if (($requesting && !$isBranchUser) || (!$requesting && !$canManage)) {
            header('Location: ' . $redirectBase . '&msg=denied'); exit;
        }
        // Branch users may only order for their own branch.
        $branchId   = $requesting ? $userBranch : (int) ($_POST['branch_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        if (!in_array($branchId, $validBranchIds, true) || $supplierId <= 0) {
            header('Location: ' . $redirectBase . '&msg=invalid'); exit;
        }
        $lines = $buildLines($branchId, $_POST['qty'] ?? null);
        if (!$lines) { header('Location: ' . $redirectBase . '&msg=empty'); exit; }

        $status = $requesting ? 'requested' : 'pending';
        // Snapshot the supplier name so history survives even if the supplier is later deleted.
        $supplierName = '';
        foreach ($suppliers as $sp) { if ((int) $sp['id'] === $supplierId) { $supplierName = $sp['name']; break; } }
        $pdo->beginTransaction();
        try {
            $po = $pdo->prepare('INSERT INTO purchase_orders (branch_id, supplier_id, supplier_name, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $po->execute([$branchId, $supplierId, $supplierName, $status, trim($_POST['notes'] ?? ''), $_SESSION['user_id']]);
            $oid = (int) $pdo->lastInsertId();
            $li = $pdo->prepare('INSERT INTO purchase_order_items (order_id, item_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $iid => $q) { $li->execute([$oid, $iid, $q]); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            header('Location: ' . $redirectBase . '&msg=invalid'); exit;
        }
        // Notify the right people about the new order/request.
        $branchName = '';
        foreach ($branches as $b) { if ((int) $b['id'] === $branchId) { $branchName = $b['name']; break; } }
        if ($requesting) {
            notify_roles(['account_admin', 'agency_admin', 'agency_user'], 'order',
                __('notif_req_t') . ' #' . $oid, trim($branchName . ' — ' . __('notif_req_b')), 'orders.php');
        } else {
            notify_supplier_users($supplierId, __('notif_new_order_t') . ' #' . $oid, trim($branchName . ' — ' . __('notif_new_order_b')));
        }
        header('Location: ' . $redirectBase . '&msg=' . ($requesting ? 'requested' : 'ord_created')); exit;
    }

    /* Manager: approve a request -> pending (the order is now "placed"). */
    if ($action === 'approve_order' && $canManage) {
        $oid = (int) ($_POST['id'] ?? 0);
        $upd = $pdo->prepare('UPDATE purchase_orders SET status = "pending", approved_by = ?, approved_at = NOW() WHERE id = ? AND status = "requested"' . $acctOrderClause);
        $upd->execute([$_SESSION['user_id'], $oid]);
        if ($upd->rowCount() === 1) {
            $info = $pdo->prepare('SELECT po.created_by, po.supplier_id, b.name AS branch FROM purchase_orders po LEFT JOIN branches b ON b.id = po.branch_id WHERE po.id = ?');
            $info->execute([$oid]);
            if ($row = $info->fetch()) {
                if ($row['created_by']) {
                    notify_user((int) $row['created_by'], 'order', __('notif_appr_t') . ' #' . $oid, __('notif_appr_b'), 'orders.php');
                }
                notify_supplier_users((int) $row['supplier_id'], __('notif_new_order_t') . ' #' . $oid, trim(($row['branch'] ?? '') . ' — ' . __('notif_new_order_b')));
            }
        }
        header('Location: ' . $redirectBase . '&msg=approved'); exit;
    }

    /* Manager: reject a request -> rejected (terminal). */
    if ($action === 'reject_order' && $canManage) {
        $oid = (int) ($_POST['id'] ?? 0);
        $upd = $pdo->prepare('UPDATE purchase_orders SET status = "rejected", approved_by = ?, approved_at = NOW() WHERE id = ? AND status = "requested"' . $acctOrderClause);
        $upd->execute([$_SESSION['user_id'], $oid]);
        if ($upd->rowCount() === 1) {
            $cb = $pdo->prepare('SELECT created_by FROM purchase_orders WHERE id = ?');
            $cb->execute([$oid]);
            if ($createdBy = (int) ($cb->fetchColumn() ?: 0)) {
                notify_user($createdBy, 'order', __('notif_rej_t') . ' #' . $oid, __('notif_rej_b'), 'orders.php');
            }
        }
        header('Location: ' . $redirectBase . '&msg=rejected'); exit;
    }

    /* Manager (HQ): confirm receipt from the supplier and forward to the branch -> forwarded.
     * No stock is added here — the branch confirms FINAL receipt (verify_order below), which
     * is what actually adds the stock to the branch. Flow: supplier -> HQ -> branch. */
    if ($action === 'forward_order' && $canManage) {
        $oid = (int) ($_POST['id'] ?? 0);
        $upd = $pdo->prepare('UPDATE purchase_orders SET status = "forwarded", forwarded_by = ?, forwarded_at = NOW() WHERE id = ? AND status IN ("pending","delivered")' . $acctOrderClause);
        $upd->execute([$_SESSION['user_id'], $oid]);
        if ($upd->rowCount() === 1) {
            $info = $pdo->prepare('SELECT branch_id FROM purchase_orders WHERE id = ?');
            $info->execute([$oid]);
            if ($bid = (int) ($info->fetchColumn() ?: 0)) {
                // Ask the branch's own users to confirm receipt.
                $bu = $pdo->prepare('SELECT id FROM users WHERE role = "account_user" AND branch_id = ?');
                $bu->execute([$bid]);
                foreach ($bu->fetchAll() as $u) {
                    notify_user((int) $u['id'], 'order', __('notif_fwd_t') . ' #' . $oid, __('notif_fwd_b'), 'orders.php');
                }
            }
        }
        header('Location: ' . $redirectBase . '&msg=forwarded'); exit;
    }

    /* Branch (or manager): confirm FINAL receipt of a forwarded order -> received (adds stock).
     * Atomic + idempotent: the row is locked (FOR UPDATE) and "claimed" with a status guard
     * BEFORE any stock is added, so a double-submit/replay can never add stock twice. Balances
     * use a relational `quantity = quantity + ?` to avoid lost updates under concurrency. */
    if ($action === 'verify_order') {
        if (stock_frozen_for($role)) { header('Location: ' . $redirectBase . '&msg=frozen'); exit; }
        $oid = (int) ($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT branch_id FROM purchase_orders WHERE id = ? AND status = "forwarded" FOR UPDATE');
            $sel->execute([$oid]);
            $order   = $sel->fetch();
            // Final receipt is confirmed ONLY by the branch's own user (HQ just forwards).
            $allowed = $order && $isBranchUser && (int) $order['branch_id'] === $userBranch;
            if ($allowed) {
                // Claim it; if a concurrent request already received this order, rowCount is 0 -> add no stock.
                $claim = $pdo->prepare('UPDATE purchase_orders SET status = "received", received_at = NOW(), verified_by = ? WHERE id = ? AND status = "forwarded"');
                $claim->execute([$_SESSION['user_id'], $oid]);
                if ($claim->rowCount() === 1) {
                    $bid   = (int) $order['branch_id'];
                    $lines = $pdo->prepare('SELECT item_id, quantity FROM purchase_order_items WHERE order_id = ?');
                    $lines->execute([$oid]);
                    $upd = $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ? AND branch_id = ?');
                    $get = $pdo->prepare('SELECT quantity FROM items WHERE id = ? AND branch_id = ?');
                    $mv  = $pdo->prepare('INSERT INTO stock_movements (item_id, branch_id, type, quantity, balance_after, movement_date, user_id) VALUES (?, ?, "in", ?, ?, ?, ?)');
                    $today = date('Y-m-d');
                    foreach ($lines->fetchAll() as $ln) {
                        $iid = (int) $ln['item_id']; $q = (int) $ln['quantity'];
                        $upd->execute([$q, $iid, $bid]);
                        if ($upd->rowCount() === 0) { continue; } // item moved/removed
                        $get->execute([$iid, $bid]);
                        $bal = (int) $get->fetchColumn();
                        $mv->execute([$iid, $bid, $q, $bal, $today, $_SESSION['user_id']]);
                    }
                    notify_roles(['account_admin', 'agency_admin', 'agency_user'], 'order',
                        __('notif_recv_t') . ' #' . $oid, __('notif_recv_b'), 'orders.php');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); }
        header('Location: ' . $redirectBase . '&msg=verified'); exit;
    }

    /* Cancel. Manager: any requested/pending. Branch user: own branch's requested only. */
    if ($action === 'cancel_order') {
        $oid = (int) ($_POST['id'] ?? 0);
        if ($canManage) {
            $pdo->prepare('UPDATE purchase_orders SET status = "cancelled" WHERE id = ? AND status IN ("requested","pending")' . $acctOrderClause)->execute([$oid]);
        } elseif ($isBranchUser) {
            $pdo->prepare('UPDATE purchase_orders SET status = "cancelled" WHERE id = ? AND status = "requested" AND branch_id = ?')->execute([$oid, $userBranch]);
        }
        header('Location: ' . $redirectBase . '&msg=cancelled'); exit;
    }

    /* Delete an order permanently. Only for states that never applied stock:
     * requested / pending / rejected / cancelled. Manager: any; branch user: own branch. */
    if ($action === 'delete_order') {
        $oid = (int) ($_POST['id'] ?? 0);
        $deletable = "'requested','pending','rejected','cancelled'";
        $pdo->beginTransaction();
        try {
            if ($canManage) {
                $sel = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? AND status IN ($deletable)" . $acctOrderClause . ' FOR UPDATE');
                $sel->execute([$oid]);
            } elseif ($isBranchUser) {
                $sel = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? AND branch_id = ? AND status IN ($deletable) FOR UPDATE");
                $sel->execute([$oid, $userBranch]);
            } else {
                $sel = null;
            }
            if ($sel && $sel->fetch()) {
                $pdo->prepare('DELETE FROM purchase_order_items WHERE order_id = ?')->execute([$oid]);
                $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$oid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
        header('Location: ' . $redirectBase . '&msg=deleted'); exit;
    }

    /* Report a receipt problem (wrong / short delivery). Branch user (own branch) or manager.
     * Notifies the supplier and the managers; the order status is left unchanged. */
    if ($action === 'report_issue') {
        $oid    = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) > 300) { $reason = mb_substr($reason, 0, 300); }
        $issueAcctClause = $acct ? ' AND po.branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')' : '';
        $o = $pdo->prepare('SELECT po.branch_id, po.supplier_id, b.name AS branch FROM purchase_orders po LEFT JOIN branches b ON b.id = po.branch_id WHERE po.id = ? AND po.status IN ("pending","delivered","forwarded","received")' . $issueAcctClause);
        $o->execute([$oid]);
        $order   = $o->fetch();
        $allowed = $order && ($canManage || ($isBranchUser && (int) $order['branch_id'] === $userBranch));
        if ($allowed) {
            $body = trim(($order['branch'] ?? '') . ' — ' . __('notif_issue_b') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            notify_supplier_users((int) $order['supplier_id'], __('notif_issue_t') . ' #' . $oid, $body, 'supplier.php');
            notify_roles(['account_admin', 'agency_admin', 'agency_user'], 'order', __('notif_issue_t') . ' #' . $oid, $body, 'orders.php');
        }
        header('Location: ' . $redirectBase . '&msg=issue'); exit;
    }

    /* Adjust a RECEIVED order's quantities (fix a wrong stock-in). For each line the
     * difference vs what was recorded is applied to stock as an 'in'/'out' movement,
     * and the order line is updated to the corrected quantity. Branch user (own branch)
     * or manager. Never drives a balance below zero. */
    if ($action === 'adjust_order') {
        if (stock_frozen_for($role)) { header('Location: ' . $redirectBase . '&msg=frozen'); exit; }
        $oid = (int) ($_POST['id'] ?? 0);
        $adj = is_array($_POST['adjust'] ?? null) ? $_POST['adjust'] : [];
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT branch_id FROM purchase_orders WHERE id = ? AND status = "received"' . $acctOrderClause . ' FOR UPDATE');
            $sel->execute([$oid]);
            $order   = $sel->fetch();
            $allowed = $order && ($canManage || ($isBranchUser && (int) $order['branch_id'] === $userBranch));
            if ($allowed) {
                $bid     = (int) $order['branch_id'];
                $lines   = $pdo->prepare('SELECT id, item_id, quantity FROM purchase_order_items WHERE order_id = ?');
                $lines->execute([$oid]);
                $apply   = $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ? AND branch_id = ? AND quantity + ? >= 0');
                $get     = $pdo->prepare('SELECT quantity FROM items WHERE id = ? AND branch_id = ?');
                $mv      = $pdo->prepare('INSERT INTO stock_movements (item_id, branch_id, type, quantity, balance_after, movement_date, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $updLine = $pdo->prepare('UPDATE purchase_order_items SET quantity = ? WHERE id = ?');
                $today   = date('Y-m-d');
                foreach ($lines->fetchAll() as $ln) {
                    $iid = (int) $ln['item_id']; $old = (int) $ln['quantity'];
                    if (!array_key_exists($iid, $adj)) { continue; }
                    $new   = max(0, (int) $adj[$iid]);
                    $delta = $new - $old;
                    if ($delta === 0) { continue; }
                    $apply->execute([$delta, $iid, $bid, $delta]);
                    if ($apply->rowCount() === 0) { continue; } // item gone, or would go negative
                    $get->execute([$iid, $bid]);
                    $bal = (int) $get->fetchColumn();
                    $mv->execute([$iid, $bid, $delta > 0 ? 'in' : 'out', abs($delta), $bal, $today, $_SESSION['user_id']]);
                    $updLine->execute([$new, $ln['id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); }
        header('Location: ' . $redirectBase . '&msg=adjusted'); exit;
    }

    header('Location: orders.php'); exit;
}

/* Items for the selected branch. */
$items = [];
if ($selectedBranch) {
    $st = $pdo->prepare('SELECT id, name, quantity, unit, category, sort_order FROM items WHERE branch_id = ? AND category = ? ORDER BY sort_order ASC, name ASC');
    $st->execute([$selectedBranch, ORDER_CATEGORY]);
    $items = $st->fetchAll();
}

/* Orders list — branch users see only their own branch; managers see all branches
 * within the acting account (or every account when agency "all accounts"). */
if ($isBranchUser) {
    $ordWhere  = 'WHERE po.branch_id = ?';
    $ordParams = [$userBranch];
} elseif ($acct) {
    $ordWhere  = 'WHERE po.branch_id IN (SELECT id FROM branches WHERE account_id = ' . (int) $acct . ')';
    $ordParams = [];
} else {
    $ordWhere  = '';
    $ordParams = [];
}
$ostmt = $pdo->prepare(
    'SELECT po.id, po.status, po.created_at, po.received_at, po.branch_id,
            COALESCE(s.name, po.supplier_name) AS supplier, b.name AS branch, u.name AS requester,
            (SELECT COALESCE(SUM(quantity),0) FROM purchase_order_items poi WHERE poi.order_id = po.id) AS total_qty
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN branches  b ON b.id = po.branch_id
     LEFT JOIN users     u ON u.id = po.created_by
     ' . $ordWhere . '
     ORDER BY po.id DESC LIMIT 50'
);
$ostmt->execute($ordParams);
$orders = $ostmt->fetchAll();

/* Line items for each listed order (shown in an expandable row). */
$orderItems = [];
if ($orders) {
    $oids = array_map(static fn($o) => (int) $o['id'], $orders);
    $in   = implode(',', array_fill(0, count($oids), '?'));
    $li   = $pdo->prepare("SELECT poi.order_id, poi.item_id, i.name, poi.quantity FROM purchase_order_items poi JOIN items i ON i.id = poi.item_id WHERE poi.order_id IN ($in) ORDER BY i.name ASC");
    $li->execute($oids);
    foreach ($li->fetchAll() as $r) { $orderItems[(int) $r['order_id']][] = $r; }
}

$flashMap = [
    'sup_added'   => ['sup_added',      'green'],
    'sup_updated' => ['sup_updated',    'green'],
    'sup_removed' => ['sup_removed',    'green'],
    'ord_created' => ['ord_created',    'green'],
    'requested'   => ['ord_requested',  'green'],
    'approved'    => ['ord_approved',   'green'],
    'rejected'    => ['ord_rejected',   'green'],
    'verified'    => ['ord_verified',   'green'],
    'forwarded'   => ['ord_forwarded',  'green'],
    'cancelled'   => ['ord_cancelled',  'green'],
    'deleted'     => ['ord_deleted',    'green'],
    'issue'       => ['ord_issue_reported', 'green'],
    'adjusted'    => ['ord_adjusted',   'green'],
    'empty'       => ['ord_empty_lines','red'],
    'invalid'     => ['err_fill_all',   'red'],
    'denied'      => ['inv_not_allowed','red'],
    'frozen'      => ['stock_frozen',   'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

// New Order form is collapsed by default; keep it open after a validation error.
$openOrderForm = in_array($_GET['msg'] ?? '', ['empty', 'invalid'], true);

$statusBadge = [
    'requested' => ['status_requested', 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
    'pending'   => ['status_pending',   'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    'delivered' => ['status_delivered', 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'],
    'forwarded' => ['status_forwarded', 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300'],
    'received'  => ['status_received',  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
    'cancelled' => ['status_cancelled', 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
    'rejected'  => ['status_rejected',  'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
];

$pageTitle = __('nav_orders');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('ord_title')) ?></h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e($isBranchUser ? __('ord_request_sub') : __('ord_subtitle')) ?></p>
    </div>

    <?php if ($isBranchUser): ?>
        <div class="mt-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 px-4 py-3 text-sm text-blue-700 dark:text-blue-300">
            <?= e(__('ord_flow_note')) ?>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <?php [$fk, $fc] = $flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm
            <?= $fc === 'green'
                ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
                : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>">
            <?= e(__($fk)) ?>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <!-- Suppliers (managers only) -->
        <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-white"><?= e(__('sup_title')) ?></h2>
                <button type="button" onclick="var f=document.getElementById('supplier-form'); if(f){f.classList.toggle('hidden');}"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-semibold hover:bg-gray-800 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <?= e(__('sup_add')) ?>
                </button>
            </div>
            <div id="supplier-form" class="<?= $editSupplier ? '' : 'hidden' ?> mt-4">
                <form method="post" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editSupplier ? 'update_supplier' : 'add_supplier' ?>">
                    <?php if ($editSupplier): ?><input type="hidden" name="id" value="<?= (int) $editSupplier['id'] ?>"><?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('sup_name')) ?></label>
                        <input type="text" name="name" required value="<?= e($editSupplier['name'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                        <input type="email" name="email" value="<?= e($editSupplier['email'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('sup_phone')) ?></label>
                        <input type="text" name="phone" value="<?= e($editSupplier['phone'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($editSupplier ? __('btn_save_changes') : __('sup_save')) ?></button>
                        <?php if ($editSupplier): ?><a href="<?= e($redirectBase) ?>" class="px-4 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a><?php endif; ?>
                    </div>
                </form>
            </div>
            <?php if ($suppliers): ?>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($suppliers as $sp): ?>
                        <span class="inline-flex items-center gap-2 pl-3 pr-2 py-1.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 ring-1 ring-gray-200 dark:ring-gray-700 text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-200"><?= e($sp['name']) ?></span>
                            <?php if ($sp['phone']): ?><span class="text-gray-400 text-xs"><?= e($sp['phone']) ?></span><?php endif; ?>
                            <a href="<?= e($redirectBase) ?>&amp;edit_supplier=<?= (int) $sp['id'] ?>#supplier-form" title="<?= e(__('sup_edit')) ?>" class="p-1 rounded text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <form method="post" class="inline" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                                <button type="submit" title="<?= e(__('btn_delete')) ?>" class="p-1 rounded text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400"><?= e(__('sup_none')) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- New order (managers) / Request order (branch users) -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="font-semibold text-gray-900 dark:text-white"><?= e($isBranchUser ? __('ord_request_new') : __('ord_new')) ?></h2>
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($canManage): ?>
                    <form method="get" action="orders.php" class="flex items-center gap-2">
                        <label class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('label_branch')) ?>:</label>
                        <select name="branch" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= $selectedBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php elseif ($branches): ?>
                    <span class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('label_branch')) ?>: <span class="font-semibold text-gray-700 dark:text-gray-200"><?= e($branches[0]['name']) ?></span></span>
                <?php endif; ?>
                <button type="button" onclick="var b=document.getElementById('new-order-body'); if(b){b.classList.toggle('hidden'); var c=this.querySelector('svg'); if(c){c.classList.toggle('rotate-180');} if(!b.classList.contains('hidden')){b.scrollIntoView({behavior:'smooth',block:'nearest'});}}"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors shadow-sm shadow-indigo-600/20">
                    <?= e($isBranchUser ? __('btn_request_order') : __('btn_create_order')) ?>
                    <svg class="w-4 h-4 transition-transform <?= $openOrderForm ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
        </div>

        <div id="new-order-body" class="<?= $openOrderForm ? '' : 'hidden' ?> mt-4">
        <?php if (!$suppliers): ?>
            <p class="text-sm text-amber-600 dark:text-amber-400"><?= e(__('sup_none')) ?></p>
        <?php elseif (!$items): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('inv_empty')) ?></p>
        <?php else: ?>
            <form method="post" action="orders.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $isBranchUser ? 'request_order' : 'create_order' ?>">
                <input type="hidden" name="branch_id" value="<?= (int) $selectedBranch ?>">
                <div class="flex flex-col sm:flex-row gap-3 mb-4">
                    <select name="supplier_id" required class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value=""><?= e(__('ord_select_supplier')) ?></option>
                        <?php foreach ($suppliers as $sp): ?>
                            <option value="<?= (int) $sp['id'] ?>"><?= e($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($isBranchUser ? __('btn_request_order') : __('btn_create_order')) ?></button>
                </div>
                <div class="overflow-x-auto rounded-xl ring-1 ring-gray-100 dark:ring-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_balance')) ?></th>
                                <th class="px-4 py-3 font-semibold text-center"><?= e(__('ord_qty')) ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php foreach ($items as $it): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                    <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($it['name']) ?></td>
                                    <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400"><?= e((int) $it['quantity']) ?></td>
                                    <td class="px-4 py-2.5 text-center">
                                        <input type="number" min="0" name="qty[<?= (int) $it['id'] ?>]" placeholder="0" class="w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
        </div>
    </div>

    <!-- Orders list -->
    <div class="mt-6">
        <h2 class="font-semibold text-gray-900 dark:text-white mb-3"><?= e(__('ord_list')) ?></h2>
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_order_id')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('label_date')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_supplier')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_branch')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_requester')) ?></th>
                            <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_items')) ?></th>
                            <th class="px-4 py-3 font-semibold"><?= e(__('col_status')) ?></th>
                            <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php if (!$orders): ?>
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('ord_none')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $o):
                                [$sk, $scls] = $statusBadge[$o['status']] ?? $statusBadge['pending'];
                                $ownBranch   = $isBranchUser && (int) $o['branch_id'] === $userBranch;
                            ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">#<?= (int) $o['id'] ?></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e(date('d/m/Y', strtotime($o['created_at']))) ?></td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-200"><?= e($o['supplier'] ?: '-') ?></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($o['branch'] ?: '-') ?></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($o['requester'] ?: '-') ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" onclick="var d=document.getElementById('ord-items-<?= (int) $o['id'] ?>'); if(d){d.classList.toggle('hidden');}"
                                                class="inline-flex items-center gap-1 font-medium text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                            <?= (int) $o['total_qty'] ?>
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $scls ?>"><?= e(__($sk)) ?></span></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                        <?php if ($o['status'] === 'requested'): ?>
                                            <?php if ($canManage): ?>
                                                <form method="post">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="approve_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-semibold text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors"><?= e(__('btn_approve')) ?></button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('<?= e(__('btn_reject')) ?>?');">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="reject_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_reject')) ?></button>
                                                </form>
                                            <?php elseif ($ownBranch): ?>
                                                <form method="post" onsubmit="return confirm('<?= e(__('btn_cancel_order')) ?>?');">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_cancel_order')) ?></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-300 dark:text-gray-600">-</span>
                                            <?php endif; ?>
                                        <?php elseif (in_array($o['status'], ['pending', 'delivered'], true)): ?>
                                            <?php if ($canManage): ?>
                                                <form method="post">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="forward_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-semibold text-sky-600 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-900/30 transition-colors"><?= e(__('btn_forward')) ?></button>
                                                </form>
                                                <form method="post" onsubmit="var r=prompt('<?= e(__('issue_prompt')) ?>'); if(r===null){return false;} this.reason.value=r;">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="report_issue"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="reason" value="">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors"><?= e(__('btn_report_issue')) ?></button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('<?= e(__('btn_cancel_order')) ?>?');">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_cancel_order')) ?></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-300 dark:text-gray-600">-</span>
                                            <?php endif; ?>
                                        <?php elseif ($o['status'] === 'forwarded'): ?>
                                            <?php if ($canManage || $ownBranch): ?>
                                                <?php if ($ownBranch): ?>
                                                <form method="post">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="verify_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-semibold text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors"><?= e(__('btn_verify')) ?></button>
                                                </form>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="var r=prompt('<?= e(__('issue_prompt')) ?>'); if(r===null){return false;} this.reason.value=r;">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="report_issue"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="reason" value="">
                                                    <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors"><?= e(__('btn_report_issue')) ?></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-300 dark:text-gray-600">-</span>
                                            <?php endif; ?>
                                        <?php elseif ($o['status'] === 'received' && ($canManage || $ownBranch)): ?>
                                            <button type="button" onclick="var d=document.getElementById('ord-adjust-<?= (int) $o['id'] ?>'); if(d){d.classList.toggle('hidden'); if(!d.classList.contains('hidden')){d.scrollIntoView({behavior:'smooth',block:'nearest'});}}"
                                                    class="px-2.5 py-1 rounded-lg text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_adjust')) ?></button>
                                            <form method="post" onsubmit="var r=prompt('<?= e(__('issue_prompt')) ?>'); if(r===null){return false;} this.reason.value=r;">
                                                <?= csrf_field() ?><input type="hidden" name="action" value="report_issue"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="reason" value="">
                                                <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors"><?= e(__('btn_report_issue')) ?></button>
                                            </form>
                                        <?php elseif (($canManage || $ownBranch) && in_array($o['status'], ['rejected', 'cancelled'], true)): ?>
                                            <form method="post" onsubmit="return confirm('<?= e(__('ord_confirm_delete')) ?>');">
                                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                                <button class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-300 dark:text-gray-600">-</span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr id="ord-items-<?= (int) $o['id'] ?>" class="hidden bg-gray-50/70 dark:bg-gray-900/40">
                                    <td colspan="8" class="px-6 py-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2"><?= e(__('sup_order_items')) ?></p>
                                        <div class="grid gap-x-8 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                                            <?php foreach (($orderItems[(int) $o['id']] ?? []) as $ln): ?>
                                                <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 dark:border-gray-700/60 py-1">
                                                    <span class="text-gray-700 dark:text-gray-200"><?= e($ln['name']) ?></span>
                                                    <span class="font-semibold text-gray-900 dark:text-white"><?= (int) $ln['quantity'] ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php if ($o['status'] === 'received' && ($canManage || $ownBranch)): ?>
                                <tr id="ord-adjust-<?= (int) $o['id'] ?>" class="hidden bg-indigo-50/40 dark:bg-indigo-900/10">
                                    <td colspan="8" class="px-6 py-4">
                                        <form method="post" action="orders.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="adjust_order">
                                            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-500 mb-3"><?= e(__('adj_title')) ?></p>
                                            <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                                <?php foreach (($orderItems[(int) $o['id']] ?? []) as $ln): ?>
                                                    <div class="flex items-center justify-between gap-3 text-sm">
                                                        <span class="text-gray-700 dark:text-gray-200"><?= e($ln['name']) ?></span>
                                                        <input type="number" min="0" name="adjust[<?= (int) $ln['item_id'] ?>]" value="<?= (int) $ln['quantity'] ?>" class="w-24 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"><?= e(__('btn_save_changes')) ?></button>
                                                <span class="text-xs text-gray-400 dark:text-gray-500"><?= e(__('adj_hint')) ?></span>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
