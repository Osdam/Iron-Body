# Hermes vs OpenAI directo — medición contra el servidor real

Todo lo de aquí está medido en `srv1728633` contra la cuenta de OpenAI de
producción. No hay estimaciones.

## Veredicto

**Hermes no se enciende.** No cumple los criterios objetivos, y el margen no es
estrecho: consume 337 veces más tokens de entrada por la misma tarea y pierde el
30 % de las peticiones cuando diez personas escriben a la vez.

OpenAI directo sigue siendo el motor comercial.

## La tabla

Misma tarea en los tres casos: clasificar «Hola, ¿cuánto vale la mensualidad?» y
devolver un JSON con `intent` y `reply`.

| | OpenAI directo | Hermes minimal | Hermes completo |
|---|---|---|---|
| Tokens de entrada / llamada | **37** | 12 465 | 14 351 |
| Latencia p50 (1 petición) | **1,15 s** | 3,34 s | — |
| Latencia p50 (10 simultáneas) | **1,01 s** | 2,38 s | — |
| Latencia p95 (10 simultáneas) | **1,19 s** | 14,62 s | — |
| Éxito con 1 petición | 1/1 | 1/1 | 1/1 |
| Éxito con 5 simultáneas | **5/5** | 4/5 | — |
| Éxito con 10 simultáneas | **10/10** | 7/10 | — |
| Fallos por límite de tasa | **0** | 4 | — |

Los fallos de Hermes son todos `rate_limit`: la cuenta tiene 30 000 TPM y cada
llamada suya pide ~12 400, así que dos peticiones simultáneas ya rozan el techo.

Coste relativo por conversación (solo entrada, gpt-4.1): Hermes cuesta **≈337×**
lo que cuesta OpenAI directo para producir la misma decisión.

## De dónde salen los tokens

`hermes prompt-size` desglosa el prompt fijo del perfil original:

```
System prompt total :   22 284 B      Tool schemas : 18 701 B (7 tools)
  skills index      :    6 732 B        session_search : 6 457 B (1 tool)
  stable            :   20 601 B        skills         : 5 611 B (3 tools)
  context           :    1 603 B        memory         : 2 833 B
                                        clarify        : 2 414 B
                                        todo           : 1 372 B
```

Unos 41 KB de andamiaje fijo antes de que el prospecto diga nada. El índice de
skills venía de **64 skills** instaladas de serie —`humanizer`, `claude-code`,
`p5js`, `comfyui`, `powerpoint`, `touchdesigner-mcp`…— ninguna de las cuales
pinta nada en un gimnasio.

### Qué se probó a recortar

Se creó el perfil `iron-sales-minimal` con **todo** desactivado: sin terminal,
sin ficheros, sin navegador, sin ejecución de código, sin web, sin visión, sin
memoria, sin skills, sin `session_search`, sin `todo`, sin `clarify`. Cero
herramientas. Índice de skills vaciado.

El prompt fijo cayó un **93 %**:

| | iron-sales | iron-sales-minimal |
|---|---|---|
| System prompt | 22 284 B | **2 984 B** |
| Índice de skills | 6 732 B | **0 B** |
| Esquemas de herramientas | 18 701 B (7) | **2 B (0)** |
| **Total fijo** | **40 985 B** | **2 986 B** |

Y el coste real por llamada apenas se movió: de 14 351 a **12 465** tokens. Un
13 %.

### Por qué el recorte no sirvió

La medición decisiva: se mandó el prompt más corto posible —«di ok», dos
palabras, `max_tokens=5`— al perfil ya vaciado.

```
prompt = 12 440 tokens
```

Prácticamente lo mismo que la clasificación completa (12 465). **El coste es
fijo y no depende de lo que se le pida.** Y no es el prompt de sistema, que en
la plataforma del API server mide 2 028 B ≈ 580 tokens.

Quedan ~11 800 tokens por llamada que no aparecen en `prompt-size` y que no se
alcanzan desde la configuración: son estructurales del runtime de Hermes (su
bucle de agente y el andamiaje que inyecta en tiempo de ejecución, con
`agent.max_turns=500`). Hermes es un framework de agentes, no una API de
compleción, y ese es su precio de entrada.

Conclusión práctica: **no hay ajuste de configuración que haga a Hermes viable
aquí.** Se agotó la vía.

## Calidad de la respuesta

Un dato incidental que refuerza por qué Laravel valida siempre la salida, venga
del motor que venga: en las pruebas, tanto Hermes como el modelo directo se
inventaron un precio («$45», «$50») cuando no se les dio el catálogo.

Por eso `SalesAgentDecisionValidator` y los guardrails son obligatorios y no
opcionales, y por eso la prueba `the agent never invents a price` existe. La
calidad del motor no cambia esa necesidad.

## Criterios objetivos y resultado

Para encender Hermes tendría que cumplir los cinco. Cumple uno.

| Criterio | Umbral | Hermes minimal | |
|---|---|---|---|
| Tokens de entrada por llamada | ≤ 2 000 | 12 465 | **FAIL** |
| Latencia p95 con 10 simultáneas | ≤ 3 s | 14,62 s | **FAIL** |
| Éxito con 10 simultáneas | 100 % | 70 % | **FAIL** |
| Fallos por límite de tasa | 0 | 4 | **FAIL** |
| Devuelve JSON válido y validable | sí | sí | PASS |

## Qué haría falta para reconsiderarlo

Una de estas dos, y ninguna es una decisión técnica que corresponda tomar aquí:

1. **Subir el tier de OpenAI.** Con 12 400 tokens por llamada harían falta
   ~250 000 TPM para atender diez conversaciones simultáneas con holgura, frente
   a los 30 000 actuales. Hay que valorar si el gasto se justifica para una
   tarea que OpenAI directo resuelve con 37 tokens.
2. **Que Hermes reduzca su andamiaje de runtime.** Está fuera de nuestro
   control: es cómo está construido.

Mientras tanto Hermes queda instalado, aislado, verificado y **apagado**, con su
cortacircuitos y su techo de gasto puestos por si algún día cambia el escenario.

## Cómo reproducir la medición

```bash
# Composición del prompt fijo
docker exec hermes-sales hermes prompt-size
docker exec hermes-sales hermes -p iron-sales-minimal prompt-size

# Comparativa bajo concurrencia (el script queda en el servidor)
bash /tmp/bench.sh openai 10
bash /tmp/bench.sh hermes-min 10
```
