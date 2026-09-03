<?php
/**
 * Widget Tutor IA + proxy al BFF Academy (Cloud Run).
 *
 * Snippet único para WPCode / mu-plugin:
 *   - AJAX: nonce + LearnDash + POST al BFF
 *   - wp_footer: inyecta CSS/HTML/JS solo en el curso UA_CHAT_COURSE_ID
 *
 * El frontend envía POST a admin-ajax.php con:
 *   action     = ua_chat_send_message
 *   nonce      = window.uaChatConfig.nonce
 *   history    = JSON [{ role, parts: [{ text }] }, ...]
 *   session_id = wp-{userId}-{timestamp}
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

	$session_id = ua_chat_resolver_session_id(
		isset( $_POST['session_id'] ) ? wp_unslash( $_POST['session_id'] ) : '',
		get_current_user_id()
	);

	$texto = ua_chat_consultar_bff( $mensaje, $session_id );
	if ( is_wp_error( $texto ) ) {
		ua_chat_log_error( 'bff', $texto->get_error_message() );
		wp_send_json_error(
			array( 'message' => ua_chat_mensaje_amigable( $texto ) ),
			500
		);
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
	$max_items = 24;
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

/**
 * Traduce errores técnicos del BFF a mensajes amigables para el alumno.
 * El detalle técnico queda solo en ua_chat_log_error() (WP_DEBUG).
 *
 * @param WP_Error $error
 * @return string
 */
function ua_chat_mensaje_amigable( WP_Error $error ) {
	$msg  = strtolower( $error->get_error_message() );
	$code = $error->get_error_code();

	if (
		false !== strpos( $msg, 'timeout' ) ||
		false !== strpos( $msg, 'timed out' ) ||
		false !== strpos( $msg, 'curl error 28' )
	) {
		return 'El tutor está tardando más de lo habitual. Intenta de nuevo en unos segundos o haz una pregunta más específica.';
	}

	if (
		false !== strpos( $msg, '429' ) ||
		false !== strpos( $msg, 'resource_exhausted' ) ||
		false !== strpos( $msg, '503' ) ||
		false !== strpos( $msg, '504' )
	) {
		return 'Tu consulta es muy amplia. ¿Podrías indicar el módulo o el tema concreto?';
	}

	if (
		'ua_chat_bff_empty' === $code ||
		false !== strpos( $msg, 'sin texto usable' ) ||
		false !== strpos( $msg, 'respuesta vacía' ) ||
		false !== strpos( $msg, 'respuesta del bff vacía' )
	) {
		return 'No recibí una respuesta del tutor. Intenta reformular tu pregunta.';
	}

	if (
		false !== strpos( $msg, 'http 4' ) ||
		false !== strpos( $msg, 'http 5' ) ||
		false !== strpos( $msg, 'bff rechazó' )
	) {
		return 'No pude conectar con el tutor en este momento. Intenta de nuevo en unos segundos.';
	}

	return 'No pude conectar con el tutor en este momento. Intenta de nuevo en unos segundos.';
}

/**
 * Valida session_id del cliente o genera uno seguro.
 * Formato aceptado: wp-{userId}-{timestamp} (solo dígitos en el stamp).
 * Si falta o no pertenece al usuario autenticado, crea wp-{userId}-{time()}.
 *
 * @param mixed $raw
 * @param int   $user_id
 * @return string
 */
function ua_chat_resolver_session_id( $raw, $user_id ) {
	$user_id = (int) $user_id;
	$prefix  = 'wp-' . $user_id . '-';
	$candidato = is_string( $raw ) ? trim( $raw ) : '';

	if (
		'' !== $candidato
		&& 0 === strpos( $candidato, $prefix )
		&& preg_match( '/^wp-\d+-\d{10,16}$/', $candidato )
	) {
		return $candidato;
	}

	return $prefix . (string) time();
}

/**
 * @param string $mensaje
 * @param string $session_id Ya validado (wp-{userId}-{timestamp}).
 * @return string|WP_Error
 */
function ua_chat_consultar_bff( $mensaje, $session_id ) {
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
		return new WP_Error( 'ua_chat_bff_transport', $response->get_error_message() );
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$crudo   = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $crudo, true );

	if ( $code < 200 || $code >= 300 ) {
		$detalle = is_array( $decoded ) && isset( $decoded['detail'] )
			? (string) $decoded['detail']
			: ( 'HTTP ' . $code );
		return new WP_Error(
			'ua_chat_bff',
			'BFF rechazó la petición: HTTP ' . $code . ' — ' . $detalle
		);
	}

	if ( ! is_array( $decoded ) || empty( $decoded['response'] ) || ! is_string( $decoded['response'] ) ) {
		return new WP_Error( 'ua_chat_bff_empty', 'Respuesta del BFF sin texto usable' );
	}

	$texto = trim( $decoded['response'] );
	if ( '' === $texto ) {
		return new WP_Error( 'ua_chat_bff_empty', 'Respuesta del BFF vacía' );
	}

	return $texto;
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
		'userId'  => (int) get_current_user_id(),
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

/* En cuestionarios LearnDash: subir el FAB para no tapar el botón Next */
.ua-chat-widget.ua-chat-en-quiz {
  bottom: 120px;
}

/* Reserva extra de altura: bottom 120 + FAB/gap + margen superior */
.ua-chat-widget.ua-chat-en-quiz .ua-chat-window {
  max-height: calc(100vh - 220px);
  max-height: calc(100dvh - 220px);
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

.ua-chat-header-actions {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.ua-chat-new,
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
  transition: background 0.15s ease, opacity 0.15s ease;
}

.ua-chat-new:hover,
.ua-chat-new:focus-visible,
.ua-chat-close:hover,
.ua-chat-close:focus-visible {
  background: rgba(255, 255, 255, 0.12);
  outline: none;
}

.ua-chat-new:disabled {
  opacity: 0.4;
  cursor: not-allowed;
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

.ua-chat-md-heading {
  display: block;
  margin: 0.35em 0 0.2em;
  font-size: 1.05em;
  font-weight: 700;
  line-height: 1.35;
}

.ua-chat-md-heading:first-child {
  margin-top: 0;
}

.ua-chat-md-li {
  display: block;
  position: relative;
  padding-left: 1.1em;
  margin: 0.15em 0;
}

.ua-chat-md-li::before {
  content: "•";
  position: absolute;
  left: 0;
  color: inherit;
}

/* Confirmación inline: nueva conversación */
.ua-chat-confirm {
  max-width: 92%;
  padding: 12px 14px;
  background: #ffffff;
  color: #1a1a1a;
  border-radius: 16px 16px 16px 4px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  border-left: 3px solid #03035b;
}

.ua-chat-confirm-text {
  margin: 0 0 12px;
  font-size: 13px;
  line-height: 1.45;
  color: #1a1a1a;
}

.ua-chat-confirm-text strong {
  font-weight: 700;
}

.ua-chat-confirm-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.ua-chat-confirm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 34px;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease, opacity 0.15s ease;
}

.ua-chat-confirm-cancel {
  border: 1px solid #c8c8d0;
  background: transparent;
  color: #4a4a55;
}

.ua-chat-confirm-cancel:hover,
.ua-chat-confirm-cancel:focus-visible {
  background: #f0f0f5;
  outline: none;
}

.ua-chat-confirm-ok {
  border: 0;
  background: #03035b;
  color: #ffffff;
}

.ua-chat-confirm-ok:hover,
.ua-chat-confirm-ok:focus-visible {
  background: #05057a;
  outline: none;
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
  background: #03035b;
  color: #ffffff;
  box-shadow:
    0 8px 24px rgba(3, 3, 91, 0.35),
    0 2px 6px rgba(0, 0, 0, 0.12);
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.ua-chat-btn:hover {
  transform: scale(1.05);
  box-shadow:
    0 10px 28px rgba(3, 3, 91, 0.42),
    0 3px 8px rgba(0, 0, 0, 0.14);
}

.ua-chat-btn:focus-visible {
  outline: 3px solid #4d4dff;
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

.ua-chat-btn-icon svg {
  stroke: #ffffff;
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

  .ua-chat-widget.ua-chat-open #ua-chat-tooltip {
    display: none;
  }
}

/* ---------- Coreografía visual: pulso + tooltip ---------- */
@keyframes softPulse {
  0%,
  100% {
    transform: scale(1);
    box-shadow:
      0 8px 24px rgba(3, 3, 91, 0.35),
      0 2px 6px rgba(0, 0, 0, 0.12),
      0 0 0 0 rgba(77, 77, 255, 0.45);
  }
  50% {
    transform: scale(1.05);
    box-shadow:
      0 10px 28px rgba(3, 3, 91, 0.42),
      0 3px 8px rgba(0, 0, 0, 0.14),
      0 0 0 12px rgba(77, 77, 255, 0);
  }
}

.ua-animacion-pulso {
  animation: softPulse 2s ease-in-out infinite;
}

#ua-chat-tooltip {
  position: absolute;
  right: 0;
  bottom: calc(60px + 16px + 8px);
  max-width: 220px;
  padding: 10px 14px;
  background: #03035b;
  color: #ffffff;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.4;
  text-align: left;
  border-radius: 12px;
  box-shadow: 0 8px 20px rgba(3, 3, 91, 0.22);
  white-space: normal;
  z-index: 1;
}

#ua-chat-tooltip::after {
  content: "";
  position: absolute;
  right: 22px;
  bottom: -6px;
  width: 12px;
  height: 12px;
  background: #03035b;
  transform: rotate(45deg);
  border-radius: 2px;
}

.ua-tooltip-oculto {
  opacity: 0;
  transform: translateY(10px);
  pointer-events: none;
  transition: all 0.4s ease;
}

.ua-tooltip-visible {
  opacity: 1;
  transform: translateY(0);
  pointer-events: auto;
  transition: all 0.4s ease;
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
        <div class="ua-chat-header-actions">
          <button type="button" class="ua-chat-new" aria-label="Nueva conversación" title="Nueva conversación">
            <!-- Icono refresh: reinicia historial + session_id -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 2v6h-6"/>
              <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
              <path d="M3 22v-6h6"/>
              <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
          </button>
          <button type="button" class="ua-chat-close" aria-label="Cerrar chat">
            <!-- Icono de cierre (X) -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
          </button>
        </div>
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

    <!-- Tooltip de atracción + botón flotante -->
    <div id="ua-chat-tooltip" class="ua-tooltip-oculto">¿Dudas con esta lección? Estoy aquí para ayudarte.</div>
    <button type="button" class="ua-chat-btn" aria-label="Abrir chat" aria-expanded="false">
      <span class="ua-chat-btn-icon ua-chat-btn-open" aria-hidden="true">
        <!-- Icono de mensaje SVG (blanco sobre fondo azul) -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <span class="ua-chat-btn-icon ua-chat-btn-close" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round">
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
    formData.append("session_id", sessionId || obtenerOCrearSessionId());

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
    guardarHistorial();
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
      guardarHistorial();
      pintarMensaje("model", respuesta);
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
UA_CHAT_JS;
}
