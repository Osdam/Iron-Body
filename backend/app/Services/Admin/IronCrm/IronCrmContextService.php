<?php

namespace App\Services\Admin\IronCrm;

use App\Models\Admin;
use Carbon\CarbonImmutable;

/**
 * Construye el CONTEXTO mínimo y seguro que se inyecta al prompt de IRON (CRM):
 * quién pregunta (admin autenticado + rol), fecha/hora y qué módulos existen.
 *
 * IMPORTANTE: aquí NO se vuelca la base de datos. Solo metadatos operativos y
 * una guía de módulos disponibles. Los datos reales (miembros, pagos, etc.) se
 * obtienen bajo demanda con las herramientas de {@see IronCrmToolService}.
 */
class IronCrmContextService
{
    public function __construct(private readonly IronCrmToolService $tools) {}

    public function build(?Admin $admin): string
    {
        $now = CarbonImmutable::now();
        $role = $admin?->role ?? 'Automatización interna';
        $name = $admin?->name ?? 'Sistema';

        $modules = $this->tools->run('available_modules', []);
        $available = collect($modules['modules'] ?? [])
            ->filter(fn ($v) => $v === true)
            ->keys()
            ->map(fn ($k) => str_replace('_', ' ', $k))
            ->implode(', ');
        $unavailable = collect($modules['modules'] ?? [])
            ->filter(fn ($v) => $v === false)
            ->keys()
            ->map(fn ($k) => str_replace('_', ' ', $k))
            ->implode(', ');

        $lines = [
            'CONTEXTO DEL CRM (no lo repitas literalmente; úsalo para responder):',
            '- Gimnasio: Iron Body Neiva.',
            '- Usuario administrativo autenticado: '.$name.' (rol: '.$role.').',
            '- Fecha y hora actual: '.$now->translatedFormat('l d/m/Y H:i').' (America/Bogota).',
            '- Módulos disponibles para consultar: '.($available !== '' ? $available : 'ninguno detectado').'.',
        ];

        if ($unavailable !== '') {
            $lines[] = '- Módulos NO disponibles en esta instancia: '.$unavailable.'. Si preguntan por ellos, dilo con honestidad.';
        }

        $lines[] = '- Alcance de permisos: responde solo información administrativa que este rol pueda ver. Ante la duda, sé prudente.';

        return implode("\n", $lines);
    }
}
