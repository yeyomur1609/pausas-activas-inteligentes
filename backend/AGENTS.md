# Pausas Activas Inteligentes — Arquitectura Cloud Desacoplada (`backend/`)

## 1. Visión General
API REST desacoplada y *stateless* construida en **PHP 8.2+ (Apache Docker en Render)**, conectada a **Azure SQL Database (`pdo_sqlsrv` + T-SQL)**, **PHPMailer (SMTP)**, **OpenStreetMap Overpass/Nominatim/OSRM** y **Google Gemini API (`gemini-2.5-flash-lite`)**, consumida por un **Frontend Estático + PWA (ES Modules) en Vercel**.

- **Único directorio público (`DocumentRoot`)**: `backend/public/`. Todo código sensible (`config/`, `database/`, `src/`, `storage/`, `vendor/`, `.env`) reside fuera de `public/`.
- **Despliegue declarativo**: `render.yaml` en la raíz del repositorio configura el servicio Docker (`rootDir: backend`, `healthCheckPath: /health`).
- **Compatibilidad local**: `Database::driver()` detecta automáticamente `sqlsrv` en producción/Azure (`*.database.windows.net` / puerto `1433` / `DB_DRIVER=sqlsrv`) y conserva `mysql` opcional para desarrollo local en XAMPP.

## 2. Estructura Modular (`backend/`)
- `Dockerfile`, `docker-entrypoint.sh`, `.dockerignore`:
  - Imagen `php:8.2-apache-bookworm` con **Microsoft ODBC Driver 18 (`msodbcsql18`)**, extensiones `sqlsrv` y `pdo_sqlsrv`, Composer (`phpmailer/phpmailer`), escucha dinámica en `$PORT` y paso explícito de `Authorization` (`CGIPassAuth On` + `SetEnvIf Authorization`).
- `config/`:
  - `secrets.php`: Lectura prioritaria desde variables de entorno del host (`getenv()` / `$_ENV` / `$_SERVER`) con respaldo a `.env` local y soporte de desencriptación AES-256-GCM (`ENC:`).
  - `database.php`: Singleton PDO para Azure SQL (`Encrypt=yes;TrustServerCertificate=no;LoginTimeout=15`, `PDO::SQLSRV_ENCODING_UTF8`) y helper `Database::lastInsertId()` vía `SCOPE_IDENTITY()`.
- `database/`:
  - `schema.sql` y `seed-demo.sql`: Scripts 100% T-SQL idempotentes para Azure SQL Database (`IDENTITY(1,1)`, `NVARCHAR`, `DATETIME2(0)`, `BIT`, `MERGE`, índices filtrados).
- `src/Services/`:
  - `JwtService.php`: Emisión y verificación *stateless* de tokens **JWT HS256 (HMAC-SHA256)** nativos (`JWT_SECRET`, `JWT_TTL_SECONDS`) sin dependencias externas.
  - `GeminiService.php`: Cliente hacia Google Gemini API priorizando `gemini-2.5-flash-lite` → `gemini-2.5-flash` → `gemini-2.0-flash`.
  - `OsmCacheService.php`: Consultas paralelas a espejos Overpass (`out center tags qt;`), Nominatim y OSRM con caché efímero autogestionado (`storage/cache/` `0775` + respaldo en `/tmp`).
  - `EmailService.php`: Validación MX anti-desechables y plantillas HTML vía PHPMailer apuntando a `FRONTEND_URL/login.html`.
- `src/Middleware/`:
  - `CorsMiddleware.php`: Autoriza `FRONTEND_URL` y `CORS_ALLOWED_ORIGINS`, habilita `Authorization` y `X-CSRF-Token`, y responde `204` a preflight `OPTIONS`.
  - `AuthMiddleware.php`: Autenticación primaria *stateless* mediante `Authorization: Bearer <jwt>` (`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, `getallheaders()`), manteniendo `$_SESSION` solo como respaldo secundario en local.
  - `CsrfMiddleware.php`: Exime peticiones autenticadas con `Authorization: Bearer <jwt>` válido (inmunes a CSRF) y valida `X-CSRF-Token` en sesiones locales por cookie.
  - `RateLimitMiddleware.php`: Control de tasa persistente en Azure SQL (`MERGE` + `UPDLOCK, ROWLOCK`) con respaldo atómico en almacenamiento efímero.
- `src/Models/`:
  - `UsuarioModel.php`, `RutinaModel.php`, `ParqueFavoritoModel.php`, `HistorialModel.php` (consultas T-SQL nativas cuando `Database::isSqlSrv()` es `true`).
- `public/`:
  - `health.php` (accesible vía `GET /health`, `GET /health?deep=1` y `GET /health.php`).
  - `api/auth.php`, `api/usuarios.php`, `api/mapa.php`, `api/rutinas.php`, `api/chat.php`.
