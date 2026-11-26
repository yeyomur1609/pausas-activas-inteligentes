<?php
// ARCHIVO: backend/src/Services/JwtService.php
// Servicio JWT Stateless nativo (RFC 7519, HMAC-SHA256 / HS256) sin dependencias externas.
// Diseñado para despliegues cross-domain (Vercel <-> Render) donde el disco es efímero
// y los navegadores bloquean cookies de terceros.
declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;
use RuntimeException;

final class JwtService
{
    private const DEFAULT_TTL_SECONDS = 604800; // 7 días

    /**
     * Firma un payload como JWT HS256 con expiración automática.
     *
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, int $ttlSeconds = 0): string
    {
        $secret = self::resolveSecret();
        $now = time();
        $ttl = $ttlSeconds > 0
            ? $ttlSeconds
            : max(300, Secrets::int('JWT_TTL_SECONDS', self::DEFAULT_TTL_SECONDS));

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $claims = array_merge($payload, [
            'iss' => 'pausas-activas-api',
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $now + $ttl,
        ]);

        $headerEncoded  = self::base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $signingInput = $headerEncoded . '.' . $payloadEncoded;
        $signature    = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Valida firma HMAC-SHA256 en tiempo constante y expiración (`exp` / `nbf`).
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token): ?array
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        $parts = explode('.', $trimmed);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        if ($headerB64 === '' || $payloadB64 === '' || $sigB64 === '') {
            return null;
        }

        $headerRaw  = self::base64UrlDecode($headerB64);
        $payloadRaw = self::base64UrlDecode($payloadB64);
        $sigRaw     = self::base64UrlDecode($sigB64);

        if ($headerRaw === null || $payloadRaw === null || $sigRaw === null) {
            return null;
        }

        $header = json_decode($headerRaw, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        try {
            $secret = self::resolveSecret();
        } catch (RuntimeException $e) {
            error_log('[JwtService Error] ' . $e->getMessage());
            return null;
        }

        $expectedSig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        if (!hash_equals($expectedSig, $sigRaw)) {
            return null;
        }

        $claims = json_decode($payloadRaw, true);
        if (!is_array($claims)) {
            return null;
        }

        $now = time();
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int)$claims['exp'] <= $now) {
            return null;
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int)$claims['nbf'] > ($now + 30)) {
            return null;
        }

        return $claims;
    }

    /**
     * Genera un JWT estandarizado para el usuario autenticado.
     *
     * @param array<string, mixed> $usuario
     */
    public static function issueForUser(array $usuario, int $ttlSeconds = 0): string
    {
        return self::encode([
            'sub'              => (int)($usuario['id'] ?? 0),
            'nombre'           => (string)($usuario['nombre'] ?? 'Usuario'),
            'email'            => (string)($usuario['email'] ?? ''),
            'rol'              => (string)($usuario['rol'] ?? 'usuario'),
            'ocupacion'        => (string)($usuario['ocupacion'] ?? 'estudiante'),
            'email_verificado' => (bool)($usuario['email_verificado'] ?? false),
        ], $ttlSeconds);
    }

    private static function resolveSecret(): string
    {
        Secrets::load();

        $candidates = [
            Secrets::get('JWT_SECRET', ''),
            Secrets::get('APP_KEY', ''),
            Secrets::get('MASTER_ENCRYPTION_KEY', ''),
        ];

        foreach ($candidates as $candidate) {
            $clean = trim($candidate);
            if ($clean !== '' && strlen($clean) >= 16) {
                return $clean;
            }
        }

        if (Secrets::isProduction()) {
            throw new RuntimeException(
                'JWT_SECRET (o APP_KEY / MASTER_ENCRYPTION_KEY) debe configurarse con al menos 16 caracteres en producción.'
            );
        }

        // Respaldo determinista exclusivamente para desarrollo local si aún no se definió JWT_SECRET en .env
        return hash('sha256', __DIR__ . '|' . php_uname('n') . '|pai-dev-jwt-key');
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
