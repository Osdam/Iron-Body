<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioStrategyValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EL PUENTE hacia el gobierno (contrato H9, D9-2 «puente OutreachPublishedStrategies»,
 * D9-11.3 «El lector», D9-11.4, §9.3): el ÚNICO fichero de la ejecución que
 * mira las tablas del Studio, como `VoicePublishedConfig` en el H8. Lee, por
 * SQL directo y SOLO LECTURA, la cadena firmada de estrategias
 * (`ultron_studio_strategy_publications`), las versiones, las estrategias,
 * las plantillas y los vínculos de personas; verifica la cadena con la ÚNICA
 * implementación de la firma (`Core\StrategyChainSignature`, pura) y relee el
 * contenido con `Core\StrategyContent::fromStored()`.
 *
 * NUNCA LANZA ni escribe: una estrategia cuya entrada no verifica sale con
 * `chainVerified ≠ ok` y la escalera la niega (`chain_unreadable`, N5); si la
 * CABEZA de la cadena no verifica ({@see headVerified()}), la ejecución niega
 * todas y el monitor abre el freno global (D9-11.3). La comparación entre la
 * fila y su mensaje (`row_mismatch`) es la misma que hace S9: una entrada
 * copiada de otra estrategia o con la acción cambiada no verifica.
 *
 * Dos lecturas del Studio van por su código y no por SQL, porque son la única
 * implementación de su regla (nombradas aquí y en ningún otro fichero de
 * `Outreach`): la referencia comercial de N3
 * (`StudioStrategyValidator::studioReference`, sin exigir el estado: la
 * integridad, no la retroactividad, D9-11.4) y las ayudas puras de las
 * plantillas (`StudioMessageTemplates::usesPaymentLink/violationsOf`).
 */
final class OutreachPublishedStrategies
{
    public const VERIFY_ROW_MISMATCH = 'row_mismatch';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_OFF = 'off';

    /** @var array<string, list<int|string>>|null */
    private ?array $domains = null;

    /** @var array<string, array<string, mixed>|null> plantillas por uuid, una vez por pasada */
    private array $templates = [];

    public function __construct(private readonly CatalogDomains $catalogDomains = new CatalogDomains) {}

    // ── La cadena ───────────────────────────────────────────────────────────

    /**
     * Las versiones VIVAS de todas las estrategias no archivadas (una o dos por
     * estrategia: `canary` y `rest`), con su entrada de la cadena verificada.
     * Las que no verifican, las pausadas y las que no se pueden releer vienen
     * también (con su motivo): quien planifica decide qué hace con ellas
     * (negar, cancelar), y el panel las enseña.
     *
     * `$strategyIds` las limita a esas estrategias (la bandeja asistida lee solo las de su página, N-2);
     * null = todas. La cadena se verifica igual: cada entrada contra la anterior GLOBAL.
     *
     * @param  list<int>|null  $strategyIds
     * @return list<LiveStrategy>
     */
    public function liveStrategies(?array $strategyIds = null): array
    {
        $consulta = DB::table(OutreachTables::STRATEGIES)->whereNull('archived_at')->orderBy('id');
        if ($strategyIds !== null) {
            if ($strategyIds === []) {
                return [];
            }
            $consulta->whereIn('id', array_values(array_unique(array_map('intval', $strategyIds))));
        }
        $estrategias = $consulta->get(['id', 'uuid', 'key', 'purpose'])->keyBy('id');
        if ($estrategias->isEmpty()) {
            return [];
        }
        $estados = $this->chainStates($estrategias->keys()->map(static fn ($id): int => (int) $id)->all());
        $uuids = [];
        foreach ($estados as $estado) {
            foreach (['canary', 'rest'] as $parte) {
                if (isset($estado['state'][$parte]['version'])) {
                    $uuids[] = strtolower((string) $estado['state'][$parte]['version']);
                }
            }
        }
        $versiones = $uuids === [] ? collect() : DB::table(OutreachTables::VERSIONS)->whereIn('uuid', array_values(array_unique($uuids)))
            ->get(['id', 'uuid', 'strategy_id', 'number', 'content_json', 'content_hash', 'catalog_version', 'evaluator_version', 'studio_ref_kind', 'studio_version_uuid', 'studio_content_hash', 'status'])
            ->keyBy(static fn (object $v): string => strtolower((string) $v->uuid));

        $vivas = [];
        foreach ($estados as $strategyId => $estado) {
            $s = $estrategias->get($strategyId);
            if ($s === null || $estado['state'] === null) {
                continue;
            }
            foreach (['canary', 'rest'] as $parte) {
                $ref = $estado['state'][$parte] ?? null;
                if ($ref === null) {
                    continue;
                }
                $v = $versiones->get(strtolower((string) $ref['version']));
                if ($v === null || (int) $v->strategy_id !== (int) $s->id) {
                    // La cadena nombra una versión que no es de la estrategia (o ya no existe): ilegible, no se reinterpreta.
                    $vivas[] = $this->unreadable($s, $parte, $estado, (string) $ref['version'], (string) $ref['contentHash']);

                    continue;
                }
                $vivas[] = $this->live($s, $v, $parte, $estado, hash_equals((string) $v->content_hash, (string) $ref['contentHash']) ? $estado['verified'] : self::VERIFY_ROW_MISMATCH);
            }
        }

        return $vivas;
    }

    /** Las vivas que pueden decidir ahora: cadena legible, estado `active`, versión en su estado y contenido releído. @return list<LiveStrategy> */
    /**
     * Las estrategias (no archivadas) cuya CABEZA de la cadena no verifica (firma, fila o núcleo).
     * Con ellas, un par (versión, parte) AUSENTE no se trata como huérfano (REV9 m-10, L9-72): una
     * entrada de reversión o retirada falsificada, o una cabeza que nombra una versión inexistente,
     * no cancela nada; N5 niega, el monitor frena y se espera a que la cadena vuelva a leerse.
     *
     * @return list<int>
     */
    public function unverifiedStrategyIds(): array
    {
        $ids = DB::table(OutreachTables::STRATEGIES)->whereNull('archived_at')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $salida = [];
        foreach ($this->chainStates($ids) as $strategyId => $estado) {
            if ($estado['verified'] !== self::STATUS_OFF && $estado['verified'] !== 'ok') {
                $salida[] = (int) $strategyId;
            }
        }

        return $salida;
    }

    public function runnableStrategies(): array
    {
        return array_values(array_filter($this->liveStrategies(), static fn (LiveStrategy $l): bool => $l->runnable() && ! $l->paused()));
    }

    /** La versión viva (por `strategy_version_id` y parte) tal como está AHORA en la cadena, o null si ya no lo está (`strategy_moved`). */
    public function liveVersion(int $versionId, string $part): ?LiveStrategy
    {
        foreach ($this->liveStrategies() as $l) {
            if ($l->versionId === $versionId && $l->part === $part) {
                return $l;
            }
        }

        return null;
    }

    /**
     * El contenido releído de una versión, esté o no viva (lo que un resultado
     * tardío necesita de su secuencia: el `on` del paso y los retrasos), o
     * null si no se puede releer.
     *
     * @return array<string, mixed>|null
     */
    public function contentOfVersion(int $versionId): ?array
    {
        $v = DB::table(OutreachTables::VERSIONS)->where('id', $versionId)->first(['content_json', 'content_hash']);
        if ($v === null) {
            return null;
        }

        return StrategyContent::fromStored((string) $v->content_json, (string) $v->content_hash, $this->domains())['canonical'];
    }

    /**
     * ¿Verifica la CABEZA de la cadena (la entrada de mayor `seq`)? Sin
     * entradas, sí (no hay nada publicado). Si no verifica, se niega todo
     * (D9-11.3) y el monitor abre el freno global.
     *
     * @return array{verified: bool, result: string|null, seq: int|null}
     */
    public function headVerified(): array
    {
        $cabeza = DB::table(OutreachTables::PUBLICATIONS)->orderByDesc('seq')->first(self::columns());
        if ($cabeza === null) {
            return ['verified' => true, 'result' => null, 'seq' => null];
        }
        $anterior = (int) $cabeza->seq > 1 ? DB::table(OutreachTables::PUBLICATIONS)->where('seq', (int) $cabeza->seq - 1)->value('effective_hash') : null;
        $r = $this->verifyRow($cabeza, is_string($anterior) ? $anterior : null);

        return ['verified' => $r === StrategyChainSignature::RESULT_OK, 'result' => $r, 'seq' => (int) $cabeza->seq];
    }

    /**
     * La cadena ENTERA desde la génesis, por el núcleo (lo que mira el monitor).
     *
     * @return array{result: string, seq: int|null, verified: int}
     */
    public function verifyChain(): array
    {
        $filas = DB::table(OutreachTables::PUBLICATIONS)->orderBy('seq')->get(['effective_json', 'signature']);
        $clave = $this->appKey();
        if ($clave === null) {
            return ['result' => 'signature_mismatch', 'seq' => $filas->isEmpty() ? null : 1, 'verified' => 0];
        }

        return StrategyChainSignature::verifyChain(
            $filas->map(static fn (object $f): array => ['message' => (string) $f->effective_json, 'signature' => (string) $f->signature])->all(),
            $clave,
        );
    }

    // ── Las personas (cuatro ojos de los frenos, L9-7) ──────────────────────

    /**
     * La persona VERIFICADA de una cuenta (su vínculo verificado y sin revocar),
     * o null. La misma consulta que `StudioPersonBindings::personOf()`.
     *
     * @return array{identityId: int, identityUuid: string}|null
     */
    public function personOf(int $adminId): ?array
    {
        $fila = DB::table(OutreachTables::PERSON_BINDINGS.' as b')
            ->join(OutreachTables::IDENTITIES.' as i', 'i.id', '=', 'b.identity_id')
            ->where('b.admin_id', $adminId)
            ->whereNotNull('b.verified_at')
            ->whereNull('b.revoked_at')
            ->orderByDesc('b.id')
            ->first(['b.identity_id', 'i.uuid as identity_uuid']);
        if ($fila === null || ! is_string($fila->identity_uuid) || $fila->identity_uuid === '') {
            return null;
        }

        return ['identityId' => (int) $fila->identity_id, 'identityUuid' => strtolower((string) $fila->identity_uuid)];
    }

    /**
     * Los vínculos de persona VERIFICADOS y vivos (sin revocar), el `verified`
     * de `StudioPersonBindings` (`:359`), contados de dos maneras y sin datos
     * personales (ni cuentas ni identidades):
     *  - `verified`: cuántos vínculos (una persona con dos cuentas, L9-7,
     *    cuenta dos);
     *  - `persons`: cuántas identidades DISTINTAS tienen al menos uno, que es
     *    lo que cuentan los cuatro ojos («hacen falta dos personas vinculadas
     *    (hay N)»).
     * El panel los necesita sin la lista de vínculos, que exige un permiso alto.
     *
     * @return array{verified: int, persons: int}
     */
    public function verifiedBindingsCount(): array
    {
        $vivos = static fn () => DB::table(OutreachTables::PERSON_BINDINGS)->whereNotNull('verified_at')->whereNull('revoked_at');

        return ['verified' => $vivos()->count(), 'persons' => $vivos()->distinct()->count('identity_id')];
    }

    /** ¿Son la misma persona? Por cuenta O por identidad: cualquiera de las dos basta para negar (P9-4, L9-7). */
    public static function samePerson(int $adminA, ?int $identityA, int $adminB, ?int $identityB): bool
    {
        return $adminA === $adminB || ($identityA !== null && $identityB !== null && $identityA === $identityB);
    }

    // ── Las plantillas (N3/N4 del paso) ─────────────────────────────────────

    /**
     * El estado de la plantilla de un canal de un paso, lo que N3 y N4 miran:
     * aprobada con la huella exacta y de su canal (`template_not_approved` si
     * no), la categoría que casa con la finalidad (WhatsApp: `utility` →
     * cobranza, `marketing` → comercial), si usa el marcador de enlace de pago
     * y las violaciones R-01/02/03/06. Sin la fila, nada aprobado.
     *
     * @param  array{uuid: string, hash: string}  $ref
     * @return array{id: int|null, approved: bool, categoryMatches: bool, usesPaymentLink: bool, violations: list<string>, medium: string|null, category: string|null}
     */
    public function templateState(array $ref, string $stepChannel, string $purpose): array
    {
        $uuid = strtolower((string) ($ref['uuid'] ?? ''));
        if (! array_key_exists($uuid, $this->templates)) {
            $fila = $uuid === '' ? null : DB::table(OutreachTables::TEMPLATES)->where('uuid', $uuid)->first(['id', 'channel', 'purpose', 'category', 'content_json', 'content_hash', 'status']);
            $contenido = null;
            if ($fila !== null) {
                $decodificado = json_decode((string) $fila->content_json, true);
                $contenido = is_array($decodificado) ? $decodificado : [];
            }
            $this->templates[$uuid] = $fila === null ? null : [
                'id' => (int) $fila->id, 'channel' => (string) $fila->channel, 'purpose' => (string) $fila->purpose, 'category' => $fila->category,
                'hash' => (string) $fila->content_hash, 'status' => (string) $fila->status, 'content' => $contenido,
                'integrity' => hash_equals(hash('sha256', (string) $fila->content_json), (string) $fila->content_hash),
            ];
        }
        $t = $this->templates[$uuid];
        $nada = ['id' => null, 'approved' => false, 'categoryMatches' => false, 'usesPaymentLink' => false, 'violations' => [], 'medium' => null, 'category' => null];
        if ($t === null) {
            return $nada;
        }
        $medio = OutreachCatalog::mediumOf($stepChannel);
        $canalTrabajo = OutreachSendGate::jobChannelOf($stepChannel);
        $deSuCanal = match ($canalTrabajo) {
            'assisted' => $t['channel'] === StudioMessageTemplates::CHANNEL_ASSISTED && (($t['content']['medium'] ?? null) === $medio),
            OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE => $t['channel'] === StudioMessageTemplates::CHANNEL_WHATSAPP,
            OutreachCatalog::CHANNEL_VOICE_CALL => $t['channel'] === StudioMessageTemplates::CHANNEL_VOICE,
            default => false,
        };
        $categoria = $t['channel'] !== StudioMessageTemplates::CHANNEL_WHATSAPP
            || (($t['content']['category'] ?? $t['category']) === (StudioMessageTemplates::CATEGORY_BY_PURPOSE[$purpose] ?? null));

        return [
            'id' => $t['id'],
            'approved' => $t['status'] === 'APPROVED' && $t['integrity'] && hash_equals($t['hash'], (string) ($ref['hash'] ?? '')) && $deSuCanal,
            'categoryMatches' => $t['purpose'] === $purpose && $categoria,
            'usesPaymentLink' => StudioMessageTemplates::usesPaymentLink($t['content']),
            'violations' => StudioMessageTemplates::violationsOf($t['content']),
            'medium' => is_string($t['content']['medium'] ?? null) ? $t['content']['medium'] : null,
            'category' => is_string($t['content']['category'] ?? null) ? $t['content']['category'] : (is_string($t['category']) ? $t['category'] : null),
        ];
    }

    /** El guion de una plantilla asistida (lo que ve el personal en la bandeja, D9-9.5); null si no es asistida. */
    public function assistedScript(int $templateId): ?string
    {
        $fila = DB::table(OutreachTables::TEMPLATES)->where('id', $templateId)->first(['channel', 'content_json']);
        if ($fila === null || (string) $fila->channel !== StudioMessageTemplates::CHANNEL_ASSISTED) {
            return null;
        }
        $c = json_decode((string) $fila->content_json, true);

        return is_array($c) && is_string($c['script'] ?? null) ? $c['script'] : null;
    }

    // ── W9: la plantilla de WhatsApp y su correspondencia con Meta ─────────

    /**
     * La plantilla de WhatsApp del SELLO de un trabajo (id + huella), tal como
     * está guardada en el Studio, solo si sigue APROBADA, es de WhatsApp, es de
     * esa huella, sus bytes casan con su huella y es de la finalidad de la
     * estrategia con la categoría que esa finalidad admite (cobranza y
     * servicio `utility`, comercial `marketing`: D9-6, P9-7); null en
     * cualquier otro caso (el canal no envía: `template_not_approved`). Nunca
     * lanza.
     *
     * @return array{id: int, uuid: string, hash: string, purpose: string, content: array<string, mixed>}|null
     */
    public function approvedWhatsappTemplate(int $templateId, string $hash, string $purpose): ?array
    {
        try {
            $fila = DB::table(OutreachTables::TEMPLATES)->where('id', $templateId)->first(['id', 'uuid', 'channel', 'purpose', 'content_json', 'content_hash', 'status']);
        } catch (Throwable $e) {
            Log::error('outreach.bridge.template_unreadable', ['template_id' => $templateId, 'error_class' => $e::class]);

            return null;
        }
        if ($fila === null || (string) $fila->channel !== StudioMessageTemplates::CHANNEL_WHATSAPP || (string) $fila->status !== 'APPROVED'
            || ! hash_equals((string) $fila->content_hash, strtolower($hash))
            || ! hash_equals(hash('sha256', (string) $fila->content_json), (string) $fila->content_hash)) {
            return null;
        }
        $contenido = json_decode((string) $fila->content_json, true);
        if (! is_array($contenido) || (string) $fila->purpose !== $purpose
            || ($contenido['category'] ?? null) !== (StudioMessageTemplates::CATEGORY_BY_PURPOSE[$purpose] ?? null)) {
            return null;
        }

        return [
            'id' => (int) $fila->id, 'uuid' => (string) $fila->uuid, 'hash' => (string) $fila->content_hash,
            'purpose' => (string) $fila->purpose, 'content' => $contenido,
        ];
    }

    /**
     * ¿Corresponde esta plantilla del Studio a una plantilla APROBADA por Meta?
     * Manda la ÚLTIMA comprobación registrada de esa plantilla
     * (`ultron_studio_template_meta_checks`, mayor id): tiene que ser
     * `APPROVED`, de la MISMA huella del Studio y decir el mismo nombre de
     * Meta, idioma, categoría y texto (la huella sha256 de `bodyPreview`, el
     * texto exacto que aprobó Meta). Una pausa, un rechazo o una baja en Meta
     * posteriores (otra fila) la dejan sin aprobar. Nunca lanza: sin poder
     * leerla, no (falla cerrado).
     *
     * Y LOS COMPONENTES (plantillas de Meta desde el CRM, PLANTILLAS_META_PLAN
     * §5.3; enmienda de la protección 12 en L9-96): una fila SIN huella de
     * componentes (la orden manual) solo vale para una plantilla de solo
     * cuerpo, como hasta hoy; una CON huella (la sincronización) tiene que
     * casar con la del contenido (encabezado, pie y botones, con la ayuda pura
     * `componentsFingerprint`). Así, editar los botones en el administrador de
     * Meta no se salta los cuatro ojos.
     *
     * @param  array{id: int, hash: string, content: array<string, mixed>}  $template  {@see approvedWhatsappTemplate()}
     */
    public function metaApproved(array $template): bool
    {
        try {
            $f = DB::table(OutreachTables::TEMPLATE_META_CHECKS)->where('template_id', $template['id'])->orderByDesc('id')
                ->first(['status', 'template_content_hash', 'meta_name', 'language', 'category', 'meta_body_hash', 'meta_components_hash']);
        } catch (Throwable $e) {
            Log::error('outreach.bridge.meta_check_unreadable', ['template_id' => $template['id'], 'error_class' => $e::class]);

            return false;
        }
        $c = $template['content'];
        if ($f === null || ! is_string($c['bodyPreview'] ?? null)) {
            return false;
        }

        return (string) $f->status === 'APPROVED'
            && hash_equals((string) $f->template_content_hash, $template['hash'])
            && (string) $f->meta_name === ($c['metaName'] ?? null)
            && (string) $f->language === ($c['language'] ?? null)
            && (string) $f->category === ($c['category'] ?? null)
            && hash_equals((string) $f->meta_body_hash, hash('sha256', $c['bodyPreview']))
            && self::componentsMatch($f->meta_components_hash, $c, $template['id']);
    }

    /**
     * La regla de los componentes de {@see metaApproved()}: sin huella en la
     * fila, la plantilla no puede tener componentes; con huella, tienen que
     * casar. Nunca lanza: si la huella no se puede calcular, no casa (falla
     * cerrado) y queda en el registro.
     *
     * @param  array<string, mixed>  $content
     */
    private static function componentsMatch(mixed $huella, array $content, int $templateId): bool
    {
        try {
            return $huella === null
                ? ! StudioMessageTemplates::hasComponents($content)
                : hash_equals((string) $huella, StudioMessageTemplates::componentsFingerprint($content));
        } catch (Throwable $e) {
            Log::error('outreach.bridge.components_unreadable', ['template_id' => $templateId, 'error_class' => $e::class]);

            return false;
        }
    }

    /**
     * Las restricciones del código (R-01 importes, R-02 descuentos, R-03 URL,
     * R-06 promesas) sobre el texto YA COMPUESTO de una plantilla: N3 las mira
     * al validar y «otra vez al despachar» (D9-7), y los marcadores resueltos
     * podrían traer lo que la plantilla no traía. Con la ÚNICA implementación
     * (la del Studio).
     *
     * @return list<string>
     */
    public static function restrictionViolations(string $text): array
    {
        return StudioMessageTemplates::restrictionViolations($text);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * El estado de la cadena de cada estrategia (su última entrada, verificada
     * contra la anterior GLOBAL), en cuatro consultas.
     *
     * @param  list<int>  $strategyIds
     * @return array<int, array{state: array<string,mixed>|null, seq: int|null, verified: string}>
     */
    private function chainStates(array $strategyIds): array
    {
        $salida = array_fill_keys($strategyIds, ['state' => null, 'seq' => null, 'verified' => self::STATUS_OFF]);
        if ($strategyIds === []) {
            return $salida;
        }
        $maximos = DB::table(OutreachTables::PUBLICATIONS)->whereIn('strategy_id', $strategyIds)->groupBy('strategy_id')
            ->selectRaw('max(seq) as seq')->pluck('seq')->map(static fn ($s): int => (int) $s)->all();
        if ($maximos === []) {
            return $salida;
        }
        $ultimas = DB::table(OutreachTables::PUBLICATIONS)->whereIn('seq', $maximos)->get(self::columns());
        $anteriores = DB::table(OutreachTables::PUBLICATIONS)->whereIn('seq', array_map(static fn (int $s): int => $s - 1, $maximos))->pluck('effective_hash', 'seq')->all();
        foreach ($ultimas as $fila) {
            $previo = $anteriores[(int) $fila->seq - 1] ?? null;
            $verificada = $this->verifyRow($fila, is_string($previo) ? $previo : null);
            $m = StrategyChainSignature::decode((string) $fila->effective_json);
            $salida[(int) $fila->strategy_id] = [
                'state' => is_array($m['state'] ?? null) ? $m['state'] : null,
                'seq' => (int) $fila->seq,
                'verified' => $verificada,
            ];
        }

        return $salida;
    }

    /** La verificación de una fila: el mensaje con el núcleo y la fila con su mensaje. Nunca lanza. */
    private function verifyRow(object $fila, ?string $previousHash): string
    {
        $clave = $this->appKey();
        if ($clave === null) {
            return 'signature_mismatch';
        }
        $json = $fila->effective_json;
        if (! is_string($json) || ! hash_equals(StrategyChainSignature::entryHash($json), (string) $fila->effective_hash)) {
            return self::VERIFY_ROW_MISMATCH;
        }
        $seq = (int) $fila->seq;
        if ($seq > 1 && $previousHash === null) {
            return 'seq_gap';
        }
        $r = StrategyChainSignature::verifyEntry($json, (string) $fila->signature, $seq > 1 ? ['seq' => $seq - 1, 'entryHash' => (string) $previousHash] : null, $clave);
        if ($r !== StrategyChainSignature::RESULT_OK) {
            return $r;
        }
        $m = (array) StrategyChainSignature::decode($json);
        $estrategia = DB::table(OutreachTables::STRATEGIES)->where('id', (int) $fila->strategy_id)->first(['id', 'uuid', 'key', 'purpose']);
        $persona = $fila->acted_by_person_id === null ? null : DB::table(OutreachTables::IDENTITIES)->where('id', (int) $fila->acted_by_person_id)->value('uuid');
        $cuadra = $estrategia !== null
            && ($m['seq'] ?? null) === $seq
            && hash_equals((string) $fila->prev_hash, (string) ($m['prevHash'] ?? ''))
            && ($m['action'] ?? null) === $fila->action
            && ($m['strategy']['uuid'] ?? null) === (string) $estrategia->uuid
            && ($m['strategy']['key'] ?? null) === (string) $estrategia->key
            && ($m['strategy']['purpose'] ?? null) === (string) $estrategia->purpose
            && is_string($persona) && strtolower($persona) === ($m['actedByPerson'] ?? null);

        return $cuadra ? StrategyChainSignature::RESULT_OK : self::VERIFY_ROW_MISMATCH;
    }

    /** @param  array{state: array<string,mixed>|null, seq: int|null, verified: string}  $estado */
    private function live(object $s, object $v, string $parte, array $estado, string $verified): LiveStrategy
    {
        $releida = StrategyContent::fromStored((string) $v->content_json, (string) $v->content_hash, $this->domains());
        $contenido = $releida['canonical'];
        $studio = $this->studioReference($v);

        return new LiveStrategy(
            strategyId: (int) $s->id,
            strategyUuid: strtolower((string) $s->uuid),
            strategyKey: (string) $s->key,
            purpose: (string) $s->purpose,
            versionId: (int) $v->id,
            versionUuid: strtolower((string) $v->uuid),
            versionNumber: (int) $v->number,
            versionStatus: (string) $v->status,
            contentHash: (string) $v->content_hash,
            content: $contenido,
            contentReason: $releida['reason'],
            catalogVersion: (string) $v->catalog_version,
            evaluatorVersion: (string) $v->evaluator_version,
            studioRefKind: (string) $v->studio_ref_kind,
            studioVersionUuid: $v->studio_version_uuid !== null ? strtolower((string) $v->studio_version_uuid) : null,
            studioContentHash: (string) $v->studio_content_hash,
            part: $parte,
            publicationSeq: (int) $estado['seq'],
            chainStatus: (string) ($estado['state']['status'] ?? self::STATUS_OFF),
            chainVerified: $verified,
            studio: $studio,
            dynamicDomains: $this->domains(),
        );
    }

    /** Una parte de la cadena que nombra una versión que no es de la estrategia: ilegible. */
    private function unreadable(object $s, string $parte, array $estado, string $versionUuid, string $contentHash): LiveStrategy
    {
        return new LiveStrategy(
            strategyId: (int) $s->id, strategyUuid: strtolower((string) $s->uuid), strategyKey: (string) $s->key, purpose: (string) $s->purpose,
            versionId: 0, versionUuid: strtolower($versionUuid), versionNumber: 0, versionStatus: 'UNKNOWN', contentHash: $contentHash,
            content: null, contentReason: 'version_not_found', catalogVersion: OutreachCatalog::VERSION, evaluatorVersion: 'outreach-evaluator/1',
            studioRefKind: 'baseline', studioVersionUuid: null, studioContentHash: str_repeat('0', 64), part: $parte,
            publicationSeq: (int) ($estado['seq'] ?? 0), chainStatus: (string) ($estado['state']['status'] ?? self::STATUS_OFF),
            chainVerified: self::VERIFY_ROW_MISMATCH, studio: ['referenceValid' => false, 'paymentLinksEnabled' => false, 'canaryOnly' => true, 'reason' => 'version_not_found'],
        );
    }

    /**
     * N3: la referencia comercial de la versión (D9-11.4): la integridad de la
     * huella, no su estado actual (no hay retroactividad). Si no se puede
     * leer, inválida (falla cerrado).
     *
     * @return array{referenceValid: bool, paymentLinksEnabled: bool, canaryOnly: bool, reason: string|null}
     */
    private function studioReference(object $v): array
    {
        try {
            $ref = (string) $v->studio_ref_kind === 'version'
                ? ['kind' => 'version', 'uuid' => (string) $v->studio_version_uuid, 'contentHash' => (string) $v->studio_content_hash]
                : ['kind' => 'baseline', 'catalogVersion' => $this->baselineCatalogVersion($v), 'catalogHash' => (string) $v->studio_content_hash];
            $r = StudioStrategyValidator::studioReference($ref, false);

            return ['referenceValid' => $r['valid'], 'paymentLinksEnabled' => $r['paymentLinksEnabled'], 'canaryOnly' => $r['canaryOnly'], 'reason' => $r['reason']];
        } catch (Throwable $e) {
            // Tragado a propósito y anotado: una referencia que no se puede comprobar NO vale (N3 la niega).
            Log::warning('outreach.studio_reference_unreadable', ['version_id' => (int) $v->id, 'error_class' => class_basename($e)]);

            return ['referenceValid' => false, 'paymentLinksEnabled' => false, 'canaryOnly' => true, 'reason' => 'unreadable'];
        }
    }

    /** La versión del catálogo del Studio que declara el contenido (`studio.catalogVersion`), sin releer la versión entera. */
    private function baselineCatalogVersion(object $v): string
    {
        $c = json_decode((string) $v->content_json, true);

        return is_array($c) && is_string($c['studio']['catalogVersion'] ?? null) ? $c['studio']['catalogVersion'] : '';
    }

    /** @return array<string, list<int|string>> */
    private function domains(): array
    {
        return $this->domains ??= $this->catalogDomains->read();
    }

    private function appKey(): ?string
    {
        try {
            return StrategyChainSignature::appKeyRaw((string) config('app.key'));
        } catch (Throwable $e) {
            // Tragado a propósito: sin clave la cadena no verifica (falla cerrado). Solo la clase.
            Log::warning('outreach.strategy_chain_key_unavailable', ['error_class' => class_basename($e)]);

            return null;
        }
    }

    /** @return list<string> */
    private static function columns(): array
    {
        return ['id', 'uuid', 'seq', 'prev_hash', 'action', 'strategy_id', 'version_id', 'effective_json', 'effective_hash', 'signature', 'acted_by_person_id'];
    }
}
