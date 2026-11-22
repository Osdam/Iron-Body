# Informe api-security · cuatro ojos por regla (B-2), 22-11-2026

**Alcance:** `git diff dfcee951..HEAD` de `cierre/gobierno` (4 commits), pase único con veredicto. Rutas del Studio de WhatsApp y voz, órdenes de consola y jobs. **Fuera de alcance:** el CRM, producción, el resto del Studio y una auditoría general. Sin red ni producción. No he tocado producto ni pruebas. Las sondas están en `scratchpad/apisec-b2/` (`ApisecB2DumpTest.php`, `ApisecB2BypassTest.php` y los dos `bootstrap-*.php`).
**Marcas:** **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. **Modelo:** el activo es el texto que dice ULTRON (WhatsApp y voz). El actor es una cuenta legítima con `manage_rules` + `approve`/`publish` que intenta aprobar o publicar sus propias frases. La frontera es la API del Studio frente al libro de solo inserción.

## Veredicto: **APTO CON CONDICIONES** para integrar y desplegar

No hay ningún bloqueante. El control está en las cinco puertas que activan una versión: aprobar, publicar y promover en WhatsApp, y publicar y promover en la voz. Se calcula sobre el contenido congelado, falla cerrado y no se puede rodear por la API. Para una versión sin frases, las respuestas son idénticas byte a byte. Queda **una condición** operativa y barata (C-1) y tres hallazgos menores.

## Hallazgos

**BLOQUEANTES:** ninguno.

**CONDICIONES**
- **C-1 · La base de la inducción en producción (solo lectura, antes de desplegar).** Hay caminos que **reactivan sin B-2** una versión que ya sirvió. En WhatsApp, revertir la PUBLISHED restaura la RETIRED anterior (`StudioPublications.php:437`, `:461-466`, `ultron.rollback`, sin cuatro ojos por contrato §A5.3). En la voz, revertir vuelve al agente anterior (`StudioVoicePublishing.php:448-467`) y soltar el freno reanuda el agente servido (`StudioVoiceEvidence.php:338`). No es un rodeo: lo que vuelve ya pasó aprobar, publicar y promover con B-2 por otras cuentas [L]. Pero eso solo es cierto para lo que se activó **después** de este despliegue. El «0 S-NNN y 0 publicaciones» de producción viene del plan [S] y nadie lo ha mirado en esta tanda.
  - Qué hacer: comprobar `count(*)` de `ultron_studio_rules` con `code LIKE 'S-%'`.
  - Si sale > 0: que ninguna versión RETIRED/PUBLISHED/CANARY y ningún agente de voz que haya servido copie una S-NNN con frases. Si alguna lo hace, «Volver a Legacy» o «Apagar la voz» antes de dar B-2 por cerrado.
  - Hasta entonces, la regla E-4 sigue vigente.

**MENORES**
- **M-1 · El libro atribuye autoría a quien no escribió nada (caso `unverified`)** [C, sonda R4 en SQLite y PG].
  - Dónde: `StudioVersions.php:558,561`, `StudioPublications.php:2076,2079`, `StudioVoicePublishing.php:1101` y `StudioRulePhraseAuthors::auditDiff` (`:147`).
  - Escenario: falta la línea de la revisión 2 de S-001. Carla, que no tocó la regla, intenta aprobar y recibe el 403 correcto. Pero queda una línea **inmutable** `version.four_eyes_denied` con el actor Carla, el resumen «…que copia frases de reglas **que escribió**» y `diff.rulePhraseAuthor: ["S-001"]` (más `rulePhraseUnverified: ["S-001"]`).
  - Impacto: una prueba falsa de autoría en el registro de rendición de cuentas, que ya no se puede corregir.
  - Corrección mínima:
    - un resumen según el caso («…que copia frases de reglas sin autoría probada en el libro»);
    - `rulePhraseAuthor` solo con los códigos con `authored ≠ []`; lo `unverified` sigue en `rulePhraseUnverified`;
    - ajustar `test_una_revision_sin_su_linea_del_libro_…`, que fija hoy la lista mezclada.
  - Cuándo: antes de la primera S-NNN en producción.
- **M-2 · El coste de la autoría crece con revisiones que un usuario puede inflar (API4)** [C, sonda R6].
  - Dónde: `StudioRulePhraseAuthors.php:234-254` lee **todas** las líneas `rule.created/updated` de las reglas copiadas, también las posteriores a la revisión copiada (se filtran en PHP), y trae `diff` entero (`:240`, unos 4,3 KB por línea con frases al máximo: 3×10×60).
  - Escenario: un Administrador (`manage_rules` por defecto) hace PATCH de prioridad en bucle, a 30 por minuto por cuenta (`AppServiceProvider.php:617-619`). Como **toda versión nueva copia todas las reglas vivas**, cada detalle, vista previa, aprobar o publicar por otra cuenta paga el coste.
  - Medido para una sola llamada a `of()`:
    - 5 000 revisiones: SQLite 67 ms y +36 MiB; PG14 85 ms y +40 MiB.
    - 20 000 revisiones (unas 11 h de bucle): 388 ms y +166 MiB.
  - Impacto: puede tirar esas rutas con un 500 si se agota `memory_limit` [S: no sé el de producción]. Falla cerrado (no aprueba nada) y deja rastro ruidoso en el libro. Se corta archivando la regla.
  - Corrección mínima: no traer `diff`, solo `toRevision` (selector JSON `e.diff->toRevision`), o acotar en SQL a `toRevision ≤ copiada` por código. Conservar el «entero o sin rastro» y medirlo con performance-specialist.
- **M-3 · El veredicto del laboratorio solo excluye a quien creó la versión** (`StudioLabVerdicts.php:59`, `StudioVoiceLabVerdicts.php:55-60`). Es la pregunta 7a. Recomendación en una frase: **no es un hueco del control de cuatro ojos**, porque aprobar y publicar siguen exigiendo cuentas ajenas a las frases [C]; por coherencia, el propietario puede decidir que el veredicto `approved` excluya también a los autores con el mismo `StudioRulePhraseAuthors::of()`, dejando abiertos `rejected` y `needs_changes`.

## 1 · Cobertura de rutas (BFLA)

| Ruta / orden | Permiso | Qué activa | ¿B-2? |
|---|---|---|---|
| `POST versions/{uuid}/approve` | `ultron.approve` | → APPROVED | **Sí**: `StudioVersions.php:274`→`:552` [C] |
| `POST versions/{uuid}/reject` | `ultron.approve` | → REJECTED | No, a propósito (D15, dirección segura) [C] |
| `POST versions/{uuid}/publish` | `ultron.publish` | APPROVED → CANARY | **Sí**: `StudioPublications.php:204`→`:2070` [C] |
| `POST versions/{uuid}/promote` | `ultron.publish` | CANARY → PUBLISHED | **Sí**: `:316`→`:2070` [C] |
| `POST versions/{uuid}/rollback` | `ultron.rollback` | → ROLLED_BACK; **restaura** RETIRED → PUBLISHED (`:461`) | No (§A5.3). Vuelve lo ya revisado: ver C-1 [L] |
| `POST publications/legacy` | `ultron.rollback` | nada queda activo | n/a [L] |
| `POST versions`, `…/submit` | `ultron.edit_draft` | DRAFT / VALIDATED | n/a: las reglas salen de la tabla, las del borrador se ignoran (R2) [C] |
| `POST voice/publish` | `ultron.voice_publish` | agente → canario | **Sí**: `StudioVoicePublishing.php:278`→`:1096` [C] |
| `POST voice/promote` | `ultron.voice_publish` | canario → resto | **Sí**: `:384`, sobre la versión del agente [C] |
| `POST voice/rollback` · `voice/brakes/{uuid}/release` | `ultron.voice_publish` | el agente anterior · reanudar el servido | No: no cambian el contenido revisado (C-1) [L] |
| `POST voice/off` | `ultron.voice_publish` | apaga | n/a |
| `POST lab/runs/{uuid}/verdict`, `voice-lab/sessions/{uuid}/verdict` | veredicto | la puerta A13 de publicar | No: solo quien creó (M-3) [L] |
| `integrations/canaries/*`, `voice/profiles/*`, `strategies/*` | sus permisos | a quién llega, el perfil, otra entidad | n/a: no compilan frases de reglas (`byRule` solo en `StudioVoiceCompiler.php:773` y `StudioPromptBundle.php:177,202`) [L] |
| Consola y jobs | — | — | Ninguna orden ni job cambia estados: `PublicationCheckCommand:34` y `MonitorCommand:205` solo leen `state()`; `import-baseline` solo toca R-xx [L] |

## 2-8 · Respuestas breves

2. **Rodeos:** ninguno por la API.
   - **Editar después de copiar** no cambia nada: la revisión es la del contenido congelado (`StudioRulePhraseAuthors.php:209-216`).
   - **Archivar después de copiar** sigue bloqueando aprobar, publicar y promover (R1) [C]. Desarchivar no existe (`StudioRules.php:800-802`) y los códigos no se reutilizan (`:883-893`, UNIQUE).
   - **Que la versión la cree otra cuenta** no cambia nada [C]. No hay ruta que derive una versión de otra, y unas `rules` metidas en el borrador se ignoran (R2) [C].
   - **Orden y carreras:** el reintento exige la misma persona. Las líneas ≤ la copiada son inmutables, y la fila y su línea van en la misma transacción (`StudioRules.php:251-288`) [L].
3. **Fallo cerrado:** se niega siempre [C, R3/R4 en SQLite y PG] con:
   - una línea ausente, sin actor o con `toRevision` no entero;
   - una revisión `"1"`, `1.0`, `0`, `-1`, `null`, ausente o un array;
   - un código sin fila, o una revisión mayor que el libro.
   - Un error al leer el libro sale como 500, nunca como permiso.
   - **Bloquear a los demás con acceso normal: no** [L]. `rule.created` y `rule.updated` siempre llevan persona (`StudioAccess.php:85-99`) y `toRevision` entero (`StudioRules.php:181,284`). `actor_admin_id` no tiene FK (`2026_10_18_100001…:168-171`): borrar una cuenta no deja líneas sin actor. Solo se llega por SQL. El coste (M-2) es otra vía de disponibilidad.
4. **Exposición:** el 403, la vista previa y `actions` solo enseñan a quien mira **sus** revisiones y `unverified`, con códigos y números, nunca nombres (R5) [C]. Quien no escribió nada ve `{ok:true, rules:[]}`. No hay oráculo nuevo: `GET audit` ya enseña a `ultron.view` las líneas `rule.*` con su actor (`StudioAudit.php:191-211`), y la regla, su `createdBy`/`updatedBy` (`StudioRules.php:531-532`).
5. **Orden y contrato:** B-2 va dentro del paso 4.
   - Aprobar: después del permiso y antes del estado y la integridad (`StudioVersions.php:273-276`).
   - Publicar: después de permiso → llaves → reintento, y antes del estado, la integridad y la ventana.
   - Promover: antes que `scope_not_allowed`.
   - El fixture fija las dos (`UltronStudioPublicationLifecycleTest` 77/758 OK) [C]. Mismo evento `*.four_eyes_denied` y cambios aditivos (`detail`, `rulePhraseAuthor`, el requisito al final) [C]. Que el CRM los pinte no lo he verificado.
6. **Sin frases, nada cambia** [C]. Cinco escenarios, más de 90 respuestas HTTP más el libro entero, con uuids deterministas y reloj fijo:
   - el detalle de 4 cuentas en 3 estados;
   - aprobar con 403 y 200, y rechazar con 403;
   - la vista previa de 4 cuentas (`previewHash` igual);
   - publicar 403 (cuatro ojos), 403 `forbidden` y 201; promover 403 y 409; revertir; Legacy;
   - la voz: publicar 403 por versión y por perfil y 201, y promover 403 y 409.

   Corrí los servicios de `dfcee951` (cargados delante con un bootstrap aparte) y los de HEAD: **sha256 idénticos** en los 5 escenarios, y HEAD dos veces da lo mismo (control de determinismo). Incluye una versión **sin ninguna S-NNN**, como la aprobada que espera en producción.
7. **(a)** Ver M-3. **(b) B-1 no empeora** [L]: el diff no toca `promptedPart`, `promptedForPublish`, `promptedForMove` ni `promptsDto`. Además, la V arrastrada ya necesitó cuentas ajenas a sus frases para aprobarse y publicarse; la condición E-3 sigue vigente. **(c) Es por cuenta, no por persona** (`StudioAccess::id()`): una persona con dos cuentas lo esquiva. Es un límite, no un hallazgo.
8. **Consultas:** una lectura por versión y petición (12/218 OK en SQLite y en PG14) [C]. La preview la hace para cualquier `ultron.view` (`StudioPublications.php:598`). El coste crece con las revisiones (M-2). `byRule` está acotado a 100 reglas vivas (`StudioRules.php:61`).

## Pruebas negativas para tester

- **R1:** archivar la regla después de copiarla → 403 al aprobar, publicar y promover.
- **R2:** un borrador con `rules` propias → la versión no las lleva.
- **R4:** `toRevision` en texto → 403 a todos; con M-1 ya corregido, la línea no atribuye autoría a quien la pide.
- **M-2:** `of()` no trae `diff` y no lee más filas de las necesarias.

## Qué no pude verificar

- **El CRM pintando el motivo:** no es este repositorio.
- **Los datos de producción** (C-1) **y su `memory_limit`:** sin acceso, por regla.
- **Carreras reales en PG:** las razoné, no las forcé.
- **La batería completa:** corrí las nuevas 12/218 (SQLite y PG), el ciclo de vida 77/758, las paridades 181/874 y mis sondas 5 + 6 (SQLite; las 6 de rodeos también en PG).
