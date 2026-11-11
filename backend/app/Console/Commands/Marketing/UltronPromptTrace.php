<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingAiAction;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * LA TRAZA DE LOS PROMPTS DE ULTRON, EN LA CONSOLA (Enmienda M-1 del H5; plan
 * de motores §D). Es la evidencia mínima de «publicada ↔ aplicada» si el CRM
 * no llegara a tiempo, y la comprobación de cada etapa de la activación.
 *
 * SOLO LECTURA: lee la configuración, la oferta que haría `decide` AHORA para
 * la conversación canaria (una sentencia sobre la cadena del Studio, solo con
 * las dos llaves encendidas) y los últimos turnos de esa conversación en
 * `marketing_ai_actions`. No escribe nada.
 *
 * SIN DATOS DE NADIE: ni el id de la conversación canaria, ni ids de mensajes,
 * ni textos (ni los de la persona ni los de los prompts). Por turno, el minuto
 * en UTC, lo declarado (`metadata.studio`), lo ofrecido y lo observado
 * (`metadata.studio_prompts`) con versiones y huellas recortadas a 8
 * caracteres, y el estado. La misma forma que `status.engine.prompts` del
 * Studio, más `studioConfig`.
 */
class UltronPromptTrace extends Command
{
    protected $signature = 'ultron:prompt-trace
        {--last=20 : cuántos turnos de la conversación canaria (1 a 100)}
        {--json : salida en JSON}';

    protected $description = 'Traza de los prompts de ULTRON (solo lectura): llaves, base, huella Legacy, la oferta que haría decide ahora y los últimos turnos de la canaria.';

    public function handle(UltronPromptOffer $offer): int
    {
        $n = max(1, min(100, (int) $this->option('last')));
        $canario = config('marketing.ultron.canary_conversation_id');

        // La oferta de ahora, exactamente la de `decide` (sin los textos).
        $oferta = $offer->forConversation($canario === null ? 0 : (int) $canario)['response'];

        $traza = [
            'key' => UltronPromptOffer::key(),
            'studioConfig' => self::studioConfig(),
            'canaryConfigured' => $canario !== null,
            'base' => UltronPromptBase::ID,
            'compiler' => UltronPromptCompiler::VERSION,
            'legacyBundleHash' => UltronPromptBase::LEGACY_BUNDLE_HASH,
            'current' => [
                'mode' => $oferta['mode'],
                'version' => $oferta['version'],
                'bundleHash' => $oferta['bundle_hash'],
                'reason' => $oferta['reason'],
            ],
            'lastTurns' => $canario === null ? [] : $this->turnos((int) $canario, $n),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($traza, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('ULTRON · prompts del motor (solo lectura)');
        $this->line(sprintf('  llave de prompts: %s · llave del sello: %s · canario: %s', $traza['key'], $traza['studioConfig'], $traza['canaryConfigured'] ? 'configurado' : 'SIN CONFIGURAR (nadie recibe prompts)'));
        $this->line(sprintf('  base %s · compilador %d · huella Legacy %s', $traza['base'], $traza['compiler'], $traza['legacyBundleHash']));
        $actual = $traza['current'];
        $this->line(sprintf('  decide ofrecería ahora a la canaria: %s', $actual['mode'] === UltronPromptOffer::MODE_STUDIO
            ? 'studio · versión '.$actual['version'].' · huella '.$actual['bundleHash']
            : 'legacy · motivo '.$actual['reason']));
        $this->newLine();

        if ($traza['lastTurns'] === []) {
            $this->line('  Sin turnos de la canaria que enseñar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Minuto (UTC)', 'Declarada', 'Ofrecida', 'Observada', 'Estado'],
            array_map(static fn (array $t): array => [
                $t['at'] ?? '—',
                $t['declared'] === null ? '—' : trim(($t['declared']['state'] ?? '?').' '.($t['declared']['version8'] ?? '')),
                $t['offered'] === null ? '—' : trim(($t['offered']['mode'] ?? '?').' '.($t['offered']['version8'] ?? '').' '.($t['offered']['bundle8'] ?? '').' '.($t['offered']['reason'] ?? '')),
                $t['observed'] === null ? '—' : trim(($t['observed']['mode'] ?? '?').' '.($t['observed']['bundle8'] ?? 'sin huella').' '.($t['observed']['flowRev'] ?? '')),
                $t['state'] ?? 'sin sello',
            ], $traza['lastTurns']),
        );

        return self::SUCCESS;
    }

    /**
     * Los últimos turnos de la canaria, del más antiguo al más reciente.
     *
     * @return list<array{at:?string,declared:?array<string,mixed>,offered:?array<string,mixed>,observed:?array<string,mixed>,state:?string}>
     */
    private function turnos(int $conversacion, int $n): array
    {
        return MarketingAiAction::query()
            ->where('conversation_id', $conversacion)
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->orderByDesc('id')
            ->limit($n)
            ->get(['id', 'metadata', 'created_at'])
            ->reverse()
            ->map(static function (MarketingAiAction $a): array {
                $meta = is_array($a->metadata) ? $a->metadata : [];
                $declarada = is_array($meta['studio'] ?? null) ? $meta['studio'] : null;
                $sello = is_array($meta['studio_prompts'] ?? null) ? $meta['studio_prompts'] : null;
                $ofrecida = is_array($sello['offered'] ?? null) ? $sello['offered'] : null;
                $observada = is_array($sello['observed'] ?? null) ? $sello['observed'] : null;

                return [
                    'at' => $a->created_at === null ? null : Carbon::parse($a->created_at)->utc()->format('Y-m-d\TH:i\Z'),
                    'declared' => $declarada === null ? null : ['state' => self::texto($declarada['state'] ?? null), 'version8' => self::corto($declarada['version'] ?? null)],
                    'offered' => $ofrecida === null ? null : [
                        'mode' => self::texto($ofrecida['mode'] ?? null),
                        'reason' => self::texto($ofrecida['reason'] ?? null),
                        'version8' => self::corto($ofrecida['version'] ?? null),
                        'bundle8' => self::corto($ofrecida['bundleHash'] ?? null),
                    ],
                    'observed' => $observada === null ? null : [
                        'mode' => self::texto($observada['mode'] ?? null),
                        'bundle8' => self::corto($observada['bundleHash'] ?? null),
                        'flowRev' => self::texto($observada['flowRev'] ?? null),
                    ],
                    'state' => $sello === null ? null : self::texto($sello['state'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /** La llave del sello declarado, con el criterio del lector (`canary`, `all` u `off`). */
    private static function studioConfig(): string
    {
        $valor = config('marketing.ultron.studio_config');
        $valor = is_string($valor) ? strtolower(trim($valor)) : '';

        return in_array($valor, ['canary', 'all'], true) ? $valor : 'off';
    }

    private static function corto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? substr($valor, 0, 8) : null;
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : null;
    }
}
