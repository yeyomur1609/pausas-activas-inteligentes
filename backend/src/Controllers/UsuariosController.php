<?php
// ARCHIVO: backend/src/Controllers/UsuariosController.php
// Controlador de perfil de usuario, cambio de correo con reverificación, cambio de contraseña,
// preferencias de accesibilidad/avatar/tema JSON, envío de resumen por correo, estadísticas y baja de cuenta.
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Secrets;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\HistorialModel;
use App\Models\ParqueFavoritoModel;
use App\Models\UsuarioModel;
use App\Services\EmailService;

final class UsuariosController
{
    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body   = self::readJsonBody();
        $accion = (string)($_GET['accion'] ?? $body['accion'] ?? 'perfil');

        if ($method === 'GET') {
            $sessionUser = AuthMiddleware::requireAuth();
            RateLimitMiddleware::check('usuarios_get', 60, 60, (string)$sessionUser['id']);

            if ($accion === 'comunidad') {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                self::json(200, [
                    'ok'             => true,
                    'comunidad_feed' => HistorialModel::obtenerFeedComunitario(15),
                ]);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($accion === 'estadisticas') {
                $stats = HistorialModel::obtenerEstadisticasUsuario($sessionUser['id']);
                $favoritos = ParqueFavoritoModel::listarPorUsuario($sessionUser['id']);
                self::json(200, [
                    'ok'                => true,
                    'estadisticas'      => $stats,
                    'parques_favoritos' => $favoritos,
                ]);
            }

            $usuario = UsuarioModel::findById($sessionUser['id']);
            if ($usuario === null) {
                self::json(404, ['ok' => false, 'error' => 'Usuario no encontrado.']);
            }

            self::json(200, [
                'ok'           => true,
                'usuario'      => UsuarioModel::toPublicArray($usuario),
                'estadisticas' => HistorialModel::obtenerEstadisticasUsuario($sessionUser['id']),
            ]);
        }

        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            self::json(405, ['ok' => false, 'error' => 'Método HTTP no permitido.']);
        }

        $sessionUser = AuthMiddleware::requireAuth();
        CsrfMiddleware::validateOrFail($body);
        RateLimitMiddleware::check('usuarios_write', 25, 60, (string)$sessionUser['id']);

        switch ($accion) {
            case 'actualizar_perfil':
                self::actualizarPerfil($sessionUser['id'], $body);
                break;

            case 'guardar_preferencias':
                self::guardarPreferencias($sessionUser['id'], $body);
                break;

            case 'cambiar_email':
                self::cambiarEmail($sessionUser['id'], $body);
                break;

            case 'enviar_resumen_email':
                RateLimitMiddleware::check('usuarios_resumen_email', 4, 300, (string)$sessionUser['id']);
                self::enviarResumenEmail($sessionUser['id']);
                break;

            case 'cambiar_password':
                self::cambiarPassword($sessionUser['id'], $body);
                break;

            case 'eliminar_cuenta':
                self::eliminarCuenta($sessionUser['id'], $body);
                break;

            default:
                self::json(400, ['ok' => false, 'error' => 'Acción de usuario no reconocida.']);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function actualizarPerfil(int $usuarioId, array $body): void
    {
        $nombre    = trim(strip_tags((string)($body['nombre'] ?? '')));
        $ocupacion = (string)($body['ocupacion'] ?? 'estudiante');
        $metaMin   = (int)($body['meta_diaria_min'] ?? 15);
        $prefs     = is_array($body['preferencias'] ?? null) ? $body['preferencias'] : null;

        if (mb_strlen($nombre, 'UTF-8') < 2) {
            self::json(422, ['ok' => false, 'error' => 'El nombre o alias debe tener al menos 2 caracteres.']);
        }

        $actualizado = UsuarioModel::updateProfile($usuarioId, $nombre, $ocupacion, $metaMin, $prefs);
        if ($actualizado === null) {
            self::json(404, ['ok' => false, 'error' => 'No se encontró la cuenta para actualizar.']);
        }

        $_SESSION['nombre']    = (string)$actualizado['nombre'];
        $_SESSION['ocupacion'] = (string)$actualizado['ocupacion'];

        self::json(200, [
            'ok'      => true,
            'mensaje' => 'Perfil y preferencias actualizados correctamente.',
            'usuario' => UsuarioModel::toPublicArray($actualizado),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function guardarPreferencias(int $usuarioId, array $body): void
    {
        $prefs = is_array($body['preferencias'] ?? null) ? $body['preferencias'] : $body;
        $actualizado = UsuarioModel::updatePreferences($usuarioId, $prefs);

        if ($actualizado === null) {
            self::json(404, ['ok' => false, 'error' => 'Usuario no encontrado.']);
        }

        self::json(200, [
            'ok'           => true,
            'mensaje'      => 'Preferencias de accesibilidad y personalización sincronizadas.',
            'preferencias' => $actualizado['preferencias'],
            'usuario'      => UsuarioModel::toPublicArray($actualizado),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function cambiarEmail(int $usuarioId, array $body): void
    {
        $nuevoEmailRaw = trim((string)($body['nuevo_email'] ?? $body['email'] ?? ''));
        $passActual    = (string)($body['password_actual'] ?? '');
        if ($nuevoEmailRaw === '') {
            self::json(400, ['ok' => false, 'error' => 'Ingresa la nueva dirección de correo electrónico.']);
        }
        if ($passActual === '') {
            self::json(400, ['ok' => false, 'error' => 'Ingresa tu contraseña actual para confirmar el cambio de correo.']);
        }

        $usuarioActual = UsuarioModel::findById($usuarioId);
        if ($usuarioActual === null) {
            self::json(404, ['ok' => false, 'error' => 'No se encontró la cuenta para actualizar el correo.']);
        }
        if (!password_verify($passActual, (string)$usuarioActual['password_hash'])) {
            self::json(401, ['ok' => false, 'error' => 'La contraseña actual ingresada es incorrecta.']);
        }

        $validacion = EmailService::validarCorreoReal($nuevoEmailRaw);
        if (!$validacion['valido']) {
            self::json(422, ['ok' => false, 'error' => $validacion['error']]);
        }
        $nuevoEmail = $validacion['email_limpio'];

        if (strtolower((string)$usuarioActual['email']) === $nuevoEmail) {
            self::json(422, ['ok' => false, 'error' => 'El nuevo correo es igual a tu correo actual.']);
        }

        $existente = UsuarioModel::findByEmail($nuevoEmail);
        if ($existente !== null && (int)$existente['id'] !== $usuarioId) {
            self::json(409, ['ok' => false, 'error' => 'Ese correo electrónico ya está asociado a otra cuenta.']);
        }

        $cred = EmailService::generarCredencialesTemporales();
        $ttl  = Secrets::int('EMAIL_VERIFY_EXPIRY_MINUTES', 10080);
        $actualizado = UsuarioModel::updateEmailForVerification(
            $usuarioId,
            $nuevoEmail,
            $cred['token_hash'],
            $cred['codigo_hash'],
            $ttl
        );

        if ($actualizado === null) {
            self::json(404, ['ok' => false, 'error' => 'No se encontró la cuenta para actualizar el correo.']);
        }

        $_SESSION['email']            = $nuevoEmail;
        $_SESSION['email_verificado'] = false;

        $envio = EmailService::enviarVerificacionCuenta(
            $nuevoEmail,
            (string)$actualizado['nombre'],
            $cred['token'],
            $cred['codigo'],
            $ttl
        );

        $resp = [
            'ok'      => true,
            'mensaje' => "Correo actualizado a {$nuevoEmail}. Hemos enviado un nuevo código de 6 dígitos y enlace de confirmación.",
            'usuario' => UsuarioModel::toPublicArray($actualizado),
        ];
        if (Secrets::get('APP_ENV', 'development') !== 'production') {
            $resp['verificacion'] = $envio;
        }
        self::json(200, $resp);
    }

    private static function enviarResumenEmail(int $usuarioId): void
    {
        $usuario = UsuarioModel::findById($usuarioId);
        if ($usuario === null) {
            self::json(404, ['ok' => false, 'error' => 'Usuario no encontrado.']);
        }

        $estadisticas = HistorialModel::obtenerEstadisticasUsuario($usuarioId);
        $envio = EmailService::enviarResumenProgreso(
            (string)$usuario['email'],
            (string)$usuario['nombre'],
            $estadisticas
        );

        self::json(200, [
            'ok'      => true,
            'envio'   => $envio,
            'mensaje' => '📧 Hemos enviado el resumen de tu progreso, racha y últimas pausas a ' . $usuario['email'] . '.',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function cambiarPassword(int $usuarioId, array $body): void
    {
        $passActual = (string)($body['password_actual'] ?? '');
        $passNueva  = (string)($body['password_nueva'] ?? '');

        if ($passActual === '' || $passNueva === '') {
            self::json(400, ['ok' => false, 'error' => 'Debes ingresar tu contraseña actual y la nueva contraseña.']);
        }

        $usuario = UsuarioModel::findById($usuarioId);
        if ($usuario === null) {
            self::json(404, ['ok' => false, 'error' => 'Usuario no encontrado.']);
        }

        $passValida = password_verify($passActual, (string)$usuario['password_hash']);

        if (!$passValida) {
            self::json(401, ['ok' => false, 'error' => 'La contraseña actual ingresada es incorrecta.']);
        }

        $errorPolitica = AuthController::validarPoliticaPassword($passNueva, (string)$usuario['email']);
        if ($errorPolitica !== null) {
            self::json(422, ['ok' => false, 'error' => $errorPolitica]);
        }

        UsuarioModel::updatePassword($usuarioId, password_hash($passNueva, PASSWORD_BCRYPT));

        self::json(200, [
            'ok'      => true,
            'mensaje' => 'Tu contraseña se actualizó exitosamente.',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function eliminarCuenta(int $usuarioId, array $body): void
    {
        $confirmacion = trim((string)($body['confirmacion'] ?? ''));
        $passActual   = (string)($body['password_actual'] ?? '');
        if ($confirmacion !== 'ELIMINAR') {
            self::json(422, ['ok' => false, 'error' => 'Escribe la palabra ELIMINAR para confirmar la baja definitiva de tu cuenta.']);
        }
        if ($passActual === '') {
            self::json(400, ['ok' => false, 'error' => 'Ingresa tu contraseña actual para confirmar la eliminación de tu cuenta.']);
        }

        $usuario = UsuarioModel::findById($usuarioId);
        if ($usuario === null) {
            self::json(404, ['ok' => false, 'error' => 'Usuario no encontrado.']);
        }
        if (!password_verify($passActual, (string)$usuario['password_hash'])) {
            self::json(401, ['ok' => false, 'error' => 'La contraseña actual ingresada es incorrecta.']);
        }

        UsuarioModel::deleteAccount($usuarioId);
        AuthMiddleware::logoutSession();

        self::json(200, [
            'ok'         => true,
            'mensaje'    => 'Tu cuenta y todos tus datos asociados han sido eliminados permanentemente.',
            'csrf_token' => CsrfMiddleware::getToken(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                return $dec;
            }
        }
        return $_POST;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(int $status, array $payload): never
    {
        \App\Middleware\CorsMiddleware::sendJson($status, $payload);
    }
}
