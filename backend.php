<?php
/**
 * Widget Tutor IA + proxy al BFF Academy (Cloud Run).
 *
 * Snippet único para WPCode / mu-plugin:
 *   - AJAX: nonce + LearnDash + POST al BFF
 *   - wp_footer: inyecta CSS/HTML/JS solo en el curso UA_CHAT_COURSE_ID
 *
 * El frontend envía POST a admin-ajax.php con:
 *   action  = ua_chat_send_message
 *   nonce   = window.uaChatConfig.nonce
 *   history = JSON [{ role, parts: [{ text }] }, ...]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 * CONFIGURACIÓN EDITABLE
 * ============================================================================= */

$ua_chat_course_id = 46067;

if ( ! defined( 'UA_CHAT_COURSE_ID' ) ) {
	define( 'UA_CHAT_COURSE_ID', (int) $ua_chat_course_id );
}

if ( ! defined( 'UA_CHAT_BFF_URL' ) ) {
	define(
		'UA_CHAT_BFF_URL',
		'https://academy-ae-gateway-919237484930.us-east1.run.app/api/chat'
	);
}

if ( ! defined( 'UA_CHAT_NONCE_ACTION' ) ) {
	define( 'UA_CHAT_NONCE_ACTION', 'ua_chat_send_message' );
}

/* =============================================================================
 * HOOKS
 * ============================================================================= */

add_action( 'wp_ajax_ua_chat_send_message', 'ua_chat_handle_send_message' );
add_action( 'wp_footer', 'ua_chat_inyectar_widget_curso', 20 );

/* =============================================================================
 * 1. Controlador AJAX
 * ============================================================================= */

function ua_chat_handle_send_message() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Error de seguridad' ), 403 );
	}

	if ( ! check_ajax_referer( UA_CHAT_NONCE_ACTION, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Error de seguridad' ), 403 );
	}

	if ( ! ua_chat_usuario_tiene_acceso_curso() ) {
		wp_send_json_error( array( 'message' => 'No estás inscrito en el curso' ), 403 );
	}

	$historial = ua_chat_sanitizar_historial(
		isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : null
	);

	if ( is_wp_error( $historial ) ) {
		wp_send_json_error( array( 'message' => 'Error de seguridad' ), 400 );
	}

	$ultimo  = end( $historial );
	$mensaje = isset( $ultimo['parts'][0]['text'] ) ? (string) $ultimo['parts'][0]['text'] : '';
	if ( '' === trim( $mensaje ) ) {
		wp_send_json_error( array( 'message' => 'Error de seguridad' ), 400 );
	}

	$texto = ua_chat_consultar_bff( $mensaje, get_current_user_id() );
	if ( is_wp_error( $texto ) ) {
		ua_chat_log_error( 'bff', $texto->get_error_message() );
		wp_send_json_error( array( 'message' => 'Error comunicando con el servidor IA' ), 500 );
	}

	wp_send_json_success( array( 'text' => $texto ) );
}

/* =============================================================================
 * 2. Autorización LearnDash
 * ============================================================================= */

function ua_chat_usuario_tiene_acceso_curso() {
	$course_id = (int) UA_CHAT_COURSE_ID;
	$user_id   = get_current_user_id();

	if ( $course_id <= 0 || $user_id <= 0 ) {
		return false;
	}

	if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
		return false;
	}

	return (bool) sfwd_lms_has_access( $course_id, $user_id );
}

/**
 * True si el visitante está en el curso objetivo (o una lección/tema/quiz suyo)
 * y tiene matrícula.
 */
function ua_chat_debe_mostrar_widget() {
	if ( is_admin() || ! is_user_logged_in() ) {
		return false;
	}

	if ( ! function_exists( 'learndash_get_course_id' ) ) {
		return false;
	}

	$course_id = (int) learndash_get_course_id();
	if ( $course_id !== (int) UA_CHAT_COURSE_ID ) {
		return false;
	}

	return ua_chat_usuario_tiene_acceso_curso();
}

/* =============================================================================
 * 3. Sanitización del historial
 * ============================================================================= */

function ua_chat_sanitizar_historial( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = json_decode( $raw, true );
	}

	if ( ! is_array( $raw ) || empty( $raw ) ) {
		return new WP_Error( 'ua_chat_history', 'Historial inválido' );
	}

	$limpio    = array();
	$max_items = 40;
	$max_chars = 8000;

	foreach ( $raw as $item ) {
		if ( count( $limpio ) >= $max_items ) {
			break;
		}

		if ( ! is_array( $item ) ) {
			continue;
		}

		$role = isset( $item['role'] ) ? sanitize_key( $item['role'] ) : '';
		if ( ! in_array( $role, array( 'user', 'model' ), true ) ) {
			continue;
		}

		$texto = ua_chat_extraer_texto_mensaje( $item );
		$texto = wp_check_invalid_utf8( $texto );
		$texto = trim( $texto );

		if ( '' === $texto ) {
			continue;
		}

		if ( strlen( $texto ) > $max_chars ) {
			$texto = substr( $texto, 0, $max_chars );
		}

		$limpio[] = array(
			'role'  => $role,
			'parts' => array(
				array( 'text' => $texto ),
			),
		);
	}

	if ( empty( $limpio ) ) {
		return new WP_Error( 'ua_chat_history', 'Historial vacío' );
	}

	$ultimo = end( $limpio );
	if ( ! is_array( $ultimo ) || 'user' !== $ultimo['role'] ) {
		return new WP_Error( 'ua_chat_history', 'El último mensaje debe ser del usuario' );
	}

	return $limpio;
}

function ua_chat_extraer_texto_mensaje( $item ) {
	if ( isset( $item['parts'][0]['text'] ) && is_string( $item['parts'][0]['text'] ) ) {
		return $item['parts'][0]['text'];
	}

	if ( isset( $item['content'] ) && is_string( $item['content'] ) ) {
		return $item['content'];
	}

	return '';
}

/* =============================================================================
 * 4. Llamada al BFF (Cloud Run)
 * ============================================================================= */

function ua_chat_consultar_bff( $mensaje, $user_id ) {
	$session_id = 'wp-' . (int) $user_id;

	$response = wp_remote_post(
		UA_CHAT_BFF_URL,
		array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'message'    => $mensaje,
					'session_id' => $session_id,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'ua_chat_bff', $response->get_error_message() );
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$crudo   = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $crudo, true );

	if ( $code < 200 || $code >= 300 ) {
		$detalle = is_array( $decoded ) && isset( $decoded['detail'] )
			? (string) $decoded['detail']
			: ( 'HTTP ' . $code );
		return new WP_Error( 'ua_chat_bff', 'BFF rechazó la petición: ' . $detalle );
	}

	if ( ! is_array( $decoded ) || empty( $decoded['response'] ) || ! is_string( $decoded['response'] ) ) {
		return new WP_Error( 'ua_chat_bff', 'Respuesta del BFF sin texto usable' );
	}

	return trim( $decoded['response'] );
}

/* =============================================================================
 * 5. Inyección del widget (solo curso + alumno inscrito)
 * Orden: uaChatConfig → CSS → HTML → JS
 * ============================================================================= */

function ua_chat_inyectar_widget_curso() {
	if ( ! ua_chat_debe_mostrar_widget() ) {
		return;
	}

	$config = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( UA_CHAT_NONCE_ACTION ),
		'action'  => 'ua_chat_send_message',
	);

	echo '<script>window.uaChatConfig=' . wp_json_encode( $config ) . ';</script>' . "\n";
	echo '<style id="ua-chat-widget-css">' . "\n";
	echo ua_chat_widget_css();
	echo "\n</style>\n";
	echo ua_chat_widget_html();
	echo "\n<script id=\"ua-chat-widget-js\">\n";
	echo ua_chat_widget_js();
	echo "\n</script>\n";
}

function ua_chat_log_error( $contexto, $mensaje ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[UA Chat][' . $contexto . '] ' . $mensaje );
	}
}

function ua_chat_widget_css() {
	return <<<'UA_CHAT_CSS'
/* ============================================================
   Widget de chat flotante — prefijo ua-chat-*
   Namespacing estricto para convivir con WordPress / Elementor
   sin heredar ni contaminar estilos globales del tema o LMS.
   ============================================================ */

/* Página de prueba local (no forma parte del widget inyectable) */


/* ---------- Contenedor principal (z-index supremo) ---------- */
.ua-chat-widget {
  position: fixed !important;
  right: 24px;
  bottom: 24px;
  z-index: 999999 !important;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 16px;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  box-sizing: border-box;
  line-height: 1.45;
  color: #1a1a1a;
}

.ua-chat-widget *,
.ua-chat-widget *::before,
.ua-chat-widget *::after {
  box-sizing: border-box;
}

/* Texto solo para lectores de pantalla */
.ua-chat-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* ---------- Ventana del chat ---------- */
.ua-chat-window {
  width: 380px;
  height: 600px;
  /* Reserva espacio superior + botón flotante + gap para no tocar la barra del navegador */
  max-height: calc(100vh - 124px);
  max-height: calc(100dvh - 124px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #ffffff;
  border-radius: 16px;
  box-shadow:
    0 12px 40px rgba(3, 3, 91, 0.16),
    0 4px 12px rgba(0, 0, 0, 0.08);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateY(12px) scale(0.96);
  transform-origin: bottom right;
  transition:
    opacity 0.22s ease,
    transform 0.22s ease,
    visibility 0.22s ease;
}

.ua-chat-widget.ua-chat-open .ua-chat-window {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateY(0) scale(1);
}

/* ---------- Cabecera ---------- */
.ua-chat-header {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 14px 16px;
  background: #03035b;
  color: #ffffff;
}

.ua-chat-header-info {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.ua-chat-avatar {
  position: relative;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
}

.ua-chat-avatar svg {
  display: block;
  width: 40px;
  height: 40px;
  border-radius: 50%;
}

.ua-chat-status {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  background: #3ddc84;
  border: 2px solid #03035b;
  border-radius: 50%;
}

.ua-chat-header-text {
  min-width: 0;
}

.ua-chat-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
  color: #ffffff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ua-chat-subtitle {
  margin: 2px 0 0;
  font-size: 12px;
  font-weight: 400;
  color: rgba(255, 255, 255, 0.82);
}

.ua-chat-close {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  padding: 0;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #ffffff;
  cursor: pointer;
  transition: background 0.15s ease;
}

.ua-chat-close:hover,
.ua-chat-close:focus-visible {
  background: rgba(255, 255, 255, 0.12);
  outline: none;
}

/* ---------- Área de mensajes ---------- */
.ua-chat-messages {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px 14px 12px;
  background: #e7e7e7;
  scroll-behavior: smooth;
}

.ua-chat-messages::-webkit-scrollbar {
  width: 6px;
}

.ua-chat-messages::-webkit-scrollbar-thumb {
  background: #c4c4c4;
  border-radius: 8px;
}

.ua-chat-row {
  display: flex;
  margin-bottom: 10px;
}

.ua-chat-row-user {
  justify-content: flex-end;
}

.ua-chat-row-bot {
  justify-content: flex-start;
}

/* Burbujas */
.ua-chat-bubble {
  max-width: 78%;
  padding: 10px 13px;
  font-size: 14px;
  line-height: 1.5;
  word-wrap: break-word;
  overflow-wrap: break-word;
}

.ua-chat-bubble-user {
  background: #03035b;
  color: #ffffff;
  border-radius: 16px 16px 4px 16px;
}

.ua-chat-bubble-bot {
  background: #ffffff;
  color: #1a1a1a;
  border-radius: 16px 16px 16px 4px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.ua-chat-bubble strong {
  font-weight: 700;
}

/* Indicador de escritura (3 puntitos) */
.ua-chat-typing {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-width: 52px;
  padding: 14px 16px;
}

.ua-chat-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #767777;
  animation: ua-chat-bounce 1.2s infinite ease-in-out;
}

.ua-chat-dot:nth-child(2) {
  animation-delay: 0.18s;
}

.ua-chat-dot:nth-child(3) {
  animation-delay: 0.36s;
}

@keyframes ua-chat-bounce {
  0%,
  80%,
  100% {
    transform: translateY(0);
    opacity: 0.45;
  }
  40% {
    transform: translateY(-5px);
    opacity: 1;
  }
}

/* ---------- Zona de escritura ---------- */
.ua-chat-input-area {
  flex-shrink: 0;
  display: flex;
  align-items: flex-end;
  gap: 8px;
  padding: 10px 12px 12px;
  background: #ffffff;
  border-top: 1px solid #ececec;
}

.ua-chat-field {
  flex: 1;
  max-height: 96px;
  padding: 10px 12px;
  margin: 0;
  border: 0;
  border-radius: 12px;
  background: #f3f3f3;
  color: #1a1a1a;
  font-family: inherit;
  font-size: 14px;
  line-height: 1.45;
  resize: none;
  outline: none;
  box-shadow: none;
}

.ua-chat-field::placeholder {
  color: #767777;
}

.ua-chat-field:focus {
  background: #efefef;
}

.ua-chat-send {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 0;
  border-radius: 10px;
  background: transparent;
  cursor: pointer;
  transition: background 0.15s ease, opacity 0.15s ease;
}

.ua-chat-send:hover:not(:disabled),
.ua-chat-send:focus-visible:not(:disabled) {
  background: #f0f0f5;
  outline: none;
}

.ua-chat-send:disabled {
  opacity: 0.38;
  cursor: not-allowed;
}

.ua-chat-send svg {
  display: block;
}

/* ---------- Botón flotante ---------- */
.ua-chat-btn {
  position: relative;
  width: 60px;
  height: 60px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: #ffffff;
  box-shadow:
    0 8px 24px rgba(3, 3, 91, 0.18),
    0 2px 6px rgba(0, 0, 0, 0.08);
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.ua-chat-btn:hover {
  transform: scale(1.05);
  box-shadow:
    0 10px 28px rgba(3, 3, 91, 0.22),
    0 3px 8px rgba(0, 0, 0, 0.1);
}

.ua-chat-btn:focus-visible {
  outline: 3px solid #03035b;
  outline-offset: 3px;
}

.ua-chat-btn-icon {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.ua-chat-btn-close {
  opacity: 0;
  transform: scale(0.6) rotate(-45deg);
  pointer-events: none;
}

.ua-chat-widget.ua-chat-open .ua-chat-btn-open {
  opacity: 0;
  transform: scale(0.6) rotate(45deg);
  pointer-events: none;
}

.ua-chat-widget.ua-chat-open .ua-chat-btn-close {
  opacity: 1;
  transform: scale(1) rotate(0);
}

/* ---------- Móvil: ocupa el 100% de la pantalla ---------- */
@media (max-width: 480px) {
  .ua-chat-widget {
    right: 16px;
    bottom: 16px;
  }

  .ua-chat-widget.ua-chat-open {
    right: 0;
    bottom: 0;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    gap: 0;
  }

  .ua-chat-widget.ua-chat-open .ua-chat-window {
    width: 100%;
    height: 100%;
    height: 100dvh;
    max-height: none;
    border-radius: 0;
    box-shadow: none;
  }

  /* El botón flotante no debe tapar el input a pantalla completa */
  .ua-chat-widget.ua-chat-open .ua-chat-btn {
    display: none;
  }
}

UA_CHAT_CSS;
}

function ua_chat_widget_html() {
	return <<<'UA_CHAT_HTML'
<div class="ua-chat-widget" aria-live="polite">

    <!-- Ventana del chat (oculta hasta que el usuario abre el widget) -->
    <section class="ua-chat-window" role="dialog" aria-label="Chat con Agente Actas de Entrega" aria-hidden="true">

      <header class="ua-chat-header">
        <div class="ua-chat-header-info">
          <div class="ua-chat-avatar" aria-hidden="true">
            <!-- Avatar SVG incrustado: perfil genérico de asistente -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="40" height="40">
              <circle cx="20" cy="20" r="20" fill="#1a1a7a"/>
              <circle cx="20" cy="15" r="7" fill="#ffffff"/>
              <path d="M8 34c1.6-7.2 6.8-11 12-11s10.4 3.8 12 11" fill="#ffffff"/>
            </svg>
            <span class="ua-chat-status" title="En línea"></span>
          </div>
          <div class="ua-chat-header-text">
            <h2 class="ua-chat-title">Agente Actas de Entrega</h2>
            <p class="ua-chat-subtitle">Tu asistente virtual</p>
          </div>
        </div>
        <button type="button" class="ua-chat-close" aria-label="Cerrar chat">
          <!-- Icono de cierre (X) -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M18 6L6 18M6 6l12 12"/>
          </svg>
        </button>
      </header>

      <div class="ua-chat-messages" role="log" aria-label="Historial de mensajes"></div>

      <form class="ua-chat-input-area" autocomplete="off">
        <textarea
          class="ua-chat-field"
          name="ua-chat-message"
          rows="1"
          placeholder="Escribe tu mensaje..."
          aria-label="Escribe tu mensaje"
        ></textarea>
        <button type="submit" class="ua-chat-send" aria-label="Enviar mensaje" disabled>
          <!-- Icono SVG de avión de papel -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#03035b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 2L11 13"/>
            <path d="M22 2l-7 20-4-9-9-4 20-7z"/>
          </svg>
        </button>
      </form>
    </section>

    <!-- Botón flotante: abre y cierra el chat -->
    <button type="button" class="ua-chat-btn" aria-label="Abrir chat" aria-expanded="false">
      <span class="ua-chat-btn-icon ua-chat-btn-open" aria-hidden="true">
        <!-- Icono de mensaje SVG (#03035b) -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#03035b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <span class="ua-chat-btn-icon ua-chat-btn-close" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#767777" stroke-width="2" stroke-linecap="round">
          <path d="M18 6L6 18M6 6l12 12"/>
        </svg>
      </span>
    </button>
  </div>
UA_CHAT_HTML;
}

function ua_chat_widget_js() {
	return <<<'UA_CHAT_JS'
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

  var estaCargando = false;

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
   * Convierte markdown básico a HTML seguro:
   * **negrita** → <strong> y saltos de línea \n → <br>
   */
  function renderizarMarkdown(texto) {
    var seguro = escaparHtml(texto);
    seguro = seguro.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
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
   * @returns {HTMLElement} fila insertada
   */
  function pintarMensaje(rol, texto) {
    var fila = document.createElement("div");
    var esUsuario = rol === "user";

    fila.className =
      "ua-chat-row " + (esUsuario ? "ua-chat-row-user" : "ua-chat-row-bot");

    var burbuja = document.createElement("div");
    burbuja.className =
      "ua-chat-bubble " +
      (esUsuario ? "ua-chat-bubble-user" : "ua-chat-bubble-bot");
    burbuja.innerHTML = renderizarMarkdown(texto);

    fila.appendChild(burbuja);
    areaMensajes.appendChild(fila);
    scrollAlFinal();

    return fila;
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
   * Envía el historial al gateway PHP y devuelve el texto del agente.
   * Requiere window.uaChatConfig (nonce + ajaxUrl) inyectado por backend.php.
   * @param {Array} history
   * @returns {Promise<string>}
   */
  async function sendMessageToBackend(history) {
    if (typeof window.uaChatConfig === "undefined") {
      throw new Error("No se encontró la configuración segura. ¿Estás logueado?");
    }

    var formData = new URLSearchParams();
    formData.append("action", window.uaChatConfig.action);
    formData.append("nonce", window.uaChatConfig.nonce);
    formData.append("history", JSON.stringify(history));

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

    return result.data.text;
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
    pintarMensaje("user", contenido);

    campo.value = "";
    ajustarAlturaCampo();
    actualizarEstadoEnviar();

    var typing = mostrarTyping();

    try {
      var respuesta = await sendMessageToBackend(chatHistory);
      quitarTyping(typing);

      chatHistory.push({
        role: "model",
        parts: [{ text: respuesta }]
      });
      pintarMensaje("model", respuesta);
    } catch (error) {
      quitarTyping(typing);
      pintarMensaje(
        "model",
        "Lo siento, no pude procesar tu mensaje. Inténtalo de nuevo en unos segundos."
      );
    } finally {
      estaCargando = false;
      actualizarEstadoEnviar();
      campo.focus();
    }
  }

  /* ============================================================
     Abrir / cerrar widget
     ============================================================ */

  function estaAbierto() {
    return widget.classList.contains("ua-chat-open");
  }

  function abrirChat() {
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
    if (evento.key === "Escape" && estaAbierto()) {
      cerrarChat();
    }
  });

  /* ============================================================
     Inicialización
     ============================================================ */

  function iniciar() {
    chatHistory.push({
      role: "model",
      parts: [{ text: MENSAJE_BIENVENIDA }]
    });
    pintarMensaje("model", MENSAJE_BIENVENIDA);
    actualizarEstadoEnviar();
  }

  iniciar();
})();

UA_CHAT_JS;
}
