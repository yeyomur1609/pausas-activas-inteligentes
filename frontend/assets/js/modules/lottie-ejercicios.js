/**
 * Módulo de Animaciones Lottie para Ejercicios de Rutinas (ES Module)
 * - Mapea cada ejercicio (por ID de BD o por título/slug normalizado) a su archivo JSON local en assets/animations/.
 * - Gestiona el ciclo de vida estricto con destroy() antes de cada cambio para evitar fugas de memoria.
 * - Maneja errores data_failed con respaldo automático en assets/animations/default.json.
 * - Respeta prefers-reduced-motion y a11yState.reducir_movimiento mediante goToAndStop().
 */
import { prefersReducedMotion } from './ui.js';

export const DEFAULT_ANIMATION_PATH = 'assets/animations/default.json';
const DEFAULT_STATIC_FRAME = 45;

/**
 * Mapeo directo por ID de `rutina_ejercicios` (backend/database/schema.sql).
 */
export const EXERCISE_ANIMATIONS_BY_ID = Object.freeze({
  1: {
    slug: 'regla-20-20-20-parpadeo',
    path: 'assets/animations/regla-20-20-20-parpadeo.json',
    staticFrame: 75,
    alt: 'Demostración visual: enfoque lejano 20-20-20 y parpadeo consciente'
  },
  2: {
    slug: 'liberacion-lateral-cuello',
    path: 'assets/animations/liberacion-lateral-cuello.json',
    staticFrame: 38,
    alt: 'Demostración visual: inclinación lateral suave de cuello y trapecios'
  },
  3: {
    slug: 'circulos-escapulares-apertura',
    path: 'assets/animations/circulos-escapulares-apertura.json',
    staticFrame: 45,
    alt: 'Demostración visual: círculos escapulares de hombros y apertura torácica'
  },
  4: {
    slug: 'descompresion-munecas',
    path: 'assets/animations/descompresion-munecas.json',
    staticFrame: 35,
    alt: 'Demostración visual: extensión y flexión suave de muñecas y túnel carpiano'
  },
  5: {
    slug: 'torsion-columna-toracica',
    path: 'assets/animations/torsion-columna-toracica.json',
    staticFrame: 38,
    alt: 'Demostración visual: torsión suave de columna torácica con pelvis estable'
  },
  6: {
    slug: 'coordinacion-cruzada-enfoque',
    path: 'assets/animations/coordinacion-cruzada-enfoque.json',
    staticFrame: 30,
    alt: 'Demostración visual: toque alterno mano con rodilla opuesta para enfoque mental'
  },
  7: {
    slug: 'respiracion-diafragmatica',
    path: 'assets/animations/respiracion-diafragmatica.json',
    staticFrame: 75,
    alt: 'Demostración visual: respiración diafragmática rítmica 4-4-6'
  },
  8: {
    slug: 'marcha-activacion-parque',
    path: 'assets/animations/marcha-activacion-parque.json',
    staticFrame: 15,
    alt: 'Demostración visual: marcha activa con balanceo de brazos en parque público'
  },
  9: {
    slug: 'apertura-pectoral-aire-libre',
    path: 'assets/animations/apertura-pectoral-aire-libre.json',
    staticFrame: 65,
    alt: 'Demostración visual: apertura pectoral y extensión dorsal al aire libre'
  },
  10: {
    slug: 'movilidad-cadera-tobillos',
    path: 'assets/animations/movilidad-cadera-tobillos.json',
    staticFrame: 25,
    alt: 'Demostración visual: elevaciones de talón y círculos de tobillo junto a banca'
  },
  11: {
    slug: 'estiramiento-lateral-cadena',
    path: 'assets/animations/estiramiento-lateral-cadena.json',
    staticFrame: 38,
    alt: 'Demostración visual: estiramiento lateral con brazo elevado sobre la cabeza'
  },
  12: {
    slug: 'pausa-sensorial-cierre',
    path: 'assets/animations/pausa-sensorial-cierre.json',
    staticFrame: 70,
    alt: 'Demostración visual: pausa sensorial 3-2-1 de respiración y atención plena'
  }
});

/**
 * Reglas de mapeo semántico para rutinas de respaldo (FALLBACK_ROUTINES) o creadas por administradores.
 */
const EXERCISE_KEYWORD_RULES = [
  {
    keywords: ['20-20-20', 'parpadeo', 'visual', 'ocular', 'vista'],
    entry: EXERCISE_ANIMATIONS_BY_ID[1]
  },
  {
    keywords: ['cuello', 'trapecio', 'cervical'],
    entry: EXERCISE_ANIMATIONS_BY_ID[2]
  },
  {
    keywords: ['escapular', 'hombro', 'retraccion escapular'],
    entry: EXERCISE_ANIMATIONS_BY_ID[3]
  },
  {
    keywords: ['muneca', 'carpiano', 'flexores de muneca', 'dedos'],
    entry: EXERCISE_ANIMATIONS_BY_ID[4]
  },
  {
    keywords: ['gato-vaca', 'gato vaca'],
    entry: {
      slug: 'gato-vaca-columna',
      path: 'assets/animations/gato-vaca-columna.json',
      staticFrame: 45,
      alt: 'Demostración visual: flexión y extensión suave gato-vaca de columna'
    }
  },
  {
    keywords: ['torsion', 'rotacion toracica', 'columna toracica'],
    entry: EXERCISE_ANIMATIONS_BY_ID[5]
  },
  {
    keywords: ['coordinacion cruzada', 'hemisferio', 'rodilla'],
    entry: EXERCISE_ANIMATIONS_BY_ID[6]
  },
  {
    keywords: ['respiracion cuadrada', 'box breathing', '4-4-4-4'],
    entry: {
      slug: 'respiracion-cuadrada-enfoque',
      path: 'assets/animations/respiracion-cuadrada-enfoque.json',
      staticFrame: 45,
      alt: 'Demostración visual: respiración cuadrada 4-4-4-4 para enfoque mental'
    }
  },
  {
    keywords: ['diafragmatica', '4-4-6', 'respiracion'],
    entry: EXERCISE_ANIMATIONS_BY_ID[7]
  },
  {
    keywords: ['empuje dinamico', 'flexiones inclinadas', 'banca o pecho'],
    entry: {
      slug: 'empuje-dinamico-banca',
      path: 'assets/animations/empuje-dinamico-banca.json',
      staticFrame: 45,
      alt: 'Demostración visual: empuje dinámico controlado apoyado en banca de parque'
    }
  },
  {
    keywords: ['marcha', 'caminata', 'entorno verde', 'impulso ritmico'],
    entry: EXERCISE_ANIMATIONS_BY_ID[8]
  },
  {
    keywords: ['apertura pectoral', 'extension dorsal', 'pecho'],
    entry: EXERCISE_ANIMATIONS_BY_ID[9]
  },
  {
    keywords: ['cadera', 'tobillo', 'talon', 'pie'],
    entry: EXERCISE_ANIMATIONS_BY_ID[10]
  },
  {
    keywords: ['estiramiento lateral', 'cadena muscular', 'costillas', 'oblicuo'],
    entry: EXERCISE_ANIMATIONS_BY_ID[11]
  },
  {
    keywords: ['sensorial', '3-2-1', 'cierre'],
    entry: EXERCISE_ANIMATIONS_BY_ID[12]
  }
];

const DEFAULT_META = Object.freeze({
  slug: 'default',
  path: DEFAULT_ANIMATION_PATH,
  staticFrame: DEFAULT_STATIC_FRAME,
  alt: 'Demostración visual de pausa activa ergonómica'
});

const lottieState = {
  instance: null,
  container: null,
  statusEl: null,
  meta: DEFAULT_META,
  isLoaded: false,
  isPlayingDesired: false,
  hasFallenBack: false,
  loadToken: 0,
  mediaQueryBound: false
};

function normalizeText(value) {
  return String(value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();
}

export function resolveExerciseAnimation(exercise) {
  if (!exercise) return DEFAULT_META;

  const numId = Number(exercise.id || 0);
  if (numId > 0 && Object.prototype.hasOwnProperty.call(EXERCISE_ANIMATIONS_BY_ID, numId)) {
    return EXERCISE_ANIMATIONS_BY_ID[numId];
  }

  const haystack = normalizeText(
    `${exercise.nombre || ''} ${exercise.titulo || ''} ${exercise.slug || ''}`
  );
  if (haystack) {
    for (const rule of EXERCISE_KEYWORD_RULES) {
      if (rule.keywords.some((kw) => haystack.includes(kw))) {
        return rule.entry;
      }
    }
  }

  return DEFAULT_META;
}

function updateStageUI(stateKey, captionText) {
  if (lottieState.container) {
    lottieState.container.setAttribute('data-lottie-state', stateKey);
    lottieState.container.setAttribute('data-lottie-slug', lottieState.meta.slug);
    lottieState.container.setAttribute('aria-label', lottieState.meta.alt);
  }
  if (lottieState.statusEl && captionText) {
    lottieState.statusEl.textContent = captionText;
  }
}

function clampStaticFrame(anim, preferredFrame) {
  const totalFrames = Number(anim && anim.totalFrames ? anim.totalFrames : 0);
  if (totalFrames > 1) {
    return Math.min(Math.max(0, preferredFrame), Math.floor(totalFrames - 1));
  }
  return Math.max(0, preferredFrame || DEFAULT_STATIC_FRAME);
}

function applyPlaybackState() {
  const anim = lottieState.instance;
  if (!anim) return;

  const reduced = prefersReducedMotion();
  const staticFrame = clampStaticFrame(anim, lottieState.meta.staticFrame);

  if (reduced) {
    anim.goToAndStop(staticFrame, true);
    updateStageUI('reduced-motion', 'Fotograma fijo · Movimiento reducido activo');
    return;
  }

  if (lottieState.isPlayingDesired) {
    anim.play();
    updateStageUI('playing', 'Guía animada en curso');
  } else {
    anim.pause();
    updateStageUI('paused', 'Guía visual en pausa');
  }
}

function bindReducedMotionMediaListener() {
  if (lottieState.mediaQueryBound || !window.matchMedia) return;
  lottieState.mediaQueryBound = true;
  const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
  const onChange = () => syncLottieReducedMotion();
  if (typeof mq.addEventListener === 'function') {
    mq.addEventListener('change', onChange);
  } else if (typeof mq.addListener === 'function') {
    mq.addListener(onChange);
  }
}

function mountAnimationPath(container, path, token) {
  if (!container || !window.lottie || typeof window.lottie.loadAnimation !== 'function') {
    updateStageUI('unavailable', 'Guía visual no disponible');
    return;
  }

  if (lottieState.instance) {
    try {
      lottieState.instance.destroy();
    } catch (_err) {}
    lottieState.instance = null;
  }
  container.innerHTML = '';
  lottieState.isLoaded = false;

  const anim = window.lottie.loadAnimation({
    container,
    renderer: 'svg',
    loop: true,
    autoplay: false,
    path,
    rendererSettings: {
      preserveAspectRatio: 'xMidYMid meet',
      progressiveLoad: false
    }
  });

  lottieState.instance = anim;

  anim.addEventListener('DOMLoaded', () => {
    if (token !== lottieState.loadToken) return;
    lottieState.isLoaded = true;
    const staticFrame = clampStaticFrame(anim, lottieState.meta.staticFrame);

    if (prefersReducedMotion()) {
      anim.goToAndStop(staticFrame, true);
      updateStageUI('reduced-motion', 'Fotograma fijo · Movimiento reducido activo');
    } else if (lottieState.isPlayingDesired) {
      anim.play();
      updateStageUI('playing', 'Guía animada en curso');
    } else {
      // Muestra un fotograma ilustrativo claro mientras el temporizador está listo/pausado
      anim.goToAndStop(staticFrame, true);
      updateStageUI('ready', 'Guía visual lista • Pulsa Iniciar');
    }
  });

  anim.addEventListener('data_failed', () => {
    if (token !== lottieState.loadToken) return;
    if (!lottieState.hasFallenBack && path !== DEFAULT_ANIMATION_PATH) {
      lottieState.hasFallenBack = true;
      lottieState.meta = DEFAULT_META;
      mountAnimationPath(container, DEFAULT_ANIMATION_PATH, token);
    } else {
      updateStageUI('error', 'No se pudo cargar la guía visual');
    }
  });
}

export function destroyExerciseAnimation() {
  lottieState.loadToken += 1;
  lottieState.isLoaded = false;
  if (lottieState.instance) {
    try {
      lottieState.instance.destroy();
    } catch (_err) {}
    lottieState.instance = null;
  }
  if (lottieState.container) {
    lottieState.container.innerHTML = '';
  }
}

export function loadExerciseAnimation(container, exercise, options = {}) {
  if (!container) return null;
  bindReducedMotionMediaListener();

  const { autoplay = false, statusEl = document.getElementById('player-lottie-status') } = options;

  destroyExerciseAnimation();

  const token = lottieState.loadToken;
  const meta = resolveExerciseAnimation(exercise);
  lottieState.container = container;
  lottieState.statusEl = statusEl || null;
  lottieState.meta = meta;
  lottieState.isPlayingDesired = Boolean(autoplay);
  lottieState.hasFallenBack = false;

  updateStageUI('loading', 'Cargando guía visual…');
  mountAnimationPath(container, meta.path, token);

  return meta;
}

export function playExerciseAnimation() {
  lottieState.isPlayingDesired = true;
  if (!lottieState.instance) return;
  if (!lottieState.isLoaded) return;
  applyPlaybackState();
}

export function pauseExerciseAnimation() {
  lottieState.isPlayingDesired = false;
  if (!lottieState.instance) return;
  if (!lottieState.isLoaded) return;
  applyPlaybackState();
}

export function stopExerciseAnimation() {
  lottieState.isPlayingDesired = false;
  const anim = lottieState.instance;
  if (!anim) return;
  if (prefersReducedMotion()) {
    const staticFrame = clampStaticFrame(anim, lottieState.meta.staticFrame);
    anim.goToAndStop(staticFrame, true);
    updateStageUI('reduced-motion', 'Fotograma fijo · Movimiento reducido activo');
  } else {
    anim.stop();
    updateStageUI('stopped', 'Ejercicio completado');
  }
}

export function syncLottieReducedMotion() {
  if (!lottieState.instance || !lottieState.isLoaded) return;
  applyPlaybackState();
}
