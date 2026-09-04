# Tutor Analytics 360 — Plan de implementación

Telemetría, feedback y FinOps para el Tutor IA (curso Actas de Entrega, ID `46067`).

## Objetivos

1. **FinOps granular:** tokens por alumno/curso; coste USD en BigQuery (no en el BFF).
2. **Auditoría académica:** mapa de calor por lección/módulo LearnDash.
3. **Feedback:** 👍/👎 por respuesta (`message_id`).

## Decisiones de diseño

| Tema | Decisión |
|------|----------|
| Eventos | `chat` y `feedback` separados |
| Correlación | `message_id` (UUID) por turno |
| Contexto LD | Armado en **PHP** (CPTs + `learndash_get_course_id`, etc.) |
| FinOps $ | Solo en **vista SQL BigQuery**; BFF reporta tokens |
| Branches | `feature/analytics-360` (WP + BFF) |

## Fases

0. Gobernanza (este doc + branch)
1. WP: `user_context` + contrato `chat` (+ parse `message_id`/`usage`)
2. BFF: `message_id`, usage, logs, API feedback
3. UI thumbs
4. Log Router → BigQuery + vista (con `cost_usd`)
5. Looker Studio
6. Producción

## Contrato (resumen)

**Chat WP→BFF:** `event_type`, `message`, `session_id`, `user_context`  
**BFF→WP:** `response`, `message_id`, `usage`  
**Feedback:** `event_type`, `message_id`, `session_id`, `feedback_score`, `user_id`

## Estado en esta branch

- [x] Fase 0: branch `feature/analytics-360` + este doc
- [x] Fase 1 (WP): `ua_chat_construir_user_context()`, payload `event_type`+`user_context`, parse `message_id`/`usage`, JS `data-message-id` + `post_id`
- [ ] Fase 2: BFF (repo aparte)
- [ ] Fase 3: UI thumbs
- [ ] Fases 4–6: GCP / Looker / prod
