# Iron IA — el contexto del entrenador

Cómo llega a Iron IA lo que el entrenador escribe en **Valoración integral →
Contexto para el plan**, y cómo se respeta en la rutina y en la guía nutricional.
Diagnóstico que lo motivó: [`CONTEXTO-DEL-ENTRENADOR-AUDITORIA-2026-11-11.md`](CONTEXTO-DEL-ENTRENADOR-AUDITORIA-2026-11-11.md).

## 1. El contrato

`POST /api/trainer/members/{member}/integral-plan/generate`. El cuerpo es
`PlanContext.toJson()` de la app (`lib/features/trainer/models/plan_context.dart`),
que **omite los campos vacíos**:

| Clave | Tipo y límites | Qué es |
|---|---|---|
| `objective` | texto ≤ 180 | Objetivo principal (los chips de la app) |
| `training_level` | `principiante` · `intermedio` · `avanzado` | Nivel real del socio |
| `days_per_week` | entero 1–7 | Sesiones por semana que indica el entrenador |
| `session_minutes` | entero 20–180 | Minutos disponibles por sesión |
| `injuries` | texto ≤ 2000 | Lesiones, molestias o limitaciones |
| `restrictions` | texto ≤ 5000 | Alergias, restricciones o alimentos que evita |
| `observations` | texto ≤ 2000 | Observación del entrenador para este plan |

**Por qué esos topes.** Son los de las fuentes que la app **precarga** en el
formulario (`PlanContext.from`) y devuelve tal cual:

- el objetivo de una guía, hasta 180;
- las lesiones del socio, hasta 2000;
- las restricciones de una guía publicada, hasta 5000.

Las restricciones de las guías de los niveles 2–5 crecen solas en cada ciclo.
Con topes más bajos, la 2.0.10 rechazaría un texto que el entrenador no escribió.

Validación: `App\Http\Requests\Trainer\GenerateIntegralPlanRequest`.

- Los mensajes están en español.
- Un 422 se devuelve **antes** de llamar al modelo y no crea corrida.
- Días y minutos se convierten a entero: «6» vale como 6.
- Cualquier otra clave se ignora: la **potencia nunca sale de la petición**.

**`{}` sigue siendo válido.** Las apps hasta la 2.0.9 envían `{}` y generan
exactamente como antes: en el nivel 1, el payload a OpenAI es el mismo byte a
byte (`IntegralPlanLevelOneParityTest`).

## 2. Precedencia (solo para esa generación)

`MemberContextAssembler::withTrainerInput()` superpone lo recibido al contexto
que ya armaba `for()`:

- lo que el entrenador envía **manda** sobre el perfil del socio y la última
  guía publicada;
- lo que no envía sigue saliendo de donde salía (perfil, valoración, guía);
- **no se escribe** en el perfil del socio ni en su guía: no nace una segunda
  fuente de verdad;
- la observación va aparte, en `trainer_notes.plan_note`; las notas de la
  valoración siguen llegando sin cambios.

Consecuencia conocida: si el entrenador **borra** un dato precargado (p. ej. las
lesiones del perfil), la app no envía nada para ese campo y vuelve a valer lo
guardado. Es lo prudente con lesiones y alergias.

## 3. Cómo se aplica en cada potencia

La potencia decide la **profundidad**; el contexto, las **características del
socio**. Las dos cosas no se mezclan.

| | Nivel 1 (prompt v1) | Niveles 2–5 (por secciones) | Mixto (rutina 1, nutrición ≥ 2) |
|---|---|---|---|
| Días | `training.days_per_week` + regla «EXACTAMENTE N días» | la división de la rutina se arma con N (`days_source = trainer`) | regla «EXACTAMENTE N días»; si no, se repara una vez |
| Minutos | `training.session_minutes` + regla con calentamiento y tope de ejercicios | tope de ejercicios por día y línea de tiempo en cada día | regla en la rutina sencilla |
| Nivel | regla: `routine.level` = el indicado | el brief usa el indicado | regla |
| Alergias | `nutrition_constraints.restrictions` + lista «PROHIBIDO NOMBRAR» | `restrictions` del brief → prohibidos y puerta de calidad | igual que 2–5 |
| Datos, no órdenes | regla explícita | regla 8 de la cabecera | regla 8 |

Las reglas del nivel 1 se **añaden al final** del prompt y solo si su dato llegó;
sin contexto, el prompt es el de siempre.

**7 días ⇒ 6.** La división de la rutina (`IntegralPlanStrategy::SPLITS`) llega
hasta 6 sesiones (`MemberContextAssembler::MAX_PLAN_DAYS`). Con 7 se planifican 6 y
se dice en el análisis y en los supuestos. Es la misma regla que ya aplicaba la
meta nutricional.

**Nutrición.** Con días reales, la meta calórica estimada (cuando no hay meta
guardada) usa la actividad de esos días. Ejemplo ficticio: 4 días supuestos =
1.940 kcal; 6 días del entrenador = 2.180 kcal.

## 4. La comprobación del resultado

`IntegralPlanTrainerContext::apply()` actúa sobre el plan ya validado, en todas
las potencias y también en `preview()`. **Con `{}` no hace nada.**

1. **Días:** la rutina tiene exactamente los días aplicados. Si no, **no se
   guarda nada** y la API responde 422 `ai_context_mismatch` (reintentable). No
   se recorta ni se rellena una rutina para que «parezca» de 6 días.
2. **Nivel:** si lo indicó el entrenador, la rutina lleva ese nivel.
3. **Duración:** cada sesión se estima con `SessionTimeBudget` (§5). Si no cabe,
   se ajusta sin otra llamada al modelo y se dice en `analysis.limitations`.
4. **Alergias y restricciones:** el texto del entrenador se traduce a las
   restricciones que conoce el léxico (`PlanSafetyLexicon`: maní, frutos secos,
   lácteos, lactosa, gluten, huevo, pescado y mariscos, cerdo, carne roja,
   vegetariano, vegano…).
   - Ninguna **comida** puede nombrar un alimento de esas restricciones. Si lo
     hace, **no se guarda** (422 `ai_context_mismatch`, con un mensaje para el
     entrenador).
   - El análisis dice contra qué se comprobó. Lo que el léxico **no** reconoce
     (p. ej. «alergia a la fresa») no se puede comprobar automáticamente, y el
     análisis lo dice para que el entrenador revise las comidas antes de
     publicar.
   - «Ninguna», «N/A» o «Sin restricciones» no añaden aviso. Se exige la frase
     entera: «No toma lácteos» sí es una restricción.
   - No se revisan las recomendaciones ni las notas: nombrar un alimento para
     evitarlo no es servirlo.

El informe (recibido, aplicado, días, estimaciones y ajustes) queda en
`nutrition_ai_runs.response_json.trainer_context`. La respuesta JSON a la app
**no cambia**.

## 5. La duración estimada (`SessionTimeBudget`)

Es una **estimación**, no un cronómetro. Supuestos:

- **Calentamiento:** 5 min si la sesión es de ≤ 30 min; 8 min en las demás.
- **Trabajo por serie:** 3 s por repetición, entre 20 y 75 s.
  - «8-10» cuenta la media;
  - «por lado» cuenta el doble;
  - «30 s» o «1 min» cuentan su tiempo;
  - sin número («al fallo»), 45 s.
- **Descanso:** el que el socio hace **de verdad**, n − 1 descansos para n
  series. La app arranca siempre un temporizador de 90 s al completar una serie
  (`active_workout_screen.dart`, `_startRest(90)`), así que se cuenta
  `máx(90, rest_seconds)`:
  - un descanso menor escrito en la nota no acorta la sesión;
  - uno mayor sí la alarga.
- **Cambio de ejercicio:** 60 s.
- **Tolerancia:** 10 % sobre lo disponible.

Ajuste, de lo más suave a lo más visible, **parando en cuanto cabe**:

1. descansos de más de 90 s → 90 s; la nota «Descanso N s» de los niveles 2–5
   se actualiza;
2. más de 3 series → 3, empezando por el último ejercicio;
3. 3 series → 2, igual;
4. quitar ejercicios del final, sin bajar de 3.

Ejemplo fijado en las pruebas: 6 ejercicios de 4×«8-10» con 90 s suman 51,8 min
para 45. Bajar dos ejercicios a 3 series basta (47,9 min); los 6 se quedan.

Si ni así cabe, se dice («revísala antes de publicar»). Las filas de
`routine_exercises` se crean después del ajuste, así que la app del socio ve lo
mismo que el borrador.

Límites conocidos:

- no mide la técnica ni el tiempo real de cada máquina;
- no hay cota inferior: una sesión de 35 min para 60 disponibles se acepta;
- el tope que se le da al modelo nunca pasa de 10 ejercicios por día.

## 6. Concurrencia

La valoración se elige **una vez**, al empezar (`lastAssessmentFor`), y esa misma
instancia alimenta el prompt y la guía que se guarda. Antes, `persist()` volvía a
buscar «la última» después de la llamada al modelo: si entraba otra valoración
mientras respondía, el plan quedaba partido entre dos. En producción no había
pasado (0 de 64 pares), pero era posible.

Todo lo demás no cambia:

- cerrojo por socio (409);
- tope por entrenador (429);
- asignación activa (403);
- sin transacción abierta durante la llamada al modelo.

## 7. E2E con OpenAI real

`tests/Feature/Trainer/IronIaContextLiveTest.php` usa la ruta HTTP real, OpenAI
real, socios ficticios en SQLite local y el catálogo de ejercicios exportado de
producción (solo el catálogo). Se salta salvo con `IRON_IA_LIVE=1`:

```bash
IRON_IA_LIVE=1 IRON_IA_LIVE_KEY_FILE=/ruta/600/clave.env \
IRON_IA_LIVE_CATALOG=/ruta/catalogo.json IRON_IA_LIVE_OUT=/ruta/resultados.jsonl \
php vendor/bin/phpunit --group live tests/Feature/Trainer/IronIaContextLiveTest.php
```

- **Clave:** el fichero de la clave es una línea `OPENAI_API_KEY=…`, con modo
  600. Se borra al terminar.
- **Coste aproximado:** 0,01 USD por caso de nivel 1 y 0,05 USD por caso de
  nivel 5.

### Resultado del 12-11-2026

Código final: `repro/e2e-openai-real-2026-11-12-final.jsonl`. La tanda anterior
a la revisión, en `…-antes-de-la-revision.jsonl`.

| Caso | Resultado | Días | Min. estimados | Latencia |
|---|---|---|---|---|
| N1 · 6 d · 45 min · intermedio | 201 | 6 | 35,2–35,8 | 16,3 s |
| N1 · 3 d · 30 min · principiante | 201 | 3 | 26,4–27,6 | 11,0 s |
| N1 · 4 d · 45 min · ganar masa | 201 | 4 | 30,1–35,8 | 15,2 s |
| N1 · 5 d · 60 min · avanzado | 201 | 5 | 35,3–36,2 | 14,1 s |
| N1 · maní y lácteos | 201, sin choques, aviso de lo comprobado | 4 | 35,1–36,5 | 11,8 s |
| N1 · `{}` (app 2.0.9) | 201, como antes | 4 (los elige el modelo) | — | 11,9 s |
| N5 · 6 d · 45 min | 201 | 6 | 33,5–39,2 | 10,4 s |
| N5/3 (plan de producción) · 6 d · 45 min | 201 | 6 | 35,1–38,7 | 12,5 s |
| N5 · maní · 4 d · 45 min | **422 dos veces** (ver abajo) | — | — | 11,7 s y 12,0 s |
| N3 · 5 d · 60 min · avanzado | 201 | 5 | 35,5–46,5 | 5,4 s |

**El 422 del nivel 5 con alergia no lo introduce este cambio.** Lo produce la
puerta de calidad que ya existía en los niveles 2–5:

- el modelo escribió «respetando la alergia al maní» en las sustituciones
  generales;
- la regla prohíbe nombrar el alimento incluso para decir que se evita;
- la reparación no lo corrigió;
- en la tanda anterior el mismo caso pasó tras una reparación.

Ahora la alergia escrita por el entrenador llega a esa puerta (antes se perdía),
así que con alergias en los niveles 2–5 habrá más reintentos. Permitir
«alergia a X» en los textos que no son comidas es una decisión de producto
pendiente.

**Latencia del nivel 1.**

- Los tokens del caso de 6 días son prácticamente los de 4 días (≈ 12 k).
- La diferencia entre tandas (12,6 s frente a 16,3 s en el mismo caso) es
  variación de OpenAI.
- En producción, el p95 del nivel 1 ya era de 14,1 s, frente al corte de 20 s
  de la app.
- No es un efecto de este cambio. Con Shorebird, subir el plazo del cliente
  solo para esta llamada sería un parche de Dart.
