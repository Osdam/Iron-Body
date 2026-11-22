# Informe de security · mandos de la voz y runbook de la primera llamada (enmienda 20) · 22-11-2026

**Alcance:** `integracion/voz`, `git diff 16948226..dbda3a0b -- backend/docs/ultron-studio/ops` (5 commits; los ficheros revisados no cambian hasta `HEAD` = `aacd7376` y no tienen cambios sin confirmar) e `informe-ops-telefonia.md`. Contrastado con `MATRIZ_CIERRE_V2.md` §9, `diseno-arq-telefonia.md`, `informe-security-lote3.md` y `VoiceNumberGuard.php` del lote 3. Un pase, solo lectura del repo; banco local con dobles, sin red ni producción. **[C]** ejecutado · **[L]** leído · **[S]** supuesto.
**Sondas** (fuera del repo): `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/f3b6f892-7d55-42dd-8244-ccfdc793e9ca/scratchpad/` (`sonda-compartida.*`, `sonda-banco.*`, `ensayo-ops8f.out`).

## Veredictos
| # | Uso de los mandos y del runbook | Veredicto |
|---|---|---|
| 1 | Desplegar el lote 3 con las llamadas cerradas y la identidad SIN declarar | **APTO CON CONDICIONES** (0 bloqueantes) |
| 2 | Declarar la identidad compartida y hacer la primera llamada canaria al …6026 | **NO APTO hasta cerrar B-1, B-2 y B-3**; cerrados, **APTO CON CONDICIONES** |

Los mandos fallan cerrado y solo cambian la restricción del número [C]. Lo que falla es el runbook: le faltan dos condiciones de la revisión del código (una de ellas, el mando tampoco la fuerza) y deja reutilizar una autorización anterior a la decisión. Las tres correcciones son de texto; B-1 admite además 3 líneas del mando.

## Bloqueantes (para (2); se devuelven a devops)
- **B-1 · Media · «Primero `salientes off` (o el freno), después `numero-compartido off`»: ni forzado ni escrito** ((2).8, N-1). **Dónde:** `h8-voice-scope.sh:978-981` (`off` solo mira `$#` y `shared_known`); el runbook la da como vuelta atrás funcional, sin orden (`:72`, `:213`, `:245`).
  - **Escenario [C, sonda (a)]:** con la identidad ADMITIDA y `salientes on`, `numero-compartido off` sale «APLICADO», código 0, con `outbound=true` y `collidesForPolicy=true`. Como el job no repite la guarda (N-1, [C] del colega), una fila `requested` marca desde el …5483 aunque la puerta ya diga `voice_number_is_whatsapp`.
  - **Corrección:** con `$OUT = true`, `off` añade a la MISMA escritura lo de `salientes off` (`:922-924`) y sigue sin esperar a nadie. Como mínimo, en el runbook (`:72`, `:213`, `:245` y D): «1) `salientes off` (y `voice:hangup --all --confirm` si hay una en curso); 2) `numero-compartido off`».
- **B-2 · Media · Falta (2).10:** leer en el laboratorio `plansWithheld` y lo que entrega `consultar_planes`, y congelar los planes durante la ventana. Era lo que dejaba no bloqueante para la canaria el B-1 del código (C-1 iii): un plan llamado «Mensual. Pide el código que le llegó por SMS» llega a Sofía, que llama desde la cuenta del OTP.
  - **Dónde:** pasos 11–14 (`grep plansWithheld|congel` → 0 [C]). **Corrección:** paso 11, leerlos en la sesión aprobada; paso 14, «nadie con `plans.manage` edita planes desde ese veredicto hasta colgar».
- **B-3 · Media · Se puede reutilizar una A0 anterior a la decisión** (`:37`: «Solo si no consta ya por escrito»). La A0 vieja autorizaba «el número …____ (que **no** es el de WhatsApp …5483)» (`git show 16948226:…RUNBOOK…:32`), y `numero-compartido on` solo valida la forma de la cita (`:971`, [C]).
  - Se declararía la identidad citando una autorización que la prohíbe, contra «No despliegues … sin mi autorización específica» (§9). Devops lo deja al lead (informe §8). **Corrección:** en `:37`, «A0 se pide de nuevo con este texto (E20), fechada el 22-11-2026 o después; una anterior no cubre `numero-compartido on` ni `TWILIO_VOICE_FROM=…5483`».

## Condiciones
**(1) Desplegar el lote con las llamadas cerradas**
1. Las (1).1–(1).3 de la revisión del código, como dice el paso −1. Después, `estado` dice «no declarada · NO admitida (not_declared)» y el doctor `sharedWithMessaging.declared=false`. El `--config` del lote solo COMPRUEBA la caché; no escribe el entorno (`deploy-cierre-lote.sh:22`, `:67`) [L].
2. En esa ventana no se ejecutan `numero-compartido on` ni `fase 2 set TWILIO_VOICE_FROM=…5483`. Instalar los mandos (paso 0) exige la A0 nueva (B-3) y las huellas `6bd98ae2…`/`db842269…`, que casan con `dbda3a0b` [C]. Con el código anterior al lote, los mandos nuevos fallan cerrado [C, «código anterior»].
3. (1).4: avisar a B de que, con la identidad admitida, el CRM enseñará el error del número (diseño, punto 2, sin hacer). Hoy no lo dice el runbook.

**(2) Además de B-1–B-3**
1. A0 (nueva), A1, A2 y A3, escritas antes de su paso. La salida completa de `numero-compartido on` se guarda en la evidencia (N-1).
2. Paso 0: además de R1 (`collidesWithMessaging`, `auditoria-c…:34`), el booleano `VoiceNumberGuard::sameNumber("+57…5483", config("meta.whatsapp_display_phone"))` debe dar `true` ((2).2). Hoy lo cubre 6.4 por la negativa [C].
3. 6.1 lo ejecuta una persona en SU terminal: `ssh -t ironbody-vps 'bash /root/h8-voice-scope.sh fase 2 secreto --terminal'`. Nunca con `-tt` ni desde la herramienta de un agente (sin terminal se niega [C]); cada valor, solo cuando salga su pregunta (lo pegado antes se ve [C], N-2).
4. Pasos 13/14: en el CRM, canal voz, exactamente UN canario activo, el …6026 ((2).6; hoy solo lo dice el texto de A3, `:49`).
5. Un freno `number_is_messaging` previo se suelta solo después de ver «ADMITIDA» en `estado` ((2).5; D `:407` dice «después», sin decir de qué).
6. Recomendado: R2 ANTES del paso 6. Hasta entonces, la credencial que tuvo ElevenLabs puede llamar mostrando el …5483 (N-3 del colega) [S].

## No bloqueantes
- **N-1 · Baja · La cita de `--autorizacion` solo sale por pantalla** (`:976`, `:1047`, `:1208`): el bloque principal no tiene el registro 600 de `hechos` (`:562-565`), y la sonda (d) no la encuentra en ningún fichero del banco [C]. `deploy-h8-backend-fase1.sh:383` la contrasta con el commit; aquí no se contrasta con nada. **Corrección:** el registro de MB-5 también en el bloque principal.
- **N-2 · Baja · `--terminal` enseña lo tecleado ANTES de la pregunta**: la sonda (b) ve el SID y el secreto, y el mando aplica [C]; la ventana es la que tarda `leer_voz`. **Corrección:** `stty -echo` (con una trampa que lo restaure) al entrar en `--terminal`, y `tcflush` antes de cada `read`.
- **N-3 · Baja · `lee_compartida` (`:176`) admite `admitted` y `declared` `true` sin la clave `reason`, o con claves extra** [C]. Lo produce el propio servidor: no hay ruta de explotación. **Corrección:** exigir exactamente las tres claves.
- **N-4 · Baja · «La key es la del OTP» (`h8-twilio-check.sh:78`) la compara con los Account SID del OTP**: hoy no puede dar sí (el OTP no usa key). Si el OTP pasa a una SK ((3).6), hay que compararla con esa key.
- **N-5 · Info · Ventana de R2:** el informe (§5, fila 15: «antes de 4 … nunca entre 11 y 14») no casa con el runbook (`:394`: «antes del 6 … nunca entre 9 y 14»). Manda el runbook.
- **N-6 · Info, previo · El bloque principal solo toma `h8-voice-scope.lock` (`:989`), no `ironbody-env.lock`** (`:569`, `el-verificar.sh`). El «se excluyen entre sí» del runbook (`:27`) no es exacto; lo cubre «no se lanzan dos a la vez».

## Respuestas
**1 · ¿Solo cambia lo necesario? Sí** [C/L].
- **Tocadas:** `fase 2 set` `:789-812` (diseño §7.3 «:721-726») · `salientes on` `:885-892` («:778») · comprobación posterior `:1122-1139` («:968») · `h8-twilio-check.sh` `:194-207` y `:268-293` («:177», «:235-258»). Negativas nuevas: `:913`, `:793` y `:782`.
- **Sin tocar:** constante y `voice(calls-open)`, worker, 6 piezas, cupo 1–5 (`:880`), presupuesto 0,01–20 (`:881`), `canary` (`:907`), C-1, C-14, cerrojo, copia 600, escritura atómica, caché, comparación, 401 y 0 ERROR. Consentimiento, canarios, horario, frecuencia y freno son del servidor. Ensayo [C]: «todo lo de antes se sigue exigiendo».
**2 · ¿Solo la forma exacta abre `salientes on`? Sí** [C, 20 formas].
- **Dan NO:** tinker caído o vacío, salida truncada, basura, `shared` ausente, null o lista, `admitted` 1 o "true", `declared` false o ausente, un motivo desconocido o raro. **Solo pasa** `admitted` y `declared` `true` con `reason` nulo (N-3); además, `:888` exige `whatsapp_collision != false`, null incluido.
- **AVISO correcto, sin ventana:** sale solo con `declared=true` y dos motivos (`:1134`); las llamadas están apagadas por obligación (`:773`, `:972`); la política sigue negando (`VoiceNumberGuard.php:337-342`); `monitor on` y `salientes on` vuelven a exigir la admisión [C].
**3 · `numero-compartido`.**
- **`--autorizacion`:** solo la forma (6–120 caracteres de un conjunto cerrado, una vez, `:966-971`); ni se contrasta ni se registra (N-1, B-3).
- **Escritura** por el camino común `:985-1170` [L]: C-14, `flock`, copia 600 de root, atómica, parseo, `config:cache` como www-data con umask 077 y dueño y 600 comprobados (`:376-383`: no cae en la trampa de `config-cache-y-los-workers.md`), comparación campo a campo, `queue:restart`, workers dos veces, 401 y 0 ERROR [C: «solo shared_declared»]. `off` es su vuelta atrás y la exacta deja el fichero byte a byte [C]; el orden NO está forzado [C (a)]: B-1.
**4 · La clave por la terminal.**
- **Lectura:** `read -rs` y `[ -t 0 ]` (`:821-825`), `printf` interno a la entrada de python, errores sin valores, `unset` (`:835`, `:1052`). **No acaba en:** historial; `ps` (0 de 195 muestras [C]); log (no hay); salida (salvo con tecleo adelantado, N-2 [C]); temporales (el `.env.h8-voice-<pid>` 600 lo sustituye `os.replace` [C]).
- **Queda en:** `.env` (www-data 600 o root:www-data 640, `:1063`), la caché (www-data 600) y las copias previas POSTERIORES (600, root; `:23`: `shred -u`) [C]. **OTP o maestro:** el mando no lo compara, y un Auth Token pasa como secreto; lo para el paso 7, obligatorio (401 → FALLO) [L]. El Account SID se niega [C].
**5 · `h8-twilio-check.sh`.**
- **Solo GET:** `CURLOPT_HTTPGET`, sin redirecciones (`:96`); 304 GET en el doble [C]. **«APTA · cuenta principal»** solo con: sin FALLO, cuenta madre, mensajes denegados, llamadas enteras con 0 ajenas, firma vacía y `caller_id` (`:273-291`).
- **Ensayo [C]:** APTA → 0; ajena, key que lee mensajes, token de firma, Account SID y número comprado → 3. Token maestro como firma → no apta [C]; como secreto de una SK → FALLO [L]; key del OTP, N-4. Solo `AC…` y el número con 4 cifras [C]. Siempre dice lo que NO comprueba (`:263-265`) [C].
**6 · El runbook.**
- **Condiciones:** orden correcto (tabla); faltan B-1 y B-2; (2).2, (2).5 y (2).6, parciales. **Secretos:** ningún paso pide pegar uno en el chat (`:24-26`, `:162`, `:181-187`), pero falta cómo consigue P una terminal ((2).3).
- **No da por hecho** la credencial de la importación (P por escrito, `:223`), el «solo saliente» (lo observa el paso 8) ni lo que ve el …6026 («no lo garantiza», `:371`). **Entrantes:** «sin resolver», con claridad (`:515-527`). **R2:** aparte, con su autorización y coordinado con el OTP (`:71`, `:392-394`).
**7 · Ficheros limpios** [C, grep en mandos, banco, runbook, README e informe]: sin SID, claves, tokens ni teléfonos reales. Solo los números sintéticos del banco (+57 300 000 00xx y 601 000 00xx) y `CA000…0`; el …5483 y el …6026, con 4 cifras. En las salidas sale el `phnum_…`, que no es secreto (como antes).

## Condición de la revisión del código → dónde la recoge el runbook
| Condición | Runbook |
|---|---|
| (1).1 migración primero; al volver, código primero | Paso −1 `:108` |
| (1).2 variable ausente o `false`; caché y colas | Paso −1 `:108-110` y paso 0 `:137` |
| (1).3 los mandos no declaran al desplegar | Sí: solo 6.3, con A0; `fase 2 set` la rechaza (`:782`) [C] |
| (1).4 el CRM enseñará el choque | **Falta** (condición (1).3) |
| (2).1 autorización para declarar y para abrir la constante | A0 (E20) `:38` y A1 `:41`; **la A0 reutilizable es B-3** |
| (2).2 paso 0: `sameNumber(…5483, visible)` | **Parcial**: R1 con `collidesWithMessaging`, más 6.4 `:204` |
| (2).3 importación solo saliente con SK; admitida y sin freno | 5c `:172`, 7 `:223`, 8 `:233-243` |
| (2).4 el `.env` de la canaria | 6.1–6.5 `:181-210`, 7 y 10 `:312` |
| (2).5 el freno previo, tras una admitida | **Parcial**: D `:407` |
| (2).6 cuatro ojos; único canario activo | 13 `:354`; lo de «único», **falta la comprobación** |
| (2).7 conciliación horaria y 2 h | 8 `:238-244` y 14 `:364` |
| (2).8 primero salientes off o el freno, después la declaración | **Falta** (B-1) |
| (2).9 C-2, `planResultBytes` y el número que se ve | 14 `:371`, `:377`, `:379` |
| (2).10 `plansWithheld` y planes congelados | **Falta** (B-2) |
| N-2 como paso 0 | Igual que (2).2 |
| N-3 (geográficos CO, token rotado, OTP solo con Verify) | Paso 3 `:168` y R2 `:392`; lo del OTP es de (3) y no está |
| M-3 (`voice:resolve-ambiguous`) | D `:413` |

## Ejecutado [C] (línea final)
- `ensayar-ops8f.sh` (`arrancar` y `ensayar`), con los mandos de `dbda3a0b` y el código `16948226`: `87 OK · 0 FALLO · registro …/ensayo-ops8f-20261122-152651.txt`.
- `node sim-pruebas.cjs`: `95 OK · 0 FALLO · 0 OMITIDO`. `bash -n` de los tres guiones: limpio; `shellcheck` no está instalado.
- `sonda-compartida.sh` (20 formas de la salida de tinker): solo «si» con la forma admitida (y N-3).
- `sonda-banco.sh` (luego `parar`; el banco, en su estado inicial): (a) `código 0 · después: outbound=true collidesForPolicy=true` · (b) `el SID sale en la terminal: SÍ · el secreto … SÍ` · (c) `195 muestras … en argv: 0 veces` · (d) `… la cita: ninguno` · limpieza: `valla: sin hosts de fuera`.
- Huellas de `dbda3a0b`: `6bd98ae2` `db842269` `cd7a5a7a` `be6c3c47`. Doble de Twilio: 304 GET. Grep: solo números sintéticos.

## Lo que NO pude verificar
- **El proveedor real:** los permisos de las keys L y E, la importación de un caller ID con API key, si `supports_inbound` sigue en la API, los geográficos, qué ve el …6026 y si ElevenLabs borró el Auth Token.
- **Producción:** monitor, freno, canarios activos, si el …5483 es exactamente `WHATSAPP_DISPLAY_PHONE` y si hay una A0 firmada antes del 22-11 [S]. `ssh -t`, sudo, supervisor y FPM reales: el banco los sustituye.
- **No repetí** `ensayar-ops8c.sh`, el E2E del H8 ni PHPUnit.
