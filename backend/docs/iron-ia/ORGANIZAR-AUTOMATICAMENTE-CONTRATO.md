# Contrato: «Organizar automáticamente» en el editor del entrenador (punto F, opción A)

Aprobado por el propietario el 14-11-2026. Propuesta: `PROPUESTA-F-ORGANIZACION-ENTRENAMIENTO.md`.

**Qué hace:** reparte por días, según los grupos musculares, los ejercicios que el entrenador
ya tiene en el editor. Usa SOLO `RoutineMuscleOrganizer`: el reparto `dayPlan`, la clasificación
`tagsOf`, la tabla `COMPATIBLE` y la comprobación `review`. No hay un segundo motor y no se llama al modelo.

**Qué no hace:**
- No añade ni quita ejercicios: los mismos entran y los mismos salen. Por eso no puede
  saltarse una lesión ni un ejercicio que el entrenador quitó.
- No cambia series, repeticiones, peso ni notas.
- **No guarda nada.** Es una vista previa: el entrenador la aplica en el editor y guarda con el
  `PUT /trainer/routines/{id}/exercises` de siempre, o la cancela.

## Petición

`POST /api/trainer/routines/{routine}/organize`

Lleva el mismo token y el mismo permiso que editar (`routines.assign`) y las mismas guardas que
el `PUT …/exercises`:
- la rutina es del entrenador;
- es un borrador sin publicar;
- el socio sigue asignado a él.

Cuerpo:
```json
{
  "days_per_week": 4,
  "session_minutes": 45,
  "exercises": [
    {"id": 812, "exercise_id": 45, "sets": 3, "reps": "10-12", "weight": null, "notes": null,
     "day": "Día 2 · Tracción A", "pinned": false}
  ]
}
```

| Campo | Regla |
|---|---|
| `exercises` | La lista ACTUAL del editor, con lo que aún no se ha guardado y en su orden. Las mismas reglas que el PUT: de 1 a 60 ejercicios, y `exercise_id` del catálogo |
| `exercises.*.pinned` | Opcional. Con `true`, el ejercicio se queda en su `day` si ese día existe en el reparto nuevo; si no existe, se reparte como los demás |
| `days_per_week` | Opcional, de 1 a 6. Por defecto, el número de días distintos que hay en la lista (como mínimo 1). Es la disponibilidad que el entrenador confirma |
| `session_minutes` | Opcional, de 20 a 180. Solo sirve para avisar de los días que no caben |

## Respuesta 200

```json
{"ok": true, "data": {
  "days_per_week": 4,
  "days": [
    {"name": "Día 1 · Torso A", "focus": "Torso A", "muscles": "pecho, espalda y hombro",
     "exercise_count": 3, "estimated_minutes": 38.5}
  ],
  "exercises": [
    {"id": 812, "exercise_id": 45, "name": "Remo con barra", "muscle_group": "Espalda",
     "sets": 3, "reps": "10-12", "weight": null, "notes": null, "pinned": false,
     "day": "Día 1 · Torso A", "previous_day": "Día 2 · Tracción A", "moved": true}
  ],
  "review": {"ok": true, "min_share": 0.8, "problems": []},
  "warnings": ["El día 2 («Pierna A») dura unos 52 min y la sesión es de 45."],
  "changed": true
}}
```

- **`exercises`:** vienen agrupados por día, en el orden del reparto. Dentro de cada día se
  conserva el orden relativo que traían. Son exactamente los mismos ejercicios: el mismo
  `id`, para que el PUT actualice cada fila en su sitio.
- **`moved`:** indica si el ejercicio cambió de día. **`changed`:** con `false`, nada cambia.
- **`review`:** la misma comprobación que la generación (cuota mínima de 0,6 por día). Es
  informativa: no bloquea.
- **`warnings`:** avisos de duración, cuando llega `session_minutes`, y de días vacíos.

## Errores

| Estado | `code` | Cuándo |
|---|---|---|
| 422 | `organize_more_days_than_exercises` | Hay más días pedidos que ejercicios |
| 422 | `routine_published` | La rutina ya está publicada; solo se organizan borradores |
| 422 | `routine_not_owned` / `assignment_inactive` | Es de otro entrenador o el socio ya no está asignado |
| 422 | `invalid_exercise_id` / `routine_empty` | Las mismas comprobaciones de la lista que el PUT |
| 422 | (validación de Laravel) | `{"message", "errors": {...}}` |

El formato de los errores es el de siempre: `{"ok": false, "code", "message", "retryable"}`.

## Orden de despliegue

Primero el backend; después el parche de la app. Si el parche llega antes, el endpoint
responde 404 y la app tiene que ocultar el botón o explicar el error sin romper el editor.
