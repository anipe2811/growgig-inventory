<?php
/**
 * health.php — uptime/health probe for monitoring.
 *
 * Deliberately does NOT include config/config.php: that file starts a session,
 * runs redirects and role checks, none of which a probe should trigger.
 * Returns 200 {"ok":true} when PHP + MySQL are reachable, 500 otherwise.
 * No secrets or internal details are ever exposed.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$ok = false;
try {
    $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: 'localhost')
         . ';dbname=' . (getenv('DB_NAME') ?: 'saas_inventory')
         . ';charset=utf8mb4';
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $ok = $pdo->query('SELECT 1')->fetchColumn() == 1;
} catch (Throwable $e) {
    $ok = false;
}

http_response_code($ok ? 200 : 500);
echo json_encode(['ok' => $ok, 'db' => $ok]);
