<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\WeeklyStreakConfig;
use App\Models\WeeklyStreakReward;
use App\Services\WeeklyStreakService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Administración del módulo "Esta semana" desde el CRM (Angular SPA).
 *
 * Sigue el patrón del resto del CRM admin: rutas sin auth.member (el panel
 * tiene su propia auth de admin). CRUD de la configuración y de los beneficios,
 * más upload de imágenes promocionales al disco público (mismo patrón que
 * stories CRM: $file->storeAs(..., 'public')).
 *
 * Las imágenes se guardan como URL pública (Storage::url), NUNCA binarios en DB.
 */
class WeeklyStreakAdminController extends Controller
{
    /** GET /api/admin/weekly-streak/configs — lista configs con sus beneficios. */
    public function index(): JsonResponse
    {
        $configs = WeeklyStreakConfig::query()
            ->with(['rewards' => fn ($q) => $q->orderBy('required_days')->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (WeeklyStreakConfig $c) => $this->serializeConfig($c));

        return response()->json(['ok' => true, 'data' => $configs]);
    }

    /** POST /api/admin/weekly-streak/configs — crea una config. */
    public function storeConfig(Request $request): JsonResponse
    {
        $data = $this->validateConfig($request);
        $config = WeeklyStreakConfig::create($data);

        return response()->json(['ok' => true, 'data' => $this->serializeConfig($config->fresh('rewards'))], 201);
    }

    /** PUT/PATCH /api/admin/weekly-streak/configs/{config} — actualiza. */
    public function updateConfig(Request $request, WeeklyStreakConfig $config): JsonResponse
    {
        $data = $this->validateConfig($request, partial: true);
        $config->update($data);

        return response()->json(['ok' => true, 'data' => $this->serializeConfig($config->fresh('rewards'))]);
    }

    /** DELETE /api/admin/weekly-streak/configs/{config} — borra (cascade nullea rewards). */
    public function destroyConfig(WeeklyStreakConfig $config): JsonResponse
    {
        $config->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/admin/weekly-streak/rewards — crea un beneficio. */
    public function storeReward(Request $request): JsonResponse
    {
        $data = $this->validateReward($request);
        $reward = WeeklyStreakReward::create($data);

        return response()->json(['ok' => true, 'data' => $this->serializeReward($reward)], 201);
    }

    /** PUT/PATCH /api/admin/weekly-streak/rewards/{reward} — actualiza. */
    public function updateReward(Request $request, WeeklyStreakReward $reward): JsonResponse
    {
        $data = $this->validateReward($request, partial: true);
        $reward->update($data);

        return response()->json(['ok' => true, 'data' => $this->serializeReward($reward->fresh())]);
    }

    /** DELETE /api/admin/weekly-streak/rewards/{reward} — borra. */
    public function destroyReward(WeeklyStreakReward $reward): JsonResponse
    {
        $reward->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/admin/weekly-streak/upload — sube una imagen promocional y
     * devuelve su URL pública. Mismo patrón que el uploader de stories CRM.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:8192|mimes:jpg,jpeg,png,webp',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs('weekly-streak', $filename, 'public');

        return response()->json([
            'ok' => true,
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ]);
    }

    /**
     * GET /api/admin/weekly-streak/members — racha de TODOS los miembros.
     *
     * Calcula racha actual, racha más larga, días activos de la semana y última
     * actividad de cada miembro. Eficiente: una sola query de días de actividad
     * agrupada por miembro (sin N+1). Soporta búsqueda, filtro por racha
     * mín/máx y orden por racha (desc por defecto).
     *
     * Query params: search, min_streak, max_streak, sort (current_desc|current_asc|
     * week_desc|name_asc).
     */
    public function members(Request $request): JsonResponse
    {
        $tz = WeeklyStreakService::TZ;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);

        $goal = WeeklyStreakConfig::activePrimary()?->weekly_goal_days ?? 5;

        // Todos los días de actividad, agrupados por miembro (1 sola query).
        $daysByMember = MemberAppActivityDay::query()
            ->orderBy('member_id')
            ->orderBy('activity_date')
            ->get(['member_id', 'activity_date'])
            ->groupBy('member_id');

        $members = Member::query()
            ->with('user:id,name,plan')
            ->whereHas('user')
            ->get(['id', 'user_id', 'full_name', 'document_number', 'status']);

        $rows = $members->map(function (Member $m) use ($daysByMember, $today, $weekStart, $weekEnd, $goal): array {
            $dates = ($daysByMember[$m->id] ?? collect())
                ->pluck('activity_date')
                ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
                ->values()
                ->all();

            $streak = $this->streakFromDates($dates, $today);

            $weekActive = collect($dates)->filter(
                fn ($d) => $d >= $weekStart->toDateString() && $d <= $weekEnd->toDateString(),
            )->count();

            return [
                'member_id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name ?: $m->full_name,
                'document' => $m->document_number,
                'plan' => $m->user?->plan,
                'status' => $m->status,
                'current_streak_days' => $streak['current'],
                'longest_streak_days' => $streak['longest'],
                'active_days_this_week' => $weekActive,
                'weekly_goal_days' => $goal,
                'reached_goal' => $goal > 0 && $weekActive >= $goal,
                'last_active_date' => $streak['last'],
            ];
        });

        // ── Filtros ──
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = Str::lower($search);
            $rows = $rows->filter(function (array $r) use ($needle): bool {
                return str_contains(Str::lower((string) $r['name']), $needle)
                    || str_contains(Str::lower((string) $r['document']), $needle)
                    || str_contains(Str::lower((string) $r['plan']), $needle);
            });
        }
        if ($request->filled('min_streak')) {
            $min = (int) $request->query('min_streak');
            $rows = $rows->filter(fn (array $r) => $r['current_streak_days'] >= $min);
        }
        if ($request->filled('max_streak')) {
            $max = (int) $request->query('max_streak');
            $rows = $rows->filter(fn (array $r) => $r['current_streak_days'] <= $max);
        }

        // ── Orden ──
        $sort = (string) $request->query('sort', 'current_desc');
        $rows = match ($sort) {
            'current_asc' => $rows->sortBy('current_streak_days'),
            'week_desc' => $rows->sortByDesc('active_days_this_week'),
            'name_asc' => $rows->sortBy('name'),
            default => $rows->sortByDesc('current_streak_days'),
        };

        $rows = $rows->values();

        return response()->json([
            'ok' => true,
            'count' => $rows->count(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'weekly_goal_days' => $goal,
            'data' => $rows,
        ]);
    }

    /**
     * Racha actual y más larga a partir de un arreglo ordenado de fechas
     * (YYYY-MM-DD). Pura (sin BD) para poder calcular en lote todos los miembros.
     */
    private function streakFromDates(array $dates, CarbonImmutable $today): array
    {
        if (empty($dates)) {
            return ['current' => 0, 'longest' => 0, 'last' => null];
        }

        $set = array_flip($dates);

        $longest = 1;
        $run = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $prev = CarbonImmutable::parse($dates[$i - 1]);
            $curr = CarbonImmutable::parse($dates[$i]);
            if ($prev->addDay()->toDateString() === $curr->toDateString()) {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 1;
            }
        }

        $current = 0;
        $cursor = $today;
        if (! isset($set[$today->toDateString()])) {
            $cursor = $today->subDay(); // hoy sin marcar → la racha vive desde ayer
        }
        while (isset($set[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->subDay();
        }

        return ['current' => $current, 'longest' => max($longest, $current), 'last' => end($dates)];
    }

    // ── Validación ──────────────────────────────────────────────────────────

    private function validateConfig(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => "$req|string|max:120",
            'subtitle' => 'nullable|string|max:200',
            'weekly_goal_days' => 'nullable|integer|min:1|max:7',
            'hero_title' => 'nullable|string|max:160',
            'hero_description' => 'nullable|string|max:500',
            'hero_image_url' => 'nullable|string|max:1000',
            'promo_image_url' => 'nullable|string|max:1000',
            'cta_label' => 'nullable|string|max:80',
            'cta_route' => 'nullable|string|max:120',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);
    }

    private function validateReward(Request $request, bool $partial = false): array
    {
        $reqDays = $partial ? 'sometimes' : 'required';
        $reqTitle = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'config_id' => 'nullable|integer|exists:weekly_streak_configs,id',
            'required_days' => "$reqDays|integer|min:1|max:7",
            'title' => "$reqTitle|string|max:120",
            'description' => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:1000',
            'badge_label' => 'nullable|string|max:80',
            'reward_type' => 'nullable|string|max:60',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);
    }

    // ── Serialización ───────────────────────────────────────────────────────

    private function serializeConfig(WeeklyStreakConfig $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'subtitle' => $c->subtitle,
            'weekly_goal_days' => $c->weekly_goal_days,
            'hero_title' => $c->hero_title,
            'hero_description' => $c->hero_description,
            'hero_image_url' => $c->hero_image_url,
            'promo_image_url' => $c->promo_image_url,
            'cta_label' => $c->cta_label,
            'cta_route' => $c->cta_route,
            'is_active' => $c->is_active,
            'sort_order' => $c->sort_order,
            'metadata' => $c->metadata,
            'rewards' => $c->relationLoaded('rewards')
                ? $c->rewards->map(fn (WeeklyStreakReward $r) => $this->serializeReward($r))->all()
                : [],
        ];
    }

    private function serializeReward(WeeklyStreakReward $r): array
    {
        return [
            'id' => $r->id,
            'config_id' => $r->config_id,
            'required_days' => $r->required_days,
            'title' => $r->title,
            'description' => $r->description,
            'image_url' => $r->image_url,
            'badge_label' => $r->badge_label,
            'reward_type' => $r->reward_type,
            'is_active' => $r->is_active,
            'sort_order' => $r->sort_order,
            'metadata' => $r->metadata,
        ];
    }
}
