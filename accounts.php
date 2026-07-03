<?php
/**
 * accounts.php — Agency-only: manage all customer accounts (tenants).
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!role_is_agency($role)) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: accounts.php?msg=denied'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account' || $action === 'update_account') {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['contact_email'] ?? '');
        $wa      = trim($_POST['whatsapp'] ?? '');
        $brand   = trim($_POST['brand_name'] ?? '');
        if ($name === '') { header('Location: accounts.php?msg=invalid'); exit; }
        if ($action === 'create_account') {
            $pdo->prepare('INSERT INTO accounts (name, brand_name, contact_email, whatsapp) VALUES (?, ?, ?, ?)')
                ->execute([$name, $brand ?: $name, $email ?: null, $wa ?: null]);
            $aid = (int) $pdo->lastInsertId();
            try {
                $pdo->prepare("INSERT INTO subscriptions (account_id, plan, status, trial_ends_at) VALUES (?, 'trial', 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY))")
                    ->execute([$aid]);
            } catch (Throwable $e) { /* subscription optional in P1 */ }
            header('Location: accounts.php?msg=added'); exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE accounts SET name=?, brand_name=?, contact_email=?, whatsapp=? WHERE id=?')
            ->execute([$name, $brand ?: $name, $email ?: null, $wa ?: null, $id]);
        header('Location: accounts.php?msg=updated'); exit;
    }

    if ($action === 'delete_account') {
        $id = (int) ($_POST['id'] ?? 0);
        $bc = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE account_id = ?'); $bc->execute([$id]);
        $uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE account_id = ?');    $uc->execute([$id]);
        if ((int) $bc->fetchColumn() > 0 || (int) $uc->fetchColumn() > 0) {
            header('Location: accounts.php?msg=in_use'); exit;
        }
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM subscriptions WHERE account_id = ?')->execute([$id]);
        header('Location: accounts.php?msg=deleted'); exit;
    }

    if ($action === 'set_sub' && role_is_super($role)) {
        $id    = (int) ($_POST['id'] ?? 0);
        $state = in_array($_POST['state'] ?? '', ['active', 'trial', 'frozen'], true) ? $_POST['state'] : '';
        if ($id > 0 && $state !== '') {
            if ($state === 'trial') {
                $pdo->prepare("UPDATE subscriptions SET status='trial', trial_ends_at = DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE account_id = ?")->execute([$id]);
            } else {
                $pdo->prepare('UPDATE subscriptions SET status=? WHERE account_id = ?')->execute([$state, $id]);
            }
        }
        header('Location: accounts.php?msg=sub'); exit;
    }
    header('Location: accounts.php'); exit;
}

$editAccount = null;
if (isset($_GET['edit'])) {
    $ea = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $ea->execute([(int) $_GET['edit']]);
    $editAccount = $ea->fetch() ?: null;
}

$rows = $pdo->query(
    "SELECT a.id, a.name, a.contact_email,
            (SELECT COUNT(*) FROM branches b WHERE b.account_id = a.id) AS branches,
            (SELECT COUNT(*) FROM users u WHERE u.account_id = a.id AND u.role = 'account_user') AS seats,
            (SELECT s.status FROM subscriptions s WHERE s.account_id = a.id LIMIT 1) AS sub_status,
            (SELECT s.price_per_user FROM subscriptions s WHERE s.account_id = a.id LIMIT 1) AS price_per_user
     FROM accounts a ORDER BY a.name ASC"
)->fetchAll();

$flashMap = [
    'added'   => ['acct_added',   'green'],
    'updated' => ['acct_updated', 'green'],
    'deleted' => ['acct_deleted', 'green'],
    'in_use'  => ['acct_in_use',  'red'],
    'invalid' => ['err_fill_all', 'red'],
    'denied'  => ['inv_not_allowed', 'red'],
    'sub'     => ['sub_updated',  'green'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('nav_accounts');
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('acct_title')) ?></h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('acct_sub')) ?></p>

    <?php if ($flash): ?><?php [$fk,$fc]=$flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm <?= $fc==='green'
            ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
            : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>"><?= e(__($fk)) ?></div>
    <?php endif; ?>

    <div id="acct-form" class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4"><?= e($editAccount ? __('acct_edit') : __('acct_add')) ?></h3>
        <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editAccount ? 'update_account' : 'create_account' ?>">
            <?php if ($editAccount): ?><input type="hidden" name="id" value="<?= (int) $editAccount['id'] ?>"><?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_name')) ?></label>
                <input type="text" name="name" required value="<?= e($editAccount['name'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_brand')) ?></label>
                <input type="text" name="brand_name" value="<?= e($editAccount['brand_name'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_email')) ?></label>
                <input type="email" name="contact_email" value="<?= e($editAccount['contact_email'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_whatsapp')) ?></label>
                <input type="text" name="whatsapp" value="<?= e($editAccount['whatsapp'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex items-center gap-3">
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($editAccount ? __('btn_save_changes') : __('acct_add')) ?></button>
                <?php if ($editAccount): ?><a href="accounts.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('acct_name')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('card_branches')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('bill_seats')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('bill_status')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('bill_monthly')) ?></th>
                        <?php if (role_is_super($role)): ?><th class="px-4 py-3 font-semibold"><?= e(__('col_billing')) ?></th><?php endif; ?>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= role_is_super($role) ? 7 : 6 ?>" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('acct_none')) ?></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <?php $monthly = (int) $r['seats'] * (float) ($r['price_per_user'] ?? 29.90); ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($r['name']) ?></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"><?= (int) $r['branches'] ?></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"><?= (int) $r['seats'] ?></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($r['sub_status'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">RM <?= e(number_format($monthly, 2)) ?></td>
                            <?php if (role_is_super($role)): ?>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1">
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_sub"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="state" value="active">
                                        <button type="submit" class="px-2 py-1 rounded text-xs font-medium bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 transition-colors"><?= e(__('btn_activate')) ?></button>
                                    </form>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_sub"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="state" value="trial">
                                        <button type="submit" class="px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_reset_trial')) ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Freeze stock in/out now?');"><?= csrf_field() ?><input type="hidden" name="action" value="set_sub"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="state" value="frozen">
                                        <button type="submit" class="px-2 py-1 rounded text-xs font-medium bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 transition-colors"><?= e(__('btn_freeze')) ?></button>
                                    </form>
                                </div>
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="accounts.php?account=<?= (int) $r['id'] ?>" class="px-2.5 py-1 rounded-lg text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors"><?= e(__('acct_enter')) ?></a>
                                    <a href="accounts.php?edit=<?= (int) $r['id'] ?>#acct-form" class="px-2.5 py-1 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_edit')) ?></a>
                                    <form method="post" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
