<?php
/**
 * includes/header.php
 * -----------------------------------------------------------------------------
 * Opens the HTML document and renders the app chrome:
 *   - Logged in  -> a fixed LEFT SIDEBAR (all nav on the left) + mobile topbar.
 *   - Logged out -> a slim top bar (login / register).
 * Each page sets $pageTitle before including this file.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';

$brand     = current_brand();
$pageTitle = $pageTitle ?? $brand['name'];
$current   = basename($_SERVER['SCRIPT_NAME']);
$activeLang = current_lang();
$role      = $_SESSION['user_role'] ?? '';
$unread    = is_logged_in() ? unread_notification_count() : 0;
$fbUnread  = is_logged_in() ? unread_feedback_count() : 0;

/* Top-bar link classes (logged-out pages). */
function nav_link_class(string $file, string $current): string
{
    $base = 'block px-3 py-2 rounded-lg text-sm font-medium transition-colors';
    return $base . ' ' . ($file === $current
        ? 'bg-indigo-600 text-white'
        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800');
}

/* One sidebar entry (icon + label, active highlight, optional badge). */
function sidebar_link(string $href, string $current, string $label, string $icon, ?int $badge = null, array $activeFiles = []): string
{
    $active = $activeFiles ? in_array($current, $activeFiles, true) : ($href === $current);
    $cls = 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors ' . ($active
        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
        : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60');
    $badgeHtml = ($badge !== null && $badge > 0)
        ? '<span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-[11px] font-bold flex items-center justify-center">' . ($badge > 99 ? '99+' : (int) $badge) . '</span>'
        : '';
    return '<a href="' . e($href) . '" class="' . $cls . '">'
        . '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="' . $icon . '"/></svg>'
        . '<span>' . e($label) . '</span>' . $badgeHtml . '</a>';
}

$IC = [
    'home'   => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    'box'    => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    'swap'   => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
    'truck'  => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM20 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8h4l3 4v4a1 1 0 01-1 1h-1',
    'cart'   => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
    'chart'  => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    'users'  => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8z',
    'bell'   => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    'user'   => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'chat'   => 'M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
    'logout' => 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
];
?>
<!DOCTYPE html>
<html lang="<?= e($activeLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> &middot; <?= e($brand['name']) ?></title>

    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <link rel="icon" type="image/png" href="<?= e($brand['logo']) ?>">
    <?php if ($brand['key'] === 'growgig'): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700&display=swap" rel="stylesheet">
    <style>.brand-font { font-family:'Orbitron',sans-serif; font-weight:600; letter-spacing:.01em }</style>
    <?php endif; ?>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-300 <?= is_logged_in() ? '' : 'flex flex-col' ?>">

<?php if (is_logged_in()): ?>
<div class="lg:flex min-h-screen">

    <!-- ===================== Sidebar ===================== -->
    <aside id="sidebar" class="fixed lg:sticky inset-y-0 left-0 lg:top-0 z-50 h-screen w-64 shrink-0 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-200">
        <!-- Brand -->
        <a href="dashboard.php" class="h-16 flex items-center gap-2 px-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
            <span class="inline-flex items-center justify-center rounded-lg<?= $brand['key'] === 'growgig' ? ' dark:bg-white dark:p-1' : '' ?>">
                <img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>" class="h-11 w-11 object-contain">
            </span>
            <span class="font-semibold text-sm <?= $brand['accent'] ?> leading-tight"><?= e($brand['nav_name']) ?></span>
        </a>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
            <?php if (role_is_supplier($role)): ?>
                <?= sidebar_link('supplier.php', $current, __('nav_supplier_orders'), $IC['box']) ?>
                <?= sidebar_link('notifications.php', $current, __('nav_notifications'), $IC['bell'], $unread) ?>
                <?= sidebar_link('profile.php', $current, __('nav_profile'), $IC['user']) ?>
            <?php else: ?>
                <?php if (role_is_agency($role)): ?>
                    <?= sidebar_link('accounts.php', $current, __('nav_accounts'), $IC['users']) ?>
                <?php endif; ?>
                <?= sidebar_link('dashboard.php', $current, __('nav_dashboard'), $IC['home']) ?>
                <?= sidebar_link('inventory.php', $current, __('nav_inventory'), $IC['box']) ?>
                <?= sidebar_link('stock.php', $current, __('nav_stock'), $IC['swap']) ?>
                <?= sidebar_link('mobile.php', $current, __('nav_mobile'), $IC['truck']) ?>
                <?php if (role_can_use_orders($role)): ?>
                    <?= sidebar_link('orders.php', $current, __('nav_orders'), $IC['cart']) ?>
                <?php endif; ?>
                <?= sidebar_link('reports.php', $current, __('nav_reports'), $IC['chart'], null, ['reports.php', 'stockcard.php', 'inventory_report.php', 'sales_report.php']) ?>
                <?php if (role_can_manage_users($role)): ?>
                    <?= sidebar_link('users.php', $current, __('nav_users'), $IC['users']) ?>
                <?php endif; ?>
                <?= sidebar_link('notifications.php', $current, __('nav_notifications'), $IC['bell'], $unread) ?>
                <?= sidebar_link('feedback.php', $current, __('nav_feedback'), $IC['chat'], $fbUnread) ?>
                <?= sidebar_link('profile.php', $current, __('nav_profile'), $IC['user']) ?>
            <?php endif; ?>
        </nav>

        <!-- Bottom controls -->
        <div class="border-t border-gray-200 dark:border-gray-700 p-3 space-y-2.5 shrink-0">
            <div class="flex items-center justify-between gap-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"><?= e(role_label($role)) ?></span>
                <button type="button" onclick="toggleTheme()" title="<?= e(__('toggle_theme')) ?>" class="p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.71-.71M6.34 6.34l-.71-.71m12.02 0l-.71.71M6.34 17.66l-.71.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
            </div>
            <?php if (role_is_agency($role)): ?>
                <?php $acctList = all_accounts(); $actingId = current_account_id(); ?>
                <form method="get" class="mt-1">
                    <label class="block text-[11px] font-semibold text-gray-400 mb-1"><?= e(__('acct_switcher')) ?></label>
                    <select name="account" onchange="this.form.submit()"
                            class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-indigo-500 outline-none">
                        <option value="0" <?= $actingId === null ? 'selected' : '' ?>><?= e(__('acct_all')) ?></option>
                        <?php foreach ($acctList as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= $actingId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <div class="flex items-center text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <a href="?lang=en" class="flex-1 text-center px-2.5 py-1.5 <?= $activeLang === 'en' ? 'bg-indigo-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">EN</a>
                <a href="?lang=ms" class="flex-1 text-center px-2.5 py-1.5 <?= $activeLang === 'ms' ? 'bg-indigo-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">MY</a>
            </div>
            <a href="?logout=1" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $IC['logout'] ?>"/></svg>
                <span><?= __('nav_logout') ?></span>
            </a>
        </div>
    </aside>

    <!-- Mobile overlay -->
    <div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 z-40 bg-black/40 hidden lg:hidden"></div>

    <!-- ===================== Main column ===================== -->
    <div class="flex-1 min-w-0 flex flex-col min-h-screen">
        <!-- Mobile topbar -->
        <header class="lg:hidden sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 bg-white/90 dark:bg-gray-800/90 backdrop-blur border-b border-gray-200 dark:border-gray-700">
            <button type="button" onclick="toggleSidebar()" aria-label="<?= e(__('menu')) ?>" class="p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <span class="font-semibold text-sm <?= $brand['accent'] ?>"><?= e($brand['nav_name']) ?></span>
            <a href="notifications.php" class="relative p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $IC['bell'] ?>"/></svg>
                <?php if ($unread > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
            </a>
        </header>

        <main class="flex-1 w-full">
            <?php if (is_impersonating()): ?>
                <div class="sticky top-0 z-50 bg-amber-500 text-white text-sm font-semibold px-4 py-2 flex items-center justify-center gap-3">
                    <span><?= e(__('imp_banner')) ?> <?= e($_SESSION['user_name'] ?? '') ?></span>
                    <a href="?stop_impersonate=1" class="underline hover:no-underline"><?= e(__('imp_exit')) ?></a>
                </div>
            <?php endif; ?>
<?php else: ?>
    <!-- ===================== Logged-out top bar ===================== -->
    <nav class="bg-white/90 dark:bg-gray-800/90 backdrop-blur border-b border-gray-200 dark:border-gray-700 sticky top-0 z-40 transition-colors">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <a href="index.php" class="flex items-center gap-1.5 shrink-0">
                <span class="inline-flex items-center justify-center rounded-xl dark:bg-white dark:p-1 dark:ring-1 dark:ring-gray-700">
                    <img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>" class="h-14 w-14 object-contain">
                </span>
                <?php if ($brand['key'] === 'growgig'): ?>
                <span class="leading-tight">
                    <span class="block brand-font text-xl leading-none pb-1 bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e($brand['nav_name']) ?></span>
                    <span class="block text-[10.5px] font-medium tracking-wide text-gray-400 dark:text-gray-500"><?= e(__('gg_brand_tagline')) ?></span>
                </span>
                <?php else: ?>
                <span class="font-semibold <?= $brand['accent'] ?> hidden sm:block"><?= e($brand['nav_name']) ?></span>
                <?php endif; ?>
            </a>
            <div class="flex items-center gap-2">
                <a href="login.php"    class="<?= nav_link_class('login.php', $current) ?>"><?= __('nav_login') ?></a>
                <a href="register.php" class="<?= nav_link_class('register.php', $current) ?>"><?= __('nav_register') ?></a>
                <div class="hidden sm:flex items-center text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden ml-1">
                    <a href="?lang=en" class="px-2.5 py-1 <?= $activeLang === 'en' ? 'bg-indigo-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">EN</a>
                    <a href="?lang=ms" class="px-2.5 py-1 <?= $activeLang === 'ms' ? 'bg-indigo-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">MY</a>
                </div>
                <button type="button" onclick="toggleTheme()" title="<?= e(__('toggle_theme')) ?>" class="p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.71-.71M6.34 6.34l-.71-.71m12.02 0l-.71.71M6.34 17.66l-.71.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
            </div>
        </div>
    </nav>
    <main class="flex-1 w-full">
<?php endif; ?>
