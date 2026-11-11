# ULTRON STUDIO · Activación por etapas (fase 6 del cierre, lead, 10-11-2026)

Del estado desplegado hoy (Hito 8 en producción con todo apagado; Hito 9 en fase 1 con todo apagado) a la operación comercial supervisada, **una etapa cada vez**, cada una con su autorización escrita, su mando, su verificación y su vuelta atrás. Los controles que siguen apagados cuando no se usan (parada global, exclusiones, cupos y presupuestos, cuatro ojos, ventana horaria) **no se tocan nunca para aparentar que el sistema está completo**. Nada de esta lista se ha ejecutado en producción: cada paso cita lo que necesita.

Lectura de cada paso: **(a)** solo tu autorización escrita · **(b)** un dato o decisión tuya · **(c)** el plan de pago de ElevenLabs · **(d)** una prueba real posterior (consume o contacta).

Mandos (en el servidor, como root, con copia previa del entorno y `config:cache` como `www-data`): `h8-voice-scope.sh` (voz) y `h9-outreach-scope.sh` (automatización). Hoy el del Hito 9 está en `/root`; los cuatro del Hito 8 (`h8-voice-scope.sh`, `h8-secrets-register.sh`, `h8-twilio-check.sh`, `el-verificar.sh`) **no** están copiados: es el paso 0.

**Runbook operativo de las etapas 4 a 7 (Hito 9):** `ops/RUNBOOK_H9.md` (precondiciones, órdenes exactas del mando, salida esperada, verificación, vuelta atrás, incidentes y permisos).

## Etapa 0 · Piezas previas (sin plan)

| # | Paso | Mando / comando | Necesita |
|---|---|---|---|
| 0.1 | Copiar los cuatro guiones del Hito 8 a `/root` (700/600) y comprobar huellas | `scp` desde el repositorio (ops/) · `sha256sum` | (a) |
| 0.2 | Rotar la clave de ElevenLabs (A-2: pasó por el chat) con alcance `convai_read` + `convai_write` y verificar | fichero 600 en `/root/el-clave.txt` → `bash /root/el-verificar.sh /root/el-clave.txt <agent_id>` → revocar la vieja → reconocer en el Studio | (a) + (b) la clave nueva |
| 0.3 | Rotar el Auth Token maestro de Twilio del OTP (ElevenLabs lo custodia desde la importación) y decidir D-2/D-3: reimportar el número con credencial restringida si ElevenLabs lo admite, o subcuenta de voz con el número vuelto a verificar | consola de Twilio + consola de ElevenLabs (borrar el secreto viejo) | (b) decisión + (a) |
| 0.4 | Base de los webhooks | `bash /root/h8-voice-scope.sh fase 2 set VOICE_WEBHOOK_BASE_URL=https://api.ironbodyneiva.cloud` | (a) |
| 0.5 | Registrar los dos secretos de Laravel y el webhook post-llamada en ElevenLabs | `bash /root/h8-secrets-register.sh --solo-comprobar` (solo GET; dice si el plan gratuito lo admite, H-23) → `bash /root/h8-secrets-register.sh --clave-fichero=…` → reconocer en el Studio | (a) + H-23 |
| 0.6 | Claves restringidas de Twilio solo de Voz (hoy la cuenta tiene 0 API keys) y las piezas del número | fichero 600 `/root/tw-voz.txt` → `bash /root/h8-voice-scope.sh fase 2 secreto /root/tw-voz.txt` → `bash /root/h8-voice-scope.sh fase 2 set TWILIO_VOICE_ACCOUNT_SID=AC… TWILIO_VOICE_FROM=+57…5483 ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_1001m6hsdgrqe2mtwa8dhp2gv7sh VOICE_NUMBER_INBOUND_CAPABLE=false` → `bash /root/h8-twilio-check.sh` (APTA) → reconocer | (b) las claves + (a) |
| 0.7 | Monitor del Hito 7 con la expectativa de la fase 2 (si se enciende) | `bash /root/h8-voice-scope.sh expectativa fase2` | (a) |
| 0.8 | Permisos: `ultron.voice_lab_run` a quien hará la primera sesión; la segunda persona con cuenta propia (D-7); el trato tú/usted y los textos del aviso (D-6, con la voz Lina ya elegida en el perfil) | CRM → Roles · CRM → Studio → Voz → perfil | (b) |
| 0.9 | Fase 2 del Hito 6 y el canario de voz del propietario (D-13): registrar el número con consentimiento y que OTRA persona lo autorice y active | `bash /root/h6-integrations-scope.sh …` + CRM → Studio → Integraciones → canarios | (a) + (b) |
| 0.10 | Worker de voz (duerme hasta la 4a) | `docs/ops/supervisor/ironbody-voice.conf` → `supervisorctl reread/update` (13 → 14) | (a) antes de la etapa 3 |
| 0.11 | El commit `voice(calls-open):` (una línea: la constante) y su despliegue | lead, con tu orden citada en el commit | (a) antes de la etapa 3 |
| 0.12 | Supresión 1581 de la voz y CSP del CRM en Report-Only | `ops/supresion-1581-voz.propuesta.sql` (database-specialist) · `ops/nginx-h8-voz.conf.propuesta` | (a) antes de la etapa 6 |

## Etapa 1 · Laboratorio hablado

| # | Paso | Mando | Necesita |
|---|---|---|---|
| 1.1 | Ensayo sin gasto desde el CRM con el driver falso: abrir, hablar, cerrar; el veredicto queda negado (`fake_mode`, H-21) | `bash /root/h8-voice-scope.sh lab on --driver=fake --presupuesto-dia=0 --presupuesto-sesion=0` → `SMOKE_PHASE=2a bash /root/smoke-run-h8.sh` → `lab off` | (a) |
| 1.2 | Encender el laboratorio real con techos | `bash /root/h8-voice-scope.sh lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=300` → smoke 2a | (a) (encender no gasta) |
| 1.3 | Primera sesión real (≤ 5 min, con Lina en español) y veredicto de la segunda persona | CRM → Studio → Voz → Laboratorio hablado | **(c)** plan (o excepción escrita D-4) + (d) |
| vuelta atrás | `bash /root/h8-voice-scope.sh lab off` (bandera, driver falso y presupuestos a 0) | | |

## Etapa 2 · Publicación de la voz en canario

| # | Paso | Mando | Necesita |
|---|---|---|---|
| 2.1 | Ratificar por escrito «4a antes que 3» (E16-16) y P-M2 | — | (b) |
| 2.2 | Monitor de voz (si la llamada del 03-11 sigue en la ventana de 48 h de la conciliación, el mando se niega: `--acepta-freno` y soltar con nota). **Antes, en seco:** `voice:webhook-health --dry-run` dice si la salud del webhook echaría el freno (`webhook_unhealthy`, SC-3) sin escribir nada; si la respuesta real de ElevenLabs no trae `auth_type`, frenaría cada hora (L9-91) | `sudo -u www-data php artisan voice:webhook-health --dry-run` → `bash /root/h8-voice-scope.sh monitor on` | (a) |
| 2.3 | Worker instalado (0.10) y constante abierta desplegada (0.11); salientes encendidas con cupo 1 (la puerta sigue cerrada por `no_publication`) y reconocer (H-25) | `bash /root/h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00` → reconocer en el Studio | (a) |
| 2.4 | Publicar el canario desde el CRM con su `expectedSpecHash`, en la ventana horaria (H-26: después de salientes on) | `bash /root/h8-voice-scope.sh publicar on` → CRM → Studio → Voz → Publicar | (a) + veredicto de 1.3 (**c**) |
| 2.5 | Comprobar `estado` (cadena `canary:ok`) y `SMOKE_PHASE=4a bash /root/smoke-run-h8.sh` | | |
| vuelta atrás | revertir la publicación desde el Studio; `publicar off`; `salientes off` | | |

## Etapa 3 · Llamadas salientes desde el CRM

| # | Paso | Necesita |
|---|---|---|
| 3.1 | Primera saliente al canario de voz del propietario desde CRM → Voz → Llamadas (≈ 0,61 US$ reservados por llamada a móvil; ≈ 0,97 US$ con minutos) | **(d)** prueba real + (a) |
| 3.2 | Verificar: `requested → dialing → active → completed`, post-llamada procesada, coste, `voice:reconcile --dry-run` con `unknown 0`, sin rechazos en el registro de voz | — |
| 3.3 | Tres llamadas con post-llamada, 24 h sin incidentes, promoción a `rest` (evidencia del canario) | (d) |
| 3.4 | Contactos con consentimiento (4b): visto bueno legal (D-12), supresión 1581 de voz, consentimientos a cuatro ojos | (b) + (a) |
| vuelta atrás | `salientes off` (cupo y presupuesto a 0); `voice:hangup --all --confirm`; revertir publicación; `monitor off` | |

## Etapa 4 · Hito 9 en modo sombra (≥ 7 días, P9-11)

| # | Paso | Mando | Necesita |
|---|---|---|---|
| 4.1 | `COMMERCIAL_EVENTS_ENABLED=true` (P9-24: sin ella N6 niega todo) y las dos primeras personas vinculadas por consola (cuatro ojos por persona verificada) | mando del motor comercial + `php artisan` del vínculo (S9) | (a) + (b) |
| 4.2 | Gobierno y publicación de estrategias | `bash /root/h9-outreach-scope.sh gobierno on` · `publicar on` · `ventana set 07:00-19:00` · `canario set --horas=24 --contactos=3` · `simulacion required` | (a) |
| 4.3 | Crear, simular, aprobar (otra persona) y publicar en canario la primera estrategia desde el CRM (vencimientos) | CRM → Mercadeo → Campañas automáticas | (b) |
| 4.4 | Sombra con programador y monitor | `bash /root/h9-outreach-scope.sh sombra on` → `programador on` → `monitor on` → `stream on` → `estado` | (a) |
| 4.5 | Siete días de sombra: pasadas, inscripciones y trabajos simulados en el panel; 0 contactos; incidentes 0. **La sombra no acumula evidencia del canario:** la evidencia para promover a `rest` solo cuenta trabajos en vivo que no son simulados (`StudioStrategyEvidence.php:112-115`), así que llega en la etapa 5 (corrección de doc9, L9-85) | — | tiempo |
| vuelta atrás | `bash /root/h9-outreach-scope.sh apagar` (nivel 0) o `parada-global --motivo="…"` (nivel 0b, sin tocar el entorno) | | |

## Etapa 5 · Modo asistido

| # | Paso | Mando | Necesita |
|---|---|---|---|
| 5.0 | **Precondición:** con la estrategia en canario y en vivo, el asistido solo alcanza teléfonos canarios ACTIVOS del Hito 6; sin la fase 2 del H6 y sus canarios (0.9) no hay a quién atender ni evidencia para promover (doc9, L9-85) | `bash /root/h6-integrations-scope.sh …` + CRM → Studio → Integraciones → canarios | (a) + (b) |
| 5.1 | Asistido con tope diario; el personal reclama en la bandeja y registra el resultado; P9-12 | `bash /root/h9-outreach-scope.sh asistido on --cupo=N` | (a) + sombra superada |
| vuelta atrás | `asistido off` | | |

## Etapa 6 · Automatizaciones reales (W9 y V9)

| # | Paso | Mando | Necesita |
|---|---|---|---|
| 6.1 | Plantillas aprobadas por Meta y su correspondencia en el Studio; consentimientos por finalidad y canal; visto bueno legal | — | (b) + legal |
| 6.2 | El commit `outreach(sends-open):` (la constante de envío) con tu orden citada, su revisión de seguridad y su despliegue (el despliegue de la fase 1 lo rechaza a propósito) | lead | (a) |
| 6.3 | Canal WhatsApp con cupo y presupuesto | `bash /root/h9-outreach-scope.sh canal whatsapp on --cupo=N --presupuesto=USD` | (a) |
| 6.4 | Canal de voz (exige las etapas 2–3 del Hito 8 superadas y sus palancas abiertas) | `bash /root/h9-outreach-scope.sh canal voz on` | (a) + (d) |
| vuelta atrás | `canal whatsapp off` · `canal voz off` · `parada-global` | | |

## Etapa 7 · Operación comercial supervisada

Horario propio más estricto que el legal (`horario set`), clasificador apagado hasta AI9 (`clasificador off`), vigilancia diaria del panel (frenos, incidentes, gasto, opt-outs), revisión semanal a cuatro ojos de resultados y de las estrategias publicadas, y la parada global a mano de cualquier persona con `commercial_automation.stop`.

## Calendario frente al 15-11

Las etapas 0 a 2.3 y 4.1–4.4 son ejecutables antes del 15-11 con tus autorizaciones; la etapa 1.3 y la 2.4 dependen del plan; la 3 de pruebas reales; la 4.5 exige siete días desde que se encienda la sombra (si se enciende el 11-11, termina el 18-11); las etapas 5–7 vienen después. La matriz de entrega distingue **implementación terminada** (hoy) de **certificación operativa pendiente** (lo que exige plan, pruebas reales o tiempo).
