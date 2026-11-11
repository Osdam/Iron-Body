# Informe del paquete del motor (B-M) — FINAL (pasos 1a, 1b y 1c)

**Autor:** `backend` (B-M). **Fecha:** 11-11-2026. **Árbol:** `feat/ultron-studio-h9` sobre `da80942b`, en local. **Sin commit, sin push, sin producción, sin red real; n8n sin tocar.**
**Contrato:** `plans/STUDIO_MOTORES_PLAN.md` §B.3–B.8 y §D, congelado por la Enmienda M-1 (`ULTRON_STUDIO_H5_CONTRACT.md`). API del compilador para B-S: `informes-motores/api-compilador.md` (publicada al cerrar 1a).

> **Estado:** 1a, 1b y 1c terminados y verificados en local (SQLite y PostgreSQL 14 desechable).
> **Primer punto desplegable (tras 1b):** con `MARKETING_ULTRON_STUDIO_PROMPTS` sin definir (`off`) y sin que n8n mande `prompt_contract`, `decide` y `commit` se comportan exactamente como hoy (E5 y E7: instantáneas de respuesta, token y fila).
> **Antes de integrar, el lead aplica dos diffs** de pruebas que no son mías (§5). Sin ellos hay **4 rojos esperados** (guardas que usaban `/2` como «contrato desconocido») + 1 en `UltronEngineIsolationTest` (la frontera A9 enmendada). No son regresiones y no he relajado nada.

## 1. Qué cambió y por qué (por fichero)

### Nuevos (todos en mis rutas exclusivas)

| Fichero | Qué es |
|---|---|
| `resources/ultron-prompts/n8n-29de5119/{strategist,composer,critic,composer_retry,critic_retry}.txt` | La base: bytes exactos de `nuevo4.json → <nodo>.system` (= `values[0].content` del flujo vivo 29de5119; comparado hoy en Python y en PHP), sin salto final. |
| `resources/ultron-prompts/.gitattributes`, `.editorconfig` | `*.txt -text`; sin salto final ni recorte de espacios: nadie «arregla» la base sin querer (rompería su sha256). |
| `app/Services/Marketing/Ultron/Prompts/UltronPromptBase.php` | Pura. Carga y verifica la base (5 sha256 fijados), `bundleHash()` (fórmula §B.3), `LEGACY_BUNDLE_HASH`. |
| `…/Prompts/UltronPromptCompiler.php` | Pura y determinista. Parches anclados de §B.3 con los textos aprobados tal cual (M1-3); `VERSION = 1`; topes 1 200 / 40 960 / 163 840 bytes. `compile(línea base) === base` byte a byte. |
| `…/Prompts/UltronPromptException.php` | Excepción con código cerrado (`base_unreadable`, `base_corrupt`, `invalid_governed`, `anchor`, `too_large`, `roles`). |
| `…/Prompts/UltronPromptStamp.php` | Pura. Tabla de estados de §B.6 y forma de `metadata.studio_prompts`. |
| `…/Prompts/UltronPromptOffer.php` | La oferta de `decide` (§B.5, 8 pasos). Nunca lanza; cero consultas con cualquiera de las dos llaves apagada o fuera de la canaria; una con todo encendido. |
| `…/Prompts/UltronPromptsN8nFlow.php` | Pura. Construye S1 desde el respaldo 29de5119 y la base: selector «Elegir Prompts» (base embebida, SHA-256 en JS puro), `Preparar Commit` con el bloque `prompts`, operaciones del MCP, flujo esperado, huellas y fixtures. Conserva los `{}` del export. |
| `app/Console/Commands/Marketing/UltronPromptsN8n.php` | `php artisan ultron:prompts-n8n [--out=n8n/flujos/ultron-studio-s1] [--from=n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json]` (rutas relativas a la raíz del repositorio). Determinista. |
| `app/Console/Commands/Marketing/UltronPromptTrace.php` | `php artisan ultron:prompt-trace [--last=20] [--json]`. Solo lectura. La forma de §D (`status.engine.prompts`) + `studioConfig` y `canaryConfigured`. |
| `n8n/flujos/ultron-studio-s1/` (raíz del repo) | La salida del generador: `elegir-prompts.js`, `preparar-commit.js`, `operaciones-mcp.json`, `flujo-s1.json` (solo para el comprobador, **nunca se importa**), `huellas.json`, `fixtures/decide-{studio,legacy}.json`, `LEEME.md`. |
| `docs/ultron-studio/ops/motores/verificar-flujo.cjs` | Comprobador offline de §B.8 (Node, sin red; 111 comprobaciones). |
| `docs/ultron-studio/ops/motores/motores-prompts-scope.sh` | Mando de la llave con el patrón de `h5-engine-scope.sh` (copia 600, `config:cache` como www-data con umask 077, reinicio, 13 **o 14** workers según esté `ironbody-voice`, status 401, traza, publication-check; sin imprimir valores). |
| `docs/ultron-studio/ops/motores/README.md` | Piezas, comprobador, etapas E0–E5 con lo que debe decir la traza, vuelta atrás y cuidados. |
| Pruebas | `tests/Unit/Marketing/Ultron/Prompts/{UltronPromptBaseTest, UltronPromptCompilerTest, UltronPromptStampTest, UltronPublishedConfigV2Test}.php` + rasgo `PromptPublications.php`; `tests/Feature/Marketing/{UltronDecidePromptsTest, UltronCommitPromptsTest, UltronPromptsN8nCommandTest, UltronPromptsTraceCommandTest}.php`. |

### Modificados (`git diff --stat`: 5 ficheros, +295 −45)

| Fichero | Cambio |
|---|---|
| `app/Services/Marketing/Ultron/UltronPublishedConfig.php` | `CONTRACT_V2` y `CONTRACTS` (`CONTRACT` sigue siendo `/1`: lo ata `StudioPublicationDeclarationTest`). El lector acepta `/1` y `/2`: en `/2` toda parte no nula lleva además `governed` y `prompts` con claves y tipos exactos, o `malformed`. Nuevo `partFor()` (misma lectura, verificación y resolución que `forConversation()`, que devuelve lo mismo de siempre). `describe()` cuenta las partes de una cabeza `/2`. `scope()` pasa a pública (la usa la oferta). El aviso de log de `partFor()` lleva `via: decide`; el del commit no cambia. **La firma no cambia** (§2.4). |
| `app/Services/Marketing/Ultron/UltronDecideService.php` | `decide(…, bool $negotiatePrompts = false)`; solo si se negoció: oferta, `studio_prompts` en la respuesta (antes de `superseded`) y `sp` en la carga firmada `x` del token. |
| `app/Http/Controllers/Api/Internal/UltronController.php` | `decide`: `prompt_contract` por `$request->input()`, nunca 422. `commit`: reglas del bloque `prompts` y `'prompts'` en la lista de bloques anidados de `unexpectedKeys()`. |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | `promptStamp()` justo tras verificar el token (nunca lanza: `stamp_error`); `$studioPrompts` como argumento obligatorio de `persist()` y de los caminos posteriores al token (`contestarLaHoraDeLaVisita`, `handleRecovery`, `handleCriticFailure`, `safeFallback`); `blocked()` (antes del token) pasa `null`; aviso de log sin textos en `mismatch`, `legacy_drift` y `stamp_error`. Nada del commit se ramifica por el sello. |
| `config/marketing.php` | `marketing.ultron.studio_prompts` ← `MARKETING_ULTRON_STUDIO_PROMPTS`: `canary` (sin distinguir mayúsculas ni espacios) o `off`; `all` y todo lo demás, `off`. |

## 2. Contratos exactos

### 2.1 `decide`

Petición: `{"conversation_id": 1, "message_id": 2, "prompt_contract": "ultron-prompts/1"}`. Cualquier otro valor o tipo = no negociado, sin 422 (probado con `null`, `"ultron-prompts/2"`, mayúsculas, `""`, `1`, `true`, listas y objetos). Nota: el middleware `TrimStrings` de Laravel quita espacios alrededor, así que `" ultron-prompts/1 "` sí negocia.

Respuesta: la de hoy y, **solo si se negoció**, `studio_prompts` justo antes de `superseded`:

```json
{"ok":true, "…":"lo de siempre", "decide_token":"…", "expires_at":"…",
 "studio_prompts":{"contract":"ultron-prompts/1","mode":"studio","reason":null,
   "base":"n8n-29de5119","compiler":1,"bundle_hash":"702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c",
   "version":"b0000000-0000-4000-8000-00000000000b","content_hash":"<64 hex>","entry":"<uuid>","seq":7,
   "roles":{"strategist":"…","composer":"…","critic":"…","composer_retry":"…","critic_retry":"…"}},
 "superseded":false}
```

Legacy: `{"contract":"ultron-prompts/1","mode":"legacy","reason":"no_publication","base":"n8n-29de5119","compiler":1,"bundle_hash":null,"version":null,"content_hash":null,"entry":null,"seq":null,"roles":null}`. Motivos: `prompts_off`, `studio_config_off`, `out_of_scope`, `no_publication`, `part_legacy`, `declaration_v1`, `publication_<contract_unknown|hash_mismatch|signature_invalid|malformed|table_missing|read_failed>`, `base_changed`, `compiler_changed`, `bundle_mismatch`, `too_large`, `error`.

Token: `x.sp = {"m":"studio","r":null,"h":"<hex>","v":"<uuid>","c":"<hex>","e":"<uuid>","s":7}` solo si se negoció. **Medido:** 428 caracteres (Legacy) y 668 (`studio`), tope 2 048; la prueba exige < 1 500 en los 126 casos de la matriz. Respuesta de `decide` en `studio`: ~129 KB (sin negociar, ~12,6 KB).

### 2.2 `commit`

```json
"prompts": {"mode":"studio","bundle_hash":"<64 hex observado>","reason":null,"flow_rev":"S1","execution_id":"12345"}
```

Reglas: `prompts` nullable array; `mode` `required_with:prompts` ∈ {studio, legacy}; `bundle_hash` nullable `^[0-9a-f]{64}$`; `reason` nullable ≤ 64 `^[A-Za-z0-9_.:-]+$`; `flow_rev` `required_with:prompts` ≤ 32, mismo patrón; `execution_id` nullable ≤ 64, mismo patrón. Clave de más dentro → 422 `unexpected_fields` con `prompts.<clave>` (y no se escribe fila). `prompts: null` o `{}` = sin bloque. La respuesta del commit no cambia con el bloque.

`marketing_ai_actions.metadata.studio_prompts` (solo si hay `sp` o bloque; si no, la fila es la de hoy):

```json
{"v":1,"state":"applied",
 "offered":{"mode":"studio","reason":null,"version":"<uuid>","contentHash":"<hex>","entry":"<uuid>","seq":7,"bundleHash":"<hex>"},
 "observed":{"mode":"studio","bundleHash":"<hex>","reason":null,"flowRev":"S1","execution":"12345"}}
```

Estados: `applied`, `mismatch`, `engine_fallback`, `legacy`, `legacy_drift`, `not_reported`, `unverified`; y `stamp_error` (`offered`/`observed` null) si calcularlo lanzara. Va en la fila ejecutada, la del crítico fallido, la rendición y la de la hora de la visita; no en las bloqueadas antes del token. `metadata.studio` (H5) no cambia de forma.

### 2.3 `ultron-engine-config/2` (lo escribe B-S)

Toda parte no nula = las 6 claves de `/1` + `"governed":{"persona.emojis":bool,"persona.maxChars":int,"persona.tone":string,"strategy.goal":string}` + `"prompts":{"base":"n8n-29de5119","bundleHash":"<64 hex>","compiler":1}`. Legacy siempre `/1`.

### 2.4 Decisiones mías que el tester y B-S deben conocer

- **Firma:** «firma … los de `/1`» (§B.4) literal: `signatureFor()` no cambia y el texto firmado lleva `"ultron-engine-config/1\n"` **también en las filas `/2`** (el contrato va dentro del JSON firmado y debe ser igual a la columna). Una fila `/2` firmada con prefijo `/2` sale `signature_invalid` (probado).
- **Inserciones tras la misma línea:** se aplican en cadena; quedan en orden inverso al de aplicación (Critic con tono y sin emojis: `naturalness:` → `EMOJIS:` → `REGISTRO PUBLICADO:`). Fijado en prueba.
- **Selector (diferencias con el esqueleto de §B.7, todas hacia «más robusto»):** UTF-8 a mano en vez de `unescape(encodeURIComponent())`; `roles` como lista → `shape`; el motivo de una oferta legacy se sanea a `[A-Za-z0-9_.:-]` (para que nunca provoque un 422 en el commit); la lectura de `$input` también va en `try`.

## 3. Huellas

| | sha256 |
|---|---|
| strategist (29 243 B) | `c84387582f581640493545996c7c61ab9a46d11829d740f1319c34b5c50a6917` |
| composer (27 132 B) | `78437378e4a3b7bfba0b407d73b9e165b3a28e1e0555371bf771d0e47780e866` |
| critic (15 583 B) | `180aa6c37257448f24a15e0ba7dafab918693a60cecf19e6b8141471e200c896` |
| composer_retry (25 023 B) | `92d97a04316cf5a6fecb4a70c3f0e573c49d54f881bf6f2733b96524cb279ccd` |
| critic_retry (15 235 B) | `a03940c2f4d0d5f21f3ee49007162b1057106bb619aa162292a22673009315ed` |
| **`LEGACY_BUNDLE_HASH`** | **`3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902`** (también calculada con Python y con el crypto de Node) |
| V2 de aceptación (`profesional` + `200`) | `702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c` (113 672 B, 10 parches) |
| Selector S1 (`jsCode`, 118 831 B) | `456e4d495115cf4b126277773b6f4998530a78635f850eac6b8b7ec4e7350d0f` |
| `Preparar Commit` S1 | `fb35df55dbfe6825482baa7f30b4f41050ede24d6eecfc92f23585a9f8fff7ce` |

## 4. Cómo generar y verificar el flujo de n8n

```bash
cd backend && php artisan ultron:prompts-n8n          # → ../n8n/flujos/ultron-studio-s1/
cd .. && node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json>   # 0 = OK
```

- Sobre `n8n/flujos/ultron-studio-s1/flujo-s1.json`: **OK (111 comprobaciones)**. Sobre el respaldo 29de5119: **FALLO** (salida 1), como debe.
- Las operaciones (`operaciones-mcp.json`): 1 `addNode`, 1 `removeConnection`, 2 `addConnection`, 5 `setNodeParameter` de `/responses` (array entero, por la trampa conocida del MCP; `values[1]` intacto), 1 de `/jsonBody`, 1 de `/jsCode`. **Los nombres de campo de cada operación no los he podido contrastar con el esquema del MCP** (no tengo la herramienta y n8n no se toca): el contenido es el verificado; el lead ajusta los nombres si hiciera falta y verifica siempre con export + comprobador + `versionId`.
- Pasos de aplicación, vuelta atrás (solo por el historial de versiones) y lo que debe decir `ultron:prompt-trace` en cada etapa: `n8n/flujos/ultron-studio-s1/LEEME.md` y `docs/ultron-studio/ops/motores/README.md`.
- **El generador y E10 leen el respaldo** `n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json`, hoy sin seguimiento en git: hay que incluirlo en el mismo commit (y `n8n/flujos/ultron-studio-s1/`).

## 5. Guardas que caen por el cambio legítimo (NO relajadas): diffs para el lead

**5.1 `/2` era el «contrato desconocido» de tres guardas** (4 rojos). Enmienda mínima: usar `/3`. Diff listo: `informes-motores/enmienda-guardas-contrato-v2.diff`.

- `tests/fixtures/ultron-studio/publication-cases.json:6195`, `:6204`, `:6235` (casos `scope/34` y `scope/35`: solo `engineContract` y el nombre; huellas y firmas intactas, van sobre el JSON);
- `tests/Unit/Marketing/UltronPublishedConfigTest.php:439`;
- `tests/Feature/Marketing/UltronCommitStudioStampTest.php:443`.

No sirve cambiar lo esperado a `malformed`: `StudioPublicationParityTest::test_el_studio_lee_las_cabezas_que_el_lector_acepta_y_no_las_que_rechaza_por_su_forma` exige que el Studio rechace toda cabeza `malformed`, y esas las lee. Verificado en copias: `UltronPublishedConfigTest` 217/217 (SQLite); con `UltronCommitStudioStampTest`, 247/247 en PostgreSQL; `StudioPublicationParityTest` 106/106 con el fixture enmendado.

**5.2 `tests/Feature/UltronStudio/UltronEngineIsolationTest.php`** (lo edita otro agente; no lo toco). Cae `test_la_unica_lectura_del_motor_es_de_solo_lectura_y_solo_la_usa_el_commit` porque `UltronPromptOffer` nombra al lector (frontera A9 enmendada, M1-6). Diff listo: `informes-motores/enmienda-UltronEngineIsolationTest.diff` (añade `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` a `ENGINE_READER_NAMERS`, en el orden del rastreo, y la prueba `test_la_llave_de_los_prompts_vale_off_por_defecto`). Hecho contra el fichero tal como está ahora (comprobado byte a byte antes de cerrar). Verificado en copia: 10/10, también con la llave `canary` en el entorno del proceso.

**5.3 No es mío, pero lo veo:** `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` cae hoy por `routes/console.php:566` (`'ultron_studio.meta_templates.sync_enabled'`), que ha añadido otro agente (plantillas de Meta) después de mi arranque: la guarda solo admite la bandera del monitor en el programador. Lo tiene que resolver su dueño.

## 6. Variables de entorno (para `.env.example`; las añade el lead)

```
# Prompts de ULTRON gobernados por la versión publicada del Studio (Enmienda M-1).
# off (defecto) | canary. Solo la conversación de MARKETING_ULTRON_CANARY_CONVERSATION_ID,
# y solo con MARKETING_ULTRON_STUDIO_CONFIG distinta de off. Cualquier otro valor es off.
MARKETING_ULTRON_STUDIO_PROMPTS=off
```

En producción se cambia solo con `docs/ultron-studio/ops/motores/motores-prompts-scope.sh`. Ninguna migración es mía (la de `/2` es del especialista de datos y ya está en el árbol: `2026_11_16_100001_…`).

## 7. Verificación ejecutada (comando → resultado)

Todo con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, un proceso cada vez.

| Qué | Resultado |
|---|---|
| Suites `Ultron*` del motor + las mías (`tests/Feature/Marketing/Ultron*Test.php tests/Unit/Marketing/Ultron*Test.php tests/Unit/Marketing/Ultron/Prompts/`), llaves por defecto (`off`) | **961 pruebas, 10 560 aserciones, 4 rojos = los de §5.1**, ningún otro |
| Lo mismo con `MARKETING_ULTRON_STUDIO_CONFIG=canary MARKETING_ULTRON_STUDIO_PROMPTS=canary` (comprobado que llegan a `config()`) | 961, los mismos 4 |
| PostgreSQL 14 (`UNIT_DB=ultron_studio_bm bash docs/ultron-studio/ops/pgunit.sh …`): mis Feature + `Unit/Marketing/Ultron/Prompts` | **308 OK** |
| PostgreSQL: `UltronCommitStudioStampTest`, `UltronPublishedConfigTest`, `UltronDecideTest` | 272, los mismos 4 de §5.1 |
| Copias con las enmiendas de §5 (SQLite y PG) | todas en verde (ver §5) |
| `UltronEngineIsolationTest` | 9 pruebas: 1 rojo mío esperado (§5.2) + 1 ajeno (§5.3) |
| `OutreachStaticGuardsTest` + `TelephonyIsolationTest` + `UltronStudioEngineReadingsTest` + `UltronStudioEngineReadOnlyTest` | OK |
| Comprobador sobre el flujo generado / sobre el respaldo | OK (111) / FALLO, salida 1 |
| `php artisan ultron:prompt-trace` en local (canario sin configurar, sin consultas) y su lectura con el `php -r` del guion | `off legacy prompts_off` |
| `bash -n motores-prompts-scope.sh` | sintaxis OK (no hay `shellcheck` en la máquina) |
| `vendor/bin/pint --test` sobre todo lo tocado | passed |

Cifras por suite (pruebas): UltronPublishedConfigTest 217 · UltronDecidePromptsTest 138 · UltronPromptCompilerTest 53 · UltronCommitTest 48 · UltronCommitPromptsTest 35 · UltronCanaryReportTest 32 · UltronTurnWatchdogTest 32 · UltronPublishedConfigV2Test 31 · UltronCommitStudioStampTest 30 · UltronFixturesTest 28 · UltronDecideTest 25 · UltronZeroSilentTurnsTest 25 · UltronEventBridgeTest 24 · UltronPromptStampTest 24 · UltronPaymentStatusTest 19 · UltronIncidentTest 18 · UltronPresenceTest 18 · UltronHandoffHardGuardTest 17 · UltronPromptBaseTest 14 · UltronMobileAppTest 14 · UltronPaymentApprovedTest 14 · UltronPaymentLinkTest 12 · UltronPriceRedactionTest 12 · UltronMembershipContextTest 11 · UltronStrategistContractTest 11 · UltronCustomerIntelligenceTest 9 · UltronCriticContractTest 8 · UltronPromptsN8nCommandTest 8 · UltronAuthorityMatrixTest 6 · UltronMemoryGuardsTest 6 · UltronPromptsTraceCommandTest 5 · UltronToolVocabularyTest 5 · UltronCanaryReportSinglePassH7Test 4 · UltronComposerStyleTest 4 · UltronGymContextTest 2 · UltronMemoryArcTest 2. (Nuevas mías: 308.)

## 8. Lo que NO he verificado

- **La suite completa** (la corre el lead al integrar) ni el CRM ni el Studio de B-S.
- **Nada contra n8n:** ni que el MCP acepte un `jsCode` de ~119 KB, ni los nombres de campo de sus operaciones, ni que n8n no reevalúe los `{{PLAN_PRICE}}` que quedan dentro del texto devuelto por la expresión del contenido de sistema (**[SUPOSICIÓN]** del plan; se comprueba en la copia, etapa E1). El selector y `Preparar Commit` se han ejecutado en `vm` de Node, no en el runner de n8n.
- **El guion `motores-prompts-scope.sh` no se ha ejecutado** (es para el servidor, como root): solo su sintaxis y el analizador de la traza que usa.
- **El efecto en el modelo** de los parches (R6): se mide en la canaria.
- `routes/console.php` (§5.3): no es mío.

## 9. Para `tester` (casos límite que conozco desde dentro)

- La rendición (`Preparar Rendicion`/`Rendicion Minima`) no manda bloque → `not_reported` aunque `decide` ofreciera `studio`.
- Cabeza movida entre `decide` y `commit`: `offered` (de `decide`) y `metadata.studio` (del commit) pueden hablar de versiones distintas; no se rechaza.
- `mode: studio` con la huella Legacy es `applied` (versión publicada con la línea base, etapa E3).
- Un rol alterado en tránsito → n8n cae a Legacy y manda `legacy` + huella Legacy → `engine_fallback`; constantes Legacy editadas en n8n → `legacy_drift`.
- Con el canario quitado del motor, nadie recibe prompts (`out_of_scope`), aunque `studio_config` sea `all`.
- Mutaciones que deben tumbar pruebas: quitar la comparación de huella en `UltronPromptOffer` (paso 7), quitar `'prompts'` de `unexpectedKeys`, quitar el `try` del selector, quitar la comprobación de unicidad de anclas en `UltronPromptCompiler::apply()`, escribir Legacy en `/2`.

---

## Resumen para el lead

- **1a–1c hechos y en verde en local** (SQLite y PG). Sin commit, push, producción ni n8n. 308 pruebas nuevas; suites `Ultron*` del motor: 961, solo 4 rojos esperados, iguales con las llaves en `off` y en `canary`.
- **Desplegable con las llaves en `off`:** `decide`/`commit` idénticos a hoy si n8n no negocia.
- **Te toca aplicar:** `enmienda-guardas-contrato-v2.diff` (`/2` → `/3` como contrato desconocido en el fixture y dos pruebas) y `enmienda-UltronEngineIsolationTest.diff` (la oferta nombra al lector, M1-6, y la prueba de la llave en `off`). Las dos están verificadas sobre copias. Añadir `MARKETING_ULTRON_STUDIO_PROMPTS=off` a `.env.example`.
- **Hay que incluir en el commit:** `resources/ultron-prompts/`, `n8n/flujos/ultron-studio-s1/` y el respaldo `n8n/respaldos/20261111-ultron-29de5119/` (el generador y E10 lo leen).
- **Decisiones que fijé:** la firma de `/2` usa el mismo prefijo `/1` que hoy; inserciones en la misma línea quedan en orden inverso; el selector es algo más defensivo que el esqueleto. Las dos primeras ya están en `api-compilador.md` para B-S y el tester.
- **Ojo, no es mío:** `routes/console.php:566` (plantillas de Meta) tumba otra prueba de `UltronEngineIsolationTest`.
- **Pendiente en n8n (tuyo):** contrastar los nombres de campo de las operaciones con el esquema del MCP; ensayo en copia; la suposición de que n8n no reevalúa `{{PLAN_PRICE}}`.
