# Nutrición — Código de barras (EAN/UPC/GTIN) y OCR

## 1. Normalización de código de barras

`App\Services\Nutrition\BarcodeNormalizer`. Reglas:

- **Siempre STRING** — se preservan ceros a la izquierda; nunca se convierte a
  integer (perdería el cero inicial de muchos EAN-13/UPC-A).
- `clean()` quita espacios/guiones y deja solo dígitos.
- Longitud plausible: **8–14 dígitos** (8/12/13/14 son estándar; 9–11 se aceptan
  igual y se buscan tal cual — son recuperables, no se rechazan).
- Tipos: EAN-8, UPC-A (12), EAN-13, GTIN-14, UPC-E (8 con sistema 0/1).
- `canonical()`: forma para almacenar/buscar — UPC-A → EAN-13 (antepone `0`);
  GTIN-14 → EAN-13 si los ceros lo permiten.
- `variants()`: conjunto equivalente a probar (UPC-A↔EAN-13, GTIN-14, ceros de
  relleno, expansión UPC-E→UPC-A→EAN-13).
- `hasValidCheckDigit()`: valida el dígito de control GTIN (mod-10) pero **NO
  bloquea** — un dígito malo puede ser un producto recuperable; preferimos
  intentar resolver antes que rechazar.

## 2. Flujo de resolución

`App\Services\Nutrition\FoodBarcodeResolver::resolve()`:

1. `clean` + `isPlausible`. Si no es plausible → `invalid` / `reason: bad_read`.
2. Genera `variants()`.
3. **BD local** por cualquier variante (prefiere completos y mayor score
   Colombia). Si hay → `found` / `incomplete`.
4. **Proveedores** (Open Food Facts → Nutritionix) por las variantes; cachea
   bajo la forma **canónica**. Si hay → `found` / `incomplete`.
5. Si no aparece → `not_found` con un **motivo diferenciado**.

### Por qué un producto puede NO aparecer (`reason`)

| reason | significado |
|--------|-------------|
| `bad_read` | código implausible / mal leído por la cámara (o dígito de control inválido) |
| `provider_disabled` | no hay proveedor externo habilitado |
| `not_found_provider` | el proveedor no tiene ese código |
| `incomplete` | existe pero le faltan macros → se permite completar |
| (existe por nombre) | a veces el producto está indexado por nombre/marca pero no por barcode → la app ofrece “Buscar por nombre” |

Otros casos que el sistema distingue conceptualmente: **filtrado por país** o
**no importado** (el producto existe en el dump pero no se trajo con los filtros
del importador) — se resuelven trayéndolo bajo demanda del proveedor o ampliando
la importación. Logs: `nutrition:barcode:scan|resolved|not_found`.

### Respuesta `not_found`

```json
{
  "ok": false,
  "status": "not_found",
  "code": "food_barcode_not_found",
  "reason": "not_found_provider",
  "barcode": "0036000291452",
  "actions": ["create_manual", "scan_label", "search_by_name", "scan_another"]
}
```

La app preserva el barcode escaneado; si el usuario crea/OCR el producto, queda
asociado a ese código para próximas búsquedas.

## 3. OCR de etiquetas

`App\Services\Nutrition\NutritionLabelParser` (texto → macros). Motor:
Tesseract (`TesseractNutritionOcrProvider`) + preprocesamiento ImageMagick. Ver
`NUTRITION_OCR_DEPLOY.md` para instalación y límites de subida (413).

El parser detecta (ES/EN):

- Calorías/kcal (convierte kJ → kcal), proteína, carbohidratos (totales),
  grasa total, azúcares (totales), fibra, sodio (normalizado a mg; sal→sodio).
- Extras: grasa saturada, grasa trans, azúcares añadidos.
- Tamaño de porción: `30 g`, `1/3 de paquete (80 g)` (toma el peso entre
  paréntesis, no el “1/3”), `por 100 g`/`por 100 ml`, y formato en línea
  `Información Nutricional (100 g): Calorías 348, …`.
- Porciones por envase.
- Decimales con coma o punto; unidades g/mg/µg/kcal/kJ.
- **Nunca asume 0**: un campo no detectado queda `null` (la app lo marca como
  faltante). Confianza global y por campo (`field_confidence`).

### Errores comunes / troubleshooting

- **Tabla girada / empaque curvo / brillo / poca luz**: el OCR puede no leer.
  La app comprime y endereza (EXIF) y, si falla, ofrece reintentar / galería /
  manual / buscar por nombre — nunca inventa valores.
- **Confunde campos**: el parser usa etiquetas específicas (“grasa total” vs
  “grasa saturada”, “azúcares totales” vs “añadidos”) para no mezclar.
- **413 al subir**: ver `NUTRITION_OCR_DEPLOY.md` (nginx/PHP 16M + compresión
  cliente). El backend responde JSON `ocr_image_too_large`, nunca HTML.
