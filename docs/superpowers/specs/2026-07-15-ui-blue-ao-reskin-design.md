# Design Spec — Blue-AO UI Reskin (Hybrid)

**Date:** 2026-07-15
**Goal:** Upgrade the GrowGig/Aktifotak inventory app UI to match the polish of the *Aktifotak Car Sticker System* ("AO") design language, **while keeping a blue theme** (AO's accent is red; we translate it to blue).

**Approach chosen:** Hybrid — a shared design *foundation* recolors/re-fonts every page automatically, plus full AO-style rebuilds of the highest-visibility surfaces (login, dashboard, sidebar). Inner pages inherit the foundation, layouts unchanged.

## Reference design (AO — `PAKYA/Aktifotak Car Sticker System/public/style.css`)
- Warm paper bg, white cards, dark "ink" sidebar, red accent.
- Fonts: **Bricolage Grotesque** (display), **Figtree** (body), **JetBrains Mono** (numbers/plate).
- Signatures: stat cards + one hero-gradient stat, pill badges, tab pills, soft shadows, radius 14px, `fadeUp` animation, toasts, blur modals, dark mode via `data-theme`.

## Translation to inventory app (blue)
Palette tokens (light / dark):
| Token | Light | Dark |
|---|---|---|
| `--blue` | `#2563eb` | `#3b82f6` |
| `--blue-deep` | `#1d4ed8` | `#60a5fa` |
| `--blue-soft` | `#eaf1ff` | `#16233f` |
| `--ink` / sidebar | `#0f172a` | `#0b1020` |
| `--paper` (body) | `#f3f6fc` | `#0b1020` |
| `--card` | `#ffffff` | `#141a2e` |
| `--line` | `#e5e9f2` | `#26304a` |
| `--txt` | `#1e293b` | `#e2e8f5` |
| `--muted` | `#64748b` | `#94a3c0` |
| `--ok` | `#0e9f6e` | `#2fc78f` |
| `--warn` | `#c77414` | `#e79a3c` |
| `--danger` | `#dc2626` | `#f87171` |
| `--hero-grad` | `120deg,#0f172a→#1e3a8a→#2563eb` | `#0b1020→#16225a→#2563eb` |

## Foundation (touches every page)
1. **`assets/theme.css` (new)** — tokens (light + `.dark`), body paper bg + Figtree, `h1–h3/.font-display` → Bricolage, component classes: `.ao-card`, `.ao-stat`, `.ao-hero`, `.badge` (+variants), `.ao-btn` (+variants), `.num` (mono tabular), tab pills, custom scrollbar, `@keyframes fadeUp`, blue focus ring. Respects `prefers-reduced-motion`.
2. **`includes/header.php`**:
   - Google Fonts preconnect + `Bricolage Grotesque` / `Figtree` / `JetBrains Mono`.
   - Extend `tailwind.config.theme.extend`: remap `colors.indigo` → blue-AO scale (so all existing `indigo-*` utilities become blue-AO); `fontFamily.sans=Figtree`, `.display`, `.mono`.
   - `<link rel="stylesheet" href="assets/theme.css">` **after** the Tailwind CDN script (override order).
   - Swap `<body>` classes: remove `bg-gray-50 dark:bg-gray-900` (theme.css `body{background:var(--paper)}` owns it; element selector must not be beaten by a Tailwind class).

Because pages already use `indigo-*` / `bg-white` / cards, the remap + theme.css shift the whole app to blue-AO with zero per-page edits.

## Full AO rebuilds
3. **Sidebar + mobile topbar (`header.php`)** — white → navy `--ink`; nav items AO style (blue left-bar when active), brand tile, role chip, language/theme controls restyled. All links, badges (`$unread`, `$fbUnread`), roles, account switcher, impersonation banner **unchanged**.
4. **`login.php`** — split screen: navy gradient hero (logo + Bricolage headline + mono deco strip) | white form card. Preserve: `email`/`password` inputs, `csrf_field()`, `POST action=login.php`, `?registered` + `$error` banners, register link, autofocus, dark/lang toggles.
5. **`dashboard.php`** — `.page-head` Bricolage heading; AO stat cards + one `.ao-hero` gradient card; `.num` mono values; retune Chart.js palette to blue family. All PHP queries, role logic, subscription banner, charts data **unchanged**.
6. **`includes/footer.php`** — align link colors to tokens (minor); no structural change.

## Non-goals / untouched
DB, business logic, forms, CSRF, multi-tenant branding (`current_brand()`), PDF reports (`print_report.php`), Chart.js data. No new dependencies (fonts via CDN, same as existing Orbitron).

## Dark mode & i18n
Keep the existing `.dark` class toggle (`toggleTheme()` in footer.php) — theme.css keys all tokens off `.dark`. Bilingual EN/MS untouched.

## Risk
Low. No data/logic changes. Main risks: (a) body-bg specificity (handled by editing body classes), (b) contrast in dark mode (verified in review), (c) sidebar restyle regressions (badges/active states) — covered by live verify + adversarial workflow review.

## Verification
- Local: `serve.bat` → `http://localhost:8090`. Login + dashboard render; no console/network errors; dark toggle; mobile sidebar drawer; EN/MS.
- Adversarial Workflow review: dark-mode correctness, contrast/a11y, responsive, functionality-preservation, AO-fidelity, cross-page consistency → fix.
- Proof screenshots to user. Deploy via the user's push + VPS loop (agent does not push/deploy).

## Implementation order
theme.css → header.php (foundation + sidebar) → footer.php → login.php → dashboard.php → live verify → workflow review → fixes → screenshots.
