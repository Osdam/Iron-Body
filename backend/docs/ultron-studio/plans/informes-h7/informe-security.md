# ULTRON STUDIO · Hito 7 · SEC7 · Seguridad transversal (`security`)

**Fecha:** 01-11-2026. **Base:** `feat/ultron-studio-h7` en `f78adfc` (CRM `f38699d`, solo lectura). **Sin commit.** Rutas relativas a `backend/`.
**Árbol compartido:** DB7, B7 y OPS7 escribían a la vez; para atribuir resultados, las suites se repitieron en un worktree temporal **HEAD + solo los ficheros de SEC7**.

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

```sql
-- Marca también el ZWJ de un emoji legítimo (falso positivo): revisar a mano.
SELECT id, key, review_status, origin
  FROM marketing_knowledge_items
 WHERE (key || coalesce(title, '') || coalesce(content, ''))
       ~ '[­؀-؅؜۝܏᠎​-‏ -‮⁠-⁤⁦-⁯﻿￹-￻\U000E0000-\U000E007F]';
```

## 6. Ficheros de SEC7

`app/Support/Text/InvisibleCharacters.php` (nuevo) · `app/Rules/PlainText.php` · `app/Http/Controllers/Api/Internal/InternalMarketingKnowledgeController.php` · `tests/Unit/Support/Text/InvisibleCharactersTest.php` (nuevo) · `tests/Feature/Security/StudioPlainTextInvisiblesTest.php` (nuevo) · `tests/Feature/Marketing/KnowledgeInvisiblesTest.php` (nuevo) · este informe.
