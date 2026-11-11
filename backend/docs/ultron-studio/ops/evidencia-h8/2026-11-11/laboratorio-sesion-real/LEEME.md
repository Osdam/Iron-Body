# Laboratorio hablado · primera sesión real en producción (11-11-2026)

Cuenta temporal de humo (id 32, Super Admin solo durante la prueba), versión «ULTRON Studio - Canario V1 - Pruebas»
(aprobada) y perfil «Sofia Medellin» (revisión 2, aprobado). Navegador real sin cabeza con micrófono sintético.
Herramientas: `ops/h8-lab-real/`.

| Intento | Hora (UTC) | Producción | Resultado |
|---|---|---|---|
| 1 | 05:51 | API `da80942`, CRM `7a58f16` | El CRM abre, el servidor despliega el agente y el WebSocket conecta (101); **ElevenLabs lo cierra a los 0,3 s**: `1008 agent_configuration_error · Missing required dynamic variables in tools: {'call_ref'}`. La sesión se cierra sola por el informe post-llamada firmado (0 s, US$ 0,00). 12 OK · 1 FALLO |
| 2 | 11:31 | API `120a8b2`, CRM `f5cd0d2` (enmienda E18) | Conecta y **el agente habla** (aviso de IA y privacidad). El micrófono falso estaba mudo (caja de arena del servicio de audio de Chrome: defecto del arnés, no del producto): el proveedor transcribe «...», el agente pregunta dos veces si sigue ahí, se despide y cuelga con `end_call`. 52 s, US$ 0,07. 15 OK · 0 FALLO |
| 3 | 11:37 | los mismos | **Conversación de ida y vuelta.** Persona: «Hola, buenas noches. Quisiera saber qué planes tienen y cuánto cuesta la mensualidad.» Agente: «Hola, buenas noches. Ese dato no te lo puedo confirmar. Si gustas, el equipo de Iron Body te puede dar toda la información sobre nuestros planes y precios por WhatsApp o directamente en la sede.» Persona: «Perfecto. Muchas gracias. Eso era todo. Que tenga buena noche.» Agente: «Gracias por tu tiempo. Fue un gusto conversar contigo. Que tengas un excelente día.» y `end_call`. 67 s, US$ 0,09; 2 083 tramas de audio enviadas (537 KB) y 20 recibidas (293 KB), μ-law 8 kHz. Cierre por `provider_report`. 15 OK · 0 FALLO |
| 4 | 18:55 | la versión aprobada y la **revisión 3** del perfil «Sofia Medellin» (con `consultar_planes` y `consultar_horarios`; enviada a revisión) | **Consultas reales (E18-8).** Persona: «Quisiera saber qué planes tienen y cuánto cuesta la mensualidad.» El agente llamó a `consultar_planes` y dijo el Plan Valera (65 000), el Plan Mensual (80 000), el Élite (180 000) y la PROMO X4 (75 000), de 30 días: son los de la base. Persona: «¿Y dónde queda el gimnasio y en qué horario atienden?» El agente llamó a `consultar_horarios` y dijo la sede (Calle 24 Sur número 33-53, Neiva, Huila) y el horario (lunes a viernes de 5 a 22; sábados, domingos y festivos de 8 a 14). Despedida y `end_call`. 97 s, US$ 0,13; 15 OK · 0 FALLO en el guion. En el servidor: dos `voice.tool` con `verdict: ok` y `lab: true`, ninguna `invalid_schema`; sesión cerrada por el informe del proveedor, `toolRequests = 2`. Carpeta `intento-4-consultas-de-planes-y-horarios/` |

Lo que queda demostrado: abrir desde el CRM, compilar y desplegar el agente, la deriva (D8-7), la URL firmada, el cliente
oficial en el navegador, audio en los dos sentidos, la transcripción, la herramienta de sistema `end_call`, el webhook
post-llamada firmado, el coste y el cupo, y la lista del CRM.

Lo que NO demuestra: una persona hablando con su voz (lo hace el propietario), ni el veredicto de otra persona (cuatro
ojos; estas tres sesiones quedan sin veredicto), ni una llamada telefónica.

Observaciones para quien juzgue al agente (no son fallos de la tubería): con el perfil actual el agente **no informa
planes ni precios** (el perfil solo tiene la herramienta «no volver a llamar»; además, en el laboratorio las
herramientas de consulta no devuelven datos porque la conversación no es una llamada); no usó el saludo de llamada
saliente del perfil; se despidió con «excelente día» de noche.
