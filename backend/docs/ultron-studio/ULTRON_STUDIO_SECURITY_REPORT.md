# ULTRON STUDIO: informe de seguridad

**Fecha:** 17-10-2026. **Alcance:** la vista previa visual (Hito C/D). Se irá ampliando con cada fase.

## Superficie de esta versión

- **Solo frontend.** Sin endpoints nuevos, sin llamadas a la API del CRM ni a servicios externos desde el módulo (no hay `HttpClient`, `fetch`, `WebSocket` ni `EventSource`; los cinco paquetes de Foblex tampoco usan red).
- **Acceso:** ruta y submenú detrás de la bandera `ultronStudioPreviewEnabled` y del permiso `marketing.agent.manage` (Super Admin y Administrador). El submenú y la ruta usan la misma condición. Como no hay API nueva, el backend no tiene nada que autorizar todavía.
- **Datos:** demostración y un borrador en `localStorage` por persona (`ironbody.ultronStudio.preview.v1.<id>`), sin datos personales.

## Revisión independiente (`security`)

**Veredicto: nada bloquea publicar la vista previa.** Sin hallazgos críticos ni altos.

| # | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| H1 | Media | **El catálogo viaja en un chunk público.** Los estáticos del CRM no pueden exigir sesión (el token es un bearer en el almacenamiento del navegador), así que cualquiera puede descargar el chunk del Studio aunque el guard no le deje navegar a la ruta. El catálogo llevaba rutas de ficheros del backend con números de línea, nombres de rutas internas, el detalle de firma e idempotencia del puente con n8n, procedimientos de operación (freno, «frase del propietario», runbook) y el estado de certificación. Sin secretos ni datos personales, pero útil para preparar un ataque o ingeniería social | **Corregido (poda).** Los `ref` son nombres de clases, tablas o comandos, sin rutas ni líneas; se retiraron las rutas internas, el detalle de firmas y los procedimientos. Una prueba (`studio-catalog.spec.ts`) vigila que no vuelvan (`app/Services`, `app/Http`, `config/marketing`, `HMAC`, `wamid`, `NO-GO`, `php artisan`, «frase del propietario», «runbook»), y se comprobó sobre los chunks del build de producción |
| H2 | Baja | `sanitize` no validaba `viewport` ni `savedAt`, ni el tipo de cada elemento de las listas, ni que un `select` estuviera entre sus opciones, ni acotaba números (`1e999` → `Infinity`). Sin XSS (todo se pinta por interpolación o binding de propiedad) y sin contaminación de prototipos; solo UI rota para quien pudiera escribir en el almacenamiento de la víctima | **Corregido.** `viewportSeguro` (finito, escala entre 0,3 y 2,2), `savedAt` solo si `Date.parse` lo entiende, listas solo con texto recortado (≤ 60 caracteres, ≤ 20 elementos), `select` solo entre opciones, rangos acotados a mínimo y máximo. Pruebas con los casos de la revisión (`viewport:{x:'a'}`, `savedAt:5`, `sources:[{},1]`, `tone:'<img …>'`, `maxChars:1e999`) |

**Confirmado por la revisión:** sin `innerHTML` ni `bypassSecurityTrust*`; `[class]` y `[style.--accent]` solo reciben constantes; sin `[href]`/`[src]`; los avisos de saneamiento de Angular 21.2.10 no se alcanzan desde este código; los paquetes de Foblex no tienen scripts de instalación, su única dependencia es `tslib`, no usan red ni `eval`; en el lockfile entran los cinco paquetes y desaparecen 14 entradas opcionales de desarrollo que npm 11 ya no anota (`jiti`, `lightningcss` y sus binarios, `tailwindcss`), sin cambiar ninguna versión ni hash de lo que ya había; los atajos de teclado viven en el componente y desaparecen al salir de la ruta.

**Decisión de diseño que la revisión respalda:** el catálogo debe venir del **backend** en la fase con persistencia (autorización real en el servidor, una sola fuente de verdad y la misma validación del grafo en ambos lados). Mientras tanto, la poda.

**Nota sobre la bandera:** `ultronStudioPreviewEnabled` quita la ruta y el submenú en ejecución, pero el chunk se sigue generando en el build. Para que la bandera retirara el chunk habría que resolverla al compilar (`fileReplacements` de un fichero de rutas). Con la poda, el chunk ya no contiene nada operativo, así que no se hizo.

## Fase de persistencia (Hito F): API `/api/admin/ultron-studio/*`

**Superficie nueva:** 19 rutas (status, catálogo, borrador, versiones, reglas, auditoría), cinco tablas `ultron_studio_*`, seis permisos `ultron.*`. El motor no cambia (`git diff origin/main` sobre `app/Services/Marketing`, `app/Jobs`, `app/Http/Controllers/Api/Internal`, `routes/marketing.php`, `routes/api.php` y `config/marketing.php` sale vacío) y no lee ninguna tabla del Studio. **El catálogo ya no viaja en el bundle público**: lo sirve `GET catalog` solo a quien tiene `ultron.view` (cierra H1 de la vista previa de forma estructural).

**Revisión independiente (`api-security-specialist`), sobre el commit `b4e07af`:** sin hallazgos críticos, altos ni medios. Verificado con sondas reales: autorización ruta a ruta (falla cerrado, `auth:coverage --unmapped` vacío, `requirePerson()` en las 19 acciones; token compartido, roles con todo Mercadeo y admin desactivado → 403; Administrador sin `publish`/`rollback`), sin BOLA (el borrador no lleva id y filtra por la persona; un `admin_id` en el cuerpo se ignora; los DTO no llevan ids de base, correos ni `clientRequestId`), cuatro ojos por identidad (aguantan ascensos de rol a mitad del flujo y campos de creador/revisor inyectados), máquina de estados (toda transición inválida → 409; publicar/revertir → 409 siempre; en la base solo hay estados alcanzables), `clientRequestId` ajeno → 409 sin datos, entrada acotada (64 KB, JSON malformado, `baseRevision` no entero, uuid inválido, bidi/ZWSP/NUL → 422; `per_page` ≤ 100), cupos con `Retry-After`.

| # | Gravedad | Hallazgo | Corrección (aplicada, con prueba) |
|---|---|---|---|
| H1 | Baja | Las llaves de escritura no exigían `ultron.view`: un rol personalizado con solo `ultron.approve` podía aprobar «a ciegas» copiando los avisos del 409 y leer el detalle en la respuesta | `StudioAccess::require()` exige `ultron.view` además de la llave de la acción (`UltronStudioHardeningTest`) |
| H2 | Baja | El cupo se contaba por token antes de autenticar: un bearer inventado por petición nunca llegaba a 429 | Segundo techo por red: 600/min lectura y 120/min escritura (holgado: la oficina comparte IP). Depende de que `$request->ip()` sea la IP real tras el proxy (verificar `TrustProxies` en producción) |
| H3 | Baja | El libro de solo inserción se podía inundar con intentos denegados (aprobar lo propio, publicar) | `StudioAudit::recordDenied()`: una fila por persona, sujeto, evento y resumen cada 10 minutos |
| H4 | Baja | Reglas sin tope: `GET rules` sin paginar y cada versión copiaba todas las reglas en una fila inmutable | Tope de 100 reglas del Studio sin archivar → 409 `rule_limit_reached` |
| H6 | Baja (pruebas) | La prueba de las protecciones que solo existen en PG salía en verde en SQLite sin comprobar nada; `conditionalWrite()` no pasaba por el modelo | La prueba se marca OMITIDA en SQLite (hay que correr el grupo con `DB_CONNECTION=pgsql`); `conditionalWrite()` lanza si una regla del Studio intenta cambiar de identidad o activarse |
| H5 | Informativo | `GET rules` revela qué claves de la base de conocimiento existen y su estado (nunca su contenido) a todo `ultron.view` | **Aceptado** por contrato: `ultron.view` es de Super Admin y Administrador |
| H7–H9 | Informativos | Ids secuenciales del libro (huecos revelan cuántos eventos ajenos hubo); el catálogo es un mapa del motor (por diseño, solo `ultron.view`); `PlainText` admite U+2028/U+2029/U+00AD (regla compartida por todo el CRM) | Aceptados; U+2028 queda anotado para `security` fuera del Studio |
| S2 (datos) | Baja | `ip_address` y `user_agent` del personal en un libro inmutable sin política de retención (Ley 1581) | No se persisten en esta fase: quedan `NULL` (prueba en `UltronStudioAuditTest`) |
| S1 (datos) | Nota | El rol de la base que migra es el mismo que ejecuta: podría desactivar los disparadores | Para `devops`/`security` antes del uso real: separar el rol dueño (migra) del rol de ejecución (solo DML) |
| P5 (datos) | Nota | La tabla de reglas no tenía disparador de borrado: un DELETE por SQL saltaría la auditoría | Disparadores `usr_forbid_delete` y `usr_forbid_truncate` (las reglas se archivan, no se borran) |

**Carreras reales (`tester`, pila real con `artisan serve --no-reload` y PostgreSQL 14):** 57 repeticiones sin un solo resultado incorrecto: dos `PUT draft` con la misma revisión → 200 + 409; dos primeros guardados → una sola fila; dos `POST versions` con el mismo `clientRequestId` → 201 + 200 `replayed`, una sola versión; dos `approve` simultáneos → 200 + 409 y exactamente una reseña. Además, un `POST rules` concurrente podía agotar los reintentos del código S-NNN (500): corregido con `pg_advisory_xact_lock` en PostgreSQL.

## Requisitos para las fases siguientes (recogidos de las revisiones y la auditoría)

- Permisos `ultron.*` (ver, editar borrador, reglas, campañas, simulaciones, canarios, pruebas telefónicas, aprobar, publicar, revertir, ajustes) **validados en Laravel en cada endpoint**; separar quien edita, aprueba, publica y administra integraciones.
- **El servidor revalida todo lo publicable** sin fiarse del borrador: la confirmación de los parámetros importantes hoy solo existe en el navegador, y un borrador cargado de `localStorage` podría traer `paymentLinksEnabled: true` sin haber pasado por ella.
- El Studio **nunca** reutiliza las rutas internas de n8n ni muestra ejecuciones de n8n (guardan el bearer).
- Nunca devolver al front tokens, secretos de webhook, claves de OpenAI/ElevenLabs ni teléfonos en claro; ids de Meta enmascarados como hace el panel actual.
- Evaluación con OpenAI: clave y proyecto propios, salidas estructuradas estrictas, registro de tokens y coste, entradas redactadas.
- Twilio Voice: subcuenta, `TimeLimit`, cupo diario propio y rangos de alto riesgo cerrados; webhooks con firma validada e idempotencia; ningún mecanismo actual frenaría un gasto de voz.
- ElevenLabs: adaptador tras bandera, webhooks firmados, grabación desactivada hasta resolver consentimiento y retención (Ley 1581).
- Hallazgos previos del motor que no son del Studio pero importan (auditoría §13): `/ai/decide` sin interruptor ni canario, el precio que llega al Strategist, la respuesta del commit con precio y URL de pago hacia n8n.
