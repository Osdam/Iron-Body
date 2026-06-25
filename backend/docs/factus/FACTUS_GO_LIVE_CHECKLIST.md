# Factus — Checklist operativo de activación a PRODUCCIÓN (Go-Live)

Iron Body Neiva. Procedimiento final para emitir facturación electrónica real.
Complementa `docs/factus/FACTUS_PRODUCTION.md` (datos del RUT y variables `.env`).

> Regla de oro: **la facturación NUNCA bloquea el cobro.** Si algo falla, el pago
> queda registrado y la factura queda `pending`/`error` para reintentar. Ante un
> comprobante ya emitido y errado: **nota crédito**, jamás borrar.

---

## 1. Pre-requisitos (ANTES de activar)
- [ ] **Tributación de membresías confirmada por el contador** (excluido / exento / IVA 19%).
- [ ] **Asignar `tax_rate_id` a todos los planes** en CRM → Facturación → *Configuración fiscal*.
- [ ] Productos de caja: *Asignar IVA 19% incluido a todos los activos* (ya validado).
- [ ] `FACTUS_TAX_DECISION_CONFIRMED=true` en `.env`.
- [ ] Credenciales **productivas** en `.env` (`FACTUS_CLIENT_ID/SECRET`, `FACTUS_USERNAME/PASSWORD`).
- [ ] `FACTUS_ENV=production` y `FACTUS_BASE_URL=https://api.factus.com.co`.
- [ ] `FACTUS_NUMBERING_RANGE_ID` (rango de **factura** prod) — obtener con `php artisan billing:factus-check`.
- [ ] `FACTUS_CREDIT_NUMBERING_RANGE_ID` (rango de **nota crédito** prod).
- [ ] `FACTUS_DEFAULT_MUNICIPALITY_CODE=41001` y datos del emisor (`FACTUS_COMPANY_*`).
- [ ] `php artisan config:clear && php artisan billing:factus-doctor` → **LISTO PARA PRODUCCIÓN** (exit 0).

> Mientras el doctor no diga LISTO, **no continuar**.

## 2. Plan de activación
1. [ ] **Backup `.env`**: `cp .env .env.backup.$(date +%F_%H%M)`.
2. [ ] **Backup DB** (pg_dump / mysqldump) con timestamp.
3. [ ] `FACTUS_ENABLED=true` en `.env`.
4. [ ] `php artisan config:cache` (recarga config en prod).
5. [ ] `php artisan queue:restart` y asegurar worker: `php artisan queue:work --queue=billing --tries=5` (supervisor) + cron `schedule:run`.
6. [ ] **Primera factura real controlada**: un pago real pequeño → botón "Emitir" (a solicitud del cliente) **o** `php artisan billing:factus-smoke --payment-id=<ID>`.
7. [ ] **Validar**: número `SETP…`, **CUFE**, **PDF**, **XML**, estado `validated`, y verificación en el **portal DIAN**.

## 3. Rollback
1. [ ] `FACTUS_ENABLED=false` en `.env`.
2. [ ] `php artisan config:cache`.
3. [ ] `php artisan queue:restart`.
4. [ ] **No eliminar** facturas ya emitidas. Las `pending`/`error` se reintentan luego; las erradas se corrigen con **nota crédito**.
- El cobro y el CRM siguen intactos (facturación desacoplada).

## 4. Checklist para RECEPCIONISTAS
- **Cuándo emitir factura:** solo **cuando el cliente la solicita** (no es automática).
- **Consumidor final:** si el cliente no da datos fiscales, marcar *Usar consumidor final* (es válido ante la DIAN).
- **Cómo emitir:** en Pagos o Caja → columna *Factura* → botón **Emitir** → confirmar.
- **Si falla:** la factura queda *Pendiente* o *Fallida*. **No reintentar a lo loco**: avisar a un administrador; desde el detalle se puede **Reintentar** o **Sincronizar**.
- **Nota crédito / anulación:** **solo el dueño o un administrador**. Recepción no puede.
- **Nunca** prometer factura "ya enviada" sin ver el estado **Validada** y el **CUFE**.

## 5. Controles preventivos — NO EMITIR si…
1. `billing:factus-doctor` no está en **LISTO**.
2. Hay **planes/productos activos sin `tax_rate_id`** (el doctor lo marca).
3. `FACTUS_TAX_DECISION_CONFIRMED=false`.
4. Falta **PDF/XML después de validación** → no re-emitir; usar **Sincronizar** (re-descarga) o `php artisan billing:factus-backfill-files`.
5. **No hay backup previo** de `.env` y DB.
6. No hay **ventana de prueba aprobada**: no poner `FACTUS_ENABLED=true` sin acuerdo.
7. Ante errores: **no eliminar** facturas emitidas; **nota crédito** si aplica.

### Operación: logs, cola y estado
- **Logs app:** `storage/logs/laravel.log` (claves `billing.*`).
- **Traza por factura:** tabla `electronic_invoice_logs` (saneada, sin secretos).
- **Cola:** `php artisan queue:work --queue=billing`; fallidas en `failed_jobs` (`php artisan queue:failed`).
- **Estados:** tabla `electronic_invoices` (`pending/processing/validated/rejected/error/cancelled/credit_note_*`).

### Qué hacer si Factus responde error
- **422 (rechazo de datos/DIAN):** corregir datos (municipio, documento, tarifa) y **Reintentar**. No reintenta solo.
- **409 (conflicto):** revisar duplicidad de `reference_code`; consultar la factura por número.
- **429 (rate limit):** esperar; el job reintenta con backoff.
- **5xx / red:** transitorio; el job reintenta. Revisar `electronic_invoice_logs`.

### Si la DIAN ACEPTA pero el CRM falla guardando PDF/XML
La factura queda `validated` **sin** `pdf_path`/`xml_path`. Recuperar (read-safe, no re-emite):
- Botón **Sincronizar** en la factura, **o**
- `php artisan billing:factus-backfill-files --dry-run` (revisar) → luego `php artisan billing:factus-backfill-files`.

## 6. Checklist técnico — DESPUÉS de la primera factura
- [ ] `electronic_invoices`: la fila existe con `status=validated`.
- [ ] `electronic_invoice_logs`: pasos `emit` (ok) sin secretos.
- [ ] `full_number` con el número fiscal real (`SETP…`), **no** el uuid.
- [ ] `cufe` presente.
- [ ] `pdf_path` presente y archivo en `storage/app/private/invoices/{uuid}/factura.pdf`.
- [ ] `xml_path` presente y archivo `.xml`.
- [ ] **Descargar PDF y XML desde el CRM** (detalle de la factura) → abren correctamente.
- [ ] La factura aparece **Validada** en el listado y en el portal DIAN.

## 7. Comandos de referencia
```
php artisan billing:factus-check                 # credenciales + rangos
php artisan billing:factus-doctor                # readiness de producción (LISTO/BLOQUEADO)
php artisan billing:factus-smoke --payment-id=ID # 1 factura controlada
php artisan billing:factus-backfill-files --dry-run   # PDF/XML faltantes (revisar)
php artisan billing:factus-backfill-files --limit=50  # recuperar PDF/XML faltantes
```
