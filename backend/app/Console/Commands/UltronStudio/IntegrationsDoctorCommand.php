<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\UltronStudio\StudioIntegrations;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Qué afirma el Studio de cada integración comercial (contrato H6 §I7.2): lo
 * mismo que `GET integrations/status` sin el bloque `can` (en consola no hay
 * persona). SIN RED y sin escribir nada.
 *
 * `--expect=<id>:<valor>,…` lo usa el smoke del despliegue: `<valor>` es la
 * clasificación del bloque y, en `elevenlabs`, vale también su código
 * (`ELEVENLABS_NOT_CONFIGURED`…). Sale 1 si algo esperado no coincide, si un
 * bloque esperado no está disponible o si la expectativa está mal escrita.
 *
 * Nunca imprime secretos, teléfonos ni ids de Meta: solo clasificaciones,
 * estados, motivos y recuentos.
 */
class IntegrationsDoctorCommand extends Command
{
    protected $signature = 'ultron-studio:integrations-doctor
        {--json : el resultado en JSON}
        {--expect= : expectativas separadas por comas, <id>:<clasificación> (en elevenlabs vale también el código)}';

    protected $description = 'Clasifica las integraciones comerciales de ULTRON STUDIO (Meta Ads, Twilio, Twilio Voice, números canarios y ElevenLabs) sin red. Con --expect, sale 1 si algo no coincide.';

    public function handle(StudioIntegrations $integrations): int
    {
        $esperado = self::parseExpectations((string) $this->option('expect'));
        if ($esperado === null) {
            $this->error('La expectativa está mal escrita: usa <id>:<valor>,… con los ids '.implode(', ', StudioIntegrations::PROVIDER_IDS).'.');

            return self::FAILURE;
        }

        $proveedores = $integrations->providers();
        $porId = array_column($proveedores, null, 'id');
        $fallos = [];
        foreach ($esperado as $id => $valor) {
            $bloque = $porId[$id] ?? null;
            $vale = $bloque !== null && ($bloque['available'] ?? false) === true
                && (($bloque['classification'] ?? null) === $valor || ($id === 'elevenlabs' && ($bloque['code'] ?? null) === $valor));
            if (! $vale) {
                $fallos[] = ['id' => $id, 'expected' => $valor, 'actual' => ($bloque['available'] ?? false) === true ? ($bloque['classification'] ?? null) : 'unavailable'];
            }
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'ok' => $fallos === [],
                'flags' => StudioIntegrations::flags(),
                'providers' => $proveedores,
                'mismatches' => $fallos,
                'note' => StudioIntegrations::STATUS_NOTE,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $fallos === [] ? self::SUCCESS : self::FAILURE;
        }

        $banderas = StudioIntegrations::flags();
        $this->line('Escrituras: '.($banderas['writesEnabled'] ? 'encendidas' : 'apagadas').' · sonda de Twilio: '.($banderas['twilioProbeEnabled'] ? 'encendida' : 'apagada'));
        foreach ($proveedores as $p) {
            if (($p['available'] ?? false) !== true) {
                $this->warn("{$p['id']}: no disponible (su fuente falló; ver el log)");

                continue;
            }
            $this->line(OutputFormatter::escape(StudioIntegrations::describe($p)));
        }
        $this->line(StudioIntegrations::STATUS_NOTE);

        foreach ($fallos as $f) {
            $this->error("{$f['id']}: se esperaba {$f['expected']} y es {$f['actual']}.");
        }

        return $fallos === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `meta_ads:connected_read,elevenlabs:ELEVENLABS_NOT_CONFIGURED` → mapa; vacío → [];
     * null si algo no tiene la forma `<id conocido>:<valor>`.
     *
     * @return array<string,string>|null
     */
    public static function parseExpectations(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $par) {
            $partes = explode(':', trim($par), 2);
            if (count($partes) !== 2 || ! in_array($partes[0], StudioIntegrations::PROVIDER_IDS, true) || preg_match('/^[A-Za-z_]{1,40}$/D', $partes[1]) !== 1) {
                return null;
            }
            $out[$partes[0]] = $partes[1];
        }

        return $out;
    }
}
