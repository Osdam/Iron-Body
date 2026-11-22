# Informe de backend · PT-19: la puerta del secreto interno corre antes que el limitador

**22-11-2026 · rama `cierre/puertas-internas` (desde `feat/ultron-studio-h9`; su código de producto es el de producción, `f1fe6cac`) · SIN desplegar, sin push.** Lo implementó el lead, con la autorización del propietario del 22-11 («autorizo implementar la corrección de seguridad en código, verificando la autenticación antes de descontar el cupo de solicitudes internas. Mantén límites de tráfico legítimo y pruebas de regresión»). **[C]** comprobado ejecutando · **[L]** leído.

## El defecto
Las 21 rutas internas (n8n → API) declaran `['automation.internal', 'throttle:…']`. Laravel reordena los middlewares por su lista de prioridad, donde está `ThrottleRequests` y no `VerifyInternalAutomationSignature`: el limitador corría ANTES que la puerta del secreto. Los cubos con nombre son globales (`internal-marketing-ai`, 120 por minuto; `internal-automation`, 600), así que una petición SIN secreto recibía su 401 y aun así gastaba el cupo de n8n. Hallazgo P-1 de seguridad (`plans/informes-motores/informe-security-pt15.md`); evidencia en producción, en solo lectura: `ops/evidencia-h9/2026-11-22/10-puertas-internas-cupo-sin-secreto.txt`.

## El cambio (un fichero de producto)
`bootstrap/app.php`: `prependToPriorityList(before: ThrottleRequests, prepend: VerifyInternalAutomationSignature)`. Hace efectivo el orden que las rutas ya declaraban. No cambia rutas, limitadores, topes, configuración ni base de datos.

- Sin secreto (o con uno equivocado): 401 **sin contar** y **sin cabeceras `X-RateLimit-*`**.
- Con secreto: el mismo tope de siempre y el mismo 429 con `Retry-After`.
- El orden efectivo en las 21 rutas internas [C]: `VerifyInternalAutomationSignature → ThrottleRequests → SubstituteBindings → ProtectAdminPaths → EnforceAdminAuthorization → EnforceTrainerMemberScope`.
- **Ninguna otra ruta cambia [C]:** el orden efectivo de las 1 046 rutas, antes y después, difiere en 21, todas bajo `api/internal/`.

## Pruebas
`tests/Feature/Marketing/PuertasInternasSecretoAntesDelCupoTest.php`, 12 pruebas (10 al implementar y 2 tras la revisión de seguridad):

| Prueba | Qué afirma | Con el código de producción |
|---|---|---|
| `test_una_inundacion_sin_secreto_no_cambia_lo_que_recibe_el_asesor` (3 casos: sin cabecera, secreto equivocado, esquema que no es Bearer) | Tras 12 peticiones sin secreto a `decide`, `commit` y `check`, n8n recibe lo mismo que sin inundar | Cae (429) |
| `test_una_inundacion_sin_secreto_no_cambia_lo_que_reciben_las_notificaciones` (3 casos) | Lo mismo en `notify-member` | Cae (429) |
| `test_el_401_no_ensena_el_contador_del_cupo` | El 401 no lleva `X-RateLimit-*` ni `Retry-After` | Cae (enseña `X-RateLimit-Limit`) |
| `test_en_toda_ruta_interna_el_secreto_corre_antes_que_el_limitador` | La guarda, por la clase RESUELTA de cada middleware y sobre el orden efectivo: ninguna ruta vive bajo `api/internal/` ni gasta un cubo global de n8n sin que la puerta corra antes que su limitador | Cae (las 21) |
| `test_la_guarda_ve_una_ruta_que_gasta_el_cubo_de_n8n_sin_la_puerta_y_una_interna_sin_puerta` | La guarda ve lo que debe (NB-1 de seguridad): una ruta fuera de `api/internal/` que comparte el cubo sin la puerta, y una interna sin puerta; y no se queja de una declarada al revés, que la prioridad corrige | Cae |
| `test_una_firma_mala_con_el_secreto_valido_no_cuenta_ni_cambia_lo_que_recibe_el_asesor` | Con el secreto válido y la firma HMAC mala, el 403 no cuenta ni enseña el contador (NB-3) | Cae (429) |
| `test_con_el_secreto_el_tope_sigue_existiendo` | Con tope 8, de 12 peticiones legítimas se rechazan 4, en el asesor y en las notificaciones | Pasa (regresión) |
| `test_el_429_del_trafico_legitimo_conserva_sus_cabeceras` | El 429 legítimo sigue diciendo cuándo reintentar | Pasa (regresión) |

- Con la corrección, la clase y `LimitadoresInternosTest`: `OK (15 tests, 212 assertions)`. Sin ella (el `bootstrap/app.php` de producción): `Tests: 12, Failures: 10`; pasan las dos de regresión del tope legítimo [C].
- `LimitadoresInternosTest` (el aislamiento entre puertas, anterior): en verde [C].
- Las 68 clases de prueba que usan la puerta interna (`api/internal/`, el secreto, automatización): `OK (1574 tests, 25316 assertions)` [C].
- La guarda construye el núcleo HTTP antes de mirar el orden: sin él, el enrutador no tiene la lista de prioridad y la prueba mediría el orden declarado.

## Lo que se valoró y no se añadió
- **Un tope por IP para los intentos sin secreto.** Antes, el cubo global limitaba también, de rebote, los intentos de adivinar el secreto. Ahora no los limita la aplicación. No se añade: el secreto de producción tiene 64 caracteres (leída solo su longitud), nginx mantiene su límite de 30 peticiones por segundo y por IP, y un 401 cuesta lo mismo que un 429. Añadirlo sería otra pieza que mantener. Queda a criterio de la revisión de seguridad.
- **Una regla de nginx para `/api/internal/`.** Defensa añadida, de infraestructura; no forma parte de este cambio.

## Lo que NO se ha verificado
- Nada en producción con el cambio. **Tras desplegar:** recargar PHP-FPM (el cambio vive en `bootstrap/app.php`; las cachés de rutas y de configuración no lo contienen) y repetir la petición única sin credenciales: debe dar 401 **sin** `x-ratelimit-*`. El siguiente turno real de n8n debe responder como siempre.
- La batería completa se corrió sobre `5cd201eb` (ver abajo); después solo cambiaron esta clase de pruebas y los documentos.

## Al integrar con PT-15
El cubo propio de `ai/check` (Enmienda A4-6) queda cubierto sin tocar nada: la prioridad actúa sobre la puerta, no sobre cada limitador, y la prueba de la inundación ya incluye `check`. Resuelve NB-2 y NB-3 de `informe-security-pt15.md`.

## Batería completa y revisión de seguridad (22-11-2026)
- **Batería completa sobre `5cd201eb`** (el commit de la corrección): 19 263 pruebas y 506 149 aserciones; un único fallo, el tolerado de la línea base (la prueba de pagos que corrige PT-16 en su rama); **0 regresiones** [C].
- **Seguridad de API, independiente, una pasada: APTO CON CONDICIONES, 0 bloqueantes, 5 no bloqueantes** (`informe-apisec-pt19.md`). Confirmó por su cuenta que de las 1 046 rutas cambian 21, todas internas; que no queda otra forma de gastar los cubos de n8n sin el secreto (ninguna otra ruta usa esos limitadores, ninguna clave choca, no hay `throttle` en el grupo `api`); que ninguna otra puerta de máquina (voz, webhooks) tiene el patrón «limitador global antes de autenticar»; que con el secreto válido el recorrido es idéntico, y, con una mutación propia, que las pruebas caen sin la prioridad.
- **Lo que pidió y se hizo** (solo pruebas): la guarda ampliada (NB-1) y la prueba de la firma mala (NB-3).
- **Lo que no se añade:** un tope por IP para los fallos de secreto (NB-2: la fuerza bruta es inviable con 64 caracteres y sin oráculo; se pierde solo la visibilidad en la aplicación; decide el propietario).
- **Condición de despliegue (NB-4):** `ops/deploy-cierre-lote.sh` y `ops/hotfix-backend.sh` ABORTAN con este diff, porque no admiten cambios en `bootstrap/app.php` salvo el montaje de un fichero de rutas. Fallan cerrado, que es lo correcto: hace falta una vía de despliegue autorizada para este cambio (una enmienda del guion, ensayada en el banco). La prueba de que el código nuevo está sirviendo es el marcador: una petición sin credenciales da `401` SIN `x-ratelimit-*`. Las cachés de rutas y de configuración no intervienen; si el FPM revalida los ficheros, ni siquiera hace falta recargarlo.
- **Fuera de este cambio, anterior, solo documentado (NB-5):** los limitadores del kiosko cuentan por el `device_id` declarado antes de verificar la firma del dispositivo; quien conozca el id de una tablet podría agotar su cupo. Afecta a una tablet, no es global, y ya se valoró en una revisión anterior.

