# Sesión real del laboratorio hablado contra producción (H8 · D8-23)

Herramientas del lead para **demostrar** que el laboratorio hablado conversa de verdad: el CRM de producción en un
Chrome sin cabeza, con un micrófono falso que reproduce un WAV (voz sintética de macOS), contra el agente de laboratorio
que el servidor compila y despliega en ElevenLabs. Es audio real por el camino real (cliente oficial → WebSocket →
agente). No es una simulación de texto. El E2E del CRM (`ops/h8-e2e-crm`) NO cubre esto: su simulador no modela el
WebSocket.

**Gasta**: una sesión del cupo diario y unos céntimos del presupuesto del laboratorio (67 s ≈ US$ 0,09 el 11-11-2026).
Solo con la orden del propietario de probar el laboratorio.

## Piezas

| Fichero | Dónde corre | Qué hace |
|---|---|---|
| `lab-tecnica-cuenta.sh abrir|cerrar` | servidor, root (copiarlo a `/root`) | Activa la cuenta temporal de humo (id 32) con una contraseña de un solo uso, inicia sesión por la API pública y deja la sesión en un fichero 600 de root, sin imprimirla; programa un cierre automático a los 25 min. `cerrar` la deja en reposo, comprueba que la sesión ya no vale y borra el fichero. |
| `lab-tecnica.cjs <sesion.json> <audio.wav> <salida> <versión> <perfil> [segundos]` | Mac | Abre la sesión desde el CRM, registra el WebSocket (tipos de trama, bytes de audio en cada sentido, el diálogo que transcribe el proveedor), cuelga y espera el cierre por el informe post-llamada. Nunca escribe la sesión del CRM, la URL firmada ni el id completo del agente. |
| `sonda-microfono.cjs <audio.wav|pitidos> [segundos]` | Mac | Sin proveedor ni gasto: mide si el audio del micrófono falso llega de verdad al grafo de audio del navegador. |
| `sonda-conversacion.php <uuid de la sesión>` | servidor, www-data | Solo lectura: qué dice ElevenLabs de la conversación (estado, motivo de terminación, error) y cómo quedó el agente. |

## Pasos

```bash
scp lab-tecnica-cuenta.sh ironbody-vps:/root/ && ssh ironbody-vps 'chmod 700 /root/lab-tecnica-cuenta.sh && bash /root/lab-tecnica-cuenta.sh abrir'
scp ironbody-vps:/root/.lab-tecnica-sesion.json ./s.json          # 600; no imprimirlo nunca
node lab-tecnica.cjs "$PWD/s.json" "$PWD/persona.wav" "$PWD/out" <versionUuid> <profileUuid> 80
ssh ironbody-vps 'bash /root/lab-tecnica-cuenta.sh cerrar'; rm -f s.json
```

El WAV: PCM de 16 bits, mono, 48 kHz; Chrome lo reproduce **en bucle y desde el principio cada vez que se abre el
micrófono**. El agente habla unos 19 s al empezar: la primera frase de la persona va a partir del segundo 23.

## Dos trampas ya pagadas

1. **El micrófono falso de Chrome da silencio** si el servicio de audio corre en su caja de arena: no puede leer el WAV.
   Hace falta `--disable-features=AudioServiceSandbox,AudioServiceOutOfProcess`. Síntoma: el proveedor transcribe «...»
   y el agente pregunta «¿sigues ahí?». Compruébalo antes con `sonda-microfono.cjs` (gratis).
2. **Los valores por defecto de las variables dinámicas del agente no valen en una conversación real** (son de prueba de
   la consola del proveedor): sin `dynamicVariables` al abrir, ElevenLabs cierra con 1008 «Missing required dynamic
   variables in tools». Arreglado en la enmienda E18 del contrato del H8 (API `120a8b2`, CRM `f5cd0d2`).

Evidencia del 11-11-2026: `ops/evidencia-h8/2026-11-11/laboratorio-sesion-real/` (tres intentos: el fallo, el arreglo
con el micrófono mudo y la conversación de ida y vuelta).
