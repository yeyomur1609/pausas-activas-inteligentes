<?php
// ARCHIVO: backend/src/Services/OsmCacheService.php
// Consultas en tiempo real a OpenStreetMap (Overpass API, Nominatim y OSRM) sin catálogos semilla estáticos.
declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;

final class OsmCacheService
{
    /**
     * Endpoints públicos de Overpass API (primario oficial + espejos activos con fallback paralelo rápido).
     */
    private const OVERPASS_ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://overpass.openstreetmap.fr/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
    ];

    /**
     * Paso de cuadrícula espacial (~550 m) para reutilizar caché sin recalcular por micro-desplazamientos.
     */
    private const CACHE_GRID_STEP_DEG = 0.005;
    private const STALE_CACHE_MAX_AGE_SECONDS = 604800; // 7 días de respaldo stale-if-error
    private const DEFAULT_RADIUS_METERS = 3000;
    private const MIN_RADIUS_METERS = 1500;
    private const MAX_RADIUS_METERS = 4500;

    private static function cacheDir(): string
    {
        $primary = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($primary)) {
            @mkdir($primary, 0775, true);
        }
        if (is_dir($primary) && is_writable($primary)) {
            return $primary;
        }

        // Respaldo seguro en almacenamiento efímero /tmp si storage/cache no es escribible
        $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/pai_osm_cache';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        return $fallback;
    }

    private static function liveCacheTtlSeconds(): int
    {
        // Caché breve solo de respuestas reales de Overpass para evitar saturación al mover el marcador
        return max(30, min(3600, Secrets::int('OSM_CACHE_TTL_SECONDS', 300)));
    }

    /**
     * Lee cualquier archivo JSON de caché de OSM con bloqueo compartido si no ha expirado.
     *
     * @return mixed
     */
    private static function readJsonCacheFile(string $archivoCache, int $maxAgeSeconds)
    {
        if (!is_file($archivoCache) || (time() - (int)@filemtime($archivoCache)) >= $maxAgeSeconds) {
            return null;
        }
        $fp = @fopen($archivoCache, 'rb');
        if ($fp === false) {
            return null;
        }
        $raw = false;
        if (@flock($fp, LOCK_SH)) {
            $raw = stream_get_contents($fp);
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /**
     * Escribe un archivo JSON en caché de forma atómica (archivo temporal + rename).
     *
     * @param mixed $data
     */
    private static function writeJsonCacheAtomic(string $archivoCache, $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return;
        }
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            $suffix = uniqid('', true);
        }
        $tmpFile = $archivoCache . '.' . $suffix . '.tmp';
        if (@file_put_contents($tmpFile, $payload, LOCK_EX) !== false) {
            if (!@rename($tmpFile, $archivoCache)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Lee un archivo de caché real de Overpass si existe y su antigüedad no supera $maxAgeSeconds.
     *
     * @return ?array{parques: array<int, mixed>, endpoint: string}
     */
    private static function readCachedOverpassFile(string $archivoCache, int $maxAgeSeconds): ?array
    {
        $dec = self::readJsonCacheFile($archivoCache, $maxAgeSeconds);
        if (!is_array($dec) || empty($dec['parques']) || !is_array($dec['parques'])) {
            return null;
        }
        return [
            'parques'  => $dec['parques'],
            'endpoint' => (string)($dec['endpoint'] ?? self::OVERPASS_ENDPOINTS[0]),
        ];
    }

    private static function userAgent(): string
    {
        return Secrets::get(
            'OSM_USER_AGENT',
            'PausasActivasInteligentes/3.0 (https://pausasactivas.edu.mx; soporte@pausasactivas.edu.mx)'
        );
    }

    /**
     * Consulta en tiempo real a OpenStreetMap Overpass API por latitud, longitud y radio dinámico (m).
     * Calcula distancias reales con Haversine, detecta equipamiento y accesibilidad wheelchair,
     * y ordena estrictamente de menor a mayor distancia respecto a ($lat, $lng).
     *
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    public static function consultarParquesPorRadio(
        float $lat,
        float $lng,
        int $radioMetros = self::DEFAULT_RADIUS_METERS,
        array $filtros = []
    ): array {
        $lat = max(-89.9, min(89.9, $lat));
        $lng = max(-180.0, min(180.0, $lng));
        $radioMetros = max(self::MIN_RADIUS_METERS, min(self::MAX_RADIUS_METERS, $radioMetros));

        if (!function_exists('curl_init')) {
            return [
                'ok'          => false,
                'http_status' => 500,
                'codigo'      => 'CURL_UNAVAILABLE',
                'error'       => 'La extensión cURL de PHP no está disponible para consultar Overpass API.',
                'parques'     => [],
            ];
        }

        // Cuadrícula espacial consistente (~550 m) + cubeta de radio (500 m) para máximo acierto de caché
        $gridLat = round(round($lat / self::CACHE_GRID_STEP_DEG) * self::CACHE_GRID_STEP_DEG, 4);
        $gridLng = round(round($lng / self::CACHE_GRID_STEP_DEG) * self::CACHE_GRID_STEP_DEG, 4);
        $radioBucket = max(
            self::MIN_RADIUS_METERS,
            min(self::MAX_RADIUS_METERS, (int)(round($radioMetros / 500) * 500))
        );
        $clave = sprintf('overpass_v3_%.4f_%.4f_%d', $gridLat, $gridLng, $radioBucket);
        $archivoCache = self::cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $clave) . '.json';

        $parquesBase = null;
        $desdeCache = false;
        $esStale = false;
        $endpointUsado = self::OVERPASS_ENDPOINTS[0];

        $freshCache = self::readCachedOverpassFile($archivoCache, self::liveCacheTtlSeconds());
        if ($freshCache !== null) {
            $parquesBase = $freshCache['parques'];
            $endpointUsado = $freshCache['endpoint'];
            $desdeCache = true;
        }

        if ($parquesBase === null) {
            // Consulta centrada en la celda con margen de 350 m para cubrir todo punto dentro de la cuadrícula
            $radioConsulta = min(self::MAX_RADIUS_METERS, $radioBucket + 350);
            $live = self::consultarOverpassAround($gridLat, $gridLng, $radioConsulta);
            if (!$live['ok']) {
                // Resiliencia stale-if-error: si Overpass falla temporalmente, devuelve caché real previa (< 7 días)
                $staleCache = self::readCachedOverpassFile($archivoCache, self::STALE_CACHE_MAX_AGE_SECONDS);
                if ($staleCache !== null) {
                    $parquesBase = $staleCache['parques'];
                    $endpointUsado = $staleCache['endpoint'];
                    $desdeCache = true;
                    $esStale = true;
                } else {
                    return [
                        'ok'          => false,
                        'http_status' => (int)($live['http_status'] ?? 502),
                        'codigo'      => (string)($live['codigo'] ?? 'OVERPASS_API_ERROR'),
                        'error'       => (string)($live['error'] ?? 'No se pudo consultar OpenStreetMap Overpass API.'),
                        'parques'     => [],
                    ];
                }
            } else {
                $parquesBase = $live['parques'];
                $endpointUsado = (string)$live['endpoint'];

                if (!empty($parquesBase)) {
                    self::writeJsonCacheAtomic($archivoCache, [
                        'actualizado_en' => date('c'),
                        'endpoint'       => $endpointUsado,
                        'lat'            => $gridLat,
                        'lng'            => $gridLng,
                        'radio'          => $radioBucket,
                        'parques'        => $parquesBase,
                    ]);
                }
            }
        }

        // Recalcular distancia exacta Haversine desde el punto actual del usuario y ordenar por cercanía
        $conDistancia = [];
        foreach ($parquesBase as $parque) {
            if (!is_array($parque) || !isset($parque['lat'], $parque['lng'])) {
                continue;
            }
            $pLat = (float)$parque['lat'];
            $pLng = (float)$parque['lng'];
            $distM = (int)round(self::distanciaHaversine($lat, $lng, $pLat, $pLng));
            if ($distM > ($radioMetros + 250)) {
                continue;
            }

            $parque['distancia_m'] = $distM;
            $parque['distancia_metros'] = $distM;
            $conDistancia[] = $parque;
        }

        usort($conDistancia, static function (array $a, array $b): int {
            return ((int)($a['distancia_metros'] ?? 0)) <=> ((int)($b['distancia_metros'] ?? 0));
        });

        $filtrados = self::filtrarParques($conDistancia, $filtros);

        return [
            'ok'           => true,
            'http_status'  => 200,
            'fuente'       => $esStale ? 'overpass_stale_cache' : ($desdeCache ? 'overpass_cache' : 'overpass_api_live'),
            'endpoint'     => $endpointUsado,
            'fuente_cache' => $desdeCache,
            'cache_stale'  => $esStale,
            'celda_cache'  => sprintf('%.4f,%.4f', $gridLat, $gridLng),
            'parques'      => $filtrados,
        ];
    }

    /**
     * Envoltorio compatible si algún cliente invoca consulta por BBox; convierte a centro + radio Overpass en vivo.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function obtenerParquesPublicos(
        float $south,
        float $west,
        float $north,
        float $east,
        array $filtros = []
    ): array {
        $latCentro = ($south + $north) / 2.0;
        $lngCentro = ($west + $east) / 2.0;
        $radio = (int)round(self::distanciaHaversine($latCentro, $lngCentro, $north, $east));
        $res = self::consultarParquesPorRadio(
            $latCentro,
            $lngCentro,
            max(self::MIN_RADIUS_METERS, min(self::MAX_RADIUS_METERS, $radio)),
            $filtros
        );
        return is_array($res['parques'] ?? null) ? $res['parques'] : [];
    }

    /**
     * Busca direcciones o colonias mediante Nominatim con caché en disco.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buscarLugarNominatim(string $query): array
    {
        $q = trim($query);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return [];
        }

        $qCorta = mb_substr($q, 0, 120, 'UTF-8');
        $hashCache = 'nominatim_' . hash('sha256', mb_strtolower($qCorta, 'UTF-8'));
        $archivoCache = self::cacheDir() . '/' . $hashCache . '.json';

        $dec = self::readJsonCacheFile($archivoCache, 86400);
        if (is_array($dec)) {
            return $dec;
        }

        $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=6&countrycodes=mx&q=' . rawurlencode($qCorta);
        $respuesta = self::httpRequest($url, null, 8);
        $datos = ($respuesta['ok'] && is_string($respuesta['body'])) ? json_decode($respuesta['body'], true) : null;

        $lugares = [];
        if (is_array($datos)) {
            foreach ($datos as $item) {
                if (!is_array($item) || !isset($item['lat'], $item['lon'])) {
                    continue;
                }
                $displayName = (string)($item['display_name'] ?? 'Ubicación');
                $partesNombre = explode(',', $displayName);
                $lugares[] = [
                    'nombre'    => trim($partesNombre[0] ?? $displayName),
                    'direccion' => $displayName,
                    'lat'       => (float)$item['lat'],
                    'lng'       => (float)$item['lon'],
                ];
            }
        }

        if (!empty($lugares)) {
            self::writeJsonCacheAtomic($archivoCache, $lugares);
        }

        return $lugares;
    }

    /**
     * Calcula ruta peatonal real mediante OSRM (router.project-osrm.org) con geometría Haversine si OSRM no responde.
     *
     * @return array<string, mixed>
     */
    public static function calcularRutaPeatonal(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $lat1 = max(-89.9, min(89.9, $lat1));
        $lng1 = max(-180.0, min(180.0, $lng1));
        $lat2 = max(-89.9, min(89.9, $lat2));
        $lng2 = max(-180.0, min(180.0, $lng2));

        $osrmUrl = sprintf(
            'https://router.project-osrm.org/route/v1/foot/%.6F,%.6F;%.6F,%.6F?overview=full&geometries=geojson',
            $lng1,
            $lat1,
            $lng2,
            $lat2
        );
        $osrmResp = self::httpRequest($osrmUrl, null, 6);
        if ($osrmResp['ok'] && is_string($osrmResp['body'])) {
            $osrmJson = json_decode($osrmResp['body'], true);
            $ruta0 = $osrmJson['routes'][0] ?? null;
            $rawCoords = $ruta0['geometry']['coordinates'] ?? null;
            if (is_array($ruta0) && is_array($rawCoords) && count($rawCoords) >= 2) {
                $coordsLatLng = [];
                foreach ($rawCoords as $pt) {
                    if (is_array($pt) && count($pt) >= 2) {
                        $coordsLatLng[] = [(float)$pt[1], (float)$pt[0]];
                    }
                }
                if (count($coordsLatLng) >= 2) {
                    $distM = (int)round((float)($ruta0['distance'] ?? self::distanciaHaversine($lat1, $lng1, $lat2, $lng2)));
                    $tiempoMin = max(1, (int)ceil(((float)($ruta0['duration'] ?? ($distM / 1.2))) / 60));
                    return [
                        'distancia_metros' => $distM,
                        'tiempo_minutos'   => $tiempoMin,
                        'coordenadas'      => $coordsLatLng,
                        'fuente'           => 'osrm_foot',
                    ];
                }
            }
        }

        $distanciaLineal = self::distanciaHaversine($lat1, $lng1, $lat2, $lng2);
        $distanciaPeatonal = (int)round($distanciaLineal * 1.25);
        $tiempoMinutos = max(1, (int)ceil($distanciaPeatonal / 72));

        return [
            'distancia_metros' => $distanciaPeatonal,
            'tiempo_minutos'   => $tiempoMinutos,
            'coordenadas'      => [
                [$lat1, $lng1],
                [$lat2, $lng2],
            ],
            'fuente'           => 'haversine_estimado',
        ];
    }

    /**
     * Ejecuta Overpass QL con radio dinámico buscando parques, zonas recreativas, equipamiento y wheelchair.
     *
     * @return array<string, mixed>
     */
    private static function consultarOverpassAround(float $lat, float $lng, int $radioMetros): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $query = sprintf(
            '[out:json][timeout:10];('
            . 'nwr["leisure"="park"](around:%d,%.5F,%.5F);'
            . 'nwr["landuse"="recreation_ground"](around:%d,%.5F,%.5F);'
            . 'nwr["leisure"="recreation_ground"](around:%d,%.5F,%.5F);'
            . 'nwr["leisure"="fitness_station"](around:%d,%.5F,%.5F);'
            . ');out center tags qt;',
            $radioMetros,
            $lat,
            $lng,
            $radioMetros,
            $lat,
            $lng,
            $radioMetros,
            $lat,
            $lng,
            $radioMetros,
            $lat,
            $lng
        );

        $carrera = self::raceOverpassMirrors(self::OVERPASS_ENDPOINTS, 'data=' . rawurlencode($query), 11);
        if (!empty($carrera['ok'])) {
            return [
                'ok'       => true,
                'endpoint' => (string)$carrera['endpoint'],
                'parques'  => $carrera['parques'],
            ];
        }

        $errores = $carrera['errores'] ?? [];
        return [
            'ok'          => false,
            'http_status' => 502,
            'codigo'      => 'OVERPASS_API_UNAVAILABLE',
            'error'       => 'No se pudo consultar OpenStreetMap (Overpass API) en este momento. Detalle: ' . implode(' | ', array_slice($errores, 0, 3)),
        ];
    }

    /**
     * Consulta múltiples espejos de Overpass en paralelo y devuelve el primer JSON válido sin agotar el tiempo de PHP.
     *
     * @param array<int, string> $endpoints
     * @return array<string, mixed>
     */
    private static function raceOverpassMirrors(array $endpoints, string $postBody, int $timeout): array
    {
        if (!function_exists('curl_multi_init')) {
            return [
                'ok'      => false,
                'errores' => ['curl_multi_init no disponible en PHP'],
            ];
        }

        $mh = curl_multi_init();
        $handles = [];
        $errores = [];

        foreach ($endpoints as $ep) {
            $ch = self::createCurlHandle($ep, $postBody, $timeout);
            if ($ch === null) {
                $errores[] = $ep . ': curl_init fallo';
                continue;
            }
            curl_multi_add_handle($mh, $ch);
            $key = is_object($ch) ? spl_object_id($ch) : (int)$ch;
            $handles[$key] = ['ch' => $ch, 'endpoint' => $ep];
        }

        $ganador = null;
        $running = 0;

        do {
            $status = curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = is_object($ch) ? spl_object_id($ch) : (int)$ch;
                $ep = $handles[$key]['endpoint'] ?? '';
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errno = curl_errno($ch);
                $err = curl_error($ch);
                $multiResult = (int)($info['result'] ?? CURLE_OK);

                if ($multiResult === CURLE_OK && $errno === 0 && $code === 200 && is_string($body) && $body !== '') {
                    $json = json_decode($body, true);
                    $remark = is_array($json) && isset($json['remark']) ? strtolower(trim((string)$json['remark'])) : '';
                    $remarkHasError = $remark !== '' && (str_contains($remark, 'error') || str_contains($remark, 'timeout') || str_contains($remark, 'timed out'));

                    if (
                        is_array($json) &&
                        isset($json['elements']) &&
                        is_array($json['elements']) &&
                        !$remarkHasError &&
                        ($remark === '' || !empty($json['elements']))
                    ) {
                        $ganador = [
                            'ok'       => true,
                            'endpoint' => $ep,
                            'parques'  => self::normalizarElementosOverpass($json['elements']),
                        ];
                        break 2;
                    }
                    $errores[] = $ep . ($remark !== '' ? (': ' . (string)$json['remark']) : ': respuesta JSON sin campo elements');
                } else {
                    $detailErr = ($errno !== 0 || $multiResult !== CURLE_OK)
                        ? ($err !== '' ? $err : ('cURL #' . ($errno !== 0 ? $errno : $multiResult)))
                        : ('HTTP ' . $code);
                    $errores[] = $ep . ': ' . $detailErr;
                }
            }

            if ($running > 0 && $status === CURLM_OK) {
                if (curl_multi_select($mh, 0.25) === -1) {
                    usleep(10000);
                }
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $entry) {
            curl_multi_remove_handle($mh, $entry['ch']);
            curl_close($entry['ch']);
        }
        curl_multi_close($mh);

        if ($ganador !== null) {
            return $ganador;
        }

        return [
            'ok'      => false,
            'errores' => $errores,
        ];
    }

    /**
     * Separa parques/áreas recreativas de nodos de equipamiento cercano y enriquece cada parque con tags OSM.
     *
     * @param array<int, mixed> $elements
     * @return array<int, array<string, mixed>>
     */
    private static function normalizarElementosOverpass(array $elements): array
    {
        $parquesCrudos = [];
        $amenidadesCercanas = [];

        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];

            $coords = self::extraerCoordenadasElemento($el);
            if ($coords === null) {
                continue;
            }
            [$elLat, $elLng] = $coords;

            $leisure = strtolower(trim((string)($tags['leisure'] ?? '')));
            $landuse = strtolower(trim((string)($tags['landuse'] ?? '')));
            $amenity = strtolower(trim((string)($tags['amenity'] ?? '')));
            $access  = strtolower(trim((string)($tags['access'] ?? '')));

            if ($access === 'private' || $access === 'no') {
                continue;
            }

            if ($leisure === 'fitness_station' || $amenity === 'bench') {
                $amenidadesCercanas[] = [
                    'lat'     => $elLat,
                    'lng'     => $elLng,
                    'leisure' => $leisure,
                    'amenity' => $amenity,
                    'sport'   => strtolower((string)($tags['sport'] ?? '')),
                ];
            }

            $esParque = $leisure === 'park' || $landuse === 'recreation_ground' || $leisure === 'recreation_ground';
            if (!$esParque) {
                continue;
            }

            $osmType = (string)($el['type'] ?? 'node');
            $osmNum  = (string)($el['id'] ?? '');
            $osmId   = 'osm_' . $osmType . '_' . ($osmNum !== '' ? $osmNum : bin2hex(random_bytes(4)));

            $parquesCrudos[$osmId] = [
                'osm_id'  => $osmId,
                'osm_num' => $osmNum,
                'lat'     => $elLat,
                'lng'     => $elLng,
                'tags'    => $tags,
                'leisure' => $leisure,
                'landuse' => $landuse,
            ];
        }

        $resultado = [];
        foreach ($parquesCrudos as $osmId => $item) {
            $tags = $item['tags'];
            $pLat = (float)$item['lat'];
            $pLng = (float)$item['lng'];

            $nombreRaw = trim((string)($tags['name:es'] ?? $tags['name'] ?? $tags['official_name'] ?? ''));
            if ($nombreRaw === '') {
                $prefijo = $item['landuse'] === 'recreation_ground' || $item['leisure'] === 'recreation_ground'
                    ? 'Zona Recreativa / Área Verde'
                    : 'Parque Público / Área Verde';
                $nombre = $prefijo . ($item['osm_num'] !== '' ? ' (OSM #' . $item['osm_num'] . ')' : '');
            } else {
                $nombre = $nombreRaw;
            }

            $wheelchairRaw = strtolower(trim((string)($tags['wheelchair'] ?? '')));
            $rampaRaw = strtolower(trim((string)($tags['ramp:wheelchair'] ?? $tags['ramp'] ?? '')));
            $accesibleSilla = in_array($wheelchairRaw, ['yes', 'designated', 'limited'], true)
                || $rampaRaw === 'yes';

            $equipamiento = self::detectarEquipamientoOsm($tags, $nombre, $pLat, $pLng, $amenidadesCercanas, $accesibleSilla);

            $partesDir = array_filter([
                trim((string)($tags['addr:street'] ?? '')),
                trim((string)($tags['addr:housenumber'] ?? '')),
                trim((string)($tags['addr:suburb'] ?? $tags['addr:neighbourhood'] ?? '')),
                trim((string)($tags['addr:city'] ?? '')),
            ], static fn (string $v): bool => $v !== '');

            $direccion = !empty($partesDir)
                ? implode(', ', $partesDir)
                : 'Área pública en OpenStreetMap (' . number_format($pLat, 5) . ', ' . number_format($pLng, 5) . ')';

            $esRecreativa = $item['landuse'] === 'recreation_ground' || $item['leisure'] === 'recreation_ground';

            $tieneSombra = in_array(strtolower((string)($tags['trees'] ?? '')), ['yes', 'designated'], true)
                || strtolower((string)($tags['natural'] ?? '')) === 'wood'
                || str_contains(mb_strtolower((string)($tags['description'] ?? ''), 'UTF-8'), 'sombra')
                || str_contains(mb_strtolower((string)($tags['description'] ?? ''), 'UTF-8'), 'árbol')
                || str_contains(mb_strtolower((string)($tags['description'] ?? ''), 'UTF-8'), 'arbol');

            $resultado[] = [
                'id'                     => $osmId,
                'parque_osm_id'          => $osmId,
                'nombre'                 => $nombre,
                'lat'                    => round($pLat, 6),
                'lng'                    => round($pLng, 6),
                'latitud'                => round($pLat, 6),
                'longitud'               => round($pLng, 6),
                'direccion'              => $direccion,
                'categoria'              => $esRecreativa ? '🏋️ Zona Recreativa / Área Verde' : '🌳 Parque Público',
                'equipamiento'           => $equipamiento,
                'wheelchair'             => $wheelchairRaw !== '' ? $wheelchairRaw : 'no_especificado',
                'accesibilidad_silla'    => $accesibleSilla,
                'accesible_silla_ruedas' => $accesibleSilla,
                'iluminacion'            => strtolower((string)($tags['lit'] ?? '')) === 'yes',
                'sombra_natural'         => $tieneSombra,
                'rutina_sugerida_id'     => 3,
            ];
        }

        return $resultado;
    }

    /**
     * @param array<string, mixed> $el
     * @return array{0: float, 1: float}|null
     */
    private static function extraerCoordenadasElemento(array $el): ?array
    {
        if (isset($el['lat'], $el['lon'])) {
            return [(float)$el['lat'], (float)$el['lon']];
        }
        if (isset($el['center']['lat'], $el['center']['lon'])) {
            return [(float)$el['center']['lat'], (float)$el['center']['lon']];
        }
        if (!empty($el['geometry']) && is_array($el['geometry'])) {
            $sumLat = 0.0;
            $sumLng = 0.0;
            $n = 0;
            foreach ($el['geometry'] as $pt) {
                if (isset($pt['lat'], $pt['lon'])) {
                    $sumLat += (float)$pt['lat'];
                    $sumLng += (float)$pt['lon'];
                    $n++;
                }
            }
            if ($n > 0) {
                return [$sumLat / $n, $sumLng / $n];
            }
        }
        return null;
    }

    /**
     * Detecta equipamiento real de tags OSM y nodos cercanos (ejercitadores, calistenia, bancas, andadores, rampas).
     *
     * @param array<string, mixed> $tags
     * @param array<int, array<string, mixed>> $amenidadesCercanas
     * @return string[]
     */
    private static function detectarEquipamientoOsm(
        array $tags,
        string $nombre,
        float $pLat,
        float $pLng,
        array $amenidadesCercanas,
        bool $accesibleSilla
    ): array {
        $eq = [];
        $todoTexto = mb_strtolower(
            $nombre . ' ' . (string)($tags['description'] ?? '') . ' ' . json_encode($tags, JSON_UNESCAPED_UNICODE),
            'UTF-8'
        );

        $tieneEjercitadores = !empty($tags['fitness_station'])
            || !empty($tags['outdoor_fitness'])
            || str_contains($todoTexto, 'fitness_station')
            || str_contains($todoTexto, 'ejercitador')
            || str_contains($todoTexto, 'gimnasio')
            || str_contains($todoTexto, 'outdoor_gym');

        $tieneCalistenia = str_contains($todoTexto, 'calisthenics')
            || str_contains($todoTexto, 'calistenia')
            || str_contains($todoTexto, 'pull_up')
            || str_contains($todoTexto, 'street_workout')
            || str_contains($todoTexto, 'barras');

        $tieneBancas = strtolower((string)($tags['bench'] ?? '')) === 'yes'
            || str_contains($todoTexto, 'banca')
            || str_contains($todoTexto, 'bench');

        foreach ($amenidadesCercanas as $am) {
            if (self::distanciaHaversine($pLat, $pLng, (float)$am['lat'], (float)$am['lng']) <= 130.0) {
                if ($am['leisure'] === 'fitness_station') {
                    $tieneEjercitadores = true;
                    if (str_contains((string)$am['sport'], 'calisthenics')) {
                        $tieneCalistenia = true;
                    }
                }
                if ($am['amenity'] === 'bench') {
                    $tieneBancas = true;
                }
            }
        }

        if ($tieneEjercitadores) {
            $eq[] = 'aparatos_ejercitadores';
        }
        if ($tieneCalistenia) {
            $eq[] = 'barras_calistenia';
        }
        if ($tieneBancas) {
            $eq[] = 'bancas_descanso';
        }
        if (str_contains($todoTexto, 'running') || str_contains($todoTexto, 'track') || str_contains($todoTexto, 'trote') || str_contains($todoTexto, 'pista')) {
            $eq[] = 'pista_trote';
        }
        if ($accesibleSilla) {
            $eq[] = 'rampa_universal';
        }

        $surface = strtolower((string)($tags['surface'] ?? ''));
        if (in_array($surface, ['paved', 'asphalt', 'concrete', 'paving_stones'], true) || strtolower((string)($tags['foot'] ?? '')) === 'yes') {
            $eq[] = 'andador_peatonal';
        }

        return array_values(array_unique($eq));
    }

    /**
     * @param array<int, array<string, mixed>> $parques
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    private static function filtrarParques(array $parques, array $filtros): array
    {
        $soloSilla = !empty($filtros['accesibilidad_silla']);
        $equipFiltro = isset($filtros['equipamiento']) ? mb_strtolower(trim((string)$filtros['equipamiento']), 'UTF-8') : '';
        $busqueda = isset($filtros['q']) ? mb_strtolower(trim((string)$filtros['q']), 'UTF-8') : '';

        $salida = [];
        foreach ($parques as $p) {
            if ($soloSilla && empty($p['accesibilidad_silla'])) {
                continue;
            }
            if ($equipFiltro !== '') {
                $eqLista = is_array($p['equipamiento'] ?? null) ? $p['equipamiento'] : [];
                if (!in_array($equipFiltro, $eqLista, true)) {
                    $eqTexto = mb_strtolower(implode(' ', $eqLista), 'UTF-8');
                    if (!str_contains($eqTexto, $equipFiltro)) {
                        continue;
                    }
                }
            }
            if ($busqueda !== '') {
                $nom = mb_strtolower((string)($p['nombre'] ?? ''), 'UTF-8');
                $dir = mb_strtolower((string)($p['direccion'] ?? ''), 'UTF-8');
                if (!str_contains($nom, $busqueda) && !str_contains($dir, $busqueda)) {
                    continue;
                }
            }
            $salida[] = $p;
        }

        return array_values($salida);
    }

    private static function distanciaHaversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2.0) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2.0) ** 2);
        return $R * 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    }

    /**
     * Crea un handle cURL configurado de forma consistente para Overpass, Nominatim y OSRM.
     */
    private static function createCurlHandle(string $url, ?string $postBody = null, int $timeout = 12): ?\CurlHandle
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $headers = [
            'Accept: application/json',
            'Accept-Language: es-MX,es;q=0.9',
            'Expect:',
        ];
        if ($postBody !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::userAgent(),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }

        return $ch;
    }

    /**
     * @return array{ok: bool, http_status: int, body: ?string, error: ?string}
     */
    private static function httpRequest(string $url, ?string $postBody = null, int $timeout = 12): array
    {
        $ch = self::createCurlHandle($url, $postBody, $timeout);
        if ($ch === null) {
            return [
                'ok'          => false,
                'http_status' => 0,
                'body'        => null,
                'error'       => 'curl_init fallo',
            ];
        }

        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $errno !== 0) {
            return [
                'ok'          => false,
                'http_status' => 0,
                'body'        => null,
                'error'       => $err !== '' ? $err : ('cURL #' . $errno),
            ];
        }

        if ($code !== 200 || !is_string($res) || $res === '') {
            return [
                'ok'          => false,
                'http_status' => $code,
                'body'        => is_string($res) ? $res : null,
                'error'       => 'HTTP ' . $code,
            ];
        }

        return [
            'ok'          => true,
            'http_status' => 200,
            'body'        => $res,
            'error'       => null,
        ];
    }
}
