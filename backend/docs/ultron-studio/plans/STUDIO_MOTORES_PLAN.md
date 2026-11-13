# ULTRON STUDIO · Integración con el motor real de WhatsApp (prompts gobernados por la versión publicada) — PLAN

**Autor:** `architect` (no implementa). **Fecha:** 11-11-2026. **Base leída:** backend `07e70dac` (rama `feat/ultron-studio-h9`, árbol con cambios de otros agentes, ver §Impacto) y el flujo de n8n `YspRwsXnqt33NouP` versión `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` exportado en `n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json`. **Entrega:** 15-11-2026. **Ejecutan:** `backend` ×2 (motor y Studio), `database-specialist` (una migración de dos líneas), `frontend`, `tester`, `security`/`api-security-specialist` y el lead (n8n y producción). **Criterio del propietario:** un cambio aprobado y publicado en el Studio se refleja en una ejecución canaria real de ULTRON por WhatsApp, sin editar prompts en n8n, con trazabilidad publicada↔aplicada, reversión verificable, transición controlada desde Legacy y sin romper el WhatsApp de hoy.

Todo lo que se afirma del código lleva `ruta:línea` y está leído hoy; lo que no se pudo verificar va marcado **[SUPOSICIÓN]**.

---

## Contexto

### C1. El motor real hoy (n8n 29de5119)

- 24 nodos. Orden: `Entrada ULTRON` → `Normalizar Evento` → `Pedir Contexto al CRM` (POST `ai/decide`, cuerpo `{conversation_id, message_id}`, error → `Responder: sin contexto` 503) → `Mensaje superado?` → (falso) `Strategist` → `Validar Estrategia` → `Composer` → `Critic Comercial` → `Critic aprueba?` → `Preparar Commit` → `Ejecutar en el CRM` (POST `ai/commit`, cuerpo `JSON.stringify($json)`) → `Commit OK?` → …; con reintento `Composer Reintento` → `Critic Comercial 2`; y la rendición `Clasificar Fallo del Commit` → `Rendirse?` → `Preparar Rendicion` | `Rendicion Minima` → `Ejecutar en el CRM`.
- Los 5 nodos de OpenAI (`@n8n/n8n-nodes-langchain.openAi` 2.3, `gpt-4.1-mini`) llevan en `responses.values[0].content` un prompt de sistema **literal** (no empieza por `=`, así que n8n no evalúa los `{{PLAN_NAME}}` / `{{APP_LINKS}}` que contiene el del Composer) y en `values[1].content` una expresión con el contexto. Tamaños en bytes UTF-8: Strategist 29 243, Composer 27 132, Critic Comercial 15 583, Composer Reintento 25 023, Critic Comercial 2 15 235 (**112 216 en total**; las cifras del lead son caracteres). Ninguno contiene `` ` `` ni `${`.
- **Los prompts de Composer y Composer Reintento no son iguales**, ni los de los dos Critic (p. ej. `TRANSPARENCIA`, `FORMA`, `PRECIOS`; primera y tercera línea de los Critic): hay cinco bases, no tres.
- `Strategist` lee su contexto de `$json` (la salida de `Mensaje superado?`, es decir, la respuesta de `decide` pasada tal cual); los otros cuatro leen `$json` del nodo anterior y `$("Pedir Contexto al CRM").first().json` por nombre. `Validar Estrategia` copia la respuesta de `decide` entera en `context` (última línea de su `jsCode`). **Consecuencia:** un nodo que se intercale delante del Strategist tiene que devolver la respuesta de `decide` sin tocarla.
- `Preparar Rendicion` y `Rendicion Minima` construyen su cuerpo con las claves del contrato una a una; no heredan nada de `Preparar Commit`. Un 422 de forma (`unexpected_fields`, `validation_error`) se clasifica como `contrato` y acaba en rendición (texto curado de Laravel), una sola vez por ejecución (`yaSeRindio`): **degrada el turno, no lo deja mudo**.
- **Copia en el repositorio verificada byte a byte:** `backend/docs/marketing/prompts/2026-09-22-voz/nuevo4.json` (commit `5a8769e0`) tiene, para los cinco nodos, `system` idéntico a `values[0].content` y `user` idéntico a `values[1].content` del flujo 29de5119 (sha256 comparados hoy). La base se puede importar sin tocar n8n.

### C2. El backend hoy

- `decide` valida solo `conversation_id` y `message_id` (`app/Http/Controllers/Api/Internal/UltronController.php:83-86`) y **no rechaza claves de más**. `commit` es lista blanca estricta (`:150-275`) y además rechaza claves sobrantes en el primer nivel y dentro de `proposal`, `critic` y `recovery` (`:285-292`, `:404-418`; la lista de bloques anidados está en `:408`).
- El `decide_token` lleva una carga firmada `x` que el commit recupera tal cual (`app/Services/Marketing/Ultron/UltronDecideToken.php:60`, `:131`); vida 120 s (`:27`); `decide` lo emite en `UltronDecideService.php:389-402`; el commit admite como mucho 2048 caracteres (`UltronController.php:155`).
- El H5 eligió la **opción A**: «Aplicación: Vacío. El motor solo sella» (`docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md:32-36`). `UltronPublishedConfig` lee la cabeza firmada de `ultron_studio_publications` una vez por commit (`UltronCommitService.php:324`), la verifica en estricto (contrato `ultron-engine-config/1` `UltronPublishedConfig.php:68`; claves de la parte `:116`; los 4 `must_match` `:119-124`; tope 16 384 `:101`), resuelve la parte por conversación (`:391-406`) y el commit guarda el sello en `metadata.studio` (`UltronCommitService.php:2474`). Nunca lanza.
- La frontera (H5 §A9, `ULTRON_STUDIO_H5_CONTRACT.md:260`): el motor lee **una** tabla del Studio desde **una** clase y **solo** la invoca el commit; lo vigila `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:288-296` y `:529-549`. En PostgreSQL el CHECK `uspub_engine_contract_chk` solo admite `ultron-engine-config/1` (`database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php:116`).
- **Alcance real de n8n hoy:** con el canario puesto, el emisor descarta toda conversación que no sea la canaria **antes** de crear el evento (`UltronEventEmitter.php:84-87`) y el commit responde 403 a cualquier otra (`UltronCommitService.php:291-303`); el enrutador emite a ULTRON y sigue con el análisis local (`MarketingInboundMessageRouter.php:132-141`). Es decir: **hoy solo la conversación canaria pasa por este flujo de n8n**; los demás clientes van por el camino local. `canary_conversation_id = null` significa «sin restricción» (`config/marketing.php:72-86`).
- El laboratorio del H4 **no** compila los prompts del motor: compila los de su propio actor y juez (`app/Services/UltronStudio/StudioLabPrompts.php:121-160`) y pasa el texto del actor por las guardas puras del motor (`StudioLabEngineChecks.php:18-55`). Su veredicto (la puerta `publish_lab_gate` del H5) no prueba los prompts de n8n. (Corrige el hecho 3 del encargo.)
- El precedente que sí encaja es el H8: «una versión aprobada … se compila, en Laravel, en un agente … inmutable, con su hash y en una cadena firmada propia», con `specHash`, `payloadHash`, `compilerVersion` en la parte firmada y un sello observado después (`ULTRON_STUDIO_H8_CONTRACT.md:12-13`, `:238-256`).

### C3. A — La tabla de la brecha (21 parámetros + reglas)

Fuente de la columna «mando real»: `StudioCatalog::parameterBindings()` (`app/Services/UltronStudio/StudioCatalog.php:676-726`) y la línea base (`:553-567`). **Ningún valor de la versión publicada cambia hoy nada** (opción A): las guardas del commit actúan siempre, con o sin versión; la versión solo puede coincidir con ellas (y el validador bloquea los valores que las contradicen).

| Capa · parámetro | Origen | ¿La versión publicada cambia algo hoy? | Dónde vive el comportamiento real | Tras este plan |
|---|---|---|---|---|
| knowledge.sources | fixed | No | Código (fuentes fijas) | Igual (`no_effect`) |
| knowledge.strictFacts | fixed = true | No (false bloquea, R-01) | Commit: `UltronDraftPlaceholders`, `OutboundContentGuard` | Igual |
| **persona.tone** | proposal | No | **Solo prompts**: Composer y Reintento, línea 42 «COMO SUENAS» (+ `knowledge_base.tone` por datos) | **Gobernado** (Composer ×2, Critic ×2) |
| **persona.emojis** | proposal | No | **Solo prompts**: «FORMA», línea 44; la guardia de estilo solo anota > 2 | **Gobernado** (Composer ×2, Critic ×2) |
| **persona.maxChars** | proposal | No | **Solo prompts**: «1 a 3 frases»; el Critic usa ~900 (línea 40); la guardia anota > 900 | **Gobernado** (Composer ×2, Critic ×2) |
| intent.driver | env_flag | No (`must_match`) | Entorno | Igual |
| intent.fallback | proposal | No | Prompt del Strategist | **Sigue `no_effect`**: «humano» no se puede aplicar por prompt; lo impiden `Validar Estrategia` (solo `explicit_human_request`) y `HumanHandoffAuthority` (R-07) |
| context.windowTurns | fixed = 10 | No | `UltronDecideService::recentMessages` | Igual |
| memory.useLeadProfile | fixed = true | No | Código | Igual |
| **strategy.goal** | proposal | No | **Solo prompt** del Strategist | **Gobernado** (Strategist) |
| strategy.offerPriority | engine_data | No | Módulo de Planes (`is_recommended`, orden) | Igual |
| strategy.discountsAllowed | fixed = false | No (true bloquea, R-02) | Commit | Igual |
| rules.handoffOnlyCorroborated | fixed = true | No (false bloquea, R-07) | `HumanHandoffAuthority` | Igual |
| rules.paymentLinksEnabled | env_flag | No (`must_match`) | Entorno | Igual |
| composer.critic / composer.retry | fixed (n8n) | No | Topología del flujo | Igual |
| integrations.channel / appointments | fixed | No | Código, `CourtesyAuthority` | Igual |
| governance.enabled / canaryOnly | env_flag | No (`must_match`) | Entorno | Igual |
| governance.autoBrake | fixed = true | No | Vigía | Igual |
| Reglas S-NNN | — | No (`no_effect`, H5:49) | En ninguna parte | **Siguen `no_effect`** (G2) |
| Reglas R-xx | — | Describen el código | Código | Igual |

**Matiz que hay que decir:** los valores de la línea base de esos cuatro no describen literalmente el motor (`maxChars = 320` cuando el prompt no fija tope; `goal = cita` cuando el Strategist hace venta consultiva con día de cortesía). Se mantiene la convención que ya fijó el H5 (`ULTRON_STUDIO_H5_CONTRACT.md:44`): **valor de la línea base = lo que hace hoy el motor (Legacy)**; cualquier otro valor = un cambio explícito en los prompts (G4).

---

## Impacto

- **Archivos a tocar** (por paquete, exclusivos; detalle en §Plan):
  - Motor (B-M): nuevos `app/Services/Marketing/Ultron/Prompts/{UltronPromptBase,UltronPromptCompiler,UltronPromptOffer,UltronPromptStamp}.php`, `resources/ultron-prompts/n8n-29de5119/*.txt`, `app/Console/Commands/Marketing/{UltronPromptTrace,UltronPromptsN8n}.php`; cambios en `UltronPublishedConfig.php`, `UltronDecideService.php`, `UltronController.php`, `UltronCommitService.php`, `config/marketing.php`; `UltronEngineIsolationTest.php` (solo la lista de quién nombra al lector).
  - Studio (B-S): `StudioPublicationDeclaration.php`, `StudioPublishing.php`, `StudioPublications.php`, `StudioEngineReadings.php`, `StudioCatalog.php` (+ `VERSION` y fixtures), `app/Support/Access/PermissionCatalog.php:242-243` (texto).
  - Base de datos: una migración nueva que amplía `uspub_engine_contract_chk`.
  - CRM: `Iron-Body_Front/src/app/modules/ultron-studio/` (diálogo de publicar, barra, traza, tipos, fixtures).
  - n8n: 1 nodo nuevo y 8 parámetros (5 contenidos de sistema, el cuerpo de `decide`, el código de `Preparar Commit`, una conexión).
- **Contratos que cambian:** `decide` (petición: campo opcional `prompt_contract`; respuesta: `studio_prompts` solo si se pidió); `commit` (bloque opcional `prompts`); `decide_token.x.sp` (firmado); publicación `ultron-engine-config/2` (aditivo; el lector sigue leyendo `/1`); `metadata.studio_prompts` nuevo (el sello `metadata.studio` NO cambia de forma); DTOs del Studio (`publication-preview.prompts`, `status.engine.prompts`); llave nueva `MARKETING_ULTRON_STUDIO_PROMPTS`; frontera A9 enmendada.
- **Consumidores afectados:** el flujo de n8n (único cliente de `decide`/`commit`); el arnés E2E (`Iron-Body_Front/e2e/ultron-studio/mock-n8n.cjs`); el CRM (Studio); el vigía y el acta no cambian (no leen `studio_prompts`). **Ningún cliente de WhatsApp fuera de la conversación canaria** (C2).
- **Migración de datos:** **no**. Solo una migración de esquema que amplía un CHECK de PostgreSQL (`/1` → `/1|/2`). Ningún backfill: los turnos anteriores quedan sin `studio_prompts`, que se lee como «no informado».
- **Árbol sucio de otros agentes (no tocar):** `backend/.env.example`, `config/ultron_studio.php`, `StudioMessageTemplates.php`, `UltronStudioStrategyController.php`, `ops/h8*`, `ops/h9-e2e/*`, `app/Services/Meta/Templates/`, el módulo `commercial-automation` del CRM. El diseño evita a propósito una bandera en `config/ultron_studio.php` (ver B.3); las líneas de `.env.example` las añade el lead al integrar.

---

## Opciones y recomendación (B)

### B.1 Las tres formas de que la versión publicada mande en los prompts

| | (1) Laravel compila los cinco prompts y los entrega en `decide`; n8n los usa o cae a Legacy | (2) Superposición por rol (n8n conserva su prompt y Laravel añade un trozo) | (3) Dos flujos: el de hoy intacto y un clon «Studio» al que Laravel enruta la canaria |
|---|---|---|---|
| ¿Cumple «no editar un prompt independiente en n8n»? | **Sí**: el texto efectivo sale del repo + la versión firmada | **No**: la base sigue editable en n8n y la versión no determina el prompt efectivo | Sí en el clon |
| Legacy byte a byte | Sí, por construcción y verificable (las constantes Legacy = base del repo = n8n 29de5119) | Difícil: el array de mensajes del nodo es fijo; un mensaje extra vacío cambia la petición, y concatenar obliga a meter el literal en una expresión | Sí (no se toca) |
| Contradicciones | Ninguna: los parches **sustituyen** o **insertan** en puntos anclados | La superposición contradice la base («Emoji opcional» vs «sin emojis»); el modelo decide | Ninguna |
| Trazabilidad | Huella del paquete completo firmada y **observada** en n8n | Solo de la superposición | Igual que (1) |
| Esfuerzo / riesgo | Medio; toca el flujo vivo (que hoy solo atiende la canaria) | Bajo, pero no cumple el criterio | Alto: enrutado nuevo en el camino caliente de cada turno, dos flujos que mantener, y apagar el clon con tráfico enrutado deja turnos mudos |

**→ Recomendación: (1)**, con los refinamientos de B.2–B.6. (3) queda como plan B solo si el propietario prohíbe modificar el flujo vivo (G10).

### B.2 Dónde se compila (sub-decisión)

- **(i) En `decide`, desde el contenido de la versión:** el motor tendría que leer `ultron_studio_versions` y recalcular su huella con `StudioContentHasher` (clase del Studio) → rompe A9; y los cuatro ojos no habrían visto el texto exacto. **Descartada.**
- **(ii) Guardar los cinco prompts en la cadena:** 112–224 KB por entrada contra un tope de 16 KB que el commit lee en cada turno. **Descartada.**
- **(iii) Elegida (patrón H8):** al publicar, el Studio firma en la parte de la entrada **los cuatro valores gobernados** y la **huella del paquete compilado** (`ultron-engine-config/2`). En `decide`, el motor recompila desde la base del repositorio con los valores firmados y **solo entrega si su huella coincide con la firmada**; si no, Legacy. Lo que aprobaron cuatro ojos es exactamente lo que se envía, el motor sigue leyendo una sola tabla del Studio y no importa nada del Studio.

### B.3 El compilador (determinista, puro, con huella)

- **Base `n8n-29de5119`:** `backend/resources/ultron-prompts/n8n-29de5119/{strategist,composer,critic,composer_retry,critic_retry}.txt`, cada uno con los **bytes exactos** de `nuevo4.json → <nodo>.system` (sin salto de línea final añadido). `UltronPromptBase` las carga y verifica contra sha256 fijados en constantes; si no cuadran, lanza (el llamante cae a Legacy). Orden canónico de roles ↔ nodos: `strategist`↔Strategist, `composer`↔Composer, `critic`↔Critic Comercial, `composer_retry`↔Composer Reintento, `critic_retry`↔Critic Comercial 2.
- **Huella del paquete:** `bundleHash = sha256_hex(implode("\n", ["ultron-prompts/1", "strategist", S, "composer", C, "critic", K, "composer_retry", CR, "critic_retry", KR]))` sobre UTF-8. `LEGACY_BUNDLE_HASH` = esa huella sobre la base (constante fijada en PHP, comprobada en prueba y embebida en el selector de n8n).
- **Parches anclados, no plantillas:** la base no se edita. Cada valor distinto de la línea base aplica parches `{rol, ancla, operación}` donde el ancla es una subcadena que **debe aparecer exactamente una vez** (verificado hoy, sensible a mayúsculas: `COMO SUENAS:`, `FORMA:` y `Emoji opcional y raro, nunca en temas sensibles.` una vez en Composer y una en Reintento; `mas de ~900 caracteres`, `naturalness:` y `progressive_disclosure:` (estas dos a principio de línea) una vez en cada Critic; `PRINCIPIO:` una vez en el Strategist; la prueba E2 lo vuelve a comprobar en CI). Operaciones: `insert_after_line` (inserta una línea nueva tras la línea que contiene el ancla) y `replace` (sustituye la subcadena). Varios parches sobre la misma ancla se aplican en el orden de los parámetros: `persona.tone`, `persona.emojis`, `persona.maxChars`, `strategy.goal`. Ancla ausente o repetida → excepción.
- **Propiedad central (prueba unitaria): `compile(línea base) === base` byte a byte y `bundleHash === LEGACY_BUNDLE_HASH`.**
- **Qué entra de cada capa en cada rol** (los textos son BORRADOR; los congela el propietario el día 1, G3; mismo estilo sin tildes que la base):

| Parámetro = valor | Strategist | Composer y Composer Reintento | Critic y Critic 2 |
|---|---|---|---|
| tone = cercano (base) | — | — | — |
| tone = profesional | — | insertar tras `COMO SUENAS:` → «REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio.» | insertar tras `naturalness:` → «REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es.» |
| tone = entusiasta | — | insertar tras `COMO SUENAS:` → «REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.» | insertar tras `naturalness:` → «REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.» |
| emojis = true (base) | — | — | — |
| emojis = false | — | reemplazar `Emoji opcional y raro, nunca en temas sensibles.` → «Sin emojis: ninguno, en ningun mensaje.» | insertar tras `naturalness:` → «EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.» |
| maxChars = 320 (base) | — | — | — |
| maxChars = N (160–480, paso 20, ≠ 320) | — | insertar tras `FORMA:` → «TOPE DE LONGITUD PUBLICADO: como maximo unos N caracteres por mensaje, contando cada marcador como el texto que lo sustituye; tambien la informacion general respeta el tope: si no cabe, da lo esencial y ofrece ampliar.» | reemplazar `mas de ~900 caracteres` → `mas de ~N caracteres`; e insertar tras la línea que empieza por `progressive_disclosure:` (única, verificado) → «TOPE PUBLICADO: un borrador de mas de ~N caracteres falla por progressive_disclosure aunque se haya pedido detalle.» |
| goal = cita (base) | — | — | — |
| goal = venta | insertar tras `PRINCIPIO:` → «OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.» | — (el Composer ya «escribe dentro del objetivo que le da la estrategia») | — |
| goal = informar | insertar tras `PRINCIPIO:` → «OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.» | — | — |

- **Límites:** cada parche ≤ 1 200 bytes; cada rol compilado ≤ 40 960 bytes; el paquete ≤ 163 840 bytes. Superarlos → excepción (Legacy).
- **Versión del compilador:** `UltronPromptCompiler::VERSION = 1`. Cambiar un texto o un ancla obliga a subirla; las publicaciones firmadas con otra versión caen a Legacy en `decide` con motivo `compiler_changed` hasta volver a publicar (seguro, y se ve en la traza).
- **Pureza:** sin base de datos, sin `config()`, sin reloj, sin log. El Studio lo importa (dirección permitida, como `StudioLabEngineChecks`); el motor no importa nada del Studio. Nombres sin la subcadena `UltronStudio` (la vigila `UltronEngineIsolationTest`).

#### Compilador 3 (Enmienda A4-1 del contrato H5, 12-11-2026)

> Texto pendiente de la aprobación del propietario (HUMAN GATE 4-A del cierre V2): si lo cambia antes del despliegue, se corrige aquí y en el compilador sin subir otra vez la versión, porque el compilador 3 no habrá llegado a producción

- **Por qué.** En la prueba real del 12-11-2026 el redactor superó el tope de 200 en 4 de 5 borradores (307, 261, 303 y 224) con el texto del compilador 1–2, que solo pedía «como maximo unos N caracteres». Desde el cierre V2 (apartado 4) el commit mide el tope antes de enviar: al borrador que se pasa le quita la pregunta final y, si aun así no cabe, lo descarta; este texto se lo dice al redactor y le pide un margen.
- **Qué cambia.** Solo el texto de `maxChars = N` para Composer y Composer Reintento: **sustituye** al de la tabla de arriba, que se queda escrito como histórico (es el del compilador 1–2). Misma ancla (`FORMA:`), misma operación (`insert_after_line`) y mismo orden. `UltronPromptCompiler::VERSION = 3`.
- **Texto** (ASCII, sin tildes, una sola línea; `N` = `persona.maxChars` y `M`, `P` y `F` su margen): insertar tras `FORMA:` → «TOPE DE LONGITUD PUBLICADO: el limite es N caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos M caracteres, que son unas P palabras: como mucho F frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.»
- **Fórmulas** (enteros, sin coma flotante): `M = intdiv(N * 8, 100) * 10` (el 80 % de N redondeado a la decena por abajo); `P = intdiv(M, 30) * 5`; `F = max(2, intdiv(M + 30, 60))`.
- **Tabla** (los 16 topes distintos de 320):

| N | M | P | F |
|---|---|---|---|
| 160 | 120 | 20 | 2 |
| 180 | 140 | 20 | 2 |
| 200 | 160 | 25 | 3 |
| 220 | 170 | 25 | 3 |
| 240 | 190 | 30 | 3 |
| 260 | 200 | 30 | 3 |
| 280 | 220 | 35 | 4 |
| 300 | 240 | 40 | 4 |
| 340 | 270 | 45 | 5 |
| 360 | 280 | 45 | 5 |
| 380 | 300 | 50 | 5 |
| 400 | 320 | 50 | 5 |
| 420 | 330 | 55 | 6 |
| 440 | 350 | 55 | 6 |
| 460 | 360 | 60 | 6 |
| 480 | 380 | 60 | 6 |

- **Lo que no cambia.** Los textos de los Critic (`T_MAX_CRITIC`, «TOPE PUBLICADO: un borrador de mas de ~N caracteres…», y el reemplazo de `mas de ~900 caracteres`), los demás textos, las anclas y el orden. Con 320 no hay parche: `compile(línea base) === base` y la huella Legacy `3f6a5674…c902` siguen igual. Cambian las huellas de todo paquete con `maxChars` ≠ 320 (la de «profesional + 200», `702890f7…311c`, deja de valer). Lo publicado con el compilador 2 cae a Legacy en `decide` (`compiler_changed`) hasta publicar una versión nueva.

### B.4 Contrato de la publicación `ultron-engine-config/2` (aditivo)

- Misma entrada que `/1` (`ULTRON_STUDIO_H5_CONTRACT.md:111-117`) con `contract: "ultron-engine-config/2"` y cada parte no nula con dos claves más, exactas y tipadas:
  - `governed`: `{"persona.emojis": bool, "persona.maxChars": int, "persona.tone": string, "strategy.goal": string}`;
  - `prompts`: `{"base": "n8n-29de5119", "bundleHash": "<64 hex>", "compiler": 1}`.
- Firma, huella, decodificación estricta y tope de 16 KB: los de `/1`. El lector acepta `/1` y `/2`; con `/1` el sello `metadata.studio` sale como hoy y `decide` no ofrece prompts (`declaration_v1`).
- **Quién escribe `/2`:** el Studio, **solo si en el momento de publicar la lectura del motor dice que la llave de prompts no está en `off`** (`StudioEngineReadings`, que ya lee la configuración del motor para los `must_match`). Con la llave en `off` se escribe `/1` exactamente como hoy (el fixture congelado `publication-cases.json` sigue valiendo entero). Así no hace falta bandera nueva en `config/ultron_studio.php` (fichero sucio de otro agente).
- **Las entradas de «volver a Legacy» (`{canary:null, rest:null}`) se escriben siempre en `/1`**, para que un backend devuelto al código de hoy pueda leer la cabeza (R10).
- **Clasificación al publicar con la llave de prompts encendida:** los cuatro gobernados pasan de `no_effect` a una clase nueva `prompt` (no exige reconocimiento; la vista previa enseña su cambio exacto); `intent.fallback` y las S-NNN siguen en `no_effect`. Con la llave en `off`, clasificación idéntica a la de hoy. Publicar con un compilado que falla → 409 `prompts_compile_failed` (nunca se firma una parte que no compila).
- PostgreSQL: el CHECK `uspub_engine_contract_chk` pasa a admitir `/1` y `/2` (migración nueva; `down()` se niega si hay filas `/2`).

### B.5 Contrato nuevo de `decide` (aditivo y negociado)

- **Petición:** campo opcional `prompt_contract: "ultron-prompts/1"`. Se lee con `$request->input()` y **nunca produce un 422** (un valor desconocido = no negociado). El n8n de hoy no lo manda → respuesta y token **idénticos** a los de hoy.
- **Respuesta, solo si se negoció:** clave nueva `studio_prompts`:

```json
{"contract":"ultron-prompts/1","mode":"studio","reason":null,
 "base":"n8n-29de5119","compiler":1,"bundle_hash":"<64 hex>",
 "version":"<uuid>","content_hash":"<64 hex>","entry":"<uuid>","seq":7,
 "roles":{"strategist":"…","composer":"…","critic":"…","composer_retry":"…","critic_retry":"…"}}
```

  En modo `legacy`: `roles`, `bundle_hash`, `version`, `content_hash`, `entry` y `seq` a `null` y `reason` con uno de: `prompts_off`, `studio_config_off`, `out_of_scope`, `no_publication`, `part_legacy`, `declaration_v1`, `publication_<contract_unknown|hash_mismatch|signature_invalid|malformed|table_missing|read_failed>`, `base_changed`, `compiler_changed`, `bundle_mismatch`, `too_large`, `error`. Sin ids de conversación, etiquetas, nombres ni texto libre del personal (los prompts compilados solo contienen la base y textos fijos del compilador: **ningún dato personal**).
- **Token:** en la carga firmada `x` se añade `sp = {m, r, h, v, c, e, s}` (modo, motivo, huella del paquete, versión, huella de contenido, entrada, seq) solo si se negoció. Prueba: el token completo con `sp` < 1 500 caracteres (tope 2048).
- **Algoritmo (`UltronPromptOffer::forConversation`, nunca lanza):** (1) llave `marketing.ultron.studio_prompts` ≠ `canary` → `prompts_off`; (2) `marketing.ultron.studio_config` = `off` → `studio_config_off` (sin sello declarado no hay prompts: la traza quedaría coja); (3) canario del motor nulo o distinto de la conversación → `out_of_scope` (con el canario quitado, **nadie** recibe prompts: falla cerrado); (4) `UltronPublishedConfig::partFor()` (método nuevo, la misma resolución que `forConversation`) → sin cabeza, parte nula, `/1` o fallo de verificación → su motivo; (5) `base` y `compiler` firmados ≠ los del código → `base_changed` / `compiler_changed`; (6) compilar con `governed`; excepción o límites → `error` / `too_large`; (7) huella calculada ≠ `bundleHash` firmado → `bundle_mismatch`; (8) si todo cuadra → `studio`. Una sola sentencia sobre `ultron_studio_publications` por `decide`, y ninguna con la llave en `off` (DB::listen).
- **Llave y alcance:** `MARKETING_ULTRON_STUDIO_PROMPTS` → `marketing.ultron.studio_prompts`, valores `off` (defecto) y `canary`; cualquier otra cosa, `all` incluido, es `off` en esta entrega. La conversación canaria es la de `MARKETING_ULTRON_CANARY_CONVERSATION_ID` (entorno, la decide el propietario); el Studio nunca la conoce ni la enseña.
- **Ante versión ausente, firma inválida, base cambiada o error: siempre Legacy, nunca un turno mudo.** El freno de emergencia no cambia: con el freno echado no hay turno (comportamiento actual, el emisor y el commit lo cortan).

### B.6 Contrato nuevo de `commit`: de «declarada» a «aplicada»

- **Bloque opcional `prompts`** (n8n solo lo manda si `decide` devolvió `studio_prompts.contract`; por eso el n8n nuevo es compatible con un backend devuelto al código de hoy):

```json
"prompts": {"mode":"studio","bundle_hash":"<64 hex observado>","reason":null,"flow_rev":"S1","execution_id":"12345"}
```

  Reglas: `prompts` nullable array; `prompts.mode` `required_with:prompts` ∈ {studio, legacy}; `bundle_hash` nullable `^[0-9a-f]{64}$`; `reason` nullable ≤ 64 `^[A-Za-z0-9_.:-]+$`; `flow_rev` `required_with:prompts` ≤ 32 mismo patrón; `execution_id` nullable ≤ 64 mismo patrón. **Añadir `'prompts'` a la lista de bloques anidados de `unexpectedKeys` (`UltronController.php:408`).** Nada del commit se ramifica por este bloque: es traza.
- `bundle_hash` es la huella que **n8n calcula** sobre los cinco textos que acaba de pasar a los nodos de OpenAI (B.7): es una observación, no un eco de lo que le mandaron.
- **Sello nuevo `marketing_ai_actions.metadata.studio_prompts`** (`metadata.studio` NO cambia de forma; el fixture del H5 sigue intacto). Se escribe solo si el token trae `sp` o el cuerpo trae `prompts`; si no, la fila queda como hoy:

```json
{"v":1,"state":"applied",
 "offered":{"mode":"studio","reason":null,"version":"<uuid>","contentHash":"<hex>","entry":"<uuid>","seq":7,"bundleHash":"<hex>"},
 "observed":{"mode":"studio","bundleHash":"<hex>","reason":null,"flowRev":"S1","execution":"12345"}}
```

- **Estados** (calcula `UltronPromptStamp`, puro):

| Observado | Ofrecido | Huella observada | Estado |
|---|---|---|---|
| (sin bloque) | cualquiera | — | `not_reported` (n8n viejo o rendición) |
| studio | studio | = ofrecida | **`applied`** |
| studio | studio | ≠ ofrecida, o ofrecido ≠ studio | `mismatch` (aviso en log, sin textos) |
| legacy | studio | = `LEGACY_BUNDLE_HASH` | `engine_fallback` (n8n rechazó el paquete; `reason` dice por qué) |
| legacy | legacy | = `LEGACY_BUNDLE_HASH` | `legacy` |
| legacy | cualquiera | ≠ `LEGACY_BUNDLE_HASH` | **`legacy_drift`** (alguien editó a mano las constantes Legacy en n8n; aviso en log) |
| cualquiera | cualquiera | null | `unverified` |

- **Nunca bloquea y nunca lanza** (un fallo calculando el sello → `stamp_error`, con el log de clase del error). Se calcula justo después de verificar el token (`UltronCommitService.php:343-359`) y viaja como argumento hasta `persist()` y los caminos que crean filas después de ese punto (ejecutada, crítico fallido, rendición, hora de la visita), como `$studio` (dos commits no comparten sello). Las filas `blocked` previas al token no lo llevan (no hubo respuesta).
- **Cambio de cabeza entre `decide` y `commit`** (una reversión en esos segundos): no se rechaza el turno (las guardas de contenido siguen mandando); la fila enseña `offered` (lo que se usó, de `decide`) junto a `metadata.studio` (lo declarado al commit) y se ve la diferencia.

### B.7 El cambio en n8n (C) — nodo a nodo

Lo genera `php artisan ultron:prompts-n8n --out=n8n/flujos/ultron-studio-s1` (B-M) a partir de la base del repo; nadie lo escribe a mano. Produce: el código del selector, las operaciones atómicas para el MCP (`update_workflow`), las huellas esperadas y un `LEEME.md`. `FLOW_REV = "S1"`.

1. **Nodo nuevo «Elegir Prompts»** (`n8n-nodes-base.code` v2, JavaScript, una vez para todos los ítems), entre `Mensaje superado?` (salida falsa) y `Strategist`. Conexiones: quitar `Mensaje superado?`[1] → `Strategist`; añadir `Mensaje superado?`[1] → `Elegir Prompts` y `Elegir Prompts` → `Strategist`. Comportamiento (esqueleto; el generador lo emite completo):

```js
const CONTRACT = "ultron-prompts/1", FLOW_REV = "S1";
const ROLES = ["strategist","composer","critic","composer_retry","critic_retry"];
const LEGACY = { strategist: "<literal>", /* … los cinco, generados desde la base */ };
const LEGACY_HASH = "<64 hex>";      // generado; informativo
const MAX_ROLE = 40960;              // caracteres JS (PHP limita en bytes: más estricto)
function sha256hex(s) { /* SHA-256 en JS puro sobre UTF-8 (unescape(encodeURIComponent(s))) */ }
const material = r => [CONTRACT].concat(...ROLES.map(k => [k, r[k]])).join("\n");
const ctx = $input.first().json || {};
let mode = "legacy", reason = "absent", roles = LEGACY, observed = null;
try {
  const sp = ctx.studio_prompts;
  if (sp && sp.contract === CONTRACT) {
    if (sp.mode !== "studio") reason = "offered_" + String(sp.reason || "legacy").slice(0, 48);
    else if (!sp.roles || typeof sp.roles !== "object" || Object.keys(sp.roles).sort().join() !== [...ROLES].sort().join()) reason = "shape";
    else if (ROLES.some(k => typeof sp.roles[k] !== "string" || sp.roles[k].length < 1 || sp.roles[k].length > MAX_ROLE)) reason = "size";
    else { const h = sha256hex(material(sp.roles));
           if (h !== sp.bundle_hash) reason = "hash_mismatch"; else { mode = "studio"; reason = null; roles = sp.roles; observed = h; } }
  } else if (sp) reason = "contract_unknown";
  if (mode === "legacy") observed = sha256hex(material(LEGACY));
} catch (e) { mode = "legacy"; roles = LEGACY; reason = "selector_error"; observed = null; }
const out = Object.assign({}, ctx); delete out.studio_prompts;
out.ultron_prompts = { mode, reason, bundle_hash: observed, flow_rev: FLOW_REV, roles };
return [{ json: out }];
```

   La verificación de n8n es de **integridad** (forma, tamaños y sha256 de lo recibido = `bundle_hash`); la autenticidad la da el canal autenticado de `decide`, y la prueba de lo aplicado la hace Laravel al comparar la huella observada con la firmada en el token. Sin `require('crypto')` (no sabemos si `NODE_FUNCTION_ALLOW_BUILTIN` lo permite en el servidor **[SUPOSICIÓN]**) y sin nodos extra.
2. **Contenido de sistema de los 5 nodos de OpenAI** (`responses.values[0].content`; `values[1]`, `options`, modelo y reintentos **sin tocar**):
   - Strategist: `={{ $('Elegir Prompts').first().json.ultron_prompts.roles.strategist }}`
   - Composer: `… .roles.composer }}` · Critic Comercial: `… .roles.critic }}` · Composer Reintento: `… .roles.composer_retry }}` · Critic Comercial 2: `… .roles.critic_retry }}`
3. **«Pedir Contexto al CRM»**, `jsonBody`: `={{ JSON.stringify({ conversation_id: $json.conversation_id, message_id: $json.message_id, prompt_contract: "ultron-prompts/1" }) }}`.
4. **«Preparar Commit»**, antes del `return`: si `ctx.studio_prompts && ctx.studio_prompts.contract === "ultron-prompts/1"`, construir `prompts = {mode, bundle_hash, reason, flow_rev}` desde `$("Elegir Prompts").first().json.ultron_prompts` más `execution_id: String($execution.id)`, todo en `try/catch` (si falla, sin bloque); y devolverlo como clave de primer nivel solo si existe. Nada más cambia en el nodo.
5. **Nada más.** `Preparar Rendicion`, `Rendicion Minima`, los IF, los `Responder:*`, el webhook y el flujo de errores (`DaJ0v1QWTASuxmWH`) no se tocan. (Un fallo en «Elegir Prompts» llegaría al parte con la etapa `unknown`, que el backend admite: `UltronController.php:61-65`.)

**Por qué una conversación no canaria (o con todo apagado) da exactamente lo de hoy:** las constantes `LEGACY` son los bytes de la base, que son los de 29de5119 (C1); el contenido de sistema es una referencia a una cadena, que n8n no reevalúa; `values[1]` no cambia y el Strategist recibe la respuesta de `decide` intacta (más una clave que su expresión no lee). La única diferencia observable es la traza. Se demuestra en tres sitios: prueba unitaria `compile(base) = base`, el comprobador sobre el flujo exportado (B.8) y el primer turno real con la llave en `off` (huella observada = `LEGACY_BUNDLE_HASH`).

### B.8 Cómo se prueba, se verifica y se revierte n8n

- **Comprobador offline** `backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json>` (Node, sin red): nodos = los 24 del respaldo + «Elegir Prompts»; solo cambian los 8 parámetros de B.7 y la conexión; `values[1]` y `options` de los cinco nodos idénticos al respaldo (sha256); ejecuta el selector en `vm` con fixtures (sin `studio_prompts` → roles = base byte a byte y huella = `LEGACY_BUNDLE_HASH`; `studio` válido → roles ofrecidos; un rol alterado → `hash_mismatch`; falta un rol → `shape`; uno enorme → `size`; contrato desconocido) y ejecuta `Preparar Commit` en `vm` (sin negociación → sin bloque `prompts`; con negociación → bloque válido para el contrato). Sale ≠ 0 ante cualquier diferencia.
- **Ensayo en copia (lead):** duplicar el flujo en n8n (inactivo, otro path de webhook, nombre «… (copia Studio S1)»); aplicar las operaciones; exportar y pasar el comprobador; ejecutar a mano con datos fijados en `Entrada ULTRON` y en `Pedir Contexto al CRM` (fixtures sintéticos del repo: uno sin `studio_prompts`, uno `studio` con `tone=profesional`), con `Ejecutar en el CRM` **desactivado** (la copia nunca llama al commit de producción). Con autorización del propietario (G9), una ejecución real de los nodos de OpenAI con el fixture sintético de precio para comprobar que el borrador sigue usando `{{PLAN_PRICE}}` literal (verifica la suposición de no-reevaluación) y que el registro cambia a «usted». **No se leen ejecuciones del flujo de producción** (enseñan el bearer en claro, memoria del proyecto); la copia ejecutada a mano sin webhook no lo lleva **[SUPOSICIÓN: verificarlo antes de abrir su ejecución]**.
- **Tamaño por el MCP:** el `jsCode` del selector ocupa ~115 KB. Probarlo primero en la copia. Si el MCP no lo admite o confirma sin escribir (memoria del proyecto), aplicar con el editor de n8n o su API pública; en todo caso **verificar siempre con el `versionId` y el comprobador sobre el flujo exportado**.
- **Producción:** las mismas operaciones en un borrador del flujo real → `versionId` nuevo del borrador → exportar → comprobador → publicar → anotar el `versionId` activo → primer turno real de la canaria con las llaves en `off` → `ultron:prompt-trace` debe dar `legacy`, `flowRev S1` y la huella Legacy.
- **Vuelta atrás:** restaurar y publicar la versión `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` desde el **historial de versiones de n8n**. **Nunca reimportando el respaldo JSON**: está limpio de credenciales y dejaría sin credencial a OpenAI y a las dos llamadas al CRM (todos los turnos morirían). Verificación: `versionId` activo = `29de5119…` y el siguiente turno canario sale `not_reported` (el flujo viejo no negocia) con respuesta normal.

---

## Trazabilidad en el CRM (D)

- **Por turno, en el Studio** (`GET status` → `engine.prompts`, permiso `ultron.view`): `{key: off|canary, base, compiler, legacyBundleHash, current: {mode, version?, bundleHash?, reason?}, lastTurns: [≤ 20]}`, con cada turno de la conversación canaria (`source_type = inbound_message`) como `{at (minuto UTC), declared: {state, version8}, offered: {mode, reason, version8, bundle8}, observed: {mode, bundle8, flowRev}, state}`. **Sin ids de conversación, de mensaje ni textos**, el mismo criterio que la evidencia del H5 (A9). Se calcula en `StudioPublications` (lectura de `marketing_ai_actions` ya permitida al Studio para recuentos de la canaria).
- **CRM:** la barra de publicación enseña «Prompts del motor: Legacy | <versión> (huella xxxxxxxx) | llave apagada»; un bloque nuevo «Últimos turnos de la canaria» con la tabla de arriba y el estado con su etiqueta en llano; el diálogo de publicar enseña la tabla de cambios (parámetro, valor, roles, texto exacto) y la huella del paquete. **El texto obligatorio del H5** («En este hito no cambia lo que ULTRON dice ni hace», `ULTRON_STUDIO_H5_CONTRACT.md:286`; CRM `studio-publication-text.ts:28`; backend `StudioPublications.php:106-107` y `PermissionCatalog.php:242-243`) **deja de ser cierto con la llave encendida**: pasa a depender de `preview.effect = declaration | prompts`, que manda el backend.
- **Consola del servidor:** `php artisan ultron:prompt-trace --last=20 [--json]` (solo lectura, mismo contenido, más la oferta que haría `decide` ahora mismo). Es la evidencia mínima si el CRM no llegara a tiempo.
- **Cómo se demuestra una reversión:** turnos T1 (`legacy`, huella L) → publicar V2 → T2 (`applied`, V2, huella H2, estilo nuevo visible en el WhatsApp) → revertir V2 en el Studio → T3 (`legacy` o la parte anterior, huella L). La tabla lo enseña en tres filas; el propietario lo ve en su teléfono.

---

## Plan

Fechas: construir 12-11 y 13-11; activar 14-11 y 15-11 (ventana de publicación 07:00–19:00 Bogotá, H5 A5.1 paso 11). Cada paso con su verificación. «∥» = puede ir en paralelo (ficheros disjuntos).

0. **[lead + propietario] Congelar la enmienda «M-1» del contrato H5** (12-11, mañana): este documento §B.3–B.6 como contrato (`/2`, `ultron-prompts/1`, estados, llave, frontera A9 nueva: «el motor lee una tabla del Studio desde una clase; la invocan el commit (sello) y `UltronPromptOffer` (oferta de prompts en `decide`); el Studio importa del motor solo `UltronPromptBase` y `UltronPromptCompiler`, puros») y **los textos de los parches** (G3). *Verificación:* documento firmado por el lead; sin él nadie escribe contratos. **Bloquea 1b, 2 y 5.**
1. **[backend · B-M] Paquete del motor.**
   - **1a** (12-11, ∥ 3, ∥ 4, ∥ 5a): base (`resources/ultron-prompts/n8n-29de5119/*.txt` desde `nuevo4.json`), `UltronPromptBase` y `UltronPromptCompiler` con los parches de B.3. *Verificación:* `tests/Unit/Marketing/Ultron/Prompts/*` verdes (E1–E4). **Entrega su API a B-S a mediodía.**
   - **1b** (12-11 tarde): llave en `config/marketing.php`; `UltronPublishedConfig::partFor()` + lectura de `/2`; `UltronPromptOffer`; `decide` (negociación, `studio_prompts`, `sp` en el token); `commit` (bloque `prompts`, `unexpectedKeys`, `UltronPromptStamp`, sello en `persist()`); `UltronEngineIsolationTest` (lista de quién nombra al lector y llave nueva en `off` por defecto). *Verificación:* E5–E9 verdes y **todas las suites `Ultron*` verdes dos veces, con las llaves en `off` y en `canary`** (como H5 A12). **Primer punto verde y desplegable: tras 1b con las llaves en `off` el sistema se comporta exactamente como hoy.**
   - **1c** (13-11): `ultron:prompt-trace`, `ultron:prompts-n8n` (generador) y `docs/ultron-studio/ops/motores/{verificar-flujo.cjs, motores-prompts-scope.sh, README.md}` (el script de llaves copia el patrón de `ops/h5-engine-scope.sh`: copia 600 del `.env`, `config:cache` como www-data, reinicio de workers, sin imprimir valores). *Verificación:* E10 verde; el comprobador pasa sobre el flujo generado y falla sobre el respaldo 29de5119.
2. **[backend · B-S] Paquete del Studio** (12-11 tarde → 13-11; ∥ 1b tras la API de 1a): `StudioPublicationDeclaration` (`/2`, partes, clasificación con llave), `StudioPublishing` (escribe `/2` solo con la llave encendida y nunca en Legacy; compila y firma `governed` + `prompts`; 409 `prompts_compile_failed`), `StudioPublications` (vista previa con `prompts` y `effect`; `status.engine.prompts`; textos de `:106-107`), `StudioEngineReadings` (lectura de la llave de prompts), `StudioCatalog` (textos de los bindings de los cuatro gobernados y de `fallback`, componentes reales que dicen «sin versión por turno», `VERSION` + `ultron-studio:export-fixtures`), `PermissionCatalog.php:242-243`. **No tocar** `config/ultron_studio.php`, `StudioMessageTemplates.php`, `UltronStudioStrategyController.php`. *Verificación:* E11–E13 verdes; `UltronStudioEngineReadOnlyTest` y `StudioCatalogTest` verdes.
3. **[database-specialist]** (12-11, ∥ 1a): migración `2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications.php` (PG: `DROP`+`ADD` del CHECK en un solo `ALTER TABLE`, `/1|/2`; SQLite sin cambios; `down()` se niega con filas `/2`) y `ops/motores-schema-check.sql`. *Verificación:* `ops/pgunit.sh` (puerta obligatoria) con `/2` aceptado, `/3` rechazado (23514) y `down()` negado.
4. **[frontend]** (12-11 ∥ → 13-11, contra fixtures): `studio-api.types.ts` (DTOs nuevos tolerantes a su ausencia), `studio-publication-text.ts` y `studio-publication-dialog.ts` (texto según `effect`, tabla de cambios, huella), `studio-publication-bar.ts` (estado de prompts), componente nuevo `studio-prompts-trace.ts` (+ spec, hoja < 8 KB, nunca `ultron-studio.scss`), fixtures regenerados con `export-fixtures`. Solo `src/app/modules/ultron-studio/`; nada de `commercial-automation`. *Verificación:* specs, paridad de fixtures, `check:literals`, build y presupuestos de chunk; 390 px y teclado.
5. **[tester]** **5a** (12-11, desde la enmienda, sin mirar la implementación): `tests/fixtures/ultron-prompts/compile-cases.json` derivado de forma independiente (script Node propio que aplica la tabla de parches) y `tests/fixtures/ultron-studio/publication-cases-v2.json`. **5b** (13-11): mutaciones (quitar la comparación de huella en `decide`, el `unexpectedKeys` de `prompts`, el `try` del selector, la comprobación de unicidad de anclas, el `/1` de Legacy): cada una tumba su prueba; E2E (E14); suite completa contra `origin/main` el mismo día y PG.
6. **[security + api-security-specialist]** (13-11, tras 1b y 2): ver §Riesgos R4, R15 y la lista de E15. Hallazgo bloqueante → vuelve al dueño del paquete.
7. **[reviewer]** (13-11 noche): veredicto. Sin bloqueantes abiertos no se despliega.
8. **[lead] Activación por etapas** — cada etapa con orden expresa del propietario (G5), solo la conversación canaria (la del propietario, según el lead), nunca clientes:
   - **E0** (14-11): desplegar backend + migración con las llaves en `off`. *Verificación:* turno canario normal; `metadata.studio_prompts` ausente; suite de humo.
   - **E1** (14-11): n8n S1 en copia → comprobador → producción (B.8). *Verificación:* turno canario → `legacy`, huella = `LEGACY_BUNDLE_HASH`, `flowRev S1`. **Esto demuestra en producción que Legacy es idéntico.**
   - **E2** (14-11): `MARKETING_ULTRON_STUDIO_CONFIG=canary` (si no lo estaba), `MARKETING_ULTRON_STUDIO_PROMPTS=canary`, `ULTRON_STUDIO_PUBLISHING_ENABLED=true` con el script. *Verificación:* turno → `legacy` con motivo `no_publication` o `declaration_v1`.
   - **E3** (15-11, opcional pero recomendado): publicar en canario una versión **con los valores de la línea base** → turno `applied` con huella = `LEGACY_BUNDLE_HASH` (todo el camino del Studio, cero cambio de comportamiento) → revertirla (una sola `CANARY` a la vez, H5 A5.1 paso 10).
   - **E4** (15-11): publicar V2 con `persona.tone=profesional` y `persona.maxChars=200` (G8) → 2–3 turnos desde el teléfono canario. *Verificación:* `applied` con la huella de V2 en la traza y respuestas de usted y cortas. **Criterio de aceptación cumplido.**
   - **E5** (15-11): revertir V2 en el Studio → turno siguiente `legacy` (huella L) y estilo de antes. **Reversión verificada.** Después, el propietario decide si V2 se queda en canario; `all` no existe en esta entrega.

**Recorte si falta tiempo (en este orden):** textos del catálogo (no el texto del diálogo de publicar, que es obligatorio) → tabla de traza en el CRM (queda `ultron:prompt-trace` y el JSON de `status`) → `goal` y `maxChars` (con `tone` y `emojis` basta para demostrar el criterio). Nunca se recortan: la negociación, la huella observada, `compile(base)=base`, el comprobador del flujo ni la etapa E1.

### Pruebas (E)

| # | Qué | Dónde | Dueño |
|---|---|---|---|
| E1 | Base: sha256 fijados; cada fichero = `nuevo4.json → .system` byte a byte; `LEGACY_BUNDLE_HASH` = cálculo | `tests/Unit/Marketing/Ultron/Prompts/UltronPromptBaseTest.php` | B-M |
| E2 | `compile(línea base) === base` y huella Legacy; cada valor cambia **solo** sus roles (los demás idénticos); anclas únicas; determinismo (mismas entradas en otro orden = mismo resultado); los 17 valores de `maxChars`; límites; valor desconocido → excepción | `UltronPromptCompilerTest.php` + `compile-cases.json` | B-M / tester |
| E3 | La huella PHP = la del SHA-256 JS del selector sobre la base y sobre un caso `studio` | `ops/motores/verificar-flujo.cjs` + prueba PHP con el mismo fixture | tester |
| E4 | `UltronPromptStamp`: la tabla de estados de B.6 completa | Unit | B-M |
| E5 | `decide` sin negociar: claves de la respuesta y carga del token **idénticas** a hoy (instantánea) | `tests/Feature/Marketing/UltronDecidePromptsTest.php` | B-M |
| E6 | `decide` negociado: matriz `studio_config {off,canary,all}` × `studio_prompts {off,canary}` × conversación {canaria, otra, canario sin configurar} × cabeza {ninguna, `/1`, `/2` válida, firma mala, base cambiada, compilador cambiado, parte nula}; nunca lanza (tabla inexistente, error de lectura); una sentencia con la llave encendida y cero apagada (DB::listen); token < 1 500 | idem | B-M |
| E7 | `commit` sin bloque: comportamiento y `metadata` idénticos (instantánea; `UltronCommitStudioStampTest` intacto) | `tests/Feature/Marketing/UltronCommitPromptsTest.php` | B-M |
| E8 | `commit` con bloque: aceptado; clave desconocida dentro de `prompts` → 422 `unexpected_fields` `prompts.x`; modo inválido → 422; los 7 estados; el sello en todas las filas posteriores al token; dos commits no lo comparten; logs sin textos | idem | B-M |
| E9 | Regresión: todas las suites `Ultron*` y `UltronFixturesTest` verdes con llaves en `off` y en `canary`; `UltronEngineIsolationTest` actualizado | suites existentes | B-M |
| E10 | Generador: el selector emitido contiene la base exacta; las operaciones del MCP tocan solo lo de B.7; el comprobador pasa sobre el flujo generado y falla sobre el respaldo | `UltronPromptsN8nCommandTest.php` + `verificar-flujo.cjs` | B-M |
| E11 | Declaración `/2`: bytes exactos, firma, parseo `/1` y `/2`, Legacy siempre `/1`, clasificación con y sin llave, `previewHash` cambia con la huella del paquete, 409 `prompts_compile_failed` | Unit/Feature del Studio + `publication-cases-v2.json` | B-S / tester |
| E12 | `status.engine.prompts`: forma, sin ids ni textos, solo turnos de la canaria; 401/403 por rol como el resto de `status` | Feature | B-S |
| E13 | PG: CHECK `/1|/2`, `down()` negado; carreras de publicar en `/2` como en H5 | `ops/pgunit.sh` | database-specialist / tester |
| E14 | **E2E con doble del flujo:** `Iron-Body_Front/e2e/ultron-studio/mock-n8n.cjs` aprende a negociar, ejecuta **el selector generado** en `vm` y manda el bloque `prompts`; spec nueva `specs/motores-prompts.cjs`: llaves en `off` → `legacy`; publicar V2 con cuatro ojos → turno `applied` con la huella de V2 y el rol `composer` recibido contiene «REGISTRO PUBLICADO: profesional»; revertir → `legacy` con huella L; llave en `off` → sin oferta; rol alterado en tránsito → `engine_fallback`; constantes Legacy alteradas → `legacy_drift` | arnés del H5 | tester |
| E15 | Seguridad: nada del commit se ramifica por `prompts`; `decide` no expone ids ni texto libre; el token sigue firmado; la vista previa ata la huella exacta a los cuatro ojos; `status` sin ids; los prompts no llevan datos personales; ningún texto del personal entra en un prompt en esta entrega | revisión | security / api-security |

**No regresión de Legacy, en cuatro capas:** E2 (`compile(base)=base`), E3/E10 (el selector devuelve la base byte a byte), E5/E7 (el n8n de hoy recibe lo mismo y su commit se trata igual) y E1 de activación (huella observada en producción = Legacy).

### Paquetes, dueños y paralelismo (F)

| Paquete | Dueño | Ficheros exclusivos | Depende de | En paralelo con |
|---|---|---|---|---|
| 0 Enmienda M-1 + textos | lead + propietario | este plan (como contrato) | — | — |
| 1a Base + compilador | backend B-M | `app/Services/Marketing/Ultron/Prompts/UltronPrompt{Base,Compiler}.php`, `resources/ultron-prompts/**`, sus Unit | 0 (textos) | 3, 4, 5a |
| 1b Oferta, decide, commit, sello | backend B-M | `UltronPublishedConfig.php`, `UltronDecideService.php`, `UltronController.php`, `UltronCommitService.php`, `config/marketing.php`, `UltronPrompt{Offer,Stamp}.php`, `UltronEngineIsolationTest.php`, Feature nuevas | 1a | 2 |
| 1c Traza, generador, comprobador, script de llaves | backend B-M | `app/Console/Commands/Marketing/UltronPrompt*.php`, `n8n/flujos/ultron-studio-s1/**`, `docs/ultron-studio/ops/motores/**` | 1b | 2, 4 |
| 2 Studio | backend B-S | `StudioPublicationDeclaration.php`, `StudioPublishing.php`, `StudioPublications.php`, `StudioEngineReadings.php`, `StudioCatalog.php`, `PermissionCatalog.php`, sus pruebas | 1a (API), 0 | 1b, 1c, 3, 4 |
| 3 Migración | database-specialist | migración nueva, `ops/motores-schema-check.sql` | 0 | 1a, 4 |
| 4 CRM | frontend | `Iron-Body_Front/src/app/modules/ultron-studio/**` (sin `commercial-automation`) | 0 (DTOs) | todo |
| 5 Pruebas | tester | `tests/fixtures/ultron-prompts/**`, `publication-cases-v2.json`, `e2e/ultron-studio/{mock-n8n.cjs, specs/motores-prompts.cjs}` | 0; E14 tras 1c y 2 | todo |
| 6 Seguridad | security + api-security-specialist | — | 1b, 2 | 4, 5 |
| 7 Revisión | reviewer | — | 1–6 | — |
| 8 n8n y producción | lead | n8n, servidor | 7 | — |

Con dos agentes backend: B-M hace 1a→1b→1c; B-S hace 2 desde el mediodía del 12-11. Con uno solo: 1a → 1b → 2 → 1c, y el día 2 se recorta según «Recorte».

---

## Riesgos y puntos de no retorno

- **R1. Se modifica el flujo vivo.** Hoy solo lo recorre la conversación canaria (C2); un defecto del selector afecta solo a esa conversación y, si deja un turno mudo, el vigía frena el canario. Mitigación: selector con `try/catch` que siempre cae a Legacy, comprobador offline, ensayo en copia, etapa E1 con llaves en `off`, vuelta atrás por historial.
- **R2. Restaurar importando el respaldo** dejaría el flujo sin credenciales (el respaldo está limpio de ellas): todos los turnos morirían. Solo historial de versiones de n8n.
- **R3. El MCP puede confirmar escrituras que no ocurrieron** y puede no admitir 115 KB en un parámetro: verificar siempre `versionId` + export + comprobador + turno real; plan alternativo por el editor o la API pública.
- **R4. Leer una ejecución del flujo de producción enseña el bearer:** la evidencia es el sello (`studio_prompts`) y la traza; el `execution_id` del sello sirve para correlacionar metadatos, no para abrir la ejecución.
- **R5. Un `maxChars` estrecho** hará fallar más al Critic → más rendiciones (texto curado). Se mira `recoveries` en la evidencia del canario; la V2 de aceptación usa 200, no 160.
- **R6. Un parche puede no obedecerse del todo** (el modelo pondera reglas). Por eso los parches **sustituyen** donde la base dice lo contrario y se dicen «esto manda»; el efecto real se mide en la canaria, no se promete.
- **R7. Cambiar la base o los textos del compilador en el repo** hace que toda publicación `/2` anterior caiga a Legacy (`base_changed`/`compiler_changed`) hasta volver a publicar. Es lo seguro; se documenta en el README de `ops/motores` y se ve en la traza.
- **R8. Tamaño:** `decide` crece ~113 KB solo en turnos canarios negociados; los datos de ejecución de n8n crecen ~0,3 MB por ejecución canaria (la respuesta de `decide` se copia en `Validar Estrategia`). Despreciable con el tráfico de la canaria; vigilarlo si un día hay `all`.
- **R9. Token:** `sp` añade ~350 caracteres; prueba < 1 500 contra el tope de 2048.
- **R10. Vuelta atrás del backend con una cabeza `/2`:** el lector viejo la sellaría `fallback/contract_unknown` (inocuo) pero el Studio viejo podría no parsearla al revertir. Procedimiento: **primero «volver a Legacy» en el Studio (escribe `/1`), después devolver el código.** **Punto de no retorno (inocuo):** las entradas `/2` quedan para siempre en la cadena de solo inserción.
- **R11. n8n nuevo + backend viejo:** seguro por la negociación (sin `studio_prompts` no hay bloque `prompts` y no hay 422). El orden de despliegue sigue siendo backend primero.
- **R12. Cabeza movida entre `decide` y `commit`:** el turno sale con lo ofrecido; la fila enseña ofrecido y declarado, distintos. No se rechaza (rechazar crearía un camino de fallo nuevo por una traza).
- **R13. Árbol sucio de otros agentes:** `.env.example` y `config/ultron_studio.php` no se tocan; el lead documenta las variables al integrar.
- **R14. Si alguien quita el canario del motor**, n8n atendería a todas las conversaciones, pero en Legacy idéntico (la llave `canary` de prompts sin canario no ofrece a nadie).
- **R15. Superficie nueva:** ninguna entrada de texto libre del personal llega a un prompt en esta entrega (las S-NNN siguen fuera), así que no se abre inyección desde el Studio; la superficie nueva es el tamaño de `decide` y el DTO de traza. La revisan `security` y `api-security-specialist` (paso 6).
- **R16. El laboratorio no prueba estos prompts** (C2): su veredicto no valida lo que se publica (G6).

---

## Lo que solo el propietario puede decidir o autorizar (G)

| # | Decisión | Recomendación |
|---|---|---|
| G1 | Responder a B-A1/Q6: el Studio gobierna el comportamiento de ULTRON por WhatsApp mediante prompts compilados desde la versión publicada, y **los prompts dejan de editarse a mano en n8n** (un cambio de base va por el repo, con base nueva y nueva publicación) | **Sí** |
| G2 | Alcance de esta entrega: `tone`, `emojis`, `maxChars`, `goal`. `intent.fallback` sigue sin efecto (contradice R-07) y las reglas S-NNN también (meter texto libre del personal en el prompt de producción es un hito aparte, con laboratorio que use los prompts reales) | **Sí** |
| G3 | Textos exactos de los parches (§B.3), el 12-11 por la mañana | Aprobar los borradores o corregirlos ese día; después solo con versión nueva del compilador |
| G4 | Semántica de la línea base (`maxChars = 320` y `goal = cita` significan «como hoy») | **Aceptar ahora**; corregir el catálogo en el hito siguiente |
| G5 | Órdenes para E0 (desplegar backend), E1 (cambiar el flujo de n8n), E2 (llaves `studio_config`, `studio_prompts` y publicar), E3–E5 (publicar y revertir) | Autorizar en ese orden, cada una tras ver la evidencia de la anterior |
| G6 | Puerta del laboratorio en estas publicaciones: `required` (exige un veredicto que no prueba estos prompts) o `waived` explícito (queda escrito en cada publicación y en rojo en el CRM) | **`waived` solo para E3–E5**, compensado por la vista previa exacta, el ensayo en copia y la canaria del propietario; volver a `required` después |
| G7 | **Segunda persona real con cuenta propia** para los cuatro ojos (quien publica ≠ autor; no hay atajo) | Imprescindible: sin ella **no hay E3–E5 el 15-11**, aunque el código esté listo |
| G8 | Cambio de aceptación: `tone = profesional` + `maxChars = 200`, enviado desde el teléfono canario | Sí |
| G9 | Gasto de OpenAI en el ensayo de la copia con datos sintéticos | Sí (≤ 0,05 US$) |
| G10 | Modificar el flujo vivo (opción 1) frente a dos flujos (opción 3) | **Opción 1** |

## Preguntas abiertas

Solo las que cambian materialmente el plan:

1. **G7:** ¿quién es la segunda persona y tendrá cuenta el 15-11? Si no, el plan se entrega hasta E2 (Legacy verificado en producción con la tubería nueva) y E3–E5 esperan.
2. **G6:** ¿`waived` o `required`? Con `required` hace falta además un veredicto `approved` del laboratorio sobre V2 antes del 15-11.
3. **Técnica (la resuelve el lead en la copia, no el propietario):** ¿admite el MCP de n8n un `jsCode` de ~115 KB? Si no, la etapa E1 se hace con el editor o la API pública; no cambia el diseño.
