# Banco local del Hito 6 (`ops/banco-h6/`)

Ensayo de punta a punta, **en el Mac**, de `deploy-h6-backend-fase1.sh`, `h6-integrations-scope.sh` y del smoke (`smoke-run-h6.sh` + `smoke-h6.py`) contra una «producción» de juguete. **Nunca toca producción.** No contiene credenciales: el fichero de entorno del banco se genera con valores aleatorios al montarlo. El banco vive **fuera del repo**: lleva un volcado y ese fichero de entorno, y las herramientas se niegan a montarlo dentro del repo. Antes estaba en el scratchpad de la sesión, que se purga a los tres días.

## Qué monta `montar.sh`

- **Git.** Un clon («producción») en `7ba0ce8`, la fase 1 del H5. Su `origin` es otro clon cuyo `main` es la ref que se quiere desplegar (por defecto `HEAD` del worktree). Si la ref no contiene `7ba0ce8`, se le fusiona el `origin/main` del repo, solo en el banco.
- **PostgreSQL 14 desechable** en `127.0.0.1:55432`, usuario `ironbody`, como `pgunit.sh`. La base `ultron_studio_banco_h6` se borra y se recrea, con el modelo de roles de producción:
  - `h5_owner`, dueño sin superusuario, hace de `iron`;
  - `h5_app`, solo DML por privilegios por defecto, hace de `iron_app`;
  - las migraciones de `7ba0ce8` se aplican como el dueño (esquema 30/27, 11 tablas).
- **Respaldo** con el formato del runbook en `bk/`: `api-commit.txt`, `ironbody.dump` y su `.sha256`, y `env.backup`, con modos 700/600.
- **Sustitutos** en `bin/`:
  - `sudo`: ignora `-u`;
  - `stat -c` de GNU;
  - `supervisorctl`: 13 RUNNING; con `sup-dip`, `sup-backoff` y `sup-backoff-from` se simulan un worker que tarda o uno en BACKOFF;
  - `systemctl` y `sha256sum`;
  - `ironbody-migrate`: vacía la caché, migra como dueño y la regenera en 644, el peor caso. Con `lock-once`, el primer intento choca con un lector del libro y la 100002 cae por 55P03.

## Uso

```bash
O=backend/docs/ultron-studio/ops/banco-h6            # desde la raíz del worktree
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h6  # fuera del repo
bash $O/montar.sh $B [<ref>]                          # BANCO_WIP=1 añade los cambios sin confirmar del backend
bash $O/api.sh $B start                               # artisan serve --no-reload en :8161 (BANCO_PORT)
bash $O/ensayar.sh $B desplegar --solo-comprobar      # paso 0
bash $O/ensayar.sh $B desplegar --55p03               # despliegue con el 55P03 provocado → FASE 1 (H6) COMPLETADA
bash $O/sembrar.sh $B                                 # lead canario, espejo de Meta (semilla del tester) y cuenta de smoke
bash $O/ensayar.sh $B smoke 1                         # smoke de la fase 1
bash $O/ensayar.sh $B alcance writes on off           # fase 2 (y «probe on off», y las vueltas)
bash $O/ensayar.sh $B smoke 2
bash $O/api.sh $B stop && rm -rf $B                   # limpiar (la base: dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h6)
bash $O/prueba-entorno.sh                             # sin banco: funciones del fichero de entorno, extraídas de los scripts
```

`ensayar.sh` regenera antes las copias del banco con `preparar.sh`. Solo cambian rutas, `psql`, la URL, los roles, los dueños esperados y el canal del log; `preparar.sh` enseña cuántas líneas cambian.

**Antes de probar la sonda encendida**, apunta `ULTRON_STUDIO_TWILIO_PROBE_BASE` a un puerto cerrado en el `.env` del banco (por ejemplo, `http://127.0.0.1:9`) y regenera la caché. Fuera de `production` se respeta, así que nada sale a Twilio. El smoke no pulsa la sonda, y la tabla de instantáneas sin cambios lo demuestra.

**Cupos:** el smoke gasta 2 de las 6 llamadas por hora del cupo de la sonda de su cuenta, así que caben 3 smokes por hora y cuenta. Otra cuenta tiene su propio cupo, pero el correo del smoke es único: para la segunda, cambia antes el correo de la primera.

## Qué no prueba

GNU `grep`, `sed` y `stat` de verdad, `sudo`, el envoltorio real `ironbody-migrate`, los dueños `root` y `www-data`, FPM, supervisor y los datos reales de Meta y Twilio. Para eso está `--solo-comprobar` en el servidor (runbook en `plans/informes-h6/informe-devops-h6.md`).
