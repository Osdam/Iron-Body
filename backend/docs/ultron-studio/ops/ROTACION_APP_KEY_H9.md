# Rotar `APP_KEY` con el Hito 9 desplegado (runbook corto)

OPS9-b · 10-11-2026 · contrato H9 L9-4, L9-29, L9-30, D9-11.3, D9-14; R-3 de SEC9 (`informe-security.md` §2) y m-5 de REV9.
Lo que sigue está leído del código del árbol integrado (rutas entre paréntesis); lo que no está comprobado se dice.

## 0. En una frase

**Rotar `APP_KEY` con una cadena de estrategias publicada deja la automatización comercial cerrada y sin vuelta
atrás por software hasta que exista una herramienta de re-anclaje que hoy no existe.** La ventana segura es la fase 1
(nada publicado, tablas vacías) o, en general, antes de la primera publicación de `ultron_studio_strategy_publications`.
Rotar es una decisión del propietario con el lead, no una operación rutinaria.

## 1. Qué deriva de `APP_KEY` en el Hito 9 (y qué le pasa al rotar)

Todo son HMAC-SHA256 con una **subclave** = HMAC(APP_KEY cruda, etiqueta). `APP_PREVIOUS_KEYS` (Laravel 12, `config/app.php`
`previous_keys`) solo sirve para **descifrar** con `Crypt`: no afecta a un HMAC. Nada de esto se «re-deriva solo».

| Pieza | Etiqueta | Dónde se guarda | Al rotar |
|---|---|---|---|
| `subject_ref` (seudónimo de persona) · `app/Services/Outreach/Facts/SubjectRef.php` | `outreach-subject/1` | `outreach_enrollments`, `outreach_jobs`, `outreach_decisions`, `outreach_outcomes` (`subject_ref`, inmutable por disparador) | El seudónimo que el CRM pide (`GET …/outreach/people/{subjectRef}/timeline`, derivado al vuelo con la clave nueva) **ya no casa** con el guardado: el historial anterior queda sin enlace (200 con lista vacía; nada se pierde, nada se enlaza). `subject_key` sigue en claro: la supresión 1581 (`outreach:suppress-subject`, `supresion-1581.sql` bloque H9) **sigue funcionando** |
| `phone_ref` (seudónimo de teléfono) · `app/Services/Outreach/Facts/PhoneRef.php` | `outreach-phone/1` | `outreach_jobs.phone_ref` (inmutable) y `outreach_budget_counters.scope = 'phone:<ref>'` (por `budget_day`) | El tope por teléfono (L9-30) no ve lo anterior: **un día** de ceguera como mucho (los contadores son por día de Bogotá). Rotar fuera de horario legal y sin trabajos vivos lo reduce a nada |
| Firma de la cadena de estrategias · `app/Services/Outreach/Core/StrategyChainSignature.php` | `ultron-studio-strategy-chain` | `ultron_studio_strategy_publications.signature` (solo inserción, inmutable) | **Toda** la cadena pasa a `signature_mismatch`: la cabeza no verifica (`OutreachPublishedStrategies::headVerified()`), `GET …/outreach/status` da `data.chain.head.verified = false`, la escalera niega todo con `chain_unreadable` (N5, `PolicyLadder.php`), `outreach:monitor` abre el **freno GLOBAL** `chain_unreadable` con incidente IRON GUARD (fuente `outreach`) **en cada pasada** (`OutreachMonitor::checkChain`) y el gobierno no publica nada nuevo: 409 `chain_unreadable` (`StudioStrategyPublishing.php`). La cadena es **global** (la cabeza es la de mayor `seq` de todas las estrategias): una estrategia nueva tampoco se publica. Falla cerrado: nada sale |

Lo que **no** se rompe con `APP_PREVIOUS_KEYS` bien puesto: lo cifrado con `Crypt` (el tique del canal SSE,
`StreamTicketService`, 5 minutos; los tiques del quiosco; las plantillas faciales de `TrainerFaceReference`, que se
descifran con la clave anterior y se vuelven a cifrar con la nueva al guardarse).

## 2. Lo que ya dependía de `APP_KEY` antes del Hito 9 (se rompe igual; cada pieza tiene su dueño)

No es de este runbook, pero quien rote tiene que saberlo antes, no después. Leído con `grep` (`hash_hmac` + `app.key`):

- **H5** `UltronPublishedConfig` (firma de `ultron_studio_publications`): el motor sella `fallback`; `ultron-studio:publication-check` ≠ 0.
- **H8** `VoicePublishedConfig` (cadena de voz) y `VoicePhone` (huellas de teléfono de la voz: consentimientos, contactos).
- **H6/H7** `App\Support\Privacy\PhoneFingerprint` (`ultron_studio_canary_numbers.phone_hash`: la coherencia con el motor), `StudioVoiceProfiles` (huella del teléfono), `StudioIntegrationConfig` (huellas de la configuración de integraciones: el monitor del H7 verá `config_drift`).
- Fuera del Studio: `Member::access_hash` (se calcula al crear el socio), `MetaAdEntity` (ids derivados), `MemberRegistrationController`, `Workforce\Secrets`, `UltronDecideToken`, `AuditSanitizer` (huella de IP, inocua). **Por confirmar con `backend`** si alguno se verifica recalculando (entonces se rompe) o solo se compara con lo guardado (entonces no).

## 3. Regla

1. **No rotar** mientras `ultron_studio_strategy_publications` tenga filas, salvo compromiso de la clave con decisión escrita del propietario (§5).
2. Si hay que rotar, **primero** la PARADA GLOBAL y el nivel 0, **después** la clave, **nunca** al revés: con la clave nueva y el programador encendido, el monitor abriría frenos e incidentes cada 10 minutos.
3. Nunca re-firmar a mano, tocar `signature`/`effective_json` ni borrar filas de la cadena: sería falsificar la evidencia que el propio diseño protege (solo inserción, `ultron_studio_forbid()`).

## 4. Cómo se rehace con seguridad (lo que hay y lo que falta)

| Qué | Cómo | Estado |
|---|---|---|
| El historial por seudónimo (`subject_ref`) | Rederivar en lote **desde `subject_key`** con la clave nueva, como el dueño, con los disparadores de inmutabilidad apagados en una transacción (`subject_ref` es inmutable para la aplicación) | **No existe la orden** (`outreach:rederive-refs`, propuesta de OPS9-a §7 para B9-run, pendiente de diseño). Hasta entonces: el historial anterior queda sin enlace y se documenta; la supresión 1581 no depende de ello |
| Los contadores por teléfono (`phone_ref`) | Nada: son por día; al día siguiente la clave nueva manda | Hecho por diseño; rotar sin trabajos vivos |
| La cadena de estrategias | **Re-anclar**: una génesis nueva por «época de clave» (una columna `key_epoch` o una marca de génesis que la verificación respete desde la primera fila de la época; S9 + B9-run + DB9) o aceptar que la cadena vieja queda como evidencia muerta y **recrear** las estrategias (uuids nuevos) sobre una tabla vacía de publicaciones, cosa que hoy tampoco se puede (la cabeza global manda) | **Pendiente de decisión** (lead, con SEC9 y DB9): es una enmienda del contrato, no un paso de este runbook |

## 5. Procedimiento, si no hay más remedio (clave comprometida)

Entorno: producción (VPS, como root). Impacto: todo el H9 cerrado hasta el re-anclaje; el motor de ULTRON (H5) sella
`fallback`; la voz del H8 pierde su cadena. Vuelta atrás: **la clave anterior** (copia previa 600 del fichero de
entorno) + `config:cache` + `queue:restart`: lo re-derivado con la clave nueva volvería a no casar, así que o se rota
una vez y se re-ancla, o no se rota.

1. **Decisión escrita** del propietario con el lead (incluye aceptar §1 y §2). Respaldo fresco (`pg_dump -Fc`, 700/600).
2. **Cerrar el H9** con el mando, en este orden (cada orden deja copia previa en `/root`):
   ```bash
   bash /root/h9-outreach-scope.sh parada-global --motivo="rotacion de APP_KEY <fecha>"   # freno global, ≤ 60 s
   bash /root/h9-outreach-scope.sh apagar                                                  # nivel 0: maestra, modo, programador, monitor, canales
   bash /root/h9-outreach-scope.sh publicar off && bash /root/h9-outreach-scope.sh gobierno off && bash /root/h9-outreach-scope.sh stream off
   bash /root/h9-outreach-scope.sh estado                                                  # 0 tareas outreach:*, frenos globales ≥ 1
   ```
3. **Inventario antes** (solo lectura; guardarlo en el acta):
   ```bash
   sudo -u postgres psql -X -Atq -d ironbody -c "SELECT (SELECT count(*) FROM ultron_studio_strategy_publications)||' publicaciones · cabeza seq '||coalesce((SELECT max(seq)::text FROM ultron_studio_strategy_publications), '-')||' · '||(SELECT count(*) FROM outreach_jobs WHERE status IN ('planned','claimed','dispatching'))||' trabajos vivos · '||(SELECT count(*) FROM outreach_enrollments WHERE redacted_at IS NULL)||' inscripciones con seudónimo';"
   sudo -u www-data php artisan ultron-studio:publication-check; echo "publication-check: $?"
   ```
   Con trabajos vivos: esperar a que venzan o cancelarlos por la vía del contrato; no rotar con trabajos vivos.
4. **Rotar la clave** (no la cubre ningún mando: a mano, con el patrón de `el-verificar.sh`): generar la nueva con
   `php artisan key:generate --show` **a un fichero 600 de root, nunca a la pantalla ni al historial**; escribir
   `APP_KEY=<nueva>` y `APP_PREVIOUS_KEYS=<anterior>` en el fichero de entorno con copia previa 600 y escritura atómica
   que conserve dueño y modo; `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'`; `chmod 600
   bootstrap/cache/config.php` como www-data; `sudo -u www-data php artisan queue:restart -q` (los workers se
   reinician y leen la clave nueva); destruir el fichero de la clave con `shred -u`.
5. **Comprobar lo esperado** (es lo que dice §1, no un fallo del despliegue):
   - `GET /api/admin/ultron-studio/outreach/status` → `data.chain.head.verified = false`, `result = signature_mismatch` (si había filas) y `data.config.enabled = false`;
   - `sudo -u www-data php artisan outreach:monitor` con la maestra apagada sale con 0 y «apagado» (no abre nada: §3.2);
   - `publication-check` del H5 y `voice:doctor` del H8: los dueños de esos hitos deciden su re-publicación;
   - el canal SSE y el quiosco siguen (Crypt con la clave anterior).
6. **Rehacer** (§4): rederivar `subject_ref` cuando exista la orden; re-anclar la cadena cuando exista la enmienda. Solo
   entonces: `gobierno on`, publicar de nuevo, `sombra on` y **soltar el freno global desde el CRM a cuatro ojos**
   (`POST …/outreach/brakes/{uuid}/release`, otra persona, con nota): el mando nunca suelta frenos.
7. **Acta**: fecha UTC, decisión, inventario antes/después, copias previas, y las dos piezas pendientes con su dueño.

## 6. Pendientes con dueño (antes de que haga falta rotar)

| # | Qué | Dueño |
|---|---|---|
| R-1 | `outreach:rederive-refs`: rederivar `subject_ref`/`phone_ref` en lote desde `subject_key` con la clave nueva (dueño de la base, disparadores apagados en una transacción, acta en el libro) | B9-run + DB9 |
| R-2 | Re-anclaje de la cadena de estrategias por época de clave (enmienda del contrato: génesis nueva verificable, nunca re-firma) | lead → S9 + B9-run + DB9 + SEC9 |
| R-3 | Confirmar qué piezas fuera del Studio (§2) se verifican recalculando el HMAC | backend |
| R-4 | Ensayar este runbook en el banco (`banco-h9`) con una cadena publicada (fase 2 del banco) antes de la fase 2 en producción | OPS9 |
