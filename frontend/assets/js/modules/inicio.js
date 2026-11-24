/**
 * Módulo de Ventana: Inicio (index.html) — ES Module
 * Gestiona Hero Timer 2 min, Bento Grid, Pausa 60s, Telemetría, Historial y Muro Comunitario.
 */
import { ApiClient, setSessionUser } from './api-client.js';
import {
  a11yState,
  announceToScreenReader,
  escapeHtml,
  exportHistoryRowsToCSV,
  formatSecondsMMSS,
  getSelectedParkFromStorage,
  playCompletionChime,
  prefersReducedMotion,
  showToast
} from './ui.js';
import { initProtectedPage, syncLayoutUserUI } from './layout.js';

let remoteHistory = [];

function renderStatsBar(stats) {
  const rachaActualEl = document.getElementById('stat-racha-actual');
  const rachaMaxEl = document.getElementById('stat-racha-maxima');
  const totalPausasEl = document.getElementById('stat-total-pausas');
  const minTotalesEl = document.getElementById('stat-minutos-totales');
  const utilidadPromEl = document.getElementById('stat-utilidad-promedio');

  const rachaActual = Number(stats.racha_actual || 0);
  const rachaMax = Number(stats.racha_maxima ?? stats.mejor_racha ?? 0);
  const totalPausas = Number(stats.total_pausas || 0);
  const minTotales = Number(
    stats.minutos_totales !== undefined
      ? stats.minutos_totales
      : Math.round(Number(stats.segundos_totales || 0) / 60)
  );
  const utilidadPromedio = Number(stats.utilidad_promedio || stats.calificacion_promedio || 5.0).toFixed(1);

  if (rachaActualEl) rachaActualEl.textContent = `${rachaActual} ${rachaActual === 1 ? 'día' : 'días'}`;
  if (rachaMaxEl) rachaMaxEl.textContent = `${rachaMax} ${rachaMax === 1 ? 'día' : 'días'}`;
  if (totalPausasEl) totalPausasEl.textContent = String(totalPausas);
  if (minTotalesEl) minTotalesEl.textContent = `${minTotales} min`;
  if (utilidadPromEl) {
    utilidadPromEl.textContent = `Tiempo Activo • Utilidad ${utilidadPromedio}★`;
  }

  const metaMin = Number(stats.meta_diaria_min || a11yState.meta_diaria_minutos || 15);
  const minHoy = Number(stats.minutos_hoy !== undefined ? stats.minutos_hoy : minTotales);
  const pct = Math.min(100, Math.round((minHoy / Math.max(1, metaMin)) * 100));

  const goalBadge = document.getElementById('daily-goal-badge');
  const goalLabel = document.getElementById('daily-goal-label');
  const goalFill = document.getElementById('daily-goal-fill');

  if (goalBadge) {
    goalBadge.textContent =
      pct >= 100 ? '🎉 ¡Meta Diaria Cumplida!' : `Meta Diaria • ${pct}% completado hoy`;
    goalBadge.className = pct >= 100 ? 'badge badge-success' : 'badge badge-info';
  }
  if (goalLabel) {
    goalLabel.textContent = `${minHoy} de ${metaMin} minutos recomendados completados hoy.`;
  }
  if (goalFill) {
    goalFill.value = pct;
    goalFill.setAttribute('aria-valuenow', String(pct));
  }
}

function renderHistoryAndCommunity(historyList = [], communityFeed = []) {
  const historyContainer = document.getElementById('user-history-list');
  const communityContainer = document.getElementById('community-feed-list');

  if (historyContainer) {
    if (!Array.isArray(historyList) || historyList.length === 0) {
      historyContainer.innerHTML = `
        <div class="history-item-row">
          <div>
            <strong>Sin pausas registradas hoy</strong>
            <p class="stat-label">Completa una pausa de 60s o inicia una rutina guiada para inaugurar tu historial.</p>
          </div>
          <span class="badge badge-info">Listo</span>
        </div>
      `;
    } else {
      historyContainer.innerHTML = historyList
        .slice(0, 6)
        .map(
          (h) => `
          <div class="history-item-row">
            <div>
              <strong>${escapeHtml(h.titulo)}</strong>
              <p class="stat-label">${escapeHtml(h.modo)} • ${escapeHtml(h.parque)} • Esfuerzo ${Number(h.esfuerzo || 3)}/5 • Utilidad ${Number(h.calificacion || 5)}★ (${escapeHtml(h.fecha)})</p>
            </div>
            <span class="badge badge-success">+${Number(h.minutos)} min</span>
          </div>
        `
        )
        .join('');
    }
  }

  if (communityContainer) {
    if (!Array.isArray(communityFeed) || communityFeed.length === 0) {
      communityContainer.innerHTML = `
        <div class="history-item-row">
          <div>
            <strong>Sin actividad comunitaria reciente</strong>
            <p class="stat-label">Completa una pausa activa o explora parques públicos desde el mapa.</p>
          </div>
          <span class="badge badge-info">En vivo</span>
        </div>
      `;
    } else {
      communityContainer.innerHTML = communityFeed
        .slice(0, 6)
        .map(
          (c) => `
          <div class="history-item-row">
            <div>
              <strong>${escapeHtml(c.ubicacion || 'Santa Catarina, N.L.')}</strong>
              <p class="stat-label">${escapeHtml(c.usuario || 'Miembro')} • ${escapeHtml(c.actividad || 'pausa')} (${escapeHtml(c.necesidad || 'bienestar')}) • ${escapeHtml(c.fecha || 'Hoy')}</p>
            </div>
            <span class="badge badge-info">+${Number(c.minutos || 3)} min</span>
          </div>
        `
        )
        .join('');
    }
  }
}

async function refreshTelemetryDashboard() {
  try {
    const res = await ApiClient.usuarios.perfil();
    if (res && res.usuario) {
      setSessionUser(res.usuario);
      syncLayoutUserUI(res.usuario);
    }
    if (res && res.estadisticas) {
      renderStatsBar(res.estadisticas);
      const rawHist = Array.isArray(res.estadisticas.historial) ? res.estadisticas.historial : [];
      remoteHistory = rawHist.map((item) => ({
        titulo: item.rutina_titulo || item.titulo || 'Pausa Activa Guiada',
        minutos: Number(item.minutos || Math.max(1, Math.round(Number(item.duracion_segundos || 180) / 60))),
        modo: item.completada_sentado || item.modo_accesible === 'sentado' ? 'Sentado / Silla' : 'De pie',
        parque: item.ubicacion || item.parque_nombre || 'Santa Catarina, N.L.',
        esfuerzo: Number(item.esfuerzo || 3),
        calificacion: Number(item.utilidad || item.calificacion || item.estrellas || 5),
        fecha: item.fecha || item.completada_en || 'Reciente'
      }));
      renderHistoryAndCommunity(remoteHistory, res.estadisticas.comunidad_feed || []);
    }
  } catch (_err) {
    renderStatsBar({ racha_actual: 0, racha_maxima: 0, total_pausas: 0, minutos_totales: 0 });
  }
}

async function recordQuickPause(durationSec) {
  const park = getSelectedParkFromStorage();
  try {
    await ApiClient.rutinas.registrarPausa({
      rutina_id: 1,
      duracion_segundos: Number(durationSec || 60),
      modo_sentado: Boolean(a11yState.modo_sentado),
      actividad: a11yState.ubicacion_defecto || 'computadora',
      necesidad: a11yState.objetivo_defecto || 'relajarse',
      esfuerzo: 2,
      calificacion: 5,
      parque_nombre: park ? park.nombre : null,
      parque_osm_id: park ? park.osm_id : null
    });
    await refreshTelemetryDashboard();
  } catch (_err) {
    // Mantener experiencia fluida si falla telemetría temporalmente
  }
}

function initInteractiveSaaSWidgets() {
  const heroCard = document.getElementById('hero-timer-mockup');
  const previewSection = document.getElementById('saas-60s-preview');
  const eyeDigits = document.getElementById('bento-eye-digits');

  const HERO_STEP_SEC = 40;
  const HERO_TOTAL_SEC = 120;
  const HERO_FLOWS = {
    neck: {
      label: 'Cuello y Hombros',
      steps: [
        {
          title: 'Inclinación Cervical Suave',
          desc: 'Relaja ambos hombros hacia abajo, inclina suavemente tu oído derecho hacia el hombro derecho y respira con calma.',
          breath: 'Inhala 4s · Exhala 6s'
        },
        {
          title: 'Giros de Hombros y Trapecio',
          desc: 'Dibuja círculos lentos hacia atrás con ambos hombros para liberar la rigidez del trapecio y el cuello.',
          breath: 'Círculos lentos · Mandíbula suelta'
        },
        {
          title: 'Apertura de Pecho y Clavícula',
          desc: 'Entrelaza ligeramente las manos detrás de tu espalda o respaldo y eleva el esternón con una inhalación profunda.',
          breath: 'Abre el pecho · Exhala suave'
        }
      ]
    },
    mobility: {
      label: 'Movilidad en Escritorio',
      steps: [
        {
          title: 'Giro Torácico Sentado',
          desc: 'Siéntate erguido, apoya tu mano derecha sobre el muslo izquierdo y gira suavemente hacia cada lado.',
          breath: 'Alarga columna · Giro suave'
        },
        {
          title: 'Liberación de Muñecas y Antebrazos',
          desc: 'Extiende un brazo al frente con la palma hacia arriba y lleva suavemente los dedos hacia abajo para aliviar la fatiga del teclado.',
          breath: 'Codo relajado · Respiración fluida'
        },
        {
          title: 'Activación de Cadera y Tobillos',
          desc: 'Ponte de pie cómodamente o alterna elevaciones de talones para activar la circulación y despejar la mente.',
          breath: 'Movimiento ligero · Enfoque renovado'
        }
      ]
    },
    seated: {
      label: 'Flujo Sentado',
      steps: [
        {
          title: 'Alcance Lateral en Silla de Ruedas',
          desc: 'Con los frenos asegurados, apoya una mano en el descansabrazos y extiende el brazo contrario suavemente por encima de la cabeza.',
          breath: 'Cadera firme · Respira profundo'
        },
        {
          title: 'Onda Espinal Gato-Vaca Sentado',
          desc: 'Exhala redondeando suavemente los hombros al frente; inhala abriendo el pecho hacia arriba con el abdomen firme.',
          breath: 'Inhala al abrir · Exhala al redondear'
        },
        {
          title: 'Respiración Vagal 4-6',
          desc: 'Descansa las manos sobre tus piernas y alarga cada exhalación para calmar la tensión del sistema nervioso.',
          breath: 'Inhala 4s · Exhala 6s'
        }
      ]
    }
  };

  const CADENCE_NUDGES = {
    '30': {
      title: '🔔 Próximo aviso postural suave en 30 min',
      text: 'Frecuencia de enfoque intenso: separa los hombros de las orejas, parpadea 5 veces despacio y nivela la altura del monitor.'
    },
    '45': {
      title: '🔔 Próximo aviso postural suave en 45 min',
      text: 'Revisión rápida: relaja la mandíbula, separa los hombros de las orejas y alinea tu cabeza suavemente sobre la columna.'
    },
    '60': {
      title: '🔔 Próximo aviso postural suave en 60 min',
      text: 'Bloque de trabajo profundo: ponte de pie o libera tu postura en silla para una pausa ergonómica de 2 minutos.'
    }
  };

  const BENTO_STRETCHES = {
    pie: [
      {
        title: '1. Ajuste Escapular',
        desc: 'Ponte de pie con espalda recta y junta suavemente los omóplatos hacia atrás',
        duration: '40s'
      },
      {
        title: '2. Marcha Activa de Tobillos y Cadera',
        desc: 'Alterna elevaciones talón-punta para activar la circulación frente al escritorio',
        duration: '40s'
      }
    ],
    sentado: [
      {
        title: '1. Alcance Torácico Sentado',
        desc: 'Asegura los frenos o apoya los pies, entrelaza las manos y estira suavemente hacia arriba',
        duration: '40s'
      },
      {
        title: '2. Inclinación Adaptada de Muñeca y Cuello',
        desc: 'Apoya una palma en el descansabrazos mientras inclinas suavemente el oído hacia el hombro libre',
        duration: '40s'
      }
    ]
  };

  const PREVIEW_60S_FLOWS = {
    neck: {
      label: 'Cuello y Trapecios',
      phases: [
        {
          title: '01. Retracción Cervical y Liberación de Trapecio',
          desc: 'Lleva la barbilla suavemente hacia atrás e inclina la cabeza de lado a lado.'
        },
        {
          title: '02. Giros de Hombros y Apertura de Pecho',
          desc: 'Dibuja círculos amplios y lentos hacia atrás con los codos relajados.'
        },
        {
          title: '03. Respiración Restauradora 4-6',
          desc: 'Inhala 4 segundos por la nariz y exhala suavemente durante 6 segundos.'
        }
      ]
    },
    eyes: {
      label: 'Vista y Enfoque',
      phases: [
        {
          title: '01. Descanso Ocular con Palmas Tibias',
          desc: 'Frota suavemente tus palmas y cubre tus ojos cerrados sin presionar los párpados.'
        },
        {
          title: '02. Enfoque a Horizonte Lejano (6 Metros)',
          desc: 'Relaja el cristalino mirando sin esfuerzo hacia una ventana o árbol lejano.'
        },
        {
          title: '03. Parpadeo Lento y Relajación de Sienes',
          desc: 'Realiza 10 parpadeos pausados mientras relajas pómulos y mandíbula.'
        }
      ]
    },
    wrists: {
      label: 'Muñecas y Espalda',
      phases: [
        {
          title: '01. Deslizamiento Suave de Tendones',
          desc: 'Abre los dedos en abanico, llévalos a posición plana y cierra en puño suave.'
        },
        {
          title: '02. Estiramiento de Extensores de Antebrazo',
          desc: 'Extiende el brazo al frente con comodidad y dirige las puntas de los dedos hacia abajo.'
        },
        {
          title: '03. Exhalación con Postura Erguida',
          desc: 'Desliza los omóplatos suavemente hacia abajo durante una exhalación de 6 segundos.'
        }
      ]
    }
  };

  const setText = (el, txt) => {
    if (el && el.textContent !== txt) el.textContent = txt;
  };
  const setPill = (sel, attr, val) => {
    document.querySelectorAll(sel).forEach((btn) => {
      const active = btn.getAttribute(attr) === String(val);
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', String(active));
    });
  };

  const intervals = { hero: null, eye: null, preview60: null };
  const s = {
    heroMode: 'neck',
    heroRemaining: HERO_TOTAL_SEC,
    heroRunning: false,
    heroDone: false,
    eyeRemaining: 20,
    eyeRunning: false,
    eyeDone: false,
    previewTopic: 'neck',
    previewRemaining: 60,
    previewRunning: false,
    previewDone: false,
    previewLastPhase: 0
  };

  const clearSlot = (slot) => {
    if (intervals[slot]) {
      clearInterval(intervals[slot]);
      intervals[slot] = null;
    }
  };

  const pauseOthers = (except) => {
    if (except !== 'hero' && s.heroRunning) {
      clearSlot('hero');
      s.heroRunning = false;
      renderHero();
    }
    if (except !== 'eye' && s.eyeRunning) {
      clearSlot('eye');
      s.eyeRunning = false;
      renderEye();
    }
    if (except !== 'preview60' && s.previewRunning) {
      clearSlot('preview60');
      s.previewRunning = false;
      renderPreview60();
    }
  };

  // --- Hero 2 min ---
  const heroStatusBadge = document.getElementById('hero-timer-status-badge');
  const heroDial = document.getElementById('hero-timer-dial');
  const heroDigits = document.getElementById('hero-timer-digits');
  const heroBreath = document.getElementById('hero-timer-breath');
  const heroProgress = document.getElementById('hero-timer-progress');
  const heroStepBadge = document.getElementById('hero-timer-step-badge');
  const heroStepTitle = document.getElementById('hero-timer-step-title');
  const heroStepDesc = document.getElementById('hero-timer-step-desc');
  const btnHeroToggle = document.getElementById('btn-hero-timer-toggle');
  const btnHeroNext = document.getElementById('btn-hero-timer-next');
  const btnHeroReset = document.getElementById('btn-hero-timer-reset');

  function renderHero() {
    const flow = HERO_FLOWS[s.heroMode] || HERO_FLOWS.neck;
    const elapsed = HERO_TOTAL_SEC - s.heroRemaining;
    const stepIdx = s.heroDone
      ? flow.steps.length - 1
      : Math.min(flow.steps.length - 1, Math.floor(elapsed / HERO_STEP_SEC));
    const step = flow.steps[stepIdx];

    setText(heroDigits, formatSecondsMMSS(s.heroRemaining));
    setText(heroBreath, s.heroDone ? 'Completada · Calma renovada' : step.breath);
    if (heroDial) heroDial.classList.toggle('is-running', s.heroRunning);
    if (heroProgress) {
      heroProgress.value = elapsed;
      heroProgress.setAttribute('aria-valuenow', String(elapsed));
    }
    setText(heroStepBadge, `Paso ${stepIdx + 1} de 3 • ${HERO_STEP_SEC}s`);
    setText(heroStepTitle, step.title);
    setText(heroStepDesc, step.desc);

    if (heroStatusBadge) {
      if (s.heroDone) setText(heroStatusBadge, 'Completado • 00:00');
      else if (s.heroRunning) setText(heroStatusBadge, `En curso • ${formatSecondsMMSS(s.heroRemaining)}`);
      else if (elapsed > 0) setText(heroStatusBadge, `En pausa • ${formatSecondsMMSS(s.heroRemaining)}`);
      else setText(heroStatusBadge, 'Listo • 2:00');
    }

    if (btnHeroToggle) {
      if (s.heroDone) setText(btnHeroToggle, 'Repetir Pausa 2:00');
      else if (s.heroRunning) setText(btnHeroToggle, 'Pausar');
      else if (elapsed > 0) setText(btnHeroToggle, 'Reanudar Pausa');
      else setText(btnHeroToggle, 'Iniciar Pausa 2:00');
    }
  }

  function finishHero() {
    clearSlot('hero');
    s.heroRunning = false;
    s.heroDone = true;
    s.heroRemaining = 0;
    renderHero();
    playCompletionChime();
    recordQuickPause(HERO_TOTAL_SEC);
    showToast('¡Pausa activa de 2 minutos completada! Tu progreso fue actualizado.', 'success');
    announceToScreenReader('Pausa activa de 2 minutos completada.');
  }

  function resetHero(modeKey = s.heroMode) {
    clearSlot('hero');
    s.heroMode = HERO_FLOWS[modeKey] ? modeKey : 'neck';
    s.heroRunning = false;
    s.heroDone = false;
    s.heroRemaining = HERO_TOTAL_SEC;
    setPill('[data-hero-mode]', 'data-hero-mode', s.heroMode);
    renderHero();
  }

  if (heroCard) {
    document.querySelectorAll('[data-hero-mode]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const mode = btn.getAttribute('data-hero-mode') || 'neck';
        resetHero(mode);
        announceToScreenReader(`Temporizador cambiado a ${(HERO_FLOWS[mode] || HERO_FLOWS.neck).label}.`);
      });
    });

    if (btnHeroToggle) {
      btnHeroToggle.addEventListener('click', () => {
        if (s.heroDone) resetHero(s.heroMode);
        if (s.heroRunning) {
          clearSlot('hero');
          s.heroRunning = false;
          renderHero();
          announceToScreenReader('Pausa de 2 minutos en pausa.');
          return;
        }
        pauseOthers('hero');
        s.heroRunning = true;
        renderHero();
        announceToScreenReader('Pausa activa de 2 minutos iniciada.');
        intervals.hero = window.setInterval(() => {
          if (s.heroRemaining <= 1) {
            finishHero();
            return;
          }
          s.heroRemaining -= 1;
          renderHero();
        }, 1000);
      });
    }

    if (btnHeroNext) {
      btnHeroNext.addEventListener('click', () => {
        const elapsed = HERO_TOTAL_SEC - s.heroRemaining;
        const idx = Math.floor(elapsed / HERO_STEP_SEC);
        if (s.heroDone || idx >= 2) {
          finishHero();
          return;
        }
        s.heroRemaining = Math.max(1, HERO_TOTAL_SEC - (idx + 1) * HERO_STEP_SEC);
        s.heroDone = false;
        renderHero();
      });
    }

    if (btnHeroReset) {
      btnHeroReset.addEventListener('click', () => {
        resetHero(s.heroMode);
        announceToScreenReader('Temporizador de 2 minutos reiniciado.');
      });
    }
    renderHero();
  }

  // --- Bento Cadence & Stretches ---
  const nudgeTitle = document.getElementById('bento-posture-nudge-title');
  const nudgeText = document.getElementById('bento-posture-nudge-text');
  document.querySelectorAll('[data-bento-cadence]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const cadence = btn.getAttribute('data-bento-cadence') || '45';
      const copy = CADENCE_NUDGES[cadence] || CADENCE_NUDGES['45'];
      setPill('[data-bento-cadence]', 'data-bento-cadence', cadence);
      if (nudgeTitle) nudgeTitle.textContent = copy.title;
      if (nudgeText) nudgeText.textContent = copy.text;
    });
  });

  const bentoStretchList = document.getElementById('bento-stretch-list');
  document.querySelectorAll('[data-bento-posture]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const postureKey = btn.getAttribute('data-bento-posture') === 'sentado' ? 'sentado' : 'pie';
      setPill('[data-bento-posture]', 'data-bento-posture', postureKey);
      if (!bentoStretchList) return;
      const items = BENTO_STRETCHES[postureKey] || BENTO_STRETCHES.pie;
      bentoStretchList.innerHTML = items
        .map(
          (item) => `
          <div class="bento-micro-row">
            <span><strong>${escapeHtml(item.title)}</strong><br><span class="stat-label">${escapeHtml(item.desc)}</span></span>
            <span class="badge badge-info">${escapeHtml(item.duration)}</span>
          </div>`
        )
        .join('');
    });
  });

  // --- Bento Eye 20s ---
  const eyeStatus = document.getElementById('bento-eye-status');
  const btnEyeToggle = document.getElementById('btn-bento-eye-toggle');
  const eyeProgress = document.getElementById('bento-eye-progress');

  function renderEye() {
    const elapsed = 20 - s.eyeRemaining;
    setText(eyeDigits, formatSecondsMMSS(s.eyeRemaining));
    if (eyeProgress) {
      eyeProgress.value = elapsed;
      eyeProgress.setAttribute('aria-valuenow', String(elapsed));
    }
    if (eyeStatus) {
      if (s.eyeDone) setText(eyeStatus, 'Completado • ¡Vista descansada! Regresa suavemente a tu pantalla.');
      else if (s.eyeRunning) setText(eyeStatus, 'En curso • Mira a 6 metros de distancia y parpadea despacio.');
      else if (elapsed > 0) setText(eyeStatus, `En pausa • ${s.eyeRemaining}s restantes.`);
      else setText(eyeStatus, 'Listo • Enfoca una ventana o punto lejano y realiza 10 parpadeos lentos.');
    }
    if (btnEyeToggle) {
      if (s.eyeDone) setText(btnEyeToggle, 'Repetir Descanso 20s');
      else if (s.eyeRunning) setText(btnEyeToggle, 'Pausar 20s');
      else if (elapsed > 0) setText(btnEyeToggle, 'Reanudar 20s');
      else setText(btnEyeToggle, 'Iniciar Descanso Visual 20s');
    }
  }

  if (btnEyeToggle) {
    btnEyeToggle.addEventListener('click', () => {
      if (s.eyeDone) {
        s.eyeDone = false;
        s.eyeRemaining = 20;
      }
      if (s.eyeRunning) {
        clearSlot('eye');
        s.eyeRunning = false;
        renderEye();
        return;
      }
      pauseOthers('eye');
      s.eyeRunning = true;
      renderEye();
      intervals.eye = window.setInterval(() => {
        if (s.eyeRemaining <= 1) {
          clearSlot('eye');
          s.eyeRunning = false;
          s.eyeDone = true;
          s.eyeRemaining = 0;
          renderEye();
          playCompletionChime();
          showToast('¡Descanso visual 20-20-20 completado!', 'success');
          return;
        }
        s.eyeRemaining -= 1;
        renderEye();
      }, 1000);
    });
  }

  // --- Preview 60s ---
  const btnHeroTry60 = document.getElementById('btn-hero-try-60s');
  const chkPreview60Chime = document.getElementById('chk-preview-60s-chime');
  const btnPreview60Toggle = document.getElementById('btn-preview-60s-toggle');
  const btnPreview60Reset = document.getElementById('btn-preview-60s-reset');
  const preview60Digits = document.getElementById('preview-60s-digits');
  const preview60Badge = document.getElementById('preview-60s-badge');
  const preview60Progress = document.getElementById('preview-60s-progress');
  const preview60PhasesList = document.getElementById('preview-60s-phases-list');
  const preview60DoneBox = document.getElementById('preview-60s-done-box');

  function renderPreview60() {
    const flow = PREVIEW_60S_FLOWS[s.previewTopic] || PREVIEW_60S_FLOWS.neck;
    const elapsed = 60 - s.previewRemaining;
    const phaseIdx = s.previewDone ? 2 : Math.min(2, Math.floor(elapsed / 20));

    setText(preview60Digits, formatSecondsMMSS(s.previewRemaining));
    if (preview60Progress) {
      preview60Progress.value = elapsed;
      preview60Progress.setAttribute('aria-valuenow', String(elapsed));
    }
    if (preview60Badge) {
      if (s.previewDone) setText(preview60Badge, 'Completado • 00:00');
      else if (s.previewRunning) setText(preview60Badge, `Fase ${phaseIdx + 1} de 3 • En curso`);
      else if (elapsed > 0) setText(preview60Badge, `Fase ${phaseIdx + 1} de 3 • En pausa`);
      else setText(preview60Badge, 'Fase 1 de 3 • Listo');
    }
    if (preview60PhasesList) {
      preview60PhasesList.querySelectorAll('.saas-60s-phase-item').forEach((el, idx) => {
        const item = flow.phases[idx];
        if (item) {
          setText(el.querySelector('strong'), item.title);
          setText(el.querySelector('.stat-label'), item.desc);
        }
        el.classList.toggle('done', s.previewDone || idx < phaseIdx);
        el.classList.toggle('active', !s.previewDone && idx === phaseIdx);
      });
    }
    if (preview60DoneBox) preview60DoneBox.classList.toggle('hidden', !s.previewDone);
    if (btnPreview60Toggle) {
      if (s.previewDone) setText(btnPreview60Toggle, 'Repetir Pausa de 60s');
      else if (s.previewRunning) setText(btnPreview60Toggle, 'Pausar Pausa de 60s');
      else if (elapsed > 0) setText(btnPreview60Toggle, 'Reanudar Pausa de 60s');
      else setText(btnPreview60Toggle, 'Probar Pausa de 60 Segundos Ahora');
    }
  }

  function resetPreview60(topicKey = s.previewTopic) {
    clearSlot('preview60');
    s.previewTopic = PREVIEW_60S_FLOWS[topicKey] ? topicKey : 'neck';
    s.previewRunning = false;
    s.previewDone = false;
    s.previewRemaining = 60;
    s.previewLastPhase = 0;
    setPill('[data-preview60-topic]', 'data-preview60-topic', s.previewTopic);
    renderPreview60();
  }

  function startPreview60() {
    if (s.previewDone) resetPreview60(s.previewTopic);
    if (s.previewRunning) return;
    pauseOthers('preview60');
    s.previewRunning = true;
    renderPreview60();
    intervals.preview60 = window.setInterval(() => {
      if (s.previewRemaining <= 1) {
        clearSlot('preview60');
        s.previewRunning = false;
        s.previewDone = true;
        s.previewRemaining = 0;
        renderPreview60();
        playCompletionChime(chkPreview60Chime ? chkPreview60Chime.checked : a11yState.alertas_sonoras);
        recordQuickPause(60);
        showToast('¡Pausa de 60 segundos completada! ¿Te sientes más ligero?', 'success');
        return;
      }
      s.previewRemaining -= 1;
      const nextPhase = Math.min(2, Math.floor((60 - s.previewRemaining) / 20));
      if (nextPhase > s.previewLastPhase) {
        s.previewLastPhase = nextPhase;
        if (chkPreview60Chime && chkPreview60Chime.checked) playCompletionChime(true);
      }
      renderPreview60();
    }, 1000);
  }

  if (previewSection) {
    document.querySelectorAll('[data-preview60-topic]').forEach((btn) => {
      btn.addEventListener('click', () => resetPreview60(btn.getAttribute('data-preview60-topic') || 'neck'));
    });
    if (btnPreview60Toggle) {
      btnPreview60Toggle.addEventListener('click', () => {
        if (s.previewRunning) {
          clearSlot('preview60');
          s.previewRunning = false;
          renderPreview60();
          return;
        }
        startPreview60();
      });
    }
    if (btnPreview60Reset) btnPreview60Reset.addEventListener('click', () => resetPreview60(s.previewTopic));
    if (btnHeroTry60) {
      btnHeroTry60.addEventListener('click', () => {
        previewSection.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
        if (!s.previewRunning) startPreview60();
      });
    }
    renderPreview60();
  }

  return {
    isAnyRunning: () => Boolean(s.heroRunning || s.eyeRunning || s.previewRunning)
  };
}

async function bootInicioPage() {
  let timersCtrl = null;
  const auth = await initProtectedPage('inicio', {
    isTimerBusy: () => Boolean(timersCtrl && timersCtrl.isAnyRunning())
  });
  if (!auth) return;

  timersCtrl = initInteractiveSaaSWidgets();

  const btnExportCsvHome = document.getElementById('btn-export-csv-home');
  if (btnExportCsvHome) {
    btnExportCsvHome.addEventListener('click', () => exportHistoryRowsToCSV(remoteHistory));
  }

  await refreshTelemetryDashboard();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInicioPage);
} else {
  bootInicioPage();
}
