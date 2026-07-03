<?php
/**
 * login.php — Native authentication (email + password_verify).
 */
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = __('err_csrf');
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = __('err_fill_all');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Populate the session keys.
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;
                $_SESSION['account_id'] = $user['account_id'] !== null ? (int) $user['account_id'] : null;
                $_SESSION['lang']      = current_lang();

                header('Location: dashboard.php');
                exit;
            }
            $error = __('login_err_invalid');
        }
    }
}

$pageTitle = __('login_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-4 py-12 sm:py-16">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8 transition-colors">
        <div class="flex justify-center mb-5">
            <span class="inline-flex items-center justify-center rounded-2xl dark:bg-white dark:p-3 dark:ring-1 dark:ring-gray-700">
                <img src="<?= current_brand()['key'] === 'growgig' ? 'assets/logo-growgig-full.png' : e(current_brand()['logo']) ?>" alt="<?= e(current_brand()['name']) ?>" class="h-28 w-auto object-contain">
            </span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white"><?= e(__('login_title')) ?></h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('login_subtitle')) ?></p>

        <?php if (isset($_GET['registered'])): ?>
            <div class="mt-5 rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-700 dark:text-green-300">
                <?= e(__('login_success_reg')) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mt-5 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" class="mt-6 space-y-4" novalidate>
            <?= csrf_field() ?>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_email')) ?></label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus
                       placeholder="<?= e(__('ph_email')) ?>"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('label_password')) ?></label>
                <input type="password" id="password" name="password" required
                       placeholder="<?= e(__('ph_password')) ?>"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition">
            </div>
            <button type="submit" class="w-full py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                <?= __('btn_login') ?>
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
            <?= __('login_no_account') ?>
            <a href="register.php" class="text-indigo-600 dark:text-indigo-400 font-medium hover:underline"><?= __('login_register_link') ?></a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
