<?php
/**
 * includes/account_gate.php
 * Rendered in place of a stock-data page when an agency user has not yet
 * selected a company. Include this and exit; it renders header + prompt + footer.
 */
$accts = all_accounts();
$pageTitle = __('pick_acct_title');
require __DIR__ . '/header.php';
?>
<section class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
        <div class="mx-auto w-14 h-14 rounded-2xl bg-indigo-50 dark:bg-indigo-900/40 flex items-center justify-center mb-5">
            <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m2-14h2m-2 4h2m6-4h2m-2 4h2"/></svg>
        </div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white"><?= e(__('pick_acct_title')) ?></h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400"><?= e(__('pick_acct_sub')) ?></p>
        <?php if ($accts): ?>
            <form method="get" class="mt-6 flex flex-col sm:flex-row items-stretch justify-center gap-2 max-w-md mx-auto">
                <select name="account" required
                        class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2.5 focus:ring-2 focus:ring-indigo-500 outline-none">
                    <option value="" disabled selected><?= e(__('select_account')) ?></option>
                    <?php foreach ($accts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e(__('pick_acct_cta')) ?></button>
            </form>
        <?php else: ?>
            <a href="accounts.php" class="mt-6 inline-flex px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors"><?= e(__('nav_accounts')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
