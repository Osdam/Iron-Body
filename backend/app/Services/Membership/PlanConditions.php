<?php

namespace App\Services\Membership;

use App\Models\Plan;
use App\Services\IronAiMembershipAccessService;

/**
 * LO QUE UN PLAN LE DA A QUIEN LO TIENE, dicho en un solo sitio y comparable.
 *
 * Existe para el traslado de socios entre planes: antes de cambiar a nadie hay
 * que poder enseñar, lado a lado, qué cambia para ellos —precio, reglas de
 * acceso, funciones de la app, IRON IA— y guardarlo tal cual en el lote, porque
 * el catálogo se edita y dentro de un mes «Élite» puede no incluir lo mismo.
 *
 * NO INTERPRETA NADA POR SU CUENTA. Cada dato sale del método que ya decide en
 * producción: las reglas de acceso de {@see PlanAccessRules}, las funciones de
 * {@see Plan::resolvedFeatures()}, las capacidades de IRON IA de
 * {@see IronAiMembershipAccessService::getAiCapabilities()}, lo vendible de
 * {@see Plan::isSellable()}. Si mañana cambia una de esas reglas, esto la
 * enseña cambiada sin tocarlo.
 *
 * Los importes viajan como TEXTO con dos decimales («180000.00»): un float
 * serializado distinto en PostgreSQL y en SQLite cambiaría la huella de la
 * simulación sin que nada hubiera cambiado.
 */
class PlanConditions
{
    /** Cómo se llama cada función de la app para quien lee la diferencia. */
    private const FEATURE_LABELS = [
        'iron_ia' => 'IRON IA en la app',
        'workouts' => 'Entrenamientos',
        'custom_routines' => 'Rutinas personalizadas',
        'ranking' => 'Ranking',
        'classes' => 'Clases (reservar desde la app y el mostrador)',
        'progress' => 'Progreso',
        'nutrition' => 'Nutrición',
    ];

    private const AI_LABELS = [
        'ai_enabled' => 'IRON IA',
        'ai_chat_enabled' => 'Chat de IRON IA',
        'monthly_messages_limit' => 'Mensajes de IRON IA al mes',
        'daily_messages_limit' => 'Mensajes de IRON IA al día',
        'fair_use_limit' => 'Tope de uso razonable de IRON IA',
        'max_output_tokens' => 'Largo de las respuestas de IRON IA',
        'context_level' => 'Contexto de IRON IA',
        'progress_analysis_enabled' => 'Análisis de progreso con IRON IA',
        'smart_recommendations_enabled' => 'Recomendaciones de IRON IA',
        'weekly_summary_enabled' => 'Resumen semanal de IRON IA',
        'proactive_notifications_enabled' => 'Avisos proactivos de IRON IA',
        'ai_voice_chat_enabled' => 'Voz con IRON IA',
        'ai_realtime_voice_enabled' => 'Voz en tiempo real con IRON IA',
        'ai_image_analysis_enabled' => 'Análisis de imágenes con IRON IA',
        'ai_file_upload_enabled' => 'Subir archivos a IRON IA',
        'ai_audio_monthly_limit' => 'Audios de IRON IA al mes',
        'ai_image_monthly_limit' => 'Imágenes de IRON IA al mes',
        'ai_max_audio_seconds' => 'Duración máxima de audio en IRON IA',
        'ai_max_image_size_mb' => 'Tamaño máximo de imagen en IRON IA',
    ];

    /**
     * Las capacidades de IRON IA que solo se aplican a quien NO tiene
     * membresía. Un socio trasladado tiene membresía, así que compararlas solo
     * metería ruido en la lista de diferencias.
     */
    private const AI_IGNORED = ['free_trial_messages'];

    public function __construct(private readonly IronAiMembershipAccessService $ai) {}

    /**
     * Las condiciones del plan, como se enseñan y como se guardan en el lote.
     *
     * @return array<string, mixed>
     */
    public function of(Plan $plan): array
    {
        $features = $plan->resolvedFeatures();
        ksort($features);

        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'active' => (bool) $plan->active,
            'sellable' => $plan->isSellable(),
            'visible_in_app' => $plan->isVisibleInApp(),
            'billable' => $plan->isBillable(),
            'price' => self::money($plan->price),
            'duration_days' => (int) $plan->duration_days,
            'tier' => $plan->tier,
            'pricing_mode' => $plan->pricing_mode,
            'tax_rate_id' => $plan->tax_rate_id !== null ? (int) $plan->tax_rate_id : null,
            'access' => $plan->accessRules()->toArray(),
            'features' => $features,
            // `access_classes` y `reservations_limit` se guardan en el plan
            // pero hoy no los aplica nadie: la única puerta de las clases es
            // la función `classes` (ClassBookingService::hasClassesFeature).
            // Se enseñan para que nadie crea que cuentan.
            'classes' => [
                'feature' => (bool) ($features['classes'] ?? false),
                'access_classes' => (bool) $plan->access_classes,
                'reservations_limit' => $plan->reservations_limit !== null ? (int) $plan->reservations_limit : null,
            ],
            'benefits' => $plan->benefitsArray(),
            // Lo que IRON IA le da a un socio CON membresía vigente en este
            // plan, resuelto por la misma clase que decide en la app.
            'ai' => $this->ai->getAiCapabilities([
                'active' => true,
                'plan_id' => (int) $plan->id,
                'plan_name' => (string) $plan->name,
                'plan' => $plan,
                'end_date' => null,
            ]),
        ];
    }

    /**
     * Qué cambia para el socio al pasar de un plan al otro.
     *
     * `kind` dice cómo lo vive el socio: `loss` pierde algo, `gain` gana algo,
     * `restriction` le ponen un límite que no tenía y `change` es un cambio que
     * no es ni lo uno ni lo otro (el precio de la próxima renovación).
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{key: string, label: string, from: mixed, to: mixed, kind: string}>
     */
    public function diff(array $from, array $to): array
    {
        $cambios = [];
        $add = function (string $key, string $label, mixed $antes, mixed $despues, string $kind) use (&$cambios): void {
            $cambios[] = ['key' => $key, 'label' => $label, 'from' => $antes, 'to' => $despues, 'kind' => $kind];
        };

        if ($from['price'] !== $to['price']) {
            $add('price', 'Precio de la próxima renovación', $from['price'], $to['price'], 'change');
        }
        if ($from['duration_days'] !== $to['duration_days']) {
            $add('duration_days', 'Duración de la próxima renovación (días)', $from['duration_days'], $to['duration_days'], 'change');
        }

        // ── Reglas de acceso ────────────────────────────────────────────────
        $a = $from['access'];
        $b = $to['access'];
        if ($a['mode'] !== $b['mode']) {
            $add('access.mode', 'Tipo de acceso', $a['mode_label'], $b['mode_label'],
                $b['mode'] === PlanAccessRules::MODE_ENTRIES ? 'restriction' : 'gain');
        } elseif ($a['entries'] !== $b['entries']) {
            $add('access.entries', 'Entradas por periodo', $a['entries'], $b['entries'],
                (int) $b['entries'] > (int) $a['entries'] ? 'gain' : 'loss');
        }
        // La etiqueta es null cuando la regla NO restringe: lo decide
        // PlanAccessRules, no esta clase.
        if ($a['days_label'] !== $b['days_label']) {
            $add('access.days', 'Días de acceso', $a['days_label'] ?? 'Todos los días', $b['days_label'] ?? 'Todos los días',
                $this->ruleKind($a['days_label'] !== null, $b['days_label'] !== null));
        }
        if ($a['hours_label'] !== $b['hours_label']) {
            $add('access.hours', 'Horario de acceso', $a['hours_label'] ?? 'A toda hora', $b['hours_label'] ?? 'A toda hora',
                $this->ruleKind($a['hours_label'] !== null, $b['hours_label'] !== null));
        }

        // ── Funciones de la app ─────────────────────────────────────────────
        $claves = array_unique(array_merge(array_keys($from['features']), array_keys($to['features'])));
        sort($claves);
        foreach ($claves as $clave) {
            $antes = (bool) ($from['features'][$clave] ?? false);
            $despues = (bool) ($to['features'][$clave] ?? false);
            if ($antes !== $despues) {
                $add('features.'.$clave, self::FEATURE_LABELS[$clave] ?? $clave, $antes, $despues, $despues ? 'gain' : 'loss');
            }
        }

        // ── IRON IA ─────────────────────────────────────────────────────────
        $claves = array_diff(
            array_unique(array_merge(array_keys($from['ai']), array_keys($to['ai']))),
            self::AI_IGNORED,
        );
        sort($claves);
        foreach ($claves as $clave) {
            $antes = $from['ai'][$clave] ?? null;
            $despues = $to['ai'][$clave] ?? null;
            if ($antes !== $despues) {
                $add('ai.'.$clave, self::AI_LABELS[$clave] ?? 'IRON IA · '.$clave, $antes, $despues, $this->valueKind($antes, $despues));
            }
        }

        // ── Lo comercial y lo fiscal: afecta a la próxima venta ─────────────
        if ($from['benefits'] !== $to['benefits']) {
            $add('benefits', 'Beneficios que se le enseñan', $from['benefits'], $to['benefits'], 'change');
        }
        if ($from['tier'] !== $to['tier']) {
            $add('tier', 'Segmento comercial', $from['tier'], $to['tier'], 'change');
        }
        if ($from['pricing_mode'] !== $to['pricing_mode']) {
            $add('pricing_mode', 'Cómo se interpreta el precio', $from['pricing_mode'], $to['pricing_mode'], 'change');
        }
        if ($from['tax_rate_id'] !== $to['tax_rate_id']) {
            $add('tax_rate_id', 'Tarifa de IVA', $from['tax_rate_id'], $to['tax_rate_id'], 'change');
        }

        return $cambios;
    }

    /**
     * Avisos sobre los PLANES, no sobre un socio concreto. Los de severidad
     * `warning` obligan a confirmar antes de ejecutar; los `info` solo
     * informan.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  list<array{key: string, kind: string}>  $diferencias
     * @return list<array{code: string, severity: string, message: string}>
     */
    public function warnings(array $from, array $to, array $diferencias): array
    {
        $avisos = [];
        $add = function (string $code, string $severity, string $message) use (&$avisos): void {
            $avisos[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
        };
        $tipos = fn (string $prefijo, string $kind): array => array_values(array_filter(
            $diferencias,
            fn (array $d): bool => str_starts_with($d['key'], $prefijo) && $d['kind'] === $kind,
        ));

        if ($from['price'] !== $to['price']) {
            $add('price_differs', 'warning', 'El precio cambia de $'.self::pesos($from['price']).' a $'.self::pesos($to['price']).
                '. Ahora no se cobra ni se devuelve nada: el precio nuevo se aplicará en la próxima renovación.');
        }
        if ($from['duration_days'] !== $to['duration_days']) {
            $add('duration_differs', 'info', 'La duración cambia de '.$from['duration_days'].' a '.$to['duration_days'].
                ' días. Las fechas de los socios no se tocan: la diferencia se nota en la próxima renovación.');
        }
        if ($perdidas = $tipos('features.', 'loss')) {
            $add('features_lost', 'warning', 'Pierden en la app: '.implode(', ', array_column($perdidas, 'label')).'.');
        }
        if ($ganadas = $tipos('features.', 'gain')) {
            $add('features_gained', 'info', 'Ganan en la app: '.implode(', ', array_column($ganadas, 'label')).'.');
        }
        if (array_filter($diferencias, fn (array $d): bool => str_starts_with($d['key'], 'ai.')) !== []) {
            $add('ai_differs', 'warning', 'IRON IA no les da lo mismo en el plan destino. Revisa la tabla de diferencias.');
        }
        if ($this->accessRules($from) !== $this->accessRules($to)) {
            $add('access_rules_differ', 'warning', 'Las reglas de entrada al gimnasio cambian desde el momento del traslado: '.
                'tipo de acceso, días u horario.');
        }
        if ($from['tax_rate_id'] !== $to['tax_rate_id'] || $from['pricing_mode'] !== $to['pricing_mode']) {
            $add('tax_differs', 'warning', 'El tratamiento de IVA del plan destino es distinto. No afecta a lo ya facturado; '.
                'sí a la próxima renovación.');
        }
        if (! $to['sellable']) {
            $add('to_plan_not_sellable', 'warning', 'El plan destino no se puede vender hoy: al renovar, el mostrador no lo encontrará.');
        }
        if (! $to['visible_in_app']) {
            $add('to_plan_hidden_in_app', 'warning', 'El plan destino no se enseña en la app: no podrán renovarlo desde el teléfono.');
        }
        if (! $to['billable']) {
            $add('to_plan_not_billable', 'warning', 'El plan destino no genera factura electrónica: sus renovaciones no se facturarán.');
        }
        if ($from['sellable'] || $from['visible_in_app']) {
            $add('from_plan_still_sellable', 'warning', 'El plan origen se sigue vendiendo'.
                ($from['visible_in_app'] ? ' y se ve en la app' : '').
                '. Si a alguno de estos socios se le cobra ese plan después, volverá a él. '.
                'Conviene ocultarlo y dejar de venderlo antes de trasladar.');
        }

        return $avisos;
    }

    /** «180000.00», igual en los dos motores. */
    public static function money(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /** «180.000», como se dice en el mostrador. */
    private static function pesos(string $valor): string
    {
        return number_format((float) $valor, 0, ',', '.');
    }

    /**
     * Las reglas que deciden en la puerta, sin el nombre de las franjas: dos
     * planes con las mismas horas son iguales aunque una franja se llame
     * «mañana» y la otra no tenga nombre.
     *
     * @param  array<string, mixed>  $condiciones
     * @return array<string, mixed>
     */
    private function accessRules(array $condiciones): array
    {
        $a = $condiciones['access'];

        return [
            'mode' => $a['mode'],
            'entries' => $a['entries'],
            'days' => $a['days_label'],
            'hours' => $a['hours_label'],
        ];
    }

    private function ruleKind(bool $antesRestringe, bool $despuesRestringe): string
    {
        if ($despuesRestringe && ! $antesRestringe) {
            return 'restriction';
        }
        if ($antesRestringe && ! $despuesRestringe) {
            return 'gain';
        }

        return 'change';
    }

    /**
     * Para un valor de IRON IA: un interruptor que se apaga es una pérdida;
     * un límite numérico que baja también; null es «sin límite».
     */
    private function valueKind(mixed $antes, mixed $despues): string
    {
        if (is_bool($antes) || is_bool($despues)) {
            return (bool) $despues ? 'gain' : 'loss';
        }
        if ($antes === null && is_numeric($despues)) {
            return 'restriction';
        }
        if (is_numeric($antes) && $despues === null) {
            return 'gain';
        }
        if (is_numeric($antes) && is_numeric($despues)) {
            return (float) $despues > (float) $antes ? 'gain' : 'loss';
        }

        return 'change';
    }
}
