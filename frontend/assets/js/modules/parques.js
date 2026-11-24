/**
 * Módulo de Ventana: Parques (parques.html) — ES Module
 * Vista satelital híbrida (Esri World Imagery + etiquetas) y mapa estándar OSM,
 * geolocalización en tiempo real, Overpass API con AbortController + debounce + caché en memoria,
 * marcadores de alto contraste vía layerGroup, OSRM y favoritos.
 */
import { PAI_CONFIG, STORAGE_KEYS } from './config.js';
import { ApiClient } from './api-client.js';
import {
  announceToScreenReader,
  escapeHtml,
  saveSelectedParkToStorage,
  showToast
} from './ui.js';
import { initProtectedPage } from './layout.js';

const DEFAULT_SEARCH_RADIUS = 3000;
const MOVEEND_DEBOUNCE_MS = 450;
const MIN_PAN_REQUERY_METERS = 350;
const SESSION_CACHE_GRID_DEG = 0.005; // ~550 m, alineado con OsmCacheService
const SESSION_CACHE_TTL_MS = 5 * 60 * 1000;
const SESSION_CACHE_MAX_ENTRIES = 25;

// Caché en memoria por sesión: volver a zonas recientes es instantáneo (< 1 ms)
const sessionParksCache = new Map();

const mapState = {
  parks: [],
  favoriteParkIds: [],
  favoriteParkDbMap: {},
  activeParkFilter: 'todos',
  activeBaseLayerKey: 'satellite',
  selectedPark: null,
  selectedParkWalkMinutes: 12,
  userCoords: { lat: PAI_CONFIG.DEFAULT_COORDS.lat, lng: PAI_CONFIG.DEFAULT_COORDS.lng },
  hasLiveGeolocation: false,
  shouldCenterOnNextFix: false,
  geoWatchId: null,
  lastParksFetchCoords: null,
  parksRequestSeq: 0,
  activeParksAbortController: null,
  moveEndDebounceId: null,
  isProgrammaticMove: false,
  parksErrorMessage: null,
  isLoadingParks: false,
  leafletMap: null,
  baseLayers: {
    satellite: null,
    standard: null
  },
  markersLayerGroup: null,
  userLocationMarker: null,
  userAccuracyCircle: null,
  routeLayerGroup: null
};

function calculateDistanceMeters(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const toRad = (deg) => (deg * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLon = toRad(lon2 - lon1);
  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
  return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
}

function getSessionCacheKey(lat, lng, radio) {
  const gLat = (Math.round(lat / SESSION_CACHE_GRID_DEG) * SESSION_CACHE_GRID_DEG).toFixed(4);
  const gLng = (Math.round(lng / SESSION_CACHE_GRID_DEG) * SESSION_CACHE_GRID_DEG).toFixed(4);
  const rBucket = Math.max(1500, Math.min(4500, Math.round(radio / 500) * 500));
  return `${gLat}_${gLng}_${rBucket}`;
}

function readSessionParksCache(key) {
  const entry = sessionParksCache.get(key);
  if (!entry) return null;
  if (Date.now() - entry.ts > SESSION_CACHE_TTL_MS) {
    sessionParksCache.delete(key);
    return null;
  }
  return entry;
}

function writeSessionParksCache(key, parks, meta = {}) {
  if (sessionParksCache.size >= SESSION_CACHE_MAX_ENTRIES) {
    const oldestKey = sessionParksCache.keys().next().value;
    if (oldestKey !== undefined) sessionParksCache.delete(oldestKey);
  }
  sessionParksCache.set(key, {
    ts: Date.now(),
    parks,
    meta
  });
}

function loadSavedBaseLayerPreference() {
  try {
    const saved = localStorage.getItem(STORAGE_KEYS.MAP_BASE_LAYER);
    if (saved === 'satellite' || saved === 'standard') {
      return saved;
    }
  } catch (_err) {}
  return 'satellite';
}

function saveBaseLayerPreference(mode) {
  try {
    localStorage.setItem(STORAGE_KEYS.MAP_BASE_LAYER, mode);
  } catch (_err) {}
}

function setMapLoadingIndicator(visible, text = 'Buscando parques cercanos…') {
  const pill = document.getElementById('map-loading-pill');
  const label = document.getElementById('map-loading-pill-text');
  if (label && text) label.textContent = text;
  if (pill) pill.classList.toggle('hidden', !visible);
}

function getFilteredParks() {
  let list = [...mapState.parks];
  const filter = mapState.activeParkFilter;
  if (filter === 'accesible') {
    list = list.filter((p) => p.accesible_silla_ruedas);
  } else if (filter === 'favoritos') {
    list = list.filter(
      (p) =>
        mapState.favoriteParkIds.includes(String(p.id)) ||
        mapState.favoriteParkIds.includes(String(p.osm_id))
    );
  } else if (filter !== 'todos') {
    list = list.filter((p) => Array.isArray(p.equipamiento) && p.equipamiento.includes(filter));
  }
  return list;
}

function renderSelectedParkEmptyState(customTitle, customAddress) {
  const nameEl = document.getElementById('selected-park-name');
  const addrEl = document.getElementById('selected-park-address');
  const distEl = document.getElementById('selected-park-distance');
  const tagsEl = document.getElementById('selected-park-tags');
  const favBtn = document.getElementById('btn-toggle-favorite-park');
  const walkBtn = document.getElementById('btn-park-log-walk');

  if (nameEl) nameEl.textContent = customTitle || 'Cargando parques desde OpenStreetMap...';
  if (addrEl) addrEl.textContent = customAddress || 'Consultando parques públicos y áreas recreativas en tu radio actual.';
  if (distEl) distEl.textContent = 'Distancia calculada por Haversine al recibir resultados de Overpass API.';
  if (tagsEl) tagsEl.innerHTML = '';
  if (favBtn) {
    favBtn.textContent = 'Guardar Parque Favorito ☆';
    favBtn.setAttribute('aria-pressed', 'false');
  }
  if (walkBtn) walkBtn.textContent = 'Registrar Caminata Ida y Vuelta';
}

function createParkDivIcon(park, isSelected) {
  const classes = ['pai-park-pin'];
  if (park.accesible_silla_ruedas) classes.push('pai-park-pin--accessible');
  if (isSelected) classes.push('pai-park-pin--selected');
  const glyph = park.accesible_silla_ruedas ? '♿' : '🌳';
  const size = isSelected ? 36 : 30;
  return window.L.divIcon({
    className: 'pai-park-pin-icon',
    html: `<span class="${classes.join(' ')}" aria-hidden="true">${glyph}</span>`,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
    popupAnchor: [0, -(size / 2)]
  });
}

function createUserDivIcon() {
  return window.L.divIcon({
    className: 'pai-user-pin-icon',
    html: '<span class="pai-user-pin" aria-hidden="true"></span>',
    iconSize: [22, 22],
    iconAnchor: [11, 11],
    popupAnchor: [0, -12]
  });
}

function renderLeafletMarkers() {
  if (!mapState.leafletMap || !mapState.markersLayerGroup || typeof window.L === 'undefined') return;
  mapState.markersLayerGroup.clearLayers();

  const selectedId = mapState.selectedPark ? String(mapState.selectedPark.id) : '';
  getFilteredParks().forEach((park) => {
    const isSelected = selectedId !== '' && String(park.id) === selectedId;
    const marker = window.L.marker([park.latitud, park.longitud], {
      icon: createParkDivIcon(park, isSelected),
      alt: park.nombre,
      title: park.nombre,
      keyboard: true,
      zIndexOffset: isSelected ? 600 : 100
    });
    marker.bindPopup(
      `<strong>${escapeHtml(park.nombre)}</strong><br>${escapeHtml(park.direccion)}<br><small>A ${Number(park.distancia_m || 0)} m de tu posición</small>`
    );
    marker.on('click', () => selectPark(park, true));
    mapState.markersLayerGroup.addLayer(marker);
  });
}

function renderParksCatalog() {
  const container = document.getElementById('parks-catalog-list');
  if (!container) return;

  if (mapState.isLoadingParks && mapState.parks.length === 0) {
    container.innerHTML = `
      <li class="card">
        <h2 class="module-card-title">Consultando OpenStreetMap (Overpass API)...</h2>
        <p class="module-card-desc">Obteniendo parques públicos, áreas recreativas, equipamiento y distancias en tiempo real.</p>
      </li>
    `;
    return;
  }

  if (mapState.parksErrorMessage && mapState.parks.length === 0) {
    container.innerHTML = `
      <li class="card">
        <h2 class="module-card-title">No se pudo conectar con Overpass API</h2>
        <p class="module-card-desc">${escapeHtml(mapState.parksErrorMessage)}</p>
        <button type="button" class="btn btn-primary btn-sm mt-3" data-action="retry-overpass">
          Reintentar consulta en Overpass API
        </button>
      </li>
    `;
    return;
  }

  const filtered = getFilteredParks();
  if (filtered.length === 0) {
    container.innerHTML = `
      <li class="card">
        <h2 class="module-card-title">Sin parques para este filtro o radio</h2>
        <p class="module-card-desc">Prueba seleccionando "Todos los parques" o moviendo el mapa hacia otra zona.</p>
      </li>
    `;
    return;
  }

  container.innerHTML = filtered
    .map((park) => {
      const isSelected = mapState.selectedPark && mapState.selectedPark.id === park.id;
      const isFav =
        mapState.favoriteParkIds.includes(String(park.id)) ||
        mapState.favoriteParkIds.includes(String(park.osm_id));
      const idaMin = Math.max(2, Math.round(park.distancia_m / 75));
      const idaVueltaMin = Math.max(5, Math.min(60, idaMin * 2));

      return `
        <li class="park-item-wrap">
          <button type="button" class="park-item-card ${isSelected ? 'selected' : ''}" data-select-park-id="${escapeHtml(park.id)}" aria-pressed="${isSelected ? 'true' : 'false'}">
            <span class="module-card-header">
              <span class="badge ${park.accesible_silla_ruedas ? 'badge-success' : 'badge-info'}">
                ${park.accesible_silla_ruedas ? '♿ Silla de ruedas (OSM)' : '🌳 Parque OSM'}
              </span>
              <span class="stat-label">${park.distancia_m} m (~${idaVueltaMin} min ida/vuelta) ${isFav ? '★' : ''}</span>
            </span>
            <span class="module-card-title">${escapeHtml(park.nombre)}</span>
            <span class="module-card-desc">${escapeHtml(park.direccion)}</span>
          </button>
        </li>
      `;
    })
    .join('');
}

function setMapViewProgrammatically(lat, lng, zoom) {
  if (!mapState.leafletMap) return;
  mapState.isProgrammaticMove = true;
  mapState.leafletMap.setView([lat, lng], zoom);
  window.setTimeout(() => {
    mapState.isProgrammaticMove = false;
  }, 600);
}

function selectPark(park, panMap = true) {
  if (!park) return;
  mapState.selectedPark = park;
  saveSelectedParkToStorage(park);

  const idaMin = Math.max(2, Math.round(Number(park.distancia_m || 400) / 75));
  const idaYVueltaMin = Math.max(5, Math.min(60, idaMin * 2));
  mapState.selectedParkWalkMinutes = idaYVueltaMin;

  const nameEl = document.getElementById('selected-park-name');
  const addrEl = document.getElementById('selected-park-address');
  const distEl = document.getElementById('selected-park-distance');
  const tagsEl = document.getElementById('selected-park-tags');
  const favBtn = document.getElementById('btn-toggle-favorite-park');
  const walkBtn = document.getElementById('btn-park-log-walk');

  if (nameEl) nameEl.textContent = park.nombre;
  if (addrEl) addrEl.textContent = park.direccion;
  if (distEl) {
    distEl.textContent = `Distancia real: ${park.distancia_m} m (~${idaMin} min ida) • Ida y vuelta: ~${idaYVueltaMin} min`;
  }
  if (walkBtn) {
    walkBtn.textContent = `Registrar Caminata Ida y Vuelta (+${idaYVueltaMin} min)`;
  }

  if (tagsEl) {
    const labelsMap = {
      aparatos_ejercitadores: 'Ejercitadores',
      barras_calistenia: 'Calistenia',
      bancas_descanso: 'Bancas y Sombra',
      andador_peatonal: 'Andador Peatonal',
      rampa_universal: 'Rampa Universal',
      pista_trote: 'Pista de Trote'
    };
    const eqChips = (park.equipamiento || [])
      .map((tag) => `<span class="badge badge-info">${escapeHtml(labelsMap[tag] || tag)}</span>`)
      .join('');
    const wcBadge = park.accesible_silla_ruedas
      ? '<span class="badge badge-success">♿ Accesible Silla de Ruedas (OSM)</span>'
      : `<span class="badge badge-info">Accesibilidad OSM: ${escapeHtml(park.wheelchair || 'no_especificado')}</span>`;
    tagsEl.innerHTML = wcBadge + eqChips;
  }

  if (favBtn) {
    const isFav =
      mapState.favoriteParkIds.includes(String(park.id)) ||
      mapState.favoriteParkIds.includes(String(park.osm_id));
    favBtn.textContent = isFav ? 'Parque Favorito ★ (Guardado)' : 'Guardar Parque Favorito ☆';
    favBtn.setAttribute('aria-pressed', String(isFav));
  }

  if (panMap && mapState.leafletMap) {
    setMapViewProgrammatically(park.latitud, park.longitud, Math.max(16, mapState.leafletMap.getZoom()));
    announceToScreenReader(`Parque seleccionado: ${park.nombre}, a ${park.distancia_m} metros.`);
  }
  renderLeafletMarkers();
  renderParksCatalog();
}

function updateUserLocationMarker(lat, lng, accuracyMeters) {
  if (!mapState.leafletMap || typeof window.L === 'undefined') return;
  const popupLabel = mapState.hasLiveGeolocation
    ? `<strong>Tu ubicación en tiempo real</strong><br>Precisión GPS: ±${Math.round(accuracyMeters || 15)} m`
    : '<strong>Centro de búsqueda actual</strong><br>Activa GPS en "Mi Ubicación" para rastreo continuo';

  if (mapState.userLocationMarker) {
    mapState.userLocationMarker.setLatLng([lat, lng]);
    mapState.userLocationMarker.setPopupContent(popupLabel);
  } else {
    mapState.userLocationMarker = window.L.marker([lat, lng], {
      icon: createUserDivIcon(),
      alt: 'Tu ubicación actual',
      title: 'Tu ubicación actual',
      zIndexOffset: 1000
    }).addTo(mapState.leafletMap);
    mapState.userLocationMarker.bindPopup(popupLabel);
  }

  if (mapState.hasLiveGeolocation && accuracyMeters && accuracyMeters > 0) {
    const clampedRadius = Math.min(800, Math.max(20, accuracyMeters));
    if (mapState.userAccuracyCircle) {
      mapState.userAccuracyCircle.setLatLng([lat, lng]);
      mapState.userAccuracyCircle.setRadius(clampedRadius);
    } else {
      mapState.userAccuracyCircle = window.L.circle([lat, lng], {
        radius: clampedRadius,
        color: '#ffffff',
        weight: 2,
        fillColor: '#f26b50',
        fillOpacity: 0.2
      }).addTo(mapState.leafletMap);
    }
  }
}

function recomputeParkDistances(lat, lng) {
  mapState.parks = mapState.parks
    .map((p) => ({
      ...p,
      distancia_m: calculateDistanceMeters(lat, lng, p.latitud, p.longitud)
    }))
    .sort((a, b) => a.distancia_m - b.distancia_m);

  if (mapState.selectedPark) {
    const updated = mapState.parks.find((p) => String(p.id) === String(mapState.selectedPark.id));
    if (updated) {
      selectPark(updated, false);
      return;
    }
  }
  renderLeafletMarkers();
  renderParksCatalog();
}

function normalizeServerParks(rawParks, refLat, refLng) {
  return rawParks
    .map((p, idx) => {
      const pLat = Number(p.latitud ?? p.lat);
      const pLng = Number(p.longitud ?? p.lng ?? p.lon);
      return {
        id: String(p.id || p.parque_osm_id || p.osm_id || `osm-park-${idx}`),
        osm_id: String(p.parque_osm_id || p.osm_id || p.id || `osm_sc_${idx}`),
        nombre: p.nombre || 'Parque Público (OpenStreetMap)',
        direccion: p.direccion || 'Área pública verificada en OpenStreetMap',
        latitud: pLat,
        longitud: pLng,
        distancia_m: calculateDistanceMeters(refLat, refLng, pLat, pLng),
        wheelchair: String(p.wheelchair || 'no_especificado'),
        accesible_silla_ruedas: Boolean(p.accesible_silla_ruedas ?? p.accesibilidad_silla ?? false),
        iluminacion_nocturna: Boolean(p.iluminacion_nocturna ?? p.iluminacion ?? false),
        sombra_natural: Boolean(p.sombra_natural ?? true),
        equipamiento: Array.isArray(p.equipamiento) ? p.equipamiento : []
      };
    })
    .sort((a, b) => a.distancia_m - b.distancia_m);
}

function applyLoadedParks(normalizedParks, statusText, radio) {
  const statusBadge = document.getElementById('map-status-badge');
  mapState.parks = normalizedParks;
  mapState.parksErrorMessage = null;

  if (mapState.parks.length > 0) {
    const stillPresent =
      mapState.selectedPark &&
      mapState.parks.find((p) => String(p.id) === String(mapState.selectedPark.id));
    selectPark(stillPresent || mapState.parks[0], false);
  } else {
    mapState.selectedPark = null;
    renderLeafletMarkers();
    renderSelectedParkEmptyState(
      'Sin parques públicos en este radio',
      `No se encontraron parques dentro de ${radio} m.`
    );
    renderParksCatalog();
  }

  if (statusBadge && statusText) {
    statusBadge.textContent = statusText;
  }
}

async function fetchNearbyParks(lat, lng, radio = DEFAULT_SEARCH_RADIUS, { forceRefresh = false } = {}) {
  const clampedRadio = Math.max(1500, Math.min(4500, Number(radio || DEFAULT_SEARCH_RADIUS)));
  const cacheKey = getSessionCacheKey(lat, lng, clampedRadio);

  // 1. Acierto instantáneo en caché de sesión en memoria (Map)
  if (!forceRefresh) {
    const cached = readSessionParksCache(cacheKey);
    if (cached) {
      if (mapState.activeParksAbortController) {
        mapState.activeParksAbortController.abort();
        mapState.activeParksAbortController = null;
      }
      mapState.isLoadingParks = false;
      mapState.lastParksFetchCoords = { lat, lng };
      setMapLoadingIndicator(false);
      const recalculated = cached.parks
        .map((p) => ({
          ...p,
          distancia_m: calculateDistanceMeters(mapState.userCoords.lat, mapState.userCoords.lng, p.latitud, p.longitud)
        }))
        .sort((a, b) => a.distancia_m - b.distancia_m);
      applyLoadedParks(
        recalculated,
        `OpenStreetMap Overpass (${recalculated.length} parques • Caché en memoria)`,
        clampedRadio
      );
      return;
    }
  }

  // 2. Cancelación limpia de petición anterior mediante AbortController
  if (mapState.activeParksAbortController) {
    mapState.activeParksAbortController.abort();
  }
  const abortController = new AbortController();
  mapState.activeParksAbortController = abortController;

  const requestSeq = ++mapState.parksRequestSeq;
  const statusBadge = document.getElementById('map-status-badge');
  mapState.isLoadingParks = true;
  mapState.parksErrorMessage = null;
  mapState.lastParksFetchCoords = { lat, lng };
  setMapLoadingIndicator(true, 'Consultando parques en OpenStreetMap…');
  if (statusBadge && mapState.parks.length === 0) {
    statusBadge.textContent = 'Consultando OpenStreetMap Overpass API en vivo...';
  }
  if (mapState.parks.length === 0) {
    renderParksCatalog();
  }

  try {
    const res = await ApiClient.mapa.parquesCercanos(lat, lng, clampedRadio, abortController.signal);
    if (requestSeq !== mapState.parksRequestSeq) return;

    mapState.isLoadingParks = false;
    mapState.activeParksAbortController = null;
    setMapLoadingIndicator(false);

    if (!res || res.ok === false || !Array.isArray(res.parques)) {
      throw new Error((res && res.error) || 'Respuesta inválida de OpenStreetMap Overpass API.');
    }

    const normalized = normalizeServerParks(res.parques, mapState.userCoords.lat, mapState.userCoords.lng);
    writeSessionParksCache(cacheKey, normalized, {
      fuente: res.fuente,
      tiempo_ms: res.tiempo_ms
    });

    const sourceLabel = res.cache_stale
      ? `OpenStreetMap (${normalized.length} parques • Respaldo reciente)`
      : res.fuente_cache
        ? `OpenStreetMap Overpass (${normalized.length} parques • Caché rápido)`
        : `OpenStreetMap Overpass en Vivo (${normalized.length} parques)`;
    applyLoadedParks(normalized, sourceLabel, clampedRadio);
  } catch (err) {
    // Ignorar peticiones canceladas intencionalmente por movimiento del mapa o cambio de consulta
    if (err && (err.isAborted || err.name === 'AbortError')) {
      return;
    }
    if (requestSeq !== mapState.parksRequestSeq) return;

    mapState.isLoadingParks = false;
    mapState.activeParksAbortController = null;
    setMapLoadingIndicator(false);
    mapState.parksErrorMessage = (err && err.message) || 'Error al consultar OpenStreetMap Overpass API.';

    if (mapState.parks.length === 0) {
      renderLeafletMarkers();
      renderSelectedParkEmptyState('No se pudieron cargar parques desde Overpass API', mapState.parksErrorMessage);
      renderParksCatalog();
    }
    if (statusBadge) statusBadge.textContent = 'Error temporal al consultar Overpass API';
    showToast(mapState.parksErrorMessage, 'error');
  }
}

function setMapBaseLayer(mode, persist = true) {
  if (!mapState.leafletMap || typeof window.L === 'undefined') return;
  const nextKey = mode === 'standard' ? 'standard' : 'satellite';
  const nextLayer = mapState.baseLayers[nextKey];
  if (!nextLayer) return;

  Object.keys(mapState.baseLayers).forEach((key) => {
    const layerObj = mapState.baseLayers[key];
    if (layerObj && key !== nextKey && mapState.leafletMap.hasLayer(layerObj)) {
      mapState.leafletMap.removeLayer(layerObj);
    }
  });

  if (!mapState.leafletMap.hasLayer(nextLayer)) {
    nextLayer.addTo(mapState.leafletMap);
  }

  mapState.activeBaseLayerKey = nextKey;
  if (persist) saveBaseLayerPreference(nextKey);

  const mapEl = document.getElementById('leaflet-map');
  if (mapEl) mapEl.setAttribute('data-map-layer', nextKey);

  document.querySelectorAll('[data-map-layer]').forEach((btn) => {
    const isActive = btn.getAttribute('data-map-layer') === nextKey;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-pressed', String(isActive));
  });
}

function startRealtimeGeolocationWatch(centerOnNextFix = false) {
  if (!('geolocation' in navigator)) {
    const statusBadge = document.getElementById('map-status-badge');
    if (statusBadge) statusBadge.textContent = 'Geolocalización no soportada • Mostrando área actual';
    return;
  }
  if (centerOnNextFix) mapState.shouldCenterOnNextFix = true;
  if (mapState.geoWatchId !== null) return;

  mapState.geoWatchId = navigator.geolocation.watchPosition(
    (pos) => {
      const lat = Number(pos.coords.latitude);
      const lng = Number(pos.coords.longitude);
      const accuracy = Number(pos.coords.accuracy || 0);
      const prevCoords = mapState.lastParksFetchCoords;
      const firstFix = !mapState.hasLiveGeolocation;

      mapState.hasLiveGeolocation = true;
      mapState.userCoords = { lat, lng };
      updateUserLocationMarker(lat, lng, accuracy);

      if ((firstFix || mapState.shouldCenterOnNextFix) && mapState.leafletMap) {
        setMapViewProgrammatically(lat, lng, 15);
        mapState.shouldCenterOnNextFix = false;
      }

      const moved = prevCoords ? calculateDistanceMeters(prevCoords.lat, prevCoords.lng, lat, lng) : 9999;
      if (prevCoords === null || moved >= MIN_PAN_REQUERY_METERS || firstFix) {
        fetchNearbyParks(lat, lng, DEFAULT_SEARCH_RADIUS);
      } else if (mapState.parks.length > 0) {
        recomputeParkDistances(lat, lng);
      }
    },
    (geoErr) => {
      const statusBadge = document.getElementById('map-status-badge');
      if (statusBadge && mapState.parks.length === 0) {
        statusBadge.textContent =
          geoErr && geoErr.code === 1
            ? 'Permiso GPS denegado • Consultando OSM en zona actual'
            : 'Señal GPS no disponible • Consultando OSM en zona actual';
      }
    },
    { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 }
  );
}

async function drawPedestrianRoute() {
  const park = mapState.selectedPark;
  if (!park || !mapState.leafletMap || typeof window.L === 'undefined') return;

  if (mapState.routeLayerGroup) {
    mapState.routeLayerGroup.clearLayers();
  }

  let coordsList = [
    [mapState.userCoords.lat, mapState.userCoords.lng],
    [park.latitud, park.longitud]
  ];
  let distM = park.distancia_m || 450;
  let idaMin = Math.max(2, Math.round(distM / 75));

  try {
    const res = await ApiClient.mapa.rutaPeatonal(
      mapState.userCoords.lat,
      mapState.userCoords.lng,
      park.latitud,
      park.longitud
    );
    if (res && res.ruta) {
      const polyCoords = res.ruta.coordenadas || res.ruta.geometria;
      if (Array.isArray(polyCoords) && polyCoords.length > 1) coordsList = polyCoords;
      distM = Number(res.ruta.distancia_metros || distM);
      idaMin = Math.max(2, Number(res.ruta.tiempo_minutos || res.ruta.duracion_minutos || idaMin));
    }
  } catch (_err) {
    // Respaldo de trazo directo
  }

  const idaYVueltaMin = Math.max(5, Math.min(60, idaMin * 2));
  mapState.selectedParkWalkMinutes = idaYVueltaMin;

  // Doble trazo (borde blanco + línea coral) para contraste óptimo sobre vista satelital y mapa estándar
  const casingLine = window.L.polyline(coordsList, {
    color: '#ffffff',
    weight: 8,
    opacity: 0.9
  });
  const routeLine = window.L.polyline(coordsList, {
    color: '#f26b50',
    weight: 4.5,
    opacity: 1,
    dashArray: '8, 6'
  });

  if (mapState.routeLayerGroup) {
    mapState.routeLayerGroup.addLayer(casingLine);
    mapState.routeLayerGroup.addLayer(routeLine);
  }

  mapState.isProgrammaticMove = true;
  mapState.leafletMap.fitBounds(routeLine.getBounds(), { padding: [36, 36] });
  window.setTimeout(() => {
    mapState.isProgrammaticMove = false;
  }, 600);

  const distEl = document.getElementById('selected-park-distance');
  if (distEl) {
    distEl.textContent = `Ruta peatonal de ida: ${distM} m (~${idaMin} min) • Ida y vuelta: ~${idaYVueltaMin} min`;
  }
  const walkBtn = document.getElementById('btn-park-log-walk');
  if (walkBtn) walkBtn.textContent = `Registrar Caminata Ida y Vuelta (+${idaYVueltaMin} min)`;
  showToast(`Ruta trazada hacia ${park.nombre} (${distM} m • ~${idaYVueltaMin} min ida y vuelta).`, 'success');
}

async function logWalkToSelectedPark() {
  const park = mapState.selectedPark;
  if (!park) return;
  const walkMinutes = Math.max(5, Math.min(60, Number(mapState.selectedParkWalkMinutes || 12)));
  try {
    await ApiClient.mapa.registrarCaminata(park, walkMinutes);
    showToast(`🚶 ¡Caminata de ${walkMinutes} min (ida y vuelta) en ${park.nombre} sumada a tu meta diaria!`, 'success');
  } catch (err) {
    showToast(err.message || 'No se pudo registrar la caminata en tu cuenta.', 'error');
  }
}

async function toggleFavoritePark() {
  const park = mapState.selectedPark;
  if (!park) return;
  const parkKey = String(park.osm_id || park.id);
  const idx = mapState.favoriteParkIds.indexOf(parkKey);

  if (idx >= 0) {
    mapState.favoriteParkIds.splice(idx, 1);
    const favDbId = mapState.favoriteParkDbMap[parkKey];
    showToast(`Quitado de favoritos: ${park.nombre}`, 'info');
    if (favDbId) {
      ApiClient.mapa.eliminarFavorito(favDbId).catch(() => {});
      delete mapState.favoriteParkDbMap[parkKey];
    }
  } else {
    mapState.favoriteParkIds.push(parkKey);
    showToast(`Guardado en tus parques favoritos: ${park.nombre}`, 'success');
    ApiClient.mapa
      .guardarFavorito({
        parque_osm_id: parkKey,
        nombre: park.nombre,
        direccion: park.direccion,
        latitud: park.latitud,
        longitud: park.longitud,
        equipamiento: park.equipamiento || [],
        accesible_silla_ruedas: park.accesible_silla_ruedas
      })
      .then((res) => {
        if (res && res.favorito && res.favorito.id) {
          mapState.favoriteParkDbMap[parkKey] = Number(res.favorito.id);
        }
      })
      .catch(() => {});
  }

  try {
    localStorage.setItem(STORAGE_KEYS.FAVORITE_PARKS, JSON.stringify(mapState.favoriteParkIds));
  } catch (_err) {}
  selectPark(park, false);
}

function bindParkEvents() {
  document.addEventListener('click', (e) => {
    const layerBtn = e.target.closest('[data-map-layer]');
    if (layerBtn) {
      const mode = layerBtn.getAttribute('data-map-layer') || 'satellite';
      setMapBaseLayer(mode, true);
      announceToScreenReader(
        mode === 'satellite' ? 'Vista satelital híbrida activada.' : 'Vista de mapa estándar activada.'
      );
      return;
    }

    const parkCard = e.target.closest('[data-select-park-id]');
    if (parkCard) {
      const found = mapState.parks.find((p) => String(p.id) === String(parkCard.getAttribute('data-select-park-id')));
      if (found) selectPark(found, true);
      return;
    }

    if (e.target.closest('[data-action="retry-overpass"]')) {
      const center = mapState.leafletMap ? mapState.leafletMap.getCenter() : mapState.userCoords;
      fetchNearbyParks(center.lat, center.lng, DEFAULT_SEARCH_RADIUS, { forceRefresh: true });
      return;
    }

    const filterChip = e.target.closest('[data-park-filter]');
    if (filterChip) {
      mapState.activeParkFilter = filterChip.getAttribute('data-park-filter') || 'todos';
      document.querySelectorAll('[data-park-filter]').forEach((chip) => {
        chip.classList.toggle('active', chip === filterChip);
        chip.setAttribute('aria-pressed', String(chip === filterChip));
      });
      renderParksCatalog();
      renderLeafletMarkers();
      announceToScreenReader(`Filtro ${filterChip.textContent.trim()}: ${getFilteredParks().length} parque(s).`);
    }
  });

  const formSearch = document.getElementById('form-map-search');
  if (formSearch) {
    formSearch.addEventListener('submit', async (e) => {
      e.preventDefault();
      const query = document.getElementById('input-map-search').value.trim();
      if (!query) return;

      const localMatch = mapState.parks.find(
        (p) =>
          p.nombre.toLowerCase().includes(query.toLowerCase()) ||
          p.direccion.toLowerCase().includes(query.toLowerCase())
      );
      if (localMatch) {
        selectPark(localMatch, true);
        showToast(`Parque encontrado: ${localMatch.nombre}`, 'success');
        return;
      }

      try {
        setMapLoadingIndicator(true, 'Buscando zona en OpenStreetMap…');
        const geoRes = await ApiClient.mapa.buscarDireccion(`${query}, Santa Catarina, Nuevo Leon`);
        setMapLoadingIndicator(false);
        const candidatos = geoRes && (geoRes.lugares || geoRes.resultados);
        if (Array.isArray(candidatos) && candidatos.length > 0) {
          const first = candidatos[0];
          const lat = Number(first.lat);
          const lng = Number(first.lng ?? first.lon);
          mapState.userCoords = { lat, lng };
          if (mapState.leafletMap) {
            setMapViewProgrammatically(lat, lng, 15);
            updateUserLocationMarker(lat, lng, null);
          }
          await fetchNearbyParks(lat, lng, DEFAULT_SEARCH_RADIUS);
          showToast(`Ubicación centrada en: ${first.nombre || query}`, 'info');
        } else {
          showToast('No se encontraron coincidencias en Nominatim / OpenStreetMap.', 'info');
        }
      } catch (err) {
        setMapLoadingIndicator(false);
        showToast((err && err.message) || 'Error al buscar dirección en OpenStreetMap Nominatim.', 'error');
      }
    });
  }

  const btnGeo = document.getElementById('btn-map-geolocate');
  if (btnGeo) {
    btnGeo.addEventListener('click', () => {
      if (!navigator.geolocation) {
        showToast('Tu navegador no soporta geolocalización.', 'error');
        return;
      }
      showToast('Obteniendo ubicación GPS de alta precisión...', 'info');
      mapState.shouldCenterOnNextFix = true;
      startRealtimeGeolocationWatch(true);
      navigator.geolocation.getCurrentPosition(
        async (pos) => {
          const lat = Number(pos.coords.latitude);
          const lng = Number(pos.coords.longitude);
          const acc = Number(pos.coords.accuracy || 0);
          mapState.userCoords = { lat, lng };
          mapState.hasLiveGeolocation = true;
          updateUserLocationMarker(lat, lng, acc);
          setMapViewProgrammatically(lat, lng, 15);
          await fetchNearbyParks(lat, lng, DEFAULT_SEARCH_RADIUS);
          showToast('Ubicación actualizada y parques cercanos recargados.', 'success');
        },
        (err) => {
          showToast(
            err && err.code === 1
              ? 'Permiso de ubicación denegado por el navegador.'
              : 'No se pudo obtener tu posición GPS exacta.',
            'error'
          );
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  const btnStartRoutine = document.getElementById('btn-park-start-routine');
  if (btnStartRoutine) {
    btnStartRoutine.addEventListener('click', () => {
      window.location.assign('rutinas.html?start=3');
    });
  }

  const btnDrawRoute = document.getElementById('btn-park-draw-route');
  if (btnDrawRoute) btnDrawRoute.addEventListener('click', drawPedestrianRoute);

  const btnLogWalk = document.getElementById('btn-park-log-walk');
  if (btnLogWalk) btnLogWalk.addEventListener('click', logWalkToSelectedPark);

  const btnAskActiBot = document.getElementById('btn-park-ask-actibot');
  if (btnAskActiBot) {
    btnAskActiBot.addEventListener('click', () => {
      const park = mapState.selectedPark;
      const prompt = park
        ? `Estoy en ${park.nombre} en Santa Catarina. ¿Qué rutina de 5 minutos me recomiendas aprovechar aquí?`
        : '';
      window.location.assign(prompt ? `chatbot.html?prompt=${encodeURIComponent(prompt)}` : 'chatbot.html');
    });
  }

  const btnFav = document.getElementById('btn-toggle-favorite-park');
  if (btnFav) btnFav.addEventListener('click', toggleFavoritePark);

  window.addEventListener('beforeunload', () => {
    if (mapState.geoWatchId !== null && navigator.geolocation && navigator.geolocation.clearWatch) {
      navigator.geolocation.clearWatch(mapState.geoWatchId);
    }
    if (mapState.moveEndDebounceId) {
      clearTimeout(mapState.moveEndDebounceId);
    }
    if (mapState.activeParksAbortController) {
      mapState.activeParksAbortController.abort();
    }
  });
}

function initLeafletMap() {
  const mapContainer = document.getElementById('leaflet-map');
  if (!mapContainer) return;
  if (typeof window.L === 'undefined') {
    const statusBadge = document.getElementById('map-status-badge');
    if (statusBadge) statusBadge.textContent = 'Mapa interactivo no disponible sin conexión a red';
    renderParksCatalog();
    return;
  }

  mapState.leafletMap = window.L.map('leaflet-map', {
    center: [mapState.userCoords.lat, mapState.userCoords.lng],
    zoom: PAI_CONFIG.DEFAULT_COORDS.zoom,
    zoomControl: true
  });

  // Capa 1: Vista Satelital Híbrida (Esri World Imagery + etiquetas/límites de referencia)
  const esriSatellite = window.L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    {
      maxZoom: 19,
      maxNativeZoom: 18,
      attribution:
        'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, USDA, USGS, AeroGRID, IGN &amp; GIS User Community'
    }
  );
  const esriLabelsOverlay = window.L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
    {
      maxZoom: 19,
      maxNativeZoom: 18,
      opacity: 0.92,
      attribution: 'Etiquetas &copy; Esri'
    }
  );
  mapState.baseLayers.satellite = window.L.layerGroup([esriSatellite, esriLabelsOverlay]);

  // Capa 2: Mapa Estándar OpenStreetMap
  mapState.baseLayers.standard = window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors • Overpass API en Vivo'
  });

  // Capas independientes de marcadores y rutas (persisten al alternar Satelital / Estándar)
  mapState.routeLayerGroup = window.L.layerGroup().addTo(mapState.leafletMap);
  mapState.markersLayerGroup = window.L.layerGroup().addTo(mapState.leafletMap);

  const preferredLayer = loadSavedBaseLayerPreference();
  setMapBaseLayer(preferredLayer, false);

  // Debounce en moveend (~450 ms) con cancelación automática vía AbortController
  mapState.leafletMap.on('moveend', () => {
    if (mapState.isProgrammaticMove) return;
    if (mapState.moveEndDebounceId) {
      clearTimeout(mapState.moveEndDebounceId);
    }
    mapState.moveEndDebounceId = window.setTimeout(() => {
      if (!mapState.leafletMap) return;
      const center = mapState.leafletMap.getCenter();
      const prev = mapState.lastParksFetchCoords;
      const moved = prev ? calculateDistanceMeters(prev.lat, prev.lng, center.lat, center.lng) : 9999;
      if (moved >= MIN_PAN_REQUERY_METERS) {
        fetchNearbyParks(center.lat, center.lng, DEFAULT_SEARCH_RADIUS);
      }
    }, MOVEEND_DEBOUNCE_MS);
  });

  updateUserLocationMarker(mapState.userCoords.lat, mapState.userCoords.lng, null);
  fetchNearbyParks(mapState.userCoords.lat, mapState.userCoords.lng, DEFAULT_SEARCH_RADIUS);
  startRealtimeGeolocationWatch(false);
}

async function bootParquesPage() {
  const auth = await initProtectedPage('parques');
  if (!auth) return;

  renderSelectedParkEmptyState();
  bindParkEvents();

  try {
    const favRes = await ApiClient.mapa.favoritos();
    if (favRes && Array.isArray(favRes.favoritos)) {
      const dbMap = {};
      mapState.favoriteParkIds = favRes.favoritos.map((f) => {
        const osmKey = String(f.parque_osm_id || f.osm_id || f.id);
        if (f.id) dbMap[osmKey] = Number(f.id);
        return osmKey;
      });
      mapState.favoriteParkDbMap = dbMap;
    }
  } catch (_err) {
    // Continuar sin favoritos remotos
  }

  initLeafletMap();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootParquesPage);
} else {
  bootParquesPage();
}
