<?php
/**
 * users.php — "User": account-user management + supplier accounts + subscription.
 * Managers only (agency_admin / agency_user / account_admin). Split out of
 * profile.php ("My Account"), which now only shows the user's own details.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? 'account_user';
if (!role_can_manage_users($role)) {
    header('Location: dashboard.php');
    exit;
}

$acctId = current_account_id();
if ($acctId === null && role_is_agency($role)) {
    $pageTitle = __('nav_users');
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('users_title')) ?></h1>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400"><?= e(__('acct_pick_first')) ?></p>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$branches       = $pdo->prepare('SELECT id, name, location FROM branches WHERE account_id = ? ORDER BY name ASC');
$branches->execute([$acctId]);
$branches       = $branches->fetchAll();
$validBranchIds = array_map(static fn($b) => (int) $b['id'], $branches);

/* -------------------------------------------------------------------------
 * POST actions.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: users.php?msg=denied'); exit; }

    // Agency must have an acting account selected before mutating account data.
    if (role_is_agency($role) && $acctId === null) {
        header('Location: users.php?msg=pick_account'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $branch = (int) ($_POST['branch_id'] ?? 0);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6 || !in_array($branch, $validBranchIds, true)) {
            header('Location: users.php?msg=invalid'); exit;
        }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) { header('Location: users.php?msg=taken'); exit; }
        $pdo->prepare('INSERT INTO users (name, email, password, whatsapp, role, branch_id, account_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), '', 'account_user', $branch, $acctId]);
        header('Location: users.php?msg=added'); exit;
    }

    if ($action === 'update_user') {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $branch = (int) ($_POST['branch_id'] ?? 0);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($branch, $validBranchIds, true) || ($pass !== '' && strlen($pass) < 6)) {
            header('Location: users.php?msg=invalid'); exit;
        }
        $t = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'account_user'");
        $t->execute([$id]);
        if (!$t->fetch()) { header('Location: users.php?msg=denied'); exit; }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $chk->execute([$email, $id]);
        if ($chk->fetch()) { header('Location: users.php?msg=taken'); exit; }
        if ($pass !== '') {
            $pdo->prepare("UPDATE users SET name=?, email=?, branch_id=?, password=? WHERE id=? AND role='account_user' AND account_id = ?")
                ->execute([$name, $email, $branch, password_hash($pass, PASSWORD_DEFAULT), $id, $acctId]);
        } else {
            $pdo->prepare("UPDATE users SET name=?, email=?, branch_id=? WHERE id=? AND role='account_user' AND account_id = ?")
                ->execute([$name, $email, $branch, $id, $acctId]);
        }
        header('Location: users.php?msg=user_updated'); exit;
    }

    if ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'account_user' AND account_id = ?")->execute([$id, $acctId]);
        header('Location: users.php?msg=deleted'); exit;
    }

    // Subscription controls — scoped to the current account only.
    if ($action === 'sub_activate' && in_array($role, ['agency_admin', 'account_admin'], true)) {
        $pdo->prepare("UPDATE subscriptions SET status='active' WHERE account_id = ?")->execute([$acctId]);
        header('Location: users.php?msg=sub'); exit;
    }
    if ($action === 'sub_trial' && $role === 'agency_admin') {
        $pdo->prepare("UPDATE subscriptions SET status='trial', trial_ends_at = DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE account_id = ?")->execute([$acctId]);
        header('Location: users.php?msg=sub'); exit;
    }
    if ($action === 'sub_freeze' && $role === 'agency_admin') {
        $pdo->prepare("UPDATE subscriptions SET status='frozen' WHERE account_id = ?")->execute([$acctId]);
        header('Location: users.php?msg=sub'); exit;
    }

    // Supplier logins.
    if ($action === 'create_supplier_user') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $supplier = (int) ($_POST['supplier_id'] ?? 0);
        $chkSup   = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? AND account_id = ?');
        $chkSup->execute([$supplier, $acctId]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6 || !$chkSup->fetch()) {
            header('Location: users.php?msg=invalid'); exit;
        }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) { header('Location: users.php?msg=taken'); exit; }
        $pdo->prepare('INSERT INTO users (name, email, password, whatsapp, role, branch_id, supplier_id, account_id) VALUES (?, ?, ?, ?, "supplier", NULL, ?, ?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), '', $supplier, $acctId]);
        header('Location: users.php?msg=sup_added'); exit;
    }

    if ($action === 'update_supplier_user') {
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $supplier = (int) ($_POST['supplier_id'] ?? 0);
        $chkSup   = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? AND account_id = ?');
        $chkSup->execute([$supplier, $acctId]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$chkSup->fetch() || ($pass !== '' && strlen($pass) < 6)) {
            header('Location: users.php?msg=invalid'); exit;
        }
        $t = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'supplier'");
        $t->execute([$id]);
        if (!$t->fetch()) { header('Location: users.php?msg=denied'); exit; }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $chk->execute([$email, $id]);
        if ($chk->fetch()) { header('Location: users.php?msg=taken'); exit; }
        if ($pass !== '') {
            $pdo->prepare("UPDATE users SET name=?, email=?, supplier_id=?, password=? WHERE id=? AND role='supplier' AND account_id = ?")
                ->execute([$name, $email, $supplier, password_hash($pass, PASSWORD_DEFAULT), $id, $acctId]);
        } else {
            $pdo->prepare("UPDATE users SET name=?, email=?, supplier_id=? WHERE id=? AND role='supplier' AND account_id = ?")
                ->execute([$name, $email, $supplier, $id, $acctId]);
        }
        header('Location: users.php?msg=sup_user_updated'); exit;
    }

    if ($action === 'delete_supplier_user') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'supplier' AND account_id = ?")->execute([$id, $acctId]);
        header('Location: users.php?msg=sup_deleted'); exit;
    }

    // Branches.
    if ($action === 'add_branch') {
        $name = trim($_POST['name'] ?? '');
        $loc  = trim($_POST['location'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO branches (name, location, account_id) VALUES (?, ?, ?)')->execute([$name, $loc !== '' ? $loc : null, $acctId]);
        }
        header('Location: users.php?msg=branch_added'); exit;
    }
    if ($action === 'delete_branch') {
        $id = (int) ($_POST['id'] ?? 0);
        $uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE branch_id = ?'); $uc->execute([$id]);
        $ic = $pdo->prepare('SELECT COUNT(*) FROM items WHERE branch_id = ?'); $ic->execute([$id]);
        if ((int) $uc->fetchColumn() > 0 || (int) $ic->fetchColumn() > 0) {
            header('Location: users.php?msg=branch_in_use'); exit;
        }
        $pdo->prepare('DELETE FROM branches WHERE id = ? AND account_id = ?')->execute([$id, $acctId]);
        header('Location: users.php?msg=branch_removed'); exit;
    }

    // Suppliers (records).
    if ($action === 'add_supplier') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO suppliers (name, email, phone, account_id) VALUES (?, ?, ?, ?)')
                ->execute([$name, trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''), $acctId]);
        }
        header('Location: users.php?msg=supplier_added'); exit;
    }
    if ($action === 'delete_supplier') {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND account_id = ?')->execute([(int) ($_POST['id'] ?? 0), $acctId]);
        header('Location: users.php?msg=supplier_removed'); exit;
    }

    header('Location: users.php'); exit;
}

/* -------------------------------------------------------------------------
 * Data.
 * ---------------------------------------------------------------------- */
$accountUsersStmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.created_at, b.name AS branch
     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
     WHERE u.role = 'account_user' AND u.account_id = ? ORDER BY u.id DESC"
);
$accountUsersStmt->execute([$acctId]);
$accountUsers = $accountUsersStmt->fetchAll();
$seats   = count($accountUsers);
$sub     = subscription_state();
$monthly = $seats * $sub['price'];
$statusMap = [
    'trial'  => ['status_trial',  'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    'active' => ['status_active', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
    'frozen' => ['status_frozen', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
];
[$stKey, $stClass] = $statusMap[$sub['status']] ?? $statusMap['trial'];

$suppliersStmt = $pdo->prepare('SELECT id, name, email, phone FROM suppliers WHERE account_id = ? ORDER BY name ASC');
$suppliersStmt->execute([$acctId]);
$suppliers     = $suppliersStmt->fetchAll();
$supplierUsersStmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.created_at, s.name AS supplier
     FROM users u LEFT JOIN suppliers s ON s.id = u.supplier_id
     WHERE u.role = 'supplier' AND u.account_id = ? ORDER BY u.id DESC"
);
$supplierUsersStmt->execute([$acctId]);
$supplierUsers = $supplierUsersStmt->fetchAll();

$editUser = null;
if (isset($_GET['edit_user'])) {
    $eu = $pdo->prepare("SELECT id, name, email, branch_id FROM users WHERE id = ? AND role = 'account_user' AND account_id = ?");
    $eu->execute([(int) $_GET['edit_user'], $acctId]);
    $editUser = $eu->fetch() ?: null;
}
$editSupplierUser = null;
if (isset($_GET['edit_supplier_user'])) {
    $es = $pdo->prepare("SELECT id, name, email, supplier_id FROM users WHERE id = ? AND role = 'supplier' AND account_id = ?");
    $es->execute([(int) $_GET['edit_supplier_user'], $acctId]);
    $editSupplierUser = $es->fetch() ?: null;
}

$flashMap = [
    'added'            => ['users_added',         'green'],
    'deleted'          => ['users_deleted',       'green'],
    'sub'              => ['sub_updated',          'green'],
    'sup_added'        => ['sup_account_added',    'green'],
    'sup_deleted'      => ['sup_account_deleted',  'green'],
    'user_updated'     => ['user_updated',         'green'],
    'sup_user_updated' => ['sup_account_updated',  'green'],
    'branch_added'     => ['branch_added',         'green'],
    'branch_removed'   => ['branch_removed',       'green'],
    'branch_in_use'    => ['branch_in_use',        'red'],
    'supplier_added'   => ['sup_added',            'green'],
    'supplier_removed' => ['sup_removed',          'green'],
    'taken'            => ['err_email_taken',      'red'],
    'invalid'          => ['err_fill_all',         'red'],
    'denied'           => ['inv_not_allowed',      'red'],
    'pick_account'     => ['acct_pick_first',      'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('nav_users');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('users_title')) ?></h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('users_subtitle')) ?></p>

    <?php if ($flash): ?>
        <?php [$fk, $fc] = $flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm
            <?= $fc === 'green'
                ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
                : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>">
            <?= e(__($fk)) ?>
        </div>
    <?php endif; ?>

    <!-- Subscription / billing panel -->
    <div class="mt-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-5 flex-1">
                <div>
                    <p class="text-xs text-gray-400 mb-1"><?= e(__('bill_status')) ?></p>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?= $stClass ?>"><?= e(__($stKey)) ?></span>
                    <?php if ($sub['status'] === 'trial'): ?>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= (int) $sub['days_left'] ?> <?= e(__('bill_days_left')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-xs text-gray-400 mb-1"><?= e(__('bill_seats')) ?></p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= (int) $seats ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-400 mb-1">RM <?= e(number_format($sub['price'], 2)) ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?= e(__('bill_per_user')) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-400 mb-1"><?= e(__('bill_monthly')) ?></p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">RM <?= e(number_format($monthly, 2)) ?></p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if (in_array($role, ['agency_admin', 'account_admin'], true) && $sub['status'] !== 'active'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sub_activate">
                        <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors"><?= e(__('btn_activate')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($role === 'agency_admin'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sub_trial">
                        <button class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_reset_trial')) ?></button>
                    </form>
                    <?php if ($sub['status'] !== 'frozen'): ?>
                        <form method="post" onsubmit="return confirm('Freeze stock in/out now?');"><?= csrf_field() ?><input type="hidden" name="action" value="sub_freeze">
                            <button class="px-4 py-2 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 text-sm font-medium hover:bg-red-100 transition-colors"><?= e(__('btn_freeze')) ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <p class="mt-4 text-xs text-gray-400"><?= e(__('bill_charged')) ?></p>
        <?php if ($sub['frozen']): ?>
            <div class="mt-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-2.5 text-sm text-red-700 dark:text-red-300"><?= e(__('bill_frozen_note')) ?></div>
        <?php endif; ?>
    </div>

    <!-- Branches (add / list) -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('card_branches')) ?></h3>
            <button type="button" onclick="var f=document.getElementById('branch-form'); if(f){f.classList.toggle('hidden');}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-semibold hover:bg-gray-800 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <?= e(__('branch_add')) ?>
            </button>
        </div>
        <div id="branch-form" class="hidden mt-4">
            <form method="post" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_branch">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_branch')) ?></label>
                    <input type="text" name="name" required placeholder="<?= e(__('ph_branch_name')) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_location')) ?></label>
                    <input type="text" name="location" placeholder="<?= e(__('ph_location')) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e(__('branch_add')) ?></button>
                </div>
            </form>
        </div>
        <?php if ($branches): ?>
            <div class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($branches as $b): ?>
                    <span class="inline-flex items-center gap-2 pl-3 pr-2 py-1.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 ring-1 ring-gray-200 dark:ring-gray-700 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200"><?= e($b['name']) ?></span>
                        <?php if (!empty($b['location'])): ?><span class="text-gray-400 text-xs"><?= e($b['location']) ?></span><?php endif; ?>
                        <form method="post" class="inline" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_branch"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button type="submit" title="<?= e(__('btn_delete')) ?>" class="p-1 rounded text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create / edit account user -->
    <div id="user-form" class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4"><?= e($editUser ? __('users_edit') : __('users_add')) ?></h3>
        <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editUser ? 'update_user' : 'create_user' ?>">
            <?php if ($editUser): ?><input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>"><?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_name')) ?></label>
                <input type="text" name="name" required value="<?= e($editUser['name'] ?? '') ?>" placeholder="<?= e(__('ph_name')) ?>"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                <input type="email" name="email" required value="<?= e($editUser['email'] ?? '') ?>" placeholder="<?= e(__('ph_email')) ?>"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_password')) ?></label>
                <input type="password" name="password" <?= $editUser ? '' : 'required' ?> placeholder="<?= e($editUser ? __('ph_password_keep') : __('ph_password')) ?>"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_branch')) ?></label>
                <select name="branch_id" required
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    <option value=""><?= e(__('select_branch')) ?></option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= ($editUser && (int) $editUser['branch_id'] === (int) $b['id']) ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex items-center gap-3">
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($editUser ? __('btn_save_changes') : __('btn_create_user')) ?></button>
                <?php if ($editUser): ?><a href="users.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Account users list -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('label_name')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('label_email')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_branch')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_created')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$accountUsers): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('users_none')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($accountUsers as $u): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($u['name']) ?></td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($u['email']) ?></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($u['branch'] ?: '-') ?></td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="users.php?edit_user=<?= (int) $u['id'] ?>#user-form" class="px-2.5 py-1 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_edit')) ?></a>
                                        <form method="post" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===================== Supplier accounts ===================== -->
    <div class="mt-10">
        <h2 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white"><?= e(__('sup_accounts_title')) ?></h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('sup_accounts_sub')) ?></p>
    </div>

    <!-- Suppliers (add / list) -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white"><?= e(__('sup_title')) ?></h3>
            <button type="button" onclick="var f=document.getElementById('supplier-record-form'); if(f){f.classList.toggle('hidden');}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-semibold hover:bg-gray-800 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <?= e(__('sup_add')) ?>
            </button>
        </div>
        <div id="supplier-record-form" class="hidden mt-4">
            <form method="post" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_supplier">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('sup_name')) ?></label>
                    <input type="text" name="name" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                    <input type="email" name="email" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('sup_phone')) ?></label>
                    <input type="text" name="phone" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e(__('sup_save')) ?></button>
                </div>
            </form>
        </div>
        <?php if ($suppliers): ?>
            <div class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($suppliers as $sp): ?>
                    <span class="inline-flex items-center gap-2 pl-3 pr-2 py-1.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 ring-1 ring-gray-200 dark:ring-gray-700 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200"><?= e($sp['name']) ?></span>
                        <?php if (!empty($sp['phone'])): ?><span class="text-gray-400 text-xs"><?= e($sp['phone']) ?></span><?php endif; ?>
                        <form method="post" class="inline" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?= (int) $sp['id'] ?>">
                            <button type="submit" title="<?= e(__('btn_delete')) ?>" class="p-1 rounded text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create / edit supplier login -->
    <div id="supplier-user-form" class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4"><?= e($editSupplierUser ? __('sup_account_edit') : __('sup_account_add')) ?></h3>
        <?php if (!$suppliers): ?>
            <p class="text-sm text-amber-600 dark:text-amber-400"><?= e(__('sup_none')) ?></p>
        <?php else: ?>
            <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editSupplierUser ? 'update_supplier_user' : 'create_supplier_user' ?>">
                <?php if ($editSupplierUser): ?><input type="hidden" name="id" value="<?= (int) $editSupplierUser['id'] ?>"><?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_name')) ?></label>
                    <input type="text" name="name" required value="<?= e($editSupplierUser['name'] ?? '') ?>" placeholder="<?= e(__('ph_name')) ?>"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                    <input type="email" name="email" required value="<?= e($editSupplierUser['email'] ?? '') ?>" placeholder="<?= e(__('ph_email')) ?>"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_password')) ?></label>
                    <input type="password" name="password" <?= $editSupplierUser ? '' : 'required' ?> placeholder="<?= e($editSupplierUser ? __('ph_password_keep') : __('ph_password')) ?>"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('sup_link')) ?></label>
                    <select name="supplier_id" required
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <option value=""><?= e(__('sup_select')) ?></option>
                        <?php foreach ($suppliers as $sp): ?>
                            <option value="<?= (int) $sp['id'] ?>" <?= ($editSupplierUser && (int) $editSupplierUser['supplier_id'] === (int) $sp['id']) ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-4 flex items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($editSupplierUser ? __('btn_save_changes') : __('btn_create_supplier')) ?></button>
                    <?php if ($editSupplierUser): ?><a href="users.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Supplier accounts list -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('label_name')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('label_email')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_supplier')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('col_created')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$supplierUsers): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('sup_user_none')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($supplierUsers as $su): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($su['name']) ?></td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($su['email']) ?></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($su['supplier'] ?: '-') ?></td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e(date('d/m/Y', strtotime($su['created_at']))) ?></td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="users.php?edit_supplier_user=<?= (int) $su['id'] ?>#supplier-user-form" class="px-2.5 py-1 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_edit')) ?></a>
                                        <form method="post" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_supplier_user">
                                            <input type="hidden" name="id" value="<?= (int) $su['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
