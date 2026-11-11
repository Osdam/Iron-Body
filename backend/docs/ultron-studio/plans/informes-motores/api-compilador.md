# API pública del compilador de prompts (paquete B-M, paso 1a) — para B-S

**Autor:** `backend` (B-M). **Fecha:** 11-11-2026. **Estado:** paso 1a en verde, en local, sin commit (`tests/Unit/Marketing/Ultron/Prompts/*`: 91 pruebas, 5 097 aserciones; `pint --test` limpio).
**Contrato:** `plans/STUDIO_MOTORES_PLAN.md` §B.3–B.6 congelado por la Enmienda M-1 (`ULTRON_STUDIO_H5_CONTRACT.md`, M1-3: textos aprobados tal cual; `VERSION = 1`).

El Studio importa del motor **solo** `UltronPromptBase` y `UltronPromptCompiler` (M1-6). Las dos son puras: no usan base de datos, `config()`, reloj ni log; la base se lee de `resources/ultron-prompts/n8n-29de5119/` por una ruta relativa a la clase. `UltronPromptException` es la excepción que lanzan las dos.

Espacio de nombres: `App\Services\Marketing\Ultron\Prompts`.

---

## 1. `UltronPromptBase` (final)

| Constante | Tipo | Valor |
|---|---|---|
| `ID` | string | `'n8n-29de5119'` (es `prompts.base` en `/2`) |
| `CONTRACT` | string | `'ultron-prompts/1'` (primera línea del material de la huella y valor de `prompt_contract` en `decide`) |
| `ROLES` | list<string> | `['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry']` (orden canónico) |
| `NODES` | array<string,string> | `strategist→Strategist`, `composer→Composer`, `critic→Critic Comercial`, `composer_retry→Composer Reintento`, `critic_retry→Critic Comercial 2` |
| `SHA256` | array<string,string> | ver §4 |
| `BYTES` | array<string,int> | `29243, 27132, 15583, 25023, 15235` (112 216 en total) |
| `LEGACY_BUNDLE_HASH` | string | `'3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902'` |

| Método | Firma | Lanza |
|---|---|---|
| `roles()` | `public static function roles(): array` → `array<string,string>` rol → texto, en el orden de `ROLES` (memoizado por proceso, verificado contra `SHA256`) | `UltronPromptException` (`base_unreadable`, `base_corrupt`) |
| `bundleHash()` | `public static function bundleHash(array $roles): string` → 64 hex en minúsculas. Exige exactamente los cinco roles como `string`; el orden de claves de entrada no cuenta | `UltronPromptException` (`roles`) |
| `directory()` | `public static function directory(): string` → ruta absoluta de la base | — |

Fórmula de la huella (§B.3): `sha256_hex(implode("\n", ["ultron-prompts/1", "strategist", S, "composer", C, "critic", K, "composer_retry", CR, "critic_retry", KR]))` sobre UTF-8.

## 2. `UltronPromptCompiler` (final)

| Constante | Valor |
|---|---|
| `VERSION` | `1` (es `prompts.compiler` en `/2`) |
| `BASE` | `UltronPromptBase::ID` |
| `TONE`, `EMOJIS`, `MAX_CHARS`, `GOAL` | `'persona.tone'`, `'persona.emojis'`, `'persona.maxChars'`, `'strategy.goal'` |
| `PARAMETERS` | `[TONE, EMOJIS, MAX_CHARS, GOAL]` — **orden de aplicación** de los parches |
| `BASELINE` | `['persona.emojis' => true, 'persona.maxChars' => 320, 'persona.tone' => 'cercano', 'strategy.goal' => 'cita']` (claves en orden alfabético, el de `governed` en `/2`) |
| `TONES` | `['cercano', 'profesional', 'entusiasta']` |
| `GOALS` | `['cita', 'venta', 'informar']` |
| `MAX_CHARS_MIN`, `MAX_CHARS_MAX`, `MAX_CHARS_STEP` | `160`, `480`, `20` (17 valores) |
| `INSERT_AFTER_LINE`, `REPLACE` | `'insert_after_line'`, `'replace'` |
| `MAX_PATCH_BYTES`, `MAX_ROLE_BYTES`, `MAX_BUNDLE_BYTES` | `1200`, `40960`, `163840` |

| Método | Firma | Devuelve | Lanza |
|---|---|---|---|
| `compile()` | `public static function compile(array $governed): array` | ver forma abajo | `UltronPromptException`: `invalid_governed`, `base_unreadable`, `base_corrupt`, `anchor`, `too_large` |
| `patches()` | `public static function patches(array $governed): array` | `list<{parameter, value, role, operation, anchor, text}>` en orden de aplicación, **sin leer la base** (para la vista previa: «cambio exacto»). `[]` con la línea base | `invalid_governed`, `too_large` |
| `validate()` | `public static function validate(array $governed): array` | los cuatro valores en orden canónico (el de `BASELINE`) | `invalid_governed` |
| `isBaseline()` | `public static function isBaseline(array $governed): bool` | | `invalid_governed` |
| `changedRoles()` | `public static function changedRoles(array $governed): array` | `list<string>` en orden canónico | `invalid_governed` |

Forma de `compile()`:

```php
[
  'contract'   => 'ultron-prompts/1',
  'base'       => 'n8n-29de5119',
  'compiler'   => 1,
  'governed'   => ['persona.emojis' => bool, 'persona.maxChars' => int, 'persona.tone' => string, 'strategy.goal' => string],
  'roles'      => ['strategist' => string, 'composer' => string, 'critic' => string, 'composer_retry' => string, 'critic_retry' => string],
  'bundleHash' => '<64 hex>',
  'bytes'      => int,            // suma de los cinco roles en bytes UTF-8
  'patches'    => [ ['parameter' => 'persona.tone', 'value' => 'profesional', 'role' => 'composer',
                     'operation' => 'insert_after_line', 'anchor' => 'COMO SUENAS:', 'text' => '…'], … ],
]
```

**Validación estricta de `$governed`:** exactamente las cuatro claves (ni una más, ni una menos); `persona.emojis` `bool`; `persona.maxChars` `int` (ni `float` ni cadena) en [160, 480] y `(n − 160) % 20 === 0`; `persona.tone` ∈ `TONES` y `strategy.goal` ∈ `GOALS`, sensibles a mayúsculas. El orden de claves no cuenta (determinista).

**Propiedad central (E2):** `compile(UltronPromptCompiler::BASELINE)['roles'] === UltronPromptBase::roles()` byte a byte, `bundleHash === LEGACY_BUNDLE_HASH` y `patches === []`.

## 3. Semántica de los parches (para la vista previa y para el fixture del tester)

- `insert_after_line`: busca el ancla (debe aparecer **exactamente una vez** en el texto actual del rol, sensible a mayúsculas; si no, `anchor`); inserta `"\n" . texto` justo antes del primer `"\n"` posterior al ancla (es decir, como línea nueva tras la línea del ancla). Si el ancla está en la última línea, añade `"\n" . texto` al final.
- `replace`: sustituye la única aparición del ancla por el texto.
- Se aplican **en cadena**, cada uno sobre el resultado del anterior, en el orden de `PARAMETERS` y, dentro de un parámetro, en el de la tabla de abajo. **Consecuencia:** dos inserciones tras la misma línea quedan en orden inverso al de aplicación. Hoy solo ocurre en `critic`/`critic_retry` con tono ≠ `cercano` y `emojis = false` a la vez: `naturalness: …` → `EMOJIS: …` → `REGISTRO PUBLICADO: …`. (Lo fija `test_dos_inserciones_tras_la_misma_linea_quedan_en_orden_inverso_al_de_aplicacion`.)
- Ningún texto de parche contiene un ancla, ni saltos de línea, ni caracteres fuera de ASCII imprimible.

| Parámetro = valor | Parches, en orden |
|---|---|
| `persona.tone = profesional` / `entusiasta` | `composer` y `composer_retry`: `insert_after_line` `COMO SUENAS:`; `critic` y `critic_retry`: `insert_after_line` `naturalness:` |
| `persona.emojis = false` | `composer` y `composer_retry`: `replace` `Emoji opcional y raro, nunca en temas sensibles.`; `critic` y `critic_retry`: `insert_after_line` `naturalness:` |
| `persona.maxChars = N ≠ 320` | `composer`, `composer_retry`: `insert_after_line` `FORMA:`; `critic`: `replace` `mas de ~900 caracteres` → `mas de ~N caracteres`, luego `insert_after_line` `progressive_disclosure:`; `critic_retry`: igual |
| `strategy.goal = venta` / `informar` | `strategist`: `insert_after_line` `PRINCIPIO:` |

Los textos son los de §B.3 tal cual (con `N` sustituido por el número). Están en constantes privadas de la clase; la API los expone por `patches()`.

## 4. Huellas fijadas

| Rol | sha256 |
|---|---|
| strategist | `c84387582f581640493545996c7c61ab9a46d11829d740f1319c34b5c50a6917` |
| composer | `78437378e4a3b7bfba0b407d73b9e165b3a28e1e0555371bf771d0e47780e866` |
| critic | `180aa6c37257448f24a15e0ba7dafab918693a60cecf19e6b8141471e200c896` |
| composer_retry | `92d97a04316cf5a6fecb4a70c3f0e573c49d54f881bf6f2733b96524cb279ccd` |
| critic_retry | `a03940c2f4d0d5f21f3ee49007162b1057106bb619aa162292a22673009315ed` |
| **Paquete Legacy** | **`3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902`** |

Referencia para el cambio de aceptación (G8: `tone = profesional`, `maxChars = 200`, el resto línea base): `bundleHash = 702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c`, 113 672 bytes, 10 parches.

## 5. Lo que el Studio escribe en `/2` (implementado en el paso 1b, en verde)

- `UltronPublishedConfig::CONTRACT` **sigue siendo** `'ultron-engine-config/1'` (lo ata `StudioPublicationDeclarationTest`); se añade `UltronPublishedConfig::CONTRACT_V2 = 'ultron-engine-config/2'`.
- **Firma: la misma función y el mismo texto firmado que en `/1`**, sin cambios: `UltronPublishedConfig::signatureFor(string $effectiveJson): string` = `hex(HMAC-SHA256(k, "ultron-engine-config/1\n" . effective_json))` también para las filas `/2` («Firma, huella, decodificación estricta y tope de 16 KB: los de `/1`», §B.4). El contrato de la entrada va dentro del texto firmado y el lector exige que sea igual a la columna `engine_contract`.
- En `/2`, **toda parte no nula** lleva exactamente 8 claves: las 6 de `/1` + `governed` + `prompts`:
  - `governed`: `{"persona.emojis": bool, "persona.maxChars": int, "persona.tone": string, "strategy.goal": string}` → usar `compile($valores)['governed']`;
  - `prompts`: `{"base": "n8n-29de5119", "bundleHash": "<64 hex>", "compiler": 1}` → `['base' => $c['base'], 'bundleHash' => $c['bundleHash'], 'compiler' => $c['compiler']]`.
  - Una parte que se arrastra de una publicación anterior (`rest` al publicar en canario) también tiene que llevar las 8 claves si la entrada es `/2`.
- Legacy (`{canary:null, rest:null}`) **siempre en `/1`**.
- La llave del motor: `config('marketing.ultron.studio_prompts')` ∈ `{'off', 'canary'}` (el fichero de configuración ya normaliza: cualquier otra cosa, `all` incluido, es `off`). Variable `MARKETING_ULTRON_STUDIO_PROMPTS`.
- El lector estricto rechaza (`malformed`) una parte `/2` sin `governed`/`prompts`, con una clave de más o con un tipo distinto; los **valores** (que `base`/`compiler` sean los del código, que `governed` compile y que la huella cuadre) los comprueba `decide`, que cae a Legacy (`base_changed`, `compiler_changed`, `error`, `bundle_mismatch`).
