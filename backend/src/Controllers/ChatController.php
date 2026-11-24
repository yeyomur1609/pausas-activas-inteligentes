<?php
// ARCHIVO: backend/src/Controllers/ChatController.php
// Controlador de ActiBot: memoria de sesión acotada, límite RATE_LIMIT_CHATBOT_PER_MIN y llamadas a GeminiService.
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Secrets;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\GeminiService;

final class ChatController
{
    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            self::json(405, ['ok' => false, 'error' => 'Método no permitido. Usa POST.']);
        }

        $sessionUser = AuthMiddleware::requireAuth();
        $body = self::readJsonBody();
        CsrfMiddleware::validateOrFail($body);

        $accion = (string)($body['accion'] ?? 'mensaje');
        if ($accion === 'limpiar_historial') {
            Secrets::initSecureSession();
            $_SESSION['actibot_history'] = [];
            self::json(200, [
                'ok'      => true,
                'mensaje' => 'Conversación de ActiBot reiniciada.',
            ]);
        }

        $maxPorMin = max(1, Secrets::int('RATE_LIMIT_CHATBOT_PER_MIN', 10));
        $idExtra = (string)$sessionUser['id'];
        RateLimitMiddleware::check('chatbot_mensaje', $maxPorMin, 60, $idExtra);

        $mensaje = trim((string)($body['mensaje'] ?? ''));
        if ($mensaje === '') {
            self::json(400, [
                'ok'    => false,
                'error' => 'Escribe tu pregunta o la molestia física que deseas aliviar.',
            ]);
        }

        $parqueContexto = isset($body['parque_contexto']) ? trim(strip_tags((string)$body['parque_contexto'])) : null;

        $historialSesion = is_array($_SESSION['actibot_history'] ?? null) ? $_SESSION['actibot_history'] : [];
        $historial = array_slice($historialSesion, -6);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $resultado = GeminiService::responder($mensaje, $historial, $parqueContexto);
        if (empty($resultado['ok'])) {
            self::json((int)($resultado['http_status'] ?? 502), [
                'ok'     => false,
                'codigo' => (string)($resultado['codigo'] ?? 'GEMINI_API_ERROR'),
                'error'  => (string)($resultado['error'] ?? 'No se pudo obtener respuesta de Google Gemini API.'),
            ]);
        }

        // Guardar últimos 6 turnos en la sesión tras una respuesta válida de Gemini API
        Secrets::initSecureSession();
        $historialGuardar = is_array($_SESSION['actibot_history'] ?? null) ? $_SESSION['actibot_history'] : $historial;
        $historialGuardar[] = ['role' => 'user', 'text' => mb_substr($mensaje, 0, 450, 'UTF-8')];
        $historialGuardar[] = ['role' => 'model', 'text' => (string)$resultado['respuesta']];
        $_SESSION['actibot_history'] = array_slice($historialGuardar, -6);
        session_write_close();

        self::json(200, [
            'ok'          => true,
            'respuesta'   => $resultado['respuesta'],
            'origen'      => $resultado['origen'],
            'modelo'      => $resultado['modelo'] ?? null,
            'sugerencias' => $resultado['sugerencias'],
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
