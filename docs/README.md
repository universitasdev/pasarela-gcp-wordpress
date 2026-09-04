# Pasarela GCP → WordPress — Tutor IA (Agente Actas de Entrega)

Pasarela segura entre un **widget de chat flotante** en WordPress/LearnDash y un **agente de IA** servido vía BFF en Google Cloud Run. Diseñada para el curso [Actas de entrega en la Administración Pública](https://universitas.academy/cursos/actas-de-entrega/) en [Universitas Academy](https://universitas.academy/).

**Estado:** en producción (curso LearnDash ID `46067`).

---

## Descripción corta (GitHub)

> Widget de Tutor IA para LearnDash: chat flotante en WordPress con pasarela AJAX segura hacia un BFF en Cloud Run (GCP).

*(Cópiala en el campo Description al crear el repositorio.)*

---



## Tabla de contenidos

1. [Contexto y objetivo](#1-contexto-y-objetivo)
2. [Arquitectura](#2-arquitectura)
3. [Estructura del repositorio](#3-estructura-del-repositorio)
4. [Frontend (widget)](#4-frontend-widget)
5. [Backend WordPress (gateway)](#5-backend-wordpress-gateway)
6. [Seguridad](#6-seguridad)
7. [Integración LearnDash](#7-integración-learndash)
8. [Despliegue en producción](#8-despliegue-en-producción)
9. [Desarrollo local](#9-desarrollo-local)
10. [Configuración](#10-configuración)
11. [API / contrato de datos](#11-api--contrato-de-datos)
12. [UX y casos especiales](#12-ux-y-casos-especiales)
13. [Troubleshooting](#13-troubleshooting)
14. [Limitaciones y roadmap](#14-limitaciones-y-roadmap)
15. [Historial de trabajo](#15-historial-de-trabajo)
16. [Créditos](#16-créditos)

---



## 1. Contexto y objetivo



### Problema

Los alumnos del LMS (BuddyBoss + LearnDash) necesitan un **tutor conversacional** mientras cursan temas, materiales y cuestionarios, sin salir del flujo pedagógico y sin exponer credenciales de Google Cloud al navegador.

### Solución

Un widget flotante (FAB) namespaced (`ua-chat-*`) que:

- Solo aparece a usuarios **logueados** e **inscritos** en el curso objetivo.
- Habla con WordPress vía `admin-ajax.php` (nonce CSRF).
- WordPress actúa como **gateway**: valida, sanitiza y reenvía al **BFF** en Cloud Run.
- El BFF mantiene sesión de agente (`session_id`) y responde al alumno.



### Curso en producción

- URL: [https://universitas.academy/cursos/actas-de-entrega/](https://universitas.academy/cursos/actas-de-entrega/)
- LearnDash Course ID: `46067`
- Nombre del agente en UI: **Agente Actas de Entrega**

---



## 2. Arquitectura

```
┌─────────────────────────────────────────────────────────────┐
│  Navegador (alumno)                                         │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ Widget ua-chat (HTML + CSS + JS vanilla)              │  │
│  │  • Historial localStorage (máx. 24 msgs)              │  │
│  │  • Markdown ligero en burbujas                        │  │
│  │  • FAB + tooltip + softPulse                          │  │
│  └─────────────────────────┬─────────────────────────────┘  │
└────────────────────────────┼────────────────────────────────┘
                             │ POST application/x-www-form-urlencoded
                             │ action, nonce, history (JSON)
                             ▼
┌─────────────────────────────────────────────────────────────┐
│  WordPress (Universitas Academy)                            │
│  Snippet PHP / WPCode: wp-snippet-tutor-ia.php              │
│  • is_user_logged_in + check_ajax_referer                   │
│  • sfwd_lms_has_access(COURSE_ID, user_id)                  │
│  • Sanitiza historial → extrae último mensaje user          │
│  • wp_remote_post → BFF                                     │
│  • wp_footer: inyecta config + CSS + HTML + JS              │
│    solo si learndash_get_course_id() === COURSE_ID          │
└─────────────────────────────┬───────────────────────────────┘
                              │ POST JSON
                              │ { message, session_id: "wp-{uid}-{ts}" }
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  BFF Cloud Run (GCP)                                        │
│  https://academy-ae-gateway-919237484930.us-east1.run.app   │
│  /api/chat                                                  │
│  → Agente / Reasoning Engine / Vertex AI                    │
└─────────────────────────────────────────────────────────────┘
```



### Principios de diseño


| Principio                      | Implementación                                   |
| ------------------------------ | ------------------------------------------------ |
| Zero Composer en cPanel        | PHP puro + `wp_remote_post`                      |
| Credenciales fuera del browser | El JS nunca ve tokens GCP; habla solo con WP     |
| Namespacing CSS                | Prefijo `ua-chat-*`, `z-index: 999999`           |
| Falla cerrado                  | Sin login / sin curso / sin nonce → error limpio |
| Un curso, un widget            | Filtro por `UA_CHAT_COURSE_ID`                   |


---



## 3. Estructura del repositorio

```
pasarela-gcp-wp/
├── README.md                          ← Este documento
├── index.html                         ← Prototipo local del widget
├── style.css                          ← Estilos del widget (fuente de verdad UI)
├── script.js                          ← Lógica del widget (fuente de verdad JS)
├── backend.php                        ← Gateway PHP (referencia / evolución)
├── wp-snippet-tutor-ia.php            ← Snippet listo para WPCode (PHP + assets embebidos)
└── docs/
    ├── documentacion-tutor-ia-2026-08-20.md   ← Bitácora de sesión (20 ago 2026)
    └── (este README concentra la visión completa del proyecto)
```



### Qué archivo usar para qué


| Objetivo                        | Archivo                                                          |
| ------------------------------- | ---------------------------------------------------------------- |
| Probar UI en local              | `index.html` (+ css/js)                                          |
| Desplegar en WordPress          | Pegar `wp-snippet-tutor-ia.php` como fragmento **PHP** en WPCode |
| Entender/evolucionar el gateway | `backend.php` o la parte PHP de `wp-snippet-tutor-ia.php`        |
| Bitácora de cambios recientes   | `docs/documentacion-tutor-ia-2026-08-20.md`                      |


> **Importante:** en producción debe vivir **un solo** snippet del widget. Si quedó un fragmento HTML antiguo de Elementor/WPCode, desactívalo para no duplicar el FAB.

---



## 4. Frontend (widget)



### 4.1 Capacidades

- Botón flotante (FAB) esquina inferior derecha.
- Ventana 380×600 px (con `max-height` adaptable al viewport).
- En móvil (`max-width: 480px`) ocupa pantalla completa al abrirse.
- Cabecera corporativa `#03035b`.
- Burbujas user/bot, indicador *typing* (3 puntitos).
- Envío con Enter; Shift+Enter = salto de línea; Escape cierra.
- Iconos SVG incrustados (sin Font Awesome ni CDNs).



### 4.2 Persistencia (`localStorage`)

LearnDash recarga la página en cada paso (tema → cuestionario → material). El array en memoria se pierde.

- Clave historial: `ua-chat-history-46067`
- Clave sesión Vertex: `ua-chat-session-46067` (`wp-{userId}-{timestamp}`)
- Tope: **24** mensajes (los más recientes)
- Al iniciar: si hay guardado, se restaura; si no, mensaje de bienvenida
- Los errores de red **no** se guardan en el historial ni en `localStorage`



### 4.3 Markdown renderizado

Función `renderizarMarkdown()` (XSS-safe con escape previo):


| Entrada                 | Salida                            |
| ----------------------- | --------------------------------- |
| `**texto**`             | `<strong>`                        |
| `#` … `######` título   | Título visual **sin** mostrar `#` |
| `*`, `-`, `•`, `^` ítem | Viñeta                            |
| `\n`                    | `<br>`                            |




### 4.4 Coreografía visual

- Clase `.ua-animacion-pulso` → `@keyframes softPulse`
- Tooltip `#ua-chat-tooltip`: aparece a los **7 s**, se oculta a los **17 s**
- Textos:
  - Lección: *¿Dudas con esta lección? Estoy aquí para ayudarte.*
  - Cuestionario: *¿Dudas con este cuestionario? Estoy aquí para ayudarte.*
- Al abrir el chat: se cancelan timers, se oculta tooltip, se pausa el pulso
- Al cerrar el chat: el pulso se reanuda



### 4.5 Contexto cuestionario vs lección

Detección JS (`esPaginaCuestionario`):

- `body.single-sfwd-quiz` / clases con `sfwd-quiz`
- Presencia de `.wpProQuiz_content`, etc.

Si es quiz → clase `ua-chat-en-quiz`:

```css
.ua-chat-widget.ua-chat-en-quiz {
  bottom: 120px; /* no tapa el botón Next de LearnDash */
}
.ua-chat-widget.ua-chat-en-quiz .ua-chat-window {
  max-height: calc(100dvh - 220px); /* evita que el chat se corte arriba */
}
```

---



## 5. Backend WordPress (gateway)

Implementado en `wp-snippet-tutor-ia.php` (y espejado en `backend.php`).

### Hooks

```php
add_action( 'wp_ajax_ua_chat_send_message', 'ua_chat_handle_send_message' );
add_action( 'wp_footer', 'ua_chat_inyectar_widget_curso', 20 );
```

**No** se registra `wp_ajax_nopriv_`*: anónimos no pueden llamar al endpoint.

### Flujo AJAX (`ua_chat_handle_send_message`)

1. ¿Usuario logueado? → si no, 403
2. ¿Nonce válido? (`check_ajax_referer`) → si no, 403
3. ¿Acceso LearnDash al curso? → si no, 403 *"No estás inscrito en el curso"*
4. Sanitizar `history` (roles `user`/`model`, límites de ítems/caracteres)
5. Extraer último mensaje del usuario
6. `ua_chat_consultar_bff( $mensaje, $user_id )`
7. `wp_send_json_success( [ 'text' => $texto ] )` o error 500 con mensaje amigable (`ua_chat_mensaje_amigable`)



### Graceful fallback (errores BFF)

La función `ua_chat_mensaje_amigable( WP_Error $error )` traduce fallos del BFF a texto útil para el alumno. El detalle técnico solo va a `error_log` si `WP_DEBUG` está activo.

| Condición (mensaje/código interno) | Mensaje al alumno |
|-----------------------------------|-------------------|
| `timeout`, `timed out`, `cURL error 28` | El tutor está tardando más de lo habitual. Intenta de nuevo en unos segundos o haz una pregunta más específica. |
| `429`, `RESOURCE_EXHAUSTED`, `503`, `504` | Tu consulta es muy amplia. ¿Podrías indicar el módulo o el tema concreto? |
| `ua_chat_bff_empty`, respuesta vacía | No recibí una respuesta del tutor. Intenta reformular tu pregunta. |
| HTTP 4xx/5xx del BFF | No pude conectar con el tutor en este momento. Intenta de nuevo en unos segundos. |
| Cualquier otro error | Mismo mensaje genérico de conexión |

En el frontend, `enviarMensaje` muestra `error.message` del JSON (no el texto fijo *"Lo siento, no pude procesar..."* salvo fallback local).

PHP sanitiza como máximo **24** ítems en `ua_chat_sanitizar_historial` (`$max_items = 24`).



### Inyección en footer

Orden:

1. `<script>window.uaChatConfig={ajaxUrl,nonce,action}</script>`
2. `<style>` con CSS del widget
3. HTML del widget
4. `<script>` con JS del widget

Solo si `ua_chat_debe_mostrar_widget()` es verdadero.

### Nowdocs

CSS/HTML/JS van en nowdocs PHP (`<<<'UA_CHAT_CSS'`, etc.).  
**Regla crítica:** el identificador debe ir **solo en su línea**; el contenido empieza en la siguiente. Si se pega pegado (`<<<'UA_CHAT_CSS'/*...`), WPCode falla con errores de sintaxis engañosos.

---



## 6. Seguridad


| Control             | Detalle                                                               |
| ------------------- | --------------------------------------------------------------------- |
| Autenticación WP    | Solo `wp_ajax_` (usuarios logueados)                                  |
| CSRF                | Nonce `ua_chat_send_message`                                          |
| Autorización LMS    | `sfwd_lms_has_access( $course_id, $user_id )` — orden: curso, usuario |
| Scope de UI         | Widget solo en páginas del curso `46067`                              |
| Sanitización        | Roles permitidos, recorte de longitud, UTF-8                          |
| Secretos            | Ningún JSON de service account ni token GCP en el frontend            |
| XSS en UI           | Escape HTML antes de aplicar Markdown                                 |
| Mensajes al cliente | Errores genéricos; detalle técnico solo en `error_log` si `WP_DEBUG`  |


---



## 7. Integración LearnDash

Estructura típica del curso:

```
Curso (46067)
 └── Módulo
      └── Tema / lección
      └── Cuestionario (sfwd-quiz / WP Pro Quiz)
      └── Materiales de apoyo
```

Implicaciones:

- Cada navegación = nueva carga de página → hace falta `localStorage`.
- El botón **Next** del quiz compite espacialmente con el FAB → clase `ua-chat-en-quiz`.
- El snippet debe ejecutarse en el footer global; el filtro por curso lo hace el propio PHP.

---



## 8. Despliegue en producción



### Requisitos

- WordPress + LearnDash (+ BuddyBoss en Universitas).
- Plugin WPCode (o mu-plugin equivalente).
- BFF Cloud Run accesible desde el servidor WP (`wp_remote_post`).
- Curso ID correcto (`46067` para Actas de Entrega).



### Pasos

1. Abrir `wp-snippet-tutor-ia.php` completo.
2. WPCode → **Fragmento de código PHP** (no HTML).
3. Pegar el archivo tal cual (respetando saltos de línea de los nowdocs).
4. Activar el snippet (ubicación típica: Run everywhere / `wp_footer`; el PHP ya filtra por curso).
5. Desactivar cualquier snippet HTML previo del mismo widget.
6. Probar como alumno inscrito:
  - Lección: FAB abajo, tooltip de lección.
  - Cuestionario (zoom 100%): Next usable, chat sin cortarse.
7. Hard refresh (Ctrl+F5) tras actualizar el snippet.



### Verificación rápida


| Prueba                             | Resultado esperado                     |
| ---------------------------------- | -------------------------------------- |
| Usuario no logueado                | No ve el widget                        |
| Logueado sin matrícula             | No ve el widget / AJAX 403 inscripción |
| Logueado con matrícula en el curso | Ve FAB y puede chatear                 |
| Tema → cuestionario                | Historial de burbujas se conserva      |
| DevTools → `admin-ajax.php`        | `success: true` y `data.text`          |


---



## 9. Desarrollo local

```text
1. Abrir index.html en el navegador (doble clic o Live Server).
2. Probar UI, pulso, tooltip, markdown, localStorage.
3. sendMessageToBackend() requiere window.uaChatConfig → en local fallará
   la llamada real (esperado). Para mock, restaurar temporalmente un setTimeout.
4. Iterar en style.css / script.js / index.html.
5. Regenerar o sincronizar cambios hacia wp-snippet-tutor-ia.php antes de desplegar.
```

---



## 10. Configuración

Constantes PHP (editables al inicio del snippet):


| Constante              | Valor actual                                                        | Descripción             |
| ---------------------- | ------------------------------------------------------------------- | ----------------------- |
| `UA_CHAT_COURSE_ID`    | `46067`                                                             | ID LearnDash del curso  |
| `UA_CHAT_BFF_URL`      | `https://academy-ae-gateway-919237484930.us-east1.run.app/api/chat` | Endpoint del BFF        |
| `UA_CHAT_NONCE_ACTION` | `ua_chat_send_message`                                              | Acción del nonce / AJAX |


Constantes JS (frontend):


| Variable               | Valor                   | Descripción                      |
| ---------------------- | ----------------------- | -------------------------------- |
| `UA_CHAT_COURSE_ID`    | `46067`                 | Alineado con PHP (clave storage) |
| `UA_CHAT_STORAGE_KEY`  | `ua-chat-history-46067` | Historial en `localStorage`      |
| `UA_CHAT_SESSION_KEY`  | `ua-chat-session-46067` | `session_id` Vertex persistido   |
| `UA_CHAT_MAX_MENSAJES` | `24`                    | Tope de historial en UI          |
| PHP `$max_items`       | `24`                    | Tope al sanitizar historial AJAX |


Colores corporativos principales: `#03035b` (azul Universitas), texto blanco en cabecera/FAB, fondo de mensajes `#e7e7e7`.

---



## 11. API / contrato de datos



### Browser → WordPress (`admin-ajax.php`)

```http
POST /wp-admin/admin-ajax.php
Content-Type: application/x-www-form-urlencoded

action=ua_chat_send_message
&nonce=...
&history=[{"role":"user","parts":[{"text":"..."}]},...]
```

Respuesta OK:

```json
{ "success": true, "data": { "text": "Respuesta del agente..." } }
```

Respuesta error (mensaje amigable según el fallo):

```json
{ "success": false, "data": { "message": "No pude conectar con el tutor en este momento. Intenta de nuevo en unos segundos." } }
```



### WordPress → BFF

```json
{
  "event_type": "chat",
  "message": "texto del último mensaje del usuario",
  "session_id": "wp-123-1718293812",
  "user_context": {
    "user_id": 123,
    "display_name": "Juan Pérez",
    "course_id": 46067,
    "course_title": "…",
    "post_id": 46102,
    "post_type": "sfwd-lessons",
    "post_title": "…",
    "lesson_id": 46102,
    "topic_id": null,
    "quiz_id": null
  }
}
```

Respuesta esperada del BFF (Analytics 360; `message_id`/`usage` opcionales mientras el BFF no los implemente):

```json
{
  "response": "texto plano o markdown del agente",
  "message_id": "uuid-opcional",
  "usage": { "input_tokens": 120, "output_tokens": 80, "total_tokens": 200 }
}
```

`session_id` = `"wp-" + user_id + "-" + timestamp` (generado en el frontend, validado en PHP). Botón «Nueva conversación» regenera el ID y limpia `localStorage`. Plan: [PLAN-tutor-analytics-360.md](PLAN-tutor-analytics-360.md).

---



## 12. UX y casos especiales



### Zoom del navegador

- A **100%**, sin `ua-chat-en-quiz`, el FAB tapaba **Next**.
- Con `bottom: 120px` sin ajustar `max-height`, el chat se cortaba arriba.
- Solución combinada: subir FAB **y** reducir `max-height` en quizzes.



### Móvil

- Widget abierto: pantalla completa; FAB oculto (cerrar con X de cabecera).



### Historial vs sesión BFF

- UI: `localStorage` (dispositivo).
- Agente: `session_id` dinámico (`wp-{uid}-{ts}`), persistido en `ua-chat-session-46067`.
- Botón «Nueva conversación» limpia historial UI + regenera `session_id` (resetea memoria Vertex).

---



## 13. Troubleshooting


| Síntoma                                      | Causa probable                                             | Acción                                                           |
| -------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------- |
| WPCode: `unexpected token , expecting ;`     | Nowdoc mal formado (contenido en la misma línea que `<<<`) | Verificar saltos de línea en `UA_CHAT_CSS/HTML/JS`               |
| Widget no aparece                            | No logueado, sin matrícula, o no estás en el curso 46067   | Revisar `ua_chat_debe_mostrar_widget`                            |
| AJAX: mensaje amigable del tutor (timeout, BFF caído, etc.) | Fallo en `ua_chat_consultar_bff` | Revisar `[UA Chat][bff]` en error_log con `WP_DEBUG`; mensaje al alumno ya es legible |
| AJAX: "Error de seguridad"                   | Nonce / sesión                                             | Recargar página logueado                                         |
| AJAX: "No estás inscrito…"                   | LearnDash sin acceso                                       | Matricular usuario de prueba                                     |
| Historial se pierde                          | Otro navegador/dispositivo o clave distinta                | Verificar `ua-chat-history-46067` en Application → Local Storage |
| Next tapado                                  | Snippet viejo sin `ua-chat-en-quiz`                        | Actualizar snippet                                               |
| Chat cortado en quiz                         | Falta regla `max-height` de quiz                           | Actualizar CSS del snippet                                       |
| `##` visibles en burbujas                    | JS viejo sin render de headings                            | Actualizar snippet                                               |


---



## 14. Limitaciones y roadmap



### Limitaciones actuales

- Historial UI no es multi-dispositivo.
- Un `session_id` por conversación (`wp-{uid}-{ts}`); se reutiliza entre páginas hasta «Nueva conversación».
- Markdown del frontend es un subconjunto (no tablas, no código fence completo).
- Dependencia de selectores LearnDash/BuddyBoss para detectar quizzes.



### Roadmap sugerido

1. ~~Botón «Nueva conversación»~~ (implementado: limpia historial + nuevo `session_id`).
2. Persistencia servidor (`user_meta` o API del BFF) alineada con la UI.
3. Prompt del agente: evitar encabezados `#` (refuerzo al render).
4. Empaquetar como mu-plugin versionado en lugar de solo WPCode.
5. Tests de humo automatizados del endpoint AJAX en staging.

---



## 15. Historial de trabajo



### Fase inicial

- Widget estático local (HTML/CSS/JS).
- Gateway PHP seguro hacia GCP / BFF.
- Despliegue en staging y luego producción del curso Actas.



### Sesión 29 agosto 2026 (staging)

- Graceful fallback BFF (`ua_chat_mensaje_amigable`).
- Historial acotado a **24** mensajes (JS + PHP).
- JS muestra `error.message` del servidor en burbujas de error.
- Plan: [PLAN-graceful-fallback-staging.md](PLAN-graceful-fallback-staging.md).

### Sesión 20 agosto 2026 (resumen)

- Coreografía FAB (pulso, tooltip, colores).
- `localStorage` (inicialmente N=80; reducido a 24 en sesión 29/08).
- Snippet unificado + fix nowdocs WPCode.
- Markdown headings/listas.
- UX quizzes: `bottom` + `max-height` + tooltip diferenciado.
- Documentación de bitácora en `docs/documentacion-tutor-ia-2026-08-20.md`.

Detalle ampliado: ver ese archivo en `docs/`.

---



## 16. Créditos

- **Producto / Academia:** Universitas Academy  
- **Curso:** Actas de entrega en la Administración Pública — [https://universitas.academy/cursos/actas-de-entrega/](https://universitas.academy/cursos/actas-de-entrega/)  
- **Stack:** WordPress, LearnDash, BuddyBoss, WPCode, Cloud Run (GCP), Vanilla JS

---



## Licencia

Uso interno Universitas / `universitasdev`, salvo acuerdo distinto. Añadir licencia formal en el repositorio si el repo es público.