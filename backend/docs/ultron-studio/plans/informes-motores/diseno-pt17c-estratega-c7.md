# PT-17c · La estrategia del turno de planes, coherente con la lista (compilador 7)

> Nota de diseño corta (backend, 23-11-2026). Enmienda A4-7 del H5; texto en `plans/STUDIO_MOTORES_PLAN.md` §B.3, «Compilador 7». Implementado en LOCAL en `cierre/planes-c7` (sobre `5f4a44a4`), sin confirmar ni desplegar. Ajustado el mismo día a petición del propietario (la recomendación pedida, tras la lista; §3), sin subir la versión.

## 1. La causa (medida)

Medición de la v4 («profesional · 200») con el compilador 6 en producción: 25 recorridos del laboratorio frente al motor (20 del turno de planes, E-001 a E-005, y 5 de la consulta combinada E-006, «¿Qué planes tienen y abren los domingos?»), `gpt-4.1-mini`, paquete `962902ab…73a4`.

- `UltronPromptCompiler::T_PLANS_STRATEGIST` (el texto del 5, que el 6 no tocó) mandaba al estratega, con `plans_overview`, `next_best_action recommend_plan`, `conversation_goal recommend` y un `recommended_plan_id` de UN plan «nunca null», junto a `recommendation_goal compare_plans` y un `response_goal` de lista. Esa estrategia contradictoria llega al redactor y al revisor.
- El revisor no recibe `plans_overview` (`StudioLabEngineFlow::CRITIC_POLICY_KEYS` y el nodo «Critic Comercial» de S2): juzga la lista contra la estrategia y no puede saber que `PLAN_3` es la POSICIÓN 3 (con `po` = [3, 4, 8], el Élite, id 8).
- Turno de planes: el revisor rechazó 9 de los 18 segundos borradores que el servidor daba por buenos; de los 5 respaldos con un solo plan o ninguno, 4 nombran `{{PLAN_3}}`; una nota lee `PLAN_3` como «el plan 3 activo» y otra cita «la estrategia indica que primero debe recomendar el plan». Ningún primer borrador fue válido (0 de 20).
- Combinada: los 5 segundos borradores traían los tres planes y el horario sin defecto del servidor; el revisor los rechazó y el tercero cayó a un plan: 5 respaldos.
- `recommend_plan` además va contra la política en algunos turnos con lista: en el primer `general_info` («Precios?», «Hola, precios por favor») `allowed_next_actions` no lo trae y `forbidden_actions` lleva `plan_recommendation`.

## 2. El texto (Strategist, insertar tras `QUE PLAN SE COTIZA`; 1 195 B)

«LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. PLAN_k es la posicion k de plans_overview.plans, no el plan de id k. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras y en menos de 160 caracteres: se presentan los planes de plans_overview, cada uno con su marcador y un beneficio tal como esta en sus benefits, sin cambiarle el sentido; se contesta tambien lo demas que pregunto en ese mensaje, nombrandolo; y cabe una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.»

## 3. Por qué así

| Valor | De dónde sale (contratos de hoy, sin cambiarlos) |
|---|---|
| `next_best_action answer` | Está en `commercial_turn_policy.allowed_next_actions` de toda política con lista (`CommercialTurnPolicy::decide()`; la lista nunca sale en recepción, memoria ni visita, `pideElPanorama()`); la base dice que esa lista acota lo que se propone (`strategist.txt:136`). El nodo lo acepta (texto) y el commit también (`max:60`). Validar Estrategia no lo corrige. Manda sobre la lista de `next_best_action` de la base (`strategist.txt:92`) solo en este turno |
| `conversation_goal recommend`, `recommendation_goal compare_plans` | `StrategyContract::CONVERSATION_GOALS` y `RECOMMENDATION_GOALS`; `compare_plans` es el de comparar (`strategist.txt:57`) y ningún otro objetivo de conversación describe presentar los planes (`understand` es descubrir). Se conservan del 6 |
| `information_needed []` | `StrategyContract::ASKABLE`, vacío. El paréntesis «(ni experiencia, objetivo ni disponibilidad)» se quitó en el ajuste para que cupiera la recomendación en 1 200 B: «no descubre» y la lista vacía dicen lo mismo |
| `recommended_plan_id` nunca null, solo referencia | La base lo exige con precio (`strategist.txt:19`) y Validar Estrategia pone el de por defecto si viene null; con null y un `{{PLAN_PRICE}}` el commit lanza `plan_required_for_placeholder`. Con la lista válida el commit no lo usa para el texto ni lo penaliza (`policy_wrong_first_plan` mira la política). Se destaca solo si la persona pidió una recomendación |
| `response_goal` del estratega | Lo que el mensaje tiene que lograr, sin frase: cada plan con su marcador y un beneficio tal como está en sus `benefits`, lo demás que preguntó (nombrándolo, para que el redactor lo conteste) y una pregunta breve opcional. Menos de 160 caracteres: es el tope del commit y Validar Estrategia corta a 160 |
| `PLAN_k` = posición k | La confusión medida; el servidor ya expande por posición |
| La recomendación, tras la lista | Ajuste del propietario (23-11-2026): lo prohibido es que una recomendación SUSTITUYA la lista completa, no contestar «¿Qué planes tienen y cuál me recomienda?». La lista va siempre entera; si (y solo si) la persona pidió una recomendación, va después de la lista. Lo pedido lo contesta el `response_goal` («lo demás que preguntó en ese mensaje») |

El redactor y el revisor no se tocan (condición del propietario): sus cuatro papeles compilados son los del 6 byte a byte en toda la rejilla.

## 4. Límites

- Es una instrucción al modelo: no garantiza que el estratega, el redactor o el revisor la sigan. Se mide en el laboratorio frente al motor con el servidor desplegado, antes de publicar.
- El revisor sigue sin `plans_overview` y sigue viendo un `recommended_plan_id`; su texto del 6 ya dice que eso no es defecto. Darle la lista sería tocar su nodo y su texto: fuera de alcance.
- Los segundos borradores reales de la combinada que ahora pasan el servidor no son perfectos: cierran con una pregunta de descubrimiento y uno dice «para una semana» de un plan de un mes. Eso lo juzga el revisor, no el servidor.
- Lo firmado con el 6 (la v4 en canario) cae a `compiler_changed` al desplegar el 7 hasta publicar otra versión.
- Hoy el servidor no ofrece la lista cuando el mensaje pide una recomendación (`CommercialTurnPolicy::NO_PIDE_EL_PANORAMA`, en `pideElPanorama()`): con «¿Qué planes tienen y cuál me recomienda?» no viene `plans_overview` y este texto no aplica. El ajuste cubre la petición cuando sí llega con la lista; cambiar cuándo la ofrece el servidor es lógica, fuera de este alcance.
- El catálogo de las pruebas con textos reales usa los nombres y precios de producción y los beneficios de `PlansSeeder`; el de producción no se leyó.
