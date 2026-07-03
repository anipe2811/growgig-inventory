<?php
/**
 * includes/footer.php
 * Closes <main> (and the sidebar wrapper when logged in), renders the footer,
 * and loads the small client-side scripts (dark-mode + sidebar toggle).
 */
?>
</main>

<!-- ===================== Footer ===================== -->
<footer class="mt-auto bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 transition-colors duration-300">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400 text-center sm:text-left">
            &copy; <?= date('Y') ?> <?= e(current_brand()['name']) ?>. <?= __('footer_rights') ?>
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-300 text-center sm:text-right">
            Email
            <?php $brandEmail = current_brand()['email'] ?? 'hello@growgig.tech'; ?>
            <a href="mailto:<?= e($brandEmail) ?>" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline"><?= e($brandEmail) ?></a>
        </p>
    </div>
</footer>

<?php if (is_logged_in()): ?>
    </div><!-- /main column -->
</div><!-- /sidebar + main wrapper -->
<?php endif; ?>

<!-- ===================== Scripts ===================== -->
<script>
    // Toggle dark mode and persist the choice.
    function toggleTheme() {
        var html = document.documentElement;
        html.classList.toggle('dark');
        try { localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
    }

    // Slide the left sidebar in/out on mobile.
    function toggleSidebar() {
        var s = document.getElementById('sidebar');
        var o = document.getElementById('sidebarOverlay');
        if (s) { s.classList.toggle('-translate-x-full'); }
        if (o) { o.classList.toggle('hidden'); }
    }
</script>
</body>
</html>
