<?php

namespace App\Console\Commands;

use App\Models\MembershipAiCapability;
use App\Models\Plan;
use Illuminate\Console\Command;

/**
 * Siembra/actualiza las capacidades IA de IRON IA a partir de los planes
 * EXISTENTES (tabla `plans`). NO crea planes comerciales nuevos.
 *
 * Idempotente: crea filas faltantes y no duplica. Crea:
 *  - filas especiales por plan_code: free_trial, default_membership,
 *    basic, intermediate, premium (membership_plan_id null), y
 *  - una fila por cada plan existente (membership_plan_id = plan->id),
 *    con capacidades inferidas por tier desde el nombre del plan.
 */
class SyncMembershipAiCapabilitiesCommand extends Command
{
    protected $signature = 'iron-ai:sync-membership-capabilities {--force : Sobrescribe capacidades existentes con los valores base}';
    protected $description = 'Crea/actualiza capacidades IA de IRON IA según los planes/membresías existentes (idempotente)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $created = 0;
        $skipped = 0;

        // 1) Filas especiales (plan_code, sin plan del catálogo).
        $special = [
            'free_trial'         => config('iron_ai.free_trial'),
            'default_membership' => config('iron_ai.default_membership'),
            'basic'              => config('iron_ai.tiers.basic'),
            'intermediate'       => config('iron_ai.tiers.intermediate'),
            'premium'            => config('iron_ai.tiers.premium'),
        ];

        foreach ($special as $code => $caps) {
            if (! is_array($caps)) {
                continue;
            }
            $existing = MembershipAiCapability::whereNull('membership_plan_id')
                ->whereRaw('lower(plan_code) = ?', [$code])->first();

            if ($existing && ! $force) {
                $skipped++;
                $this->line("  = plan_code '{$code}' ya existe (sin cambios)");
                continue;
            }

            MembershipAiCapability::updateOrCreate(
                ['membership_plan_id' => null, 'plan_code' => $code],
                array_merge($caps, ['is_active' => true]),
            );
            $created++;
            $this->line(($existing ? "  ~ " : "  + ") . "plan_code '{$code}' " . ($existing ? 'actualizado' : 'creado'));
        }

        // 2) Una fila por cada plan existente del catálogo.
        $plans = Plan::all();
        if ($plans->isEmpty()) {
            $this->warn('  No hay planes en la tabla `plans` (solo se sembraron los códigos genéricos).');
        }

        foreach ($plans as $plan) {
            $tier = $this->inferTier($plan->name) ?? 'default_membership';
            $caps = $tier === 'default_membership'
                ? config('iron_ai.default_membership')
                : (config("iron_ai.tiers.$tier") ?? config('iron_ai.default_membership'));

            $existing = MembershipAiCapability::where('membership_plan_id', $plan->id)->first();

            if ($existing && ! $force) {
                $skipped++;
                $this->line("  = plan #{$plan->id} '{$plan->name}' ya tiene capacidades (sin cambios)");
                continue;
            }

            MembershipAiCapability::updateOrCreate(
                ['membership_plan_id' => $plan->id],
                array_merge($caps, [
                    'plan_code' => $this->slug($plan->name),
                    'is_active' => true,
                ]),
            );
            $created++;
            $this->line(($existing ? "  ~ " : "  + ") . "plan #{$plan->id} '{$plan->name}' → tier '{$tier}'");
        }

        $this->newLine();
        $this->info("Listo — escritas/actualizadas: {$created} · sin cambios: {$skipped} · total filas: " . MembershipAiCapability::count());

        return self::SUCCESS;
    }

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

        return str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $s);
    }

    private function slug(string $s): string
    {
        $s = $this->normalize($s);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }
}
