# Aviso de «cobrado sin servicio» (`payments:approved-unactivated-check`)

**Qué es.** Una orden de SOLO AVISO. Busca pagos de pasarela `approved` de los últimos 7 días, aprobados hace al menos 5 minutos, con usuario resuelto (el de la transacción o el de la ficha del socio). Avisa de los que no tienen su fila `payments` de origen `gateway` con la misma referencia, y de los que la tienen `paid` con un plan de más de 0 días y sin periodo. Son las clases `FALLO_*` de [`pagos-aprobados-sin-activar.sql`](pagos-aprobados-sin-activar.sql), con el mismo criterio (clase `UnactivatedApprovedPayments`, en `app/Services/IronGuard/`).

Cuando encuentra algo:
- deja un `Log::error('payments.approved_unactivated', …)`;
- abre o suma al incidente **crítico** de IRON GUARD con fuente `wompi` y tipo `payments_approved_unactivated`. Es un solo incidente por clase: las pasadas seguidas suman ocurrencias.

No activa nada, no escribe en pagos ni en socios y no reintenta el activador. La evidencia lleva el recuento, los ids de la transacción, las referencias y la fecha del más antiguo: ningún dato personal y ningún importe.

**Bandera.** `PAYMENTS_UNACTIVATED_CHECK_ENABLED` (por defecto `false`; la encienden `true`, `1`, `on` o `yes`). Es **independiente de `IRON_GUARD_ENABLED`**: con IRON GUARD apagado (producción, 22-11-2026) esto corre igual.
- Apagada: el programador no la agenda, y la orden lanzada a mano sin `--dry-run` no lee ni escribe y lo dice.
- Encendida: corre cada 30 minutos, sin solaparse (cerrojo de 10 min) y en un solo servidor. Son 30 y no 60 porque la ventana de agrupación de `IncidentRecorder` es de 60 minutos (`IRON_GUARD_GROUPING_MINUTES`). Con una pasada cada hora, cada una caería en el borde y unas veces reabriría el incidente desde cero. **Si alguien baja `IRON_GUARD_GROUPING_MINUTES` por debajo de 30, pasa lo mismo.**
- Ajustes: `PAYMENTS_UNACTIVATED_CHECK_WINDOW_DAYS` (7) y `PAYMENTS_UNACTIVATED_CHECK_GRACE_MINUTES` (5).

## Probarla en seco en producción (sin encender nada)
```bash
sudo -u www-data php artisan payments:approved-unactivated-check --dry-run          # tabla legible
sudo -u www-data php artisan payments:approved-unactivated-check --dry-run --json   # lo mismo en JSON
```
`--dry-run` funciona con la bandera apagada. Solo hace `SELECT`: ni incidente ni log. Sale con 0 si no hay nada que avisar y con 1 si hay pagos que avisar o no pudo comprobar. Lo esperado hoy es 0, porque la consulta de diagnóstico dio 0 `FALLO_*` el 22-11-2026.

## Encenderla
1. Antes, la prueba en seco de arriba con salida 0. Si sale 1, primero se investiga (ver abajo). Encendida con fallos viejos abriría el incidente en la primera pasada.
2. Poner `PAYMENTS_UNACTIVATED_CHECK_ENABLED=true` en el fichero de entorno y regenerar la caché como siempre (`config:cache` como `www-data`).
3. Comprobar que se agenda: `sudo -u www-data php artisan schedule:list | grep approved-unactivated` (una línea, `*/30 * * * *`).

**Vuelta atrás:** la bandera en `false` y `config:cache`. Los incidentes ya abiertos quedan como historial y se cierran desde el panel de IRON GUARD.

## Qué hacer cuando salta
1. En el incidente, mira `evidence`: `count`, `by_class`, `transaction_ids`, `references` y `oldest_at`.
2. Lanza la consulta de diagnóstico en **solo lectura**: tiene las instrucciones en su cabecera (`PGOPTIONS='-c default_transaction_read_only=on …'`). El bloque 1 detalla cada `FALLO_*` y su clase dice qué falta:
   - `FALLO_SIN_PAGO_NI_MEMBRESIA`: no hay fila de pago y había plan. Es cobrado sin servicio.
   - `FALLO_SIN_REGISTRO_DE_PAGO`: no hay fila de pago y no había plan.
   - `FALLO_CON_PLAN_SIN_PERIODO`: hay fila pagada, pero sin periodo.
3. Busca la causa en el log: `Activación de membresía post-pago falló` (un `warning` del activador, con la referencia).
4. **No reactives a mano desde aquí.** Reactivar es núcleo de pagos: membresía, periodo, factura y notificaciones. Lo decide el propietario con quien lleve pagos (ver el asunto 1 de `plans/informes-cierre/diseno-arq-pagos.md`).
5. Cuando esté resuelto, cierra el incidente en el panel. Si el problema vuelve, se reabre solo.

Fuera de la ventana de 7 días no se avisa. La historia entera la cuenta la consulta de diagnóstico.
