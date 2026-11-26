<?php
// ARCHIVO: backend/src/Middleware/AuthMiddleware.php
// Autenticación híbrida Cloud-Ready:
// 1) Primaria Stateless: Authorization: Bearer <jwt> (HMAC-SHA256 vía JwtService, ideal para Vercel <-> Render).
// 2) Respaldo secundario local: $_SESSION en PHP para compatibilidad con desarrollo local / XAMPP.
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Secrets;
use App\Models\UsuarioModel;
use App\Services\JwtService;

final class AuthMiddleware
{
    private static bool $authenticatedViaBearer = false;

    /**
     * Extrae el token Bearer desde HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION o getallheaders().
     */
    public static function extractBearerToken(): ?string
    {
        $rawHeader = '';

        $serverKeys = [
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'REDIRECT_REDIRECT_HTTP_AUTHORIZATION',
        ];
        foreach ($serverKeys as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $rawHeader = trim($_SERVER[$key]);
                break;
            }
        }

        if ($rawHeader === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                        $rawHeader = trim($value);
                        break;
                    }
                }
            }
        }

        if ($rawHeader === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                        $rawHeader = trim($value);
                        break;
                    }
                }
            }
        }

        if ($rawHeader === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+)$/i', $rawHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Indica si la petición actual porta un JWT Bearer criptográficamente válido y vigente.
     */
    public static function hasValidBearerToken(): bool
    {
        $token = self::extractBearerToken();
        if ($token === null) {
            return false;
        }
        $claims = JwtService::decode($token);
        return is_array($claims) && (int)($claims['sub'] ?? 0) > 0;
    }

    public static function isAuthenticatedViaBearer(): bool
    {
        return self::$authenticatedViaBearer;
    }

    /**
     * Devuelve los datos básicos del usuario autenticado (primero vía JWT Bearer stateless;
     * si no hay Bearer, usa $_SESSION como respaldo local) o null si es visitante.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}|null
     */
    public static function optionalUser(): ?array
    {
        self::$authenticatedViaBearer = false;

        // 1. Vía primaria Stateless: Authorization: Bearer <jwt>
        $bearer = self::extractBearerToken();
        if ($bearer !== null) {
            $claims = JwtService::decode($bearer);
            if (is_array($claims) && (int)($claims['sub'] ?? 0) > 0) {
                self::$authenticatedViaBearer = true;
                return [
                    'id'               => (int)$claims['sub'],
                    'nombre'           => (string)($claims['nombre'] ?? 'Usuario'),
                    'email'            => (string)($claims['email'] ?? ''),
                    'rol'              => (string)($claims['rol'] ?? 'usuario'),
                    'ocupacion'        => (string)($claims['ocupacion'] ?? 'estudiante'),
                    'email_verificado' => (bool)($claims['email_verificado'] ?? false),
                ];
            }
            // Si el cliente envió un Bearer inválido o expirado, no crear sesión nueva en disco
            if (empty($_COOKIE['PAI_SESSID'])) {
                return null;
            }
        }

        // 2. Respaldo secundario para compatibilidad en desarrollo local ($_SESSION)
        if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE['PAI_SESSID'])) {
            return null;
        }

        Secrets::initSecureSession();

        if (empty($_SESSION['usuario_id'])) {
            return null;
        }

        return [
            'id'               => (int)$_SESSION['usuario_id'],
            'nombre'           => (string)($_SESSION['nombre'] ?? 'Usuario'),
            'email'            => (string)($_SESSION['email'] ?? ''),
            'rol'              => (string)($_SESSION['rol'] ?? 'usuario'),
            'ocupacion'        => (string)($_SESSION['ocupacion'] ?? 'estudiante'),
            'email_verificado' => (bool)($_SESSION['email_verificado'] ?? false),
        ];
    }

    /**
     * Exige autenticación activa (JWT Bearer o $_SESSION local); responde HTTP 401 si no existe.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}
     */
    public static function requireAuth(bool $requireVerifiedEmail = false): array
    {
        $user = self::optionalUser();
        if ($user === null) {
            http_response_code(401);
            echo json_encode([
                'ok'             => false,
                'requiere_login' => true,
                'error'          => 'Inicia sesión o crea tu cuenta para guardar tu racha, parques favoritos e historial.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($requireVerifiedEmail && !$user['email_verificado']) {
            http_response_code(403);
            echo json_encode([
                'ok'                    => false,
                'requiere_verificacion' => true,
                'error'                 => 'Debes confirmar tu correo electrónico antes de realizar esta acción.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $user;
    }

    /**
     * Exige autenticación con rol 'admin' validado contra base de datos; responde HTTP 403 en caso contrario.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}
     */
    public static function requireAdmin(): array
    {
        $user = self::requireAuth(true);
        $dbUser = UsuarioModel::findById($user['id']);
        if ($dbUser === null || !(bool)($dbUser['activo'] ?? true) || ($dbUser['rol'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode([
                'ok'    => false,
                'error' => 'Acceso restringido exclusivamente a administradores del sistema.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $user;
    }

    /**
     * Emite un JWT Bearer firmado para el usuario y mantiene $_SESSION sincronizada como respaldo local.
     *
     * @param array<string, mixed> $usuario
     */
    public static function loginUserSession(array $usuario): string
    {
        $jwt = JwtService::issueForUser($usuario);

        // Mantener $_SESSION como respaldo secundario para compatibilidad local (XAMPP / pruebas por cookie)
        if (!Secrets::isProduction() || !empty($_COOKIE['PAI_SESSID']) || session_status() === PHP_SESSION_ACTIVE) {
            Secrets::initSecureSession();
            @session_regenerate_id(true);
            CsrfMiddleware::rotateToken();

            $_SESSION['usuario_id']       = (int)$usuario['id'];
            $_SESSION['nombre']           = (string)($usuario['nombre'] ?? 'Usuario');
            $_SESSION['email']            = (string)($usuario['email'] ?? '');
            $_SESSION['rol']              = (string)($usuario['rol'] ?? 'usuario');
            $_SESSION['ocupacion']        = (string)($usuario['ocupacion'] ?? 'estudiante');
            $_SESSION['email_verificado'] = (bool)($usuario['email_verificado'] ?? false);
        }

        return $jwt;
    }

    public static function logoutSession(): void
    {
        self::$authenticatedViaBearer = false;

        if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE['PAI_SESSID'])) {
            return;
        }

        Secrets::initSecureSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookieCfg = Secrets::sessionCookieParams();
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'] ?: $cookieCfg['path'],
                    'domain'   => $params['domain'] ?: $cookieCfg['domain'],
                    'secure'   => (bool)($cookieCfg['secure'] || ($params['secure'] ?? false)),
                    'httponly' => true,
                    'samesite' => $cookieCfg['samesite'],
                ]
            );
        }

        @session_destroy();
    }
}
