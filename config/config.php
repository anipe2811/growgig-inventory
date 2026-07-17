<?php
/**
 * config/config.php
 * -----------------------------------------------------------------------------
 * Core configuration for: SaaS Inventory — Pusat Terapi Kanak-Kanak dan Klinik
 *
 * Responsibilities:
 *   - Start (or resume) the PHP session
 *   - Initialise a safe PDO MySQL connection
 *   - Initialise the dual-language (EN / MS) system
 *   - Provide auth, CSRF and output-escaping helpers
 *   - Handle the global ?lang= and ?logout actions
 *
 * The SQL DDL needed to create the database & tables is documented at the
 * BOTTOM of this file. Copy it into phpMyAdmin / the MySQL CLI before running.
 * -----------------------------------------------------------------------------
 */

/* -------------------------------------------------------------------------
 * 0. Environment & error reporting
 * ---------------------------------------------------------------------- */
// Debug is ON locally; set env APP_DEBUG=false in production (Hostinger/VPS).
define('APP_DEBUG', (getenv('APP_DEBUG') ?: 'true') !== 'false');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    // Production: never show errors to users, but always log them (they land on
    // the container's stderr, i.e. `docker logs`), so a 500 is never silent.
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/* -------------------------------------------------------------------------
 * 1. Session
 * ---------------------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -------------------------------------------------------------------------
 * 2. Application & database constants
 * ---------------------------------------------------------------------- */
define('APP_NAME', 'Aktifotak');

// Credentials come from the environment in production (Docker/VPS); the values
// after ?: are the local development defaults (Laravel Herd / XAMPP MySQL).
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'saas_inventory');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/* -------------------------------------------------------------------------
 * 3. PDO connection (guarded singleton)
 * ---------------------------------------------------------------------- */
if (!isset($pdo)) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        if (APP_DEBUG) {
            die('Database connection failed: ' . $e->getMessage());
        }
        die('Database connection failed. Please contact the administrator.');
    }
}

/* -------------------------------------------------------------------------
 * 4. Language initialisation (English is the default)
 * ---------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/lang.php';

// Handle the EN | MY toggle, then redirect to strip the ?lang query param.
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = ($_GET['lang'] === 'ms') ? 'ms' : 'en';
    $params = $_GET;
    unset($params['lang']);
    $qs  = http_build_query($params);
    $url = strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : '');
    header('Location: ' . $url);
    exit;
}
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

// Agency account switcher: ?account=<id> (0/empty = all accounts).
if (isset($_GET['account']) && role_is_agency($_SESSION['user_role'] ?? '')) {
    $aid = ctype_digit((string) $_GET['account']) ? (int) $_GET['account'] : 0;
    $_SESSION['acting_account_id'] = $aid > 0 ? $aid : null;
    $qs = $_GET; unset($qs['account']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? ('?' . http_build_query($qs)) : ''));
    exit;
}

/* -------------------------------------------------------------------------
 * 5. Logout handler (?logout=1)
 * ---------------------------------------------------------------------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    unset($_SESSION['acting_account_id']);
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

/* -------------------------------------------------------------------------
 * 5b. Stop impersonation (?stop_impersonate=1): restore the original agency identity.
 * ---------------------------------------------------------------------- */
if (isset($_GET['stop_impersonate']) && !empty($_SESSION['impersonator_id'])) {
    global $pdo;
    log_impersonation('stop', (int) $_SESSION['impersonator_id'], (string) ($_SESSION['impersonator_name'] ?? ''), (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user_name'] ?? ''), isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null);
    $impId = (int) $_SESSION['impersonator_id'];
    try {
        $st = $pdo->prepare('SELECT id, name, role, branch_id, account_id FROM users WHERE id = ?');
        $st->execute([$impId]);
        $u = $st->fetch();
    } catch (Throwable $e) { $u = null; }
    if ($u) {
        $_SESSION['user_id']    = (int) $u['id'];
        $_SESSION['user_name']  = $u['name'];
        $_SESSION['user_role']  = $u['role'];
        $_SESSION['branch_id']  = $u['branch_id'] !== null ? (int) $u['branch_id'] : null;
        $_SESSION['account_id'] = $u['account_id'] !== null ? (int) $u['account_id'] : null;
        unset($_SESSION['impersonator_id'], $_SESSION['impersonator_name'], $_SESSION['acting_account_id']);
        session_regenerate_id(true);   // Fix M-1: new session id on privilege change
        header('Location: dashboard.php');
        exit;
    }
    // Impersonator user is gone: fail closed — never leave a headless account-user session.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------------------------
 * 6. Helpers — output escaping
 * ---------------------------------------------------------------------- */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/* -------------------------------------------------------------------------
 * 7. Helpers — authentication & access control
 * ---------------------------------------------------------------------- */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/* Whether the current session is an agency_admin impersonating an account user. */
function is_impersonating(): bool
{
    return !empty($_SESSION['impersonator_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function current_user(): ?array
{
    global $pdo;
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email, whatsapp, avatar, role, branch_id, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function current_role(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/* -------------------------------------------------------------------------
 * 7b. Role helpers — GoHighLevel-style role hierarchy.
 *   agency_admin  : full control of the whole system (the SaaS provider / super admin)
 *   agency_user   : agency staff — broad access, but no user / agency management
 *   account_admin : manages their own account (the subscribing client's admin)
 *   account_user  : restricted, read-only access (the client's staff member)
 * ---------------------------------------------------------------------- */
const ROLES = ['agency_admin', 'agency_user', 'account_admin', 'account_user'];

function role_is_super(?string $role): bool
{
    return $role === 'agency_admin';
}

function role_is_agency(?string $role): bool
{
    return in_array($role, ['agency_admin', 'agency_user'], true);
}

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

/* True when an agency user has no company selected and must pick one before
 * stock-data pages will show anything. account roles always have an account. */
function agency_needs_account(): bool
{
    return role_is_agency($_SESSION['user_role'] ?? '') && current_account_id() === null;
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

function role_can_manage_inventory(?string $role): bool
{
    // All roles can manage inventory. account_user is the admin of ONE branch
    // (scoped via role_sees_all_branches); the others manage every branch.
    return in_array($role, ROLES, true);
}

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

/* -------------------------------------------------------------------------
 * 7c. Branding by context.
 *   Logged out / agency "all accounts"      -> GrowGig brand (the SaaS provider).
 *   Account user (their own account)        -> that account's brand.
 *   Agency user acting on an account        -> that account's brand (so they
 *                                              know which tenant they're on).
 * ---------------------------------------------------------------------- */
function current_brand(): array
{
    $growgig = ['key'=>'growgig','name'=>'GrowGig','nav_name'=>'GrowGig','logo'=>'assets/logo-growgig.png','accent'=>'text-blue-600 dark:text-blue-400','email'=>'hello@growgig.tech'];
    if (!is_logged_in()) { return $growgig; }
    $acctId = current_account_id();
    if (!$acctId) { return $growgig; } // agency "all accounts" (or no context)
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT name, brand_name, logo, contact_email FROM accounts WHERE id = ?');
        $st->execute([$acctId]);
        $a = $st->fetch();
    } catch (Throwable $e) { $a = null; }
    if (!$a) { return $growgig; }
    $name = ($a['brand_name'] ?: $a['name']) ?: 'GrowGig';
    return [
        'key'      => 'account',
        'name'     => $name,
        'nav_name' => $name,
        'logo'     => $a['logo'] ?: 'assets/logo-aktifotak.png',
        'accent'   => 'text-indigo-600 dark:text-indigo-400',
        'email'    => ($a['contact_email'] ?: 'hello@growgig.tech'),
    ];
}

/* -------------------------------------------------------------------------
 * 7d. User management + subscription (free trial / billing).
 * ---------------------------------------------------------------------- */
// Only these roles may create/manage account users (billed per account user).
function role_can_manage_users(?string $role): bool
{
    return in_array($role, ['agency_admin', 'agency_user', 'account_admin'], true);
}

// Only admins (account admin + agency) may place/approve orders & manage suppliers.
function role_can_order(?string $role): bool
{
    return in_array($role, ['agency_admin', 'agency_user', 'account_admin'], true);
}

// Branch users (account_user) may REQUEST an order; an admin must approve it.
// They can also verify receipt of their own branch's orders.
function role_can_request_order(?string $role): bool
{
    return $role === 'account_user';
}

// Anyone who can place OR request orders may open the Orders page.
function role_can_use_orders(?string $role): bool
{
    return role_can_order($role) || role_can_request_order($role);
}

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
        // agency "all accounts" / no context: earliest row (display only).
        $r = $pdo->query('SELECT * FROM subscriptions ORDER BY id LIMIT 1')->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Returns ['status'=>trial|active|frozen, 'days_left'=>int, 'frozen'=>bool, 'price'=>float, 'trial_ends_at'=>?string].
 * Trial that has passed its end date counts as frozen.
 */
function subscription_state(?int $accountId = null): array
{
    $s = get_subscription($accountId);
    if (!$s) {
        return ['status' => 'active', 'days_left' => 0, 'frozen' => false, 'price' => 29.90, 'trial_ends_at' => null];
    }
    $price = (float) ($s['price_per_user'] ?? 29.90);
    if ($s['status'] === 'active') {
        return ['status' => 'active', 'days_left' => 0, 'frozen' => false, 'price' => $price, 'trial_ends_at' => $s['trial_ends_at']];
    }
    if ($s['status'] === 'frozen') {
        return ['status' => 'frozen', 'days_left' => 0, 'frozen' => true, 'price' => $price, 'trial_ends_at' => $s['trial_ends_at']];
    }
    // trial: frozen once the end date has passed (end of that day)
    $end  = strtotime($s['trial_ends_at'] . ' 23:59:59');
    $secs = $end - time();
    return [
        'status'        => 'trial',
        'days_left'     => $secs > 0 ? (int) ceil($secs / 86400) : 0,
        'frozen'        => $secs <= 0,
        'price'         => $price,
        'trial_ends_at' => $s['trial_ends_at'],
    ];
}

// Stock In/Out is frozen for customer (account) roles once trial ends / subscription frozen.
function stock_frozen_for(?string $role): bool
{
    if (role_is_agency($role)) {
        return false; // the provider is never frozen
    }
    return subscription_state()['frozen'];
}

/* -------------------------------------------------------------------------
 * 8. Helpers — CSRF protection
 * ---------------------------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/* -------------------------------------------------------------------------
 * 9. Supplier role + in-app notifications
 * ---------------------------------------------------------------------- */
function role_is_supplier(?string $role): bool
{
    return $role === 'supplier';
}

/* Insert a single notification (best-effort; never fatal). type: system|software|order */
function notify_user(int $userId, string $type, string $title, string $body = '', string $link = ''): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $type, $title, $body, $link]);
    } catch (Throwable $e) { /* notifications are non-critical */ }
}

/* Notify every user holding one of $roles. Returns the number notified. */
function notify_roles(array $roles, string $type, string $title, string $body = '', string $link = ''): int
{
    global $pdo;
    if (!$roles) { return 0; }
    try {
        $in   = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($in)");
        $stmt->execute(array_values($roles));
        $n = 0;
        foreach ($stmt->fetchAll() as $r) { notify_user((int) $r['id'], $type, $title, $body, $link); $n++; }
        return $n;
    } catch (Throwable $e) { return 0; }
}

/* Notify the supplier login(s) linked to a supplier record. */
function notify_supplier_users(int $supplierId, string $title, string $body = '', string $link = 'supplier.php'): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'supplier' AND supplier_id = ?");
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll() as $r) { notify_user((int) $r['id'], 'order', $title, $body, $link); }
    } catch (Throwable $e) { /* best-effort */ }
}

/* Record an impersonation event (best-effort; never fatal). action: start|stop */
function log_impersonation(string $action, int $impersonatorId, string $impersonatorName, int $targetId, string $targetName, ?int $accountId): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO impersonation_log (impersonator_id, impersonator_name, target_id, target_name, account_id, action) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$impersonatorId, $impersonatorName, $targetId, $targetName, $accountId, $action]);
    } catch (Throwable $e) { /* audit is best-effort */ }
}

/* Notification types the agency team sees. Agency is the SaaS operator, so it
 * only cares about system-management notices (system / software / feedback), not
 * per-branch operational noise like stock-order updates. Returns a SQL fragment
 * (leading " AND ...") to append to a notifications query, or '' for non-agency. */
function notif_type_filter_sql(?string $role): string
{
    return role_is_agency($role)
        ? " AND type IN ('system', 'software', 'feedback')"
        : '';
}

function unread_notification_count(): int
{
    global $pdo;
    if (!is_logged_in()) { return 0; }
    try {
        $filter = notif_type_filter_sql($_SESSION['user_role'] ?? '');
        $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0' . $filter);
        $s->execute([$_SESSION['user_id']]);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* Unread user-feedback reports for the current user (agency team receives these).
 * Powers the badge on the "Have a problem?" sidebar link. */
function unread_feedback_count(): int
{
    global $pdo;
    if (!is_logged_in()) { return 0; }
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND type = 'feedback'");
        $s->execute([$_SESSION['user_id']]);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* Supplier logins are confined to their own portal (+ their notifications/account). */
if (is_logged_in() && current_role() === 'supplier') {
    $allowedSupplierPages = ['supplier.php', 'notifications.php', 'profile.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $allowedSupplierPages, true)) {
        header('Location: supplier.php');
        exit;
    }
}

/* =============================================================================
 * SQL DDL — run this once in phpMyAdmin or the MySQL CLI before first use.
 * =============================================================================
 *
 * -- 1. Create the database (skip if it already exists on shared hosting)
 * CREATE DATABASE IF NOT EXISTS `saas_inventory`
 *   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE `saas_inventory`;
 *
 * -- 2. Users table
 * CREATE TABLE IF NOT EXISTS `users` (
 *   `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `name`       VARCHAR(120) NOT NULL,
 *   `email`      VARCHAR(190) NOT NULL,
 *   `password`   VARCHAR(255) NOT NULL,
 *   `whatsapp`   VARCHAR(30)  NOT NULL,
 *   -- GoHighLevel-style roles. New sign-ups default to the lowest role.
 *   `role`       ENUM('agency_admin','agency_user','account_admin','account_user') NOT NULL DEFAULT 'account_user',
 *   `branch_id`  INT UNSIGNED DEFAULT NULL,  -- the cawangan an account_user belongs to (NULL = sees all)
 *   `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`),
 *   UNIQUE KEY `uq_users_email` (`email`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 3. Branches (cawangan) — each clinic location
 * CREATE TABLE IF NOT EXISTS `branches` (
 *   `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `name`       VARCHAR(120) NOT NULL,
 *   `location`   VARCHAR(190) DEFAULT NULL,
 *   `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 4. Inventory items table (starter module)
 * CREATE TABLE IF NOT EXISTS `items` (
 *   `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `branch_id`     INT UNSIGNED DEFAULT NULL,   -- which cawangan owns this item
 *   `sort_order`    INT          NOT NULL DEFAULT 0,  -- custom display order within a branch
 *   `name`          VARCHAR(150) NOT NULL,
 *   `sku`           VARCHAR(60)  DEFAULT NULL,
 *   `category`      VARCHAR(80)  DEFAULT NULL,
 *   `quantity`      INT          NOT NULL DEFAULT 0,
 *   `unit`          VARCHAR(30)  NOT NULL DEFAULT 'pcs',
 *   `reorder_level` INT          NOT NULL DEFAULT 0,
 *   `price`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 *   `notes`         TEXT         DEFAULT NULL,
 *   `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 5. Stock movements (daily Stock In / Stock Out log)
 * CREATE TABLE IF NOT EXISTS `stock_movements` (
 *   `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `item_id`       INT UNSIGNED NOT NULL,
 *   `branch_id`     INT UNSIGNED DEFAULT NULL,
 *   `type`          ENUM('in','out') NOT NULL,
 *   `quantity`      INT NOT NULL,
 *   `balance_after` INT DEFAULT NULL,
 *   `movement_date` DATE NOT NULL,
 *   `user_id`       INT UNSIGNED DEFAULT NULL,
 *   `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_mv_item` (`item_id`),
 *   KEY `idx_mv_branch` (`branch_id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 6. Subscription / free-trial state (per customer account; single row for now)
 * CREATE TABLE IF NOT EXISTS `subscriptions` (
 *   `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `plan`           VARCHAR(20) NOT NULL DEFAULT 'trial',
 *   `status`         ENUM('trial','active','frozen') NOT NULL DEFAULT 'trial',
 *   `trial_ends_at`  DATE NOT NULL,
 *   `price_per_user` DECIMAL(10,2) NOT NULL DEFAULT 29.90,
 *   `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *   `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 * -- Seed a 14-day trial:
 * -- INSERT INTO `subscriptions` (`status`,`trial_ends_at`,`price_per_user`)
 * --   VALUES ('trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 29.90);
 *
 * -- 7. OPTIONAL seed agency admin (top-level super admin / SaaS provider).
 * --    Login -> email: admin@growgig.tech   password: Admin@123
 * --    IMPORTANT: change this password immediately after the first login.
 * -- INSERT INTO `users` (`name`, `email`, `password`, `whatsapp`, `role`) VALUES
 * --   ('Agency Admin', 'admin@growgig.tech',
 * --    '$2y$12$UlXykf4YI1VJKm6ycEIvBucVYktpp.cvIsSEObaIBa8NY7Bbv5SOG',
 * --    '+60132227762', 'agency_admin');
 *
 * -- 8. Mobile "on-the-go" trips (therapist takes stock to standby; sells or returns)
 * CREATE TABLE IF NOT EXISTS `mobile_trips` (
 *   `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `branch_id`      INT UNSIGNED DEFAULT NULL,
 *   `therapist_name` VARCHAR(120) NOT NULL,
 *   `status`         ENUM('open','settled','cancelled') NOT NULL DEFAULT 'open',
 *   `note`           VARCHAR(300) DEFAULT NULL,
 *   `trip_date`      DATE NOT NULL,
 *   `return_date`    DATE NULL DEFAULT NULL,   -- date the unsold stock was returned (settle/cancel)
 *   `created_by`     INT UNSIGNED DEFAULT NULL,
 *   `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   `settled_by`     INT UNSIGNED DEFAULT NULL,
 *   `settled_at`     TIMESTAMP NULL DEFAULT NULL,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_mt_branch` (`branch_id`),
 *   KEY `idx_mt_status` (`status`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 9. Mobile trip line items (per item: taken / sold / returned + price snapshot)
 * CREATE TABLE IF NOT EXISTS `mobile_trip_items` (
 *   `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `trip_id`      INT UNSIGNED NOT NULL,
 *   `item_id`      INT UNSIGNED NOT NULL,
 *   `qty_taken`    INT NOT NULL DEFAULT 0,
 *   `qty_sold`     INT NOT NULL DEFAULT 0,
 *   `qty_returned` INT NOT NULL DEFAULT 0,
 *   `unit_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_mti_trip` (`trip_id`),
 *   KEY `idx_mti_item` (`item_id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * -- 10. Tag stock movements that belong to a trip (NULL = normal movement).
 * --     Lets sales_report.php net out returned stock so it is not counted as a sale.
 * ALTER TABLE `stock_movements`
 *   ADD COLUMN `trip_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`,
 *   ADD KEY `idx_mv_trip` (`trip_id`);
 *
 * =============================================================================
 */
