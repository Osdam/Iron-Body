# Respaldos de PostgreSQL

Cierra el primer requisito obligatorio antes del go-live que dejó abierto F.13.
Antes de esto no había automatización: ningún cron, ningún temporizador,
`archive_mode` en `off`, y el volcado completo más reciente era manual y de hacía
38 días. La restauración nunca se había probado.

## Arquitectura

```
ironbody-db-backup.timer   (03:15 America/Bogota, diario)
        └─> ironbody-db-backup.service
                └─> /usr/local/sbin/ironbody-db-backup.sh
                        pg_dump -Fc -Z9  →  fichero .part
                        validar: tamaño > 200 kB
                        validar: pg_restore --list ≥ 200 objetos
                        rename atómico  →  .dump   (0600)
                        sha256          →  .dump.sha256
                        retención (14 diarios + 8 semanales)
                        estado          →  /var/lib/ironbody/backup-status.json

ironbody-db-restore-test.timer  (lunes 04:30 America/Bogota, semanal)
        └─> ironbody-db-restore-test.service
                └─> /usr/local/sbin/ironbody-db-restore-test.sh
                        verificar sha256
                        createdb  ironbody_restore_check_<fecha>_<pid>
                        pg_restore  (cronometrado → RTO)
                        comparar 150 tablas y 17 críticas
                        unión real miembro–usuario–pago
                        dropdb
                        estado → /var/lib/ironbody/restore-test-status.json

IRON GUARD  ─ lee los dos JSON ─> incidente `backup_unhealthy` (severidad alta)
```

### Por qué así

**Sin contraseña, en ningún sitio.** `pg_dump` corre como el usuario del sistema
`postgres`, y la autenticación local de este servidor es `peer`: la identidad la
da el usuario, no un secreto. No hay contraseña en el script, ni en un `.pgpass`,
ni en la línea de comandos donde `ps` la vería.

**El volcado sale por la salida estándar y lo escribe root.** `postgres` no puede
escribir en un directorio `0700` de root, y la alternativa —abrir el directorio de
respaldos para que `postgres` entre— habría cambiado el problema por uno peor. Con
la redirección, quien escribe es root y los respaldos siguen siendo ilegibles para
todo lo demás.

**Un respaldo incompleto no puede parecer válido.** En `/root/db-backups` hay un
`.dump` de cero bytes de junio con nombre perfectamente normal: alguien lo dio por
bueno porque `pg_dump` salió con código 0. De ahí que se escriba a `.part`, se
valide, y solo entonces se le ponga el nombre definitivo. Mientras no pase las
comprobaciones, no existe con el nombre que alguien buscaría en una urgencia.

**Formato `custom`, no `.dump.gz`.** `-Fc -Z9` ya comprime dentro y además permite
`pg_restore` selectivo de una tabla o un esquema. Envolverlo en gzip obligaría a
descomprimir el fichero entero antes de poder mirar dentro, y en una urgencia ese
paso se paga en minutos.

**La retención corre al final, nunca al principio.** Limpiar primero y fallar
después dejaría el sistema con menos respaldos que cuando empezó. Y solo se toca
`/root/db-backups/auto`: los snapshots manuales de `/root/db-backups` son
anteriores a cambios concretos y no los borra nada.

## Parámetros

| | |
|---|---|
| Base | `ironbody` (70 MB) |
| Destino | `/root/db-backups/auto` (`0700 root`) |
| Ficheros | `ironbody-YYYY-MM-DD_HH-mm-ss.dump` + `.sha256`, ambos `0600 root` |
| Frecuencia | diaria, **03:15 hora de Neiva** (08:15 UTC) |
| Verificación | semanal, **lunes 04:30 hora de Neiva** |
| Retención | 14 diarios + 8 semanales (≈ 3 meses, < 100 MB) |
| Tamaño medido | 2,9 MB por volcado |
| Duración medida | 1–2 s el volcado · 2 s la restauración |
| Log | `/var/log/ironbody-backup.log` (`0640`) |
| Estado | `/var/lib/ironbody/{backup,restore-test}-status.json` (`0644`, sin secretos) |

La zona horaria se declara explícitamente en el timer. El servidor corre en UTC:
sin `America/Bogota`, «03:15» habrían sido las 22:15 del día anterior en Neiva,
que es cuando el gimnasio todavía está cerrando.

## RPO y RTO

**RPO = 24 h** (peor caso), derivado de la frecuencia diaria. Sin WAL archiving no
hay recuperación a un punto en el tiempo: se pierde como máximo lo ocurrido desde
el último volcado de las 03:15. Si con Meta encendido el volumen de conversaciones
lo justifica, el siguiente paso es `archive_mode = on`; hoy no está.

**RTO = 2 s de restauración**, medido, no estimado — es el tiempo real que tardó
`pg_restore` sobre la base desechable el 8 de agosto de 2026. Extremo a extremo,
contando crear la base y verificar, **4 s**. Sobre 70 MB de datos.

## Operación

```bash
# Estado de un vistazo
systemctl list-timers 'ironbody-db-*'
cat /var/lib/ironbody/backup-status.json
cat /var/lib/ironbody/restore-test-status.json

# Ejecutar ahora, a mano
systemctl start ironbody-db-backup.service
journalctl -u ironbody-db-backup.service -n 40 --no-pager

# Listar respaldos
ls -lh /root/db-backups/auto/

# Validar la huella de uno
cd /root/db-backups/auto && sha256sum --check ironbody-<fecha>.dump.sha256

# Verificar que un respaldo se puede restaurar (base desechable, no toca producción)
systemctl start ironbody-db-restore-test.service
tail -40 /var/log/ironbody-backup.log

# Ver qué hay dentro sin restaurar
pg_restore --list /root/db-backups/auto/ironbody-<fecha>.dump | head -30
```

### Restauración real (emergencia)

```bash
# 1. NUNCA restaurar encima de la base viva. Primero, a una base aparte:
sudo -u postgres createdb ironbody_recuperada
cd /
cat /root/db-backups/auto/ironbody-<fecha>.dump \
  | sudo -u postgres pg_restore --format=custom --dbname=ironbody_recuperada --no-owner

# 2. Comprobar que está lo que tiene que estar
sudo -u postgres psql -c 'select count(*) from members' ironbody_recuperada
sudo -u postgres psql -c 'select count(*) from payment_transactions' ironbody_recuperada

# 3. Solo entonces, y con la aplicación parada:
supervisorctl stop all && systemctl stop ironbody-billing-worker php8.3-fpm
sudo -u postgres psql -c 'alter database ironbody rename to ironbody_dañada'
sudo -u postgres psql -c 'alter database ironbody_recuperada rename to ironbody'
systemctl start php8.3-fpm ironbody-billing-worker && supervisorctl start all

# 4. La base dañada NO se borra hasta haber validado la recuperada en uso.
```

### Problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| `result: failed`, «pg_dump devolvió error» | destino no escribible, o PostgreSQL caído | `journalctl -u ironbody-db-backup`, `df -h`, `systemctl status postgresql` |
| «volcado sospechosamente pequeño» | el volcado se cortó | no se promovió a definitivo; el anterior sigue intacto. Reejecutar |
| «el índice del volcado solo tiene N objetos» | fichero se abre pero está incompleto | igual que arriba; revisar espacio y carga |
| «la huella SHA-256 no coincide» | corrupción en reposo | ese respaldo no sirve: usar el anterior y revisar el disco |
| «faltan tablas» en la verificación | el dump no se aplicó entero | la base desechable **se conserva** para inspección |
| El timer no dispara | no habilitado tras un cambio | `systemctl enable --now ironbody-db-backup.timer` |
| Bases `ironbody_restore_check_*` acumuladas | verificaciones fallidas anteriores | son desechables: `dropdb` una por una tras mirarlas |

## Vigilancia

IRON GUARD abre `backup_unhealthy` (severidad **alta**, fuente `backups`) si el
último respaldo falló, si tiene más de 30 h, si la verificación de restauración
falló o tiene más de 10 días, o si no hay fichero de estado —porque no poder
afirmar que hay respaldos es, a efectos prácticos, no tenerlos—.

Alta y no crítica a propósito: no hay una avería en curso, falta una red de
seguridad. Crítico se reserva para lo que ya se está rompiendo.

Se enciende con `BACKUP_MONITORING_ENABLED=true`, y solo en el servidor que tiene
los timers. En desarrollo y en la suite queda apagado: un detector que alarma
donde no hay nada que vigilar enseña a ignorar la alarma.

## Vuelta atrás

Quitar la automatización no destruye nada: los respaldos ya hechos se quedan.

```bash
systemctl disable --now ironbody-db-backup.timer ironbody-db-restore-test.timer
# Y si además se quiere retirar del todo:
rm -f /etc/systemd/system/ironbody-db-{backup,restore-test}.{service,timer}
rm -f /usr/local/sbin/ironbody-db-{backup,restore-test}.sh
systemctl daemon-reload
```

`BACKUP_MONITORING_ENABLED=false` apaga la vigilancia sin tocar los timers.
