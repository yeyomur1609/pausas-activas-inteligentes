<?php
// ARCHIVO: backend/src/Models/RutinaModel.php
// Modelo de rutinas y ejercicios secuenciales (incluyendo filtros ergonómicos, modo sentado y CRUD admin).
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

final class RutinaModel
{
    public const CATEGORIAS  = ['computadora', 'estudio', 'reuniones', 'parque', 'todas'];
    public const NECESIDADES = ['moverse', 'relajarse', 'concentrarse', 'despejarse'];
    public const NIVELES     = ['suave', 'moderado', 'activo'];

    /**
     * Obtiene el catálogo de rutinas activas con sus ejercicios secuenciales ordenados.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listarCatalogo(array $filtros = [], bool $incluirInactivas = false): array
    {
        $pdo = Database::getConnection();

        $condiciones = [];
        $params = [];

        if (!$incluirInactivas) {
            $condiciones[] = 'r.activa = 1';
        }

        if (!empty($filtros['categoria']) && in_array($filtros['categoria'], self::CATEGORIAS, true) && $filtros['categoria'] !== 'todas') {
            $condiciones[] = "(r.categoria = :categoria OR r.categoria = 'todas')";
            $params[':categoria'] = (string)$filtros['categoria'];
        }

        if (!empty($filtros['necesidad']) && in_array($filtros['necesidad'], self::NECESIDADES, true)) {
            $condiciones[] = 'r.necesidad = :necesidad';
            $params[':necesidad'] = (string)$filtros['necesidad'];
        }

        if (!empty($filtros['nivel']) && in_array($filtros['nivel'], self::NIVELES, true)) {
            $condiciones[] = 'r.nivel = :nivel';
            $params[':nivel'] = (string)$filtros['nivel'];
        }

        if (!empty($filtros['duracion_minutos'])) {
            $dur = (int)$filtros['duracion_minutos'];
            if (in_array($dur, [3, 5, 10], true)) {
                $condiciones[] = 'r.duracion_minutos = :duracion';
                $params[':duracion'] = $dur;
            }
        }

        if (!empty($filtros['apta_movilidad_reducida'])) {
            $condiciones[] = 'r.apta_movilidad_reducida = 1';
        }

        $whereSql = count($condiciones) > 0 ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        $stmt = $pdo->prepare(
            "SELECT r.*
             FROM rutinas r
             {$whereSql}
             ORDER BY r.duracion_minutos ASC, r.id ASC"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rutinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rutinas)) {
            return [];
        }

        return self::adjuntarEjercicios($rutinas);
    }

    public static function obtenerPorId(int $id): ?array
    {
        $pdo = Database::getConnection();
        $sql = Database::isSqlSrv()
            ? 'SELECT TOP (1) * FROM rutinas WHERE id = :id'
            : 'SELECT * FROM rutinas WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $rutina = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rutina) {
            return null;
        }
        $conEjercicios = self::adjuntarEjercicios([$rutina]);
        return $conEjercicios[0] ?? null;
    }

    /**
     * Genera o selecciona una rutina personalizada según actividad, necesidad, minutos y modo sentado.
     *
     * @return array<string, mixed>
     */
    public static function obtenerRecomendada(
        string $categoria = 'computadora',
        string $necesidad = 'relajarse',
        int $duracionMin = 3,
        bool $modoSentado = false
    ): array {
        $catValida = in_array($categoria, self::CATEGORIAS, true) ? $categoria : 'computadora';
        $necValida = in_array($necesidad, self::NECESIDADES, true) ? $necesidad : 'relajarse';
        $durValida = in_array($duracionMin, [3, 5, 10], true) ? $duracionMin : 3;

        $candidatas = self::listarCatalogo([
            'categoria'               => $catValida,
            'necesidad'               => $necValida,
            'apta_movilidad_reducida' => $modoSentado ? 1 : 0,
        ]);

        if (empty($candidatas)) {
            $candidatas = self::listarCatalogo([
                'apta_movilidad_reducida' => $modoSentado ? 1 : 0,
            ]);
        }

        if (empty($candidatas)) {
            return [];
        }

        // Priorizar la rutina que coincida con la duración solicitada; si no, adaptar tiempos proporcionalmente
        $elegida = $candidatas[0];
        foreach ($candidatas as $r) {
            if ((int)$r['duracion_minutos'] === $durValida) {
                $elegida = $r;
                break;
            }
        }

        $ejercicios = is_array($elegida['ejercicios'] ?? null) ? $elegida['ejercicios'] : [];
        $totalPasos = max(1, count($ejercicios));
        $segundosPorPaso = (int)max(30, floor(($durValida * 60) / $totalPasos));

        foreach ($ejercicios as $idx => &$ej) {
            $ej['orden'] = $idx + 1;
            $ej['duracion_segundos'] = $segundosPorPaso;
            $ej['instruccion_activa'] = $modoSentado
                ? (string)$ej['instrucciones_sentado']
                : (string)$ej['instrucciones'];
        }
        unset($ej);

        $elegida['duracion_minutos'] = $durValida;
        $elegida['duracion_total_segundos'] = $segundosPorPaso * $totalPasos;
        $elegida['modo_sentado_activo'] = $modoSentado;
        $elegida['ejercicios'] = $ejercicios;

        return $elegida;
    }

    /**
     * Crea una rutina nueva y sus ejercicios secuenciales (exclusivo rol admin).
     *
     * @param array<string, mixed> $datos
     */
    public static function crearRutina(array $datos): array
    {
        $pdo = Database::getConnection();
        $titulo = mb_substr(trim((string)($datos['titulo'] ?? 'Nueva Rutina Ergonómica')), 0, 120, 'UTF-8');
        $slugBase = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo) ?: 'rutina'));
        $slug = trim($slugBase, '-') . '-' . random_int(100, 999);

        $categoria = in_array($datos['categoria'] ?? '', self::CATEGORIAS, true) ? (string)$datos['categoria'] : 'todas';
        $necesidad = in_array($datos['necesidad'] ?? '', self::NECESIDADES, true) ? (string)$datos['necesidad'] : 'moverse';
        $nivel     = in_array($datos['nivel'] ?? '', self::NIVELES, true) ? (string)$datos['nivel'] : 'suave';
        $duracion  = in_array((int)($datos['duracion_minutos'] ?? 3), [3, 5, 10], true) ? (int)$datos['duracion_minutos'] : 3;
        $icono     = mb_substr(trim((string)($datos['icono'] ?? '🧘')), 0, 16, 'UTF-8') ?: '🧘';
        $aptaSilla = !empty($datos['apta_movilidad_reducida']) ? 1 : 0;
        $desc      = mb_substr(trim((string)($datos['descripcion'] ?? 'Rutina personalizada de bienestar postural.')), 0, 280, 'UTF-8');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rutinas (slug, titulo, descripcion, categoria, necesidad, nivel, apta_movilidad_reducida, duracion_minutos, icono, activa)
                 VALUES (:slug, :titulo, :desc, :cat, :nec, :nivel, :silla, :dur, :icono, 1)'
            );
            $stmt->execute([
                ':slug'   => $slug,
                ':titulo' => $titulo,
                ':desc'   => $desc,
                ':cat'    => $categoria,
                ':nec'    => $necesidad,
                ':nivel'  => $nivel,
                ':silla'  => $aptaSilla,
                ':dur'    => $duracion,
                ':icono'  => $icono,
            ]);
            $rutinaId = Database::lastInsertId($pdo);

            $ejercicios = is_array($datos['ejercicios'] ?? null) ? $datos['ejercicios'] : [];
            if (empty($ejercicios)) {
                $ejercicios = [[
                    'titulo'                => 'Respiración y Alineación Postural',
                    'instrucciones'         => 'Inhala profundamente por la nariz en 4 tiempos y relaja hombros al exhalar.',
                    'instrucciones_sentado' => 'Con la espalda apoyada en el respaldo, inhala profundo y suelta la tensión de hombros.',
                    'duracion_segundos'     => $duracion * 60,
                    'icono_emoji'           => $icono,
                    'beneficio_ergonomico'  => 'Oxigenación y descompresión cervical',
                ]];
            }

            $insEj = $pdo->prepare(
                'INSERT INTO rutina_ejercicios (
                    rutina_id, orden, titulo, instrucciones, instrucciones_sentado,
                    duracion_segundos, icono_emoji, beneficio_ergonomico
                 ) VALUES (
                    :rid, :orden, :titulo, :inst, :inst_sentado, :seg, :icono, :beneficio
                 )'
            );

            foreach (array_values($ejercicios) as $idx => $ej) {
                if (!is_array($ej)) {
                    continue;
                }
                $tEj = mb_substr(trim((string)($ej['titulo'] ?? 'Ejercicio ' . ($idx + 1))), 0, 120, 'UTF-8');
                $iEj = trim((string)($ej['instrucciones'] ?? 'Realiza el movimiento con suavidad y respiración fluida.'));
                $iSe = trim((string)($ej['instrucciones_sentado'] ?? $iEj));
                $seg = max(15, min(600, (int)($ej['duracion_segundos'] ?? 60)));
                $ico = mb_substr(trim((string)($ej['icono_emoji'] ?? '🧘')), 0, 16, 'UTF-8') ?: '🧘';
                $ben = mb_substr(trim((string)($ej['beneficio_ergonomico'] ?? 'Alivio postural y movilidad articular')), 0, 180, 'UTF-8');

                $insEj->execute([
                    ':rid'          => $rutinaId,
                    ':orden'        => $idx + 1,
                    ':titulo'       => $tEj,
                    ':inst'         => $iEj,
                    ':inst_sentado' => $iSe,
                    ':seg'          => $seg,
                    ':icono'        => $ico,
                    ':beneficio'    => $ben,
                ]);
            }

            $pdo->commit();
            return self::obtenerPorId($rutinaId) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function alternarEstadoRutina(int $rutinaId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE rutinas SET activa = CASE WHEN activa = 1 THEN 0 ELSE 1 END WHERE id = :id');
        $stmt->execute([':id' => $rutinaId]);
        return $stmt->rowCount() > 0;
    }

    public static function eliminarRutina(int $rutinaId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM rutinas WHERE id = :id');
        $stmt->execute([':id' => $rutinaId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rutinas
     * @return array<int, array<string, mixed>>
     */
    private static function adjuntarEjercicios(array $rutinas): array
    {
        $pdo = Database::getConnection();
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rutinas);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT *
             FROM rutina_ejercicios
             WHERE rutina_id IN ({$placeholders})
             ORDER BY rutina_id ASC, orden ASC, id ASC"
        );
        foreach (array_values($ids) as $i => $idVal) {
            $stmt->bindValue($i + 1, $idVal, PDO::PARAM_INT);
        }
        $stmt->execute();

        $porRutina = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ej) {
            $rid = (int)$ej['rutina_id'];
            $porRutina[$rid][] = [
                'id'                    => (int)$ej['id'],
                'rutina_id'             => $rid,
                'orden'                 => (int)$ej['orden'],
                'titulo'                => (string)$ej['titulo'],
                'instrucciones'         => (string)$ej['instrucciones'],
                'instrucciones_sentado' => (string)$ej['instrucciones_sentado'],
                'duracion_segundos'     => (int)$ej['duracion_segundos'],
                'icono_emoji'           => (string)$ej['icono_emoji'],
                'beneficio_ergonomico'  => (string)$ej['beneficio_ergonomico'],
            ];
        }

        foreach ($rutinas as &$r) {
            $rid = (int)$r['id'];
            $r['id'] = $rid;
            $r['duracion_minutos'] = (int)$r['duracion_minutos'];
            $r['apta_movilidad_reducida'] = (bool)$r['apta_movilidad_reducida'];
            $r['activa'] = (bool)$r['activa'];
            $r['ejercicios'] = $porRutina[$rid] ?? [];
            $r['total_ejercicios'] = count($r['ejercicios']);
        }
        unset($r);

        return $rutinas;
    }
}
