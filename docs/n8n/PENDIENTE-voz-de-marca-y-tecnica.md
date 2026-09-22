# PENDIENTE en n8n — voz de marca y doctrina de técnica

**Estado: NO aplicado.** El servidor MCP de n8n está desconectado en la sesión en
que se implementó la capa comercial, así que los cinco nodos de prompt del
workflow `YspRwsXnqt33NouP` («Iron Body - ULTRON - Commercial Advisor») no se
pudieron leer, publicar ni verificar byte a byte. Este documento es el cambio
exacto que queda por aplicar, para que sea mecánico cuando haya acceso.

## Por qué hace falta

La plantilla de mensaje de usuario de cada nodo es una **lista blanca**: una
clave nueva del contexto de Laravel muere en el webhook si no se añade ahí. Dos
claves nuevas están ya en la respuesta de `/ai/decide` y **hoy no llegan al
modelo remoto**:

| Clave | Qué lleva | Origen |
|---|---|---|
| `context.sales_playbook` | `phase`, `techniques`, `directives`, `never` | `UltronSalesPlaybook::forPhase()` |
| `context.approved_brand_copy` | las frases comparativas que el negocio aprobó | categoría `brand_copy` de la base de conocimiento |

Lo que **sí** funciona ya sin tocar n8n, porque viaja dentro de una clave que la
lista blanca ya pasa (`context.knowledge_base`):

- `tone.brand_personality` — la personalidad de Iron Body, como dato del negocio.
- la categoría `brand_copy` — las frases aprobadas, visibles para el modelo.

Y lo que no depende de n8n en absoluto, porque es un cerrojo de Laravel:

- `ComposerStyleGuard`, familia `SUPERIORIDAD`: mata el turno (422,
  `machine_reply_pressure`) si el borrador afirma superioridad con alcance
  geográfico o competitivo y esa frase no está literalmente aprobada. Está
  activo en las **dos** puertas por las que entra texto de máquina
  (`/ai/commit` y `/internal/marketing/send_message`).

## 1. Lista blanca: añadir las dos claves

En la plantilla de mensaje de usuario de **Strategist**, **Composer**,
**Composer Reintento**, **Critic Comercial** y **Critic Comercial 2**, junto a
donde ya se interpolan `knowledge_base` y `active_plans`:

```
"sales_playbook": {{ JSON.stringify($json.context.sales_playbook) }},
"approved_brand_copy": {{ JSON.stringify($json.context.approved_brand_copy) }},
```

El Strategist necesita `sales_playbook` para elegir la acción sin contradecir la
doctrina; el Composer y el Reintento para redactar; los dos Critics para poder
juzgar un borrador contra la técnica que tocaba y contra el piso ético.

## 2. Doctrina en el prompt del sistema del **Composer** (y del Reintento)

Texto a añadir, tal cual:

```
CÓMO VENDER (bloque sales_playbook del contexto):
- techniques y directives dicen QUÉ PRIORIZAR al redactar en el punto donde está
  esta conversación, y never lo que no se hace nunca. Aplícalo: no es decoración.
- Son directrices de LENGUAJE. No autorizan ninguna acción, ningún plan y ningún
  link: eso lo decide el backend y te lo bloquea si lo intentas.
- No enumeres características sueltas. Di qué cambia para ESTA persona, con lo
  que ya te contó. Dos ideas traducidas convencen más que ocho características.
- Si la persona ya decidió, deja de vender y dale el siguiente paso en una frase.
- Si dijo que no o que lo piensa, no insistas: quien no se siente empujado vuelve.

AFIRMACIONES COMPARATIVAS (bloque approved_brand_copy):
- Habla con seguridad y orgullo de Iron Body, con hechos concretos del contexto.
- NUNCA te declares el mejor, el número uno, el líder ni el único de Neiva, del
  Huila, de la ciudad ni del mercado, y NUNCA compares con otros gimnasios o con
  la competencia. Un ranking así no se deduce: hace falta un dato que no tienes.
- La ÚNICA excepción son las frases que vengan literalmente en
  approved_brand_copy. Si ese bloque está vacío, no hay excepción.
- Sí puedes decir que algo es lo mejor PARA ESA PERSONA, o el plan más completo
  DE NUESTRO catálogo: eso no es un ranking de ciudad, es una recomendación.
```

Es el mismo texto que ya recibe el cerebro local en
`SalesAgentPromptBuilder::systemPrompt()`. Que los dos cerebros lean la misma
doctrina es el punto: si no, la respuesta depende de quién contestó.

## 3. Doctrina en los dos **Critic Comercial**

```
Juzga también la TÉCNICA, no solo el contenido:
- Un borrador que suelta características antes de saber para qué las quiere la
  persona está mal, aunque cada característica sea verdad.
- Un borrador que sigue vendiendo después de un «sí» o insiste después de un
  «no» está mal, aunque sea amable.
- Un borrador que se declara el mejor de un sitio y esa frase NO está en
  approved_brand_copy está mal, y además lo va a matar el backend: no lo
  apruebes esperando que pase.
```

## 4. Verificación tras publicar

1. `publish_workflow` y comprobar `versionId == activeVersionId`.
2. Un turno real de `/ai/decide` → confirmar que el nodo recibe
   `sales_playbook.phase` con la fase de la conversación.
3. Un borrador con «Iron Body es el mejor gimnasio de Neiva» sin nada aprobado
   debe morir con 422 `machine_reply_pressure` (ya lo cubre
   `VoiceAuthorityTortureTest`).

## Cómo autoriza el negocio una frase fuerte

El cerrojo falla **cerrado**: sin filas aprobadas, ningún superlativo con
alcance pasa. Para autorizar una:

```
php artisan tinker --execute="\$_SERVER['argv']=['x','brand_copy.neiva','Iron Body es el mejor gimnasio de Neiva.','<quién>']; require 'scripts/autorizar-frase-de-marca.php';"
```

Para retirarla basta desactivar la fila (`is_active = false`); el turno
siguiente vuelve a bloquear esa afirmación. Una frase propuesta por la máquina
entra por la API interna con origen `internal_api`, nace en borrador y **no
autoriza nada** hasta que una persona la apruebe con
`marketing:knowledge-review <key> --by=<quién>`.
