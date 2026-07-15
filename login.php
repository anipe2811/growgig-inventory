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
$brand = current_brand();
require __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-4 py-8 sm:py-12">
    <div class="ao-card overflow-hidden grid md:grid-cols-2 ao-fade">

        <!-- Hero panel (desktop) -->
        <div class="relative hidden md:flex flex-col justify-between p-8 lg:p-10 text-white overflow-hidden" style="background:var(--hero-grad)">
            <div class="pointer-events-none absolute -right-16 -bottom-14 w-80 h-80 rounded-full opacity-10" style="background:repeating-linear-gradient(-35deg,#fff 0 22px,transparent 22px 58px)"></div>
            <div class="pointer-events-none absolute -top-16 -left-10 w-56 h-56 rounded-full bg-blue-400/10 blur-2xl"></div>

            <div class="relative flex items-center gap-3">
                <span class="ao-brand-tile" style="width:46px;height:46px">
                    <img src="<?= e($brand['logo']) ?>" alt="" class="h-8 w-8 object-contain">
                </span>
                <div>
                    <div class="font-display font-extrabold text-lg tracking-wide leading-none"><?= e($brand['nav_name']) ?></div>
                    <div class="text-[10px] tracking-[0.28em] uppercase text-white/45 mt-1"><?= e(__('gg_brand_tagline')) ?></div>
                </div>
            </div>

            <div class="relative">
                <h2 class="font-display font-extrabold leading-tight" style="font-size:clamp(26px,3.4vw,40px);letter-spacing:-0.015em">
                    <?= e(__('login_hero_line')) ?>
                </h2>
                <p class="mt-3 text-white/60 text-sm max-w-sm"><?= e(__('login_subtitle')) ?></p>
                <div class="mt-7 inline-flex gap-1.5 num font-bold text-lg" aria-hidden="true">
                    <?php foreach (['S', 'T', 'O', 'C', 'K'] as $ch): ?>
                        <span class="px-2.5 py-1.5 rounded-md bg-black/25 border border-white/10"><?= $ch ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="relative text-xs text-white/35">&copy; <?= date('Y') ?> <?= e($brand['name']) ?></div>
        </div>

        <!-- Form -->
        <div class="p-6 sm:p-8 lg:p-10" style="background:var(--card)">
            <div class="flex md:hidden justify-center mb-5">
                <span class="ao-brand-tile" style="width:64px;height:64px">
                    <img src="<?= $brand['key'] === 'growgig' ? 'assets/logo-growgig-full.png' : e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>" class="h-12 w-auto object-contain">
                </span>
            </div>
            <h1 class="font-display text-2xl font-extrabold" style="color:var(--heading)"><?= e(__('login_title')) ?></h1>
            <p class="mt-1 text-sm" style="color:var(--muted)"><?= e(__('login_subtitle')) ?></p>

            <?php if (isset($_GET['registered'])): ?>
                <div class="mt-5 rounded-lg px-4 py-3 text-sm font-medium" style="background:var(--ok-soft);color:var(--ok)">
                    <?= e(__('login_success_reg')) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mt-5 rounded-lg px-4 py-3 text-sm font-medium" style="background:var(--danger-soft);color:var(--danger)">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="mt-6 space-y-4" novalidate>
                <?= csrf_field() ?>
                <div>
                    <label for="email" class="ao-lbl"><?= e(__('label_email')) ?></label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus
                           placeholder="<?= e(__('ph_email')) ?>" class="ao-inp">
                </div>
                <div>
                    <label for="password" class="ao-lbl"><?= e(__('label_password')) ?></label>
                    <input type="password" id="password" name="password" required
                           placeholder="<?= e(__('ph_password')) ?>" class="ao-inp">
                </div>
                <button type="submit" class="ao-btn ao-btn-blue ao-btn-block" style="padding-top:13px;padding-bottom:13px">
                    <?= __('btn_login') ?>
                </button>
            </form>

            <p class="mt-6 text-center text-sm" style="color:var(--muted)">
                <?= __('login_no_account') ?>
                <a href="register.php" class="font-semibold hover:underline" style="color:var(--blue)"><?= __('login_register_link') ?></a>
            </p>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
