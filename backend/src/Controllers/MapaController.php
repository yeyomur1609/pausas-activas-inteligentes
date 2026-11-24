<?php
// ARCHIVO: backend/src/Controllers/MapaController.php
// Controlador del mapa de parques públicos (proxy con caché de 24h a Overpass/Nominatim, rutas peatonales y favoritos).
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\HistorialModel;
use App\Models\ParqueFavoritoModel;
use App\Services\OsmCacheService;

final class MapaController
{
    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body   = self::readJsonBody();
        $accion = (string)($_GET['accion'] ?? $body['accion'] ?? 'parques_cercanos');

        // 1. Operaciones de lectura (GET): admiten exploración sin registro (Onboarding Progresivo)
        if ($method === 'GET') {
            $sessionUser = AuthMiddleware::requireAuth();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            RateLimitMiddleware::check('mapa_get', 60, 60, (string)$sessionUser['id']);

            if ($accion === 'buscar_lugar') {
                $q = trim((string)($_GET['q'] ?? ''));
                $lugares = OsmCacheService::buscarLugarNominatim($q);
                self::json(200, [
                    'ok'      => true,
                    'lugares' => $lugares,
                ]);
            }

            if ($accion === 'ruta_peatonal') {
                $origenLat  = (float)($_GET['origen_lat'] ?? 25.6760);
                $origenLng  = (float)($_GET['origen_lng'] ?? -100.4600);
                $destinoLat = (float)($_GET['destino_lat'] ?? 25.6768);
                $destinoLng = (float)($_GET['destino_lng'] ?? -100.4612);

                $ruta = OsmCacheService::calcularRutaPeatonal($origenLat, $origenLng, $destinoLat, $destinoLng);
                self::json(200, [
                    'ok'   => true,
                    'ruta' => $ruta,
                ]);
            }

            if ($accion === 'favoritos') {
                if ($sessionUser === null) {
                    self::json(200, [
                        'ok'          => true,
                        'autenticado' => false,
                        'favoritos'   => [],
                    ]);
                }
                self::json(200, [
                    'ok'          => true,
                    'autenticado' => true,
                    'favoritos'   => ParqueFavoritoModel::listarPorUsuario($sessionUser['id']),
                ]);
            }

            // Acción por defecto: parques_cercanos en tiempo real con Overpass API (radio ágil por defecto 3000 m, rango 1500..4500 m)
            $tInicio = microtime(true);
            $latCentro = 25.6768;
            $lngCentro = -100.4615;
            $radioMetros = isset($_GET['radio']) && is_numeric($_GET['radio']) ? (int)$_GET['radio'] : 3000;

            if (isset($_GET['lat'], $_GET['lng']) && is_numeric($_GET['lat']) && is_numeric($_GET['lng'])) {
                $latCentro = (float)$_GET['lat'];
                $lngCentro = (float)$_GET['lng'];
            } elseif (!empty($_GET['bbox'])) {
                $partes = explode(',', (string)$_GET['bbox']);
                if (count($partes) === 4) {
                    $south = (float)$partes[0];
                    $west  = (float)$partes[1];
                    $north = (float)$partes[2];
                    $east  = (float)$partes[3];
                    $latCentro = ($south + $north) / 2.0;
                    $lngCentro = ($west + $east) / 2.0;
                    if (!isset($_GET['radio'])) {
                        $deltaGrados = max(abs($north - $south), abs($east - $west));
                        $radioMetros = (int)round($deltaGrados * 55000);
                    }
                }
            }
            $radioMetros = max(1500, min(4500, $radioMetros));

            $filtros = [
                'accesibilidad_silla' => !empty($_GET['accesibilidad_silla']),
                'equipamiento'        => (string)($_GET['equipamiento'] ?? ''),
                'q'                   => (string)($_GET['q'] ?? ''),
            ];

            $resultado = OsmCacheService::consultarParquesPorRadio($latCentro, $lngCentro, $radioMetros, $filtros);
            $tiempoMs = (int)round((microtime(true) - $tInicio) * 1000);
            if (!headers_sent()) {
                header('X-Response-Time-Ms: ' . $tiempoMs);
            }

            if (empty($resultado['ok'])) {
                self::json((int)($resultado['http_status'] ?? 502), [
                    'ok'        => false,
                    'codigo'    => (string)($resultado['codigo'] ?? 'OVERPASS_API_ERROR'),
                    'error'     => (string)($resultado['error'] ?? 'No se pudieron obtener parques desde Overpass API.'),
                    'tiempo_ms' => $tiempoMs,
                    'parques'   => [],
                ]);
            }

            $parques = is_array($resultado['parques'] ?? null) ? $resultado['parques'] : [];
            $favoritosUsuario = $sessionUser !== null
                ? ParqueFavoritoModel::listarPorUsuario($sessionUser['id'])
                : [];

            self::json(200, [
                'ok'           => true,
                'fuente'       => (string)($resultado['fuente'] ?? 'overpass_api_live'),
                'fuente_cache' => (bool)($resultado['fuente_cache'] ?? false),
                'cache_stale'  => (bool)($resultado['cache_stale'] ?? false),
                'celda_cache'  => (string)($resultado['celda_cache'] ?? ''),
                'endpoint'     => (string)($resultado['endpoint'] ?? ''),
                'tiempo_ms'    => $tiempoMs,
                'centro'       => ['lat' => $latCentro, 'lng' => $lngCentro, 'radio' => $radioMetros],
                'parques'      => $parques,
                'favoritos'    => $favoritosUsuario,
                'total'        => count($parques),
            ]);
        }

        // 2. Operaciones de escritura (POST / DELETE): requieren sesión autenticada y CSRF
        if (!in_array($method, ['POST', 'DELETE'], true)) {
            self::json(405, ['ok' => false, 'error' => 'Método HTTP no permitido.']);
        }

        $sessionUser = AuthMiddleware::requireAuth();
        CsrfMiddleware::validateOrFail($body);
        RateLimitMiddleware::check('mapa_write', 30, 60, (string)$sessionUser['id']);

        switch ($accion) {
            case 'guardar_favorito':
                $guardado = ParqueFavoritoModel::guardarFavorito($sessionUser['id'], $body);
                self::json(201, [
                    'ok'        => true,
                    'mensaje'   => 'Parque público guardado en tus favoritos.',
                    'favorito'  => $guardado,
                    'favoritos' => ParqueFavoritoModel::listarPorUsuario($sessionUser['id']),
                ]);
                break;

            case 'eliminar_favorito':
                $favId = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if ($favId <= 0) {
                    self::json(400, ['ok' => false, 'error' => 'Identificador de parque favorito inválido.']);
                }
                $eliminado = ParqueFavoritoModel::eliminarFavorito(
                    $favId,
                    $sessionUser['id'],
                    $sessionUser['rol'] === 'admin'
                );
                if (!$eliminado) {
                    self::json(404, ['ok' => false, 'error' => 'No se encontró el parque favorito o no tienes permisos para eliminarlo.']);
                }
                self::json(200, [
                    'ok'        => true,
                    'mensaje'   => 'Parque eliminado de tus favoritos.',
                    'favoritos' => ParqueFavoritoModel::listarPorUsuario($sessionUser['id']),
                ]);
                break;

            case 'registrar_caminata':
                $registro = HistorialModel::registrarPausa($sessionUser['id'], [
                    'ubicacion_nombre'    => (string)($body['nombre_parque'] ?? 'Parque Público Santa Catarina'),
                    'parque_osm_id'       => (string)($body['parque_osm_id'] ?? ''),
                    'actividad_realizada' => 'parque',
                    'necesidad_atendida'  => 'despejarse',
                    'tiempo_minutos'      => max(3, min(90, (int)($body['minutos'] ?? 10))),
                    'esfuerzo'            => (int)($body['esfuerzo'] ?? 3),
                    'calificacion'        => (int)($body['calificacion'] ?? 5),
                ]);
                self::json(201, [
                    'ok'      => true,
                    'mensaje' => '¡Caminata y pausa al aire libre registrada en tu racha diaria!',
                    'racha'   => $registro,
                ]);
                break;

            default:
                self::json(400, ['ok' => false, 'error' => 'Acción de mapa no reconocida.']);
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
