/**
 * Módulo de Autenticación, Guardia de Sesión MPA y Página de Login (ES Module)
 */
import {
  ApiClient,
  clearAuthToken,
  clearSessionCache,
  getAuthToken,
  getCachedSession,
  saveSessionCache,
  setAuthToken,
  setSessionUser
} from './api-client.js';
import {
  applyA11yToDOM,
  bindA11yBarEvents,
  bindA11yWidgetEvents,
  hydrateUserPreferencesFromServer,
  loadLocalA11yPreferences,
  showToast
} from './ui.js';

const ALLOWED_NEXT_PAGES = new Set([
  'index.html',
  'parques.html',
  'rutinas.html',
  'chatbot.html',
  'ajustes.html',
  'legal.html',
  'parques',
  'rutinas',
  'chatbot',
  'ajustes',
  'legal'
]);

const VALID_AUTH_TABS = new Set([
  'login',
  'registro',
  'magic_link',
  'verificar_email',
  'recuperar_solicitar',
  'recuperar_confirmar'
]);

const SESSION_REVALIDATE_AFTER_MS = 60 * 1000; // Revalidar silenciosamente en segundo plano tras 1 min
let pendingResetToken = '';

// Si el usuario ya tiene sesión válida en caché (sessionStorage < 5 min), retirar el overlay síncronamente
// al evaluar el módulo para evitar cualquier destello de "Verificando tu sesión…" al cambiar de apartado.
if (typeof document !== 'undefined' && document.body && document.body.classList.contains('auth-checking')) {
  const fastCached = getCachedSession();
  if (fastCached && fastCached.user) {
    setSessionUser(fastCached.user);
    document.body.classList.remove('auth-checking');
  }
}

export function sanitizeNextTarget(rawNext) {
  if (!rawNext || typeof rawNext !== 'string') return 'index.html';
  const cleaned = rawNext.trim();
  if (
    !cleaned ||
    cleaned.startsWith('/') ||
    cleaned.startsWith('\\') ||
    cleaned.includes('://') ||
    cleaned.includes('..') ||
    /[\r\n]/.test(cleaned)
  ) {
    return 'index.html';
  }
  const qIdx = cleaned.indexOf('?');
  const base = qIdx === -1 ? cleaned : cleaned.slice(0, qIdx);
  const query = qIdx === -1 ? '' : cleaned.slice(qIdx);
  if (!ALLOWED_NEXT_PAGES.has(base)) {
    return 'index.html';
  }
  return base + query;
}

export function getCurrentNextTarget() {
  const rawSeg = (window.location.pathname || '').split('/').pop() || 'index.html';
  const lowerSeg = rawSeg.toLowerCase();
  if (!rawSeg || lowerSeg === 'login.html' || lowerSeg === 'login') {
    return 'index.html';
  }
  return sanitizeNextTarget(rawSeg);
}

export function getValidatedNextTarget() {
  const params = new URLSearchParams(window.location.search);
  return sanitizeNextTarget(params.get('next'));
}

export function buildLoginUrl(tab = '', customNext = '', forwardAuthSearch = false) {
  const out = new URLSearchParams();
  if (forwardAuthSearch) {
    const current = new URLSearchParams(window.location.search);
    ['magic_token', 'token', 'email', 'vista', 'accion', 'tab'].forEach((key) => {
      const val = current.get(key);
      if (val) out.set(key, val);
    });
  }
  if (tab && VALID_AUTH_TABS.has(tab)) {
    out.set('tab', tab);
  }
  out.set('next', sanitizeNextTarget(customNext || getCurrentNextTarget()));
  return `login.html?${out.toString()}`;
}

export function redirectAfterLogin() {
  window.location.replace(getValidatedNextTarget());
}

export function cleanAuthQueryParamsPreserveNext() {
  const current = new URLSearchParams(window.location.search);
  const nextVal = current.get('next');
  const cleanUrl = nextVal
    ? `${window.location.pathname}?next=${encodeURIComponent(sanitizeNextTarget(nextVal))}`
    : window.location.pathname;
  window.history.replaceState({}, document.title, cleanUrl);
}

export async function checkUrlAuthTokens() {
  const params = new URLSearchParams(window.location.search);
  const magicToken = params.get('magic_token');
  const vista = params.get('vista');
  const accion = params.get('accion');
  const verifyToken = params.get('token');
  const emailParam = params.get('email') || '';

  if (magicToken) {
    try {
      const res = await ApiClient.auth.validarMagicLink(magicToken, emailParam, '');
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        setSessionUser(res.usuario);
        hydrateUserPreferencesFromServer(res.usuario);
        showToast('¡Sesión iniciada con Enlace Mágico y correo confirmado!', 'success');
        return res.usuario;
      }
    } catch (err) {
      showToast(err.message || 'El enlace mágico expiró o ya fue utilizado.', 'error');
    } finally {
      cleanAuthQueryParamsPreserveNext();
    }
    return null;
  }

  const isVerifyAction =
    vista === 'verificar-email' || vista === 'verificar_email' || accion === 'verificar_email';
  if (isVerifyAction && verifyToken) {
    try {
      const res = await ApiClient.auth.verificarEmail(emailParam, '', verifyToken);
      if (res && res.token) {
        setAuthToken(res.token);
      }
      if (res && res.usuario) {
        setSessionUser(res.usuario);
        hydrateUserPreferencesFromServer(res.usuario);
      }
      showToast(res.mensaje || '¡Correo electrónico verificado con éxito!', 'success');
      return (res && res.usuario) || null;
    } catch (err) {
      showToast(err.message || 'El enlace de verificación expiró o no es válido.', 'error');
    } finally {
      cleanAuthQueryParamsPreserveNext();
    }
  }
  return null;
}

function redirectToLoginUnauthenticated(forwardAuthAction = true) {
  clearSessionCache();
  clearAuthToken();
  setSessionUser(null);
  const searchParams = new URLSearchParams(window.location.search);
  const hasAuthAction =
    forwardAuthAction &&
    Boolean(
      searchParams.get('magic_token') ||
        searchParams.get('token') ||
        searchParams.get('vista') ||
        searchParams.get('accion')
    );
  window.location.replace(buildLoginUrl('', getCurrentNextTarget(), hasAuthAction));
}

function revalidateSessionInBackground(onBackgroundRefresh) {
  ApiClient.syncSession({ silentColdStart: true })
    .then((freshData) => {
      if (!freshData || !freshData.autenticado || !freshData.usuario) {
        redirectToLoginUnauthenticated(false);
        return;
      }
      setSessionUser(freshData.usuario);
      saveSessionCache(freshData.usuario);
      hydrateUserPreferencesFromServer(freshData.usuario);
      if (typeof onBackgroundRefresh === 'function') {
        onBackgroundRefresh(freshData.usuario, freshData);
      }
    })
    .catch((err) => {
      if (err && err.status === 401) {
        redirectToLoginUnauthenticated(false);
      }
      // Ignorar abortos por navegación rápida o cortes transitorios de red para no interrumpir la UI
    });
}

/**
 * Guardia obligatoria para ventanas protegidas con caché síncrona en sessionStorage (< 5 min):
 * - Si existe sesión válida en caché: permite renderizar de inmediato sin overlay y revalida en segundo plano si hace falta.
 * - Si no existe caché: muestra el overlay "Verificando tu sesión…" hasta confirmar con el servidor.
 */
export async function requireAuthOrRedirect({ onBackgroundRefresh = null } = {}) {
  const cached = getCachedSession();
  if (cached && cached.user) {
    if (document.body) {
      document.body.classList.remove('auth-checking');
    }
    setSessionUser(cached.user);
    hydrateUserPreferencesFromServer(cached.user);

    // Refrescar silenciosamente en segundo plano si la caché supera 1 minuto o falta el token Bearer
    if (cached.ageMs >= SESSION_REVALIDATE_AFTER_MS || !getAuthToken()) {
      revalidateSessionInBackground(onBackgroundRefresh);
    }

    return {
      user: cached.user,
      sessionData: { ok: true, autenticado: true, usuario: cached.user },
      fromCache: true
    };
  }

  let sessionData = null;
  try {
    sessionData = await ApiClient.syncSession();
  } catch (_err) {
    sessionData = null;
  }

  if (!sessionData || !sessionData.autenticado || !sessionData.usuario) {
    redirectToLoginUnauthenticated(true);
    return null;
  }

  setSessionUser(sessionData.usuario);
  saveSessionCache(sessionData.usuario);
  hydrateUserPreferencesFromServer(sessionData.usuario);
  return { user: sessionData.usuario, sessionData, fromCache: false };
}

export async function syncOptionalSession() {
  try {
    const sessionData = await ApiClient.syncSession();
    if (sessionData && sessionData.autenticado && sessionData.usuario) {
      setSessionUser(sessionData.usuario);
      saveSessionCache(sessionData.usuario);
      hydrateUserPreferencesFromServer(sessionData.usuario);
      return sessionData.usuario;
    }
  } catch (_err) {
    // Ruta pública sin sesión activa
  }
  clearSessionCache();
  setSessionUser(null);
  return null;
}

export async function handleLogout() {
  try {
    await ApiClient.auth.logout();
  } catch (_err) {
    // Limpiar sesión cliente igualmente
  }
  clearSessionCache();
  clearAuthToken();
  setSessionUser(null);
  window.location.replace('login.html');
}

function updatePasswordStrengthMeter(pwd) {
  const fillEl = document.getElementById('reg-password-meter-fill');
  const labelEl = document.getElementById('reg-password-meter-label');
  if (!fillEl || !labelEl) return;

  let score = 0;
  if (pwd.length >= 8) score += 25;
  if (/[A-Z]/.test(pwd)) score += 25;
  if (/[0-9]/.test(pwd)) score += 25;
  if (/[^A-Za-z0-9]/.test(pwd)) score += 25;

  fillEl.value = score;
  fillEl.classList.remove('meter-weak', 'meter-fair', 'meter-good', 'meter-strong');

  if (score <= 25) {
    fillEl.classList.add('meter-weak');
    labelEl.textContent = 'Seguridad: Débil (agrega mayúscula, número y símbolo)';
  } else if (score === 50) {
    fillEl.classList.add('meter-fair');
    labelEl.textContent = 'Seguridad: Media (agrega número y símbolo)';
  } else if (score === 75) {
    fillEl.classList.add('meter-good');
    labelEl.textContent = 'Seguridad: Buena (falta un requisito)';
  } else {
    fillEl.classList.add('meter-strong');
    labelEl.textContent = 'Seguridad: Excelente • Cumple todos los factores';
  }
}

export function switchAuthTab(tabName, focusPanel = false) {
  const tabListBtns = Array.from(document.querySelectorAll('.auth-tabs [role="tab"]'));
  const hasMatchingMainTab = tabListBtns.some((b) => b.getAttribute('data-auth-tab') === tabName);

  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    const isActive = btn.getAttribute('data-auth-tab') === tabName;
    btn.classList.toggle('active', isActive);
    if (btn.getAttribute('role') === 'tab') {
      btn.setAttribute('aria-selected', String(isActive));
      if (hasMatchingMainTab) {
        btn.setAttribute('tabindex', isActive ? '0' : '-1');
      } else {
        btn.setAttribute('tabindex', btn.getAttribute('data-auth-tab') === 'login' ? '0' : '-1');
      }
    }
  });

  document.querySelectorAll('[data-auth-panel]').forEach((panel) => {
    panel.classList.toggle('hidden', panel.getAttribute('data-auth-panel') !== tabName);
  });

  if (focusPanel) {
    const activePanel = document.querySelector(`[data-auth-panel="${tabName}"]`);
    const firstInput = activePanel ? activePanel.querySelector('input, button, select') : null;
    if (firstInput) {
      firstInput.focus();
    } else {
      const titleEl = document.getElementById('auth-card-title');
      if (titleEl) titleEl.focus();
    }
  }
}

function bindLoginForms() {
  document.addEventListener('click', (e) => {
    const tabBtn = e.target.closest('[data-auth-tab]');
    if (tabBtn) {
      const targetTab = tabBtn.getAttribute('data-auth-tab');
      const isMainTab = tabBtn.getAttribute('role') === 'tab';
      switchAuthTab(targetTab, !isMainTab);
    }
  });

  const regPasswordInput = document.getElementById('reg-password');
  if (regPasswordInput) {
    regPasswordInput.addEventListener('input', () => {
      updatePasswordStrengthMeter(regPasswordInput.value);
    });
  }

  const formLogin = document.getElementById('form-auth-login');
  if (formLogin) {
    formLogin.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('login-email').value.trim();
      const password = document.getElementById('login-password').value;
      try {
        const res = await ApiClient.auth.login(email, password);
        if (res && res.token) {
          setAuthToken(res.token);
        }
        setSessionUser(res.usuario);
        hydrateUserPreferencesFromServer(res.usuario);
        redirectAfterLogin();
      } catch (err) {
        showToast(err.message || 'Credenciales incorrectas.', 'error');
      }
    });
  }

  const formRegister = document.getElementById('form-auth-register');
  if (formRegister) {
    formRegister.addEventListener('submit', async (e) => {
      e.preventDefault();
      const nombre = document.getElementById('reg-nombre').value.trim();
      const email = document.getElementById('reg-email').value.trim();
      const ocupacion = document.getElementById('reg-carrera').value;
      const password = document.getElementById('reg-password').value;
      const aceptoEl = document.getElementById('reg-acepto-privacidad');
      if (!aceptoEl || !aceptoEl.checked) {
        showToast(
          'Debes aceptar el Aviso de Privacidad (LFPDPPP) y el Descargo de Responsabilidad Médica para registrarte.',
          'error'
        );
        return;
      }
      try {
        const res = await ApiClient.auth.registro({
          nombre,
          email,
          ocupacion,
          password,
          acepto_privacidad: true
        });
        if (res && res.token) {
          setAuthToken(res.token);
        }
        if (res && res.usuario) {
          setSessionUser(res.usuario);
          hydrateUserPreferencesFromServer(res.usuario);
          redirectAfterLogin();
          return;
        }
        const otpEmailInput = document.getElementById('otp-email');
        if (otpEmailInput) otpEmailInput.value = email;
        switchAuthTab('verificar_email', true);
        showToast(res.mensaje || 'Revisa tu correo e ingresa el código OTP de 6 dígitos.', 'success');
      } catch (err) {
        showToast(err.message || 'No se pudo completar el registro.', 'error');
      }
    });
  }

  const formMagic = document.getElementById('form-auth-magic-link');
  if (formMagic) {
    formMagic.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('magic-email').value.trim();
      try {
        const res = await ApiClient.auth.solicitarMagicLink(email);
        showToast(res.mensaje || 'Enlace mágico enviado a tu correo electrónico.', 'success');
      } catch (err) {
        showToast(err.message || 'Error al solicitar el enlace mágico.', 'error');
      }
    });
  }

  const formVerifyOtp = document.getElementById('form-auth-verify-otp');
  if (formVerifyOtp) {
    formVerifyOtp.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('otp-email').value.trim();
      const codigo = document.getElementById('otp-code').value.trim();
      try {
        const res = await ApiClient.auth.verificarEmail(email, codigo);
        if (res && res.token) {
          setAuthToken(res.token);
        }
        if (res && res.usuario) {
          setSessionUser(res.usuario);
          hydrateUserPreferencesFromServer(res.usuario);
          redirectAfterLogin();
          return;
        }
        switchAuthTab('login', true);
        showToast(res.mensaje || '¡Correo verificado con éxito!', 'success');
      } catch (err) {
        showToast(err.message || 'Código OTP inválido o expirado.', 'error');
      }
    });
  }

  const btnResendOtp = document.getElementById('btn-resend-otp');
  if (btnResendOtp) {
    btnResendOtp.addEventListener('click', async () => {
      const email = document.getElementById('otp-email').value.trim();
      if (!email) {
        showToast('Ingresa tu correo electrónico primero.', 'error');
        return;
      }
      try {
        const res = await ApiClient.auth.reenviarCodigo(email);
        showToast(res.mensaje || 'Nuevo código OTP enviado a tu correo.', 'info');
      } catch (err) {
        showToast(err.message || 'No se pudo reenviar el código.', 'error');
      }
    });
  }

  const formRecoverReq = document.getElementById('form-auth-recover-request');
  if (formRecoverReq) {
    formRecoverReq.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('recover-email').value.trim();
      try {
        const res = await ApiClient.auth.solicitarRecuperacion(email);
        const confirmEmail = document.getElementById('recover-confirm-email');
        if (confirmEmail) confirmEmail.value = email;
        switchAuthTab('recuperar_confirmar', true);
        showToast(res.mensaje || 'Código de recuperación enviado a tu correo.', 'info');
      } catch (err) {
        showToast(err.message || 'Error al solicitar recuperación.', 'error');
      }
    });
  }

  const formRecoverConfirm = document.getElementById('form-auth-recover-confirm');
  if (formRecoverConfirm) {
    formRecoverConfirm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('recover-confirm-email').value.trim();
      const codeEl = document.getElementById('recover-confirm-code');
      const codigo = codeEl ? codeEl.value.trim() : '';
      const nuevaPassword = document.getElementById('recover-confirm-password').value;
      try {
        const res = await ApiClient.auth.confirmarRecuperacion(email, codigo, nuevaPassword, pendingResetToken);
        pendingResetToken = '';
        if (codeEl) {
          codeEl.required = true;
          const group = codeEl.closest('.form-group');
          if (group) group.classList.remove('hidden');
        }
        if (res && res.token) {
          setAuthToken(res.token);
        }
        if (res && res.usuario) {
          setSessionUser(res.usuario);
          hydrateUserPreferencesFromServer(res.usuario);
          redirectAfterLogin();
          return;
        }
        switchAuthTab('login', true);
        showToast(res.mensaje || 'Contraseña restablecida. Ahora puedes iniciar sesión.', 'success');
      } catch (err) {
        showToast(err.message || 'Código o enlace inválido, o contraseña no válida.', 'error');
      }
    });
  }
}

export async function initLoginPage() {
  loadLocalA11yPreferences();
  applyA11yToDOM();
  document.body.classList.remove('auth-checking');
  bindA11yBarEvents();
  bindA11yWidgetEvents();
  bindLoginForms();

  const params = new URLSearchParams(window.location.search);
  const tabParam = params.get('tab');
  const vistaParam = params.get('vista');
  const accionParam = params.get('accion');
  const emailParam = params.get('email') || '';

  if (tabParam && VALID_AUTH_TABS.has(tabParam)) {
    switchAuthTab(tabParam, false);
  } else if (
    (vistaParam === 'verificar-email' || vistaParam === 'verificar_email' || accionParam === 'verificar_email') &&
    !params.get('token')
  ) {
    const otpEmail = document.getElementById('otp-email');
    if (otpEmail && emailParam) otpEmail.value = emailParam;
    switchAuthTab('verificar_email', false);
  } else if (vistaParam === 'restablecer-password') {
    const recEmail = document.getElementById('recover-confirm-email');
    if (recEmail && emailParam) recEmail.value = emailParam;
    const urlResetToken = (params.get('token') || '').trim();
    if (/^[a-f0-9]{64}$/i.test(urlResetToken)) {
      pendingResetToken = urlResetToken;
      const codeEl = document.getElementById('recover-confirm-code');
      if (codeEl) {
        codeEl.required = false;
        const group = codeEl.closest('.form-group');
        if (group) group.classList.add('hidden');
      }
      cleanAuthQueryParamsPreserveNext();
    }
    switchAuthTab('recuperar_confirmar', false);
  }

  const tokenUser = await checkUrlAuthTokens();
  if (tokenUser) {
    redirectAfterLogin();
    return;
  }

  const activeUser = await syncOptionalSession();
  if (activeUser) {
    redirectAfterLogin();
  }
}

if (document.body && document.body.getAttribute('data-initial-view') === 'login') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLoginPage);
  } else {
    initLoginPage();
  }
}
