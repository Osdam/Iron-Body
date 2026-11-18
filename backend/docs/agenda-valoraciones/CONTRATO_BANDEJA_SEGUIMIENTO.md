# Contrato: bandeja de valoraciones recibidas (Seguimiento)

**Versión 1 (18-11-2026).**

**Causa del problema:** la pestaña «Seguimiento» no tenía listado; solo un buscador y el panel de un socio. Los avisos SSE llegaban, pero no había ninguna lista que refrescar.

## Endpoint

`GET /api/admin/valuations/follow-ups/recent?page=1&per_page=5&q=`
- **Permiso:** `valuations.view` o `view_all`. El token compartido no entra.
- **Alcance:** `view_all` ve todo; una cuenta vinculada a un entrenador solo ve a los socios que tiene asignados.
- **Filas:** las valoraciones ENVIADAS por los entrenadores, es decir, `professional_assessments` raíz con `status` `submitted` o `amended` y `submitted_at`. Salen aunque no hubiera cita.
- **Orden:** `submitted_at DESC, id DESC`, estable.
- **`per_page`:** 5 por defecto (1..50).
- **`q`:** opcional, de 2 a 80 caracteres; filtra por nombre del socio (sin mayúsculas ni tildes) o por documento.

```json
{ "ok": true,
  "data": [ {
    "assessment_uuid": "…",
    "member": {"id": 12, "name": "María González"},
    "trainer": {"id": 3, "name": "Carlos Pérez"} | null,
    "completed_at": "2026-11-18T15:32:00-05:00",
    "appointment": {"uuid": "…", "status": "completed"} | null,
    "is_new": true,
    "reviewed_at": "ISO" | null,
    "reviewed_by_name": "…" | null,
    "follow_up": {"status": "al_dia|proximo|vencido|agendado|pausado|sin_valoracion",
                  "next_suggested_on": "2026-12-18" | null,
                  "next_appointment_starts_at": "ISO" | null}
  } ],
  "meta": {"current_page": 1, "last_page": 3, "per_page": 5, "total": 12, "unreviewed": 2} }
```

- **`meta.unreviewed`:** las «Nuevas» dentro del alcance, sin filtrar por `q`.
- **Sin medidas** ni datos de salud: el detalle completo se pide con el `GET …/members/{id}/follow-up` que ya existe.

`POST /api/admin/valuations/follow-ups/{assessmentUuid}/reviewed`
- Marca la valoración como «Revisada»: guarda `admin_reviewed_at` y quién lo hizo.
- **Idempotente:** si ya estaba revisada, conserva la primera marca.
- **Respuesta:** `{ok, data: <fila>}`.
- **Fuera de alcance o inexistente:** 404.

## «Nueva» y «Revisada»
- **Columnas** en `professional_assessments`: `admin_reviewed_at` y `admin_reviewed_by_admin_id`, ambas NULL. Persisten en la base y no dependen de la pestaña del navegador.
- **Las valoraciones anteriores al despliegue** se marcan como revisadas en la migración, para no mostrar 100 «nuevas» falsas.

## Tiempo real (CRM)
- **Canal:** el de siempre, `/api/admin/notifications/stream`, con los avisos `type=valuation` de `source_event` `valuation_completed`, `valuation_assessment_unlinked` y `valuation_assessment_review`, que se escriben después del commit.
- **Refresco:**
  - si estás en la página 1, sin búsqueda y sin ninguna tarjeta abierta, se relee la página 1;
  - si no, aparece el aviso «Hay nuevas valoraciones disponibles», que vuelve a la página 1 y relee.
- **Al reconectar el canal o volver a la pestaña:** se relee la página actual, para reconciliar con la base.
- **Deduplicación:** por el id del aviso. Las filas se identifican por `assessment_uuid`.
