# Hito 5 · E2E con la pila real (A10.5 / fila «E2E» de A12): gobierno y versionado

**Autor:** tester (E2E) · **Fecha:** 25-10-2026 (14:45–17:00 UTC; mañana de Bogotá) · **Refs probadas:** backend `2bc053b` + CRM `e7ba41b` (ramas `feat/ultron-studio-h5`) · **Línea base:** `0366009` + `5c1dbd7` (`origin/main`) · **Sin commit ni push; nada de producción; ninguna rama cambiada.**

Contrato: `ULTRON_STUDIO_H5_CONTRACT.md` (A1–A13, Enmienda 1). Formas y orden de puertas: `informe-api.md` §1–§3 y §7. Arnés: `Iron-Body_Front/e2e/ultron-studio/` (su `README.md` lleva el uso, las piezas y las 30 trampas pagadas).

---

## 0. En una pantalla

| Ejecución | Refs | Resultado | Lo rojo |
|---|---|---|---|
| **R1″ · definitiva** | `2bc053b` + `e7ba41b` | **404/408** | B-1 ×3 (backend) · C-1 (CRM) |
| R1′ | `2bc053b` + `e7ba41b` | 402/408 | B-1 ×3 · C-1 · H4 ×2 (A-8, del arnés, corregido después) |
| R1 (antes de añadir P0s, D-10 y más muestras de tiempo) | `2bc053b` + `e7ba41b` | 365/366 | C-1 |
| R4 · commit posterior del CRM | `2bc053b` + `33821e4` | 405/408 | B-1 ×3 (C-1 ya no sale) |
| R2 · línea base, mismo arnés | `0366009` + `5c1dbd7` | **131/131** | — |
| R3 · CRM nuevo con backend viejo | `0366009` + `e7ba41b` | **131/131** | — |
| Mutaciones (`mutations.sh`, pila en pie) | `2bc053b` + `e7ba41b` | controles 40/40, 27/27, 30/30, 104/104 · **9/9 tumbadas** | — |

**Hallazgos:**
- **B-1 · backend · media:** `ULTRON_STUDIO_PUBLISHING_ENABLED=off|no|disabled` ABRE la llave del Studio. A13 dice «valor inválido → `false`». Es M-1 de `security` y AS-1 de `api-security-specialist` (que `informe-devops-h5.md` pide cerrar o aceptar antes de la fase 2); aquí queda confirmado en la pila real: `status.publishing` responde `available: true` («Se puede publicar en canario…») y `POST publish` pasa la llave (cae después en `lab_verdict_required`). Con `false` la llave cierra y con `true` abre, como debe (P0 y P1 de la misma spec).
- **C-1 · CRM `e7ba41b` · menor:** con la puerta del laboratorio `waived`, el diálogo de publicar dice dos veces que está dispensada. Lo corrige `33821e4` (R4 en verde). **Para el lead:** si el CRM que se despliega es `e7ba41b`, C-1 va con él; con `33821e4`, no.
- **Arnés:** 6 defectos, todos corregidos, más una adaptación pedida y una observación (§6, A-1…A-8). El más serio: `mutations.sh` no podía haber corrido nunca, y una vez arreglado, OPcache de `php -S` seguía sirviendo una mutación ya restaurada.
- **Ninguno bloqueante** del backend ni del CRM en los flujos del encargo: publicar, el sello del motor, la evidencia, promover, revertir, Legacy, los cuatro ojos, el Administrador y las fugas se comportan como dice el contrato.

**Lo que NO se verificó** (§7): revertir una PUBLISHED que restaura otra RETIRED, `restore_integrity_mismatch`, `publication_head_invalid` provocado (solo bajo la mutación M9), carreras de dos sesiones, el 429 de la evidencia, la pila de producción (`config:cache`), teclado y 390 px, y un p95 del commit con muestra suficiente.

---

## 1. Cómo se levantó la pila

Máquina: macOS 26.6.2, 8 CPU, 8 GB; PHP 8.3.33, Node 24.20.0, PostgreSQL 14.24 (clúster desechable `~/.ironbody-pg14-disposable`, `127.0.0.1:55432`, ya en marcha: el arnés lo usó sin pararlo), Chrome 155. Carga de 2 a 20 según el momento (otros agentes con sus suites en paralelo; ningún PHPUnit mío en el worktree del backend).

```bash
cd Iron-Body_Front     # _worktrees/thermal-receipts, rama feat/ultron-studio-h5 (sin tocar la rama)
# pila de desarrollo en pie (F, G, H4 y turno); contra ella, h5-governance y las mutaciones
E2E_HOLD=1 E2E_TIMEOUT_SCALE=2 E2E_DB=ultron_studio_e2e_h5dev bash e2e/ultron-studio/run.sh 2bc053b e7ba41b f-versions g-inspector-compare h4-lab-fake ultron-turn
E2E_TIMEOUT_SCALE=2 bash e2e/ultron-studio/mutations.sh ~/.cache/ironbody-e2e/ultron-studio/run-20261025T144720Z-2bc053b-e7ba41b
# ejecuciones completas, cada una con su base (ninguna colisiona con h5_api, h5_lead ni h5_races)
E2E_TIMEOUT_SCALE=2 E2E_DB=ultron_studio_e2e_h5def   bash e2e/ultron-studio/run.sh 2bc053b e7ba41b   # R1″ (R1: _h5full, R1′: _h5final)
E2E_TIMEOUT_SCALE=2 E2E_DB=ultron_studio_e2e_base    bash e2e/ultron-studio/run.sh 0366009 5c1dbd7   # R2
E2E_TIMEOUT_SCALE=2 E2E_DB=ultron_studio_e2e_cross   bash e2e/ultron-studio/run.sh 0366009 e7ba41b   # R3
E2E_TIMEOUT_SCALE=2 E2E_DB=ultron_studio_e2e_h5crm2  bash e2e/ultron-studio/run.sh 2bc053b 33821e4   # R4
```

Lo que monta `run.sh` (diseño sin cambios; ver README): instantánea del backend con `git archive` desde la raíz del repo + `vendor` **copiado** (comprobado en cada ejecución que `App\` sale de la instantánea), `.env` desde `.env.example` validado con Dotenv, base nueva con 315 migraciones, `import-baseline`, 11 escenarios del laboratorio y la semilla (conversación canaria creada con `MetaLeadService`), `artisan serve --no-reload` con 8 workers de `php -S` y `PGGSSENCMODE=disable`, los tres workers reales (`lab`, `whatsapp`, `commercial`), proxy :8080, `ng serve` :4300, simuladores de OpenAI (:8093) y n8n (:8094) y la valla de red (:8099; su sonda quedó cazada en cada ejecución). Parada por PID de nietos, hijo y padre: al terminar cada ejecución los 6 puertos quedaron libres.

**Llaves.** El `.env` base las escribe ahora explícitas con los valores de producción (`ULTRON_STUDIO_PUBLISHING_ENABLED=false`, `MARKETING_ULTRON_STUDIO_CONFIG=off`, `ULTRON_STUDIO_PUBLISH_LAB_GATE=required`, 24 h / 3 turnos, ventana `07:00-19:00`). `h5-governance` las cambia por fases reiniciando servidor y workers (`specs/h5-stack.sh`: los workers solo leen el `.env` al arrancar; 11 reinicios por ejecución, 2,5–3,5 s cada uno) y al salir repone el `.env` original (copia byte a byte en `env.original`). La conversación canaria del motor es `marketing.ultron.canary_conversation_id` ← `MARKETING_ULTRON_CANARY_CONVERSATION_ID` (`config/marketing.php:83`).

---

## 2. Escenarios del encargo (spec nueva `specs/h5-governance.cjs`)

| Fase | `STUDIO_CONFIG` | Canario del motor | Puerta lab | Qué prueba |
|---|---|---|---|---|
| P0 | `off`, publicar apagado (`.env` base) | C1 (semilla) | required | Legacy `off`, 0 sellos, 409 `publishing_disabled` |
| P0b | `off`, publicar encendido | C1 | required | 409 `engine_not_reading_versions`, turno sin sello |
| P0s | `canary`, publicar = `off`/`no`/`disabled` | C1 | required | A13: valor inválido → `false` (B-1) |
| P1 | `canary` | CA (nueva) | required | vista previa, puerta del laboratorio, cuatro ojos, Administrador, laboratorio `openai`, publicar y revertir desde el CRM, turnos, evidencia |
| P3 | `all` | CB (nueva) | waived | inmutabilidad de la cadena, entrada «de ayer», evidencia suficiente, promover desde el CRM |
| P4 | `all` | — (sin canario) | waived | deriva, `applied` en TODAS las conversaciones, D-10 |
| P4b | `canary` | — | waived | `out_of_scope` |
| P5w | `all` | CB | waived | ventana cerrada (`13:00-14:00` Bogotá) |
| P5 | `all` | CB | waived | otra versión en canario sobre la publicada, desde el CRM; revertir; Legacy desde la barra |
| P6 | `.env` original | C1 | required | `off`, 409, 0 sellos |

Versiones (creadas por la API, enviadas y aprobadas por la revisora): **A** (tono «profesional»; la autora), **B** (línea base; creada por una cuenta CON `ultron.publish`, para que los cuatro ojos se prueben por identidad y no por permiso), **C** (tono «entusiasta» y sin emojis; la autora) y la de F. Conversaciones nuevas **CA** y **CB**, creadas con los servicios de la ingesta y con ids ≥ 987654321 para poder buscarlos en las respuestas. Los turnos son REALES: entrante firmado como Meta → worker `whatsapp` → worker `commercial` → simulador de n8n → `ai/decide` → `ai/commit`. Resultados de R1′ (ids exactos en `<run>/results.jsonl`, evidencia en `<run>/evidence/h5-governance.json`):

| # | Escenario | Resultado | Evidencia (comprobación → valor real) |
|---|---|---|---|
| a1 | `engine.publication` en Legacy (API y CRM) | ✓ | P0 `state: off, envScope: off, head: null`; P1 `state: legacy, envScope: canary, canary/rest: null, drift: []`; barra `data-estado-publicacion-sello="legacy"`, `data-vigente="legacy"`, `data-llave="canary"` |
| a2 | Vista previa de una APPROVED: requisitos y `previewHash` estable | ✓ | dos lecturas de la publicadora y una de la lectora → la misma huella (64 hex); 4 `must_match` `ok`; `noEffect = ["persona.tone"]`; `blocking = ["lab_verdict_required"]`, el resto de requisitos `ok`; tras el veredicto la huella CAMBIA (Q-4: `labGate.latest` entra en ella) |
| a3 | Publicar con `previewHash` y `acknowledgedNoEffect` exactos → 201, CANARY, cadena con 1 entrada | ✓ (desde el CRM) | diálogo «publicable», 10 requisitos `data-ok=true`, una casilla `persona.tone`, envío deshabilitado hasta marcarla; **una** sola petición en el proxy, `201`; A en CANARY; «la cadena gana UNA entrada (0 → 1: cadena con 1 entrada)»; entrada `seq 1`, `lab_gate {mode: required, runUuid, verdictId, promptVersion}`, `acknowledged.noEffect = ["persona.tone"]`; `version.published` con la entrada y la huella efectiva |
| a4 | Idempotencia | ✓ | mismo `clientRequestId` y cuerpo (leídos de la fila) → 200 `replayed: true` con la entrada de la primera vez; otra nota → 409 `client_request_reused`; la cadena sigue en 1 |
| a5 | `publication-check` sale 0 | ✓ | P1 y P3 `exit 0, ok: true`; P4 (deriva) `exit 1, problems: ["drift"]` |
| b1 | Turno REAL en la canaria → `metadata.studio` `applied` con el uuid de la versión | ✓ | P1: `{v:1, env:"canary", state:"applied", entry:<seq 1>, seq:1, part:"canary", version:<A>, contentHash:<A>, reason:null}` (las 9 claves exactas de A4.5) |
| b2 | Turno en OTRA conversación con `canary` | ✓ | C1 con el canario en CA: el entrante se guarda, **0 eventos** hacia n8n, ningún turno, ninguna fila con sello; sí la fila de contabilidad del enrutador (1 fila: el filtro mira filas de verdad) |
| b3 | … y el sello según el alcance | ✓ | P4 (sin canario en el motor): C1, CA y CB salen `applied`, parte `rest`, versión B; P4b (`canary` sin canario): `out_of_scope` con `entry/seq` de la cabeza y sin parte ni versión |
| c | La evidencia cuenta esos turnos | ✓ | P1: `turns 1 = stamped 1 = byState.applied 1`, `unstamped 0`, umbrales `{1, 1}`, `hours 0` → `failing: ["hours"]`, acta `exitCode 0, total 0`, sin frenos ni deriva, `signatureOk true`; P3: desde la entrada sembrada, `hours ≥ 24`, `turns 1 = stamped 1`, `passes: true`, `failing: []` |
| d1 | La cadena no admite UPDATE (comprobado y escrito) | ✓ | psql: `UPDATE … created_at` → `ERROR: ultron_studio: la cadena de publicaciones es de solo inserción (UPDATE en ultron_studio_publications)`; igual DELETE y TRUNCATE; por la aplicación: el modelo lanza `LogicException`, el constructor de consultas `QueryException` SQLSTATE `P0001` (UPDATE y DELETE); `created_at` sin cambios |
| d2 | Entrada de publicar «de ayer» con las clases reales | ✓ | `h5-seed.php publish-backdated` llama a `StudioPublications::publish()` (todas sus puertas, `D::declaration` + `UltronPublishedConfig::signatureFor`, transición y libro) con el reloj del proceso 25 h atrás → `created_at` de hace 25 h, B en CANARY, puerta `waived`; el libro con la misma fecha y la entrada |
| d3 | Promover con `evidenceHash` → PUBLISHED, 2 entradas, `applied` en todas con `all` | ✓ (desde el CRM) | diálogo de evidencia «pasa», ninguna medida que decide en rojo; B PUBLISHED con **sus 2 entradas** (publish sembrada + promote); la de promover declara `{canary: B, rest: B}` con la MISMA parte (Q-3) y guarda la evidencia; `version.promoted`; barra «en todas»; el turno siguiente `applied` con la entrada de promover; P4 `applied` en las tres conversaciones |
| e1 | Revertir con motivo → ROLLED_BACK y la cadena vuelve | ✓ | P1 desde el CRM: A ROLLED_BACK, entrada `rollback` con el motivo y `{canary: null, rest: null}`, `version.rolled_back`; el turno siguiente sale `legacy` con la entrada de la reversión. P5 por la API: C (en canario sobre B publicada) ROLLED_BACK → la cadena vuelve a `{canary: B, rest: B}` y el turno siguiente sale `applied` con B |
| e2 | Volver a Legacy; `already_legacy` | ✓ | P5 desde la barra: 201, B → RETIRED, `system.publication_legacy` y `version.retired {by: legacy}`, la barra deja de ofrecer Legacy, el turno sale `legacy`; repetir → 409 `already_legacy` (también en P1, tras revertir el único canario); el historial de la barra lista las 7 entradas desde la cabeza |
| f1 | `required` SIN veredicto → 409 de la puerta | ✓ | 409 `lab_verdict_required`, `latest: null`; el diálogo del CRM sale «bloqueada» con `labGate data-ok=false` y el envío deshabilitado |
| f2 | Con un veredicto `approved` sobre la misma huella → pasa | ✓ (en `openai`, ver O-1) | ejecución `openai` de 11 casos contra el SIMULADOR: 22 peticiones, 0 violaciones, `completed`, `pass 11`; la autora recibe 403 `four_eyes_required` + `run.verdict_denied`; la revisora emite `approved` → publicar pasa |
| g | Llaves apagadas → 409 `publishing_disabled` y 0 sellos | ✓ | P0 y P6: 409 `publishing_disabled` (auditado `version.publish_refused`); P0b: 409 `engine_not_reading_versions`; 0 sellos en los turnos de `ultron-turn`, de P0b y en los 9 de P6 aunque la cadena tenga 7 entradas |
| h | Cuatro ojos; Administrador sin llave | ✓ | quien creó B (con `ultron.publish`) → 403 `four_eyes_required` al publicar (P1, P3) y al promover (P3), con `version.four_eyes_denied {action}`; el Administrador → 403 `forbidden`; en el CRM, sin «Publicar en canario» en una APPROVED, sin «Revertir» en la CANARY y sin «Volver a Legacy» en la barra, con el motivo visible |
| i | Ninguna respuesta lleva el id canario, `effective_json` ni `signature` | ✓ | 228 respuestas barridas (111 de la spec, 114 del CRM en Chrome, 3 del CLI): 0 con los ids de CA/CB, 0 con las claves `effective_json`/`signature`/`canary_conversation_id`, 0 con una firma o el texto firmado de la base; el detector caza las 4 fugas sembradas a propósito |

Además (A5, A9, A12): `no_effect_not_acknowledged` (de menos y de más), `preview_stale` con la vista previa nueva, `scope_not_allowed` (auditado), `outside_publication_window`, `invalid_transition` al promover una PUBLISHED; **D-10 en la pila real** (sin canario configurado, publicar da `engine_value_mismatch` en `governance.canaryOnly`, nunca `engine_has_no_canary`); la puerta `waived` sale en rojo (`is-alerta`) y obliga a reconocer las dos casillas de C; la deriva de `governance.canaryOnly` en el estado, la barra y el CLI; la huella de las 15 tablas del motor y las colas idéntica antes y después de CADA escritura del Studio (las siete y el 409); y un **oráculo independiente** de la cadena: para cada entrada, `sha256(effective_json) = effective_hash` y la firma recalculada en Node con la `APP_KEY` de la instantánea según la Enmienda 1 (Q-1) coincide con la guardada; `seq` contiguo y enlazado (7/7 en R1′).

---

## 3. Ejecución completa y comparación con la línea base

R1″ (definitiva, `2bc053b` + `e7ba41b`), salida real del resumen de `run.sh` (consola entera en `<run>/console.out`); solo abrevio con «…» el detalle de las dos filas de P0s que repiten el de la primera:

```
== Resumen
   ✓ f-versions: 40/40
   ✓ g-inspector-compare: 25/25
   ✓ h4-lab-fake: 27/27
   ✓ ultron-turn: 30/30
   ✗ h5-governance: 272/276
       ✗ P0s: ULTRON_STUDIO_PUBLISHING_ENABLED=off con el motor en `canary` mantiene publicar cerrado (A13: valor inválido → false; M-1/AS-1) — {"publishing":{"available":true,"code":null,"message":"Se puede publicar en canario: el motor lee la cadena solo en la conversación canaria."},"publish":{"status":409,"code":"lab_verdict_required"}}
       ✗ P0s: ULTRON_STUDIO_PUBLISHING_ENABLED=no con el motor en `canary` mantiene publicar cerrado (A13: valor inválido → false; M-1/AS-1) — …
       ✗ P0s: ULTRON_STUDIO_PUBLISHING_ENABLED=disabled con el motor en `canary` mantiene publicar cerrado (A13: valor inválido → false; M-1/AS-1) — …
       ✗ P5 · CRM: el motivo de la puerta dispensada se dice UNA vez (sin repetir el mensaje del servidor) — report: se cumple. Dispensada en el entorno: se publica sin exigir un veredicto aprobado y queda escrito en la cadena. La puerta del laboratorio está dispensada en el servidor (waived): queda escrito en la publicación.
   ✓ cierre: 10/10
   TOTAL 404/408 (4 fallos)
== Duración: 475 s
== Parada
   procesos parados; registros en /Users/alejodev/.cache/ironbody-e2e/ultron-studio/run-20261025T162601Z-2bc053b-e7ba41b/logs
```

En la misma ejecución: `ng serve` listo a los 72 s; 230 respuestas barridas sin fugas (spec 111, CRM 116, CLI 3).

R1′ (misma pareja de refs, 16:10 UTC, antes de corregir A-8): `f-versions 40/40 · g 25/25 · h4 25/27 · turno 30/30 · h5 272/276 · cierre 10/10 · TOTAL 402/408`, en 398 s. R4 (`2bc053b` + `33821e4`): `40/40 · 25/25 · 27/27 · 30/30 · h5 273/276 · 10/10 · TOTAL 405/408`. R2 (`0366009` + `5c1dbd7`) y R3 (`0366009` + `e7ba41b`): `39/39 · 25/25 · 27/27 · 30/30 · 10/10 · TOTAL 131/131` las dos.

**Comparación por nombre R1 ↔ R2** (`results.jsonl` de las dos): 128 comprobaciones están en las dos y **ninguna cambia de resultado**. Solo en la base: las 2 de `f-versions` del modo F (aviso «no lee nada» y el botón deshabilitado `data-publicar-version`, que el CRM H5 ya no pinta con un backend H5) y el recuento del proxy (su nombre lleva el número). Solo con H5: sus 3 equivalentes de `f-versions` (aviso «solo anota la versión publicada», la revisora sin botón y con `data-motivo-accion="publish"`, 422 con el cuerpo vacío por D-13) y `h5-governance`.

**R3 (compatibilidad):** el CRM del Hito 5 contra el backend anterior da exactamente lo de la línea base, 131/131 con los mismos ids: el aviso «no lee nada» y el «Publicar» deshabilitado con el motivo del servidor (lo que promete `informe-front-integracion.md` §0). Desplegar el CRM antes que el backend no rompe F/G/H4.

---

## 4. Pendientes del arnés (informe-e2e-arnes §4)

1. **`mutations.sh`: ejecutado.** No podía haber corrido nunca: el ancla de M1 aparece dos veces en `StudioVersions.php` (el propio `mutate` se niega) y `"«$exp»"` revienta en bash 3.2 con `set -u` (dejó M1 aplicada en la instantánea: la restauré y comparé con `git show 2bc053b:…`). Arreglado eso, **OPcache de `php -S` siguió sirviendo M1 después de restaurarla**: el SAPI `cli-server` usa OPcache aunque `opcache.enable_cli` esté apagado, valida por `mtime` en segundos, y la copia y la mutación se escriben en el mismo segundo; la control de F salió con la autora aprobando lo suyo. Ahora hay una control sin mutar de cada spec (si una sale roja no se muta nada), `touch` y reinicio de servidor y workers tras mutar y tras restaurar PHP (los workers de cola también guardan las clases en memoria), una trampa de salida que lo restaura todo y una comprobación final de que no queda `.e2e-orig` ni `E2E-MUTANTE`. Resultado (pila `run-20261025T144720Z-2bc053b-e7ba41b`, carpeta `mutations/`; con la spec h5 de ese momento, antes de P0s, D-10 y A-8, que no tocan lo que miran las mutaciones):

| | Mutación | Spec | Cae en |
|---|---|---|---|
| control | sin mutar | F 40/40 · H4 27/27 · turno 30/30 · h5 (P0–P1) 104/104 | — |
| M1 | cuatro ojos de aprobar no niega | F 12/15 | 403 `four_eyes_required` (salió 200) y `version.four_eyes_denied` (0) |
| M2 | veredicto en `fake` admitido | H4 24/27 | 409 `fake_mode` (200) y «ningún veredicto» (1) |
| M3 | n8n no verifica el HMAC | turno 29/30 | «HMAC malo → 401» (200) |
| M4 | el evento lleva el teléfono | turno 28/30 | «evento mínimo» (`["phone"]`, los dos turnos) |
| M5 | el CRM deja aprobar sin reconocer | F 39/40 | «deshabilitado sin reconocer los avisos» |
| M6 | el motor no sella | h5 100/104 | sello `null`, evidencia `stamped 0`, el turno tras revertir sin `legacy` |
| M7 | publicar sin cuatro ojos | h5 103/104 | quien creó B recibe 409 `lab_verdict_required` en vez de 403 (pasó la puerta) |
| M8 | «Publicar en canario» sin mirar `actions` | h5 103/104 | el Administrador ve el botón en una APPROVED |
| M9 | la cadena firmada con sha256 | h5 83/94 | cabeza `signatureOk: false`, sello `fallback/signature_invalid`, `publication-check` 1 (`head_invalid`), barra `fallback`, evidencia `failing [hours, stamped, signatureOk]`; revertir desde el CRM → **409** en el proxy (componer sobre una cabeza que no verifica; el cuerpo no quedó registrado), el diálogo no se cierra y la spec termina por tiempo. El barrido de fugas también salta, por un artefacto de la mutación: con sha256 la «firma» es igual a `effectiveHash`, que sí viaja en las respuestas |

2. **Caché de la instantánea del CRM (ruta estable por commit): probada.** (a) Re-extracción: antes de R1 añadí una marca a `crm-e7ba41b65e48/src/…/studio-versions-panel.ts` y un `sobrante.ts.e2e-orig`; tras el paso 2 de R1 el fichero es byte a byte el de `e7ba41b` y no queda ningún `.e2e-orig`. `tar -x` sobrescribe; un fichero NUEVO que dejara una mutación sí sobreviviría (hoy ninguna crea ficheros). (b) Velocidad: **la caché no acelera `ng serve`**. Prueba A/B en la misma ventana y con la misma carga (5–6,6), con `.angular` y apartándolo: con caché 62,7 s y 58,9 s; sin caché 57,2 s y 56,2 s. (Primera compilación en la ruta nueva, con carga 2,4: 38,8 s; R1, con carga ≈13: 58,1 s.) La ruta estable no hace daño (los puertos impiden dos ejecuciones sobre ella a la vez), pero la razón que la justificaba no se sostiene: lo que pesa es la carga de la máquina.
3. **Las tablas reales de la huella: corregido.** `marketing_agent_pauses` no existe en ninguna de las refs; la pausa del agente es la fila única de `marketing_agent_settings` (`MarketingAgentSwitch`, modelo `MarketingAgentSetting`). `ENGINE_TABLES` la nombra y `cierre` exige ahora que existan las 17 entradas de la huella: una tabla ausente daba `null` en las dos tomas y pasaba por «idéntica». En todas las ejecuciones: «las 17 tablas de la huella existen».
4. **Orden 409/403 del veredicto en `fake`: no es un defecto.** H4 §A6 lista las dos negativas (ejecución que no admite veredicto → 409 `run_not_reviewable`; el autor → 403 `four_eyes_required`) sin fijar su orden. `StudioLabVerdicts::record` comprueba primero que sea revisable (líneas 51–54) y después los cuatro ojos (57–63). En `openai` los cuatro ojos SÍ se aplican: en P1 la autora recibe 403 `four_eyes_required` y queda `run.verdict_denied {reason: four_eyes_required}`; la revisora emite `approved`.

---

## 5. Tiempos del commit por llave (A4.7, indicativo)

`ms` del paso `commit` del simulador de n8n (ida y vuelta de `POST ai/commit`):

| Llave | R1′ | R4 | R1″ |
|---|---|---|---|
| `off` | n=10 · mín 57 · mediana 82 · máx 126 | n=10 · mediana 108 · máx 127 | n=10 · mediana 134 · máx 259 |
| `canary` | n=3 · 149, 685, 875 | n=3 · mediana 395 · máx 464 | n=3 · mediana 616 · máx 1395 |
| `all` | n=16 · mín 141 · mediana 166 · máx 449 | n=16 · mediana 166 · máx 505 | n=16 · mediana 212 · máx 552 |

La misma llave varía entre ejecuciones tanto como entre llaves (la mediana de `off` va de 82 a 134 ms según la carga del momento). Con el simulador, un turno entero dura 1–2 s, no los 7–13 s de producción. **No es un p95**: las muestras son pequeñas, los turnos de `canary` son los tres primeros tras un reinicio (arranque en frío) y los de `off` caen casi todos en P6, con la pila caliente. Lo que se puede decir: el commit con la llave en `all` está en las mismas centenas de milisegundos que con `off`; atribuir la diferencia de medianas a la lectura de la cadena exigiría una muestra intercalada y mayor.

---

## 6. Hallazgos

| Id | Severidad | Dónde | Qué | Evidencia | Estado |
|---|---|---|---|---|---|
| B-1 | **Media** (backend `2bc053b`; = M-1 de `security` y AS-1 de `api-security-specialist`) | `config/ultron_studio.php:63` (`(bool) env(...)`), `StudioPublishing.php:71-74` (`flagEnabled()`) | `ULTRON_STUDIO_PUBLISHING_ENABLED` con `off`, `no` o `disabled` abre la llave del Studio; A13 pide «valor inválido → `false`» | R1′ y R4, P0s, con el motor en `canary`: `status.publishing = {available: true, code: null, message: "Se puede publicar en canario: el motor lee la cadena solo en la conversación canaria."}` y `POST publish` (con una huella falsa, para no publicar nada) → 409 `lab_verdict_required`, es decir, PASÓ la llave; la cadena no cambió | Abierto (backend). Las comprobaciones de P0s se pondrán en verde cuando se corrija |
| C-1 | Menor | CRM `e7ba41b`, `studio-publication-text.ts` (`requirementRows`) | Con la puerta `waived`, la fila del laboratorio repite que está dispensada: el texto del CRM («Dispensada en el entorno: …») y el `message` del servidor («La puerta del laboratorio está dispensada en el servidor (waived)…»). La fila sí sale en rojo (`is-alerta`), que es lo que exige A5.1 | R1 y R1′, P5: `report: se cumple. Dispensada en el entorno: se publica sin exigir un veredicto aprobado y queda escrito en la cadena. La puerta del laboratorio está dispensada en el servidor (waived): queda escrito en la publicación.` | Corregido en `33821e4` (con mensaje del servidor, solo ese); R4 en verde |
| A-1 | Alta (arnés) | `mutations.sh` | Nunca había corrido: ancla de M1 ambigua, `$exp»` en bash 3.2, sin restauración si muere, OPcache de `php -S` sirviendo el mutante restaurado, sin controles | §4.1 | Corregido; 9/9 |
| A-2 | Media (arnés) | `mock-openai.cjs` | El juez simulado buscaba `criteria[].id`/`rules[].code`, y la entrada real va en castellano (`escenario.criterios[].id`, `reglas[].codigo`; `StudioLabPrompts::judgeTargets`). El backend lo rechazó bien: `invalid_output` dos veces seguidas → `stopped/provider_errors`. El simulador nunca se había ejercitado en `openai` | primera pasada de h5 en la pila de desarrollo: casos 1–2 `review`, 3–11 `skipped`; `laravel.log`: `ultron_studio.lab.provider_call … class: invalid_output` | Corregido (`__stats.unknownJudgeInput` cuenta entradas que no reconoce) |
| A-3 | Media (arnés) | `g-inspector-compare` | Leía `aria-expanded` UNA vez tras elegir la capa y, si no era `true`, pulsaba «Inspector», que por encima de 1280 px está en `display:none` → timeout. Con `e7ba41b` cayó (8/9); el inspector sí se abre (sonda en Chrome: `true` a los 1,5 s) | pila de desarrollo, primera pasada | Corregido (espera al estado); 25/25 en todas las ejecuciones después |
| A-8 | Media (arnés) | `h4-lab-fake` | Intermitente (1 de 9 ejecuciones, R1′): el panel del veredicto de la revisora no apareció. Hecho: en el proxy no hay ni una petición de la revisora a `lab/runs/{uuid}`, así que el clic no llegó a abrir nada, y el arnés hacía un solo clic y se tragaba el error con `.catch(() => {})`. Causa probable, NO demostrada: la lista se pide dos veces (sin y con `?version=`) y se repinta, y el clic cayó en el elemento viejo. El código del laboratorio del CRM no cambió entre `5c1dbd7` y `e7ba41b` | R1′: `CRM: el veredicto sale «no-disponible» para la revisora — null` y su motivo; las 7 comprobaciones de API de esa misma spec, en verde | Corregido (reintenta el clic hasta ver el veredicto); R4 27/27, R1″ 27/27 |
| A-4 | Baja (arnés) | `specs/lib.cjs`, `cierre` | `marketing_agent_pauses` no existe: `null` = `null` pasaba por idéntica | §4.3 | Corregido + comprobación de existencia |
| A-5 | Baja (arnés) | `h4-lab-fake` | «ningún veredicto registrado» contaba toda la tabla: roja en una pila con veredictos de h5 | control de mutaciones, `detail: 2` | Corregido (los de su ejecución, mismo id) |
| A-6 | Baja (arnés) | `f-versions` | Esperaba el comportamiento F con backend H5 (cambio pedido, `informe-front-integracion.md` §5) | — | Adaptada por `readsVersions`, con los ids F intactos |
| A-7 | Info (arnés) | `run.sh` | La caché de Angular en la ruta estable no acelera `ng serve` | §4.2 | Documentado |

Mientras escribía la spec nueva cacé dos defectos MÍOS antes de dar ningún resultado: un «sin turno» que comparaba horas de Node con columnas `timestamp` sin zona mientras la sesión de psql va en `America/Bogota` (el filtro no miraba ninguna fila: verde vacío; ahora filtra por id y además exige ver la fila de contabilidad del enrutador) y una comprobación del libro con `now()` (misma causa). README, trampa 25.

**Observaciones sobre el encargo (no son defectos):**
- **O-1.** «Un veredicto `approved` del laboratorio en `fake`» no puede existir: H4 §A6 solo admite veredicto sobre ejecuciones `openai` (`fake` → 409 `run_not_reviewable/fake_mode`, que H4 afirma). La puerta `required` se cerró con una ejecución `openai` contra el SIMULADOR. La valla no vio ningún intento de salir y la URL del simulador se ignora en `production`. Presupuesto del laboratorio a 1 US$ en la base desechable (con 0 sale `zero_budget`): coste simulado y 0 peticiones fuera de 127.0.0.1.
- **O-2.** «Cadena con 2 entradas» tras promover se cumple por versión (B: publish + promote). La cadena entera tenía ya 4 porque la spec publica antes A en P1. Las aserciones de la cadena son relativas a su estado inicial y absolutas solo con la cadena vacía («0 → 1: cadena con 1 entrada»).
- **O-3.** Con el canario del motor puesto, otra conversación nunca llega al commit (`UltronEventEmitter`: `not_canary_conversation`), así que no hay sello que mirar. Los sellos `rest` y `out_of_scope` de otras conversaciones solo se ven quitando el canario del entorno de la instantánea (P4/P4b), lo que deja deriva en `governance.canaryOnly`: es justo el escenario «deriva al cambiar una bandera del entorno» de A12. El Studio no lo provoca: lo hice yo en el `.env` de la pila desechable.
- **O-4.** Las entradas «de ayer» se siembran con el servicio real en un proceso con el reloj 25 h atrás. `artisan tinker` y este guion arrancan igual la aplicación; el guion añade las negativas de `lib/boot.php` (solo `ultron_studio_*` en 127.0.0.1 y nunca `production`) y no deja historial de PsySH.

---

## 7. Lo que NO se pudo verificar (y por qué)

- **Revertir una PUBLISHED que restaura otra RETIRED** (RETIRED → PUBLISHED): exige dos promociones, y cada una exige una canaria cuyos turnos desde su entrada estén todos sellados con esa versión. Se puede montar con una tercera conversación y otra entrada «de ayer»; no cupo. Lo cubre `UltronStudioPublicationRollbackTest` (PHPUnit), no esta pila.
- **`restore_integrity_mismatch` y `publication_head_invalid`** provocados a propósito: la cabeza rota solo se vio bajo M9 (revertir → 409). Romper una fila de verdad exigiría escribir en la cadena saltándose los disparadores.
- **Carreras de dos sesiones** (20 publicaciones a la vez, promover contra revertir, AS-4): fila PostgreSQL de A12, con barrera `pg_blocking_pids`; no es de este arnés.
- **El 429 de la evidencia** y los limitadores: `CACHE_STORE=array` (README, trampa 10). AS-2 (promover fuera del limitador de la evidencia) tampoco.
- **La pila de producción** (nginx + PHP-FPM, `config:cache`/`route:cache`, build de producción del CRM): esto es `artisan serve` + `ng serve`. En particular, la llave del motor con `config:cache` (A4.8) no se probó.
- **p95 del commit:** §5.
- **Teclado, lectores de pantalla, 390 px, Safari y Firefox** en los diálogos nuevos: solo se usó Escape para cerrar dos diálogos.

---

## 8. Ficheros del arnés creados o cambiados

Todos en `Iron-Body_Front/e2e/ultron-studio/` (sin commit). Fuera de esa carpeta solo he escrito este informe.

| Fichero | Cambio |
|---|---|
| `specs/h5-governance.cjs` | **Nuevo.** La spec del Hito 5 (§2), 276 comprobaciones |
| `specs/h5-lib.cjs` | **Nuevo.** Grabadora de respuestas, reinicio de la pila, artisan y semilla, turnos, oráculo de la firma |
| `specs/h5-stack.sh` | **Nuevo.** Reinicio de servidor y workers con otras llaves (`set`/`restore`/`restart`) |
| `specs/h5-seed.php` | **Nuevo.** Conversaciones con id inconfundible, publicar «de ayer» con el servicio real, intento de UPDATE/DELETE por la aplicación |
| `run.sh` | Lista por defecto con `h5-governance` si el backend trae el Hito 5; llaves del Hito 5 explícitas en el `.env` base; `pids`, `fence` y `serveWorkers` en `context.json` |
| `specs/lib.cjs` | `marketing_agent_pauses` → `marketing_agent_settings` |
| `specs/cierre.cjs` | Exige las 17 entradas de la huella; secretos también en la cadena y los veredictos |
| `specs/f-versions.cjs` | Modo H5 por `readsVersions` (ids F intactos) |
| `specs/g-inspector-compare.cjs` | Espera al estado del inspector (A-3) |
| `specs/h4-lab-fake.cjs` | Veredictos de su ejecución (A-5) y reintento del clic en la ejecución (A-8) |
| `mock-openai.cjs` | Entrada del juez en castellano (A-2) |
| `mutations.sh` | Controles, M1 inequívoca, `${…}`, restauración al salir, `touch` y reinicio tras PHP, M6–M9, recuentos leyendo el JSON |
| `README.md` | Uso con el Hito 5, piezas nuevas, specs, mutaciones, trampas 23–30 y «qué no cubre» |

Estado de cada ejecución en `~/.cache/ironbody-e2e/ultron-studio/` (0700, fuera de `/tmp`; la consola de cada una en `console.out`, la pasada de mutaciones en `mutations/console.out` y la prueba A/B de la caché en `ab-cache/` de la pila de desarrollo): `run-20261025T144720Z-2bc053b-e7ba41b` (desarrollo + mutaciones), `run-20261025T154709Z-2bc053b-e7ba41b` (R1), `run-20261025T161046Z-2bc053b-e7ba41b` (R1′), `run-20261025T161838Z-2bc053b-33821e4` (R4), `run-20261025T160306Z-0366009-5c1dbd7` (R2), `run-20261025T160703Z-0366009-e7ba41b` (R3), `run-20261025T162601Z-2bc053b-e7ba41b` (R1″).
