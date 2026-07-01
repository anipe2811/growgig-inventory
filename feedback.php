<?php
/**
 * feedback.php — "Have a problem? Give opinion."
 *
 * Any logged-in user can report a bug or share an opinion. The message is
 * delivered as an in-app notification to the agency team (agency_admin +
 * agency_user), so feedback lands straight in the agency account.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$uid      = (int) ($_SESSION['user_id'] ?? 0);
$branchId = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: feedback.php?msg=denied'); exit; }

    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if (mb_strlen($subject) > 120)  { $subject = mb_substr($subject, 0, 120); }
    if (mb_strlen($message) > 1000) { $message = mb_substr($message, 0, 1000); }
    if ($message === '') { header('Location: feedback.php?msg=empty'); exit; }

    // Who is reporting (name/email + branch), for the notification body.
    $who = 'User #' . $uid;
    try {
        $s = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $s->execute([$uid]);
        if ($r = $s->fetch()) { $who = (string) ($r['name'] ?: ($r['email'] ?: $who)); }
    } catch (Throwable $e) { /* best-effort */ }

    $branchName = '';
    if ($branchId) {
        try {
            $b = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
            $b->execute([$branchId]);
            $branchName = (string) $b->fetchColumn();
        } catch (Throwable $e) { /* best-effort */ }
    }

    // Fit the notifications columns (title varchar(160), body varchar(500)).
    $title = __('fb_notif_title') . ' — ' . ($subject !== '' ? $subject : __('fb_default_subject'));
    if (mb_strlen($title) > 160) { $title = mb_substr($title, 0, 157) . '...'; }
    $body = $who . ($branchName !== '' ? ' (' . $branchName . ')' : '') . ': ' . $message;
    if (mb_strlen($body) > 500) { $body = mb_substr($body, 0, 497) . '...'; }

    notify_roles(['agency_admin', 'agency_user'], 'feedback', $title, $body, 'notifications.php');
    header('Location: feedback.php?msg=sent');
    exit;
}

$flashMap = [
    'sent'   => ['fb_sent',          'green'],
    'empty'  => ['fb_empty',         'red'],
    'denied' => ['inv_not_allowed',  'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('nav_feedback');
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('nav_feedback')) ?></h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('fb_sub')) ?></p>
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

    <form method="post" action="feedback.php" class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6 space-y-4"
          onsubmit="return this.message.value.trim() !== '';">
        <?= csrf_field() ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('fb_subject')) ?></label>
            <input type="text" name="subject" maxlength="120" placeholder="<?= e(__('ph_fb_subject')) ?>"
                   class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('fb_message')) ?></label>
            <textarea name="message" rows="6" required maxlength="1000" placeholder="<?= e(__('ph_fb_message')) ?>"
                      class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition"></textarea>
        </div>
        <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-gray-400 dark:text-gray-500"><?= e(__('fb_hint')) ?></span>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20">
                <?= e(__('fb_send')) ?>
            </button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
