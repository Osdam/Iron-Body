<?php

namespace Tests\Feature\Outreach\Run\Support;

use App\Models\Admin;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\UltronStudio\StudioCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;

/**
 * El mundo de las pruebas de B9-run: las filas del gobierno (DB9, por el
 * constructor de filas de `OutreachSchemaFixtures`, sin copiarlo) con una
 * CADENA FIRMADA DE VERDAD (la única implementación: `StrategyChainSignature`
 * con la `APP_KEY` de la suite), contenido `strategy-content/1` válido y los
 * socios y prospectos de `FactsWorld` (B9-facts). Ninguna fila lleva
 * teléfonos reales, nombres de socios reales ni documentos.
 *
 * Las personas: Ana (cuenta 1), Beto (2) y Caro (3), con sus vínculos
 * verificados (`people()` del esquema); Dani (4) comparte la identidad de Ana
 * (una persona con dos cuentas, L9-7).
 */
trait RunWorld
{
    use FactsWorld;
    use OutreachSchemaFixtures;

    /** @var array<string, Admin> */
    private array $cuentas = [];

    /**
     * Tres cuentas REALES con las personas verificadas del esquema: Ana, Beto y
     * Caro. Las filas del esquema vinculan las cuentas 1, 2 y 3 (números sin
     * fila en `admins`); si las cuentas reales reciben otros ids (en
     * PostgreSQL las secuencias no se deshacen con la transacción), cada una
     * recibe además su propio vínculo `crm` verificado a la MISMA identidad
     * (una persona puede tener dos cuentas).
     *
     * @return array{ana: Admin, beto: Admin, caro: Admin}
     */
    protected function threePeople(): array
    {
        if ($this->cuentas !== []) {
            return $this->cuentas;
        }
        foreach (['ana' => 'Ana', 'beto' => 'Beto', 'caro' => 'Caro'] as $clave => $nombre) {
            $this->cuentas[$clave] = Admin::create([
                'name' => $nombre, 'email' => strtolower($nombre).'-'.Str::random(8).'@run.test', 'password' => 'secret-password',
                'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
            ]);
        }
        $people = $this->people();
        // Quién crea y quién verifica cada vínculo extra: distintos entre sí y de la cuenta vinculada (cuatro ojos de la base).
        $testigos = ['ana' => [1, 3, 2], 'beto' => [2, 3, 1], 'caro' => [3, 1, 2]];
        foreach ($testigos as $clave => [$numero, $creador, $verificador]) {
            $cuenta = $this->cuentas[$clave];
            if ((int) $cuenta->id === $numero) {
                continue;
            }
            $id = $this->insertBinding((int) $cuenta->id, $people[$numero], ['created_by_admin_id' => $creador, 'created_by_name' => self::NAMES[$creador]]);
            $this->verifyBinding($id, $verificador, $people[$verificador]);
        }

        return $this->cuentas;
    }

    /** La identidad (id de `identities`) de una de las tres personas. */
    protected function identityOf(string $who): int
    {
        return $this->people()[['ana' => 1, 'beto' => 2, 'caro' => 3][$who]];
    }

    /** Dani (cuenta 4), con la MISMA identidad que Ana: una persona con dos cuentas (L9-7). */
    protected function secondAccountOfAna(): Admin
    {
        $this->threePeople();
        $dani = Admin::create(['name' => 'Dani', 'email' => 'dani-'.Str::random(8).'@run.test', 'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $people = $this->people();
        // Lo crea Caro y lo verifica Beto: la base exige que quien verifica sea distinto de quien lo creó y de la persona vinculada.
        $id = $this->insertBinding((int) $dani->id, $people[1], ['created_by_admin_id' => 3, 'created_by_name' => 'Caro']);
        $this->verifyBinding($id, 2, $people[2]);

        return $dani;
    }

    /** Una cuenta SIN vínculo con ninguna persona. */
    protected function unboundAccount(string $role = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create(['name' => 'Sin vínculo', 'email' => 'libre-'.Str::random(8).'@run.test', 'password' => 'secret-password', 'role' => $role, 'status' => 'active']);
    }

    /** Las banderas de la ejecución (todas apagadas por defecto en la suite). @param  array<string, mixed>  $extra */
    protected function runFlags(string $mode = 'shadow', array $extra = []): void
    {
        config(array_merge([
            'outreach.enabled' => true,
            'outreach.mode' => $mode,
            'outreach.assisted.enabled' => true,
            'outreach.assisted.daily_cap' => 20,
        ], $extra));
    }

    /** Un instante UTC. */
    protected static function utcAt(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * Un contenido `strategy-content/1` VÁLIDO: renovación a `daysBefore`
     * días, un paso asistido por WhatsApp con la plantilla dada, línea base
     * del Studio.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function validContent(int $templateId, array $override = []): array
    {
        $t = DB::table('ultron_studio_message_templates')->where('id', $templateId)->first(['uuid', 'content_hash', 'channel', 'content_json']);
        $canal = match ((string) $t->channel) {
            'whatsapp' => 'whatsapp_template',
            'voice' => 'voice_call',
            default => 'assisted:'.(json_decode((string) $t->content_json, true)['medium'] ?? 'whatsapp'),
        };

        return array_replace([
            'format' => 'strategy-content/1',
            'goal' => 'renew',
            'purpose' => 'commercial',
            'priority' => 60,
            'trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 7]],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
            ]],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
            'sequence' => [[
                'id' => 'aviso',
                'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'],
                'channels' => [$canal],
                'templates' => [$canal => ['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash]],
                'on' => ['no_answer' => 'retry_step', 'answered' => 'end_success'],
            ]],
            'limits' => ['maxTouches' => 3, 'enrollmentDays' => 14, 'newEnrollmentsPerDay' => 100, 'contactsPerDay' => 50, 'cooldownDays' => 30],
            'canary' => ['channel' => 'whatsapp'],
            'studio' => ['kind' => 'baseline', 'catalogVersion' => StudioCatalog::VERSION, 'catalogHash' => StudioCatalog::hash()],
        ], $override);
    }

    /**
     * Una estrategia con su versión 1 aprobada por Beto y VIVA en la cadena
     * con una entrada FIRMADA de verdad: `CANARY` (publish) o `PUBLISHED`
     * (publish + promote). Devuelve lo que las filas de la ejecución necesitan.
     *
     * @param  array<string, mixed>  $content  un contenido válido ({@see validContent()})
     * @param  array<string, mixed>  $strategy
     * @return array{strategy: int, strategyUuid: string, version: int, versionUuid: string, seq: int, purpose: string, part: string, v: object, contentHash: string}
     */
    protected function liveStrategy(array $content, string $status = 'CANARY', array $strategy = []): array
    {
        $this->threePeople();
        $leido = StrategyContent::fromArray($content, (new CatalogDomains)->read());
        if ($leido['canonical'] === null) {
            throw new \RuntimeException('El contenido de la prueba no es válido: '.json_encode($leido['errors']));
        }
        $s = $this->insertStrategy($strategy + ['purpose' => $content['purpose']]);
        $v = $this->insertVersion($s, [
            'content_json' => $leido['json'],
            'content_hash' => $leido['contentHash'],
            'catalog_version' => OutreachCatalog::VERSION,
            'studio_content_hash' => StudioCatalog::hash(),
        ]);
        $this->approveVersion($v);
        $version = $this->db()->table('ultron_studio_strategy_versions')->where('id', $v)->first();
        $parte = ['contentHash' => (string) $version->content_hash, 'version' => (string) $version->uuid];
        $seq = $this->signedPublication($s, $v, 'publish', ['canary' => $parte, 'rest' => null, 'status' => 'active']);
        $this->setVersionStatus($v, 'CANARY');
        $part = 'canary';
        if ($status === 'PUBLISHED') {
            $seq = $this->signedPublication($s, $v, 'promote', ['canary' => null, 'rest' => $parte, 'status' => 'active']);
            $this->setVersionStatus($v, 'PUBLISHED');
            $part = 'rest';
        }

        return [
            'strategy' => $s,
            'strategyUuid' => (string) $this->db()->table('ultron_studio_strategies')->where('id', $s)->value('uuid'),
            'version' => $v,
            'versionUuid' => (string) $version->uuid,
            'seq' => $seq,
            'purpose' => (string) $content['purpose'],
            'part' => $part,
            'v' => $this->db()->table('ultron_studio_strategy_versions')->where('id', $v)->first(),
            'contentHash' => (string) $version->content_hash,
        ];
    }

    /** Pausa la estrategia en la cadena (una entrada `pause` firmada). @param  array{strategy: int, version: int, v: object}  $live */
    protected function pauseStrategy(array $live, string $reason = 'Pausa de la prueba'): int
    {
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $estado = $live['part'] === 'rest' ? ['canary' => null, 'rest' => $parte, 'status' => 'paused'] : ['canary' => $parte, 'rest' => null, 'status' => 'paused'];

        // La base exige `version_id` nulo en pausas y reanudaciones (`usspub_shape_chk`): el estado va en el mensaje.
        return $this->signedPublication($live['strategy'], null, 'pause', $estado, $reason);
    }

    /** Revierte la estrategia en la cadena (`rollback`: queda apagada) y la versión pasa a ROLLED_BACK. @param  array{strategy: int, version: int, v: object}  $live */
    protected function rollbackStrategy(array $live, string $reason = 'Reversión de la prueba'): int
    {
        $seq = $this->signedPublication($live['strategy'], $live['version'], 'rollback', ['canary' => null, 'rest' => null, 'status' => 'off'], $reason);
        $this->setVersionStatus($live['version'], 'ROLLED_BACK');

        return $seq;
    }

    /**
     * Una entrada de la cadena FIRMADA con la clave de la suite: el mensaje
     * canónico (L9-23), `prevHash` de la cabeza, `seq` = cabeza + 1, la persona
     * que actúa (Beto) por su identidad.
     *
     * @param  array{canary: array{contentHash: string, version: string}|null, rest: array{contentHash: string, version: string}|null, status: string}  $state
     */
    protected function signedPublication(int $strategyId, ?int $versionId, string $action, array $state, ?string $reason = null): int
    {
        $people = $this->people();
        $beto = (string) $this->db()->table('identities')->where('id', $people[2])->value('uuid');
        $s = $this->db()->table('ultron_studio_strategies')->where('id', $strategyId)->first(['uuid', 'key', 'purpose']);
        $cabeza = $this->db()->table('ultron_studio_strategy_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = ($cabeza === null ? 0 : (int) $cabeza->seq) + 1;
        $prev = $cabeza === null ? StrategyChainSignature::GENESIS_PREV_HASH : (string) $cabeza->effective_hash;
        $mensaje = StrategyChainSignature::message([
            'action' => $action,
            'actedByPerson' => strtolower($beto),
            'approvedByPerson' => in_array($action, StrategyChainSignature::APPROVAL_ACTIONS, true) ? strtolower($beto) : null,
            'contract' => StrategyChainSignature::CONTRACT,
            'issuedAt' => RelativeTime::format(now()),
            'prevHash' => $prev,
            'seq' => $seq,
            'state' => $state,
            'strategy' => ['key' => (string) $s->key, 'purpose' => (string) $s->purpose, 'uuid' => (string) $s->uuid],
        ]);
        $firma = StrategyChainSignature::sign($mensaje, StrategyChainSignature::appKeyRaw((string) config('app.key')));
        $this->insertPublication($seq, $cabeza?->id, [
            'strategy_id' => $strategyId,
            'version_id' => $versionId,
            'action' => $action,
            'effective_json' => $mensaje,
            'effective_hash' => StrategyChainSignature::entryHash($mensaje),
            'signature' => $firma,
            'simulation_gate' => in_array($action, StrategyChainSignature::APPROVAL_ACTIONS, true) ? 'required' : null,
            'evidence' => $action === 'promote' ? json_encode(['touches' => 3, 'hours' => 24, 'passes' => true]) : null,
            'reason' => $reason,
        ]);

        return $seq;
    }

    /** Un socio con plan vigente que vence en `$daysFromToday` días de Bogotá contados desde `$nowUtc`. @return array{user: int, member: int, key: string} */
    protected function expiringMember(DateTimeImmutable $nowUtc, int $daysFromToday, array $user = [], array $member = []): array
    {
        $fin = (new DateTimeImmutable(RelativeTime::bogotaDate($nowUtc), new DateTimeZone('America/Bogota')))->modify(($daysFromToday >= 0 ? '+' : '').$daysFromToday.' days')->format('Y-m-d');
        $r = $this->socio(['membership_end_date' => $fin, 'plan' => 'Mensual'] + $user, $member);

        return $r + ['key' => 'm:'.$r['member']];
    }

    /** La plantilla asistida aprobada (Ana la crea, Beto la aprueba), por las filas del esquema. */
    protected function assistedTemplate(array $o = []): int
    {
        $this->threePeople();

        return $this->approvedTemplate(['channel' => 'assisted'] + $o);
    }

    /**
     * Un socio que califica: vence en `$days` días, con su prospecto de WhatsApp
     * autorizado (`granted`), conversación y número canario activo (la línea
     * base del Studio declara «solo canario», N3).
     *
     * @return array{user: int, member: int, key: string, lead: int}
     */
    protected function qualifyingMember(DateTimeImmutable $nowUtc, int $days, string $phone): array
    {
        $r = $this->expiringMember($nowUtc, $days);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $this->canary('+57'.$phone, 'whatsapp');

        return $r + ['lead' => $lead];
    }

    /**
     * Una estrategia viva, un socio que califica y su trabajo planificado (en el
     * modo de las banderas); el reloj queda 15 min después (el desfase).
     *
     * @param  array<string, mixed>  $flags
     * @param  array<string, mixed>  $content
     * @return array{live: array<string, mixed>, member: array<string, mixed>, job: object}
     */
    protected function plannedJobWorld(DateTimeImmutable $nowUtc, string $mode = 'shadow', array $flags = [], array $content = []): array
    {
        $this->runFlags($mode, $flags);
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), $content));
        $socio = $this->qualifyingMember($nowUtc, 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep($nowUtc, true);
        $r = $planner->planDue($nowUtc);
        if (($r['planned'] ?? 0) !== 1) {
            throw new \RuntimeException('No se planificó el trabajo de la prueba: '.json_encode($r));
        }
        Carbon::setTestNow(Carbon::instance($nowUtc)->addMinutes(15));

        return ['live' => $vivo, 'member' => $socio, 'job' => DB::table('outreach_jobs')->first()];
    }
}
