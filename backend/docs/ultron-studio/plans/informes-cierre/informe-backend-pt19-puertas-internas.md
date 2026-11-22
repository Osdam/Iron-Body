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
`tests/Feature/Marketing/PuertasInternasSecretoAntesDelCupoTest.php`, 10 pruebas:

| Prueba | Qué afirma | Con el código de producción |
|---|---|---|
| `test_una_inundacion_sin_secreto_no_cambia_lo_que_recibe_el_asesor` (3 casos: sin cabecera, secreto equivocado, esquema que no es Bearer) | Tras 12 peticiones sin secreto a `decide`, `commit` y `check`, n8n recibe lo mismo que sin inundar | Cae (429) |
| `test_una_inundacion_sin_secreto_no_cambia_lo_que_reciben_las_notificaciones` (3 casos) | Lo mismo en `notify-member` | Cae (429) |
| `test_el_401_no_ensena_el_contador_del_cupo` | El 401 no lleva `X-RateLimit-*` ni `Retry-After` | Cae (enseña `X-RateLimit-Limit`) |
| `test_en_toda_ruta_interna_el_secreto_corre_antes_que_el_limitador` | La guarda: en TODA ruta con la puerta interna, la puerta va delante de cualquier limitador en el orden efectivo | Cae (las 21) |
| `test_con_el_secreto_el_tope_sigue_existiendo` | Con tope 8, de 12 peticiones legítimas se rechazan 4, en el asesor y en las notificaciones | Pasa (regresión) |
| `test_el_429_del_trafico_legitimo_conserva_sus_cabeceras` | El 429 legítimo sigue diciendo cuándo reintentar | Pasa (regresión) |

- Con la corrección: `OK (10 tests, 202 assertions)`. Sin ella: `Tests: 10, Failures: 8` [C].
- `LimitadoresInternosTest` (el aislamiento entre puertas, anterior): en verde [C].
- Las 68 clases de prueba que usan la puerta interna (`api/internal/`, el secreto, automatización): `OK (1574 tests, 25316 assertions)` [C].
- La guarda construye el núcleo HTTP antes de mirar el orden: sin él, el enrutador no tiene la lista de prioridad y la prueba mediría el orden declarado.

## Lo que se valoró y no se añadió
- **Un tope por IP para los intentos sin secreto.** Antes, el cubo global limitaba también, de rebote, los intentos de adivinar el secreto. Ahora no los limita la aplicación. No se añade: el secreto de producción tiene 64 caracteres (leída solo su longitud), nginx mantiene su límite de 30 peticiones por segundo y por IP, y un 401 cuesta lo mismo que un 429. Añadirlo sería otra pieza que mantener. Queda a criterio de la revisión de seguridad.
- **Una regla de nginx para `/api/internal/`.** Defensa añadida, de infraestructura; no forma parte de este cambio.

## Lo que NO se ha verificado
- Nada en producción con el cambio. **Tras desplegar:** recargar PHP-FPM (el cambio vive en `bootstrap/app.php`; las cachés de rutas y de configuración no lo contienen) y repetir la petición única sin credenciales: debe dar 401 **sin** `x-ratelimit-*`. El siguiente turno real de n8n debe responder como siempre.
- Batería completa: la lanza el lead sobre la punta de la rama.

## Al integrar con PT-15
El cubo propio de `ai/check` (Enmienda A4-6) queda cubierto sin tocar nada: la prioridad actúa sobre la puerta, no sobre cada limitador, y la prueba de la inundación ya incluye `check`. Resuelve NB-2 y NB-3 de `informe-security-pt15.md`.
