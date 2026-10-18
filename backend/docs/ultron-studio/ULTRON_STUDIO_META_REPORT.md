# ULTRON STUDIO: campañas Meta (estado)

**Fecha:** 17-10-2026. **Estado: NO IMPLEMENTADO.** No se ha tocado Meta. «Pautas» = campañas de Facebook e Instagram en Meta Ads Manager: la correspondencia está confirmada por la integración existente.

## Lo que existe hoy (verificado)

- **Lectura de Meta Ads** con token propio y solo `ads_read` (el comando de comprobación falla si trae otro permiso). Cliente solo GET: insights por anuncio, alcance, campañas, conjuntos y anuncios con `effective_status`. Sincronización horaria a `meta_ad_entities`, `meta_ad_insights_daily`, `meta_ad_reach_snapshots`. El CRM ya tiene el panel «Mercadeo digital».
- **Sin escritura en Meta Ads.** Las únicas escrituras en Graph son de WhatsApp (mensajes, medios, suscripción).
- **Atribución:** solo el `referral` de Click-to-WhatsApp (`ad_id`, `ctwa_clid`), confianza alta con anuncio y clic; lo demás `unknown`. La campaña se resuelve al leer contra las cuentas conectadas.
- **No existe** el concepto «campaña autorizada para ULTRON» ni una tabla que vincule campaña ↔ plan/estrategia/versión. El estado es el de la última pasada (viejo a los 180 min).

## Lo que hará el Hito K

- Conector **solo lectura** reutilizando la sincronización existente: consultar campañas, estado real (con su antigüedad), seleccionar una, asociar estrategia y versión, activar o desactivar la **asociación comercial dentro de ULTRON**, trazabilidad del vínculo.
- **Diferenciar siempre** «campaña activa en Meta» de «asociación activa en ULTRON».
- **Nunca** crear, pausar ni editar anuncios, presupuestos ni segmentación. Sin permisos de escritura.
- **Atribución:** usar el referral cuando exista; si no, «origen desconocido». Nunca adivinar la campaña por el teléfono.
- Si no hay permisos o token: datos de demostración claramente marcados y sin afirmar conexión real.
