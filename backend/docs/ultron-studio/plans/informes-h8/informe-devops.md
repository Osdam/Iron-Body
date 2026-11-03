# Hito 8 · OPS8-a (paso 1) · informe de devops: simuladores, valla, banco y worker de voz

**Fecha:** 03-11-2026.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`.
**Entrega técnica:** confirmada por el lead en `fa5be95f`. Este informe y el README del banco van **sin commit**.
**Entorno:** **solo local (Mac). No es producción.** No se tocó ningún servidor, ninguna credencial real ni la red. Todo corrió en `127.0.0.1` y en el PostgreSQL desechable (`127.0.0.1:55432`).

## 1. Qué entrego

| Fichero | Qué es |
|---|---|
| `docs/ultron-studio/ops/banco-h8/sim-elevenlabs.cjs` | Simulador de ElevenLabs. Lado proveedor: **13 rutas** con `xi-api-key`. Lado emisor hacia Laravel: iniciación, herramientas con Bearer y post-llamada firmada (21 variantes). 59 códigos de violación, cada uno con su cláusula |
| `docs/ultron-studio/ops/banco-h8/sim-twilio.cjs` | Simulador de la subcuenta de voz de Twilio: **10 rutas** con Basic, credenciales por clase con la matriz de permisos de D8-5, y el emisor de avisos de gasto (`X-Twilio-Signature` sobre la base canónica, 13 variantes). 23 códigos de violación |
| `docs/ultron-studio/ops/banco-h8/sim-comun.cjs` | Común a los dos: registro JSONL sin secretos, fallos inyectables por ruta, emisión solo a `127.0.0.1` y firmas |
| `docs/ultron-studio/ops/banco-h8/valla.cjs` | La valla de red: un proxy que bloquea y anota el host. Es la del E2E del CRM, traída al backend |
| `docs/ultron-studio/ops/banco-h8/sim-pruebas.cjs` | Pruebas propias: 73 casos con oráculos independientes y el contraste con los fixtures de TEST8-a |
| `docs/ultron-studio/ops/banco-h8/{comun,montar,sembrar,ensayar}.sh` | El banco, calcado del H7: «producción» en `cfcd0d1`, base `ultron_studio_banco_h8_*` y puertos 8401–8404 |
| `docs/ops/supervisor/ironbody-voice.conf` | **Borrador** del worker `ironbody-voice` (carril `voice`, `tries=1`, `numprocs=1`, `timeout=60`, `stopwaitsecs=90`). **Sin instalar** |
| `docs/ultron-studio/ops/banco-h8/README.md` | **(este paso)** Cómo se monta, siembra y ensaya; los puertos; la superficie simulada 13 + 10 y los emisores; la correspondencia pieza → variable |
| `docs/ultron-studio/plans/informes-h8/informe-devops.md` | **(este paso)** Este informe (nombre de E1-11) |

## 2. Verificado (comandos ejecutados el 03-11-2026, con su salida)

### Pruebas propias (`node sim-pruebas.cjs`)

**73 OK · 0 FALLO · 0 OMITIDO**, en la última ejecución, con los simuladores ya alineados con B8-a y TEST8-a. Además, las pruebas provocan violaciones a propósito y las cuentan una a una: ElevenLabs 46 y Twilio 22.

**Oráculos independientes:**
- **Firma de ElevenLabs.** Las 21 variantes del emisor las verifica la clase **real** del H6, `ElevenLabsWebhookSignature::verify`, y el resultado sale como el esperado en las 21.
- **Firma de Twilio.**
  - 3 vectores de la documentación y los SDK de Twilio, comprobados recomputando el HMAC: `0/KCTR6DLpKmkAf8muzZqo1nDgQ=`, `RSOYDt4T1cUTdK1PDd93/VVr8B8=` y el de `bodySHA256` `a9nBmqA0ju/hNViExpshrM61xv4=`.
  - Un diferencial de 8 casos contra una segunda implementación en Python, con claves repetidas, duplicados y UTF-8.

**Contraste con los fixtures de TEST8-a** (`tests/fixtures/voice/`):
- `twilio-signature-vectors.json`, vectores de cálculo: **15/15**, los 6 oficiales de `twilio-python` incluidos.
- Vectores de validación: **25/25** con la tolerancia de puerto de DF-T2. Esto se midió con un arnés aparte; **no está en la suite**.
- `elevenlabs-webhook-vectors.json`: el firmador da **la misma cabecera** en los **8** válidos canónicos (los otros 3 «ok» son formas no canónicas).
- `elevenlabs-webhook-bodies.json` y `twilio-webhook-bodies.json`: coinciden **clave por clave**:
  - el cuerpo de la post-llamada (`data` y `metadata`);
  - la cabecera `X-Voice-Initiation-Secret` y los 5 campos de la iniciación;
  - el cuerpo `{conversation_id, call_sid}` de la herramienta;
  - los 10 parámetros del aviso de gasto, en su orden.
- En la suite quedan **3 casos permanentes** de contraste. Si los fixtures no existen, quedan OMITIDO.

### Mutaciones

**11 de 11 cazadas.** Rompí una regla en una copia (en el scratchpad, sin tocar el repo) y comprobé que alguna prueba se ponía roja:

| Mutación | Resultado |
|---|---|
| m1 · firma de ElevenLabs sin el punto | 65 OK · 2 FALLO |
| m2 · Twilio sin ordenar las claves | 66 · 1 |
| m3 · sin `unknown_route` | 66 · 1 |
| m4 · colgar sin vigilar | 66 · 1 |
| m5 · fuga del Bearer al registro | 66 · 1 |
| m6 · la valla no anota el CONNECT | 66 · 1 |
| m7 · iniciación sin la revisión del override | 66 · 1 |
| m8 · firma sobre la URL real | 65 · 2 |
| m9 · grabación sin vigilar | 66 · 1 |
| m10 · sin la regla de los 7 días | 66 · 1 |
| m11 · la API del banco arrancada sin la valla | el ensayo `valla` da «0 de 5» → 6 OK · 1 FALLO |

Las m1–m10 corrieron sobre la suite de **67** casos, **antes** de alinearla con B8-a y TEST8-a. **No las he repetido sobre la de 73.** Las reglas que tocan no cambiaron, pero eso es una hipótesis hasta repetirlas.

### El banco (`~/.cache/ironbody-banco-h8`, base `ultron_studio_banco_h8_ops`)

- **`montar.sh`: 12 s.** Salida:
  - 56 rutas del Studio y 3 instantáneas de sistema;
  - `voice:doctor` = `ELEVENLABS_NOT_CONFIGURED`;
  - 227 tablas con datos en el respaldo.
- **`ensayar.sh valla`: 7/7.**
  - https a fuera → `ConnectionException`; http → 403 de la valla; las dos sondas quedan anotadas.
  - PHP alcanza los dos simuladores **sin** la valla (200 200), y no entró tráfico local por ella.
  - Los 5 procesos de la API llevan `https_proxy`. El control negativo es válido: 0 coincidencias en un proceso sin `RED` y 1 en la API.
- **`ensayar.sh doctor`: 7/7.** Lo que se probó es el **adaptador real del H6** (el de `cfcd0d1`) contra el simulador:
  - fase 1 sin red: `NOT_CONFIGURED`, 0 peticiones;
  - con las piezas en el entorno de **un** proceso, `voice:doctor --verify` da `credentials_ok`, código 0 y `ulaw_8000`, con **una** petición (`GET /v1/convai/agents/{agent_id}`), 0 violaciones y la valla sin cambios.
- **`ensayar.sh worker revisar`: 13/13** propiedades del borrador.
- **`ensayar.sh worker comprobar`: PENDIENTE** (código 3). El error literal es `InvalidArgumentException: The [voice] queue connection has not been configured.` Pasa en `cfcd0d1` y en el origen `57bab8a`, y ahora es de **B8-b** (E1-10).
- **`ensayar.sh worker instalar` / `retirar`:** `supervisorctl status` pasa de 13 a **14** RUNNING y vuelve a **13**.
- **`ensayar.sh cierre`: 4/4.** 0 violaciones en los dos simuladores y la valla solo con sus 2 sondas.
- **Emisores contra la API real del banco:** la post-llamada y el aviso de gasto dan **404**, como se espera: esas rutas son de B8-b. La valla siguió en 2.
- **`arrancar`/`parar`:** idempotentes, con los puertos 8401–8404 libres al parar.

## 3. Impacto, vuelta atrás y autorizaciones

- **Impacto en producción: ninguno.** Son ficheros de `docs/` y `ops/`; el worker es un borrador que nadie instala sin la orden del propietario (fase 1, OPS8-b).
- **Vuelta atrás:**
  - de la entrega: revertir `fa5be95f` (solo documentación y herramientas del banco, sin efecto en la aplicación);
  - de este paso: borrar los dos ficheros nuevos;
  - del banco: `bash ops/banco-h8/ensayar.sh $B parar; rm -rf $B; dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h8_ops`. Solo afecta al banco desechable; el prefijo `ultron_studio_banco_h8_` impide recrear otra base.
- **Pendiente de autorización: nada** de OPS8-a. Instalar `ironbody-voice` en el servidor es de OPS8-b, en la fase 1, con la orden del propietario. Los pasos de instalar y retirar están en la cabecera del borrador.

## 4. Lo que cambió en el contraste con B8-a y TEST8-a (ya en `fa5be95f`)

- **API de herramientas.** B8-a crea las herramientas con `POST /v1/convai/tools` y las referencia en `prompt.tool_ids`; las de sistema van en `built_in_tools`. El simulador añadió `tools_create` y `tools_get`, con su almacén, revisa cada herramienta **una vez** (al crearla) y marca `tool_id_unknown` para un id que no emitió.
- **Sin `PATCH` ni `DELETE` de agentes.** B8-a no tiene ninguno (D8-7), así que ahora son violación.
- **Detección de contestador.** `twilio_machine_detection` llega como objeto en B8-a y pasa a Twilio como `MachineDetection=Enable`.
- **Patrones.** El uuid usa el patrón de B8-a, sin exigir versión.
- **Números sintéticos** con la convención de TEST8-a: +57 601 000 00xx y +57 300 000 00xx.
- **Tramo de transferencia** como llamada hija con `parent_call_sid` (DF-W4).
- **Lo que no he hecho:** **no he ejecutado los adaptadores de B8-a contra el simulador.** Eso es de B8-b y del E2E de TEST8. El contraste fue de lectura: rutas, verbos, cabeceras y formas.

## 5. Para OPS8-b, B8 y TEST8: hallazgos, riesgos y deuda

1. **E1-12 cambia el estado de partida del banco.** `montar.sh` modela la producción de antes de las 22:55 UTC: `ELEVENLABS_NOT_CONFIGURED`, sin piezas. OPS8-b tiene que montar el estado real antes de ensayar `deploy-h8-backend-fase1.sh`:
   - las 4 piezas del H6, el secreto de las herramientas y `ELEVENLABS_ENABLED=true`, tomados de `sim/piezas.conf`;
   - un `integrations-snapshot --verify-voice` contra el simulador.

   Así se ensaya que el despliegue **no aborta** al encontrarlas y que el smoke y el monitor esperan `ELEVENLABS_VERIFIED`, que es lo que pide E1-12. El caso «fase 1: NOT_CONFIGURED» de `ensayar.sh doctor` describe el banco, no la producción de hoy.
2. **El aviso del simulador es el de antes de E1-3.** La constante `AVISO` de `sim-elevenlabs.cjs` es el primer turno del agente en los guiones y lleva el texto de P-9 de la congelación. Si la guarda posterior compara con el texto de E1-3, el guion `limpio` saldría «sin aviso». Es una línea, pero no la cambio aquí porque este paso es solo documentación. Lo hace quien abra el siguiente cambio de los simuladores, a la vez que TEST8-a reexporta `world.p9Notice`.
3. **Los ensayos del banco no se han repetido** tras los últimos cambios de los simuladores (API de herramientas, sin `PATCH`, contestador, uuid, números). Las pruebas propias sí (73/73). Hay que repetirlos al empezar OPS8-b: `ensayar.sh $B arrancar`, `valla`, `doctor`, `cierre`.
4. **El `supervisorctl` del banco no modela el FATAL** del worker de voz. Sin la conexión `voice`, el worker real quedaría en FATAL tras `startretries`, y el sustituto lo enseña RUNNING. Lo ve `worker comprobar` (PENDIENTE), no `status`. El despliegue de OPS8-b tiene que comprobar la conexión **antes** de copiar el fichero, y su ensayo debería simular el FATAL, como `sup-backoff` en el H6.
5. **Rutas y formas por confirmar en la referencia oficial (D8-3):**
   - 6 de las 13 rutas de ElevenLabs: listar agentes, números y conversaciones, leer número y conversación, y borrar conversación;
   - la forma de la respuesta de `POST /v1/convai/tools` (el simulador devuelve `{id, tool_config, access_info, usage_stats}` y B8-a solo lee `id`);
   - el 429 de concurrencia de ElevenLabs;
   - el código de una key restringida de Twilio sin permiso (el simulador da 401 con el código 20003);
   - la respuesta al colgar una llamada ya terminada;
   - el formato de `DateFired` y de `IdempotencyToken`;
   - las unidades de `metadata.cost`.

   Están marcadas en el código y en `GET /__surface`.
6. **La valla solo ve HTTP.** Por eso el banco pone `MAIL_MAILER=log`, y en el banco no hay Redis. Un socket crudo no pasaría por ella. Es un límite declarado, igual que el del E2E.
7. **Secretos.** Ninguno real en ningún sitio:
   - las piezas son aleatorias, en 600, fuera del repo, y no viajan por la línea de órdenes ni por el entorno de los simuladores (se leen de `--config`);
   - el barrido de higiene comprueba que los registros no llevan ninguna.

   Lo que sí queda abierto es de E1-12: **la clave de ElevenLabs de producción viajó por el chat y hay que rotarla antes de la fase 3.** La rotación es del propietario y exige su autorización.
8. **Estado del banco.** Está montado y **parado**, en `~/.cache/ironbody-banco-h8` y con la base `ultron_studio_banco_h8_ops`, para que OPS8-b lo reutilice. Cómo limpiarlo, en el §3.
