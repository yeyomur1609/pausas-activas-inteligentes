<?php
// ARCHIVO: backend/public/health.php
// Health Check JSON para Render (/health.php y /health) con verificación real de BD sin exponer secretos ni trazas.
declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

require_once dirname(__DIR__) . '/config/secrets.php';
require_once dirname(__DIR__) . '/config/database.php';

use App\Config\Database;
use App\Config\Secrets;

Secrets::load();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim(trim((string)$_SERVER['HTTP_ORIGIN']), '/') : '';
if ($origin !== '') {
    header('Vary: Origin');
    if (
        in_array($origin, Secrets::allowedOrigins(), true) ||
        (!Secrets::isProduction() && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin) === 1)
    ) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
}

$status = 200;
$payload = [
    'ok'        => true,
    'status'    => 'healthy',
    'service'   => 'pausas-activas-api',
    'env'       => Secrets::get('APP_ENV', 'development'),
    'db_driver' => Database::driver(),
    'db'        => 'skipped',
    'php'       => PHP_VERSION,
    'time'      => gmdate('c'),
];

// Verifica conectividad real a la base de datos por defecto (usa ?shallow=1 solo si deseas omitir la consulta SQL)
$skipDb = isset($_GET['shallow']) && $_GET['shallow'] === '1';
if (!$skipDb) {
    try {
        $pdo = Database::getConnection(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $payload['db'] = 'connected';
    } catch (\Throwable $e) {
        error_log('[Health Check DB Error] ' . $e->getMessage());
        $status = 503;
        $payload['ok'] = false;
        $payload['status'] = 'degraded';
        $payload['db'] = 'unavailable';
    }
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}
http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
