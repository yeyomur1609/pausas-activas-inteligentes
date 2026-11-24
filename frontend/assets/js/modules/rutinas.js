/**
 * Módulo de Ventana: Rutinas (rutinas.html) — ES Module
 * Generador 3 pasos, catálogo remoto/resiliente, reproductor en 2 columnas, voz guiada y evaluación doble.
 */
import { ApiClient } from './api-client.js';
import {
  a11yState,
  announceToScreenReader,
  escapeHtml,
  formatSecondsMMSS,
  getSelectedParkFromStorage,
  onA11yChange,
  playCompletionChime,
  saveA11yPreferences,
  showToast
} from './ui.js';
import { initProtectedPage } from './layout.js';
import {
  destroyExerciseAnimation,
  loadExerciseAnimation,
  pauseExerciseAnimation,
  playExerciseAnimation,
  stopExerciseAnimation,
  syncLottieReducedMotion
} from './lottie-ejercicios.js';

const FALLBACK_ROUTINES = [
  {
    id: 1,
    slug: 'alivio-cervical-visual-express',
    titulo: 'Alivio Cervical y Descanso Visual Express',
    descripcion: 'Pausa rápida de 3 minutos para liberar la tensión en cuello, trapecios y fatiga ocular frente al monitor.',
    categoria: 'computadora',
    necesidad: 'relajarse',
    nivel: 'suave',
    duracion_segundos: 180,
    apta_silla_ruedas: true,
    pasos: [
      {
        orden: 1,
        nombre: 'Regla 20-20-20 y Parpadeo Consciente',
        duracion_segundos: 45,
        instrucciones_pie: 'De pie con postura relajada, dirige la vista a un objeto a más de 6 metros y realiza 10 parpadeos lentos y completos.',
        instrucciones_sentado: 'Con la espalda apoyada en el respaldo, mira a un punto lejano por la ventana y realiza 10 parpadeos lentos y profundos.',
        respiracion: 'Inhala 4 segundos por la nariz, exhala 6 segundos por la boca.'
      },
      {
        orden: 2,
        nombre: 'Inclinación Lateral de Cuello y Trapecio',
        duracion_segundos: 65,
        instrucciones_pie: 'Lleva suavemente la oreja derecha hacia el hombro derecho sin elevar los hombros. Mantén 30 segundos y cambia de lado.',
        instrucciones_sentado: 'Sujeta el borde lateral de tu asiento o silla de ruedas con una mano e inclina suavemente la cabeza hacia el lado opuesto.',
        respiracion: 'Respira profundo soltando la mandíbula en cada exhalación.'
      },
      {
        orden: 3,
        nombre: 'Retracción Escapular y Apertura Torácica',
        duracion_segundos: 70,
        instrucciones_pie: 'Entrelaza las manos detrás de la espalda o abre los brazos llevando los omóplatos hacia atrás y abajo.',
        instrucciones_sentado: 'Abre los brazos en forma de cactus a la altura de los hombros y junta suavemente los omóplatos abriendo el pecho.',
        respiracion: 'Expande las costillas al inhalar y relaja los hombros al exhalar.'
      }
    ]
  },
  {
    id: 2,
    slug: 'descarga-lumbar-apertura-cadera',
    titulo: 'Descarga Lumbar y Movilidad de Muñecas',
    descripcion: 'Rutina de 4 minutos para descomprimir la espalda baja, muñecas y túnel carpiano tras escribir o usar el teclado.',
    categoria: 'estudio',
    necesidad: 'moverse',
    nivel: 'suave',
    duracion_segundos: 240,
    apta_silla_ruedas: true,
    pasos: [
      {
        orden: 1,
        nombre: 'Extensión de Flexores de Muñeca y Dedos',
        duracion_segundos: 75,
        instrucciones_pie: 'Extiende el brazo derecho al frente con la palma hacia arriba y lleva suavemente los dedos hacia abajo con la otra mano. Cambia de brazo.',
        instrucciones_sentado: 'Con los codos relajados cerca del tronco, estira suavemente la palma y los dedos de cada mano durante 35 segundos por lado.',
        respiracion: 'Mantén una respiración rítmica y fluida.'
      },
      {
        orden: 2,
        nombre: 'Rotación Torácica Suave',
        duracion_segundos: 85,
        instrucciones_pie: 'Con los pies al ancho de hombros, gira suavemente el torso hacia la derecha y luego hacia la izquierda manteniendo la pelvis estable.',
        instrucciones_sentado: 'Gira el tronco hacia la derecha apoyando la mano en el reposabrazos o respaldo de tu silla. Mantén 40 segundos por lado.',
        respiracion: 'Inhala al alargar la columna y exhala al girar suavemente.'
      },
      {
        orden: 3,
        nombre: 'Gato-Vaca Ergonómico de Columna',
        duracion_segundos: 80,
        instrucciones_pie: 'Apoya las manos sobre los muslos con rodillas semiflexionadas, arquea suavemente el pecho al inhalar y redondea la espalda al exhalar.',
        instrucciones_sentado: 'Con las manos sobre los muslos, proyecta el esternón al frente al inhalar y curva suavemente los hombros al exhalar.',
        respiracion: 'Sincroniza cada movimiento con tu ciclo respiratorio.'
      }
    ]
  },
  {
    id: 3,
    slug: 'activacion-parque-santa-catarina',
    titulo: 'Activación al Aire Libre en Parque Público',
    descripcion: 'Rutina revitalizante de 5 minutos diseñada para realizarse junto a una banca, andador o ejercitador de parque.',
    categoria: 'parque',
    necesidad: 'despejarse',
    nivel: 'moderado',
    duracion_segundos: 300,
    apta_silla_ruedas: true,
    pasos: [
      {
        orden: 1,
        nombre: 'Marcha Activa o Impulso Rítmico de Brazos',
        duracion_segundos: 95,
        instrucciones_pie: 'Realiza una marcha suave en tu lugar o a lo largo del andador elevando rodillas moderadamente y balanceando los brazos.',
        instrucciones_sentado: 'Realiza círculos amplios y dinámicos hacia adelante y hacia atrás con ambos brazos para activar la circulación superior.',
        respiracion: 'Inhala profundo el aire fresco en 3 tiempos y exhala en 3 tiempos.'
      },
      {
        orden: 2,
        nombre: 'Empuje Dinámico en Banca o Pecho al Aire',
        duracion_segundos: 105,
        instrucciones_pie: 'Apoya las manos en el respaldo firme de una banca del parque y realiza flexiones inclinadas controladas.',
        instrucciones_sentado: 'Extiende ambos brazos al frente y hacia el cielo alternando empujes controlados con contracción de espalda alta.',
        respiracion: 'Exhala al empujar e inhala al regresar lentamente.'
      },
      {
        orden: 3,
        nombre: 'Respiración Diafragmática de Cierre en Sombra',
        duracion_segundos: 100,
        instrucciones_pie: 'Detente bajo la sombra de un árbol, eleva ambos brazos al inhalar profundamente y bájalos lentamente al exhalar.',
        instrucciones_sentado: 'Apoya cómodamente la espalda bajo la sombra, eleva los brazos al inhalar y suéltalos despacio al exhalar.',
        respiracion: 'Inhala 4 segundos, retén 2 segundos y exhala 6 segundos.'
      }
    ]
  },
  {
    id: 4,
    slug: 'enfoque-mental-post-reunion',
    titulo: 'Reinicio Mental y Postural Post-Reunión',
    descripcion: 'Secuencia de 5 minutos para oxigenar el cerebro, recuperar la concentración y liberar hombros tras videollamadas.',
    categoria: 'reuniones',
    necesidad: 'concentrarse',
    nivel: 'suave',
    duracion_segundos: 300,
    apta_silla_ruedas: true,
    pasos: [
      {
        orden: 1,
        nombre: 'Respiración Cuadrada de Enfoque (Box Breathing)',
        duracion_segundos: 90,
        instrucciones_pie: 'De pie con hombros sueltos, inhala 4 segundos, sostén 4 segundos, exhala 4 segundos y espera 4 segundos.',
        instrucciones_sentado: 'Sentado con la columna erguida, inhala 4 segundos, sostén 4 segundos, exhala 4 segundos y espera 4 segundos.',
        respiracion: 'Ritmo constante 4-4-4-4 para equilibrar el sistema nervioso.'
      },
      {
        orden: 2,
        nombre: 'Círculos Amplios de Hombros y Escápulas',
        duracion_segundos: 105,
        instrucciones_pie: 'Realiza 10 círculos lentos y amplios con los hombros hacia atrás y 10 hacia adelante.',
        instrucciones_sentado: 'Con las manos descansando en los muslos, dibuja círculos lentos y profundos con ambos hombros.',
        respiracion: 'Inhala al subir los hombros y exhala largo al bajarlos.'
      },
      {
        orden: 3,
        nombre: 'Estiramiento Lateral de Costillas y Oblicuos',
        duracion_segundos: 105,
        instrucciones_pie: 'Eleva el brazo derecho por encima de la cabeza e inclina suavemente el tronco hacia la izquierda. Cambia a los 50 segundos.',
        instrucciones_sentado: 'Sujeta el reposabrazos izquierdo con una mano y alarga el brazo derecho por encima de la cabeza. Cambia de lado.',
        respiracion: 'Dirige el aire hacia el costado que se está estirando.'
      }
    ]
  }
];

const rState = {
  routines: [...FALLBACK_ROUTINES],
  activeRoutine: FALLBACK_ROUTINES[0],
  lastCompletedRoutine: null,
  generator: {
    category: 'computadora',
    durationMin: 3,
    need: 'relajarse'
  },
  player: {
    stepIndex: 0,
    remainingSeconds: 45,
    stepTotalSeconds: 45,
    isRunning: false,
    intervalId: null
  }
};

function normalizeRoutineFromServer(r) {
  const rawSteps = Array.isArray(r.pasos) ? r.pasos : Array.isArray(r.ejercicios) ? r.ejercicios : [];
  const durSec = Number(
    r.duracion_segundos || r.duracion_total_segundos || Number(r.duracion_minutos || 3) * 60
  );
  return {
    ...r,
    id: Number(r.id || 1),
    duracion_segundos: durSec,
    apta_silla_ruedas: Boolean(r.apta_silla_ruedas ?? r.apta_movilidad_reducida ?? true),
    pasos: rawSteps.map((s, idx) => ({
      id: s.id ? Number(s.id) : null,
      orden: Number(s.orden || idx + 1),
      nombre: s.nombre || s.titulo || `Paso ${idx + 1}`,
      duracion_segundos: Number(s.duracion_segundos || 60),
      instrucciones_pie: s.instrucciones_pie || s.instrucciones || '',
      instrucciones_sentado: s.instrucciones_sentado || s.instrucciones || '',
      respiracion: s.respiracion || s.beneficio_ergonomico || 'Respiración nasal profunda y fluida.'
    }))
  };
}

function syncGeneratorFromDefaults() {
  if (a11yState.ubicacion_defecto) rState.generator.category = a11yState.ubicacion_defecto;
  if (a11yState.tiempo_defecto) rState.generator.durationMin = Number(a11yState.tiempo_defecto);
  if (a11yState.objetivo_defecto) rState.generator.need = a11yState.objetivo_defecto;

  document.querySelectorAll('[data-gen-category]').forEach((btn) => {
    const isActive = btn.getAttribute('data-gen-category') === rState.generator.category;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-pressed', String(isActive));
  });
  document.querySelectorAll('[data-gen-duration]').forEach((btn) => {
    const isActive = Number(btn.getAttribute('data-gen-duration')) === rState.generator.durationMin;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-pressed', String(isActive));
  });
  document.querySelectorAll('[data-gen-need]').forEach((btn) => {
    const isActive = btn.getAttribute('data-gen-need') === rState.generator.need;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-pressed', String(isActive));
  });
}

function renderRoutinesCatalog() {
  const container = document.getElementById('routines-catalog-container');
  if (!container) return;

  container.innerHTML = rState.routines
    .map((r) => {
      const min = Math.max(1, Math.round((r.duracion_segundos || 180) / 60));
      return `
        <article class="card routine-card">
          <div>
            <div class="module-card-header">
              <span class="badge badge-info">${min} min • ${escapeHtml(r.nivel || 'Suave')}</span>
              <span class="badge badge-info">♿ Silla / De pie</span>
            </div>
            <h2 class="module-card-title">${escapeHtml(r.titulo)}</h2>
            <p class="module-card-desc">${escapeHtml(r.descripcion)}</p>
          </div>
          <button type="button" class="btn btn-primary btn-block" data-start-routine-id="${Number(r.id)}">
            Comenzar esta Rutina
          </button>
        </article>
      `;
    })
    .join('');
}

function triggerVisualFlashAlert() {
  const playerCard = document.getElementById('routine-active-player');
  if (!playerCard) return;
  playerCard.classList.remove('visual-flash-alert');
  void playerCard.offsetWidth;
  playerCard.classList.add('visual-flash-alert');
}

function speakCurrentStepIfEnabled() {
  if (!a11yState.voz_guiada || !('speechSynthesis' in window) || !rState.activeRoutine) return;
  const steps = rState.activeRoutine.pasos || [];
  const step = steps[rState.player.stepIndex];
  if (!step) return;

  window.speechSynthesis.cancel();
  const instr = a11yState.modo_sentado ? step.instrucciones_sentado : step.instrucciones_pie;
  const utter = new SpeechSynthesisUtterance(`${step.nombre}. ${instr}`);
  utter.lang = 'es-MX';
  window.speechSynthesis.speak(utter);
}

function syncVoiceButton() {
  const btnVoice = document.getElementById('btn-player-voice');
  if (!btnVoice) return;
  const voiceOn = Boolean(a11yState.voz_guiada);
  btnVoice.textContent = voiceOn ? 'Voz Guiada: Activada 🔊' : 'Voz Guiada: Desactivada';
  btnVoice.setAttribute('aria-pressed', String(voiceOn));
}

function setupCurrentStepTimer() {
  const steps = Array.isArray(rState.activeRoutine.pasos) ? rState.activeRoutine.pasos : [];
  const currentStep = steps[rState.player.stepIndex] || {
    nombre: 'Respiración profunda y movilidad suave',
    duracion_segundos: 45,
    instrucciones_pie: 'Realiza estiramientos suaves acompañados de respiración profunda.',
    instrucciones_sentado: 'En tu silla, realiza movimientos articulares suaves sin dolor.'
  };
  const duration = Number(currentStep.duracion_segundos || 45);
  rState.player.remainingSeconds = duration;
  rState.player.stepTotalSeconds = duration;
}

function renderRoutineStepsList() {
  const listContainer = document.getElementById('routine-steps-list');
  if (!listContainer || !rState.activeRoutine) return;

  const steps = Array.isArray(rState.activeRoutine.pasos) ? rState.activeRoutine.pasos : [];
  listContainer.innerHTML = steps
    .map((step, idx) => {
      const isCurrent = idx === rState.player.stepIndex;
      const isDone = idx < rState.player.stepIndex;
      const sec = Number(step.duracion_segundos || 45);
      return `
        <li class="routine-step-entry">
          <button
            type="button"
            class="routine-step-item ${isCurrent ? 'active' : ''} ${isDone ? 'done' : ''}"
            data-jump-step-index="${idx}"
            aria-current="${isCurrent ? 'step' : 'false'}"
          >
            <span class="routine-step-meta">
              <strong>${idx + 1}. ${escapeHtml(step.nombre)}</strong>
              <span class="stat-label">${isDone ? '✓ Completado' : isCurrent ? '▶ En reproducción' : 'Pendiente'}</span>
            </span>
            <span class="badge badge-info">${sec}s</span>
          </button>
        </li>
      `;
    })
    .join('');
}

function renderCurrentPlayerStep() {
  if (!rState.activeRoutine) return;
  const steps = Array.isArray(rState.activeRoutine.pasos) ? rState.activeRoutine.pasos : [];
  const totalSteps = Math.max(1, steps.length);
  const currentStep = steps[rState.player.stepIndex] || steps[0];
  if (!currentStep) return;

  const counterEl = document.getElementById('player-step-counter');
  const nameEl = document.getElementById('player-exercise-name');
  const instrEl = document.getElementById('player-exercise-instructions');
  const digitsEl = document.getElementById('player-timer-digits');
  const phaseEl = document.getElementById('player-timer-phase');
  const pauseBtn = document.getElementById('btn-player-pause');
  const progressFill = document.getElementById('player-progress-fill');

  if (counterEl) {
    counterEl.textContent = `Ejercicio ${rState.player.stepIndex + 1} de ${totalSteps} • ${
      a11yState.modo_sentado ? 'Modo Sentado / Silla' : 'Modo De Pie'
    }`;
  }
  if (nameEl) nameEl.textContent = currentStep.nombre;
  if (instrEl) {
    instrEl.textContent = a11yState.modo_sentado
      ? currentStep.instrucciones_sentado || currentStep.instrucciones_pie
      : currentStep.instrucciones_pie || currentStep.instrucciones_sentado;
  }
  if (digitsEl) digitsEl.textContent = formatSecondsMMSS(rState.player.remainingSeconds);
  if (phaseEl) phaseEl.textContent = rState.player.isRunning ? 'En curso' : 'Listo / En pausa';
  if (pauseBtn) pauseBtn.textContent = rState.player.isRunning ? 'PAUSAR' : 'INICIAR / REANUDAR';
  if (progressFill) {
    const elapsed = rState.player.stepTotalSeconds - rState.player.remainingSeconds;
    const totalPct = Math.min(
      100,
      Math.round(((rState.player.stepIndex + elapsed / Math.max(1, rState.player.stepTotalSeconds)) / totalSteps) * 100)
    );
    progressFill.value = totalPct;
    progressFill.setAttribute('aria-valuenow', String(totalPct));
  }
  syncVoiceButton();
}

function loadCurrentStepAnimation(autoPlay = false) {
  const lottieContainer = document.getElementById('player-lottie-container');
  if (!lottieContainer || !rState.activeRoutine) return;
  const steps = Array.isArray(rState.activeRoutine.pasos) ? rState.activeRoutine.pasos : [];
  const currentStep = steps[rState.player.stepIndex] || steps[0];
  loadExerciseAnimation(lottieContainer, currentStep, { autoplay: autoPlay });
}

function stopRoutineTimer() {
  rState.player.isRunning = false;
  if (rState.player.intervalId) {
    clearInterval(rState.player.intervalId);
    rState.player.intervalId = null;
  }
  pauseExerciseAnimation();
  renderCurrentPlayerStep();
}

function startRoutineTimer() {
  if (rState.player.isRunning) return;
  rState.player.isRunning = true;
  renderCurrentPlayerStep();
  playExerciseAnimation();
  speakCurrentStepIfEnabled();

  rState.player.intervalId = window.setInterval(() => {
    if (rState.player.remainingSeconds > 1) {
      rState.player.remainingSeconds -= 1;
      renderCurrentPlayerStep();
    } else {
      advanceToNextStepOrFinish();
    }
  }, 1000);
}

function updateEffortSliderLabel(val) {
  const labelEl = document.getElementById('eval-effort-label');
  const slider = document.getElementById('eval-effort-slider');
  const map = {
    1: '1 / 5 (Muy suave)',
    2: '2 / 5 (Ligero)',
    3: '3 / 5 (Moderado)',
    4: '4 / 5 (Exigente)',
    5: '5 / 5 (Intenso)'
  };
  const textVal = map[val] || `${val} / 5`;
  if (labelEl) labelEl.textContent = textVal;
  if (slider) {
    slider.setAttribute('aria-valuenow', String(val));
    slider.setAttribute('aria-valuetext', textVal);
  }
}

function updateUtilitySliderLabel(val) {
  const labelEl = document.getElementById('eval-utility-label');
  const slider = document.getElementById('eval-utility-slider');
  const map = {
    1: '1 / 5 (Poco alivio)',
    2: '2 / 5 (Regular)',
    3: '3 / 5 (Útil)',
    4: '4 / 5 (Muy útil)',
    5: '5 / 5 (Excelente)'
  };
  const textVal = map[val] || `${val} / 5`;
  if (labelEl) labelEl.textContent = textVal;
  if (slider) {
    slider.setAttribute('aria-valuenow', String(val));
    slider.setAttribute('aria-valuetext', textVal);
  }
}

function completeRoutineSession() {
  stopRoutineTimer();
  stopExerciseAnimation();
  triggerVisualFlashAlert();
  playCompletionChime();

  if (a11yState.voz_guiada && 'speechSynthesis' in window) {
    const utter = new SpeechSynthesisUtterance(
      '¡Pausa activa completada! Evalúa tu esfuerzo y utilidad percibida.'
    );
    utter.lang = 'es-MX';
    window.speechSynthesis.speak(utter);
  }

  const liveWorkspace = document.getElementById('routine-live-workspace');
  const celebrationBox = document.getElementById('routine-celebration-box');
  const summaryText = document.getElementById('celebration-summary-text');
  const effortSlider = document.getElementById('eval-effort-slider');
  const utilitySlider = document.getElementById('eval-utility-slider');

  if (effortSlider) {
    effortSlider.value = '3';
    updateEffortSliderLabel(3);
  }
  if (utilitySlider) {
    utilitySlider.value = '5';
    updateUtilitySliderLabel(5);
  }

  const routine = rState.activeRoutine;
  rState.lastCompletedRoutine = routine;
  const min = Math.max(1, Math.round((routine.duracion_segundos || 180) / 60));

  if (liveWorkspace) liveWorkspace.classList.add('hidden');
  if (celebrationBox) celebrationBox.classList.remove('hidden');
  if (summaryText) {
    summaryText.textContent = `Completaste "${routine.titulo}" (${min} min). Ajusta el nivel de esfuerzo y utilidad, y pulsa "Guardar Evaluación en mi Historial":`;
  }
  showToast('🔔 ¡Pausa activa completada! Evalúa tu sesión para guardarla en tu historial.', 'success');
}

function advanceToNextStepOrFinish() {
  const steps = Array.isArray(rState.activeRoutine.pasos) ? rState.activeRoutine.pasos : [];
  if (rState.player.stepIndex + 1 < steps.length) {
    rState.player.stepIndex += 1;
    setupCurrentStepTimer();
    renderRoutineStepsList();
    renderCurrentPlayerStep();
    loadCurrentStepAnimation(rState.player.isRunning);
    triggerVisualFlashAlert();
    playCompletionChime();
    speakCurrentStepIfEnabled();
    const nextStep = steps[rState.player.stepIndex];
    if (nextStep) {
      announceToScreenReader(`Ejercicio ${rState.player.stepIndex + 1} de ${steps.length}: ${nextStep.nombre}`);
    }
  } else {
    completeRoutineSession();
  }
}

function loadRoutineIntoPlayer(routine, autoStart = false) {
  if (!routine) return;
  stopRoutineTimer();
  rState.activeRoutine = routine;
  rState.player.stepIndex = 0;

  const liveWorkspace = document.getElementById('routine-live-workspace');
  const celebrationBox = document.getElementById('routine-celebration-box');
  if (liveWorkspace) liveWorkspace.classList.remove('hidden');
  if (celebrationBox) celebrationBox.classList.add('hidden');

  const titleEl = document.getElementById('player-routine-title');
  if (titleEl) titleEl.textContent = routine.titulo;

  setupCurrentStepTimer();
  renderRoutineStepsList();
  renderCurrentPlayerStep();
  loadCurrentStepAnimation(autoStart);
  if (autoStart) startRoutineTimer();
}

function jumpToRoutineStep(stepIndex) {
  if (!rState.activeRoutine || !Array.isArray(rState.activeRoutine.pasos)) return;
  if (stepIndex < 0 || stepIndex >= rState.activeRoutine.pasos.length) return;
  const wasRunning = rState.player.isRunning;
  stopRoutineTimer();
  rState.player.stepIndex = stepIndex;
  setupCurrentStepTimer();
  renderRoutineStepsList();
  renderCurrentPlayerStep();
  loadCurrentStepAnimation(wasRunning);
  if (wasRunning) startRoutineTimer();
}

function generateAndStartCustomRoutine() {
  const { category, durationMin, need } = rState.generator;
  const baseMatch =
    rState.routines.find((r) => r.categoria === category || r.necesidad === need) || rState.routines[0];
  const totalSec = durationMin * 60;
  const baseSteps = Array.isArray(baseMatch.pasos) ? baseMatch.pasos : FALLBACK_ROUTINES[0].pasos;
  const stepDuration = Math.max(20, Math.round(totalSec / Math.max(1, baseSteps.length)));

  loadRoutineIntoPlayer(
    {
      ...baseMatch,
      titulo: `${baseMatch.titulo} (${durationMin} min)`,
      duracion_segundos: totalSec,
      pasos: baseSteps.map((s, idx) => ({
        ...s,
        orden: idx + 1,
        duracion_segundos: stepDuration
      }))
    },
    true
  );
  showToast(`¡Pausa personalizada de ${durationMin} min iniciada!`, 'success');
}

function bindRoutineEvents() {
  document.addEventListener('click', (e) => {
    const startTrigger = e.target.closest('[data-start-routine-id]');
    if (startTrigger) {
      const id = Number(startTrigger.getAttribute('data-start-routine-id'));
      const found = rState.routines.find((r) => Number(r.id) === id) || rState.routines[0];
      loadRoutineIntoPlayer(found, true);
      return;
    }

    const catBtn = e.target.closest('[data-gen-category]');
    if (catBtn) {
      rState.generator.category = catBtn.getAttribute('data-gen-category');
      document.querySelectorAll('[data-gen-category]').forEach((b) => {
        b.classList.toggle('active', b === catBtn);
        b.setAttribute('aria-pressed', String(b === catBtn));
      });
    }

    const durBtn = e.target.closest('[data-gen-duration]');
    if (durBtn) {
      rState.generator.durationMin = Number(durBtn.getAttribute('data-gen-duration'));
      document.querySelectorAll('[data-gen-duration]').forEach((b) => {
        b.classList.toggle('active', b === durBtn);
        b.setAttribute('aria-pressed', String(b === durBtn));
      });
    }

    const needBtn = e.target.closest('[data-gen-need]');
    if (needBtn) {
      rState.generator.need = needBtn.getAttribute('data-gen-need');
      document.querySelectorAll('[data-gen-need]').forEach((b) => {
        b.classList.toggle('active', b === needBtn);
        b.setAttribute('aria-pressed', String(b === needBtn));
      });
    }

    const jumpBtn = e.target.closest('[data-jump-step-index]');
    if (jumpBtn) {
      jumpToRoutineStep(Number(jumpBtn.getAttribute('data-jump-step-index')));
    }
  });

  const formGenerator = document.getElementById('form-routine-generator');
  if (formGenerator) {
    formGenerator.addEventListener('submit', (e) => {
      e.preventDefault();
      generateAndStartCustomRoutine();
    });
  }

  const btnPause = document.getElementById('btn-player-pause');
  const btnNext = document.getElementById('btn-player-next');
  const btnFinish = document.getElementById('btn-player-finish');
  const btnVoice = document.getElementById('btn-player-voice');

  if (btnPause) {
    btnPause.addEventListener('click', () => {
      if (rState.player.isRunning) {
        stopRoutineTimer();
        announceToScreenReader('Rutina en pausa.');
      } else {
        startRoutineTimer();
        announceToScreenReader('Rutina reanudada.');
      }
    });
  }
  if (btnNext) btnNext.addEventListener('click', advanceToNextStepOrFinish);
  if (btnFinish) btnFinish.addEventListener('click', completeRoutineSession);
  if (btnVoice) {
    btnVoice.addEventListener('click', () => {
      a11yState.voz_guiada = !a11yState.voz_guiada;
      syncVoiceButton();
      saveA11yPreferences();
      if (a11yState.voz_guiada) speakCurrentStepIfEnabled();
    });
  }

  const effortSlider = document.getElementById('eval-effort-slider');
  const utilitySlider = document.getElementById('eval-utility-slider');
  const btnSaveEval = document.getElementById('btn-save-routine-eval');

  if (effortSlider) effortSlider.addEventListener('input', () => updateEffortSliderLabel(Number(effortSlider.value)));
  if (utilitySlider) utilitySlider.addEventListener('input', () => updateUtilitySliderLabel(Number(utilitySlider.value)));

  if (btnSaveEval) {
    btnSaveEval.addEventListener('click', async () => {
      const effortVal = effortSlider ? Number(effortSlider.value) : 3;
      const utilityVal = utilitySlider ? Number(utilitySlider.value) : 5;
      const routine = rState.lastCompletedRoutine || rState.activeRoutine;
      const park = getSelectedParkFromStorage();

      try {
        await ApiClient.rutinas.registrarPausa({
          rutina_id: routine.id || 1,
          duracion_segundos: routine.duracion_segundos || 180,
          modo_sentado: Boolean(a11yState.modo_sentado),
          actividad: routine.categoria || rState.generator.category || 'computadora',
          necesidad: routine.necesidad || rState.generator.need || 'relajarse',
          esfuerzo: effortVal,
          calificacion: utilityVal,
          parque_nombre: park ? park.nombre : null,
          parque_osm_id: park ? park.osm_id : null
        });
      } catch (err) {
        showToast(err.message || 'No se pudo guardar tu evaluación en el servidor.', 'error');
        return;
      }

      const celebrationBox = document.getElementById('routine-celebration-box');
      const liveWorkspace = document.getElementById('routine-live-workspace');
      if (celebrationBox) celebrationBox.classList.add('hidden');
      if (liveWorkspace) liveWorkspace.classList.remove('hidden');
      showToast(`¡Evaluación guardada! Esfuerzo: ${effortVal}/5 • Utilidad: ${utilityVal}/5 ⭐`, 'success');
    });
  }
}

async function bootRutinasPage() {
  const auth = await initProtectedPage('rutinas', {
    isTimerBusy: () => Boolean(rState.player.isRunning)
  });
  if (!auth) return;

  onA11yChange(() => {
    renderCurrentPlayerStep();
    syncLottieReducedMotion();
  });
  window.addEventListener('beforeunload', destroyExerciseAnimation);
  syncGeneratorFromDefaults();
  bindRoutineEvents();

  renderRoutinesCatalog();
  loadRoutineIntoPlayer(rState.routines[0], false);

  try {
    const res = await ApiClient.rutinas.catalogo();
    if (res && Array.isArray(res.rutinas) && res.rutinas.length > 0) {
      rState.routines = res.rutinas.map(normalizeRoutineFromServer);
      renderRoutinesCatalog();
      loadRoutineIntoPlayer(rState.routines[0], false);
    }
  } catch (_err) {
    // Mantiene FALLBACK_ROUTINES
  }

  // Soporte de enlace directo desde Parques (`rutinas.html?start=3`)
  const startParam = new URLSearchParams(window.location.search).get('start');
  if (startParam) {
    const target = rState.routines.find((r) => Number(r.id) === Number(startParam)) || rState.routines[0];
    loadRoutineIntoPlayer(target, true);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootRutinasPage);
} else {
  bootRutinasPage();
}
