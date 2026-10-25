# ULTRON STUDIO · Hito 5 · Revisión de seguridad transversal (`security`)

**Fecha:** 25-10-2026. **Revisado:** backend `feat/ultron-studio-h5` en `61048ff` (diff `0366009..61048ff`) y CRM `feat/ultron-studio-h5` en `24a691d` (diff `5c1dbd7..24a691d`; incluye `e7ba41b` y `33821e4`, y `24a691d`, que se confirmó mientras revisaba: D-1 y paridad, sin sumideros nuevos).

**Modo:** solo lectura. No edité código, no hice commit ni push y no toqué producción ni el servidor. Las pruebas de este informe son scripts mínimos del scratchpad (`…/scratchpad/security/`) que cargan clases reales del backend con `vendor/autoload.php`, sin arrancar la aplicación ni leer ningún fichero de entorno. No ejecuté PHPUnit, el E2E ni los specs del CRM, porque otros agentes los tenían en marcha.

**Reparto:** la autorización endpoint por endpoint (BOLA/BFLA, cuatro ojos, `clientRequestId`, forma de las respuestas) es de `api-security-specialist`. Aquí van la criptografía de la cadena, los secretos, los logs, la configuración, los scripts de operación, la base de datos, el cliente y las dependencias.

## 0. Resumen

**Bloqueantes: no. Altas: ninguna.**

| Sev. | Id | Qué | Dónde |
|---|---|---|---|
| Media | M-1 | `ULTRON_STUDIO_PUBLISHING_ENABLED=off` (o `no`, `disabled`, cualquier texto no booleano) **abre** publicar | `config/ultron_studio.php:63`, `StudioPublishing.php:72-75` |
| Media (operativa) | M-2 | El script de la fase 1 aborta en silencio en la línea 11 con el checkout limpio: tal cual, la fase 1 no se puede ejecutar | `ops/deploy-h5-backend-fase1.sh:11` |
| Media (condicional, sin verificar) | M-3 | `git` se ejecuta como root sobre un checkout de `www-data`. Si `.git` es escribible por `www-data`, un compromiso de la app pasa a root en el siguiente despliegue | `ops/deploy-h5-backend-fase1.sh:9-13` |
| Baja | B-1 | Añadir una clave a un fichero de entorno que no acaba en salto de línea rompe el parseo del fichero ENTERO (cae toda la API) y el error imprime el valor de la última clave | `deploy-h5-backend-fase1.sh:19`, `h5-engine-scope.sh:22` |
| Baja | B-2 | El historial no verifica cada entrada: una fila insertada con DML (sin `APP_KEY`) aparece en el CRM como legítima | `StudioPublications.php:520-548`, `:654` |
| Baja (documentación) | B-3 | El riesgo R6 del plan dice que, tras rotar la `APP_KEY`, basta con «volver a publicar». No se puede: la única salida es Legacy, seguida de un ciclo completo nuevo | `plans/ULTRON_STUDIO_H5_PLAN.md:572` |
| Nota | N-1…N-10 | Clave corta aceptada, firma no atada al entorno, envenenamiento de `seq`, `UNIQUE` no reconocido al log, etiquetas en `publication-check`, limitador de `promote`, ventana 644 de la caché, scripts sin aserción, `canary` abre publicar, `lock_timeout` | §1 |

**Lo que está bien (comprobado, no supuesto; detalle en §3):**
- **Firma.** La derivación real coincide byte a byte con una derivación independiente con `openssl`. Compara en tiempo constante (`hash_equals`) en el motor y en el Studio. Sin `APP_KEY` (vacía, nula, solo el prefijo `base64:` o base64 inválido) lanza en vez de firmar con una clave vacía. Va sobre los bytes guardados (`text`, contenido solo ASCII, el mismo tope de 16 385 caracteres al leer en los dos lados) y ata `entry`/`seq` a la fila. El dominio está separado del `decide_token`.
- **Logs.** Todas las líneas de log nuevas llevan solo la clase del error. El texto del acta no sale del búfer propio de `Artisan::call` (comprobado en el código del framework).
- **Configuración.** La llave del motor, la puerta del laboratorio, los umbrales, la ventana y el carril del laboratorio fallan cerrado. El ejemplo de entorno del backend solo trae defectos. Ninguna llave nueva abre publicar sin las dos llaves, con la única excepción del análisis de M-1.
- **Base de datos.** Disparadores UPDATE/DELETE/TRUNCATE, los CHECK, índices parciales y `down()` que se niega con datos de H5, bajo cerrojo. El rol `iron_app` tiene `arwd` y `rwU`, que es exactamente lo que necesita el código.
- **CRM.** Ni `innerHTML` ni `bypassSecurityTrust`, nada nuevo en `localStorage`/`sessionStorage`. El 429 se lee del cuerpo y no de las cabeceras. Los botones salen de `actions.*.allowed` del servidor, y las huellas las recalcula el servidor.
- **Dependencias.** Ningún `composer.*` ni `package*.json` cambia en ninguna de las dos ramas.

---

## 1. Hallazgos

### M-1 (media) · `ULTRON_STUDIO_PUBLISHING_ENABLED` se abre con cualquier texto no booleano

**Hecho.** En `config/ultron_studio.php:63` está `'publishing_enabled' => (bool) env('ULTRON_STUDIO_PUBLISHING_ENABLED', false)`, y `StudioPublishing::flagEnabled()` (`:72-75`) vuelve a aplicar `(bool)`. El `env()` de Laravel solo convierte `true`/`false`/`(true)`/`(false)`/`null`/`empty`. Cualquier otro texto llega a `(bool)` como cadena no vacía, es decir, como `true`. La línea viene de F, pero hasta H5 no abría nada: `ENGINE_READS_VERSIONS` era `false` y publicar respondía 409 siempre. Desde el paso 3 es la llave real de publicar y promover.

**Evidencia.** `envparse.php`, con el `Illuminate\Support\Env` real del backend:

```
'true'     env()=true      (bool)=true   filter_var=true
'false'    env()=false     (bool)=false  filter_var=false
'0'        env()='0'       (bool)=false  filter_var=false
'no'       env()='no'      (bool)=true   filter_var=false
'off'      env()='off'     (bool)=true   filter_var=false
'disabled' env()='disabled'(bool)=true   filter_var=false
' false'   env()=' false'  (bool)=true   filter_var=false
```

**Cómo se explota (vía creíble, sin atacante externo).** Con el motor leyendo (fase 2 o 3), el propietario quiere congelar las publicaciones sin dejar de sellar y escribe a mano `ULTRON_STUDIO_PUBLISHING_ENABLED=off`. El valor «apagado» de la llave hermana `MARKETING_ULTRON_STUDIO_CONFIG` es precisamente `off`. Tras `config:cache`, `status.publishing.available` sale `true` y `POST versions/{uuid}/publish` supera el paso 2 (`keyRefusalFor()`, `StudioPublishing.php:202-220`). Un Super Admin con `ultron.publish` que no sea el autor puede publicar en canario.

**Impacto real.** Levanta una protección que el contrato llama intocable («publicar … nunca se levanta sin autorización expresa del propietario») con un valor que quien opera cree que la cierra. Contradice §A13 («Valor inválido: `false`») y el requisito del lead. Lo acota esto: en la opción A publicar no cambia lo que ULTRON dice ni hace, siguen las demás puertas (permiso, cuatro ojos, laboratorio, ventana, canario) y se deshace con revertir o con Legacy. Por eso es media y no alta. Los scripts no la provocan: siempre escriben `true`/`false` literales (`h5-engine-scope.sh:14-16`, `deploy-h5-backend-fase1.sh:22`).

**Corrección propuesta (sin aplicar):**
- `'publishing_enabled' => filter_var(env('ULTRON_STUDIO_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`. Da `true` solo con true/1/on/yes y `false` con off/no y basura, así que es compatible con los valores en uso.
- En `flagEnabled()`, `config('ultron_studio.publishing_enabled') === true`.
- Prueba negativa en `UltronStudioPublicationConfigTest` con `off`, `no`, `disabled` y `' false'`, que exija `flagEnabled() === false` y `status.publishing.code = publishing_disabled`. Hoy ninguna prueba cubre un valor inválido de esta llave: la única aparición es `=false`, en `:143`.
- Una línea en el ejemplo de entorno: «solo `true` o `false`».

**Dueños:** backend (B-S) y tester.

### M-2 (media, operativa; no es de seguridad) · `deploy-h5-backend-fase1.sh` no pasa de la línea 11

**Hecho.** El script combina `set -euo pipefail` (`:6`) con `MODIFICADOS=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')` (`:11`). `grep` sale con 1 cuando no selecciona ninguna línea, y eso es justo lo que pasa con el checkout limpio: salida vacía o solo líneas `??`. Con `pipefail` la tubería devuelve 1, y en bash una sustitución fallida dentro de una asignación termina el script por `set -e`, sin mensaje.

**Evidencia** (`pipefail-case.sh`: un proceso bash por caso, con `/usr/bin/grep`):

```
caso []:                       código de salida del script: 1   (no llega a la línea siguiente)
caso [?? docs/x.md]:           código de salida del script: 1
caso [ M backend/app/x.php]:   llegó a la línea siguiente: MODIFICADOS=1 → la línea 12 aborta «cambios locales»
printf '' | /usr/bin/grep -v '^??'; echo $?   → 1
```

El manual de GNU grep documenta el mismo código (1 = ninguna línea seleccionada). No lo pude ejecutar con GNU grep en local.

**Consecuencia.** Nunca se pasa de la línea 11: con el checkout limpio sale con 1 en silencio, y con cambios locales sale con 1 y mensaje. Falla de forma segura (antes del `merge`, el servidor queda como estaba), pero bloquea la fase 1 e invita a improvisar en el servidor, por ejemplo quitando la guarda. `deploy-h4-backend-fase1.sh:15` tiene la misma línea **después** del `git merge` (`:12`): si se reutiliza, aborta con el código ya fusionado y sin variables, migraciones ni cachés, es decir, a medias. El registro de la fase 1 de H4 (`ULTRON_STUDIO_FINAL_REPORT.md:331`) describe todos los pasos, así que probablemente no se ejecutó con esa versión. No lo sé.

**Corrección.** `MODIFICADOS=$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')`, sin `grep`, o bien `{ grep -v '^??' || true; }`. Ensayar el script entero con bash en Linux contra un directorio de prueba antes de la fase 1.

**Dueño:** devops.

### M-3 (media si se confirma; sin verificar) · root ejecuta `git` sobre un checkout de `www-data`

**Hecho.** `deploy-h5-backend-fase1.sh` se ejecuta como root (`:2-3`) y lanza `git fetch`, `git status` y `git merge` en `/var/www/api` (`:9-13`). Otros informes dicen que el árbol del backend es de `www-data`: `impresion-recibos/IRON_BODY_POS_FINAL_REPORT.md:538` («propietario `www-data` en los 27 archivos del backend») y `agenda-valoraciones/IRON_BODY_VALUATION_SCHEDULING_REPORT.md:564` («`chown www-data` de lo nuevo»).

**Hipótesis (no tengo acceso al servidor).** Si `/var/www/api/.git` (o `.git/config`, o `.git/hooks`) es escribible por `www-data`, quien consiga ejecutar código como `www-data` (cualquier RCE en la app o en una dependencia) puede dejar un `.git/hooks/post-merge`, o `core.fsmonitor`/`core.hooksPath` en `.git/config`, y el siguiente `git status` o `git merge` de root lo ejecuta como root. Desde la versión 2.35.2, git se niega por defecto («dubious ownership»). Que los scripts funcionen indica una de dos cosas: o `.git` es de root (seguro), o root tiene `safe.directory` para esa ruta (y entonces la protección está quitada).

**Cómo verificarlo** (en el servidor, solo lectura):
- `stat -c '%U:%G %a %n' /var/www/api/.git /var/www/api/.git/config /var/www/api/.git/hooks`
- `git config --global --get-all safe.directory`, como root
- `find /var/www/api/.git -user www-data | head`

En la misma revisión conviene mirar cómo lanza PHP `/usr/local/bin/ironbody-migrate`, que no está en el repo:
- como root, ejecuta código que `www-data` puede modificar (la misma clase de problema);
- como `www-data`, expone las credenciales del dueño a ese uid durante la migración (`/proc/<pid>/environ`).

**Corrección si se confirma.** `.git` de root y no escribible por `www-data`, sin `safe.directory`; o bien git como el dueño del árbol (`sudo -u www-data git …`), dejando root solo para lo que lo necesita (supervisor). No lo introduce H5 (H4 hace lo mismo), pero el script nuevo lo perpetúa.

**Dueño:** devops (verificar). Si resulta escribible, vuelve a security.

### B-1 (baja) · Añadir a un fichero de entorno sin salto final rompe la API entera y saca un valor

**Hecho.** `add_var` (`deploy-h5-backend-fase1.sh:19`) y `set_var` (`h5-engine-scope.sh:22`, cuando la clave falta) añaden con `printf '  %s=%s\n' >> "$ENVF"` sin comprobar que el fichero acaba en salto de línea.

**Evidencia** (fichero de prueba del scratchpad y el `Dotenv` real del backend). Si la última línea, `  LAST_KEY=valor`, no tiene salto final, `add_var MARKETING_ULTRON_STUDIO_CONFIG off` deja `  LAST_KEY=valor  MARKETING_ULTRON_STUDIO_CONFIG=off`, y el parseo falla con:

```
Dotenv\Exception\InvalidFileException: Failed to parse dotenv file. Encountered unexpected whitespace at [valor  MARKETING_ULTRON_STUDIO_CONFIG=off].
```

**Impacto.**
- `ironbody-migrate` regenera la caché de configuración y la limpia antes. Con un fichero que no parsea no queda caché y Laravel no arranca: TODA la API (no solo el Studio) responde 500 hasta que alguien repare el fichero.
- El mensaje de error incluye el VALOR de la última línea, y el script lo imprime (`:30`, `2>&1 | sed`, y el tinker de `:41`). Si esa línea es un secreto, acaba en la terminal y en el registro del despliegue.

**Condición.** El fichero tiene que haberse quedado sin salto final. Es improbable si el último en tocarlo fue el `add_var` de H4, que siempre termina en `\n`, y plausible tras una edición a mano con un editor que lo quite.

**Corrección.**
- En los dos scripts, antes del primer añadido: `[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"`.
- Validar que el fichero parsea ANTES de tocar las cachés, sin imprimir el mensaje: `sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }'`.
- Respaldo del fichero (root, 600) antes de editarlo, con su vuelta atrás escrita.

**Dueño:** devops.

### B-2 (baja) · El historial enseña entradas sin verificar

**Hecho.** `GET publications` (`StudioPublications::page()`, `:520-528`) y el detalle de la versión (`forVersion()`, `:536-548`) devuelven `entryDto()` (`:654`) de cada fila sin comprobar su huella ni su firma. Solo se verifica la cabeza (`state()`, `:602-608`).

**Cómo se explota.** Justo el atacante del que la firma protege por contrato (§A4.1: «una escritura cruda en la base … por quien no tenga la `APP_KEY`», rol `iron_app` con `arwd`) puede insertar una fila en un `seq` libre por debajo de la cabeza. Sirve `seq = 0` o un negativo: en PG `seq` es `integer`, sin CHECK `>= 1`. Puede poner `action = 'publish'`, el nombre y el rol que quiera (`actor_name = 'Propietario'`) y un motivo. El motor no la lee, porque no es la cabeza, y el Studio no compone encima. Pero el CRM la enseña en el historial como una entrada más, sin ninguna marca. La puerta de la evidencia (`since`, `:1064-1069`) y la de la restauración (`:1128-1134`) no se dejan engañar: toman el `seq` más alto, y la restauración además verifica la entrada.

**Impacto.** Falsifica el historial que se enseña, no lo que lee el motor ni lo que compone el Studio. Exige DML sobre la base de producción. El libro de auditoría no tendría el evento correspondiente: se puede detectar, pero nadie lo mira de forma automática.

**Corrección propuesta.** Un `verified: bool` por entrada en `PublicationEntryDto` (el mismo `verify()` que la cabeza, como mucho 50 HMAC por página), y que el CRM marque las que no verifican. Opcionalmente, `CHECK (seq >= 1)` en una migración aditiva posterior. Toca §A7 (el DTO), así que es una decisión del lead o del architect.

### B-3 (baja, documentación) · R6 del plan: «volver a publicar» tras rotar la `APP_KEY` no es posible

**Hecho.** En `ULTRON_STUDIO_H5_PLAN.md:572`, el procedimiento de R6 dice: «volver a publicar (entrada nueva firmada con la clave nueva)». El código no lo permite:
- Tras rotar, la cabeza no verifica, y publicar, promover y revertir componen sobre ella, así que responden 409 `publication_head_invalid` (`StudioPublications.php:160`, `:269`, `:357` → `verifiedParts()`, `:1194-1201`).
- Solo Legacy escribe (`:421-484`; `assertDeclaring()`, `:1229-1238`).
- Después de Legacy, la versión que estaba en CANARY queda `ROLLED_BACK` (terminal) y la `PUBLISHED`, `RETIRED`, que solo vuelve con una reversión que la restaure, y esa reversión ya no existe.

Volver al estado anterior exige una versión nueva con su ciclo completo: aprobación de otra persona, laboratorio, ventana, canario de al menos 24 h y 3 turnos sellados, y promoción. Con unos 3 turnos de ULTRON por semana, son días. El informe del implementador lo dice bien («La salida es Legacy», `informe-api.md:1075`); el plan, no.

**Corrección.** Corregir R6 y llevar el §2 de este informe al runbook (`ULTRON_STUDIO_ROLLBACK.md` / `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`).

**Dueños:** documentation-specialist y lead.

### Notas

- **N-1 · Clave muy corta aceptada.** `subkey()` (`UltronPublishedConfig.php:462-473`) acepta cualquier clave no vacía: `base64:QQ==` (1 byte) firma (`firma.php`: «base64 de 1 byte firma=27f8ac97…»). El cifrador de Laravel exige 32 bytes para AES-256; aquí no hay mínimo. Hoy no tiene impacto real, porque la clave de producción sale de `key:generate`. Para un contrato futuro: fallar cerrado con menos de 32 bytes decodificados. Es del architect y no cambia nada en H5.
- **N-2 · La firma no ata el entorno.** Ata `entry` y `seq` a la fila (`UltronPublishedConfig.php:349-354`; `StudioPublicationDeclaration::parts()`, `:565`), pero no la instalación. Con la misma `APP_KEY` en dos entornos, una entrada firmada en uno vale en el otro si se inserta con DML en `seq = cabeza + 1`. El E2E genera su propia clave (`run.sh:195`), así que eso está bien. Recomendación: una `APP_KEY` distinta por entorno (se compara sin imprimirla, con el `sha256` de la clave decodificada) y, para `/2`, un identificador de instalación dentro del texto firmado.
- **N-3 · Envenenamiento de `seq` con DML.** Una fila insertada con `seq = 2147483647` (en PG, `unsignedInteger` es `integer`, `create_…:84`) se convierte en la cabeza y no verifica.
  - Legacy calcula `seq + 1` (`StudioPublications.php:1264`) y falla con 22003, es decir, un 500. El gobierno se queda sin salida desde el Studio.
  - El motor sella `fallback/signature_invalid` hasta que su llave pase a `off`.
  - Los disparadores impiden borrar la fila con `iron_app`.

  Exige el mismo DML que B-2. Para el runbook: la salida inmediata es `h5-engine-scope.sh off`; la reparación, solo con el rol dueño y con autorización del propietario, porque toca el libro. No propongo cambio de código en H5.
- **N-4 · Un `UNIQUE` no reconocido acaba en el log con sus valores.** `conflictRefusal()` lo relanza tal cual (`StudioPublications.php:1367`). Nadie lo atrapa, así que el manejador de Laravel lo registra en `laravel.log` con el SQL y sus valores: `reason` (texto libre del personal, riesgo B-1 de la Ley 1581), `actor_name`, `effective_json` y `signature`. Ninguno es un secreto: la salida de un HMAC no revela la clave. En la práctica es inalcanzable (`uuid` v4; `client_request_id` pasa antes por `replay()`), y es el comportamiento general de la app. Solo si el lead quiere coherencia con el resto de H5: un 409, o `report()` con solo la clase.
- **N-5 · Etiquetas con forma de marcado en `publication-check`.** El comando imprime con `$this->line()` (`PublicationCheckCommand.php:49`, `:61`), que pasa por el formateador de Symfony. Una etiqueta como `<href=…>ver</>` o `<error>` desaparece del JSON cuando la salida no está decorada, y cuando lo está se convierte en secuencias ANSI u OSC 8 (un enlace pulsable). Evidencia de `formatter.php`:

  ```
  sin decorar: {"label":"Estable ver !"}
  decorada:    {"label":"Estable \u001b]8;;https://ejemplo.invalid/x\u001b\\ver\u001b]8;;…"}
  ```

  `PlainText` (`UltronStudioVersionController.php:82`) impide caracteres de control, no etiquetas `<…>`. Impacto: alguien de dentro con permiso de edición altera lo que lee quien opera; el JSON sigue siendo válido. Corrección: `writeln($json, OutputInterface::OUTPUT_RAW)` en `--json` y `OutputFormatter::escape()` sobre las etiquetas en modo texto.
- **N-6 · `promote` ejecuta el acta con otro limitador.** `POST versions/{uuid}/promote` calcula la evidencia, acta incluida (`StudioPublications.php:257`), bajo el limitador de escritura (`routes/ultron-studio.php:122`, grupo `:100`: 30/min por llave, `AppServiceProvider.php:610-613`) y no bajo el de la evidencia (10/min por persona, `:649`, ruta `:96`). Solo es posible con `ultron.publish`, las dos llaves, el motor en `all` y una versión en CANARY. Se lo paso a `api-security-specialist` (consumo de recursos por endpoint); no está roto.
- **N-7 · Ventana de lectura de la caché de configuración.** En `h5-engine-scope.sh:28-29`, `config:cache` escribe `config.php`, con todos los secretos, con la umask de `www-data` (normalmente 644), y el `chmod 600` llega después. Durante milisegundos, otro usuario local podría leerlo. Corrección: `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'`. No pude ver si `ironbody-migrate` sigue el mismo patrón.
- **N-8 · Los scripts imprimen la configuración efectiva, pero no la comprueban.**
  - `add_var` no sobrescribe una clave que ya existe. Si `ULTRON_STUDIO_PUBLISHING_ENABLED=true` ya estaba, la fase 1 quedaría «todo apagado» solo de nombre.
  - Una línea `CLAVE = valor` con espacios escapa al `^\s*CLAVE=` de `grep` y `sed`. La línea nueva va al final, y en Dotenv (inmutable) gana la primera.
  - «FASE 1 (H5) COMPLETADA» se imprime igualmente.

  Corrección: comparar el JSON de tinker con lo esperado y salir con 1 si difiere. En la fase 1, `engine_scope=off` y `publishing_enabled=false`; en `h5-engine-scope.sh`, los dos valores escritos.
- **N-9 · `h5-engine-scope.sh canary` abre también publicar** (`:15`). Si el propietario autoriza solo que el motor selle, sin publicaciones nuevas, el script no sirve tal cual. La defensa que importa está en el código: con el motor en `off`, publicar y promover responden 409 aunque la llave del Studio esté abierta (`keyRefusalFor()`, `StudioPublishing.php:202-220`). El script no puede dejar `publicar=true` con `motor=off` (el `case` con lista blanca de `:13-18` lo impide), y sus valores no tienen metacaracteres para `sed`.
- **N-10 (no verificado) · `lock_timeout`.** Viaja como `PGOPTIONS` a través de `sudo env … ironbody-migrate` (`:30`). Solo llega a la migración si el envoltorio conserva el entorno hasta `php`; un `sudo -u` interno con `env_reset` lo perdería. El registro de H4 dice 5 s, y el README dice que el envoltorio ya lo aplica. Si es así, la variable sobra.

---

## 2. Procedimiento de rotación de la `APP_KEY` con la cadena viva (para el runbook)

**Qué rompe una rotación, leído en el código:**

- **La cadena.**
  - Ninguna entrada firmada con la clave vieja vuelve a verificar.
  - El motor (con su llave distinta de `off`) sella `fallback/signature_invalid` en cada turno; en la opción A no cambia nada más.
  - En el Studio, publicar, promover y revertir responden 409 `publication_head_invalid`, y la evidencia da `signatureOk: false`.
  - `ultron-studio:publication-check` sale con 1 (`head_invalid`): esa es la alarma.
- **Fuera de H5** (`grep` de `config('app.key')`):
  - `UltronDecideToken.php:145`, el `decide_token`, que dura 120 s (`:27`). Un turno en curso durante la rotación falla con `decide_token_bad_signature` (`:96-98`) y queda como turno mudo; en la conversación canaria, el vigía echa el freno. Esto es una hipótesis por lectura de código; no lo ejecuté.
  - Otras HMAC con la clave: `Member.php:97` (`access_hash`), `Support/Workforce/Secrets.php:58` (códigos y tokens del kiosco, guardados como HMAC), `Support/Moderation/AuditSanitizer.php:124`, `MemberRegistrationController.php:453` y `MetaAdEntity.php:84`.
  - Cifrado con la clave: `TrainerFaceReference` (`template`), las sesiones y las cookies. Laravel descifra con `app.previous_keys` (`config/app.php:112`); las HMAC anteriores no lo usan.
  - **Rotar la `APP_KEY` es un evento de todo el sistema. H5 añade una línea a la lista; no justifica una rotación por sí solo.**

**Procedimiento** (cuando el propietario autorice una rotación por otro motivo, por ejemplo una sospecha de fuga):

0. **Condiciones.**
   - Autorización expresa del propietario.
   - Una ventana sin tráfico de ULTRON y ningún turno en curso: al menos 120 s desde el último `decide`. Mejor aún, con ULTRON en pausa por quien la gobierna (el Studio no toca la pausa).
   - Respaldo del fichero de entorno y de `bootstrap/cache/config.php`, solo root, con modo 600.
1. **Foto.** `sudo -u www-data php artisan ultron-studio:publication-check --json > /root/pre-rotacion-publication.json`. No lleva secretos ni ids de conversación. Anotar `seq`, la acción y la versión de cada parte.
2. **Decisión, si hay una versión en CANARY.** Su evidencia cuenta los turnos desde su publicación y exige `stamped = turns`. Un turno sellado `fallback` durante la rotación se queda para siempre en su ventana, y ese canario ya no podrá promoverse. Hay que elegir antes:
   - (a) motor en `off` (`h5-engine-scope.sh off`, que también cierra publicar), para no sellar nada durante la transición;
   - (b) aceptar que ese canario se descarta.
3. **Rotar.**
   - Generar la clave nueva sin que pase por la terminal ni por el historial: `sudo -u www-data php artisan key:generate --force` la escribe en el fichero. No usar `--show`.
   - Poner la clave vieja en `APP_PREVIOUS_KEYS`, para que sesiones, cookies y columnas cifradas se sigan descifrando.
   - `config:cache` como `www-data`, con `umask 077`, y después `chmod 600`.
   - `queue:restart` y `supervisorctl restart 'ironbody-lab:*'`.
4. **Comprobar la alarma.** `publication-check` sale con 1 y `head_invalid`: es lo esperado. `status.engine.publication.state` es `fallback` si el motor lee.
5. **Salida.** `POST /api/admin/ultron-studio/publications/legacy`, con el permiso `ultron.rollback` y el motivo «Rotación de la APP_KEY (fecha, autorizada por …)». Es la única escritura que acepta una cabeza que no verifica, y firma `{canary: null, rest: null}` con la clave nueva. La versión en CANARY pasa a `ROLLED_BACK` y la `PUBLISHED`, a `RETIRED`.
6. **Comprobar.** `publication-check` sale con 0, con `state: legacy` (u `off`) y `head.signatureOk: true`.
7. **Si en el paso 2 se puso el motor en `off`:** volver al alcance anterior con `h5-engine-scope.sh canary|all`, y solo con la autorización del propietario para esa fase.
8. **Recuperar la declaración anterior:** versión nueva con el mismo contenido → aprobación de otra persona → laboratorio → publicar (dentro de la ventana) → canario (al menos 24 h y 3 turnos sellados) → promover. Las versiones `ROLLED_BACK` y `RETIRED` no vuelven.
9. **Historial.** Las entradas viejas se siguen listando, pero ya no verifican, y ninguna sirve como origen de una restauración (`restore_integrity_mismatch`, `StudioPublications.php:1126-1153`). Dejarlo escrito en el acta de la rotación.

**Para el architect (no es de H5).** Un contrato `/2` podría verificar también con `app.previous_keys` y llevar un identificador o huella de la clave dentro del texto firmado. Otra opción es una acción explícita de «re-anclaje», bajo cuatro ojos, que vuelva a firmar la cabeza. Las dos tocan §A4.1 (una sola implementación, en el motor) y el CHECK de `action`.

---

## 3. Lo comprobado (comando y resultado)

| # | Comprobación | Comando o lugar | Resultado |
|---|---|---|---|
| 1 | Dependencias | `git diff --name-only 0366009..61048ff \| grep -iE 'composer\.(json\|lock)\|package(-lock)?\.json'`; en el CRM, `5c1dbd7..24a691d` con `package(-lock)?\.json\|yarn\.lock\|pnpm-lock` | Sin salida (exit 1) en los dos repos |
| 2 | Secretos en los diffs | regex de `sk-…`, `AKIA…`, `BEGIN … PRIVATE KEY`, `xox?-`, `EAA…`, `ghp_…`, `base64:` de 32 bytes sobre las líneas añadidas | Backend: solo las claves de prueba `base64:AAAA…A=` (84) y `base64:AAECAwQF…Hh8=` (4), en tests y fixture. CRM: nada. Claves del fixture: ceros, `0x00..0x1f` y `ultron-studio-raw-key-0123456789` |
| 3 | Firma, derivación independiente | `firma.php` (`signatureFor()` real con un contenedor mínimo) frente a `openssl dgst -sha256 -mac HMAC` | Iguales: `8bfeb35d…28da`. La subclave `3059c3cd…71c3` es la `subkeyHex` del fixture |
| 4 | Claves límite | el mismo `firma.php` | `''`, `null`, `base64:` y `base64:!!!!` → `RuntimeException`, sin firmar; `base64:QQ==` firma (N-1). En el motor, la excepción acaba en `fallback/read_failed` (`:164-170`); en el Studio, 500 sin escribir nada |
| 5 | Tiempo constante | lectura | `hash_equals` en `UltronPublishedConfig.php:302`, `:311` y en `StudioPublications.php:1148`, `:1216-1217` |
| 6 | Bytes exactos | lectura | Columna `text` (`create_…:99`). Valores solo ASCII por las aserciones del Studio (`StudioPublicationDeclaration.php:133-138`, `:851-901`), así que caracteres = bytes. Mismo tope `substr(…, 1, 16385)` en el motor (`:264`) y en el Studio (`:1185`); `mb_strlen ≤ 16384` al escribir (`:496`) |
| 7 | Dominio | lectura | Subclave = HMAC(`APP_KEY` decodificada, etiqueta). El `decide_token` usa HMAC con la cadena cruda de `config('app.key')`, con el prefijo `base64:` (`UltronDecideToken.php:145`): claves distintas, más el prefijo `ultron-engine-config/1\n` y `contract` dentro del JSON |
| 8 | Logs | `grep` de `report(\|ChannelLog::exception\|Log::…\|logger(\|getMessage()` en los 10 ficheros nuevos o tocados | Cinco escrituras, todas con `error_class` (y `env`, `reason` o `reading`). `getMessage()` solo para clasificar (`UltronPublishedConfig.php:499`, `StudioPublications.php:1353`). `ChannelLog` pasa por `LogRedactor::scrub` |
| 9 | Acta en memoria | `StudioCanaryMeasures.php:165-191`; framework: `Console/Application.php:166` (`lastOutput = $outputBuffer ?: new BufferedOutput`) y `:76` (`setCatchExceptions(false)`); `BufferedOutput::fetch()` (`:24-30`) vacía el búfer | `Artisan::output()` posterior devuelve `''`. `UltronCanaryReport` no escribe logs, ficheros ni caché (`grep` de `Log::\|ChannelLog\|Storage::\|file_put_contents\|Cache::\|report(` sin resultados). No hay escuchas de `CommandFinished` ni Telescope, Sentry o Debugbar en `composer.json` |
| 10 | Configuración | lectura y `envparse.php` | Llave del motor (`config/marketing.php:102`, `StudioPublishing::engineScope()`): todo lo que no es `canary` ni `all` → `off`. Laboratorio: todo lo que no es `waived` → `required`. Umbrales recortados a 1–168 y 1–100. Ventana mal escrita → la del contrato. Carril del laboratorio, 60–3600. La llave de publicar: M-1 |
| 11 | Ejemplo de entorno | `git diff 0366009..HEAD` del ejemplo del backend | Solo defectos (`off`, `false`, `required`, `24`, `3`, `07:00-19:00`, `300`), sin valores reales |
| 12 | Scripts | lectura y las reproducciones de M-2 y B-1 | Ninguno imprime valores del fichero de entorno: solo nombres de clave, dueño y modo, el JSON de tinker con escalares no secretos, y `curl` sin token con solo el código HTTP. `h5-engine-scope.sh` usa lista blanca, `sed` anclado que conserva la sangría y valores sin metacaracteres. Orden de la fase 1: variables → `ironbody-migrate` → `route:cache` → `queue:restart` ✓ |
| 13 | Base de datos | las dos migraciones `2026_10_25_1000*` | Disparadores UPDATE/DELETE (los dos motores) y TRUNCATE (PG) sobre la cadena. CHECK de `action`, `engine_contract` y `reason`. `usv_status_chk` ampliado en un único `ALTER`. Índices únicos parciales. `down()` se niega con filas de H5 bajo ACCESS EXCLUSIVE. Literales solo de constantes. Privilegios según la documentación previa: `iron_app` con tablas `arwd`, secuencias `rwU` y sin `CREATE` en `public` (`ULTRON_STUDIO_SECURITY_REPORT.md:230`, `ULTRON_STUDIO_DEPLOYMENT_PLAN.md:50`). Basta para el código, y los disparadores cubren UPDATE y DELETE |
| 14 | CRM | `grep` de `innerHTML\|bypassSecurityTrust\|outerHTML\|insertAdjacentHTML\|DomSanitizer\|eval(\|new Function\|document.write` en `src/app/modules/ultron-studio` (sin specs) | 0 apariciones. Almacenamiento nuevo: solo `localStorage.clear()` en dos specs; `getItem`/`removeItem` de `studio-session.ts:576-582` vienen de F. El 429 lee `retry_after` del cuerpo (`studio-api.service.ts:89-94`). Botones según `actions.*.allowed` del servidor (`studio-versions-panel.ts:167-187`). `publication_head_invalid` conocido (`studio-api.types.ts:966`, `:1013`; `studio-publication-text.ts:253`). `recuentos()` rechaza controles en las claves (`studio-parse.ts:476`). `previewHash`, `evidenceHash` y `acknowledgedNoEffect` los recalcula el servidor (`StudioPublications.php:147-151`, `:257-263`): el cliente solo puede provocar un 409. El FE informa en verde el spec de texto hostil y la mutación M13 (`informe-front-integracion.md:232`, `:274`); no lo relancé |
| 15 | Arnés E2E | lectura | Secretos aleatorios en cada ejecución (`run.sh:157-166`, `openssl rand` y `umask 077`), clave propia (`run.sh:195`), fichero de entorno 600 (`make-env.mjs:64`); se niega con una API que no es local o con `APP_ENV=production` (`accounts.mjs:40-44`). Ningún secreto en el repo |

Los scripts de prueba (`envparse.php`, `pipefail-case.sh`, `dotenv.php`, `firma.php` y `formatter.php`) están en el scratchpad de la sesión, que se purga a los tres días. Las salidas relevantes van copiadas arriba.

## 4. Lo que no pude verificar

- **Servidor.**
  - El dueño y los permisos de `/var/www/api/.git` y la configuración de `safe.directory` (M-3).
  - El contenido de `/usr/local/bin/ironbody-migrate`: cómo lanza PHP, si aplica `lock_timeout` y con qué umask escribe la caché (M-3, N-7, N-10).
  - Los privilegios reales de `iron_app` sobre la tabla nueva, que solo existirán tras la fase 1. Se comprueba con `SELECT has_table_privilege('iron_app','ultron_studio_publications','INSERT'), has_table_privilege('iron_app','ultron_studio_publications','TRUNCATE'), (SELECT tableowner FROM pg_tables WHERE tablename='ultron_studio_publications');` y lo esperado es `t`, `f`, `iron`. Conviene añadirlo al paso 4 del script.
  - El dueño y el modo del fichero de entorno y de `bootstrap/cache`, y si el fichero acaba en salto de línea (B-1).
  - Si la `APP_KEY` de producción es distinta de la de otros entornos (N-2).
- **GNU grep:** no lo ejecuté en local. M-2 se apoya en su código de salida documentado y en la reproducción con el grep BSD.
- **PHPUnit, E2E y specs del CRM:** no los ejecuté yo.
- **El efecto de una rotación** sobre un `decide_token` en curso y sobre el freno: lo deduje leyendo el código; no lo ejecuté.

## 5. Para cada agente

- **backend (B-S):** M-1 (la línea de `config/ultron_studio.php` y `flagEnabled()`). N-5 y N-4 son opcionales.
- **tester:**
  - casos negativos de M-1 (`off`, `no`, `disabled`, `' false'`), con la llave del motor en `canary`: 409 `publishing_disabled`;
  - si se acepta B-2, una fila insertada con `seq = 0` tiene que salir `verified: false`;
  - ensayo con bash en Linux de los dos scripts de la fase 1 (M-2, B-1).
- **devops:** M-2, M-3 (verificar), B-1, N-7, N-8 y N-10, y la consulta de privilegios del §4 en el paso 4 del script.
- **architect:** B-2 (el DTO), N-1, N-2 y el diseño de rotación de `/2`.
- **documentation-specialist / lead:** B-3 y el §2 al runbook.
- **api-security-specialist:** N-6.
