# Pausas Activas Inteligentes — Arquitectura Backend (`backend/`)

## 1. Visión General
API REST desacoplada en **PHP 8.1+**, **MySQL 8.0+ (PDO `utf8mb4`)**, **PHPMailer** y **Google Gemini API (`gemini-2.5-flash-lite`)**.
El único directorio expuesto vía servidor web (`DocumentRoot`) es `backend/public/`. Todos los archivos sensibles (`.env`, `config/`, `database/`, `src/`, `storage/`, `vendor/`) residen un nivel por encima de `public/`.

## 2. Estructura Modular (`backend/`)
- `config/`:
  - `secrets.php`: Carga y validación de variables desde `.env`.
  - `database.php`: Conexión PDO Singleton (`ATTR_EMULATE_PREPARES => false`, `ERRMODE_EXCEPTION`).
- `database/`:
  - `schema.sql`: Esquema relacional InnoDB (`utf8mb4_unicode_ci`) con 8 tablas y datos semilla de Santa Catarina, N.L.
- `src/Middleware/`:
  - `CorsMiddleware.php`: Validación estricta de `CORS_ALLOWED_ORIGINS` con `Access-Control-Allow-Credentials: true`.
  - `CsrfMiddleware.php`: Generación y verificación `hash_equals` de cabecera `X-CSRF-Token`.
  - `AuthMiddleware.php`: Sesiones endurecidas (`HttpOnly`, `SameSite=Lax`, `use_strict_mode`), regeneración de ID y liberación temprana de bloqueo (`session_write_close()`).
  - `RateLimitMiddleware.php`: Limitador de peticiones por IP/acción en MySQL (`rate_limits`).
- `src/Models/`:
  - `UsuarioModel.php`, `RutinaModel.php`, `ParqueFavoritoModel.php`, `HistorialModel.php`.
- `src/Services/`:
  - `EmailService.php`, `OsmCacheService.php` (TTL 24h en `storage/cache/`), `GeminiService.php`.
- `src/Controllers/` y `public/api/`:
  1. `AuthController.php` (`public/api/auth.php`)
  2. `UsuariosController.php` (`public/api/usuarios.php`)
  3. `MapaController.php` (`public/api/mapa.php`)
  4. `RutinasController.php` (`public/api/rutinas.php`)
  5. `ChatController.php` (`public/api/chat.php`)
