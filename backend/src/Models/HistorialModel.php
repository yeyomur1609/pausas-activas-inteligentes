<?php
// ARCHIVO: backend/src/Models/HistorialModel.php
// Modelo para registrar pausas completadas (incluyendo esfuerzo 1-5 y utilidad/calificación 1-5),
// calcular estadísticas personales y alimentar el muro comunitario.
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

final class HistorialModel
{
    public const ACTIVIDADES = ['computadora', 'estudio', 'reuniones', 'parque'];
    public const NECESIDADES = ['moverse', 'relajarse', 'concentrarse', 'despejarse'];

    /**
     * Registra una pausa activa completada y actualiza automáticamente la racha del usuario.
     *
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public static function registrarPausa(int $usuarioId, array $datos): array
    {
        $pdo = Database::getConnection();

        $rutinaId = !empty($datos['rutina_id']) ? (int)$datos['rutina_id'] : null;
        $ubicacion = trim(strip_tags((string)($datos['ubicacion_nombre'] ?? $datos['ubicacion'] ?? 'Mi Espacio Actual')));
        if ($ubicacion === '') {
            $ubicacion = 'Mi Espacio Actual';
        }
        $ubicacion = mb_substr($ubicacion, 0, 140, 'UTF-8');

        $parqueOsmId = !empty($datos['parque_osm_id'])
            ? preg_replace('/[^a-zA-Z0-9_\-]/', '', substr((string)$datos['parque_osm_id'], 0, 64))
            : null;

        $actividad = (string)($datos['actividad_realizada'] ?? $datos['actividad'] ?? 'computadora');
        if (!in_array($actividad, self::ACTIVIDADES, true)) {
            $actividad = 'computadora';
        }

        $necesidad = (string)($datos['necesidad_atendida'] ?? $datos['necesidad'] ?? 'moverse');
        if (!in_array($necesidad, self::NECESIDADES, true)) {
            $necesidad = 'moverse';
        }

        $minutos = max(1, min(120, (int)($datos['tiempo_minutos'] ?? $datos['minutos'] ?? 3)));
        $esfuerzo = max(1, min(5, (int)($datos['esfuerzo'] ?? 3)));
        $calificacion = max(1, min(5, (int)($datos['calificacion'] ?? $datos['utilidad'] ?? $datos['estrellas'] ?? 5)));
        $sentado = !empty($datos['completada_sentado']) ? 1 : 0;

        $nowExpr = Database::isSqlSrv() ? 'GETUTCDATE()' : 'NOW()';

        $stmt = $pdo->prepare(
            "INSERT INTO historial_pausas (
                usuario_id, rutina_id, ubicacion_nombre, parque_osm_id,
                actividad_realizada, necesidad_atendida, tiempo_minutos,
                esfuerzo, calificacion, completada_sentado, fecha_pausa
             ) VALUES (
                :uid, :rid, :ubicacion, :osm_id,
                :act, :nec, :min, :esf, :cal, :sentado, {$nowExpr}
             )"
        );

        $stmt->bindValue(':uid', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $rutinaId, $rutinaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':ubicacion', $ubicacion, PDO::PARAM_STR);
        $stmt->bindValue(':osm_id', $parqueOsmId, $parqueOsmId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':act', $actividad, PDO::PARAM_STR);
        $stmt->bindValue(':nec', $necesidad, PDO::PARAM_STR);
        $stmt->bindValue(':min', $minutos, PDO::PARAM_INT);
        $stmt->bindValue(':esf', $esfuerzo, PDO::PARAM_INT);
        $stmt->bindValue(':cal', $calificacion, PDO::PARAM_INT);
        $stmt->bindValue(':sentado', $sentado, PDO::PARAM_INT);
        $stmt->execute();

        $pausaId = Database::lastInsertId($pdo);
        $rachas = UsuarioModel::updateStreakAfterPause($usuarioId);

        return [
            'id'             => $pausaId,
            'racha_actual'   => $rachas['racha_actual'],
            'mejor_racha'    => $rachas['mejor_racha'],
            'tiempo_minutos' => $minutos,
            'esfuerzo'       => $esfuerzo,
            'calificacion'   => $calificacion,
        ];
    }

    /**
     * Obtiene las estadísticas completas de progreso y rachas de un usuario.
     *
     * @return array<string, mixed>
     */
    public static function obtenerEstadisticasUsuario(int $usuarioId): array
    {
        $pdo = Database::getConnection();
        $isSqlSrv = Database::isSqlSrv();
        $usuario = UsuarioModel::findById($usuarioId);
        $metaDiaria = (int)($usuario['meta_diaria_min'] ?? 15);

        $condHoy = $isSqlSrv
            ? 'CAST(fecha_pausa AS DATE) = CAST(GETUTCDATE() AS DATE)'
            : 'DATE(fecha_pausa) = CURDATE()';
        $condSemana = $isSqlSrv
            ? 'fecha_pausa >= DATEADD(DAY, -7, CAST(GETUTCDATE() AS DATE))'
            : 'fecha_pausa >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';

        $stmtResumen = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_pausas,
                COALESCE(SUM(tiempo_minutos), 0) AS minutos_totales,
                COALESCE(ROUND(AVG(CAST(calificacion AS DECIMAL(4,2))), 1), 5.0) AS calificacion_promedio,
                COALESCE(ROUND(AVG(CAST(esfuerzo AS DECIMAL(4,2))), 1), 3.0) AS esfuerzo_promedio,
                COALESCE(SUM(CASE WHEN {$condHoy} THEN tiempo_minutos ELSE 0 END), 0) AS minutos_hoy,
                COALESCE(SUM(CASE WHEN {$condSemana} THEN tiempo_minutos ELSE 0 END), 0) AS minutos_semana
             FROM historial_pausas
             WHERE usuario_id = :uid"
        );
        $stmtResumen->execute([':uid' => $usuarioId]);
        $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [];

        $minutosHoy = (int)($resumen['minutos_hoy'] ?? 0);
        $porcentajeMeta = $metaDiaria > 0 ? min(100, (int)round(($minutosHoy / $metaDiaria) * 100)) : 100;

        $top30 = $isSqlSrv ? 'TOP (30) ' : '';
        $limit30 = $isSqlSrv ? '' : ' LIMIT 30';

        $stmtHist = $pdo->prepare(
            "SELECT {$top30}h.id,
                    h.ubicacion_nombre AS ubicacion,
                    h.parque_osm_id,
                    h.actividad_realizada AS actividad,
                    h.necesidad_atendida AS necesidad,
                    h.tiempo_minutos AS minutos,
                    h.esfuerzo,
                    h.calificacion AS estrellas,
                    h.calificacion AS utilidad,
                    h.completada_sentado,
                    h.fecha_pausa,
                    r.titulo AS rutina_titulo
             FROM historial_pausas h
             LEFT JOIN rutinas r ON r.id = h.rutina_id
             WHERE h.usuario_id = :uid
             ORDER BY h.id DESC{$limit30}"
        );
        $stmtHist->execute([':uid' => $usuarioId]);
        $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

        foreach ($historial as &$fila) {
            $fila['id']                 = (int)$fila['id'];
            $fila['minutos']            = (int)$fila['minutos'];
            $fila['esfuerzo']           = (int)$fila['esfuerzo'];
            $fila['estrellas']          = (int)$fila['estrellas'];
            $fila['utilidad']           = (int)$fila['utilidad'];
            $fila['completada_sentado'] = (bool)$fila['completada_sentado'];
            $fila['fecha']              = self::formatearFecha((string)($fila['fecha_pausa'] ?? ''), 'd/m/Y H:i');
            unset($fila['fecha_pausa']);
        }
        unset($fila);

        return [
            'racha_actual'          => (int)($usuario['racha_actual'] ?? 0),
            'mejor_racha'           => (int)($usuario['mejor_racha'] ?? 0),
            'ultima_pausa_fecha'    => $usuario['ultima_pausa_fecha'] ?? null,
            'meta_diaria_min'       => $metaDiaria,
            'minutos_hoy'           => $minutosHoy,
            'minutos_semana'        => (int)($resumen['minutos_semana'] ?? 0),
            'porcentaje_meta_hoy'   => $porcentajeMeta,
            'total_pausas'          => (int)($resumen['total_pausas'] ?? 0),
            'minutos_totales'       => (int)($resumen['minutos_totales'] ?? 0),
            'calificacion_promedio' => (float)($resumen['calificacion_promedio'] ?? 5.0),
            'esfuerzo_promedio'     => (float)($resumen['esfuerzo_promedio'] ?? 3.0),
            'historial'             => $historial,
            'comunidad_feed'        => self::obtenerFeedComunitario(12),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function obtenerFeedComunitario(int $limite = 12): array
    {
        $pdo = Database::getConnection();
        $isSqlSrv = Database::isSqlSrv();
        $lim = max(1, min(30, $limite));

        $tailClause = $isSqlSrv
            ? 'OFFSET 0 ROWS FETCH NEXT :lim ROWS ONLY'
            : 'LIMIT :lim';

        $stmt = $pdo->prepare(
            "SELECT h.ubicacion_nombre AS ubicacion,
                    h.actividad_realizada AS actividad,
                    h.necesidad_atendida AS necesidad,
                    h.tiempo_minutos AS minutos,
                    h.esfuerzo,
                    h.calificacion AS estrellas,
                    h.fecha_pausa,
                    COALESCE(u.nombre, 'Miembro de la Comunidad') AS usuario,
                    COALESCE(u.ocupacion, 'estudiante') AS ocupacion
             FROM historial_pausas h
             LEFT JOIN usuarios u ON u.id = h.usuario_id
             ORDER BY h.id DESC
             {$tailClause}"
        );
        $stmt->bindValue(':lim', $lim, PDO::PARAM_INT);
        $stmt->execute();
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filas as &$fila) {
            $fila['minutos']   = (int)$fila['minutos'];
            $fila['esfuerzo']  = (int)$fila['esfuerzo'];
            $fila['estrellas'] = (int)$fila['estrellas'];
            $fila['fecha']     = self::formatearFecha((string)($fila['fecha_pausa'] ?? ''), 'd/m H:i');
            unset($fila['fecha_pausa']);
        }
        unset($fila);

        return $filas;
    }

    private static function formatearFecha(string $raw, string $formato): string
    {
        $limpia = trim($raw);
        if ($limpia === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($limpia);
            return $dt->format($formato);
        } catch (\Throwable $e) {
            return $limpia;
        }
    }
}
