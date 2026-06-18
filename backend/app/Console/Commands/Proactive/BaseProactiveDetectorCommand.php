<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Services\ProactiveCoachService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Base de los detectores del Iron Body Proactive Coach.
 *
 * Aporta a todos los detectores nuevos las opciones de prueba segura y la
 * mecánica común de iteración/emisión. Cada subclase solo implementa detect()
 * con su consulta específica y llama a $this->consider().
 *
 * Opciones compartidas (incluir en el {--...} de cada subclase):
 *   --dry-run        Muestra qué se emitiría SIN escribir nada.
 *   --member-id=     Evalúa un solo miembro (cualquier estado) para pruebas.
 *   --limit=         Tope de acciones (emitidas + "would_emit"). Ideal --limit=1.
 *   --event=         Filtra a un único event_type (para detectores multi-evento).
 */
abstract class BaseProactiveDetectorCommand extends Command
{
    protected ProactiveCoachService $coach;

    protected int $emitted = 0;
    protected int $would = 0;
    protected int $skipped = 0;
    protected int $duplicate = 0;

    /** @var array<string,int> conteo por motivo de skip */
    protected array $reasons = [];

    public function handle(ProactiveCoachService $coach): int
    {
        $this->coach = $coach;

        if ($this->isDryRun()) {
            $this->info('▶ DRY-RUN: no se escribirá ni emitirá nada.');
        }

        $this->detect();
        $this->printSummary();

        return self::SUCCESS;
    }

    /** Cada detector implementa su lógica aquí (usa forEachMember/consider). */
    abstract protected function detect(): void;

    /**
     * Itera miembros activos (o el --member-id indicado) y aplica $cb.
     * Respeta el tope --limit sobre acciones (emitidas + would_emit).
     *
     * @param callable(Member):void $cb
     */
    protected function forEachMember(callable $cb): void
    {
        $single = $this->option('member-id');
        if ($single !== null && $single !== '') {
            $member = Member::find((int) $single);
            if ($member === null) {
                $this->error("Miembro {$single} no existe.");
                return;
            }
            $cb($member);
            return;
        }

        Member::query()
            ->where('status', Member::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(200, function ($members) use ($cb) {
                foreach ($members as $member) {
                    $cb($member);
                    if ($this->limitReached()) {
                        return false; // detiene el chunking
                    }
                }
                return true;
            });
    }

    /**
     * Evalúa un evento para un miembro: respeta --event, delega en el servicio
     * (con dry-run) y lleva el conteo.
     *
     * @param array<string,mixed> $context
     */
    protected function consider(Member $member, string $eventType, array $context = []): void
    {
        if ($this->limitReached()) {
            return;
        }
        $eventFilter = $this->option('event');
        if ($eventFilter !== null && $eventFilter !== '' && $eventFilter !== $eventType) {
            return;
        }

        $res = $this->coach->consider($member, $eventType, $context, $this->isDryRun());
        $status = $res['status'];

        if ($status === 'emitted') {
            $this->emitted++;
            $this->line("  ✓ emitido     {$eventType} → member {$member->id}");
        } elseif ($status === 'would_emit') {
            $this->would++;
            $title = $res['notification']['title'] ?? '';
            $this->line("  • would_emit  {$eventType} → member {$member->id}  «{$title}»");
        } elseif ($status === 'duplicate') {
            $this->duplicate++;
        } else { // skipped
            $this->skipped++;
            $reason = $res['reason'] ?? 'skipped';
            $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        }
    }

    protected function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    protected function limitReached(): bool
    {
        $limit = $this->option('limit');
        if ($limit === null || $limit === '') {
            return false;
        }
        return ($this->emitted + $this->would) >= (int) $limit;
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('proactive_coach.timezone', 'America/Bogota'));
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Resumen %s — emitidos:%d would_emit:%d duplicados:%d omitidos:%d',
            $this->getName(),
            $this->emitted,
            $this->would,
            $this->duplicate,
            $this->skipped,
        ));
        foreach ($this->reasons as $reason => $n) {
            $this->line("   omitido[{$reason}]: {$n}");
        }
    }
}
