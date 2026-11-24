/**
 * Módulo de Ventana: Ajustes, Perfil y Accesibilidad (ajustes.html) — ES Module
 */
import { ApiClient, getSessionUser, setSessionUser } from './api-client.js';
import {
  a11yState,
  applyA11yToDOM,
  buildServerPreferencesPayload,
  exportHistoryRowsToCSV,
  hydrateUserPreferencesFromServer,
  onA11yChange,
  persistLocalA11yPreferences,
  playCompletionChime,
  saveA11yPreferences,
  scheduleErgonomicReminderTimer,
  showToast
} from './ui.js';
import { handleLogout } from './auth.js';
import { initProtectedPage, syncLayoutUserUI } from './layout.js';

let remoteHistory = [];

function resizeImageFileToDataUrl(file, maxWidth, maxHeight, quality) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = (ev) => {
      const img = new Image();
      img.onload = () => {
        let width = img.width;
        let height = img.height;
        if (width > maxWidth || height > maxHeight) {
          const ratio = Math.min(maxWidth / width, maxHeight / height);
          width = Math.round(width * ratio);
          height = Math.round(height * ratio);
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          reject(new Error('Canvas context unavailable'));
          return;
        }
        ctx.drawImage(img, 0, 0, width, height);
        resolve(canvas.toDataURL('image/jpeg', quality));
      };
      img.onerror = reject;
      img.src = ev.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function syncSettingsFormFields() {
  const escala = a11yState.escala_fuente === 'muy-grande' ? 'muy_grande' : a11yState.escala_fuente;
  const chkTemaClaro = document.getElementById('pref-tema-claro');
  const labelTemaActivo = document.getElementById('label-tema-activo');
  const chkContrast = document.getElementById('pref-alto-contraste');
  const chkReadable = document.getElementById('pref-fuente-legible');
  const chkUnderline = document.getElementById('pref-subrayar-enlaces');
  const chkSeated = document.getElementById('pref-modo-sentado');
  const chkMotion = document.getElementById('pref-reducir-movimiento');
  const chkSound = document.getElementById('pref-alertas-sonoras');
  const chkChatbot = document.getElementById('pref-chatbot-habilitado');
  const selFont = document.getElementById('pref-escala-fuente');
  const selFreq = document.getElementById('pref-frecuencia-min');
  const selUbicacionDef = document.getElementById('pref-ubicacion-defecto');
  const selTiempoDef = document.getElementById('pref-tiempo-defecto');
  const selObjetivoDef = document.getElementById('pref-objetivo-defecto');

  if (chkTemaClaro) chkTemaClaro.checked = Boolean(a11yState.tema_claro);
  if (labelTemaActivo) {
    labelTemaActivo.textContent = a11yState.tema_claro
      ? '☀️ Modo Claro Activo (Desmarca para Modo Oscuro)'
      : '🌙 Modo Oscuro Activo (Marca para Modo Claro)';
  }
  if (chkContrast) chkContrast.checked = Boolean(a11yState.alto_contraste);
  if (chkReadable) chkReadable.checked = Boolean(a11yState.fuente_legible);
  if (chkUnderline) chkUnderline.checked = Boolean(a11yState.subrayar_enlaces);
  if (chkSeated) chkSeated.checked = Boolean(a11yState.modo_sentado);
  if (chkMotion) chkMotion.checked = Boolean(a11yState.reducir_movimiento);
  if (chkSound) chkSound.checked = Boolean(a11yState.alertas_sonoras);
  if (chkChatbot) chkChatbot.checked = Boolean(a11yState.chatbot_habilitado);
  if (selFont) selFont.value = escala || 'normal';
  if (selFreq) selFreq.value = String(a11yState.frecuencia_pausa_min || 45);
  if (selUbicacionDef) selUbicacionDef.value = a11yState.ubicacion_defecto || 'computadora';
  if (selTiempoDef) selTiempoDef.value = String(a11yState.tiempo_defecto || 3);
  if (selObjetivoDef) selObjetivoDef.value = a11yState.objetivo_defecto || 'relajarse';

  document.querySelectorAll('[data-avatar-choice]').forEach((btn) => {
    const isSelected = btn.getAttribute('data-avatar-choice') === a11yState.avatar;
    btn.classList.toggle('active', isSelected);
    btn.setAttribute('aria-pressed', String(isSelected));
  });
}

function syncSettingsAccountUI(user = getSessionUser()) {
  const profileCard = document.getElementById('settings-profile-card');
  const profileNombre = document.getElementById('profile-nombre');
  const profileCorreo = document.getElementById('profile-correo');
  const profileCarrera = document.getElementById('profile-carrera');
  const profileMeta = document.getElementById('profile-meta-diaria');
  const settingsVerifyCard = document.getElementById('settings-verification-card');
  const verifyBoxVerified = document.getElementById('verify-box-verified');
  const verifyBoxPending = document.getElementById('verify-box-pending');
  const verifyConfirmedEmailText = document.getElementById('verify-confirmed-email-text');
  const verifyCountdownText = document.getElementById('verify-countdown-text');

  if (!user) return;
  if (profileCard) profileCard.classList.remove('hidden');
  if (profileNombre) profileNombre.value = user.nombre || '';
  if (profileCorreo) profileCorreo.value = user.email || '';
  if (profileCarrera && user.ocupacion) profileCarrera.value = user.ocupacion;
  if (profileMeta) profileMeta.value = String(a11yState.meta_diaria_minutos || 15);

  const isVerified = Boolean(user.email_verificado);
  if (settingsVerifyCard) {
    settingsVerifyCard.classList.remove('hidden');
    settingsVerifyCard.classList.toggle('verified', isVerified);
    settingsVerifyCard.classList.toggle('pending', !isVerified);
  }
  if (isVerified) {
    if (verifyBoxVerified) verifyBoxVerified.classList.remove('hidden');
    if (verifyBoxPending) verifyBoxPending.classList.add('hidden');
    if (verifyConfirmedEmailText) {
      verifyConfirmedEmailText.textContent = `Correo confirmado (${user.email}). Tu cuenta y tu progreso están respaldados permanentemente.`;
    }
  } else {
    const dias = user.dias_restantes_verificacion ?? 7;
    const horas = user.horas_restantes_verificacion ?? 168;
    if (verifyBoxVerified) verifyBoxVerified.classList.add('hidden');
    if (verifyBoxPending) verifyBoxPending.classList.remove('hidden');
    if (verifyCountdownText) {
      verifyCountdownText.textContent = `Enviamos un enlace y código de confirmación a ${user.email}. Te quedan ${dias} día(s) (${horas} horas restantes) para verificar tu correo antes de que la cuenta se elimine automáticamente.`;
    }
  }
}

function bindSettingsEvents() {
  document.addEventListener('click', (e) => {
    const avatarBtn = e.target.closest('[data-avatar-choice]');
    if (avatarBtn) {
      a11yState.avatar = avatarBtn.getAttribute('data-avatar-choice') || '🧘';
      syncSettingsFormFields();
      saveA11yPreferences();
      syncLayoutUserUI();
    }
  });

  const inputPhoto = document.getElementById('input-profile-photo');
  if (inputPhoto) {
    inputPhoto.addEventListener('change', async (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      if (!file.type.startsWith('image/')) {
        showToast('Selecciona un archivo de imagen válido (JPG, PNG o WebP).', 'error');
        return;
      }
      try {
        a11yState.foto_perfil = await resizeImageFileToDataUrl(file, 400, 400, 0.85);
        saveA11yPreferences();
        applyA11yToDOM();
        showToast('📷 Foto de perfil optimizada y guardada.', 'success');
      } catch (_err) {
        showToast('No se pudo procesar la imagen seleccionada.', 'error');
      }
    });
  }

  const btnClearPhoto = document.getElementById('btn-clear-profile-photo');
  if (btnClearPhoto) {
    btnClearPhoto.addEventListener('click', () => {
      a11yState.foto_perfil = '';
      saveA11yPreferences();
      applyA11yToDOM();
      showToast('Usando tu emoji avatar seleccionado.', 'info');
    });
  }

  const btnResendVerify = document.getElementById('btn-settings-resend-verify');
  if (btnResendVerify) {
    btnResendVerify.addEventListener('click', async () => {
      const user = getSessionUser();
      if (!user || !user.email) return;
      try {
        const res = await ApiClient.auth.reenviarCodigo(user.email);
        showToast(res.mensaje || 'Correo de confirmación reenviado.', 'success');
      } catch (err) {
        showToast(err.message || 'No se pudo reenviar el correo.', 'error');
      }
    });
  }

  const formInlineOtp = document.getElementById('form-settings-inline-otp');
  if (formInlineOtp) {
    formInlineOtp.addEventListener('submit', async (e) => {
      e.preventDefault();
      const user = getSessionUser();
      if (!user || !user.email) return;
      const codeInput = document.getElementById('inline-otp-code');
      const code = codeInput ? codeInput.value.trim() : '';
      if (!code) return;
      try {
        const res = await ApiClient.auth.verificarEmail(user.email, code);
        const updatedUser = (res && res.usuario) || { ...user, email_verificado: true };
        setSessionUser(updatedUser);
        if (codeInput) codeInput.value = '';
        syncLayoutUserUI(updatedUser);
        syncSettingsAccountUI(updatedUser);
        showToast('🎉 ¡Tu correo electrónico ha sido confirmado exitosamente!', 'success');
      } catch (err) {
        showToast(err.message || 'Código inválido o expirado.', 'error');
      }
    });
  }

  const formA11y = document.getElementById('form-settings-a11y');
  if (formA11y) {
    formA11y.addEventListener('submit', (e) => {
      e.preventDefault();
      a11yState.tema_claro = document.getElementById('pref-tema-claro').checked;
      a11yState.alto_contraste = document.getElementById('pref-alto-contraste').checked;
      a11yState.fuente_legible = document.getElementById('pref-fuente-legible').checked;
      a11yState.subrayar_enlaces = document.getElementById('pref-subrayar-enlaces').checked;
      a11yState.modo_sentado = document.getElementById('pref-modo-sentado').checked;
      a11yState.reducir_movimiento = document.getElementById('pref-reducir-movimiento').checked;
      a11yState.alertas_sonoras = document.getElementById('pref-alertas-sonoras').checked;
      a11yState.chatbot_habilitado = document.getElementById('pref-chatbot-habilitado').checked;
      a11yState.escala_fuente = document.getElementById('pref-escala-fuente').value;
      a11yState.frecuencia_pausa_min = Number(document.getElementById('pref-frecuencia-min').value || 45);
      a11yState.ubicacion_defecto = document.getElementById('pref-ubicacion-defecto').value;
      a11yState.tiempo_defecto = Number(document.getElementById('pref-tiempo-defecto').value || 3);
      a11yState.objetivo_defecto = document.getElementById('pref-objetivo-defecto').value;

      applyA11yToDOM();
      saveA11yPreferences();
      scheduleErgonomicReminderTimer();
      showToast('Preferencias de accesibilidad y rutina guardadas.', 'success');
    });
  }

  const btnTestChime = document.getElementById('btn-test-chime');
  if (btnTestChime) {
    btnTestChime.addEventListener('click', () => {
      playCompletionChime(true);
      showToast('🔔 Reproduciendo acorde armónico de 3 tonos.', 'info');
    });
  }

  const btnEnableNotif = document.getElementById('btn-enable-notifications');
  if (btnEnableNotif) {
    btnEnableNotif.addEventListener('click', async () => {
      if (!('Notification' in window)) {
        showToast('Tu navegador no soporta notificaciones de escritorio.', 'error');
        return;
      }
      const perm = await Notification.requestPermission();
      showToast(
        perm === 'granted' ? '⏰ Notificaciones ergonómicas activadas.' : 'Permiso de notificaciones denegado.',
        perm === 'granted' ? 'success' : 'info'
      );
    });
  }

  const btnExportCsv = document.getElementById('btn-export-csv');
  if (btnExportCsv) {
    btnExportCsv.addEventListener('click', () => exportHistoryRowsToCSV(remoteHistory));
  }

  const btnSendEmailSummary = document.getElementById('btn-send-email-summary');
  if (btnSendEmailSummary) {
    btnSendEmailSummary.addEventListener('click', async () => {
      const user = getSessionUser();
      try {
        const res = await ApiClient.usuarios.enviarResumenEmail();
        showToast(res.mensaje || `📧 Resumen de progreso enviado a ${user ? user.email : 'tu correo'}.`, 'success');
      } catch (err) {
        showToast(err.message || 'No se pudo enviar el resumen por correo.', 'error');
      }
    });
  }

  const formProfile = document.getElementById('form-settings-profile');
  if (formProfile) {
    formProfile.addEventListener('submit', async (e) => {
      e.preventDefault();
      const nombre = document.getElementById('profile-nombre').value.trim();
      const ocupacion = document.getElementById('profile-carrera').value;
      const metaEl = document.getElementById('profile-meta-diaria');
      const nextMetaMin = metaEl ? Number(metaEl.value || 15) : Number(a11yState.meta_diaria_minutos || 15);
      try {
        const res = await ApiClient.usuarios.actualizarPerfil({
          nombre,
          ocupacion,
          meta_diaria_min: nextMetaMin,
          preferencias: { ...buildServerPreferencesPayload(), meta_diaria_minutos: nextMetaMin }
        });
        a11yState.meta_diaria_minutos = nextMetaMin;
        persistLocalA11yPreferences();
        if (res && res.usuario) {
          setSessionUser(res.usuario);
          hydrateUserPreferencesFromServer(res.usuario);
          syncLayoutUserUI(res.usuario);
          syncSettingsAccountUI(res.usuario);
        }
        showToast('Perfil y meta diaria actualizados.', 'success');
      } catch (err) {
        showToast(err.message || 'No se pudo actualizar el perfil.', 'error');
      }
    });
  }

  const formChangeEmail = document.getElementById('form-settings-change-email');
  if (formChangeEmail) {
    formChangeEmail.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = document.getElementById('change-email-input');
      const pwdInput = document.getElementById('change-email-password');
      const nuevoEmail = input ? input.value.trim() : '';
      const passwordActual = pwdInput ? pwdInput.value : '';
      if (!nuevoEmail || !passwordActual) return;
      try {
        const res = await ApiClient.usuarios.cambiarEmail(nuevoEmail, passwordActual);
        if (res && res.usuario) {
          setSessionUser(res.usuario);
          syncLayoutUserUI(res.usuario);
          syncSettingsAccountUI(res.usuario);
        }
        if (input) input.value = '';
        if (pwdInput) pwdInput.value = '';
        showToast(res.mensaje || 'Correo actualizado. Revisa tu bandeja para confirmarlo.', 'success');
      } catch (err) {
        showToast(err.message || 'No se pudo cambiar el correo electrónico.', 'error');
      }
    });
  }

  const formPassword = document.getElementById('form-settings-password');
  if (formPassword) {
    formPassword.addEventListener('submit', async (e) => {
      e.preventDefault();
      const actual = document.getElementById('pwd-current').value;
      const nueva = document.getElementById('pwd-new').value;
      const confirmacion = document.getElementById('pwd-confirm').value;
      if (nueva !== confirmacion) {
        showToast('La nueva contraseña y su confirmación no coinciden.', 'error');
        return;
      }
      try {
        const res = await ApiClient.usuarios.cambiarPassword(actual, nueva);
        formPassword.reset();
        showToast(res.mensaje || 'Contraseña actualizada correctamente.', 'success');
      } catch (err) {
        showToast(err.message || 'Error al actualizar la contraseña.', 'error');
      }
    });
  }

  const btnLogoutSettings = document.getElementById('btn-logout-settings');
  if (btnLogoutSettings) btnLogoutSettings.addEventListener('click', handleLogout);

  const formDelete = document.getElementById('form-settings-delete');
  if (formDelete) {
    formDelete.addEventListener('submit', async (e) => {
      e.preventDefault();
      const confirmText = document.getElementById('delete-account-confirm').value.trim();
      const pwdInput = document.getElementById('delete-account-password');
      const passwordActual = pwdInput ? pwdInput.value : '';
      try {
        const res = await ApiClient.usuarios.eliminarCuenta(confirmText, passwordActual);
        formDelete.reset();
        showToast(res.mensaje || 'Tu cuenta ha sido eliminada.', 'info');
        window.location.replace('login.html');
      } catch (err) {
        showToast(err.message || 'Verifica tu contraseña y escribe ELIMINAR para confirmar.', 'error');
      }
    });
  }
}

async function bootAjustesPage() {
  const auth = await initProtectedPage('ajustes');
  if (!auth) return;

  onA11yChange(() => syncSettingsFormFields());
  syncSettingsFormFields();
  syncSettingsAccountUI(auth.user);
  bindSettingsEvents();

  try {
    const res = await ApiClient.usuarios.perfil();
    if (res && res.usuario) {
      setSessionUser(res.usuario);
      syncLayoutUserUI(res.usuario);
      syncSettingsAccountUI(res.usuario);
    }
    if (res && res.estadisticas && Array.isArray(res.estadisticas.historial)) {
      remoteHistory = res.estadisticas.historial;
    }
  } catch (_err) {}
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAjustesPage);
} else {
  bootAjustesPage();
}
