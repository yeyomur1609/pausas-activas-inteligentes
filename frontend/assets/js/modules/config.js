/**
 * Configuración global del cliente Frontend (ES Module) — Pausas Activas Inteligentes
 * Sin claves secretas ni credenciales privadas (seguridad por diseño).
 *
 * Despliegue en producción:
 * - Frontend estático + PWA en Vercel (Free Tier)
 * - Backend Docker PHP/Apache en Render (Free Tier) -> actualizar PRODUCTION_API_BASE_URL con tu URL real de Render
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

// Permite sobrescribir opcionalmente la URL del backend mediante <meta name="pai-api-base" content="https://.../api">
const metaApiBase =
  typeof document !== 'undefined'
    ? (document.querySelector('meta[name="pai-api-base"]')?.getAttribute('content') || '').trim()
    : '';

// Autodetección de entorno:
// 1. Etiqueta <meta name="pai-api-base"> explícita (si existe)
// 2. XAMPP / Apache local (/.../frontend/): mismo origen -> /.../backend/public/api
// 3. Servidor local separado (localhost:3000 / 127.0.0.1:3000): backend en :8000/api
// 4. Producción (Vercel *.vercel.app o dominio propio): PRODUCTION_API_BASE_URL (Render)
let defaultApiBase;
if (metaApiBase) {
  defaultApiBase = metaApiBase.replace(/\/+$/, '');
} else if (isApacheLocalFrontend) {
  const basePrefix = frontendIdx > 0 ? pathname.slice(0, frontendIdx) : '';
  defaultApiBase = `${origin}${basePrefix}/backend/public/api`;
} else if (isLocalHost) {
  defaultApiBase = `${protocol}//${hostname}:8000/api`;
} else {
  defaultApiBase = PRODUCTION_API_BASE_URL;
}

export const STORAGE_KEYS = Object.freeze({
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
  APP_VERSION: '3.1.0',
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
