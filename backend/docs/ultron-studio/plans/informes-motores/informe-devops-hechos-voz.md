# Informe de devops · los hechos de la voz (`h8-voice-scope.sh hechos`) y el recuento del monitor del H7

Fecha: 2026-11-11 · Autor: devops · Rama `feat/ultron-studio-h9` · Árbol `_worktrees/ultron-h8/Iron-Body`

## Resumen para el lead

**Entorno.** Todo se hizo en local y en el banco de la ola M-1 (`~/.cache/ironbody-banco-m1`: código `b1959a0`, fichero
de entorno sintético con el estado de producción, H9 en sombra y laboratorio hablado en 2a). **No se ha tocado
producción.** Hubo **cero red**: la valla dio 0 peticiones bloqueadas en todas las pasadas. No hay commit ni push.
Mientras trabajaba, HEAD pasó de `e65e022c` a `99f8bb23` por dos commits ajenos (`0d656946` E18-8 y `99f8bb23` E18-9).
Ninguno toca mis ficheros, y `VoiceTools::hours()` sigue leyendo `config('voice.facts.site|hours')` igual.

### Qué cambió

| Fichero | Líneas | Qué |
|---|---|---|
| `ops/h8-voice-scope.sh` | 22–23 | Uso: `hechos --sede='…' [--horario='…'] [--en-seco]` y `hechos borrar [--en-seco]` |
| | 60–78 | Cabecera del modo: garantías, conjunto cerrado y rechazos |
| | 96 | `uso()` imprime las líneas 8–23 (antes 8–21). **Es la única línea existente modificada**; todo lo demás es añadido |
| | 193–213 | Lecturas: `leer_hechos` (configuración efectiva por artisan como www-data, en **huellas** [sha256, bytes]), `hechos_fichero` (el fichero tal como lo parsea Dotenv, sin caché), `mismo_json` y `huellas` |
| | 329–335 | `estado`: una línea con las huellas, las líneas que hay en el fichero y si la herramienta los dice |
| | 345–408 | `escribir_hechos`: escritura atómica que conserva dueño, modo y bytes ajenos (CRLF incluido) y vuelve a comprobar el conjunto |
| | 409–639 | El bloque `hechos`, autónomo (ver «Garantías») |
| `ops/h7-monitor-scope.sh` | 66–99 | `programadas` cuenta **solo sus dos tareas** (`ultron-studio:monitor` e `ultron-studio:integrations-snapshot`, sin argumentos). `otras_del_studio` es informativo, solo para `estado` |
| | 21, 193, 220–222, 246, 279 | Textos: «del Studio» pasa a «del monitor». `estado` nombra las otras `ultron-studio:*` que no cuentan |
| `ops/banco-h9/voz-hechos.sh` | nuevo | Banco de las dos tareas, sobre el banco M-1, sin cambiar `m1.sh` |
| `ops/banco-h9/README.md` | sección nueva | Cómo se ejecuta |
| `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md` | 330 | Con qué orden se ponen, su vuelta atrás y qué cuenta como «hecho» |
| `ULTRON_STUDIO_H8_VOZ.md` | 337 | Forma admitida y el mando |
| `ops/evidencia-h9/2026-11-11/hechos-voz/` | 01–03 | Salida íntegra de los ensayos, sin rutas del Mac ni valores |

### El conjunto de caracteres y por qué

- **Admitidos:** letras ASCII; `á é í ó ú ü ñ` y sus mayúsculas; dígitos; el espacio (U+0020); y `. , ; : - # / ( )`.
- **Longitud:** de 1 a 300 caracteres.
- **Formato de escritura:** `CLAVE="texto"`, entre comillas dobles, con la sangría que tuviera la línea (dos espacios
  si se añade).

**Por qué este conjunto.** Lo he comprobado en `vlucas/phpdotenv` v5.6.3 (`EntryParser::processToken`). Dentro de
comillas dobles, Dotenv solo interpreta tres caracteres: `"` (cierra), `\` (escape) y `$` (interpolación `${…}`).
Ninguno de los tres se admite, así que el texto queda **literal**. Las comillas son lo que hace que el `#` de la
dirección y los espacios no corten el valor.

**Además se rechaza:**
- Espacios al principio o al final: `config()` los recorta y la comparación byte a byte no casaría.
- Las palabras que `env()` de Laravel convierte en otra cosa (`true`, `false`, `null`, `empty`, con o sin paréntesis).
- Una URL (`://` o `www.`) y 7 o más dígitos seguidos: `.env.example` pide «sin teléfonos ni URL», en línea con
  `b1959a0`.
- Cualquier otro carácter: comillas, `$`, `\`, acento grave, tabuladores, saltos de línea, tildes sueltas NFD (avisa de
  usar la letra compuesta), otros alfabetos, emojis y UTF-8 inválido.

Todo eso se niega con código 2 sin tocar nada. El mensaje da el motivo y los códigos `U+XXXX`, nunca el texto.

### Garantías del modo `hechos`

Son las del mando de plantillas de la ola M-1, en este orden:

1. Registro de todo lo impreso en `/root/h8-voice-scope-hechos*-<fecha>.log` (600).
2. Los **tres cerrojos** de los mandos que escriben el entorno: `ironbody-env`, `h8-voice-scope` y `h9-outreach-scope`.
3. Fichero de entorno C-14 (www-data 600 o root:www-data 640).
4. El código desplegado tiene `voice.facts`.
5. Ninguna clave repetida.
6. El fichero y la caché dicen hoy lo mismo (solo para fijar; `borrar` no lo exige).
7. Idempotente: si ya está, «nada que escribir».
8. Workers 13 o 14 sin BACKOFF/FATAL, y programador legible.
9. Copia previa 600.
10. Escritura atómica.
11. Dueño y modo iguales, y el resto del fichero idéntico a la copia.
12. Parsea, y **Dotenv da lo pedido byte a byte ANTES de tocar la caché**.
13. `config:cache` como www-data en 600.
14. **`config('voice.facts.*')` leída por artisan igual byte a byte al argumento** (sha256 y bytes).
15. Ni el resto de la voz, ni el motor, ni el H4–H7, ni ElevenLabs, ni el programador cambian.
16. `queue:restart`.

Si algo falla en las etapas 1–2, **una trampa restaura** el fichero (y la caché y las colas) **y lo comprueba**: el
fichero tiene que ser idéntico a la copia y la configuración efectiva la de antes. Después: workers dos veces, 401 sin
token, 0 ERROR nuevos y `voice:doctor` `facts.hoursConfigured`.

`--en-seco` hace todas las comprobaciones, imprime las huellas que escribiría y sale sin copia ni escritura. No exige
ninguna fase: el laboratorio puede seguir encendido. Imprime huellas, nunca el texto.

### El ensayo

| Ensayo | Casos | Resultado |
|---|---|---|
| `voz-hechos.sh … hechos` (tarea 1) | 122 | **122 OK · 0 FALLO** (259 s), valla 0 |
| `voz-hechos.sh … monitor` (tarea 2) | 20 | **20 OK · 0 FALLO** (81 s), valla 0 |
| Regresión del camino general con el mando definitivo (`publicar on` → `publicar off`) | 2 | APLICADO las dos veces; banco restaurado byte a byte; valla 0 |

Desglose de la tarea 1:

| Grupo | Casos |
|---|---|
| Partida, `estado` y `uso` | 4 |
| 22 rechazos con código 2: comillas dobles y simple, `${…}`, `\`, acento grave, salto de línea, tabulador, vacío, 301 caracteres, `@<>`, emoji, NFD, UTF-8 inválido, espacio inicial, `null`, URL, teléfono, argumento desconocido, sin argumentos, `borrar` con opción, opción repetida, solo `--en-seco`. Cada uno con «el fichero no cambió», y ninguno deja copia ni registro | 45 |
| `--en-seco` con los valores reales: huellas exactas, sin copia y sin tocar el fichero | 3 |
| **Valores reales** en 640: dos líneas exactas, config y `consultar_horarios` **byte a byte** (lectura independiente por reflexión de `VoiceTools::hours()`), modo; repetición idempotente sin copia | 8 |
| Tildes, ñ, ü y un horario de **300 caracteres** (más bytes; la longitud se comprueba aparte): sustituidas en su sitio, byte a byte | 5 |
| **Fallo forzado en la etapa 1** (Dotenv) y **en la etapa 2** (tras la caché): código 1, «restaurado y COMPROBADO», fichero idéntico, modo 640, configuración y herramienta las de antes, copias conservadas | 11 |
| **Cerrojo común ocupado** por otro proceso (flock real): aborta, sin tocar nada y sin copia | 3 |
| `borrar --en-seco`; `borrar` → **ida y vuelta idéntica byte a byte en 640**; herramienta «no los tiene»; `hoursConfigured=false`; `borrar` repetido | 9 |
| Uno solo (aviso «necesita los dos», herramienta todavía no) y después el otro (los dice); `borrar` idéntico | 6 |
| **Ida y vuelta idéntica en 600** | 5 |
| Líneas vacías de `.env.example`: sustituidas en su sitio con su sangría, el resto igual; **la vuelta exacta IMPRESA, ejecutada** → idéntica; `borrar` las quita | 11 |
| Fichero y caché incoherentes: fijar aborta y `borrar` sí funciona; clave repetida: se niega | 6 |
| Cierre: 20 registros en 600; copias en 600; ningún texto en los registros; ningún temporal; cero red; banco restaurado | 6 |

En la tarea 2, el mando de HEAD reproduce en el banco el fallo de producción: cuenta «1 del Studio» con la
sincronización encendida y `on off` aborta «no cuadra». El del árbol cuenta 0 o 2 y nombra la sincronización como
«otra». Enciende y apaga de verdad con la sincronización apagada (33 0 → 35 2 → 33 0) y encendida (34 0 → 36 2 → 34 0),
con el fichero idéntico tras cada ida y vuelta. `h8-voice-scope.sh expectativa fase1` se niega en el banco, como debe:
allí ElevenLabs no está verificado.

### Huellas sha256 (árbol de trabajo, sin confirmar)

| Fichero | sha256 | Bytes | Sustituye a (HEAD = lo instalado hoy en `/root`) |
|---|---|---|---|
| `ops/h8-voice-scope.sh` | `d8a1896047f104789387020abcc44be05cc35f15e9c6450a76093e99f35c5d63` | 81 009 | `74fa184e8e29deedb3c8e4201760a9483764632ecb6f3de4aa4b9b0212bcd31c` (55 561) |
| `ops/h7-monitor-scope.sh` | `c70537b7da81d4401deee6421142def07a84524971e6b84da5e13652a9f151ab` | 24 316 | `9a2b01a9d03cbd32db98adfe756a7d3cd9f89f1bcb46b3ffd57d8b684c681a6f` (22 802) |
| `ops/banco-h9/voz-hechos.sh` | `ab7d54d4df87f6fada65b165cbaa4bf08dd1a0a6b1503a3b22a3273f8e0d1102` | — | nuevo |

Huellas de los dos valores reales, para comparar con lo que imprima el mando:

| Valor | Bytes | sha256 |
|---|---|---|
| Sede | 31 | `f219af8ac5fdf3954c12b23599f42695682b2fb1b84e37c16a7dea6655c5ed5c` |
| Horario | 150 | `d2aa16e9f9cf45a89ef8f2fd24a58a10acb02d9c1fbe4ca9b916de58822507e5` |

### Órdenes exactas de producción · tarea 1 (pendientes de la orden del propietario)

`C` es el commit que confirme estos ficheros. Un cambio cada vez, y ningún mando en curso mientras se instalan.

```bash
# 0. Mac (raíz Iron-Body/): instalar los dos mandos del commit C, guardando los de hoy
O=backend/docs/ultron-studio/ops
ssh ironbody-vps 'sha256sum /root/h8-voice-scope.sh /root/h7-monitor-scope.sh'
#    esperado hoy: 74fa184e… y 9a2b01a9…; si no, PARAR (lo instalado no es lo que se cree)
ssh ironbody-vps 'T=$(date -u +%Y%m%d-%H%M%S); for n in h8-voice-scope.sh h7-monitor-scope.sh; do cp -p /root/$n /root/$n.pre-hechos-$T; done; ls -l /root/*.pre-hechos-*'
git show "$C:$O/h8-voice-scope.sh" | ssh ironbody-vps 'umask 077; cat > /root/h8-voice-scope.sh && chmod 700 /root/h8-voice-scope.sh && sha256sum /root/h8-voice-scope.sh'    # d8a18960…
git show "$C:$O/h7-monitor-scope.sh" | ssh ironbody-vps 'umask 077; cat > /root/h7-monitor-scope.sh && chmod 700 /root/h7-monitor-scope.sh && sha256sum /root/h7-monitor-scope.sh'  # c70537b7…

# 1. Servidor, como root: leer
bash /root/h8-voice-scope.sh estado
#    línea «hechos de consultar_horarios»: «sede: vacía · horario: vacía · líneas en el fichero: VOICE_FACTS_SITE N,
#    VOICE_FACTS_HOURS M · la herramienta los dice: no». Anotar N y M.

# 2. En seco (comillas SIMPLES: sin ellas, el «#» de la dirección empieza un comentario de la consola)
bash /root/h8-voice-scope.sh hechos --sede='Cl. 24 Sur #33-53, Neiva, Huila' --horario='Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.' --en-seco
#    tiene que decir: «escribiría (en el fichero): sede: 31 bytes, sha256 f219af8ac5fd… · horario: 150 bytes, sha256 d2aa16e9f9cf…»
#    con otras huellas, lo tecleado no es el texto: PARAR

# 3. Aplicar: la misma orden sin --en-seco
bash /root/h8-voice-scope.sh hechos --sede='Cl. 24 Sur #33-53, Neiva, Huila' --horario='Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.'
#    «MANDO hechos APLICADO»; paso 2 «… → sede: 31 bytes, sha256 f219af8ac5fd… · horario: 150 bytes, sha256 d2aa16e9f9cf… = el argumento byte a byte»;
#    paso 7 «facts.hoursConfigured=true». Guardar las dos líneas «vuelta atrás» que imprime.

# 4. Después (solo lectura)
bash /root/h8-voice-scope.sh estado          # «… la herramienta los dice: sí»
cd /var/www/api/backend && sudo -u www-data php artisan tinker --execute='$m = new ReflectionMethod(App\Services\Telephony\VoiceTools::class, "hours"); $m->setAccessible(true); $r = $m->invoke(null); echo json_encode(["disponible" => $r["disponible"], "sede" => hash("sha256", $r["sede"] ?? ""), "horario" => hash("sha256", $r["horario"] ?? "")]), PHP_EOL;'
#    → {"disponible":true,"sede":"f219af8a…","horario":"d2aa16e9…"}  (lo que devuelve consultar_horarios)
```

**Vuelta atrás:**
- **Funcional:** `bash /root/h8-voice-scope.sh hechos borrar`. Si en el paso 1 N y M eran 0, el fichero vuelve idéntico
  byte a byte (ensayado en 640 y en 600).
- **Exacta:** la línea `install -m … /root/env-pre-voice-hechos-<fecha> /var/www/api/backend/.env && … config:cache …
  queue:restart` que imprime el paso 3 (ensayada en el banco).
- **Del mando:** `install -m 700 /root/h8-voice-scope.sh.pre-hechos-<T> /root/h8-voice-scope.sh`, y lo mismo con el del
  H7.
- **Al validar:** `shred -u /root/env-pre-voice-hechos-<fecha>`, porque la copia lleva todos los secretos.

### Lo que el banco no prueba

- **Usuarios reales.** No hay `root` ni `www-data`: `sudo -u` es un sustituto que ignora `-u`, y `root:www-data 640` se
  emula como `usuario:staff 640`. Tampoco hay FPM real (es `artisan serve`) ni supervisor real (son 13 programas;
  producción tiene 14).
- **Bash 5 y GNU.** El banco corrió con el bash 3.2 de macOS y un `stat` GNU sustituto. En producción hacen falta
  Python ≥ 3.7 (modo UTF-8 con `LC_ALL=C`; el mando ya depende de `python3 -I`) y PHP ≥ 7.4 (`fn`).
- **Corte por señal.** SIGINT o SIGTERM a mitad no se ha ensayado; la trampa es de `EXIT`.
- **Lo que teclee el operador.** Comillas de la consola, o tildes NFD de su teclado: estas se niegan con un mensaje
  claro. El en seco y las huellas de la tabla son la defensa.
- **El código de producción.** El banco es `b1959a0`. HEAD (`99f8bb23`, con E18-8) lee `voice.facts` igual.
- **Una conversación real.** No se ha probado con el laboratorio hablado contra ElevenLabs (sin red). La herramienta se
  verificó por reflexión. Hasta que E18-8 esté desplegado, el laboratorio no llama a `consultar_horarios`: nadie usa
  los hechos en producción hasta E18-8 o hasta la fase 3.
- **Exclusión con mandos sin cerrojo.** No protege frente a `h7-monitor-scope.sh` ni `h6-integrations-scope.sh`, que no
  toman ningún cerrojo.

### Resultado de la tarea 2

- **Recuento corregido y ensayado** con la sincronización encendida y apagada. Además, ya no contaría
  `ultron-studio:integrations-snapshot --verify-voice` (la verificación semanal del monitor de voz). Con el recuento
  anterior, esa tarea habría vuelto a romper el mando el día en que se encienda el monitor de voz.
- **Expectativa.** `expectativa fase1` (`twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED`)
  es la de hoy si no hay número de voz (`TWILIO_VOICE_FROM` vacío ⇒ `not_configured`, según
  `StudioIntegrations::classifyTwilioVoice`). Si hubiera número con `TWILIO_VOICE_ENABLED=false`, sería `fase2`.
  **No hace falta un valor nuevo.** «fase1» nombra el estado de las integraciones, no la fase del despliegue. El mando
  se niega sin tocar nada si `integrations-doctor` no la confirma, así que probarla es seguro.
- **Órdenes de producción**, tras el paso 0 de arriba y en un momento distinto de la tarea 1:

```bash
bash /root/h7-monitor-scope.sh estado
#    «(total · del monitor): N 0 … que no cuentan: 1 (ultron-studio:meta-templates-sync)» y la expectativa «… NO»
bash /root/h8-voice-scope.sh estado
#    TWILIO_VOICE_FROM «—» y «ElevenLabs según el Studio: ELEVENLABS_VERIFIED»
cd /var/www/api/backend && sudo -u www-data php artisan tinker --execute='$u = App\Services\UltronStudio\StudioIntegrationConfig::latest(App\Models\UltronStudio\StudioIntegrationSnapshot::PROVIDER_VOICE, App\Models\UltronStudio\StudioIntegrationSnapshot::KIND_PROBE); echo json_encode(["studio" => App\Services\UltronStudio\StudioIntegrations::voiceState(), "ultima_verificacion" => $u?->outcome, "en" => $u?->created_at?->toIso8601String(), "caduca" => $u?->created_at?->copy()->addSeconds(App\Services\UltronStudio\StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS)->toIso8601String()]), PHP_EOL;'
#    anotar «caduca» (ver riesgo 1)
bash /root/h8-voice-scope.sh expectativa fase1      # «MANDO expectativa-fase1 APLICADO»; si dice NO SE APLICA, parar
bash /root/h7-monitor-scope.sh estado               # «… la confirma el estado de hoy …: sí»
bash /root/h7-monitor-scope.sh on off               # «MANDO monitor → on APLICADO», programador N 0 → N+2 2
sudo -u www-data php artisan ultron-studio:monitor  # primera pasada a mano (lo pide el mando): 0 y ningún incidente nuevo
```

**Vuelta atrás de la tarea 2:**
- `bash /root/h7-monitor-scope.sh off on` (nivel 2; no depende de la expectativa).
- La expectativa puede quedarse: con el monitor apagado no hace nada. Para volver exactamente, está la línea «vuelta
  atrás exacta» que imprime `expectativa`.

## 1. Entorno

| | |
|---|---|
| Dónde | Mac del propietario, árbol `_worktrees/ultron-h8/Iron-Body` |
| Banco | `~/.cache/ironbody-banco-m1`, desplegado en `b1959a0` por `m1.sh` |
| Puertos | PostgreSQL desechable en :55432. API propia en **:8541**, porque el :8501 del banco lo ocupa otra aplicación del Mac (streamlit) y no se ha tocado. Valla en :8504 |
| Al terminar | Banco parado (`voz-hechos.sh … parar`) y su fichero de entorno restaurado byte a byte en cada ensayo |
| **No es producción** | No se ha ejecutado nada contra el VPS, ni siquiera de lectura |

## 2. Diseño y decisiones

- **Un bloque propio** para `hechos`, en vez de tocar el camino común. Las demás órdenes del mando no cambian; el diff
  solo modifica la línea 96. El camino común rechaza a propósito espacios, `#` y comillas, porque es la guarda de las
  otras claves. Los hechos necesitan comillas y un conjunto distinto.
- **`borrar` quita las líneas** en vez de dejarlas vacías. Para `trim((string) env(...))` es lo mismo, y es lo único que
  devuelve el fichero idéntico desde el estado más probable de producción (las claves ausentes, porque «ningún mando
  sabe escribirlas»). Si el fichero ya tenía `VOICE_FACTS_SITE=` vacías, `borrar` las quita: la configuración efectiva
  es la misma, pero el fichero no es idéntico. La vuelta exacta es la copia, y el mando lo dice en ese caso (ensayado).
- **La comprobación byte a byte se hace dos veces:** primero sobre el fichero con Dotenv, antes de la caché (así un
  fallo solo obliga a restaurar el fichero), y después sobre `config()` con artisan como www-data, con la caché nueva.
  Se comparan sha256 y longitud en bytes. Como los textos son públicos, el banco compara además el texto en base64 por
  una vía independiente (`VoiceTools::hours()` por reflexión).
- **Para fijar se exige coherencia entre el fichero y la caché; para `borrar`, no.** La vuelta atrás no se condiciona,
  igual que «apagar» en los demás mandos.
- **Se conserva la copia previa,** como en el resto de `h8-voice-scope.sh` y no como en el mando de plantillas, que la
  borra. Así la vuelta exacta impresa es ejecutable. Se avisa de `shred -u`.
- **La sede o el horario solos se admiten,** con un AVISO: `consultar_horarios` solo los dice con los dos
  (`VoiceTools::hours()`).
- **Los hechos no entran en la huella de voz del Studio** (`StudioIntegrationConfig::voiceFacts`: ElevenLabs y Twilio
  Voice). Fijarlos no provoca `config_drift:voice`.

## 3. En seco

Salida del ensayo (`evidencia-h9/2026-11-11/hechos-voz/01-ensayo-hechos-122-OK.txt`, líneas 107–114):

```
== EN SECO (hechos): no se ha tocado nada (ni copia ni escritura). Todas las comprobaciones previas pasan:
   antes (configuración efectiva): sede: vacía · horario: vacía · líneas en el fichero: VOICE_FACTS_SITE 0, VOICE_FACTS_HOURS 0
   escribiría (en el fichero): sede: 31 bytes, sha256 f219af8ac5fd… · horario: 150 bytes, sha256 d2aa16e9f9cf… · configuración efectiva esperada: …
```

No se destruye ni se reemplaza nada: solo se añaden o sustituyen dos líneas del fichero de entorno.

## 4. Impacto

- **Tarea 1.**
  - Toca dos claves del fichero de entorno, `config:cache` y `queue:restart`. Los 14 workers terminan su trabajo en
    curso y supervisor los levanta, como con cualquier orden del mando. FPM lee la caché nueva en la siguiente petición.
  - No se espera interrupción y no hace falta reiniciar servicios.
  - Nada irreversible.
  - Lo nota el agente de voz cuando llame a `consultar_horarios`: en producción, tras E18-8 o en la fase 3.
- **Tarea 2.**
  - Una clave más `config:cache` y `queue:restart`.
  - Programa dos tareas sin red: `ultron-studio:monitor` cada 30 min e `integrations-snapshot` a las 07:10 de Bogotá.
  - Pueden abrir incidentes de IRON GUARD (fuente `ultron_studio`) si hay deriva. Es justo su propósito, y por eso la
    expectativa va primero.

## 5. Vuelta atrás

Ver el resumen. Todas las vueltas se han ensayado en el banco: `borrar` (idéntico en 640 y en 600), la exacta impresa,
la restauración automática en las dos etapas y `h7 … off on`. Rollback de configuración y no de datos: no hay
migraciones.

## 6. Verificación

- **Comprobado en el banco:** todo lo de la tabla, con su salida íntegra en `evidencia-h9/2026-11-11/hechos-voz/`.
- **No comprobado:** lo de «Lo que el banco no prueba» y, sobre todo, nada en producción. Que funcione allí es una
  hipótesis fuerte, no un resultado, hasta que se ejecuten las órdenes y salgan las huellas de la tabla.

## 7. Pendiente de autorización

Las órdenes del resumen: el paso 0 (instalar los mandos), la tarea 1 (pasos 1–4) y la tarea 2. Antes, el commit de
estos ficheros, que es del lead.

## 8. Riesgos residuales, deuda y deriva

1. **`ELEVENLABS_VERIFIED` caduca.** Lo hace 30 días después de la última verificación `ok`
   (`StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS`). Con el monitor de voz apagado, nada la renueva (la
   semanal es suya). Ese día, el laboratorio hablado responderá `credentials_unverified` y, con el monitor del H7
   encendido, se abrirá un `integrations_drift`, que será **verdadero**. La fecha se lee con la orden de la tarea 2. La
   renovación es `sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice` (un GET a
   ElevenLabs), con autorización.
2. **`smoke-run-h7.sh:81` tiene el mismo recuento.** Con la sincronización de Meta encendida (hoy), su «[$TAREAS 0]»
   fallará. `deploy-h7-backend.sh:160` también, aunque es histórico. **No los he corregido** porque están fuera de
   alcance; el arreglo es el de `h7-monitor-scope.sh:66–90`.
3. **`h7-monitor-scope.sh` y `h6-integrations-scope.sh` no toman ningún cerrojo.** Pueden escribir el entorno a la vez
   que otro mando. Sin corregir.
4. **Las demás órdenes de `h8-voice-scope.sh` no tienen** registro en `/root`, ni los tres cerrojos (solo el suyo), ni
   restauración automática: imprimen la vuelta y paran. El encargo lo daba por hecho. **Solo `hechos` lo tiene.** Llevarlo
   a las demás es un cambio aparte, con su banco.
5. **Las copias previas de este mando se acumulan en `/root` con todos los secretos.** Hay que hacer `shred -u` al
   validar.
6. **La instalación con `cat > /root/…` reescribe el fichero en su sitio.** No hay que hacerla con un mando en curso.
7. **«Sabados» va sin tilde,** tal como vino. «Sábados» se admite; cambiarlo después es otra orden `hechos`.
8. **La regla de los 7 dígitos puede rechazar un texto legítimo** (por ejemplo, «Carrera 5 20 30 40»). El mensaje
   explica el motivo.
9. **Deriva:** ninguna. No he hecho ningún cambio manual en ningún entorno. Secretos a rotar: ninguno; no se ha impreso
   ni leído ninguno.
