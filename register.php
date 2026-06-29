<?php
/**
 * register.php — Public "start free trial" / invite page.
 * Self-serve account-user creation is disabled: only agency_admin, agency_user
 * and account_admin create account users (in users.php). New customer accounts
 * are set up with the GrowGig team.
 */
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = __('reg_invite_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-lg mx-auto px-4 py-12 sm:py-16">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8 transition-colors text-center">
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-600/10 text-blue-600 dark:text-blue-400 mb-5">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </span>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white"><?= e(__('reg_invite_title')) ?></h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400"><?= e(__('reg_invite_sub')) ?></p>

        <ul class="mt-6 space-y-3 text-left">
            <?php foreach (['reg_b1', 'reg_b2', 'reg_b3'] as $b): ?>
                <li class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-200">
                    <svg class="w-5 h-5 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?= e(__($b)) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <a href="mailto:hello@growgig.tech?subject=Start%20my%2014-day%20free%20trial%20(GIS)"
           class="mt-7 inline-flex w-full justify-center px-6 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg shadow-blue-600/20">
            <?= e(__('reg_invite_contact')) ?>
        </a>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
            <?= __('register_have_account') ?>
            <a href="login.php" class="text-blue-600 dark:text-blue-400 font-medium hover:underline"><?= __('nav_login') ?></a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
