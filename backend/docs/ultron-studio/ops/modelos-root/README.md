# Ficheros MODELO para `/root` del servidor (Hito 8, fases 2 → 4a)

Plantillas **sin valores** de los tres ficheros que el propietario deja en `/root` para que los guiones de la voz lean
los secretos **sin que pasen por la línea de órdenes, `ps`, el historial de la shell ni el chat**. Cada guion destruye
su fichero al terminar (`shred -u`, o `rm -f` si no hay `shred`). Preparados por OPS8-d (10-11-2026); ninguno lleva un
valor real.

| Modelo | Fichero en el servidor | Lo lee | Cuándo |
|---|---|---|---|
| `el-clave.txt.modelo` | `/root/el-clave.txt` | `bash /root/el-verificar.sh /root/el-clave.txt <agent_id>` (rotación de la clave, E2-2; **antes** del registro de secretos) o `… --solo-clave` (una rotación **después** del registro, H-16) | Fase 2 |
| `el-registro.txt.modelo` | `/root/el-registro.txt` | `bash /root/h8-secrets-register.sh --clave-fichero=/root/el-registro.txt` (una clave **temporal** de usuario, 15 min, solo `convai_read`, `convai_write` y `webhooks_write`), si la clave de producción no puede crear secretos o webhooks | Fase 2 (opcional) |
| `tw-voz.txt.modelo` | `/root/tw-voz.txt` | `bash /root/h8-voice-scope.sh fase 2 secreto /root/tw-voz.txt` (la key restringida de Laravel en la (sub)cuenta de la voz y, si se quiere, el token de firma) | Fase 4a-primero, paso 3 |

Cómo se crean en el servidor (como root; el editor no deja copia si es `vi`/`vim` con `set noswapfile` o `nano` sin
respaldo; nunca `echo` con el valor en la línea de órdenes):

```bash
install -m 600 -o root -g root /dev/null /root/el-clave.txt && vi /root/el-clave.txt      # una sola línea: la clave
install -m 600 -o root -g root /dev/null /root/el-registro.txt && vi /root/el-registro.txt # una sola línea: la clave temporal
install -m 600 -o root -g root /dev/null /root/tw-voz.txt && vi /root/tw-voz.txt          # CLAVE=VALOR por línea (abajo)
```

Formas que exige cada guion (se rechaza lo que no case, sin imprimir el valor):

- `el-clave.txt` y `el-registro.txt`: **una línea** con la clave tal cual la enseña la consola de ElevenLabs (`el-verificar.sh` la lee entera con `read().strip()`; `h8-secrets-register.sh` la pasa a PHP por la entrada estándar).
- `tw-voz.txt`: solo estas tres claves, cada una una vez; `TWILIO_VOICE_API_KEY_SID` y `TWILIO_VOICE_API_KEY_SECRET` van **juntas** (son un par); `TWILIO_VOICE_SIGNING_TOKEN` es opcional (vacío = sin avisos de gasto de Twilio para la voz; con la cuenta madre se deja fuera: D8-4).
  - `TWILIO_VOICE_API_KEY_SID`: `SK` + 32 hex;
  - `TWILIO_VOICE_API_KEY_SECRET`: 32 caracteres alfanuméricos;
  - `TWILIO_VOICE_SIGNING_TOKEN`: 32 hex (el Auth Token de la **subcuenta**, nunca el de la cuenta del OTP).

Si el mando rechaza `tw-voz.txt` («no tiene la forma esperada»), el fichero **se queda** en disco (600, root) para corregirlo sin volver a la consola (Twilio enseña el secreto de una key una sola vez); si se aplica, se destruye.
