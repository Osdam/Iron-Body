# ULTRON STUDIO FULL · Matriz de aceptación técnica

**22-11-2026 (al día a las 21:52 UTC) · pendiente PT-13 del control de cierre · la escribe el lead.** Cada función con su estado, dónde vive y la evidencia que lo respalda. El detalle de cada pendiente está en `MATRIZ_CIERRE_V2.md` §11; el porcentaje sale de `ops/herramientas-lead/avance.py` (nunca de cabeza).

## Cómo leerla

**Estados** (los que fijó el propietario; «READY» no es una prueba funcional):

| Estado | Qué significa aquí |
|---|---|
| **OPERATIVO Y PROBADO** | Está en producción, verificado allí y visto funcionar en real (una prueba real o uso real) |
| **IMPLEMENTADO PERO DESHABILITADO** | Está en producción, verificado allí, y apagado por una bandera o una constante hasta su autorización o su prueba real |
| **EN INTEGRACIÓN** | El código está terminado, revisado y probado en su rama (cierre técnico), pero NO está desplegado: espera una autorización (HUMAN GATE) |
| **BLOQUEADO** | Espera una decisión o una acción del propietario o de un tercero (Meta, Twilio, ElevenLabs, asesoría legal) |
| **NO IMPLEMENTADO** | No existe |

**Tipos de evidencia.** *Batería*: la batería completa de PHPUnit sobre el commit exacto. *Revisión*: seguridad, seguridad de API o revisión final independiente, con su informe. *Ensayo*: despliegue y vuelta atrás en el banco local. *Simulado*: de extremo a extremo con simuladores. *Producción*: leído en el servidor. *Real*: una interacción real (WhatsApp o llamada). **Una prueba simulada no equivale a una interacción real**, y aquí nunca se presenta como tal.

## 1. Dónde está cada cosa (22-11-2026, 21:52 UTC)

**Producción** (leído en el servidor): API `f1fe6cac` (desde las 16:11 UTC), CRM `a9e7e7a`, n8n con el flujo S2 activo, versión 3 del Studio en canario (huella `26d57565…`, compilador 4), conversación canaria #931. Las llamadas y los envíos automáticos, cerrados por una constante del código. El resto de clientes, con el motor anterior (Legacy).

**Terminado y revisado, sin desplegar** (cada despliegue exige la autorización específica del propietario):

| Qué | Rama y commit | Batería completa | Revisiones |
|---|---|---|---|
| Lote 3 · voz y telefonía | `integracion/voz` `b1f66c32` | 19 328 pruebas, 0 regresiones | Seguridad del código y de los mandos: apto con condiciones. Revisión final: aprobado, 0 bloqueantes |
| Lote 4 · autorizaciones de contacto y quejas | `integracion/consent` `ba4b143e` | 19 400 pruebas y 511 069 aserciones, solo los dos fallos esperados (la prueba de pagos que corrige PT-16 y la puerta de diff) | Seguridad de API (dos pases) y revisión final (dos pases): 0 bloqueantes |
| Lote 5 · laboratorio frente al motor | `cierre/lab` `bdb93199` | 19 412 pruebas, 0 regresiones | Seguridad, base de datos y revisión final: 0 bloqueantes abiertos |
| Turno de planes · compilador 5 (PT-17) | `cierre/planes-c5` `e5a673c1` | 19 259 pruebas, solo los fallos esperados | Seguridad: apto con condiciones. Revisión final: aprobado, 0 bloqueantes |
| Cobro manual con IVA adicional (PT-16) | `cierre/pagos-guarda-iva` `e1c2af3c` | Dentro de la integración definitiva | Revisión final: aprobado, 0 bloqueantes |
| Condiciones previas a ampliar WhatsApp (PT-15) | `cierre/planes-condiciones` `a994b050` | Dentro de la integración definitiva | Revisión final: aprobado con condiciones, 0 bloqueantes. Seguridad: 0 bloqueantes |
| CRM · tres pantallas nuevas | repositorio del CRM, `cierre/crm-lote5` `9d5a8e6` | 1 453 pruebas del Studio y 814 de automatización, Inbox y quejas; compilación de producción | Revisión final (dos pases): aprobado con condiciones, 0 bloqueantes |
| **Todo junto** | `integracion/final` `269736bd` | **19 730 pruebas, 544 039 aserciones; el único fallo es la puerta de diff con los siete ficheros esperados, que pasa sola al subir** | Recorridos de extremo a extremo simulados (PT-12), hechos sobre `c4323242` (antes de entrar PT-15) |
| Guion de despliegue por lotes | `ops/deploy-cierre-lote.sh` (sha256 `7c7298d8…`) | — | Ensayado en el banco con los tres lotes: comprobar, desplegar y volver atrás, 0 · 0 · 0 |

## 2. Función por función

### Apartados 1 a 3 · Recuperar el estado, coordinación, laboratorio de aceptación y canario
Cerrados (100 %). El laboratorio de simulación, la publicación en canario, la traza y la reversión están **OPERATIVOS Y PROBADOS** desde el 12-11 y el 18-11 (`MATRIZ_CIERRE_V2.md` §1 y §2).

### Apartado 4 · El Studio gobierna WhatsApp (87,0 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| La versión publicada se aplica a la conversación canaria (instrucciones del Studio, flujo S2) | **OPERATIVO Y PROBADO** (solo canario) | Real: tercera y cuarta prueba, los tres turnos `applied` con la huella de la versión 3 (`a4-evidencia/162`, `164`, `165`) | — |
| Tope de longitud (200) y trato de usted | **OPERATIVO Y PROBADO** (solo canario) | Real: 104, 199 y 183 caracteres; sin tuteo en lo enviado | — |
| «Regenerar antes de sustituir»: comprobación del servidor, notas de reescritura y hasta tres borradores | **OPERATIVO Y PROBADO** (solo canario) | Real: turnos de saludo y de horario escritos por el modelo; el de planes, comprobado y reescrito dos veces | — |
| Diagnóstico de quién escribió cada respuesta y por qué se descartó un borrador | **OPERATIVO Y PROBADO** | Producción: `ultron:origin-report` con la causa exacta del respaldo en la cuarta prueba | — |
| El texto fijo de respaldo como última red | **OPERATIVO Y PROBADO** | Real: turno 2 de las pruebas tercera y cuarta, con los precios del catálogo | — |
| **El turno «¿qué planes tienen?» escrito por el modelo** | **EN INTEGRACIÓN** (PT-17). **La aceptación NO está superada**: la cuarta prueba real falló en ese turno | Batería, seguridad y revisión final sin bloqueantes; la forma pedida (175 caracteres, tres planes) pasa la comprobación del servidor con el catálogo de producción | Medirlo con el modelo real en el laboratorio; autorizar el despliegue; publicar una versión nueva; quinta prueba real |
| Frases prohibidas de las reglas del Studio | **OPERATIVO** en el compilador; sin prueba real (la versión publicada no lleva frases) | Batería; 29 917 frases candidatas contra las anclas, 0 fallos (seguridad de PT-17) | Una prueba real con una versión que lleve frases |
| Condiciones previas a ampliar más allá del canario (PT-15): la lista de planes no tapa otra pregunta y la comprobación del borrador tiene su propio cupo | **EN INTEGRACIÓN** | Batería (19 730, dentro de la integración); 304 y 6 pruebas propias que caen con el código de antes; Legacy byte a byte; revisión final y seguridad, 0 bloqueantes | Autorizar su despliegue. Antes de ampliar: valorar los tres huecos de la regla de la lista (`MATRIZ_CIERRE_V2.md` §11.2) y corregir el orden del limitador y el secreto (PT-19) |
| Ampliar el Studio a más conversaciones | **BLOQUEADO** | — | La aceptación del turno de planes y la decisión del propietario, por etapas |

### Apartado 5 · Laboratorio de texto frente al motor real (57,6 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| El recorrido del motor dentro del laboratorio (backend) | **EN INTEGRACIÓN** (lote 5) | Batería (19 412); paridad con la comprobación real del servidor en SQLite y PostgreSQL; seguridad, base de datos y revisión final | Autorizar su despliegue |
| Lanzar y ver una ejecución del motor desde el CRM | **EN INTEGRACIÓN** | 1 453 pruebas, compilación de producción, revisión final | Autorizar su publicación |
| Una ejecución real del motor con el modelo real | **BLOQUEADO** | — | Lo anterior y **la decisión sobre el modelo del laboratorio**: hoy está en otro modelo que el del agente y el recorrido del motor se niega así |

### Apartado 6 · Sofía, ElevenLabs y publicación de la voz (57,4 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Sofía dice los beneficios de los planes con datos del CRM | **EN INTEGRACIÓN** (lote 3) | Batería (19 328); revisión final; simulado (`e2e-final/02-voz.txt`) | Autorización específica del lote 3 |
| Guarda de lo que Sofía dice, ampliada | **EN INTEGRACIÓN**, con huecos documentados | 968 casos sin perder ningún código frente a producción | Para clientes reales: PT-14 (quién gobierna esos textos) |
| Perfil de voz aprobado por otra cuenta, veredicto del laboratorio hablado y publicación de la voz en canario | **BLOQUEADO** | — | Acciones del propietario, en ese orden |
| Instrucciones del Studio aplicadas a la voz, vistas en una llamada | Pendiente (real) | Simulado: el sello de cada llamada coincide con lo publicado | Una llamada real |

### Apartado 7 · Twilio Voice (49,6 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| El número empresarial como origen de las salientes (código, mandos y runbook) | **EN INTEGRACIÓN** (lote 3) | Batería; seguridad del código y de los mandos; de 126 combinaciones con la declaración puesta se admite una; ensayo de los mandos, 109 de 109 | Autorización específica del propietario para desplegar; otra, aparte, para declarar la identidad y llamar |
| Robustez de la llamada (plazo y salida de la que no llegó a iniciarse) | **EN INTEGRACIÓN** (lote 3) | Batería; concurrencia real en PostgreSQL | Lo mismo |
| Las llamadas | **IMPLEMENTADO PERO DESHABILITADO** | Producción: cerradas por la constante del código | Las autorizaciones A0 a A3 del runbook |
| Clave restringida, importación del número en ElevenLabs y piezas de la fase 2 | **BLOQUEADO** | — | Acciones del propietario en Twilio y ElevenLabs |
| Primera llamada real | Pendiente (real) | — | No antes del lunes 23-11 |
| Llamadas entrantes | **NO IMPLEMENTADO** (por decisión: «documenta por separado la necesidad de enrutamiento») | Documentado en el runbook, apartado F | Decisión futura |

### Apartado 8 · Automatización comercial, H9 (60,5 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Motor de oportunidades, decisiones y auditoría | **IMPLEMENTADO**, en sombra y sin estrategias publicadas | Producción; simulado (`e2e-final/03-automatizacion.txt`) | Estrategias reales |
| Clasificador de respuestas | **IMPLEMENTADO PERO DESHABILITADO** | Producción: desplegado el 22-11 (lote 2), apagado | Su prueba real |
| Sombra con estrategias reales (siete días de observación) | **BLOQUEADO** | — | Dos personas reales vinculadas |
| WhatsApp automático en canario | **BLOQUEADO** | — | Una plantilla aprobada por Meta |
| Voz por estrategia en canario | **BLOQUEADO** | — | El apartado 7 |

### Apartado 9 · Bandeja asistida (81,0 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Seguridad de la bandeja | **IMPLEMENTADO PERO DESHABILITADO** (el modo asistido está apagado) | Producción: desplegado y verificado el 22-11 (lote 1) | — |
| Los once motivos y el aviso del reclamo en el CRM | **OPERATIVO** (publicado) | Producción: CRM `a9e7e7a` | Verlo con una sesión real |
| Modo asistido en vivo | **BLOQUEADO** | — | Estrategias publicadas; la decisión D-3 |

### Apartado 10 · Consentimientos, quejas y cuatro ojos (61,3 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Libro de autorizaciones de contacto, lector y rutas | **EN INTEGRACIÓN** (lote 4) | Batería; seguridad de API y revisión final; carreras con dos conexiones en PostgreSQL | Autorizar su despliegue |
| Quejas del personal y descarte de una queja mal detectada | **EN INTEGRACIÓN** (lote 4) | Lo mismo | Lo mismo |
| El identificador del socio en esas rutas (PT-18) | **EN INTEGRACIÓN** (lote 4) | En las dos rutas un socio es su `users.id`; 9 pruebas nuevas que caen con el código de antes; seguridad de API (16 de 16 respuestas 404 idénticas, sin oráculo) y revisión final, 0 bloqueantes; batería completa | Autorizar el despliegue del lote 4 |
| Pantallas del CRM: autorizaciones y quejas | **EN INTEGRACIÓN** | 814 pruebas; revisión final | Autorizar su publicación |
| Texto legal de la autorización | **BLOQUEADO** | — | El propietario y su asesoría (sin él, pedir y confirmar responden que no hay texto) |
| Cuatro ojos por regla | **IMPLEMENTADO PERO DESHABILITADO** | Producción: desplegado el 22-11 (lote 2), apagado | Su prueba real |
| Dos personas reales vinculadas | **BLOQUEADO** | — | Acción del propietario |

### Apartado 11 · Meta y plantillas (55,2 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Permisos de Meta | **OPERATIVO Y PROBADO** | Producción: sonda del 22-11 | — |
| Cinco plantillas redactadas y validadas | Hecho (documento) | Contra el validador real | Enviarlas |
| El validador exige la línea de baja en el texto | **EN INTEGRACIÓN** (lote 4) | Batería; seguridad de API | Autorizar su despliegue |
| Primera plantilla enviada y aprobada por Meta, y su canario | **BLOQUEADO** | — | Dos personas vinculadas; la aprobación de Meta |

### Apartado 12 · Pagos, membresías y eventos (78,3 %)

| Función | Estado | Evidencia | Qué falta |
|---|---|---|---|
| Aviso de «pago aprobado sin membresía» | **IMPLEMENTADO PERO DESHABILITADO** | Producción: desplegado el 22-11 (lote 2), apagado; 0 pagos aprobados sin activar | Su prueba real |
| Cobro manual de un plan con IVA adicional (PT-16) | **EN INTEGRACIÓN** | 2 240 casos comparados con la regla anterior; revisión final | Autorizar su despliegue (inerte hoy: el IVA adicional está apagado) |
| Activador de membresías | Sin tocar, por recomendación | Análisis contrastado con producción | Decisión del propietario |

### Apartado 13 · Credenciales e infraestructura (50,8 %)

| Función | Estado | Qué falta |
|---|---|---|
| Rotar la clave de ElevenLabs | **BLOQUEADO** | Acción del propietario (procedimiento seguro, sin pegarla en el chat) |
| Copias históricas del entorno | Revisadas | La limpieza pide autorización |
| Rol Administrativo y cuentas Super Admin | Revisados | El ajuste pide autorización |
| Reimportar el número en ElevenLabs con clave restringida y rotar el token maestro | **BLOQUEADO** | Acción del propietario |

### Apartado 14 · Pruebas finales y certificación (40,0 %)

| Función | Estado | Evidencia |
|---|---|---|
| Batería completa sobre la integración definitiva | **HECHO** | 19 730 pruebas; un único fallo, la puerta de diff |
| Recorridos de extremo a extremo simulados | **HECHO**, con la voz parcial | `plans/informes-cierre/informe-tester-e2e-final.md` |
| Pruebas canarias reales de los cinco recorridos | Pendiente (real) | — |
| Aceptación final | Pendiente | Después de las pruebas reales |

## 3. Los pendientes del control de cierre

| Id | Qué | Estado | Evidencia principal |
|---|---|---|---|
| PT-01 | Corrección del turno de planes: notas de reescritura | CERRADO y desplegado | `a4-evidencia/163` |
| PT-02 | Lote 4, backend | CERRADO | `informe-apisec-lote4.md`, `informe-reviewer-lote4.md` |
| PT-03 | Lote 3, backend | CERRADO | `informe-reviewer-lote3.md` |
| PT-04 | Runbook y mando de la primera llamada | CERRADO | `informe-security-pt04.md`; `ops/evidencia-h9/2026-11-22/08-…` |
| PT-05 | Lote 5, backend | CERRADO | `informe-reviewer-lote5.md` (un bloqueante, corregido) |
| PT-06 | Guion de despliegue por lotes | CERRADO | `ops/evidencia-h9/2026-11-22/07-…` |
| PT-07 | CRM: autorizaciones de contacto | CERRADO | `informe-fe-lote4.md` |
| PT-08 | CRM: quejas | CERRADO | `informe-fe-lote4.md` |
| PT-09 | CRM: laboratorio frente al motor | CERRADO | `informe-fe-lab.md` |
| PT-10 | Revisión final del CRM nuevo | CERRADO | `informe-reviewer-crm-cierre.md`, `informe-reviewer-ids.md` |
| PT-11 | Integración definitiva y su batería | CERRADO | `integracion/final` `c4323242` |
| PT-12 | Recorridos de extremo a extremo simulados | CERRADO (voz parcial) | `informe-tester-e2e-final.md` |
| PT-13 | Esta matriz | CERRADO con este documento | — |
| PT-14 | Quién gobierna lo que Sofía dice de los planes | ESPERA LA DECISIÓN DEL PROPIETARIO | `informe-security-lote3.md`, B-1 |
| PT-15 | Condiciones previas a ampliar WhatsApp | CERRADO | `informe-backend-pt15-condiciones.md`, `informe-reviewer-pt15.md`, `informe-security-pt15.md` |
| PT-16 | Cobro manual con IVA adicional | CERRADO | `informe-reviewer-pt17-pt16.md`, parte B |
| PT-17 | Instrucciones del turno de planes (compilador 5) | CERRADO en lo técnico; la aceptación exige la medición y la quinta prueba | `informe-security-pt17.md`, `informe-reviewer-pt17-pt16.md` |
| PT-18 | El identificador del socio en autorizaciones y quejas | CERRADO | `informe-apisec-ids.md`, `informe-reviewer-ids.md`; batería completa del lote 4 |
| PT-19 | Las puertas internas cuentan la petición antes de comprobar el secreto (anterior a este trabajo; en producción) | PROPUESTO: espera la autorización del propietario | `ops/evidencia-h9/2026-11-22/10-puertas-internas-cupo-sin-secreto.txt` |

## 4. Lo que solo demuestra una prueba real

1. Que el modelo escriba el turno de planes con una lista válida (laboratorio con el modelo real y quinta prueba).
2. El audio en los dos sentidos, la voz de Sofía, la latencia y el corte por duración de una llamada.
3. Una acción automática real de la automatización (WhatsApp con plantilla o llamada): hoy cerrada por diseño.
4. La clasificación de una respuesta real y el seguimiento.
5. Las pantallas nuevas del CRM en un navegador contra el servidor real.
6. Lo que el guion de despliegue solo puede ver en el servidor (lo enseña su paso de comprobación antes de cada despliegue).

## 5. Lo que depende del propietario

| Qué | Para qué |
|---|---|
| Autorizar el despliegue del lote 5 y de su pantalla, y decidir el modelo del laboratorio | Medir el turno de planes sin WhatsApp |
| Aprobar los seis textos nuevos y autorizar el despliegue del compilador 5; publicar una versión nueva; quinta prueba | Aceptar el apartado 4 |
| Autorizar el despliegue del lote 4 y de sus pantallas; fijar el texto legal con su asesoría | Autorizaciones de contacto y quejas |
| Autorización específica del lote 3; después, A0 a A3 del runbook | Voz y primera llamada |
| Decidir PT-14 | Llamar a clientes reales |
| Autorizar el despliegue de PT-16 y de PT-15 | Pagos con IVA adicional; ampliar WhatsApp |
| **Decidir PT-19** (corregir que las puertas internas cuenten antes de comprobar el secreto) | Que nadie pueda dejar sin respuesta al asesor de WhatsApp, ni sin notificaciones a los socios, con peticiones sin credenciales |
| Vincular a dos personas reales; rotar la clave de ElevenLabs; clave restringida de Twilio | Sombra con estrategias, plantillas de Meta, voz |

## 6. Hallazgos adyacentes, fuera del alcance congelado (documentados, sin tocar)

- **Puertas internas, en producción desde antes de este cierre (el más serio):** el limitador corre antes que la comprobación del secreto y su cubo es global; 120 peticiones por minuto sin credenciales, desde internet, dejan sin respuesta al asesor de WhatsApp, y 600, sin notificaciones a los socios. No expone datos. Reproducido en local y comprobado en producción en solo lectura (`ops/evidencia-h9/2026-11-22/10-puertas-internas-cupo-sin-secreto.txt`). Propuesto como PT-19.
- **Pagos, en producción desde antes de este cierre:** con el precio nuevo apagado, marcar «importe distinto» sin escribir el motivo acepta cualquier importe para un plan (`informe-reviewer-pt17-pt16.md`, NB-B3).
- **CRM de producción:** el botón flotante de IRON tapa «Crear borrador» en «Nueva versión de plantilla» a 1 440 px (`informe-tester-e2e-final.md`, NB-1).
- Los no bloqueantes de cada revisión, en `MATRIZ_CIERRE_V2.md` §11.2.
