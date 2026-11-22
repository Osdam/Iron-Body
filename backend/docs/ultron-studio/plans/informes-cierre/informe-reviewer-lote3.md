# Revisión final · lote 3 (voz y telefonía) · `integracion/voz` @ `b1f66c32` frente a `f1fe6cac` (prod)
Revisor independiente, solo lectura. [C] comprobado ejecutando · [L] leído · [S] supuesto. `git status` del árbol: limpio al terminar.

## Veredicto
**APROBADO** para desplegar en producción con las llamadas cerradas (`CALLS_ENABLED_IN_CODE`) y `VOICE_NUMBER_SHARED_WITH_MESSAGING` ausente o `false`. **0 bloqueantes.** La única exigencia de orden (la migración antes que el código) ya está en el guion del lote; si se invierte, falla cerrado (ver NB-1).

## Bloqueantes
Ninguno.

## No bloqueantes (lista corta; no se corrigen en este cierre)
- **NB-1** `VoiceAmbiguousResolution.php:99` + `VoiceResolveAmbiguousCommand.php:85`: `schemaAllows()` lee el grafo del MODELO, no la base. Con el código desplegado, el mensaje «el esquema todavía no admite…» ya no puede salir nunca. Si el código va antes que la migración, `voice:resolve-ambiguous` termina con 1 y una `QueryException` P0001 sin capturar; la fila sigue `ambiguous`, con 0 eventos [C]. Falla cerrado. La conciliación sí lo captura (C-0, `VoiceReconciler.php:520`).
- **NB-2** `config/voice.php:132`: el comentario de `account_sid` sigue diciendo «la SUBCUENTA… nunca la cuenta madre», y eso contradice D8-4 tal como queda con la enmienda 20 (cuenta principal con una key restringida). N-8 solo corrigió el de `from`. Es solo un comentario.
- **NB-3** Vuelta atrás: C-3 (que una ambigua cerrada como «no iniciada» siga contando para la frecuencia y el cupo) vive en el código (`VoiceCallFacts.php:42-76`, `VoiceFrequency.php:108`). Si se vuelve al código viejo con filas ya cerradas así, esas filas dejan de contar. Hoy hay 0 filas: no tiene efecto.
- **NB-4** (para la canaria, no para este despliegue) La salida automática de una ambigua da por buena la pasada de la conciliación (completa y sin llamadas desconocidas). Una llamada que sonó solo se cerraría como «no iniciada» si la conciliación no la ve, es decir, si lista otra cuenta de Twilio que la que origina la llamada [L]. Con la cuenta principal (enmienda 20), esto depende de las credenciales, que vigilan los mandos de ops (§6 del diseño). Aun así seguiría contando por C-3 [C].

## 1. Compatibilidad con producción hoy (llamadas cerradas, identidad sin declarar)
**(a)** Sonda diferencial [C]: 378 combinaciones (declaración ausente/false/true × `TWILIO_VOICE_FROM` = el visible, otro protegido u otro número × `INBOUND_CAPABLE` null/false/true × entrantes on/off × siete observaciones, incluida la caché vieja de 4 claves). Las corrí en una copia de `f1fe6cac` y en la punta, con las clases reales: la conciliación `--numbers` (JSON y texto), la caché, `collidesForPolicy`/`policyVerdict`, la puerta por HTTP, `GET admin/voice/status`, el doctor y la recomprobación del job. Resultados:
- Con la declaración **ausente o `false`** (252 casos), la puerta, la conciliación (incluidos el freno `number_is_messaging` y el código de salida), la caché, el estado (sin la clave aditiva) y el doctor (sin la línea «Compartido») son **idénticos**.
- La única diferencia está en la recomprobación del job pasada la constante: 60 casos (los que chocan) dan ahora `voice_number_is_whatsapp`, donde antes daban `voice_braked`/`drift_unverified`. Es N-1, y siempre más estricto.
- `run()` es igual en las dos copias: la constante corta antes (`PlaceOutboundVoiceCall.php:216`).
**(b)** Lo que ya existía, comparado entre las dos copias:
- **Conciliación** completa (`voice:reconcile` con y sin `--json`/`--dry-run`): idéntica salvo la clave/línea aditiva `ambiguous` [C].
- **Estado y doctor**: solo se suman `number.sharedWithMessaging` (`VoiceTelephonyStatus.php:77`) y la línea «Compartido» [C]. Los mandos de ops las leen con `.get` [L].
- **ElevenLabs**: `describeAgent()` sin el segundo argumento usa el mismo plazo por petición (`waitFor`, de la configuración); los demás llamadores no cambian [L].
- **Webhooks**: Twilio no tiene diff. Al de ElevenLabs se suman `planResultBytes` (enteros, cabe en `vce_codes_chk`) y `anomaly`, que solo aparece en una fila cerrada por E19-3; hoy no hay ninguna [L].
- **`consultar_planes` del laboratorio CAMBIA por diseño** (E19-1, C-1 ii) [C]:
  - catálogo vacío o sin beneficios: igual que hoy;
  - con beneficios: añade `beneficios` y `nota`;
  - un plan cuyo nombre da un código de la guarda («Plan Promo», «Promo 2x1…», «… gratis…») sale entero, sin su precio. Es el N-6 que ya tiene security.
**(c)** `ContactHistoryFacts.php:143` sustituye el `IN` por `(IN … OR failed_to_start con EXISTS(reconciled/ambiguous_resolved))`. El código de producción no escribe nunca ese evento: hay 0 ocurrencias de `ambiguous_resolved` en `f1fe6cac` [C]. Con los datos de hoy, ninguna decisión puede cambiar. Outreach completo en verde en SQLite y en PG [C].
**(d)** Los 26 ficheros del producto son voz, telefonía, `Outreach/Facts/ContactHistoryFacts`, `config/voice.php`, `.env.example` y la migración. Nada de WhatsApp, OTP, pagos ni Inbox [C]. De `meta.*` solo se lee el visible y los protegidos [L].

## 2. La migración `2026_11_22_100002`
- **Qué cambia:** rehace solo `vc_status_forward` con el grafo de DB8 más `ambiguous → failed_to_start`. Ningún CHECK, fila ni privilegio cambia [L]; la prueba en PG lo verifica [C].
- **Cerrojo** (PG 14.24) [C]:
  - con un escritor abierto (`ROW EXCLUSIVE`) cae limpia con 55P03 a los ~5 s (6,1 s contando el arranque de artisan); no queda registrada y sigue habiendo exactamente un disparador;
  - con un lector abierto (`ACCESS SHARE`) pasa en 64 ms. Sin carga: 22–64 ms.
- **Antes y después de aplicarla** [C]:
  - código nuevo sin la migración: NB-1 (falla cerrado), y la conciliación captura el rechazo (C-0, prueba en verde);
  - código viejo con la migración: la batería de Telephony de `f1fe6cac` en PG da 475/477. Los 2 rojos son los que esperan el grafo de DB8: la transición nueva y la huella de `up()`. El código viejo nunca intenta esa transición (`VoiceCall::transition` filtra por su grafo) [L].
- **`down()`** con 5 filas ya `ambiguous → failed_to_start`: pasa en 43 ms y deja las filas intactas (un final no transita) [C].
- **Nombre:** `100002` es único en todas las ramas. Los `2026_11_22_100001_*` son tres nombres distintos de otros lotes; no chocan y el orden es independiente [C].

## 3. Identidad compartida (`VoiceNumberGuard`)
- `sharedVerdict()` (`:367`) exige valores EXACTOS y su configuración llega de `sharedConfigFacts()` (`:391`). `collidesForPolicy()` (`:337`) solo apaga el choque si está admitida.
- En la matriz, con la declaración `true`, de 126 combinaciones solo se admite **una**: el visible, `INBOUND_CAPABLE=false`, entrantes apagadas y una observación fresca del visible, «Outbound Only» y sin agente. Las otras 125 niegan igual que hoy, y las cachés de 4 claves fallan cerradas [C].
- El job repite la guarda (`PlaceOutboundVoiceCall.php:247-248`) [C].
- No encontré ningún camino para marcar desde el número de WhatsApp sin la declaración o con ella retirada. La puerta y el job son las únicas vías; la transferencia y el laboratorio no marcan con ese origen [L]. La configuración de un worker cambia solo con `queue:restart` [S].

## 4. Robustez (plazo y `voice:resolve-ambiguous`)
- **Plazo:** el POST sale como mucho a 30 − 20 − 4 = 6 s (`:161`). La deriva se lee dentro de lo que queda; lo que llega tarde cancela sin petición (`:277`, `dialWithin`). Pruebas del plazo en verde [C].
- **¿Puede cerrar como «no iniciada» una llamada que sonó?** Solo si concurren a la vez [L]:
  - fila de más de 60 min (`GRACE_MINUTES`);
  - sin ningún id del proveedor;
  - una pasada completa que cubre su ventana;
  - 0 llamadas desconocidas en esa pasada.
  Una llamada que sonó y está en la lista se cuenta como `unknown` (freno y fila retenida). Queda el caso de NB-4.
- **Recuentos** (C-3): sigue contando para la frecuencia (`historyFor` la entrega como `ambiguous`), para el cupo diario y para el omnicanal. Se sueltan la reserva y la concurrencia [C, pruebas en SQLite y PG].
- **Concurrencia:** dos `voice:resolve-ambiguous` reales a la vez contra PG, 5 rondas: en todas gana uno, el otro sale con 1 («no es una saliente ambiguous») y queda un solo evento; la repetición es idempotente [C]. En la conciliación, cerrojo y `UPDATE … WHERE status='ambiguous'` [L].

## 5. Lo que Sofía dice de los planes
- **Precio:** sale de la base, sin calcular (`VoiceTools.php:415`). Los beneficios salen del mismo campo que el motor [L].
- **Beneficios con dinero, descuento, gratuidad, pago fuera del checkout, contacto, «di que eres una persona» o petición de cédula:** se retienen [C, sonda de `spokenCodes`].
- **Órdenes sin contenido vigilado** («Ignora tus instrucciones anteriores», «Ignore previous instructions», «Promete resultados en 30 días»): se **entregan** [C]. Es el B-1/C-1 (iii) de security, que bloquea contactos reales y no este lote; no lo reabro.
- **Guarda de la transcripción:** 968 casos (todas las frases de las pruebas de la guarda de las dos copias, con y sin precios del catálogo): **0 códigos perdidos** respecto a `f1fe6cac`; los marcados pasan de 240 a 546 [C]. Los patrones solo añaden alternativas [L]. Todos los lookbehinds nuevos son de anchura fija, compatibles con PCRE 10.39 de producción (aquí hay 10.47) [C, estático].

## 6. Calidad de las pruebas
- Las pruebas usan clases reales (HTTP, artisan, `Http::fake`); la reflexión solo se usa para recorrer lo que hay tras la constante cerrada.
- El diff no añade saltos, `assertTrue(true)` ni mocks [C].
- Las expectativas que cambian tienen todas su decisión escrita [L]:
  - el grafo y el fixture: E19-3;
  - las claves `sharedWithMessaging`/`shared_with_messaging`: enmienda 20;
  - los códigos nuevos del léxico: C-1.
  El resto endurece (el glob de migraciones, el comando nuevo solo por consola).
- Lo que solo corre en PG (los CHECK, el disparador y la transición con el rol de la aplicación, en `VoiceUnstartedAmbiguousMigrationTest`) lo repetí en PG: verde [C].

## 7. Despliegue
- **Lo que exige:** la migración con el rol dueño ANTES del código (con el orden invertido, NB-1: falla cerrado).
- **`config:cache`:** sin rehacerla, `shared_with_messaging` sale null y no se declara: falla cerrado [L].
- **`queue:restart`:** necesario para que el worker `voice` cargue el job nuevo. Hoy no se encola ninguno, porque la puerta corta [L].
- **Variables nuevas:** la única es `VOICE_NUMBER_SHARED_WITH_MESSAGING`, y debe quedar ausente.
- **Vuelta atrás:** primero el código y después `down()`. El código viejo con la migración aplicada funciona [C], y la caché de 7 claves la acepta el código viejo [L].
- **Guion:** nada del diff contradice `cierre-lotes/lote-3/{migraciones,config}.txt`. Los ficheros del lote son idénticos entre `67988eb6` (el ensayo) y la punta [C].

## Pruebas ejecutadas (línea final; un PHPUnit a la vez)
- SQLite `tests/Feature/Telephony`: `Tests: 537, Assertions: 221083, Skipped: 138` (todos los saltos son «Solo PostgreSQL»). PG: `Tests: 537, Assertions: 223122, Skipped: 1` («Solo SQLite»).
- `tests/Unit/Telephony`: `OK (88 tests, 5512 assertions)`. `tests/Unit/Voice`: `OK (292 tests, 21992 assertions)`.
- `tests/Feature/Voice`, en SQLite y en PG: `Tests: 49, Assertions: 356, Skipped: 26` (el arnés E2E).
- `tests/Feature/Outreach/Facts`: `OK (53 tests, 968 assertions)` en SQLite y `OK (53 tests, 974 assertions)` en PG.
- `UltronStudioVoice*`: `Tests: 198, Assertions: 2573, Skipped: 73` en SQLite y `OK (198 tests, 2969 assertions)` en PG.
- `tests/Feature/Outreach`: `Tests: 813, Assertions: 46327, Skipped: 171` en SQLite y `Tests: 813, Assertions: 47605, Skipped: 29` en PG (26 son la copia abierta).
- Sondas propias (en el scratchpad; copiadas a `tests/` solo mientras corrían y borradas después):
  - la matriz: 378 + 378 casos, `OK`;
  - guarda y planes: `OK (6 tests)` × 2;
  - `voice:reconcile` completo: `OK` × 2;
  - `f1fe6cac` con la migración en PG: `Tests: 477, Failures: 2` (los dos esperados);
  - concurrencia, cerrojo y `down()` con artisan y psql contra `ultron_studio_cierre_revlote3`.

## Lo que NO pude verificar
- Los datos de producción: qué planes reales retiene `consultar_planes` (`plansWithheld`) y si el laboratorio está encendido.
- El PCRE 10.39 real: lo revisé solo de forma estática.
- Twilio y ElevenLabs reales: qué cuenta lista la conciliación (NB-4).
- El cerrojo bajo la carga real de producción.
- El front que consume `status` (el cambio es aditivo).
- La batería completa: no la lancé; es la del lead sobre `b461c205`.
- La base desechable `ultron_studio_cierre_revlote3` queda creada en el clúster local.
