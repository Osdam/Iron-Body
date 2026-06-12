# Wompi — Validación en Sandbox

La cuenta Wompi está en **revisión / Sandbox**. Esta integración queda
completamente funcional en Sandbox real (sin respuestas falsas). Para validar:

## Prerrequisitos

1. Credenciales **de prueba** de Wompi en `.env` (`pub_test_*`, `prv_test_*`,
   `test_integrity_*`, `test_events_*`).
2. URL de eventos pública (sandbox) registrada en el dashboard de pruebas.
3. App apuntando al backend de pruebas (`--dart-define=BACKEND_BASE_URL=...`).

## Escenarios oficiales a probar

| Escenario | Resultado esperado |
|---|---|
| Card aprobada | `approved`, membresía activa una vez |
| Card rechazada | `declined`, sin activación |
| Card error | `error`, mensaje controlado |
| Card 3DS | `requires_action` → WebView → estado final por webhook |
| PSE aprobada | WebView banco → `approved` por webhook |
| PSE rechazada | `declined` |
| Nequi aprobada | push → `approved` |
| Nequi rechazada | `declined` |
| DaviPlata (si habilitado) | OTP válido → `approved`; OTP inválido/vencido → controlado |
| Pending | polling resuelve o queda pendiente con refresco |
| Webhook duplicado | 200 idempotente, sin doble activación |
| Webhook retrasado | reconciliación lo resuelve antes |
| Conexión perdida | reconciliación recupera el estado |
| Monto alterado en webhook | rechazado (no aprueba) |

## Comandos

```bash
php artisan test                       # backend (incluye Wompi)
php artisan test tests/Feature/Wompi   # flujo Wompi
php artisan test tests/Unit/Wompi      # firma + máquina de estados
php artisan route:list | grep -i wompi
php artisan payments:wompi-reconcile
```

```bash
flutter analyze
flutter test test/payments/
```

## Tarjetas de prueba Wompi (referencia)

Usar las tarjetas de prueba que publica Wompi en su documentación de Sandbox
(aprobada / rechazada). **No** usar tarjetas reales en Sandbox.

## Pendientes externos (no falsear)

- [ ] Wompi aún no aprobó **producción** (cuenta en revisión).
- [ ] **DaviPlata**: habilitación comercial + validar ciclo OTP real en sandbox.
- [ ] **3D Secure**: confirmar habilitación en la cuenta si se requiere.
- [ ] Registrar la **URL de eventos** en el dashboard.
- [ ] **Rotar** las credenciales expuestas previamente.
- [ ] Prueba con **dinero real** pendiente hasta producción.
