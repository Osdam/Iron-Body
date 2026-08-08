# Topología de workers

Qué trabajo corre en qué carril, con cuántos procesos, y cómo volver atrás.

Nace de un hallazgo medido en la fase F.6, no de una preferencia arquitectónica:
con **un solo worker** para toda la cola `default` y el agente encendido, el
rendimiento caía de 4,46 a 0,52 trabajos por segundo y una ráfaga de cincuenta
mensajes dejaba al último esperando **~97 s**. El cuello no era CPU (2,5 % de un
núcleo) ni memoria (45 MB): era un proceso bloqueado esperando a OpenAI mientras
el mensaje del siguiente cliente no llegaba a guardarse.

## Mapa: trabajo → carril → worker → prioridad

| Job | Carril (cola) | Worker | P | Timeout | retry_after | tries |
|---|---|---|---|---|---|---|
| `ProcessMetaWebhookEvent` | `whatsapp-high` | supervisor `ironbody-whatsapp` ×4 | P0 | 60 s | 120 s | 3 |
| `AnalyzeInboundMessage` | `agent` | supervisor `ironbody-agent` ×4 | P2 | 240 s | 360 s | 3 |
| `DownloadWhatsappMedia` | `media` | supervisor `ironbody-media` ×2 | P3 | 420 s | 600 s | 3 |
| `SendAutomationEventToN8n` | `commercial` | supervisor `ironbody-commercial` ×2 | P4 | 120 s | 300 s | 3 |
| `EvaluateCommercialSubject` | `commercial` | idem | P4 | 120 s | 300 s | 3 |
| `EmitElectronicInvoiceJob` | `billing` | systemd `ironbody-billing-worker` ×1 | P4 | 600 s | 900 s | 5 |
| `EmitCreditNoteJob` | `billing` | idem | P4 | 600 s | 900 s | 5 |
| `SendElectronicInvoiceEmailJob` | `billing` | idem | P4 | 600 s | 900 s | 3 |
| `SyncFactusInvoiceStatusJob` | `billing` | idem | P4 | 600 s | 900 s | — |

Nada queda en `default`. Si algún día aparece trabajo ahí, es un job nuevo al que
se le olvidó el carril: lo delata `ChaosLaneIsolationTest`.

El carril se fija en el **constructor** de cada job, no en cada `dispatch`.
`ProcessMetaWebhookEvent` se encola desde cuatro sitios (webhook, replay,
remediación, simulación) y una asignación que hay que recordar en cuatro sitios
es una que algún día falta en uno.

## Por qué el agente tiene su propio job

`AnalyzeInboundMessage` existe porque antes la llamada al modelo ocurría **dentro
de** `ProcessMetaWebhookEvent`, en la misma ejecución que guardaba el mensaje.
Separar las colas sin separar el trabajo no habría arreglado nada: el worker de
`whatsapp-high` seguiría bloqueado esperando a OpenAI.

Ahora la ingesta guarda, encola el análisis y suelta el proceso. Entre guardar y
analizar pasa tiempo, y en ese hueco pueden cambiar cosas —alguien toma la
conversación, el cliente pide que no le escriban—, así que las condiciones se
vuelven a comprobar **en el job del agente** y no se confía en las que había al
encolar. Un takeover posterior gana.

## La regla de `retry_after`

`retry_after` es el tiempo tras el cual la cola da por muerto un trabajo
reservado y deja que otro worker lo tome. **Tiene que ser mayor que el `--timeout`
del worker de ese carril.** Si no, un trabajo lento sigue corriendo mientras un
segundo worker empieza el mismo.

Es por **conexión**, no por cola, y de ahí las cinco conexiones de
`config/queue.php`: es el único sitio donde se puede decir que un mensaje se
recupera en dos minutos y una factura en quince.

El primer argumento de `queue:work` es la conexión. `queue:work whatsapp` usa
retry_after=120; `queue:work database --queue=whatsapp-high` habría usado 90 y
la regla se rompería en silencio.

Producción tenía ese hueco en facturación: `queue:work database --queue=billing`
con retry_after=90 contra `--timeout=180`. No llegó a doler porque nunca hubo un
segundo proceso; era un agujero esperando a que alguien subiera `numprocs`.

## Capacidad

Host: 4 vCPU, 16 GB (≈13,5 GB libres). 13 procesos × ~55 MB ≈ **0,7 GB**.
Los workers son de espera, no de cálculo: el 2,5 % de CPU medido por proceso es
tiempo esperando a la red, no computando.

`--sleep=1` y no 0: sin cola, un worker con sleep=0 gira consultando la base y se
come un núcleo sin hacer nada. `--max-jobs`/`--max-time` reciclan el proceso; PHP
acumula memoria en procesos largos y Supervisor lo levanta en el acto.

## Vigilancia

`QueueHealthService` mide por carril: backlog, edad del trabajo más viejo,
último latido, trabajos por minuto, fallos de la última hora.

No se inspeccionan procesos del sistema a propósito. Preguntarle a Supervisor
cuántos workers cree tener responde a la pregunta equivocada: un worker puede
estar arrancado y bloqueado, o vivo pero escuchando la cola equivocada por un
error de configuración, y en los dos casos diría que todo va bien. Lo que importa
es si el trabajo **avanza**, y eso son dos señales que no se pueden falsear: el
latido que deja cada job al terminar, y la edad del más viejo que sigue esperando.

IRON GUARD abre dos incidentes distintos, y la distinción importa porque el
arreglo es distinto:

- **`queue_unattended`** — hay cola vieja y nadie ha terminado nada en ese
  carril. Falta un proceso. Crítico si es P0 o P1, alto si no.
- **`queue_backlog`** — hay workers vivos pero no dan. Falta capacidad. Nunca
  crítico: nadie tiene que levantarse de madrugada, pero alguien tiene que verlo
  antes de que se convierta en el otro.

Uno por carril: que se caiga multimedia y que se caiga facturación son dos
averías con dos culpables. Ninguna remediación automática toca esto — reencolar
no arregla un carril sin proceso.

## Despliegue

```bash
# 1. Copia de seguridad de lo que hay
ssh root@<host> 'mkdir -p /root/worker-config-backup-$(date +%Y%m%d-%H%M%S) && \
  cp /etc/supervisor/conf.d/ironbody-queue-worker.conf /root/worker-config-backup-*/ && \
  cp /etc/systemd/system/ironbody-billing-worker.service /root/worker-config-backup-*/'

# 2. Código primero: los jobs tienen que conocer sus carriles ANTES de que
#    arranque un worker que escucha esos nombres.
ssh root@<host> 'cd /var/www/api && git pull --ff-only origin main && \
  cd backend && php artisan config:clear && php artisan config:cache'

# 3. Vaciar la cola vieja antes de retirar su worker. Lo que quedara en
#    `default` no lo escucharía nadie después.
ssh root@<host> 'cd /var/www/api/backend && php artisan queue:work database \
  --queue=default --stop-when-empty --max-time=120 || true'

# 4. Instalar y activar
ssh root@<host> 'cp /var/www/api/backend/docs/ops/supervisor/ironbody-workers.conf \
    /etc/supervisor/conf.d/ && \
  rm -f /etc/supervisor/conf.d/ironbody-queue-worker.conf && \
  cp /var/www/api/backend/docs/ops/supervisor/ironbody-billing-worker.service \
    /etc/systemd/system/ && \
  supervisorctl reread && supervisorctl update && \
  systemctl daemon-reload && systemctl restart ironbody-billing-worker'

# 5. Verificar
ssh root@<host> 'supervisorctl status; systemctl is-active ironbody-billing-worker'
ssh root@<host> 'cd /var/www/api/backend && php artisan queue:health'
```

## Vuelta atrás

Devuelve el worker único y no pierde trabajo: los jobs siguen en la tabla `jobs`
con su nombre de cola, y basta que alguien los escuche.

```bash
ssh root@<host> 'supervisorctl stop ironbody-whatsapp: ironbody-agent: \
    ironbody-media: ironbody-commercial: && \
  rm -f /etc/supervisor/conf.d/ironbody-workers.conf && \
  cp /root/worker-config-backup-*/ironbody-queue-worker.conf /etc/supervisor/conf.d/ && \
  cp /root/worker-config-backup-*/ironbody-billing-worker.service /etc/systemd/system/ && \
  supervisorctl reread && supervisorctl update && \
  systemctl daemon-reload && systemctl restart ironbody-billing-worker'
```

Con el worker único restaurado hay que darle los cinco nombres de cola, o lo
encolado en los carriles nuevos se queda sin nadie que lo atienda:

```bash
# Añadir a command= en ironbody-queue-worker.conf, por orden de prioridad:
--queue=whatsapp-high,agent,media,commercial
```

El código no necesita revertirse: los carriles salen de `config/queue.php` y un
worker que los escuche por turnos se comporta como el de antes —peor, pero
correcto—. Revertir el código solo hace falta si el problema estuviera en la
separación del agente, y en ese caso basta `MARKETING_INBOUND_ANALYZE_INLINE=true`
para que el análisis vuelva a correr dentro de la ingesta sin tocar una línea.
