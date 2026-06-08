# Cobertura Colombia — Módulo Nutrición Iron Body

Iron Body está orientada a Colombia. La base nutricional **prioriza productos
vendidos en Colombia** (cadenas como D1, Éxito, Carulla, Olímpica, Ara, Jumbo,
Metro, Alkosto, PriceSmart, Colsubsidio… y marcas locales como Alpina, Zenú,
Colanta, Postobón, Diana, Ramo, Noel, etc.).

> **Importante (expectativa realista):** ninguna fuente externa garantiza el
> 100% de los productos del país. La estrategia productiva combina **máxima
> cobertura Colombia + base propia Iron Body + caché + importador masivo +
> OCR/manual** para cerrar los vacíos de forma progresiva con los usuarios.

---

## 1. Estrategia Colombia

La cobertura se construye en capas, de mayor a menor prioridad:

1. **Alimentos creados/completados por el usuario** (su despensa real).
2. **Base propia Iron Body** (alimentos verificados y completos).
3. **Productos Colombia completos** (país = colombia, o señales de cadena/marca).
4. **Productos de cadenas/marcas priorizadas** (D1/Éxito/Olímpica/Ara + marcas seed).
5. **Open Food Facts cacheado** (consultas en vivo por barcode/nombre, cacheadas).
6. **USDA** para genéricos (arroz, pollo, huevo…).
7. **Productos incompletos** al final, con badge **“Datos incompletos”** (nunca
   se muestran macros en 0 como válidos).

### Señales de priorización Colombia

El servicio `NutritionColombiaClassifier` detecta que un producto pertenece a
Colombia mediante cualquiera de estas señales (suma un *priority score*):

| Señal                                   | Origen                       | Puntos |
|-----------------------------------------|------------------------------|:-----:|
| `countries_tags` contiene `colombia`    | Open Food Facts / dump       | 50 |
| `stores` contiene una cadena colombiana | `colombia_retailers` (.env)  | 30 |
| `brand` coincide con marca colombiana   | `colombia_brand_seeds` (.env)| 20 |
| `barcode` empieza por prefijo GS1       | `colombia_barcode_prefixes`  | 10 |

> Los **importados vendidos en Colombia NO se excluyen**: basta que su
> `countries_tags` incluya `colombia` (o que se vendan en una cadena del país).
> El prefijo `770` solo **prioriza**; no es un filtro excluyente.

---

## 2. Configuración (.env)

```env
NUTRITION_OFF_COLOMBIA_ENABLED=true
NUTRITION_OFF_COLOMBIA_RETAILERS="D1,Tiendas D1,Éxito,Exito,Carulla,Surtimax,Super Inter,Olímpica,Olimpica,Ara,Jumbo,Metro,Alkosto,PriceSmart,Colsubsidio,Euro,La 14,Popular"
NUTRITION_OFF_COLOMBIA_BRAND_SEEDS="D1,Éxito,Exito,Carulla,Ara,Olímpica,Olimpica,Zenú,Zenu,Colanta,Alpina,Alquería,Alqueria,Noel,Ramo,Jet,Postobón,Postobon,Hatsu,Fruco,La Muñeca,Diana,Roa,Florhuila,Doña Gallina,Margarita,Nestlé,Nestle,Kellogg,Quaker,Bimbo,Colombina,Frutiño,Frutino,Juan Valdez,Sello Rojo,Águila Roja,Aguila Roja"
NUTRITION_OFF_COLOMBIA_BARCODE_PREFIXES="770"
NUTRITION_OFF_IMPORT_COLOMBIA_PRIORITY=true
```

Tras editar `.env`:

```bash
php artisan config:clear && php artisan config:cache
```

---

## 3. Descargar el dump de Open Food Facts

El importador trabaja con un **dump local** (no martillea la API en vivo).
Open Food Facts publica exports completos:

- **CSV** (todos los productos, ~varios GB comprimidos):
  <https://world.openfoodfacts.org/data> → *“CSV export”*
  (`https://static.openfoodfacts.org/data/en.openfoodfacts.org.products.csv.gz`)
- **JSONL** (un producto por línea):
  `https://static.openfoodfacts.org/data/openfoodfacts-products.jsonl.gz`

Descomprime el archivo:

```bash
gunzip en.openfoodfacts.org.products.csv.gz
# o
gunzip openfoodfacts-products.jsonl.gz
```

> Consejo: para Colombia puedes prefiltrar el dump con `grep`/`zgrep` por
> `colombia` antes de subirlo (reduce mucho el tamaño), p. ej.:
> `zgrep -i 'colombia' openfoodfacts-products.jsonl.gz > colombia.jsonl`

---

## 4. Subir el dump al VPS

```bash
scp colombia.jsonl usuario@TU_VPS:/var/www/iron-body/storage/app/off/
# o usa rsync para archivos grandes:
rsync -avP colombia.jsonl usuario@TU_VPS:/var/www/iron-body/storage/app/off/
```

Asegúrate de que el archivo sea legible por el usuario de PHP/Laravel.

---

## 5. Importar productos Colombia

El comando es **opcional, reanudable y por lotes** (no carga todo en memoria).
Guarda incompletos como incompletos (NUNCA macros en 0) y hace *upsert* por
`barcode`.

```bash
# Todos los productos con countries_tags=colombia
php artisan nutrition:off-import --file=/ruta/products.csv --country=colombia --limit=50000

# Solo ciertas cadenas
php artisan nutrition:off-import --file=/ruta/products.jsonl --country=colombia --stores="D1,Éxito,Olímpica,Ara" --limit=50000

# Solo marcas colombianas configuradas (seeds)
php artisan nutrition:off-import --file=/ruta/products.csv --brand-seeds --country=colombia --limit=50000

# Reanudar una importación interrumpida
php artisan nutrition:off-import --file=/ruta/products.jsonl --country=colombia --resume

# Estadísticas (incluye desglose Colombia con --country)
php artisan nutrition:off-import --stats --country=colombia
```

### Salida del importador (conteos)

```
Resumen → procesados · creados · actualizados · completos · incompletos · omitidos · errores
Colombia → detectados · marcas Colombia · D1 · Éxito · Olímpica · Ara
```

### Opciones

| Opción            | Efecto |
|-------------------|--------|
| `--file=`         | Ruta del dump local (`.csv` o `.jsonl`). |
| `--country=`      | Filtra por `countries_tags` (no excluye importados con tag colombia). |
| `--stores=`       | Solo productos cuyo `stores` contenga alguna de las cadenas (CSV). |
| `--brand-seeds`   | Solo productos cuya marca coincida con `colombia_brand_seeds`. |
| `--limit=`        | Máximo de productos a procesar. |
| `--resume`        | Reanuda desde el último cursor guardado. |
| `--stats`         | Solo estadísticas (no importa). |

---

## 6. Limitaciones reales

- **No existe el 100% garantizado** por ninguna API: faltan productos locales,
  marcas regionales y presentaciones específicas.
- Open Food Facts es colaborativo: muchos productos colombianos están
  **incompletos** (sin macros). Por eso se guardan como *incompletos* y se
  completan con OCR/manual, nunca con ceros falsos.
- Los prefijos de barcode (`770`) **no cubren importados**: por eso son una
  señal de prioridad, no un filtro.

---

## 7. Mejora progresiva con usuarios / OCR

Cuando un producto **no aparece** (búsqueda o barcode), la app ofrece un flujo
útil para cerrarlo en ~30 segundos:

1. **Escanear etiqueta nutricional** (OCR Tesseract → revisión → guardar).
2. **Crear manualmente** (formulario con validación).
3. **Buscar por nombre** (vuelve a la búsqueda).
4. **Escanear otro código**.

El producto completado queda guardado (`source=user|ocr`, `is_public=false` por
defecto) y disponible para ese usuario y la base Iron Body en el siguiente
escaneo. En un futuro puede añadirse **moderación** para promover a públicos los
productos completados y verificados, ampliando la cobertura para toda la
comunidad.

> Nota: el OCR **solo propone** valores; el usuario **siempre confirma** antes de
> guardar. Ver `app/Services/Nutrition/NutritionOcrService.php` y
> `TesseractNutritionOcrProvider.php`.

---

## 8. Base comunitaria (retroalimentación con control de calidad)

Cuando un usuario crea un producto con **código de barras nuevo**, ese producto
pasa a ser una **contribución comunitaria** disponible para todos:

| Caso | source | visibility | verification_status | visible a otros |
|------|--------|-----------|---------------------|:---------------:|
| Crea SIN barcode | `user` | `private` | `private` | No (solo él) |
| Crea CON barcode nuevo | `community` | `community` | `community` | Sí — “Aportado por la comunidad” |
| Staff lo verifica | — | `verified` | `verified` | Sí — “Verificado Iron Body” |
| Reportado ≥ umbral (no verificado) | — | — | — | Oculto hasta revisión |
| Rechazado por staff | — | — | `rejected` | No |

**Calidad y duplicados:**
- Macros validados (no negativos, topes razonables). Incompletos = `incomplete`,
  nunca se muestran 0 como válidos.
- **Anti-duplicado por barcode:** creación dentro de `DB::transaction` +
  `lockForUpdate`. Si el barcode ya existe: se completa (si estaba incompleto) o
  se devuelve el existente (**idempotente**, `deduplicated:true`), nunca duplica.
- **Anti doble-tap** sin barcode: dedup por (miembro + nombre normalizado +
  calorías) dentro de `NUTRITION_COMMUNITY_IDEMPOTENCY_WINDOW` segundos.
- **Confirmaciones:** cuando OTRO usuario usa por primera vez un producto
  comunitario, sube `community_confirmations_count` (no lo verifica).
- **Reportes:** `POST /api/nutrition/foods/{uuid}/report` → `reports_count++`; al
  alcanzar `NUTRITION_COMMUNITY_REPORTS_HIDE_THRESHOLD` se oculta de búsquedas
  (salvo verificados).

**Moderación (CRM admin):**
- `GET  /api/admin/nutrition/foods/pending`
- `GET  /api/admin/nutrition/foods/{uuid}`
- `POST /api/admin/nutrition/foods/{uuid}/verify`
- `POST /api/admin/nutrition/foods/{uuid}/reject`
- `POST /api/admin/nutrition/foods/{uuid}/merge` (fusiona duplicado → canónico,
  re-apunta entradas/favoritos/recientes).

**.env comunidad:**
```env
NUTRITION_COMMUNITY_REPORTS_HIDE_THRESHOLD=3
NUTRITION_COMMUNITY_IDEMPOTENCY_WINDOW=15
```

> Los datos comunitarios **no se presentan como certificados** mientras no estén
> verificados por staff (badge “Aportado por la comunidad” vs “Verificado Iron Body”).
