# Frontend estático

## Desarrollo local

Desde la raíz del proyecto, abre dos terminales:

```bash
php -S localhost:8000 -t backend/public
php -S localhost:3000 -t frontend
```

Abre `http://localhost:3000/index.html`. El backend debe responder en `http://localhost:8000/api/auth.php?accion=session`.
El endpoint `http://localhost:8000/health.php` sirve para comprobar disponibilidad básica de la API sin consultar la base de datos.
Con ambos servidores activos, ejecuta `powershell -ExecutionPolicy Bypass -File scripts/smoke-test.ps1` desde la raíz para repetir las comprobaciones HTTP de lanzamiento.

Configura `backend/.env` a partir de `backend/.env.example`, importa `backend/database/schema.sql` en MySQL y ajusta `frontend/assets/js/config.js` si cambia la URL de la API.

Antes de producción, cambia la contraseña temporal `admin12345`, configura `CORS_ALLOWED_ORIGINS` con el dominio HTTPS real y ejecuta la aplicación con PHP 8.1 o superior.

## cPanel

1. Crea la base MySQL y un usuario con permisos limitados.
2. Importa `backend/database/schema.sql`.
3. Sube `backend/` fuera de `public_html`; instala dependencias con `composer install --no-dev --optimize-autoloader`.
4. Configura el DocumentRoot del subdominio de API en `backend/public/`.
5. Publica el contenido de `frontend/` en el DocumentRoot del dominio web.
6. Copia `.env.example` a `backend/.env`, configura SMTP, Google, Gemini, CORS y credenciales MySQL.
7. Activa HTTPS y usa el origen HTTPS del frontend en `CORS_ALLOWED_ORIGINS` y `APP_URL`.

## VPS

Configura PHP 8.1+, PHP-FPM, MySQL y Composer. Usa un virtual host para que el frontend sirva `frontend/` y otro para que la API sirva exclusivamente `backend/public/`. Protege `.env`, habilita HTTPS con Let's Encrypt y configura permisos de escritura únicamente para `backend/storage/cache/`.
