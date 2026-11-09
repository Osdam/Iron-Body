# Hito 8 · DOC8 · informe de `documentation-specialist`

**Fecha:** 08-11-2026. **Base:** backend `8d3c41f` («Enmienda 9») más lo que entró mientras trabajaba: `ccbf4a0` y `e112586` (cierres del lead), `926c945` (**Enmienda 10**), `c8c4eb4` (E10-7 y `el-verificar.sh` endurecido) y `b81af59`; HEAD `b81af59`. CRM `26ef142` (paso 2 de FE8). **Sin commit.** No he tocado código, pruebas, scripts ni el contrato. En el árbol hay cambios ajenos (las guardas de SEC8-b en `tests/…IsolationTest.php` y `VoiceBoundaryTest.php`): no los toqué. Mis tres ficheros nuevos ya estaban confirmados por el lead en su forma parcial; lo de ahora son modificaciones sin commit.

**Estado: completo salvo las marcas «[paso 3]»** (15 apariciones contando las leyendas: 3 en el manual, 8 en la guía y 4 en las pruebas físicas; ninguna en el checklist), que se quitan cuando el lead avise del CRM del paso 3, y salvo el cierre de SEC8-b, que puede añadir filas al registro de riesgos.

## 1. Qué documenté, para quién y de qué tipo

| Documento | Público | Tipo |
|---|---|---|
| `ULTRON_STUDIO_H8_VOZ.md` (nuevo): piezas, flujo, dos diagramas Mermaid (la entrante del canario y la publicación), las 34 rutas con permiso + 4 públicas + la ruta 45, los comandos, las 4 tareas, las variables, los estados, la matriz de evidencia y lo que la voz no hace | Propietario técnico, quien opera, desarrollo y revisión | Descriptivo (§1–§6) y de referencia (§7–§12) |
| `ULTRON_STUDIO_GUIA_ADMINISTRACION.md` §14 (nueva) y correcciones de E1-12 en §2, §8 y §9 | Propietario y personal (Super Admin y Administrador) | Uso, en lenguaje llano («qué ves / qué haces / qué NO hagas») |
| `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md` §5 (nueva) y correcciones de E1-12 en §1 y §4 | Quien opera el servidor y el propietario | Operativo |
| `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §9 (nueva: nivel 3 de la voz) y notas en §6.3 y §7 | Propietario y quien opera las fases | Operativo |
| `ULTRON_STUDIO_H8_RIESGOS.md` (nuevo): R8-01 a R8-29, con el §SEC8-b parcial fundido | Propietario, lead, security | Referencia |
| `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8»: correcciones fechadas autorizadas por el lead | Quien despliega | Operativo |

## 2. Ficheros (lista exacta)

**Nuevos:**
- `backend/docs/ultron-studio/ULTRON_STUDIO_H8_VOZ.md`
- `backend/docs/ultron-studio/ULTRON_STUDIO_H8_RIESGOS.md`
- `backend/docs/ultron-studio/plans/informes-h8/informe-doc.md`

**Modificados (solo añadir o anotar con fecha y fuente):**
- `backend/docs/ultron-studio/ULTRON_STUDIO_GUIA_ADMINISTRACION.md`: la cabecera; §2 (fila del Hito 8 y la nota de E1-12); §8 (la fila `ELEVENLABS_VERIFIED`, la columna «Hoy» y la pestaña «Voz»); §9 (dos notas); **§14 nueva** (14.1–14.18).
- `backend/docs/ultron-studio/ULTRON_STUDIO_CHECKLIST_OPERATIVO.md`: la cabecera; §1 (las dos expectativas de ElevenLabs, la nota de E1-12, dos filas de la tabla y la nota del Hito 7 ya desplegado); §4 (la fila de `voice:doctor`); **§5 nueva** (5.0–5.11).
- `backend/docs/ultron-studio/ULTRON_STUDIO_PRUEBAS_FISICAS.md`: notas de «superado» en §6.3 y §7; **§9 nueva** (9.0–9.5); un pendiente nuevo.
- `backend/docs/ultron-studio/ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8»: los bloqueantes 1 (resuelto) y 4 (resuelto en B8-c); el ritmo del smoke; la incidencia del login en 429; la fila de la fase 3 (`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`); la fila de la fase 4b (la publicación promovida); la nota del freno (5–300, varias aperturas, `voice:reconcile` y B8-d); la prueba de O-2 (B8-d); la rotación con `el-verificar.sh` (la ruta y el aviso de los secretos regenerados).

## 3. Cómo verifiqué

**Ejecutado** (en el árbol de trabajo, sin red, sin base):
- `php artisan route:list --path=voice --json`: 21 rutas del Studio + la 45 + 13 del CRM de telefonía + 4 públicas, con sus topes y sus limitadores (`voice-admin-read/write`, `ultron-studio-*`). Las 14 de facturación electrónica salen por «invoice».
- `php artisan tinker` con `PermissionCatalog::all()` (108) y `CrmPermission::byRole()`: Super Admin, 111 permisos y las 7 llaves; Administrador, `ultron.approve`, `ultron.voice_manage` y `telephony.view`; Administrativo, Recepción y Entrenador, ninguna de voz.
- `php artisan schedule:list` **falló** (necesita la base): el recuento de 4 tareas sale de leer `routes/console.php:429-469` y de `VoiceMonitorScheduleTest`, y así lo dice el checklist.
- Un comprobador propio de tablas (scratchpad) sobre los 7 ficheros tocados: columnas coherentes. Una búsqueda de patrones de secreto (`sk_…`, `AC`/`SK` + 32 hex, hex largos): nada.

**Leído:**
- `routes/voice.php`, `routes/ultron-studio.php`, `routes/console.php:405-469`;
- `config/voice.php` (entero), `config/ultron_studio.php:28-48, 262-316`, `config/queue.php:186-204`;
- el fichero de ejemplo de entorno (líneas 395–513, solo nombres; todos los secretos, vacíos);
- `app/Support/Access/{AuthorizationMap,CrmPermission,PermissionCatalog}.php`;
- los 4 comandos `voice:*`;
- `app/Services/Telephony/{VoiceBrake,VoiceReconciler,VoiceCostGuard,InboundGate,OutboundGate,OutboundPolicy,InboundPolicy,VoicePublishedConfig,VoiceEvidence,VoiceHangup,VoiceTools}.php`;
- `app/Services/UltronStudio/{StudioVoicePublishing,StudioVoiceEvidence,StudioVoiceLabConfig,StudioIntegrations}.php`;
- `app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php`;
- `backend/docs/ultron-studio/ops/el-verificar.sh`;
- en el CRM, `studio-voice-page.ts`, y `git show HEAD:` de `studio-voice-text.ts` y `studio-integrations-text.ts`.

**Fuentes documentales:** el contrato con las Enmiendas 1–9, la auditoría y los 9 informes de `plans/informes-h8/`, incluidos los cierres del lead y el §SEC8-b parcial.

## 4. Lo que no pude verificar (y quedó marcado)

- Nada en producción ni en el banco. Las consultas SQL de la guía física y del checklist reproducen el código (`VoiceBrake::isOpen()`) y las columnas de DB8, pero **no se han ejecutado**.
- `voice:doctor --telefonia`: no lo ejecuté, porque tocaría la base del árbol compartido. Su salida esperada sale del runbook («Hito 8», paso 9).
- Los diagramas Mermaid **no se han renderizado** (no hay `mmdc` en la máquina). Revisé la sintaxis a mano: quité los `;` y las `|` de los mensajes de la secuencia.
- Todo lo de la cuenta real queda marcado **[por confirmar]** en el manual (§12.4) y en las pruebas físicas (§9).

## 5. Discrepancias

### 5.1 Corregidas por DOC8 (con la fecha y la fuente en el propio texto)

| # | Dónde | Qué decía | Lo real |
|---|---|---|---|
| D-1 | Guía §2, §8, §9; checklist §1 y §4 | `ELEVENLABS_NOT_CONFIGURED` | `voice:doctor`, `ELEVENLABS_UNVERIFIED`; Studio, `ELEVENLABS_VERIFIED` (E1-12) |
| D-2 | Plan, bloqueante 4, ritmo del smoke e incidencia del login | Las rutas del CRM con `throttle:120,1`/`30,1`, en el cubo del login | `voice-admin-read/write` por persona (E9-4) |
| D-3 | Plan, «Cómo se suelta el freno» | Nota de 5 a 500 | 5 a 300 |
| D-8 | Plan, rotación | `el-verificar.sh` sin ruta y fuera del repo | `backend/docs/ultron-studio/ops/el-verificar.sh` (en el árbol desde el 08-11) |
| D-13 | Plan, fase 3 | Sin `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED` | Publicar el canario la exige |
| D-14 | Plan, fase 4b | Sin la publicación promovida | El alcance `contacts` lee la parte `rest` |
| — | Plan, bloqueante 1 | La ascendencia perdida, como abierta | Resuelto (paso 0 repetido y ensayo sobre `8d3c41f`) |
| — | Pruebas físicas §6.3 y §7 | `NOT_CONFIGURED` y «llamada fuera del alcance» | Superados por E1-12 y el Hito 8 (§9) |

La Enmienda 10 (E10-13) recoge estas correcciones como encargo de DOC8. Además, los estados del registro de riesgos y del manual siguen ya a E10-4 a E10-12 (B8-d en curso; B-3 corregido por el lead en `c8c4eb4`). Las citas de `el-verificar.sh` van a sus líneas nuevas (`:24-34`).

### 5.2 Las que quedan (no son mías o piden una decisión)

| # | Dónde | Qué | Recomendación | Dueño |
|---|---|---|---|---|
| D-4 | `informe-devops.md`, Notas | `voice:prune-rate-limits` «cada hora»; el código dice a diario a las 04:40 de Bogotá (E9-2) | Dejarlo: es el registro histórico del paquete; el contrato y el código mandan | devops / lead |
| D-5 | Contrato D8-23 | `ULTRON_STUDIO_VOICE_LAB_*`; el código usa `VOICE_LAB_*` (E2-4 solo renombra la bandera) | Una línea en una enmienda | lead |
| D-6 | Contrato, protección 1 | «todas las `VOICE_*` en false o 0»; hay techos no nulos (B8-a §4.7) | Ídem | lead |
| D-7 | Congelación, P-18 | «Administrador: … y laboratorio hablado»; el código sigue D8-22 (solo Super Admin) | Ídem | lead |
| D-9 | `ULTRON_STUDIO_ELEVENLABS_READY.md`, cabecera | «Estado: `ELEVENLABS_NOT_CONFIGURED`» | Marcarlo con fecha (E1-12). No estaba en mi lista; lo hago si el lead quiere | DOC8, con la orden |
| D-10 | Comentarios de `config/voice.php:3-21, 50` y del bloque H6 del fichero de ejemplo | «En este hito no se hace ninguna llamada», «no hay ruta» | Actualizar los comentarios | backend |
| D-11 | `voice:doctor` (salida de texto, `VoiceDoctorCommand.php:79`) | Línea fija «Llamadas: cerradas en el código…» | Atarla a la constante antes de la fase 3 | backend |
| D-12 | Contrato, fila DOC8 | Pide además TWILIO_REPORT, ROLLBACK del H8, la fila 8 de la hoja de ruta, FINAL_REPORT, SECURITY, BACKEND, VISUAL y `ops/README.md` (y llama al manual `ULTRON_STUDIO_VOICE.md`) | Decidir si entran ahora o con REV8 | lead |
| D-16 | Plan, tabla de fases, fase 2 | No nombra la CSP del laboratorio hablado en nginx (E10-12: `connect-src wss://api.elevenlabs.io` y `Permissions-Policy: microphone=(self)`), que el laboratorio necesita en esa fase | Añadirla al criterio de la fase 2; el checklist §5.5 y el manual §12.2 ya la piden como pendiente de OPS8 | devops |
| D-15 | Plan, tabla de fases, fase 2 | «`twilio_voice:disabled` … o `connected_voice`»: con `TWILIO_VOICE_ENABLED=false` (se enciende en la fase 3) la clasificación es `disabled` (`classifyTwilioVoice`) | Quitar «o `connected_voice`» de la fase 2; el checklist ya dice `disabled` | devops |

## 6. Hallazgos de DOC8 y su decisión

- **H-D1 (R8-13): el freno de gasto con el presupuesto en 0.** Cualquier fila del día echa `cost_cap`, también la de la prueba O-2. **E10-8: corrección en curso (B8-d)**; documentado así en el manual, el checklist, la guía, el plan y el registro.
- **H-D2 (R8-27): `voice:reconcile` no es de solo lectura.** **E10-9: corrección en curso (B8-d)** (`--dry-run`).
- **H-D3 (R8-14): las transferencias no se pueden probar en el laboratorio.** **E10-10:** limitación aceptada hasta la fase 3 (el perfil del canario no lleva destinos).
- **H-D4 (R8-28), nuevo: `el-verificar.sh` regenera los dos secretos propios** (`el-verificar.sh:24-34`). Ejecutarlo después de registrarlos en ElevenLabs rompería la post-llamada y las herramientas. Avisado en el plan, el checklist y el manual.
- **H-D5 (R8-29), nuevo: una exclusión oral solo la quita la supresión.** Un canario que diga «no me vuelvas a llamar» en una prueba queda fuera de la 4a. Avisado en las pruebas físicas.

## 7. Huecos de información (preguntas concretas)

1. **Lead / B8-d:** cuando B8-d entregue E10-4 a E10-9, ¿qué presupuesto de voz se fija en la fase 3? (E10-8 dice que lo denegado no gasta, pero una entrante autorizada sí). Con la entrega se quitan las marcas «corrección en curso (B8-d)» y se actualizan R8-13, R8-19 a R8-21 y R8-27.
2. **devops:** el nombre, los argumentos y la salida de `h8-voice-scope.sh`, para poner los comandos exactos de las fases 2–4b.
3. **security (cierre de SEC8-b):** las probabilidades del registro, las filas nuevas y **qué es publicable**: R8-19 (M-2) y la prueba 8 de §9 describen cómo provocar la violación de la guarda. Es el mismo detalle que ya está en `informe-security.md`, en el mismo repositorio privado.
4. **Propietario:** dónde enseña la consola de ElevenLabs los parámetros de las herramientas (C-A3) y si tiene herramienta de prueba del webhook de iniciación (O-2).
5. **FE8-3 (vía el lead):** el aviso para quitar las 15 marcas «[paso 3]» y contrastar las pantallas (el CRM sigue en el WIP `c6c48b6`, E10-14).

## 8. Riesgos

- **Secretos:** ningún valor en lo escrito. El fichero de ejemplo de entorno tiene todos los secretos vacíos. No encontré ningún secreto expuesto en lo leído.
- **Contenido sensible:** ver la pregunta 3 (detalles de explotación de hallazgos sin corregir).
- **Fechas que caducan:** la verificación de ElevenLabs, el **03-12-2026 a las 22:55 UTC** (guía §2 y §14.15, checklist §1 y §5.4, R8-05).
- **Pendiente de revisión:** pasarlo a `reviewer` con este informe (las fuentes de cada afirmación están al final de cada sección).
