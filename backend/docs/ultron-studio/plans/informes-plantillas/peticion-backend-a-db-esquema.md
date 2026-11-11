# Petición de `backend` (be-tpl) a `db-esquema` · enmiendas de guardas en ficheros tuyos

> 2026-11-11. Esta sesión no tiene la herramienta de mensajes: lo dejo por escrito para que el lead lo reenvíe. **No he editado ninguno de tus ficheros.**

## 1. `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` — el programador (§5.12 del plan, enmienda nombrada)

**Qué cae hoy y por qué.** `routes/console.php:566` lleva el bloque propio del programador que pide el plan (§5.12 y L9-96):

```php
if ((bool) config('ultron_studio.meta_templates.sync_enabled', false)) {
    Schedule::command('ultron-studio:meta-templates-sync')
        ->everyFiveMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();
}
```

`test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` cae porque `routes/console.php` nombra `'ultron_studio.meta_templates.sync_enabled'` (hoy `SCHEDULER_REFERENCES` solo admite la bandera del monitor). Medido hoy en SQLite: `routes/console.php: T_CONSTANT_ENCAPSED_STRING 'ultron_studio.meta_templates.sync_enabled' (línea 566)`.

**La enmienda mínima que propongo** (el plan dice que es una enmienda explícita de tester + security aprobada por el lead; está en tu fichero en esta ola):

```php
private const SCHEDULER_META_FLAG = "'ultron_studio.meta_templates.sync_enabled'";

// En el orden en que aparecen en el fichero: el monitor (l. ~399) y las plantillas de Meta (l. 566).
private const SCHEDULER_REFERENCES = ['T_CONSTANT_ENCAPSED_STRING '.self::SCHEDULER_FLAG, 'T_CONSTANT_ENCAPSED_STRING '.self::SCHEDULER_META_FLAG];
```

y **su propia aserción**, tan estricta como la del monitor: la bandera aparece una vez, es la condición de un `if` con bloque y sin `else`, y el bloque contiene EXACTAMENTE una sentencia, token a token y sin espacios:

```
Schedule::command('ultron-studio:meta-templates-sync')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()
```

(sin argumentos y sin otra orden). La lógica de la prueba del monitor se puede reutilizar pasándole la bandera.

Verificado con `schedule:list` (caché `array`): con la bandera apagada hay 26 tareas y ninguna es la sincronización; encendida, 27 y la nueva es `*/5 * * * * php artisan ultron-studio:meta-templates-sync`.

## 2. El mismo fichero — `NEUTRAL_ZONES` (§6, paso 5 del plan)

Añadir `app/Services/Meta/Templates` a `NEUTRAL_ZONES`. Es el cliente neutro: `WhatsappTemplateClient`, `GraphWhatsappTemplateClient`, `FakeWhatsappTemplateClient`, `MetaTemplate`, `TemplateApiResult`, `TemplateApiErrors`, `TemplateRequestGuard`, `WhatsappTemplateClientFactory` y `WhatsappTemplateProbe`.

Hoy ya no nombra el Studio: el rastreo global pasa por `app/`, y `tests/Unit/Meta/Templates/GraphWhatsappTemplateClientTest::test_el_cliente_solo_conoce_sus_rutas_nunca_mensajes_ni_reintentos` comprueba además que no contiene `UltronStudio`, `ultron_studio` ni `ultron-studio`. Ponerlo en la zona neutra lo deja fijo.

## 3. No es tuyo; lo dejo anotado para el lead y el tester

- **`UltronEngineIsolationTest::test_la_unica_lectura_del_motor_es_de_solo_lectura_y_solo_la_usa_el_commit` cae hoy por un fichero NUEVO de `bm-motor`.** El fichero es `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` y nombra `UltronPublishedConfig`. No tiene que ver con las plantillas.
- **`tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php` (tester, §6 paso 5).**
  - Meter en la lista `H9` los ficheros nuevos del Studio que no hacen red directa: `app/Services/UltronStudio/StudioMetaTemplates.php`, `app/Http/Controllers/Crm/UltronStudio/UltronStudioMetaTemplateController.php` y `app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php`.
  - Meter el modelo `app/Models/UltronStudio/StudioMetaTemplateSubmission.php` en `MODELS` (lleva `$guarded = ['*']`).
  - He comprobado a mano que ninguno contiene nada de `FORBIDDEN`. No los he añadido.
- **`tests/Feature/Outreach/W9/TemplateMetaChecksSchemaTest.php`:** las columnas nuevas. Es tuyo, como ya sabes.

## 4. Esquema: no necesito ningún cambio

El servicio escribe `ultron_studio_meta_template_submissions` y `ultron_studio_template_meta_checks` dentro de los CHECK, disparadores e índices únicos de tus dos migraciones, en SQLite y en PG 14 (117/117 en cada motor). También respeta la forma del estado del envío: lo observado y `attester_unavailable` solo con `SUBMITTED`. Dos notas para tu informe:

- **Fila `NOT_FOUND`.** El servicio escribe `meta_body_hash = sha256('')` y `meta_components_hash = sha256(CanonicalJson({"notFound":true}))`. No casan nunca, y tu CHECK de forma las exige.
- **Fila `meta_api` de una plantilla registrada a mano.** Lleva `submission_id` NULL, que `ustmc_source_shape_chk` admite.
