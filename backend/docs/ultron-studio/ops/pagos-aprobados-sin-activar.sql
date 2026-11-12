-- QUÉ MIDE: payment_transactions en 'approved' que PaymentMembershipActivator no activó (sin su fila payments origin='gateway' con la misma reference, o con plan y sin periodo), con su criterio exacto; sin datos personales.
-- CÓMO LEERLA: bloque 0 = recuento por clase (OK_ACTIVADO incluido, para tener el total; la columna corte_bogota dice dónde cayó el corte); bloque 1 = detalle de las clases debio_activar 'si' (FALLO_*) y 'revisar'. Las NORMAL_* solo se cuentan.
-- CÓMO EJECUTARLA: PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=20s' psql -At -F '|' -f pagos-aprobados-sin-activar.sql   (solo SELECT; 16 columnas, en el orden del último SELECT).
-- CORTE DEL PERIODO (deducido, no fijado a mano): el created_at más antiguo de una fila payments de pasarela o de caja (origin 'gateway'/'counter') con period_start, porque desde que existe el periodo
--   (migración 2026_09_14_000002, lote 86) solo MembershipPeriod lo escribe en esas filas, al cobrar. Se excluyen las 'legacy': esa migración y ImportLegacyCrmCommand les rellenaron periodos antiguos.
--   Una fila de pasarela con plan y sin periodo creada ANTES del corte es NORMAL_ANTERIOR_AL_PERIODO. Límites: (1) las filas creadas entre el despliegue y el primer periodo escrito se cuentan como
--   anteriores (con el tráfico de caja el hueco es de minutos u horas, no de días); (2) si una fila anterior al despliegue recibiera después su periodo (una reconfirmación), el corte retrocedería
--   y algunas anteriores volverían a salir como FALLO: un falso positivo visible, nunca un fallo escondido.
-- RESULTADO EN PRODUCCIÓN (12-11-2026, antes de añadir esta clase): 74 aprobadas = 9 FALLO_CON_PLAN_SIN_PERIODO + 1 NORMAL_SIN_PLAN + 55 NORMAL_SIN_USUARIO + 9 OK_ACTIVADO. Las 9 eran de Wompi,
--   aprobadas del 12-06 al 14-09-2026 17:26 de Bogotá, anteriores a la columna (pasarela con plan y sin periodo: 8 de 12 en septiembre, 0 de 5 desde octubre). Con esta versión deben salir como
--   NORMAL_ANTERIOR_AL_PERIODO y FALLO_* en 0. Cualquier FALLO_* posterior al corte es real.
--
-- Criterio, leído en el código (no supuesto):
--  · PaymentMembershipActivator::activate() usa el user_id de la transacción o, si falta, el de la ficha
--    (members.user_id); sin usuario existente sale sin hacer nada -> NORMAL_SIN_USUARIO.
--  · Si hay usuario, SIEMPRE crea (firstOrCreate) la fila payments (reference, origin='gateway'), tenga plan o no.
--    Falta esa fila -> FALLO_SIN_PAGO_NI_MEMBRESIA (con plan: cobrado sin servicio) o FALLO_SIN_REGISTRO_DE_PAGO (sin plan).
--  · Con plan, MembershipPeriod::apply() deja el periodo en payments.period_start/period_end; lo vacía revert()
--    al dejar de estar cobrado (status <> 'paid' -> NORMAL_PAGO_REVERTIDO). No hace nada con un plan que ya no
--    existe o sin días (-> REVISAR_PLAN_SIN_DIAS). Con plan válido y sin periodo: antes del corte ->
--    NORMAL_ANTERIOR_AL_PERIODO; desde el corte -> FALLO_CON_PLAN_SIN_PERIODO.
--  · En la pasarela no hay ventas de producto ni abonos: esos van por caja (origin='counter'), no por aquí.
with corte as (
    select min(created_at) as desde
    from payments
    where origin in ('gateway', 'counter')
      and period_start is not null
), aprobadas as (
    select t.id,
           t.provider,
           t.reference,
           t.amount,
           t.currency,
           t.plan_id,
           coalesce(t.approved_at, t.paid_at, t.updated_at) as aprobada_utc,
           case when t.approved_at is not null then 'approved_at'
                when t.paid_at is not null then 'paid_at'
                else 'updated_at' end as fecha_de,
           coalesce(t.user_id, m.user_id) as usuario
    from payment_transactions t
    left join members m on m.id = t.member_id
    where t.status = 'approved'
), clasificadas as (
    select a.*,
           p.id is not null as fila_payments,
           (p.period_start is not null and p.period_end is not null) as periodo,
           pl.duration_days as plan_dias,
           case
               when a.usuario is null or not exists (select 1 from users u where u.id = a.usuario)
                   then 'NORMAL_SIN_USUARIO'
               when p.id is null and a.plan_id is not null
                   then 'FALLO_SIN_PAGO_NI_MEMBRESIA'
               when p.id is null
                   then 'FALLO_SIN_REGISTRO_DE_PAGO'
               when coalesce(p.status, '') <> 'paid'
                   then 'NORMAL_PAGO_REVERTIDO'
               when p.plan_id is null
                   then 'NORMAL_SIN_PLAN'
               when pl.id is null or coalesce(pl.duration_days, 0) <= 0
                   then 'REVISAR_PLAN_SIN_DIAS'
               when (p.period_start is null or p.period_end is null)
                    and (select desde from corte) is not null
                    and p.created_at < (select desde from corte)
                   then 'NORMAL_ANTERIOR_AL_PERIODO'
               when p.period_start is null or p.period_end is null
                   then 'FALLO_CON_PLAN_SIN_PERIODO'
               else 'OK_ACTIVADO'
           end as clase
    from aprobadas a
    left join payments p
           on p.reference = a.reference
          and p.origin = 'gateway'
          and p.reference is not null
    left join plans pl on pl.id = coalesce(p.plan_id, a.plan_id)
), etiquetadas as (
    select c.*,
           case when c.clase like 'FALLO_%' then 'si'
                when c.clase like 'REVISAR_%' then 'revisar'
                else 'no' end as debio_activar
    from clasificadas c
)
select bloque, clase, debio_activar, n_clase, tx_id, proveedor, aprobada_bogota, fecha_de,
       importe, moneda, tiene_plan, plan_dias, fila_payments, periodo, ref_fin6, corte_bogota
from (
    select 0 as bloque, clase, debio_activar, count(*) as n_clase,
           null::bigint as tx_id, null::text as proveedor, null::text as aprobada_bogota, null::text as fecha_de,
           null::numeric as importe, null::text as moneda, null::boolean as tiene_plan, null::integer as plan_dias,
           null::boolean as fila_payments, null::boolean as periodo, null::text as ref_fin6,
           to_char(((select desde from corte) at time zone 'UTC') at time zone 'America/Bogota', 'YYYY-MM-DD HH24:MI') as corte_bogota,
           null::timestamp as orden_fecha
    from etiquetadas
    group by clase, debio_activar
    union all
    select 1, clase, debio_activar, count(*) over (partition by clase),
           id, provider,
           to_char((aprobada_utc at time zone 'UTC') at time zone 'America/Bogota', 'YYYY-MM-DD HH24:MI'),
           fecha_de, amount, currency, plan_id is not null, plan_dias, fila_payments, periodo, right(reference, 6),
           null::text,
           aprobada_utc
    from etiquetadas
    where debio_activar in ('si', 'revisar')
) r
order by bloque,
         case debio_activar when 'si' then 0 when 'revisar' then 1 else 2 end,
         clase,
         orden_fecha desc nulls last,
         tx_id;
