<?php
// ARCHIVO: backend/src/Controllers/RutinasController.php
// Controlador del catálogo de rutinas, motor de recomendación, registro de pausas completadas y CRUD Admin.
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\HistorialModel;
use App\Models\RutinaModel;

final class RutinasController
{
    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body   = self::readJsonBody();
        $accion = (string)($_GET['accion'] ?? $body['accion'] ?? 'catalogo');

        // 1. Lectura de rutinas (GET): disponible sin login para Onboarding Progresivo (Pausa Express de 3-5 min)
        if ($method === 'GET') {
            $sessionUser = AuthMiddleware::requireAuth();
            $esAdmin = ($sessionUser['rol'] ?? 'usuario') === 'admin';
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            RateLimitMiddleware::check('rutinas_get', 60, 60, (string)$sessionUser['id']);

            if ($accion === 'detalle') {
                $id = (int)($_GET['id'] ?? 1);
                $rutina = RutinaModel::obtenerPorId($id);
                if ($rutina === null) {
                    self::json(404, ['ok' => false, 'error' => 'Rutina no encontrada.']);
                }
                self::json(200, ['ok' => true, 'rutina' => $rutina]);
            }

            if ($accion === 'recomendada') {
                $categoria   = (string)($_GET['categoria'] ?? $_GET['actividad'] ?? 'computadora');
                $necesidad   = (string)($_GET['necesidad'] ?? 'relajarse');
                $duracionMin = (int)($_GET['duracion_minutos'] ?? $_GET['tiempo'] ?? 3);
                $modoSentado = !empty($_GET['modo_sentado']);

                $rutina = RutinaModel::obtenerRecomendada($categoria, $necesidad, $duracionMin, $modoSentado);
                self::json(200, [
                    'ok'     => true,
                    'rutina' => $rutina,
                ]);
            }

            $filtros = [
                'categoria'               => $_GET['categoria'] ?? null,
                'necesidad'               => $_GET['necesidad'] ?? null,
                'nivel'                   => $_GET['nivel'] ?? null,
                'duracion_minutos'        => $_GET['duracion_minutos'] ?? null,
                'apta_movilidad_reducida' => !empty($_GET['apta_movilidad_reducida']),
            ];

            $incluirInactivas = $esAdmin && !empty($_GET['incluir_inactivas']);
            $rutinas = RutinaModel::listarCatalogo($filtros, $incluirInactivas);

            self::json(200, [
                'ok'      => true,
                'rutinas' => $rutinas,
                'total'   => count($rutinas),
            ]);
        }

        // 2. Escritura (POST / PUT / DELETE): requiere autenticación + CSRF
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            self::json(405, ['ok' => false, 'error' => 'Método HTTP no permitido.']);
        }

        $sessionUser = AuthMiddleware::requireAuth();
        CsrfMiddleware::validateOrFail($body);
        RateLimitMiddleware::check('rutinas_write', 25, 60, (string)$sessionUser['id']);

        if ($accion === 'completar_pausa') {
            $resultado = HistorialModel::registrarPausa($sessionUser['id'], $body);
            $estadisticas = HistorialModel::obtenerEstadisticasUsuario($sessionUser['id']);

            self::json(201, [
                'ok'           => true,
                'mensaje'      => '¡Pausa activa completada! Tu racha e historial han sido actualizados.',
                'resultado'    => $resultado,
                'estadisticas' => $estadisticas,
            ]);
        }

        // Acciones CRUD exclusivas para rol 'admin'
        AuthMiddleware::requireAdmin();

        switch ($accion) {
            case 'crear_rutina':
                $creada = RutinaModel::crearRutina($body);
                self::json(201, [
                    'ok'      => true,
                    'mensaje' => 'Nueva rutina ergonómica agregada al catálogo.',
                    'rutina'  => $creada,
                ]);
                break;

            case 'alternar_rutina':
                $id = (int)($body['id'] ?? 0);
                if ($id <= 0 || !RutinaModel::alternarEstadoRutina($id)) {
                    self::json(404, ['ok' => false, 'error' => 'No se encontró la rutina especificada.']);
                }
                self::json(200, [
                    'ok'      => true,
                    'mensaje' => 'Estado de la rutina actualizado.',
                ]);
                break;

            case 'eliminar_rutina':
                $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if ($id <= 0 || !RutinaModel::eliminarRutina($id)) {
                    self::json(404, ['ok' => false, 'error' => 'No se encontró la rutina para eliminar.']);
                }
                self::json(200, [
                    'ok'      => true,
                    'mensaje' => 'Rutina eliminada del catálogo.',
                ]);
                break;

            default:
                self::json(400, ['ok' => false, 'error' => 'Acción de rutinas no reconocida.']);
        }
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
