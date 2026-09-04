<?php

namespace App\Services\Trainer;

use App\Exceptions\NutritionGuideException;
use App\Models\Member;
use App\Models\NutritionGuide;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\NotificationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de dominio de las guías nutricionales del entrenador.
 *
 * Calcada de {@see ProfessionalAssessmentService}, que ya resolvió el mismo
 * problema: una guía publicada es inmutable, las correcciones crean una versión
 * enlazada, el socio recibe aviso y todo queda auditado en la misma
 * transacción. No hay un segundo patrón porque no hay un segundo problema.
 *
 * Lo propio de este dominio es el SNAPSHOT: al publicar se congelan las medidas
 * y la procedencia. La valoración de la que salieron puede corregirse mañana;
 * la guía de hoy debe seguir diciendo con qué números se escribió.
 */
class NutritionGuideService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TrainerAuditService $audit,
    ) {}

    /**
     * Crea el borrador.
     *
     * Si el entrenador pide arrancar de la última valoración, las medidas se
     * copian de ahí: ya las tomó una vez y volver a teclearlas es una fuente de
     * erratas, no de rigor. Lo que el entrenador escriba en el formulario manda
     * sobre lo copiado.
     */
    public function createDraft(Trainer $trainer, Member $member, array $data, bool $useLastAssessment = false): NutritionGuide
    {
        $desdeValoracion = [];
        $origen = null;

        if ($useLastAssessment) {
            $origen = $this->lastAssessmentFor($member);
            if ($origen !== null) {
                $desdeValoracion = $this->measurementsFrom($origen);
            }
        }

        $guide = NutritionGuide::create(array_merge(
            $desdeValoracion,
            // Lo escrito a mano gana: la copia es una comodidad, no una atadura.
            array_filter(
                Arr::only($data, NutritionGuide::CONTENT_FIELDS),
                fn ($v) => $v !== null,
            ),
            [
                'member_id' => $member->getKey(),
                'trainer_id' => $trainer->getKey(),
                'source_assessment_id' => $origen?->getKey(),
                'trainer_type' => $trainer->roleNames()[0] ?? null,
                'status' => NutritionGuide::STATUS_DRAFT,
                'version' => 1,
            ],
        ));

        $this->audit->record('nutrition_guide.draft_created', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
            'guide' => $guide->uuid,
            'member_id' => $member->getKey(),
            'from_assessment' => $origen?->uuid,
        ]);

        return $guide;
    }

    public function updateDraft(NutritionGuide $guide, Trainer $trainer, array $data): NutritionGuide
    {
        if (! $guide->isDraft()) {
            throw NutritionGuideException::notEditable();
        }

        $guide->fill(Arr::only($data, NutritionGuide::CONTENT_FIELDS))->save();

        $this->audit->record('nutrition_guide.draft_updated', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
            'guide' => $guide->uuid,
            'member_id' => $guide->member_id,
        ]);

        return $guide->refresh();
    }

    /**
     * Publica el borrador: lo vuelve inmutable, avisa al socio y audita.
     *
     * Todo en una transacción. Una guía publicada sin aviso al socio es una guía
     * que nadie sabe que existe; una auditada a medias no permite reconstruir
     * quién pautó qué.
     */
    public function publish(NutritionGuide $guide, Trainer $trainer): NutritionGuide
    {
        if (! $guide->isDraft()) {
            throw NutritionGuideException::notPublishable();
        }

        $this->assertPublishable($guide);

        return DB::transaction(function () use ($guide, $trainer) {
            // Si este borrador corrige a una versión publicada, el relevo ocurre
            // AHORA y no antes: hasta este momento el socio seguía viendo la
            // anterior, que es lo que le permitía seguir comiendo con una pauta
            // válida mientras su entrenador preparaba la nueva.
            $anterior = $guide->parent_id !== null
                ? NutritionGuide::query()
                    ->whereKey($guide->parent_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $guide->forceFill([
                'status' => NutritionGuide::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            if ($anterior !== null && $anterior->status === NutritionGuide::STATUS_PUBLISHED) {
                $anterior->forceFill(['status' => NutritionGuide::STATUS_AMENDED])->save();
            }

            $esCorreccion = $anterior !== null;
            $this->notifyMember($guide, isAmendment: $esCorreccion);

            $this->audit->record('nutrition_guide.published', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
                'guide' => $guide->uuid,
                'member_id' => $guide->member_id,
                'version' => $guide->version,
            ]);

            // El momento en que la anterior deja de estar vigente es un hecho
            // distinto del de publicar la nueva, y se registra aparte.
            if ($esCorreccion) {
                $this->audit->record('nutrition_guide.amended', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
                    'guide' => $anterior->uuid,
                    'member_id' => $anterior->member_id,
                    'version' => $anterior->version,
                ]);
            }

            return $guide->refresh();
        });
    }

    /**
     * Abre la corrección de una guía publicada: crea el BORRADOR de la
     * siguiente versión, prellenado con lo que ya decía.
     *
     * No publica nada. Un cambio de pauta nutricional se revisa antes de que el
     * socio empiece a seguirlo, así que la corrección nace como borrador y la
     * anterior SIGUE SIENDO LA VIGENTE hasta que el entrenador publique. Si se
     * relevara aquí, el socio se quedaría sin plan válido durante todo el rato
     * que su entrenador tardara en terminar de escribir el nuevo.
     *
     * El relevo —anterior a `amended`, nueva a `published`— lo hace
     * {@see publish()}, en una sola transacción.
     */
    public function amend(NutritionGuide $original, Trainer $trainer, array $data): NutritionGuide
    {
        if ($original->status !== NutritionGuide::STATUS_PUBLISHED) {
            throw NutritionGuideException::notAmendable();
        }

        $motivo = trim((string) ($data['amendment_reason'] ?? ''));
        if ($motivo === '') {
            throw NutritionGuideException::amendmentReasonRequired();
        }

        // Un borrador de corrección ya abierto no se duplica: sería tener dos
        // versiones N+1 compitiendo por relevar a la misma.
        $abierto = NutritionGuide::query()
            ->where('parent_id', $original->getKey())
            ->where('status', NutritionGuide::STATUS_DRAFT)
            ->first();
        if ($abierto !== null) {
            return $abierto;
        }

        // Hereda lo que no se reescriba: cambiar una restricción no debería
        // obligar a teclear el plan entero otra vez.
        $heredado = Arr::only($original->toArray(), NutritionGuide::CONTENT_FIELDS);
        $nuevo = array_filter(
            Arr::only($data, NutritionGuide::CONTENT_FIELDS),
            fn ($v) => $v !== null,
        );
        $contenido = array_merge($heredado, $nuevo);

        $borrador = NutritionGuide::create(array_merge($contenido, [
            'member_id' => $original->member_id,
            'trainer_id' => $trainer->getKey(),
            'parent_id' => $original->getKey(),
            'source_assessment_id' => $original->source_assessment_id,
            'trainer_type' => $trainer->roleNames()[0] ?? $original->trainer_type,
            'status' => NutritionGuide::STATUS_DRAFT,
            'version' => $original->version + 1,
            'amendment_reason' => $motivo,
        ]));

        $this->audit->record('nutrition_guide.amend_started', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
            'guide' => $borrador->uuid,
            'member_id' => $original->member_id,
            'version' => $borrador->version,
        ]);

        return $borrador;
    }

    /**
     * Anula una guía publicada.
     *
     * Existe porque una pauta puede quedar contraindicada —el socio informa de
     * una condición nueva— y entonces lo correcto no es corregirla sino retirarla.
     * No se borra: queda con su motivo, fuera de la vista del socio y de la IA.
     */
    public function void(NutritionGuide $guide, Trainer $trainer, string $reason): NutritionGuide
    {
        if ($guide->status !== NutritionGuide::STATUS_PUBLISHED) {
            throw NutritionGuideException::notVoidable();
        }
        if (trim($reason) === '') {
            throw NutritionGuideException::voidReasonRequired();
        }

        return DB::transaction(function () use ($guide, $trainer, $reason) {
            $guide->forceFill([
                'status' => NutritionGuide::STATUS_VOIDED,
                'void_reason' => trim($reason),
                'voided_at' => now(),
            ])->save();

            $this->audit->record('nutrition_guide.voided', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
                'guide' => $guide->uuid,
                'member_id' => $guide->member_id,
                'version' => $guide->version,
            ]);

            return $guide->refresh();
        });
    }

    /** El socio marcó la guía como leída. Idempotente. */
    public function acknowledge(NutritionGuide $guide): NutritionGuide
    {
        if ($guide->acknowledged_at === null) {
            $guide->forceFill(['acknowledged_at' => now()])->save();
        }

        return $guide->refresh();
    }

    /**
     * Medidas de la última valoración, para arrancar la guía sin volver a
     * teclearlas. Solo las cuatro que ambas entidades comparten: las de la
     * báscula de composición corporal las toma el entrenador aparte.
     *
     * @return array<string, mixed>
     */
    public function measurementsFrom(ProfessionalAssessment $assessment): array
    {
        $out = [];
        foreach (NutritionGuide::ASSESSMENT_MEASUREMENTS as $campo) {
            if ($assessment->{$campo} !== null) {
                $out[$campo] = $assessment->{$campo};
            }
        }

        return $out;
    }

    /** La última valoración utilizable del socio: enviada o corregida. */
    public function lastAssessmentFor(Member $member): ?ProfessionalAssessment
    {
        return ProfessionalAssessment::query()
            ->forMember((int) $member->getKey())
            ->visibleToMember()
            ->orderByDesc('submitted_at')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Lo mínimo para que una guía sirva de algo publicada.
     *
     * Se comprueba al publicar y no al guardar el borrador: un borrador a medias
     * es trabajo en curso legítimo, y exigirle todo desde la primera pulsación
     * obligaría al entrenador a rellenarlo de una sentada.
     */
    private function assertPublishable(NutritionGuide $guide): void
    {
        if (trim((string) $guide->objective) === '') {
            throw NutritionGuideException::objectiveRequired();
        }
        if ($guide->orderedMeals() === []) {
            throw NutritionGuideException::emptyMealPlan();
        }
    }

    private function notifyMember(NutritionGuide $guide, bool $isAmendment): void
    {
        $member = Member::find($guide->member_id);
        if ($member === null) {
            return;
        }

        try {
            $this->notifications->createMemberNotification($member, [
                'type' => $isAmendment ? 'nutrition_guide_amended' : 'nutrition_guide',
                'title' => $isAmendment ? 'Guía nutricional actualizada' : 'Nueva guía nutricional',
                'message' => $isAmendment
                    ? 'Tu entrenador actualizó tu guía nutricional. Tócala para verla.'
                    : 'Tu entrenador publicó una nueva guía nutricional. Tócala para verla.',
                'action_type' => 'route',
                'action_url' => '/nutrition-guide',
                'action_payload' => ['guide_uuid' => $guide->uuid],
                'priority' => 'high',
                'event_key' => 'nutrition_guide:'.$guide->uuid.':v'.$guide->version,
            ]);
        } catch (\Throwable $e) {
            // El aviso es un extra: si el canal falla, la guía YA está publicada
            // y el socio la verá al entrar. Tumbar la publicación por no poder
            // avisar sería perder el trabajo del entrenador por un problema ajeno.
            Log::warning('nutrition_guide.notify_failed', [
                'guide' => $guide->uuid,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
