<?php
// ARCHIVO: scripts/reset-demo-users.php
// Script CLI idempotente para restaurar las cuentas predeterminadas de prueba/administración
// con contraseña temporal "admin12345" (hash PASSWORD_BCRYPT real) y email_verificado = 1.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once dirname(__DIR__) . '/backend/config/database.php';

use App\Config\Database;
use App\Config\Secrets;

if (Secrets::get('APP_ENV', 'development') === 'production' && !in_array('--force', $argv ?? [], true)) {
    fwrite(STDERR, "Bloqueado: no se deben restaurar usuarios demo en APP_ENV=production (usa --force solo si es intencional).\n");
    exit(1);
}

$pdo = Database::getConnection();
$isSqlSrv = Database::isSqlSrv();

// 1. Garantizar que exista la columna `activo` en instalaciones previas
if ($isSqlSrv) {
    $colCheck = $pdo->query("SELECT COL_LENGTH('dbo.usuarios', 'activo')")->fetchColumn();
    if ($colCheck === null || $colCheck === false) {
        $pdo->exec('ALTER TABLE dbo.usuarios ADD activo BIT NOT NULL CONSTRAINT DF_usuarios_activo_mig DEFAULT 1');
        echo "[MIGRACIÓN][Azure SQL] Columna 'activo' agregada a dbo.usuarios.\n";
    }
} else {
    $colCheck = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'activo'")->fetch(PDO::FETCH_ASSOC);
    if (!$colCheck) {
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verificado');
        echo "[MIGRACIÓN][MySQL] Columna 'activo' agregada a la tabla usuarios.\n";
    }
}

// 2. Generar hash PASSWORD_BCRYPT real para 'admin12345'
$plainPassword = 'admin12345';
$passwordHash  = password_hash($plainPassword, PASSWORD_BCRYPT);

$demoAccounts = [
    [
        'id'        => 1,
        'nombre'    => 'Administrador del Sistema',
        'email'     => 'admin@pausasactivas.edu.mx',
        'legacy'    => 'admin@pausas.com',
        'rol'       => 'admin',
        'ocupacion' => 'trabajador_oficina',
        'racha'     => 5,
        'mejor'     => 12,
        'meta'      => 20,
        'prefs'     => '{"alto_contraste":false,"escala_fuente":"normal","reducir_movimiento":false,"alertas_sonoras":true,"narrador_voz":false,"modo_sentado":false,"frecuencia_recordatorio_min":45}',
    ],
    [
        'id'        => 2,
        'nombre'    => 'Andrea Villarreal (Estudiante)',
        'email'     => 'estudiante@pausasactivas.edu.mx',
        'legacy'    => 'estudiante@pausas.com',
        'rol'       => 'usuario',
        'ocupacion' => 'estudiante',
        'racha'     => 3,
        'mejor'     => 7,
        'meta'      => 15,
        'prefs'     => '{"alto_contraste":false,"escala_fuente":"normal","reducir_movimiento":false,"alertas_sonoras":true,"narrador_voz":false,"modo_sentado":false,"frecuencia_recordatorio_min":30}',
    ],
];

foreach ($demoAccounts as $acc) {
    // Evitar conflicto de UNIQUE(email) si existiera en otro ID
    $dupSql = $isSqlSrv
        ? 'SELECT TOP (1) id FROM dbo.usuarios WHERE email = :email AND id <> :id'
        : 'SELECT id FROM usuarios WHERE email = :email AND id <> :id LIMIT 1';
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute([':email' => $acc['email'], ':id' => $acc['id']]);
    if ($dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute([':id' => (int)$dupRow['id']]);
    }

    if ($isSqlSrv) {
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET IDENTITY_INSERT dbo.usuarios ON');
            $upsert = $pdo->prepare(
                'MERGE dbo.usuarios WITH (HOLDLOCK) AS target
                 USING (
                     SELECT
                         :id        AS id,
                         :nombre    AS nombre,
                         :email     AS email,
                         :hash      AS password_hash,
                         :rol       AS rol,
                         :ocupacion AS ocupacion,
                         :racha     AS racha_actual,
                         :mejor     AS mejor_racha,
                         :meta      AS meta_diaria_min,
                         :prefs     AS preferencias_json
                 ) AS source
                    ON target.id = source.id
                 WHEN MATCHED THEN
                     UPDATE SET
                         nombre                    = source.nombre,
                         email                     = source.email,
                         password_hash             = source.password_hash,
                         rol                       = source.rol,
                         ocupacion                 = source.ocupacion,
                         email_verificado          = 1,
                         activo                    = 1,
                         email_verificado_en       = COALESCE(target.email_verificado_en, SYSUTCDATETIME()),
                         preferencias_json         = COALESCE(target.preferencias_json, source.preferencias_json),
                         aviso_privacidad_aceptado = 1,
                         actualizado_en            = SYSUTCDATETIME()
                 WHEN NOT MATCHED THEN
                     INSERT (
                         id, nombre, email, password_hash, rol, ocupacion,
                         email_verificado, activo, email_verificado_en,
                         racha_actual, mejor_racha, ultima_pausa_fecha,
                         meta_diaria_min, preferencias_json, aviso_privacidad_aceptado
                     ) VALUES (
                         source.id, source.nombre, source.email, source.password_hash, source.rol, source.ocupacion,
                         1, 1, SYSUTCDATETIME(),
                         source.racha_actual, source.mejor_racha, CAST(GETUTCDATE() AS DATE),
                         source.meta_diaria_min, source.preferencias_json, 1
                     );'
            );
            $upsert->execute([
                ':id'        => $acc['id'],
                ':nombre'    => $acc['nombre'],
                ':email'     => $acc['email'],
                ':hash'      => $passwordHash,
                ':rol'       => $acc['rol'],
                ':ocupacion' => $acc['ocupacion'],
                ':racha'     => $acc['racha'],
                ':mejor'     => $acc['mejor'],
                ':meta'      => $acc['meta'],
                ':prefs'     => $acc['prefs'],
            ]);
            $pdo->exec('SET IDENTITY_INSERT dbo.usuarios OFF');
            $pdo->commit();
        } catch (\Throwable $e) {
            try {
                $pdo->exec('SET IDENTITY_INSERT dbo.usuarios OFF');
            } catch (\Throwable $_ignored) {
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } else {
        $upsert = $pdo->prepare(
            'INSERT INTO usuarios (
                id, nombre, email, password_hash, rol, ocupacion,
                email_verificado, activo, email_verificado_en,
                racha_actual, mejor_racha, ultima_pausa_fecha,
                meta_diaria_min, preferencias_json, aviso_privacidad_aceptado
            ) VALUES (
                :id, :nombre, :email, :hash, :rol, :ocupacion,
                1, 1, NOW(),
                :racha, :mejor, CURDATE(),
                :meta, :prefs, 1
            )
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                email = VALUES(email),
                password_hash = VALUES(password_hash),
                rol = VALUES(rol),
                ocupacion = VALUES(ocupacion),
                email_verificado = 1,
                activo = 1,
                email_verificado_en = COALESCE(usuarios.email_verificado_en, NOW()),
                preferencias_json = COALESCE(usuarios.preferencias_json, VALUES(preferencias_json)),
                aviso_privacidad_aceptado = 1'
        );

        $upsert->execute([
            ':id'        => $acc['id'],
            ':nombre'    => $acc['nombre'],
            ':email'     => $acc['email'],
            ':hash'      => $passwordHash,
            ':rol'       => $acc['rol'],
            ':ocupacion' => $acc['ocupacion'],
            ':racha'     => $acc['racha'],
            ':mejor'     => $acc['mejor'],
            ':meta'      => $acc['meta'],
            ':prefs'     => $acc['prefs'],
        ]);
    }
}

// 3. Verificación final en base de datos
$verifyStmt = $pdo->query(
    "SELECT id, nombre, email, rol, ocupacion, email_verificado, activo, password_hash
     FROM usuarios
     WHERE email IN ('admin@pausasactivas.edu.mx', 'estudiante@pausasactivas.edu.mx')
     ORDER BY id ASC"
);
$rows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("=== Cuentas predeterminadas restauradas [%s] (clave temporal: %s) ===\n", strtoupper(Database::driver()), $plainPassword);
foreach ($rows as $row) {
    $hashOk = password_verify($plainPassword, (string)$row['password_hash']) ? 'BCRYPT_OK' : 'BCRYPT_FAIL';
    echo sprintf(
        "ID=%d | %s | rol=%s | verificado=%d | activo=%d | %s\n",
        (int)$row['id'],
        $row['email'],
        $row['rol'],
        (int)$row['email_verificado'],
        (int)$row['activo'],
        $hashOk
    );
}
