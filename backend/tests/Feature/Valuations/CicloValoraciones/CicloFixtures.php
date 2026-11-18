<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Models\Member;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Valuations\ValuationFixtures;

/**
 * El ciclo completo por los caminos REALES: la recepción agenda por
 * `POST /api/admin/valuations` y el entrenador valora y envía por su portal
 * (`/api/trainer/...`) con una sesión emitida por `TrainerSessionService`.
 * Nada del flujo se sustituye.
 *
 * Reloj en UTC (ValuationFixtures::clock recibe la hora de pared de Bogotá).
 */
trait CicloFixtures
{
    use ValuationFixtures;

    /** @var array<int, string> trainer_id → token */
    private array $tokens = [];

    protected function portalOn(): void
    {
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
        ]);
    }

    /** Entrenador con rol de planta, disponibilidad diaria y sesión del portal. */
    protected function coach(string $nombre): Trainer
    {
        $t = $this->trainer($nombre);
        $t->syncRoles([TrainerRole::FLOOR]);
        $this->everyDay($t);
        $this->tokens[$t->id] = app(TrainerSessionService::class)->issueSession($t, ['device_id' => 'ciclo-'.$t->id])['token'];

        return $t;
    }

    protected function asCoach(Trainer $t): array
    {
        return ['Authorization' => 'Bearer '.$this->tokens[$t->id]];
    }

    /** Borrador por el portal. Devuelve su uuid. */
    protected function draft(Trainer $t, Member $m, array $datos = ['weight_kg' => 80.5, 'observations' => 'Buen progreso']): string
    {
        return $this->postJson("/api/trainer/members/{$m->id}/assessments", $datos, $this->asCoach($t))
            ->assertCreated()
            ->json('data.uuid');
    }

    protected function submit(Trainer $t, string $uuid): TestResponse
    {
        return $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asCoach($t));
    }

    /** Borrador + envío. Devuelve el uuid. */
    protected function assess(Trainer $t, Member $m): string
    {
        $uuid = $this->draft($t, $m);
        $this->submit($t, $uuid)->assertOk();

        return $uuid;
    }

    /** La recepción agenda; devuelve el uuid de la cita. */
    protected function schedule(array $recepcion, Member $m, Trainer $t, string $cuando): string
    {
        return $this->book($recepcion, $m, $t, $cuando)->assertCreated()->json('data.uuid');
    }

    /** Los avisos de la bandeja del CRM que nacen del envío de valoraciones. */
    protected function assessmentNotices(): Collection
    {
        return Notification::query()
            ->where('audience', 'admin')
            ->where('event_key', 'like', 'valuation:assessment:%')
            ->orderBy('id')
            ->get();
    }
}
