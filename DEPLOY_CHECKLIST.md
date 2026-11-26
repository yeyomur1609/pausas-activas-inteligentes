# Guía y Checklist de Despliegue Cloud — Pausas Activas Inteligentes

**Arquitectura desacoplada de producción:**
- **Frontend + PWA (`frontend/`)**: **Vercel** (Free Tier — HTML5/CSS3/ES Modules + [frontend/vercel.json](frontend/vercel.json))
- **Backend API (`backend/`)**: **Render** (Free Tier — Docker PHP 8.2 + Apache + ODBC 18 + `pdo_sqlsrv` + Blueprint [render.yaml](render.yaml))
- **Autenticación Cross-Domain**: **JWT Bearer Stateless (HMAC-SHA256)** en `Authorization: Bearer <token>` (`localStorage: pai_auth_token_v1`), inmune al bloqueo de cookies de terceros entre `*.vercel.app` y `*.onrender.com` y al disco efímero de Render Free (conservando `$_SESSION` como respaldo local en XAMPP).
- **Base de Datos (`backend/database/`)**: **Azure SQL Database** (T-SQL 100% compatible bajo **Azure for Students**)

---

## Paso 1 — Base de Datos en Azure SQL (Azure for Students)

1. En [portal.azure.com](https://portal.azure.com), crea un recurso **SQL Database** dentro de tu suscripción **Azure for Students**:
   - **Database name**: `pausas_activas_db`
   - **Server**: crea uno nuevo (ej. `sql-pausas-activas.database.windows.net`) con **SQL authentication** (`DB_USER` y `DB_PASSWORD` de 24+ caracteres).
   - **Compute + storage**: aplica **Free Offer** de Azure SQL o **Serverless / Basic (5 DTU)**.
2. **Firewall de Azure SQL para Render Free:**
   - En el recurso del **SQL Server → Security → Networking → Public access**:
     - Marca **Allow Azure services and resources to access this server**.
     - Agrega tu IP actual para usar el Query Editor.
     - Agrega la regla `Render-Free-Dynamic-Outbound` (`0.0.0.0` a `255.255.255.255`) ya que Render Free rota sus IPs de salida. La conexión viaja cifrada extremo a extremo (`Encrypt=yes;TrustServerCertificate=no`).
3. **Inicializar esquema T-SQL:**
   - En `pausas_activas_db` → **Query editor (preview)**, ejecuta completo [backend/database/schema.sql](backend/database/schema.sql).
   - *(Opcional para pruebas iniciales)* Ejecuta [backend/database/seed-demo.sql](backend/database/seed-demo.sql) y recuerda cambiar o eliminar las cuentas demo tras validar.

---

## Paso 2 — Backend Docker en Render (Flujo Rápido con `render.yaml` o Manual)

### Opción A (Recomendada): Blueprint automático con `render.yaml`
1. Sube el repositorio a GitHub (confirma con `git status` que `backend/.env` NO esté rastreado).
2. En [dashboard.render.com](https://dashboard.render.com) → **New + → Blueprint** → selecciona tu repositorio.
3. Render leerá automáticamente [render.yaml](render.yaml) (`rootDir: backend`, `dockerfilePath: ./Dockerfile`, `healthCheckPath: /health`, `plan: free`) y te pedirá rellenar únicamente las variables marcadas con `sync: false`.

### Opción B: Creación manual de Web Service
- **Runtime**: `Docker` | **Root Directory**: `backend` | **Instance Type**: `Free` | **Health Check Path**: `/health`

### Variables de entorno en Render

| Variable | Valor en Render | Notas |
|---|---|---|
| `APP_ENV` | `production` | Preconfigurada en `render.yaml` |
| `APP_DEBUG` | `false` | Preconfigurada en `render.yaml` |
| `APP_TIMEZONE` | `America/Monterrey` | Preconfigurada en `render.yaml` |
| `FRONTEND_URL` | `https://<tu-proyecto>.vercel.app` | Sin `/` final (actualizar tras Paso 3) |
| `APP_URL` | `https://<tu-proyecto>.vercel.app` | Igual a `FRONTEND_URL` |
| `CORS_ALLOWED_ORIGINS` | `https://<tu-proyecto>.vercel.app` | Orígenes extra separados por coma |
| `JWT_SECRET` | `<64-hex-aleatorios>` | Firma HMAC-SHA256 (`openssl rand -hex 32`) |
| `JWT_TTL_SECONDS` | `604800` | Vigencia del JWT (7 días) |
| `APP_KEY` | `<64-hex-aleatorios>` | Llave auxiliar de aplicación |
| `MASTER_ENCRYPTION_KEY` | `<64-hex-aleatorios>` | Desencriptación opcional `ENC:` |
| `DB_DRIVER` | `sqlsrv` | Preconfigurada en `render.yaml` |
| `DB_HOST` | `<tu-servidor>.database.windows.net` | Servidor Azure SQL |
| `DB_PORT` | `1433` | Preconfigurada en `render.yaml` |
| `DB_NAME` | `pausas_activas_db` | Preconfigurada en `render.yaml` |
| `DB_USER` | `<tu-usuario-sql>` | Usuario administrador/app de Azure SQL |
| `DB_PASSWORD` | `<tu-password-sql>` | Contraseña de Azure SQL |
| `DB_ENCRYPT` | `yes` | Preconfigurada en `render.yaml` |
| `DB_TRUST_SERVER_CERTIFICATE` | `no` | Preconfigurada en `render.yaml` |
| `GEMINI_API_KEY` | `<tu-gemini-api-key>` | Google AI Studio (ActiBot) |
| `GEMINI_MODEL` | `gemini-2.5-flash-lite` | Preconfigurada en `render.yaml` |
| `GOOGLE_CLIENT_ID` | `<tu-client-id-google>` | Opcional (dejar vacío si no se usa) |
| `SMTP_HOST` | `smtp.gmail.com` | Preconfigurada en `render.yaml` |
| `SMTP_PORT` | `587` | Preconfigurada en `render.yaml` |
| `SMTP_SECURE` | `tls` | Preconfigurada en `render.yaml` |
| `SMTP_USER` | `<tu-correo-smtp>` | Cuenta remitente SMTP |
| `SMTP_PASS` | `<tu-app-password-smtp>` | App Password sin espacios |
| `MAIL_FROM` | `<tu-correo-smtp>` | Dirección remitente |
| `MAIL_FROM_NAME` | `Pausas Activas Inteligentes` | Preconfigurada en `render.yaml` |
| `OSM_CACHE_TTL_SECONDS` | `300` | Preconfigurada en `render.yaml` |

---

## Paso 3 — Frontend Estático + PWA en Vercel (Free Tier)

1. En [frontend/assets/js/modules/config.js](frontend/assets/js/modules/config.js), coloca tu URL real de Render en `PRODUCTION_API_BASE_URL` (también soporta `<meta name="pai-api-base">` o `window.__PAI_API_BASE__`):
   ```js
   const PRODUCTION_API_BASE_URL = 'https://pausas-activas-api.onrender.com/api';
   ```
   Haz commit y push a GitHub.
2. En [vercel.com/new](https://vercel.com/new), importa el repositorio:
   - **Framework Preset**: `Other`
   - **Root Directory**: `frontend`
   - **Build / Output Settings**: vacíos (sitio estático gobernado por [frontend/vercel.json](frontend/vercel.json)).
   - **Environment Variables**: no se requieren secretos en Vercel.
3. Copia el dominio final de Vercel (`https://<tu-proyecto>.vercel.app`) y pégalo en `FRONTEND_URL`, `APP_URL` y `CORS_ALLOWED_ORIGINS` dentro de **Render → Environment**.
4. *(Si usas Google OAuth)* Autoriza el dominio de Vercel en **Google Cloud Console → Authorized JavaScript origins**.

---

## Paso 4 — Verificación y Pruebas de Humo en Producción

- [ ] **Health Check rápido (`/health`):** abre `https://<tu-servicio>.onrender.com/health` → debe responder HTTP `200` con `"ok": true`, `"db_driver": "sqlsrv"`.
- [ ] **Health Check profundo a Azure SQL (`/health?deep=1`):** abre `https://<tu-servicio>.onrender.com/health?deep=1` → debe responder HTTP `200` con `"ok": true` y `"db": "connected"`.
- [ ] **Smoke Test automatizado remoto (incluye validación de JWT Bearer stateless + CORS):**
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts/smoke-test.ps1 `
    -BackendUrl "https://<tu-servicio>.onrender.com" `
    -FrontendUrl "https://<tu-proyecto>.vercel.app"
  ```
- [ ] **Flujo funcional completo en navegador:**
  - [ ] Login / Registro / Magic Link / Google OAuth guardan `pai_auth_token_v1` en `localStorage` y envían `Authorization: Bearer <jwt>` en cada petición sin depender de cookies de terceros.
  - [ ] Cerrar sesión o recibir HTTP `401` limpia `pai_auth_token_v1` y redirige a `/login.html`.
  - [ ] Rutinas con las 16 animaciones Lottie, mapa satelital híbrido Esri + Overpass OSM, ActiBot con Gemini (`gemini-2.5-flash-lite`) y PWA funcionan sin errores en consola.
