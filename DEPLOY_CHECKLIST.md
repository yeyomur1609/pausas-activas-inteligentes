# Guía y Checklist de Despliegue a Producción (100% Gratuito + Azure for Students)

**Arquitectura objetivo de producción:**
- **Frontend + PWA (`frontend/`)**: **Vercel** (Free Tier — sitio estático HTML5/CSS3/ES Modules + `frontend/vercel.json`)
- **Backend API (`backend/`)**: **Render** (Free Tier — Web Service Docker con PHP 8.2 + Apache + ODBC 18 + `pdo_sqlsrv` + PHPMailer)
- **Base de Datos (`backend/database/`)**: **Azure SQL Database** (T-SQL / SQL Server bajo plan **Azure for Students**)

> Compatibilidad local conservada: en desarrollo con XAMPP (`APP_ENV=development`, `DB_HOST=127.0.0.1`), `Database::driver()` sigue usando MySQL automáticamente si no se define `DB_DRIVER=sqlsrv`.

---

## Paso 1 — Crear Base de Datos en Azure for Students (Azure SQL Database)

1. Inicia sesión en [portal.azure.com](https://portal.azure.com) con tu suscripción **Azure for Students**.
2. Busca **SQL databases** → clic en **+ Create**.
3. Configura los parámetros básicos:
   - **Subscription**: `Azure for Students`
   - **Resource group**: crea uno nuevo, por ejemplo `rg-pausas-activas`.
   - **Database name**: `pausas_activas_db`
   - **Server** → clic en **Create new**:
     - **Server name**: elige un nombre único global (ej. `sql-pausas-activas`), lo que generará `sql-pausas-activas.database.windows.net`.
     - **Location**: región cercana (ej. `Central US` o `South Central US`, idealmente la misma región que elegirás en Render).
     - **Authentication method**: **Use SQL authentication**.
     - **Server admin login**: ej. `pai_sql_admin` (anótalo para `DB_USER`).
     - **Password**: genera una contraseña robusta de 24+ caracteres (anótala para `DB_PASSWORD`).
   - **Want to use SQL elastic pool?**: `No`
   - **Compute + storage**: selecciona la oferta gratuita (**Apply offer** de Azure SQL Free si aparece disponible) o **Serverless — General Purpose (Auto-pause habilitado)** / **Basic (5 DTU)** para minimizar consumo del crédito estudiantil.
4. En la pestaña **Networking**:
   - **Connectivity method**: **Public endpoint**.
   - **Allow Azure services and resources to access this server**: `Yes`.
   - **Add current client IP address**: `Yes` (te permite abrir el Query Editor desde tu navegador).
5. Crea la base de datos y espera a que finalice el aprovisionamiento.
6. **Configurar Firewall de Azure SQL para Render Free (IPs salientes dinámicas):**
   - Abre el recurso del **SQL Server** (`sql-pausas-activas`) → **Security → Networking → Public access**.
   - Render Free asigna IPs de salida dinámicas que rotan entre despliegues. Para permitir que el contenedor de Render se conecte sin cortes:
     - En **Firewall rules**, agrega una regla llamada `Render-Free-Dynamic-Outbound` con:
       - **Start IP**: `0.0.0.0`
       - **End IP**: `255.255.255.255`
     - Haz clic en **Save**.
   - *Medidas compensatorias ya aplicadas en código:* `backend/config/database.php` exige cifrado TLS obligatorio (`Encrypt=yes;TrustServerCertificate=no`) y consultas 100% parametrizadas. Usa siempre una contraseña `DB_PASSWORD` aleatoria de 24+ caracteres. *(Si en el futuro migras a un plan de pago de Render con Static Outbound IPs, reemplaza esta regla amplia por las IPs estáticas exactas de Render.)*
7. **Importar el esquema T-SQL y datos iniciales:**
   - Entra a tu base de datos `pausas_activas_db` en Azure Portal → **Query editor (preview)** → inicia sesión con `DB_USER` y `DB_PASSWORD`.
   - Abre [backend/database/schema.sql](backend/database/schema.sql), copia todo su contenido, pégalo en el Query Editor y haz clic en **Run** (el script es 100% idempotente: crea las 7 tablas, índices filtrados, claves foráneas y las 3 rutinas con 12 ejercicios).
   - *(Opcional solo para pruebas iniciales)* Ejecuta [backend/database/seed-demo.sql](backend/database/seed-demo.sql) en el Query Editor para cargar las cuentas demo (`admin@pausasactivas.edu.mx` y `estudiante@pausasactivas.edu.mx`, clave temporal `admin12345`) y datos de ejemplo. **Cambia o elimina las cuentas demo tras validar producción.**

---

## Paso 2 — Desplegar Backend Docker en Render (Free Tier)

1. Sube tus cambios a tu repositorio en GitHub (verifica con `git status` que `backend/.env` NO esté incluido).
2. Entra a [dashboard.render.com](https://dashboard.render.com) → **New + → Web Service**.
3. Conecta tu repositorio de GitHub y configura:
   - **Name**: por ejemplo `pausas-activas-api` (tu URL será `https://pausas-activas-api.onrender.com`).
   - **Region**: elige la región más cercana a tu servidor de Azure SQL (ej. `Ohio` o `Oregon`).
   - **Branch**: `main` (o tu rama principal).
   - **Root Directory**: `backend`
   - **Runtime**: `Docker` (Render detectará automáticamente `backend/Dockerfile` y `backend/.dockerignore`).
   - **Instance Type**: `Free`
   - **Advanced → Health Check Path**: `/health.php`
4. Configura en **Environment → Environment Variables** todas las variables siguientes (NO subas archivos `.env`):

| Variable | Valor / Ejemplo | Obligatoria |
|---|---|---|
| `APP_ENV` | `production` | Sí |
| `APP_DEBUG` | `false` | Sí |
| `APP_TIMEZONE` | `America/Monterrey` | Sí |
| `FRONTEND_URL` | `https://tu-proyecto.vercel.app` *(actualizar en Paso 3)* | Sí |
| `APP_URL` | `https://tu-proyecto.vercel.app` *(mismo valor que `FRONTEND_URL`)* | Sí |
| `CORS_ALLOWED_ORIGINS` | `https://tu-proyecto.vercel.app` *(puede incluir orígenes extra separados por coma)* | Sí |
| `SESSION_LIFETIME` | `604800` | Recomendada |
| `SESSION_SAMESITE` | `None` *(automático en `APP_ENV=production` con `Secure=true; HttpOnly=true`)* | Recomendada |
| `APP_KEY` | Cadena hexadecimal aleatoria de 64 caracteres (`openssl rand -hex 32`) | Sí |
| `MASTER_ENCRYPTION_KEY` | Cadena hexadecimal aleatoria de 64 caracteres (`openssl rand -hex 32`) | Sí |
| `DB_DRIVER` | `sqlsrv` | Sí |
| `DB_HOST` | `tu-servidor.database.windows.net` | Sí |
| `DB_PORT` | `1433` | Sí |
| `DB_NAME` | `pausas_activas_db` | Sí |
| `DB_USER` | Tu usuario SQL de Azure (ej. `pai_sql_admin`) | Sí |
| `DB_PASSWORD` | Tu contraseña SQL de Azure | Sí |
| `DB_ENCRYPT` | `yes` | Sí |
| `DB_TRUST_SERVER_CERTIFICATE` | `no` | Sí |
| `GEMINI_API_KEY` | Tu clave de Google AI Studio (ActiBot) | Sí |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | Recomendada |
| `GOOGLE_CLIENT_ID` | ID de cliente OAuth 2.0 de Google *(dejar vacío si no usas botón Google)* | Opcional |
| `SMTP_HOST` | `smtp.gmail.com` *(o tu proveedor SMTP)* | Para correos |
| `SMTP_PORT` | `587` | Para correos |
| `SMTP_SECURE` | `tls` | Para correos |
| `SMTP_USER` | Correo SMTP remitente | Para correos |
| `SMTP_PASS` | Contraseña de aplicación SMTP (16 chars sin espacios en Gmail) | Para correos |
| `MAIL_FROM` | Correo remitente (normalmente igual a `SMTP_USER`) | Para correos |
| `MAIL_FROM_NAME` | `Pausas Activas Inteligentes` | Para correos |
| `OSM_CACHE_TTL_SECONDS` | `300` | Recomendada |
| `OSM_USER_AGENT` | `PausasActivasInteligentes/3.0 (https://tu-proyecto.vercel.app; soporte@tu-dominio.com)` | Recomendada |

5. Haz clic en **Deploy Web Service**. El primer build de Docker tardará ~3–5 minutos (instala Microsoft ODBC 18, `pdo_sqlsrv`, `sqlsrv` y `phpmailer/phpmailer`).
6. Cuando Render muestre **Live**, anota tu URL real de backend, ej.: `https://pausas-activas-api.onrender.com`.

> **Nota sobre Render Free (Cold Start y Disco Efímero):**
> - Tras 15 minutos sin tráfico, el contenedor hiberna y la siguiente petición tarda **30–50 segundos** en despertar. El frontend (`api-client.js`) ya incluye timeout de 65 s y aviso progresivo para el usuario.
> - El disco es efímero: `docker-entrypoint.sh`, `OsmCacheService.php` y `RateLimitMiddleware.php` recrean automáticamente `backend/storage/cache/` (`0775`, `www-data`) con respaldo en `/tmp` sin errores fatales.

---

## Paso 3 — Desplegar Frontend y PWA en Vercel (Free Tier)

1. Actualiza la constante `PRODUCTION_API_BASE_URL` en [frontend/assets/js/modules/config.js](frontend/assets/js/modules/config.js) con tu URL real de Render terminada en `/api`:
   ```js
   const PRODUCTION_API_BASE_URL = 'https://tu-servicio-render.onrender.com/api';
   ```
   Haz commit y push de este cambio a GitHub.
2. Entra a [vercel.com/new](https://vercel.com/new) e importa tu repositorio de GitHub.
3. Configura el proyecto en Vercel:
   - **Framework Preset**: `Other`
   - **Root Directory**: `frontend` *(clic en **Edit** y selecciona la carpeta `frontend`)*
   - **Build and Output Settings**: deja **Build Command**, **Output Directory** e **Install Command** vacíos (sitio estático puro; Vercel aplicará automáticamente [frontend/vercel.json](frontend/vercel.json) con rewrites limpios, `404.html`, CSP estricta y cabeceras `Cache-Control` para la PWA).
   - **Environment Variables**: no se requieren secretos en Vercel (frontend estático sin credenciales).
4. Haz clic en **Deploy** y copia tu URL pública asignada por Vercel (ej. `https://pausas-activas-inteligentes.vercel.app`).
5. **Cerrar el círculo de CORS en Render:**
   - Regresa a **Render → tu servicio → Environment**.
   - Actualiza `FRONTEND_URL`, `APP_URL` y `CORS_ALLOWED_ORIGINS` con tu URL exacta de Vercel (`https://pausas-activas-inteligentes.vercel.app`, sin barra final `/`) y guarda los cambios (Render redeployará automáticamente en segundos).
6. *(Si usas Google Sign-In)* En **Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client ID**, agrega tu URL de Vercel (`https://pausas-activas-inteligentes.vercel.app`) en **Authorized JavaScript origins**.

---

## Paso 4 — Validación Post-Despliegue en Producción

- [ ] **Health Check básico:** abre `https://<tu-servicio>.onrender.com/health.php` y confirma HTTP `200 OK` con `"ok": true`, `"env": "production"` y `"db_driver": "sqlsrv"`.
- [ ] **Health Check profundo a Azure SQL:** abre `https://<tu-servicio>.onrender.com/health.php?deep=1` y confirma `"db": "connected"` (HTTP `200`).
- [ ] **Smoke Test automatizado contra producción:** ejecuta desde tu terminal local PowerShell:
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts/smoke-test.ps1 `
    -BackendUrl "https://<tu-servicio>.onrender.com" `
    -FrontendUrl "https://<tu-proyecto>.vercel.app"
  ```
- [ ] **Verificación manual end-to-end en navegador (ventana incógnito):**
  - [ ] Abrir `https://<tu-proyecto>.vercel.app/` sin sesión redirige automáticamente a `/login.html?next=index.html`.
  - [ ] En DevTools → Network → `auth.php?accion=login`, la cabecera `Set-Cookie` de `PAI_SESSID` incluye `Secure; HttpOnly; SameSite=None` y los encabezados CORS devuelven `Access-Control-Allow-Credentials: true` + `Access-Control-Allow-Origin: https://<tu-proyecto>.vercel.app`.
  - [ ] Registro de cuenta nueva, verificación de correo (enlace + código OTP de 6 dígitos), Magic Link (15 min) y Recuperación de contraseña envían correos reales con enlaces hacia `https://<tu-proyecto>.vercel.app/login.html?...`.
  - [ ] **Google OAuth** *(si configuraste `GOOGLE_CLIENT_ID`)* inicia sesión y vincula cuenta correctamente.
  - [ ] **Rutinas (`rutinas.html`):** generador de 3 pasos, 16 animaciones Lottie locales y registro de pausa completada actualizan racha e historial en Azure SQL.
  - [ ] **ActiBot (`chatbot.html` y cajón flotante):** responde en vivo desde Google Gemini AI (`origen: "gemini_api"`).
  - [ ] **Parques (`parques.html`):** capa satelital híbrida Esri + selector Satelital/Estándar, carga de parques en vivo desde Overpass API, ruta peatonal OSRM y guardado/eliminación de favoritos en Azure SQL.
  - [ ] **PWA y rutas limpias:** `/parques`, `/rutinas`, `/chatbot`, `/ajustes`, `/legal` responden `200 OK`; una ruta inexistente muestra `404.html`; `sw.js` se registra con `Cache-Control: no-cache` y `manifest.webmanifest` sirve `application/manifest+json`.
  - [ ] **Higiene final:** cambia las contraseñas de las cuentas demo (`admin@pausasactivas.edu.mx` / `estudiante@pausasactivas.edu.mx`) o elimínalas si ya creaste cuentas reales.
