# ULTRON — Runbook del canario y criterios GO / NO-GO

Este documento se lee antes de encender ULTRON en producción por primera vez, y
se vuelve a leer con el sistema encendido. Todo lo que afirma está comprobado
contra el código y contra el servidor; lo que no se pudo comprobar se dice.

Fecha de la comprobación: **2026-09-18**. Backend en producción: `97b2e73`.

---

## 1. Qué es el canario y qué no es

El canario es **una conversación**, no un porcentaje de tráfico. Con
`MARKETING_ULTRON_CANARY_CONVERSATION_ID` puesto, ULTRON atiende esa
conversación y ninguna otra: cualquier otra entrada se descarta con el motivo
`not_canary_conversation` **antes de crear el evento**, así que no deja ni rastro
en la cola (`UltronEventEmitter::ineligibleReason()`).

No es una prueba del modelo. El modelo ya se probó en frío. El canario prueba lo
que no se puede probar en frío: que el recorrido completo —Meta, la cola, n8n, el
modelo real, los dos endpoints, el guard de salida, el despachador y otra vez
Meta— entrega un mensaje a una persona real sin que ninguna de las piezas mienta.

El canario **no** es el momento de probar el cobro. Los enlaces de pago viven
detrás de su propia bandera y de la autorización explícita del dueño
(«AUTORIZADO CANARIO WOMPI»). Sin esa frase, el canario corre con
`MARKETING_ULTRON_PAYMENT_LINKS_ENABLED=false` y el sistema explica los pasos sin
prometer ningún enlace.

## 2. Las cinco puertas, en el orden real

Un mensaje entrante recorre estas comprobaciones, de la más barata a la más
importante (`UltronEventEmitter::ineligibleReason()`):

| # | Puerta | Motivo si cierra |
|---|---|---|
| 1 | `marketing.ultron.enabled` | `ultron_disabled` |
| 2 | La conversación es la del canario | `not_canary_conversation` |
| 3 | Hay lead, el canal es WhatsApp, el mensaje es entrante y lo escribió la persona | `lead_missing`, `channel_not_supported`, `not_inbound`, `not_from_lead` |
| 4 | El lead no pidió que lo dejaran en paz · nadie tomó la conversación a mano · la IA sigue habilitada en ese hilo | `do_not_contact`, `human_takeover`, `ai_disabled` |
| 5 | Hay texto legible (una reacción no es una consulta) | `reaction_not_actionable`, `no_readable_text` |

El cerrojo del canario va pegado al interruptor **a propósito**: es una decisión
de operación, y va antes de mirar nada del mensaje. No sustituye a ninguna de las
barreras de abajo; con la conversación del canario, el opt-out, el takeover y
`ai_enabled` siguen mandando.

## 3. Estado verificado hoy

| Comprobación | Valor en producción |
|---|---|
| `marketing.ultron.enabled` | `false` |
| `marketing.ultron.payment_links_enabled` | `false` |
| `marketing.inbound.auto_analyze` | `false` |
| `marketing.inbound.meta_enabled` | `true` |
| `marketing.ultron.canary_conversation_id` | `19` |
| Workers de supervisor | 12 en RUNNING |
| Workflow n8n activo | `Iron Body - ULTRON - Commercial Advisor` (`YspRwsXnqt33NouP`) |

## 4. Precondición BLOQUEANTE: el estado del lead del canario

La conversación 19 pertenece al **lead 3**, y ese lead está hoy en
`status = needs_human`. Consecuencia comprobada: `phaseContext()` devuelve
`needs_human: true`, es decir, **la derivación a una persona estaría autorizada
en el primer turno del canario**.

Eso invalida la prueba. Todo este cierre existe porque el canario contestó «te
conecto con alguien del equipo» a quien quería pagar; medir el arreglo en una
conversación donde derivar *sí* está permitido no mide nada. Además, el lead
arrastra `staff_review_pending` con motivo `human_requested`.

Hay dos leads en ese estado, el **2** y el **3**, y ninguno tiene
`status_before_human` en su metadata: son anteriores al arreglo del ciclo de vida
(`06f1aff`), así que nada los va a devolver solos. Solo salen de ahí si alguien
los mueve.

**Esto es decisión del dueño, no mía. No he tocado ninguna fila.** Las opciones,
de menos a más intrusiva:

1. **Mover el canario a otra conversación.** Es lo más limpio si existe un hilo
   de WhatsApp reciente con un prospecto normal. Solo cambia una variable de
   entorno; no toca datos.
2. **Sacar a esos dos leads del limbo**, que además cierra una deuda real del
   inbox. La escritura mínima y acotada sería:

   ```sql
   -- Revisar antes:  SELECT id, status, name FROM marketing_leads WHERE id IN (2,3);
   UPDATE marketing_leads SET status = 'interested' WHERE id IN (2,3) AND status = 'needs_human';
   ```

   Es reversible (el valor anterior es `needs_human` y son dos filas), pero es
   una modificación de datos en producción y **requiere autorización explícita**.
   Conviene bajar además la bandera de revisión de la conversación 19 desde el
   inbox, con el botón que ya existe, en vez de por SQL.
3. **Correr el canario tal cual**, sabiendo que se prueba el camino con
   derivación autorizada. Es defendible como primera prueba de plomería, pero no
   certifica lo que este cierre vino a arreglar.

## 5. Encendido

Con la precondición resuelta y en este orden:

```bash
ssh ironbody-vps
cd /var/www/api/backend

# 1. Confirmar que el HEAD desplegado es el certificado y que no hay nada sucio
git rev-parse --short HEAD
git status --short          # solo los untracked conocidos: .env.bak-* y public.backup-*

# 2. Encender SOLO el interruptor, con el canario ya puesto
#    (editar .env: MARKETING_ULTRON_ENABLED=true; el resto NO se toca)
php artisan config:cache
php artisan queue:restart
sleep 8 && sudo supervisorctl status | grep -c RUNNING   # debe seguir siendo 12

# 3. Comprobar lo que ve el sistema, sin enviar nada
php artisan tinker --execute='echo json_encode([
  "ultron"        => config("marketing.ultron.enabled"),
  "canario"       => config("marketing.ultron.canary_conversation_id"),
  "payment_links" => config("marketing.ultron.payment_links_enabled"),
]);'
```

Después, **una persona del equipo escribe desde el WhatsApp del canario**. No se
simula con una fila insertada a mano: el objetivo es exactamente el tramo que una
fila insertada se saltaría.

## 6. Qué mirar, turno a turno

```bash
# El recorrido de este turno, sin datos personales
tail -f storage/logs/laravel.log | grep -E 'ultron\.'

# Qué decidió el sistema y por qué
php artisan tinker --execute='
$a = App\Models\MarketingAiAction::latest("id")->first();
echo json_encode([
  "estado"   => $a->status,
  "motivo"   => $a->metadata["blocked_reason"] ?? null,
  "outcome"  => $a->metadata["outcome"] ?? null,
  "tools"    => $a->metadata["tools_executed"] ?? [],
  "critic"   => $a->metadata["critic"] ?? null,
  "riesgos"  => $a->metadata["risk_flags"] ?? [],
]);'
```

Lo que hay que ver en un turno sano: una acción con su veredicto del Critic, un
mensaje saliente con autor `ai`, y **ninguna** de estas palabras en el texto que
le llegó a la persona: «te conecto», «te paso con», «te comunico», «alguien del
equipo te», «en un momento te atienden». Si aparece una, el guard falló y eso es
NO-GO inmediato.

## 7. Cuándo abortar, y cómo

**Abortar sin pensarlo** si ocurre cualquiera de estas:

- Sale un mensaje que promete una persona, un horario, una clase, un precio o una
  fecha de membresía que el CRM no respalda.
- Sale una URL escrita por el modelo, o se pide cualquier dato de tarjeta.
- Se crea una transacción de pago sin que nadie lo haya autorizado.
- La persona recibe dos respuestas al mismo mensaje, o una respuesta a un mensaje
  que ya había superado.
- Los `422` se repiten turno tras turno: significa que el contrato n8n↔backend
  está desparejado y la persona se queda sin respuesta.

El aborto es **una línea**, y no necesita despliegue:

```bash
# .env: MARKETING_ULTRON_ENABLED=false
php artisan config:cache && php artisan queue:restart
```

Apagar el interruptor cierra la puerta 1, antes de que se cree ningún evento. El
resto del CRM sigue funcionando: ULTRON es aditivo.

## 8. GO / NO-GO

Para ampliar más allá del canario hacen falta **todas**:

| Criterio | Cómo se comprueba |
|---|---|
| Al menos 10 turnos reales sin un solo mensaje que prometa una persona | leyendo los mensajes salientes, uno a uno |
| Cero afirmaciones que el CRM no respalde (precio, clase, horario, membresía, estado de pago) | igual, uno a uno |
| Cero URLs escritas por el modelo | `machine_reply_url` no aparece, y ningún saliente lleva enlace que no escribiera Laravel |
| Los rechazos que haya son explicables uno por uno | `blocked_reason` de cada acción |
| Ningún turno perdido por desparejamiento de contrato | cero `unexpected_fields` |
| La persona del equipo que leyó la conversación firma que la atendería igual | criterio humano, y es el que manda |

Con dinero de por medio (`payment_links_enabled=true`) se añaden: el monto sale
del catálogo y no de la propuesta, un solo enlace vivo por lead y plan, y el
enlace nunca vuelve al modelo en el historial.

**NO-GO** no significa apagar y olvidar: significa apagar, quedarse con el turno
que falló y arreglarlo antes de volver.

## 9. Límites honestos de esta prueba

- Un canario de una conversación no mide concurrencia. Lo que dos conversaciones
  del mismo lead hacen a la vez sigue siendo un residuo conocido.
- No mide el coste por turno: eso se ve en la factura del modelo, no aquí.
- No prueba el cobro real mientras la bandera de enlaces esté apagada.
- El día que se amplíe, la primera ampliación razonable no es «todo el tráfico»,
  sino quitar el id del canario dejando el interruptor encendido y vigilando las
  mismas seis cosas de la tabla anterior.
