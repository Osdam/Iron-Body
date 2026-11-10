# ULTRON STUDIO · Hito 8 · Informe del cierre integral (encargo del 09-11-2026)

**Veredicto: `HITO_8_NOT_YET_COMPLETE`.** El código del hito está terminado y la fase 1 (todo apagado) está en producción desde el 09-11-2026 a las 12:06 UTC (Enmienda 15). Ninguna fase 2 → 4b está activa: todas dependen de acciones externas del propietario (credenciales, cuenta de Twilio, plan de ElevenLabs, autorizaciones) y de pruebas físicas con llamadas reales que este encargo prohíbe ejecutar sin orden expresa.

**Estado de este informe:** actualizado a las 00:50 UTC del 10-11-2026. Entregados: SEC8-c, S8-d, OPS8-c, B8-g, TEST8-c y la corrección B8-h de los hallazgos de la revisión independiente REV8-c (aprobada con condiciones: 0 bloqueantes). A las 02:05 UTC quedan cerrados la regresión global (E16-29), el H-1 del CRM (SSE9-f, L9-53 del contrato del H9) y la segunda mirada del revisor (E16-27). Lo único pendiente del lado del código es la orden del propietario para el commit, el push y el despliegue 1b. Fuentes verificables: `ULTRON_STUDIO_H8_CONTRACT.md` (Enmiendas 15 y 16), `ULTRON_STUDIO_H8_COMPLETITUD.md` (matriz por fase), `plans/informes-h8/{informe-security,informe-studio,informe-backend,informe-devops,informe-tester}.md`, `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 8»). **Nada de lo de hoy tiene commit**: el propietario lo prohibió sin orden expresa.

---

## 1. Qué faltaba realmente del Hito 8

**a) Ninguna fase después de la 1 estaba activa, y no por el código.** Las fases 2 → 4b esperaban (y esperan) credenciales rotadas y registradas, una cuenta o subcuenta de Twilio con claves restringidas, un plan de ElevenLabs con minutos de agente, una segunda persona real para los cuatro ojos y las autorizaciones escritas de cada paso.

**b) Pendientes técnicos ya conocidos (Enmienda 14, E14-5), todos comprobados en el código:**

| Pendiente | Efecto real que tenía |
|---|---|
| E4-6: el lector de la verificación de ElevenLabs en la telefonía devolvía `false` siempre | **Todas las salientes se denegaban** (`elevenlabs_unverified`), aunque el Studio diera la verificación por buena |
| E13-3: la medida `missingPostCall` no existía | Se podía promover una publicación con llamadas sin post-llamada procesada |
| E10-10: el laboratorio no admitía perfiles con destinos de transferencia | Con la puerta en `required`, un perfil con destinos no se podía publicar |
| 4 pruebas dependían de la hora del reloj (04:44–05:00 UTC) | Falsos rojos en la ventana nocturna |
| `voice:hangup` no actuaba sobre filas `canceled` | Un corte no confirmado quedaba sin reintento |
| El arnés E2E esperaba 15 s | Falsos rojos con la máquina fría |
| E2E del CRM sin ejecutar | Sin evidencia de la interfaz de voz contra la pila real |

**c) Lo que el cierre integral descubrió (no estaba en ninguna lista):**

| Hallazgo | Quién | Severidad |
|---|---|---|
| El número empresarial importado como «Outbound Only» es un **Verified Caller ID** de Twilio: no recibe llamadas, no se asigna a agentes y no se traslada entre cuentas. Publicar el canario intentaba asignarle el agente (409) y la deriva del número habría echado el freno cada hora. **La fase 3 (entrantes) es imposible con ese número** | SEC8-c, OPS8-c | Alto |
| Con qué credencial se importó el número en ElevenLabs: si fue el Auth Token de la cuenta principal, ElevenLabs guarda la credencial maestra del OTP de los socios (A-1 / H-14) | SEC8-c, OPS8-c | Alto, condicionado a la respuesta del propietario |
| La clave de ElevenLabs que pasó por el chat, si no se rotó antes de importar el número, permite hoy llamar fuera de Laravel con el número real de recepción (A-2) | SEC8-c | Alto |
| La guarda de la transcripción era ciega a tildes y léxico colombiano: **18 frases de ataque pasaban** («promoción», «descuentos», «gratuita», «ochenta lucas», «un palo», inglés, fuga del prompt…) (M-1) | SEC8-c | Medio |
| La plantilla de voz no fijaba el idioma ni cubría a quien dice ser «del equipo» o «el gerente»; la primera llamada real salió en inglés porque el agente se creó a mano y un agente nuevo de ElevenLabs nace en inglés | S8-d | Medio |
| La conciliación lista **todas** las llamadas de la cuenta de Twilio configurada, sin filtrar por número: la llamada de prueba del 09-11 echaría el freno en la primera pasada; con la voz en la cuenta madre, cualquier llamada ajena frenaría (H-15) | OPS8-c | Medio |
| `el-verificar.sh` regeneraba los dos secretos propios en cada ejecución: rotar la clave después del registro habría roto la post-llamada y las herramientas (H-16) | OPS8-c | Medio |
| No existían los mandos de operación de las fases 2 → 4b (`h8-voice-scope.sh`, `h8-secrets-register.sh`, `h8-twilio-check.sh`), ni los runbooks 2a y 4a-primero, ni el smoke de esas fases; los simuladores no modelaban secretos, webhooks ni caller ID | OPS8-c | Medio |
| El webhook de iniciación podría dispararse también en una saliente (la documentación lo describe para entrantes); de ser así, el agente colgaría al canario (E16-10) | B8-g | A vigilar en la primera llamada real |
| La guarda del número de WhatsApp comparaba `TWILIO_VOICE_FROM` y no el número que ElevenLabs usa de verdad (B-1) | SEC8-c | Bajo |

## 2. Qué se implementó y corrigió (todo en el árbol de trabajo, sin commit)

| Paquete | Estado | Qué |
|---|---|---|
| **SEC8-c** (seguridad) | Entregado y cerrado | Comparativa de cuentas de Twilio con recomendación **(b′)**: subcuenta sin coste, número vuelto a verificar como caller ID, importación nueva en ElevenLabs con clave restringida, borrado de la importación actual; hallazgos A-1, A-2, M-1..M-3, B-1, B-2 con su corrección asignada; fixture de 18 ataques y 20 frases legítimas para la guarda; lista de activación |
| **S8-d** (Studio) | Entregado y cerrado | Plantilla `ultron-voice-template/2`: español de Colombia de principio a fin, sin voseo, trato por perfil (`addressForm`: tú por defecto, o usted), saludo del perfil tras el aviso de IA, precios y horarios solo si una herramienta los dio en esa llamada, `no_volver_a_llamar` sin insistir, sección fija de seguridad (ignorar reglas, cambiar de idioma, revelar el prompt, autoridades falsas). Publicar, revertir y apagar sin tocar el número cuando es solo saliente (`not_applicable_outbound_only`). Promover exige exactamente siete medidas y `missing_post_call` en la lista cerrada. Laboratorio con destinos (comprobados contra los aprobados; no transfiere de verdad: ofrece WhatsApp). Libro del Studio con abrir sesión, veredicto, publicar, promover, revertir y apagar. `compiler-cases.json` reexportado (882 + 98 casos) |
| **B8-g** (telefonía) | Entregado y cerrado (13 puntos) | Marca de verificación `voice:elevenlabs:verified_at` escrita por `--verify-voice` y leída con 30 días estrictos y forma exacta (E4-6); `missingPostCall` séptima clave y `calls` solo con post-llamada; `VOICE_NUMBER_INBOUND_CAPABLE` tri-estado, conciliación `outbound_only` sin freno y la iniciación denegando siempre en ese modo (`number_outbound_only`); comprobado que el código pide lo mismo con cuenta principal o subcuenta y nunca la credencial del OTP; las 4 pruebas de hora con reloj fijo en UTC; `voice:hangup` sobre `canceled`; **la guarda ve el español real** (`VoiceTranscriptLexicon`: tildes, números en palabras, seis códigos; 18 ataques cazados, 0 falsos positivos); **identidad del número que llama** (la conciliación compara el número importado con `TWILIO_VOICE_FROM` y la mensajería, frena si no cuadra; la puerta **falla cerrada** sin una observación de menos de 2 h: `number_unverified` / `number_mismatch`, 28 motivos; el job la recomprueba antes de marcar); el webhook de iniciación con el SID de una saliente responde como saliente sin crear filas; `template.aiNotices` con el aviso de «usted» |
| **OPS8-c** (devops) | Entregado y cerrado | `h8-voice-scope.sh` (mando de las fases 2 → 4b: copia previa, escritura atómica, caché como `www-data`, reinicio de colas, antes/después sin secretos, vuelta atrás; se niega a abrir llamadas sin un commit `voice(calls-open)` y a `lab on` sin las referencias; conciliación en seco antes de `monitor on`), `h8-secrets-register.sh` (los dos secretos y el webhook por API; el secreto HMAC lo genera ElevenLabs y nunca pasa por pantalla), `h8-twilio-check.sh` (cuenta y número, solo GET, con veredicto), `SMOKE_PHASE=1\|2a\|4a`, simuladores ampliados, runbooks «Fase 2a» y «Fase 4a-primero» en el plan de despliegue. Ensayo completo en el banco: **80 OK · 0 FALLO** |
| **TEST8-c** (pruebas) | Entregado | E2E del backend (fases cerrada 6/6, abierta 8/9 y la nueva `salientes` 10/10) y del CRM (264 comprobaciones, 262 OK; sin pasada limpia por la carga de la máquina y por H-1), con evidencia en `ops/evidencia-h8/2026-11-09/`; `esperarEstado` a 30 s; 7 medidas y `calls` con post-llamada; caso `missing_post_call`; mutaciones 11/11; el arnés copia el árbol de trabajo sin commits (`H8_E2E_ARBOL=1`). Encontró dos fallos reales: **H-1** (CRM: el parser rechaza `actions.end.allowed: true` en una sesión `ended`; lo corrige SSE9-f) y **H-4** (backend: la idempotencia se evaluaba tras la frecuencia; corregido en B8-h). **[PENDIENTE: la regresión global por nombre contra `origin/main`, en curso como proceso tras el corte del agente]** |
| **Lead** | Hecho | Matriz de completitud por fase (`ULTRON_STUDIO_H8_COMPLETITUD.md`); Enmienda 16 (E16-1..17), incluida la **enmienda explícita de criterio de entrada**: la fase 4a no exige la fase 3 (E16-16); `el-verificar.sh --solo-clave` (rotar la clave sin regenerar los secretos registrados); manual de voz actualizado (plantilla `/2`, laboratorio con destinos); verificación del árbol en SQLite y PostgreSQL (§3) |
| **Revisión independiente (REV8-c)** | Hecha: **aprobada con condiciones**, 0 bloqueantes, 2 mayores, 4 menores (E16-23) | M-1 (la observación del número no se ataba al configurado), M-2 (la guarda frenaba las negativas de la plantilla), m-1 (huecos de normalización), m-2 (fallo abierto con PCRE), m-3 (Pint), m-4 (documentos). **Segunda mirada (E16-27): aprobada con condiciones, 0 bloqueantes.** Corregidas M-1, m-1, m-2, m-3 y m-4; **M-2 solo parcialmente**: las negativas literales de la lista ya no frenan, pero 13 de 15 negativas naturales fuera de la lista siguen echando el freno: **abierta para la fase 4a** con decisión del propietario (que la plantilla fije la frase exacta de cada negativa, recomendado, o aceptar esos frenos como calibración). Menores cerrados tras la segunda mirada: H-5 (el estado ya no expone el identificador del número en el proveedor) y el falso positivo de la condicional «si estás al día con tus pagos» |

Requisitos del punto 4 del encargo («español colombiano y comportamiento comercial»): español desde el primer saludo (S8-d, plantilla `/2`); voz aprobada (el `voice_id` del perfil aprobado; el acento lo da la voz, ElevenLabs no tiene variante «es-CO»: elegir una voz latinoamericana en la fase 2a); configuración generada desde las diez capas y versiones aprobadas (compilador del Studio sobre la versión `APPROVED`/`CANARY`/`PUBLISHED` y el perfil aprobado, con huellas); prohibición de inventar (prompt + guarda posterior, reforzada en B8-g punto 7); no volver a llamar (`no_volver_a_llamar`); instrucciones maliciosas (sección fija de seguridad); trazabilidad (fila sellada por llamada, cadena firmada, libro del Studio también para el laboratorio). **El CRM es la autoridad**: el agente de ElevenLabs es inmutable por publicación y se compila desde el Studio; el agente creado a mano para la prueba del 09-11 no pasó por nada de esto, y por eso habló en inglés.

## 3. Qué pruebas pasaron y cuáles no

**Ejecutadas por el lead sobre el árbol con las tres entregas juntas (09-11, 20:27–20:31 UTC):**

| Suite | SQLite | PostgreSQL 14 (`pgunit.sh`, `ultron_studio_h8_lead`) |
|---|---|---|
| `tests/Feature/Telephony` | 394 pruebas, 181 346 aserciones, 0 fallos (131 omitidas: solo PG) | **394, 182 102, 0 omitidas, 0 fallos** |
| `tests/Feature/UltronStudio --filter 'Voice\|Lab'` | 309, 4 602, 0 fallos (82 omitidas) | **309, 5 104, 0 fallos** |
| `tests/Feature/Voice` | 48, 347, 0 fallos (25 omitidas) | — |
| `tests/Unit/Voice` + `tests/Unit/Telephony` | 273 + 20, 19 939 aserciones, 0 fallos | — |

**Puerta final de PostgreSQL del lead (21:57 UTC, con B8-g cerrado):** Telefonía + Voz + unitarias **753 pruebas, 207 640 aserciones, 0 fallos** (25 omitidas: E2E del banco); Studio voz y laboratorio **309, 5 106, 0 fallos** (E16-22).

Nota: una primera pasada con los 128 MB por defecto del CLI murió por memoria en `routes/api.php` (código 255). No es un fallo del código: la convención del proyecto es `php -d memory_limit=2G` (`pgunit.sh`, `suite-compare.sh`).

**Reportadas por los agentes (con sus informes):** S8-d: 667 pruebas propias en SQLite y PG (0 fallos), Studio entero 3 133, telefonía + voz 734, Pint, mutaciones 47 con 44 cazadas (3 equivalentes). B8-g (final, 21:41 UTC): telefonía + voz **847 pruebas, 0 fallos en SQLite y en PG** (`ultron_studio_h8_bg`), `auth:coverage --unmapped` vacío, Pint, mutaciones 25/25 + 7/7 + 6/6 cazadas, verificador de fixtures 882 casos sin discrepancias; cada punto con prueba de regresión que cae con el código anterior. OPS8-c: ensayo del banco 80 OK · 0 FALLO. SEC8-c: prueba local de la guarda (18 ataques pasan hoy; con la propuesta, 18 cazadas y 0 falsos positivos en 20 legítimas).

**Ejecutadas por el lead tras B8-h (10-11, 00:40 UTC):** telefonía + voz + unitarias en **SQLite**, 776 pruebas y 212 394 aserciones, y en **PostgreSQL**, 776 pruebas y 213 151 aserciones, en los dos casos con **un solo fallo, ajeno al H8** (`TelephonyRoutesTest`, por las 30 rutas del Hito 9 sin limitador en el árbol compartido); en una copia del árbol sin ningún fichero del Hito 9, las seis pruebas de contrato de rutas, aislamiento y esquema dan **125 pruebas, 0 fallos**; verificador de fixtures de voz OK (E16-26). **No ejecutadas (y por qué):** nada contra ElevenLabs ni Twilio reales (prohibido en el encargo); la regresión global por nombre **hecha** (E16-29): 14 061 pruebas sobre una copia sin el H9, 59 rojos = 55 conocidos de la base + 4 explicados (contaminación ya corregida y artefactos de la copia), **0 nuevos del H8**; la fase 4a positiva en el banco con una publicación canaria real (TEST8 + OPS8, antes de la 4a); `ensayar-ops8c.sh` sobre el código final (OPS8, antes de la 4a).

## 4. Dependencias externas que requieren intervención del propietario

| # | Qué | Por qué | Cuándo |
|---|---|---|---|
| D-1 | **Rotar la clave de ElevenLabs** (clave nueva en la consola → `el-verificar.sh` con el fichero → revocar la vieja; después `ultron-studio:integrations-snapshot --verify-voice`) | A-2: la clave pasó por el chat; con el número importado permite llamar como Iron Body fuera de Laravel | **Ya** |
| D-2 | **Decir con qué credencial se importó el número** en ElevenLabs (Auth Token maestro o clave restringida) | A-1 / H-14: si fue el Auth Token, ElevenLabs guarda la credencial del OTP → reimportar con clave restringida, borrar la importación vieja y rotar el token maestro (B-6) | **Ya** |
| D-3 | Decidir la **cuenta de Twilio** para la voz (recomendado (b′): subcuenta, número vuelto a verificar como caller ID, claves restringidas solo de Voz y token de firma propios) y crearla | D8-4/D8-5, M-3, H-15 | Antes de la fase 2 |
| D-4 | **Plan de ElevenLabs** con minutos de agente (hoy `free`, 10 000 caracteres, 5 694 usados) o excepción escrita para usar la franquicia gratuita en el laboratorio con el presupuesto como freno | Sin minutos no hay laboratorio ni llamadas | Antes de la fase 2a |
| D-5 | Autorizar el **registro de los dos secretos y el webhook** (`h8-secrets-register.sh`) y `VOICE_WEBHOOK_BASE_URL` | Sin las referencias el compilador no crea agentes | Fase 2 |
| D-6 | Elegir la **voz latinoamericana** del perfil (la de los fixtures es de pruebas) y aprobar los textos del aviso de IA y del aviso de «usted» (provisional) | Acento y cumplimiento | Fase 2a |
| D-7 | Una **segunda persona real** con cuenta propia para los cuatro ojos (veredicto del laboratorio, aprobación, promoción) | El contrato prohíbe que la misma persona apruebe su trabajo | Fase 2a |
| D-8 | Autorizar la instalación del **worker `ironbody-voice`** (borrador en `docs/ops/supervisor/`) | Sin él ninguna saliente se despacha | Antes de la 4a |
| D-9 | Ordenar el commit `voice(calls-open)` (único cambio: la constante) y su despliegue | Las llamadas están cerradas en el código hasta entonces | Antes de la 4a |
| D-10 | Decidir si habrá **entrantes**: exige un número de Twilio propio de la (sub)cuenta importado con entrantes (compra o portabilidad) o decidir que no haya fase 3 | El caller ID verificado no recibe llamadas | Antes de la fase 3 |
| D-11 | `ULTRON_STUDIO_MONITOR_EXPECT` antes de encender el monitor del H7; renovar la verificación de ElevenLabs antes del 03-12-2026 22:55 UTC | Operación | Fases 2 → 4b |
| D-12 | Visto bueno legal (Ley 1581/2012 y Ley 2300/2023, RNE) y la supresión 1581 de `voice_*`; CSP del CRM en Report-Only (E12-7) | Contactos con consentimiento | Antes de la 4b |

## 5. Qué fases están listas para activarse

| Fase | Código | Operación (mandos, runbook, smoke) | Qué falta para activar |
|---|---|---|---|
| **1 · todo apagado** | En producción (`fe29126` / `45dd73a`) | Hecho (E15) | — (vigilancia a 24 h el 10-11 ≥ 12:06 UTC) |
| **1b · código del cierre integral** | **Listo**: regresión global sin rojos nuevos del H8 (E16-29) y segunda mirada del revisor aprobada (E16-27). Falta la orden del propietario; antes de desplegar, repetir los E2E sobre el commit | Guion de la fase 1 reutilizable | Orden de commit, push y despliegue |
| **2 · credenciales y número** | Listo | `el-verificar.sh` (+ `--solo-clave`), `h8-secrets-register.sh`, `h8-twilio-check.sh`, `h8-voice-scope.sh fase 2` | D-1, D-2, D-3, D-5 |
| **2a · laboratorio hablado** | Listo (4 cerrojos, destinos, libro) | Runbook «Fase 2a», `SMOKE_PHASE=2a`, `h8-voice-scope.sh lab on` | D-4, D-6, D-7 y autorización escrita; la primera sesión real consume créditos |
| **3 · entrantes en canario** | Listo | Runbook de la fase 3 (plan de despliegue) | **Imposible con el número actual** (D-10) |
| **4a · salientes a canarios** | Listo | Runbook «Fase 4a-primero», `SMOKE_PHASE=4a`, `h8-voice-scope.sh fase 4a` | 1b desplegada, 2 y 2a hechas, D-8, D-9, ratificar E16-16, `TWILIO_VOICE_DAILY_CALL_CAP=1`, presupuesto ≥ 0,97 US$ por llamada, primera llamada al propietario |
| **4b · contactos con consentimiento** | Listo (consentimientos a cuatro ojos, exclusiones, Ley 2300, festivos, frecuencia) | Mando `fase 4b` | 4a con evidencia (3 llamadas, 24 h, 0 incidentes, promoción a `rest`), D-12 |

## 6. Qué autorizaciones hacen falta para cada activación

Los comandos exactos, sin secretos, están en `informe-devops.md` §«Acciones pendientes de autorización» y en el plan de despliegue («Hito 8», fases 2a y 4a-primero). Ninguno se ha ejecutado.

| Activación | Autorización que pido | Coste o irreversibilidad |
|---|---|---|
| Commit y push del cierre integral | Orden expresa en la conversación («haz commit», «súbelo») | Ninguno |
| Despliegue 1b (backend y CRM) | Orden expresa; respaldo previo automático; sin migraciones nuevas | Ninguno; vuelta atrás con el respaldo |
| Rotación de la clave (D-1) | La hace el propietario en la consola; el guion lo ejecuta root con el fichero | Revoca la clave vieja |
| Registro de secretos y webhook (D-5) | Orden expresa para `h8-secrets-register.sh` | Crea un webhook en el workspace; cambia la huella de voz del Studio |
| Fase 2 en Twilio (D-3) | El propietario crea subcuenta, verifica el número, crea claves y token; orden expresa para `h8-voice-scope.sh fase 2 secreto <fichero>` | Credenciales nuevas en el servidor |
| Fase 2a (laboratorio) | Orden escrita con presupuestos (`VOICE_LAB_*`); primera sesión real a cuatro ojos | Consume créditos de ElevenLabs |
| Commit `voice(calls-open)` y despliegue (D-9) | Orden expresa | Abre la constante; las llamadas siguen cerradas por las banderas |
| Worker `ironbody-voice` (D-8) | Orden expresa (supervisor en producción) | Reinicio de supervisor |
| Fase 4a (primera saliente) | Orden escrita y ratificación de E16-16; `TWILIO_VOICE_DAILY_CALL_CAP=1`; destinatario el propietario | Llamada real con coste (≈ 0,97 US$) |
| Fase 3 (entrantes) | Decisión D-10 y, si procede, compra o portabilidad del número | Compra |
| Fase 4b | Visto bueno legal (D-12) y evidencia de la 4a | Contactos con socios reales |

## 7. Qué queda para declarar `HITO_8_FULLY_OPERATIONAL`

1. Cerrar el código del cierre integral: **hecho** (regresión global sin rojos nuevos del H8, E16-29; segunda mirada del revisor aprobada con 0 bloqueantes, E16-27). Falta la **orden del propietario** para el commit (solo rutas del H8), el push, los E2E sobre el commit y el despliegue 1b. M-2 queda abierta para la 4a por decisión del propietario (E16-28), regresión global por nombre sin rojos nuevos, y la orden de commit, push y despliegue (1b).
2. D-1 y D-2 resueltos (clave rotada; credencial de importación confirmada y, si hace falta, número reimportado y token rotado).
3. Fase 2 completa: cuenta de Twilio decidida y comprobada con `h8-twilio-check.sh`; secretos y webhook registrados; `ensayar-ops8c.sh` repetido sobre el código desplegado.
4. Fase 2a activa y verificada: al menos una sesión real del laboratorio en español de Colombia con la voz elegida y veredicto `approved` de otra persona.
5. Fase 4a activa y verificada: publicación canaria, `voice(calls-open)`, worker, primera saliente real al propietario en español, coste conciliado, tres llamadas selladas con post-llamada, 24 h y 0 incidentes, promoción a `rest`.
6. Fase 3 resuelta: entrantes activas con un número propio **o** decisión escrita de que el alcance no incluye entrantes (enmienda).
7. Fase 4b activa con el visto bueno legal y los consentimientos a cuatro ojos; evidencia de contactos reales con consentimiento.

Hasta que los siete estén cumplidos y verificados con llamadas reales, el estado es **`HITO_8_NOT_YET_COMPLETE`**.
