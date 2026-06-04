<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Herramienta SOLO de desarrollo: libera documentos/correos/teléfonos de PRUEBA
 * (en lote) para poder repetir el flujo de creación de cuenta. Generaliza
 * dev:reset-test-member a listas y añade protección explícita de cuentas
 * activas y del correo base.
 *
 * Seguridad:
 *  - Aborta fuera de local/development/testing y si la BD no parece dev.
 *  - Dry-run por defecto: solo borra con --force (y sin --dry-run).
 *  - NUNCA borra el correo base (laravel296@gmail.com) salvo --include-base.
 *  - NO borra cuentas activas salvo --include-active (un teléfono compartido con
 *    una cuenta activa/base NO arrastra su borrado).
 *  - NUNCA toca contract_templates ni storage/app/private/contract_templates/source,
 *    ni planes/ejercicios/config base.
 */
class DevResetAccountTestsCommand extends Command
{
    protected $signature = 'dev:reset-account-tests
        {--emails= : Correos de prueba separados por coma}
        {--documents= : Documentos de prueba separados por coma}
        {--phones= : Teléfonos de prueba separados por coma}
        {--dry-run : Forzar simulación (no borra nada)}
        {--force : Ejecutar el borrado real}
        {--include-active : Permitir borrar cuentas ACTIVAS de prueba (peligroso)}
        {--include-base : Permitir borrar el correo base (requiere intención explícita)}';

    protected $description = '[DEV] Libera en lote documentos/correos/teléfonos de prueba de creación de cuenta.';

    /** Correo base que NUNCA se borra automáticamente. */
    private const BASE_EMAIL = 'laravel296@gmail.com';

    /** Tablas con member_id SIN cascada: se limpian explícitamente. */
    private const ORPHAN_MEMBER_TABLES = [
        'iron_ai_message_attachments',
        'iron_ai_messages',
        'iron_ai_conversations',
        'iron_ai_recommendations',
        'iron_ai_usage_logs',
        'nutrition_day_logs',
        'routine_completions',
        'notifications',
        'automation_events',
        'account_deletion_requests',
    ];

    /** Tablas que SÍ cascadan al borrar el member (solo para reporte). */
    private const CASCADE_MEMBER_TABLES = [
        'member_contracts', 'contract_audit_logs', 'member_legal_consents',
        'member_signatures', 'member_guardians', 'member_identity_documents',
        'member_biometrics', 'member_device_sessions', 'member_device_tokens',
        'member_device_bindings', 'member_security_events', 'member_auth_challenges',
        'member_reenrollment_tokens', 'member_routine_assignments',
        'member_app_activity_days', 'app_notifications', 'class_reservations',
        'physical_evaluations', 'nutrition_goals', 'nutrition_meal_logs',
        'nutrition_food_items', 'nutrition_ai_recommendations',
        'iron_ai_user_events', 'iron_ai_user_profiles', 'trainer_reviews', 'trainer_tasks',
    ];

    public function handle(): int
    {
        // ── 1) Entorno ──────────────────────────────────────────────────────
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->error('ABORTADO: solo corre en local/development/testing.');

            return self::FAILURE;
        }
        $db = (string) Config::get('database.connections.'.config('database.default').'.database');
        if (! preg_match('/dev|local|test/i', $db)) {
            $this->error("ABORTADO: la base '{$db}' no parece de desarrollo (sin dev/local/test).");

            return self::FAILURE;
        }
        $this->info("Entorno seguro confirmado: APP_ENV=".app()->environment().", DB_DATABASE={$db}.");

        // ── 2) Parámetros ───────────────────────────────────────────────────
        $emails = $this->listOption('emails');
        $rawDocs = $this->listOption('documents');
        $documents = array_values(array_filter(array_map(
            fn ($d) => Member::normalizeDocumentNumber($d),
            $rawDocs
        )));
        $phones = $this->listOption('phones');
        $includeActive = (bool) $this->option('include-active');
        $includeBase = (bool) $this->option('include-base');
        $isDryRun = $this->option('dry-run') || ! $this->option('force');

        if (! $emails && ! $documents && ! $phones) {
            $this->error('Indica al menos --emails, --documents o --phones.');

            return self::FAILURE;
        }

        // ── 3) Buscar candidatos ────────────────────────────────────────────
        $candidates = Member::query()
            ->with('user')
            ->where(function ($q) use ($emails, $documents, $phones): void {
                if ($documents) {
                    $q->orWhereIn('document_number', $documents);
                }
                if ($emails) {
                    $q->orWhereIn('email', $emails);
                }
                if ($phones) {
                    $q->orWhereIn('phone', $phones);
                }
            })->get();

        if ($candidates->isEmpty()) {
            $this->info('No se encontraron registros para esos datos. Nada que borrar.');

            return self::SUCCESS;
        }

        // ── 4) Clasificar ───────────────────────────────────────────────────
        $deletable = collect();
        $protectedBase = collect();
        $protectedActive = collect();

        foreach ($candidates as $m) {
            if ($this->isBase($m) && ! $includeBase) {
                $protectedBase->push($m);
            } elseif ($m->status === Member::STATUS_ACTIVE && ! $includeActive) {
                $protectedActive->push($m);
            } else {
                $deletable->push($m);
            }
        }

        $this->reportGroup('Miembros A BORRAR (cuentas de prueba)', $deletable);
        $this->reportGroup('PROTEGIDOS — activos (usa --include-active para forzar)', $protectedActive);
        $this->reportGroup('PROTEGIDOS — correo base (usa --include-base para forzar)', $protectedBase);

        // Reporte de teléfonos compartidos con protegidos.
        $protectedPhones = $protectedActive->merge($protectedBase)
            ->pluck('phone')->filter()->intersect($phones)->unique()->values();
        if ($protectedPhones->isNotEmpty()) {
            $this->warn('Teléfonos compartidos con cuentas protegidas (NO se borran por teléfono): '
                .$protectedPhones->implode(', '));
        }

        if ($deletable->isEmpty()) {
            $this->info('No hay cuentas de prueba borrables (todo quedó protegido).');

            return self::SUCCESS;
        }

        $memberIds = $deletable->pluck('id')->values();
        $this->reportRelated($memberIds);

        if ($isDryRun) {
            $this->warn('DRY-RUN: nada se borró. Ejecuta de nuevo con --force para borrar.');

            return self::SUCCESS;
        }

        // ── 5) Borrado real ─────────────────────────────────────────────────
        $filesDeleted = $this->deleteFiles($deletable);

        $deleted = [];
        $deletedUsers = 0;
        DB::transaction(function () use ($deletable, $memberIds, $emails, $documents, $includeActive, $includeBase, &$deleted, &$deletedUsers): void {
            foreach (self::ORPHAN_MEMBER_TABLES as $t) {
                if (Schema::hasTable($t) && Schema::hasColumn($t, 'member_id')) {
                    $n = DB::table($t)->whereIn('member_id', $memberIds)->delete();
                    if ($n > 0) {
                        $deleted[$t] = $n;
                    }
                }
            }
            // El delete del member cascada member_*/contracts/etc.
            $deleted['members'] = Member::whereIn('id', $memberIds)->delete();

            // Usuarios: solo los vinculados a estos miembros, que coincidan con
            // datos de prueba, no sean el base, no queden con otros miembros y
            // (salvo include-active) no estén activos.
            $userIds = $deletable->pluck('user_id')->filter()->unique();
            foreach ($userIds as $uid) {
                $u = User::find($uid);
                if (! $u) {
                    continue;
                }
                if (strcasecmp((string) $u->email, self::BASE_EMAIL) === 0 && ! $includeBase) {
                    $this->warn("Usuario #{$uid} conservado (correo base).");
                    continue;
                }
                $matchesTest = ($emails && in_array($u->email, $emails, true))
                    || ($documents && in_array($u->document, $documents, true));
                $hasOtherMembers = Member::where('user_id', $uid)->exists();
                if ($matchesTest && ! $hasOtherMembers) {
                    $u->delete();
                    $deletedUsers++;
                } else {
                    $this->warn("Usuario #{$uid} conservado (".
                        (! $matchesTest ? 'no coincide con datos de prueba' : 'aún tiene miembros vinculados').').');
                }
            }
        });

        // ── 6) Reporte ──────────────────────────────────────────────────────
        $this->line('');
        $this->info('Borrado completado:');
        $rows = [];
        foreach ($deleted as $t => $n) {
            $rows[] = [$t, $n];
        }
        $rows[] = ['users', $deletedUsers];
        $rows[] = ['archivos privados (firma/PDF/identidad/biometría)', $filesDeleted];
        $this->table(['tabla / recurso', 'borrados'], $rows);
        $this->info('contract_templates y storage/app/private/contract_templates/source: INTACTOS.');

        return self::SUCCESS;
    }

    /** @return string[] */
    private function listOption(string $name): array
    {
        $raw = (string) ($this->option($name) ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }

    private function isBase(Member $m): bool
    {
        return strcasecmp((string) $m->email, self::BASE_EMAIL) === 0
            || strcasecmp((string) ($m->user?->email ?? ''), self::BASE_EMAIL) === 0;
    }

    private function reportGroup(string $title, $members): void
    {
        $this->line('');
        $this->info("{$title}: ".$members->count());
        if ($members->isEmpty()) {
            return;
        }
        $this->table(
            ['id', 'user_id', 'email', 'document_number', 'phone', 'status'],
            $members->map(fn (Member $m) => [
                $m->id, $m->user_id, $m->email, $m->document_number, $m->phone, $m->status,
            ])->all()
        );
    }

    private function reportRelated($memberIds): void
    {
        $rows = [];
        foreach (array_merge(self::CASCADE_MEMBER_TABLES, self::ORPHAN_MEMBER_TABLES) as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'member_id')) {
                $c = DB::table($t)->whereIn('member_id', $memberIds)->count();
                if ($c > 0) {
                    $rows[] = [$t, $c, in_array($t, self::ORPHAN_MEMBER_TABLES, true) ? 'explícito' : 'cascada'];
                }
            }
        }
        if ($rows) {
            $this->line('');
            $this->info('Filas relacionadas (por member_id) de los borrables:');
            $this->table(['tabla', 'filas', 'borrado'], $rows);
        }
    }

    private function deleteFiles($members): int
    {
        $disk = (string) Config::get('contracts.disk', 'local');
        $count = 0;
        foreach ($members as $m) {
            $m->loadMissing('contracts');
            foreach ($m->contracts as $contract) {
                foreach ([$contract->signature_path, $contract->signed_pdf_path] as $path) {
                    if ($path && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $count++;
                    }
                }
            }
            // Borra identidad/biometría/firma + el directorio privado del miembro.
            // NUNCA toca contract_templates/source.
            $m->deleteStoredFiles();
        }

        return $count;
    }
}
