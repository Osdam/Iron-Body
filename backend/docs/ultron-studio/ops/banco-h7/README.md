# Banco local del Hito 7 (`ops/banco-h7/`)

Ensayo de punta a punta, **en el Mac**, del despliegue del H7 y de todo lo que OPS7 deja escrito. Se ensaya contra una «producción» de juguete. **Nunca toca producción.** No contiene credenciales: el fichero de entorno del banco se genera con valores aleatorios. El banco vive **fuera del repo**; las herramientas se niegan a montarlo dentro.

## Qué monta `montar.sh`

- **Git:** un clon («producción») en `7d2c6d8`, el H6 y base de la rama del H7. Su `origin` es otro clon cuyo `main` es la ref que se despliega. Por defecto es `HEAD` del worktree; con `BANCO_WIP=1`, más lo que aún no está confirmado del backend.
  - Con `BANCO_WIP=1`, la puerta de diff caza el trabajo en curso de otros (§«Lo que encontró»). Para ensayar lo confirmado: `BANCO_WIP=0`.
- **PostgreSQL 14 desechable** en `127.0.0.1:55432`, con el modelo de roles de producción:
  - `h5_owner` = `iron`, dueño sin superusuario;
  - `h5_app` = `iron_app`, solo DML por privilegios por defecto.
- **Esquema y estado de partida:** el del H6 (43/49/4/14) y las 3 instantáneas de sistema que deja el paso 11 del despliegue del H6.
- **Respaldo** en `bk/`, con el formato del runbook, en 700/600. Lista 226 tablas con datos, justo el umbral del script.
- **Credencial del dueño** para la propuesta de `ironbody-migrate`: `etc/ironbody-migrate/owner.env`, en 600.
- **Sustitutos** en `bin/`:
  - `sudo`, que ignora `-u`;
  - `stat -c` de GNU;
  - `supervisorctl` (13 RUNNING), `systemctl`, `sha256sum` y `flock` (este no bloquea);
  - `ironbody-migrate`, que es **la copia del banco de la propuesta**. Con `lock-once`, el primer intento choca con un lector de la cadena.

`preparar.sh` hace las copias del banco de `deploy-h7-backend.sh`, `h7-monitor-scope.sh` y `ironbody-migrate-propuesta.sh`.
- Cada sustitución tiene que encontrar su texto exacto. Si un script del repo cambia, `preparar.sh` falla en vez de ensayar otra cosa.
- **`PREVIO` (T-1 del tester):** la línea `PREVIO=…` del repo se sustituye **sea cual sea su valor** (`pendiente` o el commit que fije el lead) por el de la «producción» del banco, y tiene que haber exactamente una. El valor del repo queda anotado en la propia línea. Así el banco ensaya el guion que irá a producción.
- **`TAREAS` (m-1 del reviewer):** igual, en `deploy-h7-backend.sh` (el mando no lo usa: comprueba el salto de ±2), con `BANCO_TAREAS`, o `pendiente` mientras no se fije; el smoke lo recibe como `SMOKE_TAREAS`. Se lee como en producción: `desplegar --solo-comprobar` imprime el total del programador y `ensayar.sh <banco> tareas <n>` lo fija. En este banco es **27**: depende de las banderas, y el 26 de las pruebas aborta.
- Lo que el banco no tiene: `setpriv`, los usuarios de sistema, `find` de GNU, `/run` y el `crontab` de otros usuarios (el aviso H-1 del mando sale como «no aparece»).

## Uso

```bash
O=backend/docs/ultron-studio/ops; B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h7     # desde la raíz del worktree
BANCO_WIP=0 bash $O/banco-h7/montar.sh $B && bash $O/banco-h6/api.sh $B start && bash $O/banco-h7/sembrar.sh $B
bash $O/banco-h7/ensayar.sh $B desplegar --solo-comprobar     # paso 0: con TAREAS sin fijar, «FALTA FIJAR TAREAS=N»
bash $O/banco-h7/ensayar.sh $B tareas 27                     # el N que imprimió (27 con las banderas de montar.sh)
bash $O/banco-h7/ensayar.sh $B desplegar --solo-comprobar     # → COMPROBACIONES PREVIAS EN VERDE
bash $O/banco-h7/ensayar.sh $B desplegar --55p03              # → DESPLIEGUE H7 (BACKEND) COMPLETADO
bash $O/banco-h7/ensayar.sh $B smoke                          # smoke-run-h7.sh del repo, con perf-h7/presupuestos-h7.json
                                                              #   recién desplegado: 3 presupuestos OMITIDO por estado → código 3
bash $O/banco-h7/sembrar-presupuestos.sh $B                   # versión en CANARY y ejecución fake: el smoke mide las 17 rutas
SMOKE_BUDGETS=<copia del JSON> bash $O/banco-h7/ensayar.sh $B smoke   # otros techos sin tocar código (recalibrar)
bash $O/banco-h7/ensayar.sh $B monitor estado                 # el mando del monitor (copia del banco de h7-monitor-scope.sh)
bash $O/banco-h7/ensayar.sh $B monitor on off                 # → MANDO monitor → on APLICADO; «monitor off on» para volver
php $O/banco-h7/sembrar-1581.php $B/api/backend 7             # datos sintéticos de una persona (desde $B/api/backend)
bash $O/banco-h7/ensayo-supresion.sh $B                       # supresion-1581.sql con el dueño sin superusuario
bash $O/banco-h7/ensayo-migrador.sh $B                        # la propuesta de ironbody-migrate
bash $O/banco-h7/ensayo-restauracion.sh $B                    # volcado -Fc y restauración con recuentos
bash $O/banco-h7/ensayo-recuperacion.sh $B                    # A: PG cae (clúster propio en 55440) · B: worker del laboratorio muerto
# vuelta atrás, en un banco RECIÉN desplegado (el canario activo de sembrar-1581 cambia lo que espera el smoke del H6):
B2=$B-vuelta; BANCO_WIP=0 BANCO_DB=ultron_studio_banco_h7_vuelta BANCO_PORT=8172 bash $O/banco-h7/montar.sh $B2
bash $O/banco-h6/api.sh $B2 start && bash $O/banco-h7/sembrar.sh $B2 && bash $O/banco-h7/ensayar.sh $B2 tareas 27 && bash $O/banco-h7/ensayar.sh $B2 desplegar
bash $O/banco-h7/ensayo-vuelta.sh $B2 > $B2/logs/vuelta.log 2>&1 < /dev/null   # a fichero: tarda unos 12 min (el smoke del H6 va en serie)
# limpiar
bash $O/banco-h6/api.sh $B stop; rm -rf $B; dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h7   # y lo mismo con $B2
```

Cupos: el smoke del H6 gasta 2 de las 6 llamadas por hora de la sonda, así que caben como mucho 3 por hora y cuenta. El del H7 no toca la sonda.

## Qué no prueba

- El cambio de usuario real (`setpriv`), `sudo`, `flock` ni `crontab`.
- GNU `stat`, `grep`, `sed` y `find` reales.
- Los dueños `root`/`www-data`, FPM y supervisor.
- Los datos reales.

Para todo eso están `ironbody-migrate --check` y `deploy-h7-backend.sh --solo-comprobar` en el servidor.

El ensayo B de recuperación **simula el paso del tiempo** en `jobs.reserved_at` y en `lab_run_cases.started_at`. Lo hace con `now() AT TIME ZONE 'UTC'`: la aplicación guarda UTC en columnas sin zona horaria.
