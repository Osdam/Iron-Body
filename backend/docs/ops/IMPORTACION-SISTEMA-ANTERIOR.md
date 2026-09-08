# Importación de los export del sistema anterior

Cómo subir a Iron Body los tres export nativos del sistema viejo —clientes,
membresías y productos— sin borrar ni pisar nada de lo que ya hay dentro.

Los archivos vienen con extensión `.xls`, pero **no son Excel**: son CSV en
UTF-8 con BOM y delimitador `;`. Los comandos los leen tal cual salen del
sistema anterior; no hay que depurarlos a mano.

## Lo que hace cada comando

| Comando | Lee | Escribe |
|---|---|---|
| `iron:import-legacy-crm` | Listado de clientes + Membresías Todas | `users`, `members`, `payments` |
| `iron:import-legacy-products` | Listado de productos | `products`, `inventory_movements` |

## Garantías

Las tres reglas que hacen que esto se pueda correr sobre una base viva:

1. **No borra nada.** Ni filas, ni columnas, ni desactiva registros. Sólo crea
   y completa.
2. **Lo que ya está en Iron Body gana.** En una persona que ya existe sólo se
   rellenan los campos vacíos. Un teléfono corregido en el CRM no vuelve al
   valor viejo. La única excepción es el nombre, que sí se refresca porque
   recepción lo mantiene al día en el sistema anterior.
3. **La vigencia sólo se extiende.** Si alguien renovó dentro de Iron Body
   después de exportar, una membresía vieja del archivo no puede quitarle días.

Todo es **idempotente**: repetir la importación no duplica socios ni pagos.
La clave de un pago es `MIGR-<id de la membresía>`, y ese id lo asigna el
sistema anterior (los 9.360 registros del export son únicos). Los pagos que
cargó la migración anterior (`ImportLegacyMembersCommand`) usan esa misma
referencia, así que se reconocen y no se vuelven a crear.

## Orden de ejecución

El catálogo de planes va **primero**: un socio cuyo plan no existe como fila en
`plans` entra con todos los módulos de la app bloqueados aunque su membresía
esté vigente.

```bash
cd /ruta/al/backend

# 1. Catálogo de planes (idempotente)
php artisan db:seed --class='Database\Seeders\PlansSeeder' --force
php artisan db:seed --class='Database\Seeders\LegacyPlansSeeder' --force

# 2. Ensayo en seco: escribe todo y hace rollback. No deja nada.
php artisan iron:import-legacy-crm \
  --clientes="/ruta/Listado_de_clientes.xls" \
  --membresias="/ruta/Membresias_Todas.xls" \
  --dry-run

# 3. De verdad
php artisan iron:import-legacy-crm \
  --clientes="/ruta/Listado_de_clientes.xls" \
  --membresias="/ruta/Membresias_Todas.xls"

# 4. Productos e inventario
php artisan iron:import-legacy-products --archivo="/ruta/Listado_de_productos.xls" --dry-run
php artisan iron:import-legacy-products --archivo="/ruta/Listado_de_productos.xls"
```

Antes del paso 3, respaldo de la base (ver [BACKUPS.md](BACKUPS.md)). Todo corre
dentro de una transacción única —o entra completo, o no entra nada— pero un
respaldo previo es lo que permite volver atrás con calma si el resultado no
convence.

`--limit=N` procesa sólo los primeros N clientes, útil para ver el resumen sobre
una muestra antes de soltar el archivo entero.

## Qué esperar del export del 31/08/2026

3.770 clientes, 9.360 membresías, 23 productos.

- **49 fichas se omiten**: llevan la palabra `repetido` como identificación y
  como nombre. No son personas; como el documento es la clave, entrarían todas
  como un mismo socio y se pisarían entre sí. Quedan reportadas en el resumen.
- **177 clientes no tienen ninguna membresía.** Entran igual: son contactos
  reales del gimnasio.
- **8.766 membresías están vencidas.** Entran igual: son el historial de pagos.
  El socio queda con la vigencia que le corresponde —vencida— y la app le
  bloquea el acceso, que es el comportamiento correcto.
- **233 membresías anuladas** entran como pagos `cancelled`: traza sí, ingreso
  no. Y no cuentan para decidir la vigencia del socio.
- **~142 socios sin celular válido** no podrán recibir el OTP hasta que se les
  corrija el número en el CRM. También salen en el resumen.

### Traducción de planes

`MENSUALIDAD` en el export se llama `Plan Mensual` en el catálogo. Las
equivalencias están en `App\Support\LegacyPlanMap` y se comprobaron una a una
contra el precio y la duración reales de los 9.360 registros. Lo promocional o
de cortesía (`PROMO X4`, `INGRESO EMPLEADOS`, …) conserva su nombre y lo crea
`LegacyPlansSeeder` como plan histórico inactivo: no aparece en el catálogo de
compra de la app, pero resuelve los módulos del socio que lo tiene.

### Métodos de pago

`Efectivo` → `efectivo`, `Transferencia` → `transferencia`, `Pago por datáfono o
tarjeta` → `datafono`, y `manual` para el resto o cuando una misma membresía se
saldó con medios distintos. Los cuatro cuentan como cobro de mostrador en
`PaymentOriginInspector::MANUAL_PAYMENT_METHODS`: no pasan por pasarela, así que
la facturación electrónica no les exige una transacción que nunca existió.

## Productos

Los productos entran con SKU `LEG-<id del producto>` y **stock cero**; las
existencias las carga después `InventoryService` con origen `initial_stock`, que
es el único camino que escribe `products.stock` y deja la fila correspondiente
en `inventory_movements`. Así el inventario nace con historia auditable en vez
de con un saldo suelto.

Reimportar actualiza precios y datos del catálogo pero **no vuelve a cargar
existencias**: repetir la entrada inicial inflaría el stock sin que hubiera
entrado mercancía. Para cuadrar un saldo que ya se movió está el ajuste de
inventario del CRM, que deja constancia de quién lo hizo y por qué.

Por defecto los productos importados **no se publican** en la tienda de la app
(`visible_in_app = false`): publicar el catálogo de cafetería es una decisión
comercial, no un efecto de importar. Con `--visible-en-app` entran publicados, y
en cualquier momento se puede cambiar producto a producto desde Inventario.

Al reimportar se refrescan nombre, descripción, proveedor y precios —eso lo
mantiene al día el sistema anterior— pero **no** `active` ni `visible_in_app`:
esas se deciden en Iron Body. Un producto retirado del catálogo (borrado) no
vuelve por seguir apareciendo en el export; se restaura desde Inventario si se
quiere de vuelta.

Los productos de demostración que sembró `ProductSeeder` (`SUP-WHEY-2LB`,
`ACC-SHAKER`, …) **no se tocan**. Si ya no se venden, se desactivan desde el CRM
—queda traza— en vez de borrarlos.

## Reimportar con un export más nuevo

Mientras el gimnasio siga operando en el sistema anterior, cada export nuevo se
sube con los mismos comandos: lo ya cargado se reconoce por `MIGR-<id>` y solo
entra lo que falta. El `--dry-run` lo dice antes de escribir nada — en el export
del 07/09/2026, sobre una base que ya tenía el del 31/08:

```
users_nuevos 38 · users_actualizados 3731 · pagos_nuevos 148 · pagos_ya_estaban 9292
```

Dos cuadres que conviene comprobar en el ensayo:

- `users_nuevos + users_actualizados` == clientes del export − fichas «repetido»
- `pagos_nuevos + pagos_ya_estaban` == membresías del export − las de esas fichas

Si `pagos_ya_estaban` sale mucho más bajo de lo esperado, algo pasó con las
referencias y reimportar duplicaría ingresos: parar y revisar.

**Lo único que hay que mirar a mano es si aparecieron planes nuevos.** El
comando avisa («Planes del export que NO existen en `plans`»), pero para cuando
avisa el socio ya entró sin plan resuelto. Para verlo antes:

```bash
php -r '$fh=fopen($argv[1],"r"); $h=fgetcsv($fh,0,";");
  $h[0]=trim(preg_replace("/^\xEF\xBB\xBF/","",$h[0]),"\""); $i=array_flip(array_map("trim",$h));
  $p=[]; while(($c=fgetcsv($fh,0,";"))!==false) if(count($c)>5) $p[$c[$i["Nombre del plan"]]]=1;
  echo implode("\n",array_keys($p)),"\n";' /tmp/Membresias_Todas.xls
```

Cualquier nombre que no esté ya en `App\Support\LegacyPlanMap` ni en
`LegacyPlansSeeder` es nuevo. Si tiene el mismo precio y duración que un plan
del catálogo, va al mapa como equivalencia; si es promocional o de cortesía, al
seeder como plan histórico. Así apareció `TOTAL ACCESS PRO` (499.000 / 90 días
== plan `Pro`) en el export del 07/09/2026.

## Después de importar

```bash
php artisan tinker
>>> App\Models\User::whereDate('membership_end_date','>=',today())->count();  # socios vigentes
>>> App\Models\Payment::where('reference','like','MIGR-%')->count();          # pagos importados
>>> App\Models\Product::where('sku','like','LEG-%')->count();                 # productos importados
```

Conviene revisar en el CRM un socio vigente y uno vencido, y comprobar que el
módulo Inventario muestra los movimientos de carga inicial.

### Sobre el motor comercial

`MembershipCommercialObserver` descarta las vigencias que ya vencieron, así que
importar histórico no dispara bienvenidas retroactivas. Los socios ya existentes
a los que la importación les extienda la vigencia a una fecha **futura** sí
generan su evento de activación, que es correcto: esa membresía está viva. Si se
prefiere que la importación no genere ningún evento, basta con correrla con
`commercial.events_enabled` apagado.
