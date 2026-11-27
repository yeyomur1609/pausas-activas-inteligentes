/**
 * Cliente HTTP centralizado (ES Module) — Pausas Activas Inteligentes
 * Gestiona cookies de sesión cross-domain (`credentials: 'include'`), handshake CSRF (`X-CSRF-Token`),
 * tolerancia al cold start de Render Free (timeout 65 s + aviso progresivo no invasivo),
 * reintento automático ante CSRF expirado y redirección HTTP 401 en vistas protegidas.
 */
import { PAI_CONFIG, STORAGE_KEYS } from './config.js';

const AUTH_TOKEN_STORAGE_KEY = (STORAGE_KEYS && STORAGE_KEYS.AUTH_TOKEN) || 'pai_auth_token_v1';
const SESSION_CACHE_STORAGE_KEY = 'pai_session_cache';
export const SESSION_CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutos

let csrfToken = null;
let memoryAuthToken = null;
let currentSessionUser = null;
let backendAwake = false;
let coldStartNoticeShown = false;

function notifyColdStartPending() {
  if (typeof document === 'undefined') return;

  // Actualizar el mensaje del overlay de verificación si la página sigue bloqueada por auth-checking
  if (document.body && document.body.classList.contains('auth-checking')) {
    const overlayText = document.querySelector('#auth-checking-overlay .auth-checking-text');
    if (overlayText) {
      overlayText.textContent =
        'Despertando el servidor en la nube… la primera carga puede tardar entre 30 y 50 segundos.';
    }
  }

  if (coldStartNoticeShown) return;
  coldStartNoticeShown = true;

  const toast = document.getElementById('app-toast');
  if (toast) {
    toast.textContent = 'Conectando con el servidor en la nube (inicio en frío, ~30–50 s)…';
    toast.className = 'toast-notification toast-info';
  }
}

function clearColdStartNotice() {
  backendAwake = true;
  if (!coldStartNoticeShown || typeof document === 'undefined') return;
  coldStartNoticeShown = false;

  const toast = document.getElementById('app-toast');
  if (
    toast &&
    !toast.classList.contains('hidden') &&
    String(toast.textContent || '').includes('inicio en frío')
  ) {
    toast.classList.add('hidden');
  }
}

function getBaseUrl() {
  const configured = PAI_CONFIG && typeof PAI_CONFIG.API_BASE_URL === 'string' ? PAI_CONFIG.API_BASE_URL.trim() : '';
  return (configured || '/api').replace(/\/+$/, '');
}

export function setCsrfToken(token) {
  if (typeof token === 'string' && token.length > 0) {
    csrfToken = token;
  }
}

export function getCsrfToken() {
  return csrfToken;
}

export function getAuthToken() {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      const stored = window.localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);
      if (typeof stored === 'string' && stored.trim().length > 0) {
        memoryAuthToken = stored.trim();
        return memoryAuthToken;
      }
    }
  } catch (_ignored) {
    // Modo privado estricto o almacenamiento bloqueado: usar copia en memoria
  }
  return memoryAuthToken;
}

export function setAuthToken(token) {
  if (typeof token !== 'string' || token.trim().length === 0) {
    return;
  }
  const clean = token.trim();
  memoryAuthToken = clean;
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, clean);
    }
  } catch (_ignored) {}
}

export function clearAuthToken() {
  memoryAuthToken = null;
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
    }
  } catch (_ignored) {}
}

function buildCacheableUserSnapshot(user, includePhoto = true) {
  if (!user || typeof user !== 'object' || !user.id) return null;
  const prefs =
    user.preferencias && typeof user.preferencias === 'object'
      ? includePhoto
        ? { ...user.preferencias }
        : { ...user.preferencias, foto_perfil: '' }
      : undefined;

  return {
    id: Number(user.id),
    nombre: String(user.nombre || ''),
    email: String(user.email || ''),
    rol: String(user.rol || 'usuario'),
    ocupacion: String(user.ocupacion || 'estudiante'),
    email_verificado: Boolean(user.email_verificado),
    activo: user.activo !== undefined ? Boolean(user.activo) : true,
    horas_restantes_verificacion: user.horas_restantes_verificacion ?? null,
    dias_restantes_verificacion: user.dias_restantes_verificacion ?? null,
    racha_actual: Number(user.racha_actual || 0),
    mejor_racha: Number(user.mejor_racha || 0),
    ultima_pausa_fecha: user.ultima_pausa_fecha || null,
    meta_diaria_min: Number(user.meta_diaria_min || 15),
    preferencias: prefs
  };
}

export function saveSessionCache(user) {
  const snapshot = buildCacheableUserSnapshot(user, true);
  if (!snapshot) return;
  try {
    if (typeof window !== 'undefined' && window.sessionStorage) {
      const payload = JSON.stringify({ user: snapshot, checkedAt: Date.now() });
      window.sessionStorage.setItem(SESSION_CACHE_STORAGE_KEY, payload);
    }
  } catch (_quotaErr) {
    // Reintentar sin foto base64 si sessionStorage alcanza cuota
    try {
      const slimSnapshot = buildCacheableUserSnapshot(user, false);
      if (slimSnapshot && typeof window !== 'undefined' && window.sessionStorage) {
        window.sessionStorage.setItem(
          SESSION_CACHE_STORAGE_KEY,
          JSON.stringify({ user: slimSnapshot, checkedAt: Date.now() })
        );
      }
    } catch (_ignored) {}
  }
}

export function clearSessionCache() {
  try {
    if (typeof window !== 'undefined' && window.sessionStorage) {
      window.sessionStorage.removeItem(SESSION_CACHE_STORAGE_KEY);
    }
  } catch (_ignored) {}
}

export function getCachedSession(maxAgeMs = SESSION_CACHE_TTL_MS) {
  try {
    if (typeof window === 'undefined' || !window.sessionStorage) return null;
    const raw = window.sessionStorage.getItem(SESSION_CACHE_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (
      !parsed ||
      typeof parsed !== 'object' ||
      !parsed.user ||
      !parsed.user.id ||
      typeof parsed.checkedAt !== 'number'
    ) {
      clearSessionCache();
      return null;
    }
    const ageMs = Date.now() - parsed.checkedAt;
    if (ageMs < 0 || ageMs >= maxAgeMs) {
      clearSessionCache();
      return null;
    }
    return {
      user: parsed.user,
      checkedAt: parsed.checkedAt,
      ageMs
    };
  } catch (_ignored) {
    clearSessionCache();
    return null;
  }
}

export function getSessionUser() {
  return currentSessionUser;
}

export function setSessionUser(user) {
  currentSessionUser = user || null;
}

export async function request(endpoint, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  let url = `${getBaseUrl()}/${String(endpoint).replace(/^\/+/, '')}`;

  if (options.params && typeof options.params === 'object') {
    const searchParams = new URLSearchParams();
    Object.keys(options.params).forEach((key) => {
      const val = options.params[key];
      if (val !== undefined && val !== null && val !== '') {
        searchParams.append(key, String(val));
      }
    });
    const queryString = searchParams.toString();
    if (queryString) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + queryString;
    }
  }

  const activeJwt = getAuthToken();

  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method) && !csrfToken && !activeJwt) {
    try {
      await syncSession();
    } catch (_ignored) {
      // Continuar para retornar error estándar si el servidor no responde
    }
  }

  const headers = { Accept: 'application/json' };
  if (options.body !== undefined && options.body !== null) {
    headers['Content-Type'] = 'application/json; charset=utf-8';
  }
  const latestJwt = getAuthToken();
  if (latestJwt) {
    headers.Authorization = `Bearer ${latestJwt}`;
  }
  if (csrfToken && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const fetchConfig = {
    method,
    credentials: 'include',
    headers
  };

  if (options.body !== undefined && options.body !== null) {
    fetchConfig.body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
  }

  // Timeout tolerante al cold start de Render Free (65 s) compuesto con cualquier AbortSignal externo (ej. mapa)
  const timeoutMs =
    typeof options.timeoutMs === 'number' && options.timeoutMs > 0
      ? options.timeoutMs
      : (PAI_CONFIG && PAI_CONFIG.REQUEST_TIMEOUT_MS) || 65000;
  const coldNoticeMs = (PAI_CONFIG && PAI_CONFIG.COLD_START_NOTICE_MS) || 4500;

  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  let timedOut = false;
  let callerAborted = false;
  let timeoutId = null;
  let coldNoticeId = null;

  const onCallerAbort = () => {
    callerAborted = true;
    if (controller) controller.abort();
  };

  if (options.signal) {
    if (options.signal.aborted) {
      const abortErr = new Error('Solicitud cancelada por el cliente.');
      abortErr.name = 'AbortError';
      abortErr.isAborted = true;
      throw abortErr;
    }
    options.signal.addEventListener('abort', onCallerAbort, { once: true });
  }

  if (controller) {
    fetchConfig.signal = controller.signal;
    timeoutId = window.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);
  }

  if (!backendAwake && !options.silentColdStart) {
    coldNoticeId = window.setTimeout(() => {
      notifyColdStartPending();
    }, coldNoticeMs);
  }

  let response;
  try {
    response = await window.fetch(url, fetchConfig);
    clearColdStartNotice();
  } catch (networkErr) {
    if (callerAborted || (!timedOut && networkErr && networkErr.name === 'AbortError')) {
      networkErr.isAborted = true;
      throw networkErr;
    }
    if (timedOut) {
      const timeoutError = new Error(
        'El servidor tardó demasiado en despertar (inicio en frío). Intenta nuevamente en unos segundos.'
      );
      timeoutError.status = 408;
      timeoutError.isTimeout = true;
      timeoutError.cause = networkErr;
      throw timeoutError;
    }
    const netError = new Error('No se pudo conectar con el servidor Backend. Verifica tu conexión o intenta de nuevo.');
    netError.status = 0;
    netError.cause = networkErr;
    throw netError;
  } finally {
    if (timeoutId !== null) window.clearTimeout(timeoutId);
    if (coldNoticeId !== null) window.clearTimeout(coldNoticeId);
    if (options.signal) {
      options.signal.removeEventListener('abort', onCallerAbort);
    }
  }

  let payload = null;
  try {
    const rawText = await response.text();
    const cleanedText = rawText.replace(/^\uFEFF/, '').trim();
    if (!cleanedText) {
      payload = {
        ok: false,
        error: `El servidor devolvió una respuesta vacía (HTTP ${response.status}).`
      };
    } else {
      try {
        payload = JSON.parse(cleanedText);
      } catch (_directParseErr) {
        const jsonStart = cleanedText.indexOf('{');
        const jsonEnd = cleanedText.lastIndexOf('}');
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
          payload = JSON.parse(cleanedText.slice(jsonStart, jsonEnd + 1));
        } else {
          throw _directParseErr;
        }
      }
    }
  } catch (_parseErr) {
    payload = {
      ok: false,
      error: `Respuesta inválida del servidor (HTTP ${response.status}).`
    };
  }

  if (payload && typeof payload === 'object' && !payload.datos) {
    payload.datos = payload;
  }

  const nextCsrf = (payload && payload.csrf_token) || (payload && payload.datos && payload.datos.csrf_token);
  if (nextCsrf) {
    setCsrfToken(nextCsrf);
  }

  const nextJwt = (payload && payload.token) || (payload && payload.datos && payload.datos.token);
  if (typeof nextJwt === 'string' && nextJwt.length > 0) {
    setAuthToken(nextJwt);
  }

  const nextUser =
    payload && payload.ok !== false && payload.usuario && payload.usuario.id ? payload.usuario : null;
  if (nextUser) {
    currentSessionUser = nextUser;
    saveSessionCache(nextUser);
  }

  if (
    response.status === 403 &&
    payload &&
    payload.codigo === 'CSRF_INVALID' &&
    !options._retriedCsrf
  ) {
    try {
      await syncSession();
    } catch (_ignored) {}
    if (csrfToken) {
      return request(endpoint, { ...options, _retriedCsrf: true });
    }
  }

  const isFailure = !response.ok || (payload && (payload.ok === false || payload.exito === false));
  if (isFailure) {
    if (response.status === 401) {
      const isAuthEndpoint = String(endpoint || '').includes('auth.php');
      if (!isAuthEndpoint) {
        clearSessionCache();
        clearAuthToken();
        currentSessionUser = null;
      }
      if (window.location && document) {
        const initialView = document.body ? document.body.getAttribute('data-initial-view') : '';
        const currentSeg = (window.location.pathname || '').split('/').pop() || 'index.html';
        const currentSegLower = currentSeg.toLowerCase();
        const isPublicPage =
          ['login', 'legal', '404'].includes(initialView) ||
          ['login.html', 'login', 'legal.html', 'legal', '404.html'].includes(currentSegLower);
        if (!isPublicPage && !isAuthEndpoint) {
          const nextPage =
            currentSegLower === 'login.html' || currentSegLower === 'login' || !currentSeg
              ? 'index.html'
              : currentSeg;
          window.location.replace(`login.html?next=${encodeURIComponent(nextPage)}`);
        }
      }
    }

    const errMsg =
      payload && (payload.error || payload.mensaje)
        ? payload.error || payload.mensaje
        : `Error HTTP ${response.status}`;
    const apiError = new Error(errMsg);
    apiError.status = response.status;
    apiError.datos = payload || {};
    throw apiError;
  }

  return payload;
}

export async function syncSession(options = {}) {
  const res = await request('auth.php', {
    method: 'GET',
    params: { accion: 'session' },
    silentColdStart: Boolean(options.silentColdStart)
  });
  if (res) {
    if (res.csrf_token) {
      setCsrfToken(res.csrf_token);
    }
    if (res.token) {
      setAuthToken(res.token);
    } else if (!res.autenticado) {
      clearAuthToken();
    }
    if (res.autenticado && res.usuario) {
      currentSessionUser = res.usuario;
      saveSessionCache(res.usuario);
    } else {
      currentSessionUser = null;
      clearSessionCache();
    }
  }
  return res;
}

export const ApiClient = {
  request,
  syncSession,
  getCsrfToken,
  setCsrfToken,
  getAuthToken,
  setAuthToken,
  clearAuthToken,
  saveSessionCache,
  getCachedSession,
  clearSessionCache,
  getSessionUser,
  setSessionUser,

  auth: {
    session: syncSession,
    async login(correo, password) {
      const res = await request('auth.php?accion=login', {
        method: 'POST',
        body: { accion: 'login', email: correo, password }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    async registro(datosRegistro) {
      const email = datosRegistro.email || datosRegistro.correo || '';
      const res = await request('auth.php?accion=registro', {
        method: 'POST',
        body: {
          accion: 'registro',
          nombre: datosRegistro.nombre || '',
          email,
          ocupacion: datosRegistro.ocupacion || 'estudiante',
          password: datosRegistro.password || '',
          acepto_privacidad: Boolean(datosRegistro.acepto_privacidad)
        }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    async verificarEmail(correo, codigo, token) {
      const res = await request('auth.php?accion=verificar_email', {
        method: 'POST',
        body: {
          accion: 'verificar_email',
          email: correo || '',
          codigo: codigo || '',
          token: token || ''
        }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    reenviarCodigo(correo) {
      return request('auth.php?accion=reenviar_verificacion', {
        method: 'POST',
        body: { accion: 'reenviar_verificacion', email: correo }
      });
    },
    solicitarMagicLink(correo) {
      return request('auth.php?accion=solicitar_magic_link', {
        method: 'POST',
        body: { accion: 'solicitar_magic_link', email: correo }
      });
    },
    async validarMagicLink(token, correo, codigo) {
      const res = await request('auth.php?accion=validar_magic_link', {
        method: 'POST',
        body: {
          accion: 'validar_magic_link',
          token: token || '',
          email: correo || '',
          codigo: codigo || ''
        }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    async google(credential) {
      const res = await request('auth.php?accion=google_oauth', {
        method: 'POST',
        body: {
          accion: 'google_oauth',
          credential: credential || ''
        }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    solicitarRecuperacion(correo) {
      return request('auth.php?accion=solicitar_reset', {
        method: 'POST',
        body: { accion: 'solicitar_reset', email: correo }
      });
    },
    async confirmarRecuperacion(correo, codigo, passwordNueva, token = '') {
      const res = await request('auth.php?accion=confirmar_reset', {
        method: 'POST',
        body: {
          accion: 'confirmar_reset',
          email: correo || '',
          codigo: codigo || '',
          token: token || '',
          password_nueva: passwordNueva
        }
      });
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        currentSessionUser = res.usuario;
      }
      return res;
    },
    async logout() {
      try {
        return await request('auth.php?accion=logout', {
          method: 'POST',
          body: { accion: 'logout' }
        });
      } finally {
        clearSessionCache();
        clearAuthToken();
        currentSessionUser = null;
      }
    }
  },

  usuarios: {
    perfil() {
      return request('usuarios.php', { method: 'GET', params: { accion: 'perfil' } });
    },
    estadisticas() {
      return request('usuarios.php', { method: 'GET', params: { accion: 'estadisticas' } });
    },
    comunidad() {
      return request('usuarios.php', { method: 'GET', params: { accion: 'comunidad' } });
    },
    actualizarPerfil(datos) {
      return request('usuarios.php?accion=actualizar_perfil', {
        method: 'PUT',
        body: {
          accion: 'actualizar_perfil',
          nombre: datos.nombre || '',
          ocupacion: datos.ocupacion || 'estudiante',
          meta_diaria_min: Number(datos.meta_diaria_min || 15),
          preferencias: datos.preferencias || undefined
        }
      });
    },
    guardarPreferencias(preferencias) {
      return request('usuarios.php?accion=guardar_preferencias', {
        method: 'PUT',
        body: { accion: 'guardar_preferencias', preferencias }
      });
    },
    cambiarEmail(nuevoEmail, passwordActual) {
      return request('usuarios.php?accion=cambiar_email', {
        method: 'PUT',
        body: { accion: 'cambiar_email', nuevo_email: nuevoEmail, password_actual: passwordActual }
      });
    },
    enviarResumenEmail() {
      return request('usuarios.php?accion=enviar_resumen_email', {
        method: 'POST',
        body: { accion: 'enviar_resumen_email' }
      });
    },
    cambiarPassword(passwordActual, passwordNueva) {
      return request('usuarios.php?accion=cambiar_password', {
        method: 'POST',
        body: {
          accion: 'cambiar_password',
          password_actual: passwordActual,
          password_nueva: passwordNueva
        }
      });
    },
    async eliminarCuenta(confirmacion, passwordActual) {
      try {
        return await request('usuarios.php?accion=eliminar_cuenta', {
          method: 'DELETE',
          body: { accion: 'eliminar_cuenta', confirmacion, password_actual: passwordActual }
        });
      } finally {
        clearSessionCache();
        clearAuthToken();
        currentSessionUser = null;
      }
    }
  },

  mapa: {
    parquesCercanos(lat, lng, radio = 3000, signal = null) {
      return request('mapa.php', {
        method: 'GET',
        params: { accion: 'parques_cercanos', lat, lng, radio },
        signal
      });
    },
    buscarDireccion(q) {
      return request('mapa.php', {
        method: 'GET',
        params: { accion: 'buscar_lugar', q }
      });
    },
    rutaPeatonal(origenLat, origenLng, destinoLat, destinoLng) {
      return request('mapa.php', {
        method: 'GET',
        params: {
          accion: 'ruta_peatonal',
          origen_lat: origenLat,
          origen_lng: origenLng,
          destino_lat: destinoLat,
          destino_lng: destinoLng
        }
      });
    },
    favoritos() {
      return request('mapa.php', {
        method: 'GET',
        params: { accion: 'favoritos' }
      });
    },
    guardarFavorito(parque) {
      return request('mapa.php?accion=guardar_favorito', {
        method: 'POST',
        body: {
          accion: 'guardar_favorito',
          parque_osm_id: parque.parque_osm_id || parque.osm_id || '',
          nombre: parque.nombre || 'Parque Santa Catarina',
          lat: parque.lat || parque.latitud || 25.6768,
          lng: parque.lng || parque.longitud || -100.4612,
          direccion: parque.direccion || 'Santa Catarina, N.L.',
          equipamiento: parque.equipamiento || [],
          accesibilidad_silla: Boolean(parque.accesibilidad_silla || parque.accesible_silla_ruedas)
        }
      });
    },
    eliminarFavorito(favId) {
      return request('mapa.php?accion=eliminar_favorito', {
        method: 'DELETE',
        body: { accion: 'eliminar_favorito', id: Number(favId) }
      });
    },
    registrarCaminata(parque, minutos = 10) {
      return request('mapa.php?accion=registrar_caminata', {
        method: 'POST',
        body: {
          accion: 'registrar_caminata',
          nombre_parque: parque && parque.nombre ? parque.nombre : 'Parque Público Santa Catarina',
          parque_osm_id:
            parque && (parque.parque_osm_id || parque.osm_id) ? parque.parque_osm_id || parque.osm_id : '',
          minutos: Number(minutos)
        }
      });
    }
  },

  rutinas: {
    catalogo(filtros = {}) {
      return request('rutinas.php', {
        method: 'GET',
        params: { accion: 'catalogo', ...filtros }
      });
    },
    detalle(id) {
      return request('rutinas.php', { method: 'GET', params: { accion: 'detalle', id } });
    },
    recomendada(opciones) {
      const opts =
        typeof opciones === 'object' && opciones !== null
          ? opciones
          : { modo_sentado: Boolean(opciones) };
      return request('rutinas.php', {
        method: 'GET',
        params: {
          accion: 'recomendada',
          categoria: opts.categoria || 'computadora',
          necesidad: opts.necesidad || 'relajarse',
          duracion_minutos: Number(opts.duracion_minutos || 3),
          modo_sentado: opts.modo_sentado ? '1' : '0'
        }
      });
    },
    registrarPausa(datosPausa) {
      const mins = Math.max(1, Math.round(Number(datosPausa.duracion_segundos || 180) / 60));
      return request('rutinas.php?accion=completar_pausa', {
        method: 'POST',
        body: {
          accion: 'completar_pausa',
          rutina_id: datosPausa.rutina_id || 1,
          ubicacion_nombre: datosPausa.parque_nombre || 'Espacio Personal / Santa Catarina',
          parque_osm_id: datosPausa.parque_osm_id || null,
          actividad_realizada: datosPausa.actividad || 'computadora',
          necesidad_atendida: datosPausa.necesidad || 'moverse',
          tiempo_minutos: mins,
          completada_sentado: Boolean(datosPausa.modo_sentado),
          esfuerzo: Number(datosPausa.esfuerzo || 3),
          calificacion: Number(datosPausa.calificacion || datosPausa.utilidad || 5)
        }
      });
    }
  },

  chat: {
    enviarMensaje(mensaje, contextoParque, historial) {
      const parkText =
        contextoParque && contextoParque.nombre
          ? `${contextoParque.nombre} (${contextoParque.direccion || 'Santa Catarina'})`
          : null;
      const payload = {
        accion: 'mensaje',
        mensaje,
        parque_contexto: parkText
      };
      if (Array.isArray(historial) && historial.length > 0) {
        payload.historial = historial.slice(-6);
      }
      return request('chat.php', { method: 'POST', body: payload });
    },
    limpiarHistorial() {
      return request('chat.php', {
        method: 'POST',
        body: { accion: 'limpiar_historial' }
      });
    }
  }
};

window.ApiClient = ApiClient;
export default ApiClient;
