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

## Requisitos para las fases siguientes (recogidos de las revisiones y la auditoría)

- Permisos `ultron.*` (ver, editar borrador, reglas, campañas, simulaciones, canarios, pruebas telefónicas, aprobar, publicar, revertir, ajustes) **validados en Laravel en cada endpoint**; separar quien edita, aprueba, publica y administra integraciones.
- **El servidor revalida todo lo publicable** sin fiarse del borrador: la confirmación de los parámetros importantes hoy solo existe en el navegador, y un borrador cargado de `localStorage` podría traer `paymentLinksEnabled: true` sin haber pasado por ella.
- El Studio **nunca** reutiliza las rutas internas de n8n ni muestra ejecuciones de n8n (guardan el bearer).
- Nunca devolver al front tokens, secretos de webhook, claves de OpenAI/ElevenLabs ni teléfonos en claro; ids de Meta enmascarados como hace el panel actual.
- Evaluación con OpenAI: clave y proyecto propios, salidas estructuradas estrictas, registro de tokens y coste, entradas redactadas.
- Twilio Voice: subcuenta, `TimeLimit`, cupo diario propio y rangos de alto riesgo cerrados; webhooks con firma validada e idempotencia; ningún mecanismo actual frenaría un gasto de voz.
- ElevenLabs: adaptador tras bandera, webhooks firmados, grabación desactivada hasta resolver consentimiento y retención (Ley 1581).
- Hallazgos previos del motor que no son del Studio pero importan (auditoría §13): `/ai/decide` sin interruptor ni canario, el precio que llega al Strategist, la respuesta del commit con precio y URL de pago hacia n8n.
