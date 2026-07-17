# Homepage Blue-AO Conversion Upgrade — Design

Date: 2026-07-17 · Approved by user in chat.

## Goal
Rebuild `index.php` (public marketing homepage) so it (1) matches the blue-AO
design system already shipped on login + dashboard, and (2) follows a
conversion-focused structure (pain → solution → proof → objection handling →
CTA). No backend changes, no new pages.

## Visual
- Fonts: Bricolage Grotesque (display) / Figtree (body) / JetBrains Mono
  (numbers). Remove Inter, Plus Jakarta Sans, Orbitron, Calipso.
- Load `assets/theme.css` after the Tailwind CDN; use `--blue / --ink / --paper`
  tokens and `.ao-card`, `.ao-btn`, `.ao-badge`, `.ao-stat` classes.
- Hero becomes a full-bleed navy `--hero-grad` panel (same identity as the
  login hero panel); product mock restyled to look like the real AO dashboard.
- Footer on `--ink`. Dark mode preserved (`.dark` on `<html>`).

## Structure (top → bottom)
1. Navbar (AO buttons, EN/MY toggle, theme toggle) — unchanged behaviour.
2. Hero: outcome-led headline, pain-aware subcopy, dual CTA
   (Start free / Book demo), risk-reversal note, AO dashboard mock.
3. Trust logo marquee (unchanged content).
4. **NEW** Pain → Solution: 3 pain cards (stock-outs/expiry, manual Excel,
   multi-branch blindness) + bridge line into the product.
5. Features ×9 (existing keys, benefit-led copy kept).
6. How it works ×3 steps.
7. Highlight blocks ×3 (existing).
8. Devices section (existing).
9. **NEW** Comparison table: manual/Excel vs GrowGig, 6 rows.
10. Pricing (Pro navy card + Free card, existing keys).
11. Testimonials ×6 (existing keys).
12. **NEW** Guarantee strip (free trial, cancel anytime, data export).
13. FAQ — extended 5 → 7 (adds data-security + Excel-migration objections).
14. Final CTA panel with soft urgency (first-year promo note).
15. Footer.

## Copy / i18n
All new copy goes through `__()` keys added to `includes/lang.php` in BOTH
`en` and `ms` arrays (app stays EN-default). New key groups:
`gg_hero_*2`, `gg_pain_*`, `gg_cmp_*`, `gg_guarantee_*`, `gg_faq_q6/a6`,
`gg_faq_q7/a7`, `gg_final_urgency`. Existing keys reused where copy is fine.

## Assets
Optionally one Higgsfield-generated image (clinic/therapy shelf scene, blue
grade) saved to `assets/` for the pain/solution section. If generation or
auth fails, ship with CSS mocks only — not a blocker.

## Verification
`php -l` (Herd php84), then preview at localhost:8090 (serve.bat): check both
languages, dark mode, mobile width, all anchors, screenshot proof.
