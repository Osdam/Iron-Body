<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberBiometric;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberDeviceToken;
use App\Models\MemberReenrollmentToken;
use App\Models\MemberRiskLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Desvincula TODOS los dispositivos de un miembro y lo deja como si nunca
 * hubiera entrado desde ningún equipo. Pensado para repetir pruebas de login
 * sin quedar atrapado en los guardas de seguridad.
 *
 * Qué desbloquea, uno por uno:
 *  - Sesiones activas → quita el 409 "la cuenta ya está activa en otro
 *    dispositivo" (DeviceSessionService::concurrentActiveSession).
 *  - Vínculo equipo↔cuenta → quita "cuenta asociada a otro usuario"
 *    (AuthController::deviceBindingDenied) y el estado de equipo confiable,
 *    así el próximo login vuelve a pedir OTP desde cero.
 *  - Retos OTP pendientes, tokens de push, bloqueos de riesgo y tokens de
 *    re-enrolamiento.
 *  - Con --include-biometrics, también el rostro de referencia: si no, el login
 *    seguirá pidiendo verificación facial (AuthController::faceRequiredFor).
 *
 * Lo que NO borra: `member_security_events`. Es el registro de auditoría de
 * seguridad (quién entró, desde dónde, qué se denegó) y borrarlo falsearía el
 * historial. No bloquea ningún login.
 *
 * NO toca el plan, la membresía, el contrato ni los datos del miembro: para eso
 * está `app:grant-tester-demo-access`. Dry-run por defecto.
 */
class ResetMemberDevices extends Command
{
    protected $signature = 'app:reset-member-devices
        {--documents= : Cédulas separadas por coma}
        {--file= : Archivo con una cédula por línea}
        {--include-biometrics : Borra también el rostro de referencia (vuelve a pedir registro facial)}
        {--dry-run : Forzar simulación}
        {--force : Ejecutar el borrado real}';

    protected $description = 'Desvincula todos los dispositivos de un miembro (sesiones, vínculos, retos, push) para repetir pruebas de login.';

    public function handle(): int
    {
        $documents = $this->documents();
        if (! $documents) {
            $this->error('Indica --documents=1004301550,... o --file=testers.txt');

            return self::FAILURE;
        }

        $withBiometrics = (bool) $this->option('include-biometrics');
        $isDryRun = $this->option('dry-run') || ! $this->option('force');

        $members = Member::query()
            ->whereIn('document_number', $documents)
            ->get()
            ->keyBy('document_number');

        $missing = array_values(array_diff($documents, $members->keys()->all()));
        if ($missing) {
            $this->error('Estas cédulas no están registradas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $memberIds = $members->pluck('id')->values();

        // ── Inventario de lo que se va a limpiar ─────────────────────────────
        $counts = [
            'sesiones de dispositivo' => MemberDeviceSession::whereIn('member_id', $memberIds)->count(),
            'vínculos equipo↔cuenta' => MemberDeviceBinding::whereIn('member_id', $memberIds)->count(),
            'tokens de push' => MemberDeviceToken::whereIn('member_id', $memberIds)->count(),
            'retos OTP pendientes' => MemberAuthChallenge::whereIn('member_id', $memberIds)->count(),
            'bloqueos de riesgo' => MemberRiskLock::whereIn('member_id', $memberIds)->count(),
            'tokens de re-enrolamiento' => MemberReenrollmentToken::whereIn('member_id', $memberIds)->count(),
        ];
        if ($withBiometrics) {
            $counts['rostros de referencia'] = MemberBiometric::whereIn('member_id', $memberIds)->count();
        }

        $this->info('Miembros afectados:');
        $this->table(
            ['documento', 'miembro', 'sesiones activas'],
            $members->map(fn (Member $m) => [
                $m->document_number,
                $m->full_name,
                MemberDeviceSession::where('member_id', $m->id)->whereNull('revoked_at')->count(),
            ])->values()->all()
        );

        $this->line('');
        $this->info('Se va a borrar:');
        $this->table(
            ['qué', 'filas'],
            collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all()
        );

        if (! $withBiometrics) {
            $faces = MemberBiometric::whereIn('member_id', $memberIds)->whereNotNull('face_path')->count();
            if ($faces > 0) {
                $this->warn("Hay {$faces} rostro(s) de referencia: el login seguirá pidiendo verificación facial. ".
                    'Usa --include-biometrics si quieres empezar también sin rostro.');
            }
        }

        $this->line('');
        $this->info('NO se tocan: plan, membresía, contrato, datos del miembro ni el historial de seguridad.');

        if ($isDryRun) {
            $this->warn('DRY-RUN: no se borró nada. Repite con --force para aplicar.');

            return self::SUCCESS;
        }

        // ── Borrado ──────────────────────────────────────────────────────────
        // Los archivos de rostro se borran fuera de la transacción: el
        // almacenamiento no es transaccional y un rollback no los recuperaría.
        $facesDeleted = 0;
        if ($withBiometrics) {
            foreach (MemberBiometric::whereIn('member_id', $memberIds)->get() as $bio) {
                if ($bio->face_path && Storage::disk('local')->exists($bio->face_path)) {
                    Storage::disk('local')->delete($bio->face_path);
                    $facesDeleted++;
                }
            }
        }

        DB::transaction(function () use ($memberIds, $withBiometrics): void {
            MemberDeviceSession::whereIn('member_id', $memberIds)->delete();
            MemberDeviceBinding::whereIn('member_id', $memberIds)->delete();
            MemberDeviceToken::whereIn('member_id', $memberIds)->delete();
            MemberAuthChallenge::whereIn('member_id', $memberIds)->delete();
            MemberRiskLock::whereIn('member_id', $memberIds)->delete();
            MemberReenrollmentToken::whereIn('member_id', $memberIds)->delete();

            if ($withBiometrics) {
                MemberBiometric::whereIn('member_id', $memberIds)->delete();
                Member::whereIn('id', $memberIds)->update([
                    'biometric_status' => Member::BIOMETRIC_PENDING,
                ]);
            }
        });

        $this->line('');
        $this->info('Dispositivos desvinculados en '.$members->count().' cuenta(s).');
        if ($facesDeleted > 0) {
            $this->line("  archivos de rostro borrados: {$facesDeleted}");
        }
        $this->line('El próximo login arranca limpio: sin sesión previa, sin equipo confiable y con OTP por SMS.');

        return self::SUCCESS;
    }

    /** @return string[] */
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
