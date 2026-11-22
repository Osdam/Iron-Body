# Informe del arquitecto · Las diez capas del Studio frente a los tres motores (cierre, 22-11-2026)

**Agente:** `arq-capas` (architect), solo lectura. **Guardado por el lead** tal como lo entregó (el agente no escribe ficheros).
**Nota del lead (22-11-2026):** el punto 1 de §2 está superado: producción sirve `66b14467` con el compilador 4 desde el 22-11 a las 03:19 UTC (Enmiendas A4-2 y A4-3 desplegadas con la autorización del gate 4-H); lo que falta es publicar la versión 3 y la tercera prueba real (gate 4-M).

**Alcance.** He leído el código de la rama `feat/ultron-studio-h9` (backend `dfcee951`, CRM `eeb4b79`). No es producción. Abreviaturas: Cat=`StudioCatalog.php` · Val=`StudioGraphValidator.php` · Ver=`StudioVersions.php` · Decl=`StudioPublicationDeclaration.php` · VC=`StudioVoiceCompiler.php` · VT=`StudioVoiceTemplate.php` · Comp=`Prompts/UltronPromptCompiler.php` · Offer=`Prompts/UltronPromptOffer.php` · Commit=`UltronCommitService.php` · SSV=`StudioStrategyValidator.php` · PL=`Outreach/Core/PolicyLadder.php` · H9§5=`ULTRON_STUDIO_H9_CONTRACT.md:921-932`.

**Cuándo APLICA en WhatsApp [C].** Solo en la conversación canaria, con la llave de prompts en `canary`, `studio_config` distinto de `off`, la publicación firmada en `/2`, la misma base y el mismo compilador, y la huella recalculada igual a la firmada (Offer:121-171). En cualquier otro caso es Legacy y no cambia ni un byte (`UltronA4LongitudYPreciosTest:799`).

## 1. Tabla capa × parámetro × motor

| Capa · parámetro | WhatsApp | Voz | Automatización H9 |
|---|---|---|---|
| knowledge.sources | SIN EFECTO [C]: si difiere de la línea base va a la lista «sin efecto» que hay que reconocer (Decl:279-286); el CRM lo dice (`studio-publication-text.ts:257`) | SIN EFECTO [C]: VC no lo lee (:375-421) | NO CORRESPONDE [C]: N3 solo lee dos claves (H9 contrato :765, SSV:143-178) |
| knowledge.strictFacts | CERROJO [C]: con `false` no se puede ni crear la versión (Cat:522 → Val:135 → Ver:140) | CERROJO [C]: 422 `version_facts_unverified` (VC:397); prueba `StudioVoiceCompilerTest:476` | CERROJO heredado [C]: ninguna versión referenciable lo tiene en `false` (Ver:140, SSV:133); R-01 la aplica el código sobre el texto de la plantilla (`StudioMessageTemplates.php:282`) |
| persona.tone | APLICA [C]: parches en Composer ×2 y Critic ×2 (Comp:626-637). Con «profesional» el commit además comprueba el trato de usted (`sp.t` Offer:210-213 → Commit:5486-5494, :1714-1720). «Entusiasta» solo va al prompt. Pruebas: `UltronPromptCompilerTest:218,:234`; `UltronA4RegistroYPanoramaTest:183,:242,:319` | APLICA [C]: «CÓMO HABLAS» (VC:409-420 → VT:210-214, :484). El tú o usted lo decide el perfil, no el tono. Prueba `StudioVoiceCompilerTest:514` | NO CORRESPONDE [C]: el tono es el de la plantilla aprobada (H9§5 :924) |
| persona.emojis | APLICA [C], solo en el prompt: con `false` se reemplaza la línea del Composer y el Critic falla el borrador (Comp:639-643). El commit no lo mira: `ComposerStyleGuard.php:452` solo anota más de 2. Prueba `UltronPromptCompilerTest:249` | NO CORRESPONDE: llamada hablada (VC no lo lee) [C] | NO CORRESPONDE [C] |
| persona.maxChars | APLICA [C]: va al prompt (Comp:645-663) y el commit lo cumple (`sp.n` Offer:198-201 → Commit:5477-5485, :1662-1671: quita la pregunta final o descarta). Pruebas: `UltronA4LongitudYPreciosTest:160,:386,:454`; `UltronPromptCompilerTest:288` | NO CORRESPONDE [C]: no hay mensajes; la duración la fija el perfil | NO CORRESPONDE [C] |
| intent.driver | CERROJO [C]: `must_match`, publicar un valor distinto del entorno da 409 `engine_value_mismatch` (Decl:257-266); «hermes» bloquea (Cat:526); el sello solo lo anota (`UltronPublishedConfig.php:20-21`). Prueba: `publication-cases.json` vía `StudioPublicationDeclarationTest` | NO CORRESPONDE [C]: el modelo es el del agente del proveedor | NO CORRESPONDE [C]: en H9 la intención es el clasificador AI9 (H9§5 :925) |
| intent.fallback | SIN EFECTO [C] por decisión M1-2 (H5 :402). El CRM lo dice con verdad (`studio-engine-text.ts:146-152`) | APLICA [C]: VC:412-419 → VT:224, :485; prueba `StudioVoiceCompilerTest:514` | NO CORRESPONDE [C] |
| context.windowTurns | SIN EFECTO [C]: fijo en 10; otro valor va a «sin efecto» (Decl:269-275); la pista lo dice (Cat:620) | NO CORRESPONDE [H]: el contexto es la llamada entera | NO CORRESPONDE [C] |
| memory.useLeadProfile | SIN EFECTO [C]: fijo en `true` (Decl:269-275) | SIN EFECTO [C]: por diseño la llamada no lleva datos de la persona (D8-11, según el informe de voz §1.b) | NO CORRESPONDE [C] |
| strategy.goal | APLICA [C], solo en el prompt del Strategist (Comp:665-670); el commit no lo mira. Pruebas `UltronPromptCompilerTest:266`, `UltronPromptOfferEngineTest:62` | APLICA [C]: «TU OBJETIVO» (VC:409-420 → VT:217-221, :485); prueba :514 | NO CORRESPONDE [C]: el objetivo es el de la estrategia (H9§5 :928) |
| strategy.offerPriority | SIN EFECTO [C]: `engine_data`, no se compara con el motor (Decl:279-286, Cat:704) | SIN EFECTO [C]: `consultar_planes` da los vendibles (informe de voz §1.b) | NO CORRESPONDE [C] |
| strategy.discountsAllowed | CERROJO [C]: `true` bloquea (Cat:523, Val:135, Ver:140); R-02 actúa siempre en el commit | CERROJO [C] (VC:398) | CERROJO heredado [C], más R-02 sobre la plantilla (SSV:260) |
| rules.handoffOnlyCorroborated | CERROJO [C] (Cat:525, Ver:140) | CERROJO [C] (VC:400) | CERROJO heredado [C] |
| rules.paymentLinksEnabled | CERROJO [C]: `must_match` (Decl:257-266); `true` sin E14/E16 bloquea (Cat:529) | CERROJO [C]: `true` da 422 (VC:399) | APLICA, solo para negar [C]: con `false`, `payment_links_disabled` al validar (SSV:264) y al decidir (PL:336-338). Con `true` tampoco sale nada: W9 no resuelve `payment_link` y falla cerrado (`WhatsappRecipient.php:26-27,:41`; `WhatsappTemplateChannel.php:281`). Pruebas `UltronStudioStrategyValidationTest:143`, `WhatsappTemplateChannelTest:353` |
| composer.critic | CERROJO [C]: `false` bloquea (Cat:524) | NO CORRESPONDE [C]: la voz no tiene Critic | NO CORRESPONDE [C]: sin texto libre (H9§5 :930) |
| composer.retry | SIN EFECTO [C]: vive en n8n (Cat:713) | NO CORRESPONDE [C] | NO CORRESPONDE [C] |
| integrations.channel | SIN EFECTO [C]: solo tiene la opción `whatsapp` (Cat:653-656, :716) | NO CORRESPONDE [C] | NO CORRESPONDE [C]: el canal es del paso de la estrategia |
| integrations.appointments | SIN EFECTO [C]: fijo en `true` | SIN EFECTO [H]: la voz no agenda (VT:218) | NO CORRESPONDE [C] |
| governance.enabled | CERROJO [C]: `must_match`; `false` es solo un aviso (Cat:528), pero publicarlo da 409 | SIN EFECTO [C]: VC no lo lee; la voz tiene sus propias llaves | NO CORRESPONDE [C]: SSV no lo lee |
| governance.canaryOnly | CERROJO [C]: `must_match`, y `false` bloquea (Cat:527) | SIN EFECTO [C]: VC no lo lee; la voz tiene su canario por número [H] | Vale siempre `true` [C] (Cat:527 + Ver:140): N3 niega fuera del alcance canario y en sombra solo avisa (PL:342-345). Pruebas `H9ShadowCanaryOnlyLadderTest:37,:56` |
| governance.autoBrake | SIN EFECTO [C]: fijo en `true` | SIN EFECTO [C] | NO CORRESPONDE [C] |
| Reglas S-NNN (frases `forbid`) | APLICA [C], solo en el prompt, desde el compilador 2 (Comp:672-682; `StudioPromptBundle.php:166-178`). No es un filtro: en `app/Services/Marketing` nada busca las frases. Prueba `UltronPromptCompilerPhrasesTest` | APLICA [C]: VC:771-783 → prompt «NUNCA…» y `spec.forbiddenPhrases`; prueba `StudioVoiceCompilerTest:778`. La guarda posterior no las vigila (informe de voz §8 H-4) | NO CORRESPONDE por contrato [C] (H9§5 :929) |

**Reglas configurables frente a restricciones obligatorias [C]:**
- **Configurables con efecto real:**
  - WhatsApp: tone, emojis, maxChars, goal y frases S-NNN.
  - Voz: tone, goal, fallback y frases S-NNN.
  - H9: solo paymentLinksEnabled, y solo para negar.
- **Obligatorias (cerrojos):**
  - Seis restricciones bloqueantes del catálogo (Cat:522-527) impiden crear la versión (Ver:140).
  - Cuatro valores `must_match` (Decl:138) impiden publicar si no coinciden con el motor.
  - Cuatro interruptores de la voz (VC:396-406).
  - Las R-xx del código actúan siempre, haya o no versión.
- **Comprobación determinista en el commit:** solo maxChars y el tono «profesional». Emojis, objetivo, «entusiasta» y frases dependen del prompt y del Critic.

## 2. Conexiones pendientes dentro de los contratos (de más a menos cerca de la certificación)

1. **persona.tone y maxChars en WhatsApp: el código está hecho y falta desplegarlo.**
   - Qué: la Enmienda A4-2 (el commit comprueba el usted, compilador 4) y la A4-3 (regenerar antes de sustituir).
   - Estado: según el registro del contrato H9, «SIN DESPLEGAR», a la espera del gate 4-H (L9-123, L9-124). Producción sigue con el compilador 3 (L9-121, L9-122) [H: lo leí en el contrato, no en el servidor].
   - Dónde: Commit:1714-1720, Offer:210-213.
   - Tamaño S. Riesgo M: subir el compilador hace caer a Legacy las publicaciones firmadas con el 3 (`compiler_changed`, Offer:157-159), así que hay que republicar.
   - Prueba: repetir la aceptación «profesional 200».
2. **Textos del CRM sobre la voz para tone y goal** (M1-2 obliga a «decirlo en el CRM»).
   - Dónde: `studio-engine-text.ts:161-182`, igual que ya hace `FALLBACK_PARAM_TEXT` (:146-152).
   - Tamaño S, frontend, riesgo bajo.
   - Prueba: una spec por cada estado de la llave que afirme «en la voz sí aplica».
3. **governance.enabled: la consecuencia que enseña el inspector** (ver §3.b).
   - Dónde: corregirlo en la capa de texto del CRM (`studio-inspector.ts:220`). El catálogo está congelado (M1-10).
   - Tamaño S.
4. **Contradicción del contrato H9, no es una conexión.** L9-41 dice que `payment_link` «lo resuelve W9», pero el código no lo resuelve (`WhatsappRecipient.php:26-27`) y §6.3 prohíbe que H9 escriba en las tablas de pagos. No se debe conectar sin decisión del propietario y revisión de `security`.

**Fuera del contrato (solo se listan):**
- W2: prompts gobernados fuera de la canaria («por diseño», `auditoria-a-studio-whatsapp.md:41`).
- intent.fallback en WhatsApp (M1-2).
- Reglas S-NNN en H9 (H9§5 :929).
- Comprobar en el commit emojis, objetivo o frases (M1-18: «no es un filtro»).
- La guarda de la voz sobre las frases y los beneficios del plan (informe de voz H-3 y H-4).
- offerPriority, windowTurns, useLeadProfile y autoBrake leídos desde la versión.

## 3. Textos del CRM que hoy no dicen la verdad

- **a. tone y goal callan la voz.**
  - Llave apagada: el catálogo dice «Sin mando en el motor…» y «…lo decide el prompt del Strategist» (Cat:686, :702), y la fila del parámetro «Propuesta del borrador: el motor no la lee.» (`studio-param-row.ts:120-122`).
  - Llave encendida: solo habla de WhatsApp (`studio-engine-text.ts:175-181`).
  - Es falso para la voz, que compila los dos (VC:409-420). [C]
- **b. governance.enabled.** Cat:661, pintado en `studio-inspector.ts:220`, dice «Desde el Studio solo debería poder apagarse». Ni eso: publicar un valor distinto del motor da 409 (Decl:257-266), y la declaración solo se anota (`UltronPublishedConfig.php:20-21`). [C]
- **c. Reglas S-NNN con la llave apagada.** `studio-publication-text.ts:274` dice «Las reglas del Studio no tienen efecto en el motor». En la voz sí lo tienen (VC:771-783). Menor. [C]
- **d. Enlace de pago en H9.** `commercial-automation/ca-tpl-detail.ts:201` («N3 la bloquea si…») está incompleto: aunque la versión lo permita, W9 falla cerrado siempre. [C]
- **e. Restricciones R-xx en H9.** `ca-builder.ts:323` dice «N3 lee de esa referencia … las restricciones R-xx». No las lee de la referencia: el servidor las evalúa sobre el texto de la plantilla (`StudioMessageTemplates.php:282,:336`). [C]
- **f. Pista de emojis.** Cat:589 dice «Como mucho dos por mensaje», pero nada lo impide (`ComposerStyleGuard.php:452` solo anota). Menor. [C]

## 4. Prueba real mínima por motor

**WhatsApp.**
- La aceptación 2 (L9-122: versión `2e243853`, huella `af9e0d23`) cubre maxChars=200 (se midió 118, 158 y 189; un borrador de 215 se recortó a 158) y tone=profesional (con la observación del tono todavía abierta).
- También demostró `must_match` en verde, el sello `applied` y que fuera de la canaria sigue Legacy.
- **No cubre** emojis, goal, «entusiasta», frases S-NNN ni los cerrojos en negativo.
- Mínimo siguiente, tras el punto 1 de §2: una versión con profesional 200, `emojis=false`, `goal=informar` y una S-NNN con una frase; tres mensajes canarios, uno de ellos con señal de compra.
- Qué se observa: ningún emoji, ningún `close` ni `collect_payment`, la frase ausente y el usted.
- Dónde queda la traza: `marketing_ai_actions.metadata.studio` y `.studio_prompts` (huella ofrecida = observada; Commit:3054,:3057), y las anotaciones de longitud y de registro (Commit:1671,:1718).
- Salvedad: la huella prueba que el prompt cambió; la conducta en emojis, objetivo y frases es probabilística.

**Voz.**
- Una versión nueva con `goal` de `cita` a `informar`, publicada en canario: vuelve a compilarse, crea un agente nuevo y cambia la huella de la compilación (`studio-voice-publications.ts:179`).
- Qué se observa: la línea «TU OBJETIVO» (VT:220) y, en una llamada canaria, que Sofía no empuja a la valoración.
- Dónde queda la traza: la cadena de publicaciones de voz (huella y agente) y la llamada sellada [H: no comprobé las columnas].
- Cerrojo en negativo: compilar con `paymentLinksEnabled=true` da 422 (VC:399).

**H9.**
- Honestamente, ningún cambio en el Studio altera lo que se envía:
  - canaryOnly no puede valer `false` en ninguna versión.
  - paymentLinksEnabled solo puede negar: con `true`, W9 falla cerrado.
- Prueba mínima:
  - Validar una estrategia con una plantilla de `payment_link` contra una versión con `false`: 422 `payment_links_disabled` (SSV:264).
  - Una pasada en sombra con una persona fuera del canario: aviso `canary_only_declared`, con `studio_version_uuid` y `studio_content_hash` en `outreach_decisions` (`OutreachTables.php:46`).

## 5. Lo que no pude comprobar

- El estado de producción: commit desplegado y valores de `MARKETING_ULTRON_STUDIO_PROMPTS` y `_CONFIG`. Solo leí el registro del contrato.
- Que la copia S1 de n8n use `roles`: no inspeccioné n8n; hay evidencia en L9-113 y L9-122.
- El agente real de la voz en el proveedor: me apoyé en el informe de voz §1.c tras comprobar por muestreo VC:396-420, :771-783 y VT:210-224, :484-485.
- No encontré una prueba por cada restricción bloqueante del catálogo real (sí el validador genérico, la paridad y `UltronStudioGEdgeCasesTest:143-158`).
- El canario de la voz (`StudioCanaryPhone`) y las columnas del sello de la llamada.
- Qué build del CRM está desplegado frente a `eeb4b79`.
