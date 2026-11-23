# Informe de devops · PT-04: los tres bloqueantes de la revisión de los mandos de la voz y del runbook de la primera llamada (22-11-2026)

**Encargo:** B-1, B-2 y B-3 de `informe-security-mandos.md` y cinco condiciones de su veredicto, solo como frases del runbook. Nada más: N-1 a N-6 no se tocan. **Entorno: solo el Mac (banco local en `~/.cache`). NO es producción: ni `ssh`, ni Twilio, ni ElevenLabs, ni red** (la valla no vio ningún host de fuera). `CALLS_ENABLED_IN_CODE` sigue en `false`. Rama `integracion/voz` sobre `b461c205`; los mandos y el runbook no cambiaban desde `dbda3a0b` (`git diff --stat` vacío) [C]. Commits: `0a1e5c28` (mando y banco) · `ed454310` (runbook) · el de este informe. **[C]** ejecutado · **[L]** leído · **[S]** supuesto.

## B-1 · «primero `salientes off`, después `numero-compartido off`», forzado y escrito
- **Mando** (`h8-voice-scope.sh`): `:962` `APAGA_SALIENTES=0`; `:981-989`, si las salientes **no constan apagadas** (`$OUT != false`), `numero-compartido off` añade a la MISMA escritura las claves de `salientes off` (`:922-924`: `VOICE_OUTBOUND_ENABLED=false TWILIO_VOICE_DAILY_CALL_CAP=0 VOICE_DAILY_BUDGET_USD=0` y `TWILIO_VOICE_ENABLED=false` si las entrantes no están encendidas) y dice qué apaga y por qué; `:991-995` el JSON de la escritura; `:1213-1216` dos avisos (no cuelga una llamada en curso: `voice:hangup --all --confirm`; huella de voz H-25). `:17` la línea de uso. Con las salientes apagadas, la orden es la de siempre. Todo lo demás del mando, sin tocar.
- **Runbook:** el orden «1) `salientes off` (y `voice:hangup --all --confirm` si hay una llamada en curso); 2) `numero-compartido off`», y que el mando lo fuerza, en `:74` (tabla de autorizaciones), `:219` (paso 6), `:251` (paso 8) y una fila nueva en D (`:417`).
- **Banco [C]**, caso (a), salida del mando: `== las salientes están encendidas (VOICE_OUTBOUND_ENABLED: true): esta orden las APAGA en la misma escritura …` · `3. … budget: "2.00" → "0" · outbound: true → false · outbound_cap: 1 → 0 · shared_declared: true → false · twilio_voice: true → false` · `AVISO (B-1): … quedan APAGADAS …` · `MANDO numero-compartido-off APLICADO`.
```
OK      B-1 (a) · una sola escritura: UNA copia previa nueva (la de numero-compartido-off), que es el fichero con las salientes encendidas  [7 copias previas (antes 6)]
OK      B-1 (a) · el fichero: VOICE_OUTBOUND_ENABLED=false, TWILIO_VOICE_DAILY_CALL_CAP=0, VOICE_DAILY_BUDGET_USD=0, TWILIO_VOICE_ENABLED=false y la declaración en false
OK      B-1 (c) · la vuelta atrás EXACTA de ese «off» devuelve byte a byte el fichero de antes (salientes y declaración)
OK      B-1 · el mando de ANTES solo retira la declaración (el defecto de B-1: no apaga las salientes)
OK      B-1 (b) · el fichero de entorno queda byte a byte como con el mando de antes
OK      B-1 (b) · y la salida es la misma línea a línea (salvo la fecha de la copia y el nombre del mando)
```
- **El caso (a) CAE con el mando de antes [C]:** el mismo ensayo, con `h8-voice-scope.sh` de `b461c205` (`6bd98ae2…`) en el lugar del nuevo (copia fuera del repo, `MANDO_ANTES`): `103 OK · 6 FALLO`, los seis de (a) («lo dice», el antes → después, los dos avisos, el fichero, `salientes: no`); (b), (c) y los 87 de antes, en verde.

## B-2 · los planes (solo texto)
- Paso 11 (`:343`): en la sesión que se aprueba, se le piden los planes a Sofía; B lee lo que entrega `consultar_planes` y `plansWithheld` (la línea `voice.tool` con `"lab":true` de `storage/logs/voice-<fecha>.log` lleva `plans_withheld`: `VoiceTools.php:348`, `:568`) [L]; ningún nombre ni texto de plan con una instrucción; si alguno la lleva, no se aprueba.
- Paso 14 (`:375`): «nadie con `plans.manage` edita planes desde el veredicto del paso 11 hasta colgar» (si se editan, se para y se vuelve al 11).

## B-3 · la A0 nueva (solo texto)
- `:39`: «Se pide de NUEVO con este texto (E20), fechada el 22-11-2026 o después»; una anterior no cubre `numero-compartido on` ni `TWILIO_VOICE_FROM=…5483`, aunque conste por escrito. Lo mismo en la tabla de autorizaciones (`:69`).

## Las cinco frases (ninguna estaba ya bien escrita: faltaban o estaban a medias [C, grep])
- (1).4 → apartado B, `:91` (el CRM seguirá enseñando el aviso del número; L avisa a B antes del paso 8). Lo del CRM es [L] (diseño, punto 2): el CRM no está en este árbol.
- (2).2 → paso 0, `:138` (`sameNumber(<…5483 como en R1>, config("meta.whatsapp_display_phone"))` = `true`; si no, PARAR).
- (2).3 → paso 6.1, `:192` (`ssh -t …`, nunca `-tt` ni desde un agente, cada valor al salir su pregunta).
- (2).4 → paso 13, `:363`, y paso 14, `:374` (exactamente UN canario de voz activo, el …6026).
- (2).5 → D, `:416` (el freno se suelta solo después de ver «ADMITIDA por el servidor» en `estado`).

## Huella nueva del mando
`h8-voice-scope.sh` **`15135fba99f4a03c8f444412b16a32de8be536cf0d409400e48381e4a29b9c12`** (antes `6bd98ae2…`); `h8-twilio-check.sh` sin cambios (`db842269…`). Actualizada en el paso 0 del runbook (`:119`, `C` = `0a1e5c28`, y `:130-131`), único sitio de `backend/docs` y `docs` (fuera de los informes) que la cita [C, grep]. Los informes anteriores no se reescriben.

## Verificación [C] (líneas finales)
- `bash -n h8-voice-scope.sh` y `bash -n ensayar-ops8f.sh`: limpios. `shellcheck` no está instalado.
- `ensayar-ops8f.sh` (`montar` → `arrancar` → `ensayar` ×2 → `parar`), banco nuevo `~/.cache/ironbody-banco-h8-ops8f-pt04`, código `b461c205`: `109 OK · 0 FALLO · registro …/ensayo-ops8f-20261122-170254.txt` y, otra vez, `109 OK · 0 FALLO · registro …/ensayo-ops8f-20261122-171540.txt` (87 de antes + 22 de B-1).
- Con el mando de antes: `103 OK · 6 FALLO · registro …/ensayo-ops8f-20261122-170936.txt`.
- `node ops/banco-h8/sim-pruebas.cjs`: `95 OK · 0 FALLO · 0 OMITIDO`.
- Grep del diff: ningún teléfono completo, SID, `SK…`, `AC…` ni `phnum_…`.

## Lo que NO pude ensayar
- Producción y los proveedores reales (fuera del encargo): `ssh -t`, `sudo`, supervisor, FPM; si en producción hay una A0 anterior; qué enseña de verdad el CRM con la identidad admitida.
- Las frases de B-2, B-3 y las cinco condiciones son texto: no hay nada que ejecutar en el banco. Las entrantes encendidas con la declaración puesta (rama `TWILIO_VOICE_ENABLED` de B-1) no se pueden alcanzar con los mandos (`numero-compartido on` y `entrantes on` se excluyen): sin ensayar [S: mismo código que `salientes off`].
- No repetí `ensayar-ops8c.sh`, el E2E del H8 ni PHPUnit (prohibido en este árbol).

## Para la comprobación puntual de security
1. `h8-voice-scope.sh:981-995`: la condición es `$OUT != false` (falla cerrado: también con un valor ilegible). Efecto nuevo, más estricto: con la declaración ya retirada y las salientes encendidas con un número dedicado, `numero-compartido off` ahora también las apaga (antes solo escribía la declaración, o nada si ya estaba en `false`) [L: `:1007-1014` y `:1045`; no ensayado].
2. Que con las salientes apagadas no cambia nada: casos (b) del banco (fichero y salida iguales a los del mando de antes).
3. Runbook: las líneas «(PT-04)» de arriba, en particular que ninguna afloja una condición.

## Riesgos, drift y deuda
- **Para decidir el lead:** el punto 1 de arriba (efecto en el número dedicado). Previo y sin tocar: el runbook dice «Hacen falta **tres**» autorizaciones (`:35`) y enumera cuatro (A0–A3).
- `~/.cache`: queda el banco nuevo, parado y **sin base** (`borrar` lo quita del todo). El banco anterior (`…-ops8f`) queda con sus registros (los citan los informes de devops y security), parado y sin base: su `ensayar` la rehace. `ultron_studio_banco_h8_ops8f` quitada. Sin secretos en ficheros ni salidas; las piezas del banco son sintéticas.
