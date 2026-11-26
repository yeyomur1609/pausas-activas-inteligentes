<?php
// ARCHIVO: backend/src/Middleware/CsrfMiddleware.php
// Validación en tiempo constante del token anti-CSRF (X-CSRF-Token) para sesiones por cookie.
// Cuando la petición se autentica mediante encabezado `Authorization: Bearer <jwt>`, no exige cookie CSRF
// (los tokens Bearer enviados explícitamente por cabecera son inmunes a CSRF).
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Secrets;

final class CsrfMiddleware
{
    public static function getToken(): string
    {
        // En modo stateless puro en producción sin cookie activa, devolver un nonce efímero sin tocar disco
        if (Secrets::isProduction() && empty($_COOKIE['PAI_SESSID']) && session_status() !== PHP_SESSION_ACTIVE) {
            return bin2hex(random_bytes(32));
        }

        Secrets::initSecureSession();

        if (
            empty($_SESSION['csrf_token']) ||
            !is_string($_SESSION['csrf_token']) ||
            strlen($_SESSION['csrf_token']) < 64
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function rotateToken(): string
    {
        if (Secrets::isProduction() && empty($_COOKIE['PAI_SESSID']) && session_status() !== PHP_SESSION_ACTIVE) {
            return bin2hex(random_bytes(32));
        }

        Secrets::initSecureSession();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function validateOrFail(?array $parsedBody = null): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Las peticiones autenticadas mediante un JWT Bearer válido en Authorization son inmunes a CSRF
        if (AuthMiddleware::hasValidBearerToken()) {
            return;
        }

        // En producción cross-domain, los endpoints públicos pre-autenticación (login/registro/magic_link/reset)
        // tampoco deben depender de cookies de terceros bloqueadas si no existe cookie de sesión activa
        if (Secrets::isProduction() && empty($_COOKIE['PAI_SESSID']) && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $expected = self::getToken();
        $provided = '';

        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $provided = trim((string)$_SERVER['HTTP_X_CSRF_TOKEN']);
        } elseif (is_array($parsedBody) && !empty($parsedBody['csrf_token'])) {
            $provided = trim((string)$parsedBody['csrf_token']);
        } elseif (!empty($_POST['csrf_token'])) {
            $provided = trim((string)$_POST['csrf_token']);
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo json_encode([
                'ok'     => false,
                'codigo' => 'CSRF_INVALID',
                'error'  => 'Token de seguridad CSRF inválido o expirado. Recarga la sesión e intenta nuevamente.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
