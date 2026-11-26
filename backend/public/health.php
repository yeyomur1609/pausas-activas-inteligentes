<?php
// ARCHIVO: backend/public/health.php
// Health Check JSON para Render (/health.php) y monitoreo de producción.
declare(strict_types=1);

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
if ($origin !== '' && (in_array($origin, Secrets::allowedOrigins(), true) || (!Secrets::isProduction() && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin) === 1))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

$status = 200;
$payload = [
    'ok'        => true,
    'status'    => 'healthy',
    'service'   => 'pausas-activas-api',
    'env'       => Secrets::get('APP_ENV', 'development'),
    'db_driver' => Database::driver(),
    'php'       => PHP_VERSION,
    'time'      => gmdate('c'),
];

// Verificación opcional de conectividad real a base de datos (?deep=1) sin penalizar el health check por defecto de Render
if (isset($_GET['deep']) && $_GET['deep'] === '1') {
    try {
        $pdo = Database::getConnection(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $payload['db'] = 'connected';
    } catch (\Throwable $e) {
        error_log('[Health Deep Check Error] ' . $e->getMessage());
        $status = 503;
        $payload['ok'] = false;
        $payload['status'] = 'degraded';
        $payload['db'] = 'unavailable';
    }
}

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
