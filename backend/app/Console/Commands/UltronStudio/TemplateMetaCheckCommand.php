<?php

namespace App\Console\Commands\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * LO QUE APROBÓ META (contrato H9, D9-6, D9-9.3, §3 protección 12; W9 gancho 4):
 * registra en `ultron_studio_template_meta_checks` (solo inserción; manda la
 * última fila de cada plantilla) que una persona comprobó en el administrador
 * de WhatsApp de Meta que una versión de una plantilla del Studio corresponde
 * a una plantilla de Meta, con su nombre, idioma, categoría, estado, id y la
 * HUELLA del texto aprobado. Sin esa fila `APPROVED` y casada con el Studio,
 * el canal de WhatsApp de la automatización no envía
 * (`template_not_approved_by_meta`).
 *
 *   php artisan ultron-studio:template-meta-check --plantilla=<uuid> \
 *       --nombre-meta=renovacion_marzo --idioma=es --categoria=marketing \
 *       --estado=APPROVED --id-meta=1234567890 \
 *       --cuerpo-fichero=/ruta/texto-aprobado.txt --por=12            (EN SECO)
 *   … --aplicar                                                        (registra)
 *   php artisan ultron-studio:template-meta-check --lista
 *
 * EN SECO POR DEFECTO: valida, dice qué escribiría y si el canal ACEPTARÁ la
 * plantilla (campo a campo contra la versión del Studio) y no escribe nada.
 * Con `--aplicar` escribe UNA fila (idempotente con `--peticion`). La persona
 * de `--por` tiene que estar activa, tener `ultron.approve` y su vínculo
 * verificado a una persona. Los hechos de Meta se registran TAL CUAL, casen o
 * no con el Studio (un rechazo o una pausa en Meta también se registran). El
 * texto del fichero NUNCA se imprime ni se guarda: solo su huella. Sin red.
 * Sale con 2 si los datos no valen o falta la persona, con 0 si no.
 *
 * PLANTILLAS DE META DESDE EL CRM (PLANTILLAS_META_PLAN §5.6; L9-96): se niega,
 * también en seco y sin escribir nada, si la versión tiene encabezado, pie o
 * botones (`components_require_meta_sync`) o si se envió a Meta desde el CRM y
 * su envío sigue vivo (`meta_sync_owns_template`): de esas se ocupa la
 * sincronización (`ultron-studio:meta-templates-sync`), que sí lee los
 * componentes.
 */
class TemplateMetaCheckCommand extends Command
{
    /** Lo que se lee del fichero como mucho (el texto de Meta, ≤ 1 024 caracteres, cabe de sobra). */
    public const BODY_FILE_MAX_BYTES = 8192;

    protected $signature = 'ultron-studio:template-meta-check
        {--plantilla= : el uuid de la versión de la plantilla de WhatsApp del Studio}
        {--nombre-meta= : el nombre de la plantilla en Meta ([a-z0-9_], 1–64)}
        {--idioma= : el idioma en Meta (es | es_CO)}
        {--categoria= : la categoría en Meta (marketing | utility)}
        {--estado= : lo que dice Meta: APPROVED | REJECTED | PAUSED | DISABLED}
        {--id-meta= : el id de la plantilla en Meta (solo dígitos; opcional)}
        {--cuerpo-fichero= : fichero con el texto que aprobó Meta (UTF-8, ≤ 1 024 caracteres): se guarda su huella, nunca se imprime}
        {--por= : la cuenta del CRM (id o correo) de quien lo comprobó en Meta: activa, con ultron.approve y persona verificada}
        {--comprobado-el= : cuándo lo comprobó, AAAA-MM-DDTHH:MM:SSZ (por defecto, ahora)}
        {--peticion= : uuid de idempotencia: repetir con el mismo no escribe otra fila}
        {--aplicar : registra la comprobación; sin esto, EN SECO: dice qué escribiría y si el canal la aceptará}
        {--lista : las plantillas de WhatsApp del Studio con su última comprobación}
        {--json : la salida en JSON}';

    protected $description = 'Registra lo que aprobó Meta de una plantilla de WhatsApp del Studio (en seco por defecto; el canal solo envía con la última APPROVED que casa)';

    public function handle(StudioTemplateMetaChecks $checks): int
    {
        if ($this->option('lista')) {
            return $this->list($checks);
        }
        $cuerpo = $this->body();
        if ($cuerpo === null) {
            return self::INVALID;
        }
        $ahora = now();
        try {
            $plan = $checks->plan([
                'template' => $this->option('plantilla'),
                'metaName' => $this->option('nombre-meta'),
                'language' => $this->option('idioma'),
                'category' => $this->option('categoria'),
                'status' => $this->option('estado'),
                'metaTemplateId' => $this->option('id-meta'),
                'body' => $cuerpo,
                'by' => $this->option('por'),
                'checkedAt' => $this->option('comprobado-el'),
                'clientRequestId' => $this->option('peticion'),
            ], $ahora);
            unset($cuerpo);
            $resultado = $this->option('aplicar') ? $checks->record($plan, $ahora) : null;
        } catch (UltronStudioException $e) {
            $this->error('NO SE REGISTRA: '.$e->getMessage().' ('.$e->errorCode.')'.(isset($e->payload['errors']) ? ' '.json_encode($e->payload['errors']) : ''));

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'mode' => $resultado === null ? 'dry_run' : 'applied',
                'template' => $plan['template'],
                'check' => $plan['row'],
                'comparison' => $plan['comparison'],
                'latestBefore' => $plan['latest'],
                'result' => $resultado,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->report($plan, $resultado);

        return self::SUCCESS;
    }

    /**
     * El texto del fichero, sin imprimirlo nunca: existe, se lee y no pasa de
     * {@see BODY_FILE_MAX_BYTES}. null (y el error dicho) si no.
     */
    private function body(): ?string
    {
        $ruta = $this->option('cuerpo-fichero');
        if (! is_string($ruta) || trim($ruta) === '') {
            $this->error('NO SE REGISTRA: falta --cuerpo-fichero (el texto que aprobó Meta: se guarda su huella).');

            return null;
        }
        $ruta = trim($ruta);
        if (! is_file($ruta) || ! is_readable($ruta)) {
            $this->error('NO SE REGISTRA: no se puede leer el fichero de --cuerpo-fichero.');

            return null;
        }
        $tamano = filesize($ruta);
        if ($tamano === false || $tamano > self::BODY_FILE_MAX_BYTES) {
            $this->error('NO SE REGISTRA: el fichero de --cuerpo-fichero pasa de '.self::BODY_FILE_MAX_BYTES.' bytes (el texto de una plantilla cabe en 1 024 caracteres).');

            return null;
        }
        $texto = file_get_contents($ruta, false, null, 0, self::BODY_FILE_MAX_BYTES + 1);
        if (! is_string($texto) || strlen($texto) > self::BODY_FILE_MAX_BYTES) {
            $this->error('NO SE REGISTRA: no se pudo leer el fichero de --cuerpo-fichero entero.');

            return null;
        }

        return $texto;
    }

    /**
     * @param  array{row: array<string, mixed>, template: array<string, mixed>, comparison: array<string, mixed>, latest: array<string, mixed>|null}  $plan
     * @param  array{uuid: string, replayed: bool, channelAccepts: bool}|null  $resultado
     */
    private function report(array $plan, ?array $resultado): void
    {
        $t = $plan['template'];
        $f = $plan['row'];
        $c = $plan['comparison'];
        $this->info('── ULTRON Studio · comprobación de Meta de una plantilla ──');
        $this->line($resultado === null ? 'EN SECO: no se escribe nada (añade --aplicar para registrarla).' : ($resultado['replayed'] ? 'YA REGISTRADA con esa --peticion: no se escribe otra fila (idempotente).' : 'REGISTRADA.'));
        $this->line('Plantilla   : '.$t['uuid'].' · '.$t['key'].' v'.$t['number'].' · '.$t['purpose'].' · '.$t['status'].' en el Studio');
        $this->line('Meta        : '.$f['meta_name'].' · '.$f['language'].' · '.$f['category'].' · '.$f['status'].($f['meta_template_id'] !== null ? ' · id '.$f['meta_template_id'] : ''));
        $this->line('Texto       : huella '.substr((string) $f['meta_body_hash'], 0, 12).'… (el texto no se guarda ni se imprime)');
        $this->line('Comprobó    : '.$f['recorded_by_name'].' (cuenta '.$f['recorded_by_admin_id'].') el '.$f['checked_at'].' UTC');
        $this->line('Petición    : '.$f['client_request_id'].($resultado !== null ? ' · fila '.$resultado['uuid'] : ''));
        $marca = static fn (bool $b): string => $b ? 'casa' : 'NO casa';
        $this->line('Con el Studio: nombre '.$marca($c['fields']['metaName']).' · idioma '.$marca($c['fields']['language']).' · categoría '.$marca($c['fields']['category'])
            .' · texto '.$marca($c['fields']['body']).' · estado '.($c['fields']['status'] ? 'APPROVED' : 'no APPROVED').' · componentes '.$marca($c['fields']['components']));
        $acepta = $resultado['channelAccepts'] ?? $c['channelAccepts'];
        $this->line($acepta
            ? ($resultado === null ? 'Canal       : con esta como la última comprobación, el canal de WhatsApp ACEPTARÁ la plantilla.' : 'Canal       : el canal de WhatsApp ACEPTA la plantilla (lo dice el puente de la ejecución).')
            : 'Canal       : el canal de WhatsApp NO '.($resultado === null ? 'la aceptará' : 'la acepta').' ('.implode(', ', $c['reasons'] !== [] ? $c['reasons'] : ['template_not_approved_by_meta']).').');
        if ($plan['latest'] !== null && $resultado === null) {
            $this->line('Hoy manda   : '.$plan['latest']['status'].' del '.$plan['latest']['checkedAt'].' ('.$plan['latest']['recordedBy'].').');
        }
    }

    private function list(StudioTemplateMetaChecks $checks): int
    {
        $filas = [];
        foreach (DB::table(StudioTemplateMetaChecks::TEMPLATES)->where('channel', StudioMessageTemplates::CHANNEL_WHATSAPP)->orderBy('key')->orderBy('number')
            ->get(['id', 'uuid', 'key', 'number', 'status', 'purpose']) as $t) {
            $ultima = $checks->latest((int) $t->id);
            $filas[] = [
                'uuid' => (string) $t->uuid, 'key' => (string) $t->key, 'number' => (int) $t->number, 'status' => (string) $t->status, 'purpose' => (string) $t->purpose,
                'meta' => $ultima === null ? null : $ultima['status'].' · '.$ultima['metaName'].' · '.$ultima['language'].' · '.$ultima['checkedAt'],
                'channelAccepts' => $checks->channelAccepts((int) $t->id),
            ];
        }
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->table(['Plantilla', 'Clave', 'Studio', 'Finalidad', 'Última de Meta', 'Canal'], array_map(static fn (array $f): array => [
            $f['uuid'], $f['key'].' v'.$f['number'], $f['status'], $f['purpose'], $f['meta'] ?? 'sin comprobar', $f['channelAccepts'] ? 'la acepta' : 'no',
        ], $filas));

        return self::SUCCESS;
    }
}
