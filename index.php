<?php
/**
 * index.php  GrowGig Inventory System (GIS)  premium marketing homepage.
 * Standalone layout. Logged-in users go to the dashboard.
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                display: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
            }}}
        };
    </script>
    <style>
        @keyframes fadeUp { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:none } }
        @keyframes floaty { 0%,100% { transform:translateY(0) } 50% { transform:translateY(-12px) } }
        @keyframes blob { 0%,100% { transform:translate(0,0) scale(1) } 33% { transform:translate(24px,-28px) scale(1.1) } 66% { transform:translate(-18px,18px) scale(.95) } }
        .reveal { opacity:0 }
        .reveal.in { animation: fadeUp .7s cubic-bezier(.21,.6,.35,1) forwards }
        .floaty { animation: floaty 6s ease-in-out infinite }
        .blob { animation: blob 18s ease-in-out infinite }
        @keyframes marquee { from { transform: translateX(-50%) } to { transform: translateX(0) } }
        .marquee-track { animation: marquee 30s linear infinite; width: max-content }
        .marquee-wrap:hover .marquee-track { animation-play-state: paused }
        details > summary { list-style:none }
        details > summary::-webkit-details-marker { display:none }
        html { scroll-behavior:smooth }
    </style>
</head>
<body class="font-sans bg-white text-gray-900 dark:bg-gray-950 dark:text-gray-100 transition-colors duration-300 antialiased">

<!-- ===================== Navbar ===================== -->
<nav class="sticky top-0 z-50 bg-white/80 dark:bg-gray-950/80 backdrop-blur-xl border-b border-gray-100 dark:border-gray-800/80 transition-colors">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-[4.5rem]">
            <a href="index.php" class="flex items-center gap-3 shrink-0">
                <span class="inline-flex items-center justify-center h-12 w-12 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-600/30">
                    <svg class="h-7 w-7 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V13"/><path d="M10 20V9"/><path d="M16 20v-5"/><path d="M4 8.5l6-4 5 3 5-4"/><path d="M20 3.5v4h-4"/></svg>
                </span>
                <span class="leading-tight">
                    <span class="block font-display font-extrabold text-lg bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">GrowGig</span>
                    <span class="block text-[10.5px] font-medium tracking-wide text-gray-400 dark:text-gray-500 -mt-0.5"><?= e(__('gg_brand_tagline')) ?></span>
                </span>
            </a>

            <div class="hidden md:flex items-center gap-1">
                <a href="#features" class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:text-blue-600 hover:bg-blue-50 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"><?= __('gg_nav_features') ?></a>
                <a href="#how"      class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:text-blue-600 hover:bg-blue-50 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"><?= __('gg_nav_how') ?></a>
                <a href="#pricing"  class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:text-blue-600 hover:bg-blue-50 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"><?= __('gg_nav_pricing') ?></a>
                <a href="#faq"      class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:text-blue-600 hover:bg-blue-50 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"><?= __('gg_nav_faq') ?></a>
            </div>

            <div class="flex items-center gap-2">
                <div class="hidden sm:flex items-center text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <a href="?lang=en" class="px-2.5 py-1 <?= $activeLang === 'en' ? 'bg-blue-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">EN</a>
                    <a href="?lang=ms" class="px-2.5 py-1 <?= $activeLang === 'ms' ? 'bg-blue-600 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">MY</a>
                </div>
                <button type="button" onclick="toggleTheme()" aria-label="theme" class="p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.71-.71M6.34 6.34l-.71-.71m12.02 0l-.71.71M6.34 17.66l-.71.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
                <a href="login.php" class="hidden sm:inline-flex px-3.5 py-2 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800 transition-colors"><?= __('nav_login') ?></a>
                <a href="register.php" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg shadow-blue-600/25"><?= __('gg_get_started') ?></a>
                <button type="button" onclick="document.getElementById('mnav').classList.toggle('hidden')" class="md:hidden p-2 rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>
    </div>
    <div id="mnav" class="md:hidden hidden border-t border-gray-100 dark:border-gray-800 px-4 py-3 space-y-1">
        <a href="#features" class="block px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"><?= __('gg_nav_features') ?></a>
        <a href="#how" class="block px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"><?= __('gg_nav_how') ?></a>
        <a href="#pricing" class="block px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"><?= __('gg_nav_pricing') ?></a>
        <a href="#faq" class="block px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"><?= __('gg_nav_faq') ?></a>
        <a href="login.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-blue-600 dark:text-blue-400"><?= __('nav_login') ?></a>
    </div>
</nav>

<!-- ===================== Hero ===================== -->
<header class="relative overflow-hidden">
    <div class="absolute inset-0 -z-20 bg-gradient-to-b from-blue-50/70 via-white to-white dark:from-gray-900 dark:via-gray-950 dark:to-gray-950"></div>
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(#93c5fd55_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(ellipse_at_top,black,transparent_60%)] dark:bg-[radial-gradient(#1e40af44_1px,transparent_1px)]"></div>
    <div class="absolute -top-24 -left-24 w-[26rem] h-[26rem] -z-10 rounded-full bg-blue-400/30 dark:bg-blue-700/20 blur-3xl blob"></div>
    <div class="absolute -top-10 right-0 w-[24rem] h-[24rem] -z-10 rounded-full bg-indigo-400/25 dark:bg-indigo-700/20 blur-3xl blob" style="animation-delay:-7s"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid lg:grid-cols-2 gap-12 items-center">
        <div class="reveal in">
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-white/70 dark:bg-white/10 ring-1 ring-blue-200 dark:ring-blue-900 text-blue-700 dark:text-blue-300 backdrop-blur mb-6">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span><?= e(__('gg_hero_badge')) ?>
            </span>
            <h1 class="font-display font-extrabold text-4xl sm:text-5xl lg:text-6xl tracking-tight leading-[1.05] bg-gradient-to-br from-gray-900 via-gray-900 to-blue-700 dark:from-white dark:via-white dark:to-blue-300 bg-clip-text text-transparent">
                <?= e(__('gg_hero_title')) ?>
            </h1>
            <p class="mt-6 text-lg text-gray-600 dark:text-gray-300 max-w-xl"><?= e(__('gg_hero_sub')) ?></p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3">
                <a href="register.php" class="group inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl text-white font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 transition-all shadow-xl shadow-blue-600/30 hover:shadow-blue-600/40 hover:-translate-y-0.5">
                    <?= __('gg_get_started') ?>
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                <a href="mailto:hello@growgig.tech" class="inline-flex items-center justify-center px-6 py-3.5 rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-semibold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"><?= __('gg_book_demo') ?></a>
            </div>
            <div class="mt-6 flex items-center gap-3">
                <div class="flex text-amber-400">
                    <?php for ($s = 0; $s < 5; $s++): ?><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.36 4.18a1 1 0 00.95.69h4.4c.97 0 1.37 1.24.59 1.81l-3.56 2.59a1 1 0 00-.36 1.12l1.36 4.18c.3.92-.76 1.69-1.54 1.12l-3.56-2.59a1 1 0 00-1.18 0l-3.56 2.59c-.78.57-1.84-.2-1.54-1.12l1.36-4.18a1 1 0 00-.36-1.12L1.4 9.61c-.78-.57-.38-1.81.59-1.81h4.4a1 1 0 00.95-.69L9.05 2.93z"/></svg><?php endfor; ?>
                </div>
                <span class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('gg_hero_note')) ?></span>
            </div>
        </div>

        <!-- Product mock -->
        <div class="relative reveal in floaty" style="animation-delay:.15s">
            <div class="absolute -inset-6 -z-10 bg-gradient-to-tr from-blue-500/20 to-indigo-500/20 blur-3xl rounded-full"></div>
            <div class="rounded-2xl bg-white dark:bg-gray-800 shadow-2xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
                <div class="flex items-center gap-1.5 px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <span class="w-3 h-3 rounded-full bg-red-400"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                    <span class="w-3 h-3 rounded-full bg-green-400"></span>
                    <span class="ml-3 text-xs text-gray-400 font-medium">GrowGig Dashboard</span>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <?php
                        $mock = [['Items','128','from-blue-500 to-blue-600'],['Low','7','from-amber-400 to-amber-500'],['Branches','3','from-teal-500 to-teal-600']];
                        foreach ($mock as [$lbl,$val,$bg]): ?>
                            <div class="rounded-xl border border-gray-100 dark:border-gray-700 p-3 bg-white dark:bg-gray-800">
                                <div class="w-7 h-7 rounded-lg bg-gradient-to-br <?= $bg ?> mb-2"></div>
                                <p class="text-[11px] text-gray-400"><?= $lbl ?></p>
                                <p class="text-xl font-bold text-gray-900 dark:text-white"><?= $val ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-gray-700 overflow-hidden text-xs">
                        <?php
                        $rows = [['Exam Gloves','32','ok'],['Hand Sanitizer','9','ok'],['Gauze Pads','4','low'],['Syringe 5ml','5','low']];
                        foreach ($rows as [$n,$q,$st]): ?>
                            <div class="flex items-center justify-between px-3 py-2.5 border-b last:border-0 border-gray-100 dark:border-gray-700">
                                <span class="font-medium text-gray-700 dark:text-gray-200"><?= $n ?></span>
                                <span class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400"><?= $q ?></span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $st === 'low' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' ?>"><?= $st === 'low' ? 'Low' : 'In Stock' ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="absolute -bottom-5 -left-5 floaty hidden sm:flex items-center gap-3 px-4 py-3 rounded-2xl bg-white dark:bg-gray-800 shadow-xl ring-1 ring-gray-900/5 dark:ring-white/10">
                <span class="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-300 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z"/></svg>
                </span>
                <div>
                    <p class="text-xs font-semibold text-gray-900 dark:text-white">Low-stock alert</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">2 items need reorder</p>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 reveal">
            <?php
            $hstats = [['gg_hero_stat1','M19 11H5m14-4H5m14 8H5m14 4H5'],['gg_hero_stat2','M9 17v-6h13M9 5h13M3 5h.01M3 12h.01M3 19h.01'],['gg_hero_stat3','M3 5h12M9 3v2m1 9.5A18 18 0 016.4 9m6.1 9h7M3 21l4-4']];
            foreach ($hstats as [$k,$icon]): ?>
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-white/70 dark:bg-gray-800/60 ring-1 ring-gray-200 dark:ring-gray-700 backdrop-blur">
                    <span class="w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/></svg>
                    </span>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200"><?= e(__($k)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<!-- ===================== Trusted (logo cloud) ===================== -->
<section class="border-y border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-900/40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <p class="text-center text-sm font-medium text-gray-500 dark:text-gray-400 mb-6"><?= e(__('gg_trusted')) ?></p>
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
                    <span class="inline-flex items-center px-3 py-3 rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200/80 dark:ring-gray-700 shadow-sm shrink-0">
                        <span class="rounded-xl bg-white p-1.5 flex items-center justify-center shrink-0 ring-1 ring-gray-200/70">
                            <img src="<?= e($img) ?>" alt="<?= e($name) ?>" class="h-20 w-auto max-w-[200px] object-contain" loading="lazy">
                        </span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Features ===================== -->
<section id="features" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28 scroll-mt-20">
    <div class="text-center max-w-2xl mx-auto reveal">
        <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_features_eyebrow')) ?></p>
        <h2 class="mt-3 font-display text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white"><?= e(__('gg_features_title')) ?></h2>
        <p class="mt-4 text-gray-600 dark:text-gray-300"><?= e(__('gg_features_sub')) ?></p>
    </div>
    <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php
        $features = [
            ['gg_feat1_title','gg_feat1_desc','from-blue-500 to-blue-600','M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['gg_feat2_title','gg_feat2_desc','from-emerald-500 to-emerald-600','M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
            ['gg_feat3_title','gg_feat3_desc','from-rose-500 to-rose-600','M9 17a2 2 0 11-4 0 2 2 0 014 0zM20 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8h4l3 4v4a1 1 0 01-1 1h-1'],
            ['gg_feat4_title','gg_feat4_desc','from-indigo-500 to-indigo-600','M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['gg_feat5_title','gg_feat5_desc','from-sky-500 to-sky-600','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['gg_feat6_title','gg_feat6_desc','from-purple-500 to-purple-600','M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['gg_feat7_title','gg_feat7_desc','from-amber-400 to-amber-500','M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z'],
            ['gg_feat8_title','gg_feat8_desc','from-teal-500 to-teal-600','M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8z'],
            ['gg_feat9_title','gg_feat9_desc','from-fuchsia-500 to-fuchsia-600','M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ];
        foreach ($features as $i => [$t,$d,$bg,$icon]): ?>
            <div class="reveal group relative overflow-hidden p-6 rounded-2xl bg-white dark:bg-gray-800/70 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-2xl hover:shadow-blue-600/10 hover:-translate-y-1.5 hover:border-transparent transition-all duration-300" style="animation-delay:<?= $i * 60 ?>ms">
                <div class="pointer-events-none absolute -top-16 -right-16 w-40 h-40 rounded-full bg-gradient-to-br <?= $bg ?> opacity-0 group-hover:opacity-10 blur-2xl transition-opacity duration-500"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br <?= $bg ?> text-white flex items-center justify-center mb-5 shadow-lg shadow-blue-600/20 ring-4 ring-white dark:ring-gray-800 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="<?= $icon ?>"/></svg>
                </div>
                <h3 class="font-display font-semibold text-lg text-gray-900 dark:text-white"><?= e(__($t)) ?></h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?= e(__($d)) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===================== How it works ===================== -->
<section id="how" class="relative bg-gray-50 dark:bg-gray-900/40 scroll-mt-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
        <div class="text-center max-w-2xl mx-auto reveal">
            <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_how_eyebrow')) ?></p>
            <h2 class="mt-3 font-display text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white"><?= e(__('gg_how_title')) ?></h2>
        </div>
        <div class="mt-14 grid gap-8 md:grid-cols-3 relative">
            <div class="hidden md:block absolute top-7 left-[16%] right-[16%] h-0.5 bg-gradient-to-r from-blue-200 via-indigo-300 to-blue-200 dark:from-gray-700 dark:via-gray-600 dark:to-gray-700"></div>
            <?php
            $steps = [['1','gg_step1_title','gg_step1_desc'],['2','gg_step2_title','gg_step2_desc'],['3','gg_step3_title','gg_step3_desc']];
            foreach ($steps as $i => [$n,$t,$d]): ?>
                <div class="relative text-center reveal" style="animation-delay:<?= $i * 100 ?>ms">
                    <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white text-2xl font-bold flex items-center justify-center shadow-xl shadow-blue-600/30 ring-4 ring-gray-50 dark:ring-gray-900"><?= $n ?></div>
                    <h3 class="mt-5 font-display font-semibold text-lg text-gray-900 dark:text-white"><?= e(__($t)) ?></h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 max-w-xs mx-auto"><?= e(__($d)) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== Highlight blocks ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28 space-y-20">
    <!-- Block 1 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div>
            <h3 class="font-display text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('gg_block1_title')) ?></h3>
            <p class="mt-4 text-gray-600 dark:text-gray-300"><?= e(__('gg_block1_desc')) ?></p>
        </div>
        <div class="rounded-2xl p-6 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-900 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
            <div class="space-y-2.5">
                <?php foreach ([['BrightPath HQ','248'],['BrightPath Penang','181'],['BrightPath Johor','96']] as [$b,$c]): ?>
                    <div class="flex items-center justify-between px-4 py-3 rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-100 dark:ring-gray-700">
                        <span class="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-200"><span class="w-2 h-2 rounded-full bg-blue-500"></span><?= $b ?></span>
                        <span class="text-sm text-gray-500 dark:text-gray-400"><?= $c ?> items</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Block 2 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div class="lg:order-2">
            <h3 class="font-display text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('gg_block2_title')) ?></h3>
            <p class="mt-4 text-gray-600 dark:text-gray-300"><?= e(__('gg_block2_desc')) ?></p>
        </div>
        <div class="lg:order-1 rounded-2xl p-6 bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-gray-800 dark:to-gray-900 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
            <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-100 dark:ring-gray-700 overflow-hidden text-sm">
                <div class="grid grid-cols-4 px-4 py-2 text-[11px] font-semibold text-gray-400 border-b border-gray-100 dark:border-gray-700"><span>Item</span><span class="text-center text-emerald-600">In</span><span class="text-center text-red-500">Out</span><span class="text-right">Bal</span></div>
                <?php foreach ([['Exam Gloves','20','3','49'],['Face Masks','10','1','25']] as [$n,$in,$out,$bal]): ?>
                    <div class="grid grid-cols-4 px-4 py-2.5 border-b last:border-0 border-gray-100 dark:border-gray-700">
                        <span class="font-medium text-gray-700 dark:text-gray-200"><?= $n ?></span>
                        <span class="text-center text-emerald-600 dark:text-emerald-400">+<?= $in ?></span>
                        <span class="text-center text-red-500 dark:text-red-400"><?= $out ?></span>
                        <span class="text-right font-semibold text-gray-900 dark:text-white"><?= $bal ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Block 3 -->
    <div class="grid lg:grid-cols-2 gap-10 items-center reveal">
        <div>
            <h3 class="font-display text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('gg_block3_title')) ?></h3>
            <p class="mt-4 text-gray-600 dark:text-gray-300"><?= e(__('gg_block3_desc')) ?></p>
        </div>
        <div class="rounded-2xl p-6 bg-gradient-to-br from-purple-50 to-blue-50 dark:from-gray-800 dark:to-gray-900 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
            <div class="flex flex-wrap gap-2.5">
                <?php
                $roles = [['account_admin','bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],['account_user','bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300']];
                foreach ($roles as [$code,$cls]): ?>
                    <span class="px-3.5 py-2 rounded-xl text-sm font-semibold <?= $cls ?>"><?= e(role_label($code)) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400"><?= e(__('gg_block3_caption')) ?></p>
        </div>
    </div>
</section>

<!-- ===================== Devices ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
    <div class="grid lg:grid-cols-2 gap-14 items-center">
        <div class="reveal">
            <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_dev_eyebrow')) ?></p>
            <h2 class="mt-3 font-display text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white"><?= e(__('gg_dev_title')) ?></h2>
            <p class="mt-4 text-gray-600 dark:text-gray-300 max-w-lg"><?= e(__('gg_dev_sub')) ?></p>
            <div class="mt-7 flex flex-wrap gap-3">
                <?php
                $devs = [
                    ['gg_dev_desktop','M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a.75.75 0 01-.75.75H3.75A.75.75 0 013 12V5.25'],
                    ['gg_dev_tablet','M10.5 19.5h3m-6.75 2.25h10.5a2.25 2.25 0 002.25-2.25v-15a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 4.5v15a2.25 2.25 0 002.25 2.25z'],
                    ['gg_dev_mobile','M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3'],
                ];
                foreach ($devs as [$lbl,$icon]): ?>
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm text-sm font-semibold text-gray-700 dark:text-gray-200">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
                        <?= e(__($lbl)) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Laptop + phone mock -->
        <div class="relative reveal pb-10" style="animation-delay:.15s">
            <div class="absolute -inset-6 -z-10 bg-gradient-to-tr from-blue-500/15 to-indigo-500/15 blur-3xl rounded-full"></div>
            <!-- laptop -->
            <div class="rounded-2xl bg-white dark:bg-gray-800 shadow-2xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
                <div class="flex items-center gap-1.5 px-4 py-2.5 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                    <span class="ml-3 flex-1"><span class="block max-w-[60%] mx-auto text-center text-[10px] text-gray-400 bg-white dark:bg-gray-800 rounded-md py-0.5 ring-1 ring-gray-200 dark:ring-gray-700">app.growgig.tech</span></span>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <?php foreach ([['Items','128','from-blue-500 to-blue-600'],['Low','7','from-amber-400 to-amber-500'],['Branches','3','from-teal-500 to-teal-600']] as [$l,$v,$bg]): ?>
                            <div class="rounded-xl border border-gray-100 dark:border-gray-700 p-3">
                                <div class="w-6 h-6 rounded-lg bg-gradient-to-br <?= $bg ?> mb-2"></div>
                                <p class="text-[10px] text-gray-400"><?= $l ?></p>
                                <p class="text-lg font-bold text-gray-900 dark:text-white"><?= $v ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-gray-700 overflow-hidden text-xs">
                        <?php foreach ([['Exam Gloves','32','ok'],['Hand Sanitizer','9','ok'],['Gauze Pads','4','low'],['Cotton Roll','22','ok']] as [$n,$q,$st]): ?>
                            <div class="flex items-center justify-between px-3 py-2 border-b last:border-0 border-gray-100 dark:border-gray-700">
                                <span class="font-medium text-gray-700 dark:text-gray-200"><?= $n ?></span>
                                <span class="flex items-center gap-2"><span class="text-gray-500 dark:text-gray-400"><?= $q ?></span>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-semibold <?= $st === 'low' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' ?>"><?= $st === 'low' ? 'Low' : 'OK' ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="mx-auto h-2.5 w-3/5 rounded-b-2xl bg-gray-200 dark:bg-gray-700"></div>
            <!-- phone -->
            <div class="absolute -bottom-2 right-0 sm:right-4 w-28 sm:w-32 floaty">
                <div class="rounded-[1.5rem] bg-gray-900 p-1.5 shadow-2xl ring-1 ring-black/10">
                    <div class="rounded-[1.1rem] bg-white dark:bg-gray-800 overflow-hidden">
                        <div class="px-3 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-[9px] font-bold">GIS · Stock</div>
                        <div class="p-2 space-y-1.5">
                            <?php foreach ([['Exam Gloves','32'],['Gauze Pads','4'],['Cotton Roll','22']] as [$n,$q]): ?>
                                <div class="flex items-center justify-between px-2 py-1.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 text-[8px]">
                                    <span class="font-medium text-gray-700 dark:text-gray-200"><?= $n ?></span>
                                    <span class="text-gray-500 dark:text-gray-400"><?= $q ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-1 text-center text-[8px] font-semibold text-white bg-blue-600 rounded-lg py-1.5">+ Stock In</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Pricing (Setmore-style) ===================== -->
<section id="pricing" class="bg-gray-50 dark:bg-gray-900/40 scroll-mt-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
        <div class="grid lg:grid-cols-3 gap-8 items-start">

            <!-- Left intro -->
            <div class="reveal">
                <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_pricing_eyebrow')) ?></p>
                <h2 class="mt-3 font-display text-4xl sm:text-5xl font-extrabold text-gray-900 dark:text-white leading-tight">
                    <?= e(__('gg_pr_head1')) ?><br><span class="text-gray-400 dark:text-gray-500"><?= e(__('gg_pr_head2')) ?></span>
                </h2>
                <div class="mt-8 p-5 rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm">
                    <div class="flex text-amber-400 mb-2">
                        <?php for ($s = 0; $s < 5; $s++): ?><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.36 4.18a1 1 0 00.95.69h4.4c.97 0 1.37 1.24.59 1.81l-3.56 2.59a1 1 0 00-.36 1.12l1.36 4.18c.3.92-.76 1.69-1.54 1.12l-3.56-2.59a1 1 0 00-1.18 0l-3.56 2.59c-.78.57-1.84-.2-1.54-1.12l1.36-4.18a1 1 0 00-.36-1.12L1.4 9.61c-.78-.57-.38-1.81.59-1.81h4.4a1 1 0 00.95-.69L9.05 2.93z"/></svg><?php endfor; ?>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-200">&ldquo;<?= e(__('gg_pr_quote')) ?>&rdquo;</p>
                    <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white"><?= e(__('gg_pr_quote_name')) ?></p>
                    <p class="mt-2 inline-flex items-center gap-1.5 text-xs font-bold text-green-600 dark:text-green-400">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.3 3.29 6.8-6.79a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                        <?= e(__('gg_pr_excellent')) ?>
                    </p>
                </div>
            </div>

            <!-- Pro card -->
            <div class="reveal relative flex flex-col p-7 rounded-3xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white shadow-2xl shadow-blue-600/30">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-xs font-bold bg-amber-400 text-amber-950 shadow"><?= e(__('gg_badge_popular')) ?></span>
                <h3 class="font-display font-bold text-2xl"><?= e(__('gg_pr_pro_name')) ?></h3>
                <p class="mt-1 text-sm text-blue-100"><?= e(__('gg_pr_pro_tag')) ?></p>

                <div class="mt-5 flex items-center gap-2">
                    <span class="text-blue-200 line-through text-xl">RM99</span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-400 text-amber-950"><?= e(__('gg_pr_promo')) ?></span>
                </div>
                <p class="mt-1 flex items-end gap-1">
                    <span class="font-display text-5xl font-extrabold">RM9.90</span>
                    <span class="text-blue-200 mb-1"><?= e(__('gg_pr_permonth')) ?></span>
                </p>
                <p class="mt-2 text-xs text-blue-100 leading-relaxed"><?= e(__('gg_pr_promo_note')) ?></p>

                <a href="register.php" class="mt-6 inline-flex justify-center px-5 py-3 rounded-xl font-semibold bg-white text-blue-700 hover:bg-blue-50 transition-colors"><?= e(__('gg_pr_start_pro')) ?></a>
                <div class="mt-4 flex items-center justify-center gap-2 py-2 rounded-lg bg-white/10 text-xs font-semibold text-blue-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?= e(__('gg_pr_support')) ?>
                </div>
                <ul class="mt-6 space-y-3">
                    <?php foreach (['gg_pr_pf1','gg_pr_pf2','gg_pr_pf3','gg_pr_pf4','gg_pr_pf5','gg_pr_pf6','gg_pr_pf7','gg_pr_pf8'] as $f): ?>
                        <li class="flex items-start gap-2.5 text-sm text-blue-50">
                            <svg class="w-5 h-5 shrink-0 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= e(__($f)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Free card -->
            <div class="reveal flex flex-col p-7 rounded-3xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm">
                <h3 class="font-display font-bold text-2xl text-gray-900 dark:text-white"><?= e(__('gg_pr_free_name')) ?></h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('gg_pr_free_tag')) ?></p>
                <p class="mt-5 flex items-end gap-1">
                    <span class="font-display text-5xl font-extrabold text-gray-900 dark:text-white">RM0</span>
                    <span class="text-gray-400 mb-1"><?= e(__('gg_pr_permonth')) ?></span>
                </p>
                <p class="mt-1 text-xs text-gray-400">&nbsp;</p>
                <a href="register.php" class="mt-6 inline-flex justify-center px-5 py-3 rounded-xl font-semibold bg-gray-900 dark:bg-blue-600 text-white hover:bg-gray-800 dark:hover:bg-blue-700 transition-colors"><?= e(__('gg_pr_start_free')) ?></a>
                <div class="mt-4 flex items-center justify-center gap-2 py-2 rounded-lg bg-gray-100 dark:bg-gray-700/60 text-xs font-semibold text-gray-500 dark:text-gray-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 001.9 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <?= e(__('gg_pr_free_support')) ?>
                </div>
                <ul class="mt-6 space-y-3">
                    <?php foreach (['gg_pr_ff1','gg_pr_ff2','gg_pr_ff3','gg_pr_ff4','gg_pr_ff5'] as $f): ?>
                        <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                            <svg class="w-5 h-5 shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= e(__($f)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <p class="mt-8 text-center text-xs text-gray-400"><?= e(__('gg_price_disclaimer')) ?></p>
    </div>
</section>

<!-- ===================== Testimonials ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
    <div class="text-center max-w-2xl mx-auto reveal">
        <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_testi_eyebrow')) ?></p>
        <h2 class="mt-3 font-display text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white"><?= e(__('gg_testi_title')) ?></h2>
    </div>
    <div class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        <?php
        $gradients = ['from-blue-500 to-indigo-600','from-purple-500 to-blue-600','from-teal-500 to-emerald-600','from-amber-500 to-orange-600','from-rose-500 to-pink-600','from-indigo-500 to-violet-600'];
        for ($t = 1; $t <= 6; $t++):
            $grad = $gradients[$t - 1]; ?>
            <div class="reveal p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-lg transition-shadow" style="animation-delay:<?= ($t - 1) * 60 ?>ms">
                <div class="flex text-amber-400 mb-3">
                    <?php for ($s = 0; $s < 5; $s++): ?><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.36 4.18a1 1 0 00.95.69h4.4c.97 0 1.37 1.24.59 1.81l-3.56 2.59a1 1 0 00-.36 1.12l1.36 4.18c.3.92-.76 1.69-1.54 1.12l-3.56-2.59a1 1 0 00-1.18 0l-3.56 2.59c-.78.57-1.84-.2-1.54-1.12l1.36-4.18a1 1 0 00-.36-1.12L1.4 9.61c-.78-.57-.38-1.81.59-1.81h4.4a1 1 0 00.95-.69L9.05 2.93z"/></svg><?php endfor; ?>
                </div>
                <p class="text-gray-700 dark:text-gray-200 leading-relaxed">&ldquo;<?= e(__('gg_t' . $t . '_quote')) ?>&rdquo;</p>
                <div class="mt-5 flex items-center gap-3">
                    <span class="w-11 h-11 rounded-full bg-gradient-to-br <?= $grad ?> text-white font-bold flex items-center justify-center"><?= e(mb_substr(__('gg_t' . $t . '_name'), 0, 1)) ?></span>
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= e(__('gg_t' . $t . '_name')) ?></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= e(__('gg_t' . $t . '_role')) ?></p>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</section>

<!-- ===================== FAQ ===================== -->
<section id="faq" class="bg-gray-50 dark:bg-gray-900/40 scroll-mt-20">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
        <div class="text-center reveal mb-12">
            <p class="text-sm font-bold uppercase tracking-widest bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent"><?= e(__('gg_faq_eyebrow')) ?></p>
            <h2 class="mt-3 font-display text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white"><?= e(__('gg_faq_title')) ?></h2>
        </div>
        <div class="space-y-3">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <details class="reveal group rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 open:shadow-md transition-shadow">
                    <summary class="flex items-center justify-between cursor-pointer font-display font-semibold text-gray-900 dark:text-white">
                        <?= e(__('gg_faq_q' . $i)) ?>
                        <span class="ml-4 shrink-0 w-7 h-7 rounded-full bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300 flex items-center justify-center group-open:rotate-180 transition-transform">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </span>
                    </summary>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?= e(__('gg_faq_a' . $i)) ?></p>
                </details>
            <?php endfor; ?>
        </div>
    </div>
</section>

<!-- ===================== Final CTA ===================== -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-blue-600 via-indigo-600 to-indigo-700 px-6 py-16 text-center shadow-2xl shadow-blue-600/30 reveal">
        <div class="absolute inset-0 -z-0 opacity-30 bg-[radial-gradient(circle_at_top_left,white,transparent_45%)]"></div>
        <div class="relative">
            <h2 class="font-display text-3xl sm:text-4xl font-extrabold text-white"><?= e(__('gg_final_title')) ?></h2>
            <p class="mt-3 text-blue-100 max-w-2xl mx-auto"><?= e(__('gg_final_sub')) ?></p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="register.php" class="px-7 py-3.5 rounded-xl bg-white text-blue-700 font-semibold hover:bg-blue-50 transition-colors shadow-lg"><?= __('gg_get_started') ?></a>
                <a href="mailto:hello@growgig.tech" class="px-7 py-3.5 rounded-xl bg-white/10 text-white font-semibold border border-white/30 hover:bg-white/20 transition-colors"><?= __('gg_book_demo') ?></a>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Footer ===================== -->
<footer class="bg-gray-950 text-gray-300 border-t border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center h-11 w-11 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-600/30"><svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V13"/><path d="M10 20V9"/><path d="M16 20v-5"/><path d="M4 8.5l6-4 5 3 5-4"/><path d="M20 3.5v4h-4"/></svg></span>
                <span class="leading-tight">
                    <span class="block font-display font-extrabold text-lg text-white">GrowGig</span>
                    <span class="block text-[10.5px] font-medium tracking-wide text-gray-500 -mt-0.5"><?= e(__('gg_brand_tagline')) ?></span>
                </span>
            </div>
            <p class="mt-4 text-sm text-gray-400 max-w-xs"><?= e(__('gg_foot_blurb')) ?></p>
        </div>
        <div>
            <h4 class="font-semibold text-white mb-4"><?= e(__('gg_foot_product')) ?></h4>
            <ul class="space-y-2.5 text-sm">
                <li><a href="#features" class="hover:text-white transition-colors"><?= __('gg_nav_features') ?></a></li>
                <li><a href="#pricing" class="hover:text-white transition-colors"><?= __('gg_nav_pricing') ?></a></li>
                <li><a href="login.php" class="hover:text-white transition-colors"><?= __('nav_login') ?></a></li>
                <li><a href="register.php" class="hover:text-white transition-colors"><?= __('gg_get_started') ?></a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold text-white mb-4"><?= e(__('gg_foot_company')) ?></h4>
            <ul class="space-y-2.5 text-sm">
                <li><a href="#how" class="hover:text-white transition-colors"><?= __('gg_nav_how') ?></a></li>
                <li><a href="#faq" class="hover:text-white transition-colors"><?= __('gg_nav_faq') ?></a></li>
                <li><a href="mailto:hello@growgig.tech" class="hover:text-white transition-colors"><?= __('gg_foot_contact') ?></a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold text-white mb-4"><?= e(__('gg_foot_contact')) ?></h4>
            <ul class="space-y-2.5 text-sm">
                <li><a href="mailto:hello@growgig.tech" class="hover:text-white transition-colors">hello@growgig.tech</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 text-sm text-gray-500 text-center">
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
    function toggleAnnual(cb) {
        var on = cb.checked;
        var p = document.getElementById('proPrice');
        var n = document.getElementById('proNote');
        if (p) p.textContent = on ? p.dataset.annual : p.dataset.monthly;
        if (n) n.textContent = on ? n.dataset.annual : n.dataset.monthly;
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
