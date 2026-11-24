/**
 * Módulo de Ventana: ActiBot IA (chatbot.html) — ES Module
 * Asistente conversacional con Google Gemini AI, historial reciente y contexto del parque activo.
 */
import { ApiClient } from './api-client.js';
import { getSelectedParkFromStorage, showToast } from './ui.js';
import { appendChatBubbleToContainer, initProtectedPage } from './layout.js';

const chatHistory = [];

function renderParkContext() {
  const label = document.getElementById('chat-park-context-label');
  if (!label) return;
  const park = getSelectedParkFromStorage();
  label.textContent = park ? `Contexto activo: ${park.nombre}` : 'Contexto activo: Santa Catarina, N.L.';
}

async function sendMainChatMessage(userMessage) {
  const cleanMessage = String(userMessage || '').trim();
  if (!cleanMessage) return;

  const list = document.getElementById('chat-messages-list');
  const btnSend = document.getElementById('btn-chat-send');
  appendChatBubbleToContainer(list, cleanMessage, 'user', false);
  if (btnSend) btnSend.disabled = true;

  try {
    const contextoParque = getSelectedParkFromStorage();
    const res = await ApiClient.chat.enviarMensaje(cleanMessage, contextoParque, chatHistory.slice(-6));
    if (!res || !res.ok || !res.respuesta) {
      throw new Error((res && res.error) || 'La API de Google Gemini no devolvió una respuesta válida.');
    }

    chatHistory.push({ role: 'user', text: cleanMessage });
    chatHistory.push({ role: 'model', text: String(res.respuesta) });
    if (chatHistory.length > 6) {
      chatHistory.splice(0, chatHistory.length - 6);
    }

    appendChatBubbleToContainer(list, String(res.respuesta), 'assistant', true);
  } catch (err) {
    const errorMsg = (err && err.message) || 'No se pudo obtener respuesta de Google Gemini IA en este momento.';
    appendChatBubbleToContainer(
      list,
      `No se pudo consultar a ActiBot (Google Gemini IA): ${errorMsg}`,
      'error',
      false
    );
    showToast(errorMsg, 'error');
  } finally {
    if (btnSend) btnSend.disabled = false;
  }
}

function bindChatbotEvents() {
  const formChat = document.getElementById('form-chatbot');
  const btnClear = document.getElementById('btn-chat-clear');

  document.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-chat-prompt]');
    if (chip) {
      sendMainChatMessage(chip.getAttribute('data-chat-prompt'));
    }
  });

  if (formChat) {
    formChat.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = document.getElementById('chat-message-input');
      if (!input) return;
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      sendMainChatMessage(text);
    });
  }

  if (btnClear) {
    btnClear.addEventListener('click', () => {
      chatHistory.length = 0;
      const list = document.getElementById('chat-messages-list');
      if (list) {
        list.innerHTML = `
          <div class="chat-bubble chat-bubble-assistant">
            <p>Historial limpiado. ¿En qué puedo ayudarte hoy con tu postura o parques de Santa Catarina?</p>
            <div class="chat-action-links">
              <a href="rutinas.html" class="btn btn-primary btn-sm">Ir al Generador de Rutinas 🚀</a>
              <a href="parques.html" class="btn btn-secondary btn-sm">Ver Mapa de Parques 🌳</a>
              <a href="ajustes.html" class="btn btn-accent-outline btn-sm">Ir a Ajustes ⚙️</a>
            </div>
          </div>
        `;
      }
      ApiClient.chat.limpiarHistorial().catch(() => {});
    });
  }
}

async function bootChatbotPage() {
  const auth = await initProtectedPage('chatbot');
  if (!auth) return;

  renderParkContext();
  bindChatbotEvents();

  const promptParam = new URLSearchParams(window.location.search).get('prompt');
  if (promptParam) {
    const input = document.getElementById('chat-message-input');
    if (input) {
      input.value = promptParam;
      input.focus();
    }
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootChatbotPage);
} else {
  bootChatbotPage();
}
