<?php

namespace App\Services;

use App\Models\IronAiUsageLog;
use App\Models\Member;
use App\Models\MembershipAiCapability;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * IRON IA — control de acceso por membresía.
 *
 * Conecta IRON IA con el módulo EXISTENTE de Planes/Membresías (tabla `plans` +
 * `users.plan` / `users.membership_end_date`). NO crea un sistema de planes
 * paralelo: las capacidades IA viven en la tabla auxiliar
 * `membership_ai_capabilities` (asociada a los planes existentes) y en
 * `config/iron_ai.php` como fallback configurable.
 *
 * Reglas de negocio:
 *  - Sin membresía activa  → prueba gratuita (5 mensajes), luego se bloquea.
 *  - Con membresía activa  → cuota diaria/mensual + funciones según el plan.
 *  - Nunca se llama a OpenAI si el caller no tiene acceso (control de costos).
 */
class IronAiMembershipAccessService
{
    public function __construct(private readonly IronAiService $ironAi)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Orquestador principal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve TODO el estado de acceso del caller en una sola pasada.
     */
    public function resolveAccess(Request $request): array
    {
        $ctx = $this->resolveMember($request);
        $membership = $this->getCurrentMembership($ctx['member'], $ctx['user']);
        $capabilities = $this->getAiCapabilities($membership);
        $usage = $this->usageCounts($ctx);
        $decision = $this->decide($membership, $capabilities, $usage);

        return array_merge($ctx, [
            'membership'   => $membership,
            'capabilities' => $capabilities,
            'usage'        => $usage,
        ], $decision);
    }

    /**
     * Resuelve usuario/member/documento. Reusa la lógica de IronAiService y
     * deriva una "clave de identidad" estable para contar cuota.
     */
    public function resolveMember(Request $request): array
    {
        $ctx = $this->ironAi->resolveContext($request);

        // Clave estable para contar cuota: documento; o conversation_id si el
        // caller es anónimo y no envió documento.
        $identityKey = $ctx['document'] ?: $ctx['conversation_id'];

        return [
            'member'          => $ctx['member'],
            'user'            => $ctx['user'],
            'document'        => $ctx['document'],
            'conversation_id' => $ctx['conversation_id'],
            'identity_key'    => $identityKey,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Membresía (del módulo existente)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determina la membresía actual a partir del módulo existente:
     * `users.plan` (string) + `users.membership_end_date`. Activa = plan
     * presente y no vencida (misma convención que Member::resolvedFeatures()).
     *
     * @return array{active: bool, plan_name: ?string, plan_id: ?int, plan: ?Plan, end_date: ?string}
     */
    public function getCurrentMembership(?Member $member, ?User $user): array
    {
        $planName = $user?->plan ?: null;
        $endDate = $user?->membershipEndDate ?: null;
        $plan = null;
        $active = false;

        if ($planName) {
            $expired = false;
            if ($endDate) {
                try {
                    $expired = Carbon::parse($endDate)->endOfDay()->isPast();
                } catch (\Throwable) {
                    $expired = false;
                }
            }
            $active = ! $expired;

            // Intenta enlazar con un plan del catálogo existente (por nombre).
            $plan = Plan::whereRaw('lower(name) = ?', [mb_strtolower(trim($planName))])->first();
        }

        return [
            'active'    => $active,
            'plan_name' => $active ? $planName : null,
            'plan_id'   => $active ? $plan?->id : null,
            'plan'      => $active ? $plan : null,
            'end_date'  => $endDate,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capacidades IA (tabla auxiliar + config fallback)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Capacidades IA de una membresía. Orden de resolución:
     *  1) fila por membership_plan_id (plan del catálogo).
     *  2) fila por plan_code == users.plan exacto.
     *  3) tier inferido por palabras clave → fila/cfg del tier.
     *  4) default_membership (fila o config).
     * Sin membresía activa → prueba gratuita.
     */
    public function getAiCapabilities(array $membership): array
    {
        if (! ($membership['active'] ?? false)) {
            return $this->getFreeTrialCapabilities();
        }

        // 1) por plan del catálogo.
        if (! empty($membership['plan_id'])) {
            $row = MembershipAiCapability::where('membership_plan_id', $membership['plan_id'])
                ->where('is_active', true)->first();
            if ($row) {
                return $row->toCapabilities();
            }
        }

        // 2) por plan_code exacto (users.plan suele ser un código libre).
        $code = mb_strtolower(trim((string) ($membership['plan_name'] ?? '')));
        if ($code !== '') {
            $row = MembershipAiCapability::whereRaw('lower(plan_code) = ?', [$code])
                ->where('is_active', true)->first();
            if ($row) {
                return $row->toCapabilities();
            }
        }

        // 3) tier inferido.
        $tier = $this->inferTier($membership['plan_name'] ?? null);
        if ($tier) {
            $row = MembershipAiCapability::whereRaw('lower(plan_code) = ?', [$tier])
                ->where('is_active', true)->first();
            if ($row) {
                return $row->toCapabilities();
            }
            $cfg = config("iron_ai.tiers.$tier");
            if (is_array($cfg)) {
                return $cfg;
            }
        }

        // 4) default membership.
        $row = MembershipAiCapability::whereRaw("lower(plan_code) = 'default_membership'")
            ->where('is_active', true)->first();

        return $row ? $row->toCapabilities() : config('iron_ai.default_membership');
    }

    public function getFreeTrialCapabilities(): array
    {
        $row = MembershipAiCapability::whereRaw("lower(plan_code) = 'free_trial'")
            ->where('is_active', true)->first();

        return $row ? $row->toCapabilities() : config('iron_ai.free_trial');
    }

    public function canUseProgressAnalysis(?Member $member, ?User $user): bool
    {
        $membership = $this->getCurrentMembership($member, $user);
        $caps = $this->getAiCapabilities($membership);

        return (bool) ($caps['progress_analysis_enabled'] ?? false);
    }

    public function canUseSmartRecommendations(?Member $member, ?User $user): bool
    {
        $membership = $this->getCurrentMembership($member, $user);
        $caps = $this->getAiCapabilities($membership);

        return (bool) ($caps['smart_recommendations_enabled'] ?? false);
    }

    /** Infiere un tier (basic|intermediate|premium) por palabras clave. */
    private function inferTier(?string $planName): ?string
    {
        if (! $planName) {
            return null;
        }
        $needle = $this->normalize($planName);

        foreach (config('iron_ai.tier_keywords', []) as $tier => $keywords) {
            foreach ((array) $keywords as $kw) {
                if ($kw !== '' && str_contains($needle, $kw)) {
                    return $tier;
                }
            }
        }

        return null;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $from = ['á','é','í','ó','ú','ü','ñ'];
        $to   = ['a','e','i','o','u','u','n'];

        return str_replace($from, $to, $s);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Uso / cuota
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Conteo de mensajes consumidos (success + fallback) por identidad.
     *
     * @return array{lifetime: int, today: int, month: int}
     */
    public function usageCounts(array $ctx): array
    {
        $base = fn () => IronAiUsageLog::query()
            ->whereIn('status', IronAiUsageLog::CONSUMING)
            ->where(function ($w) use ($ctx) {
                $any = false;
                if ($ctx['member']) {
                    $w->orWhere('member_id', $ctx['member']->id);
                    $any = true;
                }
                if ($ctx['user']) {
                    $w->orWhere('user_id', $ctx['user']->id);
                    $any = true;
                }
                if (! empty($ctx['identity_key'])) {
                    $w->orWhere('document', $ctx['identity_key']);
                    $any = true;
                }
                if (! $any) {
                    $w->whereRaw('1 = 0');
                }
            });

        return [
            'lifetime' => (clone $base())->count(),
            'today'    => (clone $base())->whereDate('created_at', Carbon::today())->count(),
            'month'    => (clone $base())->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
        ];
    }

    public function getRemainingMessages(?Member $member, ?User $user, ?string $document = null): int
    {
        $ctx = [
            'member'       => $member,
            'user'         => $user,
            'identity_key' => $document ?: ($member?->document_number ?? $user?->document),
        ];
        $membership = $this->getCurrentMembership($member, $user);
        $caps = $this->getAiCapabilities($membership);
        $usage = $this->usageCounts($ctx);

        return $this->decide($membership, $caps, $usage)['remaining'] ?? 0;
    }

    /**
     * Decide si el caller puede usar el chat y calcula remaining + bloqueo.
     *
     * @return array{access_type: string, can_use_chat: bool, remaining: ?int,
     *               upgrade_required: bool, block: ?array}
     */
    private function decide(array $membership, array $caps, array $usage): array
    {
        $active = (bool) ($membership['active'] ?? false);
        $accessType = $active ? 'membership' : 'free_trial';

        // IA apagada para este plan.
        if (! ($caps['ai_enabled'] ?? true)) {
            return [
                'access_type'      => $accessType,
                'can_use_chat'     => false,
                'remaining'        => 0,
                'upgrade_required' => true,
                'block'            => $this->block('AI_DISABLED'),
            ];
        }

        if (! $active) {
            // PRUEBA GRATUITA: límite total (de por vida).
            $limit = (int) ($caps['free_trial_messages'] ?? 5);
            $used = $usage['lifetime'];
            $remaining = max(0, $limit - $used);
            $canUse = $remaining > 0;

            return [
                'access_type'      => 'free_trial',
                'can_use_chat'     => $canUse,
                'remaining'        => $remaining,
                'upgrade_required' => ! $canUse,
                'block'            => $canUse ? null : $this->block('FREE_TRIAL_LIMIT_REACHED', $limit),
            ];
        }

        // MEMBRESÍA ACTIVA: cuota diaria + mensual + fair use.
        $daily = $caps['daily_messages_limit'] ?? null;
        $monthly = $caps['monthly_messages_limit'] ?? null;
        $fairUse = $caps['fair_use_limit'] ?? null;

        if ($daily !== null && $usage['today'] >= (int) $daily) {
            return [
                'access_type'      => 'membership',
                'can_use_chat'     => false,
                'remaining'        => 0,
                'upgrade_required' => true,
                'block'            => $this->block('DAILY_LIMIT_REACHED'),
            ];
        }
        if ($monthly !== null && $usage['month'] >= (int) $monthly) {
            return [
                'access_type'      => 'membership',
                'can_use_chat'     => false,
                'remaining'        => 0,
                'upgrade_required' => true,
                'block'            => $this->block('MONTHLY_LIMIT_REACHED'),
            ];
        }
        if ($fairUse !== null && $usage['month'] >= (int) $fairUse) {
            return [
                'access_type'      => 'membership',
                'can_use_chat'     => false,
                'remaining'        => 0,
                'upgrade_required' => true,
                'block'            => $this->block('FAIR_USE_LIMIT_REACHED'),
            ];
        }

        $remaining = $monthly !== null ? max(0, (int) $monthly - $usage['month']) : null;

        return [
            'access_type'      => 'membership',
            'can_use_chat'     => true,
            'remaining'        => $remaining,
            'upgrade_required' => false,
            'block'            => null,
        ];
    }

    /** Construye el bloque de bloqueo (mensaje + CTA) por código. */
    public function block(string $code, ?int $limit = null): array
    {
        return match ($code) {
            'FREE_TRIAL_LIMIT_REACHED' => [
                'code'             => $code,
                'reply'            => 'Ya usaste tus ' . ($limit ?? 5) . ' consultas gratuitas de IRON IA. Compra una membresía para seguir recibiendo asistencia personalizada.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Desbloquear IRON IA', 'action' => 'Ver membresías'],
                'suggestions'      => ['Ver membresías', 'Comprar plan', 'Volver al inicio'],
            ],
            'DAILY_LIMIT_REACHED' => [
                'code'             => $code,
                'reply'            => 'Alcanzaste el límite diario de IRON IA de tu membresía. Vuelve mañana o mejora tu plan para más consultas.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Mejorar plan', 'action' => 'Ver membresías'],
                'suggestions'      => ['Mejorar plan', 'Ver membresías', 'Volver al inicio'],
            ],
            'MONTHLY_LIMIT_REACHED', 'FAIR_USE_LIMIT_REACHED' => [
                'code'             => $code,
                'reply'            => 'Has alcanzado el límite de IRON IA de tu membresía. Mejora tu plan para seguir usando el asistente.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Mejorar plan', 'action' => 'Ver membresías'],
                'suggestions'      => ['Mejorar plan', 'Ver membresías', 'Volver al inicio'],
            ],
            'PROGRESS_ANALYSIS_LOCKED' => [
                'code'             => $code,
                'reply'            => 'El análisis de progreso con IA está disponible en una membresía superior. Mejora tu plan para desbloquearlo.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Mejorar plan', 'action' => 'Ver membresías'],
                'suggestions'      => ['Mejorar plan', 'Ver membresías'],
            ],
            'SMART_RECOMMENDATIONS_LOCKED' => [
                'code'             => $code,
                'reply'            => 'Las recomendaciones inteligentes están disponibles en una membresía superior. Mejora tu plan para desbloquearlas.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Mejorar plan', 'action' => 'Ver membresías'],
                'suggestions'      => ['Mejorar plan', 'Ver membresías'],
            ],
            default => [ // AI_DISABLED u otros.
                'code'             => $code,
                'reply'            => 'IRON IA no está disponible para tu plan actual. Adquiere o mejora tu membresía para usar el asistente.',
                'upgrade_required' => true,
                'cta'              => ['title' => 'Ver membresías', 'action' => 'Ver membresías'],
                'suggestions'      => ['Ver membresías', 'Volver al inicio'],
            ],
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registro de uso
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra una fila en iron_ai_usage_logs. $metadata acepta: model,
     * input_tokens, output_tokens, message_id, block_reason.
     */
    public function registerUsage(array $ctx, string $status, array $metadata = []): IronAiUsageLog
    {
        $input = $metadata['input_tokens'] ?? null;
        $output = $metadata['output_tokens'] ?? null;

        return IronAiUsageLog::create([
            'user_id'            => $ctx['user']?->id,
            'member_id'          => $ctx['member']?->id,
            'document'           => $ctx['identity_key'] ?? $ctx['document'] ?? null,
            'membership_plan_id' => $ctx['membership']['plan_id'] ?? null,
            'message_id'         => $metadata['message_id'] ?? null,
            'model'              => $metadata['model'] ?? null,
            'input_tokens'       => $input,
            'output_tokens'      => $output,
            'estimated_cost'     => $this->estimateCost($input, $output),
            'status'             => $status,
            'block_reason'       => $metadata['block_reason'] ?? null,
        ]);
    }

    private function estimateCost(?int $input, ?int $output): ?float
    {
        $in = config('iron_ai.pricing.input_per_million');
        $out = config('iron_ai.pricing.output_per_million');
        if ($in === null || $out === null || ($input === null && $output === null)) {
            return null;
        }

        return round(((int) $input / 1_000_000) * (float) $in
            + ((int) $output / 1_000_000) * (float) $out, 6);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Serialización para Flutter
    // ─────────────────────────────────────────────────────────────────────────

    /** Snapshot compacto de cuota para incluir en la respuesta del chat. */
    public function quotaSnapshot(array $access): array
    {
        $caps = $access['capabilities'];
        $usage = $this->usageCounts($access); // recalcula tras registrar uso

        if (($access['access_type'] ?? 'free_trial') === 'free_trial') {
            $limit = (int) ($caps['free_trial_messages'] ?? 5);
            $used = $usage['lifetime'];

            return [
                'access_type' => 'free_trial',
                'used'        => $used,
                'limit'       => $limit,
                'remaining'   => max(0, $limit - $used),
            ];
        }

        $monthly = $caps['monthly_messages_limit'] ?? null;

        return [
            'access_type' => 'membership',
            'used'        => $usage['month'],
            'limit'       => $monthly,
            'remaining'   => $monthly !== null ? max(0, (int) $monthly - $usage['month']) : null,
        ];
    }

    /** Objeto completo de acceso para GET /access. */
    public function serializeAccess(array $access): array
    {
        $caps = $access['capabilities'];
        $usage = $access['usage'];
        $membership = $access['membership'];
        $isFree = ($access['access_type'] ?? 'free_trial') === 'free_trial';

        $freeLimit = (int) ($caps['free_trial_messages'] ?? 5);
        $monthly = $caps['monthly_messages_limit'] ?? null;

        return [
            'ok'                            => true,
            'has_active_membership'         => (bool) ($membership['active'] ?? false),
            'plan_name'                     => $membership['plan_name'] ?? null,
            'access_type'                   => $access['access_type'],
            'ai_enabled'                    => (bool) ($caps['ai_enabled'] ?? true),
            'can_use_chat'                  => (bool) $access['can_use_chat'],
            'upgrade_required'              => (bool) $access['upgrade_required'],
            'context_level'                 => $caps['context_level'] ?? 'basic',
            'max_output_tokens'             => $caps['max_output_tokens'] ?? null,
            'progress_analysis_enabled'     => (bool) ($caps['progress_analysis_enabled'] ?? false),
            'smart_recommendations_enabled' => (bool) ($caps['smart_recommendations_enabled'] ?? false),
            'weekly_summary_enabled'        => (bool) ($caps['weekly_summary_enabled'] ?? false),
            'proactive_notifications_enabled' => (bool) ($caps['proactive_notifications_enabled'] ?? false),

            // Prueba gratuita.
            'used_messages'      => $usage['lifetime'],
            'message_limit'      => $isFree ? $freeLimit : null,
            'remaining_messages' => $isFree ? max(0, $freeLimit - $usage['lifetime']) : ($access['remaining'] ?? null),

            // Membresía.
            'used_today'      => $usage['today'],
            'daily_limit'     => $caps['daily_messages_limit'] ?? null,
            'used_month'      => $usage['month'],
            'monthly_limit'   => $monthly,
            'remaining_month' => $monthly !== null ? max(0, (int) $monthly - $usage['month']) : null,

            'cta' => $access['block']['cta'] ?? null,
        ];
    }
}
