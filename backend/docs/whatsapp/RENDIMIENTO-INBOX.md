# Rendimiento del Inbox V2 — baseline, cuellos y resultado

Documento de trabajo de la fase D.2.8. Registra **qué se midió, con qué datos,
qué salió mal y qué quedó**. Sirve para dos cosas: justificar cada cambio y
permitir repetir la medición cuando alguien sospeche que algo empeoró.

## Cómo reproducirlo

```bash
# 1. Volumen realista (NUNCA en producción: el comando se niega)
php artisan marketing:bench-seed --conversations=5000 --messages=400000 --long=5 --fresh

# 2. Backend: latencia y número de consultas por operación
php artisan marketing:inbox-bench --iterations=40 --json=baseline.json

# 3. Navegador: velocidad percibida, contra la pila LOCAL
PERF_EMAIL=… PERF_PASSWORD=… node scripts/measure-inbox.mjs
```

El medidor de navegador corre contra un build de producción servido en local,
no contra `ng serve`. Es importante: el servidor de desarrollo compila a
demanda y daba un «shell caliente» de 21 s que no existe en ninguna parte.

**Volumen del baseline:** 5.004 conversaciones · 425.018 mensajes · la más larga
con 5.080. Producción hoy tiene 22 conversaciones y 163 mensajes; medir con eso
no dice nada, porque todo cabe en memoria y ningún índice hace falta.

## Backend — antes y después

| Escenario | p95 antes | p95 después | Consultas antes | Consultas después |
|---|---|---|---|---|
| Lista, primera página | 17,5 ms | 5,4 ms | **36** | **4** |
| Lista, filtro sin leer | 14,3 ms | 5,8 ms | 36 | 4 |
| Lista, filtro IA pausada | 15,3 ms | 5,9 ms | 37 | 4 |
| Lista, filtro por etiqueta | 23,0 ms | 7,5 ms | **44** | **4** |
| Búsqueda por texto | 193,2 ms | 94,6 ms | 37 | 4 |
| Búsqueda por teléfono | 219,5 ms | **2,6 ms** | 1 | 1 |
| Abrir conversación larga | 4,5 ms | 4,7 ms | 8 | 7 |
| Página anterior del historial | 4,7 ms | 5,9 ms | 5 | 5 |
| Panel derecho | 5,8 ms | 7,3 ms | **22** | **17** |

Las cifras en milisegundos oscilan entre corridas según lo ocupada que esté la
máquina; **el número de consultas no**, y es el que dice cómo va a envejecer
esto. Un endpoint de 40 ms con 21 consultas está bien hoy y duele el día que la
base tenga latencia de red.

## Frontend — antes y después

Mismo medidor, mismas condiciones, una corrida detrás de otra.

| Escenario | p95 antes | p95 después | Objetivo |
|---|---|---|---|
| Shell caliente | 346 ms | 294 ms | ≤ 1.500 ms ✔ |
| Cambio de conversación (primera vez) | 146 ms | 132 ms | ≤ 500 ms ✔ |
| Cambio de conversación (ya vista) | 53 ms | 68 ms | ≤ 500 ms ✔ |
| **Feedback visual al enviar** | **10.013 ms** | **34 ms** | ≤ 100 ms ✔ |
| Búsqueda (con antirrebote) | 655 ms | 479 ms | — |
| Filtros | 422 ms | 211 ms | ≤ 400 ms ✔ |
| Scroll del hilo | 47 fps | 47 fps | ~60 fps ⚠ |

El scroll se mide en Chrome sin GPU (rasterizado por software): 47 fps ahí no
es lo que ve una persona en su portátil, es el suelo. Se deja anotado como
limitación del método, no como cifra de producción.

---

## Cuellos encontrados

### 1. Una consulta por fila para la previsualización (N+1)

`presentListItem()` pedía el último mensaje de cada conversación. Veinte filas,
veinte viajes a la base.

**Arreglo:** la conversación guarda `last_message_preview`, mantenido por un
observador sobre la tabla de mensajes. Observador y no una línea en el servicio
de envío porque los mensajes nacen por varios caminos —webhook, envío manual,
agente, endpoint interno, reintentos del outbox— y basta que uno se olvide para
que la bandeja enseñe texto viejo.

**Coste:** un dato duplicado. Cubierto por siete pruebas de que no se queda
atrasado, incluida la de un mensaje con fecha anterior insertado después.

### 2. Doce lecturas al almacén de caché por página

El catálogo de etiquetas estaba cacheado, pero se pedía una vez por
conversación. Con el driver de base de datos eso son doce `SELECT` a la tabla
`cache` — el caché evitaba la consulta pesada, no el viaje.

**Arreglo:** memorizado dentro de la petición. Entre peticiones se sigue
releyendo, así que una etiqueta nueva se ve enseguida (probado).

### 3. Ocho consultas al catálogo del sistema por abrir el panel

`Schema::hasTable()` consulta `pg_class` cada vez, y el panel derecho lo
llamaba una vez por bloque.

**Arreglo:** memorizado por petición. El esquema no cambia a mitad de petición.

### 4. La búsqueda escaneaba los 425.000 mensajes

Medido con `EXPLAIN ANALYZE`:

```
Seq Scan on marketing_messages … rows=53125 … Rows Removed by Filter: 371893
Execution Time: 93 ms
```

Por cada pulsación que sobrevive al antirrebote. Un B-tree no sirve: `LIKE
'%algo%'` no tiene prefijo por el que empezar.

**Arreglo:** índices GIN de trigramas (`pg_trgm`) sobre `marketing_messages.body`,
`marketing_leads.name` y `marketing_leads.phone`. Si la extensión no se puede
instalar, la migración lo anota y sigue: una mejora de rendimiento no puede
tumbar un despliegue.

### 5. El índice de trigramas no se usaba aunque existiera

Con el índice creado, la búsqueda seguía en 173 ms. El plan explicaba por qué:

```
Nested Loop Semi Join
  -> Seq Scan on marketing_conversations (rows=5004)
  -> Index Scan on marketing_messages (loops=5004)
```

Escrito como `whereHas`, PostgreSQL preguntaba «¿esta conversación tiene algún
mensaje que coincida?» **5.004 veces**, y el índice de trigramas no entraba
nunca.

**Arreglo:** invertir la pregunta a «¿qué conversaciones tienen algún mensaje
que coincida?» con `IN (SELECT DISTINCT …)`. El `DISTINCT` es lo que decide al
planificador a recorrer el índice una sola vez: **173 ms → 44 ms**, con el
término más frecuente de la base y sin recortar un solo resultado.

### 6. Buscar dos letras costaba lo mismo que buscar diez

Un trigrama son tres caracteres: por debajo, el índice no se puede usar.

**Arreglo:** con menos de tres caracteres no se busca dentro de los mensajes;
sí en nombre y teléfono, que son tablas pequeñas. Dos letras tampoco
identifican a nadie.

### 7. El teléfono no se encontraba si se escribía con espacios

Se guarda `3150536026` y la gente escribe `315 053 6026`. La búsqueda literal no
encontraba nada y parecía que el buscador estaba roto.

**Arreglo:** además del texto tal cual, se busca por los dígitos sueltos.

### 8. `LIKE` se comportaba distinto en pruebas y en producción

PostgreSQL asume la barra invertida como carácter de escape; SQLite no asume
ninguno. Buscar un `%` literal —«50%»— funcionaba en producción y no en las
pruebas.

**Arreglo:** `ESCAPE` declarado explícitamente, con `!` y no con barra
invertida. La barra rompía por un sitio inesperado: dentro de `ESCAPE '\'`, el
analizador de PDO que cuenta los `?` toma la barra como si escapara la comilla
de cierre y devuelve «Invalid parameter number» aunque el SQL esté bien. Se
descubrió porque el mismo código pasaba en SQLite y fallaba en PostgreSQL, que
es el peor sitio donde encontrarse un fallo.

### 9. Ordenar la bandeja recorría la tabla entera

Existía `(status, last_message_at)`, que no sirve cuando no hay filtro de
estado —el caso normal—. Se resolvía con `Seq Scan` + `top-N heapsort`.

**Arreglo:** índice sobre `last_message_at DESC`.

### 10. El hilo recalculaba todo en cada ciclo de detección de cambios

La plantilla llamaba a un método por cada dato de cada burbuja: separador de
día, autor, glifo, estado, nombre de adjunto. Un método en una plantilla se
ejecuta en **cada** ciclo, no cuando cambia el dato, y el separador de día
construía dos `Date` y llamaba a `toLocaleDateString` —formateo Intl, de lo más
caro que hay en el navegador— por mensaje.

Medido: **26 tareas largas sumando 3 s** en seis cambios de conversación, con
picos de 347 ms. El hilo principal bloqueado justo mientras alguien pulsa.

**Arreglo:** un `computed` que resuelve el hilo entero una vez por cambio de
mensajes, y formateadores `Intl` construidos una sola vez.

### 11. El mensaje enviado no aparecía hasta que contestaba el servidor

**Arreglo:** envío optimista. La burbuja aparece marcada como «enviando» —no
como enviada— y si el servidor la rechaza se queda a la vista con su motivo. El
compositor se vacía de inmediato y devuelve el texto si el envío falla.

Además, al confirmar ya no se recarga el detalle completo: se pide sólo la
última página y se fusiona. Antes, enviar en una conversación larga tiraba todo
el historial cargado y devolvía al principio.

### 12. Cada llamada a la API costaba dos viajes de red

`max_age` de CORS estaba en `0`. El SPA vive en `ironbodyneiva.cloud` y la API
en `api.ironbodyneiva.cloud`: son orígenes distintos, así que el navegador
pedía permiso **antes de cada petición**.

**Arreglo:** `max_age` a 7.200 s (el techo que respeta Chrome). No relaja
ninguna comprobación: la respuesta real se sigue validando contra la lista de
orígenes. Lo único que se retrasa hasta dos horas es que un navegador ya
abierto note un cambio en los métodos o cabeceras admitidos.

### 13. Respuestas que llegaban tarde pisaban la pantalla

Al teclear salían varias peticiones y no vuelven en orden: la de «pla» podía
llegar después de la de «plan» y dejar resultados que no correspondían a lo
escrito.

**Arreglo:** contador de generación; sólo se pinta la última pedida. Lo mismo al
paginar la lista y al cambiar de conversación mientras viaja una petición.

---

## Encontrado y NO arreglado

**El stream SSE de notificaciones ocupa un trabajador PHP por pestaña abierta.**
Se descubrió porque en local, con un solo proceso, bloqueaba todo lo demás 21 s.
En producción hay varios trabajadores de php-fpm y no se nota hoy, pero es una
dependencia de capacidad real: N pestañas abiertas son N trabajadores ocupados
sin hacer nada. Pertenece al módulo de notificaciones, no al de marketing, y se
deja anotado en vez de tocarlo de paso.

## Fallos del propio medidor

Se anotan porque produjeron cifras alarmantes que no significaban nada, y quien
repita la medición se los va a encontrar:

- Detectar el cambio de conversación **contando** burbujas: dos conversaciones
  traen la misma página de 40, el número no cambia y la espera se iba al límite
  de 10 s. Se compara contenido.
- Detectar el envío contando burbujas: mismo problema. Se busca el texto.
- Medir los filtros esperando a que cambien las filas: un filtro que devuelve lo
  mismo parecía tardar 8 s. Se espera a la petición.
- Teléfonos sembrados fuera del formato colombiano: el despachador los
  rechazaba con `lead_without_phone` y **ningún envío llegaba a registrarse**, o
  sea que el escenario medía un camino que no existe.
- La primera fila de la lista era una conversación de pruebas antigua sin
  teléfono. El medidor busca ahora una con historial de verdad.
