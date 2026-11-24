<?php
// ARCHIVO: backend/src/Models/UsuarioModel.php
// Modelo de acceso a datos para usuarios, ciclo de verificación de 7 días (168 horas),
// magic links, password resets, preferencias completas y rachas.
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

final class UsuarioModel
{
    public const OCUPACIONES_VALIDAS = [
        'estudiante',
        'trabajador',
        'trabajador_oficina',
        'docente',
        'operativo',
        'personal_administrativo',
        'oficinista',
        'ambos',
        'otro',
    ];

    public const PREFERENCIAS_DEFAULT = [
        'alto_contraste'              => false,
        'escala_fuente'               => 'normal',
        'reducir_movimiento'          => false,
        'alertas_sonoras'             => true,
        'narrador_voz'                => false,
        'modo_sentado'                => false,
        'frecuencia_recordatorio_min' => 45,
        'notificaciones_email'        => true,
        'tema_claro'                  => true,
        'fuente_legible'              => false,
        'subrayar_enlaces'            => false,
        'avatar'                      => '🧘',
        'foto_perfil'                 => '',
        'ubicacion_defecto'           => 'computadora',
        'tiempo_defecto'              => 3,
        'objetivo_defecto'            => 'relajarse',
        'chatbot_habilitado'          => true,
    ];

    private static function isSqlSrv(): bool
    {
        return Database::isSqlSrv();
    }

    private static function nowSql(): string
    {
        return self::isSqlSrv() ? 'GETUTCDATE()' : 'NOW()';
    }

    private static function todaySql(): string
    {
        return self::isSqlSrv() ? 'CAST(GETUTCDATE() AS DATE)' : 'CURDATE()';
    }

    private static function hoursSinceCreatedSql(): string
    {
        return self::isSqlSrv()
            ? 'DATEDIFF(HOUR, u.creado_en, GETUTCDATE())'
            : 'TIMESTAMPDIFF(HOUR, u.creado_en, NOW())';
    }

    private static function dateAddMinutesSql(string $param): string
    {
        return self::isSqlSrv()
            ? "DATEADD(MINUTE, {$param}, GETUTCDATE())"
            : "TIMESTAMPADD(MINUTE, {$param}, NOW())";
    }

    /**
     * Elimina cuentas no verificadas cuyo plazo de gracia de 7 días (168 horas) haya expirado,
     * protegiendo explícitamente cuentas de administrador y del dominio institucional @pausasactivas.edu.mx.
     */
    public static function limpiarCuentasNoVerificadasExpiradas(): int
    {
        $pdo = Database::getConnection();
        $expCond = self::isSqlSrv()
            ? 'DATEDIFF(HOUR, creado_en, GETUTCDATE()) >= 168'
            : 'TIMESTAMPDIFF(HOUR, creado_en, NOW()) >= 168';

        $stmt = $pdo->prepare(
            "DELETE FROM usuarios
             WHERE email_verificado = 0
               AND LOWER(rol) <> 'admin'
               AND LOWER(email) NOT LIKE '%@pausasactivas.edu.mx'
               AND {$expCond}"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function findByEmail(string $email): ?array
    {
        $pdo = Database::getConnection();
        $top = self::isSqlSrv() ? 'TOP (1) ' : '';
        $limit = self::isSqlSrv() ? '' : ' LIMIT 1';
        $hoursExpr = self::hoursSinceCreatedSql();

        $stmt = $pdo->prepare(
            "SELECT {$top}u.*,
                    {$hoursExpr} AS horas_desde_registro
             FROM usuarios u
             WHERE u.email = :email{$limit}"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateUser($row) : null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $top = self::isSqlSrv() ? 'TOP (1) ' : '';
        $limit = self::isSqlSrv() ? '' : ' LIMIT 1';
        $hoursExpr = self::hoursSinceCreatedSql();

        $stmt = $pdo->prepare(
            "SELECT {$top}u.*,
                    {$hoursExpr} AS horas_desde_registro
             FROM usuarios u
             WHERE u.id = :id{$limit}"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateUser($row) : null;
    }

    public static function create(
        string $nombre,
        string $email,
        string $passwordHash,
        string $ocupacion = 'estudiante',
        string $tokenHash = '',
        string $codigoHash = '',
        int $ttlMinutos = 10080,
        ?string $googleId = null,
        int $emailVerificado = 0
    ): array {
        $pdo = Database::getConnection();
        $ocupacionValida = in_array($ocupacion, self::OCUPACIONES_VALIDAS, true) ? $ocupacion : 'estudiante';
        $prefsJson = json_encode(self::PREFERENCIAS_DEFAULT, JSON_UNESCAPED_UNICODE);
        $verificadoInt = $emailVerificado ? 1 : 0;
        $nowExpr = self::nowSql();
        $expiraExpr = self::dateAddMinutesSql(':ttl');
        $verificadoEnExpr = $verificadoInt === 1 ? $nowExpr : 'NULL';

        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (
                nombre, email, password_hash, rol, ocupacion, google_id,
                email_verificado, email_verificacion_token_hash, email_verificacion_codigo_hash,
                email_verificacion_expira_en, email_verificado_en, preferencias_json, aviso_privacidad_aceptado
             ) VALUES (
                :nombre, :email, :password_hash, 'usuario', :ocupacion, :google_id,
                :verificado, :token_hash, :codigo_hash,
                {$expiraExpr},
                {$verificadoEnExpr},
                :prefs, 1
             )"
        );

        $stmt->bindValue(':nombre', mb_substr(trim($nombre), 0, 100, 'UTF-8'), PDO::PARAM_STR);
        $stmt->bindValue(':email', mb_substr(strtolower(trim($email)), 0, 120, 'UTF-8'), PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':ocupacion', $ocupacionValida, PDO::PARAM_STR);
        $stmt->bindValue(':google_id', $googleId, $googleId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':verificado', $verificadoInt, PDO::PARAM_INT);
        $stmt->bindValue(':token_hash', $tokenHash !== '' ? $tokenHash : null, $tokenHash !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':codigo_hash', $codigoHash !== '' ? $codigoHash : null, $codigoHash !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':ttl', $ttlMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':prefs', $prefsJson, PDO::PARAM_STR);
        $stmt->execute();

        $id = Database::lastInsertId($pdo);
        return self::findById($id) ?? [];
    }

    public static function refreshVerificationToken(
        int $usuarioId,
        string $tokenHash,
        string $codigoHash,
        int $ttlMinutos = 10080
    ): void {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $expiraExpr = self::dateAddMinutesSql(':ttl');

        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET email_verificacion_token_hash = :token_hash,
                 email_verificacion_codigo_hash = :codigo_hash,
                 email_verificacion_expira_en = {$expiraExpr},
                 actualizado_en = {$nowExpr}
             WHERE id = :id"
        );
        $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':codigo_hash', $codigoHash, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttlMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function updateEmailForVerification(
        int $usuarioId,
        string $nuevoEmail,
        string $tokenHash,
        string $codigoHash,
        int $ttlMinutos = 10080
    ): ?array {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $expiraExpr = self::dateAddMinutesSql(':ttl');

        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET email = :email,
                 email_verificado = 0,
                 email_verificado_en = NULL,
                 email_verificacion_token_hash = :token_hash,
                 email_verificacion_codigo_hash = :codigo_hash,
                 email_verificacion_expira_en = {$expiraExpr},
                 actualizado_en = {$nowExpr}
             WHERE id = :id"
        );
        $stmt->bindValue(':email', mb_substr(strtolower(trim($nuevoEmail)), 0, 120, 'UTF-8'), PDO::PARAM_STR);
        $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':codigo_hash', $codigoHash, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttlMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();

        return self::findById($usuarioId);
    }

    public static function verifyEmailByToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', strtolower(trim($rawToken)));
        $pdo = Database::getConnection();
        $top = self::isSqlSrv() ? 'TOP (1) ' : '';
        $limit = self::isSqlSrv() ? '' : ' LIMIT 1';
        $nowExpr = self::nowSql();

        $stmt = $pdo->prepare(
            "SELECT {$top}u.* FROM usuarios u
             WHERE u.email_verificacion_token_hash = :hash
               AND u.email_verificacion_expira_en >= {$nowExpr}{$limit}"
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE usuarios
             SET email_verificado = 1,
                 email_verificado_en = {$nowExpr},
                 email_verificacion_token_hash = NULL,
                 email_verificacion_codigo_hash = NULL,
                 email_verificacion_expira_en = NULL,
                 actualizado_en = {$nowExpr}
             WHERE id = :id
               AND email_verificacion_token_hash = :hash"
        );
        $upd->execute([':id' => (int)$row['id'], ':hash' => $tokenHash]);
        if ($upd->rowCount() !== 1) {
            return null;
        }

        return self::findById((int)$row['id']);
    }

    public static function verifyEmailByCode(string $email, string $codigo): ?array
    {
        $user = self::findByEmail($email);
        if (!$user || empty($user['email_verificacion_codigo_hash'])) {
            return null;
        }

        $pdo = Database::getConnection();
        $top = self::isSqlSrv() ? 'TOP (1) ' : '';
        $limit = self::isSqlSrv() ? '' : ' LIMIT 1';
        $nowExpr = self::nowSql();

        $stmt = $pdo->prepare(
            "SELECT {$top}email_verificacion_codigo_hash
             FROM usuarios
             WHERE id = :id
               AND email_verificacion_expira_en >= {$nowExpr}{$limit}"
        );
        $stmt->execute([':id' => (int)$user['id']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila || !password_verify(trim($codigo), (string)$fila['email_verificacion_codigo_hash'])) {
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE usuarios
             SET email_verificado = 1,
                 email_verificado_en = {$nowExpr},
                 email_verificacion_token_hash = NULL,
                 email_verificacion_codigo_hash = NULL,
                 email_verificacion_expira_en = NULL,
                 actualizado_en = {$nowExpr}
             WHERE id = :id
               AND email_verificacion_codigo_hash IS NOT NULL"
        );
        $upd->execute([':id' => (int)$user['id']]);
        if ($upd->rowCount() !== 1) {
            return null;
        }

        return self::findById((int)$user['id']);
    }

    public static function linkGoogleAccount(int $usuarioId, string $googleId): ?array
    {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET google_id = COALESCE(NULLIF(google_id, ''), :gid),
                 email_verificado = 1,
                 email_verificado_en = COALESCE(email_verificado_en, {$nowExpr}),
                 actualizado_en = {$nowExpr}
             WHERE id = :id"
        );
        $stmt->execute([
            ':gid' => mb_substr(trim($googleId), 0, 128, 'UTF-8'),
            ':id'  => $usuarioId,
        ]);
        return self::findById($usuarioId);
    }

    public static function createMagicLink(
        int $usuarioId,
        string $tokenHash,
        string $codigoHash,
        int $ttlMinutos,
        string $ip
    ): void {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $expiraExpr = self::dateAddMinutesSql(':ttl');

        $pdo->prepare("UPDATE magic_links SET usado_en = {$nowExpr} WHERE usuario_id = :uid AND usado_en IS NULL")
            ->execute([':uid' => $usuarioId]);

        $stmt = $pdo->prepare(
            "INSERT INTO magic_links (usuario_id, token_hash, codigo_hash, expira_en, ip_solicitud)
             VALUES (:uid, :token_hash, :codigo_hash, {$expiraExpr}, :ip)"
        );
        $stmt->bindValue(':uid', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':codigo_hash', $codigoHash, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttlMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
    }

    public static function consumeMagicLink(?string $rawToken = null, ?string $email = null, ?string $codigo = null): ?array
    {
        $pdo = Database::getConnection();
        $isSqlSrv = self::isSqlSrv();
        $nowExpr = self::nowSql();

        if ($rawToken !== null && trim($rawToken) !== '') {
            $tokenHash = hash('sha256', strtolower(trim($rawToken)));
            $pdo->beginTransaction();
            try {
                $sqlSelect = $isSqlSrv
                    ? "SELECT TOP (1) id, usuario_id
                       FROM magic_links WITH (UPDLOCK, ROWLOCK)
                       WHERE token_hash = :hash
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}"
                    : "SELECT id, usuario_id
                       FROM magic_links
                       WHERE token_hash = :hash
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}
                       LIMIT 1
                       FOR UPDATE";

                $stmt = $pdo->prepare($sqlSelect);
                $stmt->execute([':hash' => $tokenHash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->rollBack();
                    return null;
                }

                $upd = $pdo->prepare("UPDATE magic_links SET usado_en = {$nowExpr} WHERE id = :id AND usado_en IS NULL");
                $upd->execute([':id' => (int)$row['id']]);
                if ($upd->rowCount() !== 1) {
                    $pdo->rollBack();
                    return null;
                }

                $pdo->prepare("UPDATE usuarios SET email_verificado = 1, email_verificado_en = COALESCE(email_verificado_en, {$nowExpr}), actualizado_en = {$nowExpr} WHERE id = :uid")
                    ->execute([':uid' => (int)$row['usuario_id']]);

                $pdo->commit();
                return self::findById((int)$row['usuario_id']);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($email !== null && $codigo !== null) {
            $user = self::findByEmail($email);
            if (!$user) {
                return null;
            }

            $pdo->beginTransaction();
            try {
                $sqlSelect = $isSqlSrv
                    ? "SELECT TOP (1) id, codigo_hash, intentos
                       FROM magic_links WITH (UPDLOCK, ROWLOCK)
                       WHERE usuario_id = :uid
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}
                       ORDER BY id DESC"
                    : "SELECT id, codigo_hash, intentos
                       FROM magic_links
                       WHERE usuario_id = :uid
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}
                       ORDER BY id DESC
                       LIMIT 1
                       FOR UPDATE";

                $stmt = $pdo->prepare($sqlSelect);
                $stmt->execute([':uid' => (int)$user['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['intentos'] >= 5) {
                    $pdo->rollBack();
                    return null;
                }

                if (!password_verify(trim($codigo), (string)$row['codigo_hash'])) {
                    $pdo->prepare('UPDATE magic_links SET intentos = intentos + 1 WHERE id = :id')
                        ->execute([':id' => (int)$row['id']]);
                    $pdo->commit();
                    return null;
                }

                $upd = $pdo->prepare("UPDATE magic_links SET usado_en = {$nowExpr} WHERE id = :id AND usado_en IS NULL");
                $upd->execute([':id' => (int)$row['id']]);
                if ($upd->rowCount() !== 1) {
                    $pdo->rollBack();
                    return null;
                }

                $pdo->prepare("UPDATE usuarios SET email_verificado = 1, email_verificado_en = COALESCE(email_verificado_en, {$nowExpr}), actualizado_en = {$nowExpr} WHERE id = :uid")
                    ->execute([':uid' => (int)$user['id']]);

                $pdo->commit();
                return self::findById((int)$user['id']);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return null;
    }

    public static function createPasswordReset(
        int $usuarioId,
        string $tokenHash,
        string $codigoHash,
        int $ttlMinutos,
        string $ip
    ): void {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $expiraExpr = self::dateAddMinutesSql(':ttl');

        $pdo->prepare("UPDATE password_resets SET usado_en = {$nowExpr} WHERE usuario_id = :uid AND usado_en IS NULL")
            ->execute([':uid' => $usuarioId]);

        $stmt = $pdo->prepare(
            "INSERT INTO password_resets (usuario_id, token_hash, codigo_hash, expira_en, ip_solicitud)
             VALUES (:uid, :token_hash, :codigo_hash, {$expiraExpr}, :ip)"
        );
        $stmt->bindValue(':uid', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':codigo_hash', $codigoHash, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttlMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
    }

    public static function consumePasswordReset(
        string $nuevaPasswordHash,
        ?string $rawToken = null,
        ?string $email = null,
        ?string $codigo = null
    ): ?array {
        $pdo = Database::getConnection();
        $isSqlSrv = self::isSqlSrv();
        $nowExpr = self::nowSql();
        $usuarioId = 0;
        $resetId = 0;

        $pdo->beginTransaction();
        try {
            if ($rawToken !== null && trim($rawToken) !== '') {
                $tokenHash = hash('sha256', strtolower(trim($rawToken)));
                $sqlSelect = $isSqlSrv
                    ? "SELECT TOP (1) id, usuario_id
                       FROM password_resets WITH (UPDLOCK, ROWLOCK)
                       WHERE token_hash = :hash
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}"
                    : "SELECT id, usuario_id
                       FROM password_resets
                       WHERE token_hash = :hash
                         AND usado_en IS NULL
                         AND expira_en >= {$nowExpr}
                       LIMIT 1
                       FOR UPDATE";

                $stmt = $pdo->prepare($sqlSelect);
                $stmt->execute([':hash' => $tokenHash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $resetId = (int)$row['id'];
                    $usuarioId = (int)$row['usuario_id'];
                }
            } elseif ($email !== null && $codigo !== null) {
                $user = self::findByEmail($email);
                if ($user) {
                    $sqlSelect = $isSqlSrv
                        ? "SELECT TOP (1) id, codigo_hash, intentos
                           FROM password_resets WITH (UPDLOCK, ROWLOCK)
                           WHERE usuario_id = :uid
                             AND usado_en IS NULL
                             AND expira_en >= {$nowExpr}
                           ORDER BY id DESC"
                        : "SELECT id, codigo_hash, intentos
                           FROM password_resets
                           WHERE usuario_id = :uid
                             AND usado_en IS NULL
                             AND expira_en >= {$nowExpr}
                           ORDER BY id DESC
                           LIMIT 1
                           FOR UPDATE";

                    $stmt = $pdo->prepare($sqlSelect);
                    $stmt->execute([':uid' => (int)$user['id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && (int)$row['intentos'] < 5) {
                        if (password_verify(trim($codigo), (string)$row['codigo_hash'])) {
                            $resetId = (int)$row['id'];
                            $usuarioId = (int)$user['id'];
                        } else {
                            $pdo->prepare('UPDATE password_resets SET intentos = intentos + 1 WHERE id = :id')
                                ->execute([':id' => (int)$row['id']]);
                            $pdo->commit();
                            return null;
                        }
                    }
                }
            }

            if ($usuarioId <= 0 || $resetId <= 0) {
                $pdo->rollBack();
                return null;
            }

            $upd = $pdo->prepare("UPDATE password_resets SET usado_en = {$nowExpr} WHERE id = :id AND usado_en IS NULL");
            $upd->execute([':id' => $resetId]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            $pdo->prepare(
                "UPDATE usuarios
                 SET password_hash = :hash,
                     email_verificado = 1,
                     email_verificado_en = COALESCE(email_verificado_en, {$nowExpr}),
                     actualizado_en = {$nowExpr}
                 WHERE id = :uid"
            )->execute([
                ':hash' => $nuevaPasswordHash,
                ':uid'  => $usuarioId,
            ]);

            $pdo->commit();
            return self::findById($usuarioId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function updateProfile(
        int $usuarioId,
        string $nombre,
        string $ocupacion,
        int $metaDiariaMin,
        ?array $preferencias = null
    ): ?array {
        $pdo = Database::getConnection();
        $actual = self::findById($usuarioId);
        if (!$actual) {
            return null;
        }

        $ocupacionValida = in_array($ocupacion, self::OCUPACIONES_VALIDAS, true)
            ? $ocupacion
            : (string)$actual['ocupacion'];
        $metaValida = max(5, min(120, $metaDiariaMin));

        $prefsFusionadas = is_array($actual['preferencias']) ? $actual['preferencias'] : self::PREFERENCIAS_DEFAULT;
        if (is_array($preferencias)) {
            $prefsFusionadas = self::sanitizePreferences(array_merge($prefsFusionadas, $preferencias));
        }

        $nowExpr = self::nowSql();
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET nombre = :nombre,
                 ocupacion = :ocupacion,
                 meta_diaria_min = :meta,
                 preferencias_json = :prefs,
                 actualizado_en = {$nowExpr}
             WHERE id = :id"
        );
        $stmt->execute([
            ':nombre'    => mb_substr(trim($nombre), 0, 100, 'UTF-8'),
            ':ocupacion' => $ocupacionValida,
            ':meta'      => $metaValida,
            ':prefs'     => json_encode($prefsFusionadas, JSON_UNESCAPED_UNICODE),
            ':id'        => $usuarioId,
        ]);

        return self::findById($usuarioId);
    }

    public static function updatePreferences(int $usuarioId, array $nuevasPreferencias): ?array
    {
        $actual = self::findById($usuarioId);
        if (!$actual) {
            return null;
        }

        $prefsBase = is_array($actual['preferencias']) ? $actual['preferencias'] : self::PREFERENCIAS_DEFAULT;
        $prefsLimpias = self::sanitizePreferences(array_merge($prefsBase, $nuevasPreferencias));

        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $stmt = $pdo->prepare("UPDATE usuarios SET preferencias_json = :prefs, actualizado_en = {$nowExpr} WHERE id = :id");
        $stmt->execute([
            ':prefs' => json_encode($prefsLimpias, JSON_UNESCAPED_UNICODE),
            ':id'    => $usuarioId,
        ]);

        return self::findById($usuarioId);
    }

    public static function updatePassword(int $usuarioId, string $nuevoHash): void
    {
        $pdo = Database::getConnection();
        $nowExpr = self::nowSql();
        $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = :hash, actualizado_en = {$nowExpr} WHERE id = :id");
        $stmt->execute([
            ':hash' => $nuevoHash,
            ':id'   => $usuarioId,
        ]);
    }

    /**
     * Actualiza la racha diaria del usuario al completar una pausa activa.
     *
     * @return array{racha_actual:int,mejor_racha:int}
     */
    public static function updateStreakAfterPause(int $usuarioId): array
    {
        $pdo = Database::getConnection();
        $isSqlSrv = self::isSqlSrv();
        $top = $isSqlSrv ? 'TOP (1) ' : '';
        $limit = $isSqlSrv ? '' : ' LIMIT 1';
        $diffExpr = $isSqlSrv
            ? 'DATEDIFF(DAY, ultima_pausa_fecha, CAST(GETUTCDATE() AS DATE))'
            : 'DATEDIFF(CURDATE(), ultima_pausa_fecha)';
        $todayExpr = self::todaySql();
        $nowExpr = self::nowSql();

        $stmt = $pdo->prepare(
            "SELECT {$top}racha_actual, mejor_racha, ultima_pausa_fecha,
                    {$diffExpr} AS dias_diferencia
             FROM usuarios
             WHERE id = :id{$limit}"
        );
        $stmt->execute([':id' => $usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['racha_actual' => 1, 'mejor_racha' => 1];
        }

        $rachaActual = (int)$row['racha_actual'];
        $mejorRacha  = (int)$row['mejor_racha'];
        $diasDif     = $row['ultima_pausa_fecha'] !== null ? (int)$row['dias_diferencia'] : null;

        if ($diasDif === null || $diasDif > 1 || $diasDif < 0) {
            $rachaActual = 1;
        } elseif ($diasDif === 1) {
            $rachaActual++;
        } elseif ($rachaActual === 0) {
            $rachaActual = 1;
        }

        if ($rachaActual > $mejorRacha) {
            $mejorRacha = $rachaActual;
        }

        $upd = $pdo->prepare(
            "UPDATE usuarios
             SET racha_actual = :racha,
                 mejor_racha = :mejor,
                 ultima_pausa_fecha = {$todayExpr},
                 actualizado_en = {$nowExpr}
             WHERE id = :id"
        );
        $upd->execute([
            ':racha' => $rachaActual,
            ':mejor' => $mejorRacha,
            ':id'    => $usuarioId,
        ]);

        return [
            'racha_actual' => $rachaActual,
            'mejor_racha'  => $mejorRacha,
        ];
    }

    public static function deleteAccount(int $usuarioId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $usuarioId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $prefs
     * @return array<string, mixed>
     */
    public static function sanitizePreferences(array $prefs): array
    {
        $escalaRaw = (string)($prefs['escala_fuente'] ?? 'normal');
        if ($escalaRaw === 'muy-grande' || $escalaRaw === 'muy_grande') {
            $escala = 'muy_grande';
        } elseif ($escalaRaw === 'grande') {
            $escala = 'grande';
        } else {
            $escala = 'normal';
        }

        $frecuenciaRaw = $prefs['frecuencia_recordatorio_min'] ?? ($prefs['frecuencia_pausa_min'] ?? 45);
        $frecuencia = (int)$frecuenciaRaw;
        if (!in_array($frecuencia, [0, 20, 30, 45, 60, 90, 120], true)) {
            $frecuencia = 45;
        }

        $ubicaciones = ['computadora', 'estudio', 'reuniones', 'parque'];
        $ubicacionDef = (string)($prefs['ubicacion_defecto'] ?? 'computadora');
        if (!in_array($ubicacionDef, $ubicaciones, true)) {
            $ubicacionDef = 'computadora';
        }

        $tiempoDef = isset($prefs['tiempo_defecto']) ? (int)$prefs['tiempo_defecto'] : 3;
        if (!in_array($tiempoDef, [3, 5, 10, 15], true)) {
            $tiempoDef = 3;
        }

        $objetivos = ['moverse', 'relajarse', 'concentrarse', 'despejarse'];
        $objetivoDef = (string)($prefs['objetivo_defecto'] ?? 'relajarse');
        if (!in_array($objetivoDef, $objetivos, true)) {
            $objetivoDef = 'relajarse';
        }

        $fotoPerfil = isset($prefs['foto_perfil']) && is_string($prefs['foto_perfil'])
            ? trim($prefs['foto_perfil'])
            : '';
        // Permitir Data URL de imagen segura (JPEG/PNG/WebP) hasta 250 KB (256 000 bytes) o ruta relativa segura
        $esDataUrlSegura = (bool)preg_match('#^data:image/(jpeg|jpg|png|webp);base64,[A-Za-z0-9+/=]+$#i', $fotoPerfil);
        $esRutaLocalSegura = !str_contains($fotoPerfil, '..')
            && (bool)preg_match('#^assets/img/[a-zA-Z0-9_\-/]+\.(jpg|jpeg|png|webp)$#i', $fotoPerfil);
        if ($fotoPerfil !== '' && (strlen($fotoPerfil) > 256000 || (!$esDataUrlSegura && !$esRutaLocalSegura))) {
            $fotoPerfil = '';
        }

        $avatar = isset($prefs['avatar']) && is_string($prefs['avatar']) && mb_strlen(trim($prefs['avatar']), 'UTF-8') <= 8
            ? trim($prefs['avatar'])
            : '🧘';

        return [
            'alto_contraste'              => !empty($prefs['alto_contraste']),
            'escala_fuente'               => $escala,
            'reducir_movimiento'          => !empty($prefs['reducir_movimiento']),
            'alertas_sonoras'             => array_key_exists('alertas_sonoras', $prefs) ? (bool)$prefs['alertas_sonoras'] : true,
            'narrador_voz'                => !empty($prefs['narrador_voz']) || !empty($prefs['voz_guiada']),
            'modo_sentado'                => !empty($prefs['modo_sentado']),
            'frecuencia_recordatorio_min' => $frecuencia,
            'notificaciones_email'        => array_key_exists('notificaciones_email', $prefs) ? (bool)$prefs['notificaciones_email'] : true,
            'tema_claro'                  => array_key_exists('tema_claro', $prefs) ? !empty($prefs['tema_claro']) : true,
            'fuente_legible'              => !empty($prefs['fuente_legible']),
            'subrayar_enlaces'            => !empty($prefs['subrayar_enlaces']),
            'avatar'                      => $avatar,
            'foto_perfil'                 => $fotoPerfil,
            'ubicacion_defecto'           => $ubicacionDef,
            'tiempo_defecto'              => $tiempoDef,
            'objetivo_defecto'            => $objetivoDef,
            'chatbot_habilitado'          => array_key_exists('chatbot_habilitado', $prefs) ? (bool)$prefs['chatbot_habilitado'] : true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateUser(array $row): array
    {
        $prefsRaw = isset($row['preferencias_json']) && is_string($row['preferencias_json'])
            ? json_decode($row['preferencias_json'], true)
            : null;

        $row['id']                   = (int)$row['id'];
        $row['email_verificado']     = (bool)$row['email_verificado'];
        $row['activo']               = array_key_exists('activo', $row) ? (bool)$row['activo'] : true;
        $row['horas_desde_registro'] = max(0, (int)($row['horas_desde_registro'] ?? 0));
        $row['racha_actual']         = (int)($row['racha_actual'] ?? 0);
        $row['mejor_racha']          = (int)($row['mejor_racha'] ?? 0);
        $row['meta_diaria_min']      = (int)($row['meta_diaria_min'] ?? 15);
        $row['preferencias']         = self::sanitizePreferences(
            is_array($prefsRaw) ? array_merge(self::PREFERENCIAS_DEFAULT, $prefsRaw) : self::PREFERENCIAS_DEFAULT
        );

        return $row;
    }

    /**
     * Devuelve la proyección pública segura del usuario (sin hashes de contraseña ni tokens),
     * incluyendo el contador del periodo de gracia de 7 días (168 horas) cuando `email_verificado` es `false`.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function toPublicArray(array $user): array
    {
        $verificado = (bool)($user['email_verificado'] ?? false);
        $horasDesde = max(0, (int)($user['horas_desde_registro'] ?? 0));
        $horasRestantes = !$verificado ? max(0, 168 - $horasDesde) : null;
        $diasRestantes  = !$verificado ? max(1, (int)ceil($horasRestantes / 24)) : null;

        return [
            'id'                           => (int)($user['id'] ?? 0),
            'nombre'                       => (string)($user['nombre'] ?? ''),
            'email'                        => (string)($user['email'] ?? ''),
            'rol'                          => (string)($user['rol'] ?? 'usuario'),
            'ocupacion'                    => (string)($user['ocupacion'] ?? 'estudiante'),
            'email_verificado'             => $verificado,
            'activo'                       => (bool)($user['activo'] ?? true),
            'horas_restantes_verificacion' => $horasRestantes,
            'dias_restantes_verificacion'  => $diasRestantes,
            'racha_actual'                 => (int)($user['racha_actual'] ?? 0),
            'mejor_racha'                  => (int)($user['mejor_racha'] ?? 0),
            'ultima_pausa_fecha'           => $user['ultima_pausa_fecha'] ?? null,
            'meta_diaria_min'              => (int)($user['meta_diaria_min'] ?? 15),
            'preferencias'                 => $user['preferencias'] ?? self::PREFERENCIAS_DEFAULT,
            'creado_en'                    => $user['creado_en'] ?? null,
        ];
    }
}
