<?php
// ARCHIVO: backend/config/secrets.php
// Carga centralizada de variables de entorno (.env), desencriptación en memoria AES-256-GCM (ENC:),
// autoloader PSR-4 y configuración de sesión segura.
declare(strict_types=1);

namespace App\Config;

final class Secrets
{
    private static bool $loaded = false;
    /** @var array<string, string> */
    private static array $env = [];

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // 1. Registrar autoload de Composer (busca en backend/vendor y en raíz ../vendor)
        $candidatosAutoload = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        foreach ($candidatosAutoload as $rutaAutoload) {
            if (is_file($rutaAutoload)) {
                require_once $rutaAutoload;
                break;
            }
        }

        // 2. Registrar autoloader PSR-4 nativo para el namespace App\ -> backend/src/
        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        // 3. Leer archivo .env local solo si el contenedor/host no inyecta APP_ENV=production directamente
        $hostAppEnv = strtolower(trim((string)getenv('APP_ENV')));
        if ($hostAppEnv !== 'production') {
            $rutasEnv = [
                dirname(__DIR__) . '/.env',
                dirname(__DIR__, 2) . '/.env',
            ];

            foreach ($rutasEnv as $rutaEnv) {
                if (is_file($rutaEnv) && self::parseEnvFile($rutaEnv)) {
                    break;
                }
            }
        }

        $timezone = self::get('APP_TIMEZONE', 'America/Monterrey');
        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            @date_default_timezone_set($timezone);
        }

        if (PHP_SAPI !== 'cli') {
            $debug = self::bool('APP_DEBUG', false);
            @ini_set('display_errors', $debug ? '1' : '0');
            @ini_set('display_startup_errors', $debug ? '1' : '0');
            @ini_set('html_errors', '0');
            @ini_set('log_errors', '1');
        }
    }

    private static function parseEnvFile(string $path): bool
    {
        $lineas = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lineas)) {
            return false;
        }

        foreach ($lineas as $linea) {
            $limpia = trim($linea);
            if ($limpia === '' || str_starts_with($limpia, '#') || !str_contains($limpia, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $limpia, 2);
            $clave = trim($clave);
            $valor = trim($valor);

            if (
                (str_starts_with($valor, '"') && str_ends_with($valor, '"')) ||
                (str_starts_with($valor, "'") && str_ends_with($valor, "'"))
            ) {
                $valor = substr($valor, 1, -1);
            }

            if ($clave !== '') {
                self::$env[$clave] = $valor;
                if (!array_key_exists($clave, $_ENV) && getenv($clave) === false) {
                    $_ENV[$clave] = $valor;
                }
            }
        }
        return true;
    }

    /**
     * Desencripta en memoria valores que comiencen con el prefijo `ENC:` usando AES-256-GCM
     * (Formato compatible con ActivaMente: `ENC:ivHex:authTagHex:cipherHex`).
     */
    public static function decryptIfEncrypted(string $rawValue): string
    {
        if (!str_starts_with($rawValue, 'ENC:')) {
            return $rawValue;
        }

        $payload = substr($rawValue, 4);
        $partes = explode(':', $payload);
        if (count($partes) !== 3) {
            return $rawValue;
        }

        [$ivHex, $tagHex, $cipherHex] = $partes;
        $iv = @hex2bin($ivHex);
        $tag = @hex2bin($tagHex);
        $cipher = @hex2bin($cipherHex);

        if ($iv === false || $tag === false || $cipher === false) {
            return '';
        }

        $masterKeyRaw = self::getRaw('MASTER_ENCRYPTION_KEY') ?: self::getRaw('APP_KEY');

        if ($masterKeyRaw === '') {
            error_log('[Secrets Error] Falta MASTER_ENCRYPTION_KEY o APP_KEY en backend/.env para desencriptar secreto.');
            return '';
        }

        $key32 = hash('sha256', $masterKeyRaw, true);
        $decrypted = @openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key32,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return is_string($decrypted) ? $decrypted : '';
    }

    /**
     * Utilidad para encriptar un secreto con AES-256-GCM en formato `ENC:ivHex:tagHex:cipherHex`.
     */
    public static function encryptValue(string $plainText, string $masterKey = ''): string
    {
        $secretKey = trim($masterKey !== ''
            ? $masterKey
            : (self::get('MASTER_ENCRYPTION_KEY', '') ?: self::get('APP_KEY', '')));
        if ($secretKey === '') {
            throw new \RuntimeException('MASTER_ENCRYPTION_KEY o APP_KEY debe estar configurado en backend/.env para cifrar secretos.');
        }
        $key32 = hash('sha256', $secretKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key32,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar el secreto con AES-256-GCM.');
        }
        return 'ENC:' . bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($cipher);
    }

    private static function getRaw(string $key): string
    {
        $hostVal = getenv($key);
        if (is_string($hostVal) && trim($hostVal) !== '') {
            return trim($hostVal);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
            return trim($_SERVER[$key]);
        }
        if (isset(self::$env[$key]) && trim(self::$env[$key]) !== '') {
            return trim(self::$env[$key]);
        }
        return '';
    }

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        $raw = self::getRaw($key);
        if ($raw !== '') {
            return self::decryptIfEncrypted($raw);
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $raw = self::get($key, (string)$default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return string[]
     */
    public static function allowedOrigins(): array
    {
        $defaultOrigins = self::isProduction()
            ? ''
            : 'http://localhost:3000,http://127.0.0.1:3000,http://localhost,http://127.0.0.1';
        $raw = self::get('CORS_ALLOWED_ORIGINS', $defaultOrigins);
        $origins = array_filter(array_map(
            static fn(string $item): string => rtrim(trim($item), '/'),
            explode(',', $raw)
        ));

        // Autorizar automáticamente FRONTEND_URL, CORS_ORIGIN y APP_URL (dominio de Vercel en producción)
        foreach (['FRONTEND_URL', 'CORS_ORIGIN', 'APP_URL'] as $urlKey) {
            $candidateUrl = rtrim(trim(self::get($urlKey, '')), '/');
            if ($candidateUrl !== '') {
                $parsed = parse_url($candidateUrl);
                if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host'])) {
                    $origin = $parsed['scheme'] . '://' . $parsed['host']
                        . (!empty($parsed['port']) ? ':' . (int)$parsed['port'] : '');
                    $origins[] = rtrim($origin, '/');
                }
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Devuelve la URL pública del Frontend para construir enlaces de correo (verificación, magic link, reset).
     * Prioridad: APP_URL -> FRONTEND_URL -> CORS_ORIGIN -> primer origen HTTPS -> respaldo Vercel de producción.
     */
    public static function appUrl(): string
    {
        foreach (['APP_URL', 'FRONTEND_URL', 'CORS_ORIGIN'] as $key) {
            $val = rtrim(trim(self::get($key, '')), '/');
            if ($val !== '' && filter_var($val, FILTER_VALIDATE_URL) !== false) {
                return $val;
            }
        }

        foreach (self::allowedOrigins() as $origin) {
            if (str_starts_with($origin, 'https://')) {
                return rtrim($origin, '/');
            }
        }

        return self::isProduction()
            ? 'https://pausas-activas-inteligentes.vercel.app'
            : 'http://localhost:3000';
    }

    /**
     * Configuración unificada de correo transaccional (Mailjet Send API v3.1 prioritario + respaldo SMTP/PHPMailer).
     *
     * @return array{
     *   provider:string,
     *   mailjet_api_key:string,
     *   mailjet_secret_key:string,
     *   from_email:string,
     *   from_name:string,
     *   smtp_host:string,
     *   smtp_port:int,
     *   smtp_secure:string,
     *   smtp_user:string,
     *   smtp_pass:string
     * }
     */
    public static function mailConfig(): array
    {
        $smtpUser = trim(self::get('SMTP_USER', ''));
        $fromEmail = trim(self::get('MAIL_FROM_EMAIL', ''));
        if ($fromEmail === '') {
            $fromEmail = trim(self::get('MAIL_FROM', $smtpUser));
        }

        return [
            'provider'           => strtolower(trim(self::get('MAIL_PROVIDER', ''))),
            'mailjet_api_key'    => trim(self::get('MAILJET_API_KEY', '')),
            'mailjet_secret_key' => trim(self::get('MAILJET_SECRET_KEY', '')),
            'from_email'         => $fromEmail,
            'from_name'          => trim(self::get('MAIL_FROM_NAME', 'Pausas Activas Inteligentes')) ?: 'Pausas Activas Inteligentes',
            'smtp_host'          => trim(self::get('SMTP_HOST', '')),
            'smtp_port'          => self::int('SMTP_PORT', 587),
            'smtp_secure'        => strtolower(trim(self::get('SMTP_SECURE', 'tls'))),
            'smtp_user'          => $smtpUser,
            'smtp_pass'          => str_replace(' ', '', self::get('SMTP_PASS', '')),
        ];
    }

    public static function isProduction(): bool
    {
        return strtolower(trim(self::get('APP_ENV', 'development'))) === 'production';
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
            return true;
        }
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if (str_contains($proto, 'https')) {
            return true;
        }
        $sslHeader = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        return $sslHeader === 'on';
    }

    /**
     * Parámetros efectivos de cookie de sesión (SameSite=None + Secure + HttpOnly en producción cross-domain).
     *
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    public static function sessionCookieParams(): array
    {
        self::load();
        $isProd = self::isProduction();
        $sessionLifetime = max(300, self::int('SESSION_LIFETIME', 86400 * 7));

        // En producción cross-domain (Vercel <-> Render), forzar estrictamente SameSite=None + Secure + HttpOnly
        if ($isProd) {
            return [
                'lifetime' => $sessionLifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'None',
            ];
        }

        $esHttps = self::isHttps();
        $sameSiteRaw = ucfirst(strtolower(trim(self::get('SESSION_SAMESITE', 'Lax'))));
        $sameSite = in_array($sameSiteRaw, ['Lax', 'Strict', 'None'], true) ? $sameSiteRaw : 'Lax';
        if ($sameSite === 'None' && !$esHttps) {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => $sessionLifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $esHttps,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    public static function initSecureSession(): void
    {
        self::load();
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cookieParams = self::sessionCookieParams();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $cookieParams['secure'] ? '1' : '0');
        ini_set('session.cookie_samesite', $cookieParams['samesite']);

        session_name('PAI_SESSID');
        session_set_cookie_params($cookieParams);

        @session_start();
    }
}

Secrets::load();
