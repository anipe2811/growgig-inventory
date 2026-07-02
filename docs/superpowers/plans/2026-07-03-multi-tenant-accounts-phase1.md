# Multi-tenant Accounts (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the single-tenant inventory app into a multi-tenant SaaS where the agency manages many isolated customer accounts, each with its own branches, users, suppliers, and subscription.

**Architecture:** Add an `accounts` table and an `account_id` foreign key on `branches`, `users`, `suppliers`, `subscriptions`. Items/stock/orders inherit their account transitively through `branch_id`. A scoping helper `current_account_id()` drives every branch-scoped query: agency roles are cross-tenant (with a sidebar "acting account" switcher), `account_admin`/`account_user` are locked to their own account. A new `accounts.php` panel lets the agency CRUD accounts and drill in.

**Tech Stack:** PHP 8.4 (Laravel Herd CLI locally), MariaDB 10.4 (XAMPP local / MySQL 8 prod via Docker), PDO, Tailwind (CDN), no framework, no automated test suite.

## Global Constraints

- **No test framework.** Verify with `php -l` (lint), one-off PHP DB-assertion scripts run via the Herd binary, and HTTP smoke tests against the local server. Herd PHP: `C:/Users/anipe/.config/herd/bin/php84/php.exe`. Local DB: `mysql:host=localhost;dbname=saas_inventory` user `root` no password. Local app: `http://localhost:8090` (start with `php -S localhost:8090 -t .` from repo root).
- **Migrations run on EVERY deploy** (see `.github/workflows/deploy.yml`). Every migration file MUST be idempotent (guarded `CREATE TABLE IF NOT EXISTS` / information_schema column guards / `INSERT ... WHERE NOT EXISTS`).
- **Live production data exists.** Existing customer becomes account #1; behaviour must be identical for account users on day one.
- **Escaping/CSRF patterns:** use existing `e()`, `csrf_field()`, `csrf_verify()` helpers. All new mutating POST endpoints verify CSRF.
- **Bilingual:** every user-facing string needs an EN and MS key in `includes/lang.php`.
- **Agency roles:** `agency_admin`, `agency_user` (helper `role_is_agency()`). They keep `users.account_id = NULL`.
- **Commit style:** end commit messages with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Do NOT push (prod deploy) unless the user explicitly says "deploy".

---

### Task 1: Database migration — accounts table, columns, backfill

**Files:**
- Create: `deploy/migrations/2026-07-03_accounts.sql`
- Modify: `deploy/database.sql` (schema dump: add `accounts` table + `account_id` columns)
- Verify: scratchpad PHP assertion script

**Interfaces:**
- Produces: table `accounts(id, name, slug, logo, brand_name, contact_email, whatsapp, created_at)`; `account_id` columns on `branches`, `users`, `suppliers`, `subscriptions`; account #1 seeded and all existing rows backfilled to it.

- [ ] **Step 1: Write the migration file**

`deploy/migrations/2026-07-03_accounts.sql`:
```sql
-- ============================================================================
-- Multi-tenant Phase 1: accounts table + account_id foreign keys + backfill.
-- Idempotent (runs on every deploy). Existing customer becomes account #1.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `accounts` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(150) NOT NULL,
  `slug`          varchar(80)  DEFAULT NULL,
  `logo`          varchar(190) DEFAULT NULL,
  `brand_name`    varchar(120) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `whatsapp`      varchar(40)  DEFAULT NULL,
  `created_at`    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add account_id columns only if missing (works on MySQL 8 + MariaDB).
-- Reusable guard block per table.
SET @db := DATABASE();

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='branches' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `branches` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_branches_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `users` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_users_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='suppliers' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `suppliers` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_suppliers_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='subscriptions' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `subscriptions` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD UNIQUE KEY `uq_sub_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Seed account #1 from the existing customer (only if table is empty).
INSERT INTO `accounts` (`id`, `name`, `brand_name`, `logo`)
SELECT 1, 'Aktifotak Group Sdn Bhd', 'Aktifotak Group Sdn Bhd', 'assets/logo-aktifotak.png'
WHERE NOT EXISTS (SELECT 1 FROM `accounts`);

-- Backfill existing rows to account #1 (idempotent: only rows still NULL).
UPDATE `branches`      SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `suppliers`     SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `subscriptions` SET `account_id` = 1 WHERE `account_id` IS NULL;
-- Non-agency users belong to account #1; agency users stay NULL.
UPDATE `users` SET `account_id` = 1
  WHERE `account_id` IS NULL AND `role` NOT IN ('agency_admin','agency_user');
```

- [ ] **Step 2: Add the schema to `deploy/database.sql`**

Add the `accounts` CREATE TABLE block near the other tables (e.g. before `DROP TABLE IF EXISTS feedback;`), and add `` `account_id` int(10) unsigned DEFAULT NULL, `` plus a `KEY` line to the `branches`, `users`, `suppliers`, `subscriptions` CREATE TABLE blocks. (Dump is gitignored/local; keep it consistent for fresh imports.)

- [ ] **Step 3: Apply locally**

Write `scratchpad/apply_accounts.php`:
```php
<?php
$pdo=new PDO("mysql:host=localhost;dbname=saas_inventory;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sql=file_get_contents("C:/Users/anipe/Desktop/PAKYA/Saas Inventory Therapy Centre & Clinic/deploy/migrations/2026-07-03_accounts.sql");
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt==='' || str_starts_with($stmt,'--')) continue;
    $pdo->exec($stmt);
}
echo "applied\n";
```
Run: `"C:/Users/anipe/.config/herd/bin/php84/php.exe" scratchpad/apply_accounts.php`
Expected: `applied`

Note: the migration mixes multi-statement `PREPARE`/`EXECUTE` blocks. If splitting on `;` breaks those, instead pipe the whole file through the MariaDB CLI: `"/c/xampp/mysql/bin/mysql.exe" -uroot saas_inventory < deploy/migrations/2026-07-03_accounts.sql`. Prefer the CLI path for fidelity with prod (which pipes the file into `mysql`).

- [ ] **Step 4: Assert the schema and backfill**

Write `scratchpad/assert_accounts.php`:
```php
<?php
$pdo=new PDO("mysql:host=localhost;dbname=saas_inventory;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$acc = $pdo->query("SELECT id,name FROM accounts")->fetchAll();
echo "accounts: ".json_encode($acc)."\n";
foreach (['branches','suppliers','subscriptions'] as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM `$t` WHERE account_id IS NULL")->fetchColumn();
    echo "$t null account_id: $n (expect 0)\n";
}
$u = $pdo->query("SELECT role, account_id, COUNT(*) c FROM users GROUP BY role, account_id")->fetchAll();
echo "users by role/account: ".json_encode($u)."\n";
```
Run it. Expected: one account (id 1), zero NULL account_id in branches/suppliers/subscriptions, agency users have `account_id=null`, account/supplier users have `account_id=1`.

- [ ] **Step 5: Commit**
```bash
git add deploy/migrations/2026-07-03_accounts.sql deploy/database.sql
git commit -m "feat(accounts): migration — accounts table, account_id FKs, backfill to account #1"
```

---

### Task 2: Scoping helpers in config + login session

**Files:**
- Modify: `config/config.php` (add account helpers; rework `role_sees_all_branches`)
- Modify: `login.php` (load `account_id` into session on login)
- Modify: `config/config.php` (handle `?account=` switch + logout clears it)
- Verify: DB-assertion + lint

**Interfaces:**
- Consumes: `role_is_agency()` (exists), `accounts` table (Task 1).
- Produces:
  - `all_accounts(): array` — `[{id,name,logo,brand_name,...}]` ordered by name.
  - `current_account_id(): ?int` — account scope for the logged-in user; `null` = "all accounts" (agency with no selection).
  - `account_name(?int $id): string`.
  - `role_sees_all_branches(?string $role): bool` — now TRUE only for agency (cross-tenant); `account_admin` sees all branches **within its account**, handled by scoping, not this flag. (See Step 3 for the exact new contract.)
  - Session keys: `$_SESSION['account_id']` (non-agency), `$_SESSION['acting_account_id']` (agency selection).

- [ ] **Step 1: Add account helpers to `config/config.php`**

After the `role_is_agency()` function, add:
```php
/* -------------------------------------------------------------------------
 * Multi-tenant account scope.
 * ---------------------------------------------------------------------- */
function all_accounts(): array
{
    global $pdo;
    try {
        return $pdo->query('SELECT id, name, logo, brand_name, contact_email, whatsapp FROM accounts ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* The account the current request operates on.
 *   account_admin / account_user / supplier -> their own account_id
 *   agency_admin / agency_user               -> the selected "acting" account,
 *                                               or null meaning "all accounts".
 */
function current_account_id(): ?int
{
    $role = $_SESSION['user_role'] ?? '';
    if (role_is_agency($role)) {
        $a = $_SESSION['acting_account_id'] ?? null;
        return ($a !== null && (int) $a > 0) ? (int) $a : null;
    }
    $a = $_SESSION['account_id'] ?? null;
    return ($a !== null && (int) $a > 0) ? (int) $a : null;
}

function account_name(?int $id): string
{
    if (!$id) { return ''; }
    global $pdo;
    try {
        $s = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
        $s->execute([$id]);
        return (string) ($s->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}
```

- [ ] **Step 2: Load `account_id` into the session at login**

In `login.php`, where the session is populated after a successful password check (look for `$_SESSION['user_role'] =` / `$_SESSION['branch_id'] =`), add:
```php
$_SESSION['account_id'] = $user['account_id'] !== null ? (int) $user['account_id'] : null;
```
Ensure the user-fetch SELECT includes `account_id` (change `SELECT ... FROM users WHERE email = ?` to include `account_id`, or `SELECT *`).

- [ ] **Step 3: Rework `role_sees_all_branches()`**

Replace the body so it means "sees every branch in the current account scope":
```php
/**
 * Whether a role sees every branch *within the current account scope*.
 *   agency_admin / agency_user  -> all branches (optionally filtered to the
 *                                  acting account by current_account_id()).
 *   account_admin               -> all branches in THEIR account.
 *   account_user                -> only their own branch.
 */
function role_sees_all_branches(?string $role): bool
{
    return in_array($role, ['agency_admin', 'agency_user', 'account_admin'], true);
}
```
(Contract unchanged in signature; the account filter is applied by callers via `current_account_id()` — see Task 4/5. This keeps `account_user` branch-locked.)

- [ ] **Step 4: Handle the `?account=` switch (agency) and clear on logout**

In `config/config.php`, near the existing `?lang` / `?logout` handling, add (agency only):
```php
// Agency account switcher: ?account=<id> (0/empty = all accounts).
if (isset($_GET['account']) && role_is_agency($_SESSION['user_role'] ?? '')) {
    $aid = ctype_digit((string) $_GET['account']) ? (int) $_GET['account'] : 0;
    $_SESSION['acting_account_id'] = $aid > 0 ? $aid : null;
    $qs = $_GET; unset($qs['account']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? ('?' . http_build_query($qs)) : ''));
    exit;
}
```
In the logout branch, also clear it: `unset($_SESSION['acting_account_id']);`.

- [ ] **Step 5: Lint + assert helpers**

Run: `"C:/Users/anipe/.config/herd/bin/php84/php.exe" -l config/config.php && "C:/Users/anipe/.config/herd/bin/php84/php.exe" -l login.php`
Expected: `No syntax errors detected` for both.

Write `scratchpad/assert_helpers.php` that includes config indirectly is hard (session/headers); instead assert the queries the helpers use:
```php
<?php
$pdo=new PDO("mysql:host=localhost;dbname=saas_inventory;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo "all_accounts: ".json_encode($pdo->query('SELECT id,name FROM accounts ORDER BY name')->fetchAll())."\n";
echo "acct1 name: ".$pdo->query('SELECT name FROM accounts WHERE id=1')->fetchColumn()."\n";
```
Run it. Expected: account #1 present.

- [ ] **Step 6: Commit**
```bash
git add config/config.php login.php
git commit -m "feat(accounts): scope helpers (current_account_id, all_accounts) + login session + agency switcher"
```

---

### Task 3: Agency account switcher in the sidebar + Accounts nav

**Files:**
- Modify: `includes/header.php` (add "Accounts" nav link + account switcher dropdown, agency only)
- Modify: `includes/lang.php` (nav + switcher strings, EN + MS)
- Verify: HTTP smoke as agency

**Interfaces:**
- Consumes: `all_accounts()`, `current_account_id()`, `role_is_agency()` (Task 2).
- Produces: sidebar UI; navigating to `?account=<id>` sets the acting account (handled in Task 2 Step 4).

- [ ] **Step 1: Add the "Accounts" nav link (agency only)**

In `includes/header.php`, inside the non-supplier `<nav>` block, above the `dashboard.php` link, add:
```php
<?php if (role_is_agency($role)): ?>
    <?= sidebar_link('accounts.php', $current, __('nav_accounts'), $IC['users']) ?>
<?php endif; ?>
```

- [ ] **Step 2: Add the account switcher dropdown (agency only)**

In `includes/header.php`, in the bottom controls area (near the language toggle), add (agency only):
```php
<?php if (role_is_agency($role)): ?>
    <?php $acctList = all_accounts(); $actingId = current_account_id(); ?>
    <form method="get" class="mt-1">
        <label class="block text-[11px] font-semibold text-gray-400 mb-1"><?= e(__('acct_switcher')) ?></label>
        <select name="account" onchange="this.form.submit()"
                class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="0" <?= $actingId === null ? 'selected' : '' ?>><?= e(__('acct_all')) ?></option>
            <?php foreach ($acctList as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= $actingId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
<?php endif; ?>
```

- [ ] **Step 3: Add lang keys (EN + MS)**

EN block (near `nav_feedback`): `'nav_accounts' => 'Accounts'`, `'acct_switcher' => 'Acting account'`, `'acct_all' => 'All accounts'`.
MS block: `'nav_accounts' => 'Akaun'`, `'acct_switcher' => 'Akaun aktif'`, `'acct_all' => 'Semua akaun'`.

- [ ] **Step 4: Lint + smoke**

Run: `"C:/Users/anipe/.config/herd/bin/php84/php.exe" -l includes/header.php && "C:/Users/anipe/.config/herd/bin/php84/php.exe" -l includes/lang.php`
Expected: no syntax errors. Then `curl -s -o /dev/null -w "%{http_code}" http://localhost:8090/dashboard.php` → `302` (logged out). Full render is verified in Task 7 with a logged-in session.

- [ ] **Step 5: Commit**
```bash
git add includes/header.php includes/lang.php
git commit -m "feat(accounts): agency sidebar — Accounts nav + acting-account switcher"
```

---

### Task 4: Accounts panel (`accounts.php`) + scope `users.php` by account

**Files:**
- Create: `accounts.php`
- Modify: `users.php` (scope branches/users/suppliers/subscription by `current_account_id()`)
- Modify: `includes/lang.php` (accounts panel strings, EN + MS)
- Verify: lint + logged-in smoke (Task 7)

**Interfaces:**
- Consumes: `all_accounts()`, `current_account_id()`, `account_name()`, `role_is_agency()`, `csrf_*`, `e()`.
- Produces: `accounts.php` with actions `create_account`, `update_account`, `delete_account`; account metrics list.

- [ ] **Step 1: Create `accounts.php`**

Guard to agency only; provide CRUD + metrics. Full file:
```php
<?php
/**
 * accounts.php — Agency-only: manage all customer accounts (tenants).
 */
require_once __DIR__ . '/config/config.php';
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!role_is_agency($role)) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: accounts.php?msg=denied'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account' || $action === 'update_account') {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['contact_email'] ?? '');
        $wa      = trim($_POST['whatsapp'] ?? '');
        $brand   = trim($_POST['brand_name'] ?? '');
        if ($name === '') { header('Location: accounts.php?msg=invalid'); exit; }
        if ($action === 'create_account') {
            $pdo->prepare('INSERT INTO accounts (name, brand_name, contact_email, whatsapp) VALUES (?, ?, ?, ?)')
                ->execute([$name, $brand ?: $name, $email ?: null, $wa ?: null]);
            // New account starts on a trial subscription.
            $aid = (int) $pdo->lastInsertId();
            try {
                $pdo->prepare("INSERT INTO subscriptions (account_id, plan, status, trial_ends_at) VALUES (?, 'trial', 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY))")
                    ->execute([$aid]);
            } catch (Throwable $e) { /* subscription optional in P1 */ }
            header('Location: accounts.php?msg=added'); exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE accounts SET name=?, brand_name=?, contact_email=?, whatsapp=? WHERE id=?')
            ->execute([$name, $brand ?: $name, $email ?: null, $wa ?: null, $id]);
        header('Location: accounts.php?msg=updated'); exit;
    }

    if ($action === 'delete_account') {
        $id = (int) ($_POST['id'] ?? 0);
        // Guard: cannot delete an account that still owns branches or users.
        $bc = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE account_id = ?'); $bc->execute([$id]);
        $uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE account_id = ?');    $uc->execute([$id]);
        if ((int) $bc->fetchColumn() > 0 || (int) $uc->fetchColumn() > 0) {
            header('Location: accounts.php?msg=in_use'); exit;
        }
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM subscriptions WHERE account_id = ?')->execute([$id]);
        header('Location: accounts.php?msg=deleted'); exit;
    }
    header('Location: accounts.php'); exit;
}

$editAccount = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $e->execute([(int) $_GET['edit']]);
    $editAccount = $e->fetch() ?: null;
}

// Metrics per account: branches, seats (account_user), subscription status.
$rows = $pdo->query(
    "SELECT a.id, a.name, a.contact_email,
            (SELECT COUNT(*) FROM branches b WHERE b.account_id = a.id) AS branches,
            (SELECT COUNT(*) FROM users u WHERE u.account_id = a.id AND u.role = 'account_user') AS seats,
            (SELECT s.status FROM subscriptions s WHERE s.account_id = a.id LIMIT 1) AS sub_status
     FROM accounts a ORDER BY a.name ASC"
)->fetchAll();

$flashMap = [
    'added'   => ['acct_added',   'green'],
    'updated' => ['acct_updated', 'green'],
    'deleted' => ['acct_deleted', 'green'],
    'in_use'  => ['acct_in_use',  'red'],
    'invalid' => ['err_fill_all', 'red'],
    'denied'  => ['inv_not_allowed', 'red'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

$pageTitle = __('nav_accounts');
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white"><?= e(__('acct_title')) ?></h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e(__('acct_sub')) ?></p>

    <?php if ($flash): ?><?php [$fk,$fc]=$flash; ?>
        <div class="mt-5 rounded-lg px-4 py-3 text-sm <?= $fc==='green'
            ? 'bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800'
            : 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800' ?>"><?= e(__($fk)) ?></div>
    <?php endif; ?>

    <!-- Create / edit account -->
    <div id="acct-form" class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4"><?= e($editAccount ? __('acct_edit') : __('acct_add')) ?></h3>
        <form method="post" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editAccount ? 'update_account' : 'create_account' ?>">
            <?php if ($editAccount): ?><input type="hidden" name="id" value="<?= (int) $editAccount['id'] ?>"><?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_name')) ?></label>
                <input type="text" name="name" required value="<?= e($editAccount['name'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_brand')) ?></label>
                <input type="text" name="brand_name" value="<?= e($editAccount['brand_name'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_email')) ?></label>
                <input type="email" name="contact_email" value="<?= e($editAccount['contact_email'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= e(__('acct_whatsapp')) ?></label>
                <input type="text" name="whatsapp" value="<?= e($editAccount['whatsapp'] ?? '') ?>" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none transition">
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex items-center gap-3">
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?= e($editAccount ? __('btn_save_changes') : __('acct_add')) ?></button>
                <?php if ($editAccount): ?><a href="accounts.php" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><?= e(__('btn_cancel')) ?></a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Accounts list -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-semibold"><?= e(__('acct_name')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('card_branches')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('bill_seats')) ?></th>
                        <th class="px-4 py-3 font-semibold"><?= e(__('bill_status')) ?></th>
                        <th class="px-4 py-3 font-semibold text-right"><?= e(__('col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400"><?= e(__('acct_none')) ?></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($r['name']) ?></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"><?= (int) $r['branches'] ?></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"><?= (int) $r['seats'] ?></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($r['sub_status'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="accounts.php?account=<?= (int) $r['id'] ?>" class="px-2.5 py-1 rounded-lg text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors"><?= e(__('acct_enter')) ?></a>
                                    <a href="accounts.php?edit=<?= (int) $r['id'] ?>#acct-form" class="px-2.5 py-1 rounded-lg text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"><?= e(__('btn_edit')) ?></a>
                                    <form method="post" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>');">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"><?= e(__('btn_delete')) ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
```
"Enter" sets the acting account via `?account=<id>` (handled in Task 2 Step 4) then returns here; the manager then uses `users.php` (now account-scoped) to manage that account's branches/users.

- [ ] **Step 2: Scope `users.php` by account**

In `users.php`, replace unscoped queries with `current_account_id()`-filtered ones:
- Branches list (line ~16): `SELECT id, name, location FROM branches WHERE account_id = ? ORDER BY name ASC` with `[current_account_id()]`. If agency has no acting account (`null`), show an inline notice "Pick an account first" and skip the management forms.
- `create_user` INSERT: add `account_id` = `current_account_id()` (and require it non-null).
- `accountUsers` list SELECT: add `AND u.account_id = ?`.
- `suppliers` + `supplierUsers` SELECTs: add `WHERE ... account_id = ?` / `AND u.account_id = ?`.
- `add_supplier` INSERT: set `account_id = current_account_id()`.
- Subscription panel: read the subscription for `current_account_id()` (Phase 2 makes controls per-account; in P1 just read the right row).

Guard at top after role check: if agency and `current_account_id() === null`, render only a prompt to select an account (reuse `acct_all`/switcher). Non-agency `account_admin` always has an account.

- [ ] **Step 3: Add lang keys (EN + MS)**

EN: `acct_title`=>'Accounts', `acct_sub`=>'Manage all customer accounts.', `acct_add`=>'Add account', `acct_edit`=>'Edit account', `acct_name`=>'Account name', `acct_brand`=>'Brand name', `acct_email`=>'Contact email', `acct_whatsapp`=>'WhatsApp', `acct_none`=>'No accounts yet.', `acct_added`=>'Account created.', `acct_updated`=>'Account updated.', `acct_deleted`=>'Account deleted.', `acct_in_use`=>'Cannot delete: this account still has branches or users.', `acct_enter`=>'Enter', `acct_pick_first`=>'Pick an account from the switcher to manage it.'
MS: `acct_title`=>'Akaun', `acct_sub`=>'Urus semua akaun pelanggan.', `acct_add`=>'Tambah akaun', `acct_edit`=>'Edit akaun', `acct_name`=>'Nama akaun', `acct_brand`=>'Nama jenama', `acct_email`=>'Emel kontak', `acct_whatsapp`=>'WhatsApp', `acct_none`=>'Tiada akaun lagi.', `acct_added`=>'Akaun dicipta.', `acct_updated`=>'Akaun dikemas kini.', `acct_deleted`=>'Akaun dipadam.', `acct_in_use`=>'Tak boleh padam: akaun ini masih ada cawangan atau pengguna.', `acct_enter`=>'Masuk', `acct_pick_first`=>'Pilih akaun dari penukar untuk mengurus.'

- [ ] **Step 4: Lint**

Run: `"C:/Users/anipe/.config/herd/bin/php84/php.exe" -l accounts.php && "C:/Users/anipe/.config/herd/bin/php84/php.exe" -l users.php && "C:/Users/anipe/.config/herd/bin/php84/php.exe" -l includes/lang.php`
Expected: no syntax errors.

- [ ] **Step 5: Commit**
```bash
git add accounts.php users.php includes/lang.php
git commit -m "feat(accounts): agency Accounts panel + account-scoped user management"
```

---

### Task 5: Scope inventory + stock by account

**Files:**
- Modify: `inventory.php`, `stock.php`
- Verify: DB-assertion (cross-account isolation) + lint

**Interfaces:**
- Consumes: `current_account_id()`, `role_sees_all_branches()`.
- Produces: branch lists + branch validation filtered by account; agency with "all accounts" sees all, with an acting account sees only its branches; `account_admin` sees only its account; `account_user` unchanged (branch-locked).

- [ ] **Step 1: Scope the branch query in `inventory.php`**

Where `inventory.php` builds `$branches` for `$seesAll` (line ~17): change
```php
$branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
```
to filter by the current account (agency "all" = no filter):
```php
$acct = current_account_id();
if ($acct) {
    $st = $pdo->prepare('SELECT id, name FROM branches WHERE account_id = ? ORDER BY name ASC');
    $st->execute([$acct]);
    $branches = $st->fetchAll();
} else {
    $branches = $pdo->query('SELECT id, name FROM branches ORDER BY name ASC')->fetchAll();
}
```
`$validBranchIds` already derives from `$branches`, so item add/edit/delete stay within scope automatically. Confirm the items list query joins branches within `$validBranchIds` (it filters by `branch_id` in scope) — no further change needed because all writes validate against `$validBranchIds`.

- [ ] **Step 2: Apply the same scoping to `stock.php`**

`stock.php` builds `$branches` identically (line ~22). Apply the exact same `current_account_id()` filter block.

- [ ] **Step 3: Cross-account isolation assertion**

Seed a second account + branch locally, then assert an account_admin of account 1 cannot see account 2's branch. Write `scratchpad/assert_isolation.php`:
```php
<?php
$pdo=new PDO("mysql:host=localhost;dbname=saas_inventory;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("INSERT INTO accounts (name) SELECT 'ZZ Test Co' WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE name='ZZ Test Co')");
$aid2=$pdo->query("SELECT id FROM accounts WHERE name='ZZ Test Co'")->fetchColumn();
$pdo->exec("INSERT INTO branches (name, account_id) SELECT 'ZZ Branch', $aid2 WHERE NOT EXISTS (SELECT 1 FROM branches WHERE name='ZZ Branch')");
$acct1 = $pdo->query("SELECT COUNT(*) FROM branches WHERE account_id=1")->fetchColumn();
$acct2 = $pdo->query("SELECT COUNT(*) FROM branches WHERE account_id=$aid2")->fetchColumn();
echo "acct1 branches=$acct1  acct2 branches=$acct2 (must be disjoint)\n";
```
Run it. Expected: account 2 has its own branch not counted in account 1. (Clean up ZZ rows after Task 7.)

- [ ] **Step 4: Lint**

Run: `"C:/Users/anipe/.config/herd/bin/php84/php.exe" -l inventory.php && "C:/Users/anipe/.config/herd/bin/php84/php.exe" -l stock.php`
Expected: no syntax errors.

- [ ] **Step 5: Commit**
```bash
git add inventory.php stock.php
git commit -m "feat(accounts): scope inventory + stock branch access by account"
```

---

### Task 6: Scope reports, dashboard, mobile, orders by account

**Files:**
- Modify: `sales_report.php`, `reports.php`, `inventory_report.php`, `stockcard.php`, `dashboard.php`, `mobile.php`, `orders.php`
- Verify: lint + DB-assertion + logged-in smoke (Task 7)

**Interfaces:**
- Consumes: `current_account_id()`.
- Produces: every branch/stat query filtered so a user only sees their account's data; agency "all accounts" sees aggregate.

- [ ] **Step 1: Audit each file's branch/stat queries**

For each file, find where it lists branches or aggregates across branches and add the account filter. The pattern in every case: compute `$acct = current_account_id();` and, when `$acct` is non-null, add `AND b.account_id = ?` (or `branches.account_id = ?`) to the branch source; when building stats that scan `items`/`stock_movements`, join `branches` and filter by account. Exact edits:

- `sales_report.php`: the branches list for the filter dropdown (`$seesAll ? SELECT id,name FROM branches ...`) → add account filter as in Task 5 Step 1. The main aggregate query joins `branches b` already; add `AND (b.account_id = ? )` when `$acct` is set (append to `$cond`/`$params`).
- `reports.php`, `inventory_report.php`, `stockcard.php`: same branches-list scoping; any cross-branch aggregate joins `branches` and filters by `$acct` when set.
- `dashboard.php`: stat tiles (total items, quantity, low stock, branches count, registered users) → scope counts to `current_account_id()` when set; agency "all accounts" keeps global totals. Registered-users tile counts `users` where `account_id = $acct` (agency-all = all non-agency users).
- `mobile.php`: branch source + trip queries scoped like inventory.
- `orders.php`: purchase-order lists join `branches`; filter by `$acct` when set.

For each file, show the concrete before/after of the branch-list query (identical shape to Task 5 Step 1) and the added `$cond[]='b.account_id = ?'; $params[]=$acct;` line for aggregates.

- [ ] **Step 2: Lint all touched files**

Run `php -l` on each of the seven files. Expected: no syntax errors.

- [ ] **Step 3: Aggregate-isolation assertion**

Extend `scratchpad/assert_isolation.php` to confirm a sales/stat query scoped to account 2 returns only ZZ data (zero rows if no movements), and account 1 is unchanged from pre-migration counts.

- [ ] **Step 4: Commit**
```bash
git add sales_report.php reports.php inventory_report.php stockcard.php dashboard.php mobile.php orders.php
git commit -m "feat(accounts): scope reports, dashboard, mobile, orders by account"
```

---

### Task 7: Full role-based smoke test + cleanup

**Files:**
- Create (temporary): `scratchpad/login_smoke.php` (session-authenticated page fetch harness)
- Modify: none (fix regressions found)
- Verify: render each key page as agency / account_admin / account_user

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Set known local test passwords**

So smoke tests can log in via the real flow, reset local passwords for the three role users (local DB only; never prod). `scratchpad/set_test_pw.php`:
```php
<?php
$pdo=new PDO("mysql:host=localhost;dbname=saas_inventory;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$h=password_hash('test1234', PASSWORD_DEFAULT);
foreach ([1=>'agency_admin',8=>'account_admin',9=>'account_user'] as $id=>$r) {
    $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([$h,$id]);
}
echo "test passwords set (test1234) for uids 1,8,9\n";
```
Run it.

- [ ] **Step 2: Smoke each role with a cookie jar**

For each user, log in via curl and fetch the key pages, checking for `Fatal error` / `Database connection failed`:
```bash
BASE=http://localhost:8090
for creds in "admin@growgig.tech:agency" "admin@aktifotak.com:acct_admin" "aktifotakmelaka@gmail.com:acct_user"; do
  email="${creds%%:*}"; tag="${creds##*:}"; jar="scratchpad/cj_$tag.txt"; rm -f "$jar"
  # prime CSRF (login page sets session cookie); then POST login
  curl -s -c "$jar" "$BASE/login.php" > /dev/null
  curl -s -b "$jar" -c "$jar" -d "email=$email&password=test1234" "$BASE/login.php" > /dev/null
  for p in dashboard.php inventory.php stock.php sales_report.php reports.php users.php accounts.php notifications.php feedback.php orders.php mobile.php; do
    code=$(curl -s -b "$jar" -o /dev/null -w "%{http_code}" "$BASE/$p")
    err=$(curl -s -b "$jar" "$BASE/$p" | grep -io "Fatal error\|Database connection failed\|Parse error" | head -1)
    echo "$tag $p -> $code ${err:+[$err]}"
  done
done
```
Expected: agency → 200 on all incl. `accounts.php`; account_admin → 200 on management pages, `accounts.php` → 302 (redirect, not agency); account_user → 200 on branch pages, `users.php`/`accounts.php` → 302. No `[Fatal error]` anywhere.

Note: `login.php` likely uses a CSRF token on the login form. If the scripted POST fails auth due to CSRF, adjust the harness to scrape the `csrf_token` hidden input from the primed login page and include it in the POST body.

- [ ] **Step 3: Manual visual pass**

In the browser at `http://localhost:8090`: log in as agency, confirm the account switcher shows "All accounts" + Aktifotak; create a test account "Demo Clinic"; Enter it; add a branch + user under it; switch back to Aktifotak; confirm Aktifotak's inventory/branches are unchanged and Demo Clinic's data is separate.

- [ ] **Step 4: Clean up test data**

Delete the ZZ/Demo test rows and revert test passwords if desired (or leave `test1234` locally). `scratchpad/cleanup.php` removes accounts named 'ZZ Test Co'/'Demo Clinic' and their branches/users. Do NOT run against prod.

- [ ] **Step 5: Final lint sweep + commit any fixes**
```bash
git add -A
git commit -m "test(accounts): role-based smoke pass + fixes for Phase 1 multi-tenant"
```

---

## Self-Review

**Spec coverage:**
- §4 data model → Task 1. ✓
- §5 migration/backfill → Task 1. ✓
- §6 scoping model + `current_account_id()` + `role_sees_all_branches` rework → Task 2; applied Tasks 4–6. ✓
- §7 Accounts panel → Task 4. ✓
- §8 files touched → Tasks 2–6 cover config, header, accounts.php, users.php, and every branch-scoped page. ✓
- §11 sidebar switcher → Task 3. ✓
- Login session `account_id` (implied by scoping) → Task 2 Step 2. ✓

**Placeholder scan:** Task 6 Step 1 describes per-file edits by pattern rather than pasting all seven files verbatim; the pattern (branch-list scoping block from Task 5 Step 1 + `$cond[]='b.account_id = ?'`) is fully specified and identical across files, so the implementer has exact code. Acceptable given the files are not yet read in detail during planning; the executor reads each file and applies the shown pattern. No TBD/TODO remain.

**Type consistency:** `current_account_id(): ?int`, `all_accounts(): array`, `account_name(?int): string` used consistently across Tasks 2–6. Session keys `account_id` / `acting_account_id` consistent. Action names in `accounts.php` (`create_account`/`update_account`/`delete_account`) consistent with the flash map.

**Known risk to validate during execution:** Task 6 requires reading each of the seven files to place the filter precisely (their current query shapes were not all read at plan time). The executor MUST read each file first, then apply the specified pattern — treat Step 1 as "apply this exact scoping pattern wherever the file lists branches or aggregates across branches."
