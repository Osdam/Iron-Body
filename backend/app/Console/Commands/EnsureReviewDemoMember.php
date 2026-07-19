<?php

namespace App\Console\Commands;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MembershipAiCapability;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea o actualiza SÓLO la cuenta demo de App Review (documento configurado o
 * 9999999999): miembro activo + usuario con membresía vigente + plan con todos
 * los módulos desbloqueados + consentimiento legal aceptado + contrato firmado,
 * para que el revisor de la tienda entre directo al Home y navegue toda la app
 * sin SMS real, sin pago, sin términos y sin firma.
 *
 * Idempotente: puede correrse las veces que haga falta; sólo toca la cuenta demo
 * (y un plan/plantilla dedicados). NO habilita el OTP fijo por sí mismo — eso lo
 * controla APP_REVIEW_DEMO_ENABLED.
 */
class EnsureReviewDemoMember extends Command
{
    protected $signature = 'app:ensure-review-demo-member
        {--phone= : Teléfono placeholder del miembro demo (por defecto 3000000000)}
        {--plan= : Nombre de plan a mostrar (por defecto "Demo App Review")}';

    protected $description = 'Crea/actualiza la cuenta demo de App Review (solo el documento demo).';

    public function handle(): int
    {
        $document = (string) (config('services.app_review_demo.document') ?: '9999999999');
        $phone    = (string) ($this->option('phone') ?: '3000000000');
        $planName = (string) ($this->option('plan') ?: 'Demo App Review');
        $email    = 'apple.review+demo@ironbodyneiva.cloud';
        $endDate  = Carbon::now(Member::BUSINESS_TZ)->addYears(5)->toDateString();

        $this->info("Preparando cuenta demo para documento {$document}…");

        $member = DB::transaction(function () use ($document, $phone, $planName, $email, $endDate): Member {
            // 1) Plan con TODOS los módulos desbloqueados. `MemberPayload::featuresFor`
            //    resuelve las features por el NOMBRE del plan del usuario: si no hay
            //    un Plan que coincida, la barra inferior sale con candados. Este plan
            //    dedicado (sólo para la demo) habilita ranking/clases/progreso/
            //    nutrición/IRON IA/rutinas.
            $allFeatures = array_map(fn () => true, Plan::defaultFeatures());
            $plan = Plan::updateOrCreate(
                ['name' => $planName],
                [
                    'price'         => 0,
                    'duration_days' => 3650,
                    'active'        => true,
                    'features'      => $allFeatures,
                ],
            );

            // 1b) Capacidades de IRON IA del plan demo (tabla auxiliar
            //     `membership_ai_capabilities`). Aquí viven los flags que la app
            //     usa para mostrar/ocultar voz, imagen, uploads y Tiempo Real, y
            //     que hacían aparecer "actualiza tu plan". Todo habilitado y con
            //     límites nulos (= ilimitado) SOLO para el plan demo. El resolver
            //     enlaza por `membership_plan_id` (plan del catálogo) primero.
            MembershipAiCapability::updateOrCreate(
                ['membership_plan_id' => $plan->id],
                [
                    'plan_code'                       => mb_strtolower($planName),
                    'ai_enabled'                      => true,
                    'ai_chat_enabled'                 => true,
                    'ai_voice_chat_enabled'           => true,
                    'ai_realtime_voice_enabled'       => true,
                    'ai_image_analysis_enabled'       => true,
                    'ai_file_upload_enabled'          => true,
                    'progress_analysis_enabled'       => true,
                    'smart_recommendations_enabled'   => true,
                    'weekly_summary_enabled'          => true,
                    'proactive_notifications_enabled' => true,
                    'free_trial_messages'             => 5,
                    // Límites nulos = sin tope (dentro de la cuota general nula).
                    'monthly_messages_limit'          => null,
                    'daily_messages_limit'            => null,
                    'fair_use_limit'                  => null,
                    'ai_audio_monthly_limit'          => null,
                    'ai_image_monthly_limit'          => null,
                    'max_output_tokens'               => 1200,
                    'context_level'                   => 'full',
                    'ai_max_audio_seconds'            => 120,
                    'ai_max_image_size_mb'            => 10,
                    'is_active'                       => true,
                ],
            );

            // 2) Usuario portador de la MEMBRESÍA (plan + fin de vigencia futuro).
            $user = User::query()->where('document', $document)->first();
            $user ??= User::query()->where('email', $email)->first();
            $user ??= new User();

            $user->fill([
                'name'                => 'Apple Review',
                'email'               => $user->email ?: $email,
                'document'            => $document,
                'phone'               => $phone,
                'status'              => 'active',
                'plan'                => $planName,
                'membership_start_date' => Carbon::now(Member::BUSINESS_TZ)->toDateString(),
                'membership_end_date' => $endDate,
            ]);
            if (empty($user->password)) {
                // Password aleatoria: la cuenta demo NO usa login por password
                // (entra por documento + OTP fijo). Sólo satisface el NOT NULL.
                $user->password = Hash::make(Str::random(40));
            }
            $user->save();

            // 3) Miembro activo vinculado al usuario. member_uuid/access_hash se
            //    autogeneran en el modelo. Match por documento (único).
            $member = Member::query()->where('document_number', $document)->first() ?? new Member();
            $member->fill([
                'user_id'         => $user->id,
                'full_name'       => 'Apple Review',
                'email'           => $email,
                'document_number' => $document,
                'phone'           => $phone,
                'is_minor'        => false,
                'status'          => Member::STATUS_ACTIVE,
            ]);
            $member->save();

            // 4) Consentimiento legal ACEPTADO (términos, tratamiento de datos,
            //    veracidad, contrato de servicio, exención de riesgo físico).
            $member->legalConsent()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'accepted_at'            => now(),
                    'contract_version'       => 'apple-review-demo',
                    'terms_and_conditions'   => true,
                    'data_processing'        => true,
                    'truthfulness'           => true,
                    'service_contract'       => true,
                    'physical_risk_waiver'   => true,
                    'guardian_authorization' => false, // adulto: no aplica
                ],
            );

            // 5) Firma placeholder del onboarding (no es un archivo real; la barrera
            //    de acceso mira el contrato firmado, no este blob).
            $member->signature()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'kind'           => 'onboarding',
                    'signature_path' => 'APPLE_REVIEW_DEMO_ACCEPTED',
                ],
            );

            // 6) Contrato FIRMADO del tipo recomendado → GET member/contracts/status
            //    devuelve requires_contract=false (no manda a "Términos"/firma).
            $type = $member->is_minor
                ? 'minor_release'
                : (string) config('contracts.default_registration_template', 'workout_registration');

            $template = ContractTemplate::firstOrCreate(
                ['template_key' => 'apple_review_demo'],
                [
                    'name'             => 'Apple Review Demo',
                    'version'          => 'demo-1',
                    'applies_to'       => 'any',
                    'source_file_path' => 'contracts/templates/apple_review_demo.placeholder',
                    'active'           => true,
                ],
            );

            $hasSigned = $member->contracts()
                ->where('contract_type', $type)
                ->where('status', MemberContract::STATUS_SIGNED)
                ->exists();
            if (! $hasSigned) {
                $member->contracts()->create([
                    'contract_template_id' => $template->id,
                    'contract_type'        => $type,
                    'status'               => MemberContract::STATUS_SIGNED,
                    'signature_path'       => 'APPLE_REVIEW_DEMO_ACCEPTED',
                    'signed_at'            => now(),
                    'template_version'     => $template->version,
                    'acceptance_snapshot'  => ['source' => 'apple_review_demo', 'accepted' => true],
                ]);
            }

            return $member;
        });

        $enabled = config('services.app_review_demo.enabled') ? 'true' : 'false';
        $this->info('Cuenta demo lista:');
        $this->line("  member_id       : {$member->id}");
        $this->line("  document_number : {$member->document_number}");
        $this->line("  status          : {$member->status}");
        $this->line("  plan            : {$planName} (vence {$endDate})");
        $this->line('  módulos         : desbloqueados (features del plan en true)');
        $this->line('  IRON IA         : texto+voz+imagen+uploads+Tiempo Real habilitados (sin upgrade)');
        $this->line('  legal/contrato  : aceptado + contrato firmado (sin términos/firma)');
        $this->line("  APP_REVIEW_DEMO_ENABLED = {$enabled}");
        if (! config('services.app_review_demo.enabled')) {
            $this->warn('El acceso demo está DESHABILITADO: pon APP_REVIEW_DEMO_ENABLED=true para el OTP fijo.');
        }
        if (! config('services.app_review_demo.otp')) {
            $this->warn('APP_REVIEW_DEMO_OTP no está configurado: define el OTP fijo (p. ej. 123456).');
        }

        return self::SUCCESS;
    }
}
