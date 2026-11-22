# Informe de security · PT-04 · comprobación puntual de B-1, B-2 y B-3 (mandos de la voz y runbook) · 22-11-2026
**Alcance:** `integracion/voz` @ `b1f66c32`, `git diff b461c205..b1f66c32 -- backend/docs/ultron-studio/ops` (mando, runbook, ensayo, README) e `informe-ops-runbook-voz.md`. Un pase; repo en solo lectura (`git status` limpio). Banco `~/.cache/ironbody-banco-h8-ops8f-pt04`, sin red ni producción, parado al terminar. **[C]** ejecutado · **[L]** leído · **[S]** supuesto. Sondas: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/2672c7dd-4704-4a5d-8d09-d463223e6ab6/scratchpad/pt04/` (`sonda-dedicado.sh`/`.out`, `ensayo-security.completo`).

## Veredictos
| Uso | Veredicto |
|---|---|
| Declarar la identidad compartida y hacer la primera llamada canaria al …6026 | **APTO CON CONDICIONES** · 0 bloqueantes. Condiciones: las (2).1–(2).10 de la revisión, ahora escritas en el runbook; la A0 NUEVA (`RUNBOOK:39`); y la autorización específica del propietario para desplegar y para habilitar llamadas (este informe no la sustituye) |
| Desplegar el lote con las llamadas cerradas | **APTO CON CONDICIONES** · 0 bloqueantes: las (1).1–(1).4 de la revisión, sin cambios ((1).4 ya escrita, `:91`) |

## Los tres bloqueantes
| # | ¿Cerrado? | Evidencia |
|---|---|---|
| B-1 | **Sí** | `h8-voice-scope.sh:984-992`: si `$OUT != false`, la MISMA `CAMBIOS` lleva la declaración y las cuatro claves de `salientes off` (`:922-924`). «APLICADO» (`:1225`) solo sale después de la comparación `:1088-1133`: si apaga, espera `outbound=false` o ABORTA (exit 1); si no apaga, un `outbound` que cambie es «cambió sin pedirlo» → ABORTA. Banco [C]: mi ensayo `109 OK · 0 FALLO` (`…/logs/ensayo-ops8f-20261122-173436.txt`), el 2.º del lead `109/0` (`…172701`), devops `109/0` ×2 y `103/6` con el mando de antes (los 6 de (a)) |
| B-2 | **Sí** | Paso 11 `RUNBOOK:343` y paso 14 `:375` [L]. Las fuentes casan: `VoiceTools.php:348` (línea `voice.tool` con `lab:true`), `:568` (`plans_withheld`, solo si hay), `config/logging.php:124-126` (`voice-<fecha>.log`); `plans.manage` existe (`PlanController.php:378`) |
| B-3 | **Sí** | `RUNBOOK:39` («Se pide de NUEVO … fechada el 22-11-2026 o después … aunque conste por escrito») y la tabla `:69`. «Solo si no consta ya por escrito» ya no está; ninguna de las otras 14 líneas con A0 deja reutilizar una anterior [L] |

**¿Algún camino con «APLICADO» y `outbound=true`? No** [L+C]. Si el estado es ilegible, `OUT` vale `""` o `null` → apaga (falla cerrado). Si lee `false` y la realidad es otra (caché vieja, u otro mando entre `:389` y el `flock` de `:1003`) → la comparación aborta. Lo único que queda es **previo y fuera de B-1**: N-2.
**Efecto nuevo con un número dedicado [C, sonda (d)]:** con el número dedicado, las salientes encendidas y la declaración ya retirada, el `off` nuevo sale APLICADO con `budget 2.00→0 · outbound true→false · outbound_cap 1→0 · twilio_voice true→false` y `estado` «salientes: no». El de antes, en el mismo punto: «nada que escribir» y «salientes: sí». **Aceptable:** falla al lado estricto, no afloja nada, lo anuncia antes de escribir y deja la vuelta exacta. Las entrantes no se tocan (`:987`, igual que `salientes off`) [L]. Su coste: corta una canaria que salga desde un número dedicado y dispara la deriva H-25 (avisada, `:1215`). → N-1.

## Las cinco frases [L]: están, dicen lo pedido y no aflojan nada
- (1).4 → `:91`: el CRM seguirá enseñando el aviso del número, y L avisa a B antes del paso 8. ✔
- (2).2 → `:138`: `sameNumber(<…5483 como en R1>, config("meta.whatsapp_display_phone"))` = `true`; si no, PARAR (el método existe: `VoiceNumberGuard.php:165`). ✔
- (2).3 → `:192`: `ssh -t`, nunca `-tt` ni desde un agente; cada valor solo cuando sale su pregunta. ✔
- (2).4 → `:363` y `:374`: exactamente UN canario de voz activo, el …6026; si hay otro activo, se desactiva antes del paso 14. ✔
- (2).5 → `:416`: el freno se suelta solo después de ver «ADMITIDA por el servidor» en `estado`, nunca antes. ✔
- Las 7 líneas que el diff quita del runbook se sustituyen por texto más estricto.

## Que el cambio no toca nada más
- **Con las salientes apagadas [C]:** los cinco «B-1 (b)» dan OK en mi ensayo: el fichero queda byte a byte y la salida línea a línea igual que con el mando de antes.
- **Controles [L+C]:** el diff del mando solo toca la rama `numero-compartido off` y sus avisos (`:17`, `:962`, `:981-995`, `:1211-1216`). No cambia ningún código del servidor (5 ficheros, todos de `ops/` más el informe). `fase 2 set` sigue rechazando la declaración (`:782`), así que no hay otro camino para retirarla. «Todo lo de antes se sigue exigiendo» da OK [C]: consentimiento, canarios, horario, presupuesto, límites y corte siguen igual.
- **Huella [C]:** el `sha256` de `h8-voice-scope.sh` es `15135fba…` en `b1f66c32` y en `0a1e5c28`, la misma que cita el paso 0 (`RUNBOOK:130`; `:131` invalida `6bd98ae2…`). `h8-twilio-check.sh` sigue en `db842269…`.
- **Secretos [C]:** el `grep` de las líneas añadidas no encuentra ningún teléfono completo, SID, `AC…`/`SK…`/`PN…`, `phnum_…` ni clave. Solo aparece el `sha256` del mando de antes (`ANTES_SHA` en `ensayar-ops8f.sh`). El cierre del ensayo da 0 secretos y 0 teléfonos completos en el registro.

## BLOQUEANTES
Ninguno.

## NO BLOQUEANTES (se documentan; no se corrigen en este cierre)
- **N-1 · Info · `estado` no avisa del efecto nuevo:** `h8-voice-scope.sh:418` aconseja retirar con `numero-compartido off` una declaración sin efecto (número dedicado), pero no dice que ahora también apaga las salientes si están encendidas. El mando sí lo anuncia, aunque no pide confirmación.
- **N-2 · Baja · previo (no lo introduce PT-04) · el atajo «nada que escribir» (`:1007`, `:1045-1048`) compara el FICHERO, no la configuración efectiva** [C, sonda (e)]. Con la caché vieja (el fichero ya apagado y la caché con las salientes encendidas; por ejemplo, una vuelta exacta cuyo `config:cache` falló), `numero-compartido off` y `salientes off` salen con código 0 y «nada que escribir», y `estado` sigue diciendo «salientes: sí». No sale «APLICADO» y el doctor se imprime (`encendidas=`). Corrección futura: en las órdenes de apagar, no tomar el atajo si `ANTES` difiere de lo pedido.
- **N-3 · Info · la referencia de A0:** `:37` («las mismas referencias») y `:39` («p. ej. `AUT-VOZ-0`») dejan que la A0 nueva lleve la misma referencia que una vieja. `--autorizacion` solo valida la forma y no se registra (N-1 de la revisión), así que la cita no distingue cuál de las dos es. Recomendación: una referencia que incluya la fecha.

## Lo que no pude verificar
- Producción y proveedores: si existe una A0 anterior, `ssh -t`, la caché real y qué ve el …6026. Tampoco qué enseña el CRM con la identidad admitida.
- B-2, B-3 y las cinco frases son texto para personas: solo se pueden leer. La rama `TWILIO_VOICE_ENABLED` con las entrantes encendidas (ahora solo es alcanzable con número dedicado + `entrantes on`) queda en [L]: es el mismo código que `salientes off`.
- No ejecuté PHPUnit, `ensayar-ops8c.sh` ni el E2E del H8. El banco queda parado (puertos 8461-8464 libres), con una copia previa sintética más de mi sonda en `…-pt04/root/`.
