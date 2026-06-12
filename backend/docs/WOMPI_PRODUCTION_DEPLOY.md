# Wompi — Paso a producción

El cambio Sandbox → Producción **no requiere cambios de código**: solo
credenciales, ambiente y la URL de eventos en el dashboard.

## Variables `.env` (producción)

```env
WOMPI_ENV=production
WOMPI_PUBLIC_KEY=pub_prod_xxxxxxxx
WOMPI_PRIVATE_KEY=prv_prod_xxxxxxxx
WOMPI_INTEGRITY_SECRET=prod_integrity_xxxxxxxx
WOMPI_EVENTS_SECRET=prod_events_xxxxxxxx
WOMPI_API_URL=https://sandbox.wompi.co/v1
WOMPI_PRODUCTION_API_URL=https://production.wompi.co/v1
WOMPI_WEBHOOK_URL=https://api.ironbodyneiva.cloud/api/webhooks/wompi
WOMPI_RECONCILIATION_ENABLED=true
WOMPI_RECONCILIATION_MINUTES=5
# DaviPlata solo si Wompi lo habilitó comercialmente:
WOMPI_METHOD_DAVIPLATA=false
```

`WompiConfigValidator` aborta el arranque en producción si las llaves no son
`*_prod_*` o si la api_url no apunta a `production.wompi.co`.

## Variables ePayco a RETIRAR del `.env` real (ya no se usan)

```
EPAYCO_TEST, EPAYCO_PUBLIC_KEY, EPAYCO_PRIVATE_KEY, EPAYCO_P_CUST_ID_CLIENTE,
EPAYCO_P_KEY, EPAYCO_RESPONSE_URL, EPAYCO_CONFIRMATION_URL, EPAYCO_NEQUI_PATH,
EPAYCO_APIFY_BASE, EPAYCO_CHECKOUT_JS, EPAYCO_CHECKOUT_BRIDGE_TTL,
EPAYCO_CHECKOUT_METHODS_DISABLE, NEQUI_* , PAYMENT_NEQUI_PROVIDER
```
(Retirarlas es opcional; ya no se leen. No borrar datos históricos de la BD.)

## Dashboard Wompi

- Registrar la **URL de eventos**: `https://api.ironbodyneiva.cloud/api/webhooks/wompi`.
- Confirmar métodos habilitados (tarjeta, PSE, Nequi; DaviPlata si aplica).
- Confirmar 3D Secure habilitado si se requiere.

## App Flutter (producción)

```bash
flutter build appbundle --release \
  --dart-define=BACKEND_BASE_URL=https://api.ironbodyneiva.cloud
flutter build ios --release \
  --dart-define=BACKEND_BASE_URL=https://api.ironbodyneiva.cloud
```
La app toma la llave pública desde `GET /payments/wompi/config`, así que basta con
que el backend esté en producción.

## Health checks

```bash
php artisan route:list | grep -i wompi
php artisan schedule:list | grep -i wompi
php artisan payments:wompi-reconcile --limit=10
curl -s -X POST https://api.ironbodyneiva.cloud/api/webhooks/wompi \
  -H 'Content-Type: application/json' -d '{}'   # debe responder 400 controlado
```

## Rollback

La migración es **aditiva y reversible**:
```bash
php artisan migrate:rollback --step=1   # revierte 2026_06_11_000001
```
No borra datos históricos. Para revertir el código: `git revert <commit>` en cada
repo. (ePayco fue retirado como ruta activa; sus clases siguen en el repo si se
necesitara reactivarlo temporalmente.)
