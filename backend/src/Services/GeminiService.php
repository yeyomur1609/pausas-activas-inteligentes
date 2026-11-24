<?php
// ARCHIVO: backend/src/Services/GeminiService.php
// Cliente HTTP directo hacia Google Gemini REST API (generativelanguage.googleapis.com) sin respaldos ni mocks locales.
declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;

final class GeminiService
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Modelos activos de respaldo en la misma API oficial de Google Gemini
     * usados únicamente si el modelo configurado devuelve HTTP 404 (modelo retirado para la llave).
     */
    private const LIVE_GEMINI_MODELS_ON_404 = [
        'gemini-3.5-flash-lite',
        'gemini-3.8-flash',
        'gemini-flash-lite-latest',
        'gemini-3.6-flash',
    ];

    public static function obtenerSystemPrompt(?string $parqueContexto = null): string
    {
        $contextoParque = ($parqueContexto !== null && trim($parqueContexto) !== '')
            ? ' El usuario tiene seleccionado el parque "' . trim($parqueContexto) . '" en Santa Catarina, Nuevo León; adapta tus ejercicios a su infraestructura (bancas, andadores, ejercitadores, sombra o zonas accesibles) cuando sea pertinente.'
            : ' Si el usuario pregunta por activación física al aire libre, sugiere aprovechar los parques públicos de Santa Catarina, Nuevo León.';

        return 'Eres ActiBot, asistente experto en ergonomía laboral y estudiantil, pausas activas saludables, '
            . 'accesibilidad universal e inclusión para personas usuarias de silla de ruedas y activación física en '
            . 'parques públicos de Santa Catarina, Nuevo León (México).'
            . ' Responde siempre en español claro, práctico y empático (máximo 180 palabras), con pasos accionables y seguros.'
            . ' Incluye siempre adaptaciones o variantes para realizarse sentado o en silla de ruedas cuando des rutinas físicas.'
            . ' No realices diagnósticos médicos ni prescribas fármacos; recomienda suspender ante dolor agudo o mareo.'
            . $contextoParque;
    }

    /**
     * Envía la consulta y los últimos 6 mensajes del historial directamente a Google Gemini API.
     *
     * @param array<int, array<string, mixed>> $historialReciente
     * @return array<string, mixed>
     */
    public static function responder(
        string $mensajeUsuario,
        array $historialReciente = [],
        ?string $parqueContexto = null
    ): array {
        $apiKey = trim(Secrets::get('GEMINI_API_KEY'));
        $modeloConfigurado = trim(Secrets::get('GEMINI_MODEL', 'gemini-3.8-flash'));
        if ($modeloConfigurado === '') {
            $modeloConfigurado = 'gemini-3.8-flash';
        }

        $mensajeLimpio = trim(mb_substr($mensajeUsuario, 0, 500, 'UTF-8'));
        if ($mensajeLimpio === '') {
            return [
                'ok'          => false,
                'http_status' => 400,
                'codigo'      => 'MENSAJE_VACIO',
                'error'       => 'El mensaje no puede estar vacío.',
            ];
        }

        if ($apiKey === '' || str_contains($apiKey, 'TU_')) {
            error_log('[GeminiService Error] GEMINI_API_KEY no configurada o inválida.');
            return [
                'ok'          => false,
                'http_status' => 503,
                'codigo'      => 'GEMINI_KEY_MISSING',
                'error'       => 'El servicio de asistente IA no está configurado temporalmente.',
            ];
        }

        if (!function_exists('curl_init')) {
            error_log('[GeminiService Error] Extensión cURL no disponible.');
            return [
                'ok'          => false,
                'http_status' => 503,
                'codigo'      => 'CURL_UNAVAILABLE',
                'error'       => 'El servicio de asistente IA no está disponible en este momento.',
            ];
        }

        $contents = self::construirContents($historialReciente, $mensajeLimpio);
        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => self::obtenerSystemPrompt($parqueContexto)],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => 0.55,
                'maxOutputTokens' => 650,
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return [
                'ok'          => false,
                'http_status' => 500,
                'codigo'      => 'JSON_ENCODE_ERROR',
                'error'       => 'No se pudo codificar la petición para Google Gemini API.',
            ];
        }

        $modelosIntentar = [$modeloConfigurado];
        $ultimoFallo = null;

        for ($i = 0; $i < count($modelosIntentar); $i++) {
            $modeloActual = $modelosIntentar[$i];
            $resp = self::ejecutarGenerateContent($modeloActual, $apiKey, $payloadJson);

            if ($resp['ok']) {
                return [
                    'ok'          => true,
                    'http_status' => 200,
                    'respuesta'   => $resp['texto'],
                    'origen'      => 'gemini_api',
                    'modelo'      => $modeloActual,
                    'sugerencias' => ['Ver rutinas recomendadas', 'Buscar parque cercano', 'Modo silla de ruedas'],
                ];
            }

            $ultimoFallo = $resp;
            $status = (int)($resp['http_status'] ?? 0);

            // Si el modelo configurado responde 404 (retirado para la llave), o si un modelo activo
            // sufre saturación temporal (503/429) o timeout (0), intentar siguiente modelo en vivo de Gemini API.
            $esTransitorioO404 = ($status === 404 || $status === 503 || $status === 429 || $status === 0);
            if ($esTransitorioO404 && $i === 0) {
                foreach (self::LIVE_GEMINI_MODELS_ON_404 as $candidato) {
                    if ($candidato !== $modeloActual && !in_array($candidato, $modelosIntentar, true)) {
                        $modelosIntentar[] = $candidato;
                    }
                }
                continue;
            }

            // En errores de autenticación/argumentos (400, 401, 403), abortar de inmediato sin intentar otros modelos
            if (!$esTransitorioO404) {
                break;
            }
        }

        return [
            'ok'          => false,
            'http_status' => (int)($ultimoFallo['upstream_http_status'] ?? 502),
            'codigo'      => (string)($ultimoFallo['codigo'] ?? 'GEMINI_API_ERROR'),
            'error'       => (string)($ultimoFallo['error'] ?? 'No se pudo obtener respuesta de Google Gemini API.'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $historialReciente
     * @return array<int, array<string, mixed>>
     */
    private static function construirContents(array $historialReciente, string $mensajeLimpio): array
    {
        $contents = [];
        foreach (array_slice($historialReciente, -6) as $turno) {
            if (!is_array($turno)) {
                continue;
            }
            $rawRole = strtolower(trim((string)($turno['role'] ?? 'user')));
            $role = ($rawRole === 'model' || $rawRole === 'assistant') ? 'model' : 'user';
            $texto = trim((string)($turno['text'] ?? $turno['content'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => mb_substr($texto, 0, 700, 'UTF-8')]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $mensajeLimpio]],
        ];

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private static function ejecutarGenerateContent(string $modelo, string $apiKey, string $payloadJson): array
    {
        $endpoint = self::API_BASE . rawurlencode($modelo) . ':generateContent';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
                'Expect:',
            ],
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErrno !== 0) {
            error_log('[GeminiService Network Error] modelo=' . $modelo . ' errno=' . $curlErrno . ' err=' . $curlError);
            return [
                'ok'                   => false,
                'http_status'          => 0,
                'upstream_http_status' => 502,
                'codigo'               => 'GEMINI_NETWORK_ERROR',
                'error'                => 'No se pudo establecer conexión con el asistente IA. Intenta nuevamente en unos segundos.',
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            error_log('[GeminiService Invalid JSON] modelo=' . $modelo . ' http=' . $httpCode);
            return [
                'ok'                   => false,
                'http_status'          => $httpCode,
                'upstream_http_status' => 502,
                'codigo'               => 'GEMINI_INVALID_JSON',
                'error'                => 'El asistente IA devolvió una respuesta inesperada. Intenta nuevamente.',
            ];
        }

        if ($httpCode !== 200) {
            $msg = trim((string)($decoded['error']['message'] ?? ''));
            error_log('[GeminiService HTTP Error] modelo=' . $modelo . ' http=' . $httpCode . ($msg !== '' ? ' msg=' . $msg : ''));
            return [
                'ok'                   => false,
                'http_status'          => $httpCode,
                'upstream_http_status' => $httpCode === 429 ? 429 : 502,
                'codigo'               => 'GEMINI_HTTP_' . $httpCode,
                'error'                => $httpCode === 429
                    ? 'El asistente IA alcanzó su límite temporal de consultas. Espera unos segundos e intenta nuevamente.'
                    : 'El asistente IA no pudo responder en este momento. Intenta nuevamente en unos segundos.',
            ];
        }

        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        $textos = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $textos[] = $part['text'];
                }
            }
        }
        $textoFinal = trim(implode("\n", $textos));

        if ($textoFinal === '') {
            $finishReason = (string)($decoded['candidates'][0]['finishReason'] ?? 'SIN_CANDIDATOS');
            return [
                'ok'                   => false,
                'http_status'          => 502,
                'upstream_http_status' => 502,
                'codigo'               => 'GEMINI_EMPTY_CANDIDATE',
                'error'                => 'Google Gemini API no generó contenido usable (' . $finishReason . ').',
            ];
        }

        return [
            'ok'    => true,
            'texto' => $textoFinal,
        ];
    }
}
