<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea o actualiza SÓLO la cuenta demo de App Review (documento configurado o
 * 9999999999): miembro activo + usuario con membresía vigente, para que el
 * revisor de la tienda pueda navegar toda la app sin SMS real ni pago.
 *
 * Idempotente: puede correrse las veces que haga falta; sólo toca la cuenta demo.
 * NO habilita el OTP fijo por sí mismo — eso lo controla APP_REVIEW_DEMO_ENABLED.
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
            // Usuario portador de la MEMBRESÍA (plan + fin de vigencia futuro).
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

            // Miembro activo vinculado al usuario. member_uuid/access_hash se
            // autogeneran en el modelo. Match por documento (único).
            $member = Member::query()->where('document_number', $document)->first() ?? new Member();
            $member->fill([
                'user_id'         => $user->id,
                'full_name'       => 'Apple Review',
                'email'           => $email,
                'document_number' => $document,
                'phone'           => $phone,
                'status'          => Member::STATUS_ACTIVE,
            ]);
            $member->save();

            return $member;
        });

        $enabled = config('services.app_review_demo.enabled') ? 'true' : 'false';
        $this->info('Cuenta demo lista:');
        $this->line("  member_id       : {$member->id}");
        $this->line("  document_number : {$member->document_number}");
        $this->line("  status          : {$member->status}");
        $this->line("  plan            : {$planName} (vence {$endDate})");
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
