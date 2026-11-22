# Primeras plantillas de WhatsApp del motor comercial: propuesta para aprobar (22-11-2026)

Árbol leído y ejecutado: `_worktrees/cierre-bandeja/Iron-Body` en `15a2f9e5`, sin cambios (`git status` vacío). Sin red, sin producción.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o en los documentos · **[S]** supuesto.
Guion: `validar.php` + `plantillas.php`. Salida completa: `salida.txt` (los tres, en esta misma carpeta).

## 1. Los textos (para leer tal cual)

Las cinco son **comerciales**: categoría `marketing`, idioma `es_CO`, solo cuerpo (sin encabezado, pie ni botones).
Los `{{n}}` los rellena el sistema al enviar. Ninguna lleva precios, descuentos, enlaces, planes, horarios ni promesas.

**A · Prospecto que preguntó y no volvió**, `seguimiento_prospecto_v1`. {{1}} = nombre de pila (ej. *Laura*), {{2}} = nombre del gimnasio (ej. *Iron Body*).
```
Hola, {{1}}. Le escribimos de {{2}} para retomar la conversación que tuvimos por WhatsApp. ¿Le sigue interesando entrenar con nosotros? Si tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.

Si no desea recibir más mensajes, responda BAJA.
```
**B · Membresía por vencer**, `membresia_por_vencer_v1`. {{1}} nombre, {{2}} gimnasio, {{3}} fecha de fin (ej. *15 de diciembre de 2026*).
```
Hola, {{1}}. Le recordamos que su membresía en {{2}} vence el {{3}}. Si desea renovarla o tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.

Si no desea recibir más mensajes, responda BAJA.
```
**C · Membresía vencida hace pocos días**, `membresia_vencida_v1`. {{1}} nombre, {{2}} gimnasio, {{3}} fecha de fin (ej. *15 de noviembre de 2026*).
```
Hola, {{1}}. Su membresía en {{2}} terminó el {{3}}. Si desea retomar sus entrenamientos, responda este mensaje y le indicamos cómo renovarla.

Si no desea recibir más mensajes, responda BAJA.
```
**D · Socio que dejó de asistir**, `socio_inactivo_v1`. {{1}} nombre, {{2}} gimnasio.
```
Hola, {{1}}. En {{2}} hace un tiempo no le vemos por el gimnasio y queremos saber cómo está. Si desea retomar su rutina o necesita ayuda para organizarse, responda este mensaje y con gusto le ayudamos.

Si no desea recibir más mensajes, responda BAJA.
```
**E · Valoración física desactualizada** (opcional, la quinta), `valoracion_pendiente_v1`. {{1}} nombre, {{2}} gimnasio.
```
Hola, {{1}}. En {{2}} vemos que ya pasó un tiempo desde su última valoración física. Si desea agendar una nueva para revisar su progreso, responda este mensaje y le ayudamos a programarla.

Si no desea recibir más mensajes, responda BAJA.
```

| | Clave en el Studio | Disparador del catálogo (sujeto) | Parámetro de la estrategia (sugerencia [S]) | Lo que la frase da por cierto, y de dónde sale [L] |
|---|---|---|---|---|
| A | `seguimiento-prospecto` | `lead.followup_due` (prospecto) | sin parámetros | Hay conversación de WhatsApp: W9 solo escribe a un prospecto que la tiene (`WhatsappRecipient::leadFor`) |
| B | `membresia-por-vencer` | `membership.expiring_window` (socio) | `daysBefore` = 5 | La fecha es la de `users.membership_end_date`. Congelada: no sale (`WhatsappRecipient.php:96`) |
| C | `membresia-vencida` | `membership.expired_window` (socio) | `daysAfterMin` = 1, `daysAfterMax` = 7 | Ídem |
| D | `socio-inactivo` | `member.inactive` (socio) | `days` = 14 | La última entrada fue hace ≥ N días (`TriggerPrefilters`) |
| E | `valoracion-pendiente` | `valuation.outdated` (socio) | `days` = 90 | La última valoración fue hace ≥ N días |

- **[C]** Los cinco disparadores son `periodic`, `triggerable`, `available`, con finalidad `commercial` y con prefiltro en el barrido. Ninguno está en `EVENTS_WITHOUT_EMITTER` (`OutreachCatalog.php:207`, `:501-509`).
- **Nota de estrategia [S]:** D y E pueden alcanzar a socios con la membresía vencida. Si no se quiere, la estrategia debe exigir `membership.active = true`. En C, conviene exigir que siga inactiva al enviar, por si renovó entre el barrido y el envío.

## 2. Las reglas del validador, en claro [L, `StudioMessageTemplates.php`; C con el guion]

| Regla | Qué exige | Código si falla |
|---|---|---|
| Claves | Obligatorias: `metaName`, `language`, `category`, `bodyPreview`, `variables` y `optOutLine`. Opcionales: `header`, `footer`, `buttons` y `examples` (:941) | `missing_key` / `unexpected_key` |
| Nombre en Meta | `^[a-z0-9_]{1,64}$` (:115). Clave del Studio: `^[a-z0-9][a-z0-9_-]{2,47}$` | `invalid_format` |
| Idioma | `es` o `es_CO` (:75). **Se propone `es_CO`**, como el ejemplo del plan §5.1 | `value_not_in_domain` |
| Categoría ↔ finalidad | Comercial → `marketing`; cobranza y servicio → `utility` (:84-88). AUTHENTICATION no existe | `category_purpose_mismatch` |
| Cuerpo | ≤ 1 024 caracteres; texto plano estricto (sin invisibles; admite saltos de línea y emojis); sin teléfonos ni correos; sin blancos al principio ni al final; no puede empezar ni terminar con `{{n}}`; los `{{n}}` van seguidos, de 1 a n, y cuadran con `variables` (:985-1004) | `too_long`, `plain_text`, `contact_data`, `leading_trailing_spaces`, `meta_dangling_parameter`, `placeholders_mismatch` |
| Marcadores | Como mucho 10, sin repetir, de la lista cerrada (:96). **W9 solo resuelve** `first_name`, `gym_name`, `plan_name`, `membership_end_date_text` y `days_to_expiry`; con los demás no envía nada (`template_marker_unresolvable`, `WhatsappRecipient.php:41`) | `value_not_in_domain`, `duplicate_value` |
| R-01 importes | Ni `$`/`€` con cifra, ni cifras con separador de miles, ni «pesos», «COP», «USD», «mil». El precio, solo con `plan_price`, y W9 aún no lo resuelve | `restriction_violated` |
| R-02 descuentos | Ni «gratis», «2x1», «precio especial», «descuento», «rebaja», «N %» ni «dcto» | ídem |
| R-03 URL / tarjeta | Ni `http(s)://`, ni `www.`, ni dominios `.com`, `.co`, … ni números de 13 a 19 cifras | ídem |
| R-06 promesas | Ni «garantiz…», «te prometo», «prometemos», «resultados asegurados» ni «100 % seguro» | ídem |
| Línea de baja | `optOutLine: true` (:959-961). **El código no fija el texto ni lo busca en el cuerpo** (ver §3) | `opt_out_required` |
| Ejemplos | Uno por variable (ni más ni menos); 1–40 caracteres; sin `#`, `$`, `%` ni llaves; sin 4 espacios seguidos (:1260-1332). Sin ellos no se puede enviar a Meta | `meta_examples_required` (al enviar) |
| Aviso de Meta (no bloquea) | Más de una variable por cada 4 palabras (:225) | `meta_variable_density` |
| Respuesta de baja | Las 27 frases cerradas de `WhatsappSignals::OPT_OUT_PHRASES` (:72), con el mensaje entero. «BAJA» y «Baja, gracias» son baja segura [C]. «No me interesa» no lo es [C] | — |

## 3. Comprobación con el validador real [C]

`php validar.php <backend>` arranca la aplicación con `APP_ENV=testing`, SQLite en memoria y `Http::preventStrayRequests()`. Por cada plantilla pasa (1) las reglas del controlador `store`, (2) `content()` (lo que llama `create()`), (3) `metaWarnings()` y `metaPayload()`, (4) `TemplateRequestGuard::payload()` (el cuerpo del cliente de Graph) y (5) la composición de W9 con valores realistas, más R-xx y `OutreachTemplateMessage`. No cubre la sesión, la persona vinculada ni la escritura en la base.

| Caso | Resultado |
|---|---|
| A | VÁLIDA · 258/1024 · `metaWarnings=[]` · W9 compone, R-xx=[] · hash `273467ab…` |
| B | VÁLIDA · 209/1024 · `metaWarnings=[]` · W9 compone, R-xx=[] · hash `50d6db76…` |
| C | VÁLIDA · 192/1024 · `metaWarnings=[]` · W9 compone, R-xx=[] · hash `f029a551…` |
| D | VÁLIDA · 251/1024 · `metaWarnings=[]` · W9 compone, R-xx=[] · hash `4c47eb45…` |
| E | VÁLIDA · 238/1024 · `metaWarnings=[]` · W9 compone, R-xx=[] · hash `73292a29…` |
| MALA 1: «Renueve hoy por $120.000…» | RECHAZADA, 422 `template_invalid`, `content.bodyPreview: restriction_violated` (R-01) |
| MALA 2a: sin línea de baja y sin `optOutLine` | RECHAZADA, 422, `content.optOutLine: missing_key` |
| MALA 2b: sin línea de baja, `optOutLine: false` | RECHAZADA, 422, `content.optOutLine: opt_out_required` |
| **MALA 2c: sin línea de baja, `optOutLine: true`** | **ACEPTADA** (hash `a0dd5401…`): pasa `content()`, la carga y W9 |
| MALA 3: comercial con `utility` | RECHAZADA, 422, `content.category: category_purpose_mismatch` |

**Hallazgo (2c).** El validador comprueba la casilla, no el texto. Una plantilla sin forma de darse de baja pasa si alguien marca `optOutLine: true`. Hoy solo la detiene la revisora a cuatro ojos. El contrato (D9-6, D9-15) dice «el texto dice cómo dejar de recibir mensajes». No lo he corregido: queda fuera del alcance y lo decide el lead.

**Antes y después.** El validador no me obligó a cambiar ningún texto. Cambié B y C por lo que hace W9 al enviar ([C] con el guion):
- **Antes:** «…su {{2}} vence en {{3}} días…», con `plan_name` y `days_to_expiry`. Es VÁLIDA, pero W9 compone «su MENSUALIDAD vence en 0 días» y «vence en 1 días».
- Con un plan llamado «Promo 2x1», el texto compuesto incumple R-02 y no sale (`template_violates_restriction`).
- `days_to_expiry` vale null con la membresía vencida [L, `WhatsappRecipient.php:106`]: C no saldría nunca.
- **Después:** «su membresía en {{2}} vence el {{3}}», con la fecha («15 de diciembre de 2026») y sin el nombre del plan, que en la base es texto libre.

## 4. La carga exacta hacia Meta [C], y lo que Meta suele rechazar

`POST /{WABA}/message_templates`. Ejemplo de B: 436 bytes, `request_hash` `f089526d…`. Las cinco, tal cual, en `salida.txt` (paso 4).
```json
{"name":"membresia_por_vencer_v1","language":"es_CO","category":"MARKETING","parameter_format":"POSITIONAL","components":[{"type":"BODY","text":"Hola, {{1}}. Le recordamos que su membresía en {{2}} vence el {{3}}. Si desea renovarla o tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.\n\nSi no desea recibir más mensajes, responda BAJA.","example":{"body_text":[["Laura","Iron Body","15 de diciembre de 2026"]]}}]}
```
- Lo reconstruye siempre el servidor desde la versión aprobada. El CRM solo dice «envía la versión {uuid}» [L, plan §5.2].
- Va sin `allow_category_change` ni TTL.

**Lo que documenta el plan** [L, `PLANTILLAS_META_PLAN.md` §2.2–§2.4]:
- **Motivos de rechazo:** `ABUSIVE_CONTENT`, `INCORRECT_CATEGORY`, `INVALID_FORMAT` (con su recomendación), `PROMOTIONAL`, `SCAM` y `TAG_CONTENT_MISMATCH`.
- **Reglas de formato:** el cuerpo no puede empezar ni terminar con una variable, ni tener demasiadas para su largo; las variables no llevan `#`, `$` ni `%`; los ejemplos son obligatorios.
- **Duplicados:** se rechaza una plantilla con el mismo cuerpo y pie que otra ya existente. Las cinco son distintas.
- **Revisión:** hasta 24 h.
- **Recategorización:** el plan la documenta solo para UTILITY → MARKETING, y no afecta a estas.

**No consta** en el código ni en el plan qué rechaza Meta en concreto para MARKETING (más allá de esa lista). Tampoco si admite la línea en blanco del cuerpo: supongo que sí [S].

## 5. Lo que falta fuera del código, en orden

1. **Vincular dos personas (P1).** Root, con la orden escrita del propietario: `ultron-studio:person-bindings --bootstrap`. Dos cuentas **personales** con identidades distintas (ni la 27 compartida ni la 38 de QA). Sin vínculos nadie crea, aprueba ni envía (`StudioMessageTemplates.php:688`, `:750`). Ref.: auditoría §3.1 P1 y §4.7.1; `RUNBOOK_H9.md` §1.
2. **«Comprobar permisos»** (Super Admin con vínculo). Esperado: `ok`. Con `missing_permission` o `no_waba_access`, asignar `whatsapp_business_management` en el Administrador de Meta (externo). Ref.: auditoría §4.7.2.
3. **Crear y aprobar.** A crea cada versión (CRM → Automatizaciones → Plantillas → WhatsApp · Comercial) con los textos de §1 y los ejemplos de §3; B, otra persona, la aprueba (`four_eyes_required` por cuenta y por persona). Ref.: auditoría §3.1 P2–P3 y §4.7.3.
4. **Encender el envío** (devops, configuración de producción): `ULTRON_STUDIO_META_TEMPLATES_ENABLED`, `…_SUBMIT_ENABLED` y `META_ENABLED=true`, más `…_SYNC_ENABLED` para la sincronización (`config/ultron_studio.php:376-414`). Apagado por defecto [L]; el valor en producción no lo he comprobado.
5. **«Enviar a Meta»** (Super Admin con `ultron.meta_templates_submit` y vínculo). **Es una escritura real e irreversible**: crea la plantilla en la cuenta de WhatsApp del gimnasio (`CrmPermission.php:333-336`). El servidor vuelve a mirar que esté aprobada e íntegra, que autora ≠ revisora, los ejemplos y el nombre (`StudioMetaTemplates.php:730-760`). Nunca reintenta: si no sabe si llegó, queda `UNKNOWN` y la sincronización la busca. Ref.: auditoría §4.7.4.
6. **Revisión de Meta** (externa, hasta 24 h). La sincronización (cada 5 min) o «Comprobar ahora» deja la fila `meta_api` en `ultron_studio_template_meta_checks`; da fe quien envió, si sigue activo y con vínculo (D-PM-3). Sin fila APPROVED, todo envío falla con `template_not_approved_by_meta`. Ref.: auditoría §4.7.5; runbook §5.1 W1.
7. **La estrategia.** Paso `whatsapp_template` con la plantilla aprobada → validar → congelar (A) → simular → aprobar (B) → publicar en canario (Super Admin, de 07:00 a 19:00 de Bogotá). Ref.: auditoría §3.1 P4–P8.
8. **Audiencia real.** Hoy solo recibe un prospecto con `consent_status = granted` y conversación de WhatsApp. Los socios sin prospecto de WhatsApp salen `no_whatsapp_identity` hasta P9-14 (contrato `:600`, `:905`), lo que afecta a B–E. Para la primera prueba, la más alcanzable es A [S].
9. **El canario y la constante.** Números canarios de WhatsApp activos (fase 2 del H6; runbook W3: «no hecho»), visto bueno legal (P9-22) y precios por mensaje (W4). Después, el commit de una línea `outreach(sends-open):` que abre `OutreachSendGate::SENDS_ENABLED_IN_CODE` (hoy `false`, `:39`), con la orden escrita del propietario, el modo `live` y `canal whatsapp on --cupo=… --presupuesto=…` (runbook §5.1–§5.2). El primer mensaje va a un número canario. El runbook (§5, G-9) dice que W9 está sin desplegar y sin guion de despliegue; puede estar desactualizado y no lo he comprobado.

**Riesgos que conviene conocer [L]:**
- Un nombre de pila que no se pueda leer (con emojis o cifras: `firstName("💪 Juan")` da null [C]) bloquea el envío a esa persona. Falla cerrado.
- Lo que Meta ya aceptó no se recoge.
- Para cambiar un texto aprobado hace falta una versión nueva con otro nombre (`_v2`).
