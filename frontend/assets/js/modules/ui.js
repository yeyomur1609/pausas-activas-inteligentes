/**
 * Utilidades globales de UI, Accesibilidad WCAG 2.2 AA, Audio, CSV y Service Worker (ES Module)
 */
import { STORAGE_KEYS } from './config.js';
import { ApiClient, getSessionUser } from './api-client.js';

export const DEFAULT_A11Y_PREFS = Object.freeze({
  tema_claro: true,
  alto_contraste: false,
  fuente_legible: false,
  subrayar_enlaces: false,
  escala_fuente: 'normal',
  modo_sentado: false,
  reducir_movimiento: false
});

export const a11yState = {
  tema_claro: true,
  alto_contraste: false,
  fuente_legible: false,
  subrayar_enlaces: false,
  escala_fuente: 'normal',
  modo_sentado: false,
  reducir_movimiento: false,
  alertas_sonoras: true,
  voz_guiada: false,
  frecuencia_pausa_min: 45,
  meta_diaria_minutos: 15,
  avatar: '🧘',
  foto_perfil: '',
  ubicacion_defecto: 'computadora',
  tiempo_defecto: 3,
  objetivo_defecto: 'relajarse',
  chatbot_habilitado: true
};

let sharedAudioCtx = null;
let toastTimeoutId = null;
let reminderIntervalId = null;
let lastFocusedBeforeWidget = null;
let deferredPwaPrompt = null;
const a11yListeners = new Set();

export function onA11yChange(callback) {
  if (typeof callback === 'function') {
    a11yListeners.add(callback);
  }
}

export function escapeHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

export function formatSecondsMMSS(totalSec) {
  const safeSec = Math.max(0, Number(totalSec || 0));
  const mins = Math.floor(safeSec / 60);
  const secs = safeSec % 60;
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

export function prefersReducedMotion() {
  return Boolean(
    a11yState.reducir_movimiento ||
      (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
  );
}

export function announceToScreenReader(message, isAssertive = false) {
  const region = document.getElementById('a11y-live-announcer');
  if (!region || !message) return;
  region.setAttribute('aria-live', isAssertive ? 'assertive' : 'polite');
  region.textContent = '';
  window.setTimeout(() => {
    region.textContent = String(message);
  }, 30);
}

export function showToast(message, type = 'info') {
  const toast = document.getElementById('app-toast');
  if (!toast) return;

  toast.textContent = message;
  toast.classList.remove('hidden', 'toast-info', 'toast-success', 'toast-error');

  if (type === 'error') {
    toast.classList.add('toast-error');
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
  } else if (type === 'success') {
    toast.classList.add('toast-success');
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
  } else {
    toast.classList.add('toast-info');
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
  }

  if (toastTimeoutId) clearTimeout(toastTimeoutId);
  toastTimeoutId = window.setTimeout(() => {
    toast.classList.add('hidden');
  }, 4200);
}

export function getFocusableElements(container) {
  if (!container) return [];
  const selector =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
  return Array.from(container.querySelectorAll(selector)).filter(
    (el) => !el.closest('.hidden') && el.offsetParent !== null
  );
}

export function handleHorizontalArrowNav(e, selector, activateOnMove = false) {
  const target = e.target.closest(selector);
  if (!target) return false;
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return false;

  const group = target.closest('[role="tablist"], [role="group"]');
  const scope = group || document;
  const items = Array.from(scope.querySelectorAll(selector)).filter((el) => !el.disabled);
  if (items.length < 2) return false;

  e.preventDefault();
  const idx = items.indexOf(target);
  let nextIdx = idx;
  if (e.key === 'ArrowRight') nextIdx = (idx + 1) % items.length;
  if (e.key === 'ArrowLeft') nextIdx = (idx - 1 + items.length) % items.length;
  if (e.key === 'Home') nextIdx = 0;
  if (e.key === 'End') nextIdx = items.length - 1;

  const nextEl = items[nextIdx];
  if (nextEl) {
    nextEl.focus();
    if (activateOnMove) nextEl.click();
  }
  return true;
}

export function normalizePreferencesFromServer(prefs) {
  const out = { ...(prefs || {}) };
  if (out.escala_fuente === 'muy-grande') {
    out.escala_fuente = 'muy_grande';
  }
  if (out.narrador_voz !== undefined && out.voz_guiada === undefined) {
    out.voz_guiada = Boolean(out.narrador_voz);
  }
  if (out.frecuencia_recordatorio_min !== undefined && out.frecuencia_pausa_min === undefined) {
    out.frecuencia_pausa_min = Number(out.frecuencia_recordatorio_min) || 45;
  }
  delete out.narrador_voz;
  delete out.narrador_velocidad;
  delete out.frecuencia_recordatorio_min;
  return out;
}

export function buildServerPreferencesPayload() {
  return {
    ...a11yState,
    narrador_voz: Boolean(a11yState.voz_guiada),
    frecuencia_recordatorio_min: Number(a11yState.frecuencia_pausa_min || 45)
  };
}

export function persistLocalA11yPreferences() {
  try {
    localStorage.setItem(STORAGE_KEYS.A11Y, JSON.stringify(a11yState));
  } catch (_err) {
    // Modo privado o cuota excedida
  }
}

export function loadLocalA11yPreferences() {
  try {
    const raw = localStorage.getItem(STORAGE_KEYS.A11Y);
    if (raw) {
      const parsed = JSON.parse(raw);
      Object.assign(a11yState, normalizePreferencesFromServer(parsed));
    }
  } catch (_err) {
    // Usar valores por defecto
  }
}

export function saveA11yPreferences() {
  persistLocalA11yPreferences();
  const user = getSessionUser();
  if (user && ApiClient) {
    ApiClient.usuarios.guardarPreferencias(buildServerPreferencesPayload()).catch(() => {});
  }
}

export function hydrateUserPreferencesFromServer(user) {
  if (!user) return;
  const rawPrefs = user.preferencias && typeof user.preferencias === 'object' ? user.preferencias : {};
  Object.assign(a11yState, normalizePreferencesFromServer(rawPrefs));
  if (Number(user.meta_diaria_min) > 0) {
    a11yState.meta_diaria_minutos = Number(user.meta_diaria_min);
  }
  persistLocalA11yPreferences();
  applyA11yToDOM();
  scheduleErgonomicReminderTimer();
}

export function renderBrandAvatarAndPhotoPreview() {
  const sidebarAvatar = document.getElementById('sidebar-brand-avatar');
  const mobileAvatar = document.getElementById('mobile-brand-avatar');
  const photoPreview = document.getElementById('profile-photo-preview');
  const btnClearPhoto = document.getElementById('btn-clear-profile-photo');
  const user = getSessionUser();
  const hasCustomPhoto =
    typeof a11yState.foto_perfil === 'string' && a11yState.foto_perfil.startsWith('data:image/');

  if (photoPreview) {
    if (hasCustomPhoto) {
      photoPreview.src = a11yState.foto_perfil;
      photoPreview.classList.remove('hidden');
      if (btnClearPhoto) btnClearPhoto.classList.remove('hidden');
    } else {
      photoPreview.classList.add('hidden');
      if (btnClearPhoto) btnClearPhoto.classList.add('hidden');
    }
  }

  [sidebarAvatar, mobileAvatar].forEach((container) => {
    if (!container) return;
    container.textContent = '';
    const img = document.createElement('img');
    if (user && hasCustomPhoto) {
      img.src = a11yState.foto_perfil;
      img.alt = user.nombre || 'Avatar';
      img.className = 'avatar-photo-preview';
    } else {
      img.src = 'public/logo.svg';
      img.alt = '';
      img.className = 'brand-logo-img';
    }
    container.appendChild(img);
  });
}

export function applyA11yToDOM() {
  const body = document.body;
  if (!body) return;
  const escala = a11yState.escala_fuente === 'muy-grande' ? 'muy_grande' : a11yState.escala_fuente;

  body.classList.toggle('tema-claro', Boolean(a11yState.tema_claro));
  body.classList.toggle('tema-oscuro', !a11yState.tema_claro);
  body.classList.toggle('a11y-high-contrast', Boolean(a11yState.alto_contraste));
  body.classList.toggle('a11y-readable-font', Boolean(a11yState.fuente_legible));
  body.classList.toggle('a11y-underline-links', Boolean(a11yState.subrayar_enlaces));
  body.classList.toggle('a11y-font-large', escala === 'grande');
  body.classList.toggle('a11y-font-xlarge', escala === 'muy_grande');
  body.classList.toggle('a11y-seated-mode', Boolean(a11yState.modo_sentado));
  body.classList.toggle('a11y-reduce-motion', Boolean(a11yState.reducir_movimiento));

  const themeMeta = document.querySelector('meta[name="theme-color"]');
  if (themeMeta) {
    themeMeta.setAttribute(
      'content',
      a11yState.alto_contraste ? '#000000' : a11yState.tema_claro ? '#2e6f65' : '#0f172a'
    );
  }

  document.querySelectorAll('[data-a11y-font-size]').forEach((btn) => {
    btn.setAttribute('aria-pressed', String(btn.getAttribute('data-a11y-font-size') === (escala || 'normal')));
  });
  document.querySelectorAll('[data-a11y-toggle]').forEach((switchBtn) => {
    const key = switchBtn.getAttribute('data-a11y-toggle');
    const isEnabled = Boolean(key && a11yState[key]);
    switchBtn.setAttribute('aria-checked', String(isEnabled));
    const badge = switchBtn.querySelector('.a11y-switch-badge');
    if (badge) badge.textContent = isEnabled ? 'Sí' : 'No';
  });

  const btnTheme = document.getElementById('a11y-btn-theme');
  const btnContrast = document.getElementById('a11y-btn-contrast');
  const btnFont = document.getElementById('a11y-btn-font');
  const btnSeated = document.getElementById('a11y-btn-seated');
  const btnMotion = document.getElementById('a11y-btn-motion');

  if (btnTheme) {
    btnTheme.setAttribute('aria-pressed', String(Boolean(a11yState.tema_claro)));
    btnTheme.textContent = a11yState.tema_claro ? '☀️ Modo Claro' : '🌙 Modo Oscuro';
  }
  if (btnContrast) btnContrast.setAttribute('aria-pressed', String(Boolean(a11yState.alto_contraste)));
  if (btnFont) {
    btnFont.setAttribute('aria-pressed', String(escala !== 'normal'));
    btnFont.textContent =
      escala === 'muy_grande' ? 'Texto: A++' : escala === 'grande' ? 'Texto: A+' : 'Texto: A';
  }
  if (btnSeated) {
    btnSeated.setAttribute('aria-pressed', String(Boolean(a11yState.modo_sentado)));
    btnSeated.textContent = a11yState.modo_sentado ? 'Modo Sentado: Sí' : 'Modo Sentado';
  }
  if (btnMotion) btnMotion.setAttribute('aria-pressed', String(Boolean(a11yState.reducir_movimiento)));

  const fabActiBot = document.getElementById('btn-fab-actibot');
  if (fabActiBot) {
    fabActiBot.classList.toggle('hidden', !a11yState.chatbot_habilitado);
  }
  if (!a11yState.chatbot_habilitado) {
    const drawer = document.getElementById('floating-chat-drawer');
    if (drawer) drawer.classList.add('hidden');
  }

  renderBrandAvatarAndPhotoPreview();
  a11yListeners.forEach((cb) => {
    try {
      cb(a11yState);
    } catch (_err) {}
  });
}

export function bindA11yBarEvents() {
  const btnTheme = document.getElementById('a11y-btn-theme');
  const btnContrast = document.getElementById('a11y-btn-contrast');
  const btnFont = document.getElementById('a11y-btn-font');
  const btnSeated = document.getElementById('a11y-btn-seated');
  const btnMotion = document.getElementById('a11y-btn-motion');

  if (btnTheme) {
    btnTheme.addEventListener('click', () => {
      a11yState.tema_claro = !a11yState.tema_claro;
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(a11yState.tema_claro ? '☀️ Modo Claro activado' : '🌙 Modo Oscuro activado', 'info');
    });
  }

  if (btnContrast) {
    btnContrast.addEventListener('click', () => {
      a11yState.alto_contraste = !a11yState.alto_contraste;
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(
        a11yState.alto_contraste ? 'Modo Alto Contraste activado' : 'Modo Alto Contraste desactivado',
        'info'
      );
    });
  }

  if (btnFont) {
    btnFont.addEventListener('click', () => {
      const order = ['normal', 'grande', 'muy_grande'];
      const current = a11yState.escala_fuente === 'muy-grande' ? 'muy_grande' : a11yState.escala_fuente;
      a11yState.escala_fuente = order[(order.indexOf(current) + 1) % order.length];
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(`Escala de texto: ${a11yState.escala_fuente.replace('_', ' ')}`, 'info');
    });
  }

  if (btnSeated) {
    btnSeated.addEventListener('click', () => {
      a11yState.modo_sentado = !a11yState.modo_sentado;
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(
        a11yState.modo_sentado
          ? 'Modo Sentado / Silla de Ruedas activado para todas las rutinas'
          : 'Instrucciones estándar de pie activadas',
        'success'
      );
    });
  }

  if (btnMotion) {
    btnMotion.addEventListener('click', () => {
      a11yState.reducir_movimiento = !a11yState.reducir_movimiento;
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(
        a11yState.reducir_movimiento ? 'Animaciones reducidas' : 'Animaciones estándar activadas',
        'info'
      );
    });
  }
}

export function toggleA11yWidgetPanel(show, restoreFocus = false) {
  const panel = document.getElementById('a11y-widget-panel');
  const toggleBtn = document.getElementById('btn-a11y-widget-toggle');
  if (!panel || !toggleBtn) return;

  const willOpen = typeof show === 'boolean' ? show : panel.classList.contains('hidden');
  if (willOpen) {
    lastFocusedBeforeWidget = document.activeElement;
    const chatDrawer = document.getElementById('floating-chat-drawer');
    if (chatDrawer) chatDrawer.classList.add('hidden');
    panel.classList.remove('hidden');
    toggleBtn.setAttribute('aria-expanded', 'true');
    const firstFontBtn = panel.querySelector('[data-a11y-font-size]');
    const focusables = getFocusableElements(panel);
    if (firstFontBtn) {
      firstFontBtn.focus();
    } else if (focusables.length > 0) {
      focusables[0].focus();
    }
    announceToScreenReader('Panel de accesibilidad universal abierto.');
  } else {
    panel.classList.add('hidden');
    toggleBtn.setAttribute('aria-expanded', 'false');
    if (restoreFocus) {
      const focusTarget =
        lastFocusedBeforeWidget && document.contains(lastFocusedBeforeWidget)
          ? lastFocusedBeforeWidget
          : toggleBtn;
      focusTarget.focus();
    }
  }
}

export function bindA11yWidgetEvents() {
  const root = document.getElementById('a11y-widget-root');
  const toggleBtn = document.getElementById('btn-a11y-widget-toggle');
  const closeBtn = document.getElementById('btn-close-a11y-widget');
  const resetBtn = document.getElementById('btn-a11y-widget-reset');
  const footerOpenBtn = document.getElementById('btn-footer-open-a11y');

  if (toggleBtn) toggleBtn.addEventListener('click', () => toggleA11yWidgetPanel(undefined, true));
  if (footerOpenBtn) footerOpenBtn.addEventListener('click', () => toggleA11yWidgetPanel(true, true));
  if (closeBtn) closeBtn.addEventListener('click', () => toggleA11yWidgetPanel(false, true));

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      Object.assign(a11yState, DEFAULT_A11Y_PREFS);
      applyA11yToDOM();
      saveA11yPreferences();
      showToast('Preferencias de accesibilidad restablecidas por defecto.', 'info');
    });
  }

  document.addEventListener('click', (e) => {
    const fontBtn = e.target.closest('[data-a11y-font-size]');
    if (fontBtn) {
      a11yState.escala_fuente = fontBtn.getAttribute('data-a11y-font-size') || 'normal';
      applyA11yToDOM();
      saveA11yPreferences();
      showToast(`Tamaño de fuente: ${fontBtn.textContent.trim()}`, 'info');
      return;
    }

    const switchBtn = e.target.closest('[data-a11y-toggle]');
    if (switchBtn) {
      const key = switchBtn.getAttribute('data-a11y-toggle');
      if (key && Object.prototype.hasOwnProperty.call(a11yState, key)) {
        a11yState[key] = !a11yState[key];
        applyA11yToDOM();
        saveA11yPreferences();
        const titleSpan = switchBtn.querySelector('span:first-child');
        const label = titleSpan ? titleSpan.textContent.trim() : key;
        showToast(`${label}: ${a11yState[key] ? 'activado' : 'desactivado'}`, 'info');
      }
      return;
    }

    const panel = document.getElementById('a11y-widget-panel');
    if (
      root &&
      panel &&
      !panel.classList.contains('hidden') &&
      !root.contains(e.target) &&
      e.target !== footerOpenBtn
    ) {
      toggleA11yWidgetPanel(false, false);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const a11yPanel = document.getElementById('a11y-widget-panel');
      if (a11yPanel && !a11yPanel.classList.contains('hidden')) {
        e.preventDefault();
        toggleA11yWidgetPanel(false, true);
        return;
      }
      const chatDrawer = document.getElementById('floating-chat-drawer');
      if (chatDrawer && !chatDrawer.classList.contains('hidden')) {
        e.preventDefault();
        chatDrawer.classList.add('hidden');
        const fabBtn = document.getElementById('btn-fab-actibot');
        if (fabBtn) {
          fabBtn.setAttribute('aria-expanded', 'false');
          if (!fabBtn.classList.contains('hidden')) fabBtn.focus();
        }
        return;
      }
    }

    if (handleHorizontalArrowNav(e, '.auth-tabs [role="tab"]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-a11y-font-size]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-park-filter]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-gen-category]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-gen-duration]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-gen-need]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-avatar-choice]', true)) return;
    if (handleHorizontalArrowNav(e, '[data-hero-mode]', false)) return;
    if (handleHorizontalArrowNav(e, '[data-bento-cadence]', false)) return;
    if (handleHorizontalArrowNav(e, '[data-bento-posture]', false)) return;
    if (handleHorizontalArrowNav(e, '[data-preview60-topic]', false)) return;
  });
}

export function playCompletionChime(enabledOverride = null) {
  const shouldPlay = typeof enabledOverride === 'boolean' ? enabledOverride : a11yState.alertas_sonoras;
  if (!shouldPlay) return;
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;

    if (!sharedAudioCtx) {
      sharedAudioCtx = new AudioCtx();
    }
    if (sharedAudioCtx.state === 'suspended') {
      sharedAudioCtx.resume().catch(() => {});
    }

    const chordFrequencies = [523.25, 659.25, 783.99];
    const now = sharedAudioCtx.currentTime;

    chordFrequencies.forEach((freq, idx) => {
      const osc = sharedAudioCtx.createOscillator();
      const gain = sharedAudioCtx.createGain();
      const startOffset = idx * 0.09;

      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, now + startOffset);

      gain.gain.setValueAtTime(0.001, now + startOffset);
      gain.gain.exponentialRampToValueAtTime(0.14, now + startOffset + 0.04);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + startOffset + 0.65);

      osc.connect(gain);
      gain.connect(sharedAudioCtx.destination);

      osc.start(now + startOffset);
      osc.stop(now + startOffset + 0.68);
    });
  } catch (_err) {
    // Ignorar si el navegador bloquea autoplay de audio
  }
}

export function getSelectedParkFromStorage() {
  try {
    const raw = localStorage.getItem(STORAGE_KEYS.SELECTED_PARK);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && parsed.nombre ? parsed : null;
  } catch (_err) {
    return null;
  }
}

export function saveSelectedParkToStorage(park) {
  try {
    if (!park) {
      localStorage.removeItem(STORAGE_KEYS.SELECTED_PARK);
    } else {
      localStorage.setItem(STORAGE_KEYS.SELECTED_PARK, JSON.stringify(park));
    }
  } catch (_err) {}
}

export function exportHistoryRowsToCSV(rows) {
  if (!Array.isArray(rows) || rows.length === 0) {
    showToast('Completa al menos una pausa activa para exportar tu historial CSV.', 'info');
    return;
  }

  const headers = ['Fecha', 'Rutina', 'Minutos', 'Modo Accesible', 'Esfuerzo (1-5)', 'Utilidad (1-5)', 'Ubicacion'];
  const csvLines = [
    headers.join(','),
    ...rows.map((r) =>
      [
        `"${String(r.fecha || r.completada_en || '').replace(/"/g, '""')}"`,
        `"${String(r.titulo || r.rutina_titulo || 'Pausa Activa').replace(/"/g, '""')}"`,
        Number(r.minutos || 3),
        `"${String(r.modo || (r.completada_sentado ? 'Sentado / Silla' : 'De pie')).replace(/"/g, '""')}"`,
        Number(r.esfuerzo || 3),
        Number(r.calificacion || r.utilidad || 5),
        `"${String(r.parque || r.ubicacion || 'Santa Catarina, N.L.').replace(/"/g, '""')}"`
      ].join(',')
    )
  ];

  const blob = new Blob(['\uFEFF' + csvLines.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `historial-pausas-activas-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);

  showToast('📥 Historial exportado en formato CSV.', 'success');
}

export function scheduleErgonomicReminderTimer(isBusyFn = null) {
  if (reminderIntervalId) {
    clearInterval(reminderIntervalId);
  }
  const freqMinutes = Math.max(15, Number(a11yState.frecuencia_pausa_min || 45));
  reminderIntervalId = window.setInterval(() => {
    if (typeof isBusyFn === 'function' && isBusyFn()) return;

    showToast(`⏰ ¡Hora de tu pausa ergonómica! Llevas ${freqMinutes} minutos concentrado.`, 'info');
    if ('Notification' in window && Notification.permission === 'granted') {
      try {
        new Notification('Pausas Activas Inteligentes', {
          body: 'Descansa la vista 20 segundos y relaja los hombros con una pausa express.',
          icon: 'public/logo.svg'
        });
      } catch (_err) {}
    }
  }, freqMinutes * 60 * 1000);
}

export function bindCookieBannerEvents() {
  const banner = document.getElementById('cookie-consent-banner');
  const btnAccept = document.getElementById('btn-accept-cookies');
  if (!banner || !btnAccept) return;

  try {
    if (!localStorage.getItem(STORAGE_KEYS.COOKIES_ACCEPTED)) {
      banner.classList.remove('hidden');
    }
  } catch (_err) {}

  btnAccept.addEventListener('click', () => {
    try {
      localStorage.setItem(STORAGE_KEYS.COOKIES_ACCEPTED, 'true');
    } catch (_err) {}
    banner.classList.add('hidden');
  });
}

export function setupSmartPwaInstallBanner() {
  const pwaBanner = document.getElementById('pwa-install-banner');
  const btnCloseBanner = document.getElementById('btn-close-pwa-banner');
  const btnDismissBanner = document.getElementById('btn-dismiss-pwa-banner');
  const btnTriggerBanner = document.getElementById('btn-trigger-pwa-banner');
  const iosStepsBox = document.getElementById('pwa-ios-steps');
  const btnSidebarInstall = document.getElementById('btn-install-pwa');

  const isStandalone =
    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
    window.navigator.standalone === true;

  let isDismissed = false;
  try {
    isDismissed = localStorage.getItem(STORAGE_KEYS.PWA_DISMISSED) === '1';
  } catch (_err) {}

  const ua = window.navigator.userAgent || '';
  const isIosDevice = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;

  if (!isStandalone && !isDismissed && isIosDevice && pwaBanner) {
    pwaBanner.classList.remove('hidden');
    if (btnTriggerBanner) btnTriggerBanner.textContent = '📲 Ver pasos para iPhone / iPad';
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPwaPrompt = e;
    if (btnSidebarInstall) btnSidebarInstall.classList.remove('hidden');
    if (!isStandalone && !isDismissed && pwaBanner) {
      pwaBanner.classList.remove('hidden');
      if (btnTriggerBanner) btnTriggerBanner.textContent = '📲 Instalar Web App Ahora';
    }
  });

  const dismissHandler = () => {
    if (pwaBanner) pwaBanner.classList.add('hidden');
    try {
      localStorage.setItem(STORAGE_KEYS.PWA_DISMISSED, '1');
    } catch (_err) {}
  };

  if (btnCloseBanner) btnCloseBanner.addEventListener('click', dismissHandler);
  if (btnDismissBanner) btnDismissBanner.addEventListener('click', dismissHandler);

  if (btnTriggerBanner) {
    btnTriggerBanner.addEventListener('click', async () => {
      if (deferredPwaPrompt) {
        deferredPwaPrompt.prompt();
        await deferredPwaPrompt.userChoice;
        deferredPwaPrompt = null;
        dismissHandler();
      } else if (iosStepsBox) {
        iosStepsBox.classList.toggle('hidden');
      }
    });
  }

  if (btnSidebarInstall) {
    btnSidebarInstall.addEventListener('click', async () => {
      if (!deferredPwaPrompt) return;
      deferredPwaPrompt.prompt();
      await deferredPwaPrompt.userChoice;
      deferredPwaPrompt = null;
      btnSidebarInstall.classList.add('hidden');
    });
  }
}

export function registerServiceWorker() {
  if ('serviceWorker' in navigator) {
    const swUrl = window.location.pathname.toLowerCase().includes('/frontend') ? 'sw.js' : '/sw.js';
    navigator.serviceWorker
      .register(swUrl, { updateViaCache: 'none' })
      .then((reg) => reg.update().catch(() => {}))
      .catch(() => {});
  }
}

export function normalizeErrorPageLinks() {
  const pathname = (window.location && window.location.pathname) || '/';
  const lowerPath = pathname.toLowerCase();
  const frontIdx = lowerPath.indexOf('/frontend');
  const rootBase = frontIdx !== -1 ? `${pathname.slice(0, frontIdx + '/frontend'.length)}/` : '/';
  document.querySelectorAll('a[href]').forEach((anchor) => {
    const rawHref = anchor.getAttribute('href') || '';
    if (!rawHref || /^(?:https?:|mailto:|tel:|\/|#)/i.test(rawHref)) return;
    const target = rawHref === './' ? '' : rawHref.replace(/^\.\//, '');
    anchor.setAttribute('href', `${rootBase}${target}`);
  });
}
