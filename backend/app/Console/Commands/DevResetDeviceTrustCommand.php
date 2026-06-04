<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reset SEGURO de device trust / 2FA para DESARROLLO.
 *
 * Limpia SOLO datos de seguridad/sesión/2FA (vínculos de dispositivo, sesiones,
 * retos OTP, bloqueos de riesgo, tokens push) de un MIEMBRO o de un DISPOSITIVO
 * concreto, para poder reprobar el flujo de login/2FA tras pruebas previas.
 *
 * NUNCA toca: members, users, payments, membership (plan/fecha fin), contratos,
 * plantillas, documentos de identidad, biometría (rostro), planes, ejercicios ni
 * la foto de perfil. Es decir, NO afecta la membresía ni las features premium.
 *
 * Por defecto corre en dry-run. Sólo ejecuta con --force. Abortado fuera de
 * entornos locales/dev/test y si la base no parece de desarrollo.
 */
class DevResetDeviceTrustCommand extends Command
{
    protected $signature = 'dev:reset-device-trust
        {--email=*        : Correo(s) del miembro/usuario}
        {--document=*     : Documento(s) del miembro}
        {--phone=*        : Teléfono(s) del miembro}
        {--member-id=*    : ID(s) de member}
        {--user-id=*      : ID(s) de user}
        {--device-name=*  : Nombre(s) de dispositivo (ej. iPhone)}
        {--device-id=*    : device_id exacto(s)}
        {--device-uuid=*  : uuid(s) de sesión de dispositivo}
        {--revoke-sessions : Revoca las sesiones activas (y baja sus push tokens)}
        {--clear-bindings  : Elimina los vínculos dispositivo↔cuenta (libera el equipo)}
        {--clear-otp       : Elimina los retos OTP/2FA pendientes/usados}
        {--clear-risk      : Elimina bloqueos de riesgo y eventos de seguridad}
        {--reset-local-hints : (informativo) recuerda resetear el estado local del device}
        {--include-active  : Necesario si algún miembro objetivo está activo}
        {--dry-run         : Solo muestra lo que haría (default si no hay --force)}
        {--force           : Ejecuta de verdad}';

    protected $description = 'Reset seguro de device trust/2FA (bindings, sesiones, OTP, risk) por miembro o dispositivo. No toca membresía ni datos base.';

    public function handle(): int
    {
        // ── Guardas de entorno y base de datos ──────────────────────────────
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->error('Abortado: este comando solo corre en local/development/testing. APP_ENV='.app()->environment());
            return self::FAILURE;
        }
        $db = (string) config('database.connections.'.config('database.default').'.database');
        // En testing la base es efímera (sqlite :memory:); en local/dev exigimos
        // que el nombre parezca de desarrollo para no tocar una base real.
        if (! app()->environment('testing') && ! preg_match('/dev|local|test/i', $db)) {
            $this->error("Abortado: DB_DATABASE='{$db}' no parece una base de desarrollo. Cancelo por seguridad.");
            return self::FAILURE;
        }
        $this->info("Entorno seguro: APP_ENV=".app()->environment().", DB={$db}.");

        $dryRun = $this->option('dry-run') || ! $this->option('force');

        // ── Resolver objetivos ──────────────────────────────────────────────
        $members  = $this->resolveMembers();
        $deviceIds = $this->resolveDeviceIds();

        if ($members->isEmpty() && $deviceIds->isEmpty()) {
            $this->error('No se indicó ningún objetivo. Usa --email/--document/--phone/--member-id/--user-id o --device-name/--device-id/--device-uuid.');
            return self::FAILURE;
        }

        // Vínculos/sesiones/tokens se limpian por los miembros EXPLÍCITOS o por
        // los dispositivos objetivo (NO por dispositivos hermanos del dueño).
        $bindingMemberIds = $members->pluck('id')->filter()->unique()->values();

        // OTP/risk/eventos son por-miembro: incluyen al dueño del dispositivo
        // objetivo (para dejar su 2FA limpia) además de los miembros explícitos.
        $ownerMemberIds = collect();
        if ($deviceIds->isNotEmpty()) {
            $ownerMemberIds = MemberDeviceBinding::whereIn('device_id', $deviceIds)->pluck('member_id')
                ->merge(MemberDeviceSession::whereIn('device_id', $deviceIds)->pluck('member_id'))
                ->filter()->unique()->values();
        }
        $memberScopeIds = $bindingMemberIds->merge($ownerMemberIds)->filter()->unique()->values();

        // ── Chequeo de cuentas activas ──────────────────────────────────────
        $activeMembers = Member::whereIn('id', $memberScopeIds)
            ->where('status', Member::STATUS_ACTIVE)->get();
        if ($activeMembers->isNotEmpty() && ! $this->option('include-active')) {
            $this->warn('Hay miembros ACTIVOS en el objetivo:');
            foreach ($activeMembers as $m) {
                $this->line("  - member#{$m->id} {$m->full_name} (doc {$m->document_number}) [{$m->status}]");
            }
            $this->error('Usa --include-active para incluirlos (no se borran; solo se resetea su device trust).');
            return self::FAILURE;
        }

        // ── Construir el plan de limpieza ───────────────────────────────────
        $this->line('');
        $this->info($dryRun ? '── DRY-RUN (no se modifica nada) ──' : '── EJECUTANDO ──');
        $this->describeTargets($members, $deviceIds, $bindingMemberIds, $memberScopeIds);

        $doSessions = $this->option('revoke-sessions');
        $doBindings = $this->option('clear-bindings');
        $doOtp      = $this->option('clear-otp');
        $doRisk     = $this->option('clear-risk');
        if (! $doSessions && ! $doBindings && ! $doOtp && ! $doRisk) {
            $this->warn('No se eligió ninguna acción (--revoke-sessions/--clear-bindings/--clear-otp/--clear-risk). Nada que hacer.');
            return self::SUCCESS;
        }

        $plan = $this->buildPlan($bindingMemberIds, $memberScopeIds, $deviceIds, $doSessions, $doBindings, $doOtp, $doRisk);
        $this->line('');
        $this->info('Acciones:');
        foreach ($plan as $label => $count) {
            $this->line(sprintf('  • %-42s %d', $label, $count));
        }

        if ($dryRun) {
            $this->line('');
            $this->comment('DRY-RUN: vuelve a ejecutar con --force para aplicar.');
            $this->localHintsNote();
            return self::SUCCESS;
        }

        // ── Ejecutar dentro de transacción ──────────────────────────────────
        DB::transaction(function () use ($bindingMemberIds, $memberScopeIds, $deviceIds, $doSessions, $doBindings, $doOtp, $doRisk): void {
            if ($doSessions) {
                // Revoca sesiones (conserva el histórico) y baja push tokens.
                MemberDeviceSession::where(function ($q) use ($bindingMemberIds, $deviceIds): void {
                    $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds);
                })->whereNull('revoked_at')->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'dev_reset_device_trust',
                ]);
                if (Schema::hasTable('member_device_tokens')) {
                    DB::table('member_device_tokens')->where(function ($q) use ($bindingMemberIds, $deviceIds): void {
                        $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds);
                    })->delete();
                }
            }
            if ($doBindings) {
                MemberDeviceBinding::where(function ($q) use ($bindingMemberIds, $deviceIds): void {
                    $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds);
                })->delete();
            }
            if ($doOtp) {
                DB::table('member_auth_challenges')->whereIn('member_id', $memberScopeIds)->delete();
                if (Schema::hasTable('member_reenrollment_tokens')) {
                    DB::table('member_reenrollment_tokens')->whereIn('member_id', $memberScopeIds)->delete();
                }
            }
            if ($doRisk) {
                if (Schema::hasTable('member_risk_locks')) {
                    DB::table('member_risk_locks')->whereIn('member_id', $memberScopeIds)->delete();
                }
                DB::table('member_security_events')->whereIn('member_id', $memberScopeIds)->delete();
                // Si el reset de riesgo deja a un miembro en estado suspendido, lo
                // devolvemos a activo (no se tocan otros estados como deleted).
                Member::whereIn('id', $memberScopeIds)
                    ->where('status', Member::STATUS_SUSPENDED)
                    ->update(['status' => Member::STATUS_ACTIVE]);
            }
        });

        $this->line('');
        $this->info('✔ Device trust reseteado. Membresía, plan, pagos, contratos y biometría NO se tocaron.');
        $this->localHintsNote();
        \Illuminate\Support\Facades\Log::info('dev:reset-device-trust', [
            'member_scope_ids' => $memberScopeIds->all(),
            'binding_member_ids' => $bindingMemberIds->all(),
            'device_ids_count' => $deviceIds->count(),
            'actions' => compact('doSessions', 'doBindings', 'doOtp', 'doRisk'),
        ]);

        return self::SUCCESS;
    }

    private function resolveMembers(): Collection
    {
        $emails    = $this->arr('email');
        $documents = $this->arr('document');
        $phones    = $this->arr('phone');
        $memberIds = $this->arr('member-id');
        $userIds   = $this->arr('user-id');

        if (! $emails && ! $documents && ! $phones && ! $memberIds && ! $userIds) {
            return collect();
        }

        return Member::query()->with('user')
            ->where(function ($q) use ($emails, $documents, $phones, $memberIds, $userIds): void {
                if ($emails)    { $q->orWhereIn('email', $emails)->orWhereHas('user', fn ($u) => $u->whereIn('email', $emails)); }
                if ($documents) { $q->orWhereIn('document_number', $documents); }
                if ($phones)    { $q->orWhereIn('phone', $phones); }
                if ($memberIds) { $q->orWhereIn('id', $memberIds); }
                if ($userIds)   { $q->orWhereIn('user_id', $userIds); }
            })
            ->get();
    }

    /** device_id objetivo a partir de --device-id / --device-name / --device-uuid. */
    private function resolveDeviceIds(): Collection
    {
        $names = $this->arr('device-name');
        $ids   = $this->arr('device-id');
        $uuids = $this->arr('device-uuid');

        $result = collect($ids);

        if ($names) {
            $result = $result
                ->merge(MemberDeviceBinding::whereIn('device_name', $names)->pluck('device_id'))
                ->merge(MemberDeviceSession::whereIn('device_name', $names)->pluck('device_id'));
        }
        if ($uuids) {
            $result = $result->merge(MemberDeviceSession::whereIn('uuid', $uuids)->pluck('device_id'));
        }

        return $result->filter()->unique()->values();
    }

    private function buildPlan(Collection $bindingMemberIds, Collection $memberScopeIds, Collection $deviceIds, bool $s, bool $b, bool $o, bool $r): array
    {
        $plan = [];
        if ($s) {
            $plan['Sesiones a revocar'] = MemberDeviceSession::where(fn ($q) =>
                $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds)
            )->whereNull('revoked_at')->count();
            $plan['Push tokens a eliminar'] = Schema::hasTable('member_device_tokens')
                ? DB::table('member_device_tokens')->where(fn ($q) =>
                    $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds))->count()
                : 0;
        }
        if ($b) {
            $plan['Vínculos de dispositivo a eliminar'] = MemberDeviceBinding::where(fn ($q) =>
                $q->whereIn('member_id', $bindingMemberIds)->orWhereIn('device_id', $deviceIds)
            )->count();
        }
        if ($o) {
            $plan['Retos OTP a eliminar'] = DB::table('member_auth_challenges')->whereIn('member_id', $memberScopeIds)->count();
        }
        if ($r) {
            $plan['Bloqueos de riesgo a eliminar'] = Schema::hasTable('member_risk_locks')
                ? DB::table('member_risk_locks')->whereIn('member_id', $memberScopeIds)->count() : 0;
            $plan['Eventos de seguridad a eliminar'] = DB::table('member_security_events')->whereIn('member_id', $memberScopeIds)->count();
        }

        return $plan;
    }

    private function describeTargets(Collection $members, Collection $deviceIds, Collection $bindingMemberIds, Collection $memberScopeIds): void
    {
        if ($members->isNotEmpty()) {
            $this->line('Miembros objetivo:');
            foreach ($members as $m) {
                $this->line("  - member#{$m->id} {$m->full_name} (doc {$m->document_number}) [{$m->status}]");
            }
        }
        if ($deviceIds->isNotEmpty()) {
            $this->line('Dispositivos objetivo (device_id enmascarado):');
            foreach (MemberDeviceBinding::whereIn('device_id', $deviceIds)->get() as $b) {
                $this->line("  - {$b->device_name} [".$this->mask($b->device_id)."] vinculado a member#{$b->member_id}");
            }
        }
        $this->line('Vínculos/sesiones por member_id: '.($bindingMemberIds->isEmpty() ? '(solo por dispositivo)' : $bindingMemberIds->implode(', ')));
        $this->line('OTP/risk/eventos por member_id: '.$memberScopeIds->implode(', '));
    }

    private function localHintsNote(): void
    {
        if ($this->option('reset-local-hints')) {
            $this->comment('Recuerda: el estado LOCAL del dispositivo (secure storage/Keychain) se '
                .'limpia EN EL DISPOSITIVO (logout, "Reset local security state" en debug, o reinstalar la app).');
        }
    }

    private function arr(string $name): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->option($name)), fn ($v) => $v !== ''));
    }

    private function mask(?string $v): string
    {
        $v = (string) $v;
        return strlen($v) <= 8 ? str_repeat('•', strlen($v)) : substr($v, 0, 6).'…'.substr($v, -2);
    }
}
