/**
 * Widget de chat flotante — Agente Actas de Entrega
 * Vanilla JS, encapsulado en IIFE para no contaminar el scope global
 * (importante al inyectar el script en WordPress).
 */
(function () {
  "use strict";

  /* ---------- Referencias al DOM (solo clases namespaced) ---------- */
  var widget = document.querySelector(".ua-chat-widget");
  var ventana = document.querySelector(".ua-chat-window");
  var areaMensajes = document.querySelector(".ua-chat-messages");
  var formulario = document.querySelector(".ua-chat-input-area");
  var campo = document.querySelector(".ua-chat-field");
  var botonEnviar = document.querySelector(".ua-chat-send");
  var botonFlotante = document.querySelector(".ua-chat-btn");
  var botonCerrar = document.querySelector(".ua-chat-close");
  var botonNueva = document.querySelector(".ua-chat-new");
  var tooltip = document.getElementById("ua-chat-tooltip");

  if (!widget || !ventana || !areaMensajes || !formulario || !campo) {
    return;
  }

  /**
   * Memoria de la sesión.
   * Estructura compatible con APIs tipo Gemini:
   * { role: 'user' | 'model', parts: [{ text: '...' }] }
   */
  var chatHistory = [];

  var MENSAJE_BIENVENIDA =
    "¡Hola! Soy tu Tutor IA del curso de Actas de Entrega. ¿En qué te puedo ayudar hoy?";

  var TOOLTIP_LECCION =
    "¿Dudas con esta lección? Estoy aquí para ayudarte.";
  var TOOLTIP_CUESTIONARIO =
    "¿Dudas con este cuestionario? Estoy aquí para ayudarte.";

  /** Curso LearnDash Actas de Entrega — clave de persistencia por curso */
  var UA_CHAT_COURSE_ID = 46067;
  var UA_CHAT_STORAGE_KEY = "ua-chat-history-" + UA_CHAT_COURSE_ID;
  var UA_CHAT_SESSION_KEY = "ua-chat-session-" + UA_CHAT_COURSE_ID;
  var UA_CHAT_MAX_MENSAJES = 24;

  /** session_id dinámico: wp-{userId}-{timestamp} (persistido entre páginas) */
  var sessionId = null;

  var estaCargando = false;

  /* Temporizadores de la coreografía visual (tooltip) */
  let tooltipShowTimer;
  let tooltipHideTimer;

  /* ============================================================
     Contexto de página (lección vs cuestionario LearnDash)
     ============================================================ */

  /** Detecta si estamos en un cuestionario LearnDash / WP Pro Quiz. */
  function esPaginaCuestionario() {
    var body = document.body;
    if (!body) {
      return false;
    }

    if (
      body.classList.contains("single-sfwd-quiz") ||
      body.classList.contains("sfwd-quiz")
    ) {
      return true;
    }

    /* BuddyBoss / LearnDash a veces marcan el post type en class */
    if (/\bsfwd-quiz\b/.test(body.className)) {
      return true;
    }

    if (
      document.querySelector(
        ".wpProQuiz_content, .ld-quiz-status, #learndash_post_quiz, .learndash-wrapper .wpProQuiz_content"
      )
    ) {
      return true;
    }

    return false;
  }

  /**
   * Ajusta posición del FAB y texto del tooltip según el contexto.
   * En quiz sube el widget para no tapar el botón Next.
   */
  function aplicarContextoPagina() {
    var enQuiz = esPaginaCuestionario();

    if (enQuiz) {
      widget.classList.add("ua-chat-en-quiz");
    } else {
      widget.classList.remove("ua-chat-en-quiz");
    }

    if (tooltip) {
      tooltip.textContent = enQuiz ? TOOLTIP_CUESTIONARIO : TOOLTIP_LECCION;
    }
  }

  /* ============================================================
     Persistencia localStorage (sobrevive al cambiar de página)
     ============================================================ */

  /**
   * Construye session_id: wp-{userId}-{timestamp}.
   * userId viene de uaChatConfig (inyectado por PHP); fallback "0" en local.
   * @returns {string}
   */
  function crearSessionId() {
    var userId = 0;
    if (
      typeof window.uaChatConfig !== "undefined" &&
      window.uaChatConfig.userId
    ) {
      userId = parseInt(window.uaChatConfig.userId, 10) || 0;
    }
    return "wp-" + userId + "-" + Date.now();
  }

  /** Guarda sessionId en localStorage. */
  function guardarSessionId() {
    try {
      if (sessionId) {
        window.localStorage.setItem(UA_CHAT_SESSION_KEY, sessionId);
      }
    } catch (error) {
      /* Quota o modo privado */
    }
  }

  /**
   * Carga sessionId guardado o crea uno nuevo.
   * @returns {string}
   */
  function obtenerOCrearSessionId() {
    try {
      var guardado = window.localStorage.getItem(UA_CHAT_SESSION_KEY);
      if (guardado && /^wp-\d+-\d{10,16}$/.test(guardado)) {
        return guardado;
      }
    } catch (error) {
      /* ignore */
    }
    var nuevo = crearSessionId();
    sessionId = nuevo;
    guardarSessionId();
    return nuevo;
  }

  /**
   * Recorta el historial a los últimos N mensajes.
   * @param {Array} historial
   * @returns {Array}
   */
  function recortarHistorial(historial) {
    if (!Array.isArray(historial)) {
      return [];
    }
    if (historial.length <= UA_CHAT_MAX_MENSAJES) {
      return historial;
    }
    return historial.slice(historial.length - UA_CHAT_MAX_MENSAJES);
  }

  /** Guarda chatHistory en localStorage (máx. 24 mensajes). */
  function guardarHistorial() {
    try {
      chatHistory = recortarHistorial(chatHistory);
      window.localStorage.setItem(
        UA_CHAT_STORAGE_KEY,
        JSON.stringify(chatHistory)
      );
    } catch (error) {
      /* Quota o modo privado: el chat sigue en memoria de esta página */
    }
  }

  /**
   * Lee y valida el historial guardado.
   * @returns {Array|null}
   */
  function cargarHistorialGuardado() {
    try {
      var crudo = window.localStorage.getItem(UA_CHAT_STORAGE_KEY);
      if (!crudo) {
        return null;
      }

      var data = JSON.parse(crudo);
      if (!Array.isArray(data) || data.length === 0) {
        return null;
      }

      var limpio = [];
      for (var i = 0; i < data.length; i++) {
        var item = data[i];
        if (!item || (item.role !== "user" && item.role !== "model")) {
          continue;
        }
        if (
          !item.parts ||
          !item.parts[0] ||
          typeof item.parts[0].text !== "string"
        ) {
          continue;
        }
        limpio.push({
          role: item.role,
          parts: [{ text: item.parts[0].text }]
        });
      }

      limpio = recortarHistorial(limpio);
      return limpio.length > 0 ? limpio : null;
    } catch (error) {
      return null;
    }
  }

  /** Pinta en pantalla todo el historial restaurado (sin volver a guardar). */
  function pintarHistorialCompleto(historial) {
    for (var i = 0; i < historial.length; i++) {
      var msg = historial[i];
      pintarMensaje(msg.role, msg.parts[0].text);
    }
  }

  /* ============================================================
     Utilidades
     ============================================================ */

  /** Escapa HTML para evitar XSS al pintar texto del usuario o del modelo. */
  function escaparHtml(texto) {
    return String(texto)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  /**
   * Convierte markdown básico a HTML seguro para la burbuja:
   * - # / ## / ### títulos → estilo de encabezado (sin mostrar #)
   * - **negrita** → <strong>
   * - listas * - • → viñetas
   * - saltos de línea \n → <br>
   */
  function renderizarMarkdown(texto) {
    var seguro = escaparHtml(texto);

    /* Encabezados Markdown → título visual sin los # */
    seguro = seguro.replace(
      /^#{1,6}\s+(.+)$/gm,
      '<span class="ua-chat-md-heading">$1</span>'
    );

    /* Negritas */
    seguro = seguro.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");

    /* Viñetas: *, -, • o ^ al inicio de línea */
    seguro = seguro.replace(
      /^[ \t]*[\*\-\u2022\^]\s+(.+)$/gm,
      '<span class="ua-chat-md-li">$1</span>'
    );

    seguro = seguro.replace(/\n/g, "<br>");
    return seguro;
  }

  /** Desplaza el área de mensajes al final con comportamiento suave. */
  function scrollAlFinal() {
    areaMensajes.scrollTo({
      top: areaMensajes.scrollHeight,
      behavior: "smooth"
    });
  }

  /** Ajusta la altura del textarea al contenido (máx. limitado por CSS). */
  function ajustarAlturaCampo() {
    campo.style.height = "auto";
    campo.style.height = campo.scrollHeight + "px";
  }

  /* ============================================================
     Renderizado de burbujas
     ============================================================ */

  /**
   * Pinta un mensaje en el hilo.
   * @param {'user'|'model'} rol
   * @param {string} texto
   * @param {string} [messageId] ID del turno (Analytics 360); solo bot.
   * @returns {HTMLElement} fila insertada
   */
  function pintarMensaje(rol, texto, messageId) {
    var fila = document.createElement("div");
    var esUsuario = rol === "user";

    fila.className =
      "ua-chat-row " + (esUsuario ? "ua-chat-row-user" : "ua-chat-row-bot");

    var burbuja = document.createElement("div");
    burbuja.className =
      "ua-chat-bubble " +
      (esUsuario ? "ua-chat-bubble-user" : "ua-chat-bubble-bot");
    burbuja.innerHTML = renderizarMarkdown(texto);

    if (!esUsuario && messageId) {
      burbuja.setAttribute("data-message-id", messageId);
    }

    fila.appendChild(burbuja);

    if (!esUsuario && messageId) {
      fila.appendChild(crearBarraFeedback(messageId));
    }

    areaMensajes.appendChild(fila);
    scrollAlFinal();

    return fila;
  }

  /**
   * Barra 👍/👎 bajo la burbuja del bot (no se guarda en localStorage).
   * @param {string} messageId
   * @returns {HTMLElement}
   */
  function crearBarraFeedback(messageId) {
    var barra = document.createElement("div");
    barra.className = "ua-chat-feedback";
    barra.setAttribute("data-message-id", messageId);

    var btnUp = document.createElement("button");
    btnUp.type = "button";
    btnUp.className = "ua-chat-feedback-btn ua-chat-feedback-up";
    btnUp.setAttribute("aria-label", "Respuesta útil");
    btnUp.title = "Útil";
    btnUp.textContent = "👍";

    var btnDown = document.createElement("button");
    btnDown.type = "button";
    btnDown.className = "ua-chat-feedback-btn ua-chat-feedback-down";
    btnDown.setAttribute("aria-label", "Respuesta no útil");
    btnDown.title = "No útil";
    btnDown.textContent = "👎";

    btnUp.addEventListener("click", function () {
      enviarFeedback(messageId, 1, barra, btnUp, btnDown);
    });
    btnDown.addEventListener("click", function () {
      enviarFeedback(messageId, -1, barra, btnDown, btnUp);
    });

    barra.appendChild(btnUp);
    barra.appendChild(btnDown);
    return barra;
  }

  /**
   * @param {string} messageId
   * @param {1|-1} score
   * @param {HTMLElement} barra
   * @param {HTMLElement} btnElegido
   * @param {HTMLElement} btnOtro
   */
  async function enviarFeedback(messageId, score, barra, btnElegido, btnOtro) {
    if (
      !messageId ||
      barra.classList.contains("is-voted") ||
      typeof window.uaChatConfig === "undefined"
    ) {
      return;
    }

    btnElegido.disabled = true;
    btnOtro.disabled = true;

    try {
      var formData = new URLSearchParams();
      formData.append("action", window.uaChatConfig.actionFeedback);
      formData.append("nonce", window.uaChatConfig.nonce);
      formData.append("message_id", messageId);
      formData.append("session_id", sessionId || obtenerOCrearSessionId());
      formData.append("feedback_score", String(score));

      var response = await fetch(window.uaChatConfig.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: formData.toString()
      });

      var result = await response.json();
      if (!result.success) {
        throw new Error(
          (result.data && result.data.message) || "No se pudo enviar el feedback"
        );
      }

      barra.classList.add("is-voted");
      btnElegido.classList.add("is-selected");
      var gracias = document.createElement("span");
      gracias.className = "ua-chat-feedback-thanks";
      gracias.textContent = "Gracias";
      barra.appendChild(gracias);
    } catch (error) {
      btnElegido.disabled = false;
      btnOtro.disabled = false;
    }
  }

  /** Inserta la burbuja de "Typing..." del bot y la devuelve para poder retirarla. */
  function mostrarTyping() {
    var fila = document.createElement("div");
    fila.className = "ua-chat-row ua-chat-row-bot ua-chat-typing-row";

    var burbuja = document.createElement("div");
    burbuja.className = "ua-chat-bubble ua-chat-bubble-bot ua-chat-typing";
    burbuja.setAttribute("aria-label", "El asistente está escribiendo");
    burbuja.innerHTML =
      '<span class="ua-chat-dot"></span>' +
      '<span class="ua-chat-dot"></span>' +
      '<span class="ua-chat-dot"></span>';

    fila.appendChild(burbuja);
    areaMensajes.appendChild(fila);
    scrollAlFinal();

    return fila;
  }

  function quitarTyping(nodoTyping) {
    if (nodoTyping && nodoTyping.parentNode) {
      nodoTyping.parentNode.removeChild(nodoTyping);
    }
  }

  /* ============================================================
     Backend WordPress (admin-ajax.php)
     ============================================================ */

  /**
   * Envía el historial al gateway PHP y devuelve texto (+ message_id/usage si vienen).
   * Requiere window.uaChatConfig (nonce + ajaxUrl) inyectado por backend.php.
   * @param {Array} history
   * @returns {Promise<{text: string, messageId?: string, usage?: object}>}
   */
  async function sendMessageToBackend(history) {
    if (typeof window.uaChatConfig === "undefined") {
      throw new Error("No se encontró la configuración segura. ¿Estás logueado?");
    }

    var formData = new URLSearchParams();
    formData.append("action", window.uaChatConfig.action);
    formData.append("nonce", window.uaChatConfig.nonce);
    formData.append("history", JSON.stringify(history));
    formData.append("session_id", sessionId || obtenerOCrearSessionId());
    formData.append(
      "post_id",
      String(
        (window.uaChatConfig.postId && window.uaChatConfig.postId) || 0
      )
    );

    var response = await fetch(window.uaChatConfig.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: formData.toString()
    });

    var result = await response.json();

    if (!result.success) {
      throw new Error(result.data.message || "Error desconocido en el servidor");
    }

    return {
      text: result.data.text,
      messageId: result.data.message_id || null,
      usage: result.data.usage || null
    };
  }

  /* ============================================================
     Flujo de envío
     ============================================================ */

  function actualizarEstadoEnviar() {
    var hayTexto = campo.value.trim().length > 0;
    botonEnviar.disabled = !hayTexto || estaCargando;
  }

  async function enviarMensaje(texto) {
    var contenido = (texto || "").trim();
    if (!contenido || estaCargando) {
      return;
    }

    estaCargando = true;
    actualizarEstadoEnviar();

    chatHistory.push({
      role: "user",
      parts: [{ text: contenido }]
    });
    guardarHistorial();
    pintarMensaje("user", contenido);

    campo.value = "";
    ajustarAlturaCampo();
    actualizarEstadoEnviar();

    var typing = mostrarTyping();

    try {
      var resultado = await sendMessageToBackend(chatHistory);
      quitarTyping(typing);

      chatHistory.push({
        role: "model",
        parts: [{ text: resultado.text }]
      });
      guardarHistorial();
      pintarMensaje("model", resultado.text, resultado.messageId);
    } catch (error) {
      quitarTyping(typing);
      var mensajeError = (error && error.message)
        ? error.message
        : "Lo siento, no pude procesar tu mensaje. Inténtalo de nuevo en unos segundos.";
      pintarMensaje("model", mensajeError);
    } finally {
      estaCargando = false;
      actualizarEstadoEnviar();
      campo.focus();
    }
  }

  /* ============================================================
     Abrir / cerrar / nueva conversación
     ============================================================ */

  /** Quita la burbuja de confirmación si existe (no toca el historial). */
  function quitarConfirmacionNueva() {
    var existente = areaMensajes.querySelector(".ua-chat-confirm-row");
    if (existente && existente.parentNode) {
      existente.parentNode.removeChild(existente);
    }
    if (botonNueva) {
      botonNueva.disabled = false;
    }
  }

  /**
   * Muestra una burbuja de confirmación dentro del chat (sin window.confirm).
   * No se guarda en localStorage.
   */
  function mostrarConfirmacionNuevaConversacion() {
    if (estaCargando) {
      return;
    }

    if (!estaAbierto()) {
      abrirChat();
    }

    quitarConfirmacionNueva();

    var fila = document.createElement("div");
    fila.className = "ua-chat-row ua-chat-row-bot ua-chat-confirm-row";

    var caja = document.createElement("div");
    caja.className = "ua-chat-confirm";
    caja.setAttribute("role", "group");
    caja.setAttribute("aria-label", "Confirmar nueva conversación");

    var texto = document.createElement("p");
    texto.className = "ua-chat-confirm-text";
    texto.innerHTML =
      "<strong>¿Iniciar una nueva conversación?</strong><br>" +
      "Se borrará el historial visible de este chat.";

    var acciones = document.createElement("div");
    acciones.className = "ua-chat-confirm-actions";

    var btnCancelar = document.createElement("button");
    btnCancelar.type = "button";
    btnCancelar.className = "ua-chat-confirm-btn ua-chat-confirm-cancel";
    btnCancelar.textContent = "Cancelar";

    var btnOk = document.createElement("button");
    btnOk.type = "button";
    btnOk.className = "ua-chat-confirm-btn ua-chat-confirm-ok";
    btnOk.textContent = "Sí, empezar nueva";

    btnCancelar.addEventListener("click", function () {
      quitarConfirmacionNueva();
      campo.focus();
    });

    btnOk.addEventListener("click", function () {
      ejecutarNuevaConversacion();
    });

    acciones.appendChild(btnCancelar);
    acciones.appendChild(btnOk);
    caja.appendChild(texto);
    caja.appendChild(acciones);
    fila.appendChild(caja);
    areaMensajes.appendChild(fila);
    scrollAlFinal();

    if (botonNueva) {
      botonNueva.disabled = true;
    }

    window.setTimeout(function () {
      btnOk.focus();
    }, 50);
  }

  /**
   * Limpia UI + localStorage y genera un session_id nuevo para Vertex.
   */
  function ejecutarNuevaConversacion() {
    if (estaCargando) {
      return;
    }

    try {
      window.localStorage.removeItem(UA_CHAT_STORAGE_KEY);
    } catch (error) {
      /* ignore */
    }

    sessionId = crearSessionId();
    guardarSessionId();

    chatHistory = [];
    areaMensajes.innerHTML = "";

    if (botonNueva) {
      botonNueva.disabled = false;
    }

    chatHistory.push({
      role: "model",
      parts: [{ text: MENSAJE_BIENVENIDA }]
    });
    guardarHistorial();
    pintarMensaje("model", MENSAJE_BIENVENIDA);
    actualizarEstadoEnviar();
    campo.focus();
  }

  function iniciarNuevaConversacion() {
    mostrarConfirmacionNuevaConversacion();
  }

  function estaAbierto() {
    return widget.classList.contains("ua-chat-open");
  }

  function detenerCoreografiaVisual() {
    clearTimeout(tooltipShowTimer);
    clearTimeout(tooltipHideTimer);

    if (tooltip) {
      tooltip.classList.remove("ua-tooltip-visible");
      tooltip.classList.add("ua-tooltip-oculto");
    }
  }

  function pausarPulso() {
    if (botonFlotante) {
      botonFlotante.classList.remove("ua-animacion-pulso");
    }
  }

  function reanudarPulso() {
    if (botonFlotante && !estaAbierto()) {
      botonFlotante.classList.add("ua-animacion-pulso");
    }
  }

  function abrirChat() {
    /* Al abrir: cancelar timers del tooltip y pausar el pulso (solo mientras el chat está abierto) */
    detenerCoreografiaVisual();
    pausarPulso();

    widget.classList.add("ua-chat-open");
    ventana.setAttribute("aria-hidden", "false");
    botonFlotante.setAttribute("aria-expanded", "true");
    botonFlotante.setAttribute("aria-label", "Cerrar chat");
    window.setTimeout(function () {
      campo.focus();
      scrollAlFinal();
    }, 230);
  }

  function cerrarChat() {
    widget.classList.remove("ua-chat-open");
    ventana.setAttribute("aria-hidden", "true");
    botonFlotante.setAttribute("aria-expanded", "false");
    botonFlotante.setAttribute("aria-label", "Abrir chat");
    /* El pulso se mantiene en la misma página tras cerrar el chat/tooltip */
    reanudarPulso();
  }

  function alternarChat() {
    if (estaAbierto()) {
      cerrarChat();
    } else {
      abrirChat();
    }
  }

  /* ============================================================
     Eventos
     ============================================================ */

  botonFlotante.addEventListener("click", alternarChat);
  botonCerrar.addEventListener("click", cerrarChat);
  if (botonNueva) {
    botonNueva.addEventListener("click", iniciarNuevaConversacion);
  }

  formulario.addEventListener("submit", function (evento) {
    evento.preventDefault();
    enviarMensaje(campo.value);
  });

  campo.addEventListener("input", function () {
    ajustarAlturaCampo();
    actualizarEstadoEnviar();
  });

  /* Enter envía; Shift+Enter inserta un salto de línea */
  campo.addEventListener("keydown", function (evento) {
    if (evento.key === "Enter" && !evento.shiftKey) {
      evento.preventDefault();
      enviarMensaje(campo.value);
    }
  });

  document.addEventListener("keydown", function (evento) {
    if (evento.key !== "Escape") {
      return;
    }
    if (areaMensajes.querySelector(".ua-chat-confirm-row")) {
      quitarConfirmacionNueva();
      campo.focus();
      return;
    }
    if (estaAbierto()) {
      cerrarChat();
    }
  });

  /* ============================================================
     Inicialización
     ============================================================ */

  function iniciarCoreografiaVisual() {
    if (botonFlotante) {
      botonFlotante.classList.add("ua-animacion-pulso");
    }

    /* Aparece a los 7s; se oculta 10s después (en el segundo 17) */
    tooltipShowTimer = setTimeout(function () {
      if (!tooltip || estaAbierto()) {
        return;
      }
      tooltip.classList.remove("ua-tooltip-oculto");
      tooltip.classList.add("ua-tooltip-visible");
    }, 7000);

    tooltipHideTimer = setTimeout(function () {
      if (!tooltip) {
        return;
      }
      tooltip.classList.remove("ua-tooltip-visible");
      tooltip.classList.add("ua-tooltip-oculto");
    }, 17000);
  }

  function iniciar() {
    aplicarContextoPagina();

    sessionId = obtenerOCrearSessionId();

    var guardado = cargarHistorialGuardado();

    if (guardado) {
      chatHistory = guardado;
      pintarHistorialCompleto(chatHistory);
    } else {
      chatHistory.push({
        role: "model",
        parts: [{ text: MENSAJE_BIENVENIDA }]
      });
      guardarHistorial();
      pintarMensaje("model", MENSAJE_BIENVENIDA);
    }

    actualizarEstadoEnviar();
    iniciarCoreografiaVisual();
  }

  iniciar();
})();
