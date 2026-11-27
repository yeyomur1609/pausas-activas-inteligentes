/**
 * Módulo de Layout Compartido MPA (ES Module) — Pausas Activas Inteligentes
 * Inyecta navbar con enlaces reales <a>, menú móvil, banner de verificación,
 * footer, widget WCAG, cajón flotante ActiBot y toast.
 */
import { ApiClient, getSessionUser } from './api-client.js';
import {
  a11yState,
  applyA11yToDOM,
  bindA11yWidgetEvents,
  bindCookieBannerEvents,
  getSelectedParkFromStorage,
  loadLocalA11yPreferences,
  normalizeErrorPageLinks,
  prefersReducedMotion,
  registerServiceWorker,
  renderBrandAvatarAndPhotoPreview,
  scheduleErgonomicReminderTimer,
  setupSmartPwaInstallBanner,
  showToast,
  toggleA11yWidgetPanel
} from './ui.js';
import {
  buildLoginUrl,
  checkUrlAuthTokens,
  handleLogout,
  requireAuthOrRedirect,
  syncOptionalSession
} from './auth.js';

const floatingChatHistory = [];

export function detectActiveView() {
  const bodyView = document.body ? document.body.getAttribute('data-initial-view') : '';
  if (bodyView) return bodyView;
  const seg = ((window.location.pathname || '').split('/').pop() || 'index.html')
    .toLowerCase()
    .replace(/\.html$/, '');
  if (!seg || seg === 'index') return 'inicio';
  return seg;
}

function buildNavLink(href, label, viewKey, activeView, extraHtml = '') {
  const isActive = viewKey === activeView;
  const activeClass = isActive ? ' active' : '';
  const ariaCurrent = isActive ? ' aria-current="page"' : '';
  return `<a href="${href}" class="nav-item-btn${activeClass}" data-nav-view="${viewKey}"${ariaCurrent}><span>${label}</span>${extraHtml}</a>`;
}

function buildBottomNavLink(href, label, viewKey, activeView, extraHtml = '') {
  const isActive = viewKey === activeView;
  const activeClass = isActive ? ' active' : '';
  const ariaCurrent = isActive ? ' aria-current="page"' : '';
  return `<a href="${href}" class="bottom-nav-btn${activeClass}" data-nav-view="${viewKey}"${ariaCurrent}><span>${label}${extraHtml}</span></a>`;
}

export function renderAppLayout({ activeView = detectActiveView(), includeFloatingChat = true } = {}) {
  const mainEl = document.getElementById('main-content');
  if (!mainEl || document.querySelector('.saas-navbar')) return;

  // 1. Enlace de salto, banner PWA y topbar móvil antes del app-shell
  const preShellHtml = `
    <a href="#main-content" class="skip-link">Saltar al contenido principal</a>

    <aside id="pwa-install-banner" class="pwa-install-banner hidden" role="region" aria-label="Recomendación para instalar la Web App">
      <div class="module-card-header">
        <div>
          <span class="badge badge-info">Aplicación Web Progresiva (PWA)</span>
          <h2 id="pwa-banner-title" class="module-card-title">Instala Pausas Activas Inteligentes</h2>
          <p id="pwa-banner-desc" class="module-card-desc">Úsala a pantalla completa en tu celular o computadora para acceder rápido a tus rutinas y parques.</p>
        </div>
        <button type="button" id="btn-close-pwa-banner" class="btn btn-secondary btn-sm" aria-label="Cerrar recomendación">✕</button>
      </div>
      <div id="pwa-ios-steps" class="card mt-3 hidden">
        <p class="module-card-desc">
          📲 <strong>En iPhone / iPad (Safari):</strong> 1) Pulsa el botón <strong>Compartir ⬆️</strong> en la barra de Safari.
          2) Elige <strong>"Agregar a inicio"</strong>. 3) Pulsa <strong>"Agregar"</strong>.
        </p>
      </div>
      <div class="hero-cta-group mt-3">
        <button type="button" id="btn-trigger-pwa-banner" class="btn btn-primary btn-sm">📲 Instalar / Ver pasos</button>
        <button type="button" id="btn-dismiss-pwa-banner" class="btn btn-secondary btn-sm">Ahora no</button>
      </div>
    </aside>

    <header class="mobile-topbar">
      <a href="index.html" class="brand-box brand-link" aria-label="Volver al inicio de Pausas Activas Inteligentes">
        <div id="mobile-brand-avatar" class="brand-logo" aria-hidden="true"><img src="public/logo.svg" alt="" class="brand-logo-img"></div>
        <div>
          <p class="brand-title">Pausas Activas</p>
          <p class="brand-subtitle">Ergonomía con IA</p>
        </div>
      </a>
      <div class="mobile-topbar-actions">
        <a href="login.html" id="btn-open-auth-mobile" class="btn btn-primary btn-sm">Iniciar Sesión</a>
      </div>
    </header>
  `;

  // 2. App Shell envolviendo <main id="main-content">
  const shell = document.createElement('div');
  shell.className = 'app-shell';

  const header = document.createElement('header');
  header.className = 'saas-navbar';
  header.setAttribute('aria-label', 'Navegación principal de la plataforma');
  header.innerHTML = `
    <div class="saas-navbar-inner">
      <a href="index.html" class="brand-box brand-link" aria-label="Volver al inicio de Pausas Activas Inteligentes">
        <div id="sidebar-brand-avatar" class="brand-logo" aria-hidden="true"><img src="public/logo.svg" alt="" class="brand-logo-img"></div>
        <div>
          <p class="brand-title">Pausas Activas Inteligentes</p>
          <p class="brand-subtitle">Ergonomía Laboral con IA</p>
        </div>
      </a>

      <nav class="sidebar-nav" aria-label="Secciones de la plataforma">
        ${buildNavLink('index.html', 'Inicio', 'inicio', activeView)}
        ${buildNavLink('rutinas.html', 'Rutinas', 'rutinas', activeView)}
        ${buildNavLink('parques.html', 'Parques', 'parques', activeView)}
        ${buildNavLink('chatbot.html', 'ActiBot IA', 'chatbot', activeView)}
        ${buildNavLink('ajustes.html', 'Ajustes', 'ajustes', activeView, '<span id="nav-ajustes-dot" class="nav-amber-dot hidden" title="Verificación de correo pendiente"></span>')}
        ${buildNavLink('legal.html', 'Legal', 'legal', activeView)}
      </nav>

      <div class="sidebar-footer">
        <span id="sidebar-user-status" class="nav-user-pill hidden">Vista Invitado</span>
        <a href="login.html" id="btn-open-auth-sidebar" class="btn btn-secondary btn-sm" data-open-auth="login">Iniciar Sesión</a>
        <a href="login.html?tab=registro" id="btn-start-free-navbar" class="btn btn-primary btn-sm" data-open-auth="registro">Crear Cuenta</a>
        <button type="button" id="btn-logout-sidebar" class="btn btn-secondary btn-sm hidden">Cerrar Sesión</button>
        <button type="button" id="btn-install-pwa" class="btn btn-accent-outline btn-sm hidden">Instalar App</button>
      </div>
    </div>
  `;

  const contentColumn = document.createElement('div');
  contentColumn.className = 'app-content-column';

  const unverifiedBanner = document.createElement('aside');
  unverifiedBanner.id = 'unverified-account-banner';
  unverifiedBanner.className = 'unverified-top-banner hidden';
  unverifiedBanner.setAttribute('role', 'status');
  unverifiedBanner.setAttribute('aria-live', 'polite');
  unverifiedBanner.innerHTML = `
    <p id="unverified-banner-text" class="module-card-desc">
      ⚠️ Tu correo aún no está confirmado. Tienes 7 días antes de que la cuenta se elimine automáticamente.
    </p>
    <a href="ajustes.html" class="btn btn-secondary btn-sm">Confirmar o reenviar en Ajustes →</a>
  `;

  const footer = document.createElement('footer');
  footer.className = 'app-footer';
  footer.setAttribute('aria-label', 'Información institucional y accesibilidad');
  footer.innerHTML = `
    <p>Pausas Activas Inteligentes · Estándar WCAG 2.2 AA · Santa Catarina, Nuevo León</p>
    <div class="app-footer-links">
      <a href="legal.html">Aviso de Privacidad y Descargo Médico</a>
      <button type="button" id="btn-footer-open-a11y" class="btn btn-secondary btn-sm">Panel de Accesibilidad</button>
    </div>
  `;

  const parent = mainEl.parentNode;
  parent.insertBefore(shell, mainEl);
  shell.insertAdjacentHTML('beforebegin', preShellHtml);
  mainEl.insertBefore(unverifiedBanner, mainEl.firstChild);
  contentColumn.appendChild(mainEl);
  contentColumn.appendChild(footer);
  shell.appendChild(header);
  shell.appendChild(contentColumn);

  // 3. Navegación inferior móvil, Cajón flotante ActiBot (opcional), Widget WCAG, Cookies y Toast
  const floatingChatHtml = includeFloatingChat
    ? `
      <aside id="floating-chat-drawer" class="floating-chat-drawer hidden" aria-label="Asistente flotante ActiBot">
        <header class="chat-header">
          <div>
            <strong class="module-card-title">🤖 ActiBot • Ayuda Rápida</strong>
            <p class="stat-label">Ergonomía y Parques de Santa Catarina</p>
          </div>
          <div class="mobile-topbar-actions">
            <a href="chatbot.html" class="btn btn-secondary btn-sm">Expandir</a>
            <button type="button" id="btn-close-floating-chat" class="btn btn-secondary btn-sm" aria-label="Cerrar asistente flotante">✕</button>
          </div>
        </header>
        <div id="floating-chat-messages" class="floating-chat-messages" aria-live="polite">
          <div class="chat-bubble chat-bubble-assistant">
            <p>¡Hola! ¿En qué te ayudo hoy? Elige una consulta rápida o escríbeme:</p>
            <div class="chat-action-links">
              <a href="rutinas.html" class="btn btn-primary btn-sm">Ir al Generador de Rutinas 🚀</a>
              <a href="parques.html" class="btn btn-secondary btn-sm">Ver Mapa de Parques 🌳</a>
              <a href="ajustes.html" class="btn btn-accent-outline btn-sm">Ir a Ajustes ⚙️</a>
            </div>
          </div>
        </div>
        <div class="chat-quick-chips">
          <button type="button" class="chat-chip-btn" data-floating-prompt="Tengo tensión cervical y dolor de cuello, ¿qué ejercicios hago?">Tensión cervical</button>
          <button type="button" class="chat-chip-btn" data-floating-prompt="Dame la regla 20-20-20 para fatiga visual.">Vista cansada</button>
          <button type="button" class="chat-chip-btn" data-floating-prompt="¿Qué ejercicios puedo hacer sentado en silla de ruedas?">Silla de ruedas</button>
        </div>
        <form id="form-floating-chatbot" class="chat-input-bar">
          <div class="search-field-stack">
            <label for="floating-chat-input" class="form-label form-label-compact">Pregunta rápida a ActiBot</label>
            <input type="text" id="floating-chat-input" class="form-input" placeholder="Escribe tu pregunta rápida..." maxlength="400" required>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Enviar</button>
        </form>
      </aside>
      <button type="button" id="btn-fab-actibot" class="fab-actibot-btn" aria-label="Abrir asistente flotante ActiBot" aria-expanded="false" aria-controls="floating-chat-drawer">
        🤖 ActiBot IA
      </button>
    `
    : '';

  const postShellHtml = `
    <nav class="mobile-bottom-nav" aria-label="Navegación inferior móvil">
      ${buildBottomNavLink('index.html', 'Inicio', 'inicio', activeView)}
      ${buildBottomNavLink('parques.html', 'Parques', 'parques', activeView)}
      ${buildBottomNavLink('rutinas.html', 'Rutinas', 'rutinas', activeView)}
      ${buildBottomNavLink('chatbot.html', 'ActiBot', 'chatbot', activeView)}
      ${buildBottomNavLink('ajustes.html', 'Ajustes', 'ajustes', activeView, ' <span id="nav-ajustes-dot-mobile" class="nav-amber-dot hidden"></span>')}
    </nav>

    ${floatingChatHtml}

    <aside id="a11y-widget-root" class="a11y-floating-widget" aria-label="Widget de Accesibilidad Universal">
      <div id="a11y-widget-panel" class="a11y-widget-panel hidden" role="dialog" aria-modal="false" aria-labelledby="a11y-widget-title">
        <header class="a11y-widget-header">
          <h2 id="a11y-widget-title" class="a11y-widget-title">Accesibilidad Universal</h2>
          <button type="button" id="btn-close-a11y-widget" class="btn btn-secondary btn-sm" aria-label="Cerrar panel de accesibilidad">✕</button>
        </header>
        <div class="a11y-widget-group">
          <span id="a11y-widget-font-label" class="a11y-widget-group-label">Tamaño de fuente</span>
          <div class="a11y-font-step-group" role="group" aria-labelledby="a11y-widget-font-label">
            <button type="button" class="a11y-font-step-btn" data-a11y-font-size="normal" aria-pressed="true">A 100%</button>
            <button type="button" class="a11y-font-step-btn" data-a11y-font-size="grande" aria-pressed="false">A+ 115%</button>
            <button type="button" class="a11y-font-step-btn" data-a11y-font-size="muy_grande" aria-pressed="false">A++ 130%</button>
          </div>
        </div>
        <div class="a11y-switch-list" role="group" aria-label="Opciones visuales y de movimiento">
          <button type="button" id="widget-switch-contrast" class="a11y-switch-btn" role="switch" aria-checked="false" data-a11y-toggle="alto_contraste">
            <span>Modo de alto contraste</span><span class="a11y-switch-badge" aria-hidden="true">No</span>
          </button>
          <button type="button" id="widget-switch-readable" class="a11y-switch-btn" role="switch" aria-checked="false" data-a11y-toggle="fuente_legible">
            <span>Tipografía de alta legibilidad</span><span class="a11y-switch-badge" aria-hidden="true">No</span>
          </button>
          <button type="button" id="widget-switch-underline" class="a11y-switch-btn" role="switch" aria-checked="false" data-a11y-toggle="subrayar_enlaces">
            <span>Subrayar enlaces</span><span class="a11y-switch-badge" aria-hidden="true">No</span>
          </button>
          <button type="button" id="widget-switch-motion" class="a11y-switch-btn" role="switch" aria-checked="false" data-a11y-toggle="reducir_movimiento">
            <span>Pausar animaciones</span><span class="a11y-switch-badge" aria-hidden="true">No</span>
          </button>
          <button type="button" id="widget-switch-theme" class="a11y-switch-btn" role="switch" aria-checked="true" data-a11y-toggle="tema_claro">
            <span>Tema claro (#F8FAFC)</span><span class="a11y-switch-badge" aria-hidden="true">Sí</span>
          </button>
          <button type="button" id="widget-switch-seated" class="a11y-switch-btn" role="switch" aria-checked="false" data-a11y-toggle="modo_sentado">
            <span>Modo sentado / silla</span><span class="a11y-switch-badge" aria-hidden="true">No</span>
          </button>
        </div>
        <button type="button" id="btn-a11y-widget-reset" class="btn btn-secondary btn-sm btn-block">Restablecer preferencias</button>
      </div>
      <button type="button" id="btn-a11y-widget-toggle" class="a11y-fab-btn" aria-expanded="false" aria-controls="a11y-widget-panel" aria-label="Abrir panel de accesibilidad universal">
        <span aria-hidden="true">♿</span><span>Accesibilidad</span>
      </button>
    </aside>

    <aside id="cookie-consent-banner" class="cookie-banner hidden" aria-label="Aviso de privacidad y cookies técnicas">
      <p class="module-card-desc">
        Usamos cookies técnicas de sesión y almacenamiento local para guardar tus preferencias de accesibilidad
        (alto contraste, tamaño de letra y modo sentado). Consulta nuestro
        <a href="legal.html">Aviso de Privacidad</a>.
      </p>
      <button type="button" id="btn-accept-cookies" class="btn btn-primary btn-sm">Entendido y Aceptar</button>
    </aside>

    <div id="a11y-live-announcer" class="sr-only" aria-live="polite" aria-atomic="true"></div>
    <div id="app-toast" class="toast-notification toast-info hidden" role="status" aria-live="polite" aria-atomic="true"></div>
  `;

  shell.insertAdjacentHTML('afterend', postShellHtml);
}

export function syncLayoutUserUI(user = getSessionUser()) {
  const statusLabel = document.getElementById('sidebar-user-status');
  const btnOpenAuthSidebar = document.getElementById('btn-open-auth-sidebar');
  const btnStartFreeNavbar = document.getElementById('btn-start-free-navbar');
  const btnLogoutSidebar = document.getElementById('btn-logout-sidebar');
  const btnOpenAuthMobile = document.getElementById('btn-open-auth-mobile');
  const unverifiedBanner = document.getElementById('unverified-account-banner');
  const unverifiedBannerText = document.getElementById('unverified-banner-text');
  const navAjustesDot = document.getElementById('nav-ajustes-dot');
  const navAjustesDotMobile = document.getElementById('nav-ajustes-dot-mobile');

  renderBrandAvatarAndPhotoPreview();

  if (user) {
    const avatarIcon = a11yState.avatar || '🧘';
    if (statusLabel) {
      statusLabel.textContent = `${avatarIcon} ${user.nombre}`;
      statusLabel.classList.remove('hidden');
    }
    if (btnOpenAuthSidebar) btnOpenAuthSidebar.classList.add('hidden');
    if (btnStartFreeNavbar) btnStartFreeNavbar.classList.add('hidden');
    if (btnLogoutSidebar) btnLogoutSidebar.classList.remove('hidden');
    if (btnOpenAuthMobile) {
      btnOpenAuthMobile.textContent = 'Mi Perfil';
      btnOpenAuthMobile.setAttribute('href', 'ajustes.html');
    }

    const isVerified = Boolean(user.email_verificado);
    if (isVerified) {
      if (unverifiedBanner) unverifiedBanner.classList.add('hidden');
      if (navAjustesDot) navAjustesDot.classList.add('hidden');
      if (navAjustesDotMobile) navAjustesDotMobile.classList.add('hidden');
    } else {
      const dias = user.dias_restantes_verificacion ?? 7;
      const horas = user.horas_restantes_verificacion ?? 168;
      if (unverifiedBanner) unverifiedBanner.classList.remove('hidden');
      if (unverifiedBannerText) {
        unverifiedBannerText.textContent = `⚠️ Tu correo (${user.email}) aún no está confirmado. Te quedan ${dias} día(s) (${horas} h) antes de que la cuenta no verificada se elimine automáticamente.`;
      }
      if (navAjustesDot) navAjustesDot.classList.remove('hidden');
      if (navAjustesDotMobile) navAjustesDotMobile.classList.remove('hidden');
    }
  } else {
    if (statusLabel) {
      statusLabel.textContent = 'Vista Invitado';
      statusLabel.classList.add('hidden');
    }
    if (btnOpenAuthSidebar) btnOpenAuthSidebar.classList.remove('hidden');
    if (btnStartFreeNavbar) btnStartFreeNavbar.classList.remove('hidden');
    if (btnLogoutSidebar) btnLogoutSidebar.classList.add('hidden');
    if (btnOpenAuthMobile) {
      btnOpenAuthMobile.textContent = 'Iniciar Sesión';
      btnOpenAuthMobile.setAttribute('href', 'login.html');
    }
    if (unverifiedBanner) unverifiedBanner.classList.add('hidden');
    if (navAjustesDot) navAjustesDot.classList.add('hidden');
    if (navAjustesDotMobile) navAjustesDotMobile.classList.add('hidden');
  }
}

export function toggleFloatingChatDrawer(show, restoreFocus = false) {
  const drawer = document.getElementById('floating-chat-drawer');
  const fabBtn = document.getElementById('btn-fab-actibot');
  if (!drawer) return;

  if (show) toggleA11yWidgetPanel(false, false);
  drawer.classList.toggle('hidden', !show);
  if (fabBtn) fabBtn.setAttribute('aria-expanded', String(Boolean(show)));
  if (show) {
    const input = document.getElementById('floating-chat-input');
    if (input) input.focus();
  } else if (restoreFocus && fabBtn && !fabBtn.classList.contains('hidden')) {
    fabBtn.focus();
  }
}

export function appendChatBubbleToContainer(container, text, role, includeActionLinks = false) {
  if (!container) return;
  const roleClass =
    role === 'user'
      ? 'chat-bubble-user'
      : role === 'error'
      ? 'chat-bubble-error'
      : 'chat-bubble-assistant';

  const bubble = document.createElement('div');
  bubble.className = `chat-bubble ${roleClass}`;
  const p = document.createElement('p');
  p.textContent = text;
  bubble.appendChild(p);

  if (role === 'assistant' && includeActionLinks) {
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'chat-action-links';
    actionsDiv.innerHTML = `
      <a href="rutinas.html" class="btn btn-primary btn-sm">Ir al Generador de Rutinas 🚀</a>
      <a href="parques.html" class="btn btn-secondary btn-sm">Ver Mapa de Parques 🌳</a>
      <a href="ajustes.html" class="btn btn-accent-outline btn-sm">Ir a Ajustes ⚙️</a>
    `;
    bubble.appendChild(actionsDiv);
  }

  container.appendChild(bubble);
  container.scrollTop = container.scrollHeight;
}

function bindFloatingChatEvents() {
  const fabBtn = document.getElementById('btn-fab-actibot');
  const closeBtn = document.getElementById('btn-close-floating-chat');
  const floatingForm = document.getElementById('form-floating-chatbot');
  const messagesList = document.getElementById('floating-chat-messages');

  if (fabBtn) {
    fabBtn.addEventListener('click', () => {
      const drawer = document.getElementById('floating-chat-drawer');
      toggleFloatingChatDrawer(drawer ? drawer.classList.contains('hidden') : true);
    });
  }
  if (closeBtn) {
    closeBtn.addEventListener('click', () => toggleFloatingChatDrawer(false, true));
  }

  async function sendFloatingMessage(userText) {
    const clean = String(userText || '').trim();
    if (!clean) return;
    appendChatBubbleToContainer(messagesList, clean, 'user', false);
    const submitBtn = floatingForm ? floatingForm.querySelector('button[type="submit"]') : null;
    if (submitBtn) submitBtn.disabled = true;

    try {
      const parkCtx = getSelectedParkFromStorage();
      const res = await ApiClient.chat.enviarMensaje(clean, parkCtx, floatingChatHistory.slice(-6));
      if (!res || !res.ok || !res.respuesta) {
        throw new Error((res && res.error) || 'La API de Google Gemini no devolvió respuesta.');
      }
      floatingChatHistory.push({ role: 'user', text: clean });
      floatingChatHistory.push({ role: 'model', text: String(res.respuesta) });
      if (floatingChatHistory.length > 6) floatingChatHistory.splice(0, floatingChatHistory.length - 6);
      appendChatBubbleToContainer(messagesList, String(res.respuesta), 'assistant', true);
    } catch (err) {
      const msg = (err && err.message) || 'No se pudo consultar a ActiBot en este momento.';
      appendChatBubbleToContainer(messagesList, `No se pudo consultar a ActiBot: ${msg}`, 'error', false);
      showToast(msg, 'error');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
    toggleFloatingChatDrawer(true);
  }

  document.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-floating-prompt]');
    if (chip) {
      sendFloatingMessage(chip.getAttribute('data-floating-prompt'));
    }
  });

  if (floatingForm) {
    floatingForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = document.getElementById('floating-chat-input');
      if (!input) return;
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      sendFloatingMessage(text);
    });
  }
}

function bindGlobalLayoutActions() {
  const btnLogout = document.getElementById('btn-logout-sidebar');
  if (btnLogout) btnLogout.addEventListener('click', handleLogout);

  document.addEventListener('click', (e) => {
    const brandLink = e.target.closest('a.brand-link');
    if (brandLink && detectActiveView() === 'inicio') {
      e.preventDefault();
      window.scrollTo({
        top: 0,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth'
      });
      return;
    }

    const openAuth = e.target.closest('[data-open-auth]');
    if (openAuth) {
      e.preventDefault();
      const tab = openAuth.getAttribute('data-open-auth') || 'login';
      window.location.assign(buildLoginUrl(tab));
    }
  });
}

/**
 * Inicializador estándar para las 5 páginas protegidas (`inicio`, `rutinas`, `parques`, `chatbot`, `ajustes`).
 */
export async function initProtectedPage(activeView, options = {}) {
  loadLocalA11yPreferences();
  applyA11yToDOM();

  const authResult = await requireAuthOrRedirect({
    onBackgroundRefresh: (freshUser) => {
      applyA11yToDOM();
      syncLayoutUserUI(freshUser);
    }
  });
  if (!authResult) return null;

  renderAppLayout({
    activeView,
    includeFloatingChat: activeView !== 'chatbot'
  });

  document.body.classList.remove('auth-checking');
  applyA11yToDOM();
  syncLayoutUserUI(authResult.user);

  bindA11yWidgetEvents();
  bindGlobalLayoutActions();
  if (activeView !== 'chatbot') {
    bindFloatingChatEvents();
  }
  bindCookieBannerEvents();
  setupSmartPwaInstallBanner();
  registerServiceWorker();
  scheduleErgonomicReminderTimer(options.isTimerBusy || null);

  await checkUrlAuthTokens();
  return authResult;
}

export async function initPublicLegalPage() {
  loadLocalA11yPreferences();
  applyA11yToDOM();
  document.body.classList.remove('auth-checking');

  const user = await syncOptionalSession();
  renderAppLayout({
    activeView: 'legal',
    includeFloatingChat: Boolean(user)
  });

  applyA11yToDOM();
  syncLayoutUserUI(user);
  bindA11yWidgetEvents();
  bindGlobalLayoutActions();
  if (user) {
    bindFloatingChatEvents();
  }
  bindCookieBannerEvents();
  registerServiceWorker();
}

export function initError404Page() {
  loadLocalA11yPreferences();
  applyA11yToDOM();
  bindA11yWidgetEvents();
  normalizeErrorPageLinks();
}

const autoView = document.body ? document.body.getAttribute('data-initial-view') : '';
if (autoView === 'legal') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPublicLegalPage);
  } else {
    initPublicLegalPage();
  }
} else if (autoView === '404') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initError404Page);
  } else {
    initError404Page();
  }
}
