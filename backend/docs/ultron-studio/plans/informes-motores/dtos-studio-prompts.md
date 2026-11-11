# DTOs del Studio para los prompts gobernados (Enmienda M-1) — para `frontend`

**Autor:** `backend` (B-S). **Fecha:** 11-11-2026. **Estado:** formas FIJADAS e implementadas en local (sin commit). Los JSON de abajo los producen los MISMOS presentadores que las respuestas (`StudioPublications::previewFrom()`, `promptsDto()`, `engineBlock()`/`promptsTrace()`, `StudioCanaryMeasures::promptTurns()`) vía `StudioPublicationExport::shapes()`: van también en `backend-publication.testing.ts` (`examples.preview`, `examples.previewPrompts`, `examples.engine`, `examples.enginePrompts`) al correr `php artisan ultron-studio:export-fixtures`. Las huellas son las reales del compilador del motor sobre la base del repositorio.

Todo es **aditivo**: ninguna clave existente cambia de nombre, tipo ni significado. Con la llave de prompts del motor en `off` (el defecto) la única diferencia observable es `effect: "declaration"`, `prompts: null` en la vista previa y `engine.prompts` con `key: "off"` en `status`.

---

## 1. `GET versions/{uuid}/publication-preview` (`PublicationPreviewDto`)

Dos claves nuevas, **siempre presentes y al final** del objeto (después de `blocking`):

| Clave | Tipo | Significado |
|---|---|---|
| `effect` | `"declaration" \| "prompts"` | `declaration`: la llave de prompts del motor está en `off`; publicar solo declara (el texto del H5 «no cambia lo que ULTRON dice ni hace» sigue siendo cierto). `prompts`: publicar **cambia los prompts de ULTRON en la conversación canaria** (el texto del H5 deja de ser cierto; el diálogo tiene que decirlo). |
| `prompts` | `null` \| objeto | `null` con `effect = declaration`. Si no, el paquete que se firmaría (abajo). |

Además, con `effect = prompts`:

- `params[]` puede traer filas con **`class: "prompt"`** (clase nueva): los cuatro gobernados (`persona.tone`, `persona.emojis`, `persona.maxChars`, `strategy.goal`) que se apartan de la línea base. Forma de la fila: la de `no_effect` (`layer`, `key`, `class`, `versionValue`, `engineValue: null`, `ok: true`). **No van en `noEffect`** y no se reconocen (`acknowledgedNoEffect` no los lleva).
- `noEffect` sigue trayendo `intent.fallback` (si difiere) y las reglas `rule:S-NNN`: **siguen sin efecto en WhatsApp**.
- `blocking` puede traer **`prompts_compile_failed`** (justo después de `lab_verdict_required`): el paquete de la versión, o el de la parte del resto que se arrastra, no compila. Publicar responde 409 con ese código.
- `previewHash` **incluye** la huella del paquete (y la de la parte arrastrada): si el paquete cambia entre la vista previa y la publicación, 409 `preview_stale` como siempre.

### 1.1 `prompts`

| Clave | Tipo | Notas |
|---|---|---|
| `key` | `"canary"` | la llave de prompts del motor (solo existe el objeto con `canary`) |
| `base` | string | `n8n-29de5119` |
| `compiler` | int | `1` |
| `legacyBundleHash` | 64 hex | la huella del paquete Legacy (la base sin parches) |
| `bundleHash` | 64 hex \| null | la huella del paquete de esta versión; null si no compila |
| `shortHash` | 8 hex \| null | los 8 primeros de `bundleHash` (para pintar «huella xxxxxxxx») |
| `baseline` | bool \| null | true = el paquete es exactamente Legacy (versión con los valores de la línea base: «cero cambio de comportamiento», etapa E3) |
| `bytes` | int \| null | tamaño del paquete compilado |
| `changes` | lista | **la tabla de cambios**, un elemento por parámetro gobernado que se aparta de la línea base, en el orden de aplicación (`persona.tone`, `persona.emojis`, `persona.maxChars`, `strategy.goal`): `{param, value, roles, patches}`; `roles` en orden canónico (`strategist`, `composer`, `critic`, `composer_retry`, `critic_retry`); `patches` en orden de aplicación, cada uno `{role, operation ("insert_after_line" \| "replace"), anchor, text}` con el **texto exacto** del parche. `[]` con la línea base |
| `carried` | null \| objeto | la parte del **resto** que la entrada nueva arrastra (la de la cabeza): `{versionUuid, label, bundleHash, shortHash, baseline}`. **Importa:** si después se revierte este canario, la canaria pasa a recibir los prompts de ESA versión. Con `baseline: true`, son los Legacy. null = no hay resto (tras revertir, la canaria vuelve a Legacy) |
| `error` | null \| `"prompts_compile_failed"` | |

### 1.2 Ejemplo real — cambio de aceptación (G8: `tone = profesional`, `maxChars = 200`), llave encendida

Cabecera (sin cambios de forma):

```json
{
  "versionUuid": "11111111-1111-4111-8111-111111111111",
  "contentHash": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "previewHash": "172c2b5747803ee45349f665de0f49809b244a05470f9349545ae96af60662c5",
  "publishable": true
}
```

Las filas `prompt` de `params` (el resto de filas, como siempre):

```json
[
  {
    "layer": "persona",
    "key": "tone",
    "class": "prompt",
    "versionValue": "profesional",
    "engineValue": null,
    "ok": true
  },
  {
    "layer": "persona",
    "key": "maxChars",
    "class": "prompt",
    "versionValue": 200,
    "engineValue": null,
    "ok": true
  }
]
```

`noEffect`, `blocking`, `effect` y `prompts`:

```json
{
  "noEffect": [
    "rule:S-003"
  ],
  "blocking": [],
  "effect": "prompts",
  "prompts": {
    "key": "canary",
    "base": "n8n-29de5119",
    "compiler": 1,
    "legacyBundleHash": "3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902",
    "bundleHash": "702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c",
    "shortHash": "702890f7",
    "baseline": false,
    "bytes": 113672,
    "changes": [
      {
        "param": "persona.tone",
        "value": "profesional",
        "roles": [
          "composer",
          "critic",
          "composer_retry",
          "critic_retry"
        ],
        "patches": [
          {
            "role": "composer",
            "operation": "insert_after_line",
            "anchor": "COMO SUENAS:",
            "text": "REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales (\"De una\", \"Listo\", \"Tranquilo\") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio."
          },
          {
            "role": "composer_retry",
            "operation": "insert_after_line",
            "anchor": "COMO SUENAS:",
            "text": "REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales (\"De una\", \"Listo\", \"Tranquilo\") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio."
          },
          {
            "role": "critic",
            "operation": "insert_after_line",
            "anchor": "naturalness:",
            "text": "REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es."
          },
          {
            "role": "critic_retry",
            "operation": "insert_after_line",
            "anchor": "naturalness:",
            "text": "REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es."
          }
        ]
      },
      {
        "param": "persona.maxChars",
        "value": 200,
        "roles": [
          "composer",
          "critic",
          "composer_retry",
          "critic_retry"
        ],
        "patches": [
          {
            "role": "composer",
            "operation": "insert_after_line",
            "anchor": "FORMA:",
            "text": "TOPE DE LONGITUD PUBLICADO: como maximo unos 200 caracteres por mensaje, contando cada marcador como el texto que lo sustituye; tambien la informacion general respeta el tope: si no cabe, da lo esencial y ofrece ampliar."
          },
          {
            "role": "composer_retry",
            "operation": "insert_after_line",
            "anchor": "FORMA:",
            "text": "TOPE DE LONGITUD PUBLICADO: como maximo unos 200 caracteres por mensaje, contando cada marcador como el texto que lo sustituye; tambien la informacion general respeta el tope: si no cabe, da lo esencial y ofrece ampliar."
          },
          {
            "role": "critic",
            "operation": "replace",
            "anchor": "mas de ~900 caracteres",
            "text": "mas de ~200 caracteres"
          },
          {
            "role": "critic",
            "operation": "insert_after_line",
            "anchor": "progressive_disclosure:",
            "text": "TOPE PUBLICADO: un borrador de mas de ~200 caracteres falla por progressive_disclosure aunque se haya pedido detalle."
          },
          {
            "role": "critic_retry",
            "operation": "replace",
            "anchor": "mas de ~900 caracteres",
            "text": "mas de ~200 caracteres"
          },
          {
            "role": "critic_retry",
            "operation": "insert_after_line",
            "anchor": "progressive_disclosure:",
            "text": "TOPE PUBLICADO: un borrador de mas de ~200 caracteres falla por progressive_disclosure aunque se haya pedido detalle."
          }
        ]
      }
    ],
    "carried": {
      "versionUuid": "00000000-0000-4000-8000-0000000000a0",
      "label": "Línea base publicada",
      "bundleHash": "3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902",
      "shortHash": "3f6a5674",
      "baseline": true
    },
    "error": null
  }
}
```

### 1.3 Ejemplo real — llave de prompts en `off` (cola del objeto; lo demás, como hoy)

```json
{"…": "…", "blocking": [], "effect": "declaration", "prompts": null}
```

---

## 2. `GET status` → `data.engine` (`engine.prompts`, permiso `ultron.view`)

`engine` gana la clave **`prompts`** (después de `publication`). Si su lectura falla, la clave **no viene** (el resto del bloque sí; queda una línea en el log): el CRM no debe inventar nada en ese caso. `engine.message` cambia de texto **solo** con la llave de prompts encendida (ver §3).

| Clave | Tipo | Notas |
|---|---|---|
| `key` | `"off" \| "canary"` | la llave de prompts del motor (proceso web) |
| `base`, `compiler`, `legacyBundleHash` | | como en la vista previa |
| `current` | `{mode: "studio" \| "legacy", version: uuid \| null, bundleHash: 64 hex \| null, reason: string \| null}` | la oferta que haría `decide` **ahora** a la conversación canaria (misma regla que `UltronPromptOffer`; una prueba las compara). En `legacy`, `reason` ∈ `prompts_off`, `studio_config_off`, `out_of_scope` (sin canario), `no_publication`, `part_legacy`, `declaration_v1`, `publication_<contract_unknown\|hash_mismatch\|signature_invalid\|malformed>`, `base_changed`, `compiler_changed`, `bundle_mismatch`, `too_large`, `error` |
| `lastTurns` | lista (≤ 20) | los últimos turnos de la **conversación canaria**, del más antiguo al más reciente: `{at, declared, offered, observed, state}` |

Cada turno: `at` = minuto UTC (`AAAA-MM-DDTHH:MMZ`); `declared` = `{state, version8}` del sello H5 (`metadata.studio`) o null; `offered` = `{mode, reason, version8, bundle8}` o null; `observed` = `{mode, bundle8, flowRev}` o null; `state` = el estado del sello de prompts (`applied`, `legacy`, `engine_fallback`, `mismatch`, `legacy_drift`, `not_reported`, `unverified`, `stamp_error`) o **null** (turno sin sello de prompts: anterior a S1 o con el n8n viejo). `version8`/`bundle8` son los 8 primeros caracteres. **Nunca** salen ids de conversación, de mensaje ni de fila, ni textos.

### 2.1 Ejemplo real — llave encendida, V2 aplicada y revertida

```json
{
  "key": "canary",
  "base": "n8n-29de5119",
  "compiler": 1,
  "legacyBundleHash": "3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902",
  "current": {
    "mode": "studio",
    "version": "11111111-1111-4111-8111-111111111111",
    "bundleHash": "702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c",
    "reason": null
  },
  "lastTurns": [
    {
      "at": "2026-11-03T14:58Z",
      "declared": {
        "state": "legacy",
        "version8": null
      },
      "offered": {
        "mode": "legacy",
        "reason": "part_legacy",
        "version8": null,
        "bundle8": null
      },
      "observed": {
        "mode": "legacy",
        "bundle8": "3f6a5674",
        "flowRev": "S1"
      },
      "state": "legacy"
    },
    {
      "at": "2026-11-03T15:10Z",
      "declared": {
        "state": "applied",
        "version8": "11111111"
      },
      "offered": {
        "mode": "studio",
        "reason": null,
        "version8": "11111111",
        "bundle8": "702890f7"
      },
      "observed": {
        "mode": "studio",
        "bundle8": "702890f7",
        "flowRev": "S1"
      },
      "state": "applied"
    },
    {
      "at": "2026-11-04T09:05Z",
      "declared": {
        "state": "legacy",
        "version8": null
      },
      "offered": {
        "mode": "legacy",
        "reason": "part_legacy",
        "version8": null,
        "bundle8": null
      },
      "observed": {
        "mode": "legacy",
        "bundle8": "3f6a5674",
        "flowRev": "S1"
      },
      "state": "legacy"
    }
  ]
}
```

### 2.2 Ejemplo real — llave en `off` (lo que verá el CRM al desplegar)

```json
{
  "key": "off",
  "base": "n8n-29de5119",
  "compiler": 1,
  "legacyBundleHash": "3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902",
  "current": {
    "mode": "legacy",
    "version": null,
    "bundleHash": null,
    "reason": "prompts_off"
  },
  "lastTurns": []
}
```

---

## 3. Textos que cambian

- `engine.message` con la llave de prompts encendida (`canary`; con `studio_config = all` hay otro texto que añade «En las demás conversaciones no cambia nada»):

  > El motor de ULTRON lee la cadena en la conversación canaria y anota en cada turno la versión publicada. Con la llave de prompts encendida, publicar CAMBIA lo que ULTRON dice en esa conversación: el tono, los emojis, la longitud y el objetivo comercial de la versión entran en sus prompts. «Si no entiende» y las reglas S-NNN siguen sin efecto en WhatsApp.

  Con la llave en `off`, el texto de siempre (el H5).
- El texto obligatorio del diálogo de publicar (H5 §A7, CRM `studio-publication-text.ts:28`) debe depender de `preview.effect`: con `declaration`, el de hoy; con `prompts`, decir que publicar cambia lo que ULTRON dice en la canaria (tono, emojis, longitud, objetivo) y que «Si no entiende» y las S-NNN siguen sin efecto. El texto del CRM es del `frontend`.
- 409 nuevo al publicar: `prompts_compile_failed` («Los prompts de ULTRON no se pueden compilar con los valores de esta publicación: no se publica nada. Avisa a quien administra el sistema.»). En `backend-publication.testing.ts` → `errors`.

## 4. Vocabularios nuevos en `backend-publication.testing.ts`

`contracts` (`ultron-engine-config/1`, `/2`), `effects`, `paramClasses` (+`prompt`), `promptKeys` (`off`, `canary`), `promptGoverned` (los cuatro, en orden de aplicación), `promptRoles`, `promptModes`, `promptTurnsMax` (20), `engineMessagesPrompts` y `examples.{previewPrompts, enginePrompts}`; `examples.engine` lleva ahora `prompts` (llave en `off`).

## 5. Lo que NO cambia

`PublicationEntryDto`, `PublicationStateDto`, el historial, `CanaryEvidenceDto`, `GET engine/readings` y `GET catalog` (el catálogo NO cambia en este paquete: ver el informe de B-S, §«Catálogo»).
