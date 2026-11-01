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

**Estado:** implementado en la rama `feat/ultron-studio-h4` (backend `6dbf485`, CRM `3e630eb`), **no desplegado**. Fecha de las revisiones: 19-10-2026. La Enmienda 5 del contrato (`403a12b`: ejecuciones huérfanas y cobertura) está pendiente de implementar. *(Así se escribió. **Actualización del 31-10-2026:** la Enmienda 5 se implementó en `7113f9f` y la 6 en `bc662b0`, y el Hito 4 está desplegado en las fases 1 y 2 desde el 25-10-2026, 01:15–01:25 UTC (backend `fef7c87`, CRM `5c1dbd7`), con el laboratorio en `fake`, presupuesto 0 y sin clave; ver `ULTRON_STUDIO_FINAL_REPORT.md`.)*

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

## Hito 5 (gobierno y versionado): revisiones de seguridad

**Estado:** implementado en la rama `feat/ultron-studio-h5` (backend `96397ea`, CRM `a29bcc8`; fusionados con `origin/main` el 31-10-2026 en `cdb9dc8` y `9fe1817`, más `cf59cef` en el backend), **no desplegado**. Revisiones del 25-10-2026: `plans/informes-h5/informe-security.md` e `informe-api-security.md`, más los hallazgos de seguridad del tester de backend, del E2E y de DevOps. Las protecciones de esta sección **rigen en producción cuando se despliegue**.

**Superficie nueva:**
- 5 rutas nuevas (38 en el Studio) y 2 que dejan de responder 409 siempre (`publish` y `rollback`). Ninguna llave nueva: publicar y promover van con `ultron.publish`; revertir y Legacy, con `ultron.rollback`. Lo demás, con `ultron.view`;
- la tabla `ultron_studio_publications`, de solo inserción, con texto libre del personal (la nota de publicar o promover y el motivo de revertir o de Legacy van a `reason`) y el nombre y el rol de quien actúa;
- **el motor lee por primera vez una tabla del Studio:** una sentencia por commit, desde una sola clase (`UltronPublishedConfig`) y solo con `MARKETING_ULTRON_STUDIO_CONFIG` distinta de `off`;
- una firma HMAC con una subclave derivada de la `APP_KEY`;
- el acta del canario (`ultron:canary-report`) ejecutada dentro del proceso web, con los textos de una conversación real en memoria;
- 7 variables de entorno nuevas y dos scripts que las escriben en producción.

**Lo que no cambia:** lo que ULTRON dice o hace (opción A); el motor no escribe en `ultron_studio_*` ni importa clases del Studio, y el Studio no escribe en el motor; las dependencias (`composer.*` y `package*.json` sin cambios en las dos ramas, `security` §3).

### Las llaves y el registro

Publicar exige que las tres llaves estén abiertas, y lo que rige lo dice la cabeza firmada de la cadena (contrato A5):

| Llave | Dónde | Qué abre | Quién la gira |
|---|---|---|---|
| Código | `StudioPublishing::ENGINE_READS_VERSIONS = true` (desde `2bc053b`) | Que exista código que publique y código que lea | El commit; sola no abre nada |
| Entorno del motor | `MARKETING_ULTRON_STUDIO_CONFIG = off \| canary \| all` (`off` por defecto; cualquier otro valor es `off`) | Que el motor consulte la cadena y hasta dónde | El propietario (fases 2 y 3) |
| Entorno del Studio | `ULTRON_STUDIO_PUBLISHING_ENABLED` (apagada por defecto; solo `true`, `1`, `on` o `yes` la encienden desde `d24c512`) | Las rutas de publicar y promover | El propietario |
| **Registro** | La cabeza firmada de `ultron_studio_publications` | Qué declaración rige y para quién (`canary` / `rest`) | El Studio, con cuatro ojos |

Revertir y volver a Legacy no dependen de ninguna de las dos llaves de entorno ni exigen cuatro ojos o ventana: deshacer no puede depender de la llave que permite cambiar (decisión 8 del contrato).

### Revisores y veredictos

| Revisión | Sobre | Veredicto |
|---|---|---|
| `security` (transversal) | Backend `61048ff` y CRM `24a691d` | Sin bloqueantes ni altos: 3 medios (M-1; M-2, operativo; M-3, condicional y después confirmado), 3 bajos (B-1 a B-3) y 10 notas (N-1 a N-10) |
| `api-security-specialist` | Backend `d98bbf9` (el código es el de `61048ff`) | Sin bloqueantes ni altos: 1 medio (AS-1, = M-1), 2 bajos (AS-2 y AS-3) y 5 notas (AS-4 a AS-8) |
| `tester` de backend | `61048ff`/`d98bbf9`, con dos sesiones reales de PostgreSQL 14 por carrera | H-1, medio (de contrato, sin daño a datos), y B-1 a B-3, bajos (cobertura) |
| `tester` del E2E | Backend `2bc053b` y CRM `e7ba41b`, con la pila real | 404/408: B-1 (= M-1) tres veces y C-1, menor, del CRM |
| DevOps | Los scripts y el runbook | D-1 bloqueante (= M-2) y D-2 a D-12; todos corregidos en los scripts de `5772bdf` |
| `reviewer` | Backend `cf59cef` y CRM `9fe1817` (31-10-2026) | **APROBADO CON OBSERVACIONES**, sin bloqueantes en el código: I-2 e I-3 (los scripts) e I-4 (la vuelta atrás del código) antes de la fase 1, cerradas; I-1 (CRM) corregida en `ab78a6a` el 31-10-2026 y en corrección por `frontend`. [PENDIENTE: segunda pasada sobre la documentación.] |

### Hallazgos y estado

Los códigos se repiten entre revisiones, así que cada uno lleva su origen.

| Origen · # | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| security · M-1 = api-security · AS-1 = E2E · B-1 | Media | `ULTRON_STUDIO_PUBLISHING_ENABLED=off` (o `no`, `disabled`, cualquier texto no booleano) **abría** publicar: `(bool) env()` da `true` con cualquier cadena no vacía. Quien quisiera congelar las publicaciones dejando el sello las habría abierto. Confirmado en la pila real: `status.publishing.available: true` y `POST publish` pasaba la llave | **Corregido en `d24c512`:** `filter_var(…, FILTER_VALIDATE_BOOLEAN)` en `config/ultron_studio.php` y `flagEnabled()` exige `=== true`, con prueba negativa en `UltronStudioPublicationConfigTest`. El mismo arreglo en `ULTRON_STUDIO_LAB_ENABLED` (Hito 4). La encienden `true`, `1`, `on` o `yes` (sin distinguir mayúsculas); cualquier otro valor, incluido `off`, `no` o vacío, la deja apagada (Enmienda 2 §E2.2). Confirmado en la pila real: en la pasada de cierre del lead (31-10-2026, `cf59cef` + `9fe1817`, 408/408) esas comprobaciones salen en verde |
| security · M-2 = DevOps · D-1 | Media (operativa) | `deploy-h5-backend-fase1.sh` moría en silencio en la línea 11 con el checkout limpio (`grep -v` sin coincidencias y `pipefail`): la fase 1 no se podía ejecutar. `deploy-h4-backend-fase1.sh` tiene la misma línea **después** del `merge` | **Corregido** (`git status --porcelain --untracked-files=no \| wc -l`). El script del Hito 4 queda marcado obsoleto en su cabecera |
| security · M-3 | Media (condicional) | root ejecuta `git` sobre un checkout de `www-data`: si `.git` es escribible por `www-data`, un compromiso de la aplicación pasaría a root en el siguiente despliegue (hooks o configuración del repo). El 25-10 se leyó en el servidor que `.git`, `.git/config` y `hooks/` eran de `www-data`, y root tiene `safe.directory` para esa ruta | Primero, los scripts pasaron a `sudo -u www-data -H git` (`d24c512`). **Estado real y mitigación vigente (I-3; decisión del lead, comprobada en el servidor en solo lectura el 31-10-2026):** desde el 25-10, el árbol de `/var/www/api` (2.493 entradas, entre ellas todo lo que cambia el Hito 5) y `.git` (5.089 de sus 5.131 entradas) son de root; `www-data` los lee, pero no puede escribir ni en `objects/` ni en los directorios del código, así que un `merge` como `www-data` habría fallado a medias. Código y `.git` de root, legibles por `www-data`, es la postura más segura (PHP-FPM no puede reescribir el código), y la M-3 original ya no se da: no hay hooks ni `core.hooksPath`, y root tiene `safe.directory=/var/www/api`. Los scripts ejecutan `git` como root con `umask 022` y, tras el `merge`, comprueban fichero a fichero (`git diff --name-only <PREVIO> HEAD`, con `sudo -u www-data test -r`) que `www-data` lee cada uno, y abortan si no (`db1d8f6`). Se copian desde el Mac con sus huellas. **Pendiente del propietario (Hito 7):** `/usr/local/bin/ironbody-migrate` ejecuta `php artisan migrate` como root (Enmienda 2 §E2.5) |
| security · B-1 | Baja | Añadir una clave a un fichero de entorno sin salto final pegaba la línea a la anterior: el fichero entero dejaba de parsear (500 en toda la API tras `ironbody-migrate`) y el error imprimía el valor de la última clave | **Corregido en los dos scripts:** salto final asegurado, copia previa del fichero (root, 600) y comprobación de que parsea **antes** de tocar las cachés, imprimiendo solo la clase del error |
| security · B-2 | Baja | El historial (`GET publications` y el detalle de la versión) enseña cada entrada sin verificar su huella ni su firma: una fila insertada con DML aparecería como legítima | **Diferido al Hito 7** (Enmienda 2 §E2.5): exige DML directo en producción y solo falsea lo que se enseña; el motor y el Studio verifican la cabeza, y la restauración verifica su entrada |
| security · B-3 | Baja (documentación) | El riesgo R6 del plan decía que, tras rotar la `APP_KEY`, bastaba con «volver a publicar». No se puede: la única escritura posible es Legacy | **Corregido en la documentación:** Enmienda 2 §E2.4 y el procedimiento de `ULTRON_STUDIO_ROLLBACK.md` («Hito 5») |
| api-security · AS-2 = security · N-6 | Baja | `POST promote` calculaba la evidencia entera, acta incluida, con el cupo de escritura (30/min por token): una persona con dos sesiones llegó a 68 actas por minuto | **Corregido en `602c4b9`:** promover comparte con `GET canary-evidence` el limitador de 10/min por persona y 30/min por red, con prueba negativa |
| api-security · AS-3 = security · N-3 | Baja | Una fila cruda con `seq = 2147483647` (DML del rol de la aplicación, sin `APP_KEY`) deja la cadena sin salida: Legacy responde 500 (22003), revertir 409, y la fila no se puede borrar con `iron_app`. El motor sella `fallback/signature_invalid` | **Diferido al Hito 7** (Enmienda 2 §E2.5): disparador que obligue a `seq` = siguiente y a encadenar con la cabeza, y `CHECK (seq >= 1)`, en una migración aditiva; traducir el 22003 a un 409. **Mientras tanto,** salida inmediata con `h5-engine-scope.sh off <alcance actual>` y reparación solo con el rol dueño y la autorización del propietario (runbook) |
| api-security · AS-4 = tester · H-1 | Media para el tester (de contrato); nota para api-security | El reintento de la misma petición que cruzaba la confirmación de la primera recibía 409 en vez de 200 `replayed`, en las cuatro escrituras. Sin doble efecto ni daño a datos | **Corregido por el lead en `96397ea`:** ante cualquier rechazo se vuelve a mirar el reintento fuera de la transacción; las 38 carreras del tester pasan contra el arreglo |
| api-security · AS-5 | Nota (heredada) | `PlainText` deja pasar invisibles que no están en su lista (U+00AD, U+061C, U+180E, U+2028, U+2060 y las etiquetas U+E0000–E007F, entre otros). Desde el Hito 5 acaban en `ultron_studio_publications.reason`, que no se puede editar ni borrar | **Pendiente del propietario (Hito 7)**, mejor antes de la fase 2 |
| api-security · AS-6 | Nota (diseño) | La subclave de firma comparte la clave HMAC con `Workforce\Secrets::hash()`; la separación depende de que todos sus mensajes lleven `\|`. Hoy no es explotable | Sin cambio en H5 (el fixture congela la derivación). Para un contrato `/2`: HKDF o una subclave propia para `Secrets` (`security` y architect) |
| api-security · AS-7 | Nota (para `/2`) | Revertir la publicada restaura sin volver a mirar `must_match`, el laboratorio ni la revalidación; el freno y la evidencia se miran fuera de la transacción | Correcto en la opción A. Antes de un contrato con mandos aplicados, lo revisa el architect |
| api-security · AS-8 | Nota (documentación) | La paginación no era la que decían el informe de la API y A7 | Fijada en la Enmienda 2: `per_page` (no `perPage`); 0, negativos o no enteros → 422; más de 50 → 50; 20 por defecto |
| security · N-1, N-2 y N-4 | Notas | Una clave muy corta se acepta; la firma no ata el entorno (con la misma `APP_KEY`, una entrada firmada en un entorno vale en otro); un UNIQUE no reconocido acaba en `laravel.log` con sus valores | Sin cambio en H5. N-2: conviene una `APP_KEY` distinta por entorno, comprobada sin imprimirla. Comprobado por el lead el 31-10-2026 sin imprimir valores: `.env.example` del repo no lleva `APP_KEY`; cada instantánea del E2E genera la suya; el worktree de desarrollo no tiene `.env`; la huella sha256 de la `APP_KEY` de producción no coincide con ninguna del repo. Una clave por entorno, como pide N-2. |
| security · N-5, N-7 y N-8 | Notas | Etiquetas con marcado en `publication-check`; la caché de configuración nace con la umask de `www-data` antes del `chmod 600`; los scripts imprimían la configuración sin compararla | **Corregidos:** salida en crudo con `--json` y etiquetas escapadas en modo texto; `config:cache` con `umask 077` en `h5-engine-scope.sh` (en `ironbody-migrate`, pendiente del propietario); los scripts comparan y salen con 1 |
| security · N-9 y N-10 | Notas | `canary` abre también la llave de publicar; `lock_timeout` llega por `PGOPTIONS` solo si el envoltorio conserva el entorno | N-9, documentado (es el contrato). N-10, no verificado: lo recuenta el paso 2 del runbook sin imprimir el envoltorio |
| tester · B-1, B-2 y B-3 | Baja (cobertura) | Nada fijaba que el Studio comparara la huella de la cabeza, que `previewHash` usara el catálogo de la versión ni el tope de lectura de 16 385 caracteres en el Studio | **Cerrados en `96397ea`** con tres pruebas nuevas |
| E2E · C-1 | Menor (CRM) | Con la puerta `waived`, el diálogo decía dos veces que estaba dispensada | **Corregido en el CRM `33821e4`** |
| reviewer · I-1 | Importante (antes de la fase 2) | El CRM reutiliza la `clientRequestId` de una escritura de la cadena también después de un éxito. Para «Volver a Legacy», cuyo cuerpo es solo el motivo, un segundo intento en la misma pestaña con el mismo motivo recibe 200 `replayed` con la entrada vieja: si entretanto otra persona publicó, **la salida del Studio falla en silencio** y la barra dice que ya estaba en Legacy | **Abierto** (frontend): olvidar la clave tras un 2xx legible o un 4xx definitivo. No afecta a la fase 1 (publicar cerrado; con la cadena vacía, Legacy responde `already_legacy`). [PENDIENTE: corrección de I-1, con su spec.] |
| reviewer · I-2 | Importante (antes de la fase 1) | Un tercer argumento mal escrito del script de la fase 1 (por ejemplo, `--solo-comprobr`) ejecutaba el despliegue entero en vez del ensayo | **Corregido en `db1d8f6`:** cualquier otro valor sale con 2 sin tocar nada |

### Verificado sin hallazgo

(`security`, leyendo el código y con scripts mínimos sin arrancar la aplicación; `api-security-specialist`, con 12 pruebas de concepto en SQLite y una en PostgreSQL 14; el tester del E2E, con la pila real.)
- **La firma.** La derivación real coincide byte a byte con una derivación independiente con `openssl`. Compara en tiempo constante (`hash_equals`) en el motor y en el Studio. Sin `APP_KEY` (vacía, nula, solo `base64:` o base64 inválido) lanza en vez de firmar con una clave vacía. Va sobre los bytes guardados (columna `text`, solo ASCII, el mismo tope de 16 385 caracteres al leer en los dos lados) y ata `entry` y `seq` a la fila. Su dominio está separado del `decide_token`. El E2E recalculó en Node la firma de cada entrada con la `APP_KEY` de la pila: 7/7.
- **Autorización.** Las 38 rutas están mapeadas y llevan la segunda puerta (`requirePerson()` exige persona y `ultron.view`). El Administrador recibe 403 en publicar, promover, revertir y Legacy; una llave de escritura sin `ultron.view`, 403 sin escribir nada; un uuid inventado no da oráculo. Cuatro ojos por identidad (`created_by_admin_id`), no por rol.
- **`clientRequestId`** atado a persona, acción, versión y cuerpo: otra persona con la misma llave recibe 409 `client_request_reused` sin la entrada.
- **Entrada:** 422 en los 19 cuerpos inválidos probados de `publish`, 413 por encima de 64 KB y 422 con un JSON anidado 600 niveles. Los campos de más del cuerpo se ignoran: la fila sale con los valores del servidor.
- **Exposición:** 21 respuestas del ciclo entero y el log, sin el id del canario, firmas, el texto firmado, `client_request_id`, `actor_admin_id` ni `SQLSTATE`; el E2E barrió 230 respuestas más con el mismo resultado. Las líneas de log nuevas llevan solo la clase del error. El texto del acta no sale del búfer propio de `Artisan::call` ni se guarda, se registra o se devuelve.
- **El lector del motor nunca lanza:** con una fila cruda de 20 000 caracteres sale `fallback/hash_mismatch`; con la `APP_KEY` rotada, `fallback/signature_invalid`; con la `APP_KEY` vacía, `fallback/read_failed`.
- **Limitadores:** el de la evidencia va por persona y por red, y `X-Forwarded-For` no lo esquiva (sin `TrustProxies`, cuenta la dirección del par TCP).
- **El sello no se puede falsificar desde n8n:** `metadata.studio` lo pone `persist()` en su base, y las llamadas que añaden metadatos construyen ese array en el código, sin claves del cuerpo.
- **Base de datos:** disparadores de UPDATE y DELETE (en los dos motores) y de TRUNCATE (en PostgreSQL) sobre la cadena, los CHECK, los índices únicos parciales y `down()` que se niega con datos de H5. Los privilegios por defecto de `iron` hacia `iron_app` (`arwd` en tablas y `rwU` en secuencias, sin TRUNCATE) bastan para el código sin ningún GRANT nuevo (reproducido en local por el database-specialist); en producción lo comprueba el paso 4 del script de la fase 1. Sobre la cadena incluyen UPDATE y DELETE, que la cadena no necesita y que paran los disparadores `uspub_*`: el script los enseña como información, y revocarlos es un refuerzo candidato del Hito 7 (`940b0c2`).
- **CRM:** 0 `innerHTML`, `bypassSecurityTrust` o equivalentes en el módulo; nada nuevo en el almacenamiento del navegador; el 429 se lee del cuerpo; los botones salen de `actions.*.allowed` del servidor; `previewHash`, `evidenceHash` y lo reconocido los recalcula el servidor, así que el cliente solo puede provocar un 409.
- **Arnés E2E:** secretos aleatorios en cada ejecución y su propia `APP_KEY`; se niega a correr si la API no es local o si `APP_ENV=production`.

### Protecciones del Hito 5 (rigen en producción cuando se despliegue)

- **Tres llaves y un registro** (arriba). Con las llaves por defecto, publicar y promover responden 409 `publishing_disabled` o `engine_not_reading_versions`, y el motor no hace ninguna consulta.
- **Registro firmado:** cada entrada de la cadena lleva la huella y la firma de los bytes exactos que lee el motor. Una cabeza que no verifica sale `fallback` en el motor y bloquea publicar, promover y revertir (409 `publication_head_invalid`); la única salida es volver a Legacy.
- **Cuatro ojos:** nadie publica ni promueve lo que creó (por identidad de la cuenta, no por rol).
- **Ventana:** publicar y promover, solo dentro de la ventana (por defecto, de 07:00 a 19:00, hora de Bogotá; mal escrita, vuelve a esa). Revertir y Legacy, siempre.
- **Libro de solo inserción:** la cadena y el libro de auditoría no admiten UPDATE, DELETE ni TRUNCATE con el rol de la aplicación, y cada escritura deja su evento en la misma transacción.
- **`effective_json` acotado:** el motor y el Studio leen como mucho 16 385 caracteres; una fila más larga sale `hash_mismatch` en vez de agotar la memoria del proceso.
- **El lector nunca lanza:** cualquier fallo sale como `fallback` y el turno sigue. El vigía y el freno no cambian.
- **La evidencia del canario** tiene su propio limitador, compartido con promover.

**Cuatro ojos por cuenta, no por persona** (riesgo residual de `api-security-specialist`): un Super Admin puede crearse una segunda cuenta con `ultron.publish` y publicar su propia versión. Es un control organizativo: decisión del propietario.

### Rotación de la `APP_KEY` con la cadena viva

La firma solo usa la clave vigente (`APP_PREVIOUS_KEYS` no la salva): tras rotar, ninguna entrada vuelve a verificar, el motor sella `fallback/signature_invalid` y `publication-check` sale con 1 (`head_invalid`), que es la alarma. La salida es volver a Legacy y recuperar la declaración con un ciclo completo nuevo. Fuera del Hito 5, la `APP_KEY` alimenta el `decide_token`, otras HMAC (`access_hash`, códigos y tokens del kiosco) y lo cifrado (sesiones, cookies, columnas cifradas): **rotarla es un evento de todo el sistema y el Hito 5 no lo justifica por sí solo.** Procedimiento en `ULTRON_STUDIO_ROLLBACK.md` («Hito 5»).

### Puerta PostgreSQL obligatoria

Sigue vigente y cubre ahora la cadena: antes del despliegue, `tests/Feature/UltronStudio tests/Unit/UltronStudio` en verde en un PostgreSQL 14 desechable (`docs/ultron-studio/ops/pgunit.sh`). Solo PostgreSQL ejecuta los CHECK, el disparador de TRUNCATE, los índices parciales con dos sesiones, las 38 carreras reales del tester y el READ COMMITTED de `recordDenied`. Con las carreras tarda de 3 a 8 minutos. **Hecha por el lead tras la fusión (31-10-2026):** **1446 OK** sobre `cdb9dc8` y **1447 OK** sobre `cf59cef`, con las 38 carreras; además, Studio + Access + Chaos en SQLite, **1697 OK** (56 omitidas, las de solo PostgreSQL), Marketing (Feature + Unit), **4977 OK**, `auth:coverage --unmapped` vacío y **38 rutas**.

### Pendiente del propietario (Hito 7)

- Marcar en el historial las entradas que no verifican (B-2).
- El `seq` contiguo y `CHECK (seq >= 1)` (AS-3/N-3), en una migración aditiva.
- `ironbody-migrate`, que corre como root: desde el 25-10 el árbol y `vendor/` son de root, pero al migrar root ejecuta `bootstrap/cache/*.php`, que son de `www-data` (la clase de riesgo de M-3 persiste por la caché; D2-3 del reviewer, visto en el código y no comprobado en el servidor), y su `config:cache` nace con la umask de `www-data` antes del `chmod 600` (N-7).
- Los caracteres invisibles que acaban en la cadena (AS-5), mejor antes de la fase 2.
- El procedimiento de redacción del libro con el rol dueño, que tiene que cubrir también `ultron_studio_publications` (nombre y rol del personal, y `reason`).
- Revocar UPDATE y DELETE de `iron_app` sobre la cadena (refuerzo candidato, en una migración aditiva del rol dueño; hoy los paran los disparadores).
- Los respaldos de G y del Hito 4 se crearon sin `umask 077`, y sus directorios están en 755 (comprobado por el lead el 31-10-2026); el modo de sus volcados no consta. Endurecerlos durante la fase 1 del Hito 5, con autorización (runbook, paso 9), y nunca borrarlos.

**Secretos:** ninguna revisión encontró secretos expuestos (en los diffs solo aparecen claves de prueba de los fixtures); no hay nada que rotar.

## Hito 6 (integraciones comerciales): revisiones de seguridad

**Estado:** implementado en la rama `feat/ultron-studio-h6` (backend `e3a0fdd`; CRM `f38699d`), **no desplegado**. Revisiones del 01-11-2026: `plans/informes-h6/informe-security.md` (`security`, transversal) e `informe-api-security.md` (`api-security-specialist`), más los hallazgos de seguridad del tester de backend (`informe-tester-backend.md`) y de DevOps (`informe-devops-h6.md`). Lo que deciden está congelado en las Enmiendas 3 y 4 del contrato. Las protecciones de esta sección **rigen en producción cuando se despliegue**.

**Superficie nueva:**
- **18 rutas** (`/api/admin/ultron-studio/integrations/*`; 56 en el Studio), con 4 llaves nuevas: `ultron.campaigns_manage` y `ultron.canary_manage` (el Administrador las tiene por defecto) y `ultron.canary_authorize` y `ultron.integrations_manage` (solo el Super Admin por defecto). El token compartido no recibe ninguna.
- **Datos personales en 3 tablas nuevas**, de solo inserción o con transiciones condicionadas: la huella HMAC de los teléfonos canarios, sus 4 últimos dígitos y su alias; el teléfono cifrado si es de voz; el nombre y el rol del personal; y texto libre (los motivos de desactivar y de revocar y la nota del reconocimiento), que no se puede corregir ni borrar.
- **El teléfono del lead de la conversación canaria** se lee en memoria para comparar su huella (coherencia) y se descarta.
- **Red:** solo la sonda de Twilio (5 GET, tras su bandera) y la verificación de ElevenLabs por consola (`voice:doctor --verify` e `integrations-snapshot --verify-voice`, que en el Hito 6 no se ejecutan en producción).
- **Dos subclaves HMAC nuevas de la `APP_KEY`:** `ultron-canary-phone/1` (huellas de teléfono) y `ultron-studio-config-fingerprint/1` (huellas de 8 hex de los secretos en la configuración autorizada); y los ids opacos de Meta (`MetaAdEntity::opaqueId`).
- **8 variables de entorno** y dos scripts que las escriben en producción (`deploy-h6-backend-fase1.sh` y `h6-integrations-scope.sh`).

**Lo que no cambia:** lo que ULTRON dice o hace; el motor, el OTP y las integraciones existentes (la puerta de diff del contrato sale vacía, comprobado por `security` y de nuevo sobre `e3a0fdd` el 01-11-2026); el catálogo (`2026-10-19.1`); y las dependencias (`composer.*` y `package*.json` sin cambios en los dos repos, `security` §3 #2).

### Las llaves

| Llave | Dónde | Qué abre | Quién la gira |
|---|---|---|---|
| Escrituras | `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` (apagada; solo `true`, `1`, `on` o `yes` la encienden) | Las rutas 47–54: asociar y desactivar campañas, y registrar, autorizar, activar, desactivar, revocar y fijar la expectativa de los canarios. Apagada: 409 `integrations_writes_disabled`, que no se audita | `h6-integrations-scope.sh writes`, en la fase 2 |
| Sonda | `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` (apagada; igual) | La ruta 56. Apagada: 409 `probe_disabled` | `h6-integrations-scope.sh probe`, en la fase 2, solo con el entorno efectivo en `production` (AS-5) |
| Reconocer la configuración | Sin bandera (decisión 11) | La ruta 55, con `ultron.integrations_manage` (solo el Super Admin) | Una persona, cuando quiera; queda en el libro |
| ElevenLabs | `ELEVENLABS_ENABLED` y las cuatro piezas | Solo la verificación por consola: no hay ruta de voz | El propietario, con autorización expresa aparte |
| Llamadas | `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false` | Nada: ninguna llamada es posible en el Hito 6 | Solo el commit que implemente las llamadas, con autorización expresa |

**Cuatro ojos por identidad**, en el servicio y en la base: nadie autoriza (`uscn_four_eyes_chk`) ni activa (`uscn_activation_four_eyes_chk`) un número canario que registró. Los dos CHECK se probaron con UPDATE crudos en PostgreSQL. **Cuatro ojos por cuenta, no por persona** (heredado, como en el H5): para el T1, el runbook exige dos personas, no dos cuentas.

### Revisores y veredictos

| Revisión | Sobre | Veredicto |
|---|---|---|
| `security` (transversal) | Backend: los paquetes DB, V y TW, S en `4e6f5fe` y OPS en `34ab7b3` (HEAD `f549c50`); CRM: la capa de datos de `ede716b` y una pasada rápida por la página en curso | **Sin bloqueantes, altas ni medias:** 2 bajas (B-1 y B-2) y 5 notas (N-1 a N-5). Cierra AS-4 y AS-5 de `api-security-specialist` |
| `security`, segunda pasada (la página del CRM) | CRM `f38699d` | **Hecho (01-11-2026, `security`, apartado «Segunda pasada» de `plans/informes-h6/informe-security.md`, confirmado en `8c3eb7e`): sin bloqueantes, críticas, altas, medias ni bajas; dos notas: N-6 (el lector de las bases de Twilio descarta un host IPv6 o con guion bajo y deja el panel sin leer; hoy no ocurre con las bases oficiales) y N-7 (fixture del CRM desfasado, cerrada en `3bac1e2`). Cero sumideros de HTML en los 22 ficheros, ninguna URL con datos del servidor, teléfono solo enmascarado y solo en la ruta 49, nada en almacenamiento ni consola, botones solo de `can`/`flags`/`actions`, foco atrapado y sin autofoco en el teléfono; specs del módulo 1074 + 60 en verde. |
| `api-security-specialist` | Backend `8de30ed` (el código de S es el de `4e6f5fe`) | **APROBADO CON OBSERVACIONES**, sin bloqueantes ni altas: 2 bajas (AS-1 y AS-2) y 4 notas (AS-3 a AS-6) |
| `tester` de backend | `f549c50` con la tanda final de S, en PostgreSQL 14.24 con sesiones reales | H-1 (= B-2; el tester la clasificó media, por la integridad del libro), H-2 (baja, nueva) y H-3 (= B-1, medida); O-1 y O-2, observaciones |
| DevOps | Los scripts y el runbook | H-1 (orden de la fase 2), H-2 (la rama sin `7ba0ce8`, resuelto), H-3 (cupo del smoke) y H-4 (umbral del respaldo) |
| `reviewer` | — | [PENDIENTE: revisión final del Hito 6.] |

### Hallazgos y estado

Los códigos se repiten entre revisiones, así que cada uno lleva su origen.

| Origen · # | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| security · B-1 = api-security · AS-4 = tester · H-3 | Baja (cumplimiento, Ley 1581) | **La huella del teléfono es un seudónimo, no un dato anonimizado.** Con la `APP_KEY`, la huella y los 4 dígitos que guarda la fila, el número se recupera en milisegundos (`security`: ≤ 0,2 s; `api-security-specialist`: 58 ms); sin los 4 dígitos, el espacio de WhatsApp (10⁹) se recorre en unos 24 minutos con un núcleo (tester: unos 705 000 HMAC por segundo y núcleo). I13 decía que «no se puede recuperar ni con la `APP_KEY`». **Revocar no suprime:** huella, 4 dígitos y alias son inmutables y la fila no se borra. Hace falta tener a la vez la base y la clave (un RCE como `www-data`, o el respaldo de la fase 1, que guarda juntos el volcado y `env.backup`); ese atacante ya lee en claro los teléfonos de leads y socios | **Texto del contrato corregido en la Enmienda 3** (I13): la `APP_KEY` protege también los teléfonos canarios. **Pendiente en el código:** el docblock de `PhoneFingerprint.php` (líneas 11–12) sigue diciendo que con la clave «tampoco sale el número» (comprobado el 01-11-2026 sobre `e3a0fdd`; dueño: S). **Diferido al Hito 7:** el procedimiento de supresión de una fila revocada con el rol dueño (architect y `database-specialist`, con decisión del propietario) |
| security · B-2 = tester · H-1 | Baja para `security`; media para el tester | Revocar anotaba en el libro el estado de origen leído **antes** de la transacción: con una autorización a la vez, el evento decía `pending → revoked` cuando se revocó ya autorizado. El libro, de solo inserción, contaba una historia imposible; el estado final era correcto | **Corregido en `3e1ee20`** (D-14 de S): el estado se lee dentro de la transacción con la fila bloqueada. La carrera forzada del tester lo reproduce de forma determinista y pasa con el arreglo (45/45) |
| api-security · AS-1 | Baja (antes de la fase 2) | Los motivos (rutas 48, 52 y 53), la nota del reconocimiento (55) y el alias admitían teléfonos y correos, que acaban en columnas que no se pueden corregir y en el libro, y vuelven a todo `ultron.view` | **Corregido en `3e1ee20`** (D-15): 422 con un texto fijo ante una serie de 8 o más dígitos que no tiene forma de fecha o un correo (el criterio de `LeadSourceClassifier`, copiado al Studio); en el alias, la Enmienda 4 lo amplía al dato de contacto con separadores, y el fixture pasa a 722 casos. Cierra O-1 de la Enmienda 2 |
| api-security · AS-2 | Baja | `GET integrations/meta/campaigns?page=9223372036854775807` respondía 500 y dejaba un ERROR con traza: ensuciaba justo las dos señales del despliegue | **Corregido en `3e1ee20`** (D-16): `page` hasta 100 000 (por encima, 422) y un desplazamiento que no desborda |
| security · N-1 | Nota | Un fallo de la base en el registro distinto del duplicado (interbloqueo, tiempo agotado, conexión caída) dejaba en `laravel.log` el SQL con la huella, los 4 dígitos, el alias y el cifrado | **Corregido en `3e1ee20`** (D-17): se relanza con solo la clase y el SQLSTATE, y esos valores van como parámetros sensibles. Con `zend.exception_ignore_args` de producción no comprobado |
| tester · H-2 | Baja (nueva) | En cada registro duplicado, la huella del teléfono acaba en el `DETAIL` del error 23505 del **log del servidor PostgreSQL** (no en el de la aplicación): una segunda copia de la huella con la retención y los lectores de ese log | **Diferido al Hito 7** (Enmienda 4): el procedimiento de supresión y la configuración del log del servidor. Corrección propuesta por el tester (S): comprobar el duplicado antes del INSERT; alternativa (`devops`): `log_error_verbosity = terse` para el rol de la aplicación, sin probar |
| security · N-2 = api-security · AS-5 | Nota | Fuera de `production`, la base de la sonda y la de ElevenLabs aceptan cualquier host (también `http://`); todo el fijado de hosts depende de `app()->isProduction()` y ningún script lo afirmaba | **El mando de la sonda comprueba `production`** antes de encenderla (`6241758`). Fuera de `production`, sin cambio (anotado en la Enmienda 3) |
| security · N-3 | Nota (antes de la fase 2) | «Base oficial» miraba solo el host: `http://api.twilio.com`, otro puerto o credenciales en la URL salían `official: true` | **Corregido en `3e1ee20`** (D-18): `https`, host exacto, puerto 443 y sin credenciales. Con las bases por defecto, la huella de producción no cambia. Va antes de reconocer Twilio en la fase 2 |
| security · N-4 | Nota (operación) | (a) `writes off` se daba por aplicado con tinker (CLI), sin mirar el proceso web; (b) el modo del fichero de entorno no se exigía; (c) `ironbody-migrate` como root sobre `bootstrap/cache/*.php` de `www-data` (conocido, D2-3 del H5) | (b) **Corregido en `bd1c7af`:** el mando exige el fichero en 600. (a) La vuelta atrás `writes off` se comprueba con el smoke de la fase 1, que solo vale con la sonda también apagada. [PENDIENTE: comprobar una vez `opcache.validate_timestamps` del pool de FPM y anotarlo (`devops`).] (c) Pendiente del propietario (Hito 7) |
| security · N-5 | Nota | El patrón del `agent_id` de ElevenLabs iba sin `D`, y la subclave de `secretFingerprints()` sin `#[\SensitiveParameter]` | **Corregido** en `bd1c7af` y completado en la Enmienda 4 (`4e2e8cf`): el `agent_id` ya no se recorta antes de casarlo |
| api-security · AS-3 | Nota | Quien tiene `canary_manage` puede comprobar si un teléfono que adivina está registrado (409 `canary_number_exists`) o es el canario del motor (`isEngineCanary`). Cada intento deja una fila y un evento permanentes, y el tope de 10 vivos lo corta | Inherente a la unicidad y a la coherencia. **Se revisa en el Hito 7**, junto con quién puede activar antes de T2. Opcional: una alerta cuando una persona registra y revoca muchos números en una hora |
| api-security · AS-6 | Nota (documentación) | `clientRequestId` vale por tabla, no entre tablas: la misma llave sirve para un canario y para una asociación | Sin doble efecto posible. Cada acción del CRM genera su propio uuid |
| tester · O-2 | Observación | Con `default_transaction_isolation = repeatable read` en el rol de la aplicación, 7 de las 37 carreras responderían 500 (40001); 3 de ellas, por el limitador de Laravel sobre la caché `database`, así que el problema sería de todo el CRM | El rol de producción tiene que estar en READ COMMITTED. [PENDIENTE: comprobación de solo lectura antes de la fase 2 (`devops`).] |
| documentación · fixture del CRM | Nota (sin impacto de seguridad) | La copia del CRM de `backend-integrations.testing.ts` es el export de `4e6f5fe`, anterior a la tanda final de S (`3e1ee20`): le faltan el texto general de O-2 y las claves `phoneNotMobileForWhatsapp` y `contactData` (AS-1), y su ejemplo `unreadable` lleva `activeWhatsapp: null` donde el backend exporta `1` (comprobado el 01-11-2026 en `StudioIntegrationsExport.php` y en la copia del CRM). Lo que AS-1 rechaza lo sigue rechazando el servidor con 422 | **Hecho (lead, 01-11-2026, CRM `3bac1e2`):** `backend-integrations.testing.ts` reexportado desde el backend final (`ultron-studio:export-fixtures` sobre `e3a0fdd`+docs) y copiado byte a byte; `permissions:export` idéntico al catálogo del CRM (105 llaves); specs de paridad y de canarios alineadas (texto general de O-2, `phoneNotMobileForWhatsapp`, `contactData` y `activeWhatsapp: 1` con coherencia ilegible, tolerando `null`); módulo + `models` 70/70 ficheros en verde. |
| S · T-1 (motor, fuera de alcance) | Nota | El validador privado de `usage-trigger` (`TwilioUsageTriggerController.php:78`, con `fullUrl()`) rechaza los 6 vectores oficiales válidos de Twilio: `fullUrl()` reordena la consulta, quita los puertos 443 y 80 y no trata `bodySHA256` ni las claves repetidas. Sin consulta en la URL coincide con el algoritmo publicado. Falla cerrado (403) | Sin cambio: no afecta mientras la URL configurada en Twilio no lleve consulta. [PENDIENTE: comprobar esa URL en la consola de Twilio (propietario).] Extraer el validador y `trustProxies`: Hito 7 |

### Verificado sin hallazgo

(`security`, leyendo el código, con PHPUnit en SQLite y en PostgreSQL 14 y con tres scripts mínimos de claves y teléfonos sintéticos; `api-security-specialist`, con pruebas de concepto en SQLite y en PostgreSQL; el tester, con sesiones reales.)
- **Cero motor, cero red y sin dependencias.** Con las banderas por defecto, ninguna de las 18 rutas sale a la red (`Http::recorded()` vacío). Solo pueden salir la sonda (5 GET, hosts fijos en `production`, sin redirecciones ni reintentos, primera página) y la verificación de voz por consola. Desde el CRM no hay ningún camino que escriba en Meta o que llame: la única red alcanzable desde una ruta del hito es la sonda.
- **Huellas.** La derivación real coincide con una segunda vía con `openssl`, la del teléfono y la de la configuración. Etiquetas propias y disjuntas de cualquier otro uso de la clave: la segunda parte de AS-4 (las subclaves comparten clave con `Workforce\Secrets::hash()`) **no es explotable**, porque todas las entradas de `Secrets` llevan un prefijo con `|` y ninguna etiqueta lo lleva. Sin `APP_KEY`, lanza en vez de calcular con una clave vacía; las comparaciones usan `hash_equals`.
- **Datos personales.** El número completo nunca sale, ni tras registrarlo. El cifrado, solo en voz, y a NULL en la misma sentencia que revoca (`uscn_revoke_chk`); nada lo descifra. El teléfono del lead canario solo existe en memoria y su log lleva solo la clase del error. De `marketing_calls` no se lee ninguna columna personal. En la sonda, solo recuentos y los 4 dígitos de los números de la cuenta; el `auth_token` de la ficha de la cuenta se elimina.
- **Secretos.** La centinela no encuentra secretos ni teléfonos en las 18 rutas, en el libro, en `facts`, en la consola ni en el log, en SQLite y en PostgreSQL; la del tester tampoco en el exportador de fixtures ni en la configuración calculada de verdad desde el entorno. La credencial de Twilio va en `SensitiveParameterValue`. Las huellas de voz salen solo de `secretFingerprints()`. `.env.example`, solo con defectos.
- **Autorización.** Las 56 rutas mapeadas y `auth:coverage --unmapped` vacío; la matriz del contrato entera (sin sesión 401; token compartido 403; Recepción, Entrenador y Administrativo, 403 en las 18; el Administrador, 403 en autorizar, reconocer y la sonda); una llave sin `ultron.view`, 403 antes de buscar el recurso; ningún oráculo de uuid; la barra final, `%2F` y el uuid en mayúsculas no cambian la llave.
- **Escrituras.** El `clientRequestId` de otra persona da 409 en las cuatro tablas; el libro va en la misma transacción en las 10 escrituras; los topes, bajo cerrojo consultivo después de READ COMMITTED; 413 por encima de 64 KB y 422 con un JSON de 600 niveles; los campos de más se ignoran.
- **Cupos.** Los de la sonda (6/h por persona y 20/h por red) no los esquivan dos sesiones ni `X-Forwarded-For`; el enfriamiento no hace red ni escribe evento.
- **Operación.** `git` como root con `umask 022` y lectura por `www-data` comprobada fichero a fichero; respaldo en 700 y copias en 600 de root; el fichero de entorno se comprueba que parsea antes de tocar las cachés; la configuración efectiva se compara con la esperada; ni tinker ni el smoke imprimen valores; el smoke nunca pulsa la sonda.
- **CRM** (capa de datos y una pasada rápida por la página, todavía en escritura cuando `security` revisó): sin `innerHTML`, `bypassSecurityTrust` ni almacenamiento del navegador; solo llama a `integrations/*`; los 7 textos obligatorios son literales; el lector de llamadas no copia `to_phone` ni `from_phone`; el teléfono del formulario no se guarda. `frontend` lo confirma sobre `f38699d` (`informe-front-integracion.md` §8): cero `innerHTML` (la mutación del alias como `[innerHTML]` cae), textos hostiles interpolados y el teléfono nunca en pantalla tras registrarlo, en la sesión ni en el almacenamiento (comprobado también en Chrome con la API simulada). [PENDIENTE: la segunda pasada de `security` sobre la página (contrato, «Orden», paso 5); la rellena el lead.]

### Protecciones del Hito 6 (rigen en producción cuando se despliegue)

- **Nada cambia lo que ULTRON dice o hace:** el motor no lee el registro, las asociaciones ni la configuración autorizada (T0/T1). T2 y T3 exigen autorización expresa y su propia revisión de API.
- **Cero red por defecto;** la sonda solo hace GET a los hosts oficiales en `production`, con enfriamiento y cupo, y un fallo del proveedor nunca es un 5xx.
- **Sin secretos ni teléfonos en claro** en respuestas, libro, instantáneas, logs de la aplicación ni cliente: credenciales por presencia, referencia enmascarada o huella de 8 hex; teléfonos por huella y 4 dígitos.
- **Cuatro ojos** al autorizar y al activar un número canario, por identidad y en la base.
- **Solo inserción:** las instantáneas no admiten UPDATE, DELETE ni TRUNCATE; las asociaciones y los números no se borran (se desactivan o se revocan) y sus columnas inmutables no cambian; `down()` se niega con datos del Hito 6. **Nunca `migrate:rollback` en producción.**
- **Las escrituras y la sonda, tras sus banderas**, apagadas por defecto; las llamadas, cerradas en el código.
- **La puerta PostgreSQL** cubre ahora los disparadores, los CHECK (los dos de cuatro ojos con UPDATE crudo), los índices parciales y las carreras del Hito 6.

### Rotación de la `APP_KEY` con el Hito 6 desplegado

Además de lo del Hito 5, rotar la `APP_KEY` deja inservibles las huellas de los teléfonos canarios (**el T1 se pierde**: la coherencia pasa a `engine_canary_not_registered`), los cifrados de voz (sin la clave vieja en `APP_PREVIOUS_KEYS`), los ids opacos de Meta (el CRM tiene que recargar) y las huellas de la configuración (`meta_ads` y `twilio` salen en `drift` aunque los secretos no hayan cambiado). Si se rota por compromiso de la clave, las filas revocadas siguen siendo recuperables con la clave vieja (B-1). Procedimiento en `ULTRON_STUDIO_ROLLBACK.md` («Hito 6»); se suma al del Hito 5, en el mismo acto.

### Puerta PostgreSQL obligatoria

Sigue vigente y cubre ahora el Hito 6: `docs/ultron-studio/ops/pgunit.sh` con el grupo del Studio, incluidas las carreras del tester (`UltronStudioIntegrationsRaceTest`, solo PostgreSQL) y las guardas como rol de aplicación. **Hecha por el lead sobre `e3a0fdd`** (el código final del backend, igual que `4e2e8cf`; 01-11-2026, 18:43–18:49 UTC): la puerta PostgreSQL, **2455 OK** en 4 minutos (base `ultron_studio_h6_lead`); Studio + Access + Chaos + Voice + Twilio + OTP en SQLite, **2879 OK** (102 omitidas, las de solo PostgreSQL); 56 rutas, `auth:coverage --unmapped` vacío y la puerta de diff del motor vacía. Se repite si el backend cambia de código antes de la fase 1: sin ella no se despliega.

### Pendiente del propietario (Hito 7)

Diferido por las Enmiendas 3 y 4 y por los informes de seguridad:
- **El procedimiento de supresión** (derecho de supresión, Ley 1581), con el rol dueño y su evento en el libro: cubre las 3 tablas nuevas (la huella, los 4 dígitos, el alias y los motivos de una fila revocada; el nombre y el rol del personal; las notas) además de las de F, del Hito 4 y de `ultron_studio_publications`.
- **La regla del alias más fuerte** (series de dígitos con separadores que sumen 7 o más), que la Enmienda 3 difiere.
- **Quién puede activar un número antes de T2:** hoy basta `canary_manage`, que el Administrador tiene por defecto, y ser otra persona que quien registró; cuando el motor lea el registro, activar decidirá a quién responde ULTRON (AS-3 pesa más).
- **H-2 del tester:** la huella en el log del servidor PostgreSQL.
- **El validador de `usage-trigger`:** extraerlo, `trustProxies` y la firma de la URI tal como llega (T-1, T-2 y el hallazgo de `fullUrl()`).
- **`ironbody-migrate` como root** y la umask de su `config:cache` (ya conocido del Hito 5).
- **Derivaciones con la `APP_KEY`:** un identificador de clave, un mínimo de 32 bytes decodificados, HKDF o una subclave propia para `Workforce\Secrets` en un contrato `/2`.
- **Los caracteres invisibles que `PlainText` deja pasar** (AS-5 del H5) alcanzan desde el Hito 6 tres columnas más que no se pueden corregir (`revoke_reason`, `deactivation_reason` y la nota de las instantáneas).
- **Programar `integrations-snapshot`** (el registro de cambios de configuración) y persistir los avisos de `usage-trigger`, si se decide.

**Secretos:** ninguna revisión encontró secretos expuestos ni imprimió ninguno real (las pruebas usan valores centinela y una `APP_KEY` sintética); no hay nada que rotar.

## Requisitos para las fases siguientes (recogidos de las revisiones y la auditoría)

- Permisos `ultron.*` (ver, editar borrador, reglas, campañas, simulaciones, canarios, pruebas telefónicas, aprobar, publicar, revertir, ajustes) **validados en Laravel en cada endpoint**; separar quien edita, aprueba, publica y administra integraciones.
- **El servidor revalida todo lo publicable** sin fiarse del borrador: la confirmación de los parámetros importantes hoy solo existe en el navegador, y un borrador cargado de `localStorage` podría traer `paymentLinksEnabled: true` sin haber pasado por ella.
- El Studio **nunca** reutiliza las rutas internas de n8n ni muestra ejecuciones de n8n (guardan el bearer).
- Nunca devolver al front tokens, secretos de webhook, claves de OpenAI/ElevenLabs ni teléfonos en claro; ids de Meta enmascarados como hace el panel actual.
- Evaluación con OpenAI: clave y proyecto propios, salidas estructuradas estrictas, registro de tokens y coste, entradas redactadas. *(Implementado en la rama del Hito 4, salvo «entradas redactadas»: el laboratorio no lee textos de prospectos y rechaza los datos personales en vez de redactarlos. El proyecto propio lo crea el propietario; ver «Hito 4».)*
- Twilio Voice: subcuenta, `TimeLimit`, cupo diario propio y rangos de alto riesgo cerrados; webhooks con firma validada e idempotencia; ningún mecanismo actual frenaría un gasto de voz. *(Hito 6, en la rama: solo las variables tras bandera, que nada usa para ejecutar, y la política de llamadas con `CALLS_ENABLED_IN_CODE = false`. Lo demás es del hito de voz.)*
- ElevenLabs: adaptador tras bandera, webhooks firmados, grabación desactivada hasta resolver consentimiento y retención (Ley 1581). *(Hito 6, en la rama: el adaptador tras bandera y el verificador de la firma del webhook post-llamada, sin ruta pública; ninguna grabación ni llamada. Ver «Hito 6» y `ULTRON_STUDIO_ELEVENLABS_READY.md`.)*
- Hallazgos previos del motor que no son del Studio pero importan (auditoría §13): `/ai/decide` sin interruptor ni canario, el precio que llega al Strategist, la respuesta del commit con precio y URL de pago hacia n8n.
