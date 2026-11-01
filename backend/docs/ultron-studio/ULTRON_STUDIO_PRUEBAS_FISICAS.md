# ULTRON STUDIO: procedimiento E2E controlado de pruebas físicas

**Qué es.** El procedimiento para probar a mano, en producción y con personas reales, lo que ULTRON STUDIO hace hoy y lo que hará en sus fases siguientes: configurar reglas, versionar, simular en el laboratorio, aprobar y publicar con cuatro ojos, asociar números canarios, diagnosticar Twilio y, solo si algún día se autoriza, hacer una llamada de prueba. Cada prueba dice qué demuestra, quién la ejecuta, qué evidencia deja y dónde, qué **no** ocurre y cómo se vuelve atrás.

**Para quién.** El propietario y quien opera los despliegues (lead o `devops`). Hace falta conocer el CRM; no hace falta conocer el código. Los pasos de consola son para quien entra como root en `ironbody-vps`.

**Tipo.** Operativo. No sustituye al runbook de despliegue (`ULTRON_STUDIO_DEPLOYMENT_PLAN.md`) ni a la reversión (`ULTRON_STUDIO_ROLLBACK.md`): los enlaza. Los contratos (`ULTRON_STUDIO_F_CONTRACT.md`, `…_G_…`, `…_H4_…`, `…_H5_…`, `…_H6_…`) mandan sobre este documento.

**Fecha:** 01-11-2026 (UTC; 31-10-2026 por la tarde en Bogotá), sobre `feat/ultron-studio-h5` = `origin/main` en `41b6470`. **Ninguna prueba de este documento se ha ejecutado todavía como prueba física.** Los pasos salen de los contratos, de los runbooks, de los scripts de `ops/` y del código. Las consultas SQL se ensayaron en solo lectura contra la base desechable local del E2E (PostgreSQL 14), no contra producción.

---

## Punto de partida: estado real el 01-11-2026

| Pieza | Estado | Fuente |
|---|---|---|
| Producción | Backend `7ba0ce8` y CRM `ab78a6a`, desde el 01-11-2026 01:12–01:16 UTC | `ULTRON_STUDIO_MASTER_ROADMAP.md` (registro de despliegues) |
| Hito 3 (F y G) | Desplegado: borrador por persona, versiones inmutables, reglas con condiciones, libro, lecturas del motor y comparación | Hoja de ruta |
| Hito 4 (laboratorio) | Fases 1 y 2: encendido en `fake`, presupuesto diario 0 y **sin** `ULTRON_STUDIO_LAB_OPENAI_API_KEY`. La fase 3 espera la clave del propietario | `ULTRON_STUDIO_FINAL_REPORT.md`, `plans/informes-h6/matriz-credenciales-vps.md` |
| Hito 5 (gobierno) | Fase 1, todo apagado: 38 rutas, cadena vacía, `MARKETING_ULTRON_STUDIO_CONFIG=off`, `ULTRON_STUDIO_PUBLISHING_ENABLED=false`, `ULTRON_STUDIO_PUBLISH_LAB_GATE=required`. Publicar responde 409 y el motor no consulta la cadena ni sella | Informe final («Hito 5: despliegue y verificación»), matriz |
| Hito 6 (integraciones) | **En implementación, no desplegado.** Contrato congelado el 31-10-2026. En la rama local `feat/ultron-studio-h6`, sin subir, están los paquetes DB (migraciones y guardas), V (voz) y TW (cliente de Twilio de solo lectura). Faltan S (la API del Studio, rutas 39–56), FE (el CRM), OPS (los scripts) y las revisiones | `git log feat/ultron-studio-h5..feat/ultron-studio-h6` |
| Voz y llamadas | Ningún código puede llamar (§7) | Código de `origin/main`; contrato H6 |
| ULTRON | Encendido, solo texto de WhatsApp, con el canario de conversación configurado y el cobro apagado | Contrato H5, decisión 10; informe final |
| Abierto del despliegue | [PENDIENTE: verificación de la fase 1 del Hito 5 a los 15 minutos y a las 24 horas (runbook del H5, «Después de la fase 1»)] | Informe final |

---

## Reglas comunes a todas las pruebas

1. **Dos personas reales, con dos cuentas.** Toda prueba de cuatro ojos necesita a la autora (persona **A**) y a otra persona (**B**). La cuenta temporal de smoke (admin id 32) no hace nunca de A ni de B: solo la usan los smokes, que la reactivan y la dejan inactiva. Los cuatro ojos comparan **cuentas**, no personas: nadie usa una segunda cuenta propia (control organizativo, hoja de ruta, «Pendientes del propietario (Hito 7)»).
2. **Lo que se crea en producción no se borra.** Versiones, reseñas, el libro `ultron_studio_audit_events`, las ejecuciones, casos y veredictos del laboratorio, su libro `ultron_studio_lab_events` y la cadena `ultron_studio_publications` son de solo inserción o rechazan DELETE con disparadores. Las reglas y los escenarios se **archivan**, no se borran. El borrador es lo único que se reescribe, y descartarlo no borra su fila. **Antes de cada prueba que escribe, el propietario acepta que esas filas se queden.**
3. **Sin datos personales en texto libre.** Ni nombres de socios o prospectos, ni teléfonos, ni correos, en etiquetas, notas, motivos, descripciones, condiciones o escenarios: quedan en libros que no se pueden borrar (Ley 1581). Usar textos como «Prueba física 1: …». El procedimiento para redactar el libro ante una solicitud de supresión no existe todavía (Hito 7).
4. **Nada sensible se imprime, se copia ni se archiva:** valores del fichero de entorno, el id de la conversación canaria, `signature` o `effective_json` de la cadena, teléfonos y el texto de los turnos del acta del canario. Las capturas se revisan antes de guardarlas.
5. **Horas.** La base guarda UTC (`config/app.php`: `'timezone' => 'UTC'`). La ventana de publicación (07:00–19:00) y el día del presupuesto del laboratorio son de Bogotá (UTC−5).
6. **Consola.** `ssh ironbody-vps`. La aplicación vive en `/var/www/api/backend` y Artisan corre como `www-data` (`sudo -u www-data php artisan …`). Cambiar el fichero de entorno, reiniciar workers o lanzar un smoke **requiere autorización**, y se marca en cada paso.
7. **El CRM:** `https://ironbodyneiva.cloud/marketing/ultron-studio` (Mercadeo › ULTRON Studio). El laboratorio está en `https://ironbodyneiva.cloud/marketing/ultron-studio/laboratorio`.
8. **Permisos por defecto** (contratos F §A4, H4 §A7 y H6 §I7.1):

| Llave | Super Admin | Administrador | Recepción, Entrenador, Administrativo |
|---|---|---|---|
| `ultron.view`, `ultron.edit_draft`, `ultron.manage_rules`, `ultron.approve`, `ultron.lab_manage` | sí | sí | no |
| `ultron.publish`, `ultron.rollback`, `ultron.lab_run` | sí | no (se conceden a mano desde Roles) | no |
| Hito 6, **planificado:** `ultron.campaigns_manage`, `ultron.canary_manage` | sí | sí | no |
| Hito 6, **planificado:** `ultron.canary_authorize`, `ultron.integrations_manage` | sí | no | no |

### Cómo se lanza una consulta SQL de solo lectura

Todas las consultas de este documento son `SELECT`. En el servidor se lanzan así:

```bash
sudo -u postgres env PGOPTIONS='-c default_transaction_read_only=on' psql -X -d ironbody <<'SQL'
SHOW default_transaction_read_only;   -- on
SQL
```

- `PGOPTIONS='-c default_transaction_read_only=on'` deja cada transacción de la sesión en solo lectura: un INSERT, UPDATE, DELETE o DDL escrito por error falla con `cannot execute … in a read-only transaction`. Comprobado en el PostgreSQL 14 desechable local; **no ejecutado en el servidor**: la primera línea de cada sesión es el `SHOW`, y tiene que decir `on`.
- Es una red contra el error, no un control de seguridad: `postgres` puede desactivarla dentro de la sesión. No se desactiva.
- El prefijo `sudo -u postgres psql -X … -d ironbody` y la entrada estándar son los del runbook del Hito 5 (`h5-schema-check.sql` se lanza igual).
- No se seleccionan nunca `effective_json` ni `signature` (cadena), ni `phone_hash`, `phone_last4` o `phone_encrypted` (Hito 6), ni los textos de `marketing_messages`.

---

## 1. Configuración de reglas desde las diez capas (Hito 3: F y G) — se puede hacer hoy

**Objetivo.** Comprobar en el CRM real que el borrador, el inspector de cinco pestañas, las reglas con condiciones y el motor de coherencia (422 y 409) se comportan como fija el contrato G, y que las lecturas del motor son de solo lectura.

**Precondiciones.**
- Persona A con `ultron.view`, `ultron.edit_draft` y `ultron.manage_rules`. Para la prueba de permisos, una cuenta de Recepción, Entrenador o Administrativo.
- Nadie edita a la vez el borrador de A desde otra pestaña: un guardado con revisión vieja da 409 `draft_conflict` (diálogo «recargar» o «sobrescribir»).
- El propietario acepta que las reglas S-NNN de prueba y sus eventos se queden (se archivan al terminar).
- Mientras una S-NNN esté viva, toda versión nueva la copia, y al publicar esa versión habrá que reconocerla como «sin efecto» (contrato H5 §A1). Por eso se archivan al terminar (vuelta atrás).

**Quién.** Persona A, en el CRM. Opcional: una cuenta sin llaves `ultron.*`.

**Pasos (CRM).**
1. **Abrir** Mercadeo › ULTRON Studio. Esperado: la página carga con «Mi borrador» y las diez capas. Con el backend del Hito 5, la barra de la publicación dice Legacy, la llave del motor apagada y ningún sello, y el aviso del motor dice «El motor de ULTRON solo anota la versión publicada: guardar, crear, aprobar o publicar versiones no cambia lo que ULTRON dice ni hace.».
2. **Lecturas del motor** (`GET engine/readings`). Releer el motor con el botón de la cabecera. En la capa «Control y evolución» (el gobierno), pestaña Resumen, bloque «Motor ahora»: interruptor, pausa, freno, canario (sí o no; **nunca cuál**) y el estado efectivo («Atiende: solo la conversación canaria» si el motor sigue como el 31-10-2026). En «Comprensión de intención», el driver. En «Reglas del negocio», la bandera del cobro y su canario. En «Conocimiento», la versión y los recuentos. En «Estrategia comercial», los planes, solo por nombre.
3. **Inspector de cinco pestañas.** Con una capa seleccionada: Resumen · Configuración · Reglas · Dependencias · Historial. Las flechas, Inicio y Fin cambian de pestaña.
4. **«≠ motor» y prevención, sin guardar.** Capa «Reglas del negocio» › Configuración › «Enlaces de pago» → sí. Esperado: el inspector pide confirmación, porque es un parámetro importante y aparece el bloqueo nuevo «Enlaces de pago sin su camino» (restricción `cobro-sin-camino`). **Cancelar** deja el control en el valor del borrador. Si se confirma, la fila marca «≠ motor» (el motor lee «no») y la validación enseña el bloqueo; «Usar el valor del motor» lo devuelve a «no». Antes de seguir, el valor tiene que estar otra vez en «no».
5. **Un cambio que se guarda.** Capa «Personalidad y tono» › Configuración › «Longitud máxima» → 400. «Guardar borrador» (Ctrl+S). Esperado: la revisión del borrador sube 1, la validación del servidor llega al guardar y manda si discrepa de la del CRM, y no hay bloqueos.
6. **La disposición no cuenta.** Mover un nodo y guardar. Esperado: la revisión sube 1 y la huella del borrador **no cambia**: la huella cubre `{catalogVersion, definition, configs}` y deja fuera la disposición (contrato F §A5). En el historial sale «Se movieron nodos (no cambia nada)».
7. **Regla con condiciones.** Panel «Reglas» › «Nueva regla». Descripción «Prueba física: longitud máxima 300», capa «Personalidad y tono», el tipo y la prioridad que se quiera, y en «Entonces…»: «Longitud máxima» ≤ 300. Esperado: 201, una regla `S-NNN` en borrador, con «El motor no la aplica» y verificación `studio`. Con el borrador en 400, aparece en vivo el aviso «S-NNN no se cumple» (`regla-S-NNN`), y el servidor devuelve el mismo al guardar.
8. **Contradicción con otra regla (409).** «Nueva regla» con «Entonces…: Longitud máxima ≥ 320» y sin «Si…». Esperado: 409 `rule_contradiction` con la lista de conflictos y «Ver S-NNN». No se crea nada.
9. **Contradicción con un control del motor (422).** «Nueva regla» en la capa «Estrategia comercial» con «Entonces…: Puede ofrecer descuentos = sí». Esperado: 422 con dos mensajes en la misma clave, el de la restricción y el de su regla del código, por ejemplo «Contradice R-02 (la aplica el código): «Puede ofrecer descuentos» tiene que ser «no».». No se crea nada.
10. **Las reglas del código son de solo lectura.** R-01…R-12 llevan la insignia «Código» y no se editan. Por la API, editarlas responde 409 `legacy_rule_read_only`.
11. **Historial.** «Historial» en la regla del paso 7, y la pestaña Historial de la capa en el borrador (filtrada entre los 100 cambios más recientes).
12. **Permisos.** Con la cuenta sin llaves `ultron.*`: no hay submenú, la URL directa devuelve al inicio y la API responde 403.

**Evidencia (dónde queda).**
- **Libro `ultron_studio_audit_events`:** `draft.saved` (con `configChanges` y `positionsChanged`), `rule.created` (con `conditions` en el `diff`) y, al terminar, `rule.archived` y `draft.discarded`. **No dejan evento** (contrato G §A8): las lecturas del motor, la comparación, el análisis, los 422 ni el 409 `rule_contradiction`. Su evidencia es la captura y que no haya fila nueva.
- **Tablas:** `ultron_studio_drafts` (revisión y huella) y `ultron_studio_rules`.
- **Capturas** de los pasos 2, 4, 8, 9 y 12. Referencia de cómo se ven el tablero y los paneles: `capturas/` (vista previa y F).
- **SQL de solo lectura:**

```sql
SELECT id, event, subject_type, actor_role, created_at
  FROM ultron_studio_audit_events
 WHERE created_at > now() - interval '2 hours'
   AND event IN ('draft.saved', 'draft.discarded', 'rule.created', 'rule.updated', 'rule.archived')
 ORDER BY id;
SELECT code, status, enforcement, revision, (conditions IS NOT NULL) AS con_condiciones
  FROM ultron_studio_rules WHERE source = 'studio' ORDER BY code;
SELECT count(*) FROM ultron_studio_rules
 WHERE source = 'studio' AND (enforcement <> 'none' OR status = 'active');            -- 0
SELECT revision, left(content_hash, 12) AS huella, updated_at
  FROM ultron_studio_drafts ORDER BY updated_at DESC LIMIT 3;
```

**Criterio de éxito.** Las lecturas aparecen sin ids ni secretos; las cinco pestañas funcionan con teclado; el paso 4 pide confirmación y marca «≠ motor»; el 6 sube la revisión sin cambiar la huella; el 7 da 201 y el aviso en vivo coincide con el del servidor; el 8 da 409 y el 9 da 422, los dos sin fila nueva; la cuenta sin llaves recibe 403; ninguna S-NNN tiene `enforcement` distinto de `none`.

**Qué NO ocurre.** El motor no lee el borrador ni las reglas (desde el Hito 5 solo puede leer la cabeza de `ultron_studio_publications`, y con la llave en `off` ni eso). Las S-NNN nacen con `enforcement = none` y el CHECK `usr_studio_never_active_chk` impide en la base que una del Studio se active. Los cuatro mandos reales (driver, enlaces de pago, interruptor y canario) solo se cambian en el entorno del servidor, nunca desde el Studio. Sin llamadas, sin n8n, sin coste.

**Vuelta atrás.** Archivar cada S-NNN de prueba («Archivar la regla», con un motivo de 5 a 500 caracteres y sin datos personales) → `rule.archived`. «Descartar el borrador» → el contenido vuelve a la línea base, la revisión sube 1 y queda `draft.discarded`; la fila no se borra. No hay nada más que deshacer.

---

## 2. Guardado y versionado en PostgreSQL — se puede hacer hoy

**Objetivo.** Crear una versión inmutable desde el borrador y comprobar su huella, su integridad, la comparación entre versiones y el libro, mirando la base solo en lectura.

**Precondiciones.**
- El borrador de A guardado y **sin bloqueos**: con bloqueos, «Crear versión» responde 422 `validation_blocked` y no crea nada.
- Decidido si las S-NNN de prueba entran en la versión (se copian las no archivadas). Con alguna S-NNN, la versión queda siempre en `REVIEW_REQUIRED` por `reglas-sin-aplicacion`.
- **Si esta versión va a ser la primera que se publique** (§4.3), tiene que reflejar el motor tal como está: los cuatro valores `must_match` iguales a las lecturas del motor (`governance.enabled` sí, `governance.canaryOnly` sí, `rules.paymentLinksEnabled` no e `intent.driver` «Según el entorno», según la lectura del 25-10 y del 31-10; en el inspector, ninguno de los cuatro marca «≠ motor») y, mejor, sin S-NNN.

**Quién.** Persona A (`ultron.edit_draft`). La comprobación de inmutabilidad en una base desechable, quien desarrolla (Mac).

**Pasos.**
1. **CRM, A:** «Crear versión», con la etiqueta «Prueba física: versión 1» y la nota vacía. Esperado: 201 y la versión en `DRAFT` («Creada, sin enviar»), con las reglas copiadas y su huella corta. Si se repite el envío, el CRM reutiliza el mismo `clientRequestId` y el servidor responde 200 `replayed`, sin duplicar.
2. **CRM, A:** «Enviar a revisión» (solo quien la creó; cualquier otra persona recibe 403 `not_version_owner`). Esperado: `VALIDATED` sin avisos o `REVIEW_REQUIRED` con avisos. La línea base ya avisa de `opcional-E14` y `opcional-E16`, así que una versión realista queda en `REVIEW_REQUIRED`.
3. **Huella e integridad.** En el detalle, la huella corta. El servidor recalcula la huella al leer y devuelve `integrityOk`. Al comparar, un lado con `integrityOk = false` lleva la insignia «Huella alterada». Dos versiones con el mismo comportamiento comparten huella, aunque cambie la disposición.
4. **Comparación.** «Comparar con…» la línea base, «Mi borrador» u otra versión (`GET compare`). Esperado: diferencias agrupadas en Configuración, Conexiones, Reglas y Avisos, o «Sin diferencias de comportamiento.».
5. **Libro en el CRM.** Panel «Auditoría»: los eventos en frases, sin IP ni user agent, con el filtro «Mi borrador». Los eventos de un borrador solo los ve su dueño o quien tenga `audit.view`.
6. **Inmutabilidad, en la base (solo lectura):** las consultas de abajo. La prueba **de comportamiento** (un UPDATE de una columna inmutable que falla con P0001) se hace en un PostgreSQL desechable, nunca en producción: `docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio`, desde `backend/` en el Mac, de 3 a 8 minutos (último resultado documentado: 1447 OK sobre `cf59cef`, lead, 31-10-2026).

```sql
SELECT count(*) AS disparadores FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
 WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;                        -- 30 desde la fase 1 del H5
SELECT count(*) AS checks FROM pg_constraint
 WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';              -- 27
SELECT c.relname AS tabla, t.tgname AS disparador FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
 WHERE c.relname IN ('ultron_studio_versions', 'ultron_studio_version_reviews', 'ultron_studio_audit_events', 'ultron_studio_publications')
   AND NOT t.tgisinternal ORDER BY 1, 2;
-- usae_forbid_{delete,truncate,update} · uspub_forbid_{delete,truncate,update} · usvr_forbid_{delete,truncate,update} · usv_forbid_{delete,immutable_update,truncate}
SELECT uuid, status, left(content_hash, 12) AS huella, catalog_version, created_by_admin_id AS autor, created_at
  FROM ultron_studio_versions ORDER BY id DESC LIMIT 10;
SELECT id, event, from_status, to_status, actor_type, left(content_hash, 12) AS huella, created_at
  FROM ultron_studio_audit_events
 WHERE subject_type = 'version' AND subject_uuid = '<uuid de la versión>' ORDER BY id;
```

**Evidencia.** Libro: `version.created` (`→ DRAFT`) y `version.submitted` (`DRAFT → VALIDATED | REVIEW_REQUIRED`). Tabla `ultron_studio_versions` (estado, huella, autor). La salida de las consultas y las capturas del detalle y de la comparación.

**Criterio de éxito.** La versión pasa de `DRAFT` a `VALIDATED` o `REVIEW_REQUIRED`; `integrityOk` es verdadero; mover nodos no cambia la huella; hay 30 disparadores y 27 CHECK, con los doce de las cuatro tablas de arriba; la comparación coincide con lo que se cambió en §1.

**Qué NO ocurre.** Crear o enviar una versión no cambia al agente: el motor no lee versiones, solo la cabeza firmada de la cadena (§4) y solo con su llave distinta de `off`. Ninguna escritura sale del esquema `ultron_studio_*`.

**Vuelta atrás.** No hay transición hacia atrás, por diseño. Una versión de prueba se queda en `DRAFT` o se rechaza en la revisión (§4.1, con motivo). **Nunca** UPDATE ni DELETE por SQL: los disparadores lo impiden, y una reparación con el rol dueño exige la autorización del propietario y a `database-specialist`.

---

## 3. Simulaciones del laboratorio (Hito 4)

### 3A. Ensayo en `fake`, sin coste — se puede hacer hoy

**Objetivo.** Comprobar el laboratorio tal como está en producción (encendido en `fake`, presupuesto 0, sin clave): escenarios, estimación, ejecución sin IA y que el veredicto no es posible en `fake`.

**Precondiciones.**
- Configuración de hoy: `ULTRON_STUDIO_LAB_ENABLED=true`, `ULTRON_STUDIO_LAB_DRIVER=fake`, `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0` y sin `ULTRON_STUDIO_LAB_OPENAI_API_KEY` (matriz de credenciales).
- Ver la página: `ultron.view`. Escenarios: `ultron.lab_manage`. Lanzar y cancelar: `ultron.lab_run` (solo Super Admin por defecto).
- Decisión del propietario si se lanza una ejecución: **deja filas que no se pueden borrar** (la ejecución, sus casos y sus eventos) y cuenta para el tope de 20 ejecuciones al día.

**Quién.** Persona A o B (Super Admin para ejecutar).

**Pasos (CRM).**
1. «Laboratorio» (cabecera del tablero). Esperado: «Simulación del Studio · no es ULTRON» fijo bajo el título, el modo «Ensayo sin IA» y el presupuesto del día de Bogotá con tope 0.
2. Pestaña «Escenarios»: los 11 del catálogo (C-01…C-10 y C-12), de solo lectura.
3. *(Opcional; escribe solo si pasa.)* Filtro de datos personales: un escenario nuevo con un texto como «escríbeme a prueba@ejemplo.com». Esperado: 422 junto al campo, con el mensaje «Parece un dato personal (correo, teléfono o documento) o un carácter invisible: los escenarios son sintéticos y se envían a un proveedor externo», y ninguna fila.
4. «Nueva ejecución»: objetivo (la línea base o una versión) y dos escenarios del catálogo. La estimación (`POST lab/estimate`) **no escribe nada**: coste 0 y permitida en `fake`.
5. *(Opcional, deja filas.)* «Ejecutar (máx. US$ …)». El worker `ironbody-lab` procesa un caso a la vez. Esperado: cada caso llega como mucho a «Revisar»; sale «Ensayo sin IA: no hay propuesta ni veredicto.» y el formulario del veredicto queda deshabilitado con su motivo (la API respondería 409 `run_not_reviewable` con `reason: fake_mode`).

**Evidencia.**
- Libro `ultron_studio_lab_events`: `run.created`, `run.finished` y, si se creó un escenario, `scenario.created`.
- Tablas `ultron_studio_lab_runs` y `ultron_studio_lab_run_cases`. El worker escribe en `backend/storage/logs/worker-lab.log`.
- `sudo -u www-data php artisan queue:health`: el carril `lab`, atendido.

```sql
SELECT uuid, target_kind, mode, status, stop_reason, case_count, cost_micros, ai_recommendation, created_at
  FROM ultron_studio_lab_runs ORDER BY id DESC LIMIT 10;
SELECT budget_day, count(*) AS ejecuciones, sum(cost_micros) AS coste_micros
  FROM ultron_studio_lab_runs GROUP BY budget_day ORDER BY budget_day DESC LIMIT 7;
SELECT event, count(*) FROM ultron_studio_lab_events GROUP BY event ORDER BY event;
SELECT count(*) FROM ultron_studio_lab_runs WHERE status IN ('queued', 'running');     -- 0 al terminar
```

**Criterio de éxito.** `mode = fake`, `cost_micros = 0`, `ai_recommendation` vacío y ningún veredicto posible. `budget_day` es la fecha de Bogotá.

**Qué NO ocurre.** No es ULTRON: no usa n8n, ni sus prompts, ni `decide`/`commit`. En `fake` no sale ninguna petición a OpenAI y no cuesta nada (el E2E lo comprobó con su simulador). Una ejecución `fake` no sirve para la puerta del laboratorio del Hito 5: allí solo vale un veredicto `approved`, que no existe en `fake`.

**Vuelta atrás.** Archivar los escenarios de prueba (motivo de 5 a 500 caracteres). Las ejecuciones terminadas son inmutables y se quedan. Para parar el laboratorio entero, ver 3B.

### 3B. Fase 3 con OpenAI — solo cuando exista la clave del propietario

**Objetivo.** Una ejecución real con OpenAI dentro de topes, con su propuesta determinista y el veredicto de una segunda persona. Es lo que exige `HITO_4_COMPLETED` y la vía (a) de la puerta del laboratorio del Hito 5.

**Precondiciones** (checklist del propietario, runbook del Hito 4, «Fase 3»):
- [ ] Un **proyecto de OpenAI dedicado** al laboratorio, con presupuesto propio y una **clave nueva**, distinta de `OPENAI_API_KEY` y de la credencial de n8n. La escribe el propietario en el servidor, nunca por chat.
- [ ] **Retención** confirmada: qué conserva OpenAI con `store: false` en su cuenta (por defecto, los registros de vigilancia de abusos hasta 30 días, según la documentación consultada el 19-10-2026).
- [ ] **Modelo y precios** oficiales de entrada y salida por millón de tokens.
- [ ] **Topes:** propuestos US$ 2 al día, US$ 0,50 por ejecución, 20 ejecuciones al día y 12 casos (máximo duro 20), o los que fije.
- [ ] Aceptado que un 429 del límite de gasto del proyecto que llegue sin `insufficient_quota` deja la ejecución reintentando sin gastar hasta el plazo de 30 minutos (Enmienda 3 del contrato H4).
- [ ] La versión sobre la que se ejecutará, creada por A. Si el veredicto va a abrir la puerta del Hito 5, tiene que ser **la misma versión** (misma huella) que se publicará.

**Quién.** El propietario escribe la clave. Un Super Admin (`ultron.lab_run`) lanza. El veredicto lo emite una persona con `ultron.approve` **distinta de la autora de la versión**; quien lanzó puede emitirlo si no es la autora.

**Pasos (servidor; propietario, con autorización).**
1. Copia previa del fichero de entorno, como en el runbook del H5 (root, 600; lleva secretos y no se imprime): `install -m 600 -o root -g root /var/www/api/backend/.env /root/env-pre-lab-openai-$(date -u +%Y%m%d-%H%M%S)`.
2. En `backend/.env`: `ULTRON_STUDIO_LAB_OPENAI_API_KEY`, `ULTRON_STUDIO_LAB_OPENAI_MODEL`, `ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK`, `ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK`, `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=2` (o el suyo) y `ULTRON_STUDIO_LAB_DRIVER=openai`. Los precios, con punto decimal (`0.40`): «0,40» da `missing_price`. El fichero sigue de `www-data` y en 600.
3. Que el fichero parsea (la comprobación de `h5-engine-scope.sh`; si falla, imprime solo la clase del error), la caché, los permisos y la configuración efectiva sin imprimir la clave:

```bash
cd /var/www/api/backend && sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' && echo "parsea"
cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 bootstrap/cache/config.php
sudo -u www-data php artisan security:check-secret-exposure                          # en verde
cd /var/www/api && sudo -u www-data php backend/artisan tinker --execute='echo json_encode([app()->environment(), config("ultron_studio.lab.enabled"), config("ultron_studio.lab.driver"), config("ultron_studio.lab.daily_budget_usd"), config("ultron_studio.lab.openai.base_url"), (bool) config("ultron_studio.lab.openai.api_key")]), PHP_EOL;'
# esperado: ["production",true,"openai",…,null,true]
supervisorctl restart 'ironbody-lab:*'                                               # el worker lee la configuración al arrancar
```

4. En el CRM, con un Super Admin, `GET lab/status` (la cabecera del laboratorio): `mode: openai`, `configured: true`, `reasons: []`. **Si sale `shared_key`, la clave es la del motor: parar.**

**Pasos (CRM).**
5. **Coste contra el panel de OpenAI:** una ejecución de 2 escenarios sobre la línea base. La estimación enseña «Como mucho US$ …», y el botón envía exactamente ese máximo. Al terminar, comparar el coste registrado con el panel de uso del proyecto: tienen que coincidir aproximadamente (la estimación sobreestima a propósito).
6. **Ejecución sobre la versión:** desde su detalle, «Probar en el laboratorio». Al terminar, la **propuesta** («Propuesta del laboratorio: no es un veredicto»), determinista: `reject` si algún caso es `fail`; `review` si alguno es `review`, `error` o `skipped`; `approve` si todos pasan. Un hallazgo bloqueante de los filtros gana siempre a la opinión de la IA.
7. **Veredicto de la segunda persona:** `approved`, `needs_changes` o `rejected`. `approved` exige marcar **exactamente** los casos que no pasaron; la nota (de 5 a 1000 caracteres) es obligatoria salvo al aprobar. Uno por ejecución e inmutable. Rechazos esperables: la autora → 403 `four_eyes_required` (y `run.verdict_denied`); un segundo veredicto → 409 `verdict_exists`; resultados cambiados → 409 `run_integrity_mismatch`; reconocimiento incompleto → 409 `cases_not_acknowledged`.

**Topes que se pueden observar** (contrato H4 §A3 y Enmienda 4): reserva atómica contra el tope diario (día de Bogotá) → 409 `lab_daily_budget_exceeded`; tope por ejecución → 409 `lab_run_budget_exceeded`, y en marcha corta con `stopped: budget_exhausted` y `budgetCap`; 20 ejecuciones al día → 409 `lab_daily_runs_exceeded`; 10 lanzamientos por hora y persona → 429; estimación desfasada → 409 `estimate_changed`. Sin reintentos automáticos, `max_output_tokens` en cada llamada, un caso a la vez, plazo de 30 minutos y parada tras 2 errores seguidos del proveedor; `auth`, `quota` y `bad_request` paran en el acto.

**Evidencia.**
- Libro `ultron_studio_lab_events`: `run.created` (`estimateMaxMicros`, `acceptedMaxMicros`), `run.finished` (`costMicros`, `aiRecommendation`, `budgetCap`) y `run.verdict_recorded`.
- Tablas `ultron_studio_lab_runs` (`mode = openai`, `cost_micros`, tokens, `ai_recommendation`, `results_hash`) y `ultron_studio_lab_verdicts`. El panel de uso de OpenAI (lo guarda el propietario). El log del laboratorio solo lleva `{run, posición, clase, http}`.

```sql
SELECT r.uuid AS ejecucion, r.mode, v.decision, ver.created_by_admin_id AS autor_version, v.reviewer_admin_id AS revisor,
       (v.reviewer_admin_id <> ver.created_by_admin_id) AS cuatro_ojos,
       (v.version_content_hash = ver.content_hash) AS misma_huella, r.prompt_version, v.created_at
  FROM ultron_studio_lab_verdicts v
  JOIN ultron_studio_lab_runs r ON r.id = v.run_id
  JOIN ultron_studio_versions ver ON ver.id = v.version_id
 ORDER BY v.id DESC LIMIT 5;
```

**Criterio de éxito** (`HITO_4_COMPLETED`). Una ejecución real con OpenAI cuyo coste coincide aproximadamente con el panel, y un veredicto de una segunda persona sobre una versión (`cuatro_ojos` verdadero). Se anota en la hoja de ruta.

**Qué NO ocurre.** La clave nunca sale del entorno del servidor (ni DTOs, ni libro, ni logs, ni cliente); siempre `store: false` y URL fija en producción. El laboratorio no toca el motor (`SalesAiConfig`, `services.openai.*`) ni n8n. El veredicto no cambia la versión ni el motor, y nada se aprueba solo: la propuesta no es un veredicto.

**Vuelta atrás** (`ULTRON_STUDIO_ROLLBACK.md`, «Hito 4»).
- **Parar ya**, en cualquier fase: `ULTRON_STUDIO_LAB_ENABLED=false`, la caché como arriba y `supervisorctl restart 'ironbody-lab:*'`. **No basta con `config:cache`**: el worker sigue con la configuración que leyó al arrancar. La llamada en vuelo termina y se cobra (como mucho, un caso).
- **Volver a `fake`:** `ULTRON_STUDIO_LAB_DRIVER=fake` (y, si se quiere, quitar la clave), caché y reinicio. Las ejecuciones `openai` vivas se cierran `stopped: not_configured`.

---

## 4. Aprobación por dos identidades, y publicación del Hito 5

### 4.1 Aprobar o rechazar con cuatro ojos (F) — se puede hacer hoy

**Objetivo.** Que ninguna versión se apruebe sin una segunda identidad, y que quien aprueba reconozca exactamente los avisos vigentes.

**Precondiciones.** Una versión de A en `VALIDATED` o `REVIEW_REQUIRED` (§2). Persona B ≠ A con `ultron.approve` (Super Admin o Administrador).

**Quién.** A (para comprobar la negativa) y B.

**Pasos (CRM).**
1. **A** abre su versión: no puede aprobarla (`actions.approve` llega negado con `four_eyes`). Por la API: 403 `four_eyes_required`, y queda `version.four_eyes_denied` en el libro (una fila por persona, sujeto y motivo cada 10 minutos).
2. **B:** «Versiones» › filtro de las que esperan revisión › la versión › «Aprobar». Hay que marcar **todos** los avisos vigentes (`opcional-E14`, `opcional-E16`, `regla-S-NNN`, `reglas-sin-aplicacion`…); el botón de confirmar no se habilita hasta entonces. Nota opcional, sin datos personales. Esperado: 200 y `APPROVED`.
3. *(Alternativa negativa, sobre otra versión.)* **B:** «Rechazar», con un motivo de 5 a 500 caracteres → `REJECTED`, terminal.

Rechazos esperables: avisos distintos de los vigentes → 409 `warnings_not_acknowledged`; bloqueos tras revalidar con el catálogo actual → 409 `revalidation_blocked`; huella que no cuadra → 409 `integrity_mismatch`; segunda aprobación → 409 `invalid_transition`.

**Evidencia.** Libro: `version.four_eyes_denied`, `version.approved` (`acknowledgedWarnings`, `note`) o `version.rejected` (`reason`). Tabla `ultron_studio_version_reviews`, una fila por versión.

```sql
SELECT v.uuid, v.created_by_admin_id AS autor, r.reviewer_admin_id AS revisor, r.decision,
       (r.reviewer_admin_id <> v.created_by_admin_id) AS cuatro_ojos,
       (r.content_hash = v.content_hash) AS misma_huella, r.created_at
  FROM ultron_studio_version_reviews r JOIN ultron_studio_versions v ON v.id = r.version_id
 ORDER BY r.id DESC LIMIT 10;
```

**Criterio de éxito.** La autora no puede aprobar; B aprueba solo tras reconocer todos los avisos; `cuatro_ojos` y `misma_huella` son verdaderos.

**Qué NO ocurre.** Aprobar no cambia al agente y no aplica ninguna S-NNN. Aprobar no es publicar.

**Vuelta atrás.** No hay transición hacia atrás. Una aprobación por error se queda en el libro: esa versión simplemente no se publica, y se crea otra.

### 4.2 Publicación hoy (fase 1 del Hito 5): solo lecturas y negativas

**Objetivo.** Confirmar que, con todo apagado, publicar está cerrado y el motor no sella.

**Precondiciones.** Fase 1 desplegada (lo está). Para el smoke: autorización del usuario (escribe en la fila 32 de `admins` y en `admin_sessions`) y, para la comprobación de la vista previa, una versión `APPROVED` real (§4.1).

**Quién.** Cualquier persona con `ultron.view` (CRM). El smoke, root en el servidor.

**Pasos.**
1. **CRM:** la barra de la publicación dice Legacy, la llave del motor apagada y «Ninguno: la llave del motor está apagada». En una versión `APPROVED` **no hay botón** «Publicar en canario»: el motivo se lee en texto visible (decisión D-F1 del contrato H5).
2. **Servidor:** `cd /var/www/api/backend && sudo -u www-data php artisan ultron-studio:publication-check` → código 0 y «Cadena vacía: el motor lee Legacy.».
3. **Smoke de la fase 1** (copiando antes los dos ficheros y comparando sus huellas, como el paso 1 del runbook del H5):

```bash
# Mac, desde backend/ con el árbol en el commit desplegado y limpio
O=docs/ultron-studio/ops
shasum -a 256 $O/smoke-h5.py $O/smoke-run-h5.sh
scp $O/smoke-h5.py $O/smoke-run-h5.sh ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/smoke-run-h5.sh && chmod 600 /root/smoke-h5.py && sha256sum /root/smoke-h5.py /root/smoke-run-h5.sh'
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h5.sh'
```

Esperado: código 0. Con una versión `APPROVED`, la parte del Super Admin da 36 OK en lugar de 35: comprueba que su vista previa responde 200, no publicable, con `publishing_disabled` entre los bloqueos y las llaves `{studio: false, engine: off}`. Con un uuid inventado, publicar y promover → 409 `publishing_disabled` y Legacy, con la cadena vacía → 409 `already_legacy`, sin escribir nada.

**Evidencia.** La salida del smoke (registro `/root/h5-smoke-fase1-*.log`) y del `publication-check`. En el libro, un intento real de publicar dejaría `version.publish_refused {code}`, deduplicado.

```sql
SELECT count(*) FROM ultron_studio_publications;                                        -- 0
SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';                    -- 0 con la llave en off
```

**Criterio de éxito.** Smoke con código 0, `publication-check` con 0, la cadena vacía y 0 sellos.

**Qué NO ocurre.** El motor no consulta la cadena: con `MARKETING_ULTRON_STUDIO_CONFIG=off`, `UltronPublishedConfig` devuelve `null` sin tocar la base (Legacy exacto). Nada escribe en la cadena.

**Vuelta atrás.** Nada que deshacer. El smoke deja la cuenta 32 inactiva y sin sesiones, y borra sus ficheros de `/root/`.

### 4.3 Fase 2: publicar en canario — planificada; requiere la autorización y las decisiones del propietario

**Objetivo.** Publicar la primera versión (siempre en canario) con dos identidades y ver el sello en el siguiente turno real de la conversación canaria.

**Precondiciones** (runbook del H5, «Fase 2»):
- [ ] Fase 1 estable 24 horas: 0 ERROR del Studio o del motor, 0 sellos, `publication-check` 0, workers estables, colas fallidas sin crecer y el CRM probado con sesión real. *Cálculo:* la fase 1 terminó el 01-11-2026 a las 01:16 UTC; las 24 horas se cumplen el 01-11 a las 20:16 de Bogotá, ya fuera de la ventana, así que la primera publicación posible sería desde las 07:00 de Bogotá del 02-11-2026, y solo si la puerta del laboratorio está resuelta.
- [ ] **Puerta del laboratorio, decidida por escrito por el propietario** (el 01-11-2026 pidió respetarla: sigue en `required`):
  - **(a)** cerrar antes la fase 3 del Hito 4 (§3B) con un veredicto `approved` sobre una ejecución `openai` de **esta misma versión** (misma huella) y con la versión de prompts vigente; cuenta el veredicto **más reciente**;
  - **(b)** que el propietario escriba él mismo `waived` en el entorno (procedimiento en el runbook del H5, «Fase 2»). Queda escrito en cada publicación (`lab_gate.mode = waived`) y el CRM lo pinta en rojo. **No lo escribe nadie más.**
  - Sin una de las dos, publicar responde 409 `lab_verdict_required`, y es lo correcto.
- [ ] I-1 del reviewer (el segundo «Volver a Legacy» con el mismo motivo): el CRM desplegado, `ab78a6a`, es el commit que lo corrige según su mensaje. [PENDIENTE: confirmación del reviewer sobre el arreglo de I-1; la documentación del Hito 5 aún lo da por abierto.]
- [ ] El motor tiene canario y el freno está suelto; `engine/readings` da lo que declarará la versión.
- [ ] Una versión que refleje el motor tal como está, creada por A y aprobada por B (§2 y §4.1).
- [ ] Quien publica, con `ultron.publish` (Super Admin por defecto) y **distinto de A**, nombrado por el propietario. Basta con que sea distinto de la autora: puede ser quien aprobó.
- [ ] La autorización expresa del propietario para la fase 2.

**Quién.** Root en el servidor (alcance). En el CRM, la persona que publica.

**Pasos.**
1. **Servidor (root, con la autorización):** `bash /root/h5-engine-scope.sh canary off` (copiado antes con sus huellas). Esperado: `ALCANCE canary APLICADO (copia previa: /root/env-pre-scope-canary-<fecha>)`. Cualquier otra salida (`ESCRITO CON N DESVÍOS`, código 1): no seguir. **`canary` abre también la llave de publicar.**
2. `ssh ironbody-vps 'SMOKE_PHASE=2 bash /root/smoke-run-h5.sh'` (con los dos ficheros copiados otra vez), antes de publicar y sin nadie publicando. Esperado: código 0.
3. **CRM, de 07:00 a 19:00 de Bogotá:** en el detalle de la versión, «Publicar en canario». El diálogo pide la vista previa y enseña los diez requisitos con su motivo, «Lo que declara frente a lo que lee el motor» (los cuatro `must_match`) y «Sin efecto en este hito», con una casilla por elemento. Marcar todas; nota opcional sin datos personales; enviar. Esperado: 201, la versión en `CANARY` y la entrada `seq = 1` en la cadena.
4. **El sello**, en el siguiente turno de la conversación canaria (con poco tráfico, puede tardar días):

```sql
SELECT created_at, metadata->'studio'->>'state' AS estado, metadata->'studio'->>'part' AS parte,
       metadata->'studio'->>'reason' AS motivo
  FROM marketing_ai_actions WHERE metadata ? 'studio' ORDER BY id DESC LIMIT 5;      -- applied · canary
```

5. *(Opcional, plan del architect §8.)* Simulacro de reversión (§4.5): el turno siguiente sale `legacy`. La versión revertida queda `ROLLED_BACK`, terminal: volver a publicar exige otra versión y otra aprobación.

Rechazos esperables al publicar (contrato H5 §A5.1): fuera de la ventana → 409 `outside_publication_window`; la autora → 403 `four_eyes_required`; sin veredicto → 409 `lab_verdict_required`; lo declarado ≠ el motor → 409 `engine_value_mismatch`; una lectura del motor caída → 409 `engine_reading_unavailable`; la vista previa cambió → 409 `preview_stale`; lo «sin efecto» sin reconocer entero → 409 `no_effect_not_acknowledged`; freno echado → 409 `engine_braked`; otra versión en canario → 409 `canary_in_progress`.

### 4.4 Fase 3: todas las conversaciones y la promoción — planificada; requiere la autorización del propietario

**Precondiciones.**
- [ ] La evidencia del canario pasa (`GET versions/{uuid}/canary-evidence` → `passes: true`; el CRM la enseña en «Promover a todas»): al menos 24 horas y 3 turnos, `stamped` igual a `turns` (el 100 % `applied` con esta versión), 0 frenos y el freno suelto, el acta del canario con total 0, sin deriva y la firma de la cabeza correcta.
- [ ] Ningún sello `fallback` en la ventana: un turno así se queda para siempre en la evidencia y ese canario ya no se promueve.
- [ ] La autorización expresa del propietario para la fase 3.

**Pasos.**
1. `bash /root/h5-engine-scope.sh all canary` → `ALCANCE all APLICADO (copia previa: …)`.
2. `ssh ironbody-vps 'SMOKE_PHASE=3 bash /root/smoke-run-h5.sh'` (con los ficheros copiados otra vez).
3. **CRM**, una persona distinta de la autora, dentro de la ventana: «Promover a todas», con la evidencia que se acaba de ver. Sin la llave del motor en `all` → 409 `scope_not_allowed`. La evidencia y la promoción comparten un limitador de 10 por minuto y persona y 30 por minuto y red.
4. `cd /var/www/api/backend && sudo -u www-data php artisan ultron-studio:publication-check` → 0, con `status.engine.activeVersion` igual a la versión y `state: applied`.

**Honestidad.** Con el canario del motor puesto, `canary` y `all` llegan a la misma conversación: la promoción no cambia el tráfico.

### 4.5 Revertir y volver a Legacy — siempre disponibles

- **Quién:** `ultron.rollback` (Super Admin por defecto). Motivo de 5 a 500 caracteres. Sin llaves de entorno, sin cuatro ojos y sin ventana.
- **«Revertir»** (detalle de la versión): una versión en `CANARY` pasa a `ROLLED_BACK` y el canario vuelve a lo que tenga el resto. Una `PUBLISHED` pasa a `ROLLED_BACK` y vuelve la anterior `RETIRED` si verifica; si no, 409 `restore_integrity_mismatch`, y la salida es Legacy.
- **«Volver a Legacy»** (barra de la publicación): `CANARY` → `ROLLED_BACK` y `PUBLISHED` → `RETIRED`. Funciona con cualquier alcance del motor y con una cabeza que no verifica. Con la cadena ya en Legacy → 409 `already_legacy`.

**Evidencia de §4.3 a §4.5.**
- Libro: `version.published` (con `labGate` y `noEffect`), `version.promoted` (con la evidencia), `version.retired`, `version.rolled_back`, `version.restored`, `system.publication_legacy`, los rechazos auditados (`version.publish_refused`, `version.promote_refused` y `version.rollback_refused`, deduplicados) y `version.four_eyes_denied`.
- La cadena:

```sql
SELECT seq, action, version_id IS NOT NULL AS con_version, left(effective_hash, 12) AS huella,
       lab_gate->>'mode' AS puerta, actor_admin_id, created_at
  FROM ultron_studio_publications ORDER BY seq DESC LIMIT 10;
SELECT status, count(*) FROM ultron_studio_versions WHERE status IN ('CANARY', 'PUBLISHED') GROUP BY status;   -- como mucho 1 de cada
```

- Los sellos por estado y la salida de `publication-check` (§8).

**Criterio de éxito.** Fase 2: una entrada `publish` firmada, la versión en `CANARY` y los turnos del canario sellados `applied`/`canary`. Fase 3: la evidencia pasa, una entrada `promote` y la versión en `PUBLISHED`. Siempre: como mucho una `CANARY` y una `PUBLISHED`, y `publication-check` con 0.

**Qué NO ocurre** (opción A del contrato H5). Publicar **no cambia lo que ULTRON dice ni hace**: no enciende ni apaga ULTRON, no mueve el canario, no toca el cobro, la pausa, el freno, los planes, la base de conocimiento ni n8n, y ninguna S-NNN se aplica. El Studio no frena ni revierte solo por deriva: la enseña, bloquea la promoción y `publication-check` sale con 1.

**Vuelta atrás** (`ULTRON_STUDIO_ROLLBACK.md`, «Hito 5»).

| Desde → hacia | Qué hacer |
|---|---|
| Fase 3 → 2 | `bash /root/h5-engine-scope.sh canary all` |
| Fase 2 o 3 → 1 (parar de leer) | `bash /root/h5-engine-scope.sh off canary` (o `off all`): el motor deja de consultar y de sellar |
| Parar de declarar | «Volver a Legacy» |
| Una versión concreta | «Revertir» |

Con la cadena ya escrita, el smoke de la fase 1 da 4 FALLO **por diseño**; se verifica con la salida del script, `publication-check` y el recuento de sellos, que con la llave en `off` no crece.

---

## 5. Números canarios (Hito 6) — planificado; en implementación, no desplegado

**Estado.** **No implementado en producción.** En la rama local `feat/ultron-studio-h6` están la tabla `ultron_studio_canary_numbers` y sus guardas (paquete DB). No existen todavía la API (rutas 42 y 49–54), la página del CRM `marketing/ultron-studio/integraciones` ni el script `h6-integrations-scope.sh`. Esta sección es el procedimiento **planificado** para cuando el Hito 6 esté en sus fases 1 y 2 (contrato H6 §I4 y «Despliegue»).

**Objetivo.** El T1 del contrato: registrar el número de la conversación canaria del motor con consentimiento, autorizarlo y activarlo con cuatro ojos, y ver `in_sync`.

**Precondiciones.**
- El Hito 6 desplegado en su fase 1 (con el Hito 5 en su fase 1, como mínimo) y las escrituras encendidas (fase 2: `h6-integrations-scope.sh writes on`, script **planificado**).
- El consentimiento de la persona titular del teléfono. El soporte lo custodia el propietario.
- **Dos personas reales:** quien registra (tiene el teléfono de la conversación canaria; `ultron.canary_manage`) y quien autoriza (`ultron.canary_authorize`, solo Super Admin por defecto), distinta de quien registró. La cuenta de smoke solo sirve para ensayar, **nunca** para el T1 real.

**Quién.** Persona R (registra) y persona Z (autoriza y activa; ≠ R).

**Pasos (CRM, pestaña «Números canarios»).**
1. **R registra:** canal WhatsApp; teléfono en formato internacional (en Colombia, WhatsApp exige un móvil: `+57` seguido de 3 y nueve cifras); un alias por función («Móvil de pruebas 1»: de 3 a 40 caracteres, sin series de 4 o más cifras ni `@`); la casilla «La persona titular de este número aceptó recibir mensajes de prueba (y, si es de voz, llamadas de prueba en el futuro).»; y, opcional, la versión esperada. Esperado: 201, estado «Pendiente de autorizar (registro)» y el número enmascarado (`+57 ••• ••• NNNN`). **El número completo no vuelve a salir nunca.**
2. **R intenta autorizar:** no hay botón, y el motivo se lee en texto (un Administrador no tiene la llave; un Super Admin es quien registró). Por la API, un Super Admin que registró recibe 403 `four_eyes_required` con `action: authorize`, y queda el evento `canary_number.four_eyes_denied`.
3. **Z autoriza** → «Autorizado (registro)».
4. **Z activa** (≠ R; como mucho 3 activos por canal, si no 409 `canary_active_limit`) → «Activo (registro)».
5. **Coherencia** (aviso de la pestaña): `in_sync` solo si el número activo es el teléfono del lead de la conversación canaria del entorno y no hay otro WhatsApp activo. Los demás veredictos: `engine_canary_not_registered`, `engine_canary_registered_inactive`, `registry_has_extra_active`, `engine_canary_phone_unusable`, `no_engine_canary` y `unreadable`. Nunca `in_sync` por defecto.
6. *(Opcional.)* **Versión esperada:** se compara con la que rige según la cadena del Hito 5 → `match`, `mismatch`, `none` o `not_applicable`.

**Evidencia.** Libro: `canary_number.registered`, `canary_number.authorized` y `canary_number.activated`, con dos identidades, más `canary_number.four_eyes_denied`. En el libro nunca van teléfonos, sus 4 cifras, huellas ni alias. En la base, los CHECK `uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk` impiden autorizar o activar con la identidad de quien registró, también con un UPDATE crudo.

```sql
-- válida contra el esquema del paquete DB del H6 (ensayada en local); en producción, solo tras su fase 1
SELECT uuid, channel, status, registered_by_admin_id AS registro, authorized_by_admin_id AS autorizo,
       activated_by_admin_id AS activo,
       (authorized_by_admin_id IS NULL OR authorized_by_admin_id <> registered_by_admin_id) AS cuatro_ojos_autorizar,
       (activated_by_admin_id IS NULL OR activated_by_admin_id <> registered_by_admin_id) AS cuatro_ojos_activar,
       expected_version_id IS NOT NULL AS con_expectativa, status_changed_at
  FROM ultron_studio_canary_numbers ORDER BY id DESC LIMIT 10;
```

**Criterio de éxito** (cierre del Hito 6, punto 2). `in_sync` en producción, con dos identidades en el libro.

**Qué significa y qué no.**
- **Es un registro**, y la asociación con una versión es una **expectativa auditada**: dice qué versión se espera probar con ese número. La que rige sale solo de la cadena del Hito 5. Nadie usa la expectativa para decidir: ni el motor, ni publicar, ni la política de voz.
- **El motor no lee el registro** en el Hito 6 (T0 y T1). El canario del motor sigue siendo el id de conversación del entorno, y el texto obligatorio del CRM lo dice: «Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.».
- `MARKETING_ULTRON_ENABLED`, la pausa y el freno mandan por encima en todas las fases. El canario del cobro queda fuera del registro.
- Que el motor lea el registro (T2) o lo use en lugar del id (T3) son fases futuras, cada una con su autorización expresa.

**Qué NO ocurre.** Ningún mensaje ni llamada; ningún cambio en a quién responde ULTRON; el teléfono de WhatsApp se guarda solo como huella HMAC y 4 cifras (el de voz, cifrado, y se borra al revocar).

**Vuelta atrás.** «Desactivar» (motivo; vuelve a autorizado) o «Revocar» (motivo; terminal; borra el cifrado de voz). Las escrituras se cierran con `h6-integrations-scope.sh writes off` (planificado). Nunca DELETE (lo impide `uscn_forbid_delete`) y nunca `down()` en producción.

---

## 6. Diagnóstico de Twilio y verificación de webhooks

### 6.1 Lo que existe hoy: el webhook `usage-trigger` — se puede probar hoy

**Qué es** (leído en el código de `origin/main`). `POST /api/webhooks/twilio/usage-trigger` (`routes/api.php:636`, cupo de 60 por minuto), en `TwilioUsageTriggerController`. Twilio lo llama cuando salta un aviso de gasto. **No es un control:** avisa de un gasto que ya ocurrió; el freno del gasto del OTP es `OtpCostGuard`.
- **Firma:** HMAC-SHA1 con el Auth Token del OTP (`otp.twilio.token`) sobre la URL completa más los parámetros POST en orden alfabético de clave, en base64, comparada en tiempo constante con `X-Twilio-Signature`. Sin token configurado o sin firma, falla cerrado: 403 `{"ok":false}` y una línea WARNING `twilio.usage_trigger.firma_invalida` con la IP de quien llamó.
- **Idempotencia:** `IdempotencyToken` con `Cache::add` durante 24 horas (en producción la caché es `database`, así que sobrevive a reinicios). Un aviso repetido → 200 `{"ok":true,"duplicate":true}`.
- **Un aviso válido** solo deja la línea WARNING `twilio.usage_trigger.alerta` en el log y responde 200 `{"ok":true}`. No envía nada.

**Objetivo.** Comprobar desde fuera que el webhook rechaza lo no firmado, sin gastar un SMS.

**Precondiciones.** Ninguna; no hace falta sesión.

**Quién.** Quien opera, desde su equipo (la IP queda en el log).

**Pasos.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.ironbodyneiva.cloud/api/webhooks/twilio/usage-trigger
# esperado: 403
ssh ironbody-vps "grep -c 'twilio.usage_trigger.firma_invalida' /var/www/api/backend/storage/logs/laravel.log"
# sube en 1 respecto a antes de la prueba (si el log es diario, el fichero del día)
```

No ejecutado contra producción. El comportamiento lo cubren 7 pruebas, que pasan en este árbol (`php vendor/bin/phpunit tests/Feature/Otp/TwilioUsageTriggerWebhookTest.php`, 7/7, ejecutadas el 01-11-2026): sin firma, una firma ajena, un valor manipulado y sin token → 403; un aviso firmado se acepta y se registra; el reintento de Twilio no duplica; dos avisos distintos se registran por separado.

**Evidencia.** El código HTTP y la línea `twilio.usage_trigger.firma_invalida` en `storage/logs/laravel.log`.

**Criterio de éxito.** 403 sin cuerpo útil, y la línea en el log.

**Qué NO ocurre.** Ningún SMS, ninguna petición a Twilio, ningún coste. Con la firma inválida no se llega a la idempotencia. Lo único que se escribe, además de la línea del log, es el contador del cupo de 60 por minuto, que guarda la caché (`config/cache.php` no fija otra para el limitador, y en producción la caché es `database`).

**Qué NO hacer.** Fabricar una firma válida con el Auth Token de producción para «probar el camino bueno»: es la credencial del OTP, y manejarla fuera del servidor es exponerla. Ese camino ya lo cubren las pruebas.

**Vuelta atrás.** No hay nada que deshacer: queda una línea WARNING en el log, y el contador del cupo caduca solo al minuto.

### 6.2 Panel de Twilio, configuración autorizada y sonda (Hito 6) — planificado

**Estado.** **No implementado en producción.** En la rama local están el cliente de solo lectura (`TwilioReadOnlyClient`) y el saneador de la sonda (paquete TW). La API (rutas 43, 44, 46, 55 y 56), el CRM y los scripts no existen todavía.

**Lo que hará** (contrato H6 §I5 y §I7):
- **Estado del proveedor por el espejo local, sin red** (`GET integrations/twilio`): la clasificación `connected_otp_only`, los recuentos de retos verificados del OTP, el gasto estimado del día (`OtpCostGuard`), las credenciales enmascaradas o como huella, si los dos pares de nombres (`TWILIO_SID`/`TWILIO_ACCOUNT_SID` y `TWILIO_TOKEN`/`TWILIO_AUTH_TOKEN`) son la misma credencial, la descripción fija del webhook `usage-trigger`, la voz `not_configured` y los recuentos de `marketing_calls`, sin teléfonos.
- **Configuración autorizada por huellas, sin editar el entorno** (`GET integrations/config` y `POST integrations/config/acknowledge`, con `ultron.integrations_manage`): un Super Admin reconoce la configuración vigente de Meta Ads, Twilio y voz por su huella; si cambió entretanto → 409 `config_changed`. Estados: `never_authorized`, `authorized` o `drift`. No depende de la bandera de escrituras, y nunca enseña ni edita secretos. `devops` lanza `ultron-studio:integrations-snapshot` en cada despliegue: el «observado».
- **Sonda GET, apagada por defecto** (`POST integrations/twilio/probe`). Se enciende con **las tres cosas a la vez**:
  1. la bandera `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` (en la fase 2, `h6-integrations-scope.sh probe on`; si no → 409 `probe_disabled`);
  2. el permiso `ultron.integrations_manage` (solo Super Admin por defecto);
  3. el cupo `ultron-studio-probe` (6 por hora y persona, 20 por hora y red) y un enfriamiento de 300 s, en el que se reutiliza la última sonda sin red (`reused: true`).

  Hace cinco GET a la primera página (`account`, `numbers`, `caller_ids`, `calls` y `alerts`), con los hosts oficiales fijos en producción y 30 s en total. Nunca responde 5xx. La credencial es una API key restringida si el propietario la crea (`ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` y `…_KEY_SECRET`) y, si no, la del OTP. Solo guarda recuentos saneados y las 4 últimas cifras de los números **del gimnasio**, y responde a «¿hay número de voz o solo caller ID?».

**Evidencia, cuando exista.** La tabla `ultron_studio_integration_snapshots` (solo inserción) y los eventos `integration.config_acknowledged` e `integration.probe_run`.

```sql
-- válida contra el esquema del paquete DB del H6 (ensayada en local); en producción, solo tras su fase 1
SELECT provider, kind, outcome, actor_type, left(fingerprint, 12) AS huella, created_at
  FROM ultron_studio_integration_snapshots ORDER BY id DESC LIMIT 10;
```

**Qué NO ocurre.** La sonda no envía mensajes ni hace llamadas; el texto obligatorio del CRM lo dice: «Solo lee: no envía mensajes ni hace llamadas. Puede tardar unos segundos.». Con las llaves por defecto, ninguna ruta del Hito 6 sale a la red.

**Vuelta atrás.** `h6-integrations-scope.sh probe off` (planificado).

### 6.3 `voice:doctor` → `ELEVENLABS_NOT_CONFIGURED`

**Estado.** El comando existe solo en la rama local `feat/ultron-studio-h6` (paquete V, `0014292`). **No está en `origin/main` ni en producción**, así que hoy no se puede lanzar allí.

**Cuando se despliegue el Hito 6** (leído en el código de la rama; no ejecutado por esta documentación):

```bash
cd /var/www/api/backend && sudo -u www-data php artisan voice:doctor
```

Sin ninguna variable de ElevenLabs en el entorno, la salida esperada es: la línea «Estado» con `ELEVENLABS_NOT_CONFIGURED`; la línea «Faltan» con `api_key (ELEVENLABS_API_KEY), agent_id (ELEVENLABS_AGENT_ID), webhook_secret (ELEVENLABS_WEBHOOK_SECRET)` (la región no falta: vale `default` por defecto); «Llamadas : cerradas en el código. En este hito no se hace ninguna llamada.»; «Sin configurar: faltan piezas. No sale nada a la red.»; y código 0. Nombra las piezas que faltan, nunca valores. Con `--verify` en ese estado no llama a nada y sale con 1. `--json` da el mismo estado en JSON.

---

## 7. Eventual llamada real de prueba — fuera del alcance; hoy imposible

**Hoy ningún código puede llamar** (comprobado en el código de `origin/main` el 01-11-2026):
- no hay TwiML, webhook de voz, marcador ni ninguna llamada a la API de voz de Twilio;
- `marketing:dispatch-followups` solo crea filas `pending` en `marketing_calls` «sin contactar a Twilio» (comentario de `DispatchMarketingFollowups.php`);
- no hay variables `TWILIO_VOICE_*` en el entorno ni número de voz o caller ID verificado conocido, y `marketing_calls` tenía 0 filas el 31-10-2026 (contrato H6, «Hechos de producción»);
- en la rama del Hito 6, `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false` y el puerto de voz no tiene ningún método que inicie, registre ni transfiera una llamada: la política responde siempre `allowed: false`, con `calls_disabled_in_code` como primer motivo.

**Checklist previo** para el hito de voz, que **no está implementado** ni planificado con fecha. Todo tiene que estar en verde antes de una sola llamada:
- [ ] **Autorización expresa del propietario para esa llamada.** Hoy rige el límite absoluto del 25-10-2026: sin llamadas reales.
- [ ] **Presupuesto:** una subcuenta de voz con su presupuesto y el cupo diario (`TWILIO_VOICE_DAILY_CALL_CAP`: 0 por defecto en la rama del Hito 6, con tope 20).
- [ ] **Número de voz colombiano en Twilio o caller ID verificado** (la sonda del §6.2 lo responde). Nunca el número productivo de WhatsApp.
- [ ] **Decisiones del propietario:** grabación y retención (Ley 1581: consentimiento y plazo) y si la voz puede decir precios.
- [ ] **Controles, todos abiertos a propósito:** `TWILIO_VOICE_ENABLED=true` (hoy no está en el entorno, y en la rama del Hito 6 su defecto es `false`) y `TWILIO_VOICE_FROM`; `CALLS_ENABLED_IN_CODE` girado solo en el commit que implemente las llamadas; ElevenLabs en `ELEVENLABS_VERIFIED`; un número canario **de voz** activo con cuatro ojos (§5); la fuente `canary_registry`, nunca `marketing_calls`.
- [ ] **Lo que debe traer ese hito** (contrato H6 §I6.7): su TwiML, el validador de Twilio extraído, la ruta del webhook post-llamada con el verificador de firma, la lista de permitidos de canarios de voz, `TimeLimit`, el cupo diario y su propio canario.
- [ ] **Destinatario:** un número canario de voz cuya persona titular consintió.
- [ ] **Vuelta atrás preparada:** `ELEVENLABS_ENABLED=false`, `TWILIO_VOICE_ENABLED=false` y la caché regenerada.

---

## 8. Evidencia de comportamiento, errores, auditoría y vuelta atrás

### 8.1 Comandos de evidencia (servidor, solo lectura)

Desde `/var/www/api/backend`, en `ironbody-vps`:

```bash
cd /var/www/api/backend
sudo -u www-data php artisan ultron-studio:publication-check          # (1) o con --json
sudo -u www-data php artisan queue:health                             # (2) o con --json
supervisorctl status | grep -c RUNNING                                # (3)
supervisorctl status | grep -c 'BACKOFF\|FATAL'                       # (3)
systemctl is-active ironbody-billing-worker                           # (3)
grep -c 'production.ERROR' storage/logs/laravel.log                   # (4)
grep -cE 'ultron_studio\.publication_state_unavailable|auth:admin:unmapped_route|canary_acta_unavailable' storage/logs/laravel.log   # (4)
grep ' /api/admin/ultron-studio/' /var/log/nginx/access.log | awk '{print $9}' | sort | uniq -c   # (5)
sudo -u www-data php artisan security:check-secret-exposure           # (6)
sudo -u www-data php artisan ultron:canary-report <id de la conversación canaria> --since=<fecha ISO> --json   # (7)
```

Y en la base, con el patrón de solo lectura del principio:

```sql
SELECT metadata->'studio'->>'state' AS estado, metadata->'studio'->>'part' AS parte, count(*)
  FROM marketing_ai_actions WHERE metadata ? 'studio' AND created_at > now() - interval '24 hours'
 GROUP BY 1, 2 ORDER BY 1, 2;                                                              -- (8)
SELECT count(*) FROM failed_jobs;                                                          -- (9)
SELECT count(*) FROM ultron_aborts WHERE released_at IS NULL;                              -- (10)
SELECT event, count(*) FROM ultron_studio_audit_events
 WHERE created_at > now() - interval '24 hours' GROUP BY event ORDER BY event;             -- (11)
SELECT event, count(*) FROM ultron_studio_lab_events GROUP BY event ORDER BY event;        -- (12)
```

| # | Qué | Esperado hoy | Notas |
|---|---|---|---|
| 1 | Publicación vigente | Código 0 y «Cadena vacía: el motor lee Legacy.» | Sale 1 ante `head_invalid` o `drift`. Nunca imprime ids ni secretos |
| 2 | Colas | Sin incidentes; el carril `lab`, con su umbral de 300 s | — |
| 3 | Workers | 13 RUNNING, 0 en BACKOFF o FATAL; el worker de facturación, `active` | — |
| 4 | Errores en el log | El primero, igual que la línea base anotada; el segundo, 0 | Si el log es diario, el fichero del día |
| 5 | 5xx del Studio | Ningún 5xx | Si el formato de nginx es el `combined` |
| 6 | Secretos | En verde | — |
| 7 | Acta del canario | Código 0 y `findings.mecanicos_total` = 0 (sin `--json`, la salida termina en «CANARIO SIN HALLAZGOS MECANICOS…») | Sale 1 con hallazgos. **Siempre con `--since`**: sin ventana cuenta el histórico de la conversación (memoria del proyecto `canario-ultron-acta-y-ventana`). Siete contadores mecánicos: traspasos no autorizados, horarios aplazados en una persona, planes no vendibles, precios fuera del catálogo, enlaces no oficiales, datos personales y negaciones de un cobro disponible. El id es el valor de `MARKETING_ULTRON_CANARY_CONVERSATION_ID`, que conoce quien gobierna el motor, y no se escribe en actas ni chats. **La salida lleva el id de la conversación y el texto de los turnos:** no se pega ni se archiva. Es preferible la evidencia del Studio (`GET versions/{uuid}/canary-evidence`, con una versión en canario), que solo devuelve recuentos |
| 8 | Sellos | Ninguna fila (llave en `off`) | Con `canary`: `applied`/`canary`. Legacy: `legacy`. Fuera del alcance: `out_of_scope`. Fallos: `fallback`, con `reason` |
| 9 | Colas fallidas | Igual que en el paso 2 del despliegue | Por SQL: en Laravel 12 cambió la salida de `queue:failed` y el recuento por `grep` daba siempre 0 |
| 10 | Freno | Igual que antes (0 = suelto) | El Studio no lo toca |
| 11 | Libro del Studio | Los eventos de las pruebas del día | Sin IP ni user agent |
| 12 | Libro del laboratorio | `system.lab_catalog_imported` y `scenario.catalog_imported` del despliegue, más los de las pruebas | — |

### 8.2 Errores que se verán en las pruebas

| Código | Dónde | Qué significa | Qué hacer |
|---|---|---|---|
| 409 `draft_conflict` | Guardar borrador | Otra pestaña guardó antes | Recargar o sobrescribir |
| 422 `validation_blocked` | Crear versión | El borrador tiene bloqueos | Corregir y guardar |
| 409 `rule_contradiction` · 422 en `conditions.*` | Reglas | Contradice otra S-NNN · contradice un control del motor | Es lo esperado en §1 |
| 403 `four_eyes_required` | Aprobar, veredicto, publicar, promover; en el H6, autorizar y activar | La misma identidad que creó | Otra persona |
| 409 `warnings_not_acknowledged` · `revalidation_blocked` · `integrity_mismatch` | Aprobar | Avisos distintos · bloqueos con el catálogo actual · huella alterada | Releer · versión nueva · avisar a `backend` |
| 409 `run_not_reviewable` | Veredicto | Ejecución `fake`, parcial o que no es sobre una versión | Hace falta una ejecución `openai` completa sobre la versión |
| 409 `publishing_disabled` · `engine_not_reading_versions` | Publicar | Llave del Studio cerrada · llave del motor en `off` | Es la fase 1: correcto |
| 409 `lab_verdict_required` | Publicar | Puerta `required` sin veredicto `approved` | §3B o la decisión `waived` del propietario |
| 409 `outside_publication_window` | Publicar, promover | Fuera de 07:00–19:00 de Bogotá | Esperar a la ventana |
| 409 `publication_head_invalid` | Publicar, promover, revertir | La cabeza no verifica (fila alterada o rotación de la `APP_KEY`) | `h5-engine-scope.sh off <alcance actual>` y «Volver a Legacy»; ver la reversión del H5 |
| 413 `payload_too_large` | Cualquier ruta del Studio | Cuerpo de más de 64 KB | — |
| 429 `too_many_requests` | Lecturas 120/min, escrituras 30/min por credencial; evidencia y promoción 10/min por persona | Cupo | Esperar `retry_after` |

### 8.3 Vuelta atrás por nivel

| Nivel | Cómo | Pierde datos | Fuente |
|---|---|---|---|
| Datos de prueba del Studio | Archivar reglas y escenarios; descartar el borrador | Nada (las filas se quedan) | §1, §3A |
| Laboratorio | Bandera apagada o driver `fake`, caché y `supervisorctl restart 'ironbody-lab:*'` | Nada | `ULTRON_STUDIO_ROLLBACK.md`, «Hito 4» |
| Alcance del motor (fase) | `h5-engine-scope.sh` con el alcance de destino y el actual, con la autorización del propietario | Nada | «Hito 5», «Vuelta atrás por fase» |
| Declaración | «Volver a Legacy» o «Revertir» | Nada: entradas nuevas en la cadena | Ídem |
| Código del backend | Solo si el código del H5 se porta mal, y solo si producción sigue en el commit de la fase 1 (`7ba0ce8`): checkout al `api-commit.txt` del respaldo (`7318122`), cachés y `queue:restart`. Si producción avanzó, **no hay checkout**: se sale por fase y se decide un revert con `devops` | Nada en la base | «Hito 5», «Vuelta atrás del código» |
| Datos | Solo ante corrupción, con autorización expresa y `database-specialist`: restauración a una base aparte, nunca encima de la viva | Todo lo escrito desde el volcado | «Hito 5», «Vuelta atrás de datos» |
| Hito 6 (planificado) | Desactivar y revocar; `h6-integrations-scope.sh writes off` / `probe off`; volver al backend del H5 | Nada | Contrato H6, «Reversión» |

**Nunca:** `migrate:rollback` ni `down()` en producción; DELETE o UPDATE a mano sobre tablas `ultron_studio_*`; `queue:retry all` a mano (reencola los fallos de todos los carriles, también los del motor); imprimir o pegar la `APP_KEY` o cualquier clave.

---

## Orden de ejecución recomendado

Primero lo aislado y sin llamadas; después lo que escribe en libros permanentes; al final lo que depende del propietario.

| # | Prueba | Sección | Escribe | Coste | Puede hacerse |
|---|---|---|---|---|---|
| 1 | Verificación de la fase 1 del H5 a los 15 min y a las 24 h | §8.1 | No | 0 | Ya (pendiente) |
| 2 | Lecturas del motor, inspector y permisos (pasos 1–4 y 12) | §1 | No | 0 | Hoy |
| 3 | Webhook `usage-trigger` sin firma → 403 | §6.1 | Una línea de log y el contador del cupo | 0 | Hoy |
| 4 | Borrador, reglas con condiciones, 409 y 422 | §1 | Borrador, reglas y libro | 0 | Hoy, con la decisión del propietario sobre las filas |
| 5 | Versión, huella, comparación y libro | §2 | Versión y libro | 0 | Hoy |
| 6 | Aprobar o rechazar con cuatro ojos | §4.1 | Reseña y libro | 0 | Hoy, con dos personas reales |
| 7 | Laboratorio en `fake`: escenarios y estimación | §3A (pasos 1–4) | No (salvo un escenario que pase el filtro) | 0 | Hoy |
| 8 | Ejecución `fake` | §3A (paso 5) | Ejecución y libro | 0 | Hoy, si el propietario acepta las filas |
| 9 | Smoke de la fase 1 con una versión `APPROVED` | §4.2 | Fila 32 de `admins` y sesiones (las limpia) | 0 | Hoy, con autorización del usuario |
| 10 | Fase 3 del laboratorio con OpenAI | §3B | Ejecuciones, veredicto y libro | Dentro de los topes del propietario | Con la clave del propietario |
| 11 | Fase 2 del H5: publicar en canario | §4.3 | Cadena, libro y sellos | 0 | Tras 24 h estables, la puerta resuelta, dos personas y la autorización |
| 12 | Fase 3 del H5: promover | §4.4 | Cadena y libro | 0 | Con la evidencia del canario |
| 13 | Números canarios (T1), configuración autorizada y sonda | §5, §6.2 | Registro, instantáneas y libro | 0 (GET sin coste) | Tras desplegar el Hito 6 |
| 14 | `voice:doctor` → `ELEVENLABS_NOT_CONFIGURED` | §6.3 | No | 0 | Tras desplegar el Hito 6 |
| 15 | Llamada real | §7 | — | Real | **Fuera del alcance** |

## Dependencias del propietario

| Dependencia | Bloquea | Notas |
|---|---|---|
| **Clave de OpenAI del laboratorio** (`ULTRON_STUDIO_LAB_OPENAI_API_KEY`): proyecto dedicado, presupuesto propio, clave nueva distinta de `OPENAI_API_KEY` y de la de n8n; retención confirmada; modelo, precios oficiales y topes | §3B, y la vía (a) de §4.3 | La escribe él en el servidor, nunca por chat. Hoy falta (matriz de credenciales) |
| **Decisión sobre la puerta del laboratorio:** (a) cerrar §3B o (b) escribir él mismo `waived` | §4.3 | Desde el 01-11-2026, `required` por su decisión; nadie más la cambia |
| **Segunda persona real** (y quien publique, con `ultron.publish` y distinta de la autora) | §3B (veredicto), §4.1, §4.3, §4.4 y §5 (T1) | Nunca la cuenta de smoke |
| **Autorización expresa por fase** (fase 2 y fase 3 del H5) y para cada smoke | §4.2–§4.4 | El contrato H5 exige una orden expresa por fase; el lead entiende que la del 25-10-2026 las cubre si cumplen sus criterios (informe final, «Autorizaciones»): confirmarlo |
| **Aceptar filas permanentes** en producción (reglas, versiones, ejecuciones, veredictos, cadena) | §1–§4 | Sin procedimiento de redacción del libro todavía (Hito 7) |
| **Caracteres invisibles en motivos** (AS-5): decidir si se filtran | Mejor antes de §4.3 | Acaban en `ultron_studio_publications.reason`, que no se puede editar ni borrar |
| **API key restringida de Twilio** (`ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` y `…_SECRET`), opcional | Nada: sin ella, la sonda usa la credencial del OTP | Solo para §6.2 |
| **Credenciales de ElevenLabs** (clave acotada, `agent_id`, secreto del webhook, región; además `voice_id` según la matriz) | Nada en el Hito 6 (su estado final previsto es `ELEVENLABS_NOT_CONFIGURED`) | Solo para activar la voz, con autorización expresa |
| **Número de voz o caller ID verificado, subcuenta, grabación y si la voz dice precios** | §7 | Fuera del alcance actual |
| **`TWILIO_FROM` vacío:** confirmar si el envío por Messages está en uso | Nada de este documento | Matriz de credenciales |

## Pendientes de este documento

- [PENDIENTE: verificación de la fase 1 del Hito 5 a los 15 minutos y a las 24 horas (lead o `devops`).]
- [PENDIENTE: confirmación del reviewer sobre el arreglo de I-1 en el CRM `ab78a6a` antes de la fase 2.]
- [PENDIENTE: primera ejecución en el servidor del patrón de solo lectura con `PGOPTIONS` (anotar que `SHOW default_transaction_read_only` dice `on`).]
- [PENDIENTE: contrastar §5, §6.2 y §6.3 con la API, el CRM y los scripts del Hito 6 cuando existan; hoy solo hay contrato y paquetes DB, V y TW en una rama local.]
- [PENDIENTE: el comando de §6.1 no se ha ejecutado contra producción.]

## Fuentes

Contratos `ULTRON_STUDIO_F_CONTRACT.md`, `ULTRON_STUDIO_G_CONTRACT.md`, `ULTRON_STUDIO_H4_CONTRACT.md`, `ULTRON_STUDIO_H5_CONTRACT.md` y `ULTRON_STUDIO_H6_CONTRACT.md`; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` (runbooks del Hito 4 y del Hito 5); `ULTRON_STUDIO_ROLLBACK.md`; `ULTRON_STUDIO_FINAL_REPORT.md`; `ULTRON_STUDIO_MASTER_ROADMAP.md`; `ULTRON_STUDIO_VISUAL_REPORT.md`; `ops/README.md` y los scripts de `ops/`; `plans/informes-h6/matriz-credenciales-vps.md`. Código de `origin/main` (`41b6470`): `routes/ultron-studio.php` (38 rutas), `routes/api.php:636`, `TwilioUsageTriggerController.php`, `PublicationCheckCommand.php`, `UltronCanaryReport.php`, `DispatchMarketingFollowups.php`, `StudioCatalog.php` y `config/app.php`. Rama local `feat/ultron-studio-h6`: `VoiceDoctorCommand.php`, `VoiceCallPolicy.php` y `config/voice.php`. CRM `ab78a6a`: las etiquetas de los botones.
