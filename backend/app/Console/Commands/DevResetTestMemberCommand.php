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
 * Herramienta SOLO de desarrollo: limpia de forma selectiva los datos de un
 * miembro/usuario de PRUEBA (por email/documento/teléfono) para poder repetir el
 * flujo de registro con los mismos datos.
 *
 * Seguridad:
 *  - Aborta fuera de entornos local/development/testing y si la BD no parece dev.
 *  - Exige --force para borrar.
 *  - NUNCA toca contract_templates ni storage/app/private/contract_templates/source.
 *  - Borra hijos → member (cascada) → user (solo si coincide con los datos de
 *    prueba y no quedan otros miembros vinculados).
 *  - No usa migrate:fresh ni borra planes/config.
 */
class DevResetTestMemberCommand extends Command
{
    protected $signature = 'dev:reset-test-member
        {--email= : Correo de prueba}
        {--document= : Documento de prueba}
        {--phone= : Teléfono de prueba (opcional)}
        {--force : Confirmar el borrado real}';

    protected $description = '[DEV] Limpia selectivamente un miembro/usuario de prueba para repetir el registro.';

    /**
     * Tablas con columna member_id SIN borrado en cascada (huérfanas): se
     * limpian explícitamente, en orden hijo→padre donde aplica.
     */
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
    ];

    /** Tablas que SÍ se borran en cascada al eliminar el member (solo para reporte). */
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
            $this->error('ABORTADO: este comando solo corre en local/development/testing.');

            return self::FAILURE;
        }

        $db = (string) Config::get('database.connections.'.config('database.default').'.database');
        if (! preg_match('/dev|local|test/i', $db)) {
            $this->error("ABORTADO: la base de datos '{$db}' no parece de desarrollo (sin dev/local/test).");

            return self::FAILURE;
        }
        $this->info("Entorno confirmado local/dev (env=".app()->environment().", db={$db}).");

        $email = trim((string) $this->option('email')) ?: null;
        $document = Member::normalizeDocumentNumber($this->option('document'));
        $phone = trim((string) $this->option('phone')) ?: null;

        if (! $email && ! $document) {
            $this->error('Indica al menos --email o --document.');

            return self::FAILURE;
        }

        // ── 2) Buscar registros ─────────────────────────────────────────────
        $members = Member::query()
            ->where(function ($q) use ($email, $document, $phone): void {
                if ($document) {
                    $q->orWhere('document_number', $document);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })->get();

        $users = User::query()
            ->where(function ($q) use ($email, $document): void {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($document) {
                    $q->orWhere('document', $document);
                }
            })->get();

        $userIds = $members->pluck('user_id')->filter()
            ->merge($users->pluck('id'))->unique()->values();
        $memberIds = $members->pluck('id')->values();

        if ($members->isEmpty() && $users->isEmpty()) {
            $this->info('No se encontraron registros de prueba para esos datos. Nada que borrar.');

            return self::SUCCESS;
        }

        // ── 3) Resumen ──────────────────────────────────────────────────────
        $this->line('');
        $this->info('Miembros encontrados:');
        $this->table(['id', 'user_id', 'email', 'document_number', 'status'],
            $members->map(fn (Member $m) => [
                $m->id, $m->user_id, $m->email, $m->document_number, $m->status,
            ])->all());

        $this->info('Usuarios encontrados:');
        $this->table(['id', 'name', 'email', 'document'],
            $users->merge(User::whereIn('id', $userIds)->get())->unique('id')
                ->map(fn (User $u) => [$u->id, $u->name, $u->email, $u->document])->all());

        if ($memberIds->isNotEmpty()) {
            $this->info('Filas relacionadas (por member_id):');
            $rows = [];
            foreach (array_merge(self::CASCADE_MEMBER_TABLES, self::ORPHAN_MEMBER_TABLES) as $t) {
                if (Schema::hasTable($t) && Schema::hasColumn($t, 'member_id')) {
                    $c = DB::table($t)->whereIn('member_id', $memberIds)->count();
                    if ($c > 0) {
                        $rows[] = [$t, $c, in_array($t, self::ORPHAN_MEMBER_TABLES, true) ? 'explícito' : 'cascada'];
                    }
                }
            }
            $this->table(['tabla', 'filas', 'borrado'], $rows);
        }

        if (! $this->option('force')) {
            $this->warn('Modo simulación. Ejecuta de nuevo con --force para borrar realmente.');

            return self::FAILURE;
        }

        // ── 4) Borrado de archivos privados (firma/PDF), NUNCA plantillas ────
        $disk = (string) Config::get('contracts.disk', 'local');
        $filesDeleted = 0;
        foreach ($members as $m) {
            $m->loadMissing('contracts');
            foreach ($m->contracts as $contract) {
                foreach ([$contract->signature_path, $contract->signed_pdf_path] as $path) {
                    if ($path && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $filesDeleted++;
                    }
                }
            }
            // Borra identidad/biometría/firma + el directorio privado del miembro
            // (members/{uuid}/...). No toca contract_templates/source.
            $m->deleteStoredFiles();
        }

        // ── 5) Borrado en transacción ───────────────────────────────────────
        $deleted = [];
        $deletedUsers = 0;
        DB::transaction(function () use ($memberIds, $userIds, $email, $document, &$deleted, &$deletedUsers): void {
            if ($memberIds->isNotEmpty()) {
                foreach (self::ORPHAN_MEMBER_TABLES as $t) {
                    if (Schema::hasTable($t) && Schema::hasColumn($t, 'member_id')) {
                        $n = DB::table($t)->whereIn('member_id', $memberIds)->delete();
                        if ($n > 0) {
                            $deleted[$t] = $n;
                        }
                    }
                }
                // El delete del member cascada el resto (member_*, contracts, etc.).
                $deleted['members'] = Member::whereIn('id', $memberIds)->delete();
            }

            // Usuario: solo si coincide con los datos de prueba y no quedan
            // otros miembros vinculados (no borrar usuarios reales ajenos).
            foreach ($userIds as $uid) {
                $u = User::find($uid);
                if (! $u) {
                    continue;
                }
                $matchesTest = ($email && $u->email === $email) || ($document && $u->document === $document);
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
        $report = [];
        foreach ($deleted as $t => $n) {
            $report[] = [$t, $n];
        }
        $report[] = ['users', $deletedUsers];
        $report[] = ['archivos privados (firma/PDF)', $filesDeleted];
        $this->table(['tabla / recurso', 'borrados'], $report);
        $this->info('contract_templates y storage/app/private/contract_templates/source: INTACTOS.');

        return self::SUCCESS;
    }
}
