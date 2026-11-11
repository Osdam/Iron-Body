# Operación · prompts de ULTRON gobernados por la versión publicada (Enmienda M-1)

Contrato: `docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md` (Enmienda M-1) y `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` §B.3–B.8 y §D. Informe del paquete: `docs/ultron-studio/plans/informes-motores/informe-bm.md`.

**Nada de esto se hace sin la orden expresa del propietario para su etapa (G5).** Solo la conversación canaria; nunca clientes.

## Las piezas

| Pieza | Qué hace | Dónde |
|---|---|---|
| Base | Los cinco prompts del flujo vivo 29de5119, byte a byte (sha256 fijados) | `backend/resources/ultron-prompts/n8n-29de5119/*.txt` |
| Compilador | Parches anclados por cada valor gobernado; `compile(línea base) = base` | `UltronPromptCompiler` (`VERSION = 1`) |
| Oferta | En `decide`, si n8n negocia: los cinco prompts compilados desde la parte publicada, solo si su huella es la firmada; si no, Legacy con motivo | `UltronPromptOffer` |
| Sello | En el commit: ofrecido (firmado en el token) frente a observado (huella que calcula n8n) → `metadata.studio_prompts` | `UltronPromptStamp` |
| Llave | `MARKETING_ULTRON_STUDIO_PROMPTS` = `off` (defecto) \| `canary` | `config/marketing.php` |
| Generador de n8n | `php artisan ultron:prompts-n8n` → `n8n/flujos/ultron-studio-s1/` | `UltronPromptsN8n` + `UltronPromptsN8nFlow` |
| Comprobador | `verificar-flujo.cjs <export.json>`: offline, sale 0 solo si todo cuadra | este directorio |
| Mando de la llave | `motores-prompts-scope.sh off\|canary <actual>` (en el servidor, como root) | este directorio |
| Traza | `php artisan ultron:prompt-trace [--last=20] [--json]` (solo lectura) | `UltronPromptTrace` |

## Comprobador offline

```bash
# desde la raíz del repositorio
node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json> \
     [--respaldo=n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json] \
     [--generado=n8n/flujos/ultron-studio-s1]
```

Sin red. Comprueba: la base y la huella Legacy (crypto de Node = constantes PHP); los 24 nodos del respaldo + «Elegir Prompts»; que fuera de los 8 parámetros de §B.7 nada cambió (salvo la posición en el lienzo); las conexiones; ejecuta el selector en `vm` (sin oferta → base byte a byte y huella Legacy; `studio` válido → roles ofrecidos, con el SHA-256 en JS igual al de Node en 64 longitudes y al de PHP en el fixture; rol alterado → `hash_mismatch`; forma → `shape`; tamaño → `size`; contrato desconocido; oferta legacy → `offered_<motivo>`; excepción → `selector_error`); y ejecuta «Preparar Commit» junto al de 29de5119 con los mismos datos (sin negociación, misma salida y sin bloque; con negociación, misma salida + bloque `prompts` válido). Salida 0 = OK; 1 = alguna diferencia (lista con `✗`); 2 = entradas ilegibles.

Hoy: **pasa** sobre `n8n/flujos/ultron-studio-s1/flujo-s1.json` (111 comprobaciones) y **falla** sobre el respaldo 29de5119 (es lo esperado: le falta S1).

## Etapas (plan, paso 8) y qué tiene que decir la traza

| Etapa | Acción | `ultron:prompt-trace` |
|---|---|---|
| E0 | Desplegar backend + migración de `/2` con las llaves en `off` | turno canario sin `studio_prompts` (estado «sin sello») |
| E1 | n8n S1: copia → comprobador → producción (ver `n8n/flujos/ultron-studio-s1/LEEME.md`) | turno `legacy`, observada = huella Legacy, `S1` |
| E2 | `bash h5-engine-scope.sh canary off` (si hacía falta) y `bash motores-prompts-scope.sh canary off` | oferta `legacy` con `no_publication` o `declaration_v1` |
| E3 | Publicar en canario una versión con la línea base | turno `applied` con la huella Legacy |
| E4 | Publicar V2 (`tone=profesional`, `maxChars=200`) | turno `applied`, huella `702890f7…` |
| E5 | Revertir V2 en el Studio | turno `legacy` (o la parte anterior) con huella Legacy |

Estados del sello: `applied` (n8n aplicó exactamente lo ofrecido), `legacy` (no se ofreció y n8n usó Legacy íntegro), `engine_fallback` (se ofreció y n8n lo rechazó: el motivo dice por qué), `mismatch` (n8n dice haber aplicado otra cosa), `legacy_drift` (alguien editó a mano las constantes Legacy en n8n), `not_reported` (n8n viejo o rendición), `unverified` (sin huella observada), `stamp_error` (fallo calculando el sello; nunca bloquea).

## Vuelta atrás

- **Llave:** `bash motores-prompts-scope.sh off canary` → la traza tiene que dar `off legacy prompts_off`. Inmediato y sin tocar n8n: S1 con la oferta en Legacy es idéntico a hoy.
- **Una publicación:** revertir o volver a Legacy en el Studio (Legacy se escribe siempre en `/1`).
- **n8n:** restaurar y publicar `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` desde el **historial de versiones** de n8n. **Nunca** reimportar un JSON (sin los secretos de las credenciales, todos los turnos morirían).
- **Backend:** primero volver a Legacy en el Studio (escribe `/1`), después devolver el código (R10).

## Cuidado

- **Cambiar la base o un texto del compilador** (R7) hace que toda publicación `/2` anterior caiga a Legacy en `decide` (`base_changed` o `compiler_changed`) hasta volver a publicar; y obliga a regenerar S1 (`php artisan ultron:prompts-n8n`) y a pasar otra vez el comprobador. `UltronPromptsN8nCommandTest` falla si lo del repositorio no es lo que genera el código.
- **Los ficheros de la base son bytes exactos** (sin salto de línea final y con espacios al final de algunas líneas): un editor que los «arregle» rompe su sha256 y `decide` cae a Legacy (`error`). Hay `.editorconfig` y `.gitattributes` en `resources/ultron-prompts/` para evitarlo.
- **No leer ejecuciones del flujo de producción** (enseñan el bearer en claro). La evidencia es el sello y la traza; `execution` sirve para correlacionar metadatos.
