# Hermes Agent en Iron Body Neiva

Manual de lo que está instalado en el servidor, por qué está así, y cómo
operarlo. Todo lo de aquí está verificado contra el servidor real, no supuesto.

## Qué es y qué NO es

Hermes es **solo un motor de razonamiento**. Recibe un contexto ya saneado por
Laravel y devuelve una decisión en JSON. Nada más.

Laravel sigue siendo el dueño de: las conversaciones, los mensajes, los
permisos, las herramientas del CRM, el envío a Meta, la idempotencia y la
auditoría. La salida de Hermes pasa por `SalesAgentDecisionValidator` y por los
guardrails **exactamente igual** que la de OpenAI, así que ninguna respuesta
suya llega a un cliente sin que Laravel la haya validado.

Hermes **no** recibe el webhook de Meta, **no** habla con PostgreSQL, **no**
envía mensajes, **no** toca miembros ni pagos, **no** usa QR ni Baileys, **no**
usa su propio puente de WhatsApp y **no** está expuesto a Internet.

## Qué está instalado

| Dato | Valor |
|---|---|
| Origen | Imagen oficial `nousresearch/hermes-agent` ([repo](https://github.com/NousResearch/hermes-agent)) |
| Versión fijada | `v2026.7.30` |
| Digest | `sha256:b869e64d6496d4763d5e4fb675b5f504cb23b0e35ec9b790481a56118602b10f` |
| Por qué esa versión | Nous la designa explícitamente como la release estable para consumidores downstream (imágenes Docker, instalaciones nuevas). `v2026.8.3` es más nueva pero no lleva ese sello. `latest` está descartado: en producción significa que el motor puede cambiar un martes cualquiera sin que nadie lo decida. |
| Ubicación | `/opt/hermes` (compose, `.env`, y un volumen por perfil) |
| Modelo | `gpt-4.1` vía proveedor `openai-api`, reutilizando la clave que ya usa el backend |

## Los dos perfiles

Son **dos contenedores**, no dos perfiles dentro de uno. El init de la imagen
solo supervisa un gateway: los perfiles adicionales hay que levantarlos a mano y
nadie los reinicia si se caen. Con un contenedor por perfil cada uno tiene su
propio volumen —memoria, skills y sesiones separadas de verdad, no por
convención—, su propio supervisor, sus propios límites y su propio reinicio.

| | `hermes-sales` (iron-sales) | `hermes-guard` (iron-guard) |
|---|---|---|
| Puerto (solo loopback) | `127.0.0.1:8642` | `127.0.0.1:8643` |
| Volumen | `/opt/hermes/sales-data` | `/opt/hermes/guard-data` |
| Para qué | Atender clientes: clasificar, recomendar, redactar | Observabilidad: analizar incidentes y proponer correcciones |
| Límites | 2 GB / 1,5 vCPU | 1,5 GB / 1 vCPU |

### Herramientas desactivadas

Verificado con `hermes tools list` en cada contenedor.

**iron-sales** — le llega texto de desconocidos por WhatsApp, así que es el
perfil con menos permisos del sistema. Desactivados: `terminal`, `browser`,
`file`, `code_execution`, `computer_use`, `delegation`, `cronjob`, `web`,
`vision`, `image_gen`, `bfl`, `tts`, `video`, `video_gen`, `stt`, `x_search`.

Queda solo lo que necesita para razonar: `memory`, `skills`, `todo`,
`session_search`, `clarify`.

`web` está desactivado a propósito y no por descuido: **todo** dato comercial
—precios, horarios, planes, promociones— tiene que venir de las herramientas de
Laravel. Una búsqueda web es justo por donde se colaría una promoción inventada.

**iron-guard** — tampoco tiene `terminal`. Los runbooks seguros los ejecuta
Laravel desde su allowlist auditada, no el modelo. Aquí solo razona sobre la
evidencia que se le entrega.

### Cómo se comprobó el aislamiento

```
sin token:   HTTP 401
token malo:  HTTP 401
token bueno: HTTP 200
```

Y los puertos, desde el host:

```
LISTEN 127.0.0.1:8642    (no 0.0.0.0)
LISTEN 127.0.0.1:8643    (no 0.0.0.0)
```

El prefijo `127.0.0.1:` en `ports:` es lo que importa: sin él Docker publica en
todas las interfaces y **se salta el firewall del host**, dejando la API de
Hermes en Internet.

El proceso corre como usuario `hermes` (uid 10000), no como root, aunque el
init arranca como root para poder bajar de privilegios.

## Hallazgo importante: el coste por llamada

Medido en el servidor, no estimado.

Hermes antepone su propio andamiaje (identidad, skills, memoria, catálogo de
herramientas) a cada prompt. Una clasificación trivial —"¿cuánto vale la
mensualidad?"— consume:

```
prompt_tokens: 14 351      completion_tokens: 52
```

**Catorce mil tokens de entrada para una pregunta de seis palabras.**

Con el límite actual de la organización de OpenAI (30 000 TPM) eso son unas
**dos clasificaciones por minuto** antes de que empiecen los rechazos. Se
reprodujo tres veces:

```
Rate limit reached for gpt-4.1 ... (TPM): Limit 30000, Used 20238, Requested 14553
```

Consecuencia práctica: **Hermes no puede ser hoy el cerebro principal del canal
comercial.** Un sábado por la mañana con diez personas escribiendo a la vez lo
tumbaría. Por eso queda instalado, verificado e **inerte**, y OpenAI directo
sigue siendo el responder efectivo: hace la misma tarea con una fracción de los
tokens.

Para poder encenderlo hace falta una de estas dos cosas, y ninguna es una
decisión técnica que corresponda tomar aquí:

1. Subir el tier de la organización en OpenAI (más TPM), o
2. recortar el andamiaje de Hermes (menos skills, memoria más corta).

Mientras tanto, Laravel ya protege el gasto por su cuenta:
`marketing.ai.hermes.budget.max_calls_per_hour` (60 por defecto) corta antes de
llegar al límite y degrada a OpenAI, y el cortacircuitos evita que un Hermes
caído haga esperar a cada prospecto el timeout completo.

## Operación

```bash
cd /opt/hermes

docker compose ps                    # estado y salud
docker compose logs -f hermes-sales  # seguir el log
docker compose restart               # reiniciar ambos
docker compose down                  # parar (Laravel degrada solo a OpenAI)

docker exec hermes-sales hermes status          # modelo, proveedor, claves
docker exec hermes-sales hermes tools list      # qué herramientas tiene
docker exec hermes-sales hermes doctor          # diagnóstico
```

Comprobar que responde (la clave nunca se imprime):

```bash
KEY=$(sed -nE 's/^API_SERVER_KEY=(.*)/\1/p' /opt/hermes/.env)
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $KEY" http://127.0.0.1:8642/v1/models
```

## Kill switch

Apagar Hermes **no requiere desplegar código**:

```bash
# En /var/www/api/backend/.env
MARKETING_HERMES_ENABLED=false

php artisan config:cache
```

Laravel vuelve exactamente al comportamiento anterior: OpenAI directo y, si
tampoco está listo, reglas deterministas. Parar los contenedores tiene el mismo
efecto, porque el cortacircuitos degrada tras tres fallos.

## Secretos

Viven en `/opt/hermes/.env` con permisos `600`, fuera de Git, y en el `.env`
interno de cada contenedor. La clave de OpenAI es **la misma** que ya usa el
backend: duplicarla obligaría a rotarla en dos sitios y un día se olvidaría uno.

`API_SERVER_KEY` es un valor aleatorio de 32 bytes generado en el servidor.

## Variables que usa Laravel

Sin valores; los reales van en el `.env` del servidor.

```
MARKETING_HERMES_ENABLED          # false hasta decidir encenderlo
MARKETING_HERMES_BASE_URL         # http://127.0.0.1:8642  (loopback SIEMPRE)
MARKETING_HERMES_API_KEY          # = API_SERVER_KEY de /opt/hermes/.env
MARKETING_HERMES_MODEL            # gpt-4.1
MARKETING_HERMES_TIMEOUT
MARKETING_HERMES_CB_THRESHOLD     # fallos seguidos antes de abrir el circuito
MARKETING_HERMES_CB_COOLDOWN      # segundos de enfriamiento
MARKETING_HERMES_MAX_CALLS_HOUR   # techo de gasto
```

Si `MARKETING_HERMES_BASE_URL` alguna vez apunta a algo que no sea `127.0.0.1`,
es que algo se hizo mal: Hermes no debe ser accesible desde fuera del servidor.
