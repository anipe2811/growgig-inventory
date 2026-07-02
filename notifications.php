<?php
/**
 * notifications.php — In-app notifications (system / software / order).
 *
 * Notifications stay UNREAD until the user explicitly marks them read (per item
 * or "mark all"), so the sidebar badge is a true unread indicator. This page is
 * also the POST endpoint used by the dashboard notifications panel; after an
 * update it redirects back to `return` (dashboard.php or notifications.php).
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_once __DIR__ . '/includes/notifications_ui.php';

$uid  = (int) $_SESSION['user_id'];
// Agency sees only system-management notices; branch/order noise is hidden.
$typeFilter = notif_type_filter_sql($_SESSION['user_role'] ?? '');

/* Mark-as-read actions (shared by this page and the dashboard panel). */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return = (($_POST['return'] ?? '') === 'dashboard.php') ? 'dashboard.php' : 'notifications.php';
    if (csrf_verify()) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_all') {
            // Only mark what this role actually sees (agency: management types only).
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0' . $typeFilter)->execute([$uid]);
        } elseif ($action === 'mark_one') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                ->execute([(int) ($_POST['id'] ?? 0), $uid]);
        }
    }
    header('Location: ' . $return);
    exit;
}

/* Full list (newest first). */
$stmt = $pdo->prepare('SELECT id, type, title, body, link, is_read, created_at FROM notifications WHERE user_id = ?' . $typeFilter . ' ORDER BY id DESC LIMIT 100');
$stmt->execute([$uid]);
$list = $stmt->fetchAll();

$unreadCount = 0;
foreach ($list as $n) { if (!(int) $n['is_read']) { $unreadCount++; } }

$pageTitle = __('notif_title');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('notif_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('notif_sub')) ?></p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <?= notif_mark_all_html('notifications.php') ?>
        <?php endif; ?>
    </div>

    <div class="mt-6 space-y-3">
        <?php if (!$list): ?>
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                <?= e(__('notif_none')) ?>
            </div>
        <?php else: ?>
            <?php foreach ($list as $n): ?>
                <?= notif_card_html($n, 'notifications.php') ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
