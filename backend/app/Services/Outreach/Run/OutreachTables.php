<?php

namespace App\Services\Outreach\Run;

/**
 * Los nombres de las tablas de la ejecución (DB9, `2026_11_12_100002`) y de
 * las del gobierno que la ejecución LEE (`2026_11_12_100001`), una sola vez.
 */
final class OutreachTables
{
    public const RUNS = 'outreach_runs';

    public const CURSORS = 'outreach_cursors';

    public const ENROLLMENTS = 'outreach_enrollments';

    public const JOBS = 'outreach_jobs';

    public const DECISIONS = 'outreach_decisions';

    public const OUTCOMES = 'outreach_outcomes';

    public const BRAKES = 'outreach_brakes';

    public const BUDGETS = 'outreach_budget_counters';

    /** Del gobierno (S9/DB9): SOLO LECTURA desde la ejecución. */
    public const STRATEGIES = 'ultron_studio_strategies';

    public const VERSIONS = 'ultron_studio_strategy_versions';

    public const PUBLICATIONS = 'ultron_studio_strategy_publications';

    public const TEMPLATES = 'ultron_studio_message_templates';

    public const PERSON_BINDINGS = 'ultron_studio_person_bindings';

    public const IDENTITIES = 'identities';

    /** El sello (§5), común a inscripciones, trabajos y decisiones. */
    public const SEAL_COLUMNS = [
        'strategy_id', 'strategy_version_id', 'content_hash', 'publication_seq', 'part', 'studio_ref_kind',
        'studio_version_uuid', 'studio_content_hash', 'catalog_version', 'evaluator_version', 'mode',
    ];

    public const JOB_LIVE_STATUSES = ['planned', 'claimed', 'dispatching'];

    public const ENROLLMENT_OPEN_STATUSES = ['active', 'waiting'];
}
