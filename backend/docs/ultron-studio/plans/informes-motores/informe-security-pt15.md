# Informe de security · PT-15: las dos condiciones (ReDoS de la lista y cupo propio de `ai/check`)

**22-11-2026.** Árbol `_worktrees/cierre-pagos/Iron-Body`, HEAD suelto `2f37a2cb`, comparado con la base de producción `f1fe6cac` (4 ficheros, +148 −8). Una sola pasada y alcance estrecho: no se ha tocado el árbol (`git status --porcelain` vacío al empezar y al acabar). **[C]** comprobado ejecutando · **[L]** leído.
Las sondas están en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/2bba8344-f27f-4337-82f0-4afd34306546/scratchpad/sondas/`.

**Resultado: 0 bloqueantes, 3 no bloqueantes.** Aparte, y fuera del diff, hay un problema preexistente de severidad alta (P-1) que conviene derivar antes de ampliar.

---

## Pregunta 1 · Las expresiones nuevas sobre el texto del cliente

### 1.1 Qué recibe la función [L]
- **Origen del texto.** Es `$message->body` (`UltronDecideService.php:465`→`:313`, `UltronCommitService.php:1820` y `:5585`), que viene de `ProcessMetaWebhookEvent.php:303`.
- **Sin recorte en el servidor.** La columna es `text` y el webhook no aplica `max`. El tope real lo pone WhatsApp: 4 096 caracteres. El webhook de Meta va firmado (`WebhookMetaController`), así que un tercero no puede inyectar 65 000 caracteres por esa vía.
- **Normalización.** `SalesAgentDecisionSchema::normalize()` solo aplica `mb_strtolower` y `strtr` de las vocales acentuadas y la ñ. No recorta.
- **Llamadas por turno.** `pideElPanorama()` se ejecuta hasta 3 veces: en `decide`, en el `commit` y en el respaldo.

### 1.2 Tiempos, con la función REAL [C]
Sonda `redos.php`: 35 formas adversarias, cada una a 4 096 y a 65 000 caracteres, más un caso de UTF-8 inválido. Las formas cubren:
- grupos opcionales repetidos («y los planes…», «por favor…», «el plan el plan…», «cuanto cuesta el plan mensual…», cortesías mezcladas);
- miles de espacios, comas, «?», «¿», saltos de línea o NBSP;
- letras ASCII, cirílico, CJK, emoji, ZWJ y combinantes;
- textos sin terminador, saludos repetidos y la alternativa anclada.

Se mide la función entera y cada expresión por separado (`PIDE_PANORAMA`, `PREGUNTA_DE_PRECIO_A_SECAS`, `SALUDO_QUE_PREGUNTA`, `OTRO_TEMA_DEL_PANORAMA`, `NO_PIDE_EL_PANORAMA`, `ES_PREGUNTA` y el `preg_split`).

| PHP 8.3.33 · PCRE 10.47 | peor `pideElPanorama()` a 4 096 | a 65 000 | errores PCRE a ≤ 4 096 |
|---|---|---|---|
| JIT activado (como producción por defecto) | **1,22 ms** | 13–16 ms | 0 |
| JIT desactivado (`backtrack_limit` 1 000 000) | **3,27 ms** | 64–88 ms | 0 (y 0 también a 65 000) |

- **Crecimiento lineal.** Con 16 veces más texto, el tiempo crece entre 10 y 16 veces. No hay retroceso catastrófico. Confirma lo que midió backend (≤ 3 ms con 6 000 caracteres) también con los casos peores.
- **Ningún aviso de PHP** en ninguna sonda (`avisos: 0`). Ningún error en tiempo de ejecución lanza `ErrorException`.

### 1.3 Errores de PCRE y hacia dónde fallan [C]
- **Solo aparece «JIT stack limit exhausted», y muy por encima de 4 096.** Sonda `umbral-jit.php`:

  | Construcción | Primer error a partir de |
  |---|---|
  | `SALUDO_QUE_PREGUNTA` (nueva) | 24 578 caracteres |
  | La cola de cortesías de `PIDE_PANORAMA` (nueva) | 47 702 |
  | `PREGUNTA_DE_PRECIO_A_SECAS` (nueva) | 81 896 |
  | La alternativa anclada de `PIDE_PANORAMA` (ya existía en `f1fe6cac`) | 23 210 |

  El margen es de 6 veces o más sobre el tope de WhatsApp.
- **Todos esos errores fallan hacia «no es la lista»:**
  - la puerta principal es `preg_match(self::PIDE_PANORAMA, $t) === 1` (`CommercialTurnPolicy.php:1153`);
  - en `otraPreguntaEnElMensaje()`, un `false` de `PIDE_PANORAMA`, `PREGUNTA_DE_PRECIO_A_SECAS` o `SALUDO_QUE_PREGUNTA` da `!== 1`, que se lee como «otra pregunta» (`:1195-1197`).
- **Contraste antes/después** (`antes-despues.php`, cargando el fichero de `f1fe6cac`):
  - «que planes tienen» con 65 000 caracteres de cortesías recibía la lista y ahora devuelve `false` por el límite de JIT. Falla hacia el modelo.
  - Los casos que ya fallaban antes siguen igual.
- **Qué hace quien llama con `false`:**
  - `decide` devuelve `null` y el turno sale como hoy;
  - el `commit` no exige la lista;
  - el respaldo sale sin lista.
  - No hay excepción ni turno mudo.
- **UTF-8 inválido.** No llega por JSON. Aun así, `mb_strtolower` convierte el byte malo en «?» y no se produce ningún error.

### 1.4 PCRE 10.39 [C]
- **Compilé `pcre2test` 10.39** en el scratchpad, desde el tarball oficial. Su SHA-256 (`0f03caf5…6d440`) coincide con el `pcre2.hash` de Buildroot 2022.02, verificado allí con PGP.
- **Control positivo:** `\p{Extended_Pictographic}` da «error 147: unknown property name» en 10.39 y compila en 10.47. Reproduce el antecedente de producción.
- **Las expresiones de este diff compilan en 10.39:** las 24 constantes de `CommercialTurnPolicy` y las 3 en línea de `otraPreguntaEnElMensaje()` (`(?<=[.!?\n])|(?=¿)`, `[\p{L}\p{N}]`, `[¿?]`), con `utf,ucp`, que es lo que pone el `/u` de PHP.
- **Mismos resultados que 10.47:** 221 frases del corpus de `CommercialTurnPolicyPanoramaTest`, normalizadas, contra 10 patrones dan una salida idéntica en las dos versiones (`diff` de 0 líneas sobre 5 930).
- **Construcciones usadas:** `\p{L}` y `\p{N}` (categorías generales), lookbehind de longitud fija, lookahead, `\b`, `\W` y `\s` con UCP. Ninguna es posterior a 10.39.
- **Sin verificar: el JIT de 10.39.** El JIT de mi compilación no se ejecuta en macOS arm64 (no aparece la anotación «(JIT)»). Si `pcre2_jit_compile` fallara, PHP emitiría un aviso y Laravel lo convertiría en `ErrorException`. Queda como paso posterior al despliegue (§3).

### NB-1 · NO BLOQUEANTE (endurecimiento; teórico): los vetos secundarios fallan hacia «sí es la lista»
- **Dónde:**
  - `NO_PIDE_EL_PANORAMA` con `!== 1` (`CommercialTurnPolicy.php:1155`);
  - `OTRO_TEMA_DEL_PANORAMA`, ampliada en este diff, con `=== 1` (`:1169`);
  - en el código nuevo: `preg_split(...) ?: []` (`:1189`) y los `continue` ante un `false` (`:1191-1192`);
  - con el mismo patrón ya existían `FILTRO_DE_PLANES`, `PREGUNTA_INCIDENTAL`, `HABLA_DE_VISITA` y `peticionDeHumanoEn()`.
- **Prueba [C]** (`barrido-limite.php`, sin JIT): solo forzando `pcre.backtrack_limit` a 42–59, «¿Qué planes tienen? Tengo asma» y «¿Qué planes tienen? Llámenme» reciben la lista, porque `PIDE` acierta y `OTRO_TEMA` devuelve `false`.
- **Por qué no se alcanza en producción:**
  - Esas redes necesitan como mucho 73 por posición de inicio, sea cual sea la longitud (`consumo.php`, a 4 096 y a 65 000). Producción tiene 1 000 000.
  - Con JIT, esas redes no tienen grupos repetidos y no pueden agotar la pila: el barrido con JIT dio 0 fugas.
- **Impacto si ocurriera:** la lista taparía otra pregunta, que es el problema C-1. No expone datos ni deja a nadie mudo.
- **Arreglo opcional:**
  - en los vetos, tratar `false` como veto (`preg_match(...) !== 0`);
  - `preg_split === false` → devolver `true`;
  - hacer `continue` solo con `0`.

---

## Pregunta 2 · El cupo propio de `ai/check`

### 2.1 La puerta y las rutas [C]
- **`route:list -v --path=internal/marketing` (17 rutas):**
  - `ai/check` lleva `api`, `automation.internal` y `throttle:internal-marketing-ai-check`;
  - `ai/decide`, `ai/commit` y el resto del grupo siguen con `throttle:internal-marketing-ai`.
- **Lista completa de rutas, base contra punta.** Saqué `f1fe6cac` y `2f37a2cb` con `git archive` al scratchpad, sin `.env`, y comparé las 1 046 rutas de cada una: **cambia exactamente 1 línea**, el limitador de `ai/check`. URI, método, controlador y el resto del middleware son iguales.
- **Sin secreto, 401.** `InternalSecretAuthoritySurfaceTest::test_the_third_ultron_door_only_reads` pasa.

### 2.2 ¿Amplificación? No real [C]
- **Coste de un `ai/check`** (`SondaCosteCheckTest`, reutilizando la preparación de `UltronCheckEndpointTest`):
  - con token válido: **6 SELECT** (sobre `plans`, `marketing_messages` y `marketing_conversations`), **0 escrituras**, **0 HTTP** (`Http::assertNothingSent`) y unos 23 ms en sqlite;
  - con token inválido: **0 consultas** y 422, porque el token se verifica antes de leer nada (`UltronDraftCheck.php:117-120`).
- **Ningún servicio de pago [L].** No hay `Http::`, OpenAI ni colas en `UltronDraftCheck`, `UltronTurnPolicyReader`, `UltronDraftDefects`, `UltronDraftPlaceholders` ni `UltronDecideToken`. `UltronCheckEndpointTest:630-650` ya lo fija: solo `select`, sin HTTP, sin colas, sin filas y sin cerrojo.
- **Conclusión:** pasar de 120 a 120 + 60 por minuto supone como mucho unas 360 SELECT/min más, y solo para quien tiene el secreto. Quien tiene el secreto ya podía gastar más llamando a OpenAI desde n8n (R-3).

### 2.3 Fallo seguro con un 429 en la comprobación
- **El flujo de n8n [L]** (`n8n/flujos/ultron-studio-s2/flujo-s2.json`):
  - `Comprobar Borrador 1/2` llevan `neverError: true`, `fullResponse: true`, `timeout: 3000` y `onError: continueRegularOutput`;
  - `Leer Comprobación 1/2` hace `statusCode !== 200` → `noReescribir()`, dentro de un `try/catch`, y devuelve el objeto del redactor con `ultron_check.rewrite = false`;
  - `¿Reescribir?` toma la rama falsa → `Critic Comercial` → el camino de hoy.
- **El `commit` no depende de la comprobación [C].**
  - La comprobación no escribe nada: 0 escrituras en mi sonda, y la prueba de solo lectura ya existente lo confirma. El `commit` no lee su resultado.
  - Para el `commit`, un turno con el `ai/check` en 429 es igual que un turno sin comprobación. Aplica sus propios controles: registro, longitud, `UltronPlansOverview::verify()`, atribución de beneficios y violaciones de la política. Recorta, descarta o pone el respaldo curado.
  - `UltronCheckCommitParityTest` pasa: cada código de la comprobación se corresponde con una actuación del `commit` por la misma causa, con 11 excepciones documentadas en las que lo peor es perder una reescritura.
  - **Nada que la comprobación habría parado puede salir:** la comprobación nunca para nada, el que para es el `commit`.
- **Un detalle de despliegue [L, `ThrottleRequests.php:204-208`].** Si la caché de rutas nueva convive con el proveedor viejo en opcache, el limitador con nombre no existe y Laravel lanza `MissingRateLimiterException`: un 500 solo en `ai/check`. n8n lo trata como «no reescribir», así que es seguro, pero hay que recargar FPM en el mismo paso.

### 2.4 Las pruebas `test_r2_*` [C]
Pasan las tres, dentro de la ejecución única de **429 tests y 2 863 aserciones en verde**: `LimitadoresInternos`, `InternalSecretAuthoritySurface`, `UltronCheckCommitParity`, `UltronCheckEndpoint`, `CommercialTurnPolicyPanorama` y `UltronA4RegistroYPanorama`. No volví a ejecutar el rojo con el código de antes: esa evidencia es del lead.
- **`test_r2_las_comprobaciones_no_gastan_el_cupo_de_decide_ni_del_commit`** es la prueba negativa que pedía R-2. Agota `check` (8 peticiones con tope 3) y comprueba que `decide` y `commit` responden lo mismo que sin inundar.
  - Usa un cuerpo que el controlador rechaza (422) en vez de un `commit` real con 200. Para el limitador vale: afirma que no hay 404 y que la petición llega al controlador.
- **`test_r2_decide_y_commit_no_gastan_el_cupo_de_la_comprobacion`** cubre la dirección contraria.
- **`test_r2_la_comprobacion_tiene_su_propio_tope`** comprueba que de 8 se rechazan exactamente 5.
- **Hueco:** ninguna prueba usa peticiones **sin secreto** (ver NB-2).

### NB-2 · NO BLOQUEANTE: el cubo nuevo se puede vaciar sin el secreto
- **Qué pasa [C].** La prioridad de middleware de Laravel adelanta `ThrottleRequests` por delante de `SubstituteBindings`, que viene del grupo `api`. Así queda por delante de `automation.internal`. Orden efectivo medido: `ThrottleRequests → SubstituteBindings → ProtectAdminPaths → EnforceAdminAuthorization → EnforceTrainerMemberScope → VerifyInternalAutomationSignature`.
- **Prueba (`SondaOrdenPuertaTest`, tope 3):** 5 POST a `ai/check` sin secreto dan `401, 401, 401, 429, 429`; después, con el secreto válido, **429**.
- **Explotación.** n8n llama al host público `https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check` [L]. Que nginx filtre `/api/internal` por IP no está en el repo [no verificado]. Si no lo filtra, cualquiera que envíe 60 o más POST por minuto sin credenciales deja en 429 todas las comprobaciones del Studio. Se pierde «regenerar antes de sustituir» y el `commit` decide solo, con más respaldos. No sale nada indebido y nadie se queda mudo.
- **El diff mejora este camino.** Antes, la misma inundación de `ai/check` vaciaba el cubo común y dejaba a `decide` y al `commit` en 429, es decir, mudo. Después, **[C] `decide` y `commit` responden 422 (no 429)** tras 8 `check` sin secreto.
- **Arreglo (backend, acotado).** En `limitadoresInternos()`, que solo cuente en el cubo global la petición cuyo `Bearer` pasa `hash_equals`. El resto, a un cubo por IP (`internal-unauth:{ip}`) con un tope bajo. Prueba que falta: inundar sin secreto y comprobar que n8n, con el secreto, no recibe 429.

### NB-3 · NO BLOQUEANTE (baja): el 401 enseña el contador del cubo global
- **Qué pasa [C].** Una petición sin secreto recibe 401 con `X-RateLimit-Limit: 60` y `X-RateLimit-Remaining: 55`, después de 4 comprobaciones autenticadas.
- **Impacto:** quien no tiene credenciales puede ver cuántas comprobaciones hace el Studio por minuto. Es el mismo patrón que ya tiene el cubo común. El arreglo de NB-2 lo resuelve.

*Nota de documentación (no es hallazgo):* el docblock de `InternalSecretAuthoritySurfaceTest.php:170-171` sigue diciendo «el mismo grupo… y el mismo limitador» para `ai/check`.

---

## P-1 · FUERA DEL DIFF, PREEXISTENTE: alta si `/api/internal` es accesible desde internet. No cuenta para PT-15; derivar a `api-security-specialist`/`backend`
- **El mismo mecanismo de NB-2 afecta al cubo común `internal-marketing-ai`** (y a `internal-automation`). 120 POST por minuto **sin secreto** a cualquier ruta del grupo, por ejemplo `ai/commit` con un cuerpo vacío, dejan en 429 a `decide` y al `commit` de n8n: los turnos de WhatsApp se quedan mudos mientras dure el ataque.
- **Prueba [C]:** `ai/commit` sin secreto da `401, 401, 401, 429, 429`; luego, con el secreto, 429.
- **Ya está en producción (`f1fe6cac`):** mismo grupo, mismo orden de middleware.
- **Contradice dos supuestos:**
  - el comentario de `AppServiceProvider.php:882-885` («las dos puertas ya están autenticadas con el secreto compartido») da por hecho lo contrario;
  - mi R-2 solo contemplaba «un n8n que se equivoca o el crecimiento normal».
- **Consecuencia para PT-15.** La condición 2 cierra R-2 tal como se formuló, pero el objetivo de R-2 (que el `commit` nunca reciba un 429 por tráfico ajeno) no se cumple frente a un actor externo. Recomiendo que el lead lo trate como condición antes de ampliar más allá de la canaria. El arreglo es el mismo de NB-2, aplicado a los tres limitadores internos.

---

## 3 · Pasos posteriores al despliegue (no los puedo hacer: sin acceso a producción)
1. **Sonda de PCRE en el servidor.** Ejecutar `docs/ultron-studio/ops/comprobacion-produccion/sonda-registro-y-panorama.php` con las clases del commit. Compila por reflexión todas las constantes y ejecuta `pideElPanorama()` sobre su corpus, lo que ejercita las 3 expresiones en línea, recogiendo los avisos.
   - Esperado: «no compilan: 0», «avisos de PHP: 0» y la misma HUELLA que en local.
   - Cubre el JIT de 10.39 en x86_64, que no pude verificar.
2. **Cachés y FPM.** `config:cache` + `route:cache` y **recarga de PHP-FPM en el mismo paso**. Después, `route:list -v --path=internal/marketing` debe mostrar `ai/check` con `throttle:internal-marketing-ai-check`, y un `ai/check` del canario no debe dar 500.
3. **Exposición en nginx.** Comprobar si nginx restringe `/api/internal/*` por origen. Si no lo hace, P-1 y NB-2 son explotables desde internet.

## Lo que NO pude verificar
- La compilación JIT en PCRE 10.39 (mi compilación no ejecuta JIT en arm64) y el comportamiento de la pila JIT en el x86_64 de producción.
- La configuración real de nginx, `pcre.jit` y `pcre.backtrack_limit` en producción (supuestos: los valores por defecto).
- El almacén de caché del limitador en producción.
- Un turno de extremo a extremo con un modelo real y la comprobación en 429. Lo cubren por partes la lectura del flujo, la prueba de solo lectura y la de paridad.
- El rojo de `test_r2_*` con el código de antes (lo dejo en la evidencia del lead).
