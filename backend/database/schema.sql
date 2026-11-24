-- =========================================================================
-- ARCHIVO: backend/database/schema.sql
-- Base de Datos Azure SQL Database (T-SQL / SQL Server) Lista para Producción
-- Proyecto: Pausas Activas Inteligentes (Vercel + Render + Azure SQL)
-- Nota: Ejecutar conectado directamente a la base de datos objetivo en Azure SQL
--       (Azure Data Studio, SSMS o Azure Portal Query Editor). Script 100% idempotente.
-- =========================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- -------------------------------------------------------------------------
-- 1. TABLA: dbo.usuarios
-- Credenciales Bcrypt, verificación de correo (7 días), Google OAuth,
-- rachas de bienestar y preferencias de accesibilidad JSON.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.usuarios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.usuarios (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_usuarios PRIMARY KEY,
        nombre NVARCHAR(100) NOT NULL,
        email NVARCHAR(120) NOT NULL CONSTRAINT UQ_usuarios_email UNIQUE,
        password_hash NVARCHAR(255) NOT NULL,
        rol NVARCHAR(20) NOT NULL CONSTRAINT DF_usuarios_rol DEFAULT N'usuario'
            CONSTRAINT CK_usuarios_rol CHECK (rol IN (N'usuario', N'admin')),
        ocupacion NVARCHAR(40) NOT NULL CONSTRAINT DF_usuarios_ocupacion DEFAULT N'estudiante',
        google_id NVARCHAR(128) NULL,
        email_verificado BIT NOT NULL CONSTRAINT DF_usuarios_email_verificado DEFAULT 0,
        activo BIT NOT NULL CONSTRAINT DF_usuarios_activo DEFAULT 1,
        email_verificacion_token_hash CHAR(64) NULL,
        email_verificacion_codigo_hash NVARCHAR(255) NULL,
        email_verificacion_expira_en DATETIME2(0) NULL,
        email_verificado_en DATETIME2(0) NULL,
        racha_actual INT NOT NULL CONSTRAINT DF_usuarios_racha_actual DEFAULT 0,
        mejor_racha INT NOT NULL CONSTRAINT DF_usuarios_mejor_racha DEFAULT 0,
        ultima_pausa_fecha DATE NULL,
        meta_diaria_min SMALLINT NOT NULL CONSTRAINT DF_usuarios_meta_diaria DEFAULT 15,
        preferencias_json NVARCHAR(MAX) NULL,
        aviso_privacidad_aceptado BIT NOT NULL CONSTRAINT DF_usuarios_privacidad DEFAULT 1,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_usuarios_creado_en DEFAULT SYSUTCDATETIME(),
        actualizado_en DATETIME2(0) NOT NULL CONSTRAINT DF_usuarios_actualizado_en DEFAULT SYSUTCDATETIME()
    );
END;
GO

-- Índice único filtrado en google_id para permitir múltiples NULL sin colisión en SQL Server
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UQ_usuarios_google_id' AND object_id = OBJECT_ID(N'dbo.usuarios'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_usuarios_google_id
        ON dbo.usuarios (google_id)
        WHERE google_id IS NOT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_usuarios_rol' AND object_id = OBJECT_ID(N'dbo.usuarios'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_usuarios_rol ON dbo.usuarios (rol);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_usuarios_token_verif' AND object_id = OBJECT_ID(N'dbo.usuarios'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_usuarios_token_verif
        ON dbo.usuarios (email_verificacion_token_hash)
        WHERE email_verificacion_token_hash IS NOT NULL;
END;
GO

-- -------------------------------------------------------------------------
-- 2. TABLA: dbo.password_resets
-- Solicitudes de restablecimiento de contraseña con token SHA-256 y OTP.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.password_resets', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.password_resets (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_password_resets PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL CONSTRAINT UQ_password_resets_token UNIQUE,
        codigo_hash NVARCHAR(255) NOT NULL,
        intentos TINYINT NOT NULL CONSTRAINT DF_password_resets_intentos DEFAULT 0,
        expira_en DATETIME2(0) NOT NULL,
        usado_en DATETIME2(0) NULL,
        ip_solicitud NVARCHAR(45) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_password_resets_creado_en DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_password_resets_usuario
            FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(id)
            ON DELETE CASCADE
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_reset_usuario_vigencia' AND object_id = OBJECT_ID(N'dbo.password_resets'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_reset_usuario_vigencia
        ON dbo.password_resets (usuario_id, usado_en, expira_en);
END;
GO

-- -------------------------------------------------------------------------
-- 3. TABLA: dbo.magic_links
-- Enlaces mágicos y códigos OTP de acceso sin contraseña (TTL 15 minutos).
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.magic_links', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.magic_links (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_magic_links PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL CONSTRAINT UQ_magic_links_token UNIQUE,
        codigo_hash NVARCHAR(255) NOT NULL,
        intentos TINYINT NOT NULL CONSTRAINT DF_magic_links_intentos DEFAULT 0,
        expira_en DATETIME2(0) NOT NULL,
        usado_en DATETIME2(0) NULL,
        ip_solicitud NVARCHAR(45) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_magic_links_creado_en DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_magic_links_usuario
            FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(id)
            ON DELETE CASCADE
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_magic_usuario_vigencia' AND object_id = OBJECT_ID(N'dbo.magic_links'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_magic_usuario_vigencia
        ON dbo.magic_links (usuario_id, usado_en, expira_en);
END;
GO

-- -------------------------------------------------------------------------
-- 4. TABLA: dbo.rutinas
-- Catálogo maestro de rutinas ergonómicas y al aire libre.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.rutinas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rutinas (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rutinas PRIMARY KEY,
        slug NVARCHAR(80) NOT NULL CONSTRAINT UQ_rutinas_slug UNIQUE,
        titulo NVARCHAR(120) NOT NULL,
        descripcion NVARCHAR(280) NOT NULL,
        categoria NVARCHAR(24) NOT NULL CONSTRAINT DF_rutinas_categoria DEFAULT N'todas'
            CONSTRAINT CK_rutinas_categoria CHECK (categoria IN (N'computadora', N'estudio', N'reuniones', N'parque', N'todas')),
        necesidad NVARCHAR(24) NOT NULL CONSTRAINT DF_rutinas_necesidad DEFAULT N'moverse'
            CONSTRAINT CK_rutinas_necesidad CHECK (necesidad IN (N'moverse', N'relajarse', N'concentrarse', N'despejarse')),
        nivel NVARCHAR(20) NOT NULL CONSTRAINT DF_rutinas_nivel DEFAULT N'suave'
            CONSTRAINT CK_rutinas_nivel CHECK (nivel IN (N'suave', N'moderado', N'activo')),
        apta_movilidad_reducida BIT NOT NULL CONSTRAINT DF_rutinas_silla DEFAULT 1,
        duracion_minutos TINYINT NOT NULL CONSTRAINT DF_rutinas_duracion DEFAULT 3,
        icono NVARCHAR(16) NOT NULL CONSTRAINT DF_rutinas_icono DEFAULT N'🧘',
        activa BIT NOT NULL CONSTRAINT DF_rutinas_activa DEFAULT 1,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_rutinas_creado_en DEFAULT SYSUTCDATETIME()
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_rutinas_filtro' AND object_id = OBJECT_ID(N'dbo.rutinas'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_rutinas_filtro
        ON dbo.rutinas (activa, categoria, necesidad, apta_movilidad_reducida);
END;
GO

-- -------------------------------------------------------------------------
-- 5. TABLA: dbo.rutina_ejercicios
-- Pasos secuenciales de cada rutina (incluye adaptación en silla/sentado).
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.rutina_ejercicios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rutina_ejercicios (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rutina_ejercicios PRIMARY KEY,
        rutina_id INT NOT NULL,
        orden TINYINT NOT NULL CONSTRAINT DF_ejercicios_orden DEFAULT 1,
        titulo NVARCHAR(120) NOT NULL,
        instrucciones NVARCHAR(MAX) NOT NULL,
        instrucciones_sentado NVARCHAR(MAX) NOT NULL,
        duracion_segundos SMALLINT NOT NULL CONSTRAINT DF_ejercicios_duracion DEFAULT 60,
        icono_emoji NVARCHAR(16) NOT NULL CONSTRAINT DF_ejercicios_icono DEFAULT N'🧘',
        beneficio_ergonomico NVARCHAR(180) NOT NULL CONSTRAINT DF_ejercicios_beneficio DEFAULT N'Alivio postural y activación articular',
        CONSTRAINT FK_rutina_ejercicios_rutina
            FOREIGN KEY (rutina_id) REFERENCES dbo.rutinas(id)
            ON DELETE CASCADE
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_ejercicios_rutina_orden' AND object_id = OBJECT_ID(N'dbo.rutina_ejercicios'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_ejercicios_rutina_orden
        ON dbo.rutina_ejercicios (rutina_id, orden);
END;
GO

-- -------------------------------------------------------------------------
-- 6. TABLA: dbo.parques_favoritos
-- Parques públicos destacados por cada usuario con metadatos de equipamiento.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.parques_favoritos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.parques_favoritos (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_parques_favoritos PRIMARY KEY,
        usuario_id INT NOT NULL,
        parque_osm_id NVARCHAR(64) NOT NULL,
        nombre NVARCHAR(140) NOT NULL,
        latitud DECIMAL(10, 7) NOT NULL,
        longitud DECIMAL(10, 7) NOT NULL,
        direccion NVARCHAR(220) NOT NULL CONSTRAINT DF_favoritos_direccion DEFAULT N'Santa Catarina, Nuevo León',
        equipamiento_json NVARCHAR(MAX) NULL,
        accesibilidad_silla BIT NOT NULL CONSTRAINT DF_favoritos_silla DEFAULT 1,
        notas NVARCHAR(255) NULL,
        foto_url NVARCHAR(255) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_favoritos_creado_en DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_usuario_parque_osm UNIQUE (usuario_id, parque_osm_id),
        CONSTRAINT FK_parques_favoritos_usuario
            FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(id)
            ON DELETE CASCADE
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_favoritos_usuario' AND object_id = OBJECT_ID(N'dbo.parques_favoritos'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_favoritos_usuario ON dbo.parques_favoritos (usuario_id);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_favoritos_coords' AND object_id = OBJECT_ID(N'dbo.parques_favoritos'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_favoritos_coords ON dbo.parques_favoritos (latitud, longitud);
END;
GO

-- -------------------------------------------------------------------------
-- 7. TABLA: dbo.historial_pausas
-- Registro histórico de pausas completadas y caminatas en parques públicos.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.historial_pausas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.historial_pausas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_historial_pausas PRIMARY KEY,
        usuario_id INT NOT NULL,
        rutina_id INT NULL,
        ubicacion_nombre NVARCHAR(140) NOT NULL CONSTRAINT DF_historial_ubicacion DEFAULT N'Mi Espacio de Estudio / Trabajo',
        parque_osm_id NVARCHAR(64) NULL,
        actividad_realizada NVARCHAR(24) NOT NULL CONSTRAINT DF_historial_actividad DEFAULT N'computadora'
            CONSTRAINT CK_historial_actividad CHECK (actividad_realizada IN (N'computadora', N'estudio', N'reuniones', N'parque')),
        necesidad_atendida NVARCHAR(24) NOT NULL CONSTRAINT DF_historial_necesidad DEFAULT N'moverse'
            CONSTRAINT CK_historial_necesidad CHECK (necesidad_atendida IN (N'moverse', N'relajarse', N'concentrarse', N'despejarse')),
        tiempo_minutos TINYINT NOT NULL CONSTRAINT DF_historial_tiempo DEFAULT 3,
        esfuerzo TINYINT NOT NULL CONSTRAINT DF_historial_esfuerzo DEFAULT 3,
        calificacion TINYINT NOT NULL CONSTRAINT DF_historial_calificacion DEFAULT 5,
        completada_sentado BIT NOT NULL CONSTRAINT DF_historial_sentado DEFAULT 0,
        fecha_pausa DATETIME2(0) NOT NULL CONSTRAINT DF_historial_fecha DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_historial_usuario
            FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(id)
            ON DELETE CASCADE,
        CONSTRAINT FK_historial_rutina
            FOREIGN KEY (rutina_id) REFERENCES dbo.rutinas(id)
            ON DELETE SET NULL
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_historial_usuario_fecha' AND object_id = OBJECT_ID(N'dbo.historial_pausas'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_historial_usuario_fecha
        ON dbo.historial_pausas (usuario_id, fecha_pausa DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_historial_fecha' AND object_id = OBJECT_ID(N'dbo.historial_pausas'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_historial_fecha ON dbo.historial_pausas (fecha_pausa DESC);
END;
GO

-- -------------------------------------------------------------------------
-- 8. TABLA: dbo.rate_limits
-- Control persistente de tasa de peticiones en Azure SQL.
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.rate_limits', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rate_limits (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rate_limits PRIMARY KEY,
        clave_hash CHAR(64) NOT NULL CONSTRAINT UQ_rate_limits_hash UNIQUE,
        accion NVARCHAR(50) NOT NULL,
        ip_cliente NVARCHAR(45) NOT NULL,
        intentos INT NOT NULL CONSTRAINT DF_rate_limits_intentos DEFAULT 1,
        ventana_inicio DATETIME2(0) NOT NULL CONSTRAINT DF_rate_limits_ventana DEFAULT SYSUTCDATETIME(),
        bloqueado_hasta DATETIME2(0) NULL
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_ratelimit_ventana' AND object_id = OBJECT_ID(N'dbo.rate_limits'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_ratelimit_ventana ON dbo.rate_limits (ventana_inicio);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_ratelimit_bloqueo' AND object_id = OBJECT_ID(N'dbo.rate_limits'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_ratelimit_bloqueo
        ON dbo.rate_limits (bloqueado_hasta)
        WHERE bloqueado_hasta IS NOT NULL;
END;
GO

-- =========================================================================
-- CATÁLOGO BASE DE RUTINAS Y EJERCICIOS (IDEMPOTENTE VÍA MERGE PARA AZURE SQL)
-- Nota: Las cuentas demo están separadas en backend/database/seed-demo.sql
-- =========================================================================

SET IDENTITY_INSERT dbo.rutinas ON;

MERGE dbo.rutinas AS target
USING (VALUES
    (1, N'alivio-cervical-y-visual-express', N'Alivio Cervical y Descanso Visual Express (3 min)', N'Descomprime cuello, hombros y fatiga ocular tras largas sesiones frente al monitor o portátil.', N'computadora', N'relajarse', N'suave', 1, 3, N'💆‍♂️', 1),
    (2, N'enfoque-y-oxigenacion-estudio', N'Reactivación Cognitiva y Movilidad Articular (5 min)', N'Ideal entre bloques de estudio o reuniones extensas para recuperar la concentración y liberar muñecas y espalda.', N'estudio', N'concentrarse', N'moderado', 1, 5, N'🧠', 1),
    (3, N'pausa-activa-parque-santa-catarina', N'Respiración y Movilidad en Parque Público (10 min)', N'Rutina completa al aire libre diseñada para realizarse en los parques públicos de Santa Catarina o zonas verdes cercanas.', N'parque', N'despejarse', N'activo', 1, 10, N'🌳', 1)
) AS source (id, slug, titulo, descripcion, categoria, necesidad, nivel, apta_movilidad_reducida, duracion_minutos, icono, activa)
ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        titulo = source.titulo,
        descripcion = source.descripcion,
        duracion_minutos = source.duracion_minutos
WHEN NOT MATCHED THEN
    INSERT (id, slug, titulo, descripcion, categoria, necesidad, nivel, apta_movilidad_reducida, duracion_minutos, icono, activa)
    VALUES (source.id, source.slug, source.titulo, source.descripcion, source.categoria, source.necesidad, source.nivel, source.apta_movilidad_reducida, source.duracion_minutos, source.icono, source.activa);

SET IDENTITY_INSERT dbo.rutinas OFF;
GO

SET IDENTITY_INSERT dbo.rutina_ejercicios ON;

MERGE dbo.rutina_ejercicios AS target
USING (VALUES
    (1, 1, 1, N'Regla 20-20-20 y Parpadeo Consciente',
        N'Aparta la vista del monitor y enfoca un punto lejano a más de 6 metros (por ejemplo, a través de una ventana). Parpadea lentamente 10 veces para lubricar la córnea y relaja el entrecejo.',
        N'Sentado con la espalda apoyada en el respaldo, dirige la mirada hacia el punto más lejano del salón u oficina y parpadea suavemente sin mover el cuello.',
        60, N'👀', N'Reduce la fatiga del músculo ciliar y la sequedad ocular digital'),
    (2, 1, 2, N'Liberación Lateral de Trapecios y Cuello',
        N'Deja caer suavemente la oreja derecha hacia el hombro derecho sin levantar el hombro izquierdo. Mantén 25 segundos respirando profundo y cambia al lado izquierdo.',
        N'Con las manos descansando sobre los muslos o apoyabrazos de la silla, inclina la cabeza suavemente a cada lado manteniendo el tronco estable.',
        60, N'🧘', N'Disminuye la tensión miofascial cervical y previene cefaleas tensionales'),
    (3, 1, 3, N'Círculos Escapulares y Apertura Torácica',
        N'Realiza 8 giros amplios de hombros hacia atrás y hacia abajo. Al inhalar, junta ligeramente los omóplatos abriendo el pecho; al exhalar, suelta la tensión.',
        N'Desde tu asiento, eleva los hombros al inhalar y llévalos hacia atrás y abajo al exhalar, corrigiendo la postura encorvada frente al teclado.',
        60, N'🔄', N'Contrarresta la cifosis postural y mejora la capacidad respiratoria'),
    (4, 2, 1, N'Descompresión de Muñecas y Túnel Carpiano',
        N'Extiende el brazo derecho al frente con la palma hacia arriba; con la mano izquierda lleva suavemente los dedos hacia abajo durante 30 segundos. Invierte con la palma hacia abajo y repite con el otro brazo.',
        N'Realiza el estiramiento de flexores y extensores de antebrazo con los codos cómodamente cerca del torso, sin tensar los hombros.',
        75, N'👐', N'Previene la sobrecarga tendinosa por uso continuo de teclado y ratón'),
    (5, 2, 2, N'Torsión Suave de Columna Torácica',
        N'Con la espalda erguida, gira suavemente el torso hacia la derecha apoyando la mano izquierda en el muslo derecho. Mantén 30 segundos inhalando profundo y cambia hacia la izquierda.',
        N'Manteniendo la cadera fija en el asiento o silla de ruedas, gira únicamente la parte media y alta de la espalda acompañando con la mirada.',
        75, N'🪑', N'Hidrata los discos intervertebrales y alivia la rigidez dorsal'),
    (6, 2, 3, N'Coordinación Cruzada para Enfoque Mental',
        N'Alterna el toque suave de la mano derecha hacia la rodilla izquierda y luego mano izquierda hacia rodilla derecha a un ritmo pausado y consciente, coordinando con tu respiración.',
        N'Sentado, toca con la mano derecha el muslo opuesto y alterna suavemente con la mano izquierda para activar ambos hemisferios cerebrales.',
        75, N'🎯', N'Estimula la atención ejecutiva y rompe la fatiga mental de estudio'),
    (7, 2, 4, N'Respiración Diafragmática 4-4-6',
        N'Inhala por la nariz en 4 segundos inflando el abdomen, sostén 4 segundos sin tensión y exhala suavemente por la boca durante 6 segundos.',
        N'Apoya una mano sobre el abdomen y otra en el pecho desde tu asiento; siente cómo se expande la zona baja al inhalar y cómo descienden los hombros al exhalar.',
        75, N'🌬️', N'Activa el sistema nervioso parasimpático y estabiliza el pulso'),
    (8, 3, 1, N'Marcha de Activación y Contacto con el Entorno Verde',
        N'Camina a paso cómodo por el sendero del parque durante 2 minutos, balanceando los brazos de forma natural y observando el follaje de los árboles cercanos.',
        N'Avanza suavemente por la rampa o andador accesible del parque (o realiza empujes rítmicos y movilidad de brazos en tu lugar) respirando el aire fresco.',
        120, N'🚶', N'Reactiva el retorno venoso y reduce el cortisol mediante contacto con áreas verdes'),
    (9, 3, 2, N'Apertura Pectoral y Extensión Dorsal al Aire Libre',
        N'Entrelaza las manos detrás de la espalda (o apóyalas en la cintura), abre el pecho mirando hacia la copa de los árboles e inhala profundamente 6 veces.',
        N'Sujeta los costados del respaldo de tu silla o apoya las manos en la cadera, proyectando el esternón hacia adelante con respiraciones amplias.',
        120, N'🌳', N'Expande la caja torácica y revierte el acortamiento pectoral'),
    (10, 3, 3, N'Movilidad de Cadera y Tobillos en Banca de Parque',
        N'Cerca de una banca para mayor estabilidad, realiza 10 elevaciones suaves de talones seguidas de 8 círculos con cada tobillo.',
        N'Sentado, extiende una pierna ligeramente y dibuja 10 círculos amplios con la punta del pie hacia ambos sentidos; alterna con el otro lado.',
        120, N'🦵', N'Favorece la circulación periférica en extremidades inferiores'),
    (11, 3, 4, N'Estiramiento Lateral de Cadena Muscular Completa',
        N'Eleva el brazo derecho por encima de la cabeza e inclina suavemente el tronco hacia la izquierda sintiendo el alargamiento del costado. Mantén 45 segundos por lado.',
        N'Con una mano firme sobre el apoyabrazos para dar soporte, eleva el brazo contrario e inclina suavemente el torso sin despegar la cadera.',
        120, N'🙆‍♂️', N'Descomprime la zona lumbar y alarga el músculo cuadrado lumbar'),
    (12, 3, 5, N'Pausa Sensorial 3-2-1 de Cierre',
        N'Detente en una zona con sombra del parque: identifica con calma 3 sonidos de la naturaleza, 2 tonalidades de verde y 1 sensación de bienestar corporal antes de regresar.',
        N'En una zona tranquila con sombra, cierra los ojos unos segundos o contempla el paisaje realizando 5 respiraciones lentas de integración.',
        120, N'✨', N'Restaura la atención dirigida y consolida la sensación de descanso')
) AS source (id, rutina_id, orden, titulo, instrucciones, instrucciones_sentado, duracion_segundos, icono_emoji, beneficio_ergonomico)
ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        titulo = source.titulo,
        instrucciones = source.instrucciones,
        instrucciones_sentado = source.instrucciones_sentado,
        duracion_segundos = source.duracion_segundos,
        icono_emoji = source.icono_emoji,
        beneficio_ergonomico = source.beneficio_ergonomico
WHEN NOT MATCHED THEN
    INSERT (id, rutina_id, orden, titulo, instrucciones, instrucciones_sentado, duracion_segundos, icono_emoji, beneficio_ergonomico)
    VALUES (source.id, source.rutina_id, source.orden, source.titulo, source.instrucciones, source.instrucciones_sentado, source.duracion_segundos, source.icono_emoji, source.beneficio_ergonomico);

SET IDENTITY_INSERT dbo.rutina_ejercicios OFF;
GO
