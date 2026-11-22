# Revisión de `deploy-cierre-lote.sh` para el lote 2 (reviewer, 22-11-2026)

Árbol `_worktrees/lote-2` · rama `lote/2` · `b952b220` · guion de 963 líneas. Solo lectura: sin producción, sin red, sin banco y sin PostgreSQL (no hay nada que borrar). **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto
**APROBADO CON CONDICIONES** para ejecutarlo en producción con el lote 2: **0 bloqueantes**, 3 importantes que son condiciones de ejecución (C1–C3) y 9 menores.
Ningún punto donde el guion pueda morir deja producción en un estado incompatible para ESTE lote: el código aguanta todos los intermedios [L] (esquema ancho con código previo; código nuevo con la caché de configuración previa; la app sin caché de configuración).

## Verificado (lo que ejecuté)
- `bash -n deploy-cierre-lote.sh` → OK con el bash 3.2 del Mac [C]. `shellcheck` no está instalado y Docker no tiene demonio: lo leí con ese ojo.
- `git diff --name-status --no-renames 45b53023 b952b220` [C]: 70 ficheros, 43 commits, avance rápido; 25 fuera de docs/tests (lista en P9); ni composer, ni vendor, ni bootstrap, ni otro fichero de `database/`; una sola migración, añadida (`A`); ningún commit `outreach(sends-open):` ni `voice(calls-open):`.
- Las dos constantes, con la regla del guion: `= false;` 1 vez y `= true` 0 veces, en `45b53023` y en `b952b220` [C]. Migraciones en el árbol: 349 → 350 [C].
- Copia versionada de `ironbody-migrate`: sha256 `8ecb14798c0c260e…` en P y en N; según la memoria, es la instalada [C/L].
- vendor de Laravel [C]: `migrate:status --pending` imprime «No pending migrations» (StatusCommand:82); con `--path` solo se usan esas rutas (BaseCommand:20–25); el Migrator registra la fila en `migrations` FUERA de la transacción (Migrator.php:253 → 258); `config:cache` escribe con `files->put` (el modo depende del umask); `schedule:list --json` da `expression`, `command` y `description`.
- Trampa EXIT con `exec > >(tee)` en un arnés mínimo con bash 3.2 [C]: con SIGINT al grupo, «SALIDA INESPERADA…» sale en el terminal y en el registro; con **SIGHUP (corte de ssh) no sale en ninguno**: `tee` muere y el `echo` de la trampa muere por SIGPIPE (rc −13, no 5).

## Bloqueantes
Ninguno.

## Importantes (condiciones para ejecutarlo)
**C1 · l. 953–957 — un `production.ERROR` ajeno dispara la vuelta atrás automática completa.**
- Las referencias lo trataban como desvío (código 3): `deploy-a4` l. 606–608 y `hotfix-backend` l. 98–102; además, a4 solo volvía atrás solo antes de reiniciar los workers. En producción hay ruido: la evidencia del a4 (`a4-evidencia/155`) dice «production.ERROR en el log de hoy: 3250 · en los últimos 10 minutos: 0».
- Escenario: un webhook o un trabajo ajeno escribe un ERROR entre el paso 0 y la l. 954 (unos 2–3 min, con los 90 + 15 + 16 s de los workers). Resultado, código 4: cachés del respaldo, `checkout P`, otra vez el CHECK estrecho y un segundo `queue:restart`.
- **No es peligroso para el lote 2**, pero el despliegue se pierde: las banderas están apagadas, el código previo admite el CHECK ancho, y la vuelta del CHECK va en una transacción con lock_timeout (si hay filas `classifier:*`, la base se niega y el CHECK se conserva).
- Condición: medir el ritmo de ERROR de los 10–15 min previos y lanzarlo en un rato tranquilo; si sale 4 y el único motivo es el ERROR, leer esas líneas antes de repetir. Corrección sugerida: contar solo desde el merge, o rebajarlo a aviso cuando la traza no toca ficheros del diff. → **devops**

**C2 · l. 540–551 + 764 — un corte de ssh deja el guion a medias sin la línea PARAR [C].**
- Con SIGHUP, la trampa no llega ni al terminal ni a `registro.log`. Si el corte cae dentro de `ironbody-migrate`, la caché de configuración queda **ausente**: la app sigue funcionando porque lee el entorno, pero relanzar el guion ABORTA en la l. 681, y el §6 de la ficha no lo dice. Los estados intermedios son compatibles para el lote 2, pero no queda aviso.
- Condición: lanzarlo dentro de `tmux` o `screen`, o con `setsid nohup … > fichero` siguiendo ese fichero. Si aun así se corta: `git rev-parse HEAD`, la cola de `registro.log` y `--vuelta-atras=<respaldo>`, que repone la caché del respaldo. → **lead** (operación) y **devops** (§6)

**C3 · l. 433 y 522–523 — la vuelta atrás MANUAL repone byte a byte la caché de configuración del respaldo.**
- Para la automática, unos segundos después, es lo correcto. Para una manual horas después es peligroso si el `.env` cambió entretanto (se rotó la contraseña de `iron_app` o el token de Meta, o se movió una bandera): se reponen los valores viejos. Con la contraseña de la base: 500 sostenido en FPM y BACKOFF de los 14 workers en el siguiente arranque.
- Las referencias no lo hacían: a4 no tocaba la configuración (solo avisaba, l. 345) y H9 la regeneraba con `config:cache`.
- Condición: usar la vuelta manual solo si `stat -c %Y /var/www/api/backend/.env` es anterior a la hora del respaldo (solo `stat`, sin leer el fichero); si no, después de la vuelta, `config:cache` como www-data con umask 077, aceptando que la l. 522 marque desvío. Corrección: en modo manual, regenerar la caché si el `.env` es más nuevo que el respaldo. → **devops**

## Menores
- **m1 · l. 932.** `artisan list --raw | grep -q` con `pipefail`: el SIGPIPE de `grep -q` se lee como «no está» y `auth:coverage` se salta en silencio (falso verde). Es la trampa que ya documenta `hotfix-backend.sh` l. 46. Solo lote 4; arreglo: `grep -qx … <<< "$(artisan list --raw)"`. → devops, antes del lote 4
- **m2 · l. 174.** `\>` dentro de `[[ =~ ]]`, **a confirmar** con bash 5 + glibc: si llega a regcomp como `\>` (fin de palabra en GNU), ninguna marca «desde>hasta» casa y el lote 3 ABORTA en el paso 0 (dirección segura). Arreglo: `[>]`; lo dirá el `--solo-comprobar` del lote 3. → devops
- **m3 · l. 727.** Si faltara `iron-ia/ops/ironbody-migrate` en N, `H_VER=$(g show…|sha256sum|cut)` muere por `pipefail` + `set -e` sin línea ABORTA (exit 128; es seguro). En `b952b220` está [C].
- **m4 · `ironbody-migrate` l. 36–37.** Su `config:cache` corre con el umask de sudo (022) y root hace el `chmod 600` después: un instante con la caché de secretos en 644, y el chmod de root sigue un enlace (H-5). Es anterior al guion, que podría cerrarlo con `( umask 077; "$MIGRADOR" … )` (sudo aplica la unión con su 022). → **security**
- **m5 · l. 591.** `git fetch` hereda el umask del shell, como en a4 y hotfix (H9 fija `umask 022` arriba). Comprobar antes que `umask` da 0022.
- **m6 · l. 232–243.** El recuento de ERROR es ciego si no hay `laravel*.log`, o si logrotate trunca o rota en la ventana (n > líneas → 0). No lanzarlo a la hora de cron.daily.
- **m7 · l. 684.** OPcache con `grep -r` en `/etc/php/*/fpm/`: no sigue los enlaces de `conf.d` ni ve el `php_admin_value` de los pools (igual que a4). El lote 2 no tiene sonda HTTP que delate un FPM sin revalidar (a4 tenía `ai/check`); el a4 ya demostró que revalida [L].
- **m8 · l. 252.** `legibles` falla si ningún fichero del diff existe en P (todos nuevos) y `va_codigo` acaba en PARAR. No aplica al lote 2, que tiene ficheros modificados.
- **m9 · l. 890.** `REIN < 14` solo da aviso (igual que a4): los workers que no reiniciaron siguen con el código viejo y su fallo de arranque saldría más tarde (la trampa del retardo). Si aparece, mirar `supervisorctl status` hasta ver los 14 con pid nuevo.

## Respuestas a las nueve preguntas
**P1 · ¿Puede dejar producción peor?** No, para el lote 2. Estado al morir en cada punto:

| Dónde muere | Estado del servidor | Salida |
|---|---|---|
| Previas / respaldo | intacto (solo el fetch; `aborta` o la trampa) | 1 |
| M: falla la copia, la migración o 55P03 ×3 | caché repuesta (l. 832–833) | vuelta automática, 4 |
| M: corte dentro del envoltorio | PG deshace la transacción sola (lock_timeout acota la espera huérfana [S]); caché quizá ausente; sin aviso (C2) | — |
| Entre M y C | esquema ancho + código P + caché P (la del envoltorio): compatible | relanzar reanuda («ya registrada», l. 700–702) |
| C: el merge falla limpio / a medias con árbol sucio | se deshace el CHECK / la vuelta se niega | 4 / PARAR 5 (§6) |
| Entre C y K | código N + caché P: compatible [L]; `OutreachConfig` y el transporte leen las claves ausentes como apagado (`self::int` con defecto, `micros(null)=0`, `trim((string) null)`) | — |
| K, `queue:restart`, posteriores / vuelta incompleta | vuelta / — | 4 / 5 |

Caso raro: el Migrator confirma el ALTER y muere antes de registrar la fila → «ni antes ni después» y PARAR, que es lo correcto. ¿La vuelta automática empeora algo? No: la caché previa con el esquema ancho es compatible, y si hay filas que violan el CHECK estrecho la base lo rechaza y el ancho se queda (l. 479–482, ensayado [L]). Lo único que puede empeorar es la vuelta manual tardía (C3).

**P2 · ¿Las previas abortan sin tocar nada?** Sí [L]. Antes de la l. 749 solo hay lecturas y el `git fetch`: `git`, `psql SELECT`, `stat`, `artisan migrate:status|route:list|schedule:list`, un `php -r` que incluye la caché y un GET. `cache_ok` (que puede hacer `chmod`) no se usa en las previas. Falsos verdes: el `grep -c … || true` de los workers lo cubre R0 = 14; BLOQ, LARGAS, OPC y FACT son solo informativos; `PEND_N` frente a lo declarado y el esquema «antes» abortan de verdad. Matices: m3, m6 y m7.

**P3 · ¿`--solo-comprobar` es de solo lectura?** Sí, salvo el fetch [L]: sale en las l. 749–753, antes de crear el respaldo. sudo deja su línea en `auth.log`, sin secretos.

**P4 · Secretos.** Nada imprime la caché ni el entorno; `banderas` solo da formas (`cadena`, `lista:N`, números de ≤ 6 cifras). No hay `set -x` ni `echo` de valores, y los `eval` escapan el valor. Respaldo en 700/600 de root con la comprobación `MALOS` (l. 792); `migracion-*.log` y `esquema/*.sql` en 600; `vuelta-*.sql` (sin secretos) sale con el umask heredado, pero dentro del directorio 700. Las líneas filtradas del envoltorio (SQLSTATE/Exception) llevan el DDL y el usuario, no contraseñas [S]; la salida entera queda en 600. Única ventana: m4, del envoltorio.

**P5 · Migración.**
- Se ejecuta exactamente la declarada: copiada sola en `/var/lib/ironbody-deploy/cierre-lote-<TS>/0-…/` (root, 755/644), con el hash comparado con git justo antes (l. 817–819), y lanzada con `--path=<ese dir> --realpath`, sin que Laravel añada otras rutas [C]; además, el paso 0 exige que las pendientes sean las declaradas.
- Cerrojo acotado **dos veces** [L]: el `PGOPTIONS=-c lock_timeout=5s` del envoltorio y `boundLockWait()` de la propia migración (`SET LOCAL lock_timeout '5s'` dentro de su transacción). Son ≤ 5 s por intento, 3 intentos con 15 s entre ellos; mientras espera, quien llegue detrás a `outreach_budget_counters` también espera (con los envíos cerrados, apenas nadie [S]).
- Caché de configuración: se repone en todos los fallos tratados; entre reintentos 55P03 queda ausente adrede (hasta unos 45 s, no los «1–3 s» que dice la ficha); no se repone si se mata el proceso (C2).

**P6 · Permisos y dueño.** Igual que los guiones probados: `merge` y `checkout --detach` en subshell con umask 022 y `legibles` como www-data; cachés escritas por www-data con umask 077 más `cache_ok` (fichero normal, www-data 600, chmod como www-data); el respaldo, en subshells con 077. Única diferencia, menor: m5.

**P7 · Comprobaciones posteriores.** Ruido que dispara la vuelta: el ERROR ajeno (C1) y que `admin/voice/status` no dé 401 tres veces (un corte del borde); molesto, no peligroso. Un worker lento no dispara nada (aviso; los RUNNING se esperan hasta 30 s por lectura). Pueden pasar en verde con el despliegue roto: un FPM sin revalidar (m7), workers sin pid nuevo (m9) y fallos en rutas que nadie llame en la ventana; eso lo cubre la vigilancia de 15 min.

**P8 · Bash.** Nada que afecte al lote 2. No hay `local x=$(…)` ni here-docs; `[ ]` y `[[ ]]` con la precedencia que se busca; `awk` POSIX (vale para el mawk de Ubuntu); `comm` con entradas ordenadas por bytes (el sorted() de Python da el mismo orden en UTF-8); `cut -c` corta bytes, solo en textos de pantalla; no hay `${x/…/&}`, que bash 5.2 cambia con `patsub_replacement`. Pendientes: m1 (lote 4), m2 (lote 3) y m3.

**P9 · Declaraciones frente a `b952b220`.** Cuadran [L].
- `config.txt`: `classifier.enabled` (`filter_var(env(…,false))`) → false · `daily_cap` (`$entero(…,0,0,2000)`) → 0 · `daily_budget_usd` y los dos precios (`$dolares`) → `'0'`, que sale «0» · `model` (`trim((string) env(…,''))`) → vacía · `api_key` (`env()` sin defecto) → null · `observability.approved_unactivated.enabled` → false. Valen solo si el entorno de producción no define `COMMERCIAL_AUTOMATION_CLASSIFIER_*` ni `PAYMENTS_UNACTIVATED_CHECK_ENABLED` [S].
- `migraciones.txt`: nombre, tabla, `obud_scope_chk` y la marca `classifier:reply`, que está en WIDE y no en NARROW; `deshacer` es coherente con su `down()`.
- `programador.txt`: el bloque va dentro de `if ((bool) config('observability.approved_unactivated.enabled', false))`, así que es correcto no declarar +/- y declararla `ausente`.
- Zonas: `config/*` (2) → `--config`; `routes/console.php` → `--programador`; no cambia otra ruta, así que no hacen falta `--rutas` ni `route:cache` (console.php no entra en la caché de rutas).
- Los 25 de fuera de docs/tests: `.env.example`; `AppServiceProvider` (un `bind`); `Console/Commands/ApprovedUnactivatedPaymentsCheck`; `IronGuard/UnactivatedApprovedPayments`; `Outreach/Facts/WhatsappSignals`; `Outreach/Run/{BudgetReservations,OutreachConfig,OutreachMonitor,OutreachReadModels,WhatsappTemplateOutcomes}` y los 5 de `Classifier/*`; `ReplyClassification/OpenAiReplyClassifierTransport`; los 5 de `UltronStudio/*`; los 2 de config, la migración y `console.php`.

## Qué tiene que mirar el lead en `--solo-comprobar` antes de seguir
1. `producción 45b53023 limpia · origin/main b952b220 · 43 commit(s) · 70 ficheros = la lista … 1 migración(es) · 0 de bootstrap`. Antes hay que subir `b952b220` a origin/main (es avance rápido desde `e6887659` [C]).
2. `constantes … cerradas en 45b53023 y en b952b220`.
3. `workers 14 RUNNING (esperados 14) · 0 BACKOFF/FATAL · cachés …: www-data 600`, y la línea de OPcache sin `validate_timestamps=0`.
4. `migración 2026_11_22_100001_allow_classifier_budget_s… · check outreach_budget_counters obud_scope_chk classifier:reply · esquema [1 true false] = antes · vuelta: deshacer`.
5. `pendientes frente a origin/main: [2026_11_22_100001_allow_classifier_budget_scope] = las declaradas · cerrojos …: 0 · transacciones de más de 30 s: 0`; si alguno no es 0, esperar.
6. `/usr/local/bin/ironbody-migrate = la copia versionada … (sha256 8ecb14798c0c260e…)`, sin AVISO.
7. `banderas declaradas, hoy en la caché:` con `outreach.classifier.enabled false` y todas las demás `ausente`. Si alguna sale con otro valor, PARAR: el paso 4 fallará y volverá atrás.
8. `admin/voice/status → 401`, `plan: respaldo → migraciones → código → cachés (config:cache · sin route:cache)` y, al final, `COMPROBACIONES PREVIAS EN VERDE`.
9. Fuera del guion: `umask` = 0022; sesión dentro de `tmux`/`screen` (C2); ritmo de `production.ERROR` en los 10–15 min previos (C1); `failed_jobs` de partida; que no sea la hora de logrotate (m6).

## Notas
- Bien, y comprobado contra las referencias: la lista exacta y cada zona con su declaración; las constantes; el respaldo y `SHA256SUMS`; las cachés como www-data y `legibles`; la espera de pid nuevo con dos lecturas; la sonda 401; el orden de la vuelta según el orden del lote.
- Nada destructivo: ni `rm` ni `migrate:rollback`; `DELETE FROM migrations` con el nombre exacto; `DROP` solo de una tabla vacía (lote 4).
- No verificado: bash 5, GNU, supervisor y sudo reales (no hay Linux ni Docker); el valor real de las banderas en el entorno; el ritmo real de ERROR; si en el VPS hay `tmux`. No ejecuté el banco ni PostgreSQL: no hay nada que borrar.
