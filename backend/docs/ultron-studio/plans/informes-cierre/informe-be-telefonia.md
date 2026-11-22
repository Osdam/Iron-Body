# Informe del backend · enmienda de telefonía: el número empresarial como origen de las salientes (22-11-2026)

**Por decisión expresa del propietario del 22-11-2026** (texto literal: `MATRIZ_CIERRE_V2.md` §9). Diseño: `diseno-arq-telefonia.md` (opción C), implementado tal cual salvo lo que dice §9 de este informe. Rama `cierre/telefonia` sobre `b875875f`. `CALLS_ENABLED_IN_CODE` sigue en `false` (`VoiceCallPolicy.php:23`). Nada desplegado, ninguna red, ningún proveedor real. Commits: `84f8f4a9` (producto y sus pruebas), `47b40f6e` (negativas de canario), `3e8d2892` (contrato), y el de este informe. **Migraciones:** ninguna. **Configuración nueva:** `VOICE_NUMBER_SHARED_WITH_MESSAGING` (por defecto `false`; sola no admite nada). Ningún fixture cambia.

## 1. Qué cambió (`backend/`, sobre `84f8f4a9`)
- `app/Services/Telephony/VoiceNumberGuard.php`: `SHARED_REASONS` cerrado y ordenado (`:116`); `sharedVerdict()` PURA (`:348`), cada condición con su valor exacto (null o ausente no cumple); `sharedConfigFacts()` (`:371`, condiciones 1, 3 y 4); `sharedIdentity()` (`:392`) y `sharedIdentityOf()` (`:401`): configuración + caché, UNA lectura; `isBusinessNumber()` (`:168`): exactamente `WHATSAPP_DISPLAY_PHONE` (la guarda sigue siendo el único fichero que lee las dos claves de Meta); `rememberObservation()` (`:185`) con `inboundCapable` y `agentAssigned`, por defecto null (falla cerrado); `lastObservation()` (`:199`) devuelve la forma de siempre (`OBSERVED_KEYS`, `:92`) y acepta la caché de antes (`storedObservation()` `:214`, `providerFacts()` `:231`); `policyVerdict()` (`:269`) delega en `verdictOf()` (`:282`), el mismo cuerpo; `collidesForPolicy()` (`:322`) = `(config || obs.collides) && ! admitted`.
- `app/Services/Telephony/VoiceReconciler.php:706-726`: veredicto con la observación recién hecha (condición 2 con el número OBSERVADO; 5 = `$coincide === true`; 6 y 7 del proveedor); `elseif ($choca && ! $compartida['admitted'])` → freno `number_is_messaging` como hoy; admitida → `outbound_only`. Resumen `sharedWithMessaging` (`:683`, `:718`; null sin observación); caché con los dos hechos (`:720`); en seco no escribe.
- `app/Services/Telephony/VoiceTelephonyStatus.php:77`: `number.sharedWithMessaging`, aditivo. `collidesWithMessaging` sigue siendo el HECHO.
- `app/Console/Commands/Voice/VoiceDoctorCommand.php:154-198`: «Número» y «Importado» distinguen admitida/no; línea nueva `Compartido :` con el motivo. `VoiceReconcileCommand.php:74-79`: admitida o el motivo.
- `config/voice.php:163-169` (`voice.number.shared_with_messaging`, `filter_var`, por defecto `false`) y `.env.example:543-550` (`=false`).
- Pruebas: `tests/Unit/Telephony/VoiceNumberSharedVerdictTest.php` (5, la tabla pura y las 128 combinaciones), `tests/Feature/Telephony/VoiceNumberSharedIdentityTest.php` (19), `tests/Feature/Telephony/OutboundCanaryTargetTest.php` (3); editadas: `VoiceCommandsTest.php:67` (la clave nueva) y `tests/Unit/Voice/ElevenLabsConfigTest.php` (el defecto `false`, la bandera y la variable en la lista que limpia el entorno; ver §9).
- Contrato `ULTRON_STUDIO_H8_CONTRACT.md`: protección 6 (`:56`), D8-4 «Credencial» (`:151`), D8-5 Twilio (`:162`) y la guarda (`:183`) con el texto del punto 8 del diseño; Enmienda 20, fila **E20-1**, que cierra E16-2 y E16-16.

## 2. Las siete condiciones: negativa y mutación
Negativas en `VoiceNumberSharedIdentityTest::test_sin_una_de_las_siete_todo_queda_como_hoy` (proveedor de datos, una base por caso): conciliación `[1, number_is_messaging]` con su freno `number:…:messaging`, puerta `[voice_number_is_whatsapp, voice_braked]`, sin fila, y `sharedIdentity()` da el mismo motivo. Mutaciones con un guion de la sesión, fuera del repositorio (`mutar.py`: cambia la línea citada, corre las pruebas, restaura y comprueba el sha256): **17/17 cazadas**.

| # | Condición (motivo) | Negativa (caso) | Mutación → pura / integración |
|---|---|---|---|
| 1 | declaración (`not_declared`) | «declaración apagada» | quitar la línea → 3 / 2 fallos |
| 2 | es el visible (`not_business_number`) | «otro protegido que no es el visible» | quitar → 2 / 3; I5 «cualquier protegido vale» → – / 1 |
| 3 | `INBOUND_CAPABLE=false` (`inbound_capable_declared`) | «sin declarar» y «true» | quitar → 2 / 2; C3b «sin declarar vale» → 1 / 1 |
| 4 | entrantes apagadas (`inbound_enabled`) | «entrantes encendidas» | quitar → 2 / 1 |
| 5 | observación verificada (`observation_unverified`) | `test_sin_una_observacion_verificada…` (inexistente, caducada, otro id) y `…otro_numero…` | quitar → 2 / 5; I4 «sin mirar id ni edad» → – / 2 |
| 6 | observado solo saliente (`observed_inbound`) | «supports_inbound true / ausente / null» y la caché vieja | quitar → 2 / 6; C6b «null vale» (la O de hoy) → 1 / 4 |
| 7 | sin agente (`observed_agent`) | «agente ajeno» y «el publicado» | quitar → 2 / 5 |

Integración: I1 la política ignora la admisión → 2; I2 la conciliación frena siempre → 4; I3 la caché sin los dos hechos → 4. Canario: K1 `forDial` sin filtrar el canal → 1; K2 un vivo cuenta como activo → 2; K3 el job no repite el destino → 1.

## 3. Diff vacío de la lista del punto 2 (comando real, con `bash -c`)
```
$ git diff --stat b875875f..HEAD -- <OutboundPolicy, OutboundGate, PlaceOutboundVoiceCall, InboundGate, InboundPolicy,
    StudioVoicePublishing, StudioCanaryNumbers, VoiceCanaryDirectory, VoiceBrake, Outreach/Core/DecisionTraceText, config/meta.php>
(salida vacía) · numstat=0 · existen 11 de 11
```
También 0 en `VoiceCallPolicy.php`, `app/Services/{Outreach,Marketing}`, `app/Models/Voice/VoiceCall.php`, `database/migrations`, `tests/fixtures`, `docs/ultron-studio/ops`, `VoiceNumberIdentityTest`, `VoiceDoctorCommandTest` y `TelephonyFixtureParityTest`. (El diseño sitúa `DecisionTraceText.php` en la telefonía; vive en `app/Services/Outreach/Core/`.)

## 4. Negativas de canario (punto 4), con base de datos y canarios por la API del Studio
`OutboundCanaryTargetTest`, con todo lo demás en regla (número dedicado y vigilado, proveedor verificado, cupo, presupuesto):
- (a) puerta: `pending`, `authorized` sin activar, desactivado (`authorized`) y `revoked` → 409 `outbound_denied` con `[not_an_active_voice_canary]` (+ la constante si está cerrada); canal `whatsapp` activo y uuid inexistente → 404 `target_not_found`. Cero filas, cero eventos, `committedMicros = 0`, ningún job. Control: el canario de voz activo solo tiene la constante (abierta: 202).
- (b) job: desactivado o revocado con la saliente pedida → `recheckAfterCode` = `not_an_active_voice_canary`, `request()` = null (no hay número que marcar), `cancel` → `canceled` con ese motivo, `ended_at`, sin evento y la reserva liberada (de 610 500 µUS$ a 0). Y `run()` real con un iniciador que lanza si se le llama: `canceled` (cerrada: `calls_disabled_in_code`; abierta: `not_an_active_voice_canary`).

## 5. Pruebas ejecutadas (línea final)
| Qué | Comando | Resultado |
|---|---|---|
| Voz + telefonía, SQLite | `phpunit tests/Unit/{Voice,Telephony} tests/Feature/{Voice,Telephony}` | `Tests: 944, Assertions: 246019, Skipped: 163` (917 + 27), 0 fallos |
| Telefonía + voz, PostgreSQL 14 | `UNIT_DB=ultron_studio_cierre_telefonia pgunit.sh tests/Feature/Telephony tests/Feature/Voice` | `Tests: 570, Assertions: 220985, Skipped: 27` (548 + 22), 0 fallos |
| Studio voz, integraciones, laboratorio y canarios, SQLite | `phpunit --filter "Voice\|Integrations\|Lab\|Canary" tests/Feature/UltronStudio` | `Tests: 956, Assertions: 8571, Failures: 1, Skipped: 129`: el fallo es `UltronStudioIntegrationsProbeTest:129`, intermitente y ajeno (§10.1); aislado, 2 de 3 en verde |
| V9 (leen la observación del número con 3 argumentos) | `phpunit tests/Feature/Outreach/V9` | `Tests: 72, Assertions: 22789, Skipped: 29`, 0 fallos |
| Guardas estáticas | el paquete del encargo (16 ficheros) | `OK (141 tests, 188234 assertions)` |
| Pint | `pint --test` sobre los 11 ficheros tocados | `passed` |
| Mutaciones | `mutar.py` de la sesión (§2) | 17/17 cazadas; los 4 ficheros de producto vuelven con su sha256 |
| Parches de la apertura | árbol propio `_worktrees/cierre-telefonia-abierta`, rama `tmp/telefonia-abierta-medicion` (no se integra): `cierre/telefonia` + `92c02fb9` (solo `VoiceCallPolicy.php:23`) + `git am` de los tres | **limpios**; `range-diff 3287769b^..33ba6299 HEAD~3..HEAD`: «=» en los tres; 30 ficheros, nada fuera de `backend/tests/`; huellas `9f51b30a…`, `5e5c61de…`, `361c9ff2…` |
| Abierta + parches, `bateria-rapida.txt` (24 ficheros) | ídem | `OK (624 tests, 25104 assertions)` |
| Abierta + parches, voz + telefonía | ídem | `Tests: 953, Assertions: 246480, Skipped: 163` (944 + 9 de los parches), 0 fallos: las 27 nuevas valen abiertas |
| E2E del H8, `todas` | `H8_E2E_VENTANA= H8_E2E_DB=ultron_studio_h8_e2e_ctel H8_E2E_EVIDENCIA=0 H8_E2E_DIR=~/.cache/ironbody-h8-e2e-ctel H8_E2E_PUERTO_{API,EL,TW,VALLA,API2}=8441…8445 h8-e2e.sh todas` sobre `3e8d2892` | cerrada 6/113, abierta 9/301, salientes 11/247, **0 fallos** (las cifras de la línea base). Hash `86e1a7e7baea…`: cambia SOLO porque el nombre de una comprobación lleva el puerto de la segunda API (`:8445`; puertos propios para no chocar con el arnés de otro agente). Es la única fila distinta de las 661 frente a `evidencia-h8/2026-11-22/20261122T060659Z-22d2026b`; con `:8405` el hash recalculado es **`832085b4ae85…`**, el de la línea base. Evidencia no copiada a `ops/` (no lo toco): `~/.cache/ironbody-h8-e2e-ctel/run-20261122T071414Z-3e8d2892/resumen.txt` |

## 6. El contrato para el CRM y para los mandos
- `GET /api/admin/voice/status` y `voice:doctor --telefonia --json`: `number.sharedWithMessaging = {"declared": bool, "admitted": bool, "reason": null | "not_declared" | "not_business_number" | "inbound_capable_declared" | "inbound_enabled" | "observation_unverified" | "observed_inbound" | "observed_agent"}`, siempre presente (última clave de `number`), nunca null en este backend; `reason` es null si y solo si `admitted`. El CRM debe leerlo tolerante (null con un backend anterior), y dar error de número solo si `collidesWithMessaging && !sharedWithMessaging.admitted`.
- `voice:reconcile --numbers --json`: `number.sharedWithMessaging`, la misma forma, o null sin observación; con la admitida `result = outbound_only`, `brakeOpened = false`, `collidesWithMessaging = true`.
- Lo que leen los mandos de devops (sin secretos ni números): `config('voice.number.shared_with_messaging')` (bool; `shared_known` = `array_key_exists('shared_with_messaging', config('voice.number'))`), `App\Services\Telephony\VoiceNumberGuard::sharedIdentity()` (`shared_admitted` = `['admitted']`, y `['reason']`), y `collidesWithConfig()` (el hecho, como hoy `whatsapp_collision`). La admisión exige una conciliación fresca: tras escribir la declaración o cambiar `TWILIO_VOICE_FROM`/`ELEVENLABS_AGENT_PHONE_NUMBER_ID`, `voice:reconcile --numbers` antes de leer `shared_admitted` (ver §7.1).

## 7. Lo que security debe mirar primero
1. **Hallazgo sobre el diseño (no lo corregí: cambia la forma de la caché que fija el diseño).** La observación guarda `matchesFrom` frente al `TWILIO_VOICE_FROM` de ENTONCES, sin atarse a él. Si se cambia `TWILIO_VOICE_FROM` al visible (`config:cache`) sin volver a conciliar, `sharedIdentity()` se admite con una observación de otro número. Sonda (borrada, no confirmada): con el importado = otro protegido P y `TWILIO_VOICE_FROM` = P, `{admitted: false, not_business_number}`; tras poner `TWILIO_VOICE_FROM` = visible sin conciliar, `{admitted: true}` y `collidesForPolicy() = false`; igual con un dedicado Q observado antes. Hoy ese cambio daría `voice_number_is_whatsapp`. Con P la conciliación anterior ya echó el freno (hay que soltarlo); con Q no. Lo cierra la siguiente conciliación (≤ 1 h con el monitor: `number_mismatch` y freno). **Propuesta (unas líneas + una prueba):** un tercer hecho `businessNumber` (`isBusinessNumber()` del número OBSERVADO, booleano, sin número ni huella) en `rememberObservation()`, por defecto null, exigido en la condición 2 de `sharedIdentityOf()`. Mitigación operativa: que el mando concilie justo después de cambiar el número.
2. `collidesForPolicy()` (`VoiceNumberGuard.php:322`) es lo único que cambia la puerta: revisar que la admisión no se pueda dar sin la observación fresca del id configurado (I4 lo caza) ni con un protegido que no sea el visible (I5).
3. `isBusinessNumber()` compara DÍGITOS (DF-G10: un nacional de 10 dígitos gana el 57): el paso 0 del diseño (devops, solo el booleano) sigue siendo obligatorio.
4. R4–R6 del diseño los asume el propietario (E20-1); la credencial de la importación (A-1) solo la confirma él por escrito.

**Para tester (casos límite vistos desde dentro):** la observación a 7 199 s admite y a 7 200 s ya no (`OBSERVED_FRESH_SECONDS`, `>=`), y del futuro solo dentro de 300 s; `supports_inbound` con un texto (`"false"`) llega como null y no admite (`ElevenLabsAgentDeployer.php:396-400`); el visible escrito como nacional de 10 dígitos frente a `TWILIO_VOICE_FROM` en E.164 cuenta como el mismo (DF-G10); la puerta lee `collidesForPolicy()` y `policyCheck()` en dos lecturas de la caché (previo): una conciliación que escriba entre las dos puede combinar la admisión de la observación vieja con la vigilancia de la nueva, y lo para el job, que vuelve a mirar antes de marcar (`voice_braked` por el freno de esa conciliación); en seco nada se escribe; y el caso de §7.1.

## 8. Lo que NO pude verificar (mirar al configurar, con el proveedor real)
- Que `GET /v1/convai/phone-numbers/{id}` del número importado traiga `supports_inbound: false` (está marcado como obsoleto: si falta, la condición 6 falla cerrada y hay que enmendar) y `assigned_agent` nulo, y su `phone_number` en E.164 igual a `TWILIO_VOICE_FROM`.
- Que `sameNumber(<E.164 del …5483>, WHATSAPP_DISPLAY_PHONE)` dé `true` en producción (paso 0 del diseño). Si da `false`, nada se admite.
- Que Twilio deje presentar el identificador verificado en Colombia y qué ve el …6026 («Non-guaranteed»: solo la primera canaria lo dice).
- Que la importación se hizo con una API key SK… restringida, que la cuenta principal no tenga llamadas ajenas en la ventana y que `TWILIO_VOICE_SIGNING_TOKEN` esté vacío (mandos de devops, `h8-twilio-check.sh`).
- El comportamiento de la línea del gimnasio ante devoluciones de llamada y la coexistencia con WhatsApp Business (Meta no documenta salientes PSTN por otro proveedor).

## 9. Desviaciones del diseño (con su causa)
- `ElevenLabsConfigTest` se edita además de `VoiceCommandsTest:67`: fija los defectos exactos de `config/voice.php` y caería con la clave nueva; los parches de la apertura no lo tocan. Se añade la variable a su lista de limpieza del entorno (sin eso, «yes» se filtraría a las pruebas siguientes, como B8-h).
- `lastObservation()` proyecta a sus cuatro claves en vez de devolver la caché tal cual: `VoiceNumberIdentityTest:89` (intocable) fija esa forma exacta. Los dos hechos los lee solo `sharedIdentity()`.
- En la conciliación la condición 2 se evalúa con el número OBSERVADO (`sameNumber($o->phoneNumber(), visible)`, como dice el diseño); en `sharedIdentity()`, con `TWILIO_VOICE_FROM`. Con un importado ajeno, la conciliación dice `not_business_number` y el estado `observation_unverified`: las dos, no admitida (fijado en la prueba).
- Las pruebas nuevas valen con la constante cerrada y abierta (el motivo de la constante se comprueba aparte), para que la apertura no tenga que rehacerlas.

## 10. Hallazgos fuera de alcance
1. `UltronStudioIntegrationsProbeTest::test_el_enfriamiento_reutiliza…` (`:116-135`) es intermitente: no congela el reloj y viaja 299 s sobre el reloj real; si entre la primera sonda y el viaje pasa ≥ 1 s (la máquina con siete agentes), se cumplen los 300 s de `StudioTwilioProbe::COOLDOWN_SECONDS` y responde 201 en vez de 200. Mi rango no toca `app/Services/{UltronStudio,Twilio}` ni esa prueba. Arreglo propuesto (de su dueño): `travelTo` de un instante fijo al empezar.
2. Previo a la enmienda (B-1/B8-h): la observación del número no se ata al `TWILIO_VOICE_FROM` con el que se comparó, solo al id; tras cambiar `TWILIO_VOICE_FROM` sin conciliar, la puerta da `verified` hasta 2 h. La enmienda lo agrava en el caso de §7.1; la propuesta de allí lo cierra para la identidad compartida, no para el caso general.
3. Mientras el CRM no lea `sharedWithMessaging` (frontend, punto 2 del diseño), con la identidad admitida enseñará el error del número (`collidesWithMessaging = true`). Y `h8-voice-scope.sh:147` seguirá escribiendo «CHOCA» (devops, punto 3).
4. El diseño cita `DecisionTraceText.php` como de la telefonía; está en `app/Services/Outreach/Core/`.
5. La numeración «Enmienda 20 / E20-1» puede chocar con la rama `cierre/voz` si allí también se añade una enmienda: la renumera quien integre.
