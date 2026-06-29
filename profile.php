<?php
/**
 * profile.php — "My Account": the current user's own details + self-edit.
 * User / supplier management lives in users.php.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? 'account_user';

/* Self-service: edit OWN profile (any logged-in user). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: profile.php?msg=denied'); exit; }
    if (($_POST['action'] ?? '') === 'update_profile') {
        $uid      = (int) $_SESSION['user_id'];
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $pass     = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || ($pass !== '' && strlen($pass) < 6)) {
            header('Location: profile.php?msg=invalid'); exit;
        }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $chk->execute([$email, $uid]);
        if ($chk->fetch()) { header('Location: profile.php?msg=taken'); exit; }

        /* Optional avatar/logo upload — validated as a real image, saved with a safe name. */
        $avatarPath = null;
        if (!empty($_FILES['avatar']['name'])) {
            $f       = $_FILES['avatar'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $info    = ($f['error'] === UPLOAD_ERR_OK) ? @getimagesize($f['tmp_name']) : false;
            if (!$info || !isset($allowed[$info['mime']]) || $f['size'] > 2 * 1024 * 1024) {
                header('Location: profile.php?msg=img_invalid'); exit;
            }
            $dir = __DIR__ . '/assets/avatars';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $fname = 'u' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$info['mime']];
            if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
                $avatarPath = 'assets/avatars/' . $fname;
            }
        }

        $fields = 'name=?, email=?, whatsapp=?';
        $params = [$name, $email, $whatsapp];
        if ($pass !== '')         { $fields .= ', password=?'; $params[] = password_hash($pass, PASSWORD_DEFAULT); }
        if ($avatarPath !== null) { $fields .= ', avatar=?';   $params[] = $avatarPath; }
        $params[] = $uid;
        $pdo->prepare("UPDATE users SET $fields WHERE id=?")->execute($params);

        $_SESSION['user_name'] = $name;
        header('Location: profile.php?msg=profile_updated'); exit;
    }
    header('Location: profile.php'); exit;
}

$user = current_user();
if (!$user) {
    header('Location: login.php?logout=1');
    exit;
}
$initials = strtoupper(mb_substr(trim($user['name']), 0, 1));

if (role_sees_all_branches($user['role'])) {
    $branchDisplay = __('all_branches');
} elseif (!empty($user['branch_id'])) {
    $bs = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
    $bs->execute([$user['branch_id']]);
    $branchDisplay = (string) ($bs->fetchColumn() ?: __('no_branch'));
} else {
    $branchDisplay = __('no_branch');
}

$editProfile = isset($_GET['edit_profile']);

/* Role-aware quick actions (shortcuts to the rest of the app). */
$quickLinks = [];
if (role_can_manage_users($role)) {
    $quickLinks[] = ['users.php', __('nav_users'), 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8z'];
}
if (role_is_supplier($role)) {
    $quickLinks[] = ['supplier.php', __('nav_supplier_orders'), 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'];
} else {
    $quickLinks[] = ['inventory.php', __('nav_inventory'), 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'];
    $quickLinks[] = ['reports.php', __('nav_reports'), 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'];
}
$quickLinks[] = ['notifications.php', __('nav_notifications'), 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'];

$flashMap = [
    'profile_updated' => ['profile_updated',  'green'],
    'img_invalid'     => ['img_invalid',      'red'],
    'taken'           => ['err_email_taken',  'red'],
    'invalid'         => ['err_fill_all',      'red'],
    'denied'          => ['inv_not_allowed',   'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('profile_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('profile_title')) ?></h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('profile_subtitle')) ?></p>

    <?php if ($flash): ?>
        <?php [$fk, $fc] = $flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm
            <?= $fc === 'green'
                ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
                : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>">
            <?= e(__($fk)) ?>
        </div>
    <?php endif; ?>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <!-- Profile card -->
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-colors">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-8 flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= e($user['avatar']) ?>" alt="<?= e($user['name']) ?>" class="w-16 h-16 rounded-full object-cover ring-2 ring-white/40 bg-white shrink-0">
                    <?php else: ?>
                        <div class="w-16 h-16 rounded-full bg-white/20 text-white flex items-center justify-center text-2xl font-bold ring-2 ring-white/40 shrink-0">
                            <?= e($initials) ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-white">
                        <p class="text-xl font-semibold"><?= e($user['name']) ?></p>
                        <span class="inline-flex items-center px-2.5 py-0.5 mt-1 rounded-full text-xs font-semibold bg-white/20">
                            <?= e(role_label($user['role'])) ?>
                        </span>
                    </div>
                </div>
                <?php if (!$editProfile): ?>
                    <a href="profile.php?edit_profile=1" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold ring-1 ring-white/30 transition-colors shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <?= e(__('profile_edit')) ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($editProfile): ?>
                <form method="post" action="profile.php" enctype="multipart/form-data" class="p-6 grid gap-4 sm:grid-cols-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('profile_photo')) ?></label>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= e($user['avatar']) ?>" alt="" class="w-12 h-12 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600 bg-white shrink-0">
                            <?php endif; ?>
                            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif"
                                   class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/40 dark:file:text-indigo-300 hover:file:bg-indigo-100 cursor-pointer">
                        </div>
                        <p class="mt-1 text-xs text-gray-400"><?= e(__('photo_hint')) ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_name')) ?></label>
                        <input type="text" name="name" required value="<?= e($user['name']) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                        <input type="email" name="email" required value="<?= e($user['email']) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_whatsapp')) ?></label>
                        <input type="text" name="whatsapp" value="<?= e($user['whatsapp']) ?>" placeholder="<?= e(__('ph_whatsapp')) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_password')) ?></label>
                        <input type="password" name="password" placeholder="<?= e(__('ph_password_keep')) ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3">
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e(__('btn_save_changes')) ?></button>
                        <a href="profile.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a>
                    </div>
                </form>
            <?php else: ?>
                <dl class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php
                    $rows = [
                        ['label_name',         e($user['name'])],
                        ['label_email',        e($user['email'])],
                        ['label_whatsapp',     e($user['whatsapp'] ?: '-')],
                        ['label_role',         e(role_label($user['role']))],
                        ['label_branch',       e($branchDisplay)],
                        ['label_member_since', e(date('d M Y', strtotime($user['created_at'])))],
                    ];
                    foreach ($rows as [$labelKey, $value]): ?>
                        <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400"><?= e(__($labelKey)) ?></dt>
                            <dd class="mt-1 sm:mt-0 sm:col-span-2 text-sm text-gray-900 dark:text-white"><?= $value ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Quick actions -->
        <div class="space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-bold uppercase tracking-wide text-gray-400 mb-3"><?= e(__('quick_actions')) ?></h3>
                <div class="space-y-1.5">
                    <?php foreach ($quickLinks as [$href, $label, $icon]): ?>
                        <a href="<?= e($href) ?>" class="group flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <span class="flex items-center gap-3">
                                <span class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/></svg>
                                </span>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-200"><?= e($label) ?></span>
                            </span>
                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-indigo-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    <?php endforeach; ?>
                    <a href="?logout=1" class="group flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                        <span class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </span>
                        <span class="text-sm font-medium text-red-600 dark:text-red-400"><?= e(__('nav_logout')) ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
