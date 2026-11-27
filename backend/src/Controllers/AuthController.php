<?php
// ARCHIVO: backend/src/Controllers/AuthController.php
// Controlador de autenticación: handshake de sesión + CSRF, login, registro con verificación de correo,
// Magic Link (15 min), Google OAuth, recuperación de contraseña y cierre de sesión seguro.
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Secrets;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\UsuarioModel;
use App\Services\EmailService;
use App\Services\JwtService;

final class AuthController
{
    private const HASH_TIEMPO_CONSTANTE = '$2y$10$8lWe036O6RM7MvZplJPsDeR3/9oAw1niAPXx56SxUzUqj6Emsti9W';

    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body   = self::readJsonBody();
        $accion = (string)($_GET['accion'] ?? $body['accion'] ?? 'session');

        // 1. Handshake inicial para el frontend estático (GET ?accion=session)
        if ($method === 'GET' && $accion === 'session') {
            $sessionUser = AuthMiddleware::optionalUser();
            $csrf = AuthMiddleware::isAuthenticatedViaBearer()
                ? bin2hex(random_bytes(32))
                : CsrfMiddleware::getToken();
            $usuarioCompleto = null;
            $refreshedJwt = null;

            if ($sessionUser !== null) {
                $dbUser = UsuarioModel::findById($sessionUser['id']);
                if ($dbUser !== null && (bool)($dbUser['activo'] ?? true)) {
                    $esProtegida = ($dbUser['rol'] ?? 'usuario') === 'admin'
                        || str_ends_with(strtolower((string)$dbUser['email']), '@pausasactivas.edu.mx');
                    if (
                        !$esProtegida &&
                        !$dbUser['email_verificado'] &&
                        (int)($dbUser['horas_desde_registro'] ?? 0) >= 168
                    ) {
                        UsuarioModel::deleteAccount((int)$dbUser['id']);
                        AuthMiddleware::logoutSession();
                        $csrf = CsrfMiddleware::getToken();
                    } else {
                        $usuarioCompleto = UsuarioModel::toPublicArray($dbUser);
                        $refreshedJwt = JwtService::issueForUser($dbUser);
                    }
                } else {
                    AuthMiddleware::logoutSession();
                    $csrf = CsrfMiddleware::getToken();
                }
            }

            $respSession = [
                'ok'               => true,
                'autenticado'      => $usuarioCompleto !== null,
                'csrf_token'       => $csrf,
                'google_client_id' => Secrets::get('GOOGLE_CLIENT_ID', ''),
                'usuario'          => $usuarioCompleto,
            ];
            if ($refreshedJwt !== null) {
                $respSession['token'] = $refreshedJwt;
            }
            self::json(200, $respSession);
        }

        if ($method !== 'POST') {
            self::json(405, ['ok' => false, 'error' => 'Método HTTP no permitido. Usa POST o GET ?accion=session.']);
        }

        CsrfMiddleware::validateOrFail($body);
        $maxAuthPerMin = Secrets::int('RATE_LIMIT_AUTH_PER_MIN', 12);

        switch ($accion) {
            case 'login':
                RateLimitMiddleware::check('auth_login', $maxAuthPerMin, 60, (string)($body['email'] ?? ''));
                self::login($body);
                break;

            case 'registro':
            case 'register':
                RateLimitMiddleware::check('auth_registro', 6, 60);
                self::registro($body);
                break;

            case 'verificar_email':
                RateLimitMiddleware::check('auth_verificar_email', 10, 60);
                if (!empty($body['email'])) {
                    RateLimitMiddleware::check('auth_verificar_email_otp', 5, 900, (string)$body['email']);
                }
                self::verificarEmail($body);
                break;

            case 'reenviar_verificacion':
                RateLimitMiddleware::check('auth_reenviar', 3, 3600, (string)($body['email'] ?? ''));
                self::reenviarVerificacion($body);
                break;

            case 'solicitar_magic_link':
                RateLimitMiddleware::check('auth_magic_req', 5, 300, (string)($body['email'] ?? ''));
                self::solicitarMagicLink($body);
                break;

            case 'validar_magic_link':
            case 'magic_link':
                RateLimitMiddleware::check('auth_magic_val', 10, 60, (string)($body['email'] ?? ''));
                self::validarMagicLink($body);
                break;

            case 'google_oauth':
            case 'google':
                RateLimitMiddleware::check('auth_google', 10, 60);
                self::googleOAuth($body);
                break;

            case 'solicitar_reset':
                RateLimitMiddleware::check('auth_reset_req', 4, 300, (string)($body['email'] ?? ''));
                self::solicitarReset($body);
                break;

            case 'confirmar_reset':
                RateLimitMiddleware::check('auth_reset_conf', 8, 60, (string)($body['email'] ?? ''));
                self::confirmarReset($body);
                break;

            case 'logout':
                AuthMiddleware::logoutSession();
                $nuevoCsrf = CsrfMiddleware::getToken();
                self::json(200, [
                    'ok'         => true,
                    'mensaje'    => 'Sesión cerrada de forma segura.',
                    'csrf_token' => $nuevoCsrf,
                ]);
                break;

            default:
                self::json(400, ['ok' => false, 'error' => 'Acción de autenticación no reconocida.']);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function login(array $body): void
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $pass  = (string)($body['password'] ?? '');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $pass === '') {
            self::json(400, ['ok' => false, 'error' => 'Ingresa un correo electrónico válido y tu contraseña.']);
        }

        $user = UsuarioModel::findByEmail($email);
        $hashObjetivo = $user !== null && !empty($user['password_hash'])
            ? (string)$user['password_hash']
            : self::HASH_TIEMPO_CONSTANTE;

        $passValido = password_verify($pass, $hashObjetivo);

        if ($user === null || !$passValido) {
            self::json(401, ['ok' => false, 'error' => 'Correo electrónico o contraseña incorrectos.']);
        }

        if (!(bool)($user['activo'] ?? true)) {
            self::json(403, ['ok' => false, 'error' => 'Esta cuenta está desactivada. Contacta al administrador.']);
        }

        // Ciclo de 7 días (168 horas) igual a ActivaMente (excluye admins y cuentas @pausasactivas.edu.mx):
        $esProtegida = ($user['rol'] ?? 'usuario') === 'admin'
            || str_ends_with($email, '@pausasactivas.edu.mx');

        if (!$esProtegida && !$user['email_verificado']) {
            $horasDesdeRegistro = (int)($user['horas_desde_registro'] ?? 0);
            if ($horasDesdeRegistro >= 168) {
                UsuarioModel::deleteAccount((int)$user['id']);
                self::json(401, [
                    'ok'    => false,
                    'error' => 'El plazo de 7 días (168 horas) para verificar tu correo expiró y la cuenta fue eliminada. Puedes registrarte nuevamente.',
                ]);
            }
        }

        $jwt = AuthMiddleware::loginUserSession($user);
        self::json(200, [
            'ok'         => true,
            'mensaje'    => '¡Bienvenido de nuevo a Pausas Activas Inteligentes!',
            'token'      => $jwt,
            'csrf_token' => CsrfMiddleware::getToken(),
            'usuario'    => UsuarioModel::toPublicArray($user),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function registro(array $body): void
    {
        if (!empty($body['sitio_web_hp'])) {
            self::json(400, ['ok' => false, 'error' => 'Solicitud bloqueada por filtro anti-bots.']);
        }

        // Limpiar cuentas no verificadas con >= 168 horas de antigüedad antes de validar duplicados
        UsuarioModel::limpiarCuentasNoVerificadasExpiradas();

        $nombre           = trim(strip_tags((string)($body['nombre'] ?? '')));
        $emailRaw         = (string)($body['email'] ?? '');
        $password         = (string)($body['password'] ?? '');
        $ocupacion        = (string)($body['ocupacion'] ?? 'estudiante');
        $aceptoPrivacidad = !empty($body['acepto_privacidad']) || !empty($body['aceptoTerminos']);

        if (mb_strlen($nombre, 'UTF-8') < 2) {
            self::json(422, ['ok' => false, 'error' => 'Ingresa tu nombre o alias (mínimo 2 caracteres).']);
        }

        if (!$aceptoPrivacidad) {
            self::json(422, ['ok' => false, 'error' => 'Debes aceptar el Aviso de Privacidad y Términos de Uso.']);
        }

        $valCorreo = EmailService::validarCorreoReal($emailRaw);
        if (!$valCorreo['valido']) {
            self::json(422, ['ok' => false, 'error' => $valCorreo['error']]);
        }
        $email = $valCorreo['email_limpio'];

        $errorPass = self::validarPoliticaPassword($password, $email);
        if ($errorPass !== null) {
            self::json(422, ['ok' => false, 'error' => $errorPass]);
        }

        $existente = UsuarioModel::findByEmail($email);
        if ($existente !== null) {
            self::json(409, [
                'ok'    => false,
                'error' => 'Este correo electrónico ya está registrado. Inicia sesión, usa "¿Olvidaste tu contraseña?" o verifica tu correo.',
            ]);
        }

        $cred = EmailService::generarCredencialesTemporales();
        $ttl  = Secrets::int('EMAIL_VERIFY_EXPIRY_MINUTES', 10080); // 7 días (10080 minutos)
        $hashPass = password_hash($password, PASSWORD_BCRYPT);

        $nuevoUsuario = UsuarioModel::create(
            $nombre,
            $email,
            $hashPass,
            $ocupacion,
            $cred['token_hash'],
            $cred['codigo_hash'],
            $ttl
        );

        $envio = EmailService::enviarVerificacionCuenta($email, $nombre, $cred['token'], $cred['codigo'], $ttl);
        if (empty($envio['enviado'])) {
            error_log('[AuthController Mail Warning] Falló el envío del correo de verificación en registro para: ' . $email);
        }

        // Autenticar inmediatamente (JWT Bearer stateless + sesión de respaldo local; 7 días de gracia)
        $jwt = AuthMiddleware::loginUserSession($nuevoUsuario);

        $payloadResp = [
            'ok'                    => true,
            'autenticado'           => true,
            'requiere_verificacion' => true,
            'correo_enviado'        => !empty($envio['enviado']),
            'email'                 => $email,
            'token'                 => $jwt,
            'csrf_token'            => CsrfMiddleware::getToken(),
            'usuario'               => UsuarioModel::toPublicArray($nuevoUsuario),
            'mensaje'               => !empty($envio['enviado'])
                ? '¡Cuenta creada y sesión iniciada! Te enviamos un correo de confirmación; tienes 7 días para verificar tu cuenta.'
                : '¡Cuenta creada y sesión iniciada! No pudimos entregar el correo de confirmación ahora; puedes reenviarlo desde Ajustes.',
        ];
        if (!Secrets::isProduction()) {
            $payloadResp['verificacion'] = $envio;
        }
        self::json(201, $payloadResp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function verificarEmail(array $body): void
    {
        $token  = trim((string)($body['token'] ?? ''));
        $email  = strtolower(trim((string)($body['email'] ?? '')));
        $codigo = preg_replace('/\D/', '', (string)($body['codigo'] ?? '')) ?? '';

        $usuarioVerificado = null;
        if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $usuarioVerificado = UsuarioModel::verifyEmailByToken($token);
        } elseif ($email !== '' && strlen($codigo) === 6) {
            $usuarioVerificado = UsuarioModel::verifyEmailByCode($email, $codigo);
        } else {
            self::json(400, ['ok' => false, 'error' => 'Proporciona el enlace de verificación o el código de 6 dígitos.']);
        }

        if ($usuarioVerificado === null) {
            self::json(400, ['ok' => false, 'error' => 'El código o enlace de verificación es inválido o ha expirado.']);
        }

        self::assertActiveAccount($usuarioVerificado);
        $jwt = AuthMiddleware::loginUserSession($usuarioVerificado);
        self::json(200, [
            'ok'         => true,
            'mensaje'    => '¡Correo electrónico verificado con éxito! Tu cuenta ya está activa.',
            'token'      => $jwt,
            'csrf_token' => CsrfMiddleware::getToken(),
            'usuario'    => UsuarioModel::toPublicArray($usuarioVerificado),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function reenviarVerificacion(array $body): void
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::json(400, ['ok' => false, 'error' => 'Ingresa un correo electrónico válido.']);
        }

        $user = UsuarioModel::findByEmail($email);
        $envio = null;

        if ($user !== null && !$user['email_verificado'] && (bool)($user['activo'] ?? true)) {
            $cred = EmailService::generarCredencialesTemporales();
            $ttl  = Secrets::int('EMAIL_VERIFY_EXPIRY_MINUTES', 10080);
            UsuarioModel::refreshVerificationToken((int)$user['id'], $cred['token_hash'], $cred['codigo_hash'], $ttl);
            $envio = EmailService::enviarVerificacionCuenta($email, (string)$user['nombre'], $cred['token'], $cred['codigo'], $ttl);
            if (empty($envio['enviado'])) {
                error_log('[AuthController Mail Warning] Falló el reenvío de verificación para: ' . $email);
            }
        }

        $resp = [
            'ok'      => true,
            'mensaje' => 'Si la cuenta se encuentra pendiente de confirmación, hemos enviado un nuevo enlace y código de 6 dígitos a tu correo.',
        ];
        if (!Secrets::isProduction() && $envio !== null) {
            $resp['verificacion'] = $envio;
        }
        self::json(200, $resp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function solicitarMagicLink(array $body): void
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::json(400, ['ok' => false, 'error' => 'Ingresa tu correo electrónico registrado.']);
        }

        $user = UsuarioModel::findByEmail($email);
        $ttl  = Secrets::int('MAGIC_LINK_EXPIRY_MINUTES', 15);
        $preview = null;

        if ($user !== null && (bool)($user['activo'] ?? true)) {
            $cred = EmailService::generarCredencialesTemporales();
            UsuarioModel::createMagicLink(
                (int)$user['id'],
                $cred['token_hash'],
                $cred['codigo_hash'],
                $ttl,
                RateLimitMiddleware::clientIp()
            );
            $preview = EmailService::enviarMagicLink($email, (string)$user['nombre'], $cred['token'], $cred['codigo'], $ttl);
            if (empty($preview['enviado'])) {
                error_log('[AuthController Mail Warning] Falló el envío del Enlace Mágico para: ' . $email);
            }
        }

        $resp = [
            'ok'      => true,
            'email'   => $email,
            'mensaje' => "Si el correo {$email} está registrado, recibirás un enlace mágico y un código de 6 dígitos válidos por {$ttl} minutos.",
        ];
        if (!Secrets::isProduction() && $preview !== null) {
            $resp['verificacion'] = $preview;
        }
        self::json(200, $resp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function validarMagicLink(array $body): void
    {
        $token  = trim((string)($body['token'] ?? $body['magic_token'] ?? ''));
        $email  = strtolower(trim((string)($body['email'] ?? '')));
        $codigo = preg_replace('/\D/', '', (string)($body['codigo'] ?? '')) ?? '';

        $user = UsuarioModel::consumeMagicLink(
            $token !== '' ? $token : null,
            $email !== '' ? $email : null,
            $codigo !== '' ? $codigo : null
        );

        if ($user === null) {
            self::json(400, ['ok' => false, 'error' => 'El enlace mágico o código de acceso es inválido, ya fue usado o expiró (15 min).']);
        }

        self::assertActiveAccount($user);
        $jwt = AuthMiddleware::loginUserSession($user);
        self::json(200, [
            'ok'         => true,
            'mensaje'    => '¡Acceso verificado mediante Enlace Mágico!',
            'token'      => $jwt,
            'csrf_token' => CsrfMiddleware::getToken(),
            'usuario'    => UsuarioModel::toPublicArray($user),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function googleOAuth(array $body): void
    {
        $credential = trim((string)($body['credential'] ?? ''));
        $clientId   = Secrets::get('GOOGLE_CLIENT_ID', '');

        if ($credential === '' || $clientId === '') {
            self::json(400, ['ok' => false, 'error' => 'Google OAuth no está configurado o falta la credencial JWT.']);
        }

        // Validar token ID contra el endpoint oficial tokeninfo de Google con verificación TLS
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = ($code === 200 && is_string($raw)) ? json_decode($raw, true) : null;
        $iss = is_array($payload) ? (string)($payload['iss'] ?? '') : '';
        $exp = is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp']) ? (int)$payload['exp'] : 0;
        $emailVerified = is_array($payload) && (($payload['email_verified'] ?? '') === 'true' || ($payload['email_verified'] ?? false) === true);
        $email = is_array($payload) ? strtolower(trim((string)($payload['email'] ?? ''))) : '';
        $googleId = is_array($payload) ? trim((string)($payload['sub'] ?? '')) : '';

        if (
            !is_array($payload) ||
            ($payload['aud'] ?? '') !== $clientId ||
            !in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true) ||
            $exp <= time() ||
            !$emailVerified ||
            $email === '' ||
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            $googleId === ''
        ) {
            self::json(401, ['ok' => false, 'error' => 'No se pudo verificar la autenticidad de la cuenta de Google.']);
        }

        $nombre = trim(strip_tags((string)($payload['name'] ?? 'Usuario Google')));
        if (mb_strlen($nombre, 'UTF-8') < 2) {
            $nombre = 'Usuario Google';
        }

        $user = UsuarioModel::findByEmail($email);
        if ($user === null) {
            $user = UsuarioModel::create(
                $nombre,
                $email,
                password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
                'estudiante',
                '',
                '',
                1440,
                $googleId,
                1
            );
        } else {
            self::assertActiveAccount($user);
            $existingGoogleId = trim((string)($user['google_id'] ?? ''));
            if ($existingGoogleId !== '' && !hash_equals($existingGoogleId, $googleId)) {
                self::json(401, ['ok' => false, 'error' => 'La cuenta de Google no coincide con la identidad vinculada a este correo.']);
            }
            $linked = UsuarioModel::linkGoogleAccount((int)$user['id'], $googleId);
            if ($linked !== null) {
                $user = $linked;
            }
        }

        $jwt = AuthMiddleware::loginUserSession($user);
        self::json(200, [
            'ok'         => true,
            'mensaje'    => 'Sesión iniciada con Google exitosamente.',
            'token'      => $jwt,
            'csrf_token' => CsrfMiddleware::getToken(),
            'usuario'    => UsuarioModel::toPublicArray($user),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function solicitarReset(array $body): void
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::json(400, ['ok' => false, 'error' => 'Ingresa tu correo electrónico.']);
        }

        $user    = UsuarioModel::findByEmail($email);
        $ttl     = Secrets::int('PASSWORD_RESET_EXPIRY_MINUTES', 30);
        $preview = null;

        if ($user !== null && (bool)($user['activo'] ?? true)) {
            $cred = EmailService::generarCredencialesTemporales();
            UsuarioModel::createPasswordReset(
                (int)$user['id'],
                $cred['token_hash'],
                $cred['codigo_hash'],
                $ttl,
                RateLimitMiddleware::clientIp()
            );
            $preview = EmailService::enviarRecuperacionPassword($email, (string)$user['nombre'], $cred['token'], $cred['codigo'], $ttl);
        }

        $resp = [
            'ok'      => true,
            'email'   => $email,
            'mensaje' => "Si el correo {$email} existe en nuestro sistema, hemos enviado un código y enlace válidos por {$ttl} minutos para restablecer tu contraseña.",
        ];
        if (Secrets::get('APP_ENV', 'development') !== 'production' && $preview !== null) {
            $resp['verificacion'] = $preview;
        }
        self::json(200, $resp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function confirmarReset(array $body): void
    {
        $token         = trim((string)($body['token'] ?? ''));
        $email         = strtolower(trim((string)($body['email'] ?? '')));
        $codigo        = preg_replace('/\D/', '', (string)($body['codigo'] ?? '')) ?? '';
        $nuevaPassword = (string)($body['password_nueva'] ?? $body['password'] ?? '');

        $errorPass = self::validarPoliticaPassword($nuevaPassword, $email);
        if ($errorPass !== null) {
            self::json(422, ['ok' => false, 'error' => $errorPass]);
        }

        $nuevoHash = password_hash($nuevaPassword, PASSWORD_BCRYPT);
        $user = UsuarioModel::consumePasswordReset(
            $nuevoHash,
            $token !== '' ? $token : null,
            $email !== '' ? $email : null,
            $codigo !== '' ? $codigo : null
        );

        if ($user === null) {
            self::json(400, ['ok' => false, 'error' => 'El código o enlace de recuperación es inválido o ya expiró.']);
        }

        self::assertActiveAccount($user);
        $jwt = AuthMiddleware::loginUserSession($user);
        self::json(200, [
            'ok'         => true,
            'mensaje'    => 'Tu contraseña ha sido actualizada exitosamente.',
            'token'      => $jwt,
            'csrf_token' => CsrfMiddleware::getToken(),
            'usuario'    => UsuarioModel::toPublicArray($user),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function assertActiveAccount(array $user): void
    {
        if (!(bool)($user['activo'] ?? true)) {
            self::json(403, ['ok' => false, 'error' => 'Esta cuenta está desactivada. Contacta al administrador.']);
        }
    }

    public static function validarPoliticaPassword(string $password, string $email = ''): ?string
    {
        $len = strlen($password);
        if ($len < 8 || $len > 72) {
            return 'La contraseña debe tener entre 8 y 72 caracteres.';
        }
        if (!preg_match('/[A-ZÁÉÍÓÚÑ]/u', $password) || !preg_match('/[a-záéíóúñ]/u', $password)) {
            return 'La contraseña debe incluir al menos una letra mayúscula y una minúscula.';
        }
        if (!preg_match('/\d/', $password)) {
            return 'La contraseña debe incluir al menos un número.';
        }
        if (!preg_match('/[^A-Za-zÁÉÍÓÚáéíóúÑñ0-9\s]/u', $password)) {
            return 'La contraseña debe incluir al menos un símbolo especial (por ejemplo: ! @ # $ % & *).';
        }
        if ($email !== '' && str_contains($email, '@')) {
            $local = strtolower(explode('@', $email)[0]);
            if (strlen($local) >= 3 && str_contains(strtolower($password), $local)) {
                return 'Por seguridad, la contraseña no debe contener tu nombre de usuario del correo.';
            }
        }
        return null;
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
