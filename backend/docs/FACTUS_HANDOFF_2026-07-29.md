# Facturación electrónica — traspaso al 2026-07-29

Documento de continuidad. Resume el estado real de producción al cierre de la sesión y lo que
queda por hacer. **La emisión está bloqueada y no debe reactivarse hasta que Halltec responda.**

---

## 1. Estado actual de producción

| Elemento | Estado |
|---|---|
| Emisión de documentos fiscales | **BLOQUEADA** (barrera previa al HTTP) |
| Cuenta de Factus | activa; solo lectura (`GET`) en uso |
| Documentos nuevos emitidos en esta sesión | **cero** |
| Notas crédito emitidas | **ninguna** |
| Consecutivos consumidos | **ninguno** |
| Total de solicitudes | 17 — 8 `validated`, 0 `pending`, 1 `rejected`, 8 `cancelled` |
| Migraciones ejecutadas | `2026_07_29_000001`, `2026_07_29_000002` |
| Backend desplegado | `ce01d60` |

El bloqueo vive en `FactusClient::assertEmissionAllowed()`, dentro del despachador HTTP genérico,
y aborta **cualquier POST**. Está ahí y no en `createInvoice()` porque `WompiTransactionService` y
`PaymentMembershipActivator` fuerzan la emisión ignorando `auto_emit`, y un job encolado puede
reintentar por su cuenta. Los `GET` siguen permitidos: la reconciliación fiscal los necesita y
leer no crea documentos.

**Respaldos de esta sesión:**

```
/root/backups/billing-20260729-034133          full.dump (2.3M) + electronic_invoices.sql + env
/root/backups/env-before-block-20260729-035546
/root/backups/env-before-guard-20260729-041837
```

## 2. Commits desplegados

| Commit | Contenido |
|---|---|
| `0824c88` | Bloqueo de toda emisión mientras la decisión tributaria no esté confirmada |
| `23c790d` | El proveedor pasa a ser la autoridad fiscal para documentos validados; reconciliación append-only; corrección de `billing:tax-audit`; informe de incidente y consulta a Halltec |
| `36c0f34` | Barrera de producción: rechaza facturar lo que no es una venta real y cobrada; estrictez de endpoint; sanitizador de payloads |
| `21385c8` | Cancelación de solicitudes nacidas en sandbox; comando de prueba exclusivo de sandbox |
| `ce01d60` | Tabla histórica IBFE1–IBFE8 y perfil del emisor confirmado |

Contexto previo a esta sesión: `478b04f`, `8861d5f`, `00ab3c0`.

## 3. Variables y flags activos

```
FACTUS_ENABLED                     = true
FACTUS_TAX_DECISION_CONFIRMED      = false     ← el bloqueo
FACTUS_MEMBERSHIPS_AUTO_EMIT       = false
FACTUS_PRODUCT_SALES_AUTO_EMIT     = false

BILLING_ISSUER_VAT_RESPONSIBILITY  = 49
BILLING_ISSUER_IS_VAT_RESPONSIBLE  = false
BILLING_VAT_COLLECTION_ENABLED     = false
BILLING_DEFAULT_VAT_RATE           = 0

billing.env                        = production
billing.base_url                   = https://api.factus.com.co
billing.numbering.range_id         = 2076   (prefijo IBFE)
tax_policy.emission_guard_enabled  = true   (por defecto; sin variable en producción)
```

`FACTUS_ENABLED` sigue en `true` a propósito: mantiene la cuenta operativa para consultas de
reconciliación. Quien bloquea la emisión es `FACTUS_TAX_DECISION_CONFIRMED=false`.

En la suite de pruebas, `phpunit.xml` fija `FACTUS_TAX_DECISION_CONFIRMED=true` y
`BILLING_EMISSION_GUARD=false` como línea base, para que las pruebas preexistentes de emisión
sigan ejercitando la emisión. Las pruebas de cada barrera la encienden explícitamente; nunca al
revés.

## 4. Solicitudes ID 9–16

| ID | Estado | Motivo | Reintento |
|---|---|---|---|
| 9 | `rejected` | HTTP 422 de **prevalidación**: sin número, sin CUFE, sin consecutivo consumido | Solo con autorización explícita en código |
| 10 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 11 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 12 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 13 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 14 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 15 | `cancelled` | `sandbox_test` | **Deshabilitado** |
| 16 | `cancelled` | `sandbox_test` | **Deshabilitado** |

Las siete pertenecen al **mismo socio**, todas por $80.000, todas `environment = sandbox` con
tarjeta de prueba `4242`; cinco se crearon en 47 minutos con `subscription_id` 1 a 5. Son ensayos
del flujo de suscripción recurrente, **no siete cobros distintos**.

Canceladas el 2026-07-29 con actor `cierre-tecnico-2026-07-29`. Siete entradas en
`electronic_invoice_logs` con actor, fecha y motivo. **Ninguna fila eliminada.** Los pagos
1005–1011 y sus transacciones quedaron intactos.

## 5. IBFE1–IBFE8

Importes tomados del documento validado en Factus/DIAN, **no** de las columnas locales.

| Doc | Pago origen | ¿Real o prueba? | Total | IVA (DIAN) | Tarifa | Acción recomendada |
|---|---|---|---|---|---|---|
| IBFE1 | 48 — **inexistente** | prueba (por descarte) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE2 | 43 — existe, **efectivo** | **real** | 1.700.000,00 | 271.428,57 | 19 % | Corrección definida por el contador; posible nota crédito y reexpedición |
| IBFE3 | 49 — **inexistente** | prueba (por descarte) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE4 | 50 — **inexistente** | prueba (por descarte) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE5 | 51 — **inexistente** | prueba (por descarte) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE6 | 52 — **inexistente** | prueba (por descarte) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE7 | 53 — existe, Wompi | **prueba** (4242) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |
| IBFE8 | 1012 — existe, Wompi | **prueba** (4242) | 80.000,00 | 12.773,11 | 19 % | Anulación total por nota crédito |

**IVA total discriminado: $360.840,34 en 8 de 8 documentos.**

Dos defectos **independientes**, que conviene no confundir:

1. **Tributario** — los ocho discriminan 19 % siendo el emisor responsabilidad 49. Afecta también
   a IBFE2, que sí es una venta real.
2. **De origen** — siete de los ocho no corresponden a ventas reales. Ahí el problema no es cuánto
   IVA lleva el documento, sino que el documento no debió existir.

Solo **IBFE2** es una operación real y trazable, y por tanto el único caso donde «reexpedir»
significa algo.

**Salvedad importante:** IBFE1 y IBFE3–IBFE6 se clasifican como prueba **por descarte, no por
evidencia**. Sus pagos origen (48–52) ya no existen en la base, así que no se puede afirmar su
ambiente ni su medio de pago. Esa ausencia es en sí misma un hallazgo pendiente: hay documentos
ante la DIAN cuyo respaldo documental local desapareció.

**La base local no es fuente de verdad.** IBFE1 figura con IVA 0,00 localmente y 12.773,11 ante la
DIAN. Contrastar siempre con `GET /v2/bills/{number}`; para eso está
`billing:tax-audit --audit`, que trata al proveedor como autoridad y termina con código distinto
de cero si hay discrepancias.

## 6. Perfil tributario confirmado en Factus

`GET /v2/companies` devuelve:

```json
{
  "responsibilities":   [{ "code": "R-99-PN", "name": "No responsable" }],
  "tribute":            { "code": "ZZ", "name": "No aplica" },
  "legal_organization": { "code": "2",  "name": "Persona Natural" },
  "economic_activity":  "9311"
}
```

**El perfil ya es correcto y coincide con el RUT (responsabilidad 49).** No se preparó ningún
`PUT` porque no hace falta. El 19 % de IBFE1–IBFE8 salió íntegramente de **nuestro payload**, no
de la configuración de la cuenta.

Nota: el payload de `POST /v2/bills/validate` **no contiene bloque fiscal del emisor** (solo
`customer` e `items`), y el bloque `company` de la respuesta `GET` **no expone la responsabilidad
tributaria**. La condición del emisor vive únicamente en la configuración de la cuenta.

## 7. Resultado exacto del sandbox

`php artisan billing:test-factus-sandbox --emit`, ejecutado en el entorno local con credenciales
sandbox y rango 389:

```
ambiente     : sandbox ✓
endpoint     : https://api-sandbox.factus.com.co ✓

CONTRATO EXIGIDO
  [OK] precio = 80000.00
  [OK] subtotal = 80000.00
  [OK] IVA = 0
  [OK] total = 80000.00
  [OK] sin tarifa 19
  [OK] sin extracción de IVA (≠ 67226.89)
  [OK] is_excluded no inferido
  [OK] referencia marcada como prueba

ENVIANDO A SANDBOX…
HTTP 422
  data.errors.items.0.taxes.0: El campo items.0.taxes es obligatorio.
```

Ese rechazo **es el resultado útil**: reproduce el bloqueo en aislamiento, con el perfil del emisor
ya correcto, sin tocar la DIAN. Descarta la configuración de la cuenta como causa.

En producción el mismo comando **se niega a ejecutarse** por tres señales independientes: ambiente
declarado, endpoint configurado y rango de numeración productivo (2076).

## 8. El problema pendiente con `items[].taxes`

La API V2 exige `items[].taxes` y solo admite dos formas:

| Forma enviada | Significado ante la DIAN | Resultado |
|---|---|---|
| `[{code:'01', rate:'0.00'}]` | bien **exento** (gravado a tarifa 0 %) | Aceptada, pero **falsa**: el servicio no es exento |
| `[{is_excluded:true}]` | bien **excluido** por naturaleza | Aceptada, pero **falsa**: el servicio no es excluido |
| `[{is_excluded:false}]` sin `code`/`rate` | — | **422** — «El campo código tributo es obligatorio» |
| bloque omitido por completo | — | **422** — «El campo items.0.taxes es obligatorio» |

El IVA cero de Iron Body proviene de la **condición del emisor** (responsabilidad 49 / R-99-PN),
**no de la naturaleza del producto**. Los servicios de gimnasio son gravables. Ninguna de las dos
formas admitidas describe esa situación, y ambas afirmarían ante la DIAN algo falso sobre el
producto.

Consulta enviada a Halltec: `docs/factus/CONSULTA_HALLTEC_R99PN.md`.

## 9. Lo que NO debe ejecutarse

- **No reactivar `FACTUS_TAX_DECISION_CONFIRMED`** hasta tener la respuesta escrita de Halltec.
- **No emitir la solicitud ID 9.**
- **No reintentar las solicitudes ID 10–16**: son pruebas de sandbox y tienen los reintentos
  deshabilitados.
- **No emitir notas crédito** sobre IBFE1–IBFE8. Es decisión del contador.
- **No modificar IBFE1–IBFE8**: consecutivos, CUFE, PDF, XML ni estados DIAN.
- **No corregir los importes contables locales.** El camino existe
  (`billing:tax-audit --apply-provider-values`) pero está cerrado a propósito y exige aprobación
  escrita del contador.
- **No usar `is_excluded=true`.** La barrera previa al HTTP lo rechaza.
- **No usar `code=01, rate=0.00`** sin confirmación escrita: equivale a declarar el servicio
  exento.
- **No ejecutar pruebas tributarias contra producción.**
- **No ejecutar la migración `2026_07_27_000006_add_snapshot_and_reconciliation_...`**: es trabajo
  de terceros sin commitear y no se ha verificado. Nada de lo entregado depende de sus columnas.

## 10. Siguiente paso cuando responda Factus/Halltec

1. **Leer la respuesta** y determinar cuál de estos tres escenarios aplica:
   - *(a)* Existe una representación válida que no clasifica el ítem como exento ni excluido →
     implementarla en `FactusClient::normalizeTaxes()` y ajustar la barrera
     `assertPayloadIsTaxFree()`.
   - *(b)* Halltec habilita algo en la cuenta que hace innecesario el tributo por ítem → no toca
     código; basta reverificar con el comando de sandbox.
   - *(c)* La API no soporta el caso → **decisión del contador**, por escrito, sobre cuál de las
     dos clasificaciones asumir. No es una decisión técnica.
2. **Reproducir en sandbox** con `php artisan billing:test-factus-sandbox --emit` y comprobar que
   el documento devuelto conserva 80.000 / 0 / 80.000.
3. **Solo entonces** reactivar `FACTUS_TAX_DECISION_CONFIRMED=true`, y con `auto_emit` todavía en
   `false`: la primera emisión real debe ser manual y explícita.
4. **Elegir el primer caso real.** Ninguna de las solicitudes actuales sirve: la ID 9 está
   rechazada y las ID 10–16 son pruebas. Debe ser una venta nueva, con `wants_invoice = true`,
   `environment = production` y tarjeta real. La barrera rechazará automáticamente cualquier otra
   cosa.
5. **Verificar con `billing:tax-audit --audit`** que el nuevo documento concilia contra el
   proveedor.
6. **En paralelo y por separado**, resolver con el contador: la regularización de los $360.840,34,
   qué hacer con IBFE7/IBFE8 (emitidas desde sandbox) y con los cinco documentos cuyo pago origen
   desapareció.

---

### Comandos útiles

```bash
php artisan billing:tax-audit --audit            # proveedor como autoridad; sale != 0 si hay hallazgos
php artisan billing:tax-audit --candidate=80000  # desglose candidato, sin emitir
php artisan billing:test-factus-sandbox          # verifica el contrato; --emit envía a sandbox
php artisan billing:cancel-test-requests --dry-run
```

### Documentos relacionados

- `docs/factus/INCIDENTE_IVA_2026-07.md` — informe de incidente
- `docs/factus/CONSULTA_HALLTEC_R99PN.md` — consulta enviada al proveedor
- `docs/factus/HISTORICO_IBFE1_IBFE8.md` — tabla histórica y acciones recomendadas
