/**
 * Configuración global del cliente Frontend (ES Module) — Pausas Activas Inteligentes
 * Sin claves secretas ni credenciales privadas (seguridad por diseño).
 *
 * Orden de prioridad para resolver la URL base del Backend API:
 * 1. <meta name="pai-api-base" content="https://.../api"> (en el HTML si se inyecta en despliegue)
 * 2. window.__PAI_API_BASE__ (variable global opcional en tiempo de ejecución)
 * 3. Autodetección local (XAMPP Apache en /frontend o servidor local en localhost:8000)
 * 4. PRODUCTION_API_BASE_URL -> Coloca aquí la URL real de tu Web Service en Render terminada en /api
 */
const PRODUCTION_API_BASE_URL = 'https://pausas-activas-api.onrender.com/api';

const hostname = (window.location && window.location.hostname) || 'localhost';
const port = (window.location && window.location.port) || '';
const pathname = (window.location && window.location.pathname) || '/';
const origin = (window.location && window.location.origin) || 'http://localhost';
const protocol = (window.location && window.location.protocol) || 'http:';

const isLocalHost = hostname === 'localhost' || hostname === '127.0.0.1';
const frontendIdx = pathname.toLowerCase().indexOf('/frontend');
const isApacheLocalFrontend =
  isLocalHost && frontendIdx !== -1 && (port === '' || port === '80' || port === '443');

const metaApiBase =
  typeof document !== 'undefined'
    ? (document.querySelector('meta[name="pai-api-base"]')?.getAttribute('content') || '').trim()
    : '';

const globalWindowApiBase =
  typeof window !== 'undefined' && typeof window.__PAI_API_BASE__ === 'string'
    ? window.__PAI_API_BASE__.trim()
    : '';

let defaultApiBase;
if (metaApiBase) {
  defaultApiBase = metaApiBase.replace(/\/+$/, '');
} else if (globalWindowApiBase) {
  defaultApiBase = globalWindowApiBase.replace(/\/+$/, '');
} else if (isApacheLocalFrontend) {
  const basePrefix = frontendIdx > 0 ? pathname.slice(0, frontendIdx) : '';
  defaultApiBase = `${origin}${basePrefix}/backend/public/api`;
} else if (isLocalHost) {
  defaultApiBase = `${protocol}//${hostname}:8000/api`;
} else {
  defaultApiBase = PRODUCTION_API_BASE_URL.replace(/\/+$/, '');
}

export const STORAGE_KEYS = Object.freeze({
  AUTH_TOKEN: 'pai_auth_token_v1',
  A11Y: 'pai_a11y_prefs_v4',
  COOKIES_ACCEPTED: 'pai_cookies_accepted_v1',
  GUEST_STATS: 'pai_guest_stats_v2',
  FAVORITE_PARKS: 'pai_guest_fav_parks_v1',
  SELECTED_PARK: 'pai_selected_park_v2',
  MAP_BASE_LAYER: 'pai_map_base_layer_v1',
  PWA_DISMISSED: 'pai_pwa_banner_dismissed_v1'
});

export const PAI_CONFIG = Object.freeze({
  APP_NAME: 'Pausas Activas Inteligentes',
  APP_VERSION: '3.2.0',
  API_BASE_URL: defaultApiBase,
  IS_LOCAL_ENV: isLocalHost,
  // Tiempo máximo tolerado para absorber el cold start de Render Free (30–50 s) + conexión a Azure SQL
  REQUEST_TIMEOUT_MS: 65000,
  // Umbral para avisar al usuario que el servidor gratuito está despertando
  COLD_START_NOTICE_MS: 4500,
  GOOGLE_CLIENT_ID: '',
  DEFAULT_COORDS: Object.freeze({
    lat: 25.6768,
    lng: -100.4615,
    zoom: 14,
    municipio: 'Santa Catarina, Nuevo León'
  }),
  STORAGE_KEYS
});

window.PAI_CONFIG = PAI_CONFIG;
export default PAI_CONFIG;
