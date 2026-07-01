<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Importa los socios VIGENTES del sistema anterior a Iron Body.
 *
 * Fuente: CSV (delimitado por `;`, UTF-8) ya depurado — una fila por socio con
 * su última membresía vigente (ver `socios_migracion_475_vigentes.csv`). Por
 * cada fila crea/actualiza de forma IDEMPOTENTE:
 *   - `users`   → ficha CRM (documento, teléfono, plan y vigencia REAL del viejo).
 *   - `members` → ficha app enlazada por `user_id` (login por documento + OTP).
 *   - `payments`→ un pago histórico de la membresía vigente (ref `MIGR-*`).
 *
 * Reglas clave:
 *   - Documento y teléfono se re-normalizan con los métodos del backend
 *     (`Member::normalizeDocumentNumber` / `normalizePhone`) para quedar IDÉNTICOS
 *     a los socios registrados por la app (el login compara así).
 *   - La vigencia se toma TAL CUAL del CSV (no se recalcula con la duración del
 *     plan nuevo): preserva la fecha de fin real del sistema anterior.
 *   - No pisa datos: en un socio ya existente solo rellena campos vacíos y solo
 *     extiende la membresía si la del CSV termina más tarde (nunca la acorta).
 *   - Biometría: queda `pending` (el rostro NO se migra — el viejo usaba huella;
 *     se captura nuevo desde la app o el terminal). No toca `registered`.
 *
 *   php artisan iron:import-legacy-members ruta/al.csv --dry-run
 *   php artisan iron:import-legacy-members ruta/al.csv
 */
class ImportLegacyMembersCommand extends Command
{
    protected $signature = 'iron:import-legacy-members
        {file : Ruta al CSV depurado (delimitado por ;)}
        {--dry-run : Simula todo dentro de una transacción y hace rollback}
        {--limit=0 : Procesa solo las primeras N filas (0 = todas)}';

    protected $description = 'Importa socios vigentes del sistema anterior (CSV) a users + members + payments.';

    /** Mapa nombre de plan → id (cargado una vez). */
    private array $planIds = [];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('El CSV no tiene filas de datos.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $this->planIds = Plan::query()->pluck('id', 'name')->all();
        $dryRun = (bool) $this->option('dry-run');

        $c = ['users_new' => 0, 'users_upd' => 0, 'members_new' => 0, 'members_upd' => 0,
              'payments_new' => 0, 'no_phone' => 0, 'plan_missing' => 0, 'skipped' => 0, 'errors' => 0];
        $missingPlans = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // +1 header, +1 base-0
                try {
                    $this->importRow($row, $c, $missingPlans);
                } catch (Throwable $e) {
                    $c['errors']++;
                    $this->warn("Fila {$line}: error — {$e->getMessage()}");
                }
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: se revirtieron todos los cambios (no se escribió nada).');
            } else {
                DB::commit();
                $this->info('Importación confirmada.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Abortado (rollback total): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Métrica', 'Total'], collect($c)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($missingPlans !== []) {
            $this->warn('Planes NO encontrados en la BD (se importó el socio SIN pago): '.implode(', ', array_unique($missingPlans)));
            $this->warn('→ Corre PlansSeeder o crea esos planes y vuelve a ejecutar (es idempotente).');
        }
        if ($c['no_phone'] > 0) {
            $this->warn("{$c['no_phone']} socios quedaron SIN teléfono válido → no recibirán OTP hasta corregirlo en el CRM.");
        }

        return self::SUCCESS;
    }

    /** Procesa una fila del CSV (mapa header→valor). */
    private function importRow(array $row, array &$c, array &$missingPlans): void
    {
        $doc = Member::normalizeDocumentNumber($row['document_number'] ?? null);
        $fullName = trim((string) ($row['full_name'] ?? ''));
        if ($doc === null || $fullName === '') {
            $c['skipped']++;

            return;
        }

        $phone = Member::normalizePhone($row['phone'] ?? null);
        if ($phone !== null && ! preg_match('/^3\d{9}$/', $phone)) {
            $phone = null; // teléfono inservible → null (mejor que enviar SMS a basura)
        }
        if ($phone === null) {
            $c['no_phone']++;
        }

        $planName = trim((string) ($row['plan'] ?? ''));
        $planId = $this->planIds[$planName] ?? null;
        if ($planName !== '' && $planId === null) {
            $missingPlans[] = $planName;
            $c['plan_missing']++;
        }

        $birth = $this->parseDate($row['birth_date'] ?? null);
        $start = $this->parseDate($row['membership_start_date'] ?? null);
        $end = $this->parseDate($row['membership_end_date'] ?? null);
        $rawEmail = $this->cleanEmail($row['email'] ?? null);

        // ── User (idempotente por documento) ──────────────────────────────
        $user = User::query()->where('document', $doc)->first();
        $userIsNew = $user === null;
        if ($userIsNew) {
            $user = new User();
            $user->password = Str::random(40); // el cast 'hashed' lo bcrypt-ea; el login es por OTP, no por password
        }

        $user->name = $fullName;
        $user->document = $doc;
        $user->email = $this->resolveEmail($rawEmail, $doc, $user->id);
        if (blank($user->phone) && $phone !== null) {
            $user->phone = $phone;
        }
        if (blank($user->birth_date) && $birth !== null) {
            $user->birth_date = $birth->toDateString();
        }
        if (blank($user->address) && filled($row['address'] ?? null)) {
            $user->address = trim((string) $row['address']);
        }
        if (blank($user->emergency_contact) && filled($row['emergency_contact'] ?? null)) {
            $user->emergency_contact = trim((string) $row['emergency_contact']);
        }

        // Membresía: solo extiende, nunca acorta (protege renovaciones ya hechas
        // en el sistema nuevo). Vigencia TAL CUAL del CSV.
        $curEnd = $user->membership_end_date ? Carbon::parse($user->membership_end_date) : null;
        if ($end !== null && ($curEnd === null || $end->gt($curEnd))) {
            if ($planName !== '') {
                $user->plan = $planName;
            }
            $user->membership_start_date = $start?->toDateString();
            $user->membership_end_date = $end->toDateString();
        }
        $user->status = 'active';
        $user->save();
        $userIsNew ? $c['users_new']++ : $c['users_upd']++;

        // ── Member (idempotente por documento) ────────────────────────────
        $member = Member::query()->where('document_number', $doc)->first();
        $memberIsNew = $member === null;
        if ($memberIsNew) {
            $member = new Member(); // member_uuid / access_hash se autogeneran al crear
        }
        $member->user_id = $user->id;
        $member->full_name = $fullName;
        if (blank($member->email) && $rawEmail !== null) {
            $member->email = $rawEmail;
        }
        if (blank($member->phone) && $phone !== null) {
            $member->phone = $phone;
        }
        $member->document_number = $doc;
        $member->status = Member::STATUS_ACTIVE;
        if ($member->biometric_status !== Member::BIOMETRIC_REGISTERED) {
            $member->biometric_status = Member::BIOMETRIC_PENDING;
        }
        $member->save();
        $memberIsNew ? $c['members_new']++ : $c['members_upd']++;

        // ── Payment histórico (idempotente por reference) ─────────────────
        if ($planId !== null) {
            $oldId = trim((string) ($row['old_membership_id'] ?? ''));
            $reference = 'MIGR-'.($oldId !== '' ? $oldId : 'DOC-'.$doc);
            $payment = Payment::query()->firstOrNew(['reference' => $reference]);
            if (! $payment->exists) {
                $payment->user_id = $user->id;
                $payment->member_id = $member->id;
                $payment->plan_id = $planId;
                $payment->amount = (float) preg_replace('/[^\d.]/', '', (string) ($row['payment_amount'] ?? '0'));
                $payment->method = Str::contains(Str::lower((string) ($row['payment_method'] ?? '')), 'efectivo') ? 'efectivo' : 'manual';
                $payment->status = 'paid';
                $payment->paid_at = ($start ?? $this->parseDate($row['registered_at'] ?? null) ?? now());
                $payment->save();
                $c['payments_new']++;
            }
        }
    }

    /** Lee el CSV `;` (UTF-8 con o sin BOM) a una lista de mapas header→valor. */
    private function readCsv(string $path): array
    {
        $out = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }
        $header = null;
        while (($cols = fgetcsv($fh, 0, ';')) !== false) {
            if ($cols === [null] || $cols === false) {
                continue;
            }
            if ($header === null) {
                $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cols[0]); // quita BOM
                $header = array_map(fn ($h) => trim((string) $h), $cols);

                continue;
            }
            $row = [];
            foreach ($header as $j => $key) {
                $row[$key] = $cols[$j] ?? '';
            }
            $out[] = $row;
        }
        fclose($fh);

        return $out;
    }

    /** Email de acceso único: usa el real si es válido y libre; si no, placeholder por documento. */
    private function resolveEmail(?string $email, string $doc, ?int $currentUserId): string
    {
        if ($email !== null) {
            $taken = User::query()->where('email', $email)
                ->when($currentUserId, fn ($q) => $q->whereKeyNot($currentUserId))->exists();
            if (! $taken) {
                return $email;
            }
        }

        $placeholder = "socio-{$doc}@ironbody.local";
        $taken = User::query()->where('email', $placeholder)
            ->when($currentUserId, fn ($q) => $q->whereKeyNot($currentUserId))->exists();

        return $taken ? "socio-{$doc}-".Str::lower(Str::random(4)).'@ironbody.local' : $placeholder;
    }

    private function cleanEmail(?string $value): ?string
    {
        $value = trim((string) $value);

        return ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) ? Str::lower($value) : null;
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
