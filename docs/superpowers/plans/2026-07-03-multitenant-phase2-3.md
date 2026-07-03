# Multi-tenant Accounts Phase 2 (billing) + Phase 3 (branding) Implementation Plan

> **For agentic workers:** implemented via superpowers:subagent-driven-development, task-by-task. Steps use `- [ ]`.

**Goal:** Make subscriptions per-account (each customer has its own trial/active/frozen + seat price, agency controls each), and make branding per-account (each account shows its own name/logo; agency reflects the tenant it is acting on).

**Architecture:** Build on Phase 1. `subscriptions.account_id` already exists. Scope the billing helpers by `current_account_id()`; scope the users.php billing panel and add per-account billing controls to accounts.php. For branding, `current_brand()` resolves the account in context (account user's own `account_id`, or agency's `acting_account_id`) from the `accounts` table (name/brand_name/logo), falling back to GrowGig; add per-account logo upload in accounts.php reusing the existing avatar-upload pattern.

**Tech Stack:** PHP 8.4 (Herd CLI), MariaDB 10.4 local / MySQL 8 prod (Docker), PDO, Tailwind CDN, no test framework.

## Global Constraints
- No test framework: verify with `php -l`, DB-assertion scripts (Herd php `C:/Users/anipe/.config/herd/bin/php84/php.exe`, DB `mysql:host=localhost;dbname=saas_inventory` root/no-pass), and logged-out curl on `http://localhost:8090`.
- Migrations idempotent (run every deploy). MariaDB CLI: `C:/xampp/mysql/bin/mysql.exe`.
- `current_account_id()` returns the account for account roles (non-null) and the acting account (or null="all") for agency. Agency is NEVER frozen.
- Reuse helpers: `current_account_id()`, `all_accounts()`, `account_name()`, `role_is_agency()`, `role_is_super()`, `csrf_*`, `e()`, `__()`. Bilingual EN+MS for new strings.
- Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Do NOT push (controller handles deploy).

---

### Task 1: Migration — a subscription row per account
**Files:** Create `deploy/migrations/2026-07-04_per_account_subscriptions.sql`; update `deploy/database.sql` (local dump) if it holds subscription DDL/data.

- [ ] **Step 1:** Write the migration. It must (a) be idempotent, (b) give every account that lacks a subscription a trial row. `subscriptions.account_id` + `uq_sub_account` already exist (Phase 1).
```sql
-- Ensure each account has exactly one subscription row (trial by default).
INSERT INTO `subscriptions` (`account_id`, `plan`, `status`, `trial_ends_at`, `price_per_user`)
SELECT a.id, 'trial', 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 29.90
FROM `accounts` a
WHERE NOT EXISTS (SELECT 1 FROM `subscriptions` s WHERE s.account_id = a.id);
```
- [ ] **Step 2:** Apply via MariaDB CLI twice (idempotent, both exit 0). Assert: `SELECT COUNT(*) FROM accounts a WHERE NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.account_id=a.id)` = 0; and no account has >1 subscription.
- [ ] **Step 3:** Commit (`git add deploy/migrations/2026-07-04_per_account_subscriptions.sql deploy/database.sql`).

---

### Task 2: Scope billing helpers by account (`config/config.php`)
**Files:** Modify `config/config.php`.
**Interfaces produced:** `get_subscription(?int $accountId = null): ?array`, `subscription_state(?int $accountId = null): array`, `stock_frozen_for(?string $role)` unchanged signature but now account-aware.

- [ ] **Step 1:** Change `get_subscription()` to accept an optional account id, defaulting to `current_account_id()`:
```php
function get_subscription(?int $accountId = null): ?array
{
    global $pdo;
    $accountId = $accountId ?? current_account_id();
    try {
        if ($accountId) {
            $st = $pdo->prepare('SELECT * FROM subscriptions WHERE account_id = ? ORDER BY id LIMIT 1');
            $st->execute([$accountId]);
            return $st->fetch() ?: null;
        }
        // agency "all accounts" (or no context): fall back to the earliest row (display only).
        $r = $pdo->query('SELECT * FROM subscriptions ORDER BY id LIMIT 1')->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
```
- [ ] **Step 2:** Change `subscription_state(?int $accountId = null)` to pass the id through: `$s = get_subscription($accountId);` (rest of the function body unchanged).
- [ ] **Step 3:** `stock_frozen_for($role)` already calls `subscription_state()` with no arg → now resolves to the caller's `current_account_id()`. Confirm agency still returns false early (it does). No change needed beyond confirming.
- [ ] **Step 4:** `php -l`; scratchpad assertion: for account #1, `subscription_state(1)['status']` matches the row; for a temp 2nd account with a frozen sub, `subscription_state(<id2>)['frozen']===true` while `subscription_state(1)` is unaffected. Clean up temp rows.
- [ ] **Step 5:** Commit `config/config.php`.

---

### Task 3: Per-account billing controls (`users.php` + `accounts.php`)
**Files:** Modify `users.php`, `accounts.php`, `includes/lang.php`.

- [ ] **Step 1 (users.php):** The billing panel currently reads `subscription_state()` (now auto-scoped to the manager's account — good) and the three actions (`sub_activate`, `sub_trial`, `sub_freeze`) run `UPDATE subscriptions SET status=...` with NO WHERE. Scope each to the current account: add `WHERE account_id = ?` bound to `current_account_id()`. Example:
```php
if ($action === 'sub_activate' && in_array($role, ['agency_admin','account_admin'], true)) {
    $pdo->prepare("UPDATE subscriptions SET status='active' WHERE account_id = ?")->execute([current_account_id()]);
    header('Location: users.php?msg=sub'); exit;
}
```
Apply the same `WHERE account_id = ?` to `sub_trial` (also keep its `DATE_ADD` trial reset) and `sub_freeze`. Guard: if `current_account_id()` is null (agency all-accounts), redirect `?msg=pick_account` (the early guard from Phase 1 already blocks agency-null before POST — confirm and rely on it; if not, add the guard).
- [ ] **Step 2 (accounts.php):** In the accounts list, the `sub_status` subquery already shows each account's status. Add per-row billing action buttons for `role_is_super` (agency_admin): Activate / Reset-trial / Freeze, each a small POST form to a new `accounts.php` action `set_sub` with fields `id` (account id) + `state` (`active|trial|frozen`). Handler:
```php
if ($action === 'set_sub' && role_is_super($role)) {
    $id = (int) ($_POST['id'] ?? 0);
    $state = in_array($_POST['state'] ?? '', ['active','trial','frozen'], true) ? $_POST['state'] : '';
    if ($id > 0 && $state !== '') {
        if ($state === 'trial') {
            $pdo->prepare("UPDATE subscriptions SET status='trial', trial_ends_at = DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE account_id = ?")->execute([$id]);
        } else {
            $pdo->prepare('UPDATE subscriptions SET status=? WHERE account_id = ?')->execute([$state, $id]);
        }
    }
    header('Location: accounts.php?msg=sub'); exit;
}
```
Add the `sub` flash (`'sub' => ['sub_updated','green']`) and the buttons in the actions cell (CSRF-protected). Also add a "monthly" column = that account's `account_user` seats × `price_per_user` (join subscriptions or compute from the row).
- [ ] **Step 3:** lang keys for any new labels (EN+MS): reuse existing `btn_activate`, `btn_reset_trial`, `btn_freeze`, `bill_monthly`, `sub_updated` where present; add only what's missing.
- [ ] **Step 4:** `php -l` all three; DB assertion: `set_sub` on account #1 to `frozen` then `active` flips only account #1's row; logged-out curl `users.php`/`accounts.php` → 302.
- [ ] **Step 5:** Commit.

---

### Task 4: Per-account branding in `current_brand()` (`config/config.php`)
**Files:** Modify `config/config.php`.

- [ ] **Step 1:** Replace `current_brand()` so it resolves the account in context. Rules: logged-out or agency with no acting account → GrowGig. Account user → their `account_id`'s brand. Agency with an acting account → that account's brand (so the operator sees which tenant they're in). Load name/brand_name/logo from `accounts`; fall back to GrowGig fields when missing.
```php
function current_brand(): array
{
    $growgig = ['key'=>'growgig','name'=>'GrowGig','nav_name'=>'GrowGig','logo'=>'assets/logo-growgig.png','accent'=>'text-blue-600 dark:text-blue-400'];
    if (!is_logged_in()) { return $growgig; }
    $acctId = current_account_id();
    if (!$acctId) { return $growgig; } // agency "all accounts"
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT name, brand_name, logo FROM accounts WHERE id = ?');
        $st->execute([$acctId]);
        $a = $st->fetch();
    } catch (Throwable $e) { $a = null; }
    if (!$a) { return $growgig; }
    $name = $a['brand_name'] ?: $a['name'];
    return [
        'key'      => 'account',
        'name'     => $name,
        'nav_name' => $name,
        'logo'     => $a['logo'] ?: 'assets/logo-aktifotak.png',
        'accent'   => 'text-indigo-600 dark:text-indigo-400',
    ];
}
```
- [ ] **Step 2:** `php -l`. Confirm header.php still works: the `$brand['key'] === 'growgig'` special-casing (Orbitron font, dark logo bg) now only fires for GrowGig; account brand uses the generic path. Logged-out curl → 302.
- [ ] **Step 3:** Commit.

---

### Task 5: Per-account logo upload in `accounts.php`
**Files:** Modify `accounts.php`; create dir `assets/account-logos/` with a `.gitkeep`; update `.gitignore` if avatars-style ignore is desired (optional).

- [ ] **Step 1:** Add `enctype="multipart/form-data"` to the account create/edit form and a file input `name="logo"` (accept png/jpeg/webp). In the `create_account`/`update_account` handlers, reuse the avatar-upload validation pattern from `profile.php` (validate via `getimagesize`, allowed mime→ext map, `move_uploaded_file` to `__DIR__.'/assets/account-logos/'` with a safe unique name), and set `accounts.logo` to `assets/account-logos/<fname>` when a valid file is uploaded (leave unchanged otherwise on update).
```php
$logoPath = null;
if (!empty($_FILES['logo']['name'])) {
    $f = $_FILES['logo'];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $info = ($f['error'] === UPLOAD_ERR_OK) ? @getimagesize($f['tmp_name']) : false;
    if ($info && isset($allowed[$info['mime']])) {
        $dir = __DIR__ . '/assets/account-logos';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $fname = 'acct_' . bin2hex(random_bytes(6)) . '.' . $allowed[$info['mime']];
        if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) { $logoPath = 'assets/account-logos/' . $fname; }
    }
}
```
On create: include `logo` in the INSERT when `$logoPath` set. On update: `UPDATE accounts SET ..., logo = COALESCE(?, logo) WHERE id = ?` binding `$logoPath` (null keeps existing). Show the current logo thumbnail in the edit form.
- [ ] **Step 2:** `php -l accounts.php`; logged-out curl → 302. (Upload flow verified in Task 6.)
- [ ] **Step 3:** Commit (include the `.gitkeep`).

---

### Task 6: Smoke test + isolation/branding verification
**Files:** none expected (fix regressions if found).

- [ ] **Step 1:** Reuse Phase 1's login-smoke harness (test1234 on uids 1/8/9). Smoke all pages for the 3 roles → no fatals, expected codes.
- [ ] **Step 2:** Billing check: as agency (uid1), create a temp "Demo Clinic" account, `set_sub` it to `frozen`; confirm account #1's subscription is unaffected; log in behaviour: an account_user of a frozen account has stock entry frozen while account #1's account_user does not. Confirm via `subscription_state(<id>)` assertions (logging in as a Demo account_user is optional).
- [ ] **Step 3:** Branding check: as agency, switch acting account to Demo Clinic → header brand shows Demo's name/logo; switch to "All accounts" → header shows GrowGig; account_admin (uid8) sees Aktifotak's brand.
- [ ] **Step 4:** Clean up all temp rows (Demo Clinic + its subscription/branches). Confirm baseline.
- [ ] **Step 5:** Commit any fixes; otherwise report "no code changes".

## Self-Review
- Spec coverage: P2 billing → Tasks 1–3; P3 branding → Tasks 4–5; verification → Task 6. ✓
- The `current_brand()` change reads the DB on every request for logged-in account context — acceptable (single indexed PK lookup); note it.
- Ambiguity resolved: agency acting on an account sees that account's brand (operator context); no full login-as-user impersonation (out of scope, YAGNI/security).
