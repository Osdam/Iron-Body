<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\ConditionCanonicalizer;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use Closure;

/**
 * LA VALIDACIÓN DE UNA ESTRATEGIA (contrato H9, D9-5, D9-6, D9-11.0, D9-11.4,
 * D9-12 «Hallazgos», T11, T18; P9-10). PURA respecto de lo que lee: todo lo de
 * fuera entra por {@see context()} como datos o `Closure` (la foto de los
 * dominios dinámicos, el registro de plantillas y la versión del Studio
 * referenciada), así que la misma entrada da siempre la misma salida.
 *
 * DOS CAPAS, todas las claves a la vez:
 *  1. ESTRUCTURA (`errors`): el lector estricto del núcleo
 *     (`StrategyContent::fromArray()`: gramática, catálogo, límites, horario,
 *     plantillas `{uuid, hash}` por canal, `studio`). Nunca SQL, expresiones
 *     regulares ni código: lo que no está en el catálogo es un error (T11), y
 *     nada que no haya pasado la validación se evalúa.
 *  2. GOBIERNO (`blocking` y `warnings`), con su clave en el contenido:
 *     - plantillas (D9-6): que exista, APROBADA, con la MISMA huella, del
 *       canal del paso, de la finalidad de la estrategia y con la categoría de
 *       esa finalidad (cobranza y servicio: `utility`; comercial:
 *       `marketing`); su texto sin restricciones R-01/02/03/06 (N3
 *       `template_violates_restriction`);
 *     - la referencia del Studio (N3, D9-11.4): una versión del Studio en
 *       APPROVED, CANARY o PUBLISHED con su huella, o la línea base
 *       (`LEGACY_BASELINE_V1`) mientras no haya ninguna aprobada (P9-10); un
 *       paso con plantilla de enlace de pago y `rules.paymentLinksEnabled =
 *       false` es `payment_links_disabled`; `governance.canaryOnly` es un
 *       AVISO (`canary_only_declared`: la estrategia queda en su alcance canario);
 *     - avisos de canal: `whatsapp_template` y `voice_call` todavía no salen
 *       solos (W9/V9): `channel_not_live` (se simulan, L9-16).
 *
 * El resultado es el que enseña `POST strategies/{uuid}/validate` (200) y el
 * que congela una versión (`validation_json`): con un error o un bloqueante,
 * congelar es 422 con todas las claves ({@see invalid()}).
 */
final class StudioStrategyValidator
{
    public const LEVEL_BLOCKING = 'blocking';

    public const LEVEL_WARNING = 'warning';

    public const FORMAT = 'ultron-strategy-validation/1';

    // ── Hallazgos de gobierno (los de N3 usan el código de la escalera) ─────

    public const TEMPLATE_NOT_FOUND = 'template_not_found';

    public const TEMPLATE_NOT_APPROVED = 'template_not_approved';

    public const TEMPLATE_HASH_MISMATCH = 'template_hash_mismatch';

    public const TEMPLATE_CHANNEL_MISMATCH = 'template_channel_mismatch';

    public const TEMPLATE_PURPOSE_MISMATCH = 'template_purpose_mismatch';

    public const TEMPLATE_CATEGORY_MISMATCH = 'template_category_mismatch';

    public const TEMPLATE_VIOLATES_RESTRICTION = 'template_violates_restriction';

    public const STUDIO_REFERENCE_INVALID = 'studio_reference_invalid';

    public const STUDIO_BASELINE_SUPERSEDED = 'studio_baseline_superseded';

    public const PAYMENT_LINKS_DISABLED = 'payment_links_disabled';

    public const CANARY_ONLY_DECLARED = 'canary_only_declared';

    public const CHANNEL_NOT_LIVE = 'channel_not_live';

    public const PURPOSE_IMMUTABLE = 'purpose_immutable';

    public const MESSAGES = [
        self::TEMPLATE_NOT_FOUND => 'La plantilla no existe.',
        self::TEMPLATE_NOT_APPROVED => 'La plantilla no está aprobada.',
        self::TEMPLATE_HASH_MISMATCH => 'La huella no es la de la plantilla aprobada (¿una versión distinta?).',
        self::TEMPLATE_CHANNEL_MISMATCH => 'La plantilla no es de este canal.',
        self::TEMPLATE_PURPOSE_MISMATCH => 'La plantilla es de otra finalidad.',
        self::TEMPLATE_CATEGORY_MISMATCH => 'La categoría de la plantilla no corresponde a la finalidad.',
        self::TEMPLATE_VIOLATES_RESTRICTION => 'El texto de la plantilla incumple una restricción del código (R-01, R-02, R-03 o R-06).',
        self::STUDIO_REFERENCE_INVALID => 'La versión comercial del Studio referenciada no existe, no está aprobada o su huella no cuadra.',
        self::STUDIO_BASELINE_SUPERSEDED => 'Ya hay una versión del Studio aprobada: las versiones nuevas la referencian a ella y no a la línea base.',
        self::PAYMENT_LINKS_DISABLED => 'Un paso usa una plantilla con enlace de pago y la versión comercial referenciada tiene los enlaces de pago apagados.',
        self::CANARY_ONLY_DECLARED => 'La versión comercial referenciada declara «solo canario»: la estrategia queda limitada a su alcance canario.',
        self::CHANNEL_NOT_LIVE => 'Este canal todavía no sale solo hacia las personas (W9/V9): se simula.',
        self::PURPOSE_IMMUTABLE => 'La finalidad de una estrategia no cambia.',
    ];

    /**
     * El contexto de la validación leído de la base: la foto de los dominios
     * dinámicos (L9-13), el registro de plantillas y las versiones del Studio.
     * Solo lecturas.
     *
     * @return array{dynamicDomains: array<string, list<int|string>>, template: Closure(string):(array<string,mixed>|null), studio: Closure(array<string,string>):array{valid:bool,reason:string|null,paymentLinksEnabled:bool,canaryOnly:bool}, approvedStudioVersionExists: bool}
     */
    public static function context(): array
    {
        return [
            'dynamicDomains' => (new CatalogDomains)->read(),
            'template' => static function (string $uuid): ?array {
                $t = StudioMessageTemplate::query()->where('uuid', strtolower($uuid))->first();
                if ($t === null) {
                    return null;
                }
                $contenido = json_decode((string) $t->content_json, true);

                return [
                    'uuid' => (string) $t->uuid,
                    'status' => (string) $t->status,
                    'hash' => (string) $t->content_hash,
                    'integrity' => hash_equals(hash('sha256', (string) $t->content_json), (string) $t->content_hash),
                    'channel' => (string) $t->channel,
                    'purpose' => (string) $t->purpose,
                    'content' => is_array($contenido) ? $contenido : [],
                ];
            },
            'studio' => static fn (array $ref): array => self::studioReference($ref),
            'approvedStudioVersionExists' => StudioVersion::query()->whereIn('status', self::STUDIO_REFERENCEABLE)->exists(),
        ];
    }

    /** Los estados de una versión del Studio que se pueden referenciar al congelar (D9-11.4, como E1-7 del H8). */
    public const STUDIO_REFERENCEABLE = ['APPROVED', 'CANARY', 'PUBLISHED'];

    /**
     * La referencia del Studio: ¿es íntegra y referenciable?, y lo que declara
     * (N3). Al CONGELAR se exige el estado; al PUBLICAR solo la integridad
     * (`$checkStatus = false`: sin retroactividad, D9-11.4).
     *
     * @param  array<string,string>  $ref  `{kind: version, uuid, contentHash}` o `{kind: baseline, catalogVersion, catalogHash}`
     * @return array{valid:bool,reason:string|null,paymentLinksEnabled:bool,canaryOnly:bool}
     */
    public static function studioReference(array $ref, bool $checkStatus = true): array
    {
        $no = static fn (string $reason): array => ['valid' => false, 'reason' => $reason, 'paymentLinksEnabled' => false, 'canaryOnly' => true];
        if (($ref['kind'] ?? null) === StudioStrategyVersion::STUDIO_REF_BASELINE) {
            if (($ref['catalogVersion'] ?? null) !== StudioCatalog::VERSION || ! hash_equals(StudioCatalog::hash(), (string) ($ref['catalogHash'] ?? ''))) {
                return $no('baseline_mismatch');
            }
            $base = StudioCatalog::baseline()['configs'] ?? [];

            return [
                'valid' => true, 'reason' => null,
                'paymentLinksEnabled' => ($base['rules']['paymentLinksEnabled'] ?? false) === true,
                'canaryOnly' => ($base['governance']['canaryOnly'] ?? true) !== false,
            ];
        }
        if (($ref['kind'] ?? null) !== StudioStrategyVersion::STUDIO_REF_VERSION || ! is_string($ref['uuid'] ?? null)) {
            return $no('malformed');
        }
        $v = StudioVersion::query()->where('uuid', strtolower($ref['uuid']))->first(['id', 'uuid', 'status', 'content', 'content_hash']);
        if ($v === null) {
            return $no('version_not_found');
        }
        if (! hash_equals((string) $v->content_hash, (string) ($ref['contentHash'] ?? ''))) {
            return $no('content_hash_mismatch');
        }
        if ($checkStatus && ! in_array((string) $v->status, self::STUDIO_REFERENCEABLE, true)) {
            return $no('version_not_approved');
        }
        $configs = is_array($v->content) ? ($v->content['configs'] ?? []) : [];

        return [
            'valid' => true, 'reason' => null,
            'paymentLinksEnabled' => ($configs['rules']['paymentLinksEnabled'] ?? false) === true,
            'canaryOnly' => ($configs['governance']['canaryOnly'] ?? true) !== false,
        ];
    }

    /**
     * Valida un contenido de estrategia.
     *
     * @param  array{dynamicDomains: array<string, list<int|string>>, template: Closure(string):(array<string,mixed>|null), studio: Closure(array<string,string>):array{valid:bool,reason:string|null,paymentLinksEnabled:bool,canaryOnly:bool}, approvedStudioVersionExists: bool}  $context
     * @param  string|null  $purpose  la finalidad de la estrategia (inmutable): el contenido tiene que declarar la misma
     * @return array{format:string,valid:bool,canonical:array<string,mixed>|null,json:string|null,contentHash:string|null,errors:list<array{key:string,code:string,message:string}>,blocking:list<array{id:string,code:string,key:string,level:string,message:string}>,warnings:list<array{id:string,code:string,key:string,level:string,message:string}>,catalogVersion:string,studioRef:array<string,string>|null,templates:array<string,array{usesPaymentLink:bool,violations:list<string>}>}
     */
    public static function validate(mixed $content, array $context, ?string $purpose = null): array
    {
        $r = StrategyContent::fromArray($content, $context['dynamicDomains']);
        $errores = $r['errors'];
        if ($purpose !== null && is_array($content) && ($content['purpose'] ?? null) !== $purpose && ! self::hasErrorAt($errores, StrategyContent::ROOT.'.purpose')) {
            $errores[] = ['key' => StrategyContent::ROOT.'.purpose', 'code' => self::PURPOSE_IMMUTABLE];
        }
        $salida = [
            'format' => self::FORMAT,
            'valid' => false,
            'canonical' => null,
            'json' => null,
            'contentHash' => null,
            'errors' => array_map(static fn (array $e): array => $e + ['message' => self::message($e['code'])], ConditionCanonicalizer::sortErrors($errores)),
            'blocking' => [],
            'warnings' => [],
            'catalogVersion' => OutreachCatalog::VERSION,
            'studioRef' => null,
            'templates' => [],
        ];
        if ($r['canonical'] === null || $errores !== []) {
            return $salida;
        }
        $c = $r['canonical'];
        $hallazgos = [];
        $hallazgo = static function (string $code, string $key, string $level) use (&$hallazgos): void {
            $hallazgos["{$code}@{$key}"] = ['id' => "{$code}@{$key}", 'code' => $code, 'key' => $key, 'level' => $level, 'message' => self::MESSAGES[$code] ?? $code];
        };

        // La referencia del Studio (N3).
        $studio = ($context['studio'])($c['studio']);
        if (! $studio['valid']) {
            $hallazgo(self::STUDIO_REFERENCE_INVALID, 'content.studio', self::LEVEL_BLOCKING);
        } elseif ($c['studio']['kind'] === StudioStrategyVersion::STUDIO_REF_BASELINE && $context['approvedStudioVersionExists']) {
            $hallazgo(self::STUDIO_BASELINE_SUPERSEDED, 'content.studio', self::LEVEL_BLOCKING);
        }
        if ($studio['valid'] && $studio['canaryOnly']) {
            $hallazgo(self::CANARY_ONLY_DECLARED, 'content.studio', self::LEVEL_WARNING);
        }

        // Las plantillas de cada paso.
        $usoPlantillas = [];
        foreach ($c['sequence'] as $i => $paso) {
            foreach ($paso['channels'] as $canal) {
                $clave = "content.sequence.{$i}.templates.{$canal}";
                if (in_array($canal, OutreachCatalog::AUTOMATIC_CHANNELS, true)) {
                    $hallazgo(self::CHANNEL_NOT_LIVE, "content.sequence.{$i}.channels", self::LEVEL_WARNING);
                }
                $ref = $paso['templates'][$canal];
                $t = ($context['template'])($ref['uuid']);
                if ($t === null) {
                    $hallazgo(self::TEMPLATE_NOT_FOUND, $clave, self::LEVEL_BLOCKING);

                    continue;
                }
                if (! $t['integrity'] || ! hash_equals($t['hash'], $ref['hash'])) {
                    $hallazgo(self::TEMPLATE_HASH_MISMATCH, $clave, self::LEVEL_BLOCKING);
                }
                if ($t['status'] !== StudioMessageTemplate::STATUS_APPROVED) {
                    $hallazgo(self::TEMPLATE_NOT_APPROVED, $clave, self::LEVEL_BLOCKING);
                }
                if (! StudioMessageTemplates::fitsStepChannel($canal, $t['channel'], $t['content'])) {
                    $hallazgo(self::TEMPLATE_CHANNEL_MISMATCH, $clave, self::LEVEL_BLOCKING);
                }
                if ($t['purpose'] !== $c['purpose']) {
                    $hallazgo(self::TEMPLATE_PURPOSE_MISMATCH, $clave, self::LEVEL_BLOCKING);
                }
                if ($t['channel'] === StudioMessageTemplates::CHANNEL_WHATSAPP && ($t['content']['category'] ?? null) !== StudioMessageTemplates::CATEGORY_BY_PURPOSE[$c['purpose']]) {
                    $hallazgo(self::TEMPLATE_CATEGORY_MISMATCH, $clave, self::LEVEL_BLOCKING);
                }
                $violaciones = StudioMessageTemplates::violationsOf($t['content']);
                if ($violaciones !== []) {
                    $hallazgo(self::TEMPLATE_VIOLATES_RESTRICTION, $clave, self::LEVEL_BLOCKING);
                }
                $pago = StudioMessageTemplates::usesPaymentLink($t['content']);
                if ($pago && (! $studio['valid'] || ! $studio['paymentLinksEnabled'])) {
                    $hallazgo(self::PAYMENT_LINKS_DISABLED, $clave, self::LEVEL_BLOCKING);
                }
                $usoPlantillas[$ref['uuid']] = ['usesPaymentLink' => $pago, 'violations' => $violaciones];
            }
        }
        ksort($hallazgos, SORT_STRING);
        $bloqueantes = array_values(array_filter($hallazgos, static fn (array $h): bool => $h['level'] === self::LEVEL_BLOCKING));
        $avisos = array_values(array_filter($hallazgos, static fn (array $h): bool => $h['level'] === self::LEVEL_WARNING));
        ksort($usoPlantillas, SORT_STRING);

        return [
            'valid' => $bloqueantes === [],
            'canonical' => $c,
            'json' => $r['json'],
            'contentHash' => $r['contentHash'],
            'blocking' => $bloqueantes,
            'warnings' => $avisos,
            'studioRef' => $c['studio'],
            'templates' => $usoPlantillas,
        ] + $salida;
    }

    /**
     * Lo que se guarda en `validation_json` y se enseña: sin el contenido (ya
     * está en la versión), con los ids de los avisos que hay que reconocer.
     *
     * @param  array<string,mixed>  $r  la salida de {@see validate()}
     * @return array<string,mixed>
     */
    public static function record(array $r): array
    {
        return [
            'format' => self::FORMAT,
            'valid' => $r['valid'],
            'contentHash' => $r['contentHash'],
            'catalogVersion' => $r['catalogVersion'],
            'errors' => $r['errors'],
            'blocking' => $r['blocking'],
            'warnings' => $r['warnings'],
            'warningIds' => self::warningIds($r),
        ];
    }

    /**
     * Los ids de los avisos (lo que una reseña reconoce EXACTAMENTE), ordenados.
     *
     * @param  array<string,mixed>  $r
     * @return list<string>
     */
    public static function warningIds(array $r): array
    {
        $ids = array_map(static fn (array $w): string => (string) $w['id'], $r['warnings'] ?? []);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * El 422 con TODAS las claves a la vez: errores de estructura y
     * bloqueantes de gobierno, cada uno en su clave (T11).
     *
     * @param  array<string,mixed>  $r
     */
    public static function invalid(array $r): UltronStudioException
    {
        $mensajes = [];
        foreach ([...$r['errors'], ...$r['blocking']] as $e) {
            if (! in_array($e['message'], $mensajes[$e['key']] ?? [], true)) {
                $mensajes[$e['key']][] = $e['message'];
            }
        }
        ksort($mensajes, SORT_STRING);

        return new UltronStudioException('La estrategia no pasa la validación: corrige los campos marcados.', 'strategy_invalid', 422, [
            'errors' => $mensajes,
            'findings' => array_values([...array_map(static fn (array $e): array => ['code' => $e['code'], 'key' => $e['key'], 'level' => 'error'], $r['errors']), ...array_map(static fn (array $b): array => ['code' => $b['code'], 'key' => $b['key'], 'level' => self::LEVEL_BLOCKING], $r['blocking'])]),
        ]);
    }

    /**
     * Las condiciones canónicas de un contenido ya validado (el evaluador solo
     * acepta {@see CanonicalCondition}: T11).
     *
     * @param  array<string,mixed>  $canonical
     * @param  array<string, list<int|string>>  $dynamicDomains
     * @return array{audience: CanonicalCondition, exclusions: CanonicalCondition|null, success: CanonicalCondition, guards: array<int, CanonicalCondition|null>}
     */
    public static function conditions(array $canonical, array $dynamicDomains): array
    {
        $guardas = [];
        foreach ($canonical['sequence'] as $i => $paso) {
            $guardas[$i] = isset($paso['guard']) ? CanonicalCondition::parse($paso['guard'], $dynamicDomains, "content.sequence.{$i}.guard") : null;
        }

        return [
            'audience' => CanonicalCondition::parse($canonical['audience'], $dynamicDomains, 'audience'),
            'exclusions' => isset($canonical['exclusions']) ? CanonicalCondition::parse($canonical['exclusions'], $dynamicDomains, 'exclusions') : null,
            'success' => CanonicalCondition::parse($canonical['success'], $dynamicDomains, 'success'),
            'guards' => $guardas,
        ];
    }

    /** El mensaje de un código de estructura (gramática del núcleo, contenido o los de aquí). */
    private static function message(string $code): string
    {
        return ConditionCanonicalizer::MESSAGES[$code] ?? self::MESSAGES[$code] ?? self::STRUCTURE_MESSAGES[$code] ?? 'Valor no admitido.';
    }

    /** Los códigos del contenido que no son de la gramática (`strategy-content-cases.json`, `errorCodes`). */
    private const STRUCTURE_MESSAGES = [
        'trigger_not_allowed' => 'Ese evento no puede disparar una estrategia.',
        'purpose_mismatch' => 'El evento no admite esta finalidad.',
        'constraint_violated' => 'Los parámetros del evento no son coherentes entre sí.',
        'fact_purpose_not_allowed' => 'Ese hecho no se puede usar con esta finalidad.',
        'invalid_format' => 'El formato no es válido.',
        'template_missing' => 'Falta la plantilla de este canal.',
        'transition_not_allowed' => 'Esa transición no está permitida para este resultado.',
        'schedule_outside_legal_window' => 'La franja queda fuera del horario legal.',
        'not_canonical' => 'El contenido no es canónico.',
        'too_large' => 'El contenido canónico pasa de 32 KB.',
    ];

    /** @param  list<array{key:string,code:string}>  $errores */
    private static function hasErrorAt(array $errores, string $key): bool
    {
        foreach ($errores as $e) {
            if ($e['key'] === $key) {
                return true;
            }
        }

        return false;
    }
}
