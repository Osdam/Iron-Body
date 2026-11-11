# Informe de base de datos · Enmienda M-1 del Hito 5 (paquete 3 de `STUDIO_MOTORES_PLAN.md`)

database-specialist, 11-11-2026. Trabajo en local, sin commit. Base: `feat/ultron-studio-h9`, HEAD `da80942b`, con trabajo sin commit de otros agentes.

## 1. Motor y versión

- **Producción:** PostgreSQL 14. Tiene 0 filas en `ultron_studio_publications`; el dato lo dio el lead y aquí no lo he comprobado.
- **Puerta obligatoria:** PostgreSQL 14.24 desechable en `127.0.0.1:55432`, base `ultron_studio_db_esq`, con `ops/pgunit.sh`.
- **Suite:** SQLite.

## 2. Qué se entrega

| Fichero | Qué es |
|---|---|
| `database/migrations/2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications.php` | Migración nueva |
| `docs/ultron-studio/ops/motores-schema-check.sql` | Comprobación de solo lectura, para antes y después de `ironbody-migrate` |
| `tests/Feature/UltronStudio/UltronStudioEngineConfigV2SchemaTest.php` | Prueba de esquema nueva, con 8 casos (E13) |
| `tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php` | Cambia una línea, nada más. La prueba exigía que PG rechazara `ultron-engine-config/2`; ahora exige el rechazo de `/3`. Es la consecuencia directa de la enmienda, y no se ha tocado ningún recuento |

## 3. Qué hace en cada motor

### PostgreSQL

**`up()`**, todo dentro de una transacción (un SAVEPOINT dentro de la del migrador):

1. Pone `SET LOCAL lock_timeout = '5s'` si la sesión no trae ningún límite. Si lo trae (el de `ironbody-migrate`), manda ese.
2. Toma la huella de partida:
   - el número de CHECK en `ultron_studio_%`;
   - cuántos `uspub_engine_contract_chk` hay, si están validados y cómo los escribe PG;
   - los disparadores de la cadena y su estado;
   - la ACL (`relacl`);
   - las filas por contrato.
3. Se niega en tres casos: si el CHECK no existe exactamente una vez, si ya admite `/2` (no amplía dos veces) o si no admite `/1`.
4. Sustituye el CHECK en una sola sentencia:
   `ALTER TABLE ultron_studio_publications DROP CONSTRAINT uspub_engine_contract_chk, ADD CONSTRAINT uspub_engine_contract_chk CHECK (engine_contract IN ('ultron-engine-config/1', 'ultron-engine-config/2'))`
5. Vuelve a tomar la huella. Exige un CHECK validado con `/1` y `/2`, y que lo demás no haya cambiado: el mismo número de CHECK, los mismos disparadores, la misma ACL y las mismas filas por contrato. Si algo no cuadra, lanza la excepción y la migración no se registra.

**`down()`**:

1. Pone el mismo `lock_timeout`.
2. Ejecuta `LOCK TABLE ultron_studio_publications IN ACCESS EXCLUSIVE MODE` antes de contar, para que nadie inserte un `/2` entre el recuento y el ALTER.
3. Se niega si hay alguna entrada con un contrato distinto de `/1`. El mensaje dice cuántas hay de cada contrato y remite a R10.
4. Restaura el CHECK exacto del H5 con `DROP CONSTRAINT IF EXISTS … , ADD CONSTRAINT …`. La expresión no se copia: se lee de `2026_10_25_100002::DOMAIN_CHECKS`, así hay una sola fuente de verdad.
5. Comprueba la misma huella que `up()`.
6. Nunca borra filas.

**El blindaje de la tabla no cambia, y se comprueba:**

- disparadores: `uspub_forbid_update`, `uspub_forbid_delete`, `uspub_forbid_truncate` y `uspub_chain_next`, los cuatro activos (`O`);
- el resto de CHECK: `uspub_action_chk`, `uspub_reason_chk` y `uspub_seq_chk`;
- el REVOKE UPDATE, DELETE del H7;
- columnas, índices, FKs y filas.

**SQL exacto, sacado de `--pretend`:**

```sql
-- up()
SET LOCAL lock_timeout = '5s';
ALTER TABLE ultron_studio_publications DROP CONSTRAINT uspub_engine_contract_chk, ADD CONSTRAINT uspub_engine_contract_chk CHECK (engine_contract IN ('ultron-engine-config/1', 'ultron-engine-config/2'));
-- down()
SET LOCAL lock_timeout = '5s';
LOCK TABLE ultron_studio_publications IN ACCESS EXCLUSIVE MODE;
ALTER TABLE ultron_studio_publications DROP CONSTRAINT IF EXISTS uspub_engine_contract_chk, ADD CONSTRAINT uspub_engine_contract_chk CHECK (engine_contract IN ('ultron-engine-config/1'));
```

**Cómo queda escrito en PG:**

- Antes: `CHECK (((engine_contract)::text = 'ultron-engine-config/1'::text))`
- Después: `CHECK (((engine_contract)::text = ANY ((ARRAY['ultron-engine-config/1'::character varying, 'ultron-engine-config/2'::character varying])::text[])))`

### SQLite

**No cambia nada:** `up()` y `down()` vuelven sin hacer nada. **No hay equivalente que ampliar**, y la prueba lo comprueba sobre `sqlite_master`:

- el `CREATE TABLE` de la cadena no tiene ningún CHECK. SQLite no admite añadir restricciones a una tabla existente, y el H5 las dejó solo para PG;
- los cuatro disparadores de la cadena (`uspub_chain_next`, `uspub_forbid_delete`, `uspub_forbid_update` y `uspub_seq_min`) no nombran ni `engine_contract` ni `engine-config`.

En SQLite el contrato lo vigila el Studio, que es el único que escribe en la cadena.

## 4. Impacto

- **Recuentos: ninguno cambia.** La migración sustituye un CHECK; no suma ni quita nada. Sobre la base desechable, medido con `motores-schema-check.sql` antes y después de la migración:

  | Indicador | Antes | Después |
  |---|---|---|
  | `check_studio` | 153 | 153 |
  | `check_cadena` | 4 | 4 |
  | `disparadores_cadena` | 4 | 4 |
  | Huella de la ACL | igual | igual |

  La cifra 153 incluye las dos migraciones de plantillas de Meta, que están en disco sin commit. Sin ellas, la cifra que fija `UltronStudioPublicationSchemaTest` es 141.
- **Escrituras:** ninguna degradación. Insertar evalúa un `= ANY` de dos elementos en vez de una igualdad.
- **Espacio:** nada.
- **Otras queries:** ninguna. El lector del motor no cambia.

## 5. Riesgos, cerrojos y vuelta atrás

- **Cerrojo:** ACCESS EXCLUSIVE sobre `ultron_studio_publications` durante el ALTER. Con 0 filas, la validación del CHECK es instantánea: la migración tardó entre 13 y 24 ms en local. ACCESS EXCLUSIVE choca también con un SELECT dentro de una transacción abierta, como la del lector del motor (`UltronPublishedConfig`, una lectura por commit de ULTRON con la llave del Studio encendida). En ese caso:
  - la migración espera como mucho el `lock_timeout` de 5 s;
  - después cae limpia (55P03), no se registra y se reintenta;
  - mientras espera, las lecturas nuevas de la cadena hacen cola detrás de ella, como mucho esos 5 s.
- **Antes de migrar:** la tercera consulta de `motores-schema-check.sql` enseña los cerrojos que haya sobre la cadena. Lo esperado son 0 filas.
- **Vuelta atrás:**
  - Sin entradas `/2`: `down()` deja el CHECK del H5 exacto.
  - Con alguna entrada `/2`: `down()` se niega. Es el punto de no retorno de R10, y es inocuo: la cadena es de solo inserción. El procedimiento es primero «volver a Legacy» en el Studio, que escribe `/1`, y después devolver el código. El esquema se queda.
  - En producción no se ejecuta `migrate:rollback`.
- **Si alguien quitara la negativa de `down()`:** el ALTER del H5 caería igualmente con 23514 («violated by some row»). Lo vi en la prueba de mutación. Es decir, ni siquiera sin la negativa se pierde o se borra una fila.

## 6. Operaciones pendientes de autorización

- **E0** (lo hace el lead, con orden del propietario): `ironbody-migrate` en producción, con `motores-schema-check.sql` antes y después:
  `sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v app=iron_app -x -d ironbody < /root/motores-schema-check.sql`
- Yo no he ejecutado nada en producción.

## 7. Qué verifiqué ejecutando de verdad

| Comando | Resultado |
|---|---|
| `php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/UltronStudioEngineConfigV2SchemaTest.php` (SQLite) | 8 casos: 2 pasan y 6 se omiten por ser solo de PG |
| `UNIT_DB=ultron_studio_db_esq bash docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/UltronStudio/UltronStudioEngineConfigV2SchemaTest.php` | 8 casos y 61 aserciones: 7 pasan y 1 se omite por ser solo de SQLite |
| `pgunit.sh --filter 'los_check_de_la_cadena\|truncate_de_la_cadena\|down_se_niega' …/UltronStudioPublicationSchemaTest.php` | 4 de 4 en verde, 64 aserciones |
| Mutación: quitar la negativa de `down()` | La prueba `down_se_niega` falla (23514). Después restauré el fichero y comprobé con `cmp` que es idéntico |
| `migrate:rollback --step=1` → `motores-schema-check.sql` → `migrate` → `motores-schema-check.sql` (base desechable) | Solo cambian `migracion_m1` (0 → 1), `admite_2` (f → t) y la definición del CHECK |

**Qué cubre la prueba en PG:**

- `/1` y `/2` entran.
- `/3`, `/0`, mayúsculas, espacios delante o detrás, `/` solo y la cadena vacía se rechazan con **23514** y el nombre `uspub_engine_contract_chk`.
- Una entrada `/2` sigue siendo de solo inserción: UPDATE, DELETE y TRUNCATE dan P0001.
- `down()` se niega con una entrada `/2` sin tocar nada.
- La ida y vuelta funciona con entradas `/1`: `down()` deja exactamente la definición que PG escribe para la expresión del H5 (calculada sobre una tabla temporal que sirve de oráculo), y en cada sentido hay un solo ALTER.
- La ACL es explícita y no cambia.
- `up()` se niega a ampliar dos veces o a aplicarse sin el CHECK.
- 55P03 con otra transacción que lee la cadena.
- `lock_timeout = 5s` cuando la sesión no trae ninguno.

**Qué NO verifiqué:**

- Producción: ni la versión exacta, ni las 0 filas, ni la ACL real con `iron_app`. El clúster local no tiene `iron_app`: allí la ACL es la del dueño, y la prueba hace la ACL explícita con un `GRANT SELECT … TO PUBLIC` dentro de su propia transacción.
- Las carreras de publicar en `/2` (la otra mitad de E13): son del tester y dependen del paquete 2 del Studio, que todavía no escribe `/2`.
- La suite completa, por orden del lead (la máquina es de 8 GB).
- El resto de `UltronStudioPublicationSchemaTest`: sus recuentos absolutos cambian por las migraciones de plantillas (encargo B), no por esta.

## 8. Avisos para otros

- **tester / dueño de los fixtures:** `tests/fixtures/ultron-studio/publication-cases.README.md:134` dice que en PostgreSQL `engineContract = "ultron-engine-config/2"` no entra porque el CHECK lo rechaza. Con esta migración ya entra. No lo he editado porque el fichero no es mío.
- **bm-motor:** `tests/Unit/Marketing/UltronPublishedConfigTest.php::sinElCheckDelContrato()` quita el CHECK para probar `contract_unknown` con `/2`. Sigue funcionando (`DROP … IF EXISTS`), pero con `/2` como contrato válido ese caso pierde el sentido; puede pasar a `/3`. No lo he tocado.
