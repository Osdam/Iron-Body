# Evidencia de OPS8-e (10-11-2026) · B-T1, B-T7, B-T13, B-T14, `contactos on|off` y las tareas de voz de B-T5

Todo es local (Mac; simuladores en 127.0.0.1 y la valla): nada se ejecutó en producción ni contra ElevenLabs ni Twilio. Ningún fichero lleva secretos, tokens ni teléfonos completos (barrido de cada fichero antes de copiarlo; `SHA256SUMS` al final). Horas en UTC (Bogotá = UTC−5). Detalle y cifras: `plans/informes-h8/informe-ops8e.md` (resumen en `informe-devops.md`, §OPS8-e).

**Tres pasadas, en tres bancos montados desde cero con la misma receta** («producción» `fe29126` → el 1b `a608450` con el guion NUEVO en modo normal → el commit `voice(calls-open)` fabricado en el ORIGEN del banco, nunca en el repo, y desplegado con `--fase=4a`):

- **Pasada 1** (`p1-*`, banco `~/.cache/ironbody-banco-h8-ops8e`, 23:25–00:01 UTC, con el Mac a una carga media de 106–124 en 8 núcleos): todo en verde hasta la saliente, incluido **el despliegue 4a por el guion (COMPLETADO, 0 desvíos)** y sus 14 casos negativos; se cortó en la puerta de la saliente por **H-35** (el reloj de la costura del banco, fijado al empezar dentro del horario, dejó la marca de verificación «en el futuro»: `11-h35-reloj-de-la-costura.txt`). Sus smokes, con los FALLO de tiempo separados, son funcionalmente verdes (`p1-10-re-veredicto-de-los-smokes.txt`).
- **Pasada 2** (`p2-*`, banco `~/.cache/ironbody-banco-h8-ops8e2`, 00:10–00:50 UTC, H-35 corregido, carga baja): **89 OK · 1 FALLO**; B-T1 entero en verde otra vez (el despliegue 4a COMPLETADO, 0 desvíos, y los 14 negativos); el FALLO, y el corte en la publicación, por **H-36** (la segunda API, con el reloj 12 h por delante, vio caducadas las sesiones de administración: `403 admin_token_invalid`). Se detuvo a mano antes de que el ensayo de OPS8-e corriera sobre un estado que no es el suyo.
- **Pasada 3** (sin prefijo, banco `~/.cache/ironbody-banco-h8-ops8e3`, 00:36–01:23 UTC, con H-35 y H-36 corregidos): **`ensayar-ops8d.sh` 127 OK · 0 FALLO** —la secuencia completa: B-T1 y B-T7 en el punto de partida, fases 2 y 2a, la 4a con el despliegue por el guion y sus 14 negativos, la saliente punta a punta, la vuelta atrás del código y el cierre limpio— y **`ensayar-ops8e.sh` 55 OK · 0 FALLO** (dos ejecuciones seguidas). Su primera ejecución (`05a-*`, 51 OK · 2 FALLO) destapó un error del mando del H7 en `estado` (un `case` dentro de `$(…)` que el bash 3.2 del Mac no admite) y otro del arnés (copiaba un `VoiceTelephonyStatus.php` con trabajo de B-T5 que el banco no tiene: HTTP 500); los dos se corrigieron antes de la ejecución final.

| Fichero | Qué es |
|---|---|
| `01-montaje.txt` (y `p1-`/`p2-`) | `montar.sh` (238 tablas con datos en el respaldo) |
| `02-arranque-e112-siembra-linea-base.txt` (y `p1-`/`p2-`) | `arrancar`, `e112` (4 OK), `sembrar --canarios-voz` (2 canarios de voz; solo los 4 últimos dígitos) e `import-baseline` |
| `03-despliegue-1b-modo-normal.txt` (y `p1-`/`p2-`) | El 1b con el guion NUEVO en modo normal (regresión): paso 0 EN VERDE y `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO`, 0 desvíos, con B-T7 en el paso 4 |
| `04-ensayo-ops8d.txt` · `04-registro-ensayo-ops8d.txt` (y `p1-`/`p2-`) | `ensayar-ops8d.sh`: B-T1 y B-T7 en el punto de partida, fases 2 y 2a, la 4a con el guion y sus negativos, la saliente y la vuelta atrás (salida y registro completo) |
| `04-smoke-1-con-perfil.txt` · `04-smoke-1-con-perfil-smoke-de-antes.txt` (y `p1-`/`p2-`) | B-T7: `SMOKE_PHASE=1` con un perfil aprobado, con el smoke nuevo y con el de antes (A/B) |
| `04-smoke-2a-fake.txt` · `04-smoke-2a-elevenlabs.txt` · `04-smoke-4a.txt` (y `p1-`/`p2-`) | Los smokes de las fases 2a y 4a |
| `04-despliegue-4a.txt` · `04-registro-del-guion-h8-fase4a.log` (y `p1-`/`p2-`) | El despliegue 4a: la salida y el registro del propio guion, con la referencia de la autorización en la cabecera y en el cierre |
| `04-registros-del-guion-solo-comprobar-4a.txt` (y `p1-`/`p2-`) | Los registros de cada `--solo-comprobar` (los negativos y los verdes) |
| `05-ensayo-ops8e.txt` · `05-registro-ensayo-ops8e.txt` (y `05a-*`, la primera ejecución) | `ensayar-ops8e.sh`: B-T13, B-T14, 14 workers, `contactos on\|off` (con el código de B-T4 copiado un momento al banco) y `monitor on\|off` |
| `06-prueba-expansiones-deploy.sh` y `.salida.txt` | Prueba aislada de las expansiones del modo 4a: 38 OK · 0 FALLO |
| `06-prueba-smoke-h8.py` y `.salida.txt` | Prueba aislada de la lógica nueva de `smoke-h8.py`: 14 OK · 0 FALLO |
| `07-b4-y-head-solo-lectura.sh` | El comando de SOLO LECTURA para el lead (B-4 y el HEAD de producción) |
| `08-h29-tablas-del-h9.txt` | H-29: el lanzador del smoke sobre una base con el esquema del H9 (solo lectura): «18 y 7» aborta, la comprobación nueva pasa |
| `09-tareas-de-voz-del-codigo.txt` | B-T5: 4 tareas de voz con el código del banco y 5 con el del árbol (`voice:webhook-health`) |
| `10-re-veredicto-de-los-smokes.txt` · `p1-10-…` · `p2-10-…` | El veredicto funcional de cada smoke con los FALLO de tiempo (presupuestos de PERF8 y `public401.maxMs`) aparte |
| `11-h35-reloj-de-la-costura.txt` | H-35: la marca de verificación (23:45:29) frente al reloj congelado de la costura (23:29:45): `UNVERIFIED` con ese reloj, `VERIFIED` con el real |
| `SHA256SUMS` | Huellas de todo lo anterior |

**Segunda tanda (11-11-2026, banco `-ops8e3`, 01:57–02:14 UTC):** la rotación del token maestro de Twilio del OTP (punto 7 del lead), B-4 con la lectura del lead y las 5 tareas de voz de B-T5.

| Fichero | Qué es |
|---|---|
| `12-ensayo-otp-token.txt` · `12-registro-ensayo-otp-token.txt` | `ensayar-otp-token.sh`, la ejecución final: **49 OK · 0 FALLO** (12 negativos, el ciclo A con la vuelta antes de promocionar, la ventana del aviso de gasto, el ciclo B con «drift» y la vuelta que se niega tras promocionar, el banco como estaba, el barrido) |
| `12a-*` (31 OK · 18 FALLO) | La primera ejecución: destapó un `$( [ … ] && echo …)` en una asignación del mando que, con `set -e`, salía sin decir nada **antes de copiar ni escribir** (corregido; el fichero de entorno del banco quedó intacto) |
| `12b-*` (48 OK · 1 FALLO) | La segunda: el único FALLO era del arnés (el contador de promociones del simulador no vuelve a cero entre ensayos) |
| `13-sim-pruebas-antes-88-OK.txt` · `13-sim-pruebas-despues-93-OK.txt` | `node sim-pruebas.cjs` antes y después de la cuenta maestra del simulador: 88 → 93 OK, 0 FALLO |
| `14-prueba-b4-usuario-efectivo.sh` y `.salida.txt` | B-4: la lógica de «quién ejecuta de verdad `schedule:run`» de `h7-monitor-scope.sh` con 11 crontabs sintéticos: 11 OK · 0 FALLO |
| `15-tareas-de-voz-del-arbol.txt` | B-T5: con el árbol actual, `VOICE_MONITOR_ENABLED=true` programa exactamente 5 tareas de voz (27 → 32); el banco (`a608450`), 4 |
| `16-b4-resultado-del-lead.txt` | B-4: lo que leyó el lead en producción (root con `sudo -u www-data`: correcto), transcrito |
| `05b-ensayo-ops8e-tras-b4-y-tareas.txt` · `05b-registro-…` | `ensayar-ops8e.sh` otra vez, con el B-4 nuevo y las tareas leídas del código: **55 OK · 0 FALLO** |

