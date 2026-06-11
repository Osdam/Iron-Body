<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Carga el catálogo real de planes IRONBODY agrupados por tier:
 *  - lite     → "Básicos"
 *  - pro      → "Pro"
 *  - premium  → "Total Access"
 *
 * Idempotente: usa updateOrCreate por nombre. Ejecutar con:
 *   php artisan db:seed --class=Database\\Seeders\\PlansSeeder
 */
class PlansSeeder extends Seeder
{
    /** Beneficios compartidos por todos los planes del tier "Básicos" (lite). */
    private const BENEFITS_LITE = [
        // Gimnasio
        'Acceso ilimitado al gimnasio',
        'Zona de pesas y maquinaria',
        'Asesoría semi personalizada con entrenador de planta',
        'Seguimiento en ejecución de ejercicios',
        'Acceso a clases de rumba dos veces por semana',
        'Valoración inicial',
        // App
        'Acceso básico completo a la app',
        'Perfil de usuario',
        'Visualización de plan activo',
        'Historial de pagos y documentos',
        'Rutinas generales básicas',
        'Registro básico de IMC, peso y racha',
        'Acceso a reels, stories y eventos generales',
        'IRON IA limitada',
    ];

    /** Beneficios compartidos por todos los planes del tier "Pro" (pro). */
    private const BENEFITS_PRO = [
        // Gimnasio
        'Acceso al gimnasio durante la vigencia del plan',
        'Rutinas personalizadas',
        'Seguimiento cada dos meses',
        'Acceso a clases de rumba dos veces por semana',
        // App
        'Acceso avanzado a la app',
        'Rutinas personalizadas en la app',
        'IRON IA por texto, imagen y audio limitada',
        'IRON IA coach para entrenamiento y nutrición',
        'IRON IA para progreso y racha',
        'Registro avanzado de nutrición',
        'Historial parcial de evaluaciones',
        'Acceso a eventos',
    ];

    /** Beneficios compartidos por todos los planes del tier "Total Access" (premium). */
    private const BENEFITS_PREMIUM = [
        // Gimnasio
        'Acceso total al gimnasio',
        'Acceso completo a IRONBODY WORKOUT',
        'Sesiones funcionales por agenda',
        'Entrenamiento intensivo y dinámico',
        'Rutinas guiadas por entrenadores',
        'Seguimiento continuo durante todo el proceso',
        'Guía nutricional para mejorar resultados',
        // App
        'Acceso completo a la app',
        'IRON IA completa',
        'IRON IA por texto, voz, audio, imagen y cámara',
        'Análisis visual en vivo',
        'IRON IA coach completo',
        'Rutinas personalizadas',
        'Seguimiento digital completo',
        'Nutrición avanzada',
        'Historial completo de progreso',
    ];

    public function run(): void
    {
        $plans = [
            // ── Básicos (lite) ──────────────────────────────────────────────
            ['name' => 'Plan Semana',   'tier' => 'lite',    'price' => 45000,   'original_price' => null,    'duration_days' => 7,   'sort_order' => 1,  'benefits' => self::BENEFITS_LITE],
            ['name' => 'Plan Valera',   'tier' => 'lite',    'price' => 65000,   'original_price' => null,    'duration_days' => 15,  'sort_order' => 2,  'benefits' => self::BENEFITS_LITE],
            ['name' => 'Plan Mensual',  'tier' => 'lite',    'price' => 80000,   'original_price' => null,    'duration_days' => 30,  'sort_order' => 3,  'benefits' => self::BENEFITS_LITE],

            // ── Pro / intermedios (pro) ─────────────────────────────────────
            ['name' => 'Trimestre',     'tier' => 'pro',     'price' => 210000,  'original_price' => 240000,  'duration_days' => 90,  'sort_order' => 4,  'benefits' => self::BENEFITS_PRO],
            ['name' => 'Semestre',      'tier' => 'pro',     'price' => 390000,  'original_price' => 480000,  'duration_days' => 180, 'sort_order' => 5,  'benefits' => self::BENEFITS_PRO],
            ['name' => 'Anualidad',     'tier' => 'pro',     'price' => 624000,  'original_price' => 960000,  'duration_days' => 365, 'sort_order' => 6,  'benefits' => self::BENEFITS_PRO],

            // ── Total Access (premium) ──────────────────────────────────────
            ['name' => 'Élite',         'tier' => 'premium', 'price' => 180000,  'original_price' => 270000,  'duration_days' => 30,  'sort_order' => 7,  'benefits' => self::BENEFITS_PREMIUM],
            ['name' => 'Pro',           'tier' => 'premium', 'price' => 499000,  'original_price' => 540000,  'duration_days' => 90,  'sort_order' => 8,  'benefits' => self::BENEFITS_PREMIUM],
            ['name' => 'Prime',         'tier' => 'premium', 'price' => 950000,  'original_price' => 1080000, 'duration_days' => 180, 'sort_order' => 9,  'benefits' => self::BENEFITS_PREMIUM],
            ['name' => 'Evolution',     'tier' => 'premium', 'price' => 1770000, 'original_price' => 2160000, 'duration_days' => 365, 'sort_order' => 10, 'benefits' => self::BENEFITS_PREMIUM],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['name' => $plan['name']],
                [
                    'tier'           => $plan['tier'],
                    'price'          => $plan['price'],
                    'original_price' => $plan['original_price'],
                    'duration_days'  => $plan['duration_days'],
                    'sort_order'     => $plan['sort_order'],
                    'benefits'       => json_encode($plan['benefits'], JSON_UNESCAPED_UNICODE),
                    'active'         => true,
                ],
            );
        }

        // Desactiva los planes demo antiguos para que no aparezcan en la app
        // junto al catálogo real (no se eliminan por las referencias de pagos).
        Plan::whereIn('name', ['Mensual', 'Elite'])->update(['active' => false]);
    }
}
