<?php

namespace App\Console\Commands;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MembershipAiCapability;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Concede acceso demo (plan sin límites) a miembros de prueba que YA existen,
 * registrados por ellos mismos desde la app con su cédula real.
 *
 * Diferencia clave con `app:ensure-review-demo-member`: aquel CREA la cuenta
 * demo del revisor de la tienda (documento 9999999999 + OTP fijo). Éste NO crea
 * cuentas: exige que el miembro exista y sólo le levanta las barreras. Si el
 * documento no está registrado, falla — así nunca aparecen cuentas fantasma en
 * producción por un dedazo en la lista de testers.
 *
 * Tampoco toca el bypass del OTP: los testers entran con su OTP REAL por SMS.
 * `APP_REVIEW_DEMO_*` sigue siendo exclusivo del documento del revisor.
 *
 * Caducidad: el acceso se apaga SOLO al pasar `membership_end_date`, porque
 * tanto las features de la app (MemberPayload::featuresFor) como IRON IA
 * (IronAiMembershipAccessService::getCurrentMembership) resuelven la membresía
 * desde `users.plan` + `users.membership_end_date`. No hace falta apagar nada
 * a mano ni recordar una fecha.
 *
 * Idempotente y en dry-run por defecto: sólo escribe con --force.
 */
class GrantTesterDemoAccess extends Command
{
    protected $signature = 'app:grant-tester-demo-access
        {--documents= : Cédulas de los testers separadas por coma}
        {--file= : Archivo de texto con una cédula por línea (alternativa a --documents)}
        {--plan=Demo App Review : Nombre del plan demo a asignar}
        {--days=90 : Días de vigencia; al vencer, el acceso demo se apaga solo}
        {--revoke : Retira el acceso demo (vence la membresía y limpia el plan)}
        {--dry-run : Forzar simulación}
        {--force : Ejecutar los cambios reales}';

    protected $description = 'Concede (o retira) el plan demo sin límites a testers ya registrados en la app.';

    public function handle(): int
    {
        $documents = $this->documents();
        if (! $documents) {
            $this->error('Indica --documents=1018229933,... o --file=testers.txt');

            return self::FAILURE;
        }

        $planName = (string) $this->option('plan');
        $days = max(1, (int) $this->option('days'));
        $revoke = (bool) $this->option('revoke');
        $isDryRun = $this->option('dry-run') || ! $this->option('force');
        $endDate = Carbon::now(Member::BUSINESS_TZ)->addDays($days)->toDateString();

        // ── 1) Resolver miembros: todos deben existir ────────────────────────
        $members = Member::query()->with('user')
            ->whereIn('document_number', $documents)
            ->get()
            ->keyBy('document_number');

        $missing = array_values(array_diff($documents, $members->keys()->all()));
        if ($missing) {
            $this->error('Estas cédulas NO están registradas en la app (no se crea ninguna cuenta):');
            foreach ($missing as $doc) {
                $this->line("  · {$doc}");
            }
            $this->warn('Pide a esos testers que completen el registro desde la app y vuelve a correr el comando.');

            return self::FAILURE;
        }

        // Un miembro sin `User` no puede portar la membresía y quedaría con todo
        // bloqueado sin decir por qué. El registro normal siempre lo crea
        // (syncCrmUser), así que esto delata un registro corrupto: mejor abortar.
        $orphans = $members->filter(fn (Member $m) => $m->user === null);
        if ($orphans->isNotEmpty()) {
            $this->error('Estos miembros no tienen usuario CRM asociado (registro incompleto o corrupto):');
            foreach ($orphans as $m) {
                $this->line("  · {$m->document_number} — member #{$m->id}");
            }

            return self::FAILURE;
        }

        // ── 2) Reporte previo ────────────────────────────────────────────────
        $this->info($revoke ? 'RETIRAR acceso demo:' : 'CONCEDER acceso demo:');
        $this->table(
            ['documento', 'miembro', 'estado actual', 'plan actual', 'vence actual'],
            $members->map(fn (Member $m) => [
                $m->document_number,
                $m->full_name,
                $m->status,
                $m->user->plan ?: '—',
                $m->user->membershipEndDate ?: '—',
            ])->values()->all()
        );

        if (! $revoke) {
            $this->line("  plan a asignar  : {$planName}");
            $this->line("  vigencia        : {$days} días (hasta {$endDate})");
            $this->line('  módulos         : todos desbloqueados + IRON IA sin límites');
            $this->line('  legal/contrato  : aceptado y firmado (no pasan por términos ni firma)');
            $this->line('  OTP             : REAL por SMS a su teléfono (sin bypass)');
        }

        if ($isDryRun) {
            $this->warn('DRY-RUN: no se escribió nada. Repite con --force para aplicar.');

            return self::SUCCESS;
        }

        // ── 3) Aplicar ───────────────────────────────────────────────────────
        DB::transaction(function () use ($members, $planName, $endDate, $revoke): void {
            if ($revoke) {
                foreach ($members as $m) {
                    // Vence la membresía: featuresFor y IRON IA vuelven a bloquear.
                    // El consentimiento y el contrato firmado se conservan: son
                    // hechos ocurridos, no permisos, y borrarlos sería falsear el
                    // historial legal del miembro.
                    $m->user->forceFill([
                        'plan' => null,
                        'membership_end_date' => Carbon::yesterday(Member::BUSINESS_TZ)->toDateString(),
                    ])->save();
                }

                return;
            }

            $plan = $this->ensureDemoPlan($planName);

            foreach ($members as $m) {
                $m->user->forceFill([
                    'status' => 'active',
                    'plan' => $planName,
                    'membership_start_date' => Carbon::now(Member::BUSINESS_TZ)->toDateString(),
                    'membership_end_date' => $endDate,
                ])->save();

                // Deja de figurar en "Registros incompletos" del CRM y pasa a ser
                // un miembro activo normal.
                $m->forceFill(['status' => Member::STATUS_ACTIVE])->save();

                $this->clearOnboardingBarriers($m);
            }

            unset($plan);
        });

        $this->line('');
        $this->info($revoke
            ? 'Acceso demo retirado en '.$members->count().' cuenta(s).'
            : 'Acceso demo concedido a '.$members->count()." cuenta(s), vigente hasta {$endDate}.");

        if (! $revoke) {
            $this->line('Al pasar esa fecha el acceso se apaga solo; no hay que tocar nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Plan demo con todos los módulos e IRON IA sin límites. Mismo plan que usa
     * la cuenta del revisor, así que si ya existe sólo se reafirma.
     */
    private function ensureDemoPlan(string $planName): Plan
    {
        $plan = Plan::updateOrCreate(
            ['name' => $planName],
            [
                'price' => 0,
                'duration_days' => 3650,
                'active' => true,
                'features' => array_map(fn () => true, Plan::defaultFeatures()),
            ],
        );

        MembershipAiCapability::updateOrCreate(
            ['membership_plan_id' => $plan->id],
            [
                'plan_code' => mb_strtolower($planName),
                'ai_enabled' => true,
                'ai_chat_enabled' => true,
                'ai_voice_chat_enabled' => true,
                'ai_realtime_voice_enabled' => true,
                'ai_image_analysis_enabled' => true,
                'ai_file_upload_enabled' => true,
                'progress_analysis_enabled' => true,
                'smart_recommendations_enabled' => true,
                'weekly_summary_enabled' => true,
                'proactive_notifications_enabled' => true,
                'free_trial_messages' => 5,
                'monthly_messages_limit' => null,
                'daily_messages_limit' => null,
                'fair_use_limit' => null,
                'ai_audio_monthly_limit' => null,
                'ai_image_monthly_limit' => null,
                'max_output_tokens' => 1200,
                'context_level' => 'full',
                'ai_max_audio_seconds' => 120,
                'ai_max_image_size_mb' => 10,
                'is_active' => true,
            ],
        );

        return $plan;
    }

    /**
     * Consentimiento legal + firma + contrato firmado, para que el tester entre
     * directo al Home en vez de caer en "Términos"/firma.
     */
    private function clearOnboardingBarriers(Member $member): void
    {
        $member->legalConsent()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'accepted_at' => now(),
                'contract_version' => 'tester-demo',
                'terms_and_conditions' => true,
                'data_processing' => true,
                'truthfulness' => true,
                'service_contract' => true,
                'physical_risk_waiver' => true,
                'guardian_authorization' => false,
            ],
        );

        $member->signature()->updateOrCreate(
            ['member_id' => $member->id],
            ['kind' => 'onboarding', 'signature_path' => 'TESTER_DEMO_ACCEPTED'],
        );

        $type = $member->is_minor
            ? 'minor_release'
            : (string) config('contracts.default_registration_template', 'workout_registration');

        $template = ContractTemplate::firstOrCreate(
            ['template_key' => 'tester_demo'],
            [
                'name' => 'Tester Demo',
                'version' => 'demo-1',
                'applies_to' => 'any',
                'source_file_path' => 'contracts/templates/tester_demo.placeholder',
                'active' => true,
            ],
        );

        $hasSigned = $member->contracts()
            ->where('contract_type', $type)
            ->where('status', MemberContract::STATUS_SIGNED)
            ->exists();

        if (! $hasSigned) {
            $member->contracts()->create([
                'contract_template_id' => $template->id,
                'contract_type' => $type,
                'status' => MemberContract::STATUS_SIGNED,
                'signature_path' => 'TESTER_DEMO_ACCEPTED',
                'signed_at' => now(),
                'template_version' => $template->version,
                'acceptance_snapshot' => ['source' => 'tester_demo', 'accepted' => true],
            ]);
        }
    }

    /**
     * Cédulas desde --documents o --file, normalizadas y sin duplicados.
     *
     * @return string[]
     */
    private function documents(): array
    {
        $raw = (string) ($this->option('documents') ?? '');

        if ($file = $this->option('file')) {
            if (! is_readable($file)) {
                $this->error("No se puede leer el archivo: {$file}");

                return [];
            }
            $raw .= ','.str_replace(["\r\n", "\r", "\n"], ',', (string) file_get_contents($file));
        }

        return collect(explode(',', $raw))
            ->map(fn ($d) => Member::normalizeDocumentNumber(trim($d)))
            ->filter(fn ($d) => $d !== '' && $d !== null)
            ->unique()
            ->values()
            ->all();
    }
}
