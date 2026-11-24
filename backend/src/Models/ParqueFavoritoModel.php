<?php
// ARCHIVO: backend/src/Models/ParqueFavoritoModel.php
// Modelo de parques públicos favoritos del usuario con prevención de duplicados y protección anti-IDOR.
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

final class ParqueFavoritoModel
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listarPorUsuario(int $usuarioId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, usuario_id, parque_osm_id, nombre,
                    latitud AS lat, longitud AS lng,
                    direccion, equipamiento_json, accesibilidad_silla, notas, foto_url,
                    creado_en
             FROM parques_favoritos
             WHERE usuario_id = :uid
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $usuarioId]);

        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as &$f) {
            $f['id']                  = (int)$f['id'];
            $f['usuario_id']          = (int)$f['usuario_id'];
            $f['lat']                 = (float)$f['lat'];
            $f['lng']                 = (float)$f['lng'];
            $f['accesibilidad_silla'] = (bool)$f['accesibilidad_silla'];
            $eq = isset($f['equipamiento_json']) && is_string($f['equipamiento_json'])
                ? json_decode($f['equipamiento_json'], true)
                : [];
            $f['equipamiento'] = is_array($eq) ? $eq : [];
            $rawFecha = trim((string)($f['creado_en'] ?? ''));
            if ($rawFecha !== '') {
                try {
                    $f['fecha_guardado'] = (new \DateTimeImmutable($rawFecha))->format('d/m/Y H:i');
                } catch (\Throwable $e) {
                    $f['fecha_guardado'] = $rawFecha;
                }
            } else {
                $f['fecha_guardado'] = '';
            }
            unset($f['equipamiento_json'], $f['creado_en']);
        }
        unset($f);

        return $filas;
    }

    /**
     * Guarda o actualiza un parque público en la lista de favoritos del usuario.
     *
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public static function guardarFavorito(int $usuarioId, array $datos): array
    {
        $pdo = Database::getConnection();

        $osmId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($datos['parque_osm_id'] ?? '')) ?: '';
        $lat   = (float)($datos['lat'] ?? $datos['latitud'] ?? 0.0);
        $lng   = (float)($datos['lng'] ?? $datos['longitud'] ?? 0.0);

        if ($osmId === '') {
            $osmId = sprintf('osm_pin_%d_%d', (int)round($lat * 10000), (int)round(abs($lng) * 10000));
        }

        $nombre = trim(strip_tags((string)($datos['nombre'] ?? 'Parque Público')));
        if ($nombre === '') {
            $nombre = 'Parque Público';
        }
        $nombre = mb_substr($nombre, 0, 140, 'UTF-8');

        $direccion = mb_substr(
            trim(strip_tags((string)($datos['direccion'] ?? 'Santa Catarina, Nuevo León'))),
            0,
            220,
            'UTF-8'
        );

        $equipamiento = is_array($datos['equipamiento'] ?? null)
            ? array_values(array_slice(array_map('strval', $datos['equipamiento']), 0, 10))
            : ['Andadores peatonales', 'Áreas verdes', 'Bancas de descanso'];

        $accesibilidadSilla = array_key_exists('accesibilidad_silla', $datos)
            ? (!empty($datos['accesibilidad_silla']) ? 1 : 0)
            : 1;

        $notas = isset($datos['notas'])
            ? mb_substr(trim(strip_tags((string)$datos['notas'])), 0, 255, 'UTF-8')
            : null;

        $fotoUrl = isset($datos['foto_url'])
            ? mb_substr(trim((string)$datos['foto_url']), 0, 255, 'UTF-8')
            : null;

        if (Database::isSqlSrv()) {
            $stmt = $pdo->prepare(
                'MERGE dbo.parques_favoritos WITH (HOLDLOCK) AS target
                 USING (
                     SELECT
                         :uid    AS usuario_id,
                         :osm_id AS parque_osm_id,
                         :nombre AS nombre,
                         :lat    AS latitud,
                         :lng    AS longitud,
                         :dir    AS direccion,
                         :eq     AS equipamiento_json,
                         :silla  AS accesibilidad_silla,
                         :notas  AS notas,
                         :foto   AS foto_url
                 ) AS source
                    ON target.usuario_id = source.usuario_id
                   AND target.parque_osm_id = source.parque_osm_id
                 WHEN MATCHED THEN
                     UPDATE SET
                         nombre              = source.nombre,
                         direccion           = source.direccion,
                         equipamiento_json   = source.equipamiento_json,
                         accesibilidad_silla = source.accesibilidad_silla,
                         notas               = COALESCE(source.notas, target.notas),
                         foto_url            = COALESCE(source.foto_url, target.foto_url)
                 WHEN NOT MATCHED THEN
                     INSERT (
                         usuario_id, parque_osm_id, nombre, latitud, longitud,
                         direccion, equipamiento_json, accesibilidad_silla, notas, foto_url
                     ) VALUES (
                         source.usuario_id, source.parque_osm_id, source.nombre, source.latitud, source.longitud,
                         source.direccion, source.equipamiento_json, source.accesibilidad_silla, source.notas, source.foto_url
                     );'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO parques_favoritos (
                    usuario_id, parque_osm_id, nombre, latitud, longitud,
                    direccion, equipamiento_json, accesibilidad_silla, notas, foto_url
                 ) VALUES (
                    :uid, :osm_id, :nombre, :lat, :lng,
                    :dir, :eq, :silla, :notas, :foto
                 )
                 ON DUPLICATE KEY UPDATE
                    nombre = VALUES(nombre),
                    direccion = VALUES(direccion),
                    equipamiento_json = VALUES(equipamiento_json),
                    accesibilidad_silla = VALUES(accesibilidad_silla),
                    notas = COALESCE(VALUES(notas), parques_favoritos.notas),
                    foto_url = COALESCE(VALUES(foto_url), parques_favoritos.foto_url)'
            );
        }

        $stmt->execute([
            ':uid'    => $usuarioId,
            ':osm_id' => $osmId,
            ':nombre' => $nombre,
            ':lat'    => $lat,
            ':lng'    => $lng,
            ':dir'    => $direccion,
            ':eq'     => json_encode($equipamiento, JSON_UNESCAPED_UNICODE),
            ':silla'  => $accesibilidadSilla,
            ':notas'  => $notas,
            ':foto'   => $fotoUrl,
        ]);

        $lista = self::listarPorUsuario($usuarioId);
        foreach ($lista as $item) {
            if ($item['parque_osm_id'] === $osmId) {
                return $item;
            }
        }
        return $lista[0] ?? [];
    }

    /**
     * Elimina un parque favorito verificando propiedad estricta (anti-IDOR).
     */
    public static function eliminarFavorito(int $favoritoId, int $usuarioId, bool $esAdmin = false): bool
    {
        $pdo = Database::getConnection();
        if ($esAdmin) {
            $stmt = $pdo->prepare('DELETE FROM parques_favoritos WHERE id = :id');
            $stmt->execute([':id' => $favoritoId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM parques_favoritos WHERE id = :id AND usuario_id = :uid');
            $stmt->execute([
                ':id'  => $favoritoId,
                ':uid' => $usuarioId,
            ]);
        }
        return $stmt->rowCount() > 0;
    }
}
