<?php

namespace Tests\Feature\Outreach\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Filas válidas del esquema del Hito 9 (paquete DB9) y las aserciones de las
 * pruebas de esquema, en los dos motores. Todo se escribe con SQL a pelo (el
 * constructor de consultas), nunca con los modelos: lo que se prueba es la
 * base. Ninguna fila lleva un teléfono, un nombre de socio ni un documento.
 *
 * Personas: Ana (cuenta 1) y Beto (cuenta 2), vinculadas por consola (el
 * arranque, 11.2); Caro (cuenta 3), por el CRM (la crea Ana, la verifica Beto).
 * Por defecto Ana es la autora y Beto aprueba y publica.
 *
 * `$fixtureConnection` deja usar las mismas filas desde otra conexión (las
 * carreras de PostgreSQL, que necesitan datos confirmados).
 */
trait OutreachSchemaFixtures
{
    private int $n = 0;

    private ?string $fixtureConnection = null;

    /** @var array<int, int> cuenta → identidad */
    private array $people = [];

    /** @var array<string, Migration> */
    private array $migrations = [];

    private const GOVERNANCE_MIGRATION = '2026_11_12_100001_create_ultron_studio_strategy_tables';

    private const EXECUTION_MIGRATION = '2026_11_12_100002_create_outreach_tables';

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const NAMES = [1 => 'Ana', 2 => 'Beto', 3 => 'Caro', 4 => 'Dani', 5 => 'Eva'];

    private const T_BINDINGS = 'ultron_studio_person_bindings';

    private const T_STRATEGIES = 'ultron_studio_strategies';

    private const T_TEMPLATES = 'ultron_studio_message_templates';

    private const T_VERSIONS = 'ultron_studio_strategy_versions';

    private const T_SIMULATIONS = 'ultron_studio_strategy_simulations';

    private const T_REVIEWS = 'ultron_studio_strategy_reviews';

    private const T_PUBLICATIONS = 'ultron_studio_strategy_publications';

    private const T_RUNS = 'outreach_runs';

    private const T_CURSORS = 'outreach_cursors';

    private const T_ENROLLMENTS = 'outreach_enrollments';

    private const T_JOBS = 'outreach_jobs';

    private const T_DECISIONS = 'outreach_decisions';

    private const T_OUTCOMES = 'outreach_outcomes';

    private const T_BRAKES = 'outreach_brakes';

    private const T_BUDGETS = 'outreach_budget_counters';

    private const GOVERNANCE_TABLES = [self::T_BINDINGS, self::T_STRATEGIES, self::T_TEMPLATES, self::T_VERSIONS, self::T_SIMULATIONS, self::T_REVIEWS, self::T_PUBLICATIONS];

    private const EXECUTION_TABLES = [self::T_RUNS, self::T_CURSORS, self::T_ENROLLMENTS, self::T_JOBS, self::T_DECISIONS, self::T_OUTCOMES, self::T_BRAKES, self::T_BUDGETS];

    private function db(): ConnectionInterface
    {
        return DB::connection($this->fixtureConnection);
    }

    private function migrationNamed(string $name): Migration
    {
        return $this->migrations[$name] ??= require database_path('migrations/'.$name.'.php');
    }

    private function hex(string $etiqueta): string
    {
        return hash('sha256', 'h9-db-'.$etiqueta);
    }

    // ── Personas y vínculos ─────────────────────────────────────────────────

    private function insertIdentity(): int
    {
        return (int) $this->db()->table('identities')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'document_normalized' => null,
            'phone_normalized' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Un vínculo `crm` sin verificar de la cuenta `$admin`, creado por Ana. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function bindingRow(int $admin, int $identity, array $o = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'origin' => 'crm',
            'admin_id' => $admin,
            'identity_id' => $identity,
            'bootstrap_authorization' => null,
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** El arranque por consola: verificado al nacer, sin quién lo creó ni lo verificó. @return array<string, mixed> */
    private function bootstrapOverrides(): array
    {
        return [
            'origin' => 'bootstrap',
            'created_by_admin_id' => null,
            'created_by_name' => 'consola',
            'created_by_role' => null,
            'bootstrap_authorization' => 'Orden escrita del propietario del 12-11-2026 (prueba)',
            'verified_at' => now(),
        ];
    }

    /** @param  array<string, mixed>  $o */
    private function insertBinding(int $admin, int $identity, array $o = []): int
    {
        return (int) $this->db()->table(self::T_BINDINGS)->insertGetId($this->bindingRow($admin, $identity, $o));
    }

    private function verifyBinding(int $id, int $byAdmin, int $byIdentity): int
    {
        return $this->db()->table(self::T_BINDINGS)->where('id', $id)->update([
            'verified_by_admin_id' => $byAdmin,
            'verified_by_name' => self::NAMES[$byAdmin] ?? "Cuenta {$byAdmin}",
            'verified_by_identity_id' => $byIdentity,
            'verified_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Ana y Beto (consola) y Caro (CRM, verificada por Beto). @return array<int, int> cuenta → identidad */
    private function people(): array
    {
        // La caché vale solo si sus filas siguen ahí: creadas dentro de una
        // sentencia que tenía que fallar, el SAVEPOINT las deshizo.
        if ($this->people !== [] && $this->db()->table(self::T_BINDINGS)->where('admin_id', 1)->where('identity_id', $this->people[1])->exists()) {
            return $this->people;
        }
        $this->people = [];
        $ana = $this->insertIdentity();
        $beto = $this->insertIdentity();
        $caro = $this->insertIdentity();
        $this->insertBinding(1, $ana, $this->bootstrapOverrides());
        $this->insertBinding(2, $beto, $this->bootstrapOverrides());
        $this->verifyBinding($this->insertBinding(3, $caro), 2, $beto);

        return $this->people = [1 => $ana, 2 => $beto, 3 => $caro];
    }

    // ── Estrategias, versiones, simulaciones y revisiones ───────────────────

    /** @param  array<string, mixed>  $o */
    private function insertStrategy(array $o = []): int
    {
        $n = ++$this->n;

        return (int) $this->db()->table(self::T_STRATEGIES)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'key' => "estrategia-{$n}",
            'name' => "Renovación a 7 días {$n}",
            'purpose' => 'commercial',
            'draft_json' => '{"goal":"renew"}',
            'draft_revision' => 1,
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    /** Una versión VALIDATED de Ana, con la línea base como referencia comercial (P9-10). @param  array<string, mixed>  $o @return array<string, mixed> */
    private function versionRow(int $strategyId, array $o = []): array
    {
        $people = $this->people();
        $n = ++$this->n;
        $json = (string) ($o['content_json'] ?? json_encode(['goal' => 'renew', 'n' => $n, 'purpose' => 'commercial']));
        $number = $o['number'] ?? ((int) $this->db()->table(self::T_VERSIONS)->where('strategy_id', $strategyId)->max('number')) + 1;

        $row = array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'strategy_id' => $strategyId,
            'number' => $number,
            'content_json' => $json,
            'content_hash' => hash('sha256', $json),
            'catalog_version' => 'outreach-catalog/2026-11-10.1',
            'evaluator_version' => 'outreach-evaluator/1',
            'studio_ref_kind' => 'baseline',
            'studio_version_uuid' => null,
            'studio_content_hash' => $this->hex('legacy-baseline-v1'),
            'status' => 'VALIDATED',
            'validation_json' => '{"blocking":[],"warnings":[]}',
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_by_person_id' => $people[1],
            'created_at' => now(),
            'status_changed_at' => now(),
            'updated_at' => now(),
        ], $o);
        if (array_key_exists('content_json', $o) && ! array_key_exists('content_hash', $o)) {
            $row['content_hash'] = hash('sha256', $json);
        }

        return $row;
    }

    /** @param  array<string, mixed>  $o */
    private function insertVersion(int $strategyId, array $o = []): int
    {
        return (int) $this->db()->table(self::T_VERSIONS)->insertGetId($this->versionRow($strategyId, $o));
    }

    /** VALIDATED → APPROVED por `$admin` (Beto por defecto), con la simulación que cite. @param  array<string, mixed>  $extra */
    private function approveVersion(int $id, int $admin = 2, array $extra = []): int
    {
        $people = $this->people();

        return $this->db()->table(self::T_VERSIONS)->where('id', $id)->update(array_merge([
            'status' => 'APPROVED',
            'approved_by_admin_id' => $admin,
            'approved_by_name' => self::NAMES[$admin] ?? "Cuenta {$admin}",
            'approved_by_role' => 'Super Admin',
            'approved_by_person_id' => $people[$admin] ?? $admin,
            'approved_at' => now(),
            'status_changed_at' => now(),
        ], $extra));
    }

    private function setVersionStatus(int $id, string $status): int
    {
        return $this->db()->table(self::T_VERSIONS)->where('id', $id)->update(['status' => $status, 'status_changed_at' => now()]);
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function simulationRow(int $strategyId, ?int $versionId, array $o = []): array
    {
        $n = ++$this->n;
        $hash = $versionId === null ? $this->hex("borrador-{$n}") : (string) $this->db()->table(self::T_VERSIONS)->where('id', $versionId)->value('content_hash');

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'strategy_id' => $strategyId,
            'version_id' => $versionId,
            'content_hash' => $hash,
            'kind' => 'synthetic',
            'input_hash' => $this->hex("entrada-{$n}"),
            'sample_size' => 10,
            'result_json' => '{"counts":{"enroll":3,"deny":7},"findings":[]}',
            'result_hash' => $this->hex("resultado-{$n}"),
            'blocking_findings' => 0,
            'catalog_version' => 'outreach-catalog/2026-11-10.1',
            'evaluator_version' => 'outreach-evaluator/1',
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_by_person_id' => $this->people()[1],
            'created_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertSimulation(int $strategyId, ?int $versionId, array $o = []): int
    {
        return (int) $this->db()->table(self::T_SIMULATIONS)->insertGetId($this->simulationRow($strategyId, $versionId, $o));
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function reviewRow(int $versionId, int $admin = 2, array $o = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'version_id' => $versionId,
            'decision' => 'approved',
            'content_hash' => (string) $this->db()->table(self::T_VERSIONS)->where('id', $versionId)->value('content_hash'),
            'acknowledged_warnings' => '[]',
            'note' => null,
            'reviewer_admin_id' => $admin,
            'reviewer_name' => self::NAMES[$admin] ?? "Cuenta {$admin}",
            'reviewer_role' => 'Super Admin',
            'reviewer_person_id' => $this->people()[$admin] ?? $admin,
            'created_at' => now(),
        ], $o);
    }

    // ── Plantillas ──────────────────────────────────────────────────────────

    /**
     * Una plantilla DRAFT de Ana. `content` (no es columna) sustituye el
     * contenido por defecto del canal.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function templateRow(array $o = []): array
    {
        $people = $this->people();
        $n = ++$this->n;
        $channel = (string) ($o['channel'] ?? 'whatsapp');
        $purpose = (string) ($o['purpose'] ?? 'commercial');
        $content = $o['content'] ?? match ($channel) {
            'whatsapp' => [
                'bodyPreview' => 'Hola {{1}}, tu plan vence pronto. Responde BAJA si no quieres recibir más mensajes.',
                'category' => $purpose === 'commercial' ? 'marketing' : 'utility',
                'language' => 'es',
                'metaName' => "plantilla_{$n}",
                'optOutLine' => true,
                'variables' => ['1' => 'first_name'],
            ],
            'assisted' => ['medium' => 'whatsapp', 'script' => 'Saluda, recuerda el vencimiento del plan y ofrece renovarlo.'],
            default => ['purposeKey' => 'renewal'],
        };
        $json = (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $key = (string) ($o['key'] ?? "plantilla-{$n}");
        unset($o['content']);

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'key' => $key,
            'number' => ((int) $this->db()->table(self::T_TEMPLATES)->where('key', $key)->max('number')) + 1,
            'channel' => $channel,
            'purpose' => $purpose,
            'category' => $channel === 'whatsapp' && is_array($content) ? ($content['category'] ?? null) : null,
            'content_json' => $json,
            'content_hash' => hash('sha256', $json),
            'status' => 'DRAFT',
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_by_person_id' => $people[1],
            'created_at' => now(),
            'status_changed_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertTemplate(array $o = []): int
    {
        return (int) $this->db()->table(self::T_TEMPLATES)->insertGetId($this->templateRow($o));
    }

    private function reviewTemplate(int $id, string $status = 'APPROVED', int $admin = 2, ?string $note = null): int
    {
        return $this->db()->table(self::T_TEMPLATES)->where('id', $id)->update([
            'status' => $status,
            'reviewed_by_admin_id' => $admin,
            'reviewed_by_name' => self::NAMES[$admin] ?? "Cuenta {$admin}",
            'reviewed_by_role' => 'Super Admin',
            'reviewed_by_person_id' => $this->people()[$admin] ?? $admin,
            'reviewed_at' => now(),
            'review_note' => $note ?? ($status === 'REJECTED' ? 'Falta la línea de baja' : null),
            'status_changed_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $o */
    private function approvedTemplate(array $o = []): int
    {
        $id = $this->insertTemplate($o);
        $this->reviewTemplate($id);

        return $id;
    }

    // ── La cadena ───────────────────────────────────────────────────────────

    private function headId(): ?int
    {
        $id = $this->db()->table(self::T_PUBLICATIONS)->orderByDesc('seq')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function nextSeq(): int
    {
        return ((int) $this->db()->table(self::T_PUBLICATIONS)->max('seq')) + 1;
    }

    /** Una estrategia con su versión aprobada para las entradas de la cadena que no dicen cuál. @return array{0: int, 1: int} */
    private function chainTarget(): array
    {
        $s = $this->insertStrategy();
        $v = $this->insertVersion($s);
        $this->approveVersion($v);

        return [$s, $v];
    }

    /**
     * Una entrada de la cadena (un `publish` de Beto por defecto): el mensaje
     * es un JSON con su seq y su `prevHash`, `prev_hash` la huella de
     * `$previous` (64 ceros sin ella) y `effective_hash` el sha256 de los
     * bytes. La firma es de relleno: aquí se prueba la base, no la firma.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function publicationRow(int $seq, ?int $previous, array $o = []): array
    {
        $people = $this->people();
        if (! array_key_exists('strategy_id', $o)) {
            [$o['strategy_id'], $version] = $this->chainTarget();
            $o += ['version_id' => $version];
        }
        $prev = $o['prev_hash'] ?? ($previous === null ? self::GENESIS : (string) ($this->db()->table(self::T_PUBLICATIONS)->where('id', $previous)->value('effective_hash') ?? self::GENESIS));
        $accion = (string) ($o['action'] ?? 'publish');
        $json = (string) json_encode(['action' => $accion, 'contract' => 'ultron-strategy-config/1', 'n' => ++$this->n, 'prevHash' => $prev, 'seq' => $seq], JSON_UNESCAPED_SLASHES);

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => 'publish',
            'version_id' => null,
            'previous_publication_id' => $previous,
            'prev_hash' => $prev,
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => str_repeat('c', 64),
            'simulation_gate' => in_array($accion, ['publish', 'promote'], true) ? 'required' : null,
            'evidence' => $accion === 'promote' ? '{"contacts":3,"hours":24,"incidents":0}' : null,
            'reason' => in_array($accion, ['pause', 'rollback'], true) ? 'Pausa por la prueba' : null,
            'actor_admin_id' => 2,
            'actor_name' => 'Beto',
            'actor_role' => 'Super Admin',
            'acted_by_person_id' => $people[2],
            'created_at' => now(),
        ], array_diff_key($o, ['prev_hash' => true]), ['prev_hash' => $prev]);
    }

    /** @param  array<string, mixed>  $o */
    private function insertPublication(int $seq, ?int $previous, array $o = []): int
    {
        return (int) $this->db()->table(self::T_PUBLICATIONS)->insertGetId($this->publicationRow($seq, $previous, $o));
    }

    /** La siguiente a la cabeza. @param  array<string, mixed>  $o */
    private function appendPublication(array $o = []): int
    {
        return $this->insertPublication($this->nextSeq(), $this->headId(), $o);
    }

    /**
     * Una estrategia con su versión 1 aprobada por Beto y publicada en canario
     * (APPROVED → CANARY con su entrada de la cadena), lista para inscribir.
     *
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $version
     * @return array{strategy: int, version: int, seq: int, purpose: string, v: object}
     */
    private function liveVersion(array $strategy = [], array $version = []): array
    {
        $s = $this->insertStrategy($strategy);
        $v = $this->insertVersion($s, $version);
        $this->approveVersion($v);
        $seq = $this->nextSeq();
        $this->insertPublication($seq, $this->headId(), ['strategy_id' => $s, 'version_id' => $v]);
        $this->setVersionStatus($v, 'CANARY');

        return [
            'strategy' => $s,
            'version' => $v,
            'seq' => $seq,
            'purpose' => (string) $this->db()->table(self::T_STRATEGIES)->where('id', $s)->value('purpose'),
            'v' => $this->db()->table(self::T_VERSIONS)->where('id', $v)->first(),
        ];
    }

    // ── La ejecución ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function runRow(array $o = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'kind' => 'sweep',
            'mode' => 'shadow',
            'strategy_id' => null,
            'trigger_key' => 'membership.expiring_window',
            'status' => 'running',
            'started_at' => '2026-11-12 15:00:00',
            'finished_at' => null,
            'counts' => null,
            'error_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertRun(array $o = []): int
    {
        return (int) $this->db()->table(self::T_RUNS)->insertGetId($this->runRow($o));
    }

    /** El sello de una versión viva (§5). @param  array{strategy: int, version: int, seq: int, purpose: string, v: object}  $live @return array<string, mixed> */
    private function sealOf(array $live, string $mode = 'shadow'): array
    {
        return [
            'strategy_id' => $live['strategy'],
            'strategy_version_id' => $live['version'],
            'content_hash' => $live['v']->content_hash,
            'publication_seq' => $live['seq'],
            'part' => 'canary',
            'studio_ref_kind' => $live['v']->studio_ref_kind,
            'studio_version_uuid' => $live['v']->studio_version_uuid,
            'studio_content_hash' => $live['v']->studio_content_hash,
            'catalog_version' => $live['v']->catalog_version,
            'evaluator_version' => $live['v']->evaluator_version,
            'mode' => $mode,
        ];
    }

    /**
     * Una inscripción `active` de un socio nuevo (`m:<n>`) en sombra.
     *
     * @param  array{strategy: int, version: int, seq: int, purpose: string, v: object}  $live
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function enrollmentRow(array $live, array $o = []): array
    {
        $n = ++$this->n + 1000;

        return array_merge($this->sealOf($live, (string) ($o['mode'] ?? 'shadow')), [
            'uuid' => (string) Str::uuid(),
            'subject_key' => "m:{$n}",
            'subject_ref' => $this->hex("persona-{$n}"),
            'member_id' => $n,
            'lead_id' => null,
            'cycle_key' => 'exp:2026-11-20',
            'purpose' => $live['purpose'],
            'status' => 'active',
            'step_index' => 0,
            'touches' => 0,
            'next_due_at' => null,
            'enrolled_at' => '2026-11-12 15:00:00',
            'window_ends_at' => '2026-12-12 15:00:00',
            'last_outcome' => null,
            'exit_reason' => null,
            'ended_at' => null,
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array{strategy: int, version: int, seq: int, purpose: string, v: object}  $live @param  array<string, mixed>  $o */
    private function insertEnrollment(array $live, array $o = []): int
    {
        return (int) $this->db()->table(self::T_ENROLLMENTS)->insertGetId($this->enrollmentRow($live, $o));
    }

    /** El sello y la persona de una inscripción, para sus trabajos y decisiones. @return array<string, mixed> */
    private function fromEnrollment(int $enrollmentId): array
    {
        $e = $this->db()->table(self::T_ENROLLMENTS)->where('id', $enrollmentId)->first();
        $out = ['enrollment_id' => (int) $e->id, 'subject_key' => $e->subject_key, 'subject_ref' => $e->subject_ref];
        foreach (['strategy_id', 'strategy_version_id', 'content_hash', 'publication_seq', 'part', 'studio_ref_kind', 'studio_version_uuid',
            'studio_content_hash', 'catalog_version', 'evaluator_version', 'mode'] as $c) {
            $out[$c] = $e->{$c};
        }

        return $out;
    }

    /** Un trabajo `planned` del canal simulado (sin reserva) de la inscripción. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function jobRow(int $enrollmentId, array $o = []): array
    {
        $n = ++$this->n;

        return array_merge($this->fromEnrollment($enrollmentId), [
            'uuid' => (string) Str::uuid(),
            'step_index' => 0,
            'attempt' => 1,
            'channel' => 'simulated',
            'medium' => null,
            'template_version_id' => null,
            'template_hash' => null,
            'idempotency_key' => $this->hex("trabajo-{$n}"),
            'status' => 'planned',
            'not_before' => '2026-11-13 14:00:00',
            'expires_at' => '2026-11-13 19:00:00',
            'budget_day' => '2026-11-13',
            'reserved_units' => 0,
            'reserved_micros' => 0,
            'budget_released_at' => null,
            'cancel_reason' => null,
            'claimed_at' => null,
            'marketing_message_id' => null,
            'voice_call_id' => null,
            'assisted_claimed_by_admin_id' => null,
            'assisted_claimed_by_name' => null,
            'assisted_claimed_at' => null,
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertJob(int $enrollmentId, array $o = []): int
    {
        return (int) $this->db()->table(self::T_JOBS)->insertGetId($this->jobRow($enrollmentId, $o));
    }

    /** Un trabajo asistido en `live` con su plantilla aprobada y una unidad reservada. @param  array<string, mixed>  $o */
    private function insertAssistedJob(int $enrollmentId, array $o = []): int
    {
        $t = $this->approvedTemplate(['channel' => 'assisted']);

        return $this->insertJob($enrollmentId, array_merge([
            'channel' => 'assisted',
            'medium' => 'whatsapp',
            'template_version_id' => $t,
            'template_hash' => (string) $this->db()->table(self::T_TEMPLATES)->where('id', $t)->value('content_hash'),
            'reserved_units' => 1,
        ], $o));
    }

    /** planned → claimed → dispatching → `$final` (con lo que pide cada paso). @param  array<string, mixed>  $extra */
    private function walkJob(int $id, string $final, array $extra = []): void
    {
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => 'claimed', 'claimed_at' => now(), 'status_changed_at' => now()]);
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(['status' => 'dispatching', 'status_changed_at' => now()]);
        $this->db()->table(self::T_JOBS)->where('id', $id)->update(array_merge(['status' => $final, 'status_changed_at' => now()], $extra));
    }

    /** Una decisión `enroll` de la inscripción. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function decisionRow(int $enrollmentId, array $o = []): array
    {
        $n = ++$this->n;

        return array_merge($this->fromEnrollment($enrollmentId), [
            'uuid' => (string) Str::uuid(),
            'run_id' => null,
            'job_id' => null,
            'kind' => 'enroll',
            'level' => null,
            'reasons' => '[]',
            'retry_at' => null,
            'facts_json' => '{"membership.days_to_expiry":7}',
            'facts_hash' => $this->hex("hechos-{$n}"),
            'trace_json' => '[{"path":"0","kind":"fact","result":"true"}]',
            'trace_hash' => $this->hex("traza-{$n}"),
            'template_version_id' => null,
            'template_hash' => null,
            'evaluated_at' => '2026-11-12 15:00:00',
            'created_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertDecision(int $enrollmentId, array $o = []): int
    {
        return (int) $this->db()->table(self::T_DECISIONS)->insertGetId($this->decisionRow($enrollmentId, $o));
    }

    /**
     * Un resultado sin trabajo: la ventana vencida (`system` · `no_response` →
     * `end_exit`), con la estrategia y la persona de su inscripción (L9-3).
     * `job` (no es columna) lo cuelga de un trabajo, con su canal.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function outcomeRow(int $enrollmentId, array $o = []): array
    {
        $n = ++$this->n;
        $e = $this->db()->table(self::T_ENROLLMENTS)->where('id', $enrollmentId)->first();
        if (isset($o['job'])) {
            $o += ['job_id' => (int) $o['job'], 'channel' => (string) $this->db()->table(self::T_JOBS)->where('id', $o['job'])->value('channel')];
        }
        unset($o['job']);

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'job_id' => null,
            'enrollment_id' => $enrollmentId,
            'strategy_id' => (int) $e->strategy_id,
            'subject_key' => $e->subject_key,
            'subject_ref' => $e->subject_ref,
            'channel' => null,
            'outcome' => 'no_response',
            'source' => 'system',
            'source_key' => "ventana:{$n}",
            'deterministic' => true,
            'confidence' => null,
            'reason_code' => null,
            'detail_code' => null,
            'next_action' => 'end_exit',
            'next_action_at' => null,
            'recorded_by_admin_id' => null,
            'recorded_by_name' => null,
            'occurred_at' => '2026-11-13 14:00:00',
            'created_at' => now(),
        ], $o);
    }

    /** El resultado del canal simulado de un trabajo (en sombra: `not_attempted`, sin transición). @param  array<string, mixed>  $o @return array<string, mixed> */
    private function simulatedOutcomeRow(int $enrollmentId, int $jobId, array $o = []): array
    {
        return $this->outcomeRow($enrollmentId, $o + ['job' => $jobId, 'source' => 'simulated', 'outcome' => 'not_attempted', 'next_action' => null,
            'source_key' => 'trabajo:'.$jobId]);
    }

    /** @param  array<string, mixed>  $o */
    private function insertOutcome(int $enrollmentId, array $o = []): int
    {
        return (int) $this->db()->table(self::T_OUTCOMES)->insertGetId($this->outcomeRow($enrollmentId, $o));
    }

    /** La PARADA GLOBAL que abre Ana. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function brakeRow(array $o = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'scope' => 'global',
            'scope_ref' => null,
            'reason' => 'manual',
            'note' => 'Parada global de la prueba',
            'opened_by_type' => 'person',
            'opened_by_admin_id' => 1,
            'opened_by_name' => 'Ana',
            'opened_by_person_id' => $this->people()[1],
            'opened_at' => '2026-11-12 15:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertBrake(array $o = []): int
    {
        return (int) $this->db()->table(self::T_BRAKES)->insertGetId($this->brakeRow($o));
    }

    private function releaseBrake(int $id, int $admin = 2): int
    {
        return $this->db()->table(self::T_BRAKES)->where('id', $id)->update([
            'released_at' => '2026-11-12 16:00:00',
            'released_by_admin_id' => $admin,
            'released_by_name' => self::NAMES[$admin] ?? "Cuenta {$admin}",
            'released_by_person_id' => $this->people()[$admin] ?? null,
            'release_note' => 'Revisado: se suelta',
            'updated_at' => now(),
        ]);
    }

    /** El contador del asistido en live para el 13-11, con techo de 5 unidades. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function budgetRow(array $o = []): array
    {
        return array_merge([
            'budget_day' => '2026-11-13',
            'scope' => 'channel:assisted',
            'mode' => 'live',
            'cap_units' => 5,
            'cap_micros' => 0,
            'reserved_units' => 0,
            'reserved_micros' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertBudget(array $o = []): int
    {
        return (int) $this->db()->table(self::T_BUDGETS)->insertGetId($this->budgetRow($o));
    }

    /** La reserva atómica (D9-8, etapa 5): la comprobación va en el mismo UPDATE. Devuelve las filas afectadas (1 = reservado; 0 = agotado). */
    private function reserve(int $counterId, int $units, int $micros = 0): int
    {
        return $this->db()->update(
            'UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units + ?, reserved_micros = reserved_micros + ? '
            .'WHERE id = ? AND reserved_units + ? <= cap_units AND reserved_micros + ? <= cap_micros',
            [$units, $micros, $counterId, $units, $micros],
        );
    }

    // ── Aserciones ──────────────────────────────────────────────────────────

    private function assertGuarded(Closure $sentencia, string $mensaje, string $que = ''): void
    {
        $this->assertRejected($sentencia, $this->isPostgres() ? 'P0001' : '23000', $mensaje, $que);
    }

    /** @param  list<string>  $columnas */
    private function assertUniqueRejects(Closure $sentencia, string $indice, string $tabla, array $columnas, string $que = ''): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice, $que)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed: '.implode(', ', array_map(static fn (string $c): string => "{$tabla}.{$c}", $columnas)), $que);
    }

    /** Un único parcial con expresión: SQLite lo nombra por el índice. */
    private function assertPartialRejects(Closure $sentencia, string $indice, string $que = ''): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice, $que)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed', $que);
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que = ''): void
    {
        try {
            $this->db()->transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}) {$que}");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    private function assertAccepted(Closure $sentencia, string $que = ''): void
    {
        try {
            $this->db()->transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->fail("La base rechazó lo que tenía que aceptar {$que}: ".$e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Solo PG: qué CHECK de `$table` rechazan `$row`, evaluando la expresión
     * real de cada uno sobre una copia de las columnas sin restricciones (PG
     * solo nombra el primero que falla).
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function violatedChecks(string $table, array $row): array
    {
        DB::statement("CREATE TEMP TABLE h9_probe (LIKE {$table})");
        try {
            DB::table('h9_probe')->insert((Schema::hasColumn($table, 'id') ? ['id' => 999999] : []) + $row);
            $out = [];
            foreach (DB::select("SELECT conname, pg_get_expr(conbin, conrelid) AS expr FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]) as $c) {
                if (DB::selectOne("SELECT ({$c->expr}) AS ok FROM h9_probe")->ok === false) {
                    $out[] = $c->conname;
                }
            }

            return $out;
        } finally {
            DB::statement('DROP TABLE pg_temp.h9_probe');
        }
    }

    /**
     * Solo PG: `$fila` (que viola `$esperados`) se rechaza con 23514 y el
     * primero de ellos, con los disparadores apagados para ver el CHECK.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $cambio
     * @param  list<string>  $esperados
     */
    private function assertCheckShape(string $tabla, array $base, array $cambio, array $esperados): void
    {
        $this->assertSame([], $this->violatedChecks($tabla, $base), "{$tabla}: la fila de partida es válida");
        $fila = array_merge($base, $cambio);
        $this->assertSame($esperados, $this->violatedChecks($tabla, $fila));

        DB::beginTransaction();
        try {
            DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
            $this->assertRejected(fn () => DB::table($tabla)->insert($fila), '23514', $esperados[0]);
        } finally {
            DB::rollBack();
        }
    }

    // ── Catálogo ────────────────────────────────────────────────────────────

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue);
        }
    }

    /** @return list<string> */
    private function triggersOn(string $table): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY 1', [$table]));
        }

        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<string> */
    private function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    /** @param  list<string>  $tables */
    private function countTriggers(array $tables): int
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', $tables)->count();
        }

        return (int) DB::scalar('SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ANY(?::text[])',
            ['{'.implode(',', $tables).'}']);
    }

    /** @param  list<string>  $tables */
    private function countChecks(array $tables): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = ANY(?::text[])",
            ['{'.implode(',', $tables).'}']);
    }

    private function countLike(string $que, string $patron): int
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', $patron)->count();
        }

        return $que === 'trigger'
            ? (int) DB::scalar('SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE ?', [$patron])
            : (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE ?", [$patron]);
    }

    /** @param  list<string>  $tables @return array<string, string> índice parcial → predicado */
    private function partialPredicates(array $tables): array
    {
        $out = [];
        if ($this->isPostgres()) {
            foreach (DB::select('SELECT c.relname AS nombre, pg_get_expr(i.indpred, i.indrelid) AS predicado, i.indisunique::int AS unico FROM pg_index i
                JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname = ANY(?::text[]) AND i.indpred IS NOT NULL ORDER BY 1',
                ['{'.implode(',', $tables).'}']) as $f) {
                $this->assertSame(1, (int) $f->unico, $f->nombre);
                $out[$f->nombre] = $f->predicado;
            }

            return $out;
        }
        foreach (DB::table('sqlite_master')->where('type', 'index')->whereIn('tbl_name', $tables)->orderBy('name')->get(['name', 'sql']) as $f) {
            if (is_string($f->sql) && str_contains($f->sql, ' WHERE ')) {
                $this->assertStringStartsWith('CREATE UNIQUE INDEX', $f->sql);
                $out[$f->name] = substr($f->sql, strpos($f->sql, ' WHERE ') + 7);
            }
        }

        return $out;
    }

    /** @return list<string> todos los índices parciales de las tablas cuyo nombre empieza por `$prefijo` */
    private function partialIndexesLike(string $prefijo): array
    {
        if ($this->isPostgres()) {
            $nombres = array_map(static fn (object $f): string => $f->relname, DB::select(
                'SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE ? AND i.indpred IS NOT NULL',
                [str_replace('_', '\\_', $prefijo).'%']));
        } else {
            $nombres = DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'like', $prefijo.'%')->where('sql', 'like', '% WHERE %')->pluck('name')->all();
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /**
     * Todo lo de `$tables` y `$functions`, para comparar antes y después
     * (columnas, índices, restricciones, disparadores, funciones y ACL).
     *
     * @param  list<string>  $tables
     * @param  list<string>  $functions
     * @return array<string, mixed>
     */
    private function footprint(array $tables, array $functions = []): array
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->whereIn('tbl_name', $tables)->orderBy('type')->orderBy('name')
                ->get(['type', 'name', 'sql'])->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        }
        $t = '{'.implode(',', $tables).'}';

        return [
            'columnas' => array_map(static fn (object $f): string => "{$f->table_name}.{$f->column_name} {$f->data_type} {$f->is_nullable} {$f->column_default}", DB::select(
                "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ANY(?::text[]) ORDER BY table_name, ordinal_position", [$t])),
            'indices' => array_map(static fn (object $f): string => $f->indexdef, DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ANY(?::text[]) ORDER BY indexname", [$t])),
            'restricciones' => array_map(static fn (object $f): string => "{$f->conname} {$f->def}", DB::select(
                'SELECT k.conname, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE c.relname = ANY(?::text[]) ORDER BY 1', [$t])),
            'disparadores' => array_map(static fn (object $f): string => $f->def, DB::select(
                'SELECT pg_get_triggerdef(t.oid) AS def FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ANY(?::text[]) ORDER BY t.tgname', [$t])),
            'funciones' => array_map(static fn (object $f): string => "{$f->proname} ".md5($f->def), DB::select(
                'SELECT proname, pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname = ANY(?::text[]) ORDER BY 1', ['{'.implode(',', $functions).'}'])),
            'acl' => array_map(static fn (object $f): string => "{$f->relname} {$f->acl}", DB::select(
                "SELECT relname, coalesce(relacl::text, '') AS acl FROM pg_class WHERE relname = ANY(?::text[]) AND relkind = 'r' ORDER BY 1", [$t])),
        ];
    }

    // ── El modelo dueño/app (solo PG) ───────────────────────────────────────

    private function appRoleName(): string
    {
        return 'ultron_studio_h9_db_app';
    }

    /**
     * El rol de prueba, con los privilegios por defecto del dueño como en
     * producción (`arwd` en tablas, `rwU` en secuencias), dentro de una
     * transacción que se deshace.
     *
     * @param  Closure(): void  $fn
     */
    private function withAppRole(Closure $fn): void
    {
        $rol = $this->appRoleName();
        DB::beginTransaction();
        try {
            DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.$rol.'\') THEN CREATE ROLE '.$rol.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
            DB::statement('GRANT USAGE ON SCHEMA public TO '.$rol);
            // Las tablas que ya existían (identidades, el libro): como la aplicación de hoy.
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON identities, ultron_studio_audit_events TO '.$rol);
            DB::statement('GRANT USAGE, SELECT, UPDATE ON identities_id_seq, ultron_studio_audit_events_id_seq TO '.$rol);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$rol);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.$rol);
            putenv('ULTRON_STUDIO_DB_APP_ROLE='.$rol);
            $fn();
        } finally {
            putenv('ULTRON_STUDIO_DB_APP_ROLE');
            DB::rollBack();
        }
    }

    /** @return list<bool> SELECT, INSERT, UPDATE (tabla o columna), DELETE y TRUNCATE del rol de prueba. */
    private function privileges(string $table): array
    {
        $rol = $this->appRoleName();
        $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d, has_table_privilege(?, ?, 'TRUNCATE')::int AS t",
            array_merge(...array_fill(0, 5, [$rol, "public.{$table}"])));

        return [(int) $p->s === 1, (int) $p->i === 1, (int) $p->u === 1, (int) $p->d === 1, (int) $p->t === 1];
    }

    /** Ejecuta `$fn` como el rol de la aplicación y lo deshace. @return array{0: ?string, 1: string} [SQLSTATE o null, mensaje] */
    private function asApp(Closure $fn): array
    {
        $rol = $this->appRoleName();
        $deshacer = new RuntimeException('deshecho');
        try {
            DB::transaction(function () use ($fn, $deshacer, $rol): void {
                DB::statement('SET LOCAL ROLE '.$rol);
                $this->assertSame($rol, DB::scalar('SELECT current_user'));
                $fn();

                throw $deshacer;
            });
        } catch (QueryException $e) {
            $this->assertNotSame($rol, DB::scalar('SELECT current_user'), 'el rol no se deshizo');

            return [(string) ($e->getPrevious()?->errorInfo[0] ?? $e->getCode()), $e->getMessage()];
        } catch (RuntimeException $e) {
            if ($e === $deshacer) {
                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }
}
