# Retiro de ePayco — Reporte

Retiro **funcional, no destructivo** de ePayco (y el Nequi-directo legado).
Wompi es la única pasarela activa.

## Qué se retiró (rutas activas)

**Backend** (`routes/api.php`, `routes/web.php`):
- `POST payments/epayco/{create,pay-card,pay-pse,pay-nequi,pay-daviplata,checkout-session,confirmation}`
- `GET  payments/epayco/{response,history}`
- `GET  payments/{reference}/status` (status público ePayco)
- `POST/GET payments/nequi/*` (Nequi-directo: confirmation, response, push, status, reverse)
- `GET  payments/epayco/checkout-bridge/{reference}` (web bridge)

**Flutter** (archivos borrados — código UI, sin datos):
`epayco_payment_service.dart`, `nequi_push_service.dart`,
`payment_processing_screen.dart`, `payment_pending_screen.dart`,
`pse_bank_authorization_screen.dart`, `checkout_bridge_screen.dart`,
`nequi_push_screen.dart`, `payment_approved_screen.dart`,
`payment_success_screen.dart`, `models/payment_transaction.dart`.

**Tests de ejecución legados borrados:** `EpaycoApifyClientTest`,
`EpaycoMemberPaymentTest`, `PaymentWalletFlowTest`, `NequiPushFlowTest`,
`test/payments/payment_transaction_test.dart` (Flutter).

**Textos visibles “ePayco” → neutral** en el form de billetera, recibo y PDF.

**`.env.example`:** se quitaron los bloques `EPAYCO_*`, `NEQUI_*` y
`PAYMENT_NEQUI_PROVIDER`.

## Qué se conservó (no destructivo)

- **Datos históricos**: la tabla `payments` (incl. `method=epayco`) y
  `payment_transactions` (incl. `provider=epayco`) intactas y legibles desde
  `GET /api/app/payments`.
- **Migraciones ya aplicadas**: ninguna editada. La de Wompi es aditiva y
  reversible.
- **`Payment::toPublicArray()`** sigue mapeando el provider histórico (`epayco`).
- **Clases backend ePayco/Nequi** (`EpaycoPaymentService`, `EpaycoApiClient`,
  `EpaycoApifyNequi`, `EpaycoPaymentController`, `NequiPaymentController`,
  `NequiPushPaymentService`) permanecen en el repo pero **sin rutas** (inalcanzables).
  Pueden borrarse en una limpieza posterior; se dejan por seguridad/lectura.
- **`config/services.php`** (bloque epayco/nequi) se dejó; ya no se lee en runtime.
- Dependencia composer `epayco/epayco-php`: se dejó (inocua). Puede quitarse con
  `composer remove epayco/epayco-php` cuando se borren las clases.

## Verificación

- `php artisan route:list | grep -i epayco` → vacío.
- Backend `php artisan test` → verde (tests legados retirados).
- Flutter `flutter analyze` sin issues nuevos; sin texto “ePayco” visible.

## Reactivación de emergencia

Como las clases siguen en el repo, reactivar ePayco temporalmente requeriría
restaurar las rutas (`git revert` del commit de retiro) y las variables `.env`.
No es un fallback automático: Wompi es la única pasarela activa por diseño.
