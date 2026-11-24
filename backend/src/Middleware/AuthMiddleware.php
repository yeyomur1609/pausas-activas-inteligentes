<?php
// ARCHIVO: backend/src/Middleware/AuthMiddleware.php
// Control de sesión autenticada, verificación de rol admin y modo invitado progresivo.
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Secrets;

final class AuthMiddleware
{
    /**
     * Devuelve los datos básicos del usuario en sesión o null si es visitante/invitado.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}|null
     */
    public static function optionalUser(): ?array
    {
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
     * Exige sesión autenticada activa; responde HTTP 401 si es visitante.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}
     */
    public static function requireAuth(bool $requireVerifiedEmail = false): array
    {
        $user = self::optionalUser();
        if ($user === null) {
            http_response_code(401);
            echo json_encode([
                'ok'                => false,
                'requiere_login'    => true,
                'error'             => 'Inicia sesión o crea tu cuenta para guardar tu racha, parques favoritos e historial.',
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
     * Exige sesión autenticada con rol 'admin'; responde HTTP 403 en caso contrario.
     *
     * @return array{id:int,nombre:string,email:string,rol:string,ocupacion:string,email_verificado:bool}
     */
    public static function requireAdmin(): array
    {
        $user = self::requireAuth(true);
        if ($user['rol'] !== 'admin') {
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
     * Inicia o actualiza la sesión segura del usuario regenerando el identificador de sesión.
     *
     * @param array<string, mixed> $usuario
     */
    public static function loginUserSession(array $usuario): void
    {
        Secrets::initSecureSession();
        session_regenerate_id(true);
        CsrfMiddleware::rotateToken();

        $_SESSION['usuario_id']       = (int)$usuario['id'];
        $_SESSION['nombre']           = (string)($usuario['nombre'] ?? 'Usuario');
        $_SESSION['email']            = (string)($usuario['email'] ?? '');
        $_SESSION['rol']              = (string)($usuario['rol'] ?? 'usuario');
        $_SESSION['ocupacion']        = (string)($usuario['ocupacion'] ?? 'estudiante');
        $_SESSION['email_verificado'] = (bool)($usuario['email_verificado'] ?? false);
    }

    public static function logoutSession(): void
    {
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
