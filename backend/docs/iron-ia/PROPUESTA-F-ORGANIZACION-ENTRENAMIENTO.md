# Punto F: organización automática y manual del entrenamiento (propuesta)

Evaluación de solo lectura del 13-11-2026, sobre el backend `340fb8c9` y la app `fc53be5`. Es una propuesta para que el
propietario decida; no se ha implementado nada. **[H]** marca lo que se dedujo del código sin reproducirlo.

## Estado de los defectos cercanos (actualizado el 14-11, 00:20 UTC)

| # | Defecto | Estado |
|---|---|---|
| 1 | En el editor del entrenador, un ejercicio que se sube o se baja por encima del límite de su día aparecía en otro día al reabrir | **Reproducido y corregido.** Al guardar, `RoutineDraftEditor` ordena las filas agrupadas por día. Prueba: `test_mover_un_ejercicio_por_encima_del_limite_de_su_dia_no_le_cambia_el_dia` |
| 2 | Pestañas de días sin texto en las rutinas de Iron IA de la app del socio | **Confirmado en producción.** Las 53 rutinas de Iron IA publicadas guardan sus días con `name` y sin `day`. `RoutineResource` devuelve `day: ''` y la app pinta `Tab(text: d.day)` (`routine_days_screen.dart:90`) y «Entrenar …» (:255). **Sin corregir: está fuera del alcance aprobado.** Arreglo propuesto, solo en el backend y sin parche de la app: en `RoutineResource::serializeDays`, que `day` recurra a `name` (o a «Día N») cuando falte |
| 3 | Guardar en el editor borra el `objective` de cada día (enfoque y calentamiento) | Sin corregir; puede esperar |

## Situación actual

**El organizador solo lo usa la generación.**
- `RoutineMuscleOrganizer` tiene tres piezas:
  - el reparto: `SPLITS` (:48-55), `split()` (:74) y `dayPlan()` (:89);
  - la clasificación: `tagsOf()` (:106);
  - la comprobación: `review()` (:135), con un umbral de 0,6.
- Lo usan `IntegralPlanGenerationService` (`plannedSplit` :501, `splitCheck` :520-544) e `IntegralPlanStrategy`.
- No lo usan el editor, la publicación ni la app del socio.
- No tiene ninguna función que reparta ejercicios ya elegidos.

**Entrenador: el editor de borradores** (`routine_draft_editor.dart`):
- «No es un constructor»: permite cambiar series, reps y nota; subir y bajar; y quitar, cambiar o añadir. Lo añadido cae en el último día.
- **No se puede cambiar el día de un ejercicio**: `copyWith` conserva `day` (`integral_plan.dart:372`).
- **Después de editar, nadie vuelve a llamar a `review()`.**
- **Bajar de 6 a 4 días quitando días enteros** deja Empuje A, Tracción A, Pierna A y Empuje B: dos días de empuje y uno de tracción. No es el reparto de 4 días (Torso A, Pierna A, Torso B, Pierna B). La alternativa es «Regenerar», que es una llamada pagada con tope.

**Socio:**
- «Crear rutina» es un nombre y una lista plana. Envía `{name, exercise_ids}` y no guarda días: se entrena todo en una sola sesión. Después no se puede editar.
- «Entrenamiento de hoy» va rotando rutinas asignadas enteras. Cambiarlo necesitaría tocar un contrato y una migración, así que queda fuera.

## Problema

- **Entrenador:**
  - no puede pasar un ejercicio a otro día;
  - no puede reorganizar la rutina con otro número de días sin pagar una regeneración;
  - no recibe ningún aviso de desequilibrio después de editar.

  Esto pesa justo en la revisión de las 29 propuestas de recuperación.
- **Socio:** su rutina es un bloque sin días. No se ha medido cuánta demanda hay.

## Opciones

**A. Organizar en el editor del entrenador, con vista previa calculada en el servidor (recomendada).**
- **Backend:**
  - `RoutineMuscleOrganizer::arrange(exercises, days, level, pinned)`: es determinista y no llama al modelo. Reutiliza `dayPlan`, `tagsOf`, `COMPATIBLE` y `review`.
  - `POST /api/trainer/routines/{routine}/organize`, con `routines.assign` y las guardas del editor. Es una vista previa: **no escribe nada**; se guarda con el `PUT …/exercises` de siempre.
  - `muscle_review` en el GET y en el PUT del borrador.
- **App:**
  - botón «Organizar automáticamente», que pide el número de días (en la recuperación, los confirmados);
  - «Mover a…» en cada fila, que fija ese ejercicio;
  - aviso con los `problems` de `review`.

**B. A, más la rutina del socio repartida por días.**
- `POST /app/routines/organize`.
- `store()` aceptaría `days_per_week` y `exercises[].day`, solo de forma aditiva.
- Selector de días en «Crear rutina».
- Editar después queda fuera; con ello pasaría a tamaño L.

**Descartado: reglas en Dart.** Duplicaría `SPLITS`, `MUSCLE_TAGS`, `COMPATIBLE` y el umbral. El socio y el entrenador verían repartos distintos, y cada cambio de tablas exigiría un parche.

## Recomendación

La **opción A**, con prioridad media.

- **Antes:** corregir el defecto 2. Es solo backend y no necesita parche.
- **La B:** solo si una sonda de solo lectura muestra que los socios usan «Crear rutina» con muchos ejercicios.

**Plan:**
1. `arrange()` con sus pruebas unitarias.
2. La ruta `organize`, `muscle_review` y las pruebas de feature, con una revisión ligera de seguridad.
3. Despliegue del backend y prueba con un borrador de QA.
4. Cambios en el editor de la app.
5. Pruebas de widget, iPhone 12, parche en staging y paso a stable con autorización.

## Encaje con OTA y backend

- **La A cabe en un parche:** solo Dart, sin assets ni código nativo.
- **Iconos:** `auto_awesome_rounded`, `warning_amber_rounded` y `swap_horiz` ya están en la fuente de la release (`test/ota/iconos_release.txt`).
- **[H] Iconos del propio framework:** no usar un `DropdownButton` sin `icon:`, porque usa `Icons.arrow_drop_down`, que no está en la fuente. Usar una hoja inferior con `ListTile`, como el selector que ya existe.
- **Hace falta un endpoint nuevo.** Orden: backend, comprobación y después el parche. Si el parche llega antes, el botón recibe un 404: hay que ocultarlo o explicar el error sin romper la pantalla.
- **Clientes 2.0.9:** el contrato solo añade campos, así que siguen funcionando igual.

## Esfuerzo y riesgos

**Esfuerzo:**
- A: tamaño M (backend S, app entre S y M, y medio día para el ciclo del parche).
- B: otra M.
- Sin migración de datos. La vuelta atrás es un parche de reversión más revertir el backend.

**Riesgos y cómo evitarlos:**

| Riesgo | Cómo evitarlo |
|---|---|
| Rutina de 6 días mal repartida | Prueba de equilibrio, y `review(arrange(x))` en verde con una rutina válida |
| El entrenador pierde un orden que hizo a mano | La vista previa no se guarda sola, se respetan los ejercicios fijados y el endpoint no escribe |
| Pasar de 6 a 4 días sin quitar ejercicios sobrecarga los días | Basta el aviso; en la recuperación ya lo bloquea `recovery_minutes_mismatch` |
| Filas sin día | La app no debe guardar filas sin día |
| Ids de fila | Hay que conservarlos para que el PUT actualice en el sitio |

## Decisiones para el propietario

1. **Alcance:** solo A (recomendado) o A más B.
2. **Defecto 2:** ¿corregirlo ya en el backend? Recomendado, antes de revisar las 29 propuestas.
3. **Aviso de `review()`:** ¿solo informativo (recomendado) o bloqueante?
4. **Más días que ejercicios:** ¿422 (recomendado) o días vacíos con aviso?
5. **Demanda del socio:** ¿sonda de solo lectura de las rutinas propias antes de decidir la B?
6. **Prioridad:** ¿frente al apartado 4 del Studio y frente a la campaña de recuperación?
