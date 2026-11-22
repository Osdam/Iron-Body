# Informe del backend de voz (4) · los no bloqueantes N-1, N-5, N-7 y N-8 de la revisión de security del lote 3 (22-11-2026)

**Base:** `integracion/voz` @ `aacd7376`; cuatro commits: `f28ef1f5` (N-1), `b6d9dc44` (N-7), `2f87d669` (N-8) y `49f039f2` (contrato), más el de este informe. `CALLS_ENABLED_IN_CODE` sigue en `false`. Sin red, sin producción, sin proveedores. `informe-security-lote3.md` ya estaba en la rama (`272faaa2`, idéntico al de la sesión, `cmp`): no se copia. **[C]** ejecutado · **[L]** leído.

## 1. N-1 · el job repite la guarda del número: HECHO, con una desviación [C]
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php:245-249`: tras `policyCheck()`, si `VoiceNumberGuard::collidesForPolicy()`, devuelve el motivo y `run()` cancela como con los demás (`canceled`, `ended_at`, sin evento, reserva liberada, sin petición). Docblock, paso 1 (`:54-57`).
- **Desviación:** la línea del informe (`return 'voice_number_is_whatsapp';`) **tumba `TelephonyIsolationTest`**. E4-4 admite ese literal SOLO en `OutboundPolicy.php`: probado con el literal en el job, «nombra voice_number_is_whatsapp». El motivo sale de `OutboundPolicy::REASON_NUMBER_IS_MESSAGING` (`OutboundPolicy.php:86`). Es una constante nueva y la política también la usa (`:132`), como `REASON_DUPLICATE`. Los 28 motivos no cambian. **Esto rompe el «diff vacío» de `OutboundPolicy` y `PlaceOutboundVoiceCall` que daba la respuesta A del informe de security:** que lo mire el reviewer.
- **Prueba:** `VoiceNumberSharedIdentityTest::test_si_se_retira_la_identidad_…` (`:267`). Es S1 con los tres cambios, cada uno con su saliente `requested` y con la vuelta comprobada, más `run()` real (cerrada: `calls_disabled_in_code`; abierta: `voice_number_is_whatsapp`). **Cae con el job de antes:** «null is identical to 'voice_number_is_whatsapp'».
- **Una expectativa cambia** por la propia corrección: `test_si_la_observacion_cambia_o_caduca…` (`:232`). Con la conciliación que ve un agente, el job dice ahora `voice_number_is_whatsapp` y no `voice_braked`, porque la guarda va antes que el freno, como en la puerta. La prueba sigue exigiendo el freno echado y los dos motivos en la puerta.

## 2. N-5 · las tres notas de `VoiceTools`: NO HECHO, la corrección descrita no se puede aplicar [C]
- **Huellas [L]:** ninguna nota entra en un texto firmado ni en una huella. Son respuestas de la herramienta en tiempo de llamada. Solo las nombra `VoiceToolsBenefitsTest:244`, por la constante. El hash del E2E es de `(fase, paso, nombre, ok)`, no de cuerpos. La plantilla, el compilador y `VoiceTranscriptAllowlist` no las contienen.
- **El bloqueo:** «dile que puede escribir a Iron Body por WhatsApp» lleva «WhatsApp», y `TelephonyIsolationTest` (de SEC8, no se toca) prohíbe ese literal en `app/Services/Telephony`. El propio docblock de `VoiceTools::BENEFITS_NOTE` lo dice: «la plantilla nombra el canal; la telefonía no». Probado poniéndola en `:394`: «VoiceTools.php nombra … por WhatsApp.: el motor, Meta y WhatsApp no son de la voz» (1 fallo). El fichero se restauró y conserva su sha256.
- **Propuesta (decide el lead):** la misma frase sin el canal, «dile que puede escribir a Iron Body». La plantilla ya le da el canal a Sofía. Sonda con `inspect()` y `spokenCodes()`, tras el aviso:
  - pasan limpias «Puedes escribir a Iron Body.», «Si quieres, puedes escribir a Iron Body.», «Ahora no puedo leer los planes; puedes escribir a Iron Body.» y «No tengo la sede ni el horario a mano; puedes escribir a Iron Body.»;
  - siguen frenando, con `machine_reply_unauthorized_handoff`, «Si quieres, una persona del equipo te escribe por WhatsApp», «Una persona te escribe hoy mismo» y «Si quieres, una persona te escribe».
  - La sonda está fuera del repo: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/584fdb7d-c292-4d52-a49c-d6f9c7b7e98c/scratchpad/probes/N5PhraseProbeTest.php` y su `n5-frases.out`.

## 3. N-7 · C-3 en el presupuesto omnicanal del H9: HECHO [C]
- `app/Services/Telephony/VoiceFrequency.php:108-114` añade `countedSql()`: `COUNTED_STATUSES` o `VoiceAmbiguousResolution::resolvedSql()`, la condición que ya usa `VoiceCallFacts`. Es una sola regla y va por `VoiceFrequency` porque es la única clase de la telefonía que `Outreach` puede importar (`OutreachStaticGuardsTest`, L9-28).
- `Outreach/Facts/ContactHistoryFacts.php:142-146` la aplica en la MISMA sentencia (un `EXISTS`). `voice_call_events` entra en `TABLES` (`:55`), como pide `FactsReadOnlyTest`.
- **Consultas:** la foto completa sigue en **39 por lote** en PostgreSQL (`SubjectAndSnapshotTest`). La fila cuenta también para `contact.*` y sale con su estado real (`failed_to_start`).
- **Prueba:** `AgendaEngineHistoryFactsTest::test_la_ambigua_cerrada_…` (`:251`), con el cierre real de `voice:resolve-ambiguous`. El historial, `daily_contact_limit` y la frecuencia de voz dicen lo mismo: la cerrada por E19-3 cuenta y la `failed_to_start` de siempre no. **Cae con el adaptador de antes** («resuelta: el historial»).
- **PostgreSQL:** los 3 ficheros de `Facts` implicados, `OK (16 tests, 675 assertions)`.

## 4. N-8 y la documentación [C]
- **N-8:** `config/voice.php:120-126`, solo el comentario. `php -w` da la misma huella antes y después (`63c20100de202878`).
- **Contrato del H8:** E19-4 (N-7, `:1031`) y E20-2 (N-1, `:1040`). N-5 no lleva fila.
- **El runbook no se toca:** la regla (2).8 no está escrita en `ops/` (su vuelta atrás funcional, `RUNBOOK_PRIMERA_LLAMADA.md:213`, es `numero-compartido off` sola) y N-1 no deja nada sin sentido. Desde N-1, esa orden sola ya no deja marcar una saliente que esté en la cola. E20-2 deja la regla como defensa en profundidad: añadirla al runbook es de devops.

## 5. Ejecutado sobre `49f039f2` (línea final) [C]
| Qué | Resultado |
|---|---|
| Voz + telefonía, SQLite | `OK, Tests: 966, Assertions: 248777, Skipped: 164` (965 + 1) |
| `Feature/{Telephony,Voice}`, PostgreSQL 14 (`ultron_studio_cierre_voz`) | `OK, Tests: 586, Assertions: 223312, Skipped: 27` (585 + 1) |
| `UltronStudioVoice*Test.php` (18) | `OK, Tests: 198, Assertions: 2573, Skipped: 73` |
| `tests/Unit/Outreach` + `tests/Feature/Outreach/Facts`, SQLite | `OK (1149 tests, 54513 assertions)` |
| Guardas (17 ficheros, el paquete del encargo) | `OK (149 tests, 192849 assertions)`. **Ninguna cae** |
| Pint (los 8 ficheros PHP tocados) | `passed` |
| E2E `todas` (puertos por defecto, `H8_E2E_EVIDENCIA=0`) | cerrada 6/113, abierta 9/301, salientes 11/247, 0 fallos. Hash `a6f5f5ee…`: distinto en UNA fila de 661, el nombre de una comprobación con «el próximo domingo 10:00», que depende del reloj (`2026-11-22` en la base, `2026-11-29` hoy a las 10:42). **Con esa fecha, el hash recalculado es `832085b4ae85…`**, el de la base (`…T082242Z-36300b48`). Resumen en `~/.cache/ironbody-h8-e2e-voz4/run-20261122T154249Z-49f039f2/resumen.txt` |
| Parches de la apertura (árbol desechable, rama nueva `tmp/telefonia-abierta-medicion-r3` = `49f039f2` + `f273a023`, la apertura simulada, cherry-pick de `ff0bbaf1`, solo `VoiceCallPolicy.php:23`) | `git am` limpio, `range-diff` «=» en los tres, 30 ficheros y ninguno fuera de `backend/tests/`, huellas `9f51b30a…`, `5e5c61de…`, `361c9ff2…` sin cambio. Abierta: `bateria-rapida.txt` `OK (624 tests, 25217 assertions)`; voz + telefonía `Tests: 975, Skipped: 164` (966 + 9), 0 fallos. La rama no se integra ni se borra |
| E2E `todas` sobre la rama desechable (`10135b57`, fuente ABIERTA; el arnés gira la cerrada) | 1.ª pasada: cerrada 6/113 y abierta 9/301 sin fallos; en salientes, 243/247. Las 4 caídas tienen una sola causa: la post-llamada `from_future` respondió 200 y no 401. **Causa [C]:** es un borde del banco, no de este lote. El simulador firma con `t = ahora + 301` en segundos enteros (`sim-elevenlabs.cjs:193`, `:301`) y Laravel rechaza solo si `t > ahora + 300` (`ElevenLabsWebhookSignature.php:100`). La entrega salió a las `15:55:00.98` y llegó a la `:01.08`: al cruzar el segundo, queda `t = ahora + 300` y se acepta. El diff de `app/Services/Voice`, `app/Http` y `routes` sobre `aacd7376` está vacío. **Repetida la fase salientes:** 11/247 sin fallos y `from_future` → 401 en 36 ms. Con las tres fases buenas, el hash es `a6f5f5ee…`, el mismo que en `49f039f2`; con la fecha de la base, `832085b4ae85…` |

## 6. Lo que el lote 3 cambia fuera de `app/` y `tests/` frente a producción (`git diff --name-only fddd3450..HEAD`)
- **Migración (1):** `database/migrations/2026_11_22_100002_allow_unstarted_ambiguous_voice_calls_to_fail_to_start.php`, antes que el código (condición (1).1).
- **Configuración:** `config/voice.php`, la clave `voice.number.shared_with_messaging` (por defecto `false`) y comentarios. **Variable nueva:** `VOICE_NUMBER_SHARED_WITH_MESSAGING`, en `.env.example` `=false`; en producción, ausente o `false` (condición (1).2). Después, `config:cache` y `queue:restart`.
- **Mandos de `ops/` que van al servidor:** `h8-voice-scope.sh`, `h8-twilio-check.sh` y `h8-schema-check.sql`. Solo locales: `banco-h8/*` (`ensayar-ops8f.sh` y tres simuladores) y `herramientas-lead/avance.py`.
- **Documentación:** `MATRIZ_CIERRE_V2.md`, el contrato del H8, `ops/README.md`, `RUNBOOK_PRIMERA_LLAMADA.md`, `banco-h8/README.md`, 9 informes (con este) y 209 ficheros de evidencia.
- **Nada más:** ni rutas, ni `composer`, ni otras migraciones.

## 7. Lo que NO pude verificar
- N-5 con la frase alternativa en el código: no está aplicada, solo sondeada como texto.
- Qué frase dice el modelo cuando sigue la nota, la de hoy o la propuesta: solo lo dice una llamada.
- El comportamiento de N-1 con el worker y los proveedores reales.
- Que PCRE 10.39 afecte a N-7: no hay regex nueva, es solo SQL.

## 8. Hallazgos fuera de alcance
1. **N-5:** el informe de security propone una frase que choca con su propia guarda (`TelephonyIsolationTest`). La decisión vuelve al lead.
2. **El fixture `outbound-gate-cases.json` §job:** no tiene caso de `voice_number_is_whatsapp` (tampoco de `number_unverified`). Lo cubre la prueba de §1. Añadirlo es trabajo del fixture.
3. **Docblock de `ContactBudget.php:19`:** todavía dice «en `VoiceFrequency::COUNTED_STATUSES`». No lo toqué (Core, solo comentario).
4. **El E2E no es determinista en el nombre** de `test_g` («el próximo domingo»): su hash depende de la hora en que corre. Lo arregla quien tenga el arnés, con un instante fijo.
5. **El banco tiene un borde intermitente** en `from_future` (`sim-elevenlabs.cjs:193`, `dt: FUTURE_SKEW_SECONDS + 1`): una entrega que cruza un segundo hace que Laravel acepte la firma y el E2E caiga 4 comprobaciones. Es de devops (`ops/banco-h8`): por ejemplo, `+ 2` o más. No es un fallo de Laravel: en el borde exacto (`t = ahora + 300`) la firma es válida por contrato.
