# ULTRON — Certificación de cierre

Qué se cerró, qué está comprobado y qué sigue abierto, al terminar el bucle de
catorce puntos. **Este documento no certifica que ULTRON esté listo para
atender tráfico general.** Certifica que el sistema aguanta lo que se le pidió
aguantar, dice con qué evidencia, y dice también dónde no llega.

- **Fecha:** 2026-09-19 (release candidate cerrada el mismo día)
- **Backend en producción:** `3462cb6`
- **Workflow n8n activo:** `Iron Body - ULTRON - Commercial Advisor`
  (`YspRwsXnqt33NouP`), versión `7d8477e9-44f9-4862-a023-2becb4f91ed6`
- **Banderas en producción:** ULTRON apagado · enlaces de pago apagados ·
  análisis automático apagado · canario fijado en la conversación 19

---

## 1. Qué se cerró

Once commits, todos desplegados y verificados en el servidor. Los puntos 1 a 8
se cerraron antes de este tramo; lo que sigue es lo de este bloque.

| Punto | Qué quedó | Evidencia |
|---|---|---|
| 9 · Pago → membresía | El enlace automático se retiró: el teléfono no es prueba, porque el registro no lo verifica. Ahora se PROPONE y lo acepta una persona | `ApprovedPaymentClaimer`, `PaymentClaimAcceptanceService`, 22 pruebas |
| 10 · La app | El catálogo dice lo que la app hace, contrastado pantalla a pantalla. Los enlaces salen una vez por hora | `MobileAppCatalog`, `UltronMobileAppTest` |
| 11 · Posventa | La membresía es un hecho del CRM, con cerrojo determinista contra fechas, días y estados inventados. Renovar es la única venta a un cliente | `MembershipFactsProvider`, `MembershipFactGuard`, n8n `7d8477e9` |
| 12 · Seguridad y autoridad | Matriz de autoridad escrita y **defendida por un test**; fuga de precios al modelo cerrada; detector de cron que moría a diario, arreglado | `ULTRON_AUTHORITY.md`, `UltronAuthorityMatrixTest`, `UltronPriceRedactionTest` |
| 13 · Laboratorio de tortura | 321 escenarios sobre la PROPUESTA de n8n, no solo sobre el mensaje entrante | `tests/Feature/Marketing/Torture/` |
| 14 · Canario | Runbook con las cinco puertas, qué mirar, cuándo abortar y el GO/NO-GO | `ULTRON_CANARY_RUNBOOK.md` |

### Lo que encontró el laboratorio de tortura, y está cerrado

Cuatro cosas salían al cliente y ya no salen. Las cuatro se encontraron
torturando la propuesta, no el mensaje entrante:

1. **El pago dado por bueno con la caja vacía.** «Ya te transferí» → «listo, ya
   recibimos tu pago, quedas activo». Es la palanca de fraude más barata que hay
   por WhatsApp, y salía porque las señales prohibidas solo tenían infinitivos:
   el pretérito y la pasiva no los miraba nadie.
2. **El precio dicho en palabras.** «80 mil», «ochenta mil», «80k», «80 000»,
   dígitos de ancho completo y la O por cero. El patrón reconocía formas de
   dígitos, no dinero.
3. **El descuento inventado.** «Te hago un descuento del 20%» no era dinero para
   ningún guard y comprometía al gimnasio con una rebaja que nadie autorizó.
4. **La captura como prueba de pago.** Enseñarle a la persona que una imagen
   basta es lo que convierte una captura editada en una membresía.

### Los cerrojos que existen hoy

Diez, cada uno con su código y su dueño. La lista completa, con qué defiende
cada uno, está en `ULTRON_AUTHORITY.md` §2, y un test falla si aparece uno
nuevo sin documentar:

`machine_reply_card_data` · `machine_reply_forbidden_action` ·
`machine_reply_invented_discount` · `machine_reply_invented_price` ·
`machine_reply_membership_fact` · `machine_reply_payment_fact` ·
`machine_reply_pressure` · `machine_reply_unauthorized_handoff` ·
`machine_reply_unsafe_claim` · `machine_reply_url`

---

## 2. Con qué evidencia

- **Suite completa:** 4819 pruebas. Los 55 errores y el fallo restante son los
  del baseline del repositorio (Billing/Factus, contrato de socios, un caso de
  Pricing V2) y no tienen relación con este trabajo. Se comprobó nombre a
  nombre: **cero fallos nuevos**.
- **Cada commit pasó la puerta sobre un árbol AISLADO** (`git archive` del HEAD
  commiteado, `vendor` por enlace duro, APP_KEY propia), no sobre el árbol de
  trabajo. Un cambio a medias de otra línea no podía colarse en verde.
- **Cada punto llevó verificación independiente** de un agente distinto al que
  implementó, con instrucciones de REFUTAR. Cinco veredictos fueron REQUIERE
  CAMBIOS y volvieron a su ciclo; el guard de datos de tarjeta necesitó tres.
- **Las regex se probaron contra el PCRE de producción (10.39)**, que no compila
  las propiedades Unicode que sí compila el 10.47 local. Sonda tras cada
  despliegue: `preg_last_error_msg()` devuelve `No error`.
- **El par n8n↔backend se verificó en los dos sentidos:** los cinco nodos
  tocados coinciden byte a byte con las operaciones, los doce restantes y las
  conexiones son idénticos, y los enums del Critic del workflow son exactamente
  `CriticContract::DIMENSIONS` (22) y `::HARD_FAILS` (16). La sonda sin efectos
  en producción devuelve 422 de validación pura, sin error en ninguna clave del
  Critic y **sin crear una sola fila**.

---

## 3. Qué NO certifica este documento

Se dice antes que el veredicto, porque es lo que más importa.

- **El modelo real no ha atendido a nadie todavía.** Todo lo probado es la
  autoridad de Laravel frente a una propuesta hostil. Que el modelo escriba bien
  en español colombiano, entienda a quien duda y no canse, solo se sabe con
  personas reales: eso es el canario, y está pendiente de tu aprobación.
- **La concurrencia real no está medida.** Lo que dos conversaciones del mismo
  lead hacen a la vez sigue siendo un residuo conocido.
- **El coste por turno no se ha medido.** Se ve en la factura del modelo.
- **El cobro real no se ha ejercido.** Con la bandera de enlaces apagada, ningún
  cliente ha pagado por este camino.

---

## 4. Las cinco decisiones que estaban abiertas, y cómo quedaron

Las cinco se cerraron en la release candidate, con tu instrucción. Se dejan
escritas con lo que eran, porque un documento que borra el problema que resolvió
no deja aprender nada.

1. **El canario estaba bloqueado por el estado de un lead.** La conversación 19
   pertenece al lead 3, que seguía en `needs_human`, así que derivar a una
   persona habría estado autorizado en el primer turno.
   **RESUELTO por el mecanismo del dominio, no a mano sobre la base:**
   `MarketingManualTakeoverService::releaseToAi()` devolvió las conversaciones 19
   (lead 3) y 1 (lead 2) al control autónomo, con su fila de auditoría
   (`release_to_ai`, `from_state=HUMAN_REQUIRED`, `to_state=RELEASED_TO_AI`) y su
   motivo. El canario sigue fijado en la 19.
2. **El secreto interno acuñaba enlaces de pago reales.** **CERRADO (RC-2):** el
   permiso se exige en el embudo `WompiPaymentLinkService::generateForLead()`,
   por el que pasan los cinco caminos que pueden acuñar. La bandera es absoluta y
   no tiene excepción humana. Comprobado en el servidor: con la bandera apagada,
   origen automático y panel humano verificado reciben `payment_links_disabled` y
   no se crea ninguna fila de cobro. Fijado por `PaymentLinkHardGateTest`.
3. **`payments.reference` no tenía índice único.** **CERRADO (RC-3):** índice
   único PARCIAL sobre `origin='gateway'`, decidido tras auditar producción en
   solo lectura —9 688 filas, un único grupo duplicado que son 154 cobros de
   mostrador distintos reutilizando un comprobante, y **cero** duplicados de
   pasarela—. Un único sobre toda la columna habría fallado al aplicarse y habría
   roto el mostrador.
4. **La base de conocimiento era un canal de inyección persistente.**
   **CERRADO (RC-4):** lo que escribe la máquina anónima nace en borrador y no
   llega al prompt sin aprobación, no puede reescribir un hecho ya aprobado, y
   cada escritura deja procedencia y hora. Lo que ya existía sigue publicado —18
   ítems activos tras desplegar— y **dos filas** quedan contadas en
   `knowledge/doctor` como `approved_from_untrusted_origin`: entraron por el
   secreto compartido y nadie las ha mirado nunca. **Eso sí sigue siendo tuyo:**
   mirarlas una por una.
5. **Los dos leads atascados** (2 y 3). **RESUELTOS**, y no eran el mismo caso:
   el 3 es el canario y el 2 es un lead de prueba (`manual_phone_test`) cuya
   persona pidió que no le escribieran, así que su consentimiento sigue
   `denied` y devolverlo al control autónomo **no reabre el contacto** —lo fija
   `LeadNeedsHumanLifecycleTest`—.

---

## 5. Residuos conocidos, no bloqueantes

### Deuda anotada antes del tráfico general

Dos cosas que el canario físico dejó a la vista y que NO se arreglan durante la
prueba, porque arreglarlas ahora sería abrir un refactor con el canario abierto:

- **Semántica del consentimiento con leads duplicados.** El mismo teléfono tiene
  dos filas de lead: la viva (con `meta_user_id` igual al wa_id, que es por donde
  resuelve el webhook) y una histórica de `manual_phone_test` con
  `consent_status = denied`. El opt-out vive en la fila que WhatsApp **no**
  resuelve, así que no gobierna la conversación real. Para el canario da igual
  —es el propietario probando su propio número—, pero antes del tráfico general
  el consentimiento tiene que ser del TELÉFONO, no de la fila.
- **`/ai/commit` exige el canario, pero no el interruptor maestro.** Desde
  `33db7bc` un commit para una conversación distinta de la del canario se
  rechaza con 403 y sin efectos. Lo que no comprueba es
  `marketing.ultron.enabled`: el día que el canario se quite (id a `null` = sin
  restricción), esa puerta vuelve a aceptar cualquier conversación mientras
  alguien tenga el secreto. Añadirlo hoy obligaría a tocar decenas de pruebas
  que ejercitan el commit con el interruptor apagado.



- La ventana de «ya entrenó hoy» del detector proactivo compara hora de Neiva
  contra marcas en UTC, así que abre cinco horas antes de tiempo. Síntoma: un
  aviso perdido, nunca un fallo.
- Las rutinas que programan por días ordinales («Día 1».. «Día 4») se consideran
  válidas cualquier día. Es la degradación segura pedida, pero es una decisión de
  producto.
- La redacción de precios en el historial usa una heurística, así que un año o un
  documento también se tapan. Se eligió a propósito el marcador **inerte**: en el
  peor caso el texto queda raro, y eso se ve; con el marcador resoluble, un
  número cualquiera se habría convertido en el precio real del plan.
- Un nombre propio detrás de una apertura sigue siendo el borde del guard de
  traspaso. Lo juzga el Critic.

---

## 6. GO / NO-GO

**Para el canario: GO.** La precondición 1 está resuelta y verificada en el
servidor. El sistema hace lo que promete frente a una propuesta hostil, y el
aborto es una línea que no necesita despliegue. Los criterios turno a turno están
en el runbook §8, y el acta se saca con
`php artisan ultron:canary-report 19 --since=<inicio del canario>`: sin esa
ventana el acta arrastra los cuarenta turnos del asesor anterior que viven en la
misma conversación.

**Para tráfico general: NO-GO.** Faltan las dos cosas que solo da el canario:
personas reales leyendo lo que escribe el modelo, y alguien del equipo firmando
que atendería igual esa conversación.

**Para cobrar de verdad: NO-GO hasta que lo autorices con esas palabras.** Y
antes de encender esa bandera conviene cerrar la decisión 2, porque hoy la
bandera no es la única puerta al dinero.
