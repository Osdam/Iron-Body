# ULTRON STUDIO · Hito 9 · Runbook de las etapas 4 a 7 (sombra, asistido, canales automáticos y operación supervisada)

**Para quién:**
- quien opera el servidor, como root, con la autorización escrita del propietario;
- las personas del CRM que gobiernan las estrategias.

**Qué cubre:** las etapas 4 a 7 de `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`.
- **Qué no cubre:**
  - el despliegue de la fase 1: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 9»;
  - las etapas 0 a 3 de la voz: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8».
- **El estado de cada pieza**, en `ULTRON_STUDIO_H9_COMPLETITUD.md`.

**Estado del runbook (11-11-2026): NUNCA EJECUTADO EN PRODUCCIÓN.**
- Los comandos y las salidas esperadas salen de leer el código del mando (`ops/h9-outreach-scope.sh`) y de la aplicación.
- Los ensayos son del banco (L9-71).
- Lo que este documento deduce del código sin haberlo ensayado lleva la etiqueta *deducido del código*.

**Reglas que no se saltan:**
1. **Una autorización escrita del propietario por etapa y por cada orden que encienda algo.** Apagar no espera a nadie.
2. **El fichero de entorno de producción no se edita a mano.** Solo con el mando, que hace una copia previa, escribe solo las claves pedidas, comprueba y dice cómo volver.
3. **Los frenos los suelta siempre una persona desde el CRM**, con una nota y a cuatro ojos. El mando no suelta nada.
4. **Nada sale hacia una persona en la etapa 4.**
   - En la 5 contacta el personal, a mano.
   - En la 6 salen mensajes y llamadas automáticos, y solo después de abrir con un commit la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE`.
5. Comandos: en el servidor, como root. `artisan` siempre como `www-data`, desde `/var/www/api/backend`.

---

## 1. Quién puede hacer qué

Llaves según el catálogo del código (`app/Support/Access/PermissionCatalog.php:172-181`, `AuthorizationMap.php:824-869`).
- **Por defecto:**
  - el Super Admin tiene todas;
  - el Administrador, todas salvo las cuatro marcadas como «solo SA» (`CrmPermission.php:322-331` en `ce7c150`);
  - Recepción, Entrenador, Administrativo y el token compartido de n8n no tienen ninguna.
- **La pantalla de Configuración → Roles puede haber cambiado este reparto en producción.**

| Acto | Dónde | Llave | Por defecto | Regla |
|---|---|---|---|---|
| Encender o apagar banderas | Servidor (mando) | Acceso root | — | Autorización escrita del propietario |
| Vincular las **dos primeras** personas | Servidor (`ultron-studio:person-bindings --bootstrap`) | Acceso root | — | Solo si no hay ningún vínculo vivo; lleva dentro la referencia de la autorización |
| Crear y verificar los demás vínculos | CRM → Vínculos de personas | `ultron.person_bindings_manage` | Solo SA | Verifica una segunda persona ya vinculada, distinta de la vinculada y de quien lo creó (D9-11.2) |
| Crear y editar estrategias y plantillas, validar, simular con datos sintéticos, congelar versiones, archivar | CRM → Estrategias, Constructor, Simulación, Plantillas | `ultron.strategies_edit` | SA y Administrador | Congelar y crear plantillas exige un vínculo verificado (L9-39) |
| Simular sobre una muestra real de personas | CRM → Simulación | `commercial_automation.simulate_real` | Solo SA | Solo lectura, con seudónimos |
| Aprobar o rechazar una versión o una plantilla | CRM → Gobierno / Plantillas | `ultron.approve` | SA y Administrador | Otra **persona** que la autora, con vínculo verificado; exige una simulación sin bloqueantes de esa misma versión |
| Publicar en canario, promover y reanudar | CRM → Gobierno | `ultron.strategies_publish` | Solo SA | Una persona distinta de la **autora** (quien aprobó puede publicar), con vínculo; bandera `publicar`; ventana de Bogotá; la huella vista (`StudioStrategyPublishing.php:337-346`) |
| Pausar y revertir | CRM → Gobierno | `ultron.strategies_rollback` | SA y Administrador | **Exige un vínculo verificado** (`StudioStrategyPublishing.php:165`, `:212`) y el gobierno encendido. Sin ventana y sin la bandera de publicar |
| Ver el panel, las inscripciones, los trabajos, los frenos y las métricas | CRM → Panel | `commercial_automation.view` | SA y Administrador | Con la maestra apagada solo responde `GET outreach/status` (las demás, 409 `outreach_disabled`) |
| Ver el historial de una persona | CRM → Historial por persona | `commercial_automation.timeline_view` | SA y Administrador | Por seudónimo; cada consulta queda en el log de la aplicación |
| Trabajar la bandeja asistida | CRM → Bandeja asistida | `commercial_automation.assisted_work` | SA y Administrador | Quien reclama registra; solo quien reclamó ve la persona y el guion |
| Echar un freno o la **PARADA GLOBAL** | CRM → Panel, o servidor (`parada-global`) | `commercial_automation.stop` | SA y Administrador | **No** exige vínculo (L9-39). Por el CRM exige la maestra encendida; por consola, no |
| **Soltar** un freno | CRM → Panel (frenos) | `commercial_automation.release` | Solo SA | Otra cuenta que la que lo abrió y, si las dos tienen vínculo, otra persona; con una nota de 3 a 500 caracteres sin teléfonos ni correos; **exige la maestra encendida** (`UltronStudioOutreachController.php:232-241`, `OutreachStop.php:304-346`) |

---

## 2. El mando `h9-outreach-scope.sh`

### 2.1 La versión correcta en `/root` (antes de la primera orden)

**El mando tiene que ser el del mismo commit que el código desplegado.**
- El de `ce7c150` (el código de producción hoy) espera **3** tareas del programador.
- El del árbol de trabajo espera **5** (los normalizadores de W9 y V9; `ops/h9-outreach-scope.sh:90`).

Con el mando nuevo sobre el código viejo, `sombra on` acaba en desvío del programador (código 1). Lo mismo vale para `smoke-run-h9.sh`.

```bash
# En el Mac, en la raíz del repositorio del backend: la huella del mando del commit desplegado
git show ce7c150:backend/docs/ultron-studio/ops/h9-outreach-scope.sh | shasum -a 256
#   aca123d60b29565fdf0856edc8cdc9cc09d2fb5c30514e5a673fa222c19c0e24  (la de ce7c150)
# En el servidor
sha256sum /root/h9-outreach-scope.sh
```

**Si no coinciden:**
1. Copia la versión del commit desplegado: `git show ce7c150:backend/docs/ultron-studio/ops/h9-outreach-scope.sh > h9-outreach-scope.sh`, después `scp h9-outreach-scope.sh ironbody-vps:/root/` y `chmod 700 /root/h9-outreach-scope.sh`.
2. Vuelve a comparar las huellas.

**Las huellas de `ce7c150`** para el smoke de la fase 2:

| Fichero | sha256 |
|---|---|
| `smoke-run-h9.sh` | `5459d1d411e8066ad317e6acf1e146ebf0aabc336d52647e73a331194305bec0` |
| `smoke-h9.py` | `2de9a4db8ef28aaf1c11ba902235c6dfb451c19dfd9cc47fc6bf63150939aa1b` |
| `smoke-h6.py` | `29f67d84294a3ddbf6ef9f7f175e1c6e2df31f631d2a1cb103b629845f3ec39a` |

### 2.2 Cómo responde

*Las líneas `:n` del mando que cita este runbook son las del árbol de la rama (11-11-2026). En la copia de `ce7c150`, a partir de la línea 84 van una línea antes: el comentario de las tareas tiene una línea menos.*

**Cada orden que escribe sigue estos pasos** (`ops/h9-outreach-scope.sh:28-41`, `:506-692`):
1. Exige que el fichero de entorno sea un fichero normal de `www-data` en 600 o de `root:www-data` en 640.
2. Exige que haya de 13 a 16 workers en RUNNING y ninguno en BACKOFF o FATAL.
3. Exige que nadie más esté usando el mando (cerrojo `/run/lock/h9-outreach-scope.lock`).
4. Hace una **copia previa** en `/root/env-pre-outreach-<orden>-<fecha UTC>` (600).
5. Escribe solo las claves de la orden.
6. Comprueba que el fichero parsea y regenera `config:cache` como `www-data`.
7. Comprueba que la configuración efectiva cambió **solo** en lo pedido y que el motor comercial, Legacy, los hitos 4 a 7 y la voz del H8 siguen igual.
8. Comprueba el programador y los workers (dos lecturas) tras `queue:restart`, que las rutas del H9 responden 401 sin credencial y que no hay ERROR nuevos.

**Nunca imprime valores del fichero de entorno.**

| Salida final | Código | Qué significa |
|---|---|---|
| `MANDO <orden> APLICADO (copia previa: /root/env-pre-outreach-…)` | 0 | Hecho, con 0 desvíos |
| `== <orden>: el fichero de entorno ya tiene esos valores (no se imprimen); nada que escribir` | 0 | Ya estaba así |
| `NO SE APLICA (no se ha tocado nada): <motivo>` | 2 | Falta una condición o el uso es incorrecto |
| `ABORTA: <motivo>` | 1 | Paró antes o durante la escritura; si escribió, imprime la vuelta atrás |
| `MANDO <orden> ESCRITO CON N DESVÍOS: no seguir hasta aclararlos (…; vuelta: …)` | 1 | Escribió, pero algo no cuadra: **ejecuta la vuelta que imprime** y avisa al lead |

**Toda salida que escribe termina con dos órdenes de vuelta atrás:**
- `vuelta atrás exacta (el fichero de antes): install -m … /root/env-pre-outreach-… …`: restaura el fichero y la caché;
- `vuelta atrás funcional: bash /root/h9-outreach-scope.sh …`: la orden contraria.

**Lo que el mando no hace:**
- no toca `COMMERCIAL_EVENTS_*`, `COMMERCIAL_NBA_*` ni `COMMERCIAL_AUTONOMY_*`, ni la voz del H8 (eso es `h8-voice-scope.sh`), ni Meta;
- no reinicia FPM ni ningún programa de supervisor (`:62-66`);
- en `sombra`, `asistido` y `estado` no escribe los precios de WhatsApp (`OUTREACH_WHATSAPP_PRICE_*`) ni los cupos de voz del H9 (§5.1); esos sí los escribe la orden `canal whatsapp on --precio-marketing=… --precio-utilidad=…` (y `canal voz on --cupo=… --reserva=…`), que exige la constante de envíos abierta (corrección del 11-11-2026, auditoría B, I-9).

### 2.3 Lectura del estado (solo lectura, en cualquier momento)

```bash
bash /root/h9-outreach-scope.sh estado
```

Fíjate en estas líneas:
- `fase deducida`: `1 (todo apagado)` · `2 (sombra: todo simulado, ni un contacto)` · `3 (asistido: live solo con el canal asistido)` · `4–5 …` / `6 …`;
- `constante de envío (OutreachSendGate): cerrada`;
- `base (solo lectura): frenos abiertos N (globales N) · trabajos vivos N · pasadas N · personas vinculadas y verificadas N · versiones en canario o publicadas N`;
- `programador (total · del Studio · de voz · de outreach)`: con el código de `ce7c150`, 0 tareas de outreach si el programador está apagado y 3 si está encendido;
- `fichero de entorno`: si dice `NO ADMITIDO (C-14)`, ninguna orden escribirá.

---

## 3. Etapa 4 · sombra (al menos 7 días, P9-11)

**Qué hace:** decide y registra como si fuera de verdad, con trabajos simulados. **Ni un contacto**: en sombra el despacho usa siempre el canal simulado.

### 3.1 Precondiciones (todas, antes de pedir la autorización)

| # | Precondición | Cómo se comprueba | Hoy (11-11) |
|---|---|---|---|
| P1 | La fase 1 lleva al menos 24 h estable | La lectura a las 24 h, como en L9-77: workers, 5xx, colas, `outreach_*` a 0 e incidentes | No consta |
| P2 | Las condiciones de REV9 para la fase 2: TEST9-b (`exercise()` y E2E #26), PERF9, m-9 y H-1 (L9-73) | Los informes de cada paquete | **Abiertas**: ver `ULTRON_STUDIO_H9_COMPLETITUD.md` §3 y §4 |
| P3 | **H9-E2E-1 corregido y desplegado:** sin eso, una estrategia creada desde el CRM no se puede abrir en el constructor | Crear una estrategia de prueba en el CRM y abrir su constructor | **Corregido y en producción** (`5758db9c`, antecesor de la API desplegada `2687119`; L9-94). Falta ejercerlo con una estrategia real: hoy hay 0 |
| P4 | P9-24 decidido: `COMMERCIAL_EVENTS_ENABLED=true`. Sin él, N6 niega a todo el mundo (L9-54) | `GET outreach/status` → `sources.commercialEvents: true` | **Sin procedimiento**: §3.2, paso 4.1 |
| P5 | P9-17 (horario propio) y P9-18 (topes) decididos | La decisión escrita | Pendiente |
| P6 | Dos personas reales con cuenta activa e identidad propia | §3.2, paso 4.1b | Falta la segunda persona |
| P7 | Si hay que rotar la `APP_KEY`, rotarla **antes** del paso 4.3 (`ops/ROTACION_APP_KEY_H9.md`) | — | — |

### 3.2 Pasos

**4.0 · Antes de nada (solo lectura):** `bash /root/h9-outreach-scope.sh estado`.
- **Esperado:**
  - `fase deducida: 1 (todo apagado)`;
  - constante `cerrada`;
  - `frenos abiertos 0`, `personas vinculadas y verificadas 0`;
  - las órdenes desplegadas incluyen `outreach:monitor` y `outreach:stop`;
  - workers `13 0`.

**4.1 · Eventos del motor comercial (P9-24). HUECO: no hay procedimiento.**
- El mando del H9 deja fuera el motor comercial a propósito (`ops/h9-outreach-scope.sh:62-63`), y ningún guion de `ops/` escribe `COMMERCIAL_EVENTS_ENABLED`.
- Editar el fichero a mano no es el procedimiento.
- **Pendiente de `devops`:** un mando o una orden con copia previa y vuelta atrás.
- Antes, el propietario lee qué escribe esa bandera (`commercial_events`, desde los observadores del motor comercial) y su coste, que no está medido.

**4.1b · Las dos primeras personas (autorización escrita; datos: dos ids de cuenta y dos uuid de `identities`):**

```bash
cd /var/www/api/backend
sudo -u www-data php artisan ultron-studio:person-bindings --list
sudo -u www-data php artisan ultron-studio:person-bindings --bootstrap \
  --admin-a=<id de la cuenta A> --identity-a=<uuid de la identidad A> \
  --admin-b=<id de la cuenta B> --identity-b=<uuid de la identidad B> \
  --authorization="<referencia de la autorización escrita del propietario, de 10 a 160 caracteres>"
```

- **Esperado:** una tabla con 2 vínculos de origen `bootstrap` y el mensaje `Las dos primeras personas quedan vinculadas y verificadas. Queda en el libro del Studio con la autorización.`
- **Errores y qué significan:**
  - `Ya hay vínculos vivos…` (409): el arranque solo vale una vez; los demás vínculos se hacen desde el CRM.
  - `Las dos cuentas tienen que existir y estar activas` (404).
  - `El arranque vincula dos PERSONAS distintas` (422).
- El uuid de la identidad es `identities.uuid`, el mismo que usa el módulo de personal (`app/Services/Workforce/StaffRecord.php:50-52`).

**4.2 · Gobierno (una autorización escrita; una orden cada vez, cada una debe terminar en `APLICADO`):**

```bash
bash /root/h9-outreach-scope.sh gobierno on        # las 30 rutas strategies/** dejan de responder 409
bash /root/h9-outreach-scope.sh publicar on        # publicar, promover y reanudar (dentro de la ventana)
bash /root/h9-outreach-scope.sh ventana set 07:00-19:00          # la del contrato, ahora explícita en el fichero
bash /root/h9-outreach-scope.sh canario set --horas=24 --contactos=3   # P9-19 (mínimos duros: 1 h y 1 contacto)
bash /root/h9-outreach-scope.sh horario set <HH:MM-HH:MM>        # P9-17; o «legal»
```

- `simulacion required` ya está escrita desde la fase 1: no hace falta.
- **El horario propio:**
  - solo admite una franja `HH:MM-HH:MM` dentro de 08:00–19:00 (`:453-456`), que vale para todos los días y se cruza con la ley (sábados de 08:00 a 15:00);
  - el tramo propio de los sábados que recomienda P9-17 (de 09:00 a 13:00) **no se puede escribir con el mando** (hueco de `devops`; la configuración sí lo admite con `;`).

**4.3 · La primera estrategia (en el CRM, con dos personas vinculadas; la persona A es la autora y la B revisa):**
1. **Plantillas.** A crea las plantillas (CRM → Plantillas) y B las aprueba.
2. **Estrategia.** A crea la estrategia. **Con H9-E2E-1 sin corregir, el constructor no abre: parar aquí.** Después, A edita el borrador en el Constructor y lo valida.
3. **Versión.** A congela la versión y la **simula** en CRM → Simulación. La simulación tiene que ser de esa versión exacta: la del borrador no vale para aprobar (`informe-tester-final.md` §B.2, «Observación»).
4. **Aprobación.** B aprueba la versión (CRM → Gobierno), reconociendo los avisos si los hay.
5. **Publicación en canario.** La publica una persona distinta de A con `ultron.strategies_publish`, dentro de la ventana. Respuestas posibles:
   - 409 `strategy_paused`: la estrategia está en pausa;
   - 409 `canary_in_progress`: otra versión de la estrategia está en canario;
   - 409 `content_changed`: la versión no es la que viste;
   - 409 `simulation_required`: falta la simulación de la versión.

**4.4 · Encender la sombra (autorización escrita):**

```bash
bash /root/h9-outreach-scope.sh sombra on     # escribe la maestra, MODE=shadow, el programador y el monitor
bash /root/h9-outreach-scope.sh stream on     # opcional: el canal en vivo del CRM
bash /root/h9-outreach-scope.sh estado
```

*Salida esperada de `sombra on`, deducida del código del mando y ensayada en el banco (L9-71); con la fase 1 el total era 39 (L9-76):*

```
1. claves escritas (sin valores): COMMERCIAL_AUTOMATION_ENABLED COMMERCIAL_AUTOMATION_MODE COMMERCIAL_AUTOMATION_MONITOR_ENABLED COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED · fichero …
3. configuración efectiva, antes → después: enabled: false → true · mode: "off" → "shadow" · monitor: false → true · scheduler: false → true
   fase después: 2 (sombra: todo simulado, ni un contacto)
4. programador (total · del Studio · de voz · de outreach): 39 0 0 0 → 42 0 0 3 (esperado 42 0 0 3)
5. workers (RUNNING · BACKOFF/FATAL), dos lecturas: [13 0] y [13 0] (esperado 13 0 las dos veces)
6. sin token: admin/ultron-studio/outreach/status → 401 · admin/ultron-studio/strategies → 401 (esperado 401 y 401)
MANDO sombra-on APLICADO (copia previa: /root/env-pre-outreach-sombra-on-<fecha>)
```

- `sombra on` ya enciende el programador y el monitor: `programador on` y `monitor on` después responderán «nada que escribir».
- **`stream on`:** recomendable con el vale de un solo uso ya desplegado (P9-25, L9-78). El riesgo R-1 (vale reutilizable de 300 s en la URL) existe hoy en todos los streams del CRM, no solo en este (`plans/informes-h9/informe-p9-25.md` §1).

**4.5 · Siete días de observación (solo lectura):**

| Cuándo | Qué mirar | Qué esperar |
|---|---|---|
| Nada más encender y al séptimo día | `SMOKE_PHASE=2 bash /root/smoke-run-h9.sh` (con `smoke-h9.py` y `smoke-h6.py` en `/root`; usa la cuenta 32 «Studio smoke» y la deja en reposo) | `OK` en la pasada a mano del barrido y del despacho y en **«sin envíos»**: `marketing_messages` y `voice_calls` sin cambios, colas sin trabajos nuevos, 0 trabajos `outreach_jobs` en modo `live`, 0 ERROR. Código 0, o 3 si solo faltan los presupuestos de PERF9 (OMITIDO) |
| Cada día | `estado` del mando; CRM → Panel: últimas pasadas (`sweep`, `dispatch`, `monitor`), decisiones por nivel y motivo (`GET outreach/metrics?mode=shadow`), inscripciones y trabajos simulados, frenos | Pasadas cada 10 minutos (barrido y monitor) y cada minuto (despacho); `failed` o `errorCode` en una pasada es un hallazgo; 0 frenos y 0 incidentes de IRON GUARD con fuente `outreach` |
| Cada día | Las negativas: ¿se explican por la regla correcta? | Si toda la audiencia sale negada en N6 por `human_requested_recently`, faltan los eventos (P9-24) |
| Al final | La decisión de pasar a la etapa 5 (P9-11): «sin hallazgos» | La decide el propietario; el contrato no fija más criterio (§14) |

**En sombra NO se acumula evidencia del canario.**
- Solo cuentan los trabajos que salieron en vivo y no son simulados (`app/Services/UltronStudio/StudioStrategyEvidence.php:112-115`, L9-40).
- La versión sigue en `CANARY` toda la sombra: la promoción llega en la etapa 5 (§4.3).

### 3.3 Vuelta atrás de la etapa 4

| Necesidad | Orden | Efecto |
|---|---|---|
| Parar ya, sin tocar el entorno | `bash /root/h9-outreach-scope.sh parada-global --motivo="<de 5 a 200 caracteres>"` | Nivel 0b: un freno global; en ≤ 1 pasada del despachador (≈ 60 s) no se reclama nada nuevo |
| Retirar una estrategia | CRM → Gobierno → pausar (la deja en espera) o revertir (sus inscripciones abiertas se cancelan con `strategy_moved`) | Nivel 1. **Antes de apagar el gobierno** |
| Volver a la fase 1 | `bash /root/h9-outreach-scope.sh apagar` | Nivel 0: escribe 7 claves (maestra, modo, programador, monitor, asistido, WhatsApp y voz en `false`/`off`). *No pone a 0 los cupos ni apaga el clasificador, contra L9-52 Q-7: discrepancia abierta* |
| Cerrar el gobierno, **lo último** | `bash /root/h9-outreach-scope.sh publicar off` y `gobierno off` | Con el gobierno apagado, pausar y revertir responden 409 |
| Apagar el canal en vivo | `bash /root/h9-outreach-scope.sh stream off` | El CRM vuelve solo a la consulta periódica |

Las tablas y sus filas quedan: son de solo inserción. **Nunca `migrate:rollback`.**

---

## 4. Etapa 5 · asistido

**Qué hace:** pone la automatización en vivo solo con el canal asistido. ULTRON decide y una **persona del personal** contacta, por el medio que indique la tarea, y registra el resultado. **Es la primera etapa que contacta a personas reales.**

### 4.1 Precondiciones

| # | Precondición | Hoy |
|---|---|---|
| A1 | La sombra salió sin hallazgos (al menos 7 días, P9-11), con la autorización escrita de esta etapa | — |
| A2 | P9-12: quién trabaja la bandeja (la llave `commercial_automation.assisted_work`) y cuántos trabajos al día (el `--cupo`) | Pendiente |
| A3 | **Números canarios ACTIVOS del registro del H6 para el medio de los pasos** (WhatsApp o voz). Con la estrategia en `CANARY` y en vivo, solo se alcanza a esas personas (D9-11.6; `LadderInputBuilder.php:106` en `ce7c150`; `PolicyLadder.php:428-429`). Exige la **fase 2 del H6**: dos personas para el T1, escrituras encendidas y `log_error_verbosity = terse` antes (A-5, A-6) | **No hecho** |
| A4 | La segunda persona disponible para soltar frenos (solo SA por defecto) | — |

### 4.2 Pasos

```bash
bash /root/h9-outreach-scope.sh asistido on --cupo=<N, de 1 a 500>
bash /root/h9-outreach-scope.sh estado     # fase deducida: 3 (asistido: live solo con el canal asistido)
```

- **Qué escribe:** `COMMERCIAL_AUTOMATION_MODE=live`, `COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=true` y `COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=N` (`:393`).
- **Cuándo se niega:**
  - con la maestra apagada: primero la etapa 4;
  - sin `--cupo`.
- **En el CRM (Bandeja asistida):**
  1. Reclamar la tarea.
  2. Atenderla por el medio indicado, fuera del sistema.
  3. Registrar el resultado: `answered`, `no_answer`, `not_interested`, `callback_requested`, `human_requested`, `appointment_booked`, `wrong_person` u `opted_out`, entre otros (`OutcomeTable.php:70-86`).
- Una tarea cuya inscripción ya se cerró responde 409 `enrollment_closed`.

### 4.3 Evidencia del canario y promoción

`GET strategies/versions/{uuid}/canary-evidence` (CRM → Gobierno). La versión pasa cuando se cumple todo esto:
- al menos 24 h en canario;
- al menos 3 contactos que salieron en vivo;
- 0 negativas de N1 detectadas después;
- 0 trabajos `ambiguous` sin resolver;
- *opt-out* ≤ 5 % (`StudioStrategyEvidence.php:44-69`).

Entonces promueve una persona distinta de la autora, con `ultron.strategies_publish` y dentro de la ventana. La versión pasa a `PUBLISHED` y alcanza a toda la audiencia que cumpla las condiciones.

### 4.4 Vuelta atrás de la etapa 5

- `bash /root/h9-outreach-scope.sh asistido off`: escribe `ASSISTED_ENABLED=false` y el cupo a 0. El modo sigue en `live` sin canales: nada sale.
- Para volver a la sombra: `bash /root/h9-outreach-scope.sh sombra on`.
- *Deducido del código: si ese día ya hubo trabajos asistidos reservados, la siguiente pasada del monitor abrirá el freno `budget_breach` del canal `assisted`, porque compara lo reservado hoy con el cupo configurado AHORA (`OutreachMonitor::checkBudgets()`). Es la dirección segura, pero habrá que soltarlo a cuatro ojos antes de volver a encender el asistido.*

---

## 5. Etapa 6 · canales automáticos (W9 WhatsApp y V9 voz)

**No es ejecutable hoy.**
1. W9 y V9 están terminados en el árbol, **sin commit ni despliegue**, y **no hay guion para desplegarlos** (G-9).
2. El commit `outreach(sends-open):` tampoco tiene vía de despliegue: el guion de la fase 1 lo rechaza a propósito (`deploy-h9-backend-fase1.sh:400`).

Lo que sigue describe la secuencia que el código ya impone, para cuando existan esas piezas.

### 5.1 Precondiciones

**Comunes:**
- W9 y V9 desplegados, con la revisión de seguridad y la revisión independiente sin bloqueantes;
- **el visto bueno legal** (P9-22: Ley 1581, Ley 2300, RNE y encargados);
- el commit de una línea `outreach(sends-open):` con la orden escrita del propietario citada, revisado y desplegado;
- la etapa 5 en curso: el mando exige el modo `live` (`exige_live`, `:340`).

**WhatsApp (W9):**

| # | Precondición | Hoy |
|---|---|---|
| W1 | Plantillas aprobadas por Meta (P9-13) y su correspondencia registrada en `ultron_studio_template_meta_checks`. Sin una fila aprobada, todo envío falla con `template_not_approved_by_meta` | ⏳ **La orden de registro, PENDIENTE DE CIERRE DEL LEAD** (agente b9-cierre; nombre por confirmar) |
| W2 | Consentimientos por finalidad y canal (P9-14, P9-15). Hasta la asesoría: WhatsApp comercial solo con `consent_status = granted` | C9 no existe |
| W3 | Números canarios de WhatsApp activos (fase 2 del H6) para el canario de la estrategia | No hecho |
| W4 | **Precios por mensaje** `OUTREACH_WHATSAPP_PRICE_MARKETING_USD` y `…_UTILITY_USD`, con las tarifas reales (las de Colombia están por confirmar). **Con 0, el presupuesto en US$ no limita nada: solo limita el cupo de mensajes.** La reserva es de 1 unidad y 0 micros, y el canal solo exige la unidad (`OutreachPlanner.php:641-642` en `ce7c150`; `WhatsappTemplateChannel.php:128` en el árbol). *El comentario de `config/outreach.php:96` (en `ce7c150`) dice que 0 es `budget_zero`: discrepancia abierta.* **El mando no escribe estas variables** | Hueco de `devops` |

**Voz (V9):**

| # | Precondición | Hoy |
|---|---|---|
| V1 | Las etapas 1 a 3 del H8 superadas: laboratorio real (**plan de pago de ElevenLabs**), publicación canaria de la voz, `voice(calls-open)` desplegado, salientes encendidas, primera saliente con evidencia y la 4b con contactos y consentimientos (P9-16) | Nada hecho |
| V2 | ⏳ **PENDIENTE DE CIERRE DEL LEAD:** `COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP`, `COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD` y `COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD` (agente b9-cierre). Sin ellas, la voz queda en `budget_zero`. **`canal voz on` no lleva opciones (`:419`): el mando tampoco las escribe** | En curso |
| V3 | La plantilla de voz `/3` con negativas exactas (P-M2, ⏳ agente pm2-voz) y D-V9-1 decidido (solo la finalidad comercial tiene consentimiento de voz) | En curso |

### 5.2 Pasos (cuando existan las piezas)

```bash
bash /root/h9-outreach-scope.sh canal whatsapp on --cupo=<1–500> --presupuesto=<0.01–999>
bash /root/h9-outreach-scope.sh canal voz on      # exige además CALLS_ENABLED_IN_CODE y VOICE_OUTBOUND_ENABLED del H8
bash /root/h9-outreach-scope.sh estado            # fase deducida: 4–5 (live: WhatsApp encendido) o 6 / 4–6
```

**El mando se niega** si:
- la constante no está **abierta** (`true` en ejecución, una sola declaración, y el último commit del fichero empieza por `outreach(sends-open):`);
- el modo no es `live`;
- falta la clase del canal;
- con voz: las palancas del H8 están cerradas (`:400-432`).

**El primer contacto automático va a un canario:**
- el primer WhatsApp, a un número canario de WhatsApp;
- la primera llamada por estrategia, al canario de voz del propietario.

**Verificación:**
- CRM → Panel → trabajos `whatsapp_template` / `voice_call` en `started`;
- los resultados que van llegando: `sent`, `delivered`, `read`, `replied` u `opted_out` para WhatsApp; `sent`, `answered`, `no_answer`, `failed`, `opted_out` o `human_requested` para la voz;
- el coste del día en el panel.

### 5.3 Vuelta atrás de la etapa 6

- `canal whatsapp off`: escribe la bandera en `false`, el cupo en 0 y el presupuesto en 0.
- `canal voz off`.
- O la PARADA GLOBAL.
- **Lo que ya está en el proveedor no se recoge:**
  - un WhatsApp aceptado por Meta ya salió;
  - el corte de emergencia de una llamada es el del H8 (`voice:hangup`), que el H9 no acciona.
- *Deducido del código: apagar un canal con reservas ese mismo día deja el cupo por debajo de lo reservado, y el monitor abrirá `budget_breach` en ese canal (§4.4).*
- Cerrar la constante es el nivel 3: el commit anterior.

---

## 6. Etapa 7 · operación supervisada

| Cada | Qué | Quién |
|---|---|---|
| Día | Panel: frenos abiertos, incidentes de IRON GUARD (fuente `outreach`), gasto del día por canal, bajas (`opted_out`) y negativas por motivo | Quien tenga `commercial_automation.view` |
| Día | La bandeja asistida al día, sin tareas reclamadas y olvidadas | Personal con `assisted_work` |
| Semana | Revisión **a cuatro ojos** de los resultados y de las estrategias publicadas: ¿siguen teniendo sentido sus condiciones, plantillas y topes? | Dos personas vinculadas |
| Siempre | El horario propio, más estricto que el legal (`horario set`) | Con autorización |
| Siempre | El clasificador (AI9) **apagado**: no está construido (el mando se niega a encenderlo sin su código, `:441`) | — |

Cualquier persona con `commercial_automation.stop` puede echar la PARADA GLOBAL desde el CRM.

---

## 7. Incidentes

### 7.1 PARADA GLOBAL (nivel 0b)

**Disparador:** cualquier duda seria (contactos indebidos, quejas, un error de plantilla, una deriva) mientras se investiga.

| Vía | Cómo | Notas |
|---|---|---|
| CRM | Panel → «PARADA GLOBAL», con un motivo | Exige `commercial_automation.stop` y **la maestra encendida**: apagada, la API responde 409 `outreach_disabled`, y entonces no hay nada en marcha que parar |
| Servidor | `bash /root/h9-outreach-scope.sh parada-global --motivo="<de 5 a 200 caracteres, sin comillas, \$ ni barras>"` | Funciona siempre (`outreach:stop --all`). Queda a nombre de la cuenta centinela 0 de la consola (L9-63). Esperado: `MANDO parada-global APLICADO (sin tocar el fichero de entorno)` y `globales ≥ 1` en «después» |

**Efecto (D9-14):**
- en ≤ 1 pasada del despachador (unos 60 s) no se reclama nada nuevo ni nacen inscripciones;
- los trabajos `planned` **esperan**;
- lo que ya está en el proveedor no se recoge.

**Para soltarla:**
1. Una persona con `commercial_automation.release`, **distinta de quien la echó**, la suelta desde el CRM con una nota (`POST outreach/brakes/{uuid}/release`). Con la misma cuenta o la misma persona, 403 `four_eyes_required`.
2. Los trabajos retenidos vuelven a pasar por la etapa 6 de la escalera.

*El E2E de la vuelta del 11-11, a las 00:30 UTC, registró que el CRM **ofrece** el botón de soltar a quien abrió la parada. El servidor lo niega igualmente. La causa está pendiente del informe del tester.*

**Si se aplicó `apagar` (nivel 0) con una parada echada:** soltarla exige volver a encender la maestra (`sombra on`, con autorización), porque con la maestra apagada la API de frenos responde 409. El freno global sigue impidiendo reclamar hasta que se suelte.

### 7.2 Frenos automáticos del monitor

El monitor corre cada 10 minutos con `COMMERCIAL_AUTOMATION_MONITOR_ENABLED` y la maestra encendida. **Solo abre**, cada vez con un incidente de IRON GUARD de fuente `outreach` (`OutreachStop.php:71-76`, `OutreachMonitor.php`).

| Causa | Alcance | Disparador | Qué hacer |
|---|---|---|---|
| `opt_out_spike` | Estrategia | *Opt-out* por encima del 5 % en 24 h, con al menos 5 trabajos salidos (`config/outreach.php:79-82` en `ce7c150`) | Pausar la estrategia (CRM → Gobierno) y revisar plantilla, audiencia y frecuencia **antes** de soltar el freno |
| `failure_spike` | Canal | Fallos por encima del 20 % en 24 h, con al menos 5 trabajos | Leer los `detail_code` de los fallos (CRM → trabajos). En WhatsApp, `meta_error_<n>` son rechazos de Meta |
| `budget_breach` | Canal | Lo reservado hoy supera el cupo o el presupuesto configurados **ahora**. Lo dispara también **bajar** un cupo por debajo de lo ya reservado (por ejemplo, `canal … off` o `asistido off` ese día; *deducido del código*). En `ce7c150` solo vigila WhatsApp y asistido; la voz, ⏳ b9-cierre (G-6) | Si fue una bajada voluntaria, soltarlo a cuatro ojos con esa nota antes de volver a encender el canal. Si no, investigar quién reservó por encima del techo |
| `chain_unreadable` | Global o estrategia | La cadena firmada de estrategias no verifica (manipulación, o la `APP_KEY` rotada) | **No soltar** hasta que la cadena vuelva a verificar. Mientras tanto, las inscripciones **esperan** y no se cancelan (L9-69). Si se rotó la `APP_KEY`: `ops/ROTACION_APP_KEY_H9.md` |
| `drift` | Estrategia | Una estrategia con trabajos vivos sin estar activa | Revisar su cadena y su estado en el CRM |
| `unknown_send` | Global | Un envío con metadatos del H9 sin su trabajo | **No implementado** en este cierre (L9-80, gancho 5) |

**Un freno abierto por el sistema también lo suelta una persona**, con `release` y una nota. Nunca se suelta solo.

### 7.3 Bajas (*opt-out*)

**Qué hace el sistema solo** (`OutcomeTable.php:82`; `informe-w9.md` §1.4): un `opted_out` termina la inscripción, cancela lo pendiente de esa persona **en todas las estrategias**, libera sus reservas y la suprime para esa finalidad y medio. Llega por tres vías:
- por WhatsApp: el mensaje entero es una de las 27 frases cerradas;
- por voz: `no_volver_a_llamar`;
- desde la bandeja: el personal registra `opted_out`.

**Qué NO hacer:**
- volver a inscribir a mano a quien se dio de baja;
- soltar un `opt_out_spike` sin pausar antes la estrategia.

**`do_not_contact` del Inbox:** el H9 no lo escribe; lo decide una persona en el Inbox (W9, Q-6).

**Supresión de la Ley 1581** (borrar los datos personales, no solo dejar de contactar). Solo con una solicitud y la orden del propietario:
1. `sudo -u www-data php artisan outreach:suppress-subject <m:ID|l:ID>`: en seco, informa.
2. `… --apply`: para lo que tenga vivo.
3. Después, la redacción como dueño de la base con `ops/supresion-1581.sql`, en seco primero. Lo ejecutan `devops` y `database-specialist`.

### 7.4 Qué NO hacer (en cualquier etapa)

- **No usar `gobierno off` como freno.** La ejecución sigue leyendo lo publicado: el puente no consulta esa bandera (comprobado: nada en `app/Services/Outreach` lee `ultron_studio.strategies.enabled`). Además, con el gobierno apagado no se puede pausar ni revertir. Para parar: la PARADA GLOBAL o `apagar`.
- No editar el fichero de entorno a mano, ni copiar a `/root` un mando de otro commit (§2.1).
- No soltar un freno para «ver si se arregla». Primero la causa.
- No rotar la `APP_KEY` con estrategias publicadas (`ops/ROTACION_APP_KEY_H9.md` §0).
- Nunca `migrate:rollback`.
- No abrir ninguna de las dos constantes en el árbol para probar. La vía abierta se ensaya en una copia (`ops/v9-abierta.sh`).

### 7.5 Escalado

| Situación | A quién |
|---|---|
| Una salida `ABORTA` o `… DESVÍOS` del mando | Lead + `devops`, con la salida completa (no lleva valores del entorno) |
| `chain_unreadable` o sospecha de manipulación | Lead + `security` + `database-specialist` |
| Un contacto que no debía salir, o una queja | Propietario (decide) + lead; PARADA GLOBAL primero |
| Un pico de fallos de Meta | Lead; Meta, si es del proveedor |

---

## 8. Vuelta atrás por niveles (contrato §9.2)

| Nivel | Qué | Orden |
|---|---|---|
| 0 | Todo apagado | `bash /root/h9-outreach-scope.sh apagar` |
| 0b | PARADA GLOBAL, sin tocar el entorno, en ≤ 60 s | `bash /root/h9-outreach-scope.sh parada-global --motivo="…"` o CRM → Panel |
| 1 | Pausar o revertir una estrategia | CRM → Gobierno (`ultron.strategies_rollback` y vínculo verificado) |
| 2 | Apagar un canal | `canal whatsapp off` · `canal voz off` · `asistido off`, o un freno de canal |
| 3 | El código anterior | Orden del propietario; las tablas quedan y `down()` se niega con filas |
| 4 | En los proveedores (W9 y V9) | Las palancas del H8 y de Meta (propietario) |

## 9. Lo que este runbook no pudo verificar

- **Ninguna orden se ha ejecutado en producción.** Las salidas esperadas son del código y del banco.
- **No se leyó la versión del mando que hay en `/root`:** se compara con §2.1.
- **No se leyó el reparto de llaves en la pantalla de Roles de producción.**
- **Lo marcado como *deducido del código*:** el `budget_breach` al bajar cupos, la necesidad de la maestra para soltar y el efecto de `gobierno off` sobre la ejecución.
- **Los huecos de procedimiento** (P9-24, el horario de los sábados, los precios y los cupos de voz, el despliegue de W9 y V9 y de `outreach(sends-open):`) son de `devops`. Están listados en `ULTRON_STUDIO_H9_COMPLETITUD.md` §4.
