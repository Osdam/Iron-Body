# Iron IA — contexto del entrenador: auditoría del «6 días → 4 días»

> **Seguimiento (12-11-2026).** El propietario aprobó la versión móvil y la
> corrección quedó hecha:
>
> - backend `e73fe02b`;
> - app `53b69f3` (envía `PlanContext.toJson()`);
> - 2.0.10+31, la primera versión con Shorebird.
>
> Contrato y comportamiento: [`CONTEXTO-DEL-ENTRENADOR.md`](CONTEXTO-DEL-ENTRENADOR.md).
> La prueba de caracterización de este informe se convirtió en
> `tests/Feature/Trainer/IronIaTrainerContextTest.php`, que fija el
> comportamiento nuevo. El resto del documento describe el estado del
> 11-11-2026 y se conserva tal cual.

Fecha: 11-11-2026. Base auditada: backend `origin/main` = producción `181075b3`;
app publicada `27553c3` (2.0.9+30) y `37ae697` (2.0.8), idénticas en `lib/features/trainer`.

**Estado: BLOCKED — MOBILE INPUT REQUIRED TO GUARANTEE CORRECTNESS**

No se modificó código de producción, no hubo commit, push ni despliegue y no se
tocó la app. Los siete campos de «Contexto para el plan» nunca salen del teléfono
ni se guardan en ningún sitio: el backend no puede conocerlos. Fallan las
condiciones 3 y 4 de la Fase 13.

---

## 1. Causa raíz

1. **App (todas las versiones desde que existe el flujo, 09-09-2026):**
   `IntegralPlanService.generate()` hace `postJson('/trainer/members/$memberId/integral-plan/generate', const {}, …)`.
   `PlanContext.toJson()` existe («Lo que viaja al servidor») pero **nadie lo llama**.
   No hay botón ni endpoint que guarde ese contexto: vive solo en el estado del
   widget y se pierde al salir de la pantalla. Verificado en 73bebd2, 8e8a437,
   37ae697 y 27553c3.
2. **Backend:** `IntegralPlanController::generate()` no lee el cuerpo. El contexto
   sale solo de lo persistido (`MemberContextAssembler::for()`).
3. **De dónde salen los 4 días:**
   - **Nivel 1** (14 de 19 planes): el modelo no recibe ninguna disponibilidad;
     el prompt le permite «Entre 2 y 6 días» y además le pide escribir la
     «disponibilidad» en `analysis.profile`. Elige el modelo. En producción las
     4 rutinas «intermedio» son de nivel 1 y las 4 tienen 4 días.
   - **Niveles 2–5:** `MemberContextAssembler::buildBrief()` sin meta nutricional
     → `principiante ⇒ 3`, cualquier otro ⇒ **4** («No consta la disponibilidad
     semanal: se asumen 4 días»). En producción, 11 de 11 corridas con potencia
     tienen `days_source = assumed`.
4. **Minutos por sesión:** el backend no tiene ese concepto (ni prompt, ni brief,
   ni validación).

No es un error del modelo ni de la presentación: el 6 nunca existió fuera del teléfono.

## 2. Qué envía la app (demostrado en ejecución)

Prueba de widget con pantalla, servicio y `ApiClient` reales de 27553c3 (copia
exportada con `git archive`; red sustituida por un `HttpOverrides` que captura,
y `BACKEND_BASE_URL` apuntando a un puerto local cerrado):

```
[REPRO-APP] POST /api/trainer/members/4242/integral-plan/generate
[REPRO-APP] cuerpo enviado: {}
[REPRO-APP] PlanContext.toJson() (nunca se llama): {"objective":"Perder grasa","training_level":"intermedio","days_per_week":6,"session_minutes":45,"injuries":"Ninguna","restrictions":"Ninguna reportada","observations":"Ninguna"}
[REPRO-APP] todas las peticiones API: [POST /api/trainer/members/4242/integral-plan/generate {}]
```

Archivo: `docs/iron-ia/repro/repro_contexto_plan_test.dart`. Límite de espera de
la app: 20 s (`ApiClient._timeout`), confirmado.

## 3. Qué está persistido y quién lo escribe

| Dato | Fuente que lee Iron IA | Quién puede escribirla |
|---|---|---|
| Objetivo | `members.goal`, si no la última guía publicada | el socio (perfil, registro); la guía la publica el entrenador |
| Nivel | `members.training_level` | solo el socio (`MemberProfileController`); el CRM no lo edita |
| Lesiones | `members.injuries` | el socio |
| Restricciones / alergias | `restrictions` de la última guía publicada | el entrenador, al publicar una guía |
| Peso, estatura, grasa, masa, medidas, observaciones | última valoración enviada | el entrenador (valoración profesional) |
| Días por semana | `nutrition_goals.training_days_per_week` (solo niveles 2–5) | el socio (calculadora); null en las 5 metas activas de socios con plan |
| Minutos por sesión | — | no existe |

Producción (solo lectura): de 46 socios con rutina de Iron IA, 4 tienen objetivo,
4 nivel y 3 lesiones en el perfil.

## 4. Qué usa hoy Iron IA

- Nivel 1: `member = {body_composition, measurements, goal, training{training_level, injuries}, nutrition_constraints, trainer_notes, age_years, gender}` + catálogo.
- Niveles 2–5: brief con `days_available` (supuesto), `training_level` (o
  «principiante» supuesto), restricciones de la guía publicada y del perfil de
  Iron IA, metas de la meta guardada o de la calculadora con la actividad que
  sale de los días supuestos.

## 5. Campos ignorados

Los siete del formulario: `objective`, `training_level`, `days_per_week`,
`session_minutes`, `injuries`, `restrictions`, `observations`. Objetivo y nivel
son obligatorios en la app para poder generar (`missingFor`) y aun así se
descartan: 58 de 64 rutinas salieron «principiante» (47 de nivel 1 con perfil
vacío, elegidas por el modelo; 11 supuestas por el motor). Un caso «intermedio»
lo inventó el modelo con el perfil vacío.

Consecuencias demostradas:
- **Seguridad:** una alergia escrita solo en el formulario no llega (brief sin
  restricciones, sin regla «PROHIBIDO NOMBRAR»). La misma alergia guardada en
  la guía publicada sí se aplica (`peanut` + regla de prohibidos).
- **Nutrición:** con potencias y sin meta guardada, las kcal se calculan con los
  días supuestos. Perfil ficticio (70 kg, 165 cm, 32 años, pérdida de grasa,
  intermedio): 1.940 kcal con 4 días («moderate») frente a 2.180 kcal con 6 («very_active»).

## 6. Correcciones implementadas

Ninguna en código de producción. Lo que queda en el worktree, sin commit:
- `tests/Feature/Trainer/IronIaTrainerContextReproductionTest.php` (6 pruebas de
  caracterización con clases reales; fijan el comportamiento ACTUAL).
- Este informe y `docs/iron-ia/repro/` (prueba de la app y las tres sondas de
  solo lectura de producción).

## 7. Cómo garantizar que se respeten (propuesta, pendiente de decisión)

**App 2.0.10 (dos archivos, sin cambiar la interfaz):**
- `integral_plan_service.dart`: `generate(token, memberId, {Map<String, dynamic> context = const {}})` y enviar `context` en vez de `const {}`.
- `integral_assessment_screen.dart`, `_generate()`: `_plans.generate(widget.token, widget.member.id, context: _contexto.toJson())`.
- En la misma versión, conviene validar días 1–7 y minutos en el formulario (hoy `int.tryParse` acepta 0 o 99).

**Backend (compatible hacia atrás; se despliega ANTES que la app):**
1. `IntegralPlanController::generate()` valida las siete claves de `PlanContext.toJson()`:
   `objective` ≤120, `training_level` ∈ {principiante, intermedio, avanzado},
   `days_per_week` 1–7, `session_minutes` (rango a decidir, p. ej. 20–180),
   `injuries` / `restrictions` / `observations` ≤1000. Las demás claves se siguen
   ignorando (la potencia nunca sale de la petición).
2. Una sola superposición en `MemberContextAssembler`: lo que el entrenador envía
   en ESA petición manda sobre perfil y guía, con procedencia
   (`sources.trainer_input`). No escribe el perfil del socio: no hay fuente paralela.
3. Nivel 1: añadir días, minutos y la observación solo si llegan (con `{}` el
   payload queda idéntico byte a byte y la prueba de paridad sigue valiendo) y
   reglas condicionales: número exacto de días, sesión dentro de N minutos,
   textos del entrenador como datos y nunca como instrucciones. Validación
   estructurada después: `count(days) == days_per_week` (si no, 422 reintentable,
   sin llamada extra) y duración estimada (series × (trabajo + descanso) +
   transiciones + calentamiento) ≤ minutos.
4. Niveles 2–5: `buildBrief()` toma los días del entrenador (`days_source = trainer`;
   7 ⇒ 6 con el supuesto declarado, como ya hace con la meta);
   `routineSpec` recibe un presupuesto de tiempo y la puerta de calidad lo comprueba;
   la meta calórica usa los días reales.
5. Respuesta: el mismo JSON; `analysis` dice valor recibido, valor aplicado y
   motivo de cualquier ajuste.
6. Corregir el defecto latente de valoración (§13).
7. Pruebas: validación y límites, precedencia, paridad del nivel 1 con `{}`,
   6/45 en niveles 1 y 5, alergia ⇒ regla de prohibidos, observación con una
   orden incrustada tratada como dato, 409/429/403 sin cambios.

**Orden:** backend con respaldo → comprobar que con `{}` nada cambia → app 2.0.10
(Play, App Store, APK de Empleados). Las versiones viejas siguen como hoy.

**Mientras tanto (sin tocar la app), decisión del propietario:**
- Avisar a los entrenadores de que el recuadro «Contexto para el plan» todavía
  no se usa.
- Alergias: corregir «Restricciones» y las comidas de la guía en el editor de
  guías antes de publicarla; una vez publicada, la siguiente generación la aplica.
  En el panel del plan integral la guía no tiene «Ver» (`onViewNutrition` no está
  conectado); se llega por Seguimiento → Guías (verificado en el código, no en un
  dispositivo).
- Días: el editor de rutinas de la app no permite cambiar el día de un ejercicio
  (`RoutineExerciseEdit.copyWith` no incluye `day`).

## 8–10. Nutrición, rutinas y potencias

Nada se tocó. Línea base en `origin/main`: 136/136 pruebas de Iron IA (2.479
aserciones), incluidas paridad del nivel 1, potencias, transferencia, contexto
y evaluador. Potencias en producción (solo lectura): 14 planes 1/1, 2 planes 2/2,
1 plan 3/3, 1 plan 4/4 y 1 plan 5/3.

## 11. Ejemplo de 6 días y 45 minutos

No se puede producir hoy por la vía real. Control positivo: cuando 6 días llegan
por la única vía persistida (meta nutricional), el motor de niveles 2–5 genera 6
días: Empuje A · Tracción A · Pierna A · Empuje B · Tracción B · Pierna B
(`IntegralPlanStrategy::SPLITS[6]`). Ajustar a 45 minutos requiere el contrato
propuesto: hoy no existe control de duración.

## 12. Pruebas

| Conjunto | Resultado |
|---|---|
| Línea base Iron IA (7 archivos) | 136/136, 2.479 aserciones |
| Reproducción backend (clases reales, OpenAI simulado) | 6/6, 98 aserciones |
| Reproducción app (pantalla y servicio reales de 27553c3) | 1/1 |

## 13. Concurrencia e idempotencia

- Cerrojo por socio (`Cache::lock`, 90 s) ⇒ 409; tope por entrenador ⇒ 429;
  socio ajeno ⇒ 403. Cubiertos por pruebas existentes, en verde.
- La app bloquea el doble toque (`_busy`). No hay clave de idempotencia: si la
  app corta a los 20 s y la generación termina después, un reintento crea otro
  borrador (no publicado).
- No hay transacción abierta durante la llamada al modelo.
- **Defecto latente, reproducido:** `persist()` vuelve a buscar la «última
  valoración» después de la llamada. Si entra una valoración durante la
  generación, el modelo planifica con 70 kg y la rutina queda en la valoración
  original, pero la guía se ancla a la nueva y copia 64 kg. En producción, 0 de
  64 pares. Corrección propuesta: pasar a `createDraft()` la valoración del
  contexto.

## 14. Compatibilidad móvil

Sin cambios. El contrato de la API queda intacto. La propuesta es compatible
hacia atrás.

## 15. Tiempos (producción, últimos 45 días)

| Potencias | n | p50 | p95 | máx. |
|---|---|---|---|---|
| 1/1 (con métricas) | 11 | 12,4 s | 14,1 s | 14,1 s |
| 2/2 | 1 | 8,4 s | — | — |
| 3/3 | 1 | 6,1 s | — | — |
| 5/3 correctas | 9 | 9,9 s | 11,8 s | 12,1 s |
| 5/3 inválidas | 2 | 11,9 s | 12,2 s | 12,2 s |

Límite de la app: 20 s. El nivel 1 es el más cercano al límite.

## 16. Archivos

- Backend, worktree `_worktrees/iron-ia-contexto/Iron-Body`, rama local
  `fix/iron-ia-contexto-entrenador` en 181075b3, sin commit:
  `backend/tests/Feature/Trainer/IronIaTrainerContextReproductionTest.php`, este
  informe y `backend/docs/iron-ia/repro/`. `.env` local de pruebas (sin secretos)
  y `vendor` clonado; los dos están ignorados por git.
- App: ningún archivo. La copia exportada se borró.
- Producción: tres sondas `SET SESSION … READ ONLY` + rollback, solo recuentos;
  se subieron a `/tmp` y se borraron. Ninguna escritura.

## 17. Commits y despliegue

Ninguno.

## 18. Riesgos pendientes

1. Alergias o restricciones escritas solo en el formulario no se aplican (riesgo de salud).
2. Los entrenadores creen que objetivo, nivel, días y minutos se usan.
3. El nivel 1 deja que el modelo elija el nivel con el perfil vacío.
4. Defecto latente de valoración durante la generación.
5. El nivel 1 está a ~6 s del límite de 20 s de la app.

## 19. APK / IPA

No se generó APK ni IPA. No se modificó ningún archivo Dart, Android o iOS del repositorio.
