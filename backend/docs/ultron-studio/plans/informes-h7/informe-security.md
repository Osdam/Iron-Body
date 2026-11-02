# ULTRON STUDIO · Hito 7 · SEC7 · Seguridad transversal (`security`)

**Fecha:** 01-11-2026. **Base:** `feat/ultron-studio-h7` en `f78adfc` (CRM `f38699d`, solo lectura). **Sin commit.** Rutas relativas a `backend/`.
**Árbol compartido:** DB7, B7 y OPS7 escribían a la vez; para atribuir resultados, las suites se repitieron en un worktree temporal **HEAD + solo los ficheros de SEC7**.
**Addendum del 02-11-2026 (§7):** M-1 de REV7 cerrado con la opción (b). La clase completa de §1 queda **solo en el Studio** (`new PlainText(strict: true)`); fuera, `PlainText` vuelve a la lista y al mensaje de antes del H7. Lo de §1 sobre `PlainText` describe hoy el modo `strict`. También m-3 (lista blanca de aislamiento) y la SQL de H-8 de §5, reescrita solo con ASCII y escapes (§7.8).

## 0. Veredicto

**SEC7 (a) hecho y verificado. Sin bloqueantes nuevos para el hito.** Dos medias que no exigen parar el H7 pero sí decisión antes de la fase 2: dependencias con avisos críticos/altos (ninguno con camino de explotación confirmado aquí) y la contraseña restablecida que no revoca sesiones.

| # | Severidad | `ruta:línea` | Escenario | Corrección → dueño |
|---|---|---|---|---|
| H-1 | **Media** | `composer.lock` (laravel/framework 12.58.0, guzzle 7.10.0, psr7 2.9.0, commonmark 2.8.2, symfony/* 7.4.8, jmespath 2.8.0) | `composer audit`: 13 paquetes, 46 avisos (2 críticos, 15 altos). Alcance revisado: TokenGuard (crítico) **no alcanzable** (solo guard `session`; `EnsureAdminAuth` usa `bearerToken()` + `hash_equals`, `?ticket` con `is_string`); jmespath (crítico) **no alcanzable** (vía `kreait/firebase-php`, CompilerRuntime solo con `JP_PHP_COMPILE`); CRLF de la regla `email` + `symfony/mime` solo si se envía correo a una dirección del cliente (`SendElectronicInvoiceEmailJob.php:90`; mailer por defecto `log`); URL firmada temporal (<12.61.1) **en uso** (`MarketingAttachmentController.php:84`, `routes/marketing.php:267`) | `composer update` dentro de las mayores actuales (framework ≥12.69, guzzle ≥7.15.2, psr7 ≥2.12.3, commonmark ≥2.10.2, symfony 7.4 último parche, jmespath ≥2.9.1), suite completa + puerta PG, despliegue propio → **backend/devops**, autoriza **propietario** |
| H-2 | **Media** | `app/Http/Controllers/Api/Admin/AdminUserController.php:322-324` | Restablecer la contraseña de un administrador desde el CRM no revoca sus sesiones (solo lo hace `RotateAdminPassword`, consola). Credenciales robadas + «recuérdame» (`config/admin.php:31`, 30 días): el Super Admin restablece creyendo cortar el acceso y el token del atacante sigue valiendo. Mitigación existente: desactivar la cuenta corta al instante (`isActive()` por petición) | En la misma transacción, revocar las sesiones activas del afectado (`revoked_reason=password_reset`), conservando la de quien actúa si cambia la suya; prueba negativa (token previo → 403). Trade-off: el afectado sale de todos sus dispositivos → **backend + tester** |
| H-3 | Baja | CRM `package.json` (`@angular/*` 21.2.10) | `npm audit --omit=dev`: 6 (4 altos: bypass de sanitización/XSS, i18n; 2 moderados). Sin SSR y sin los sumideros afectados (`[innerHTML]`, `[attr.href]`, `xlink:href`, `i18n`: 0 apariciones) | Parche a 21.2.24 (misma mayor), `ng test` + build → **frontend/devops** |
| H-4 | Baja | `app/Console/Commands/CheckSecretExposure.php:45-56` | Vigila 18 claves; fuera quedan `ADMIN_API_TOKEN`, `AUTOMATION_INTERNAL_SECRET`, `DB_OWNER_PASSWORD`, `ELEVENLABS_*`, `MAIL_PASSWORD`, `META_EMBEDDED_SIGNUP_APP_SECRET`, `N8N_WEBHOOK_SECRET`, `REDIS_PASSWORD`, `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET`, `FACTUS_WEBHOOK_SECRET`, `AWS_SECRET_ACCESS_KEY`, `LABSMOBILE_TOKEN`, `MEMBER_REGISTRATION_TOKEN`. Un fichero parcial (sin `APP_KEY`) a 644 no se detecta | Añadir las claves → **backend** |
| H-5 | Baja | `docs/ultron-studio/ops/ironbody-migrate-propuesta.sh:208-210` (OPS7, sin confirmar, leído 14:40) | Root hace `stat` (sin `-L`: describe el enlace) y `chmod 600` (sigue el enlace) sobre `bootstrap/cache/config.php`, en un directorio que escribe `www-data`. Con RCE previo como `www-data` y una carrera, root cambia a 600 un fichero arbitrario (DoS; sin escalada) | Hacerlo como `www-data` (`como "$WWW" chmod 600 …`) o rechazar `[ -L ]`; nace 600 con `umask 077` → **OPS7** |
| H-6 | Baja | `app/Services/UltronStudio/StudioRuleCoherence.php:1001`, `:1019`; CRM `studio-rules-coherence.ts:81` | Frases y etiquetas de las condiciones de reglas usan `UntrustedText::isPlain` (lista corta), con espejo en el CRM y fixture de paridad: U+00AD, U+2060, etiquetas… entran en `conditions` y en el `diff` del libro (`StudioRules.php:246`). `StudioLabPrompts.php:819-820` (texto del juez) usa la lista corta + 4 | Cambiar backend y CRM a la vez a `InvisibleCharacters::FORBIDDEN_SINGLE_LINE` y reexportar `coherence-cases.json` → **backend (S) + frontend + tester** |
| H-7 | Baja | `config/otp.php:141-142`; `TwilioVerifyService.php:93,108`; `TwilioSmsSender.php:68` | El OTP usa Account SID + **Auth Token maestro** (control total de la cuenta) en Basic. Quien lea el `.env` o la caché tiene la cuenta entera | API key restringida (Verify/SMS); separar SID de cuenta y usuario Basic → **propietario** (consola) + **backend** |
| H-8 | Nota | `app/Services/Marketing/MarketingKnowledgeBaseService.php:113-122` (motor) | La limpieza nueva es en la escritura. `asData()` aplana C0 y espacios pero no `\p{Cf}` ni etiquetas: una fila YA guardada y aprobada con invisibles llegaría al prompt | SQL de solo lectura (§5) y, con contrato propio, limpiar también en la lectura → **propietario / architect** |

**Notas sin hallazgo:** CORS con `localhost:4200`/`127.0.0.1:4200` y cualquier subdominio en la configuración de producción (`config/cors.php:3-14`), sin credenciales: impacto bajo (el token vive en el almacenamiento del origen del CRM) → orígenes de desarrollo por entorno. `SecurityHeaders` sin HSTS ni CSP (delegados a nginx, `SecurityHeaders.php:18-20`) → verificar en el servidor. Token del CRM en `sessionStorage` (o `localStorage` con «recuérdame», 30 días) y sin caducidad por inactividad (`AdminSessionService.php:27-39`) → valorar inactividad y «recuérdame» ≤ 7 días. `.env.production.example` lleva los datos del emisor (persona natural) → minimización. La `key` de conocimiento no tiene formato: un homoglifo («pаyment», cirílico) no es invisible y no lo cubre esta clase.

## 1. SEC7 (a) · Caracteres invisibles (AS-5 del H5)

**Clase:** `app/Support/Text/InvisibleCharacters.php` (nuevo; el contrato preveía `app/Support/Text/`). `INVISIBLE_CLASS` es carácter a carácter `StudioLabPersonalData::INVISIBLE_CLASS` (`lab-invisibles`): `\p{Cf}`, U+E0000–E007F, U+2028/2029, C0 salvo `\t\n\r`, DEL y C1. Pruebas de deriva contra la clase y `StudioLabChecks::PICTO`.

**Decisiones (para el lead):**
- **ZWJ solo dentro de un emoji** (precedido de pictograma, U+FE0F o tono U+1F3FB–1F3FF y seguido de pictograma). Antes `PlainText` rechazaba todo ZWJ; aceptar el suelto reabriría AS-5 para un carácter («des»+ZWJ+«cuento»). Es más estricto que `lab-invisibles` (que deja pasar todo ZWJ) solo en el ZWJ suelto, que ya se rechazaba. Si se prefiere la regla exacta del laboratorio, es una línea.
- **U+FE00–FE0F pasan** (no son `Cf`). **U+202F no se rechaza**: el mensaje del lead decía «U+2028–U+202F», pero U+202F (espacio fino) es visible, no está en la clase del laboratorio y lo genera `Intl` en horas («10:00 a. m.»).
- **Fuera, igual que en el laboratorio:** rellenos coreanos (U+115F, U+1160, U+3164, U+FFA0), U+034F, U+17B4–17B5, U+180B–180F, suplemento de selectores U+E0100–E01EF (contrabando por selectores). Ampliar = cambiar las dos clases a la vez. Banderas de subdivisión (usan etiquetas) se rechazan.

**`PlainText`** (`app/Rules/PlainText.php`): rechaza la clase; 422 «El texto contiene un carácter invisible o de control (U+XXXX) que no se puede guardar…» (sin repetir el texto); en una línea, «tiene que ir en una sola línea» para `\t\n\r`; UTF-8 inválido o no-texto mantienen el mensaje anterior (`MESSAGE_INVALID`). `UntrustedText` no se toca (Caja; su `isPlain` lo replica el CRM con paridad).

**API interna** (`InternalMarketingKnowledgeController.php`): limpia `key`, `title`, `content` y las cadenas de `metadata` (claves incluidas) **antes** de validar y de buscar la `key` (un ZWSP en la `key` ya no crea un gemelo de un hecho aprobado: 409). U+2028/2029 → salto, controles → espacio, el resto fuera; UTF-8 inválido → `?` (antes, 500 por 22021 en PG con el SQL en el log). Registro `marketing.knowledge.invisibles_cleaned` (campos, recuento, puntos de código distintos ≤ 16, sin texto) y campo aditivo `cleaned` en 200/409. Un texto que solo eran invisibles queda vacío → el 422 de siempre. `submitted_by` ya lo reduce `actorLabel()`.

**Inventario del Studio** (grep de `validate()` en los 9 controladores y del normalizador):
- **Con `PlainText` (23):** versiones `label`, `note` ×4, `reason` ×2; publicación `reason`; reglas `description` ×2, `reason`, `refs.knowledgeKeys.*`; canarios `alias`, `reason`; asociaciones `reason`; integraciones `note`; laboratorio `note` (veredicto) y `reason` (retirar escenario); borrador: parámetros `text`/`textarea`/`tags` (`StudioDraftNormalizer.php:247`, `:302`).
- **Con un filtro igual o más estricto:** escenarios `title`, `description`, `turns.*.text`, `criteria.*.text` (`StudioLabPersonalData`, incluye ZWJ).
- **Por pertenencia a un conjunto/patrón:** `refs.configs.*.key` (catálogo), `acknowledgedWarnings`/`acknowledgedCases` (igual al conjunto del servidor; el libro guarda el del servidor), `acknowledgedNoEffect` (regex), filtros `status` (lista), `event` (regex).
- **Sin cubrir:** frases y etiquetas de `conditions` (H-6) y el texto del juez del laboratorio (H-6).

**Registrar en `bootstrap/app.php`:** nada (SEC7 no añade middleware).

## 2. Lo verificado

| Comprobación | Resultado |
|---|---|
| `InvisibleCharactersTest` / `StudioPlainTextInvisiblesTest` / `KnowledgeInvisiblesTest` (SQLite) | 91 (554) / 11 (90) / 7 (45), OK |
| Exhaustiva: los 1 112 063 puntos de código (sin sustitutos ni ZWJ) frente a `StudioLabChecks::INVISIBLE_PATTERN` | 0 discrepancias, 264 rechazados (PCRE 10.47); ~1 s, dentro de la prueba |
| Mutación inversa | `PlainText` de HEAD: 10/11 caen (la que pasa es el control); controlador de HEAD: 7/7 caen |
| Worktree aislado (HEAD `f78adfc` + solo SEC7), `php -d memory_limit=2G` | `tests/Feature/Marketing`: **2155 (12 830) OK**. `Feature/UltronStudio`, `Unit/UltronStudio`, `Feature/Caja`, `Feature/Valuations`, `Unit/Support`, `Feature/Security`: **3384 (41 200) OK**, 102 omitidas (solo PG) |
| Árbol compartido (con DB7/B7/OPS7 en curso) | Marketing: 6 errores en `UltronCommitStudioStampTest` (el disparador nuevo de DB7 rechaza su INSERT directo). Studio: 10 fallos = esquema ×7 y aislamiento (migración de DB7, `routes/console.php` de OPS7) y monitor ×2 (OPS7). **Ninguno de SEC7** |
| Puerta PG 14 (`UNIT_DB=ultron_studio_h7_sec7`) | 109 (689) OK. Contraprueba con el controlador de HEAD: UTF-8 inválido por formulario → **500, SQLSTATE 22021**; con el cambio, 200 |
| Fixtures de paridad (`tests/fixtures/ultron-studio/*.json`) | 0 cadenas afectadas salvo `lab-cases.json` `personalData` (filtro del laboratorio, no `PlainText`); el CRM no replica `PlainText` (solo `isPlainLine` de coherencia) |
| Pint (`--test`) en los 6 ficheros | passed |
| `composer audit --locked --no-plugins` (composer 2.11.1, phar con SHA-256 publicado verificado; sin instalar nada) | 13 paquetes, 46 avisos: 2 críticos, 15 altos, 22 medios, 6 bajos, 1 sin severidad (H-1) |
| `npm audit --omit=dev` (CRM) | 6: 4 altos, 2 moderados, todos `@angular/*` (H-3) |
| Secretos en los ficheros versionados (15 patrones; solo ruta y máscara) | Backend: solo valores sintéticos en `tests/`, fixtures, `docs/` y el exportador (`StudioIntegrationsExport.php:36`, `APP_KEY` 00..1f); entropía comprobada sin imprimir. CRM: un SID sintético en un spec |
| Build de producción del CRM en copia aislada (`ng build --configuration production`) | 195 ficheros, 0 patrones, 0 source maps; solo inyecta `commit/dirty/builtAt` |
| `security:check-secret-exposure --json` (local, sin `.env`) | ok (lo relevante es el servidor; H-4) |
| `permissions:export` + `CrmPermission::byRole()` | 105 permisos. Administrador 86, sin `roles.manage`, `users.manage`, `ultron.publish/rollback/lab_run/canary_authorize/integrations_manage`. `security.manage` = solo IRON GUARD |
| Rutas del Studio | **56** (38 del H5 en producción + 18 del H6), no 94. DTOs: secretos solo como `{present, fp}` (`StudioIntegrationConfig.php:575-578`), teléfono solo 4 dígitos, IP/agente NULL en el libro (`StudioAudit.php:175-180`); centinelas del H6 en verde en la tanda aislada |
| Proveedores | Motor: Chat Completions (`store` por defecto false). Laboratorio: Responses con `store:false`, URL fija `https://api.openai.com` en producción, clave propia. Meta: `graph_base` fijo en config. Twilio: H-7. ElevenLabs: sin llamadas |
| Prompt injection | El motor solo **sella** el commit con la declaración (`UltronCommitService.php:162`): ni reglas S-NNN ni configuración del Studio llegan al prompt de producción. Llegan al laboratorio (actor/juez, `store:false`; salidas con `lab-invisibles`). Conocimiento: borrador hasta aprobación humana, 409 sobre lo aprobado, `asData()` y ahora limpieza de invisibles. Salida: `OutboundContentGuard` (acciones prohibidas, URLs, datos de tarjeta, afirmaciones, precios/descuentos inventados, traspaso no autorizado), `ComposerStyleGuard`, permiso de cobro en el embudo |
| `TrustProxies` | Sin proxies de confianza (nginx → FastCGI, sin CDN): correcto; B7 lo fija con `X-Forwarded-For` falsificado (`tests/Feature/Hardening/TrustedProxiesNetworkTest.php`) |
| `ironbody-migrate` (propuesta OPS7) | Diseño correcto: usuario dedicado sin shell, `setpriv --no-new-privs`, `env -i`, cachés de arranque privadas comprobadas tras arrancar, árbol y padres de root sin escritura ajena, credencial 600 del usuario dedicado, destino fijado por la credencial, `PGOPTIONS` acotado, `config:cache` como `www-data` con `umask 077`. Pendiente: H-5; `.env` escribible por `www-data` solo avisa (debería abortar, N-4b del H6); `find` sin `-L` no mira destinos de enlaces; con el `sudoers` acotado, `sudo env PGOPTIONS=…` se deniega (documentar `sudo /usr/local/bin/ironbody-migrate`) |

**No verificado:** servidor (APP_ENV/APP_DEBUG reales, nginx, permisos del `.env`, scopes de tokens de Meta, PCRE 10.39); la CRM en navegador; E2E. El worktree temporal y la base `ultron_studio_h7_sec7` son locales y desechables.

## 3. Matriz de riesgos residuales

| Riesgo | Prob. | Impacto | Nivel | Control actual | Procedimiento / dueño |
|---|---|---|---|---|---|
| Aviso de dependencia con camino no detectado aquí (H-1, H-3) | Media | Alto | **Medio** | Revisión de alcance de este informe | Actualizar dentro de la mayor, suite + PG + E2E, despliegue autorizado |
| Sesión robada sobrevive al restablecer contraseña (H-2) | Baja | Alto | **Medio** | Desactivar la cuenta corta al instante | Corrección en backend; hasta entonces, runbook: desactivar + reactivar |
| RCE como `www-data` → root en el siguiente despliegue (`ironbody-migrate` actual) | Baja | Crítico | **Medio** | Ninguno técnico hoy | Instalar la propuesta OPS7 tras H-5 (propietario) |
| Invisibles en conocimiento ya aprobado (H-8) | Baja | Medio | Bajo | Aprobación humana | SQL de §5; limpiar en lectura con contrato |
| Invisibles en condiciones de reglas (H-6) | Baja | Bajo | Bajo | Solo personal con `ultron.manage_rules`; sin efecto en el motor | Cambio coordinado backend + CRM + fixture |
| Auth Token maestro de Twilio expuesto (H-7) | Baja | Alto | Bajo-Medio | `.env` 600, caché 600 | API key restringida |
| Fichero parcial de secretos legible (H-4) | Baja | Alto | Bajo | La orden detecta `.env` completos | Ampliar claves |
| Token del CRM en almacenamiento web (XSS) | Baja | Alto | Bajo | Angular escapa; sin `innerHTML` | CSP en nginx; inactividad |
| Homoglifos en la `key` de conocimiento | Baja | Medio | Bajo | Aprobación humana; 409 | Formato `^[a-z0-9._-]+$` con acuerdo de n8n |

## 4. Pendientes del propietario (dependencias externas, no hechos)

1. Autorizar la actualización de dependencias (H-1, H-3) y su despliegue.
2. Instalar `ironbody-migrate` sin root (tras H-5), con `--check` y `--pretend` antes de sustituir.
3. Twilio: API key restringida para Verify/SMS y rotar el Auth Token (H-7).
4. En el servidor: `php artisan about --only=environment` (`production`, debug apagado); `nginx -T | grep -E 'real_ip|set_real_ip_from'` vacío; HSTS y CSP en nginx; `.env` `root:www-data 640`; `security:check-secret-exposure` tras ampliar claves.
5. Meta: scopes mínimos (Ads `ads_read`; WhatsApp `whatsapp_business_messaging`/`_management`), caducidad y rotación del token del usuario de sistema.
6. Decidir H-2 (revocar al restablecer) y la caducidad por inactividad.
7. Tras desplegar, sondear la clase en el PCRE 10.39 del servidor: `php -r 'require "vendor/autoload.php"; var_dump(App\Support\Text\InvisibleCharacters::violation("a\u{00AD}b"), App\Support\Text\InvisibleCharacters::violation("\u{1F468}\u{200D}\u{1F469}"));'` → `"U+00AD"` y `NULL`.

## 5. SQL de solo lectura para el conocimiento guardado (H-8)

Reescrita el 02-11-2026; detalle en el addendum, §7.8.

- **Solo ASCII y escapes de PostgreSQL** (`\uXXXX` y `\UXXXXXXXX`). La versión anterior llevaba los caracteres en claro. Al copiarla, el U+2028 se pegaba como un espacio y el rango pasaba a ser «espacio–U+202E», que casa con casi cualquier texto.
- **Cubre la clase completa:** 264 puntos de código, los 265 de la clase menos NUL. La anterior cubría 170.

```sql
-- SOLO LECTURA. Conocimiento guardado con algun caracter de la clase de
-- `lab-invisibles` (la de PlainText(strict: true) en varias lineas: todo salvo
-- tabulador y saltos; NUL no cabe en un text). Solo ASCII y escapes de
-- PostgreSQL, con standard_conforming_strings = on (el valor por defecto).
-- Marca tambien el ZWJ de un emoji legitimo (falso positivo): revisar a mano.
SELECT id, key, review_status, origin
  FROM marketing_knowledge_items
 WHERE (key || coalesce(title, '') || coalesce(content, ''))
       ~ '[\u0001-\u0008\u000B-\u000C\u000E-\u001F\u007F-\u009F\u00AD\u0600-\u0605\u061C\u06DD\u070F\u0890-\u0891\u08E2\u180E\u200B-\u200F\u2028-\u202E\u2060-\u2064\u2066-\u206F\uFEFF\uFFF9-\uFFFB\U000110BD\U000110CD\U00013430-\U0001343F\U0001BCA0-\U0001BCA3\U0001D173-\U0001D17A\U000E0000-\U000E007F]';
```

## 6. Ficheros de SEC7

`app/Support/Text/InvisibleCharacters.php` (nuevo) · `app/Rules/PlainText.php` · `app/Http/Controllers/Api/Internal/InternalMarketingKnowledgeController.php` · `tests/Unit/Support/Text/InvisibleCharactersTest.php` (nuevo) · `tests/Feature/Security/StudioPlainTextInvisiblesTest.php` (nuevo) · `tests/Feature/Marketing/KnowledgeInvisiblesTest.php` (nuevo) · este informe.

## 7. Addendum (02-11-2026): M-1 y m-3 de REV7

**Encargo del lead:** cerrar M-1 con la opción (b), acotar la clase nueva al Studio, y el menor m-3. Base `3fbb155`. **Sin commit.** No toca el motor, `bootstrap/app.php`, `routes/console.php`, `UntrustedText` ni `InvisibleCharacters`.

### 7.1 M-1 · `PlainText` en dos modos

`new PlainText(multiline: false, strict: false)` (`app/Rules/PlainText.php`):

- **Por defecto: la lista de antes del H7, tal cual.** Llama a `UntrustedText::isPlain()`, como en producción `1e5f9a0`, y falla con el mensaje de siempre (`MESSAGE_INVALID`, «El texto contiene caracteres no permitidos.»).
  - Rechaza controles C0, DEL y C1; U+200B–200F (**todo ZWJ**, también dentro de un emoji); U+202A–202E; U+2066–2069; y BOM.
  - En una línea rechaza además `\t`, `\n` y `\r`.
  - Nulo pasa. Un valor que no es texto o el UTF-8 inválido fallan con el mismo mensaje.
- **`strict: true`: la clase completa de §1** (`InvisibleCharacters`, la de `lab-invisibles`). El ZWJ solo pasa dentro de un emoji, y el 422 nombra el carácter (`message()`). Es exactamente lo que SEC7 dejó.
- **Por qué dos modos y no uno:** la agenda y la Caja están en producción, y su CRM tira el mensaje del servidor. El escenario es el de REV7 §M-1: una nota guardada con un guion blando queda sin poder reenviarse, con un mensaje que no es la causa. Ampliar la clase fuera del Studio pide antes su propia decisión: el CRM tendría que enseñar el 422 de `U+` y habría que leer los datos en producción.

### 7.2 Inventario exacto de `PlainText` (34 sitios en el código)

**Studio: `strict: true` en los 19 sitios** (18 en 8 controladores y el normalizador). El noveno controlador, `UltronStudioController`, entra por el normalizador.

| Sitio | Acción | Campo | Líneas |
|---|---|---|---|
| `UltronStudioVersionController.php:82` | `store` (crear versión) | `label` | una |
| `UltronStudioVersionController.php:83` | `store` | `note` | varias |
| `UltronStudioVersionController.php:163` | `approve` | `note` | varias |
| `UltronStudioVersionController.php:182` | `reject` | `reason` | varias |
| `UltronStudioVersionController.php:207` | `publish` | `note` | varias |
| `UltronStudioVersionController.php:232` | `promote` | `note` | varias |
| `UltronStudioVersionController.php:255` | `rollback` | `reason` | varias |
| `UltronStudioPublicationController.php:50` | `legacy` (volver a Legacy) | `reason` | varias |
| `UltronStudioRuleController.php:90` | `store` | `description` | varias |
| `UltronStudioRuleController.php:122` | `update` | `description` | varias |
| `UltronStudioRuleController.php:146` | `archive` | `reason` | varias |
| `UltronStudioRuleController.php:173` | `refsRules` (alta y edición) | `refs.knowledgeKeys.*` | una |
| `UltronStudioCanaryController.php:67` | `store` | `alias` | una |
| `UltronStudioCanaryController.php:133` | `reasonRules` (`deactivate`, `revoke`) | `reason` | varias |
| `UltronStudioCampaignLinkController.php:110` | `deactivate` | `reason` | varias |
| `UltronStudioIntegrationController.php:94` | `acknowledge` | `note` | varias |
| `UltronStudioLabController.php:164` | `verdict` | `note` | varias |
| `UltronStudioLabScenarioController.php:97` | `archive` | `reason` | varias |
| `StudioDraftNormalizer.php:422` (`plain()`, desde `:247` y `:302`) | guardar el borrador (`saveDraft` → `StudioDrafts`) y crear una versión (`StudioVersions`) | parámetros `text`, `textarea` y `tags` | según el tipo |

**Fuera del Studio: por defecto en los 15 sitios.** Igual que en producción `1e5f9a0`: `git diff 1e5f9a0 HEAD` no cambia ninguna de esas reglas.

| Sitio | Acción | Campo | Líneas |
|---|---|---|---|
| `ValuationController.php:98` | `index` | `status` (filtro) | una |
| `ValuationController.php:187` | `store` | `notes` | varias |
| `ValuationController.php:189` | `store` | `past_reason` | varias |
| `ValuationController.php:208` | `update` (tipo y notas) | `notes` | varias |
| `ValuationController.php:224` | `reschedule` | `reason` | varias |
| `ValuationController.php:237` | `cancel` | `reason` | varias |
| `ValuationController.php:336` | `searchMembers` | `q` | una |
| `ValuationController.php:405` | `transicion` (confirmar y demás) | `note` | varias |
| `ValuationAvailabilityController.php:110` | `storeBlock` | `reason` | una |
| `ReceiptStationController.php:79` | `store` | `name` | una |
| `ReceiptStationController.php:113` | `update` | `name` | una |
| `ReceiptStationController.php:115` | `update` | `printer_name` | una |
| `ReceiptAgentController.php:78` | `redeem` | `agent.version` | una |
| `ReceiptAgentController.php:80` | `redeem` | `agent.printer_name` | una |
| `ReceiptAgentController.php:118` | `report` | `printer_name` | una |

**Sin `PlainText`, sin cambios:**

| Sitio | Qué hace |
|---|---|
| API interna de conocimiento (`InternalMarketingKnowledgeController`) | Limpia con `InvisibleCharacters::clean()`, como la dejó SEC7 |
| Frases y etiquetas de `conditions` (`StudioRuleCoherence`) | `UntrustedText::isPlain` (H-6) |
| Texto del juez (`StudioLabPrompts`) | H-6 |
| Escenarios del laboratorio | `StudioLabPersonalData` |
| Importador de valoraciones | `UntrustedText::isPlain` |
| `message` del servicio local | `sanitize()`, sin validar |

**Guarda:** `StudioPlainTextInvisiblesTest::test_todas_las_entradas_del_studio_usan_el_modo_estricto`.
- Lee los tokens de `Crm/UltronStudio`, `Services/UltronStudio` y `Commands/UltronStudio`.
- Exige `strict: true` en cada `new PlainText`, y que el rastreo vea al menos 19.

### 7.3 Nota para el runbook y la guía (DOC7)

**En el Studio**, en todas sus pantallas (borrador, versiones, volver a Legacy, reglas, canarios, asociaciones de campañas, integraciones y laboratorio):
- **Qué se rechaza:** con un 422 y un mensaje en el campo que nombra el carácter («…carácter invisible o de control (U+00AD)… escríbelo de nuevo sin pegarlo»):
  - el guion blando U+00AD;
  - U+2028 y U+2029, también en las notas de varias líneas;
  - U+2060–2064, U+206A–206F, U+061C, U+180E, U+0600–0605, U+06DD, U+070F, U+0890–0891, U+08E2 y U+FFF9–FFFB;
  - las etiquetas U+E0000–E007F;
  - y lo de siempre: controles, anchura cero, bidi y BOM.
- **Qué se acepta:** un emoji compuesto con ZWJ.
- **Borradores de antes del H7:** si llevan uno de esos caracteres, se rechazan al volver a guardarlos o al crear la versión. Se ve en el campo, y se arregla reescribiendo el texto.
- **Versiones ya creadas:** no se bloquean, porque enviar, aprobar, promover y publicar no revalidan el contenido.

**Fuera del Studio NO cambia nada respecto a producción:**
- Son la agenda de valoraciones y la Caja: equipos de impresión y servicio local.
- Valen la misma lista y el mismo mensaje («El texto contiene caracteres no permitidos.»).
- U+00AD, U+2028 y los demás se siguen aceptando como antes del H7.
- Un emoji con ZWJ se sigue rechazando, como antes.

**La lectura en producción de REV7 §M-1 ya está hecha.**
- El lead la ejecutó en producción, en solo lectura y con escapes, no con los caracteres en claro del informe del reviewer.
- **Resultado: 0 filas afectadas** en valoraciones (`valuation_appointments.notes`), borradores (`ultron_studio_drafts.content`), reglas (`ultron_studio_rules.description`) y estaciones (`receipt_stations`).
- Valoraciones y estaciones, con la opción (b), ya no se revalidan con la clase nueva.
- En el Studio, ningún borrador ni regla guardados chocará con el modo estricto al volver a guardarse.

### 7.4 m-3 · `UltronEngineIsolationTest`: el programador ya no entra entero

- **`routes/console.php` sale de `ALLOWED`.**
- **Excepción por cadena**, como la del lector del motor (`SCHEDULER_REFERENCES`): lo único que puede nombrar del Studio es `'ultron_studio.monitor.enabled'`, una vez. Es la propuesta de DB7 (`informe-db.md:284`).
- **Prueba nueva:** `test_la_bandera_del_monitor_solo_programa_ordenes_del_studio`. Exige:
  - que la bandera sea la condición de un `if` con bloque y sin `else`;
  - que cada sentencia del bloque sea `Schedule::command('ultron-studio:…')` con métodos encadenados de argumentos literales (`SCHEDULER_STATEMENT`).
- **Por qué hace falta la prueba nueva:** la excepción por cadena sola no caza un trabajo del motor metido DENTRO del bloque de la bandera (M3b y M3e).
- **Resultado:** con el `routes/console.php` actual, 7 → **8 pruebas en verde** (1123 aserciones).

### 7.5 Lo verificado

Todo en el Mac: PHP 8.3.33, PHPUnit 11.5.55 y PostgreSQL 14.24 desechable (`UNIT_DB=ultron_studio_h7_sec`).

| Comprobación | Resultado |
|---|---|
| `PlainTextOutsideStudioTest` (nueva, SQLite) | **42 pruebas OK**, contando los casos de los proveedores. Barre todo Unicode frente a la lista de antes escrita a mano: rechaza **80 puntos en una línea y 77 en varias**, sin ninguna discrepancia. Por HTTP: valoraciones (alta, cambio de tipo reenviando la nota con U+00AD y U+2028, cancelación, bloqueo y búsqueda) y Caja (alta, ajuste, canje y reporte del servicio local) aceptan lo de antes, y rechazan lo de la lista de antes con el mensaje de siempre |
| `tests/Feature/Security` (SQLite) | **84 OK** (391 aserciones), con `StudioPlainTextInvisiblesTest` en **12 OK** (la guarda nueva incluida) |
| `tests/Feature/Valuations` (SQLite) | **117 OK** (1 919) |
| `tests/Feature/Caja` (SQLite) | **680 OK** (12 293) |
| `KnowledgeInvisiblesTest` (SQLite) | **7 OK** (45) |
| `tests/Unit/Support/Text` (SQLite) | **91 OK** (554), las mismas cifras de §2: `InvisibleCharacters` sin cambios |
| `tests/Unit/UltronStudio` (SQLite) | **1 425 OK** (11 969) |
| `tests/Feature/UltronStudio` (SQLite) | Primera corrida: 1 086, **1 fallo ajeno** y 106 omitidas (solo PG). Segunda corrida: **1 086 OK** (14 888 aserciones), 106 omitidas y 0 fallos |
| PG: las dos de Security, `KnowledgeInvisiblesTest` y `UltronEngineIsolationTest` | **69 OK** (1 433). Con base real: 226 tablas y la migración `2026_11_02_100001` |
| PG: `ReceiptStationsTest`, `ReceiptAgentSecurityTest` y `ReceiptAgentFlowTest` | **63 OK** (1 409) |
| PG: `tests/Feature/Valuations` | **116/117.** El fallo es la precondición de entorno de `ValuationOverlapGuardMigrationTest:112`, «corre con un rol NO superusuario»: el rol local `ironbody` tiene `rolsuper = t`. No depende del código |
| Pint (`--test`) | **passed** en los 14 PHP tocados |

**El fallo ajeno de la primera corrida** es `UltronStudioIntegrationsProbeTest::test_el_enfriamiento_reutiliza_la_ultima…:129`:
- La prueba espera 200 y recibe 201.
- Depende del reloj: hace `travel(299)` sobre un enfriamiento de 300 s, y con la máquina cargada (2:20 de suite) el reloj real avanza ≥ 1 s.
- Sola pasa 3/3.
- La sonda solo valida `clientRequestId`, sin `PlainText`.
- Va para `tester`; no lo toco.

### 7.6 Mutaciones

Se aplicaron en una copia del `backend`, en el scratchpad, sin tocar el árbol compartido. Cada fichero se restauró y se comprobó su SHA-256.

**M-1: caen 7 de 7.**

| # | Mutación | Pruebas | Resultado |
|---|---|---|---|
| M1a | `PlainText` vuelve a la clase completa por defecto (`strict = true`) | las dos de Security | **Cae: 42 fallos**, en las 9 pruebas de `PlainTextOutsideStudioTest` |
| M1b | `UntrustedText::INVISIBLE` gana U+00AD (ensancha la lista de antes) | la nueva | **Cae: 4** (el barrido, el caso U+00AD, valoraciones y Caja por HTTP) |
| M1c | `UntrustedText::INVISIBLE` pierde U+FEFF (la estrecha) | la nueva | **Cae: 3** |
| M1d | Por defecto, el mensaje con `U+XXXX` en vez del de siempre | la nueva | **Cae: 20** |
| M1e | Volver a Legacy pierde `strict` | Studio | **Cae: 8** (los 7 casos de AS-5 y la guarda) |
| M1f | El normalizador del borrador pierde `strict` | Studio | **Cae: 2** (borrador y guarda) |
| M1g | `strict` usa la lista de antes | las dos | **Cae: 30** |

**m-3: caen 5 de 5 con la prueba nueva y 0 de 5 con la de HEAD.** Los dos controles legítimos pasan con las dos.

| # | Mutación en `routes/console.php` | Prueba nueva | Prueba de HEAD |
|---|---|---|---|
| M3a | `if (config('ultron_studio.publishing_enabled'))` con `marketing:dispatch-followups` | **Cae** (rastreo global) | Pasa |
| M3b | `Schedule::job(new SyncMetaAdsInsights)` DENTRO del bloque de la bandera | **Cae** (bloque) | Pasa |
| M3c | `else` con una orden del motor | **Cae** (bloque) | Pasa |
| M3d | La misma bandera en un `->when()` del motor | **Cae** (rastreo global) | Pasa |
| M3e | `marketing:dispatch-followups` DENTRO del bloque | **Cae** (bloque) | Pasa |
| M3f | Control: otra orden `ultron-studio:` dentro del bloque | Pasa | Pasa |
| M3g | Control: tarea del motor sin condición del Studio | Pasa | Pasa |

### 7.7 Ficheros tocados en este addendum, y lo no verificado

**Código:**
- `app/Rules/PlainText.php`;
- los 8 controladores de `app/Http/Controllers/Crm/UltronStudio/` que usan `PlainText`: Version, Publication, Rule, Canary, CampaignLink, Integration, Lab y LabScenario. Solo añaden `strict: true`;
- `app/Services/UltronStudio/StudioDraftNormalizer.php`: la llamada y su comentario.

**Pruebas:**
- `tests/Feature/Security/PlainTextOutsideStudioTest.php` (nueva);
- `tests/Feature/Security/StudioPlainTextInvisiblesTest.php`: la regla con `strict: true` y la guarda;
- `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` (m-3);
- `tests/Unit/Support/Text/InvisibleCharactersTest.php`: solo dos comentarios que nombraban `PlainText` sin el modo.

**Informe:** este.

**Segunda corrida de `tests/Feature/UltronStudio`:** 1 086 OK, 0 fallos y 106 omitidas (solo PG), en 1:40. El fallo de reloj no se repitió.

**No verificado:**
- El CRM en un navegador: el CRM no cambia.
- El PCRE 10.39 de producción. El modo por defecto solo usa rangos explícitos, igual que antes. El estricto sigue con la sonda del paso 9 del runbook (§4, punto 7).
- La suite entera y el E2E: son de la fase C del tester.

### 7.8 H-8 · La lectura del conocimiento, reescrita con escapes

Lo detectó el reviewer: la consulta de §5 llevaba en claro, dentro del corchete, U+00AD, U+0600–0605, U+061C, U+06DD, U+070F, U+180E, U+200B–200F, U+2028–202E, U+2060–2064, U+2066–206F, U+FEFF y U+FFF9–FFFB. Solo las etiquetas iban escapadas (`\U000E0000-\U000E007F`).

**Causa.** Al escribir el informe, los escapes `\u` de cuatro cifras quedaron convertidos en caracteres literales. Los `\U` de ocho cifras sobrevivieron, y por eso las etiquetas sí estaban escapadas. Lo reproduje hoy: un fichero SQL escrito con escapes `\u` de U+00AD en adelante acabó con los caracteres en claro.

**Cómo se generó la versión nueva:**
- un guion construye los escapes desde los puntos de código, como enteros;
- los puntos salen del barrido de `InvisibleCharacters::INVISIBLE_CLASS` (PCRE 10.47: 265 puntos), menos NUL;
- antes de pegarla en el informe se comprobó, byte a byte, que todo es ASCII.

**Verificado en PostgreSQL 14.24** (`ultron_studio_h7_sec`, UTF8, `standard_conforming_strings = on`). Los textos de prueba se construyen con `chr()`, sin escapes ni caracteres literales.

| Comprobación | Resultado |
|---|---|
| Un espacio, U+00A0 y «Juan Pérez, 10:00 a. m.» (en ASCII, y con U+202F y U+00A0 como en Intl) | **No casan.** Tampoco tabulador ni saltos, el corazón con U+FE0F, la raya ni los puntos suspensivos |
| U+00AD, U+2028 y U+E0041 | **Casan.** También U+200B, U+FEFF, U+2060, U+180E, U+0890, U+08E2, U+1D173, U+0085 y U+001B, y la familia con ZWJ (el falso positivo documentado). En total, 19 de 19 casos como se esperaba |
| Barrido de 1 112 063 puntos de código (sin NUL ni sustitutos) | Casa con **264**: exactamente la clase de PHP menos U+0000, sin diferencias en ninguna dirección |
| El mismo barrido con 5 intercalaciones (por defecto `C.UTF-8`, `C`, `es-CO-x-icu`, `en_US.UTF-8` y `und-x-icu`) | Las mismas 264, con 0 discrepancias. Los tramos van por punto de código, no por intercalación |
| La consulta vieja, escapada tal cual | 170 puntos. Le faltaban los controles C0 y C1, U+0890–0891, U+08E2 y los `Cf` suplementarios (U+110BD, U+110CD, U+13430–1343F, U+1BCA0–1BCA3 y U+1D173–1D17A) |
| La consulta vieja copiada con el U+2028 convertido en espacio (lo que describe el reviewer) | Casa con **8 354** puntos: el espacio, U+00A0 y «Juan Pérez» incluidos |
| La consulta nueva sobre `marketing_knowledge_items`, con 5 filas de prueba en una transacción que se deshace | Devuelve las 4 sucias (guion blando, U+2028, etiqueta y ZWJ). La limpia, con tilde, U+202F y U+00A0, no sale |
| El informe entero frente a la clase (los 265 puntos y el ZWJ, salvo tabulador y saltos) | **0 caracteres de la clase en claro** |

**Sin hacer:** la lectura de H-8 en producción. Sigue siendo del propietario (§4).

**Mismo problema en otro informe:** `informe-reviewer.md:81` (la SQL de M-1) lleva también los caracteres en claro. El lead ejecutó su equivalente con escapes; si alguien la copia del informe, tendrá el mismo fallo. El fichero no es mío y no lo toco.
