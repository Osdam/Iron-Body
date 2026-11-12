# Runbook · primera llamada canaria de Sofía (H8, fase 4a-primero)

**Fecha:** 11-11-2026. **Autor:** devops. Este runbook no se ha ejecutado en producción. Todo lo que dice de los mandos está ensayado en el banco (`ops/banco-h9/telefonia-prep.sh`; informe `plans/informes-motores/informe-devops-telefonia-prep.md`).

**Base:** auditoría C (`plans/cierre/auditoria-c-voz-telefonia.md`), §3, §4.1, §4.4, §4.6 y §4.9.

**Qué sustituye:** para esta llamada, sustituye a los pasos 0.6, 2.3–2.5 y 3.1 de `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`. El paso 0.6 de ese documento escribe `TWILIO_VOICE_FROM=+57…5483`, que es el número de WhatsApp: **no se ejecuta**.

**Quién hace cada cosa:**

| Sigla | Quién |
|---|---|
| **P** | Propietario, en las consolas de Twilio y de ElevenLabs y en el CRM |
| **L** | Lead, en el servidor como root. Todas las órdenes `bash /root/…` se ejecutan desde `/root` |
| **B** | Segunda persona real con cuenta propia en el CRM. No puede ser P ni la cuenta técnica |

**Reglas generales:**
- Un paso no empieza hasta que el anterior imprima lo que dice la columna «Tiene que imprimir». Si imprime otra cosa, se para y se aplica su vuelta atrás.
- Cada orden que escribe el fichero de entorno deja una copia previa en `/root/env-pre-voice-<orden>-<fecha>` (600) e imprime su «vuelta atrás exacta». **Hay que guardar esa línea.** Las copias llevan secretos: cuando ya no hagan falta, se borran con `shred -u`.
- Ningún secreto pasa por el chat ni por la línea de órdenes. Se escriben en ficheros 600 con `vi` (ver `ops/modelos-root/README.md`).
- Los mandos que escriben el fichero de entorno se excluyen entre sí con cerrojos. Aun así, **no se lanzan dos a la vez**.

---

## A. Las autorizaciones escritas

Hacen falta **tres**. Cada una se pide y se guarda **antes** del paso que la usa. La referencia (por ejemplo `AUT-VOZ-1`) es la que se cita **literalmente** en el commit y en `--autorizacion=`.

> **A0 · Fase 2 (pasos 0 y 6).** Solo si no consta ya por escrito. Los mandos de la fase 2 la exigen (protección 13).
> «Autorizo instalar en `/root` los mandos actualizados de la voz (`h8-voice-scope.sh`, `el-verificar.sh` y `h7-monitor-scope.sh`, huellas del informe del 11-11). Autorizo también cargar en producción las piezas de Twilio de la voz con `h8-voice-scope.sh fase 2`: la key restringida, la cuenta, el número …____ (que **no** es el de WhatsApp …5483), el `phnum_…` importado y la declaración de que el número es solo saliente. Esto incluye apagar y volver a encender el monitor de voz alrededor de esa carga. Firma y fecha: ____»

> **A1 · El commit `voice(calls-open)` y su despliegue 4a (paso 9). Referencia `AUT-VOZ-1`.**
> «Autorizo abrir en el código las llamadas de voz: un commit cuyo asunto empieza por `voice(calls-open):` y que cambia **una sola línea**, `public const CALLS_ENABLED_IN_CODE = false;` → `true;` en `app/Services/Voice/VoiceCallPolicy.php`. Autorizo su despliegue con `deploy-h8-backend-fase1.sh --fase=4a --autorizacion=AUT-VOZ-1`. Autorizo, después de ese despliegue, el commit de pruebas que acompaña la apertura (C-4 del informe de devops), sin relajar ninguna guarda. Abrir la constante no hace ninguna llamada: las banderas, el cupo, el presupuesto, la publicación y el canario siguen cerrando. Firma y fecha: ____»

> **A2 · Encender las salientes en canario (paso 10). Referencia `AUT-VOZ-2`.**
> «Autorizo `bash /root/h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00`: solo alcance canario, como mucho 1 llamada al día y US$ 2,00 de presupuesto diario. La puerta reserva por llamada 0,9605 US$ a un móvil colombiano y 1,1220 US$ a cualquier otro destino. La vuelta atrás es `salientes off`. Firma y fecha: ____»

> **A3 · La primera llamada (paso 14). Referencia `AUT-VOZ-3`.** Es el texto de §3 de la auditoría, con las cifras de la puerta:
> Autorizo **una** llamada saliente de prueba de ULTRON («Sofía») desde el CRM, con estas condiciones:
> - **Origen:** el número …____ (identificador de llamada verificado en la cuenta de voz de Twilio), importado en ElevenLabs como solo saliente (`phnum_…`). **No** es el número de WhatsApp …5483.
> - **Destino:** mi teléfono registrado como **canario de voz** (…____), activado por otra persona. Ningún otro número.
> - **Agente:** el publicado en canario («Iron Body · voz · publication · <12 primeros caracteres de la huella>»), compilado con la versión «____» y el perfil «Sofia Medellin» rev. __, con el veredicto «aprobado» del laboratorio que dio ____.
> - **Cuándo:** el __-11-2026 entre las __:__ y las __:__. Horario legal: de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00. **Ni el 15-11, que es domingo, ni el 16-11, que es festivo.**
> - **Duración máxima:** 300 s, con corte automático del agente. 30 s de timbre. Si contesta un buzón, cuelga. Sin grabación ni audio guardado.
> - **Cupo:** 1 llamada al día. **Presupuesto del día:** US$ 2,00.
> - **Reserva previa del sistema:** US$ 0,9605 si el destino es un móvil colombiano (+573…) y US$ 1,1220 en cualquier otro caso. Son 5 minutos con un tramo de transferencia a fijo, que se reserva siempre.
> - **Coste real esperado:** unos US$ 0,12 por minuto (como mucho, unos 0,61 por 5 minutos). Twilio lo cobra del **mismo saldo que paga los SMS del inicio de sesión**.
> - **Resultado esperado:** suena mi teléfono; Sofía da el aviso de IA y el saludo; conversamos un momento y cuelga. En el CRM, la llamada queda «completada», con su post-llamada sin violaciones, su coste y sin freno.
> - **Cómo se corta:** cuelgo yo; o «Colgar» en el CRM; o en el servidor `sudo -u www-data php artisan voice:hangup --all --confirm`; y después `bash /root/h8-voice-scope.sh salientes off`. Una segunda llamada ese día la deniega el cupo.
>
> Firma y fecha: ____

---

## B. Avisos antes de empezar

- **C-6 · El saldo de Twilio es el del inicio de sesión.** La voz, las pruebas manuales y la llamada de verificación del identificador consumen el **mismo saldo** que los SMS del OTP. Sin saldo, **nadie puede iniciar sesión** en la app ni en el CRM. Antes de los pasos 2 y 14, P mira el saldo en Console → Billing.
  - Recomendación: no seguir con menos de US$ 10.
  - Recomendación: activar una alerta de saldo o la recarga automática.
  - Las dos son decisión de P.
- **C-5 · Un identificador verificado es «solo saliente».** Si el origen es un *Verified Caller ID*, hay que declararlo con `VOICE_NUMBER_INBOUND_CAPABLE=false` en el paso 6. Si no:
  - publicar (paso 12) intenta asignar el agente al número y responde **409 `voice_number_not_assigned`**;
  - `entrantes on` no aplica.

  `estado` lo enseña así: «el número admite entrantes (VOICE_NUMBER_INBOUND_CAPABLE): NO: «Outbound Only» declarado».
- **Qué NO hacer:**
  - en el servidor: `fase 2 set TWILIO_VOICE_FROM=+57…5483`; `fase 2 set ELEVENLABS_AGENT_PHONE_NUMBER_ID=` con el id de la importación de …5483; `monitor on --acepta-freno` sin saber por qué frenaría;
  - en la consola de ElevenLabs (§4.6), cualquiera de estas cosas echa el freno o bloquea:
    - asignar el webhook «ironbody-voz-post-llamada» a un agente propio o a todo el workspace;
    - probar o duplicar el agente publicado;
    - asignar un agente al número importado;
    - tocar los secretos o el agente de verificación;
    - revocar la clave vieja antes de que el servidor tenga la nueva.

---

## C. Los pasos

Leyenda del «Tipo»: **O** = obligatorio (sin él no hay llamada); **R** = recomendado.

### Paso 0 · L · Mandos actualizados y lecturas (con A0) — O

Desde el Mac, en la raíz `Iron-Body/`. `C` es el commit que confirme estos ficheros.

```bash
O=backend/docs/ultron-studio/ops
ssh ironbody-vps 'sha256sum /root/h8-voice-scope.sh /root/el-verificar.sh /root/h7-monitor-scope.sh'
#   esperado hoy: d8a18960… (L9-110), 7cc6100f… (el de HEAD; si no casa, PARAR y comparar) y c70537b7… (L9-110)
ssh ironbody-vps 'T=$(date -u +%Y%m%d-%H%M%S); for n in h8-voice-scope.sh el-verificar.sh h7-monitor-scope.sh; do cp -p /root/$n /root/$n.pre-telefonia-$T; done; ls -l /root/*.pre-telefonia-*'
for n in h8-voice-scope.sh el-verificar.sh h7-monitor-scope.sh; do
  git show "$C:$O/$n" | ssh ironbody-vps "umask 077; cat > /root/$n && chmod 700 /root/$n && sha256sum /root/$n"
done
#   tienen que salir las huellas nuevas de la tabla del informe (231e4a8a…, cd7a5a7a…, be6c3c47…)
```

Después, en el servidor, las lecturas R1–R6 de la auditoría (§2.1).

- **Tiene que imprimir:**
  - R1: `5483_es_whatsapp: true`;
  - R2 y R3: `ok`, sin `wouldOpen` y freno suelto;
  - R4: «TODO REGISTRADO»;
  - R5: `carrier=not_configured`, `unknown 0`.
- **Vuelta atrás:** `install -m 700 /root/<mando>.pre-telefonia-<T> /root/<mando>`.

### Paso 0.R · L con P en la terminal · Rotar la clave de ElevenLabs (§4.9) — R

Es independiente de la llamada. Se hace **antes del paso 6**, o después de la llamada, **nunca entre los pasos 9 y 14**. Hay que hacerla sin ninguna sesión del laboratorio abierta y fuera de los minutos 0, 5, 10, 20, 30, 40 y 50.

1. P crea la clave nueva en la consola. **No** revoca la vieja.
2. Se deja en un fichero 600:
   ```bash
   install -m 600 -o root -g root /dev/null /root/el-clave.txt && vi /root/el-clave.txt
   ```
3. Se ejecuta el mando:
   ```bash
   bash /root/el-verificar.sh /root/el-clave.txt --solo-clave
   ```
   - **Tiene que imprimir** «ELEVENLABS VERIFICACIÓN TERMINADA» y una línea «vuelta atrás (solo mientras la clave de antes no esté revocada en la consola): install -m …». Hay que guardar esa línea.
   - Si otro mando está escribiendo, aborta sin tocar nada: «otro mando que escribe el fichero de entorno está en curso».
   - Ante cualquier fallo, **restaura solo la copia previa y la caché, y lo comprueba**: «restaurado y COMPROBADO», «ElevenLabs NO se apaga». Si la restauración no se puede comprobar, imprime la orden a mano.
4. Después, las lecturas de §4.9, paso 4. B reconoce la configuración en el CRM. Por último, P revoca la vieja y L ejecuta `sudo -u www-data php artisan voice:doctor --verify`.

- **Vuelta atrás:** la línea guardada. Solo vale hasta que P revoque la clave vieja; después, la vuelta es otra clave nueva.

### Pasos 1–5 · P · Consolas — O, salvo donde se indica

| # | Dónde | Qué | Comprobar | Vuelta atrás | Tipo |
|---|---|---|---|---|---|
| 1 | Twilio | Crear la subcuenta «Voz». Mirar el saldo (C-6) | Aparece un AC… nuevo | Cerrarla (sin coste) | R |
| 2 | Twilio (subcuenta) | Verified Caller IDs → Add → un número que **no** sea …5483 ni el remitente del OTP → Twilio llama → teclear el código | Queda «verified» | Borrarlo | O (o comprar un número) |
| 3 | Twilio (subcuenta) | Voice → Geo permissions: Colombia | Habilitado | — | O si la subcuenta no lo hereda |
| 4 | Twilio (subcuenta) | API keys → Restricted, solo voz (`ops/modelos-root/README.md`) → dejarla en `/root/tw-voz.txt` (600, con `vi`, junto a L) | Lo comprueba el paso 7 | Revocar la key | O |
| 5 | ElevenLabs | Phone numbers → Import from Twilio: el número del paso 2, con el SID y el token **de la subcuenta**, **sin asignar agente** → anotar el `phnum_…` y los 4 últimos dígitos | Saliente sí, entrante no | Borrar la importación | O |

### Paso 6 · L (con A0) · Las piezas de la fase 2 — O

Las órdenes van una a una. Hay que esperar el «APLICADO» de cada una.

```bash
bash /root/h8-voice-scope.sh monitor off
bash /root/h8-voice-scope.sh fase 2 secreto /root/tw-voz.txt
bash /root/h8-voice-scope.sh fase 2 set TWILIO_VOICE_ACCOUNT_SID=AC… TWILIO_VOICE_FROM=+57… ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_… VOICE_NUMBER_INBOUND_CAPABLE=false
#   B, en el CRM: Studio → Integraciones → Voz → reconocer la configuración (la huella de la voz cambió con las piezas)
bash /root/h8-voice-scope.sh expectativa fase2      # si el monitor del H7 está encendido (h7-monitor-scope.sh estado)
bash /root/h8-voice-scope.sh monitor on             # primero concilia en seco: si frenaría, se niega
bash /root/h8-voice-scope.sh estado
```

- **Tiene que imprimir:**
  - «MANDO monitor-off APLICADO», «MANDO fase2-secreto APLICADO» y «/root/tw-voz.txt destruido»;
  - «MANDO fase2-set APLICADO», «MANDO expectativa-fase2 APLICADO» y «MANDO monitor-on APLICADO»;
  - `estado`: «número de voz igual a uno de WhatsApp: no», «NO: «Outbound Only» declarado», `subcuenta=si`, `numero=si` e `importado=si`.
- **Tiempo:** la expectativa y el reconocimiento van en menos de 30 minutos. Si no, el monitor del H7 abriría `integrations_drift` o `config_drift:voice`.
- **Si `fase 2 set` se niega por el número:** es el de WhatsApp. **PARAR**.
- **Si `monitor on` se niega porque frenaría:** probablemente es la llamada de verificación de Twilio en la ventana de 48 h [H]. Se espera a que salga de la ventana. Encender con `--acepta-freno` solo si se entiende la causa.
- **Vuelta atrás:** la «vuelta atrás exacta» que imprime cada orden (instalar su copia previa y regenerar la caché), en orden inverso. La funcional: `monitor off`.

### Paso 7 · L · `bash /root/h8-twilio-check.sh` — R

- **Tiene que imprimir:**
  - «VEREDICTO: APTA»;
  - «CALLER ID VERIFICADO de esta cuenta»;
  - «la key NO puede leer mensajes»;
  - 0 llamadas ajenas.
- Con `FALLO`, se para.
- **Vuelta atrás:** no hace falta (solo lee).

### Paso 8 · L · El número observado — O

1. Esperar a la pasada horaria de `voice:reconcile --numbers` (minuto 0).
2. Ejecutar `sudo -u www-data php artisan voice:doctor --telefonia --json`.

- **Tiene que imprimir:** `telefonia.number.check` = `verified`, `brakeOpen` = `false` y `webhookHealth` en `ok`.
- **Cuidado:** la puerta exige una observación de **menos de 2 h**. Con el monitor encendido, la pasada horaria la renueva sola. Hay que volver a mirarlo justo antes del paso 14.
- **Vuelta atrás:** no hace falta (solo lee).

### Paso 9 · L (con A1) · El commit `voice(calls-open)` y el despliegue 4a — O

El procedimiento completo está en el informe de devops, C-4 (opción recomendada). En resumen:

**Antes, sin tocar `main`:** el commit de pruebas `test(voice): …` ya está preparado y revisado en una rama aparte. Ver C-4.

1. Respaldo nuevo `BK`, con `api-commit.txt` = el commit que sirve producción (plan de despliegue, paso 3).
2. Commit `voice(calls-open): …` en el repo:
   - una sola línea, `app/Services/Voice/VoiceCallPolicy.php:23`;
   - un solo padre;
   - el mensaje cita `AUT-VOZ-1`.

   Después, push a `main` con la orden del propietario.
3. Ensayo y despliegue:
   ```bash
   bash /root/deploy-h8-backend-fase1.sh <commit> $BK --fase=4a --autorizacion=AUT-VOZ-1 [--acepta-main=<commits de solo documentación>] --solo-comprobar
   bash /root/deploy-h8-backend-fase1.sh <commit> $BK --fase=4a --autorizacion=AUT-VOZ-1 [--acepta-main=…]
   ```
4. **Solo con «COMPLETADO»:** push del commit de pruebas a `main` (C-4).

- **Tiene que imprimir:** «DESPLIEGUE H8 FASE 4a (voice(calls-open) <commit>, autorización «AUT-VOZ-1») COMPLETADO». Después, `estado` con «constante de llamadas: abierta».
- **Vuelta atrás:**
  - nivel 0: `salientes off`;
  - nivel 4: `( cd /var/www/api && umask 022 && git checkout -q --detach $(cat $BK/api-commit.txt) )`, cachés como www-data y `queue:restart`; en el repo, un commit que revierta las pruebas y la línea (C-4).
- **Mientras la constante esté abierta**, `deploy-h9-backend-cierre.sh` y `deploy-m1-backend.sh` **se niegan** (exigen la constante cerrada en `origin/main`), y ningún commit puede volver a tocar `VoiceCallPolicy.php`: el mando dejaría de verla «abierta».

### Paso 10 · L (con A2) · Salientes en canario — O

```bash
bash /root/h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
#   B, en el CRM: reconocer la configuración (salientes on cambia la huella de la voz, H-25)
bash /root/h8-voice-scope.sh expectativa llamadas   # si el monitor del H7 está encendido
```

- **Tiene que imprimir:**
  - la línea «reserva de la puerta por saliente (…): 0.9605 US$ a un móvil colombiano (+573…) · 1.1220 US$ a cualquier otro destino · peor caso 1.1220 US$ (presupuesto mínimo: 1.13); el presupuesto (2.00 US$) la cubre»;
  - «MANDO salientes-on APLICADO».

  La puerta todavía dice `no_publication`: es lo esperado.
- **Se niega**, con código 2 y sin tocar nada:
  - con menos de 1.13 US$ (C-1);
  - sin el worker `ironbody-voice`;
  - sin las piezas;
  - con la constante cerrada;
  - si la fórmula de la puerta cambió en el código.
- **Vuelta atrás:** `bash /root/h8-voice-scope.sh salientes off` (cupo y presupuesto a 0). Después, B reconoce otra vez.

### Paso 11 · B · Veredicto del laboratorio — O

Veredicto «aprobado» sobre una sesión de la **misma compilación** que se publicará. B no puede ser quien abrió la sesión ni quien creó la versión.

- **Tiene que verse:** `compile-preview` con `labVerdict.latest=approved`.
- **Vuelta atrás:** hay un veredicto por sesión. Si hay que rehacerlo, otra sesión.

### Paso 12 · Quien no creó la versión ni el perfil · Publicar en canario — O

Se hace de 07:00 a 19:00 de Bogotá: CRM → Studio → Voz → previsualizar → Publicar en canario. Después, en el servidor:

```bash
bash /root/h8-voice-scope.sh estado
SMOKE_PHASE=4a bash /root/smoke-run-h8.sh
```

- **Tiene que verse:** 201 en el CRM y `number.outcome=not_applicable_outbound_only`. En el servidor, `cadena=canary:ok` y el smoke 4a sin FALLO.
- **Si da 409 `voice_number_not_assigned`:** falta la declaración del paso 6 (C-5).
- **Vuelta atrás:** «Apagar la voz» (nivel 2). En la primera publicación no hay a qué revertir.

### Paso 13 · P y B · Canario de voz — O

CRM → Integraciones → Canarios, canal voz: P registra su teléfono con su consentimiento; B lo autoriza; B lo activa.

- **Tiene que verse:** «activo».
- **Vuelta atrás:** desactivar o revocar.

### Paso 14 · Persona con `telephony.call_place` (con A3) · La llamada — O

**Justo antes**, todo esto tiene que cumplirse:
- día y hora legales;
- el saldo de Twilio (C-6);
- `voice:doctor --telefonia --json`: `number.check=verified` con menos de 2 h, `brakeOpen=false` y `callsEnabledInCode=true`;
- `estado`: `cadena=canary:ok` e `ironbody-voice` en RUNNING.

**La llamada:** CRM → Voz y telefonía → Llamadas → nueva → el canario.

**Tiene que verse:**
- 202 en el CRM;
- la fila pasa por `requested → dialing → active → completed`;
- la post-llamada, procesada y sin violaciones, con su coste;
- `sudo -u www-data php artisan voice:reconcile --dry-run --json`: `unknown` 0 y `wouldOpenBrake` false;
- una segunda petición ese día da `daily_cap_reached`.

**Vuelta atrás:**
1. Colgar en el CRM, o `sudo -u www-data php artisan voice:hangup --all --confirm`.
2. `bash /root/h8-voice-scope.sh salientes off`.

**Al terminar:** `salientes off` (R), salvo que P autorice por escrito seguir hacia las 3 llamadas de la evidencia del canario. Después, B reconoce la configuración.

---

## D. Si algo sale mal

| Lo que se ve | Causa probable | Qué hacer |
|---|---|---|
| `budget_exhausted` con el cupo libre | Presupuesto por debajo de la reserva (C-1) o gasto ya comprometido hoy | `estado` y `voice:doctor --telefonia --json` (`limits`, `today`). No subir el presupuesto sin autorización |
| `elevenlabs_unverified` | La marca de 30 días caducó | `sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice` (un GET), con autorización |
| `number_unverified` (con `telefonia.number.checkReason` = `stale` o `no_observation`) | Sin una conciliación del número en las últimas 2 h | Esperar a la pasada horaria con el monitor encendido |
| `number_mismatch` | Lo que ve la conciliación no es el número de `TWILIO_VOICE_FROM` | PARAR: revisar la importación (paso 5) y las piezas (paso 6) |
| `voice_braked` | Freno echado (§4.6) | Leer la causa en el CRM. Lo suelta una persona con `ultron.voice_publish` y una nota |
| `voice_number_not_assigned` (al publicar) | Falta `VOICE_NUMBER_INBOUND_CAPABLE=false` (C-5) | Con A0: `salientes off` → `fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false` → `salientes on …` → reconocer → publicar |
| La llamada se queda en `requested` | Worker `ironbody-voice` parado | `supervisorctl status`. La fila pasa a `ambiguous` a los 15 min (la conciliación) |
| Cualquier mando: «otro mando que escribe el fichero de entorno está en curso» | Otro mando en marcha | Esperar a que termine. No se ha tocado nada |
