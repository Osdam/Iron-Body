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
| H2 | Baja | El cupo se contaba por token antes de autenticar: un bearer inventado por petición nunca llegaba a 429 | Segundo techo por red: 600/min lectura y 120/min escritura (holgado: la oficina comparte IP), agrupando con `ClientNetwork::of()` (IPv6 por /64, como el resto de cupos del CRM). Depende de que `$request->ip()` sea la IP real tras el proxy (verificar `TrustProxies` en producción) |
| H3 | Baja | El libro de solo inserción se podía inundar con intentos denegados (aprobar lo propio, publicar) | `StudioAudit::recordDenied()`: una fila por persona, sujeto, evento y resumen cada 10 minutos |
| H4 | Baja | Reglas sin tope: `GET rules` sin paginar y cada versión copiaba todas las reglas en una fila inmutable | Tope de 100 reglas del Studio sin archivar → 409 `rule_limit_reached` |
| H6 | Baja (pruebas) | La prueba de las protecciones que solo existen en PG salía en verde en SQLite sin comprobar nada; `conditionalWrite()` no pasaba por el modelo | La prueba se marca OMITIDA en SQLite (hay que correr el grupo con `DB_CONNECTION=pgsql`); `conditionalWrite()` lanza si una regla del Studio intenta cambiar de identidad o activarse (prueba por reflexión en `UltronStudioHardeningTest`) |
| H5 | Informativo | `GET rules` revela qué claves de la base de conocimiento existen y su estado (nunca su contenido) a todo `ultron.view` | **Aceptado** por contrato: `ultron.view` es de Super Admin y Administrador |
| H7–H9 | Informativos | Ids secuenciales del libro (huecos revelan cuántos eventos ajenos hubo); el catálogo es un mapa del motor (por diseño, solo `ultron.view`); `PlainText` admite U+2028/U+2029/U+00AD (regla compartida por todo el CRM) | Aceptados; U+2028 queda anotado para `security` fuera del Studio |
| S2 (datos) | Baja | `ip_address` y `user_agent` del personal en un libro inmutable sin política de retención (Ley 1581) | No se persisten en esta fase: quedan `NULL` (prueba en `UltronStudioAuditTest`) |
| S1 (datos) | Nota | El rol de la base que migra es el mismo que ejecuta: podría desactivar los disparadores | **Resuelto en el despliegue del 18-10-2026:** la app corre con `iron_app` (solo DML, sin `CREATE` en `public`); `iron` (dueño) solo migra vía `ironbody-migrate`, con sus credenciales en `/root/.ironbody-owner.env` (600, root) y nunca en el `.env` ni en la caché de configuración (riesgo señalado por la revisión automática del commit `9acddc3`: la conexión `pgsql_owner` solo recibe las credenciales del dueño durante la migración) |
| P5 (datos) | Nota | La tabla de reglas no tenía disparador de borrado: un DELETE por SQL saltaría la auditoría | Disparadores `usr_forbid_delete` y `usr_forbid_truncate` (las reglas se archivan, no se borran) |
| H-1 (tester) | Baja | La deduplicación de intentos denegados competía consigo misma: cuatro `publish` simultáneos dejaban 3–4 filas | `recordDenied()` comprueba e inserta en una transacción con `pg_advisory_xact_lock`; verificado con la pila real (20 repeticiones, una fila) |
| H-2 (tester) | Informativo | Oráculo de existencia: un rol con una llave de escritura y sin `ultron.view` recibía 404 con un uuid inexistente y 403 con uno existente | `requirePerson()` exige `ultron.view` antes de buscar ningún recurso: siempre 403 |

**Carreras reales (`tester`, pila real con `artisan serve --no-reload` y PostgreSQL 14):** en las carreras del contrato (dos `PUT draft` con la misma revisión → 200 + 409; dos primeros guardados → una sola fila; `PUT` contra `discard`; dos `POST versions` con el mismo `clientRequestId` → 201 + 200 `replayed`, una sola versión, y con otra etiqueta → 409; `approve` doble, `submit` doble y `approve` contra `reject` → 200 + 409 con exactamente una reseña; 4/6/8 creaciones de reglas a la vez con códigos consecutivos; tope de 100 bajo concurrencia; dos `import-baseline` a la vez) ningún resultado incorrecto en ninguna repetición, naturales ni forzadas. Dos hallazgos del propio arnés, corregidos y verificados: un `POST rules` concurrente podía agotar los reintentos del código S-NNN (500) → `pg_advisory_xact_lock`; y la deduplicación de intentos denegados competía consigo misma (H-1, 3–4 filas) → cerrojo en `recordDenied()` (20 repeticiones, una fila).

## Hito G (control funcional): revisiones de seguridad

**Estado:** implementado en la rama `feat/ultron-studio-g` (backend `391cffb`, con el código en `dfb30f5`; CRM `7d3ad2a`), **no desplegado** *(así se escribió. **Actualización del 19-10-2026:** G está desplegado y verificado desde las 06:00–06:04 UTC de ese día, backend `e651dd9` y CRM `44c0e8c`; ver `ULTRON_STUDIO_FINAL_REPORT.md`)*.

**Superficie nueva:**
- dos rutas de lectura, `GET engine/readings` y `GET compare`, con `ultron.view` y el cupo de lectura (21 rutas en total);
- la columna `ultron_studio_rules.conditions`;
- texto libre nuevo: las frases y etiquetas de las condiciones.

**Lo que no cambia:**
- ninguna llave nueva ni dependencia nueva, y `.env.example`, `config/` y `bootstrap/` siguen igual;
- el motor: `git diff origin/main` sobre sus zonas da 0 líneas, y sigue sin leer el Studio;
- las lecturas del motor son de solo lectura y no sacan ids, secretos, teléfonos, precios ni contenido.

**Revisores y veredictos**

| Revisión | Sobre | Veredicto |
|---|---|---|
| `security` (transversal) | Backend `5074f82` y CRM `688da4a`, más un barrido del aviso B-1 antes de su commit (`7d3ad2a`) | Sin críticos, altos ni medios: 1 bajo de cumplimiento (B-1) y 4 informativos |
| `api-security-specialist` | Backend `5074f82` | Sin críticos ni altos: 2 medios de disponibilidad con actor privilegiado (M-1 y M-2), 2 bajos (B-1 y B-2) y 5 informativos |
| `tester` | Backend `5074f82`, con la pila real y PostgreSQL 14 | 3 hallazgos: H-1 y H-2 medios (calidad de pruebas y proceso) y H-3 bajo (preexistente en F) |

**Hallazgos y estado.** Los códigos se repiten entre revisiones (hay un B-1 de `security` y otro de `api-security`), así que cada uno lleva su origen.

| Origen · # | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| api-security · M-1 | Media | `GET rules?status=archived` analizaba cada archivada releyendo todas las vivas: con 100 vivas pesadas, 1000 archivadas costaban 7,69 s por petición (PG local). Las archivadas no cuentan para el tope de 100 y no se pueden borrar, así que el coste se acumula. Regresión de G | **Corregido en `dfb30f5`.** Análisis neutro para las archivadas, y con `status=archived` no se leen las vivas (100 vivas y 300 archivadas: de 2371 a 27 ms, SQLite local) |
| api-security · M-2 | Media | La validación con comodines (`conditions.when.*`, `conditions.then.*`) es superlineal y corre antes de `max:3`: un cuerpo de 150 KB costaba 12,2 s de CPU. La misma clase ya estaba en producción con F (`layers.*`, `refs.*`, `acknowledgedWarnings.*`; `refs.configs` de 1,65 MB, 157 s) | **Corregido en `dfb30f5`, también en las vías de F.** Validación en dos fases y tope de cuerpo de 64 KB en las 21 rutas (413 `payload_too_large`). Una lista por encima de su tope da un 422 solo con su clave, en menos de 50 ms. **Mientras G no se despliegue, las vías de F siguen expuestas en producción** *(cerrado: G se desplegó el 19-10-2026, 06:00–06:04 UTC)* |
| api-security · B-1 | Baja | Con 100 vivas de 3 + 3 predicados, cada lectura o escritura del Studio cuesta unos 120 ms, y `compare`, unos 240 ms | **Aceptado sin cambio:** el coste lo acotan el tope de 100 reglas y los 4 KB de condiciones |
| api-security · B-2 | Baja | El cerrojo consultivo solo protege en READ COMMITTED, y nada lo fijaba: con la sesión en REPEATABLE READ, la contradicción se colaba (3 de 3) | **Corregido en `dfb30f5`.** `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` como primera sentencia, con una prueba en PG con la sesión en REPEATABLE READ |
| api-security · I-1 | Informativo | El recorte de etiquetas y frases es cuadrático sin PCRE JIT: en G, unos 92 ms como mucho (por los 4 KB); en el normalizador de F (hasta 64 KB), unos 20 s si JIT estuviera apagado | **Sin acción:** producción tiene `pcre.jit = 1` |
| api-security · I-2 | Informativo | `compare` no marca la integridad de una versión: con una alterada responde 200 | **Aceptado en G, diferido al Hito 5 (gobierno):** `compare` es una lectura informativa; `submit` y `approve` comprueban la integridad antes y el disparador `usv_forbid_immutable_update` impide alterar el contenido. En el Hito 5 cada lado de `compare` llevará `integrityOk` |
| api-security · I-3 | Informativo | `Cache-Control: no-cache, private`, sin `no-store`, en la API administrativa (lecturas del motor, auditoría con nombres del personal) | **Fuera de G** (es de toda la API): Hito 7 |
| api-security · I-4 | Informativo | `MarketingKnowledgeBaseService::version()`, código del motor, carga todas las filas activas en cada `GET engine/readings` | **Anotado:** hoy son decenas de ítems, y G no toca el motor |
| api-security · I-5 | Informativo | La lista de archivadas no está paginada: con 1000, la respuesta pesaba de 0,75 a 1,7 MB | **Aceptado en G, diferido al Hito 5:** la corrección de M-1 quitó el coste de CPU; el CRM no pide archivadas y solo `ultron.view` puede pedirlas. En el Hito 5 la lista de archivadas se paginará (misma forma que `GET versions`) |
| security · B-1 | Baja (cumplimiento, Ley 1581) | G guarda texto libre del personal: hasta 500 caracteres de descripción y de motivo, y las frases y etiquetas de las condiciones. Queda en `ultron_studio_rules`, que no admite DELETE, y en los eventos `rule.*` del libro de solo inserción, que no tiene política de retención. Editar la regla no lo quita, y atender una solicitud de supresión exigiría saltarse los disparadores con el rol dueño. El núcleo acepta una frase con forma de teléfono o un nombre propio | **Decisión del lead: aviso fijo** en el formulario de reglas y en el editor de condiciones (CRM `7d3ad2a`): «No escribas datos personales (nombres, teléfonos, correos): las reglas y su historial no se pueden borrar.» **Pendiente del propietario (Hito 7):** rechazar teléfonos y correos por su forma (opción 2) y el procedimiento de redacción del libro con el rol dueño (opción 3) |
| security · I-1 | Informativo | `UltronAbortLatch::current()` se traga cualquier `QueryException`: con la consulta caída, `governance.brake` salía `{available:true, value:false}`. Con la tabla inexistente, la pausa salía «no» | **Corregido en `dfb30f5`.** Consulta directa: si falla, `{available:false}` |
| security · I-2 | Informativo | La prueba estática de aislamiento solo miraba los servicios | **Corregido en `dfb30f5`:** cubre también controladores, modelos y comandos |
| security · I-3 | Informativo | `UntrustedText::isPlain()`, la regla de todo el CRM, acepta U+061C, U+2060, U+180E, U+E0000–E007F, U+2028 y U+00AD. En G solo afecta a etiquetas y frases, que escribe el personal y el motor no lee | **Fuera de G:** Hito 7, con su propia revisión |
| security · I-4 | Informativo | El cuerpo crudo de `conditions` se salta a propósito `TrimStrings` y `ConvertEmptyStringsToNull` | **Sin acción:** el núcleo lo valida todo (4096 bytes, claves exactas, tipos estrictos, opciones del catálogo y ninguna regex de usuario) |
| security · fuera de G | — | La API interna de la base de conocimiento (`InternalMarketingKnowledgeController`) acepta `content` de hasta 4000 caracteres sin filtrar invisibles. El riesgo: instrucciones ocultas que un LLM lee y un revisor no ve («ASCII smuggling») | **No verificado** que exista un flujo que lo aproveche. Revisión aparte del motor (`security` y `api-security-specialist`): Hito 7 |
| tester · H-1 | Media (calidad de pruebas) | `UltronStudioEngineReadOnlyTest` no veía una escritura con métodos dinámicos cuyo efecto neto se deshacía: alternar la pausa dos veces dentro del mismo segundo | **Corregido en `dfb30f5`:** la prueba escucha cada sentencia SQL (`DB::listen`) |
| tester · H-2 | Media (proceso) | Solo las pruebas de PostgreSQL detectan que falta el cerrojo de las reglas. Sin él, la suite SQLite sale verde (459 pruebas, 0 fallos) y, con la pila real, dos escrituras contradictorias se guardan las dos (5 de 5) | **Puerta obligatoria del despliegue** (Enmienda 2; ver abajo) |
| tester · H-3 | Baja (preexistente en F) | Un doble clic simultáneo con 99 reglas vivas respondía 201 + 409 `rule_limit_reached`, en vez de 201 + 200 `replayed`. Los datos quedaban bien | **Corregido en `dfb30f5`**, con una prueba determinista. La carrera con la pila real no se repitió después de corregir |

**Verificado sin hallazgo** (`api-security-specialist`, con sondas reales en SQLite y en PostgreSQL 14 local; `security`, leyendo el código y con una prueba de concepto en el CRM):
- **Autorización de las dos rutas nuevas:**
  - están mapeadas a `ultron.view`, y HEAD se reduce a GET, así que no esquiva la puerta;
  - `requirePerson()` va antes de buscar nada, y `auth:coverage --unmapped` sale vacío;
  - Recepción recibe 403 con el mismo cuerpo para un uuid existente y para uno inexistente;
  - comparten el cupo de lectura: 429 con `Retry-After` tras 120 peticiones.
- **Sin BOLA en `compare`:**
  - el uuid del borrador de otra persona (en minúsculas y en mayúsculas), un uuid al azar y el de una regla dan cuatro 404 `version_not_found` idénticos;
  - el `uuid` del borrador no abre el historial ajeno a quien no tiene `audit.view`.
- **`engine/readings`:** se sembraron marcadores (ids de canario, `sk-…`, un `base_url` con IP privada y un precio), y se recorrió además todo valor de configuración de 12 o más caracteres cuya clave parezca un secreto. No sale ninguno.
- **Entrada de `conditions`:**
  - las entradas raras dan 422, nunca 500, y no guardan nada: cadena vacía, espacios, `0`, JSON dentro de un texto, 200 niveles de anidamiento, 3000 frases, la capa `__proto__` y `300.0`;
  - la asignación masiva se ignora: `source`, `enforcement`, `status`, `code`, `uuid`, `revision`, `origin`, `created_by_*`, `content_hash`, `archived_at` y `format`.
- **El 409 `rule_contradiction`** no lleva el nombre ni el correo de quien creó la otra regla.
- **Formato 2 e integridad:**
  - `format` solo lo pone el servidor;
  - rebajar el formato a mano da `integrityOk:false`, y `submit` responde 409;
  - el disparador `usv_forbid_immutable_update` bloquea el UPDATE con el rol de la aplicación.
- **Auditoría:** los textos hostiles vuelven como cadenas JSON (`application/json` y `nosniff`), y `ip_address` y `user_agent` siguen en NULL.
- **CRM:**
  - **0 sumideros en el módulo:** ni `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `bypassSecurityTrust*`, `[href]`, `[src]` ni `eval`. Repetido sobre `7d3ad2a` fuera de los specs: 0;
  - **todo texto del servidor se interpola.** Una prueba de concepto inyectó `<img src=x onerror=…>`, `<svg onload=…>` y `<script>` en la comparación, en el panel de reglas (409 y 422) y en el inspector: salió literal, 3 de 3, y la prueba falla si una interpolación pasa a `[innerHTML]`;
  - **el chunk del Studio** del build de producción no contiene clases del motor, reglas del código, rutas, entorno, canarios, credenciales ni fixtures.
- **E2E con la pila real** (tester; backend `391cffb`, CRM `7d3ad2a`, Chrome y cuentas reales; 124/126, y los 2 fallos son de foco: H-1 del E2E, en el CRM):
  - el texto hostil sale literal, sin crear elementos ni abrir diálogos, en la regla, su historial, el editor, el inspector, la etiqueta de una versión, la comparación y el nombre de un plan;
  - Recepción recibe 403 en `status`, `engine/readings` y `compare`;
  - quitarle `ultron.manage_rules` al Administrador da 403 al instante, también a su token ya emitido;
  - publicar responde 409 con la llave y 403 sin ella;
  - con la tabla del freno renombrada, `391cffb` da `governance.brake` como no disponible, mientras que `c8d9cf4` lo daba como «suelto» (I-1, corregido);
  - la huella de las tablas del motor y de las colas es igual al empezar y al terminar.

**Lecturas de producción.** Las hizo el lead en solo lectura el 19-10-2026, hacia las 02:50 UTC; no se han repetido:

| Ajuste | Valor |
|---|---|
| nginx `client_max_body_size` | 10M |
| PHP-FPM **8.3** (`php8.3-fpm` activo; `php8.1-fpm` instalado pero inactivo) | `pm = dynamic`, **`pm.max_children = 24`**, sin `request_terminate_timeout` explícito. *Corrección del 19-10-2026: la primera lectura (8.1, 5 procesos) era del pool inactivo* |
| PHP | `max_execution_time = 30`, `memory_limit = 128M`, `post_max_size = 8M`, `upload_max_filesize = 4M`, `pcre.jit = 1` (por defecto) |
| PostgreSQL | `default_transaction_isolation = read committed` |

Consecuencia: con 24 workers de FPM compartidos por todo el CRM (caja, ingreso y la API de la app) y 12 s de CPU por cuerpo de 150 KB, M-2 permitía a un usuario privilegiado ocupar todos los workers con unas decenas de peticiones dentro del cupo de escritura (30/min por credencial, 120/min por red), con cuerpos muy por debajo de los 8 MB. Por eso las correcciones de M-1 y M-2 fueron condición para desplegar G (la primera estimación, con 5 workers, era del pool inactivo de PHP 8.1; la conclusión no cambia).

**Puerta PostgreSQL obligatoria (H-2 del tester de backend).** Antes de cada despliegue del Studio, `tests/Feature/UltronStudio tests/Unit/UltronStudio` tiene que pasar en verde en un PostgreSQL 14 desechable (unos 25 s; 468/468 en `dfb30f5`). La suite por defecto, en SQLite, no detecta que falte el cerrojo de las reglas.

**Fuera de G (Hito 7):**
- `no-store` en la API administrativa;
- la clase de M-2 (listas con comodín sin tope previo) en otros controladores del CRM, que no se ha auditado;
- `TrimStrings` y `ConvertEmptyStringsToNull` recorren cuerpos de hasta `post_max_size` (8 MB) antes del tope de ruta;
- los caracteres invisibles (U+061C, U+E0000–E007F) en `PlainText` y en la API interna de la base de conocimiento;
- el procedimiento de redacción del libro (B-1 de `security`).

**Secretos:** `api-security-specialist` no encontró ninguno expuesto; no hay nada que rotar.

## Hito 4 (laboratorio de IA): revisiones de seguridad

**Estado:** implementado en la rama `feat/ultron-studio-h4` (backend `6dbf485`, CRM `3e630eb`), **no desplegado**. Fecha de las revisiones: 19-10-2026. La Enmienda 5 del contrato (`403a12b`: ejecuciones huérfanas y cobertura) está pendiente de implementar.

**Superficie nueva:**
- 12 rutas `lab/*` (33 en el Studio), con dos llaves nuevas (`ultron.lab_manage` y `ultron.lab_run`) y el veredicto con `ultron.approve`;
- 5 tablas `ultron_studio_lab_*` de filas inmutables o de solo inserción, un carril de cola (`studio-lab`) y un worker propio (`ironbody-lab`);
- **la primera llamada del Studio a un tercero de pago:** la Responses API de OpenAI, con una clave propia del laboratorio y solo desde el worker;
- texto libre del personal que no se puede borrar (escenarios, motivos de archivo y notas de veredicto) y salida de un LLM guardada sin vuelta atrás (respuesta del actor y opinión del juez).

**Lo que no cambia:** el motor (`git diff origin/main 6dbf485` sobre sus zonas da 0 bytes) y sigue sin leer el Studio; el contrato de las 21 rutas de F/G; las dependencias (`composer.json/lock` y `package.json/lock` sin cambios). `.env.example` solo gana las variables del laboratorio, con la clave vacía.

### Las cuatro cerraduras antes de gastar

Son independientes: basta una cerrada para que no se gaste nada. Al crear una ejecución, la bandera se comprueba la primera, antes que la configuración:

| # | Cerradura | Valor por defecto | Con ella cerrada |
|---|---|---|---|
| 1 | Bandera `ULTRON_STUDIO_LAB_ENABLED` | `false` | Todas las rutas `lab/*` responden 409 `lab_disabled`, salvo `GET lab/status` (200) |
| 2 | Driver `ULTRON_STUDIO_LAB_DRIVER` | `fake` | Sin red y coste 0; sin propuesta ni veredicto. Un valor desconocido no cae en ningún modo: `invalid_driver` |
| 3 | Presupuesto diario `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD` | `0` | En `openai`, `lab_not_configured` con `zero_budget`: ninguna ejecución de pago |
| 4 | Clave `ULTRON_STUDIO_LAB_OPENAI_API_KEY` | vacía | `missing_key`; si es la misma que `OPENAI_API_KEY`, `shared_key`. Sin configuración no sale ninguna petición HTTP (probado) |

Con las cuatro abiertas, cada ejecución sigue limitada por: la estimación que la persona acepta de forma explícita (`acceptedMaxCostMicros`; si el servidor estima más, 409 `estimate_changed`); la reserva atómica contra el tope diario (día de Bogotá); el tope por ejecución, que desde la Enmienda 4 se aplica también **al coste real** durante la ejecución; `max_output_tokens` en cada llamada; `tries = 1` y ningún `retry()`; un solo caso en curso en todo el sistema; el plazo de 30 min; la parada por 2 errores seguidos; y el limitador de 10 lanzamientos por hora y persona. El límite exterior es el presupuesto del proyecto en OpenAI, que crea el propietario.

### Revisores y veredictos

| Revisión | Sobre | Veredicto |
|---|---|---|
| `api-security-specialist` | Backend `4fbce0a`, con sondas en SQLite y en PostgreSQL 14 | Sin críticos, altos ni medios: 3 bajos (B-1 a B-3) y 5 informativos. Nada bloqueaba las fases 1 y 2; B-1, a cerrar antes de la fase 3 |
| `security` (transversal) | Backend `4fbce0a` y CRM `4c022f8` y `32c03d3` | Sin críticos, altos ni medios: 2 bajos (B-1 y B-2) y 6 informativos. Nada bloqueaba las fases 1 y 2; B-2 y la retención (I-3), antes de la fase 3 |
| `tester` de backend, parte 1 | Backend `4fbce0a`, con la pila real y PostgreSQL 14 | Carreras de creación sin un solo resultado incorrecto; dos hipótesis por lectura de código (HIP-1 y HIP-2) |
| `tester` de backend, parte 2 | Backend `6dbf485`, con la pila real, el worker real y PostgreSQL 14 | Las correcciones aguantan: 5 carreras forzadas 10/10 en READ COMMITTED y en REPEATABLE READ, 21 casos límite correctos y las 14 mutaciones del encargo cazadas. Un hallazgo de producto (H-P2-1) y dos huecos de cobertura (H-P2-2 y H-P2-3), abiertos; su corrección la decide la Enmienda 5 |
| `tester` del E2E | Backend `4fbce0a` y CRM `32c03d3`/`564cf14`, con la pila real; final sobre `6dbf485` y `3e630eb` | 121/122 por H-1, cosmético, del CRM; **122/122** sobre `6dbf485` y `3e630eb` |
| `reviewer` | Backend `6dbf485` y CRM `3e630eb` | **APROBADO CON OBSERVACIONES** para las fases 1 y 2, sin bloqueantes; I-1 (C-11 retirado), I-2 (`lab-invisibles` con la clase completa de invisibles), I-3 (`budgetCap` expuesto), I-4 (cliente por `mode` de la ejecución) cerradas en el segundo lote antes de la fase 3 (Enmienda 6) |

### Hallazgos y estado

Los códigos se repiten entre revisiones, así que cada uno lleva su origen.

| Origen · # | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| api-security · B-1 | Baja | El tope por ejecución solo se aplicaba a la **estimación**, que cuenta 3 bytes por token. Con un proveedor que factura más tokens de entrada que los estimados, la ejecución seguía hasta el tope diario: en la sonda P08 (3× la entrada estimada) terminó `completed` con 93 530 µUSD frente a un tope de 50 000 (1,87×). La premisa de A3 («la desviación está acotada por `max_output_tokens`») no era exacta: la desviación viene de la entrada | **Corregido en `6dbf485`** (Enmienda 4.1): antes de reclamar cada caso, el paso para con `budget_exhausted` si el coste real de la ejecución supera `min(tope por ejecución, máximo aceptado)`. P08 queda `stopped` tras 7 de 12 casos con 54 613 µUSD; el exceso residual es como mucho el coste de un caso |
| api-security · B-2 | Baja | Caracteres invisibles en la respuesta del actor esquivaban guardas del motor: con un ZWSP dentro de «descuento» o de «confirmado», el filtro no marcaba nada, y el texto llegaba sin sanear a quien emite el veredicto | **Corregido en `6dbf485`** (Enmienda 4.2): hallazgo bloqueante `lab-invisibles` (ver security · B-2) |
| api-security · B-3 | Baja | `GET lab/scenarios?status=archived` sin paginar ni tope, sobre filas que no se pueden borrar: 300 archivados de unos 8 KB daban 3,2 MB por respuesta | **Corregido en `6dbf485`** (Enmienda 4.3): como mucho los 200 más recientes y `meta {truncated, total}`; el CRM avisa del recorte desde `564cf14` |
| api-security · I-1 | Informativo | El filtro de datos personales no reconoce cifras separadas por `,`, `/`, `_` u otros signos, correos ofuscados, dígitos en superíndice o en círculo, ni selectores de variación. Cumple la letra del contrato; el riesgo es el pegado accidental (Ley 1581), no un atacante | **Aceptado y documentado** (Enmienda 4.9). Ampliar los separadores daría falsos positivos en listas de precios |
| api-security · I-2 | Informativo | Fuera de producción, `ULTRON_STUDIO_LAB_OPENAI_BASE_URL` admite cualquier host http(s): un entorno de pruebas con `APP_ENV` distinto de `production` y una clave real enviaría el bearer a esa URL | **Aceptado** (Enmienda 4.9). En producción la URL es fija (verificado en código y en pruebas). El runbook comprueba `APP_ENV=production` y la variable vacía en el servidor |
| api-security · I-3 | Informativo | El techo de 10 lanzamientos por hora era por sesión: otra sesión de la misma persona empezaba de cero | **Corregido en `6dbf485`** (Enmienda 4.4): por persona; el de red (30/h) no cambia |
| api-security · I-4 | Informativo | `shared_key` solo compara con `OPENAI_API_KEY` del backend: una clave reutilizada de n8n no se detecta | **Aceptado.** El control real es el proyecto dedicado de OpenAI (pendiente del propietario) |
| api-security · I-5 | Informativo | Ventana de milisegundos entre la comprobación de caducidad y el reclamo de una ejecución en cola de casi 30 min | **Aceptado:** el reclamo es condicionado y el impacto es como mucho una reserva |
| security · B-1 | Baja (cumplimiento, Ley 1581) | El motivo de archivo de un escenario (5 a 500 caracteres) no llevaba aviso de datos personales y queda en una tabla sin DELETE y en el libro inmutable. G tampoco avisaba al archivar reglas ni al rechazar versiones | **Corregido en el CRM:** aviso fijo en archivar escenarios y reglas (`564cf14`) y en rechazar una versión (`3e630eb`), con una ranura opcional en `ib-reason-dialog` que no cambia sus otros usos. **Pendiente del propietario (Hito 7):** el procedimiento de redacción del libro, que ahora debe cubrir también las cinco tablas del laboratorio |
| security · B-2 | Baja (integridad de lo que ve el revisor) | La respuesta del actor se guardaba y se pintaba con controles bidi e invisibles: un precio escrito al revés entre U+202E y U+202C no lo marcaba el filtro del motor y en pantalla se leía como un precio. Al juez sí se le limpiaban | **Corregido en `6dbf485`** (Enmienda 4.2): `lab-invisibles`, la primera comprobación, bloqueante, sin cita, con la lista de la enmienda más LRM/RLM; excluye el ZWJ de los emojis compuestos. La respuesta se guarda y se pinta literal e interpolada; no se adoptó mostrar los invisibles escapados. Las banderas subnacionales (Escocia, Gales…) también lo disparan: aceptado |
| security · I-1 | Informativo | Alcance del filtro de datos personales (mismo fondo que api-security · I-1) | **Aceptado y documentado.** No se añadieron los selectores de variación U+E0100–E01EF |
| security · I-2 | Informativo | `shared_key` solo compara con `OPENAI_API_KEY`; los LLM de ULTRON corren en n8n con su propia credencial | **Pendiente del propietario:** proyecto de OpenAI dedicado, con presupuesto propio y una clave nueva, confirmado por escrito antes de la fase 3 |
| security · I-3 | Informativo | **Retención en OpenAI.** Según la documentación oficial consultada el 19-10-2026, los registros de vigilancia de abusos pueden contener prompts y respuestas y se conservan hasta 30 días por defecto. `store: false` evita el estado de aplicación de 30 días, pero no esos registros. No conservarlos exige una aprobación previa de OpenAI | **Pendiente del propietario:** confirmar la retención que aplica a su cuenta antes de la fase 3. La mitigación real son los escenarios sintéticos y el filtro |
| security · I-4 | Informativo | IRON GUARD reencola los `StudioLabStepJob` fallidos con `queue:retry all` | **Sin acción:** no paga dos veces (`failed()` ya liquidó el caso y cada paso reclama con un UPDATE condicionado). Anotado en el runbook |
| security · I-5 | Informativo (despliegue) | El borrador del runbook escribía las variables nuevas **después** de `ironbody-migrate`, que ya regenera la caché: regirían los valores por defecto (los seguros) hasta otro `config:cache` | **Corregido en el runbook** (Enmienda 4.8): las variables antes del envoltorio, y una comprobación de la configuración efectiva sin imprimir secretos |
| security · I-6 | Informativo | El catálogo de permisos del CRM era provisional, escrito a mano | **Cerrado:** el `backend-permission-catalog.ts` de `3e630eb` es idéntico byte a byte al que exporta `permissions:export` (sha256 `1075374b…`, comparado el 19-10-2026) |
| tester · HIP-1 | Sin gravedad asignada (afecta al gasto) | Un paso podía reclamar y pagar un caso **después** de una cancelación confirmada, si esta llegaba entre la carga de la ejecución y el cerrojo de reclamos | **Confirmado y corregido en `6dbf485`** (Enmienda 4.5): la mutación que devuelve el comportamiento anterior reclama el caso 2 con la cancelación confirmada; con la corrección, `claim()` relee la cancelación bajo el cerrojo. Prueba de dos sesiones en PG: 10/10 |
| tester · HIP-2 | Baja | La deduplicación de `run.verdict_denied` no fijaba READ COMMITTED: en REPEATABLE READ podían quedar dos filas | **Corregido en `6dbf485`** (Enmienda 4.6), con su prueba en PG. **Fuera de alcance:** `StudioAudit::recordDenied` (F) tiene el mismo patrón |
| tester · H-P2-1 | Media-baja (producto) | **Ejecución huérfana:** si un paso falla antes de reclamar un caso (por ejemplo, se corta la conexión del worker con la base mientras espera el cerrojo), `failed()` no encola nada y la ejecución queda `running` sin ningún paso. Cancelar responde 200 pero no la cierra, el plazo y la caducidad no se aplican y su reserva cuenta hasta que cambia el día de Bogotá. No gasta dinero ni bloquea otras ejecuciones | **Abierto en `6dbf485`. Corrección decidida en la Enmienda 5 (`403a12b`), pendiente de implementar:** `cancel` cierra en el acto una ejecución en curso sin caso en curso, el plazo vencido se presenta como `stopped: deadline` sin escribir, y `failed()` sin caso reclamado reencola un paso (o, al segundo fallo, para con `provider_errors`). Mientras tanto, salida operativa sin código en el runbook del Hito 4 |
| tester · H-P2-2 | — (cobertura) | «Nada aprueba solo» se vigila por el nombre de la clase: un comando que insertara veredictos con `DB::table(…)` pasaría todas las pruebas. Hoy ningún código lo hace | **Abierto en `6dbf485`.** Enmienda 5: la prueba estática detectará también las escrituras crudas en `ultron_studio_lab_verdicts`; pendiente de implementar |
| tester · H-P2-3 | — (cobertura) | La comprobación de «ocupado» dentro del reclamo solo la caza la carrera real de pasos duplicados, no PHPUnit | **Abierto en `6dbf485`.** Enmienda 5: prueba de dos sesiones en PG para los pasos duplicados; pendiente de implementar |
| E2E · H-1 | Cosmético (CRM) | La frase del filtro del motor salía duplicada: el backend la pone en el `title` y el CRM la volvía a envolver | **Corregido en el CRM `3e630eb`**; el E2E final la ve una sola vez |

### Verificado sin hallazgo

- **La clave del laboratorio.** Solo vive en el entorno y solo la lee el cliente en el momento de llamar (`withToken`). Nunca se guarda en una propiedad, un resultado, un DTO, el libro ni el log (`StudioLabConfig::toArray()` no lleva ni la clave ni su huella). `CheckSecretExposure` la incluye. Sin Telescope, Sentry ni oyentes globales de `Http`; el cliente lleva `withoutRedirecting()` y no desactiva TLS. **Centinela:** `UltronStudioLabNoEngineEffectsTest` y las sondas de api-security siembran una clave reconocible, también en cuerpos de error del proveedor que la repiten (401, 500, 502 en HTML, JSON inválido, `refusal`, 429), y no aparece en respuestas, tablas, `jobs`, `failed_jobs` ni log. Ningún literal con forma de clave en los commits de H4: lo revisó security hasta `4fbce0a` y `32c03d3`, y se repitió el 19-10-2026 sobre `6dbf485` y `3e630eb`; solo aparecen las centinelas de prueba `sk-lab-CENTINELA-…` y `sk-motor-NO-USAR-…`.
- **Datos hacia el proveedor.** El filtro se aplica a toda la entrada antes de codificarla; un objetivo con datos personales da 409 `target_contains_personal_data` y nunca se construye la entrada. No viajan nombres, ids, la etiqueta ni la nota de la versión, ni datos del motor. `store: false` siempre y sin `user`, `safety_identifier` ni `metadata` (cliente, prueba y simulador del E2E, que lo comprobó en sus 15 peticiones).
- **Inyección de instrucciones.** Escenarios y reglas viajan como JSON dato, y las instrucciones lo dicen. El juez no recibe los hallazgos; el servidor exige exactamente sus ids y códigos y acota y limpia sus textos. El resultado lo decide `StudioLabOutcome` (un bloqueante gana siempre). **Nada aprueba solo:** la única inserción de veredictos tiene un solo llamador, el controlador, y una prueba estática impide que el runner, el job o los comandos nombren el veredicto.
- **Autorización y BOLA.** Sin `ultron.view`, 403 idéntico para un uuid existente y para uno inexistente, también con la bandera apagada. Una ejecución sobre el borrador de otra persona no aparece en `GET lab/runs` ni en `lab/events` (tampoco por `subjectUuid` en mayúsculas) y su detalle es un 404 idéntico al de un uuid inexistente; un `target` de borrador con la revisión de otra persona corre sobre el borrador **propio**. `clientRequestId` ajeno → 409 sin datos. Matriz de 33 rutas por perfiles, token compartido y 401 sin sesión en `UltronStudioPermissionsTest`.
- **Asignación masiva.** Se ignoran `code`, `source`, `status`, `revision`, `uuid`, `contentHash` y autoría en escenarios, y `mode`, `model`, `prices`, `budget_day`, `status` y `target_snapshot` en ejecuciones. `acceptedMaxCostMicros` solo puede endurecer: los topes son del servidor. Una ejecución `fake` nunca sale a la red.
- **CRM.** 0 `innerHTML`, `bypassSecurityTrust*`, `DomSanitizer`, `[href]`, `[src]` ni `eval` en el módulo; todo texto del servidor, también la salida del LLM, se interpola (spec hostil y E2E con `<script>`, `<img onerror>` y `<svg onload>`). El chunk del laboratorio no contiene nombres del motor, variables de entorno, `api_key`, `sk-` ni `Bearer`.
- **Operación.** `ironbody-lab.conf` con `user=www-data`, `numprocs=1`, `--tries=1`, `--timeout=150` por debajo del `retry_after` de 300 y `stopwaitsecs=180`. Carril propio: no comparte cola con `commercial`, que lleva el puente vivo de ULTRON a n8n. Migraciones aditivas, con el `down()` destructivo documentado y prohibido en producción.

### Lecturas de producción que condicionan el laboratorio

Hechas por el lead en solo lectura el 19-10-2026; no se han repetido.

| Lectura | Valor | Consecuencia para el laboratorio |
|---|---|---|
| PHP-FPM | **PHP 8.3** (`php8.3-fpm` activo; el pool de 8.1 está instalado pero inactivo), `pm.max_children = 24`, `max_execution_time = 30` | Una llamada a un LLM nunca va dentro de la petición HTTP: la hace el worker `ironbody-lab`. **Corrección del 19-10-2026 (`fc5b571`):** la primera lectura, «FPM 8.1 con 5 procesos», era del pool inactivo; la conclusión de M-2 de G no cambia |
| PCRE | 10.39 | Compila todas las expresiones del filtro de datos personales y de los emojis del laboratorio, como root y como www-data (sonda del lead). El patrón de `lab-invisibles` llegó después (`6dbf485`): usa solo puntos de código explícitos y se sondea tras desplegar |
| PostgreSQL | 14.24; `iron` con `CREATE` en `public`; privilegios por defecto de `iron` hacia `iron_app`: tablas `arwd`, secuencias `rwU`, funciones `X` | La migración puede crear tablas y función con `ironbody-migrate`, y la aplicación las usa sin ningún GRANT nuevo |
| Supervisor | Los programas viven en `/etc/supervisor/conf.d/` (hoy solo `ironbody-workers.conf`) | `ironbody-lab.conf` va al lado, como fichero aparte |

### Puerta PostgreSQL obligatoria

Sigue vigente y cubre ahora el laboratorio: antes del despliegue, `tests/Feature/UltronStudio tests/Unit/UltronStudio` tiene que pasar en verde en un PostgreSQL 14 desechable (934/934 en `6dbf485`). Solo PG ejecuta las pruebas de los 13 CHECK y del rol, las de dos sesiones reales (la reserva atómica con la sesión en REPEATABLE READ y la cancelación frente al reclamo) y las de READ COMMITTED. Las pruebas que cazan la falta del cerrojo de presupuesto solo corren en PG.

### Pendiente del propietario antes de la fase 3

- Crear un **proyecto de OpenAI dedicado**, con presupuesto propio y una **clave nueva**, distinta de la del motor y de la de n8n.
- Confirmar qué conserva OpenAI con `store: false` en su cuenta (registros de abuso hasta 30 días por defecto).
- Elegir el modelo y los precios con la página oficial (los del plan son de terceros y solo orientativos).
- Escribir la clave **él mismo** en el entorno del servidor, nunca por chat.

### Fuera del Hito 4

- `StudioAudit::recordDenied` (F) sin READ COMMITTED.
- El procedimiento de redacción del libro (Hito 7), que debe cubrir también `scenario_snapshot`, `reply`, `judge`, `checks`, `calls`, `note`, `summary` y `diff` de las tablas del laboratorio.
- Invisibles en el borrador de respuesta del motor: api-security lo apunta como **hipótesis** sobre producción (depende de lo que haga n8n antes del commit); security sondeó la guarda del motor y no encontró un hueco nuevo. Revisión del motor en el Hito 7.

**Secretos:** ninguna revisión encontró secretos expuestos; no hay nada que rotar.

## Requisitos para las fases siguientes (recogidos de las revisiones y la auditoría)

- Permisos `ultron.*` (ver, editar borrador, reglas, campañas, simulaciones, canarios, pruebas telefónicas, aprobar, publicar, revertir, ajustes) **validados en Laravel en cada endpoint**; separar quien edita, aprueba, publica y administra integraciones.
- **El servidor revalida todo lo publicable** sin fiarse del borrador: la confirmación de los parámetros importantes hoy solo existe en el navegador, y un borrador cargado de `localStorage` podría traer `paymentLinksEnabled: true` sin haber pasado por ella.
- El Studio **nunca** reutiliza las rutas internas de n8n ni muestra ejecuciones de n8n (guardan el bearer).
- Nunca devolver al front tokens, secretos de webhook, claves de OpenAI/ElevenLabs ni teléfonos en claro; ids de Meta enmascarados como hace el panel actual.
- Evaluación con OpenAI: clave y proyecto propios, salidas estructuradas estrictas, registro de tokens y coste, entradas redactadas. *(Implementado en la rama del Hito 4, salvo «entradas redactadas»: el laboratorio no lee textos de prospectos y rechaza los datos personales en vez de redactarlos. El proyecto propio lo crea el propietario; ver «Hito 4».)*
- Twilio Voice: subcuenta, `TimeLimit`, cupo diario propio y rangos de alto riesgo cerrados; webhooks con firma validada e idempotencia; ningún mecanismo actual frenaría un gasto de voz.
- ElevenLabs: adaptador tras bandera, webhooks firmados, grabación desactivada hasta resolver consentimiento y retención (Ley 1581).
- Hallazgos previos del motor que no son del Studio pero importan (auditoría §13): `/ai/decide` sin interruptor ni canario, el precio que llega al Strategist, la respuesta del commit con precio y URL de pago hacia n8n.
