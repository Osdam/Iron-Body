# Potencias de Iron IA (nutrición y rutinas por plan)

Dos niveles independientes (1–5) por plan comercial, configurables en el CRM (Planes → Editar →
Capacidades de Iron IA), que cambian **de verdad** cómo se genera el plan integral que pide el
entrenador desde la app (`POST /api/trainer/members/{member}/integral-plan/generate`).

**No se cambia la app, el contrato público ni el endpoint.** Se cambia lo que hace el backend.

## 1. Diagnóstico (auditoría 28-10-2026, producción 594550b, app 2.0.9+30)

- **Una sola llamada** a `gpt-4.1-mini` (Chat Completions) para análisis, guía y rutina, con:
  - `max_tokens` 3000, `temperature` 0.2, `json_object` sin esquema;
  - timeout de 30 s y sin leer `finish_reason`.

  En producción hubo 46 generaciones desde el 09-09, 43 bien y 3 fallos upstream, sin latencias registradas.
- **El prompt pide poco:**
  - «texto corto» y «una frase»;
  - un solo día de comidas `label/time/description`, sin gramos, macros ni días;
  - rutina de 2–6 días y 4–10 ejercicios, sin progresión.
- **No llegan al modelo:**
  - de `nutrition_goals`: kcal y macros objetivo, gasto, actividad, días por semana, peso meta y ritmo;
  - de la calculadora: lo que no sea el basal;
  - `is_minor` (se arma y se descarta);
  - de `iron_ai_user_profiles`: preferencias y estilo;
  - el historial.

  Las alergias, la disponibilidad y el horario **no existen como campo**.
- **La app envía la petición vacía:** el formulario de contexto (días, minutos, alergias) nunca sale del
  móvil. Es un límite de la app; solo cuenta lo guardado en el servidor.
- **Recortes silenciosos:**
  - el validador corta textos con `mb_substr` y limita a 10 comidas, 7 días y 12 ejercicios;
  - descarta claves desconocidas;
  - una respuesta truncada da `ai_invalid_output` y pierde el texto;
  - la edición limita a 20 comidas;
  - `RoutineResource` descarta `rest_seconds`.
- **La generación no mira el plan del socio.** Las capacidades por plan viven en `membership_ai_capabilities`
  (CRM `edit-plan-modal`, `PUT /api/plans/{plan}/ai-capabilities`, `plans.manage`) y se resuelven por
  `users.plan` en `IronAiMembershipAccessService`.

## 2. Límites duros de la app publicada (no se cambian)

- **Timeout HTTP de 20 s** en `generate`. Si se supera, el entrenador ve un error y el servidor sigue,
  guarda y cobra.

  → La generación entera tiene un **plazo interno configurable** (por defecto 17 s). Si no cabe,
  falla con un error reintentable; nunca entrega un resultado básico como si fuera premium.
- **Nutrición: la app solo pinta texto plano.**
  - Pinta una lista de comidas sin límite, en el orden recibido, cada una con `label` (p. ej. «Lunes · Desayuno»), `time` y `description`.
  - Debajo, los bloques `objective_description`, `recommendations`, `restrictions`, `supplements` y `notes`.
  - No interpreta markdown.
- **Rutina: la app muestra días × ejercicios del catálogo** con series, repeticiones y `notes`, más el objetivo del día.
  - El descanso, el RPE, el calentamiento, la progresión y las alternativas solo caben como texto en `notes` o en el objetivo del día.
- **Tipos:** ningún campo existente cambia de tipo. Las claves nuevas se guardan para el futuro, pero la app no las ve.

## 3. Potencias

- **Dónde se guardan:** `membership_ai_capabilities.nutrition_power` y `.routine_power`, enteros de 1 a 5.
  - Por defecto valen 1, que equivale al comportamiento actual.
  - Se editan con el mismo endpoint y el mismo permiso.
- **Cómo se resuelven:** en el backend, desde la membresía vigente del socio (`getCurrentMembership` / `getAiCapabilities`).
  - Nunca vienen de la petición.
  - Un socio sin plan vigente usa el nivel 1.
  - Una membresía transferida usa el plan que recibió el beneficiario.
- **Qué no hacen:**
  - La potencia nunca habilita una capacidad desactivada.
  - Los cambios solo afectan a las generaciones futuras.

| Nivel | Nutrición | Rutina |
|---|---|---|
| 1 Esencial | Prompt v1 de hoy (una llamada combinada si ambas potencias son 1) | Igual que hoy |
| 2 Organizada | Un día tipo con cantidades orientativas y sustituciones | Distribución semanal adaptada, con alternativas básicas en notas |
| 3 Completa | Semana con 3 días tipo en rotación, cantidades y variedad | Progresión, reparto por grupos musculares y recuperación |
| 4 Avanzada | Lunes a domingo, gramos, preparación, alternativas y lista de mercado | Volumen, intensidad (RPE en notas), alternativas y progresión por bloques |
| 5 MAX PRO | Lunes a domingo, 5 momentos (ajustables), ingredientes con g/unidades crudo/cocido, preparación, sustituciones y macros estimados por comida; totales diarios y lista de mercado **calculados en el backend**; variedad garantizada | Programa de 4 semanas con progresión, calentamiento, descanso, RPE, técnica, alternativas y criterios de ajuste, dentro de los días disponibles y para el nivel real del socio |

La diferencia entre niveles está en la profundidad de la planificación, no en la exigencia física ni en la seguridad.

## 4. Motor

- **Contexto compartido**, construido de forma determinista en PHP a partir de los datos existentes. Lo usan la nutrición y la rutina, así que
  hay un solo objetivo interpretado.
  - **Qué incluye:** objetivo, nivel, días disponibles, metas de kcal y macros (de `nutrition_goals` o de la calculadora existente) y restricciones declaradas.
  - **Cuando faltan datos:** sin datos suficientes no se inventan metas; se dan rangos o pautas y se declara el supuesto.
- **Estrategia por nivel:** cada nivel tiene su propio fragmento de prompt, su `json_schema` estricto (Structured Outputs), su presupuesto de tokens, su número de secciones y sus reglas de validación.
- **Generación por secciones en paralelo** (`Http::pool`), sin arquitectura paralela, dentro del servicio actual.
  - Nutrición 4–5 se reparte por días o medios días.
  - La variedad se reparte antes, por día, de forma determinista y respetando las restricciones.
  - La rutina va en una llamada aparte.
  - El backend ensambla, suma y consolida.
- **Calidad (puerta antes de guardar):**
  - 7 días completos;
  - comidas con ingredientes y cantidades;
  - kcal ≈ 4P + 4C + 9G (±10 %);
  - totales del día frente a la meta;
  - ningún ingrediente que choque con las restricciones;
  - variedad;
  - lista de mercado coherente con las comidas;
  - en la rutina: días = disponibles, series, repeticiones, descanso y progresión.
- **Reparación y fallo:** si una sección falla o llega `finish_reason=length`, se repite **solo esa sección y una vez**, si el plazo lo permite. Si aun así no pasa, la generación falla (reintentable) y no guarda nada premium a medias.
- **Seguridad clínica:**
  - Con señales clínicas (menor de edad, embarazo o patologías en las notas) no se prescribe: se entrega un plan general seguro y se pide revisión profesional.
  - No hay restricciones extremas ni suplementación de riesgo.
  - «Alergias no registradas» nunca significa «sin alergias».
- **Coste:** se guardan `usage`, el nivel y la latencia por generación. El guardián de coste cuenta **una** generación por petición.

## 5. Presentación compatible con la app

- **Guía:**
  - `meals` lleva una entrada por comida y día («Lunes · Desayuno», hora y descripción en texto plano con ingredientes, preparación, sustituciones y macros estimados);
  - `recommendations` lleva metas, supuestos, hidratación y organización;
  - `notes` lleva la lista de mercado consolidada.
- **Datos estructurados:** se guardan además en `response_json`, para auditoría y para una futura app.
- **Límites:** se suben de forma coherente el validador y `StoreNutritionGuideRequest` para que la semana quepa y se pueda editar. Ningún texto se corta a la mitad.

## 6. Implementación (28-10-2026, rama `feat/iron-ia-potencias`, base 594550b, sin commit)

Se amplía el servicio existente; no hay módulo nuevo. Las piezas nuevas viven junto a él en
`app/Services/Trainer` y solo se usan desde `IntegralPlanGenerationService`:

| Pieza | Qué hace |
|---|---|
| `IntegralPlanStrategy` | Lo que pide cada nivel: prompt, `json_schema` estricto, tokens, secciones; reparto de variedad y división de la rutina en PHP. |
| `IntegralPlanPowerEngine` | Secciones en paralelo (`NutritionAiClient::completeMany`, `Http::pool`), plazo global, puerta, una reparación por sección y ensamblado. No persiste. |
| `IntegralPlanQualityGate` | La puerta de la §4, determinista. |
| `IntegralPlanAssembler` | La presentación de la §5: textos, totales y lista de mercado en PHP. |
| `PlanSafetyLexicon` | Léxico de restricciones y de señales clínicas, con negación simple. |
| `IntegralPlanEvaluator` | La rúbrica (cobertura, personalización, coherencia, seguridad, utilidad). |
| `iron-ia:evaluate` | El comando de evaluación con perfiles ficticios. |

### Contrato del CRM (`GET/PUT /api/plans/{plan}/ai-capabilities`, permiso `plans.manage`)

- `ai_nutrition_power` y `ai_routine_power` son enteros de 1 a 5. Los nombres no cambian.
- Validación: `numeric|integer|min:1|max:5`. Se rechazan `0`, `6`, `2.5`, `"cinco"`, `null` y `true`; «3» como texto se acepta.
- El GET devuelve lo **guardado**, también cuando Iron IA está apagada para el plan. Lo **aplicado** en ese caso es 1.
- Sin fila, ambas valen 1.

### Decisiones y desviaciones

1. **Las potencias no entran en `toCapabilities()`.**
   - Van en `MembershipAiCapability::toPowers()` y en `IronAiMembershipAccessService::getPlanPowers()`.
   - Usan el mismo orden de búsqueda, extraído a `capabilitySource()`; `getAiCapabilities()` sigue igual.
   - Motivo: `getAiCapabilities()` entra entero en `PlanConditions`, que sirve para la huella y las diferencias de los traslados entre planes. Meter las claves ahí habría cambiado esas huellas.
2. **Cuándo se aplica el nivel 1.**
   - Sin membresía vigente.
   - Con una fila que sale de la config y no de la tabla.
   - Con `ai_enabled = false` en el plan: la potencia no enciende nada.
   - El vencimiento sigue en UTC (`:96`), como pedía la auditoría.
3. **El nivel 1 no cambia.**
   - Mismo prompt, `temperature`, `max_tokens` (3000), `json_object` y timeout de 30 s.
   - El plazo de 17 s se aplica solo a los niveles 2–5. Aplicarlo al 1 cambiaría su comportamiento: queda como decisión del propietario.
   - Lo fija `IntegralPlanLevelOneParityTest`, que compara contra `tests/fixtures/iron-ia/v1-*.json`. Esos ficheros se capturaron ejecutando 594550b antes de tocar nada.
   - Se probaron dos mutaciones (una coma en el prompt, `max_tokens` 3001) y la prueba se puso roja con ambas.
   - Mejoras de robustez:
     - `finish_reason=length` da `ai_invalid_output` y guarda el texto cortado (`error_code = ai_truncated`);
     - `usage`, latencia y nivel se guardan en columnas nuevas;
     - `IntegralPlanValidator::clip()` sustituye a `mb_substr`: corta en frase o espacio y añade «…». Un texto dentro del límite sale idéntico.
4. **Niveles mezclados.** Si una potencia es 1 y la otra no, el lado de nivel 1 usa una sección «v1» con esquema estricto, no la llamada combinada. Solo con 1/1 se usa la llamada de siempre.
5. **La estructura la decide PHP.**
   - Días tipo: 1, 3 en rotación A-B-C-A-B-C-A, o 7.
   - Momentos del día y sus horas: 4 comidas en los niveles 2–3 y 5 en los niveles 4–5.
   - Anclas de variedad, filtradas por las restricciones.
   - División de la rutina según los días disponibles. Un principiante con 3 días hace cuerpo completo A/B/C.
   - Por eso el modelo no puede devolver 6 sesiones para 3 días.
6. **El nivel 5 va por medio día** (3 + 2 comidas por sección), para que cada llamada sea corta y quede plazo para reparar.
7. **Las kcal que se muestran se recalculan en PHP** (4P + 4C + 9G). Si las del modelo se desvían más de un 10 %, la sección se repara.
8. **Totales.** Los del día se comparan con la meta a ±12 % y la proteína a ±17 %. Las tolerancias se configuran.
9. **Seguridad clínica.**
   - Se activa con menor de edad (marca o edad), embarazo o patologías en las notas o el perfil. «Sin…», «niega…» y «no…» anulan la señal.
   - Sin metas de calorías, sin suplementos, RPE ≤ 7 y AVISO al principio de `recommendations` y de la descripción de la rutina.
10. **Restricciones.**
    - Salen de la última guía publicada y de `nutrition_style`/`preferences_summary` del perfil de Iron IA.
    - Se revisan en ingredientes, preparación, sustituciones y título.
    - Las alergias siempre figuran como «no registradas; confirmar».
11. **Rutina.**
    - En `notes` va «Descanso · RPE · Técnica · Progresión · Alternativa», compuesto en PHP con un tope de 500, que es el límite del editor del borrador.
    - El objetivo del día va en `days[].objective`, que `RoutineResource` ya pinta. Es la **única clave nueva** de la respuesta 201 (texto) y la prueba de contrato lo exige así.
    - El programa (semanas, criterios de ajuste) va en `description`.
12. **Límites.**
    - El modo ampliado del validador admite 42 comidas, 9.000 caracteres en `recommendations`/`notes` y 300 en el objetivo del día.
    - `StoreNutritionGuideRequest`: 50 comidas, 2.500 por descripción y 10.000 en `recommendations`/`notes`.
13. **Coste.** Una fila de `nutrition_ai_runs` por generación (el guardián cuenta 1). El `usage` por sección e intento va en `usage_json`.
14. **Erratas de la auditoría.**
    - `createRoutineDraft` sí guarda `notes` (no guarda `weight`).
    - Las líneas citadas se han desplazado unas pocas.

### Llamadas y tokens por nivel (estimación, D = días disponibles)

| Nivel | Llamadas | Tope de salida | Plazo |
|---|---|---|---|
| 1 | 1 | 3.000 | 30 s del cliente (como hoy) |
| 2 | 3 + D | 700 + 900 + 450 + 650·D | 17 s |
| 3 | 5 + D | 700 + 3·900 + 550 + 750·D | 17 s |
| 4 | 9 + D | 800 + 7·1.100 + 650 + 850·D | 17 s |
| 5 | 16 + D | 800 + 14·900 + 800 + 1.000·D | 17 s |

Con el doble de pruebas, el nivel 5 con D = 4 son unos 19.000 tokens de entrada y 7.000 de salida en 20 llamadas.

La entrada real depende sobre todo del catálogo: cada sección de día de rutina lo envía entero, igual que el v1 (hasta 400 ejercicios).

### Pendiente de medir con llamadas reales (`php artisan iron-ia:evaluate --run`, autoriza el integrador)

- Latencia real por sección y del conjunto frente a los 17 s, sobre todo en el nivel 5 y con el catálogo de producción.
- Tasa de reparación y de fallo de la puerta con `gpt-4.1-mini`: totales frente a la meta, kcal frente a macros y restricciones.
- Tokens y coste reales por nivel.
- Calidad clínica y culinaria, revisada por una persona.
- Si `json_schema` estricto se comporta igual en `gpt-4.1-mini` (Structured Outputs) con estos esquemas.
- Límites de tasa (RPM/TPM) de la cuenta con unas 20 llamadas simultáneas.

### 6.1 Arreglos tras la corrida real 1 (28-10-2026: 102 llamadas, 3 de 9 fallidas por la puerta)

Cada arreglo tiene su prueba, y se comprobó que esa prueba fallaba antes del cambio.

1. **Ana L5: kcal frente a macros.**
   - El modelo devuelve solo P, C y G en gramos: el esquema ya no lleva `kcal`.
   - PHP calcula las kcal de cada comida y del día; si el modelo manda kcal, se ignoran.
   - Se quitó la comprobación de kcal por comida. Se conserva la de totales del día frente a la meta, que repara esa sección.
   - En la rúbrica, `meals.kcal_from_macros` (las kcal guardadas y las mostradas son 4P+4C+9G) sustituye a `meals.kcal_macros`.
2. **Luisa L3 y L5: «res».**
   - Causa: el léxico trataba «res» como raíz y chocaba con «respetando», «resto» y «resistencia». El aviso de reparación solo decía «res», así que el modelo volvía a escribir «respeta».
   - El léxico ahora compara por palabra completa o su plural, sin tildes ni mayúsculas. Las raíces se marcan con `*`.
   - El aviso de reparación lista todas las palabras que chocan, con su contexto y la orden de reescribir sin nombrarlas.
   - Los prompts de nutrición llevan «PROHIBIDO NOMBRAR…» con la lista de términos.
   - El texto libre del núcleo se revisa entero.
3. **Catálogo por día de rutina.**
   - Cada día recibe los ejercicios de sus grupos musculares: un mínimo de 24 (o 3 × el máximo de ejercicios) y un tope de 48, configurables.
   - Se reparten por grupo y alternando equipo. El plan no reparte equipo, porque no hay datos de disponibilidad: el equipo sirve para variar, no para filtrar.
   - Los ids se siguen validando contra el catálogo completo.
   - Medido en la prueba (214 ejercicios, 4 días): la entrada de los días de rutina baja de unos 34.200 a unos 10.800 tokens (−69 %).
4. **Nivel 1 con riesgo.**
   - Se aplica como posproceso, sin cambiar el prompt, con una señal clínica en notas, lesiones o restricciones, o con `is_minor`.
   - Se retiran los suplementos y se antepone el aviso en `recommendations` y `notes`, con los mismos campos y tipos.
   - Sin riesgo, la paridad sigue intacta.
5. **Versión del prompt.** Pasa de `p1` a `p2`.

### 6.2 Arreglos tras la corrida real 2 (8 de 9 bien; prompt `p3`)

1. **Ana L5: proteína del día (10 reparaciones sin converger).**
   - Cada día lleva metas de P, C y G **por comida**. Se reparten por el peso de cada momento y se redondean por mayor resto, así que suman exactamente la meta del día.
   - Totales escalonados:
     - hasta ±10 %, bien;
     - entre 10 y 25 %, se acepta sin reparar, con una nota de ajuste calculada en PHP en la línea de ese día;
     - más del 25 %, se repara una vez y solo la media jornada que más se desvía, a la que se le dice su parte.
   - La rúbrica (`days.totals_vs_target`, ±10 % en kcal y proteína) penaliza la franja del 10 al 25 %.
   - Tope global de reparaciones (`max_repairs`, 4): con más secciones malas que el tope, falla sin reparar.
2. **Luisa L5: `shopping.covers_meals`.**
   - El consolidador sí unía «Fríjoles rojos secos» y «Fríjol rojo seco».
   - La comprobación comparaba con otra normalización más ingenua, y el singular, que apareció después, no casaba con la línea.
   - Ahora compara con la misma clave del consolidador (`IntegralPlanAssembler::foodKey`).
   - Pendiente (calidad, no coherencia): sinónimos (cebolla blanca/cabezona, banano/banana, pimentón/pimiento) y unidades mezcladas (aceite en cucharadas y en ml).

### 6.3 Ronda final: revisión (bloqueante) y seguridad (A1, A2, M1, M2, B6). Prompt `p4`

Cada punto tiene su prueba, y se comprobó que esa prueba fallaba antes del cambio.

1. **Macros por ingrediente.**
   - El esquema pide `grams`, `protein_g`, `carbs_g` y `fat_g` por ingrediente, sin macros por comida.
   - PHP suma la comida y el día y calcula las kcal.
   - Verosimilitud por ingrediente: P+C+G ≤ g, ≤ 9 kcal/g y P ≤ 0,9·g.
   - Copia: dos o más comidas de una sección iguales a su meta (±1 g) se reparan una vez.
   - El prompt dice que la meta orienta las porciones.
   - Rúbrica: `meals.macros_not_copied` y `meals.ingredients_plausible`. El patrón de Ana deja la coherencia por debajo de 60.
2. **A1: coste y concurrencia.**
   - `Cache::lock("integral-plan:{socio}")` devuelve 409 `generation_in_progress`. El almacén por defecto es `database` y la tabla `cache_locks` existe.
   - `RateLimiter` por entrenador (10 cada 600 s) devuelve 429 `trainer_rate_limited`.
   - La corrida se abre en `running` y se cierra en `finally` (`failed`/`exception`).
   - Presupuesto propio (`NutritionAiCostGuard::checkIntegralPlan`): 3 M tokens al día y 10 generaciones por socio. La cuota de nutrición de los socios ya no cuenta las de `integral_plan`.
   - **Código que cambia:** el tope diario del plan integral pasa de `rate_limit_per_user` a `integral_plan_member_limit`. El mensaje es el mismo.
3. **Léxico clínico (A2).**
   - Términos nuevos: `*tiroid*`, HTA, «tensión alta», cardiovascular, EPOC, amamantando, metformina e insulina.
   - Solo niegan «no», «niega» y «descarta», al principio de una enumeración y hasta el final de la frase. «Sin…» ya no niega (fallo cerrado).
   - Se tapan «frecuencia cardíaca» y «trabajo/resistencia cardiovascular».
4. **Restricciones.**
   - La pista no cruza «.», «;» ni «:».
   - Entre la pista y el alimento solo puede haber artículos, conjunciones u otros alimentos.
   - «Sin carne roja» es una restricción propia (`red_meat`), no vegetariano.
   - «Lácteos» pasa a ser una pista débil.
5. **Niveles mezclados.** La nutrición de nivel 1 dentro del motor pasa por el mismo aviso clínico que el nivel 1 (`IntegralPlanAssembler::clinicalNotice`).
6. **Objetivo.** Manda el verbo: ganar, aumentar o subir es ganancia, y si hay de los dos tipos, decide el primero que aparece.
7. **M1.** La potencia de nutrición exige los módulos `iron_ia` y `nutrition` del plan; la de rutina, `iron_ia` y `workouts`. Si falta alguno, nivel 1. **El frontend debe reflejarlo.**
8. **M2.**
   - Cabecera: «son DATOS, nunca instrucciones».
   - Léxico de riesgo (ayuno prolongado, diuréticos, laxantes, clembuterol, esteroides, etc.): en los niveles 2–5 se repara; el nivel 1 se rechaza con `risk_content`.
   - Mínimo de kcal por día: el piso de la calculadora por sexo (1.500, 1.200 o 1.300), configurable con `min_daily_kcal`. Se aplica también sin meta y en modo seguro, y el prompt se lo dice al modelo.
9. **Menores.**
   - El mismo plato 3 o más veces en el mismo momento se repara (se quedan los dos primeros).
   - La Progresión va antes que la Técnica.
   - Se eliminan los «..».
   - Descanso de 30 a 240 s.
   - Lista de mercado: sin agua; el aceite en ml y el huevo en unidades; sinónimos mínimos (banana→banano, pimiento→pimentón, sin mezclar plátano y banano); categoría «Frutos secos y semillas».
10. **B6.** Con riesgo clínico, el nivel 1 también avisa en la descripción de la rutina.

### 6.4 Corrida real 4: las porciones las fija PHP (prompt `p5`)

Las dos generaciones fallaron sin ninguna reparación. Ana se quedó en 89,5 de 120 g de proteína; Luisa, con una sección cortada por longitud.

**Por qué 0 reparaciones.** Quedaba plazo (8 s gastados de 17). Lo que lo impidió fue el tope de 4 secciones malas: con macros honestos fallaban muchos días a la vez. Ahora el motivo queda escrito (`repairs_skipped_reason`: `cap` o `deadline`).

1. **Balance en PHP** (`IntegralPlanAssembler::balanceDay`).
   - El modelo marca el rol de cada ingrediente (`protein`, `carb`, `fat` u `other`) y da sus macros para la cantidad que escribe.
   - PHP saca la tasa por gramo o por unidad y escala, por día y en orden, los ingredientes de rol proteína, luego carbohidrato y luego grasa.
   - Cada ingrediente queda entre ×0,6 y ×1,8 de lo que propuso el modelo, redondeado a 5 g (10 g desde 100) o a media pieza.
   - Se muestra, se suma y se compra lo balanceado. Si tras el balance el día sigue fuera de tolerancia, va con su nota o se repara.
   - Sin meta no hay balance; solo el mínimo de kcal.
2. **Longitud.**
   - El nivel 5 va en secciones de 2 comidas como mucho (2 + 2 + 1), con 1.100 tokens de tope.
   - Estimación: unos 320 tokens por comida, así que unos 700 por sección, unos 7 s a ~110 tokens/s. Dentro de los 17 s cabe una ronda de reparación.
   - Coste: 27 llamadas (D = 4) y unos 11.000 tokens más de entrada.
   - El tope de reparaciones pasa a 6.
3. **Verosimilitud.**
   - Más de 0,35 g de proteína por gramo se repara. Quedan fuera de la regla los alimentos densos conocidos: soya texturizada, seitán y parmesano.
   - Los suplementos proteicos no van en las comidas.
