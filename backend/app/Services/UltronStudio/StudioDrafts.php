<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * El borrador de cada persona (contrato §A1 y §A3, rutas 3–5).
 *
 * UNO POR PERSONA, garantizado por la base (UNIQUE `admin_id`). El primer
 * guardado (`baseRevision = 0`) inserta dentro de un SAVEPOINT: si otra pestaña
 * se adelantó, el INSERT choca con la unicidad, se deshace solo el savepoint y
 * la transacción sigue viva para leer lo que guardó la otra (sin él, PostgreSQL
 * la dejaría abortada: 25P02). La respuesta es 409 `draft_conflict` con
 * `current`.
 *
 * CONCURRENCIA OPTIMISTA en los demás guardados: `UPDATE … WHERE admin_id = ?
 * AND revision = ?`. Si no cambia ninguna fila, otra petición guardó antes, y
 * también es 409 con `current`. Sin `FOR UPDATE`: dos guardados a la vez dan un
 * 200 y un 409, nunca un interbloqueo ni una escritura perdida.
 *
 * El contenido llega validado por {@see StudioDraftNormalizer} (422 con claves
 * `content.*`) y se guarda en su forma canónica; el cliente adopta lo que
 * devuelve esto. Un borrador PUEDE guardarse con bloqueos de validación: se
 * enseñan en `validation`, y lo que no se puede es crear una versión con ellos.
 *
 * Nada se borra: descartar vuelve a la línea base y sube la revisión.
 *
 * HITO G (contrato G §A3 y §A4). La forma de GET draft gana `uuid` (null sin
 * fila), con el que el CRM pide el historial del borrador a `GET audit`. Y la
 * validación añade AL FINAL de los hallazgos de F los de las reglas del Studio,
 * evaluadas con las reglas VIVAS: las mismas que copiará la versión.
 */
final class StudioDrafts
{
    /** @var array<string, mixed>|null */
    private static ?array $baseline = null;

    public function __construct(private readonly StudioAudit $audit) {}

    /**
     * El contenido de `LEGACY_BASELINE_V1` en forma canónica: lo que ve quien
     * todavía no ha guardado nada, y a lo que vuelve un descarte.
     *
     * @return array{definition:array{layers:list<string>,edgeIds:list<string>},configs:array<string,array<string,mixed>>,layout:array{positions:array<string,array{x:int,y:int}>,viewport:null}}
     */
    public static function baselineContent(): array
    {
        if (self::$baseline === null) {
            $b = StudioCatalog::baseline();
            self::$baseline = StudioDraftNormalizer::normalize([
                'definition' => ['edgeIds' => $b['edgeIds']],
                'configs' => $b['configs'],
                'layout' => ['positions' => $b['positions'], 'viewport' => null],
            ]);
        }

        return self::$baseline;
    }

    /** GET draft: el de quien mira, o la línea base con revisión 0. */
    public function show(StudioAccess $access): array
    {
        return self::present($this->find($access));
    }

    /**
     * PUT draft.
     *
     * @param  array<array-key, mixed>  $content  tal como llegó (sin normalizar)
     * @return array<string, mixed> la forma de GET draft
     *
     * @throws ValidationException 422 con claves `content.*`
     * @throws UltronStudioException 409 `draft_conflict`
     */
    public function save(StudioAccess $access, Request $request, int $baseRevision, array $content): array
    {
        $access->require(StudioAccess::EDIT_DRAFT);

        $normalizado = StudioDraftNormalizer::normalize($content);
        $hash = StudioContentHasher::draftHash(StudioCatalog::VERSION, $normalizado);

        if ($baseRevision === 0) {
            return $this->firstSave($access, $request, $normalizado, $hash);
        }

        return DB::transaction(function () use ($access, $request, $baseRevision, $normalizado, $hash): array {
            $actual = $this->find($access);
            if ($actual === null || $actual->revision !== $baseRevision) {
                throw UltronStudioException::draftConflict(self::present($actual));
            }

            $this->conditionalWrite($access, $baseRevision, $normalizado, $hash);
            $nuevo = $this->find($access);

            $this->audit->record($request, $access->actor, 'draft.saved', StudioAuditEvent::SUBJECT_DRAFT, $nuevo->uuid,
                "Guardó el borrador (revisión {$nuevo->revision})", [
                    'contentHash' => $hash,
                    'diff' => ['fromRevision' => $baseRevision, 'toRevision' => $nuevo->revision]
                        + self::changes($actual->content, $normalizado),
                ]);

            return self::present($nuevo);
        });
    }

    /**
     * POST draft/discard: vuelve a la línea base. NO borra la fila.
     *
     * Sin borrador guardado y con `baseRevision = 0` no hay nada que descartar:
     * se devuelve la línea base sin escribir nada.
     *
     * @return array<string, mixed> la forma de GET draft
     */
    public function discard(StudioAccess $access, Request $request, int $baseRevision): array
    {
        $access->require(StudioAccess::EDIT_DRAFT);

        return DB::transaction(function () use ($access, $request, $baseRevision): array {
            $actual = $this->find($access);
            if ($actual === null && $baseRevision === 0) {
                return self::present(null);
            }
            if ($actual === null || $actual->revision !== $baseRevision) {
                throw UltronStudioException::draftConflict(self::present($actual));
            }

            $base = self::baselineContent();
            $hash = StudioContentHasher::draftHash(StudioCatalog::VERSION, $base);
            $this->conditionalWrite($access, $baseRevision, $base, $hash);
            $nuevo = $this->find($access);

            // Lo descartado respecto a la línea base: con esto se puede
            // reconstruir (posiciones y vista incluidas). La huella del evento
            // es la del contenido DESCARTADO, no la de la línea base.
            $this->audit->record($request, $access->actor, 'draft.discarded', StudioAuditEvent::SUBJECT_DRAFT, $nuevo->uuid,
                "Descartó el borrador y volvió a la línea base (revisión {$nuevo->revision})", [
                    'contentHash' => $actual->content_hash,
                    'diff' => ['fromRevision' => $baseRevision, 'toRevision' => $nuevo->revision]
                        + self::changes($base, $actual->content, detailedLayout: true),
                ]);

            return self::present($nuevo);
        });
    }

    /** El borrador guardado de la persona, si lo tiene. */
    public function find(StudioAccess $access): ?StudioDraft
    {
        return StudioDraft::query()->where('admin_id', $access->id())->first();
    }

    /**
     * La forma de GET draft (también la de `current` en un 409).
     *
     * @return array<string, mixed>
     */
    public static function present(?StudioDraft $draft): array
    {
        if ($draft === null) {
            $content = self::baselineContent();

            return [
                'exists' => false,
                'isBaseline' => true,
                'uuid' => null,
                'revision' => 0,
                'savedAt' => null,
                'catalogVersion' => StudioCatalog::VERSION,
                'contentHash' => StudioContentHasher::draftHash(StudioCatalog::VERSION, $content),
                'content' => self::output($content),
                'validation' => self::validation($content),
            ];
        }

        $content = $draft->content;

        return [
            'exists' => true,
            'isBaseline' => self::isBaseline($content),
            'uuid' => $draft->uuid,
            'revision' => $draft->revision,
            'savedAt' => $draft->updated_at?->toIso8601String(),
            'catalogVersion' => $draft->catalog_version,
            'contentHash' => $draft->content_hash,
            'content' => self::output($content),
            'validation' => self::validation($content),
        ];
    }

    /**
     * La validación del servidor contra el catálogo ACTUAL. Manda sobre la del
     * navegador si discrepan. Sin `$rules`, las reglas son las vivas
     * ({@see StudioRules::snapshotForVersion()}).
     *
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>|null  $rules
     * @return array{catalogVersion:string,publishable:bool,warnings:list<string>,findings:list<array<string,mixed>>}
     */
    public static function validation(array $content, ?array $rules = null): array
    {
        $findings = self::findings($content, $rules ?? StudioRules::snapshotForVersion());

        return [
            'catalogVersion' => StudioCatalog::VERSION,
            'publishable' => StudioGraphValidator::isPublishable($findings),
            'warnings' => StudioGraphValidator::warnings($findings),
            'findings' => $findings,
        ];
    }

    /**
     * Los hallazgos de un borrador: los de F y, al final, los de las reglas del
     * Studio de `$rules` (formato 2: un borrador siempre es del último).
     *
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private static function findings(array $content, array $rules): array
    {
        return [
            ...StudioGraphValidator::validate(StudioCatalog::graph(), $content['definition'], $content['configs']),
            ...StudioRuleCoherence::evaluate($rules, $content['definition'], $content['configs'], StudioCatalog::toArray(), StudioContentHasher::LATEST_FORMAT),
        ];
    }

    /**
     * El contenido tal como viaja: `configs`, cada capa y `positions` son
     * OBJETOS en JSON aunque estén vacíos (PHP no distingue `{}` de `[]`).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function output(array $content): array
    {
        $configs = [];
        foreach ($content['configs'] ?? [] as $capa => $valores) {
            $configs[$capa] = is_array($valores) && $valores !== [] ? $valores : new stdClass;
        }

        return [
            'definition' => $content['definition'],
            'configs' => $configs !== [] ? $configs : new stdClass,
            'layout' => [
                'positions' => ($content['layout']['positions'] ?? []) !== [] ? $content['layout']['positions'] : new stdClass,
                'viewport' => $content['layout']['viewport'] ?? null,
            ],
        ];
    }

    /**
     * ¿Es exactamente la línea base del código, disposición incluida? Las dos
     * formas son canónicas (salen del normalizador, en el orden del catálogo),
     * así que basta comparar su codificación.
     *
     * @param  array<string, mixed>  $content
     */
    public static function isBaseline(array $content): bool
    {
        return json_encode($content) === json_encode(self::baselineContent());
    }

    /**
     * Qué cambió de `$from` a `$to`, con valores recortados por la auditoría.
     *
     * Con `$detailedLayout`, en lugar de dos booleanos lleva las posiciones que
     * difieren y la vista: es lo que hace falta para reconstruir un descarte.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    public static function changes(array $from, array $to, bool $detailedLayout = false): array
    {
        $antes = $from['definition']['edgeIds'] ?? [];
        $despues = $to['definition']['edgeIds'] ?? [];

        $cambios = [];
        $capas = array_keys(($to['configs'] ?? []) + ($from['configs'] ?? []));
        foreach ($capas as $capa) {
            $viejos = is_array($from['configs'][$capa] ?? null) ? $from['configs'][$capa] : [];
            $nuevos = is_array($to['configs'][$capa] ?? null) ? $to['configs'][$capa] : [];
            foreach (array_keys($nuevos + $viejos) as $clave) {
                $de = $viejos[$clave] ?? null;
                $a = $nuevos[$clave] ?? null;
                if (json_encode($de) !== json_encode($a)) {
                    $cambios[] = ['layer' => (string) $capa, 'key' => (string) $clave, 'from' => $de, 'to' => $a];
                }
            }
        }

        $diff = [
            'edgesAdded' => array_values(array_diff($despues, $antes)),
            'edgesRemoved' => array_values(array_diff($antes, $despues)),
            'configChanges' => $cambios,
        ];

        $posAntes = $from['layout']['positions'] ?? [];
        $posDespues = $to['layout']['positions'] ?? [];
        $vistaAntes = $from['layout']['viewport'] ?? null;
        $vistaDespues = $to['layout']['viewport'] ?? null;

        if (! $detailedLayout) {
            return $diff + [
                'positionsChanged' => json_encode($posAntes) !== json_encode($posDespues),
                'viewportChanged' => json_encode($vistaAntes) !== json_encode($vistaDespues),
            ];
        }

        $posiciones = [];
        foreach ($posDespues as $capa => $pos) {
            if (json_encode($posAntes[$capa] ?? null) !== json_encode($pos)) {
                $posiciones[$capa] = $pos;
            }
        }

        return $diff + ['positions' => $posiciones, 'viewport' => $vistaDespues];
    }

    /**
     * El primer guardado: INSERT dentro de un SAVEPOINT (ver la cabecera).
     *
     * @param  array<string, mixed>  $normalizado
     * @return array<string, mixed>
     */
    private function firstSave(StudioAccess $access, Request $request, array $normalizado, string $hash): array
    {
        return DB::transaction(function () use ($access, $request, $normalizado, $hash): array {
            try {
                $draft = DB::transaction(fn (): StudioDraft => StudioDraft::query()->forceCreate([
                    'admin_id' => $access->id(),
                    'revision' => 1,
                    'catalog_version' => StudioCatalog::VERSION,
                    'content' => $normalizado,
                    'content_hash' => $hash,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Se deshizo solo el savepoint: la transacción sigue viva y se
                // puede leer lo que guardó la otra pestaña.
                throw UltronStudioException::draftConflict(self::present($this->find($access)));
            }

            $this->audit->record($request, $access->actor, 'draft.saved', StudioAuditEvent::SUBJECT_DRAFT, $draft->uuid,
                'Guardó el borrador por primera vez (revisión 1)', [
                    'contentHash' => $hash,
                    'diff' => ['fromRevision' => 0, 'toRevision' => 1] + self::changes(self::baselineContent(), $normalizado),
                ]);

            return self::present($draft->refresh());
        });
    }

    /**
     * `UPDATE … WHERE admin_id = ? AND revision = ?`. Cero filas es que otra
     * petición guardó antes: 409 con lo que hay ahora.
     *
     * @param  array<string, mixed>  $content
     */
    private function conditionalWrite(StudioAccess $access, int $baseRevision, array $content, string $hash): void
    {
        $filas = StudioDraft::query()
            ->where('admin_id', $access->id())
            ->where('revision', $baseRevision)
            ->update([
                'revision' => $baseRevision + 1,
                'catalog_version' => StudioCatalog::VERSION,
                // El UPDATE del constructor no aplica los casts del modelo.
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'content_hash' => $hash,
            ]);

        if ($filas !== 1) {
            throw UltronStudioException::draftConflict(self::present($this->find($access)));
        }
    }
}
