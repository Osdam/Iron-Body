# Informe del reviewer · Ola 3 (alerta M1-A, fidelidad del laboratorio y textos y estados del CRM)

**Fecha:** 11-11-2026. **Agente:** `reviewer`, solo lectura: no se editó código, no hubo commit y no se tocó producción. **Árboles:** backend `_worktrees/ultron-h8/Iron-Body/backend` y CRM `_worktrees/ultron-h8/Iron-Body_Front`, los dos en la rama `feat/ultron-studio-h9` y sin commit. En el backend solo se revisaron los 7 ficheros que pidió el lead.

## Veredicto

**APROBADO CON OBSERVACIONES.** No hay nada bloqueante. Hay un hallazgo importante en el CRM: hoy no se ve en producción, pero conviene corregirlo antes de que se abra el envío automático. Hay además dos menores.

## Verificado

- **Backend:** ejecuté `nice -n 10 php -d memory_limit=1G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/UltronStudioMonitorPromptsTest.php` → **OK (10 tests, 132 assertions)**, en SQLite. No repetí el resto: el lead ya había comprobado 138/138, 45/45 y PostgreSQL 14.
- **CRM:** no ejecuté nada (el lead ya había pasado `tsc`, `check-literals` y 1 907 pruebas). Revisé el diff completo de los 47 ficheros y las dos especificaciones nuevas.
- Contrasté el código con la fuente real del servidor:
  - `UltronCommitService::persist()`: el sello entra en el mismo `INSERT` que la fila;
  - `UltronPromptOffer` y `UltronDecideService:402`;
  - `UltronPromptStamp`, `VoiceWebhookHealth::lastVerdict()` y `VoiceProviderState::verification()`;
  - `StudioPersonBindings::personOf()` y `requirePerson()`;
  - las migraciones de `marketing_ai_actions`.
- Contrasté los textos con las auditorías A (§3.1, §3.3, §3.4 y §3.7), B (§4.8) y C (§4.1, §4.4, §4.6 y §4.8).

## Bloqueantes

Ninguno.

## Importantes

1. **`Iron-Body_Front/src/app/modules/commercial-automation/ca-activation.ts:363` — la fila «voz» (V9) ya nunca puede quedar en «Disponible y operativa».** Asignado a **frontend**.
   - **El problema:** en `canalAutomatico`, con `ch.live` y el canal de voz, ahora se añade siempre una falta `contractual` (`T.vozHito8`), sin ninguna condición. Antes del cambio, con `ch.live`, no se añadía nada.
   - **Escenario de fallo:** la fase 6, con el envío abierto en el código (`sendsEnabledInCode: true`), el modo `live`, `voice_call.enabled: true`, sin frenos y la voz del Hito 8 ya llamando. Resultado: estado «Bloqueada por condición contractual» y la frase «antes necesita un número de voz… la credencial de Twilio… voice(calls-open) y «salientes on»», que entonces es falsa. Con el canal de voz apagado y todo lo demás listo, también sale «contractual» en vez de «lista», porque `contractual` tiene más precedencia.
   - **Hoy no se ve:** el envío sigue cerrado en el código y `T.sendsOpen` ya pone la fila en contractual. Tampoco hay ninguna prueba que lleve un canal automático a «operativa», ni antes ni ahora: por eso no saltó.
   - **Corrección mínima:** no hacer de `vozHito8` una falta incondicional. Por ejemplo, añadirla solo mientras `!s.config.sendsEnabledInCode`, o dejarla solo en el dato que falta (`ActivationGap`), que ya existe. Añadir una prueba con todo abierto en la que «voz» y «whatsapp» (este con una plantilla `APPROVED` en `metaTemplates`) den `operativa`.

## Menores

1. **`Iron-Body_Front/src/app/modules/ultron-studio/studio-voice-parse.ts:1341-1342` — el lector de `providerVerification` rechaza una forma real del servidor.** Asignado a **frontend**.
   - **El problema:** `VoiceProviderState::verification()` devuelve `state: 'invalid'` **con las dos fechas** cuando la marca está en el futuro, más allá de `FUTURE_SKEW_SECONDS` (`VoiceProviderState.php:103` y `:108`). El lector exige que `invalid` llegue sin fechas.
   - **Escenario de fallo:** el reloj del servidor retrocede, o la marca se escribe con una hora futura. El CRM lee `undefined` («no llega, o llega con otra forma») y no muestra «es ilegible: cuenta como sin verificar».
   - **Por qué es menor:** nunca da un «válido» falso, y el estado de la fila no depende de este dato.
   - **Corrección:** admitir `invalid` con o sin fechas, y añadir ese caso a `studio-voice-parse.spec.ts`.
2. **`Iron-Body/backend/docs/ultron-studio/ULTRON_STUDIO_CIERRE_9_HITOS.md:42`, `:79` y `:96` — siguen diciendo que la alerta M1-A está «NO IMPLEMENTADO».** Cuando se despliegue, el documento contradirá a producción. El autor ya lo señala en su §5. Asignado al **lead** o a **documentation-specialist**, al desplegar.

## Notas

**Backend (M1-A), revisado y correcto:**
- **Ventana:** `promptsWindow()` da tramos `[S−32, S−2)` contiguos y disjuntos entre pasadas programadas, también con segundos o minutos de retraso dentro de la misma media hora. `created_at` va en segundos, con `>=` y `<`: ningún turno se cuenta dos veces. El sello se escribe en el mismo `INSERT` que la fila (`persist()`), así que no existe un «sello confirmado tarde». No encontré ninguna transacción alrededor de `persist()`: la gracia de 2 minutos sobra, pero no hace daño.
- **Límites ya documentados por el autor (no son hallazgos):**
  - una pasada programada que no corre (el servidor caído justo en ese minuto, o `withoutOverlapping(10)` ocupado) deja su media hora sin vigilar, sin recuperarla después. Con una sola conversación canaria y pocos turnos, una alerta puede perderse. El turno sigue en rojo en `ultron:prompt-trace` y en el CRM;
  - una pasada a mano sin `--dry-run` suma una ocurrencia, o reabre el incidente.
- **Aislamiento:** una excepción en `prompts()` la recoge el `try` de `handle()` como `check_failed` (incidente medio, solo con la clase de la excepción). No tumba las otras tres comprobaciones; la prueba H7 lo cubre con «las cuatro se comprobaron». Con la llave en `off` o sin canario sale `ok` con `not_watched`: no abre incidentes falsos ni hace consultas.
- **Con la llave en `off` tampoco alerta un `legacy_drift`**, aunque el sello sí se escribe: `decide` ofrece Legacy siempre que n8n negocia, `UltronDecideService:402`. Es una decisión del lead (§6.1 del informe del autor). La dejo dicha para que el propietario la conozca.
- **Incidente:** el `fingerprint` son solo los estados. La evidencia lleva estados, recuentos, la ventana y huellas recortadas a 8 caracteres hexadecimales (`short()`). No lleva ids de conversación, de fila ni de ejecución, teléfonos ni textos; las pruebas 1 y 2 lo comprueban con centinelas. Deduplicación: la de `IncidentRecorder`, como en `publication`.
- **Guardas:** el Studio no importa ninguna clase nueva del motor. `PROMPT_ALERT_STATES` se ata a `UltronPromptStamp` desde la prueba, no desde la clase. Las pruebas ejecutan el comando real con `IncidentRecorder` y la base real. El único doble es el `bind` que lanza la excepción en la prueba 9.
- **Rendimiento:** `marketing_ai_actions` no tiene índice por `conversation_id`. Los que tiene son `(lead_id, created_at)`, `(source_type, source_event_id)` y el único de `idempotency_key`. Son una o dos consultas cada 30 minutos (la segunda solo si hay alertas), con el mismo perfil que `promptTurns()`, que ya sirve `status`. Es aceptable. Si la tabla pasa del millón de filas, que **database-specialist** valore `(conversation_id, created_at)`.
- **`FIDELITY`:** el literal exacto, calculado al leer, sin guardar y sin entrar en `resultsHash`. Una prueba lo fija y el fixture del CRM es idéntico.

**CRM, revisado y correcto:**
- **Textos sobre la llave de prompts** (`studio-engine-text.ts`, `studio-param-row.ts`, `studio-inspector.ts` y `studio-versions-panel.ts`): con `canary`, la frase de M-1. Con `off` (o un servidor sin la llave), el texto literal de siempre. Ilegible, una frase neutra. Los cuatro parámetros gobernados coinciden con la auditoría A §3.1 (`PROMPT_GOVERNED_PARAMS`). «Si no entiende», las reglas S-NNN, el registro de canarios (T9) y `no_voice_engine` (T10) dicen lo que dice la auditoría y no dependen de la llave.
- **El plan de ElevenLabs:** ninguna frase visible lo pone ya como condición.
- **Cifras fijas:** no hay teléfonos ni fechas fijas. Los recuentos («hoy N») se calculan con lo leído.
- **Estados:** los seis nombres no cambian. Los cambios de estado coinciden con la auditoría C §4.8 y la B §4.8.
  - El monitor de voz encendido sigue en «operativa» aunque la última vigilancia haya encontrado problemas. Antes también era así, y ahora la frase los enumera. La auditoría C pedía «verse como en observación», y la taxonomía no tiene ese estado: queda como decisión.
  - El canal de WhatsApp solo pasa a «proveedor» con un envío esperando a Meta.
  - Publicar voz añade el id del número importado, como pide la auditoría C §4.4, punto 9.
- **Lectores nuevos:** `webhookHealth` y `providerVerification` leen bien las dos formas de producción que dio el lead. Una forma rara queda en `undefined` sin tumbar la telefonía (`suave`). Para la excepción, ver «Menores 1».
- **Botones sin persona vinculada:** `personBound()` usa el mismo criterio que el servidor (`personOf()`: un vínculo verificado y no revocado), así que una cuenta con persona vinculada no queda bloqueada. La pestaña solo se pinta con el catálogo ya leído. El motivo es texto visible, con `aria-describedby` y un candado `aria-hidden`. Un botón desactivado no recibe foco, así que no lo atrapa.
- **XSS:** ningún `innerHTML`, `bypassSecurity*` ni equivalente en el diff. Todo pasa por interpolación.
- **Pruebas:** no hay `.only`, `.skip` ni `xit`. Las aserciones que se quitaron fijaban frases o estados que las auditorías declaran falsos, y las sustituye una aserción exacta. No hay dobles que reescriban una clase de producción.
- **Alcance:** no se tocan dependencias, estilos ni maquetación. `ca-activation-panel.ts` y `commercial-automation-page.ts` estaban fuera de la lista inicial del autor, pero son el cableado que pedía su propio informe (tanda 2).

**No pude verificar:**
- la app en un navegador: el motivo bajo los botones desactivados y el cable discontinuo de Meta, en un ancho estrecho y en uno ancho;
- la batería del CRM ni PostgreSQL, que no ejecuté (me apoyo en lo que corrió el lead);
- el tamaño real de `marketing_ai_actions` en producción;
- que `GET admin/voice/status` en producción devuelva exactamente lo que da `VoiceTelephonyStatus::snapshot()`. Leí el código, no la respuesta en vivo.
