<?php
// ARCHIVO: backend/src/Middleware/RateLimitMiddleware.php
// Control de tasa de peticiones persistente en Azure SQL (T-SQL) / MySQL con respaldo seguro en disco efímero.
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use PDO;
use Throwable;

final class RateLimitMiddleware
{
    public static function clientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /**
     * Verifica y registra una petición dentro de una ventana en segundos.
     * Si excede el límite, responde HTTP 429 Too Many Requests con cabecera Retry-After.
     */
    public static function check(
        string $accion,
        int $maxIntentos = 30,
        int $ventanaSegundos = 60,
        string $identificadorExtra = ''
    ): void {
        $ip = self::clientIp();
        $accionLimpia = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $accion) ?? 'api', 0, 50);
        $claveRaw = $accionLimpia . '|' . $ip . '|' . strtolower(trim($identificadorExtra));
        $claveHash = hash('sha256', $claveRaw);

        $pdo = null;
        try {
            $pdo = Database::getConnection();
            $isSqlSrv = Database::isSqlSrv();
            $nowExpr = $isSqlSrv ? 'GETUTCDATE()' : 'NOW()';

            // Purga ligera probabilística (2% de las llamadas) de registros antiguos > 2 horas
            if (random_int(1, 50) === 1) {
                $purgeSql = $isSqlSrv
                    ? 'DELETE FROM rate_limits WHERE ventana_inicio < DATEADD(HOUR, -2, GETUTCDATE()) AND (bloqueado_hasta IS NULL OR bloqueado_hasta < GETUTCDATE())'
                    : 'DELETE FROM rate_limits WHERE ventana_inicio < TIMESTAMPADD(HOUR, -2, NOW()) AND (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW())';
                $pdo->exec($purgeSql);
            }

            $pdo->beginTransaction();

            $selectSql = $isSqlSrv
                ? 'SELECT TOP (1) id, intentos,
                          DATEDIFF(SECOND, ventana_inicio, GETUTCDATE()) AS seg_transcurridos,
                          DATEDIFF(SECOND, GETUTCDATE(), bloqueado_hasta) AS seg_bloqueo
                   FROM rate_limits WITH (UPDLOCK, ROWLOCK)
                   WHERE clave_hash = :hash'
                : 'SELECT id, intentos,
                          TIMESTAMPDIFF(SECOND, ventana_inicio, NOW()) AS seg_transcurridos,
                          TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS seg_bloqueo
                   FROM rate_limits
                   WHERE clave_hash = :hash
                   LIMIT 1
                   FOR UPDATE';

            $stmt = $pdo->prepare($selectSql);
            $stmt->execute([':hash' => $claveHash]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fila) {
                if ($isSqlSrv) {
                    $ins = $pdo->prepare(
                        'MERGE dbo.rate_limits WITH (HOLDLOCK) AS target
                         USING (
                             SELECT
                                 CAST(:hash AS VARCHAR(64))    AS clave_hash,
                                 CAST(:accion AS NVARCHAR(50)) AS accion,
                                 CAST(:ip AS VARCHAR(45))      AS ip_cliente
                         ) AS source
                            ON target.clave_hash = source.clave_hash
                         WHEN MATCHED THEN
                             UPDATE SET intentos = target.intentos + 1
                         WHEN NOT MATCHED THEN
                             INSERT (clave_hash, accion, ip_cliente, intentos, ventana_inicio)
                             VALUES (source.clave_hash, source.accion, source.ip_cliente, 1, GETUTCDATE());'
                    );
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO rate_limits (clave_hash, accion, ip_cliente, intentos, ventana_inicio)
                         VALUES (:hash, :accion, :ip, 1, NOW())
                         ON DUPLICATE KEY UPDATE intentos = intentos + 1'
                    );
                }
                $ins->execute([
                    ':hash'   => $claveHash,
                    ':accion' => $accionLimpia,
                    ':ip'     => $ip,
                ]);
                $pdo->commit();
                return;
            }

            $segBloqueo = (int)($fila['seg_bloqueo'] ?? 0);
            if ($segBloqueo > 0) {
                $pdo->commit();
                self::abortTooManyRequests($segBloqueo);
            }

            $segTranscurridos = (int)($fila['seg_transcurridos'] ?? 0);
            if ($segTranscurridos >= $ventanaSegundos || $segTranscurridos < 0) {
                $reset = $pdo->prepare(
                    "UPDATE rate_limits
                     SET intentos = 1, ventana_inicio = {$nowExpr}, bloqueado_hasta = NULL
                     WHERE id = :id"
                );
                $reset->execute([':id' => (int)$fila['id']]);
                $pdo->commit();
                return;
            }

            $nuevosIntentos = (int)$fila['intentos'] + 1;
            if ($nuevosIntentos > $maxIntentos) {
                $espera = max(5, $ventanaSegundos - $segTranscurridos);
                $bloqExpr = $isSqlSrv
                    ? 'DATEADD(SECOND, CAST(:espera AS INT), GETUTCDATE())'
                    : 'TIMESTAMPADD(SECOND, :espera, NOW())';
                $updBloq = $pdo->prepare(
                    "UPDATE rate_limits
                     SET intentos = :intentos,
                         bloqueado_hasta = {$bloqExpr}
                     WHERE id = :id"
                );
                $updBloq->bindValue(':intentos', $nuevosIntentos, PDO::PARAM_INT);
                $updBloq->bindValue(':espera', $espera, PDO::PARAM_INT);
                $updBloq->bindValue(':id', (int)$fila['id'], PDO::PARAM_INT);
                $updBloq->execute();

                $pdo->commit();
                self::abortTooManyRequests($espera);
            }

            $upd = $pdo->prepare('UPDATE rate_limits SET intentos = :intentos WHERE id = :id');
            $upd->execute([
                ':intentos' => $nuevosIntentos,
                ':id'       => (int)$fila['id'],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                @$pdo->rollBack();
            }
            error_log('[RateLimit Warning] ' . $e->getMessage());
            // Respaldo atómico en disco para acciones críticas (auth/chatbot) evitando fail-open
            if (str_starts_with($accionLimpia, 'auth_') || str_starts_with($accionLimpia, 'chatbot_')) {
                self::checkFileFallback($claveHash, $maxIntentos, $ventanaSegundos);
            }
        }
    }

    private static function resolveWritableCacheDir(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/storage/cache',
            rtrim(sys_get_temp_dir(), '/\\') . '/pai_cache',
        ];
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }
        return null;
    }

    private static function checkFileFallback(string $claveHash, int $maxIntentos, int $ventanaSegundos): void
    {
        $dir = self::resolveWritableCacheDir();
        if ($dir === null) {
            error_log('[RateLimit Warning] Directorio de caché efímero no escribible; continuando sin bloqueo de disco.');
            return;
        }
        $file = $dir . '/rl_' . substr($claveHash, 0, 32) . '.json';
        $fp = @fopen($file, 'c+b');
        if ($fp === false) {
            error_log('[RateLimit Warning] No se pudo abrir archivo temporal de rate limit: ' . $file);
            return;
        }
        $retryAfter = 0;
        if (@flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $now = time();
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($data) || !isset($data['start'], $data['count']) || ($now - (int)$data['start']) >= $ventanaSegundos) {
                $data = ['start' => $now, 'count' => 1];
            } else {
                $data['count'] = (int)$data['count'] + 1;
                if ($data['count'] > $maxIntentos) {
                    $retryAfter = max(5, $ventanaSegundos - ($now - (int)$data['start']));
                }
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($data));
            fflush($fp);
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);
        if ($retryAfter > 0) {
            self::abortTooManyRequests($retryAfter);
        }
    }

    private static function abortTooManyRequests(int $retryAfterSeconds): never
    {
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $retryAfterSeconds));
        }
        echo json_encode([
            'ok'          => false,
            'codigo'      => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => max(1, $retryAfterSeconds),
            'error'       => sprintf(
                'Demasiadas solicitudes seguidas. Por favor espera %d segundos antes de intentar nuevamente.',
                max(1, $retryAfterSeconds)
            ),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
