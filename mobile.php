<?php
/**
 * mobile.php — Mobile "on-the-go" stock for therapist trips.
 *
 * A branch operator records that a therapist TAKES stock to standby for a mobile
 * session. Taking stock immediately decrements the item balance (a real 'out'
 * movement). On settlement the operator keys in how many were SOLD; the unsold
 * remainder is RETURNED (a real 'in' movement that adds the balance back). The
 * net effect on inventory is therefore exactly the quantity sold.
 *
 * Every movement is tagged with stock_movements.trip_id so sales_report.php can
 * net out returns and avoid counting taken-but-returned stock as a sale.
 *
 * Recording is limited to account_user (branch operator), mirroring stock.php;
 * all other roles get a read-only view. Branch scope follows the same rules.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role       = $_SESSION['user_role'] ?? 'account_user';
$frozen     = stock_frozen_for($role);
$canEdit    = ($role === 'account_user') && !$frozen;   // only the branch operator records
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
 * Apply actions (POST): create | settle | cancel.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($frozen) {
        header('Location: mobile.php?msg=frozen');
        exit;
    }
    if (!$canEdit || $noBranch || !csrf_verify()) {
        header('Location: mobile.php?msg=denied');
        exit;
    }
    $branchId = $userBranch; // account_user is locked to its own branch
    if (!in_array($branchId, $validBranchIds, true)) {
        header('Location: mobile.php?msg=denied');
        exit;
    }

    /* Shared prepared statements (same column conventions as stock.php / orders.php). */
    $mv  = $pdo->prepare('INSERT INTO stock_movements (item_id, branch_id, type, quantity, balance_after, movement_date, user_id, trip_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $inc = $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ? AND branch_id = ?');
    $dec = $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ? AND branch_id = ? AND quantity >= ?');
    $get = $pdo->prepare('SELECT quantity FROM items WHERE id = ? AND branch_id = ?');

    /* ---- Take stock to standby (open a new trip). ---- */
    if ($action === 'create') {
        $therapist = trim((string) ($_POST['therapist_name'] ?? ''));
        $note      = trim((string) ($_POST['note'] ?? ''));
        if ($therapist === '') {
            header('Location: mobile.php?msg=denied');
            exit;
        }
        if (mb_strlen($therapist) > 120) { $therapist = mb_substr($therapist, 0, 120); }
        if (mb_strlen($note) > 300)      { $note = mb_substr($note, 0, 300); }

        $dateIn   = (string) ($_POST['trip_date'] ?? '');
        $d        = DateTime::createFromFormat('Y-m-d', $dateIn);
        $tripDate = ($d && $d->format('Y-m-d') === $dateIn) ? $dateIn : date('Y-m-d');

        $take = is_array($_POST['take'] ?? null) ? $_POST['take'] : [];

        $pdo->beginTransaction();
        try {
            // Lock the branch's items, capturing current quantity + price snapshot.
            $istmt = $pdo->prepare('SELECT id, quantity, price FROM items WHERE branch_id = ? FOR UPDATE');
            $istmt->execute([$branchId]);
            $cur = [];
            foreach ($istmt->fetchAll() as $r) {
                $cur[(int) $r['id']] = ['qty' => (int) $r['quantity'], 'price' => (float) $r['price']];
            }

            // Keep only positive takes for items that exist in this branch.
            $lines = [];
            foreach ($take as $id => $q) {
                $id = (int) $id; $q = max(0, (int) $q);
                if ($q === 0 || !isset($cur[$id])) { continue; }
                $lines[$id] = $q;
            }
            if (!$lines) {
                $pdo->rollBack();
                header('Location: mobile.php?msg=none');
                exit;
            }
            // Validate every take against available stock before writing anything.
            foreach ($lines as $id => $q) {
                if ($q > $cur[$id]['qty']) {
                    $pdo->rollBack();
                    header('Location: mobile.php?msg=insufficient');
                    exit;
                }
            }

            // Merge into an existing OPEN trip for the same therapist + same date (same
            // branch); otherwise start a new trip. Same date = one group, different date = separate.
            $find = $pdo->prepare('SELECT id FROM mobile_trips WHERE branch_id = ? AND status = "open" AND therapist_name = ? AND trip_date = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $find->execute([$branchId, $therapist, $tripDate]);
            $tripId = (int) ($find->fetchColumn() ?: 0);
            if ($tripId === 0) {
                $ins = $pdo->prepare('INSERT INTO mobile_trips (branch_id, therapist_name, status, note, trip_date, created_by) VALUES (?, ?, "open", ?, ?, ?)');
                $ins->execute([$branchId, $therapist, $note !== '' ? $note : null, $tripDate, $_SESSION['user_id']]);
                $tripId = (int) $pdo->lastInsertId();
            } elseif ($note !== '') {
                // Append any new note to the existing trip.
                $pdo->prepare('UPDATE mobile_trips SET note = TRIM(CONCAT(COALESCE(note, ""), " ", ?)) WHERE id = ?')->execute([$note, $tripId]);
            }

            // Existing lines for this trip, keyed by item, so re-takes merge instead of duplicating.
            $exg = $pdo->prepare('SELECT id, item_id FROM mobile_trip_items WHERE trip_id = ?');
            $exg->execute([$tripId]);
            $lineByItem = [];
            foreach ($exg->fetchAll() as $r) { $lineByItem[(int) $r['item_id']] = (int) $r['id']; }

            $insLine = $pdo->prepare('INSERT INTO mobile_trip_items (trip_id, item_id, qty_taken, qty_sold, qty_returned, unit_price) VALUES (?, ?, ?, 0, 0, ?)');
            $addQty  = $pdo->prepare('UPDATE mobile_trip_items SET qty_taken = qty_taken + ? WHERE id = ?');
            foreach ($lines as $id => $q) {
                $dec->execute([$q, $id, $branchId, $q]);
                if ($dec->rowCount() === 0) { throw new RuntimeException('insufficient'); } // lost a race
                $get->execute([$id, $branchId]);
                $bal = (int) $get->fetchColumn();
                $mv->execute([$id, $branchId, 'out', $q, $bal, $tripDate, $_SESSION['user_id'], $tripId]);
                if (isset($lineByItem[$id])) {
                    $addQty->execute([$q, $lineByItem[$id]]);
                } else {
                    $insLine->execute([$tripId, $id, $q, $cur[$id]['price']]);
                    $lineByItem[$id] = (int) $pdo->lastInsertId();
                }
            }
            $pdo->commit();
            header('Location: mobile.php?msg=saved');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $short = ($e instanceof RuntimeException && $e->getMessage() === 'insufficient');
            header('Location: mobile.php?msg=' . ($short ? 'insufficient' : 'denied'));
            exit;
        }
    }

    /* ---- Settle (sold/returned) or Cancel (return everything) an open trip. ---- */
    if ($action === 'settle' || $action === 'cancel') {
        $tripId = (int) ($_POST['trip_id'] ?? 0);
        $retIn  = is_array($_POST['returned'] ?? null) ? $_POST['returned'] : [];

        // Date the returned stock comes back (defaults to today). Used as the
        // 'in' movement date so the Stock Card / Sales Report line up correctly.
        $rdIn       = (string) ($_POST['return_date'] ?? '');
        $rd         = DateTime::createFromFormat('Y-m-d', $rdIn);
        $returnDate = ($rd && $rd->format('Y-m-d') === $rdIn) ? $rdIn : date('Y-m-d');

        $pdo->beginTransaction();
        try {
            // Claim the open trip so a double-submit cannot return stock twice.
            $sel = $pdo->prepare('SELECT id FROM mobile_trips WHERE id = ? AND status = "open" AND branch_id = ? FOR UPDATE');
            $sel->execute([$tripId, $branchId]);
            if (!$sel->fetch()) {
                $pdo->rollBack();
                header('Location: mobile.php?msg=denied');
                exit;
            }

            $ls = $pdo->prepare('SELECT id, item_id, qty_taken FROM mobile_trip_items WHERE trip_id = ?');
            $ls->execute([$tripId]);
            $updLine = $pdo->prepare('UPDATE mobile_trip_items SET qty_sold = ?, qty_returned = ? WHERE id = ?');

            foreach ($ls->fetchAll() as $ln) {
                $lid   = (int) $ln['id'];
                $iid   = (int) $ln['item_id'];
                $taken = (int) $ln['qty_taken'];
                if ($action === 'cancel') {
                    $returned = $taken;            // cancel returns everything
                } else {
                    $returned = max(0, (int) ($retIn[$lid] ?? 0));
                    if ($returned > $taken) { $returned = $taken; }
                }
                $sold = $taken - $returned;        // sold is auto-derived
                if ($returned > 0) {
                    $inc->execute([$returned, $iid, $branchId]);
                    if ($inc->rowCount() > 0) {              // item may have been deleted meanwhile
                        $get->execute([$iid, $branchId]);
                        $bal = (int) $get->fetchColumn();
                        $mv->execute([$iid, $branchId, 'in', $returned, $bal, $returnDate, $_SESSION['user_id'], $tripId]);
                    }
                }
                $updLine->execute([$sold, $returned, $lid]);
            }

            $status = $action === 'cancel' ? 'cancelled' : 'settled';
            $pdo->prepare('UPDATE mobile_trips SET status = ?, return_date = ?, settled_by = ?, settled_at = NOW() WHERE id = ?')
                ->execute([$status, $returnDate, $_SESSION['user_id'], $tripId]);
            $pdo->commit();
            header('Location: mobile.php?msg=' . ($action === 'cancel' ? 'cancelled' : 'settled'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: mobile.php?msg=denied');
            exit;
        }
    }

    /* ---- Edit a finished (settled/cancelled) trip: adjust returned/sold, therapist, date. ---- */
    if ($action === 'edit') {
        $tripId    = (int) ($_POST['trip_id'] ?? 0);
        $retIn     = is_array($_POST['returned'] ?? null) ? $_POST['returned'] : [];
        $therapist = trim((string) ($_POST['therapist_name'] ?? ''));
        if ($therapist === '') {
            header('Location: mobile.php?msg=denied');
            exit;
        }
        if (mb_strlen($therapist) > 120) { $therapist = mb_substr($therapist, 0, 120); }

        $rdIn       = (string) ($_POST['return_date'] ?? '');
        $rd         = DateTime::createFromFormat('Y-m-d', $rdIn);
        $returnDate = ($rd && $rd->format('Y-m-d') === $rdIn) ? $rdIn : date('Y-m-d');

        $pdo->beginTransaction();
        try {
            // Lock the finished trip so a concurrent edit/delete cannot race.
            $sel = $pdo->prepare('SELECT id FROM mobile_trips WHERE id = ? AND branch_id = ? AND status IN ("settled","cancelled") FOR UPDATE');
            $sel->execute([$tripId, $branchId]);
            if (!$sel->fetch()) {
                $pdo->rollBack();
                header('Location: mobile.php?msg=denied');
                exit;
            }

            $ls = $pdo->prepare('SELECT id, item_id, qty_taken, qty_returned FROM mobile_trip_items WHERE trip_id = ?');
            $ls->execute([$tripId]);

            // The take ('out') movement is unchanged; only the return ('in') is re-stated per item.
            $delMv   = $pdo->prepare('DELETE FROM stock_movements WHERE trip_id = ? AND item_id = ? AND type = "in"');
            $updLine = $pdo->prepare('UPDATE mobile_trip_items SET qty_sold = ?, qty_returned = ? WHERE id = ?');

            foreach ($ls->fetchAll() as $ln) {
                $lid = (int) $ln['id'];
                if (!array_key_exists($lid, $retIn)) { continue; } // line not on the form (e.g. deleted item) → leave as-is
                $iid    = (int) $ln['item_id'];
                $get->execute([$iid, $branchId]);
                if ($get->fetch() === false) { continue; }          // item deleted in a race → skip safely

                $taken  = (int) $ln['qty_taken'];
                $oldRet = (int) $ln['qty_returned'];
                $newRet = max(0, (int) $retIn[$lid]);
                if ($newRet > $taken) { $newRet = $taken; }
                $newSold = $taken - $newRet;
                $delta   = $newRet - $oldRet;       // change in stock left on hand

                if ($delta > 0) {
                    $inc->execute([$delta, $iid, $branchId]);
                } elseif ($delta < 0) {
                    $need = -$delta;                 // selling more = pulling that stock back out
                    $dec->execute([$need, $iid, $branchId, $need]);
                    if ($dec->rowCount() === 0) { throw new RuntimeException('insufficient'); }
                }

                // Re-state the return movement so the Stock Card / Sales Report stay exact.
                $delMv->execute([$tripId, $iid]);
                if ($newRet > 0) {
                    $get->execute([$iid, $branchId]);
                    $bal = (int) $get->fetchColumn();
                    $mv->execute([$iid, $branchId, 'in', $newRet, $bal, $returnDate, $_SESSION['user_id'], $tripId]);
                }
                $updLine->execute([$newSold, $newRet, $lid]);
            }

            // Re-classify: a trip with any sold is "settled", otherwise "cancelled".
            $sum = $pdo->prepare('SELECT COALESCE(SUM(qty_sold),0) FROM mobile_trip_items WHERE trip_id = ?');
            $sum->execute([$tripId]);
            $status = ((int) $sum->fetchColumn() > 0) ? 'settled' : 'cancelled';

            $pdo->prepare('UPDATE mobile_trips SET therapist_name = ?, return_date = ?, status = ?, settled_by = ?, settled_at = NOW() WHERE id = ?')
                ->execute([$therapist, $returnDate, $status, $_SESSION['user_id'], $tripId]);
            $pdo->commit();
            header('Location: mobile.php?msg=edited');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $short = ($e instanceof RuntimeException && $e->getMessage() === 'insufficient');
            header('Location: mobile.php?msg=' . ($short ? 'insufficient' : 'denied'));
            exit;
        }
    }

    /* ---- Delete a finished trip: reverse its net inventory effect, then remove it. ---- */
    if ($action === 'delete') {
        $tripId = (int) ($_POST['trip_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT id FROM mobile_trips WHERE id = ? AND branch_id = ? AND status IN ("settled","cancelled") FOR UPDATE');
            $sel->execute([$tripId, $branchId]);
            if (!$sel->fetch()) {
                $pdo->rollBack();
                header('Location: mobile.php?msg=denied');
                exit;
            }

            // Net effect of the trip on inventory was -(sold). Undo it by adding sold back.
            $ls = $pdo->prepare('SELECT item_id, qty_taken, qty_returned FROM mobile_trip_items WHERE trip_id = ?');
            $ls->execute([$tripId]);
            foreach ($ls->fetchAll() as $ln) {
                $iid    = (int) $ln['item_id'];
                $netOut = (int) $ln['qty_taken'] - (int) $ln['qty_returned']; // = qty sold (>= 0)
                if ($netOut > 0) {
                    $inc->execute([$netOut, $iid, $branchId]); // no-op if the item was deleted meanwhile
                }
            }

            $pdo->prepare('DELETE FROM stock_movements WHERE trip_id = ?')->execute([$tripId]);
            $pdo->prepare('DELETE FROM mobile_trip_items WHERE trip_id = ?')->execute([$tripId]);
            $pdo->prepare('DELETE FROM mobile_trips WHERE id = ?')->execute([$tripId]);
            $pdo->commit();
            header('Location: mobile.php?msg=deleted');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: mobile.php?msg=denied');
            exit;
        }
    }

    header('Location: mobile.php?msg=denied');
    exit;
}

/* -------------------------------------------------------------------------
 * View data.
 * ---------------------------------------------------------------------- */
/* Items for the "take stock" table. */
$items = [];
if (!$noBranch && $selectedBranch) {
    $st = $pdo->prepare('SELECT id, name, category, quantity, price FROM items WHERE branch_id = ? ORDER BY category ASC, sort_order ASC, name ASC');
    $st->execute([$selectedBranch]);
    $items = $st->fetchAll();
}

/* Open (active) trips + their lines. */
$openTrips   = [];
$linesByTrip = [];
if (!$noBranch && $selectedBranch) {
    $st = $pdo->prepare('SELECT * FROM mobile_trips WHERE branch_id = ? AND status = "open" ORDER BY id DESC');
    $st->execute([$selectedBranch]);
    $openTrips = $st->fetchAll();
    if ($openTrips) {
        $ids = array_map(static fn($t) => (int) $t['id'], $openTrips);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $ls  = $pdo->prepare(
            "SELECT li.id, li.trip_id, li.item_id, li.qty_taken, li.unit_price, i.name
             FROM mobile_trip_items li JOIN items i ON i.id = li.item_id
             WHERE li.trip_id IN ($in) ORDER BY li.id ASC"
        );
        $ls->execute($ids);
        foreach ($ls->fetchAll() as $r) { $linesByTrip[(int) $r['trip_id']][] = $r; }
    }
}

/* Recent settled / cancelled trips with totals. */
$history = [];
if (!$noBranch && $selectedBranch) {
    $st = $pdo->prepare(
        'SELECT t.id, t.therapist_name, t.status, t.trip_date, t.return_date, t.settled_at,
                COALESCE(SUM(li.qty_taken),0)               AS taken,
                COALESCE(SUM(li.qty_sold),0)                AS sold,
                COALESCE(SUM(li.qty_returned),0)            AS returned,
                COALESCE(SUM(li.qty_sold * li.unit_price),0) AS value
         FROM mobile_trips t LEFT JOIN mobile_trip_items li ON li.trip_id = t.id
         WHERE t.branch_id = ? AND t.status IN ("settled","cancelled")
         GROUP BY t.id ORDER BY t.id DESC LIMIT 25'
    );
    $st->execute([$selectedBranch]);
    $history = $st->fetchAll();
}

/* Per-item lines for the history trips (expandable detail view + edit pre-fill). */
$histLinesByTrip = [];
if ($history) {
    $hids = array_map(static fn($h) => (int) $h['id'], $history);
    $in   = implode(',', array_fill(0, count($hids), '?'));
    $hls  = $pdo->prepare(
        "SELECT li.id, li.trip_id, li.item_id, li.qty_taken, li.qty_sold, li.qty_returned, li.unit_price, i.name
         FROM mobile_trip_items li JOIN items i ON i.id = li.item_id
         WHERE li.trip_id IN ($in) ORDER BY li.id ASC"
    );
    $hls->execute($hids);
    foreach ($hls->fetchAll() as $r) { $histLinesByTrip[(int) $r['trip_id']][] = $r; }
}

$flashMap = [
    'saved'        => ['mob_saved',        'green'],
    'settled'      => ['mob_settled',      'green'],
    'cancelled'    => ['mob_cancelled',    'green'],
    'edited'       => ['mob_edited',       'green'],
    'deleted'      => ['mob_deleted',      'green'],
    'none'         => ['mob_none',         'red'],
    'insufficient' => ['mob_insufficient', 'red'],
    'denied'       => ['inv_not_allowed',  'red'],
    'frozen'       => ['stock_frozen',     'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('mob_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('mob_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('mob_subtitle')) ?></p>
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
            <form method="get" action="mobile.php" class="mt-6 flex items-center gap-2">
                <label for="branch" class="text-sm font-medium text-gray-600 dark:text-gray-400"><?= e(__('label_branch')) ?>:</label>
                <select id="branch" name="branch" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $selectedBranch === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <!-- ============ (a) New trip: take stock to standby ============ -->
        <?php if ($canEdit): ?>
            <form method="post" action="mobile.php" class="mt-6">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white mb-4"><?= e(__('mob_new')) ?></h2>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('mob_therapist')) ?></label>
                                <input type="text" name="therapist_name" required maxlength="120" placeholder="<?= e(__('ph_therapist')) ?>"
                                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_date')) ?></label>
                                <input type="date" name="trip_date" value="<?= e(date('Y-m-d')) ?>"
                                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('mob_note')) ?></label>
                                <input type="text" name="note" maxlength="300" placeholder="<?= e(__('ph_mob_note')) ?>"
                                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                                    <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_balance')) ?></th>
                                    <th class="px-4 py-3 font-semibold text-center text-indigo-600 dark:text-indigo-400"><?= e(__('col_take')) ?></th>
                                    <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_new_balance')) ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php if (!$items): ?>
                                    <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('inv_empty')) ?></td></tr>
                                <?php else: ?>
                                    <?php $lastGroup = null; ?>
                                    <?php foreach ($items as $item):
                                        $bal = (int) $item['quantity'];
                                        $cat = trim((string) ($item['category'] ?? ''));
                                        if ($cat !== $lastGroup): $lastGroup = $cat; ?>
                                            <tr class="bg-gray-100/80 dark:bg-gray-900/60">
                                                <td colspan="4" class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                    <?= e($cat !== '' ? $cat : __('uncategorized')) ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($item['name']) ?></td>
                                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300 cur-bal" data-bal="<?= $bal ?>"><?= e($bal) ?></td>
                                            <td class="px-4 py-2.5 text-center">
                                                <input type="number" min="0" max="<?= $bal ?>" name="take[<?= (int) $item['id'] ?>]" placeholder="0"
                                                       class="take-in w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                            </td>
                                            <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white take-newbal"><?= e($bal) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($items): ?>
                        <div class="p-4 sm:p-5 border-t border-gray-100 dark:border-gray-700 flex justify-end">
                            <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                                <?= e(__('btn_take')) ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <!-- ============ (b) Active trips: settle / cancel ============ -->
        <div class="mt-8">
            <h2 class="font-semibold text-gray-900 dark:text-white mb-3"><?= e(__('mob_open')) ?></h2>
            <?php if (!$openTrips): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    <?= e(__('mob_empty_open')) ?>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($openTrips as $trip): $tid = (int) $trip['id']; $tlines = $linesByTrip[$tid] ?? []; ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700">
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white"><?= e($trip['therapist_name']) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        <?= e(date('d/m/Y', strtotime($trip['trip_date']))) ?>
                                        <?php if (!empty($trip['note'])): ?> &middot; <?= e($trip['note']) ?><?php endif; ?>
                                    </p>
                                </div>
                                <span class="self-start inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                    <?= e(__('status_open')) ?>
                                </span>
                            </div>

                            <form method="post" action="mobile.php" class="settle-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="settle">
                                <input type="hidden" name="trip_id" value="<?= $tid ?>">
                                <?php if ($canEdit): ?>
                                    <div class="px-4 sm:px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-2">
                                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= e(__('mob_return_date')) ?>:</label>
                                        <input type="date" name="return_date" value="<?= e(date('Y-m-d')) ?>"
                                               class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                    </div>
                                <?php endif; ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                                <th class="px-4 py-3 font-semibold"><?= e(__('col_name')) ?></th>
                                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_taken')) ?></th>
                                                <th class="px-4 py-3 font-semibold text-center text-blue-600 dark:text-blue-400"><?= e(__('col_returned')) ?></th>
                                                <th class="px-4 py-3 font-semibold text-right text-emerald-600 dark:text-emerald-400"><?= e(__('col_sold')) ?></th>
                                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('mob_value')) ?></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <?php foreach ($tlines as $ln): $taken = (int) $ln['qty_taken']; ?>
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                                    <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($ln['name']) ?></td>
                                                    <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300 taken-cell" data-taken="<?= $taken ?>"><?= $taken ?></td>
                                                    <td class="px-4 py-2.5 text-center">
                                                        <input type="number" min="0" max="<?= $taken ?>" name="returned[<?= (int) $ln['id'] ?>]" value="0" <?= $canEdit ? '' : 'disabled' ?>
                                                               class="ret-in w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-blue-500 outline-none transition disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed dark:disabled:bg-gray-800/60">
                                                    </td>
                                                    <td class="px-4 py-2.5 text-right font-semibold text-emerald-600 dark:text-emerald-400 sold-cell"><?= $taken ?></td>
                                                    <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white val-cell" data-price="<?= (float) $ln['unit_price'] ?>">RM <?= e(number_format($taken * (float) $ln['unit_price'], 2)) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($canEdit): ?>
                                    <div class="p-4 sm:p-5 border-t border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-end gap-3">
                                        <button type="submit"
                                                formaction="mobile.php"
                                                onclick="this.form.querySelector('input[name=action]').value='cancel'; return confirm('<?= e(__('mob_confirm_cancel')) ?>');"
                                                class="px-4 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                            <?= e(__('btn_cancel_trip')) ?>
                                        </button>
                                        <button type="submit"
                                                onclick="this.form.querySelector('input[name=action]').value='settle';"
                                                class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                                            <?= e(__('btn_settle')) ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ (c) Trip history ============ -->
        <div class="mt-8">
            <h2 class="font-semibold text-gray-900 dark:text-white mb-3"><?= e(__('mob_history')) ?></h2>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 font-semibold"><?= e(__('label_date')) ?></th>
                                <th class="px-4 py-3 font-semibold"><?= e(__('mob_therapist')) ?></th>
                                <th class="px-4 py-3 font-semibold"><?= e(__('bill_status')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_taken')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right text-blue-600 dark:text-blue-400"><?= e(__('col_returned')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right text-emerald-600 dark:text-emerald-400"><?= e(__('col_sold')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('mob_value')) ?></th>
                                <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!$history): ?>
                                <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('mob_empty_history')) ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h):
                                    $tid         = (int) $h['id'];
                                    $hlines      = $histLinesByTrip[$tid] ?? [];
                                    $isCancelled = $h['status'] === 'cancelled';
                                    $defRet      = $h['return_date'] ?: ($h['settled_at'] ? date('Y-m-d', strtotime($h['settled_at'])) : $h['trip_date']);
                                ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">
                                            <?= e(date('d/m/Y', strtotime($defRet))) ?>
                                        </td>
                                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($h['therapist_name']) ?></td>
                                        <td class="px-4 py-2.5">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                                <?= $isCancelled
                                                    ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                                                    : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' ?>">
                                                <?= $isCancelled ? e(__('status_cancelled')) : e(__('status_settled')) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300"><?= (int) $h['taken'] ?></td>
                                        <td class="px-4 py-2.5 text-right text-blue-600 dark:text-blue-400"><?= (int) $h['returned'] ?></td>
                                        <td class="px-4 py-2.5 text-right text-emerald-600 dark:text-emerald-400"><?= (int) $h['sold'] ?></td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white">RM <?= e(number_format((float) $h['value'], 2)) ?></td>
                                        <td class="px-4 py-2.5 text-right">
                                            <button type="button" class="hist-toggle inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-medium hover:underline"
                                                    data-target="hist-<?= $tid ?>" aria-expanded="false">
                                                <svg class="hist-chevron w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                <?= e(__('mob_details')) ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="hist-<?= $tid ?>" class="hist-detail hidden bg-gray-50/70 dark:bg-gray-900/40">
                                        <td colspan="8" class="px-4 py-4">
                                            <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2"><?= e(__('mob_items')) ?></p>
                                            <?php if ($canEdit): ?>
                                                <form method="post" action="mobile.php" class="settle-form" onsubmit="return confirm('<?= e(__('mob_confirm_edit')) ?>');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="edit">
                                                    <input type="hidden" name="trip_id" value="<?= $tid ?>">
                                                    <div class="flex flex-wrap items-end gap-3 mb-3">
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('mob_therapist')) ?></label>
                                                            <input type="text" name="therapist_name" value="<?= e($h['therapist_name']) ?>" required maxlength="120"
                                                                   class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= e(__('mob_return_date')) ?></label>
                                                            <input type="date" name="return_date" value="<?= e(date('Y-m-d', strtotime($defRet))) ?>"
                                                                   class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                                                        </div>
                                                    </div>
                                                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm bg-white dark:bg-gray-800">
                                                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                                                    <th class="px-4 py-2 font-semibold"><?= e(__('col_name')) ?></th>
                                                                    <th class="px-4 py-2 font-semibold text-right"><?= e(__('col_taken')) ?></th>
                                                                    <th class="px-4 py-2 font-semibold text-center text-blue-600 dark:text-blue-400"><?= e(__('col_returned')) ?></th>
                                                                    <th class="px-4 py-2 font-semibold text-right text-emerald-600 dark:text-emerald-400"><?= e(__('col_sold')) ?></th>
                                                                    <th class="px-4 py-2 font-semibold text-right"><?= e(__('mob_value')) ?></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                                <?php foreach ($hlines as $ln): $tk = (int) $ln['qty_taken']; $rt = (int) $ln['qty_returned']; $sd = (int) $ln['qty_sold']; ?>
                                                                    <tr>
                                                                        <td class="px-4 py-2 font-medium text-gray-900 dark:text-white"><?= e($ln['name']) ?></td>
                                                                        <td class="px-4 py-2 text-right text-gray-700 dark:text-gray-300 taken-cell" data-taken="<?= $tk ?>"><?= $tk ?></td>
                                                                        <td class="px-4 py-2 text-center">
                                                                            <input type="number" min="0" max="<?= $tk ?>" name="returned[<?= (int) $ln['id'] ?>]" value="<?= $rt ?>"
                                                                                   class="ret-in w-20 text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-blue-500 outline-none transition">
                                                                        </td>
                                                                        <td class="px-4 py-2 text-right font-semibold text-emerald-600 dark:text-emerald-400 sold-cell"><?= $sd ?></td>
                                                                        <td class="px-4 py-2 text-right font-semibold text-gray-900 dark:text-white val-cell" data-price="<?= (float) $ln['unit_price'] ?>">RM <?= e(number_format($sd * (float) $ln['unit_price'], 2)) ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="flex flex-wrap items-center justify-end gap-3 mt-3">
                                                        <button type="submit"
                                                                class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                                                            <?= e(__('mob_save_changes')) ?>
                                                        </button>
                                                    </div>
                                                </form>
                                                <form method="post" action="mobile.php" class="mt-2 flex justify-end" onsubmit="return confirm('<?= e(__('mob_confirm_delete')) ?>');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="trip_id" value="<?= $tid ?>">
                                                    <button type="submit"
                                                            class="px-4 py-2.5 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                                                        <?= e(__('mob_delete_trip')) ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm bg-white dark:bg-gray-800">
                                                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                                                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                                                <th class="px-4 py-2 font-semibold"><?= e(__('col_name')) ?></th>
                                                                <th class="px-4 py-2 font-semibold text-right"><?= e(__('col_taken')) ?></th>
                                                                <th class="px-4 py-2 font-semibold text-right text-blue-600 dark:text-blue-400"><?= e(__('col_returned')) ?></th>
                                                                <th class="px-4 py-2 font-semibold text-right text-emerald-600 dark:text-emerald-400"><?= e(__('col_sold')) ?></th>
                                                                <th class="px-4 py-2 font-semibold text-right"><?= e(__('mob_value')) ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                            <?php if (!$hlines): ?>
                                                                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">&mdash;</td></tr>
                                                            <?php else: ?>
                                                                <?php foreach ($hlines as $ln): $sd = (int) $ln['qty_sold']; ?>
                                                                    <tr>
                                                                        <td class="px-4 py-2 font-medium text-gray-900 dark:text-white"><?= e($ln['name']) ?></td>
                                                                        <td class="px-4 py-2 text-right text-gray-700 dark:text-gray-300"><?= (int) $ln['qty_taken'] ?></td>
                                                                        <td class="px-4 py-2 text-right text-blue-600 dark:text-blue-400"><?= (int) $ln['qty_returned'] ?></td>
                                                                        <td class="px-4 py-2 text-right text-emerald-600 dark:text-emerald-400"><?= $sd ?></td>
                                                                        <td class="px-4 py-2 text-right font-semibold text-gray-900 dark:text-white">RM <?= e(number_format($sd * (float) $ln['unit_price'], 2)) ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </td>
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
    // Live projections: take "new balance" = balance − take; settle "returned" = taken − sold.
    document.addEventListener('input', function (ev) {
        var t = ev.target;
        if (!t.classList) { return; }
        var row = t.closest('tr');
        if (!row) { return; }

        if (t.classList.contains('take-in')) {
            var bal  = parseInt(row.querySelector('.cur-bal')?.dataset.bal || '0', 10) || 0;
            var take = parseInt(t.value || '0', 10) || 0;
            var cell = row.querySelector('.take-newbal');
            if (cell) {
                var nb = bal - take;
                cell.textContent = nb;
                cell.classList.toggle('text-amber-600', nb < 0);
            }
        }

        if (t.classList.contains('ret-in')) {
            var taken = parseInt(row.querySelector('.taken-cell')?.dataset.taken || '0', 10) || 0;
            var ret   = parseInt(t.value || '0', 10) || 0;
            if (ret < 0) { ret = 0; }
            if (ret > taken) { ret = taken; }
            var sold = taken - ret;
            var sc = row.querySelector('.sold-cell');
            if (sc) { sc.textContent = sold; }
            var vc = row.querySelector('.val-cell');
            if (vc) {
                var price = parseFloat(vc.dataset.price || '0') || 0;
                vc.textContent = 'RM ' + (sold * price).toFixed(2);
            }
        }
    });

    // Initialise the "sold" / value cells on load (returned defaults to 0 → sold = taken).
    document.querySelectorAll('.settle-form .ret-in').forEach(function (inp) {
        inp.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Trip-history: expand / collapse the per-item detail (+ edit) row.
    document.querySelectorAll('.hist-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = document.getElementById(btn.dataset.target);
            if (!row) { return; }
            var open = row.classList.toggle('hidden') === false;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            var chev = btn.querySelector('.hist-chevron');
            if (chev) { chev.style.transform = open ? 'rotate(180deg)' : ''; }
        });
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
