<?php
/**
 * includes/notifications_ui.php — shared notification UI helpers used by both the
 * dashboard panel and the Notifications page so they look and behave identically.
 *
 * The "mark as read" / "mark all as read" forms always POST to notifications.php,
 * which performs the update and redirects back to `return` (the calling page).
 */

/** [labelKey, pill/badge classes, icon SVG path] for a notification type. */
function notif_type_meta(string $type): array
{
    $m = [
        'system'   => ['notif_type_system',   'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',  'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        'software' => ['notif_type_software', 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',  'M12 4v12m0 0l-4-4m4 4l4-4M4 20h16'],
        'order'    => ['notif_type_order',    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    ];
    return $m[$type] ?? $m['system'];
}

/** Render one notification card (with a type icon + mark-as-read control). */
function notif_card_html(array $n, string $return): string
{
    [$tk, $tcls, $icon] = notif_type_meta($n['type']);
    $unread = !(int) $n['is_read'];
    $wrap = $unread
        ? 'bg-indigo-50/60 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800'
        : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700';
    ob_start(); ?>
    <div class="rounded-2xl border px-4 sm:px-5 py-4 transition-colors <?= $wrap ?>">
        <div class="flex items-start gap-3">
            <span class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center <?= $tcls ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $tcls ?>"><?= e(__($tk)) ?></span>
                    <?php if ($unread): ?><span class="w-2 h-2 rounded-full bg-indigo-500" title="<?= e(__('notif_view_all')) ?>"></span><?php endif; ?>
                    <span class="font-semibold text-gray-900 dark:text-white">
                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= e($n['link']) ?>" class="hover:underline"><?= e($n['title']) ?></a>
                        <?php else: ?>
                            <?= e($n['title']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($n['body'])): ?>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?= e($n['body']) ?></p>
                <?php endif; ?>
            </div>
            <div class="shrink-0 flex flex-col items-end gap-2">
                <span class="text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap"><?= e(date('d M Y, H:i', strtotime($n['created_at']))) ?></span>
                <?php if ($unread): ?>
                    <form method="post" action="notifications.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_one">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <input type="hidden" name="return" value="<?= e($return) ?>">
                        <button type="submit" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <?= e(__('notif_mark_read')) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 dark:text-gray-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <?= e(__('notif_read')) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Render the "Mark all as read" submit form. */
function notif_mark_all_html(string $return): string
{
    ob_start(); ?>
    <form method="post" action="notifications.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all">
        <input type="hidden" name="return" value="<?= e($return) ?>">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors shadow-sm shadow-indigo-600/20">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <?= e(__('notif_mark_all')) ?>
        </button>
    </form>
    <?php
    return ob_get_clean();
}
