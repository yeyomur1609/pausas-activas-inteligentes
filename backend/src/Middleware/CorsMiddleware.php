<?php
// ARCHIVO: backend/src/Middleware/CorsMiddleware.php
// Gestión estricta de CORS con soporte de credenciales (cookies de sesión) y preflight OPTIONS.
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Secrets;

final class CorsMiddleware
{
    public static function handle(): void
    {
        Secrets::load();

        if (PHP_SAPI !== 'cli') {
            if (ob_get_level() === 0) {
                ob_start();
            }
            set_exception_handler(static function (\Throwable $e): void {
                error_log('[API Uncaught Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                self::sendJson(500, [
                    'ok'    => false,
                    'error' => 'Error interno del servidor al procesar la solicitud.',
                ]);
            });
            register_shutdown_function(static function (): void {
                $err = error_get_last();
                if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    error_log('[API Fatal Error] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
                    while (ob_get_level() > 0) {
                        @ob_end_clean();
                    }
                    if (!headers_sent()) {
                        http_response_code(500);
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode([
                        'ok'    => false,
                        'error' => 'Error interno inesperado del servidor.',
                    ], JSON_UNESCAPED_UNICODE);
                }
            });
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim(trim((string)$_SERVER['HTTP_ORIGIN']), '/') : '';
        $allowedOrigins = Secrets::allowedOrigins();

        $originAllowed = false;
        if ($origin !== '') {
            if (in_array($origin, $allowedOrigins, true)) {
                $originAllowed = true;
            } elseif (
                Secrets::get('APP_ENV', 'development') !== 'production' &&
                preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin) === 1
            ) {
                $originAllowed = true;
            }
        }

        if ($origin !== '' && !headers_sent()) {
            header('Vary: Origin');
        }

        if ($originAllowed && !headers_sent()) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept');
            header('Access-Control-Expose-Headers: X-Response-Time-Ms, Retry-After');
            header('Access-Control-Max-Age: 86400');
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Emite una respuesta JSON limpia descartando cualquier salida previa en buffer.
     *
     * @param array<string, mixed> $payload
     */
    public static function sendJson(int $status, array $payload): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            $encoded = '{"ok":false,"error":"Error al serializar respuesta JSON."}';
        }
        echo $encoded;
        exit;
    }
}
