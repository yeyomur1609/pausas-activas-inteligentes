-- =========================================================================
-- ARCHIVO: backend/database/seed-demo.sql
-- T-SQL compatible con Azure SQL Database (SQL Server)
-- SOLO PARA DESARROLLO, DEMO Y PRUEBAS — CAMBIAR CONTRASEÑAS EN PRODUCCIÓN
-- Nota: Ejecutar conectado directamente a la base de datos en Azure SQL
--       después de importar backend/database/schema.sql.
-- =========================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- 1. Cuentas demo (contraseña temporal de pruebas: admin12345)
SET IDENTITY_INSERT dbo.usuarios ON;

MERGE dbo.usuarios AS target
USING (VALUES
    (
        1,
        N'Administrador del Sistema',
        N'admin@pausasactivas.edu.mx',
        N'$2y$10$JM8DhT5Ioo6iB2wjgNKlyeT4neZCTd1ouat6UKsP1QcFeqMP4mF1q',
        N'admin',
        N'trabajador_oficina',
        1,
        1,
        SYSUTCDATETIME(),
        5,
        12,
        CAST(GETUTCDATE() AS DATE),
        20,
        N'{"alto_contraste":false,"escala_fuente":"normal","reducir_movimiento":false,"alertas_sonoras":true,"narrador_voz":false,"modo_sentado":false,"frecuencia_recordatorio_min":45}',
        1
    ),
    (
        2,
        N'Andrea Villarreal (Estudiante)',
        N'estudiante@pausasactivas.edu.mx',
        N'$2y$10$JM8DhT5Ioo6iB2wjgNKlyeT4neZCTd1ouat6UKsP1QcFeqMP4mF1q',
        N'usuario',
        N'estudiante',
        1,
        1,
        SYSUTCDATETIME(),
        3,
        7,
        CAST(GETUTCDATE() AS DATE),
        15,
        N'{"alto_contraste":false,"escala_fuente":"normal","reducir_movimiento":false,"alertas_sonoras":true,"narrador_voz":false,"modo_sentado":false,"frecuencia_recordatorio_min":30}',
        1
    )
) AS source (
    id, nombre, email, password_hash, rol, ocupacion,
    email_verificado, activo, email_verificado_en, racha_actual, mejor_racha,
    ultima_pausa_fecha, meta_diaria_min, preferencias_json, aviso_privacidad_aceptado
)
ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        nombre = source.nombre,
        email = source.email,
        rol = source.rol,
        ocupacion = source.ocupacion,
        email_verificado = 1,
        activo = 1,
        actualizado_en = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (
        id, nombre, email, password_hash, rol, ocupacion,
        email_verificado, activo, email_verificado_en, racha_actual, mejor_racha,
        ultima_pausa_fecha, meta_diaria_min, preferencias_json, aviso_privacidad_aceptado
    )
    VALUES (
        source.id, source.nombre, source.email, source.password_hash, source.rol, source.ocupacion,
        source.email_verificado, source.activo, source.email_verificado_en, source.racha_actual, source.mejor_racha,
        source.ultima_pausa_fecha, source.meta_diaria_min, source.preferencias_json, source.aviso_privacidad_aceptado
    );

SET IDENTITY_INSERT dbo.usuarios OFF;
GO

-- 2. Parques favoritos demo
SET IDENTITY_INSERT dbo.parques_favoritos ON;

MERGE dbo.parques_favoritos AS target
USING (VALUES
    (
        1, 2, N'osm_sc_lineal_101',
        N'Parque Público Lineal Santa Catarina',
        CAST(25.6768000 AS DECIMAL(10,7)), CAST(-100.4612000 AS DECIMAL(10,7)),
        N'Av. Manuel Ordóñez, Centro de Santa Catarina, N.L.',
        N'["Andadores peatonales","Aparatos de ejercicio","Bancas con sombra","Iluminación LED","Rampas accesibles"]',
        1,
        N'Ideal para caminatas de 10 minutos y estiramientos al atardecer.',
        NULL
    ),
    (
        2, 2, N'osm_sc_hundido_102',
        N'Parque Público Hundido Las Sombras',
        CAST(25.6719000 AS DECIMAL(10,7)), CAST(-100.4534000 AS DECIMAL(10,7)),
        N'Col. Las Sombras, Santa Catarina, N.L.',
        N'["Áreas arboladas","Bancas de descanso","Cancha multiusos","Sendero pavimentado"]',
        1,
        N'Excelente sombra natural para pausas visuales y respiración.',
        NULL
    )
) AS source (
    id, usuario_id, parque_osm_id, nombre, latitud, longitud,
    direccion, equipamiento_json, accesibilidad_silla, notas, foto_url
)
ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        nombre = source.nombre,
        equipamiento_json = source.equipamiento_json
WHEN NOT MATCHED THEN
    INSERT (
        id, usuario_id, parque_osm_id, nombre, latitud, longitud,
        direccion, equipamiento_json, accesibilidad_silla, notas, foto_url
    )
    VALUES (
        source.id, source.usuario_id, source.parque_osm_id, source.nombre, source.latitud, source.longitud,
        source.direccion, source.equipamiento_json, source.accesibilidad_silla, source.notas, source.foto_url
    );

SET IDENTITY_INSERT dbo.parques_favoritos OFF;
GO

-- 3. Historial demo inicial
SET IDENTITY_INSERT dbo.historial_pausas ON;

MERGE dbo.historial_pausas AS target
USING (VALUES
    (1, 2, 1, N'Parque Público Lineal Santa Catarina', N'osm_sc_lineal_101', N'estudio', N'relajarse', 3, 2, 5, 0, DATEADD(DAY, -2, SYSUTCDATETIME())),
    (2, 2, 2, N'Biblioteca / Escritorio de Estudio', NULL, N'estudio', N'concentrarse', 5, 3, 5, 1, DATEADD(DAY, -1, SYSUTCDATETIME())),
    (3, 2, 3, N'Parque Público Hundido Las Sombras', N'osm_sc_hundido_102', N'parque', N'despejarse', 10, 3, 5, 0, SYSUTCDATETIME()),
    (4, 1, 1, N'Oficina Principal', NULL, N'computadora', N'moverse', 5, 3, 5, 0, SYSUTCDATETIME())
) AS source (
    id, usuario_id, rutina_id, ubicacion_nombre, parque_osm_id,
    actividad_realizada, necesidad_atendida, tiempo_minutos, esfuerzo, calificacion,
    completada_sentado, fecha_pausa
)
ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        esfuerzo = source.esfuerzo,
        calificacion = source.calificacion
WHEN NOT MATCHED THEN
    INSERT (
        id, usuario_id, rutina_id, ubicacion_nombre, parque_osm_id,
        actividad_realizada, necesidad_atendida, tiempo_minutos, esfuerzo, calificacion,
        completada_sentado, fecha_pausa
    )
    VALUES (
        source.id, source.usuario_id, source.rutina_id, source.ubicacion_nombre, source.parque_osm_id,
        source.actividad_realizada, source.necesidad_atendida, source.tiempo_minutos, source.esfuerzo, source.calificacion,
        source.completada_sentado, source.fecha_pausa
    );

SET IDENTITY_INSERT dbo.historial_pausas OFF;
GO
