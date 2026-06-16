<?php

namespace App\Services\Trainer;

use App\Exceptions\AssessmentException;
use App\Models\Member;
use App\Models\PhysicalEvaluation;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\NotificationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de dominio de las valoraciones profesionales. Garantiza la
 * inmutabilidad de las enviadas, versiona las correcciones y notifica al miembro
 * de forma transaccional e idempotente.
 */
class ProfessionalAssessmentService
{
    /** Campos de contenido que un entrenador puede diligenciar. */
    private const CONTENT_FIELDS = [
        ...ProfessionalAssessment::MEASUREMENT_FIELDS,
        'observations',
        'recommendations',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TrainerAuditService $audit,
    ) {}

    public function createDraft(Trainer $trainer, Member $member, array $data): ProfessionalAssessment
    {
        $assessment = ProfessionalAssessment::create(array_merge(
            Arr::only($data, self::CONTENT_FIELDS),
            [
                'member_id' => $member->getKey(),
                'trainer_id' => $trainer->getKey(),
                'trainer_type' => $trainer->roleNames()[0] ?? null,
                'location' => $trainer->location,
                'status' => ProfessionalAssessment::STATUS_DRAFT,
                'version' => 1,
            ],
        ));

        $this->audit->record('assessment.draft_created', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
            'assessment' => $assessment->uuid,
            'member_id' => $member->getKey(),
        ]);

        return $assessment;
    }

    public function updateDraft(ProfessionalAssessment $assessment, array $data): ProfessionalAssessment
    {
        if (! $assessment->isDraft()) {
            throw AssessmentException::notEditable();
        }

        $assessment->fill(Arr::only($data, self::CONTENT_FIELDS))->save();

        return $assessment->refresh();
    }

    /**
     * Envía un borrador: lo vuelve INMUTABLE, notifica al miembro y audita, todo
     * en una transacción. Idempotente por estado: solo un borrador puede enviarse.
     */
    public function submit(ProfessionalAssessment $assessment, Trainer $trainer): ProfessionalAssessment
    {
        if (! $assessment->isDraft()) {
            throw AssessmentException::notSubmittable();
        }

        return DB::transaction(function () use ($assessment, $trainer) {
            $assessment->forceFill([
                'status' => ProfessionalAssessment::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->notifyMember($assessment, isAmendment: false);

            $this->audit->record('assessment.submitted', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
                'assessment' => $assessment->uuid,
                'member_id' => $assessment->member_id,
                'version' => $assessment->version,
            ]);

            // Real-time: refresca el panel de quien atiende a este cliente
            // (incl. un segundo entrenador del mismo miembro). Best-effort.
            TrainerRealtimeEvents::assessmentForMember((int) $assessment->member_id);

            // Vuelca las medidas al historial de Evaluación Física del miembro
            // (lo que ya consume su app), para que la valoración aparezca ahí.
            $this->syncToPhysicalEvaluation($assessment);

            return $assessment->refresh();
        });
    }

    /**
     * Corrige una valoración enviada creando una NUEVA versión enlazada. La
     * anterior pasa a `amended` (histórico, nunca se sobrescribe). Requiere motivo.
     */
    public function amend(ProfessionalAssessment $original, Trainer $trainer, array $data): ProfessionalAssessment
    {
        if ($original->status !== ProfessionalAssessment::STATUS_SUBMITTED) {
            throw AssessmentException::notAmendable();
        }

        $reason = trim((string) ($data['amendment_reason'] ?? ''));
        if ($reason === '') {
            throw AssessmentException::amendmentReasonRequired();
        }

        return DB::transaction(function () use ($original, $trainer, $data, $reason) {
            $amendment = ProfessionalAssessment::create(array_merge(
                Arr::only($data, self::CONTENT_FIELDS),
                [
                    'member_id' => $original->member_id,
                    'trainer_id' => $trainer->getKey(),
                    'parent_id' => $original->getKey(),
                    'trainer_type' => $trainer->roleNames()[0] ?? $original->trainer_type,
                    'location' => $trainer->location ?? $original->location,
                    'status' => ProfessionalAssessment::STATUS_SUBMITTED,
                    'version' => $original->version + 1,
                    'amendment_reason' => $reason,
                    'submitted_at' => now(),
                ],
            ));

            $original->forceFill(['status' => ProfessionalAssessment::STATUS_AMENDED])->save();

            $this->notifyMember($amendment, isAmendment: true);

            $this->audit->record('assessment.amended', $trainer, TrainerAuditLog::ACTOR_TRAINER, metadata: [
                'assessment' => $amendment->uuid,
                'parent' => $original->uuid,
                'member_id' => $original->member_id,
                'version' => $amendment->version,
            ]);

            // La corrección también se refleja en el historial de Evaluación Física.
            $this->syncToPhysicalEvaluation($amendment);

            return $amendment;
        });
    }

    /**
     * Vuelca las medidas de una valoración ENVIADA al historial de Evaluación
     * Física del miembro (misma tabla `physical_evaluations` que ya consume su
     * app). Así la valoración del entrenador aparece en "Evaluación física" del
     * usuario, con su historial. Solo crea fila si trae al menos una medida (no
     * ensucia el historial con valoraciones puramente cualitativas). Best-effort:
     * un fallo aquí no rompe el envío de la valoración.
     */
    private function syncToPhysicalEvaluation(ProfessionalAssessment $a): void
    {
        $measurements = [
            'weight_kg' => $a->weight_kg,
            'height_cm' => $a->height_cm,
            'body_fat_pct' => $a->body_fat_pct,
            'muscle_mass_pct' => $a->muscle_mass_pct,
            'waist_cm' => $a->waist_cm,
            'hip_cm' => $a->hip_cm,
            'chest_cm' => $a->chest_cm,
            'arm_cm' => $a->arm_cm,
            'leg_cm' => $a->leg_cm,
        ];

        if (collect($measurements)->every(fn ($v) => $v === null)) {
            return;
        }

        $notes = collect([
            $a->observations ? 'Observaciones: '.$a->observations : null,
            $a->recommendations ? 'Recomendaciones: '.$a->recommendations : null,
        ])->filter()->implode("\n\n");

        PhysicalEvaluation::create(array_merge($measurements, [
            'member_id' => $a->member_id,
            'trainer_id' => $a->trainer_id,
            'trainer_notes' => $notes !== '' ? $notes : null,
        ]));
    }

    /** El miembro marca la valoración como leída. No la altera. */
    public function acknowledge(ProfessionalAssessment $assessment): ProfessionalAssessment
    {
        if ($assessment->acknowledged_at === null) {
            $assessment->forceFill(['acknowledged_at' => now()])->save();
        }

        return $assessment->refresh();
    }

    /**
     * Notifica al miembro por el MISMO canal que consume su app (`Notification`),
     * con `action_payload` para el deep link y un `event_key` que evita
     * duplicados por reenvío. El miembro abre la valoración (validada en backend),
     * nunca confiando en los parámetros del deep link.
     */
    private function notifyMember(ProfessionalAssessment $assessment, bool $isAmendment): void
    {
        $member = Member::find($assessment->member_id);
        if ($member === null) {
            return;
        }

        $this->notifications->createMemberNotification($member, [
            'type' => $isAmendment ? 'professional_assessment_amended' : 'professional_assessment',
            'title' => $isAmendment ? 'Valoración corregida' : 'Nueva valoración profesional',
            'message' => $isAmendment
                ? 'Tu entrenador corrigió tu valoración. Tócala para verla.'
                : 'Tu entrenador registró una nueva valoración. Tócala para verla.',
            'action_type' => 'route',
            'action_url' => '/assessment',
            'action_payload' => ['assessment_uuid' => $assessment->uuid],
            'priority' => 'high',
            'event_key' => 'assessment:'.$assessment->uuid.':v'.$assessment->version,
        ]);
    }
}
