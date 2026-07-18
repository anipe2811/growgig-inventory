<?php
/**
 * index.php — GrowGig Inventory System (GIS) — marketing homepage.
 * Blue-AO design system (assets/theme.css). Standalone layout.
 * Logged-in users go to the dashboard.
 */
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$brand      = current_brand();   // GrowGig for public visitors
$activeLang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?= e($activeLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GrowGig Inventory System (GIS)</title>
    <meta name="description" content="<?= e(__('gg_hero_sub2')) ?>">
    <link rel="icon" type="image/png" href="assets/logo-growgig.png">

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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Figtree:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: {
                sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                display: ['"Bricolage Grotesque"', 'Figtree', 'sans-serif'],
            }}}
        };
    </script>
    <link rel="stylesheet" href="assets/theme.css">
    <style>
        @keyframes fadeUp { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:none } }
        @keyframes floaty { 0%,100% { transform:translateY(0) } 50% { transform:translateY(-12px) } }
        .reveal { opacity:0 }
        .reveal.in { animation: fadeUp .7s cubic-bezier(.21,.6,.35,1) forwards }
        .floaty { animation: floaty 6s ease-in-out infinite }
        @keyframes marquee { from { transform: translateX(-50%) } to { transform: translateX(0) } }
        .marquee-track { animation: marquee 30s linear infinite; width: max-content }
        .marquee-wrap:hover .marquee-track { animation-play-state: paused }
        details > summary { list-style:none }
        details > summary::-webkit-details-marker { display:none }
        html { scroll-behavior:smooth }
        .gg-eyebrow { font-size:12px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:var(--blue) }
        .gg-h2 { font-family:var(--font-display); font-weight:800; letter-spacing:-.015em; color:var(--heading); font-size:clamp(26px,3.4vw,38px) }
        .gg-navlink { padding:8px 14px; border-radius:10px; font-size:14px; font-weight:600; color:var(--muted); transition:all .12s ease }
        .gg-navlink:hover { color:var(--blue); background:var(--blue-soft) }
        @media (prefers-reduced-motion: reduce) {
            .reveal, .reveal.in { opacity:1; animation:none }
            .floaty, .marquee-track { animation:none }
        }
    </style>
</head>
<body class="font-sans antialiased">

<!-- ===================== Navbar ===================== -->
<nav class="sticky top-0 z-50 backdrop-blur-xl" style="background:color-mix(in srgb, var(--card) 85%, transparent); border-bottom:1px solid var(--line)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-[4.5rem]">
            <a href="index.php" class="flex items-center gap-2 shrink-0">
                <span class="ao-brand-tile" style="width:46px;height:46px">
                    <img src="<?= e($brand['logo']) ?>" alt="GrowGig" class="h-9 w-9 object-contain">
                </span>
                <span class="leading-tight">
                    <span class="block font-display font-extrabold text-lg leading-none" style="color:var(--heading)">GrowGig</span>
                    <span class="block text-[10px] font-semibold tracking-[0.18em] uppercase mt-0.5" style="color:var(--muted)"><?= e(__('gg_brand_tagline')) ?></span>
                </span>
            </a>

            <div class="hidden md:flex items-center gap-1">
                <a href="#features" class="gg-navlink"><?= __('gg_nav_features') ?></a>
                <a href="#how"      class="gg-navlink"><?= __('gg_nav_how') ?></a>
                <a href="#pricing"  class="gg-navlink"><?= __('gg_nav_pricing') ?></a>
                <a href="#faq"      class="gg-navlink"><?= __('gg_nav_faq') ?></a>
            </div>

            <div class="flex items-center gap-2">
                <div class="hidden sm:flex items-center text-xs font-bold rounded-lg overflow-hidden" style="border:1.5px solid var(--line)">
                    <a href="?lang=en" class="px-2.5 py-1" style="<?= $activeLang === 'en' ? 'background:var(--blue);color:#fff' : 'color:var(--muted)' ?>">EN</a>
                    <a href="?lang=ms" class="px-2.5 py-1" style="<?= $activeLang === 'ms' ? 'background:var(--blue);color:#fff' : 'color:var(--muted)' ?>">MY</a>
                </div>
                <button type="button" onclick="toggleTheme()" aria-label="theme" class="p-2 rounded-lg" style="color:var(--muted)">
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.71-.71M6.34 6.34l-.71-.71m12.02 0l-.71.71M6.34 17.66l-.71.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
                <a href="login.php" class="hidden sm:inline-flex ao-btn ao-btn-ghost ao-btn-sm"><?= __('nav_login') ?></a>
                <a href="register.php" class="ao-btn ao-btn-blue ao-btn-sm"><?= __('gg_get_started') ?></a>
                <button type="button" onclick="document.getElementById('mnav').classList.toggle('hidden')" class="md:hidden p-2 rounded-lg" style="color:var(--muted)" aria-label="menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>
    </div>
    <div id="mnav" class="md:hidden hidden px-4 py-3 space-y-1" style="border-top:1px solid var(--line)">
        <a href="#features" class="block gg-navlink"><?= __('gg_nav_features') ?></a>
        <a href="#how" class="block gg-navlink"><?= __('gg_nav_how') ?></a>
        <a href="#pricing" class="block gg-navlink"><?= __('gg_nav_pricing') ?></a>
        <a href="#faq" class="block gg-navlink"><?= __('gg_nav_faq') ?></a>
        <a href="login.php" class="block gg-navlink" style="color:var(--blue)"><?= __('nav_login') ?></a>
    </div>
</nav>

<!-- ===================== Hero (navy AO panel) ===================== -->
<header class="relative overflow-hidden text-white" style="background:var(--hero-grad)">
    <div class="pointer-events-none absolute -right-24 -bottom-24 w-[30rem] h-[30rem] rounded-full opacity-[0.07]" style="background:repeating-linear-gradient(-35deg,#fff 0 22px,transparent 22px 58px)"></div>
    <div class="pointer-events-none absolute -top-24 -left-16 w-96 h-96 rounded-full bg-blue-400/10 blur-3xl"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid lg:grid-cols-2 gap-12 items-center relative">
        <div class="reveal in">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold bg-white/10 ring-1 ring-white/20 backdrop-blur mb-6">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span><?= e(__('gg_hero_badge2')) ?>
            </span>
            <h1 class="font-display font-extrabold text-4xl sm:text-5xl lg:text-[3.4rem] tracking-tight leading-[1.05]">
                <?= e(__('gg_hero_title2')) ?>
            </h1>
            <p class="mt-6 text-lg text-white/70 max-w-xl"><?= e(__('gg_hero_sub2')) ?></p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3">
                <a href="register.php" class="group inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl font-bold bg-white transition-all shadow-xl shadow-black/20 hover:-translate-y-0.5" style="color:var(--blue-deep)">
                    <?= __('gg_get_started') ?>
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                <a href="mailto:hello@growgig.tech" class="inline-flex items-center justify-center px-7 py-3.5 rounded-xl font-bold bg-white/10 text-white ring-1 ring-white/25 hover:bg-white/20 transition-colors"><?= __('gg_book_demo') ?></a>
            </div>
            <p class="mt-5 text-sm text-white/55 flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= e(__('gg_hero_cta_note')) ?>
            </p>
        </div>

        <!-- Product mock (AO dashboard style) -->
        <div class="relative reveal in floaty" style="animation-delay:.15s">
            <div class="ao-card overflow-hidden shadow-2xl">
                <div class="flex items-center gap-1.5 px-4 py-3" style="border-bottom:1px solid var(--line);background:var(--paper)">
                    <span class="w-3 h-3 rounded-full bg-red-400"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                    <span class="w-3 h-3 rounded-full bg-green-400"></span>
                    <span class="ml-3 text-xs font-semibold" style="color:var(--muted)">app.growgig.tech</span>
                </div>
                <div class="p-5" style="background:var(--card)">
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div class="ao-stat ao-hero" style="padding:14px">
                            <div class="k">Items</div>
                            <div class="v num" style="font-size:22px">128</div>
                        </div>
                        <div class="ao-stat" style="padding:14px">
                            <div class="k">Low</div>
                            <div class="v warn num" style="font-size:22px">7</div>
                        </div>
                        <div class="ao-stat" style="padding:14px">
                            <div class="k">Branches</div>
                            <div class="v blue num" style="font-size:22px">3</div>
                        </div>
                    </div>
                    <div class="rounded-xl overflow-hidden text-xs" style="border:1px solid var(--line)">
                        <?php
                        $rows = [['Exam Gloves','32','ok'],['Hand Sanitizer','9','ok'],['Gauze Pads','4','low'],['Syringe 5ml','5','low']];
                        foreach ($rows as [$n,$q,$st]): ?>
                            <div class="flex items-center justify-between px-3 py-2.5" style="border-bottom:1px solid var(--line)">
                                <span class="font-semibold" style="color:var(--txt)"><?= $n ?></span>
                                <span class="flex items-center gap-2">
                                    <span class="num" style="color:var(--muted)"><?= $q ?></span>
                                    <span class="ao-badge <?= $st === 'low' ? 'ao-badge-warn' : 'ao-badge-ok' ?>"><?= $st === 'low' ? 'Low' : 'In Stock' ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="absolute -bottom-5 -left-5 floaty hidden sm:flex items-center gap-3 px-4 py-3 ao-card" style="animation-delay:-3s">
                <span class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:var(--warn-soft);color:var(--warn)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z"/></svg>
                </span>
                <div>
                    <p class="text-xs font-bold" style="color:var(--heading)">Low-stock alert</p>
                    <p class="text-[11px]" style="color:var(--muted)">2 items need reorder</p>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 relative">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 reveal">
            <?php
            $hstats = [['gg_hero_stat1','M19 11H5m14-4H5m14 8H5m14 4H5'],['gg_hero_stat2','M9 17v-6h13M9 5h13M3 5h.01M3 12h.01M3 19h.01'],['gg_hero_stat3','M3 5h12M9 3v2m1 9.5A18 18 0 016.4 9m6.1 9h7M3 21l4-4']];
            foreach ($hstats as [$k,$icon]): ?>
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-white/[0.07] ring-1 ring-white/15 backdrop-blur">
                    <span class="w-9 h-9 rounded-lg bg-white/10 text-white flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/></svg>
                    </span>
                    <span class="text-sm font-semibold text-white/85"><?= e(__($k)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<!-- ===================== Trusted (logo cloud) ===================== -->
<section style="border-bottom:1px solid var(--line);background:var(--card)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <p class="text-center text-sm font-semibold mb-6" style="color:var(--muted)"><?= e(__('gg_trusted')) ?></p>
        <?php
        // Only brands with a real logo image are shown. To add one: drop the
        // image into assets/brands/ and add a [path, name] row below.
        $brands = [
            ['assets/brands/brightpath.png', 'BrightPath Clinic'],
            ['assets/brands/kidspark.png',   'KidSpark Therapy'],
            ['assets/logo.jpg',              'Aktifotak'],
            ['assets/brands/harmony.png',    'Harmony Health'],
            ['assets/brands/wellnest.png',   'WellNest'],
        ];
        ?>
        <div class="marquee-wrap overflow-hidden reveal [mask-image:linear-gradient(to_right,transparent,black_8%,black_92%,transparent)]">
            <div class="marquee-track flex items-center gap-2.5">
                <?php foreach (array_merge($brands, $brands) as [$img, $name]): ?>
                    <span class="inline-flex items-center px-3 py-3 rounded-2xl shrink-0" style="background:var(--paper);border:1px solid var(--line)">
                        <span class="rounded-xl bg-white p-1.5 flex items-center justify-center shrink-0" style="border:1px solid var(--line)">
                            <img src="<?= e($img) ?>" alt="<?= e($name) ?>" class="h-20 w-auto max-w-[200px] object-contain" loading="lazy">
                        </span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Pain → Solution ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
    <div class="text-center max-w-2xl mx-auto reveal">
        <p class="gg-eyebrow"><?= e(__('gg_pain_eyebrow')) ?></p>
        <h2 class="mt-3 gg-h2"><?= e(__('gg_pain_title')) ?></h2>
        <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_pain_sub')) ?></p>
    </div>
    <div class="mt-12 grid gap-6 md:grid-cols-3">
        <?php
        $pains = [
            ['gg_pain1_title','gg_pain1_desc','M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z'],
            ['gg_pain2_title','gg_pain2_desc','M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['gg_pain3_title','gg_pain3_desc','M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21'],
        ];
        foreach ($pains as $i => [$t,$d,$icon]): ?>
            <div class="ao-card ao-card-pad reveal" style="animation-delay:<?= $i * 80 ?>ms">
                <span class="w-11 h-11 rounded-xl flex items-center justify-center mb-4" style="background:var(--danger-soft);color:var(--danger)">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="<?= $icon ?>"/></svg>
                </span>
                <h3 class="font-display font-bold text-lg" style="color:var(--heading)"><?= e(__($t)) ?></h3>
                <p class="mt-2 text-sm leading-relaxed" style="color:var(--muted)"><?= e(__($d)) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-12 reveal">
        <div class="ao-card overflow-hidden grid lg:grid-cols-2 items-stretch">
            <img src="assets/clinic-stockroom.webp" alt="" loading="lazy"
                 class="w-full h-56 lg:h-full object-cover">
            <div class="flex items-center p-8 lg:p-10" style="background:var(--blue-soft)">
                <p class="text-lg lg:text-xl font-semibold leading-relaxed" style="color:var(--blue-ink)">
                    <?= e(__('gg_pain_bridge')) ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Features ===================== -->
<section id="features" class="scroll-mt-20" style="background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
        <div class="text-center max-w-2xl mx-auto reveal">
            <p class="gg-eyebrow"><?= e(__('gg_features_eyebrow')) ?></p>
            <h2 class="mt-3 gg-h2"><?= e(__('gg_features_title')) ?></h2>
            <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_features_sub')) ?></p>
        </div>
        <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <?php
            $features = [
                ['gg_feat1_title','gg_feat1_desc','M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
                ['gg_feat2_title','gg_feat2_desc','M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
                ['gg_feat3_title','gg_feat3_desc','M9 17a2 2 0 11-4 0 2 2 0 014 0zM20 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8h4l3 4v4a1 1 0 01-1 1h-1'],
                ['gg_feat4_title','gg_feat4_desc','M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                ['gg_feat5_title','gg_feat5_desc','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['gg_feat6_title','gg_feat6_desc','M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['gg_feat7_title','gg_feat7_desc','M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z'],
                ['gg_feat8_title','gg_feat8_desc','M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8z'],
                ['gg_feat9_title','gg_feat9_desc','M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ];
            foreach ($features as $i => [$t,$d,$icon]): ?>
                <div class="ao-card ao-card-pad reveal group hover:-translate-y-1 transition-transform duration-200" style="animation-delay:<?= $i * 60 ?>ms">
                    <span class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:var(--blue-soft);color:var(--blue)">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="<?= $icon ?>"/></svg>
                    </span>
                    <h3 class="font-display font-bold text-lg" style="color:var(--heading)"><?= e(__($t)) ?></h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color:var(--muted)"><?= e(__($d)) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== How it works ===================== -->
<section id="how" class="relative scroll-mt-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
        <div class="text-center max-w-2xl mx-auto reveal">
            <p class="gg-eyebrow"><?= e(__('gg_how_eyebrow')) ?></p>
            <h2 class="mt-3 gg-h2"><?= e(__('gg_how_title')) ?></h2>
        </div>
        <div class="mt-14 grid gap-8 md:grid-cols-3 relative">
            <div class="hidden md:block absolute top-7 left-[16%] right-[16%] h-0.5" style="background:var(--line)"></div>
            <?php
            $steps = [['1','gg_step1_title','gg_step1_desc'],['2','gg_step2_title','gg_step2_desc'],['3','gg_step3_title','gg_step3_desc']];
            foreach ($steps as $i => [$n,$t,$d]): ?>
                <div class="relative text-center reveal" style="animation-delay:<?= $i * 100 ?>ms">
                    <div class="w-14 h-14 mx-auto rounded-2xl text-white text-2xl font-extrabold font-display flex items-center justify-center shadow-xl" style="background:var(--hero-grad);box-shadow:0 12px 28px rgba(30,58,138,.3)"><?= $n ?></div>
                    <h3 class="mt-5 font-display font-bold text-lg" style="color:var(--heading)"><?= e(__($t)) ?></h3>
                    <p class="mt-2 text-sm max-w-xs mx-auto" style="color:var(--muted)"><?= e(__($d)) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== Highlight blocks ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20 sm:pb-24 space-y-20">
    <!-- Block 1 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div>
            <h3 class="font-display text-2xl sm:text-3xl font-bold" style="color:var(--heading)"><?= e(__('gg_block1_title')) ?></h3>
            <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_block1_desc')) ?></p>
        </div>
        <div class="ao-card ao-card-pad">
            <div class="space-y-2.5">
                <?php foreach ([['BrightPath HQ','248'],['BrightPath Penang','181'],['BrightPath Johor','96']] as [$b,$c]): ?>
                    <div class="flex items-center justify-between px-4 py-3 rounded-xl" style="background:var(--paper);border:1px solid var(--line)">
                        <span class="flex items-center gap-2 font-semibold" style="color:var(--txt)"><span class="w-2 h-2 rounded-full" style="background:var(--blue)"></span><?= $b ?></span>
                        <span class="text-sm num" style="color:var(--muted)"><?= $c ?> items</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Block 2 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div class="lg:order-2">
            <h3 class="font-display text-2xl sm:text-3xl font-bold" style="color:var(--heading)"><?= e(__('gg_block2_title')) ?></h3>
            <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_block2_desc')) ?></p>
        </div>
        <div class="lg:order-1 ao-card ao-card-pad">
            <div class="rounded-xl overflow-hidden text-sm" style="border:1px solid var(--line)">
                <div class="grid grid-cols-4 px-4 py-2 text-[11px] font-bold uppercase tracking-wide" style="color:var(--muted);border-bottom:1px solid var(--line)"><span>Item</span><span class="text-center" style="color:var(--ok)">In</span><span class="text-center" style="color:var(--danger)">Out</span><span class="text-right">Bal</span></div>
                <?php foreach ([['Exam Gloves','20','3','49'],['Face Masks','10','1','25']] as [$n,$in,$out,$bal]): ?>
                    <div class="grid grid-cols-4 px-4 py-2.5" style="border-bottom:1px solid var(--line)">
                        <span class="font-semibold" style="color:var(--txt)"><?= $n ?></span>
                        <span class="text-center num" style="color:var(--ok)">+<?= $in ?></span>
                        <span class="text-center num" style="color:var(--danger)"><?= $out ?></span>
                        <span class="text-right font-bold num" style="color:var(--heading)"><?= $bal ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Block 3 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div>
            <h3 class="font-display text-2xl sm:text-3xl font-bold" style="color:var(--heading)"><?= e(__('gg_block3_title')) ?></h3>
            <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_block3_desc')) ?></p>
        </div>
        <div class="ao-card ao-card-pad">
            <div class="flex flex-wrap gap-2.5">
                <span class="ao-badge ao-badge-blue" style="font-size:13px;padding:8px 14px"><?= e(role_label('account_admin')) ?></span>
                <span class="ao-badge ao-badge-ok" style="font-size:13px;padding:8px 14px"><?= e(role_label('account_user')) ?></span>
            </div>
            <p class="mt-4 text-xs" style="color:var(--muted)"><?= e(__('gg_block3_caption')) ?></p>
        </div>
    </div>
</section>

<!-- ===================== Devices ===================== -->
<section style="background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24 grid lg:grid-cols-2 gap-14 items-center">
        <div class="reveal">
            <p class="gg-eyebrow"><?= e(__('gg_dev_eyebrow')) ?></p>
            <h2 class="mt-3 gg-h2"><?= e(__('gg_dev_title')) ?></h2>
            <p class="mt-4 max-w-lg" style="color:var(--muted)"><?= e(__('gg_dev_sub')) ?></p>
            <div class="mt-7 flex flex-wrap gap-3">
                <?php
                $devs = [
                    ['gg_dev_desktop','M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a.75.75 0 01-.75.75H3.75A.75.75 0 013 12V5.25'],
                    ['gg_dev_tablet','M10.5 19.5h3m-6.75 2.25h10.5a2.25 2.25 0 002.25-2.25v-15a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 4.5v15a2.25 2.25 0 002.25 2.25z'],
                    ['gg_dev_mobile','M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3'],
                ];
                foreach ($devs as [$lbl,$icon]): ?>
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold" style="background:var(--paper);border:1px solid var(--line);color:var(--txt)">
                        <svg class="w-5 h-5" style="color:var(--blue)" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
                        <?= e(__($lbl)) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Laptop + phone mock -->
        <div class="relative reveal pb-10" style="animation-delay:.15s">
            <div class="ao-card overflow-hidden shadow-2xl">
                <div class="flex items-center gap-1.5 px-4 py-2.5" style="border-bottom:1px solid var(--line);background:var(--paper)">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                    <span class="ml-3 flex-1"><span class="block max-w-[60%] mx-auto text-center text-[10px] rounded-md py-0.5" style="color:var(--muted);background:var(--card);border:1px solid var(--line)">app.growgig.tech</span></span>
                </div>
                <div class="p-5" style="background:var(--card)">
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <?php foreach ([['Items','128','blue'],['Low','7','warn'],['Branches','3','ok']] as [$l,$v,$cls]): ?>
                            <div class="ao-stat" style="padding:12px">
                                <div class="k"><?= $l ?></div>
                                <div class="v <?= $cls ?> num" style="font-size:19px"><?= $v ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rounded-xl overflow-hidden text-xs" style="border:1px solid var(--line)">
                        <?php foreach ([['Exam Gloves','32','ok'],['Hand Sanitizer','9','ok'],['Gauze Pads','4','low'],['Cotton Roll','22','ok']] as [$n,$q,$st]): ?>
                            <div class="flex items-center justify-between px-3 py-2" style="border-bottom:1px solid var(--line)">
                                <span class="font-semibold" style="color:var(--txt)"><?= $n ?></span>
                                <span class="flex items-center gap-2"><span class="num" style="color:var(--muted)"><?= $q ?></span>
                                <span class="ao-badge <?= $st === 'low' ? 'ao-badge-warn' : 'ao-badge-ok' ?>" style="font-size:9px;padding:2px 8px"><?= $st === 'low' ? 'Low' : 'OK' ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="mx-auto h-2.5 w-3/5 rounded-b-2xl" style="background:var(--line)"></div>
            <!-- phone -->
            <div class="absolute -bottom-2 right-0 sm:right-4 w-28 sm:w-32 floaty">
                <div class="rounded-[1.5rem] p-1.5 shadow-2xl" style="background:var(--ink)">
                    <div class="rounded-[1.1rem] overflow-hidden" style="background:var(--card)">
                        <div class="px-3 py-2 text-white text-[9px] font-bold" style="background:var(--hero-grad)">GIS · Stock</div>
                        <div class="p-2 space-y-1.5">
                            <?php foreach ([['Exam Gloves','32'],['Gauze Pads','4'],['Cotton Roll','22']] as [$n,$q]): ?>
                                <div class="flex items-center justify-between px-2 py-1.5 rounded-lg text-[8px]" style="background:var(--paper)">
                                    <span class="font-semibold" style="color:var(--txt)"><?= $n ?></span>
                                    <span class="num" style="color:var(--muted)"><?= $q ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-1 text-center text-[8px] font-bold text-white rounded-lg py-1.5" style="background:var(--blue)">+ Stock In</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Comparison ===================== -->
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
    <div class="text-center max-w-2xl mx-auto reveal">
        <p class="gg-eyebrow"><?= e(__('gg_cmp_eyebrow')) ?></p>
        <h2 class="mt-3 gg-h2"><?= e(__('gg_cmp_title')) ?></h2>
        <p class="mt-4" style="color:var(--muted)"><?= e(__('gg_cmp_sub')) ?></p>
    </div>
    <div class="mt-12 ao-card overflow-hidden reveal">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:560px">
                <thead>
                    <tr style="border-bottom:1px solid var(--line);background:var(--paper)">
                        <th class="text-left px-5 py-4 font-bold" style="color:var(--muted)"></th>
                        <th class="text-left px-5 py-4 font-bold" style="color:var(--muted)"><?= e(__('gg_cmp_col_manual')) ?></th>
                        <th class="text-left px-5 py-4 font-extrabold font-display" style="color:var(--blue)"><?= e(__('gg_cmp_col_gg')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($r = 1; $r <= 6; $r++): ?>
                        <tr style="border-bottom:1px solid var(--line)">
                            <td class="px-5 py-4 font-bold" style="color:var(--heading)"><?= e(__('gg_cmp_r' . $r)) ?></td>
                            <td class="px-5 py-4" style="color:var(--muted)">
                                <span class="inline-flex items-start gap-2">
                                    <svg class="w-4 h-4 mt-0.5 shrink-0" style="color:var(--danger)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <?= e(__('gg_cmp_r' . $r . '_manual')) ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 font-semibold" style="color:var(--txt)">
                                <span class="inline-flex items-start gap-2">
                                    <svg class="w-4 h-4 mt-0.5 shrink-0" style="color:var(--ok)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/></svg>
                                    <?= e(__('gg_cmp_r' . $r . '_gg')) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ===================== Pricing ===================== -->
<section id="pricing" class="scroll-mt-20" style="background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
        <div class="grid lg:grid-cols-3 gap-8 items-start">

            <!-- Left intro -->
            <div class="reveal">
                <p class="gg-eyebrow"><?= e(__('gg_pricing_eyebrow')) ?></p>
                <h2 class="mt-3 font-display text-4xl sm:text-5xl font-extrabold leading-tight" style="color:var(--heading)">
                    <?= e(__('gg_pr_head1')) ?><br><span style="color:var(--muted)"><?= e(__('gg_pr_head2')) ?></span>
                </h2>
                <div class="mt-8 ao-card ao-card-pad">
                    <div class="flex text-amber-400 mb-2">
                        <?php for ($s = 0; $s < 5; $s++): ?><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.36 4.18a1 1 0 00.95.69h4.4c.97 0 1.37 1.24.59 1.81l-3.56 2.59a1 1 0 00-.36 1.12l1.36 4.18c.3.92-.76 1.69-1.54 1.12l-3.56-2.59a1 1 0 00-1.18 0l-3.56 2.59c-.78.57-1.84-.2-1.54-1.12l1.36-4.18a1 1 0 00-.36-1.12L1.4 9.61c-.78-.57-.38-1.81.59-1.81h4.4a1 1 0 00.95-.69L9.05 2.93z"/></svg><?php endfor; ?>
                    </div>
                    <p class="text-sm" style="color:var(--txt)">&ldquo;<?= e(__('gg_pr_quote')) ?>&rdquo;</p>
                    <p class="mt-3 text-sm font-bold" style="color:var(--heading)"><?= e(__('gg_pr_quote_name')) ?></p>
                    <p class="mt-2 inline-flex items-center gap-1.5 text-xs font-bold" style="color:var(--ok)">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.3 3.29 6.8-6.79a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                        <?= e(__('gg_pr_excellent')) ?>
                    </p>
                </div>
            </div>

            <!-- Pro card -->
            <div class="reveal relative flex flex-col p-7 rounded-3xl text-white" style="background:var(--hero-grad);box-shadow:0 20px 48px rgba(30,58,138,.35)">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-xs font-extrabold bg-amber-400 text-amber-950 shadow"><?= e(__('gg_badge_popular')) ?></span>
                <h3 class="font-display font-extrabold text-2xl"><?= e(__('gg_pr_pro_name')) ?></h3>
                <p class="mt-1 text-sm text-white/65"><?= e(__('gg_pr_pro_tag')) ?></p>

                <div class="mt-5 flex items-center gap-2">
                    <span class="line-through text-xl text-white/50 num">RM99</span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-400 text-amber-950"><?= e(__('gg_pr_promo')) ?></span>
                </div>
                <p class="mt-1 flex items-end gap-1">
                    <span class="font-display text-5xl font-extrabold num">RM9.90</span>
                    <span class="text-white/60 mb-1"><?= e(__('gg_pr_permonth')) ?></span>
                </p>
                <p class="mt-2 text-xs text-white/60 leading-relaxed"><?= e(__('gg_pr_promo_note')) ?></p>

                <a href="register.php" class="mt-6 inline-flex justify-center px-5 py-3 rounded-xl font-bold bg-white hover:bg-blue-50 transition-colors" style="color:var(--blue-deep)"><?= e(__('gg_pr_start_pro')) ?></a>
                <div class="mt-4 flex items-center justify-center gap-2 py-2 rounded-lg bg-white/10 text-xs font-bold text-white/85">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?= e(__('gg_pr_support')) ?>
                </div>
                <ul class="mt-6 space-y-3">
                    <?php foreach (['gg_pr_pf1','gg_pr_pf2','gg_pr_pf3','gg_pr_pf4','gg_pr_pf5','gg_pr_pf6','gg_pr_pf7','gg_pr_pf8'] as $f): ?>
                        <li class="flex items-start gap-2.5 text-sm text-white/85">
                            <svg class="w-5 h-5 shrink-0 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= e(__($f)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Free card -->
            <div class="reveal flex flex-col p-7 rounded-3xl ao-card">
                <h3 class="font-display font-extrabold text-2xl" style="color:var(--heading)"><?= e(__('gg_pr_free_name')) ?></h3>
                <p class="mt-1 text-sm" style="color:var(--muted)"><?= e(__('gg_pr_free_tag')) ?></p>
                <p class="mt-5 flex items-end gap-1">
                    <span class="font-display text-5xl font-extrabold num" style="color:var(--heading)">RM0</span>
                    <span class="mb-1" style="color:var(--muted)"><?= e(__('gg_pr_permonth')) ?></span>
                </p>
                <p class="mt-1 text-xs" style="color:var(--muted)">&nbsp;</p>
                <a href="register.php" class="mt-6 ao-btn ao-btn-ink"><?= e(__('gg_pr_start_free')) ?></a>
                <div class="mt-4 flex items-center justify-center gap-2 py-2 rounded-lg text-xs font-bold" style="background:var(--paper);color:var(--muted)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 001.9 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <?= e(__('gg_pr_free_support')) ?>
                </div>
                <ul class="mt-6 space-y-3">
                    <?php foreach (['gg_pr_ff1','gg_pr_ff2','gg_pr_ff3','gg_pr_ff4','gg_pr_ff5'] as $f): ?>
                        <li class="flex items-start gap-2.5 text-sm" style="color:var(--txt)">
                            <svg class="w-5 h-5 shrink-0" style="color:var(--ok)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= e(__($f)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <p class="mt-8 text-center text-xs" style="color:var(--muted)"><?= e(__('gg_price_disclaimer')) ?></p>
    </div>
</section>

<!-- ===================== Testimonials ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
    <div class="text-center max-w-2xl mx-auto reveal">
        <p class="gg-eyebrow"><?= e(__('gg_testi_eyebrow')) ?></p>
        <h2 class="mt-3 gg-h2"><?= e(__('gg_testi_title')) ?></h2>
    </div>
    <div class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        <?php for ($t = 1; $t <= 6; $t++): ?>
            <div class="ao-card ao-card-pad reveal" style="animation-delay:<?= ($t - 1) * 60 ?>ms">
                <div class="flex text-amber-400 mb-3">
                    <?php for ($s = 0; $s < 5; $s++): ?><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.36 4.18a1 1 0 00.95.69h4.4c.97 0 1.37 1.24.59 1.81l-3.56 2.59a1 1 0 00-.36 1.12l1.36 4.18c.3.92-.76 1.69-1.54 1.12l-3.56-2.59a1 1 0 00-1.18 0l-3.56 2.59c-.78.57-1.84-.2-1.54-1.12l1.36-4.18a1 1 0 00-.36-1.12L1.4 9.61c-.78-.57-.38-1.81.59-1.81h4.4a1 1 0 00.95-.69L9.05 2.93z"/></svg><?php endfor; ?>
                </div>
                <p class="leading-relaxed" style="color:var(--txt)">&ldquo;<?= e(__('gg_t' . $t . '_quote')) ?>&rdquo;</p>
                <div class="mt-5 flex items-center gap-3">
                    <span class="w-11 h-11 rounded-full text-white font-extrabold font-display flex items-center justify-center" style="background:var(--hero-grad)"><?= e(mb_substr(__('gg_t' . $t . '_name'), 0, 1)) ?></span>
                    <div>
                        <p class="font-bold" style="color:var(--heading)"><?= e(__('gg_t' . $t . '_name')) ?></p>
                        <p class="text-sm" style="color:var(--muted)"><?= e(__('gg_t' . $t . '_role')) ?></p>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</section>

<!-- ===================== Guarantee ===================== -->
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pb-20 sm:pb-24">
    <div class="ao-card reveal flex flex-col sm:flex-row items-center gap-6 p-8">
        <span class="w-16 h-16 shrink-0 rounded-2xl flex items-center justify-center" style="background:var(--ok-soft);color:var(--ok)">
            <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </span>
        <div class="text-center sm:text-left">
            <h3 class="font-display font-extrabold text-xl" style="color:var(--heading)"><?= e(__('gg_guarantee_title')) ?></h3>
            <p class="mt-1.5 text-sm" style="color:var(--muted)"><?= e(__('gg_guarantee_desc')) ?></p>
        </div>
        <a href="register.php" class="ao-btn ao-btn-blue sm:ml-auto shrink-0"><?= __('gg_get_started') ?></a>
    </div>
</section>

<!-- ===================== FAQ ===================== -->
<section id="faq" class="scroll-mt-20" style="background:var(--card);border-top:1px solid var(--line)">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
        <div class="text-center reveal mb-12">
            <p class="gg-eyebrow"><?= e(__('gg_faq_eyebrow')) ?></p>
            <h2 class="mt-3 gg-h2"><?= e(__('gg_faq_title')) ?></h2>
        </div>
        <div class="space-y-3">
            <?php for ($i = 1; $i <= 7; $i++): ?>
                <details class="reveal group ao-card p-5">
                    <summary class="flex items-center justify-between cursor-pointer font-display font-bold" style="color:var(--heading)">
                        <?= e(__('gg_faq_q' . $i)) ?>
                        <span class="ml-4 shrink-0 w-7 h-7 rounded-full flex items-center justify-center group-open:rotate-180 transition-transform" style="background:var(--blue-soft);color:var(--blue)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </span>
                    </summary>
                    <p class="mt-4 text-sm leading-relaxed" style="color:var(--muted)"><?= e(__('gg_faq_a' . $i)) ?></p>
                </details>
            <?php endfor; ?>
        </div>
    </div>
</section>

<!-- ===================== Final CTA ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="relative overflow-hidden rounded-[2rem] px-6 py-16 text-center reveal text-white" style="background:var(--hero-grad);box-shadow:0 20px 48px rgba(30,58,138,.35)">
        <div class="pointer-events-none absolute -right-20 -bottom-20 w-96 h-96 rounded-full opacity-[0.08]" style="background:repeating-linear-gradient(-35deg,#fff 0 22px,transparent 22px 58px)"></div>
        <div class="relative">
            <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-extrabold bg-amber-400 text-amber-950 mb-5"><?= e(__('gg_final_urgency')) ?></span>
            <h2 class="font-display text-3xl sm:text-4xl font-extrabold"><?= e(__('gg_final_title')) ?></h2>
            <p class="mt-3 text-white/70 max-w-2xl mx-auto"><?= e(__('gg_final_sub')) ?></p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="register.php" class="px-7 py-3.5 rounded-xl bg-white font-bold hover:bg-blue-50 transition-colors shadow-lg" style="color:var(--blue-deep)"><?= __('gg_get_started') ?></a>
                <a href="mailto:hello@growgig.tech" class="px-7 py-3.5 rounded-xl bg-white/10 text-white font-bold ring-1 ring-white/25 hover:bg-white/20 transition-colors"><?= __('gg_book_demo') ?></a>
            </div>
            <p class="mt-5 text-sm text-white/55"><?= e(__('gg_hero_cta_note')) ?></p>
        </div>
    </div>
</section>

<!-- ===================== Footer ===================== -->
<footer class="text-white" style="background:var(--ink)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="ao-brand-tile"><img src="<?= e($brand['logo']) ?>" alt="GrowGig" class="h-8 w-8 object-contain"></span>
                <span class="leading-tight">
                    <span class="block font-display font-extrabold text-lg">GrowGig</span>
                    <span class="block text-[10px] font-semibold tracking-[0.18em] uppercase text-white/40 mt-0.5"><?= e(__('gg_brand_tagline')) ?></span>
                </span>
            </div>
            <p class="mt-4 text-sm text-white/55 max-w-xs"><?= e(__('gg_foot_blurb')) ?></p>
        </div>
        <div>
            <h4 class="font-display font-bold mb-4"><?= e(__('gg_foot_product')) ?></h4>
            <ul class="space-y-2.5 text-sm text-white/60">
                <li><a href="#features" class="hover:text-white transition-colors"><?= __('gg_nav_features') ?></a></li>
                <li><a href="#pricing" class="hover:text-white transition-colors"><?= __('gg_nav_pricing') ?></a></li>
                <li><a href="login.php" class="hover:text-white transition-colors"><?= __('nav_login') ?></a></li>
                <li><a href="register.php" class="hover:text-white transition-colors"><?= __('gg_get_started') ?></a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-display font-bold mb-4"><?= e(__('gg_foot_company')) ?></h4>
            <ul class="space-y-2.5 text-sm text-white/60">
                <li><a href="#how" class="hover:text-white transition-colors"><?= __('gg_nav_how') ?></a></li>
                <li><a href="#faq" class="hover:text-white transition-colors"><?= __('gg_nav_faq') ?></a></li>
                <li><a href="mailto:hello@growgig.tech" class="hover:text-white transition-colors"><?= __('gg_foot_contact') ?></a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-display font-bold mb-4"><?= e(__('gg_foot_contact')) ?></h4>
            <ul class="space-y-2.5 text-sm text-white/60">
                <li><a href="mailto:hello@growgig.tech" class="hover:text-white transition-colors">hello@growgig.tech</a></li>
            </ul>
        </div>
    </div>
    <div style="border-top:1px solid rgba(255,255,255,.08)">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 text-sm text-white/40 text-center">
            &copy; <?= date('Y') ?> GrowGig Inventory System (GIS). <?= __('footer_rights') ?>
        </div>
    </div>
</footer>

<script>
    function toggleTheme() {
        var html = document.documentElement;
        html.classList.toggle('dark');
        try { localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
    }
    (function () {
        var els = document.querySelectorAll('.reveal');
        if (!('IntersectionObserver' in window)) { els.forEach(function (e){ e.classList.add('in'); }); return; }
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); obs.unobserve(en.target); } });
        }, { threshold: 0.12 });
        els.forEach(function (e) { obs.observe(e); });
    })();
</script>
</body>
</html>
