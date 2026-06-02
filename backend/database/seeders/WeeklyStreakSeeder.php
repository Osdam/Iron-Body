<?php

namespace Database\Seeders;

use App\Models\WeeklyStreakConfig;
use App\Models\WeeklyStreakReward;
use Illuminate\Database\Seeder;

/**
 * Config inicial del módulo "Esta semana".
 *
 * Crea una config activa con su meta semanal y un set de beneficios base.
 * Todo es editable luego desde el CRM (no es hardcode en la app: la app lo
 * consume por API). Idempotente: no duplica si ya existe una config.
 */
class WeeklyStreakSeeder extends Seeder
{
    public function run(): void
    {
        if (WeeklyStreakConfig::query()->exists()) {
            return;
        }

        $config = WeeklyStreakConfig::create([
            'title' => 'Esta semana',
            'subtitle' => 'Tu constancia construye resultados',
            'weekly_goal_days' => 5,
            'hero_title' => 'Tu racha Iron Body',
            'hero_description' => 'Entra cada día y mantén tu racha viva. La constancia es lo que transforma tu cuerpo.',
            'hero_image_url' => null,
            'promo_image_url' => null,
            'cta_label' => 'Entrenar hoy',
            'cta_route' => null,
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => null,
        ]);

        $rewards = [
            [
                'required_days' => 3,
                'title' => 'Arranque imparable',
                'description' => 'Tres días activos. Estás construyendo el hábito.',
                'badge_label' => '3 días',
                'reward_type' => 'badge',
                'sort_order' => 0,
            ],
            [
                'required_days' => 5,
                'title' => 'Semana cumplida',
                'description' => 'Cinco días activos. Alcanzaste tu meta semanal.',
                'badge_label' => '5 días',
                'reward_type' => 'badge',
                'sort_order' => 1,
            ],
            [
                'required_days' => 7,
                'title' => 'Racha perfecta',
                'description' => 'Siete días seguidos. Eres élite Iron Body.',
                'badge_label' => '7 días',
                'reward_type' => 'badge',
                'sort_order' => 2,
            ],
        ];

        foreach ($rewards as $r) {
            WeeklyStreakReward::create(array_merge($r, [
                'config_id' => $config->id,
                'image_url' => null,
                'is_active' => true,
                'metadata' => null,
            ]));
        }
    }
}
