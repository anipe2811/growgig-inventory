<?php
/**
 * feedback.php — "Have a problem? Give opinion."
 *
 * Any logged-in user can report a bug or share an opinion. Each submission is
 * stored in the `feedback` table AND delivered as an in-app notification to the
 * agency team (agency_admin + agency_user).
 *
 * The agency team additionally sees a "Reported Issues" list on this page where
 * they can move each issue through a status: open -> in_progress -> fixed.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$uid      = (int) ($_SESSION['user_id'] ?? 0);
$role     = $_SESSION['user_role'] ?? '';
$isAgency = role_is_agency($role);
$branchId = isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null ? (int) $_SESSION['branch_id'] : 0;

$STATUSES = ['open', 'in_progress', 'fixed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: feedback.php?msg=denied'); exit; }

    $action = $_POST['action'] ?? 'submit';

    /* ---- Agency-only issue management ---- */
    if ($action === 'set_status' || $action === 'delete_issue') {
        if (!$isAgency) { header('Location: feedback.php?msg=denied'); exit; }
        $fid = (int) ($_POST['fid'] ?? 0);
        if ($action === 'set_status') {
            $status = (string) ($_POST['status'] ?? '');
            if ($fid > 0 && in_array($status, $STATUSES, true)) {
                try { $pdo->prepare('UPDATE feedback SET status = ? WHERE id = ?')->execute([$status, $fid]); }
                catch (Throwable $e) { /* best-effort */ }
            }
            header('Location: feedback.php?msg=status');
            exit;
        }
        // delete_issue
        if ($fid > 0) {
            try { $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([$fid]); }
            catch (Throwable $e) { /* best-effort */ }
        }
        header('Location: feedback.php?msg=issue_deleted');
        exit;
    }

    /* ---- Submit a new report ---- */
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if (mb_strlen($subject) > 120)  { $subject = mb_substr($subject, 0, 120); }
    if (mb_strlen($message) > 1000) { $message = mb_substr($message, 0, 1000); }
    if ($message === '') { header('Location: feedback.php?msg=empty'); exit; }

    // Who is reporting (name/email + branch), for the notification body + issue row.
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

    // Store the issue so the agency can track and resolve it.
    try {
        $pdo->prepare('INSERT INTO feedback (user_id, reporter, branch, subject, message) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid ?: null, $who, $branchName ?: null, $subject ?: null, $message]);
    } catch (Throwable $e) { /* best-effort */ }

    // Fit the notifications columns (title varchar(160), body varchar(500)).
    $title = __('fb_notif_title') . ' — ' . ($subject !== '' ? $subject : __('fb_default_subject'));
    if (mb_strlen($title) > 160) { $title = mb_substr($title, 0, 157) . '...'; }
    $body = $who . ($branchName !== '' ? ' (' . $branchName . ')' : '') . ': ' . $message;
    if (mb_strlen($body) > 500) { $body = mb_substr($body, 0, 497) . '...'; }

    notify_roles(['agency_admin', 'agency_user'], 'feedback', $title, $body, 'feedback.php');
    header('Location: feedback.php?msg=sent');
    exit;
}

/* Reported issues (agency team only). Unresolved first, newest first. */
$issues = [];
if ($isAgency) {
    try {
        $issues = $pdo->query("SELECT * FROM feedback ORDER BY (status = 'fixed') ASC, created_at DESC")->fetchAll();
    } catch (Throwable $e) { $issues = []; }
}

$flashMap = [
    'sent'          => ['fb_sent',           'green'],
    'empty'         => ['fb_empty',          'red'],
    'status'        => ['fb_status_updated', 'green'],
    'issue_deleted' => ['fb_issue_deleted',  'green'],
    'denied'        => ['inv_not_allowed',   'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

/* Status badge styling. */
$statusStyle = [
    'open'        => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    'in_progress' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    'fixed'       => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
];
$statusLabel = [
    'open'        => __('fb_status_open'),
    'in_progress' => __('fb_status_in_progress'),
    'fixed'       => __('fb_status_fixed'),
];

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
        <input type="hidden" name="action" value="submit">
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

    <?php if ($isAgency): ?>
        <!-- Reported issues (agency team) -->
        <div class="mt-10">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= e(__('fb_issues_title')) ?></h2>
                <span class="text-xs text-gray-400 dark:text-gray-500"><?= count($issues) ?></span>
            </div>

            <?php if (!$issues): ?>
                <div class="rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    <?= e(__('fb_issues_none')) ?>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($issues as $it): $st = (string) $it['status']; ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $statusStyle[$st] ?? '' ?>">
                                            <?= e($statusLabel[$st] ?? $st) ?>
                                        </span>
                                        <?php if (trim((string) $it['subject']) !== ''): ?>
                                            <span class="font-semibold text-gray-900 dark:text-white truncate"><?= e($it['subject']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line break-words"><?= e($it['message']) ?></p>
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                        <?= e($it['reporter'] ?: '—') ?><?= trim((string) $it['branch']) !== '' ? ' · ' . e($it['branch']) : '' ?>
                                        · <?= e(date('d/m/Y H:i', strtotime($it['created_at']))) ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-2">
                                <?php
                                $btns = [
                                    'open'        => ['fb_set_open',        'text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/30'],
                                    'in_progress' => ['fb_set_in_progress', 'text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/30'],
                                    'fixed'       => ['fb_set_fixed',       'text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/30'],
                                ];
                                foreach ($btns as $stKey => [$lblKey, $cls]):
                                    $isCurrent = ($stKey === $st);
                                ?>
                                    <form method="post" action="feedback.php" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="fid" value="<?= (int) $it['id'] ?>">
                                        <input type="hidden" name="status" value="<?= e($stKey) ?>">
                                        <button type="submit" <?= $isCurrent ? 'disabled' : '' ?>
                                                class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors <?= $isCurrent
                                                    ? 'border-transparent bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 cursor-default'
                                                    : 'border-gray-200 dark:border-gray-600 ' . $cls ?>">
                                            <?= e(__($lblKey)) ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>

                                <form method="post" action="feedback.php" class="inline ml-auto" onsubmit="return confirm('<?= e(__('fb_confirm_delete')) ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_issue">
                                    <input type="hidden" name="fid" value="<?= (int) $it['id'] ?>">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                        <?= e(__('btn_delete')) ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
