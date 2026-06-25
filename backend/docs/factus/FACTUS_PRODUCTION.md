# Factus — Activación productiva (Iron Body Neiva)

Runbook para pasar facturación electrónica de sandbox a producción.
**No se asume IVA**: membresías y productos quedan bloqueados hasta que el
contador confirme su tratamiento (`FACTUS_TAX_DECISION_CONFIRMED`).

## Datos del emisor (RUT) — se configuran en el panel de Factus
- NIT/identificación: **1075265137**, DV **1** — Persona natural, Cédula.
- Nombre fiscal: **PAJOY MEDINA FREDY ALBERTO** · Comercial: **IRON BODY NEIVA**.
- Municipio **Neiva 41001**, Depto **Huila 41**, País Colombia.
- Responsabilidades **05** (renta ordinario), **52** (facturador electrónico) · actividad **9311**.
- Correo **fredypajoy52@gmail.com** · Tel **3125191764**.
- Tipo organización Factus: persona natural → `legal_organization_code=2`.
- (El NIT/responsabilidades NO van en el payload: Factus los deriva de la cuenta.)

## Variables `.env` productivas
```
FACTUS_ENABLED=false                 # se activa al final
FACTUS_ENV=production
FACTUS_BASE_URL=https://api.factus.com.co
FACTUS_CLIENT_ID=...
FACTUS_CLIENT_SECRET=...
FACTUS_USERNAME=...
FACTUS_PASSWORD=...
FACTUS_NUMBERING_RANGE_ID=<rango factura prod>
FACTUS_CREDIT_NUMBERING_RANGE_ID=<rango nota crédito prod>
FACTUS_COMPANY_NIT=1075265137
FACTUS_COMPANY_DV=1
FACTUS_COMPANY_NAME=PAJOY MEDINA FREDY ALBERTO
FACTUS_COMPANY_EMAIL=fredypajoy52@gmail.com
FACTUS_COMPANY_PHONE=3125191764
FACTUS_COMPANY_ADDRESS=CR 33 B 23 35
FACTUS_COMPANY_CITY_CODE=41001
FACTUS_COMPANY_DEPARTMENT_CODE=41
FACTUS_DEFAULT_MUNICIPALITY_CODE=41001
FACTUS_DEFAULT_LEGAL_ORGANIZATION_CODE=2
FACTUS_DEFAULT_TRIBUTE_CODE=ZZ
FACTUS_CONSUMER_FINAL_DOCUMENT_TYPE=13
FACTUS_CONSUMER_FINAL_DOCUMENT_NUMBER=222222222222
FACTUS_TAX_DECISION_CONFIRMED=false  # 🔒 true SOLO cuando el contador confirme IVA
```

## Validaciones duras (bloquean la activación)
`php artisan billing:factus-doctor` (read-only, sin red) exige:
credenciales · base_url productiva · rango factura · rango nota crédito ·
municipio por defecto · datos del emisor (nit/dv/name) ·
**decisión tributaria confirmada** · ningún plan/producto activo sin `tax_rate_id`.

Doble red: aunque se ponga `FACTUS_ENABLED=true`, los jobs de emisión NO emiten
en producción si `isReadyForProduction()` es false (la factura queda `pending`).

## Activación controlada
1. Editar `.env` productivo (arriba) con `ENABLED=false`, `TAX_DECISION_CONFIRMED=false`. `php artisan config:clear`.
2. `php artisan billing:factus-doctor` → corregir hasta que solo falte la decisión tributaria.
3. Contador confirma → asignar `tax_rate_id` a cada Plan/Producto + `FACTUS_TAX_DECISION_CONFIRMED=true`. `config:clear`. Doctor → **LISTO**.
4. `FACTUS_ENABLED=true`. `config:clear`. Worker `php artisan queue:work --queue=billing` + cron `schedule:run`.
5. Smoke productivo: 1 pago real pequeño → factura real → verificar CUFE/QR/PDF/XML + portal DIAN.
6. Monitorear `electronic_invoices` (error/rejected) y `failed_jobs`.

## Rollback
- Inmediato: `FACTUS_ENABLED=false` + `config:clear`. Deja de emitir; pagos y CRM intactos (facturación best-effort, desacoplada del cobro).
- Facturas en vuelo quedan `pending`/`error` (no se pierden).
- Comprobante mal emitido: **nota crédito** (`/credit-note`). En producción NUNCA se borra un comprobante DIAN.
- `.env` se edita manualmente; ningún proceso lo toca solo.
