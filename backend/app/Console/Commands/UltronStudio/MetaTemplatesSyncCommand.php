<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\UltronStudio\StudioMetaTemplates;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * LA SINCRONIZACIÓN DE LAS PLANTILLAS DE META (plan PLANTILLAS_META_PLAN §5.5 y
 * §5.12; contrato H9, L9-96): una pasada de {@see StudioMetaTemplates::sync()}.
 *
 *   php artisan ultron-studio:meta-templates-sync                    (escribe)
 *   php artisan ultron-studio:meta-templates-sync --dry-run --json   (lee Meta y dice qué cambiaría)
 *   php artisan ultron-studio:meta-templates-sync --template=<uuid>  (solo esa versión: un GET)
 *
 * Vigila las versiones de WhatsApp con un envío vivo y las que solo tienen
 * comprobaciones sin envío (las registradas a mano), lee su estado en Meta
 * (1–3 GET por pasada) y registra, sola, lo que cambió en
 * `ultron_studio_template_meta_checks`, que es lo que lee el canal de W9.
 *
 * CÓDIGO DE SALIDA (M-3 de la revisión de la ola; decisión del lead que
 * enmienda §5.5.7 del plan):
 *  - 1 si Meta falla para TODA la pasada (`meta_failed`: no respondió o
 *    respondió error a la lectura de la lista). Lo último registrado sigue
 *    mandando (no se inventa nada); el programador lo ve como un fallo y lo
 *    reporta en el registro de la aplicación, cada pasada que falle;
 *  - 0 con `partial` (alguna versión no se pudo comprobar y queda para la
 *    siguiente), pero lo dice: cuántas en la salida y `unresolved` en `--json`;
 *  - 0 si todo fue bien, con la maestra o la sincronización apagadas
 *    («apagado»), sin la cuenta o su credencial («sin configurar») o sin nada
 *    que vigilar;
 *  - 2 con un `--template` que no es un uuid.
 * Con el doble (`fake`) no hay red. La programa un bloque PROPIO de
 * routes/console.php, cada 5 minutos tras `ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED`.
 */
class MetaTemplatesSyncCommand extends Command
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private const OUTCOME_TEXT = [
        'disabled' => 'APAGADO: la maestra de las plantillas de Meta o su sincronización están apagadas. Sin red.',
        'not_configured' => 'SIN CONFIGURAR: falta la cuenta de WhatsApp o su credencial (o, fuera de producción, el simulador). Sin red.',
        'nothing_to_watch' => 'NADA QUE VIGILAR: ninguna versión enviada a Meta ni registrada a mano. Sin red.',
        'not_watched' => 'ESA VERSIÓN NO SE VIGILA: no tiene un envío vivo ni una comprobación sin envío. Sin red.',
        'meta_failed' => 'META FALLÓ: no se registra nada; lo último registrado sigue mandando.',
        'ok' => 'PASADA HECHA.',
        'partial' => 'PASADA HECHA EN PARTE: alguna versión no se pudo leer y queda para la siguiente.',
    ];

    protected $signature = 'ultron-studio:meta-templates-sync
        {--dry-run : lee Meta y dice qué cambiaría, sin escribir nada}
        {--json : la salida en JSON}
        {--template= : solo esta versión (uuid) de una plantilla de WhatsApp del Studio}';

    protected $description = 'Lee en Meta el estado de las plantillas de WhatsApp del Studio y registra lo que cambió (lo que lee el canal de W9)';

    public function handle(StudioMetaTemplates $meta): int
    {
        $plantilla = $this->option('template');
        if ($plantilla !== null) {
            $plantilla = strtolower(trim((string) $plantilla));
            if (preg_match(self::UUID, $plantilla) !== 1) {
                $this->error('--template tiene que ser el uuid de una versión de plantilla.');

                return self::INVALID;
            }
        }

        $r = $meta->sync($plantilla, (bool) $this->option('dry-run'), now());
        $codigo = $r['outcome'] === 'meta_failed' ? self::FAILURE : self::SUCCESS;
        $sinComprobar = count(array_filter($r['templates'], static fn (array $f): bool => ($f['note'] ?? null) === 'unresolved'));

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r + ['unresolved' => $sinComprobar], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $codigo;
        }

        $this->info('── ULTRON Studio · sincronización de las plantillas de Meta ──');
        $resultado = ($r['dryRun'] ? 'EN SECO (no se escribe nada). ' : '').(self::OUTCOME_TEXT[$r['outcome']] ?? $r['outcome']);
        $codigo === self::FAILURE ? $this->error($resultado.' Sale con 1.') : $this->line($resultado);
        $this->line("Vigiladas: {$r['watched']} · peticiones a Meta: {$r['requests']}".($r['error'] !== null ? " · motivo: {$r['error']}" : ''));
        if ($sinComprobar > 0) {
            $this->warn("Sin comprobar en esta pasada: {$sinComprobar} (sale con 0: la siguiente pasada vuelve a intentarlo).");
        }
        foreach ($r['templates'] as $f) {
            $this->line(sprintf(
                '  %s v%d · %s → %s%s%s%s',
                $f['key'], $f['number'], $f['before'] ?? '—', $f['after'] ?? '—',
                ($f['rowWritten'] ?? false) ? ' · registrada' : '',
                ($f['attesterUnavailable'] ?? false) ? ' · ESPERA a quien dé fe' : '',
                isset($f['note']) && $f['note'] !== null ? " · {$f['note']}" : '',
            ));
        }

        return $codigo;
    }
}
