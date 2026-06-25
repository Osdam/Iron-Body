# Factus — Variables de entorno productivas

Guía para parametrizar producción **sin activar** Factus. Plantilla:
`backend/.env.production.example`. Verificador oficial: `php artisan billing:factus-doctor`.

> El `billing:factus-doctor` es la **autoridad final**: nada se activa hasta que
> diga **LISTO PARA PRODUCCIÓN**. Además, un guard impide emitir si el servidor
> es producción (`APP_ENV=production`) y `FACTUS_ENV` sigue en `sandbox`.

## Tabla de variables
| Variable | Descripción | Origen | sandbox → producción | Oblig. |
|---|---|---|---|---|
| `FACTUS_ENABLED` | Interruptor maestro | operación | false → (true al final) | sí |
| `FACTUS_ENV` | Ambiente | operación | `sandbox` → `production` | sí |
| `FACTUS_BASE_URL` | URL API | Factus | sandbox → `https://api.factus.com.co` | sí |
| `FACTUS_CLIENT_ID` | OAuth2 | **Factus** | distinto por ambiente | sí |
| `FACTUS_CLIENT_SECRET` | OAuth2 (secreto) | **Factus** | distinto | sí |
| `FACTUS_USERNAME` | OAuth2 (email) | **Factus** | distinto | sí |
| `FACTUS_PASSWORD` | OAuth2 (secreto) | **Factus** | distinto | sí |
| `FACTUS_NUMBERING_RANGE_ID` | Rango de **factura** (resolución DIAN) | **Factus** | distinto | sí |
| `FACTUS_CREDIT_NUMBERING_RANGE_ID` | Rango de **nota crédito** | **Factus** | distinto | sí |
| `FACTUS_TAX_DECISION_CONFIRMED` | Bloqueo tributario | **Contador** | false → true (al confirmar) | sí |
| `FACTUS_COMPANY_NIT/DV/NAME/...` | Datos del emisor (RUT) | **Cliente** | mismos | sí |
| `FACTUS_DEFAULT_MUNICIPALITY_CODE` | Municipio DIAN (41001 Neiva) | Cliente/Factus | mismo | sí |
| `FACTUS_DEFAULT_*` (payment/unit/standard/tax) | Defaults de catálogo | Factus | mismos | sí |
| `FACTUS_CONSUMER_FINAL_*` | Consumidor final | DIAN | mismos | sí |
| `FACTUS_MEMBERSHIPS_AUTO_EMIT` / `FACTUS_PRODUCT_SALES_AUTO_EMIT` | Emisión automática | operación | false (a solicitud) | no |

## Checklist — datos que debe entregar **el CONTADOR**
- [ ] Tratamiento de IVA de **membresías**: excluido / exento / IVA 19% (incluido o no).
- [ ] Confirmar `tax_rate_id` correcto para **cada plan** (CRM → Facturación → Configuración fiscal).
- [ ] Visto bueno para poner `FACTUS_TAX_DECISION_CONFIRMED=true`.

## Checklist — datos que debe entregar **FACTUS/Halltec**
- [ ] Credenciales **producción** (`client_id`, `client_secret`, `username`, `password`).
- [ ] `base_url` productiva confirmada (`https://api.factus.com.co`).
- [ ] **Rango de factura** productivo (`numbering_range_id`) con resolución DIAN vigente.
- [ ] **Rango de nota crédito** productivo.
- [ ] Confirmar códigos de catálogo: municipio `41001`, tributo IVA `01`, forma/medio de pago.

## Verificación (sin llamar a Factus producción)
```
php artisan config:clear
php artisan billing:factus-doctor      # corregir hasta LISTO PARA PRODUCCIÓN
```
El doctor BLOQUEA si: faltan credenciales/rangos/municipio/emisor, decisión tributaria
en false, planes/productos activos sin `tax_rate_id`, **o** `APP_ENV=production` con
`FACTUS_ENV=sandbox`.

Ver también: `FACTUS_PRODUCTION.md` y `FACTUS_GO_LIVE_CHECKLIST.md`.
