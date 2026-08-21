# Documentación técnica — Tutor IA «Agente Actas de Entrega»

**Fecha:** 20 de agosto de 2026  
**Proyecto:** Pasarela widget chat ↔ WordPress ↔ BFF (Cloud Run)  
**Curso:** [Actas de entrega en la Administración Pública](https://universitas.academy/cursos/actas-de-entrega/)  
**Estado:** En producción en Universitas Academy (curso LearnDash ID `46067`)

---

## 1. Resumen ejecutivo

Se consolidó el Tutor IA flotante para el curso de Actas de Entrega: interfaz pulida, persistencia de historial entre páginas del LMS, snippet PHP único para WordPress, mejoras de Markdown en burbujas y ajustes de UX para no bloquear el botón **Next** en cuestionarios.

**Evidencia en producción:**  
https://universitas.academy/cursos/actas-de-entrega/

---

## 2. Arquitectura

```
[Alumno logueado + inscrito en curso 46067]
        │
        ▼
[Widget FAB]  HTML + CSS + JS (inyectados en wp_footer)
        │  POST admin-ajax.php
        │  action=ua_chat_send_message + nonce + history
        ▼
[Snippet PHP / WPCode]  Seguridad LearnDash + sanitización
        │  POST JSON { message, session_id: "wp-{userId}" }
        ▼
[BFF Cloud Run]  academy-ae-gateway-.../api/chat
        │
        ▼
[Agente / Vertex AI]
```

- Solo usuarios autenticados (`wp_ajax_*`, sin `nopriv`).
- Acceso validado con `sfwd_lms_has_access(46067, user_id)`.
- Widget solo en páginas del curso objetivo (`learndash_get_course_id()`).

---

## 3. Archivos del proyecto

| Archivo | Rol |
|---------|-----|
| `index.html` / `style.css` / `script.js` | Prototipo local y fuente de verdad del frontend |
| `wp-snippet-tutor-ia.php` | Snippet PHP único para pegar en WPCode (backend + CSS/HTML/JS embebidos) |
| `backend.php` | Referencia / evolución del gateway (BFF) |

**Despliegue WP:** fragmento tipo **PHP** en WPCode con el contenido de `wp-snippet-tutor-ia.php`. Desactivar snippets HTML viejos del widget para no duplicar el FAB.

---

## 4. Trabajo realizado (sesión del 20/08/2026)

### 4.1 Coreografía visual (FAB + tooltip)

- Pulso suave (`softPulse` / `.ua-animacion-pulso`) en el botón flotante.
- Tooltip a los 7 s; se oculta a los 17 s.
- FAB corporativo: fondo `#03035b`, icono blanco.
- Al abrir el chat se pausa el pulso; al cerrar se reanuda en la misma página.
- Tras cerrar el tooltip, el pulso **se mantiene**.

### 4.2 Persistencia de historial (`localStorage`)

- **Problema:** en LearnDash (curso → módulo → tema → cuestionario) cada navegación recarga la página y el `chatHistory` en RAM se perdía.
- **Solución:** `localStorage` con clave `ua-chat-history-46067`, tope **N = 80** mensajes.
- Al cargar: restaurar burbujas; no repetir bienvenida si ya hay historial.
- Errores de red genéricos no se persisten.

### 4.3 Snippet unificado para WordPress

- Estructura: AJAX + inyección en `wp_footer` solo en el curso con matrícula.
- Nowdocs `UA_CHAT_CSS` / `UA_CHAT_HTML` / `UA_CHAT_JS`.
- Corregido bug de nowdoc (identificador pegado al contenido), que provocaba en WPCode:  
  `syntax error, unexpected token , expecting ;`.

### 4.4 Markdown en respuestas del agente

- Antes solo `**negrita**` y `\n` → `<br>`; los `##` / `###` se veían crudos.
- Ahora:
  - Encabezados `#`…`######` → estilo título **sin mostrar `#`**
  - Listas `*`, `-`, `•`, `^` → viñetas
  - Negritas y saltos de línea se mantienen

### 4.5 UX en cuestionarios (conflicto con botón Next)

- **Problema:** FAB en esquina inferior derecha tapaba **Next** a zoom 100%.
- **Solución:** detectar quiz LearnDash (`sfwd-quiz` / `.wpProQuiz_content`, etc.).
  - Clase `ua-chat-en-quiz` → `bottom: 120px`.
  - Tooltip distinto: *¿Dudas con este cuestionario? Estoy aquí para ayudarte.*
  - En lecciones: *¿Dudas con esta lección? Estoy aquí para ayudarte.*
- **Efecto colateral:** al subir el FAB, el chat se cortaba arriba (el `max-height` seguía pensado para `bottom: 24px`).
- **Ajuste:**

```css
.ua-chat-widget.ua-chat-en-quiz .ua-chat-window {
  max-height: calc(100vh - 220px);
  max-height: calc(100dvh - 220px);
}
```

---

## 5. Configuración relevante

```text
UA_CHAT_COURSE_ID     = 46067
UA_CHAT_BFF_URL       = https://academy-ae-gateway-919237484930.us-east1.run.app/api/chat
UA_CHAT_NONCE_ACTION  = ua_chat_send_message
```

Frontend espera `window.uaChatConfig = { ajaxUrl, nonce, action }` inyectado por PHP.

---

## 6. Criterios de aceptación (checklist)

- [x] Widget visible en curso de Actas (alumno inscrito y logueado).
- [x] Historial sobrevive al pasar de tema ↔ cuestionario (mismo navegador).
- [x] Tooltip y pulso en lecciones; mensaje de cuestionario en quizzes.
- [x] Next usable a zoom 100% en cuestionarios.
- [x] Ventana de chat no se corta arriba en quizzes (`max-height` ajustado).
- [x] Respuestas sin `#` visibles; negritas y listas legibles.
- [x] Enlace de curso en producción: [universitas.academy/cursos/actas-de-entrega](https://universitas.academy/cursos/actas-de-entrega/)

---

## 7. Limitaciones conocidas / siguientes pasos

1. Historial en `localStorage` es por navegador/dispositivo (no multi-device).
2. El BFF usa `session_id = wp-{userId}`; la UI y el servidor pueden divergir si se limpia storage sin resetear sesión del agente.
3. Opcional: botón «Nueva conversación»; persistencia en `user_meta`/BFF; afinar `bottom`/`max-height` si Next sigue justo en ciertas resoluciones.
4. Prompt del agente: desalentar encabezados Markdown refuerza el render del frontend.

---

## 8. Cómo actualizar producción

1. Abrir `wp-snippet-tutor-ia.php` (versión con nowdocs correctos + quiz + markdown + max-height).
2. WPCode → fragmento PHP del Agente Actas → reemplazar todo el código → Activar.
3. Probar en una lección y en un cuestionario a zoom 100%.
4. Hard refresh (Ctrl+F5) si el navegador cachea JS/CSS embebido.

---

## 9. Referencias

- Curso en producción: https://universitas.academy/cursos/actas-de-entrega/
- LearnDash course ID: `46067`
- Snippet de despliegue: `wp-snippet-tutor-ia.php`
