# Informe E18-9 · El `call_ref` de una llamada real ya no la alcanza desde otra conversación

**Autor:** `backend`. **Fecha:** 11-11-2026. **Entorno:** local (árbol `_worktrees/ultron-h8`, rama
`feat/ultron-studio-h9`, HEAD `0d656946`). Sin commit, sin push, sin producción y sin red. He tocado
`backend/app/Services/Telephony/**`, `backend/tests/**` y este informe. No he tocado el contrato, `ops/`, el CRM ni el
fixture.

---

## Resumen para el lead

**Estado: arreglado y probado en local (SQLite).** Es el arreglo puntual de P-1 (revisión de seguridad de E18-8). No
lleva migraciones, rutas ni claves de configuración, y `VoiceTools::lab()` no se ha tocado.

### Qué cambió

| fichero:línea | cambio |
|---|---|
| `app/Services/Telephony/VoiceTools.php:211-216` | `find()`: el último recurso por `call_ref` pasa por el filtro nuevo. |
| `app/Services/Telephony/VoiceTools.php:225-246` | Nuevo `withoutOtherIds()`, privado, con la regla de abajo. |
| `app/Services/Telephony/VoicePostCall.php:252-261` | Docblock de `find()`. |
| `app/Services/Telephony/VoicePostCall.php:269-271` | `find()`: en el paso por `call_ref`, el mismo filtro. |
| `app/Services/Telephony/VoicePostCall.php:281-297` | Nuevo `withoutOtherIds()`, idéntico al de `VoiceTools`. |

### La regla exacta

Por `call_ref` solo se encuentra una fila cuyos ids del proveedor, si ya tiene alguno, son los del cuerpo:

```sql
… AND call_ref = ?
  AND (provider_conversation_id IS NULL OR provider_conversation_id = :conversación_del_cuerpo)
  AND (provider_call_sid        IS NULL OR provider_call_sid        = :call_sid_del_cuerpo)
```

Cuando el cuerpo no trae uno de los dos ids, esa rama queda solo en `IS NULL`.

Este paso solo se ejecuta cuando la fila no se encontró antes por conversación ni por `call_sid`. En ese punto la
regla equivale a **«la fila no tiene ningún id del proveedor»**. Según DB8 (`vc_provider_binding_chk`), eso es una
saliente en `requested`, `ambiguous`, `canceled` o `failed_to_start`. Las entrantes siempre llevan sus dos ids y los
tramos de transferencia ya estaban excluidos.

La rama `= del cuerpo` solo sirve para la carrera de PostgreSQL (§2.2). Va en el `WHERE` y no en PHP por dos motivos:

- un cuerpo ajeno no bloquea (`FOR UPDATE`) la fila de una llamada real;
- si el job guarda los ids mientras otra petición espera ese cerrojo, PG vuelve a evaluar el filtro sobre la fila
  nueva y la sigue encontrando.

Es la regla que propone la revisión, extendida al `call_sid`. Quedarse en `whereNull('provider_conversation_id')`
dejaba expuesta una saliente en curso con **solo** `call_sid`, porque DB8 la admite: el job guarda lo que devuelva el
proveedor. Hay una prueba para ese caso (§3).

### Ficheros para el commit

```
backend/app/Services/Telephony/VoiceTools.php
backend/app/Services/Telephony/VoicePostCall.php
backend/tests/Feature/Telephony/Support/VoiceFixtures.php      (una ayuda nueva: outboundWithOneProviderId)
backend/tests/Feature/Telephony/VoiceToolsTest.php
backend/tests/Feature/Telephony/VoicePostCallWebhookTest.php
backend/tests/Feature/Telephony/VoiceLabToolsTest.php
backend/docs/ultron-studio/plans/informes-motores/informe-e18-9.md
```

**No van en el commit.** Estos ficheros ya estaban modificados en el árbol y no son míos:

- `backend/docs/ultron-studio/ULTRON_STUDIO_CHECKLIST_OPERATIVO.md`
- `backend/docs/ultron-studio/ULTRON_STUDIO_H8_VOZ.md`
- `backend/docs/ultron-studio/ops/**`

La fila E18-9 del contrato sigue como «Pendiente», porque no podía editar el contrato. Te toca actualizarla.

### Pruebas: rojo y luego verde

Hay **17 pruebas nuevas**, todas por la ruta HTTP real:

- **15 estaban en ROJO** contra el código de HEAD. Las pruebas son las finales: cambié temporalmente los dos servicios
  por su versión de HEAD y luego los restauré, con el sha1 comprobado.
- **2 son el caso legítimo** y están en verde antes y después, como debe ser.

| fichero | HEAD (sin arreglo) | con el arreglo |
|---|---|---|
| `VoiceToolsTest` | 36 pruebas, **8 fallos** | **OK (36 pruebas, 207 aserciones)** |
| `VoicePostCallWebhookTest` | 26 pruebas, **6 fallos** | **OK (26 pruebas, 233 aserciones)** |
| `VoiceLabToolsTest` | 14 pruebas, **1 fallo** | **OK (14 pruebas, 355 aserciones)** |

Lo que hacía HEAD en esos rojos, sacado de la salida de los fallos:

- `consultar_planes` → `{"ok":true,"verdict":"ok","planes":[]…}` sobre la llamada ajena.
- `no_volver_a_llamar` → `{"ok":true,"excluido":true}`.
- Transcripción ajena → `{"status":"completed","sealVerified":false|true,"violations":1}`: atada a la llamada real.
- `call_initiation_failure` ajeno → evento sobre la llamada real.
- En el laboratorio, la primera herramienta (`consultar_horarios`) respondía `ok` con la llamada ajena.

Antes de dar el rojo por bueno corregí dos errores de montaje de las propias pruebas:

- Los disparadores de DB8 fijan cada id una sola vez. Por eso la saliente con un solo id se crea por su camino, con
  `outboundWithOneProviderId`.
- Hay un único por número para las llamadas vivas.

Ninguno de esos rojos era de montaje: los 15 son el bug.

**Regresión.** Cada orden se ejecutó por separado, con
`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`:

| orden | resultado |
|---|---|
| Guardas estáticas (la orden del encargo, paquete entero) | **OK (138 pruebas, 178 638 aserciones)**. Ninguna lista cerrada tocada. |
| `tests/Feature/Telephony` | **OK: 477 pruebas, 207 104 aserciones, 137 omitidas.** Las 137 son «Solo PostgreSQL» de `Schema/*`, ninguna nueva. 460 eran previas y 17 son nuevas. |
| `tests/Unit/Voice` | **OK (285 pruebas, 21 490 aserciones)** |
| `tests/Unit/Telephony` (paridad del fixture; extra) | **OK (81 pruebas, 4 989 aserciones)** |
| `tests/Feature/UltronStudio/UltronStudioVoiceLabSessionsTest.php` (extra: los cuerpos ajenos llegan ahora a `lab()`) | **OK (21 pruebas, 497 aserciones)**. No salió la intermitencia conocida. |
| `vendor/bin/pint` sobre los 6 ficheros | `passed` |

El fixture (`tests/fixtures/voice/elevenlabs-webhook-bodies.json`) no tiene diff. Sus 21 casos de §tools y 16 de
§postCall pasan sin cambios. **Ninguno depende de lo que se cierra.** El único que casa por `call_ref` es
«`call_initiation_failure` antes de que el job guarde `dialing`», y es una fila `requested` sin ids, que sigue valiendo.

### Qué NO verifiqué

- **PostgreSQL.** No hay arnés de PG. Comprobé que la SQL generada con la gramática de PG es la de arriba: `toSql()`
  sobre un `Capsule` sin conexión, sin tocar ninguna base. La carrera de §2.2 (que PG vuelva a evaluar el `WHERE`
  después de esperar el cerrojo) está **razonada, no probada**. En SQLite no hay cerrojos de fila.
- **ElevenLabs de verdad**, sin red ni cuenta. Dos cosas siguen siendo hipótesis:
  - que el cuerpo de una herramienta en una llamada de Twilio siempre traiga `call_sid`;
  - que las post-llamadas de teléfono traigan `metadata.phone_call.call_sid` o `metadata.body.CallSid`. El fixture dice
    que sí.
- **Producción:** nada. Hoy no hay llamadas reales (`CALLS_ENABLED_IN_CODE = false`).

### Casos legítimos que la regla podría dejar fuera

1. **Una saliente en curso con solo `call_sid`** (el proveedor no devolvió `conversation_id` al iniciar) cuya
   post-llamada o fallo de iniciación llegue **sin** `call_sid`. Antes se ataba por `call_ref`. Ahora va por el camino
   de una conversación desconocida: `unknown_provider_call` y **freno**, así que falla cerrado y se nota. Si el cuerpo
   trae el `call_sid`, se encuentra por él (paso 2, sin cambios). El fixture y el verificador indican que lo traen; no
   lo he comprobado con ElevenLabs real.
2. **Ids del proveedor que no coincidan** entre la respuesta de iniciar la saliente (lo que guarda el job) y lo que
   luego llega en las herramientas o en el webhook. Si ni la conversación ni el `call_sid` coinciden, antes la
   rescataba el `call_ref`; ahora no. No conozco ningún caso así, y si pasara sería un fallo del proveedor que conviene
   ver, no tapar.

### Lo que queda abierto (fuera del alcance; para architect o security)

- **Las filas SIN ids siguen alcanzables por `call_ref` desde cualquier conversación.** Son `requested` y
  `ambiguous`, en una ventana corta o hasta la conciliación, y también `canceled` y `failed_to_start`. Es el caso
  legítimo que protege el último recurso, así que no se puede cerrar con esta regla.
  - En las herramientas solo produce un `call_not_active` con su evento sobre esa fila. Ninguna herramienta se ejecuta,
    porque la fila no está en curso.
  - En la post-llamada, una transcripción del laboratorio con ese `call_ref` se seguiría atando a la fila. El agente no
    es el sellado, así que salta `seal_mismatch` y el freno (falla cerrado). En `requested` o `ambiguous`, además, la
    pasaría a `completed` y le escribiría la conversación del laboratorio.
  - Para explotarlo hace falta conocer ese `call_ref` y acertar la ventana. No sale por la API del CRM.
  - Lo cierra la **opción estructural** de la revisión: `system__agent_id` en el cuerpo de las herramientas, y en la
    post-llamada exigir el agente sellado antes de atar por `call_ref`.
- **Observabilidad.** Un cuerpo rechazado por esta regla deja la misma línea que cualquier `call_not_found`
  (`voice.tool`, sin `lab`). Distinguir un intento de usar el `call_ref` de una llamada viva costaría una consulta más.
  No lo he añadido porque no estaba pedido. Decide si lo quieres.
- **Efecto lateral, a mejor.** El oráculo `call_not_active` frente a `call_not_found` (P2 de la revisión, «fuera del
  diff») desaparece para las llamadas que ya tienen sus ids: desde otra conversación responden `call_not_found`. Sigue
  existiendo solo para las filas sin ids.

---

## 1. Por qué existía el atajo y por qué se puede cerrar así

El comentario original de `VoiceTools::find()` lo dice: el último recurso por `call_ref` existe para «una saliente que
el proveedor ya inició y cuyo job aún no guardó los ids». DB8 impide que esa fila tenga ids mientras está en
`requested` o `ambiguous`, así que solo su `call_ref` la encuentra. En `VoicePostCall` es DF-S2: el fallo de
iniciación puede llegar antes de que el job escriba `dialing`.

En los dos `find()` el orden es conversación → `call_sid` → `call_ref`, sobre la misma consulta base. Por eso, al
llegar al tercer paso, cualquier id que tenga la fila es **distinto** del que trae el cuerpo, o el cuerpo no lo trae;
si no, se habría encontrado antes. Exigir que «los ids que tenga sean los del cuerpo» es, sin concurrencia, lo mismo
que exigir «sin ids», que es exactamente el caso legítimo. El atajo solo servía para llegar a filas que **ya** eran de
otra conversación u otra llamada de Twilio, y eso es P-1.

## 2. Decisiones de diseño

### 2.1 Por qué también el `call_sid`

La revisión proponía `whereNull('provider_conversation_id')`. Pero DB8 admite una saliente en curso (`dialing` o
`active`) con **solo** `provider_call_sid`, porque el job guarda lo que devuelva el proveedor
(`PlaceOutboundVoiceCall::record`, `:281-286`). Con ese filtro, la conversación del laboratorio (sin `call_sid`) con
el `call_ref` de esa saliente la seguiría alcanzando.

Las pruebas cubren las tres formas de fila:

- los dos ids;
- solo `call_sid`;
- solo la conversación.

### 2.2 Por qué en el `WHERE` y con la rama `= del cuerpo` (la carrera de PostgreSQL)

Supón que el job (`requested` → `dialing` con los ids) y una herramienta o post-llamada de esa misma llamada se cruzan:

1. Los pasos 1 y 2 no ven la fila, porque el `UPDATE` del job aún no está confirmado.
2. El paso 3 la encuentra por `call_ref`, espera su cerrojo y, cuando el job confirma, PG (READ COMMITTED) vuelve a
   evaluar el `WHERE` sobre la versión nueva.
3. Con `IS NULL OR = del cuerpo`, la fila nueva sigue casando, porque sus ids son los del cuerpo. Es el mismo
   resultado que antes del arreglo.
4. Con un `IS NULL` a secas se perdería: `call_not_found` o freno para una llamada legítima.

Si el filtro se aplicara en PHP después del `first()`, la fila de la llamada ajena quedaría bloqueada (`FOR UPDATE`)
durante la petición. **Razonado, no probado en PG.**

### 2.3 Dos métodos privados iguales en vez de uno compartido

Un *scope* en `VoiceCall` habría salido de `app/Services/Telephony/**`. Que `VoicePostCall` llame a `VoiceTools`
crearía una dependencia que hoy no existe. Son dos métodos de tres líneas, con el mismo nombre, que se citan
mutuamente en el docblock.

### 2.4 `VoiceTools::lab()` sin tocar

Los cuerpos que antes casaban con una llamada real por `call_ref` ahora llegan a `lab()`, si son una herramienta de
lectura sin `call_sid` y de una conversación desconocida. `lab()` pregunta al laboratorio por ese uuid; no es una
sesión, así que devuelve null y la respuesta es `call_not_found`, sin evento y sin cupo. Lo cubre
`VoiceLabToolsTest::test_el_call_ref_de_una_llamada_real_puesto_en_la_conversacion_del_laboratorio_no_la_alcanza`.

## 3. Las pruebas nuevas

| prueba | qué fija | antes | después |
|---|---|---|---|
| `VoiceToolsTest::test_el_call_ref_de_una_llamada_que_ya_tiene_sus_ids_no_la_encuentra_desde_otra_conversacion` (8 casos) | `consultar_planes` y `no_volver_a_llamar` × {conversación web ajena, conversación y `call_sid` ajenos} × {los dos ids, solo `call_sid`, solo conversación}. Comprueba: `call_not_found` neutro byte a byte, `tool_calls` sigue en 3, estado `active`, ids intactos, 0 exclusiones, 0 eventos y nada a la red. | 8 rojos | verde |
| `VoiceToolsTest::test_la_saliente_que_aun_no_guardo_sus_ids_se_sigue_encontrando_por_su_call_ref` | Caso legítimo: `requested` y `ambiguous`, sin ids, con cuerpo web y con cuerpo de Twilio. Resultado: `call_not_active` y el evento `tool_call` atado a ESA fila. | verde | verde |
| `VoicePostCallWebhookTest::test_una_post_llamada_de_otra_conversacion_con_el_call_ref_de_una_llamada_real_no_se_ata_a_ella` (6 casos) | Firmadas con el secreto del webhook: transcripción web con `dynamic_variables.call_ref`, transcripción de teléfono con `user_id` y `call_initiation_failure` con `user_id`, cada una × {los dos ids, solo `call_sid`}. Comprueba: `{"ok":true,"unknown":true}`; la llamada real sin cambios en estado, sello, duración, `ended_at`, `failure_code` e ids; 0 eventos sobre ella; solo `unknown_provider_call` sin llamada; freno `unknown_provider_call`. | 6 rojos | verde |
| `VoicePostCallWebhookTest::test_la_transcripcion_de_una_saliente_que_aun_no_guardo_sus_ids_se_ata_por_su_call_ref` | Caso legítimo: transcripción de una `requested` sin ids. Se ata por `user_id`, pasa a `completed`, guarda sus ids y su duración, y deja `post_call_transcription` y `guard_verdict`. | verde | verde |
| `VoiceLabToolsTest::test_el_call_ref_de_una_llamada_real_puesto_en_la_conversacion_del_laboratorio_no_la_alcanza` | De punta a punta con una sesión real del laboratorio: las 4 herramientas con el `call_ref` de una llamada `active` dan `call_not_found`, sin cupo de la llamada ni de la sesión, sin exclusiones ni eventos. La sesión, con SU uuid, sigue respondiendo las dos de lectura. La post-llamada firmada del ensayo con el `call_ref` cambiado vuelve al laboratorio (`{"ok":true,"lab":"recorded"}`, sesión `ended`) y la llamada real queda intacta, sin eventos ni freno. | rojo | verde |

**Ayuda nueva en `tests/Feature/Telephony/Support/VoiceFixtures.php:282-299`: `outboundWithOneProviderId()`.** Crea
una saliente en curso con un solo id del proveedor por el camino que admiten los disparadores, que fijan cada id una
sola vez. No cambia `voiceCall()` ni a ninguna prueba existente.

**Casos límite para `tester`:**

- La carrera de §2.2 en el arnés de PG: dos conexiones, el job escribiendo los ids y una herramienta con esos mismos
  ids y el `call_ref` esperando el cerrojo. Debe responder, no dar `call_not_found`.
- Lo mismo con una post-llamada.
- Que un cuerpo ajeno no deje la fila real bloqueada: `lock_timeout` corto desde otra conexión mientras dura la
  petición.
