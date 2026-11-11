# Informe del reviewer · ola M-1 (motor y Studio) y plantillas de Meta (backend)

**Versión 2 (08:40).** Revisado sobre los commits `ab1ae2e4` (motor M-1), `4445abb7` (Studio M-1), `0afaa5a9` (plantillas de Meta) y `f7a79315` (guardas, mandos, despliegue e informes), encima de `9d6b3252`. `origin/main` = `f7a79315`. **Revisión de solo lectura:** no he tocado código, pruebas, fixtures ni mandos; solo escribo este informe.

## Veredicto

| Paquete | Veredicto |
|---|---|
| 1 · Enmienda M-1, MOTOR | **APROBADO CON OBSERVACIONES** |
| 2 · Enmienda M-1, STUDIO | **APROBADO CON OBSERVACIONES** |
| 3 · Plantillas de WhatsApp de Meta desde el CRM (backend) | **APROBADO CON OBSERVACIONES** |
| **Global** | **APROBADO CON OBSERVACIONES** |

**¿Se puede desplegar con todo apagado? SÍ.** Con las llaves por defecto, `decide` y `commit` son idénticos a hoy para el n8n actual (§A). La condición de N-1 (las tres migraciones justo después del `merge`, con reintentos ante 55P03 y comprobación del esquema) la cumple `deploy-m1-backend.sh` (pasos 1–4).

**Bloqueantes: ninguno.**

**Importantes: ninguno abierto en lo que se despliega hoy.** El único (I-1) está corregido en el árbol, pero **sin commit** a las 08:40: hay que confirmarlo y copiarlo a `/root` antes de E2, que es cuando se usan esos mandos.

### Cambios desde la versión 1 (08:20)

- **B-1 (la puerta de diff del H9, `OutreachIsolationTest`) → ya no es bloqueante; pasa a nota (N-9).** Fallaba porque comparaba con `origin/main` antes de la subida. Con la ola en `main` está en verde (lo he vuelto a ejecutar: OK, 15 aserciones). Además, la decisión de que M-1 toca el motor está escrita y aplicada donde importa: la LISTA CERRADA de `deploy-m1-backend.sh:151-164` nombra uno por uno los cinco ficheros modificados y los nuevos de `Prompts/`, y aborta ante cualquier otro.
- **I-1 (`c=$(curl …)` bajo `set -e` en la etapa 3 de los dos mandos de llaves) → corregido en el árbol, SIN COMMIT.** `h5-engine-scope.sh:128` y `motores/motores-prompts-scope.sh:123` llevan ahora `--max-time 20 … || c=000`. He comprobado esa construcción en local contra `127.0.0.1:9`: sigue y deja `c=000`, que cuenta como desvío. `bash -n` limpio en los dos. **Pendiente:** commit y copia a `/root` antes de E2.
- **M-1 (el importador de S1 terminaba con 0 ante fallos) → corregido** (`importar-s1.sh:46-66`, también sin commit): acumula `MAL` y sale con 1.
- **Nuevo, menor, M-6:** `deploy-m1-backend.sh` repite el patrón de I-1 en el paso 9 (sanidad HTTP: `:832` con tubería y `pipefail`; `:841`, `:844`, `:846`, `:850`, `:857`, `:860`, `:862`). Si `curl` falla en ese paso, el guion se corta sin el veredicto final ni los pasos 10–12 (los smokes) y sale con código ≠ 0. **No es peligroso**: no tiene vuelta atrás automática que dispare y el código ya está vivo; solo deja el resultado ambiguo justo cuando importa leerlo. Arreglo: `… || c=000` (y `|| true` en la tubería de `:832`). Dueño: **devops**. Antes de la ejecución de hoy, si da tiempo; si no, ante un corte en el paso 9, relanzar con `--solo-smoke`.
- **La batería:** según el lead, 17 276 pruebas con un único fallo, la línea base `PricingV2SalesAndPaymentsTest` (ajeno a la ola; yo lo reproduje y es determinista). Mi reejecución en PostgreSQL de lo que SQLite omite (migraciones, carrera `/2`, recuentos) está en «Verificado» y es válida para los commits: el contenido es el mismo que leí.
- **`RUNBOOK_M1.md`:** la vuelta atrás (§4) está en el orden correcto (llave de prompts en `off` → volver a Legacy en el Studio si hay `/2` → código), dice bien que las tres migraciones no se deshacen en producción y que el esquema nuevo es inocuo para el código anterior, y su análisis de n8n S1 con el backend anterior coincide con el mío (un único turno en vuelo podría caer con 422 `unexpected_fields`). El orden «CRM antes que backend» queda decidido y justificado en §2.2: un CRM viejo podría no tolerar la PRESENCIA de los campos nuevos. Retiro mi preferencia por «backend primero» (N-2).


## Menores

1. ~~**M-1**~~ **CORREGIDO a las 08:40 (sin commit).** Texto original: **`docs/ultron-studio/ops/motores/n8n-copia-s1/importar-s1.sh:46,51-52,58`** — si el importador falla (`RC≠0`), si el flujo vivo o su webhook cambian, o si el sha256 del selector en la base no es el generado, el mando solo imprime una línea con `✗` y **termina con 0**. Escenario: E1 con una importación fallida → el operador ve «5. exportado…» al final y un código 0. Arreglo: acumular los `✗` como en los otros mandos y salir con 1 (o abortar tras `RC≠0`). Dueño: **devops**. Antes de E1.
2. **M-2 · `app/Services/UltronStudio/StudioMetaTemplates.php:814-821`** — el camino `meta_payload_invalid` (el cliente rechaza la petición antes de la red) pasa el envío a `FAILED` y responde 422, pero **no escribe evento en el libro**: el historial de la versión se queda en `meta_template.submit_requested` sin cierre (los demás finales sí lo tienen, `settleCreate()` :852). Arreglo: registrar `meta_template.submit_failed` con `detail: payload_refused`. Dueño: **backend**. Antes de `ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED`.
3. **M-3 · `app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php:67,83`** — la orden sale **siempre con 0**, también con `meta_failed` y `partial`. El programador no verá nunca un fallo; solo queda el `Log::warning` de `StudioMetaTemplates.php:578`. Si se quiere una alarma (el monitor o IRON GUARD), que `meta_failed` salga con un código distinto. Dueño: **backend**. Antes de `…_SYNC_ENABLED`.
4. **M-4 · huecos de prueba** (dueño: **tester**):
   - la rama que ata la parte ARRASTRADA de la vista previa a la de la transacción (`StudioPublications.php:1642-1647`, `publication_moved`) no tiene un caso determinista: la carrera de `UltronStudioPublicationPromptsRaceTest.php:143` la acepta junto a `canary_in_progress`. He recorrido la lógica a mano y es correcta (misma versión y misma huella, o 409), pero es la pieza que garantiza «nadie firma un paquete arrastrado que no vio». Caso: vista previa con el resto R1; antes de publicar, otra persona revierte/promueve y el resto pasa a R2; publicar con la `previewHash` vieja → hoy cae antes por `preview_stale`; para llegar a esta rama hace falta la pausa forzada entre `facts()` y la transacción (la del rasgo `PublicationRaceSessions`);
   - en el servicio de plantillas, el 422 `meta_payload_invalid` y el `Throwable` del cliente que deja el envío en `UNKNOWN` (`StudioMetaTemplates.php:814-829`) solo están probados en el cliente, no en `submit()`.

## Notas

- **N-1 · La ventana entre el `merge` y las migraciones (devops; la cubre `deploy-m1-backend.sh`, pasos 1–4).** El código nuevo lee el esquema nuevo SIN bandera en cuatro sitios: `StudioTemplateMetaChecks.php:324` (columna `source`), `OutreachPublishedStrategies.php:366` (`meta_components_hash`), `StudioMessageTemplates.php:809` → `StudioMetaTemplates::summaries()` y `detailDto()` → `detailBlock()` (tabla de envíos), y `StudioTemplateMetaChecks::plan()` (tabla de envíos). Mientras no corran `2026_11_15_100001/100002` (o si la 100002 agota sus reintentos por 55P03): la lista y el detalle de plantillas del Studio dan 500, la orden manual de W9 falla y el puente de W9 **rechaza todas las plantillas** (falla cerrado: `catch` → `false`; además el envío de W9 sigue cerrado por la constante en código). Nada se corrompe. Por eso la condición del veredicto. La vuelta atrás del CÓDIGO sin tocar el esquema es segura: el código de hoy no nombra las columnas nuevas y sus inserciones toman `source = 'manual'` por defecto, que cumple `ustmc_source_shape_chk`.
- **N-2 · Orden CRM ↔ backend (superada: ver «Cambios desde la versión 1»).** Desde el backend, los dos órdenes funcionan con todo apagado: todo lo nuevo es aditivo (`effect`/`prompts` en la vista previa, `engine.prompts`, `meta`/`waPreview` en plantillas, `catalog.templates.meta`, la llave nueva de permisos) y un CRM viejo lo ignora. «CRM antes que backend» exige además que el CRM nuevo tolere un 404 en las 4 rutas nuevas y la ausencia de esos campos: **no lo he verificado** (es del front). Lo que sí puedo avalar es «backend primero». El plan de motores dice «backend primero» para n8n (R11, `STUDIO_MOTORES_PLAN.md:323`), que se cumple: E1 (n8n S1) va después de E0.
- **N-3 · Versión de PostgreSQL (a confirmar).** La memoria operativa del proyecto dice PostgreSQL **16** en producción; la puerta local es PG 14. Lo que hacen las migraciones (ALTER multi-subcomando, `pg_get_constraintdef`, `has_any_column_privilege`, `SET LOCAL lock_timeout`) se comporta igual en 16; la única dependencia de formato es `str_contains($definition, "'ultron-engine-config/1'")` en `2026_11_16_100001…php:115-119` y `:129`, que también casa con la forma `= ANY (ARRAY['…'::character varying …])` de PG 16. Confirmable sin riesgo con `ironbody-migrate --pretend` en el servidor.
- **N-4 · Contrato M-1, dos desviaciones no declaradas (solo texto):** (a) M1-6 dice que el Studio importa del motor «solo `UltronPromptBase` y `UltronPromptCompiler`»; `StudioPromptBundle.php:7` importa además `UltronPromptException` (pura), y la guarda `UltronStudioEngineReadOnlyTest` la admite. (b) M1-5 enumera siete estados del sello; el código tiene un octavo, `stamp_error` (`UltronPromptStamp.php:60`, escrito por `UltronCommitService.php:2472`), que el README de motores sí documenta. Arreglo: dos frases en la enmienda. Dueño: lead / documentation-specialist.
- **N-5 · Fixture congelado tocado (declarado).** `publication-cases.json` cambió dos casos de `/2` a `/3` (scope/34 y scope/35) porque `/2` ya es válido. Es correcto y está en `informe-tester-guardas.md` §1.a. El informe de B-S dice «sin tocarlo» (`informe-bs.md:13`), lo que ya no es literal. Si el CRM tiene una copia del fixture con `/2` como desconocido, que la regenere quien la tenga.
- **N-6 · Calidad (observación, no bloquea):** `UltronCommitService.php` 4 802 líneas, `StudioPublications.php` 2 089, `StudioMessageTemplates.php` 1 482, `StudioMetaTemplates.php` 1 456. Si se parte algo, lo natural es sacar la sincronización y la observación de `StudioMetaTemplates` (lo ofrece su autor) y los validadores de contenido de Meta de `StudioMessageTemplates`. Duplicaciones deliberadas por la frontera A9, atadas por prueba: `StudioCanaryMeasures::promptTurns()` (:107) ≈ `UltronPromptTrace::turnos()` (:102), y `StudioPromptBundle::offer()` ≈ `UltronPromptOffer::offer()`.
- **N-7 · Lo que M1-12 implica y conviene que el propietario sepa:** revertir la versión en canario cuando el resto es `/2` hace que la canaria pase a los prompts de la parte del RESTO, compilada al publicar y enseñada en la vista previa solo como huella (`prompts.carried`), no con su tabla de cambios. Es lo que dicen M1-12 y la ayuda de `ultron.rollback`; no es un defecto.
- **N-8 · M1-11 en la práctica:** con la llave de prompts encendida, si la versión del RESTO tiene un `persona.maxChars` fuera de paso (o no compila), TODA publicación nueva da 409 `prompts_compile_failed` hasta que se revierta o se vuelva a Legacy (falla cerrado, con salida). Hoy no hay filas en la cadena de producción.
- **N-9 · (antes B-1) La guarda de diff del H9 y M-1.** `OutreachIsolationTest` compara con `origin/main`, así que está ciega a todo lo que ya llegó a `main`: es su diseño desde el H8, no algo de esta ola. La excepción de M-1 a las protecciones 5–8 queda escrita en la lista cerrada del despliegue (`deploy-m1-backend.sh:151-164`). Sugerencia, sin urgencia: una línea en la Enmienda M-1 que diga que esas protecciones ceden exactamente en esos ficheros.

## Verificado (qué ejecuté y qué salió)

- **Batería completa del lead** (no la lancé yo; leí su salida, `storage/test-results/suite-20261111T122126Z.txt`, 07:21–07:52, SQLite): **17 276 pruebas, 451 176 aserciones, 2 fallos, 630 omitidas.**
  - Fallo 2 = la puerta de diff del H9, por comparar con `origin/main` antes de la subida; tras la subida, verde (N-9).
  - Fallo 1 = `Tests\Feature\Billing\PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote` (422 «El plan Mensual cuesta 80.000 y el cobro es de 95.200…»). **Ajeno a la ola**: no toca ningún fichero de facturación ni de pagos. Lo reproduje una vez (`--filter`, SQLite): falla igual, es determinista. A confirmar si ya fallaba en `9d6b3252` (no lo he comprobado: haría falta otro árbol).
  - Todas las pruebas nuevas de la ola pasaron en SQLite dentro de esa batería (de la junit: `UltronDecidePromptsTest` 138, `UltronCommitPromptsTest` 35, `UltronPromptCompileCasesTest` 643, `UltronPublishedConfigV2Test` 31, `StudioPromptDeclarationTest` 62, `UltronStudioPublicationPromptsTest` 11, `MetaTemplate*Test` 55, `UltronEngineIsolationTest` 11…), sin omitidas salvo las que son solo de PostgreSQL.
- **Lo que en SQLite se omite, lo corrí yo en el PostgreSQL 14 desechable** (`pgunit.sh`, base propia `ultron_studio_rev_ola`, creada y borrada; un fichero cada vez, con `nice`):
  - `UltronStudioEngineConfigV2SchemaTest` (migración `/2`): 7/7 de PG (la octava es la de SQLite) — CHECK `/1|/2`, `/3` rechazado, `/2` sigue de solo inserción, `down()` se niega con una `/2` y no toca nada, ida y vuelta exacta al H5, se niega a ampliar dos veces, no espera sin límite un cerrojo;
  - `UltronStudioTemplateMetaChecksMetaApiSchemaTest` (2026_11_15_100002): 12/12 — la fila manual de hoy entra igual, REVOKE de W9 comprobado de nuevo, `down()` se niega con filas que W9 no admite y respeta las de W9;
  - `UltronStudioMetaTemplateSubmissionsSchemaTest` (2026_11_15_100001): 16/16 — un envío vivo por versión y por nombre, FAILED definitivo, `down()` se niega con filas o citado;
  - `UltronStudioPublicationPromptsRaceTest`: 3/3 (seis sesiones a la vez: una entrada `/2` y cinco reintentos; seis versiones: solo firma la ganadora; publicar y volver a Legacy a la vez: cadena coherente y Legacy en `/1`);
  - y las guardas de recuento que la ola enmendó: `TemplateMetaChecksSchemaTest` 5 (1 omitida, la de SQLite), `UltronStudioPublicationSchemaTest` 13/13, `UltronStudioHardeningH7Test` 25/25.
- **Demostración local de I-1** (bash, contra `127.0.0.1:9`, sin red real).
- **Bytes de la base de prompts**: los cinco `.txt` dan los sha256 y tamaños fijados en `UltronPromptBase.php:56-71`; sin `\r`; `git check-attr` = `text: unset` (no se normalizan); no están ignorados por git (viajan con el `pull`).
- **Respaldo y flujo S1 de n8n**: sin portadores en claro; las 8 autenticaciones son referencias de credenciales de n8n.
- **No he ejecutado**: los mandos de ops ni el importador en ningún servidor; el comprobador `verificar-flujo.cjs`; el E2E; el CRM. La revisión de seguridad no la repito (`informe-api-security-ola.md`).

## Detalle

### A · Regresión de lo que hoy funciona (llaves por defecto)

**Comprobado leyendo el código y con las pruebas de la batería.**

- **`decide`**: la negociación solo se activa si `prompt_contract` es EXACTAMENTE `ultron-prompts/1` (`UltronController.php:142`); el n8n de hoy no lo manda. Sin ella, `UltronDecideService.php:402` no llama a la oferta (cero consultas), el billete firmado no gana `sp` (`:416`, `+ []`) y la respuesta no gana `studio_prompts` (`:610`). `decide` no rechaza campos desconocidos (`:85-88`), así que un n8n S1 contra un backend viejo tampoco rompe. Prueba: `UltronDecidePromptsTest::test_sin_negociar_la_respuesta_y_el_token_son_identicos_con_las_llaves_apagadas_y_encendidas`.
- **`commit`**: el bloque `prompts` es opcional (`UltronController.php:296-301`); antes era `unexpected_fields` y lo sigue siendo cualquier clave de más dentro (`:435`). El sello se calcula después de verificar el billete (`UltronCommitService.php:376`), nunca lanza (`:2472`, `Throwable` → `stamp_error`) y sin `sp` ni bloque es `null`, que el filtro de nulos de `persist()` quita (`:2541`, `:2555`): **la fila de `marketing_ai_actions` queda byte a byte como hoy**. `persist()` tiene el argumento nuevo sin valor por defecto; lo reciben las cinco llamadas: ejecutado (`:1253`), crítico fallido y rendición vía `safeFallback()` (`:1786`, `:1838`, `:2059`), hora de la visita (`:2282`) y `blocked()` (`:3931`, `null` a propósito: antes del billete). No hay otro `MarketingAiAction::create` en el servicio. Prueba: `UltronCommitPromptsTest::test_sin_bloque_ni_negociacion_la_respuesta_es_la_de_hoy_y_ninguna_fila_lleva_sello` y `test_el_sello_va_en_toda_fila_posterior_al_token_y_en_ninguna_anterior`.
- **El tamaño del billete** con `sp` en modo `studio` ronda los 750 caracteres; el tope de validación es 2 048 (`UltronController.php:166`).
- **`UltronPublishedConfig::forConversation()`** (lo que usa el commit hoy) pasa a `lookup()` con la misma escalera y los mismos motivos; para `/1` el resultado es idéntico (217 casos de `UltronPublishedConfigTest` en verde, con el fixture congelado). Lo único que cambia es que una cabeza `/2` se lee (`applied`) en vez de `contract_unknown`, que es lo pedido.
- **WhatsApp actual, OTP de Twilio, laboratorio hablado, H6**: ningún fichero de `app/Services/Meta` (fuera de `Templates/`, nuevo), `Twilio`, `Voice`, `Telephony`, del webhook, del outbox ni de `MarketingMessageDispatcher` cambia en la ola (`git status`).
- **W9 · `metaApproved()`** (`OutreachPublishedStrategies.php:362-405`): una fila manual (sin huella de componentes) vale solo si la plantilla no tiene encabezado, pie ni botones. **Ninguna plantilla guardada hoy puede tenerlos**: el validador de `9d6b3252` no admitía ninguna clave opcional (`self::keys($raw, [...], [], …)`, línea 500 de esa versión). Así que toda plantilla registrada a mano que hoy vale sigue valiendo. Prueba: `MetaTemplateW9AmendmentTest::test_una_fila_manual_de_una_plantilla_de_solo_cuerpo_se_acepta_como_hasta_hoy`. Las reglas nuevas del cuerpo (`leading_trailing_spaces`, `meta_dangling_parameter`) solo se aplican al crear: `content()` no se vuelve a llamar sobre plantillas guardadas.

### B · Corrección de lo nuevo

- **Compilador** (`UltronPromptCompiler.php`): `insert_after_line` y `replace` hacen lo que dicen; ningún texto de parche contiene un ancla (repasado uno a uno: los textos de los Critic dicen `naturalness;`/`naturalness.` y `progressive_disclosure` sin dos puntos; `mas de ~{N}` nunca es `~900` porque N ≤ 480). La base se verifica por sha256 en cada proceso y un fallo es Legacy.
- **Oferta** (`UltronPromptOffer.php:118-190`): los ocho pasos de §B.5 en su orden; con cualquiera de las dos llaves apagada o fuera de la canaria, ni una consulta; nunca a una conversación que no sea la canaria (paso 3); el motor recompila desde lo firmado y solo entrega si la huella es la firmada. Nunca lanza.
- **Firma y huella de `/2`**: `previewHash` incluye `{base, bundleHash, carried{bundleHash, version}, compiler}` y la lista `prompt` (`StudioPublicationDeclaration.php:399-405`); los gobernados salen del contenido, que ya ata `contentHash`. Lo que se firma (`StudioPublications.php:1632`) es el paquete calculado en las mismas puertas cuya huella se comparó. **Sí: ata exactamente lo que vio quien publicó**, incluida la parte arrastrada (`:1642-1647`). Cambiar la llave entre la vista previa y publicar da `preview_stale` (probado).
- **Promover y revertir** nunca compilan: `/2` solo si todas las partes ya lo eran (`:1662-1680`); Legacy siempre en `/1` (`:537`, sin contrato → `/1`).
- **Plantillas**: el envío nace `SENDING` y se confirma antes de la red; el POST va fuera de transacción con un cerrojo por versión, y los índices únicos parciales (un envío vivo por versión y por nombre+idioma) impiden el doble envío aunque falle la caché. Un fallo de transporte en una creación es `UNKNOWN` y no se reintenta; solo los errores de cURL 5/6/7 son «no salió». Los códigos de Meta se sanean con los mismos topes que los CHECK (`MetaTemplate.php:33-41`, `enum()` `[A-Za-z_]`, a mayúsculas). Tiempos: la aplicación está en UTC; las comparaciones de 120 s y 900 s usan Carbon 3 con signo.
- **`catch` que tragan**: todos los que vi están comentados y dejan rastro (`ChannelLog`/`Log` con la clase): oferta y sello (no pueden dejar un turno mudo), `engineBlock()` (`GET status` no puede caerse), `insertCheck()` (el UNIQUE de una pasada gemela). No encontré ninguno silencioso.
- **Migraciones**: `up()` seguro con datos (añadir columnas con DEFAULT y ampliar varchar no reescribe en PG ≥ 11; los CHECK nuevos se cumplen por construcción), `lock_timeout` de 5 s si la sesión no trae uno, y el cerrojo ACCESS EXCLUSIVE se toma antes de contar; `down()` se niega con filas que el esquema anterior no admite y nunca borra. Probado en PG (arriba).

### C · Contratos

Lo implementado es lo del plan §B.3–B.8 y §D y las decisiones M1-10 a M1-14 (M1-11: `maxChars` fuera de paso → 409 al publicar con la llave encendida; M1-12: arrastre compilado, promover y revertir sin compilar). Desviaciones no declaradas: solo las dos de texto de N-4.

### D · Pruebas

- **Nada relajado.** Las enmiendas declaradas (`informe-tester-guardas.md`) son correctas y algunas MÁS estrictas que antes: la del monitor exige ahora la condición entera del `if` (`UltronEngineIsolationTest.php:283`). Los recuentos (disparadores, CHECK, parciales, errores, permisos) suben con su desglose y siguen siendo igualdades exactas; `/2` → `/3` como «desconocido» es necesario y está explicado; las aserciones sobre la forma de la vista previa añaden `effect`/`prompts` con su valor.
- **La puerta de diff del H9:** ver N-9 (resuelto).
- **Huecos:** M-4.

### E · Operación

- I-1 en los dos mandos de llaves (corregido, sin commit). Lo demás de su lógica está bien: la trampa se arma antes de tocar nada, las etapas 1–2 restauran el fichero con su dueño y su modo y regeneran la caché, y desde las 08:05 el de prompts además relee la configuración tras restaurar (MB-3), usa cerrojos `flock` compartidos (MB-4), deja registro (MB-5) y comprueba antes de encender que el CHECK ya admite `/2` (MB-8, `:58-60`, con la misma base `ironbody` que los demás mandos). `pipefail` con `supervisorctl status` (que sale con 3 si algo no corre) está cubierto por los `|| true`.
- M-1 en el importador de S1 (corregido, sin commit); M-6 en el paso 9 del despliegue.
- Orden: N-1 (migraciones en el mismo despliegue) y N-2 (CRM ↔ backend).

### F · Calidad

N-6. Ningún comentario que mienta en lo que leí; los de cabecera de `UltronPublishedConfig`, `StudioPublications`, `config/marketing.php` y del compilador describen lo que hace el código.

## Adenda del 11-11 (08:55): B-1 cerrado — SÍ

**B-1 está cerrado. No hace falta enmendar la prueba.** Lo he comprobado yo:

- **Guardas, sin tocarlas** (SQLite, un fichero cada vez), sobre `origin/main` = `f7a79315`:
  - `OutreachIsolationTest`: OK, 5 pruebas y 2 584 aserciones;
  - `UltronEngineIsolationTest`: OK, 11 y 1 609;
  - `OutreachStaticGuardsTest`: OK, 6 y 9 909.
  - En total, 22 pruebas y 14 102 aserciones, las cifras del lead.
- **La decisión está escrita donde tiene que estar:** la fila M1-15 del contrato del H5 dice que las protecciones 5–8 del H9 ceden solo para M-1 y solo en esos ficheros. La puerta cerrada del despliegue lo aplica (`deploy-m1-backend.sh:151-164`) y aborta ante cualquier otro fichero.
- **Por qué no pido la lista cerrada dentro de la prueba:** compara con `origin/main` y, tras la subida, ya no hay diff que enumerar. Una lista ahí sería código muerto. Que la guarda quede ciega a lo que ya está en `main` es su diseño desde el H8, no algo de esta ola.

**Lo demás:**

- **N-3 cerrada:** producción es PostgreSQL 14.24, el mismo motor que la puerta local.
- **N-4 cerrada:** fila M1-16 del contrato.
- **M-4a cerrado:** `UltronStudioPublicationV2RaceTest` en el PostgreSQL desechable, con una base propia que ya borré: OK, 2 pruebas y 75 aserciones.
  - El caso principal pausa la publicación justo antes de la primera sentencia sobre la cadena. Mientras tanto, otra persona vuelve a Legacy. Resultado: 409 `publication_moved`, nada escrito y la cadena verificada.
  - Ese caso pasa exactamente por la rama que yo señalaba (`StudioPublications.php:1644-1646`, la vista con parte arrastrada y la transacción sin ella).
  - El control (la misma pausa sin mover la cabeza) publica en `/2` con el arrastre que enseñó la vista previa.
- **M-2, M-3 y M-4b:** de acuerdo con dejarlos para antes de encender las banderas de plantillas.
- **M-6** (el paso 9 del despliegue sin `|| c=000` en sus `curl`): sigue abierto y es menor. Si se corta en ese paso, relanzar con `--solo-smoke`.

**Una cosa pendiente, de proceso:** a las 08:55, estos ficheros están corregidos en el árbol pero **sin commit**:
- la fila M1-15 y la M1-16 del contrato;
- el arreglo de I-1 en `h5-engine-scope.sh` y en `motores/motores-prompts-scope.sh`;
- el de `importar-s1.sh`.

No afectan al despliegue apagado de hoy: todos están en `backend/docs/`, que es zona libre de la puerta del despliegue. Pero:
- la decisión de M1-15 tiene que quedar en el historial;
- los dos mandos tienen que llegar a `/root` desde el commit desplegado antes de E2.

**Veredicto final:** **APROBADO CON OBSERVACIONES** (los tres paquetes y global). **Se puede desplegar con todo apagado: SÍ.**
