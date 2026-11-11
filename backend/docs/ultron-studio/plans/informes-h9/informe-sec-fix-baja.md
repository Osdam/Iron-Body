# Informe SEC-FIX-BAJA · SC-1, SC-2 y SC-10: bajas y consentimiento (cierre de ULTRON STUDIO, Hito 9)

**Agente:** `backend` (sec-fix-baja). **Encargo:** corregir SC-1, SC-2 y SC-10 de `informe-sec-cierre.md` (filas L9-80 y L9-81 del contrato). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` (HEAD `d701b0c8`), **sin commit**, con mucho trabajo sin confirmar de otros agentes que no he tocado. **Fecha:** 10-11-2026. **Sin red real, sin producción, sin despliegue, sin secretos impresos, sin ULTRON Legacy.** Las dos constantes de código siguen en `false` (`SENDS_ENABLED_IN_CODE`, `CALLS_ENABLED_IN_CODE`): ninguna prueba las gira.

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros de código tocados | 5, todos de mi lista: `SubjectSuppressions` (+40/−12), `WhatsappSignals` (+274/−37), `WhatsappTemplateChannel` (+11/−6), `WhatsappTemplateOutcomes` (+17/−6), `WhatsappOutboxService` (+97/−0, solo la rama de plantillas) |
| Pruebas nuevas | 13 en 3 ficheros de `tests/Feature/Outreach/W9/` (1 336 aserciones) |
| Códigos de detalle nuevos | **0** (reutilizo `opt_out_word`, D-4); motivos del outbox: los 3 que ya devuelve la puerta |
| Migraciones, rutas, permisos, variables de entorno, configuración | **0** |
| Guardas que caen por este trabajo | **0** (ninguna enmienda que pedir) |
| `tests/Feature/Outreach` completo | SQLite **613/613** (39 496 aserciones, 163 omitidas) · PG 14 **613/613** (40 608 aserciones, 28 omitidas) |
| W9 + Run (con las mías) en PG 14 | **119/119** (2 990 aserciones, 1 omitida: solo SQLite) |
| `OutreachIsolationTest` + `UltronEngineIsolationTest` | **14/14** (2 500 aserciones) |
| Regresión dirigida de Marketing/Legacy | **339/339** (1 718 aserciones) |
| Mutaciones (en un clon APFS, fuera del árbol) | **9/9 cazadas** |
| Pint | mis 7 ficheros: `passed`; el outbox ya fallaba en HEAD con los mismos fijadores (§6) |

## 1. Qué estaba mal (resumen de SC-1, SC-2 y SC-10)

- **SC-1.** `SubjectSuppressions::isSuppressed()` (antes `:40`) suprimía un `opted_out` solo si coincidían la finalidad **y el medio** del trabajo. Quien contestaba «No me contacten más» a una plantilla comercial de WhatsApp recibía la llamada de otra estrategia comercial de voz (N2 no veía supresión para `voice`; el canal de voz no mira las señales de WhatsApp).
- **SC-2.** La palabra de baja era el mensaje ENTERO de una lista de 27 frases, con una normalización que no quitaba la tilde combinada (NFD), el ancho completo ni los invisibles. Bajas naturales («No me vuelvan a escribir», «no me llamen», «Sáquenme de su lista»…) quedaban como `replied`, y el docblock decía «equivocarse hacia la baja es el lado seguro», al revés de lo que hacía.
- **SC-10.** Entre el primer intento fallido de una plantilla del H9 y sus reintentos (30 s, 60 s, 120 s…), el outbox no volvía a mirar `canContactProactively()` ni la baja del número.

## 2. Qué cambié y por qué (por fichero), con el diff

### 2.1 `app/Services/Outreach/Run/SubjectSuppressions.php` (SC-1, y SC-2 en el libro) · +40/−12

Las reglas, en el orden en que se evalúan (docblock reescrito para que diga exactamente esto):

1. `wrong_person` por el medio M → suprimida para (cualquier finalidad, M). **Sin cambios.**
2. `opted_out` de fuente `inbound_message` (W9) o `voice_call` (la herramienta `no_volver_a_llamar` del H8, que normaliza V9) en una inscripción de la finalidad F → suprimida para F en **TODOS los medios** (SC-1).
3. Una fila del libro con `detail_code = opt_out_word` en una inscripción de F → suprimida para F en todos los medios (consecuencia de SC-2; D-2): la escribe el normalizador (posible baja, §2.4) o el despachador cuando el canal de WhatsApp no envió por una palabra de baja (`failed` de `system`, §2.3).
4. `opted_out` de otra fuente (`staff`, `classifier`) → por finalidad **y** medio, como hasta ahora (D-1).

Nada cruza finalidades (D-5). Una consulta, como antes; el índice `oout_subject_idx` (`subject_key`, …) sigue acotándola.

```diff
+    /** SC-1 · las fuentes cuyo `opted_out` alcanza TODOS los medios de su finalidad: la persona lo dijo con sus palabras. */
+    public const PURPOSE_WIDE_OPT_OUT_SOURCES = ['inbound_message', 'voice_call'];
+
+    /** SC-2 · el detalle con el que la ejecución deja en el libro una palabra de baja (segura o posible) vista por WhatsApp. */
+    public const OPT_OUT_WORD_DETAIL = 'opt_out_word';
@@ isSuppressed()
-            ->whereIn('o.outcome', self::SUPPRESSING_OUTCOMES)
+            ->where(static fn ($q) => $q->whereIn('o.outcome', self::SUPPRESSING_OUTCOMES)->orWhere('o.detail_code', self::OPT_OUT_WORD_DETAIL))
             ->limit(200)
-            ->get(['o.outcome', 'e.purpose', 'j.channel', 'j.medium']);
+            ->get(['o.outcome', 'o.source', 'o.detail_code', 'e.purpose', 'j.channel', 'j.medium']);
         foreach ($filas as $f) {
             $medio = self::mediumOfJob($f->channel, $f->medium);
-            if ((string) $f->outcome === 'wrong_person' && ($medio === null || $medio === $medium)) {
+            $suMedio = $medio === null || $medio === $medium;
+            if ((string) $f->outcome === 'wrong_person' && $suMedio) {
                 return true;
             }
-            if ((string) $f->outcome === 'opted_out' && (string) $f->purpose === $purpose && ($medio === null || $medio === $medium)) {
+            if ((string) $f->purpose !== $purpose) {
+                continue;
+            }
+            if ((string) $f->detail_code === self::OPT_OUT_WORD_DETAIL) {
                 return true;
             }
+            if ((string) $f->outcome === 'opted_out' && ($suMedio || in_array((string) $f->source, self::PURPOSE_WIDE_OPT_OUT_SOURCES, true))) {
+                return true;
+            }
         }
```

`LadderInputBuilder.php:98` no cambia: sigue llamando a `isSuppressed(…, $live->purpose, $medio)`, en el planificador y otra vez en el despachador (TOCTOU).

### 2.2 `app/Services/Outreach/Facts/WhatsappSignals.php` (SC-2) · +274/−37

- **Normalización** (`fold()`, `forms()`): la de `VoiceTranscriptLexicon::fold()` con tablas propias y cerradas, **sin `ext-intl`** (producción no la tiene; lo dicen `ReceiptTextLayoutTest` y el propio léxico de la voz): el subconjunto latino de NFKC (ancho completo U+FF01–U+FF5E y U+3000, alfanuméricos matemáticos, letras en círculo, ligaduras), minúsculas, fuera `\p{M}` (la tilde suelta de NFD) y los invisibles (`\p{Cf}` + rellenos coreanos), las letras latinas con diacrítico a su base, signos y emojis fuera, un espacio entre palabras y sin cortesías alrededor (como antes). Se busca en todas las formas: invisibles quitados, invisibles como espacio («no»+U+200B+«me»+U+200B+«escriban») y letras sueltas unidas («B A J A», «b.a.j.a»). No pliega homoglifos (quien pide la baja no se esconde). Cada `preg_*` que falla lanza `UnexpectedValueException` (antes `(string) preg_replace(…)` devolvía `''`: fallaba abierto).
- **Baja SEGURA** (`isOptOutText()`, `everOptedOut()`): la misma lista cerrada de 27 frases, el mensaje entero, ahora sobre la forma normalizada. Es lo único que el normalizador registra solo como `opted_out`.
- **POSIBLE baja** (nuevo `isPossibleOptOutText()`, `everPossiblyOptedOut()`): la segura o un mensaje que CONTIENE alguna de las 16 expresiones de `POSSIBLE_OPT_OUT` (conservador, por contención): los patrones del informe tal cual (`\bbaja\b`, `stop`, `unsubscribe`, `no me (vuelvan a |…)?(escrib|contact|llam)…`, `no (quiero|deseo) (recibir|seguir recibiendo|mas)`, `dejen de`, `saquenme|quitenme…`, `(elimin|borr)(en|ar) mis?`, `detener|detengan`, `desuscrib|desinscrib|desafili…`, `cancelar (la |mi )?suscripcion`) y variantes evidentes más estrechas para no marcar frases normales (`no me (molesten|…)`, `no (me )?(envien|manden|…)` solo en imperativo, `no mas (mensajes|whatsapp|publicidad|promociones|llamadas)`, `deja de (escrib|llam|…)`, `me (saquen|quiten|borren|eliminen) de`). Lo que no se puede leer (UTF-8 inválido, PCRE que falla) **es** una posible baja; nunca una segura. Vacío, solo emojis o solo «gracias» no lo es.
- `inboundBetween()` devuelve además `possibleOptOut` por entrante (el texto sigue sin salir de aquí). Los dos barridos comparten una consulta privada (`lastInboundBodies()`): el canal sigue haciendo **una** lectura de ≤ 200 entrantes, como antes.
- El docblock de la clase dice ahora la verdad: los dos niveles, el lado al que se equivoca y lo que deja pasar a sabiendas (§5).

```diff
-        foreach ($q->orderBy('m.created_at')->orderBy('m.id')->limit(max(1, $limit))->get(['m.id', 'm.created_at', 'm.body']) as $f) {
-            ...
-                $out[] = ['id' => (int) $f->id, 'at' => $en, 'optOut' => self::isOptOutText(is_string($f->body) ? $f->body : null)];
+                $cuerpo = is_string($f->body) ? $f->body : null;
+                $out[] = ['id' => (int) $f->id, 'at' => $en, 'optOut' => self::isOptOutText($cuerpo), 'possibleOptOut' => self::isPossibleOptOutText($cuerpo)];
@@
-    /** Minúsculas, sin tildes, sin signos ni emojis, espacios simples y sin cortesías alrededor. PURA. */
-    public static function normalize(?string $text): string
-    {
-        ...
-        $t = mb_strtolower($text, 'UTF-8');
-        $t = strtr($t, ['á' => 'a', 'é' => 'e', … 'ñ' => 'n', …]);
-        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
+    public function everPossiblyOptedOut(array $leadIds): bool          // nuevo (SC-2)
+    public static function isPossibleOptOutText(?string $text): bool   // nuevo (SC-2), conservador
+    public static function normalize(?string $text): string            // la primera de forms()
+    private static function forms(string $text): array                 // invisibles quitados / como espacio / letras unidas
+    private static function fold(string $text): string                 // NFKC latino + \p{M} + \p{Cf} + diacríticos + cortesías
+    private function lastInboundBodies(array $leadIds): Collection     // la consulta de siempre (≤ 200), compartida
+    private static function matches()/replace()/replaceCallback()      // PCRE que falla → excepción (falla cerrado)
```

### 2.3 `app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php` (SC-2) · +11/−6

La comprobación 7 mira la posible baja (que incluye la segura) con el **mismo código cerrado** `opt_out_word` (D-4): ante una posible baja, el canal no envía. El despachador ya lo asienta como `failed` de `system` con ese detalle, y §2.1 regla 3 lo lee como baja de la finalidad. Docblock del punto 7 actualizado.

```diff
-        // 7 · Una baja o una respuesta que el normalizador todavía no registró.
+        // 7 · Una baja (segura o POSIBLE, SC-2) o una respuesta que el normalizador todavía no registró.
         $leads = $this->recipients->leadIdsOf($clave);
-        if ($this->signals->everOptedOut($leads)) {
+        if ($this->signals->everPossiblyOptedOut($leads)) {
             return ChannelAttempt::failed('opt_out_word');
         }
```

### 2.4 `app/Services/Outreach/Run/WhatsappTemplateOutcomes.php` (SC-2) · +17/−6

Lo exige el código de detalle: una respuesta que es una posible baja (y no segura) deja de ser `replied` y pasa a `human_requested` (fuente `inbound_message`, que la admite) con el detalle `opt_out_word`. `human_requested` tiene una única transición, `handoff_human`: una persona la revisa **aunque la estrategia mande `replied` → `end_exit`** (que antes la cerraba sin que nadie la viera). La baja segura sigue siendo `opted_out` y gana si las dos aparecen. Clave de idempotencia nueva `job:<uuid>:possible_opt_out` (no colisiona con `replied` ni con `opted_out`; si después llega una baja segura, se registra igual). Docblock actualizado.

```diff
         $baja = null;
+        $posible = null;
         foreach ($entrantes as $m) {
             if ($m['optOut']) {
                 $baja = $m;
                 break;
             }
+            $posible ??= $m['possibleOptOut'] ? $m : null;
         }
         if ($baja !== null) {
             $registrar('opted_out', 'inbound_message', 'opted_out', $baja['at']);
+        } elseif ($posible !== null) {
+            // SC-2: una POSIBLE baja no es una baja segura ni una respuesta cualquiera: una persona la revisa.
+            $registrar('human_requested', 'inbound_message', 'possible_opt_out', $posible['at'], SubjectSuppressions::OPT_OUT_WORD_DETAIL);
         } elseif ($entrantes !== []) {
```

### 2.5 `app/Services/Marketing/WhatsappOutboxService.php`, rama de plantillas (SC-10) · +97/−0

Puramente aditivo; ninguna línea existente cambia. En `deliver()`, después de la toma humana y de la pausa (que no cambian) y **antes** de contar el intento, `outreachTemplateRefusal()`:

- solo si `metadata.kind = outreach_template` **y** `send_attempts ≥ 1` (un reintento; el primer intento acaba de pasar las guardas de la puerta, D-6). Para todo lo demás devuelve `null` sin una consulta (`isTemplate()` es pura);
- las guardas de la persona de la puerta, en su orden: sin prospecto → `no_proactive_consent` (no consta); `canReplyReactively()` → `do_not_contact`; `canContactProactively()` → `no_proactive_consent`; una hermana con `do_not_contact` y el mismo número **normalizado** (la regla de `MarketingMessageDispatcher::phoneOptedOut()`, con su `normalizePhone()` público) → `do_not_contact_sibling`;
- si ya no se puede: `dead`, `last_error_code = null` (como la pausa: no lo paró Meta), `last_error_message` = el código, `metadata.failure` con código, título y mensaje para el Inbox (`OUTREACH_TEMPLATE_REFUSALS`), `next_attempt_at = null`, `send_attempts` sin tocar, `ChannelLog::info('outbox.cancelled', {reason, message_id, conversation_id})` sin datos personales, y `['sent' => false, 'reason' => <código>, 'retryable' => false]`.

```diff
+use App\Models\MarketingLead;
@@
+    public const OUTREACH_TEMPLATE_REFUSALS = [ 'do_not_contact' => [...], 'no_proactive_consent' => [...], 'do_not_contact_sibling' => [...] ];
@@ deliver(), tras la pausa (línea 176)
+        $refusal = $this->outreachTemplateRefusal($message, $to);
+        if ($refusal !== null) {
+            $message->forceFill(['status' => self::STATUS_DEAD, 'last_error_code' => null, 'last_error_message' => $refusal,
+                'next_attempt_at' => null, 'metadata' => array_merge((array) $message->metadata, ['failure' => ['code' => $refusal] + self::OUTREACH_TEMPLATE_REFUSALS[$refusal]])])->save();
+            ChannelLog::info('outbox.cancelled', ['reason' => $refusal, 'message_id' => $message->id, 'conversation_id' => $message->conversation_id]);
+            return ['sent' => false, 'provider_message_id' => null, 'reason' => $refusal, 'retryable' => false];
+        }
@@ (línea 479)
+    private function outreachTemplateRefusal(MarketingMessage $message, string $to): ?string { … }
```

El H9 lo lee después como siempre: trabajo `started`, mensaje `dead` sin aceptación de Meta → `failed` (`meta_status`) con `outbox_dead` (R-3).

### 2.6 Pruebas nuevas (`tests/Feature/Outreach/W9/`)

| Fichero | Pruebas | Qué demuestra |
|---|---|---|
| `OptOutAcrossMediaTest.php` (W9World + V9World) | 3 | **SC-1 de punta a punta**: T0 (3-11, 10:00 Bogotá) planificador → despachador → canal → puerta de Marketing → outbox → Graph falso; P contesta «No me contacten más» por `MetaConversationService::recordInbound` y el normalizador registra `opted_out` (`inbound_message`). T1 (17-11, dos semanas después: fuera de las reglas de 7 días de N1) una estrategia comercial de VOZ publicada: la puerta inscribe a P y a Q; **P se niega en N2 con `['global_suppression']`** (inscripción `exited`, 0 trabajos); **Q** (igual en todo salvo la baja) se planifica y en el despacho es la única que llega al canal de voz REAL (un espía que lo envuelve: `OutboundGate` es `final`), que se para en la constante cerrada (`sends_disabled_in_code`); 0 `voice_calls`, 0 `PlaceOutboundVoiceCall`. Un trabajo de voz de P ya reclamado: el despachador lo cancela (`global_suppression`, decisión N2, reserva liberada, inscripción fuera con `blocked`). La tabla de reglas por la única ingesta (`OutcomeRecorder`): `inbound_message` y `voice_call` → todos los medios de su finalidad, ninguna otra finalidad; `opt_out_word` (`failed`/`system` y `human_requested`/`inbound_message`) → igual; `failed` de Meta, `replied` y `human_requested` sin detalle → nada; `staff` → por medio (sin cambios); `wrong_person` → por medio, cualquier finalidad |
| `OptOutWordHardeningTest.php` | 7 | **SC-2**: las 13 cadenas del informe (9 naturales, la tilde NFD en sus dos ejemplos, ancho completo, anchura cero) → el canal devuelve `failed_to_start` con `opt_out_word` (≤ 48) y **no llama a la puerta** (un doble de `MarketingMessageDispatcher` con `shouldNotReceive`), 0 salientes, 0 red; control: sin la frase, el mismo canal con la puerta real envía. «¿qué es la baja?» → `human_requested` + `opt_out_word` + `handoff_human` + `handed_off` con la estrategia en `replied` → `end_exit`, mientras una respuesta normal de la misma estrategia sí sale con `end_exit`; la persona queda suprimida en la finalidad (WhatsApp y voz), no en otra. NFD y ancho completo → `opted_out`. 24 frases normales de cliente → ni segura ni posible. UTF-8 inválido → posible, nunca segura. La forma normalizada = NFKD (sin marcas, minúsculas) en todo lo que mapea (> 900 caracteres comparados con `Normalizer`/`IntlChar`, solo donde está `ext-intl`) |
| `OutboxTemplateRetryRecheckTest.php` | 3 | **SC-10**: reintento de plantilla con la autorización en `pending` → `no_proactive_consent`; `denied` → `do_not_contact`; `do_not_contact` → `do_not_contact`; una hermana con el mismo número en otro formato («+57 300 111 2233») → `do_not_contact_sibling`: en los cuatro, `dead`, sin intento, motivo en `last_error_message` y `metadata.failure`, 0 red; control: igual que al nacer, sale como plantilla. De punta a punta por `marketing:retry-outbox`: 429 → trabajo `started` con su reintento → la persona marca «no contactar» → el comando no reenvía (1 petición a Meta en total) → el normalizador del H9 registra `failed` `outbox_dead`. El texto libre de Legacy: **0 consultas a `marketing_leads`** en su reintento (no pasa por la rama nueva) |

Las tres llaman a `Http::preventStrayRequests()`; la cola, falsa en la de voz.

## 3. Contratos nuevos o modificados

Ninguna ruta, permiso, migración, tabla, CHECK, variable ni configuración. Ningún consumidor HTTP cambia. Lo que cambia es semántica interna, que **el lead debe reflejar en D9-10 y D9-15** (el contrato no lo he tocado):

| Contrato | Antes | Ahora |
|---|---|---|
| Supresión de un `opted_out` de `inbound_message` o `voice_call` | (finalidad, medio) | (finalidad, **todos los medios**) |
| Fila del libro con `detail_code = opt_out_word` | no suprimía | (finalidad, todos los medios) |
| Respuesta por WhatsApp que es una POSIBLE baja | `replied` (la estrategia podía cerrarla con `end_exit`) | `human_requested` + `opt_out_word` → `handoff_human` siempre |
| Respuesta que es una baja SEGURA | `opted_out` (lista de 27, normalización débil) | `opted_out` (la misma lista, normalización NFKC/NFD/invisibles) |
| Canal de WhatsApp, comprobación 7 | `opt_out_word` solo con la baja segura | `opt_out_word` con la segura o la posible |
| Reintento de una plantilla en el outbox | sin recomprobar a la persona | `do_not_contact` / `no_proactive_consent` / `do_not_contact_sibling` → `dead` con su motivo |
| API PHP de `WhatsappSignals` | `isOptOutText`, `everOptedOut`, `normalize`, `inboundBetween` (`optOut`) | las mismas (compatibles) + `isPossibleOptOutText`, `everPossiblyOptedOut`, `inboundBetween` con `possibleOptOut` |

## 4. Decisiones (las tomé dentro del encargo; el lead las confirma o las revierte)

- **D-1 · `staff` y `classifier` siguen por medio.** El encargo nombra `inbound_message` y `voice_call`; `OutreachOutcomesTest::test_10` fija `staff` por medio (`:143`). D9-15 («toda señal negativa de cualquier fuente gana») apunta a ampliar también `staff`: si el lead lo quiere, es añadir `'staff'` a `PURPOSE_WIDE_OPT_OUT_SOURCES` y cambiar esa aserción de `test_10` (no es mía).
- **D-2 · La posible baja también suprime la finalidad en el libro.** Sin esto, «no me llamen» (una de las 12 frases, posible y no segura) dejaba pasar la llamada de otra estrategia comercial: el mismo agujero de SC-1. Revertir es quitar la regla 3 de §2.1 (un `if`); las pruebas de §2.6 caen donde deben.
- **D-3 · No hay «descartar».** El libro es de solo inserción y no tiene un resultado para «revisado: no era una baja». Hoy una posible baja: (a) para el canal de WhatsApp para siempre (barrido de ≤ 200 entrantes) y (b) suprime la finalidad en el H9. Confirmarla es marcar «no contactar» (existe); descartarla no existe. Un falso positivo cuesta, por tanto, que el H9 no vuelva a contactar a esa persona para esa finalidad (la conversación sigue abierta en el Inbox para una persona). Propuesta para el propietario con `architect`: un resultado del personal o un consentimiento renovado posterior que la levante (cambia D9-10 y su CHECK).
- **D-4 · Sin código nuevo: `opt_out_word`.** El encargo admitía «el que ya exista o uno nuevo `possible_opt_out`». Uno nuevo exigía su frase en `DecisionTraceText` (de b9-cierre) y en el espejo del CRM, o dejaba roja `DetailCodeTextsTest`; con el existente, su frase («La persona escribió una palabra de baja por WhatsApp: no se le escribe más.») es literalmente cierta con la semántica de D-2. La diferencia segura/posible queda en el libro: `opted_out` frente a `human_requested`. Si se prefiere la precisión, es cambiar dos literales (canal y normalizador), la constante de §2.1 y añadir la frase.
- **D-5 · Una baja comercial no alcanza `service`.** Lo decide el propietario con su asesoría legal (Ley 1581: revocación por finalidad; Ley 2300 art. 5 par. 2). El código está preparado para cambiarlo en un sitio (`if ((string) $f->purpose !== $purpose) continue;`).
- **D-6 · SC-10 solo en reintentos y solo en plantillas.** El primer intento lo hace la puerta en la misma petición, justo después de comprobar lo mismo; repetirlo sumaba consultas dentro de la puerta sin ganar nada. Sin prospecto → `no_proactive_consent` (no se puede afirmar la autorización).
- **D-7 · Los patrones son una heurística conservadora, fijada por sus fixtures** (las 13 cadenas del informe, 24 frases normales, las formas Unicode). Falsos positivos conocidos y aceptados: «presión baja», «clase de baja intensidad», «stop motion», «no me llamaron», «dejen de cobrarme», «quiero darme de baja de spinning». Bajas reales que NO ve: lo que la lista no nombra (p. ej. «los voy a bloquear», «no quiero saber nada más de ustedes», «no me interesa»: esta última es `not_interested` por diseño) y lo que no es texto (R-5).

## 5. Lo que queda abierto (fuera de mis ficheros; para que el lead lo enrute)

- **R-1 · Una baja escrita por WhatsApp que el H9 nunca vio sigue sin alcanzar la voz.** La supresión lee el libro; el libro solo recibe la baja como respuesta a una plantilla del H9 (normalizador) o cuando el canal de WhatsApp intenta enviar (D-2). Una persona que escribió «BAJA» en una conversación de Legacy y a la que solo la apunta una estrategia de VOZ no está suprimida (salvo que Legacy marcara «no contactar»). Cerrarlo exige que el canal de voz o la entrada de la escalera lean `WhatsappSignals` (ficheros de V9 y b9-cierre), o un `opted_out` sin trabajo, que `oout_links_chk` prohíbe para `inbound_message` (enmienda de D9-10). **Mientras tanto, la recomendación de SC-1 sigue en pie para ese caso: no abrir la voz por estrategia a personas con historial de WhatsApp sin revisar esto.**
- **R-2 · SC-10 no mira la PARADA ni los frenos del H9.** El informe de sec-cierre la menciona; el encargo no. Exigiría que Marketing lea el freno del H9 (dirección de dependencia nueva). Con la PARADA echada, un reintento de plantilla ya aceptada aún puede salir en ≤ ~4 min.
- **R-3 · El libro ve `outbox_dead`, no el motivo.** El normalizador lee `last_error_code` (nulo, como en la pausa); el motivo queda en el mensaje (`last_error_message`, `metadata.failure`). Llevarlo al libro es una línea en `WhatsappSignals::outboundOf()` y otra en el normalizador; no lo hice (cambia también lo que hoy ven la pausa y la toma humana).
- **R-4 · El monitor de bajas** (`OutreachMonitor::checkOptOutRates`, de b9-cierre) cuenta solo `opted_out`: las posibles bajas no suben la tasa.
- **R-5 · Lo que no es texto.** Una nota de voz con «no me escriban» no la ve el H9 (Legacy la transcribe en los adjuntos; el H9 no lee transcripciones).
- **R-6 · Reintento a un número viejo** (preexistente, también en Legacy): el outbox reenvía a `metadata.recipient`; si el prospecto cambió de teléfono entre intentos, no se comprueba.
- **R-7 · Observación para el dueño de la voz:** el docblock de `VoiceTranscriptLexicon` dice que «una prueba las compara con NFKD de `Normalizer`»; no encontré esa prueba (`grep FORM_KD|FORM_KC|NFKD` en `tests/`: nada). La mía (§2.6) compara la tabla de `WhatsappSignals`.
- **R-8 · `limit(200)` sin `ORDER BY`** en `SubjectSuppressions` (preexistente). Mis reglas añaden filas candidatas (`failed`/`opt_out_word`), pero la propia supresión las corta pronto (después de la primera, la escalera niega antes del canal); con > 200 filas de una persona el resultado dependería del orden físico.
- **R-9 · Medir los falsos positivos antes de abrir.** El barrido del canal lee también conversaciones de Legacy: una consulta de solo lectura sobre una COPIA de producción (cuántas personas con WhatsApp tienen una posible baja en sus últimos 200 entrantes) diría cuánta gente deja fuera D-2/D-3. No tengo acceso a producción ni lo intenté.

## 6. Guardas, Pint y lo demás

- **Ninguna guarda cae**; no pido enmiendas. Pasaron `OutreachStaticGuardsTest` (importaciones: lo mío solo usa `App\Services\Outreach`, `Illuminate` y PHP), `DetailCodeTextsTest` (sin códigos nuevos: D-4), `WhatsappW9IsolationTest` (mismas escrituras: `{"marketing_conversations@puerta":2,"marketing_messages@puerta":3,"outreach_enrollments":3,"outreach_jobs":3,"outreach_outcomes":2,"outreach_runs":2}`; la lista de ficheros que nombran `OutreachTemplateMessage` no cambia), `OutreachIsolationTest` (puerta de diff vacía) y `UltronEngineIsolationTest`. La guarda de solo lectura de los hechos (B9-facts): 0 escrituras.
- **Pint:** `--test` pasa en mis 7 ficheros (4 de código y las 3 pruebas; el outbox, aparte). El outbox falla con `fully_qualified_strict_types` (y los fijadores que Pint lista con él) **también en HEAD** (`git show HEAD:…` en el clon: mismo resultado); el diff que Pint aplicaría son dos FQCN preexistentes, `WhatsappOutboxService.php:341` (`\Illuminate\Support\Carbon`) y `:355` (`\Illuminate\Database\Eloquent\Collection`), de Legacy: no los toco («ni una línea» del camino actual). Mis líneas del outbox, limpias.
- Rendimiento: el canal sigue con UNA lectura de ≤ 200 entrantes por intento; ahora evalúa 16 expresiones sobre ≤ 4 formas de cada cuerpo (cadenas cortas). El reintento de una plantilla suma 1–2 consultas (prospecto y hermanas «no contactar», la lista diminuta que ya lee la puerta). No medí tiempos fuera de las pruebas.

## 7. Qué verifiqué ejecutando (comando → resultado)

Todo desde `backend/`, `php -d memory_limit=2G`, `--no-logging --do-not-cache-result`, un proceso mío a la vez (en un momento había 2 procesos de PHPUnit de otros agentes).

| # | Comando | Resultado |
|---|---|---|
| 0 | Línea base ANTES de tocar nada: `vendor/bin/phpunit tests/Feature/Outreach/W9 tests/Feature/Outreach/B9Cierre/DetailCodeTextsTest.php tests/Feature/Outreach/Run/OutreachOutcomesTest.php` (SQLite) | OK (46 tests, 492 assertions, 2 skipped: solo PG) |
| 1 | Lo mismo después del cambio | OK (46, 492, 2 skipped); las mismas escrituras de W9 |
| 2 | Mis 3 ficheros (SQLite) | **OK (13 tests, 1 336 assertions)** |
| 3 | `vendor/bin/phpunit tests/Feature/Outreach` (SQLite) | **OK (613 tests, 39 496 assertions, 163 skipped)**: solo PG y la vía abierta de V9 |
| 4 | `vendor/bin/phpunit tests/Feature/UltronStudio/OutreachIsolationTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **OK (14 tests, 2 500 assertions)** (los avisos «fichero nuevo en la voz del H8» son de h8-hardening, no míos) |
| 5 | `PGGSSENCMODE=disable createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_secbaja`; `UNIT_DB=ultron_studio_secbaja bash docs/ultron-studio/ops/pgunit.sh … tests/Feature/Outreach/W9 tests/Feature/Outreach/Run` | **OK (119 tests, 2 990 assertions, 1 skipped: solo SQLite)** |
| 6 | Lo mismo con `tests/Feature/Outreach` completo (PG 14) | **OK (613 tests, 40 608 assertions, 28 skipped)**; después, 0 filas en `outreach_outcomes`, `outreach_jobs` y `marketing_messages` |
| 7 | Regresión dirigida de Marketing/Legacy (SQLite): `tests/Feature/Marketing/{OutboxDelivery, OutboundMediaDelivery, OutboundAttachment, OutboundLandsInItsConversation, OutboundContentGuard, MarketingAgentPause, MarketingAgentSwitchEndpoint, OptOutPorTelefono, InternalSecretAuthoritySurface, SendMessage, UltronCommit, PaymentLink, UltronPaymentLink, DispatchMarketingFollowups}Test.php` (los 14 de la descripción de W9) **+** `{InboxOperation, AdversarialInbound, UltronComposerStyle}Test.php` (los otros que nombran el despachador o el outbox) **+** `tests/Feature/Chaos/{ChaosMetaChannel, ChaosCommercial, ChaosWorkers, ChaosMedia}Test.php` **+** `tests/Feature/IronGuard/IncidentDetectionTest.php` **+** `tests/Feature/Valuations/ValuationRemindersTest.php` | **OK (339 tests, 1 718 assertions)**. §8 de W9 no enumera sus ficheros (solo «14 de Marketing + 4 de caos + IncidentDetection + ValuationReminders», 328): la reconstruí por su descripción y le sumé tres; es un superconjunto, no su lista exacta |
| 8 | Mutaciones en un clon APFS del backend (scratchpad; el árbol compartido no se tocó): 9 mutantes, cada uno contra mis 13 pruebas | **9/9 cazadas**: M1 regla SC-1 quitada → 3 fallos; M2 regla `opt_out_word` → 2; M3 detector sin contención → 2; M4 normalización sin `\p{M}`/invisibles → 3; M5 sin ancho completo → 3; M6 canal solo con la baja segura → 1; M7 normalizador sin la posible baja → 1; M8 reintento sin recomprobar → 2; M9 sin hermanas → 1. Base y final: OK (13, 1 336) |
| 9 | Sonda pura del detector (scratchpad, `optout_probe.php`) | Las 13 cadenas del informe → posibles (4 seguras); 26 frases normales → nada; bordes (null, vacío, U+200B, «gracias», emoji) → nada; UTF-8 inválido → posible |
| 10 | `vendor/bin/pint --test` sobre mis ficheros; sobre el outbox de HEAD en el clon | `passed`; el outbox de HEAD: `fail` con los mismos fijadores (§6) |
| 11 | `php -l` de los 5 ficheros de código | sin errores |

## 8. Qué NO verifiqué

- **Producción y datos reales**: ni la tasa de falsos positivos del detector sobre conversaciones reales (R-9), ni cuántas personas quedarían suprimidas con D-2.
- **Meta real** y la cola real: las pruebas usan el Graph falso y entregan en línea; el comando `marketing:retry-outbox` sí corrió (dentro de la prueba).
- **El CRM**: cómo pinta `human_requested` + `opt_out_word` y los tres motivos nuevos del outbox en el Inbox (los textos van en `metadata.failure`, como la pausa). No corrí specs ni navegador.
- **La regresión completa de Marketing** (≈ 2 234 pruebas) ni la suite completa del backend: solo la dirigida de §7 #7.
- **`tests/Unit`**: no la corrí (ningún fichero suyo usa lo que toqué: `grep` de `WhatsappSignals`, `SubjectSuppressions`, `isOptOutText` en `tests/Unit` → nada).
- **Un fallo real de PCRE** dentro del detector: el camino existe y falla cerrado (posible baja), pero no lo provoqué en una prueba.
- **La lectura jurídica** de D-1, D-3 y D-5: es del propietario con su asesoría.
- **La vía abierta de V9** (`v9-abierta.sh`) y el delta en curso de otros agentes.

## 9. Resumen para el lead

- **SC-1 cerrado en el libro:** un `opted_out` de `inbound_message` o `voice_call` suprime TODOS los medios de su finalidad (no otras finalidades). De punta a punta: baja por WhatsApp de P → la estrategia comercial de voz la niega en la escalera (**N2 `global_suppression`**, que es donde vive esa razón en `PolicyLadder`; N1 no tiene nada que negar) y el canal de voz no se intenta; Q, igual salvo la baja, sí llega (y se para en la constante). Residual R-1: una baja por WhatsApp que el H9 nunca vio (solo Legacy) aún no alcanza la voz → la recomendación de sec-cierre sigue para ese caso.
- **SC-2 cerrado:** normalización NFKC/NFD/invisibles sin `ext-intl`; la baja segura sigue siendo `opted_out`; la posible (contención conservadora) no se envía (`opt_out_word`) y una persona la revisa (`human_requested` → `handoff_human` aunque la estrategia diga `end_exit`); docblock honesto. Las 13 cadenas del informe no salen; «¿qué es la baja?» → revisión; 24 frases normales, nada.
- **SC-10 cerrado:** en la rama de plantillas, cada reintento recomprueba «no contactar», `canContactProactively()` y las hermanas por número normalizado; si no, `dead` con su motivo. Legacy: +97/−0 aditivo, 0 consultas nuevas en su camino, regresión dirigida 339/339.
- **Cifras:** Outreach SQLite 613/613 y PG 613/613; W9 + Run PG 119/119; aislamiento 14/14; mutaciones 9/9; Pint limpio en lo mío. 0 guardas caídas, 0 migraciones, 0 variables, 0 códigos nuevos.
- **Decide tú:** D-1 (`staff` también a todos los medios), D-2 (la posible baja suprime la finalidad), D-3 (no hay «descartar»: falta un mecanismo), D-4 (`opt_out_word` frente a un código propio), D-5 (comercial ≠ `service`, con asesoría). Y reflejar D9-10/D9-15 en el contrato.
- **Sin commit.** Los cambios quedan en el árbol; la base PG desechable `ultron_studio_secbaja` queda borrada al cerrar.
