# Informe de backend: menores M-2, M-3 y M-4b de las plantillas de Meta y el hueco del exportador

Fecha: 2026-11-11. Rama `feat/ultron-studio-h9`, HEAD `1c8f70bb` (backend) y `89a24f9` (CRM). Trabajo en local: sin commit, sin push, sin producción y sin red real.

Origen:

- `informe-reviewer-ola.md`, sección «Menores»: M-2, M-3 y M-4 (el segundo punto);
- `informe-crm-studio.md`, «Vocabularios desfasados», apartado C.

## ⚠ Primero: el árbol principal estuvo sucio entre las 13:53Z y las 14:02:36Z

Tu aviso de trabajar en una copia me llegó **cuando ya había terminado** el encargo en el árbol principal.

- **Ventana en la que hubo cambios míos sin confirmar** (horas UTC, sacadas de las mtimes conservadas en el respaldo):

  | Árbol | Desde | Hasta |
  |---|---|---|
  | `backend/app` y `backend/tests` | 13:53:33Z | 14:02:36Z |
  | CRM (fixture y spec) | 13:58:47Z | 14:02:36Z |
  | `backend/docs/…/informe-be-fixes-2.md` (sin seguimiento) | 14:01:36Z | 14:02:36Z |

  - Entre 13:57Z y 13:58Z, en el árbol principal, `StudioMetaTemplates.php` y `MetaTemplatesSyncCommand.php` estuvieron unos segundos con su versión de `HEAD`: era la comprobación rojo→verde.
  - En esa misma ventana corrí en el árbol principal PHPUnit (SQLite en memoria), `pint --test` y `php artisan ultron-studio:export-fixtures`. Esta última escribió los 7 `.ts` de `testing/` del CRM; seis quedaron byte a byte iguales.
  - **Si el ensayo de despliegue leyó el árbol en esa ventana, pudo abortar o ver mis ficheros.** Conviene que el agente de despliegue lo compruebe.
- **Qué hice al recibir el aviso, en este orden:**
  1. Respaldé mis 9 ficheros y mi informe en `scratchpad/mis-cambios/`.
  2. Generé los parches con `git diff` contra `HEAD`.
  3. Devolví a `HEAD` **solo mis ocho ficheros con seguimiento**, uno a uno con `git show HEAD:<ruta> > <ruta>`, sin órdenes globales.
  4. Saqué mi informe del árbol.
- **Desde las 14:02:36Z, `git status` está así:**
  - el backend solo enseña lo que no es mío y ya estaba: `ULTRON_STUDIO_H9_CONTRACT.md`, `ops/banco-h9/m1.sh`, `ops/deploy-m1-backend.sh`, `informe-api-security-studio.md` sin seguimiento y las capturas;
  - el CRM está limpio.
- **Después, todo se hizo en la copia** `scratchpad/backend-fixes/`:
  - `rsync` del backend, con `vendor` **copiado**;
  - comprobé que las clases se cargan desde la copia (`ReflectionClass::getFileName()`) y que no hay enlaces ni rutas absolutas al árbol principal en `bootstrap/cache` ni en `vendor/composer`.

## Entrega

**Ruta del scratchpad** (abajo, `$S`):
`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/02c4d461-5751-410d-a198-37b8c49b186b/scratchpad`

| Fichero | Qué es |
|---|---|
| `$S/be-fixes-2-backend.patch` | 6 ficheros, rutas relativas a `backend/` (`a/app/…`, `a/tests/…`) |
| `$S/be-fixes-2-crm.patch` | 2 ficheros, rutas relativas a la raíz del CRM: el fixture regenerado y la spec de paridad |
| `$S/informe-be-fixes-2.md` | Este informe. Su sitio previsto: `backend/docs/ultron-studio/plans/informes-motores/informe-be-fixes-2.md` |
| `$S/mis-cambios/` | Los ficheros completos, por si prefieres copiarlos en vez de aplicar parches |
| `$S/backend-fixes/` | La copia del backend con los cambios aplicados; tiene una copia del `.env`, así que conviene borrarla al acabar |
| `$S/crm-testing-copia/` | La copia de `testing/` del CRM, con el fixture regenerado desde la copia |

**Cómo aplicarlo:**

- Backend, desde `Iron-Body/`: `git apply --directory=backend $S/be-fixes-2-backend.patch`.
- CRM, desde `Iron-Body_Front/`: `git apply $S/be-fixes-2-crm.patch`.
- A las 14:05Z comprobé con `git apply --check` que los dos aplican limpio sobre los árboles actuales.
- En lugar del parche del fixture puedes regenerarlo con `php artisan ultron-studio:export-fixtures`: el resultado es el mismo (ver «Cifras»).

## Resumen para el lead

### Qué cambió en el backend

1. **M-2: el 422 `meta_payload_invalid` ya deja su evento de cierre.**
   - `app/Services/UltronStudio/StudioMetaTemplates.php:810-828`, en `createAtMeta()`, rama `InvalidArgumentException`:
     - registra `meta_template.submit_failed` con:
       - `fromStatus: SENDING`, `toStatus: FAILED`;
       - `diff: {detail: payload_refused}`;
       - el mismo `$this->audit->record(...)`, `SUBJECT` y `SYSTEM_SYNC` que `settleCreate()` (`:840`).
     - Sin datos de Meta ni el texto de la excepción. El resumen es fijo, con la versión y la clave, como los demás cierres.
     - El cambio de estado y el evento van **en una misma transacción**: o los dos, o ninguno. Si el proceso muere a mitad, el envío sigue en `SENDING` y lo recoge `staleSending()` (→ `UNKNOWN` → `FAILED not_created`).
     - El `Log::error('…payload_refused', …)` queda **byte a byte igual**: la mutación G6 de `spec-guardas.php:20` lo busca literalmente. Ahora va antes de la transacción, para que quede aunque falle la escritura.
   - `:448`: `submit()` pasa `$t`, `$request` y `$access->actor` a `createAtMeta()`, que ahora los recibe.
2. **M-3: código de salida de `ultron-studio:meta-templates-sync`.** Fichero: `app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php`.
   - `:71`: sale con 1 si el resultado es `meta_failed`. En cualquier otro caso, con 0.
   - `:72`: cuenta las filas `note: unresolved`.
   - `:75-77`, salida `--json`: añade `unresolved` (un entero) **al final** y devuelve el código.
   - `:81-86`, salida de texto:
     - con `meta_failed`, la línea del resultado sale como error y termina en «Sale con 1.»;
     - con versiones sin comprobar, un aviso: «Sin comprobar en esta pasada: N (sale con 0: la siguiente pasada vuelve a intentarlo).»
   - `:22-35`: docblock reescrito con los cuatro códigos (1, 0, 0 y 2).
3. **Exportador: `classification` sale de la misma fuente que `providerClassifications`.** Fichero: `app/Services/UltronStudio/StudioIntegrationsExport.php`.
   - `:132`: llama a `self::classifications()`.
   - `:160-176`, `classifications()`:
     - recoge todo lo que lista `StudioIntegrations::PROVIDER_CLASSIFICATIONS`;
     - primero van las seis de §I2, en su orden de siempre; después, lo que llegó más tarde, en el orden de la fuente.
     - Resultado: `connected_read, connected_otp_only, degraded, disabled, not_configured, registry, connected_voice`. Es el orden del CRM.
     - Si mañana un bloque gana una clasificación nueva, entra sola al final y el fixture no vuelve a contradecirse.

### Pruebas nuevas o ajustadas en el backend

- `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSubmitTest.php`:
  - `:307` `clienteQueLanza()`: el doble de siempre, salvo `create()`, que lanza y cuenta las veces. Se mete en el contenedor como el servicio, así que la prueba pasa por la ruta, el controlador y el servicio.
  - `:353` `registrosDe()`: recoge lo que se escribe en el registro de la aplicación.
  - `:370`, `test_si_el_cliente_rechaza_la_plantilla_antes_de_la_red_es_422_failed_y_un_solo_evento_de_cierre` (M-2 y M-4b a):
    - 422 `meta_payload_invalid`, sin el texto de la excepción;
    - un solo `create()`;
    - envío en `FAILED`, sin columnas de Meta;
    - el libro termina en `submit_requested → submit_failed`, con **exactamente un** cierre de cualquiera de los tres tipos;
    - el cierre lleva `SENDING→FAILED`, la persona que actuó y `diff = {detail: payload_refused}`;
    - el historial del detalle empieza por el cierre;
    - el registro tiene una línea `payload_refused` y no lleva el mensaje.
  - `:405`, `test_si_el_cliente_revienta_queda_unknown_sin_segundo_post_al_repetir_y_sin_filtrar_el_mensaje` (M-4b b):
    - una `RuntimeException` deja el envío en `UNKNOWN` (201) y escribe `submit_unknown`;
    - repetir con la misma `clientRequestId` da 200 `replayed` sin segundo `create()`;
    - una petición nueva da 409 `already_submitted`, también sin `create()`;
    - el mensaje (`SQLSTATE…`, host y usuario) no aparece en las respuestas, el libro, la tabla de envíos ni el registro.
- `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSyncTest.php:319`, `test_la_orden_sale_con_1_si_meta_falla_en_toda_la_pasada_y_con_0_si_falla_en_parte_diciendolo` (M-3). Recorre los casos:

  | Caso | Código | Lo que se comprueba |
  |---|---|---|
  | Bien | 0 | `unresolved: 0`; sin línea «Sin comprobar» |
  | `partial` | 0 | `unresolved: 1`; la fila de la versión dice `unresolved` y la otra no; la salida de texto lo dice |
  | `meta_failed` | 1 | En `--json`, en texto («Sale con 1.») y en seco; ninguna fila nueva |
  | Sincronización apagada | 0 | Sin red |

  Para `partial`, Graph es de mentira (`Http::fake`): la lista contesta sin una versión y su lectura suelta da 500.
- `tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php:139-143`:
  - el conjunto `classification` es exactamente la lista de siete, en ese orden;
  - y es, como conjunto, la unión de `providerClassifications`.

### Qué cambió en el CRM

Va en `$S/be-fixes-2-crm.patch`. Hoy el CRM está en `HEAD`.

- `src/app/modules/ultron-studio/testing/backend-integrations.testing.ts`: lo generó `php artisan ultron-studio:export-fixtures`. **Solo cambia este fichero, y en una línea**: `"connected_voice"` al final de `sets.classification`.
  - Huella del fichero: `983b7452…` antes, `fcdba2d1…` después.
  - Los otros seis fixtures no cambiaron: comprobé su sha256 antes y después.
  - Lo generé dos veces con el mismo resultado byte a byte:
    1. en el árbol principal, antes de tu aviso;
    2. desde la copia, con `--path=$S/crm-testing-copia` sobre una copia de `testing/`.
- `src/app/modules/ultron-studio/studio-integrations-parity.spec.ts:155-157`: la paridad vuelve a ser **estricta**, como pedía su comentario.
  - Quité el `if (k === 'classification') continue;` y el bloque «HUECO CONOCIDO…» con sus dos aserciones.
  - `classification` se compara ahora como los demás conjuntos.
  - La prueba independiente («toda clasificación que el servidor permite… la conoce el CRM») se queda como estaba.

### Cifras (todo ejecutado, uno a uno, con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging`, en SQLite)

Pasé la batería **dos veces**: en el árbol principal antes de tu aviso (tabla de abajo) y, después, **en la copia** con el parche aplicado. La copia dio las mismas cifras, con una sola diferencia, que es del entorno:

- `tests/Feature/UltronStudio/MetaTemplates` en la copia dio **57/57** (872 aserciones) más **1 fallo**: `MetaTemplateRoutesTest::test_la_puerta_de_diff_del_whatsapp_de_hoy_esta_vacia`.
  - Esa prueba ejecuta `git diff` y la copia no es un repositorio («fatal: not a git repository»).
  - Ninguno de mis ficheros está en su `DIFF_GATE`.
  - En el árbol principal pasó: es parte del 58/58 de la tabla.
- Lo demás, en la copia, idéntico:

  | Qué | Resultado |
  |---|---|
  | `tests/Unit/Meta/Templates` | 64/64 |
  | Exportador | 3/3 |
  | Centinela | 3/3 |
  | `UltronEngineIsolationTest` | 11/11 |
  | `OutreachStaticGuardsTest` | 6/6 |
  | `pint --test` | passed |

Batería en el árbol principal, antes de tu aviso:

| Qué | Resultado |
|---|---|
| `tests/Feature/UltronStudio/MetaTemplates` (6 ficheros) | **58/58**, 890 aserciones |
| `tests/Unit/Meta/Templates` | **64/64**, 1 294 aserciones |
| `tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php` | **3/3**, 73 aserciones |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSentinelAdversarialTest.php` (usa el exportador) | **3/3**, 169 aserciones |
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **11/11**, 1 609 aserciones |
| `tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php` | **6/6**, 9 909 aserciones |
| `vendor/bin/pint --test` sobre los 6 ficheros PHP tocados | `passed` |
| CRM: `bash -c 'nice -n 10 npx ng test --watch=false --include=…parity.spec.ts --include=…vocabularios-desfasados.spec.ts'` | **2 ficheros, 25/25**. Corrido en el CRM real hacia las 13:59Z, con los dos ficheros que ahora van en `be-fixes-2-crm.patch` (byte a byte los mismos). No lo repetí sobre una copia del módulo: haría falta copiar el espacio de Angular con `node_modules`, y es desproporcionado. **Tras aplicar el parche, conviene repetirlo** |

En la salida de `ng test` salen avisos NG8107 de `src/app/modules/reports/sections/staff-section.ts`. Ya estaban antes y no tienen que ver con este cambio.

**Rojo antes que verde.** Puse el fichero de `HEAD` en lugar del mío, corrí la prueba y lo restauré en la misma orden (`cmp` lo confirmó):

- la prueba de M-2 **falla** con el servicio de `HEAD`: falta `meta_template.submit_failed` en el libro;
- la de M-3 **falla** con la orden de `HEAD`: no hay `unresolved` y el código no es 1;
- la de `Throwable` (M-4b b) **pasa** también con `HEAD`. Es lo esperado: cubre un comportamiento que ya era correcto y solo estaba probado en el cliente.

### Lo que NO verifiqué

- **PostgreSQL:** todo corrió en SQLite. La transacción nueva no hace nada propio de PG (un `UPDATE` y un `INSERT` en el libro), pero no la ejecuté en PG.
- **El programador de verdad:** no corrí `schedule:run` con la bandera encendida. Lo que hará con un 1 lo saqué de leer `vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php:206-212`:
  - lanza `Exception("Scheduled command [...] failed with exit code [1].")`;
  - la reporta con el manejador de excepciones: una línea ERROR en el registro de la aplicación **cada 5 minutos mientras Meta falle**, como mucho 288 al día;
  - emite `ScheduledTaskFailed`;
  - y sigue con las demás tareas.
- **La batería completa** de ninguno de los dos lados, ni `ng build`.
- **Las specs del CRM después de devolverlo a `HEAD`:** no las repetí. Lo que se aplicará es byte a byte lo que ya pasó 25/25.
- **Los fixtures del CRM:** solo corrí las dos specs pedidas, no el resto de specs que los leen.
- **Las mutaciones** de `tests/fixtures/ultron-prompts/tools/mutaciones/`: no las corrí. Comprobé que los anclajes que usan siguen existiendo tal cual:
  - G6: el `Log::error('…payload_refused', ['submission_uuid'`;
  - G1 y G3: los `namespace`;
  - S3, S4 y S11: el bloque de `routes/console.php`, que no toqué.

### Lista EXACTA de ficheros tocados (para el commit)

Hoy **ninguno** está en los árboles: van en los dos parches, y el informe en el scratchpad.

Backend (`Iron-Body/backend/`, en `be-fixes-2-backend.patch`):

1. `app/Services/UltronStudio/StudioMetaTemplates.php`
2. `app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php`
3. `app/Services/UltronStudio/StudioIntegrationsExport.php`
4. `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSubmitTest.php`
5. `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSyncTest.php`
6. `tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php`
7. `docs/ultron-studio/plans/informes-motores/informe-be-fixes-2.md`: este informe, hoy en `$S/informe-be-fixes-2.md`. Hay que copiarlo ahí.

CRM (`Iron-Body_Front/`, en `be-fixes-2-crm.patch`):

8. `src/app/modules/ultron-studio/testing/backend-integrations.testing.ts` (generado)
9. `src/app/modules/ultron-studio/studio-integrations-parity.spec.ts`

**No son míos y NO van en este commit.** Ya estaban en el árbol, o aparecieron mientras trabajaba:

- `M backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md`;
- `M backend/docs/ultron-studio/ops/banco-h9/m1.sh`;
- `M backend/docs/ultron-studio/ops/deploy-m1-backend.sh`;
- `?? backend/docs/ultron-studio/plans/informes-motores/informe-api-security-studio.md`;
- las capturas `?? …/evidencia-h8|h9/…`.

Los tres modificados ya salían en el `git status` del arranque, aunque el encargo decía «árbol limpio salvo capturas». Supongo que son del agente de despliegue. No los toqué.

### Decisiones y avisos para el lead

1. **`not_configured` sigue saliendo con 0.** Pasa con la sincronización encendida pero sin WABA o sin credencial.
   - Tu decisión solo pedía 1 para `meta_failed`, y el plan (§5.5.2) dice 0, así que no lo cambié.
   - Consecuencia: con `…_SYNC_ENABLED=true` y la credencial caída o revocada en el registro, el programador **no ve un fallo nunca**.
   - Si lo quieres en 1, es una palabra en `MetaTemplatesSyncCommand.php:71`, más un caso en la prueba nueva y el ajuste de `MetaTemplateSyncTest::test_la_orden_sale_con_0_apagada_sin_configurar_o_sin_nada_que_vigilar_y_sin_red`, que hoy fija 0 para `not_configured`.
2. **Con `--template=<uuid>`, un fallo de Meta es `partial` y sale con 0.** Con una sola versión no se lee la lista: su lectura suelta falla, el servicio lo llama `partial` con `unresolved: 1`, y por tu regla sale con 0.
   - El programador no usa `--template` (la guarda lo fija sin argumentos), así que no afecta a la alarma.
3. **Nadie fija el código de salida de `meta_failed`.**
   - `routes/console.php:566-571` no necesita cambios: la guarda `UltronEngineIsolationTest.php:300` fija la sentencia exacta del bloque, y su comportamiento con un 1 es el de arriba.
   - Las pruebas que fijan 0 (`MetaTemplateSyncTest.php:233-254` y `MetaTemplateRoutesTest.php:251-252`) son de apagado, sin configurar, sin nada que vigilar o fuera de vigilancia. Todas siguen en 0 y en verde.
   - **No hay enlace con IRON GUARD:** la alarma es solo la línea ERROR del registro. Añadir un `->onFailure(...)` al bloque obligaría a enmendar esa guarda. No lo hice.
4. **Textos que se quedan atrás con M-3.** No los toqué porque están fuera del alcance:
   - `docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md:591` (§5.5.7: «la pasada termina con 0»);
   - `docs/ultron-studio/plans/informes-plantillas/informe-backend.md:35` («Sale siempre con 0»).
   - El docblock de la orden ya dice que tu decisión enmienda §5.5.7.
5. **Para el próximo despliegue (devops).** La lista cerrada de `docs/ultron-studio/ops/deploy-m1-backend.sh` (no la toqué) ya incluye:
   - `StudioMetaTemplates.php` (`:192`);
   - `MetaTemplatesSyncCommand.php` (`:184`).

   **No incluye `app/Services/UltronStudio/StudioIntegrationsExport.php`.** Solo lo usa `ultron-studio:export-fixtures`, pero vive en `app/`, así que el despliegue del commit que lleve estos cambios tendrá que admitirlo.
6. **Lo que ve el CRM:**
   - el nuevo evento sale en el historial de la plantilla con su resumen y el icono `error` que ya tenía `meta_template.submit_failed` (`ca-meta-text.ts:446`);
   - el CRM no lee el `diff` de ese evento;
   - no cambia ningún contrato HTTP: el 422 es el mismo, y el `--json` de la orden solo gana `unresolved` al final.
7. **Observación aparte, no cambiada:** `settleCreate()` (`:840-863`) también guarda el estado y escribe el evento sin transacción. Si el proceso muriera entre las dos sentencias, el envío quedaría en `SUBMITTED`, `FAILED` o `UNKNOWN` sin su evento. Es el mismo hueco de M-2, solo ante una caída, y no se pidió aquí.

## Casos límite para el tester

- **M-2:**
  - **El libro falla dentro de la transacción del cierre** (por ejemplo, la base cae entre el `UPDATE` y el `INSERT`):
    - el envío vuelve a `SENDING`, sale un 500 y no se escribe evento;
    - a los 120 s `staleSending()` lo pasa a `UNKNOWN`, y a los 15 min a `FAILED not_created`;
    - no lo probé.
  - **Repetir con la misma `clientRequestId` después del 422:** da 200 `replayed` con el estado `FAILED`. No lo fijé en la prueba para no consagrar ese 200.
- **M-3:**
  - **Una pasada con todas las versiones sin resolver pero la lista leída** (lista vacía y todas las lecturas sueltas caen) es `partial`, no `meta_failed`, y sale con 0.
  - **`busy`** (otro proceso tiene el cerrojo de la versión) no cuenta como `unresolved`.
