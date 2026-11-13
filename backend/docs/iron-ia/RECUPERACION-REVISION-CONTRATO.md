# Contrato: revisión y publicación de propuestas de recuperación (portal del entrenador)

Opción A, aprobada el 13-11-2026: parche OTA de Shorebird sobre la app 2.0.10.
Los cambios del backend son **aditivos**: con las apps sin parche nada cambia,
salvo que una propuesta de recuperación ya no se puede publicar sin
confirmación.

Base de las rutas: `/api/trainer` (mismo token y permiso `routines.assign` que
publicar o retirar). Errores de negocio, como siempre:
`{"ok": false, "code": "...", "message": "texto para el entrenador", "retryable": bool}`.
Errores de validación, en el formato de Laravel:
`{"message": "...", "errors": {"campo": ["..."]}}`, con estado 422.

## 1. La rutina lleva su estado de recuperación

En `GET /members/{member}/routines`, `GET /routines/{routine}` y en las
respuestas de publicar, retirar y regenerar, cada rutina trae una clave nueva,
`recovery`:

```json
"recovery": null
```

o, si la rutina es una propuesta de recuperación:

```json
"recovery": {
  "campaign": "recuperacion-2026-11",
  "status": "proposed",
  "needs_confirmation": true,
  "proposed_days": 6,
  "original_routine_id": 115,
  "original_days": 4,
  "regenerations_left": 3
}
```

| Campo | Significado |
|---|---|
| `status` | `proposed`, `discarded`, `published` o `superseded` (una propuesta anterior que se sustituyó al regenerar) |
| `needs_confirmation` | `true` mientras no esté publicada |
| `proposed_days` | Días de la propuesta, o los confirmados al regenerar |
| `original_routine_id` / `original_days` | La rutina que sustituye y sus días |
| `regenerations_left` | Regeneraciones que quedan: máximo 3 que salgan bien y 6 llamadas al modelo en total, contando las fallidas |
| `replaced_by_routine_id` | Solo en `superseded`: la propuesta que la sustituyó |

Una rutina `superseded` sale como «Retirada» y **no se puede publicar ni
regenerar**: la app no ofrece esas acciones (el backend responde
`recovery_superseded` y `404`).

Estado de la rutina en el Seguimiento:

| Rutina | Estado que se muestra |
|---|---|
| `status = "Borrador"` y `published = false` | **Borrador** |
| `published = true` | Activa |
| `status = "Retirada"` | Retirada |

## 2. Publicar una propuesta exige la confirmación del entrenador

`POST /routines/{routine}/publish`

- Para una rutina normal, el cuerpo se ignora: igual que hoy.
- Para una propuesta de recuperación es **obligatorio**:

```json
{
  "recovery_confirmation": {
    "days_per_week": 4,
    "session_minutes": 45,
    "training_level": "intermedio",
    "objective": "Perder grasa",
    "injuries": "Ninguna",
    "availability_confirmed": true,
    "muscle_split_reviewed": true
  }
}
```

| Campo | Regla |
|---|---|
| `days_per_week` | Entero de 1 a 7. Días que el socio puede entrenar de verdad. La app NO lo rellena con los días de la propuesta: el entrenador lo escribe |
| `session_minutes` | Entero de 20 a 180 |
| `training_level` | `principiante`, `intermedio` o `avanzado` |
| `objective` | Texto de 1 a 180 caracteres |
| `injuries` | Texto de 1 a 2000 caracteres; «Ninguna» si no hay |
| `availability_confirmed` | Tiene que ser `true`: «Confirmé la disponibilidad con el socio» |
| `muscle_split_reviewed` | Tiene que ser `true`: «Revisé el reparto muscular, los ejercicios y la recuperación» |

**Rechazos (422) que NO publican nada:**

| `code` | Cuándo |
|---|---|
| `recovery_confirmation_required` | Falta la confirmación |
| `recovery_days_mismatch` | Los días confirmados no son los de la rutina (`min(6, días) != days_per_week`: el plan admite hasta 6 sesiones, así que un socio que puede 7 días lleva una rutina de 6 con un día de descanso). Mensaje: «La rutina tiene 6 días y el socio puede 4: ajústala en el editor o regénerala con 4 días.» |
| `recovery_level_mismatch` | El nivel confirmado no es el de la rutina |
| `recovery_minutes_mismatch` | Algún día no cabe en los minutos confirmados (estimación de `SessionTimeBudget`, con su tolerancia). El mensaje dice el máximo estimado |
| `recovery_superseded` | Es una propuesta anterior, sustituida al regenerar |

**Si todo cuadra:** se publica, se retira la original (sin borrar su historial),
la confirmación queda guardada y el socio recibe el aviso SSE de siempre.

## 3. Regenerar la propuesta con los datos confirmados

`POST /routines/{routine}/recovery/regenerate`

```json
{
  "days_per_week": 4,
  "session_minutes": 45,
  "training_level": "intermedio",
  "objective": "Perder grasa",
  "injuries": "Ninguna",
  "observations": "opcional, hasta 2000"
}
```

Las mismas reglas que la confirmación; `observations` es opcional. Pide las
dos llaves del entrenador: `routines.assign` (publicar) y `plans.generate`
(generar). Si la rutina no es una propuesta de recuperación, responde 404.

**Qué hace.** Genera una propuesta nueva, en borrador, con ese contexto. La
anterior queda retirada, sin borrarse. Responde **201**:

```json
{"ok": true, "data": { "...rutina nueva...": "", "recovery": { "...": "" } }}
```

Puede tardar **hasta ~60 s**: la app usa un plazo de al menos 90 s y muestra el
progreso.

**No gasta los cupos del entrenador.** Va por la campaña. Cada petición
reserva un intento ANTES de llamar al modelo: un fallo no gasta una de las 3
regeneraciones, pero sí uno de los 6 intentos, porque se paga igual.

**Rechazos:**

| Estado | `code` | Cuándo |
|---|---|---|
| 422 | `recovery_not_pending` | Ya está publicada |
| 429 | `recovery_regeneration_limit` | No quedan regeneraciones o intentos (o una petición simultánea gastó la última) |
| 409 | `generation_in_progress` | Otra generación en curso para ese socio. Reintentable; es el mismo código que la generación normal |
| 422 | `ai_*` | El modelo falló. Reintentable, como en la generación normal |
| 403 | sin `code`, `{"message": "..."}` | La propuesta es de otro entrenador o el socio ya no está asignado a él (como `GET /routines/{id}`) |

## 4. Descartar

`POST /routines/{routine}/retire` (existente). En una propuesta, la deja como
`discarded`; la original no se toca.

## 5. Editar antes de publicar

`PUT /routines/{routine}/exercises` (existente). Quitar los ejercicios de un día
recalcula `days_per_week`; la confirmación tiene que coincidir con lo que quede.

## 6. Otras vías

Las asignaciones del CRM y de la API de administración rechazan una propuesta
de recuperación pendiente con `recovery_publish_from_trainer`: solo se publica
desde el portal del entrenador, con su confirmación.
