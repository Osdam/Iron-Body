<?php

namespace App\Console\Commands;

use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Payments\MembershipPeriod;
use Illuminate\Console\Command;

/**
 * ¿POR QUÉ NO ENTRA ESTA PERSONA?
 *
 * La pregunta llega siempre igual —«el lector le dice que está vencido y no
 * debería»— y contestarla obligaba a mirar tres sitios a la vez: la ficha del
 * socio, su permiso de empleado y lo que de verdad viaja al terminal en la
 * sincronización.
 *
 * Lo tercero es lo que importaba y era justo lo que no se podía ver. El
 * terminal decide con el bloque `access` de `GET /api/users`, no con lo que
 * enseña el CRM: si el servidor manda una cosa y la pantalla otra, el lector
 * hace caso al servidor. Esto imprime literalmente lo que el servidor manda.
 *
 * NO TOCA NADA. Es un diagnóstico.
 */
class MemberAccessDoctorCommand extends Command
{
    protected $signature = 'members:access
        {quien : Documento, correo, id o parte del nombre}';

    protected $description = 'Por qué puede entrar —o no— una persona, con lo mismo que recibe el terminal.';

    public function handle(): int
    {
        $termino = trim((string) $this->argument('quien'));

        $socios = User::query()
            ->where(fn ($q) => $q
                ->where('document', $termino)
                ->orWhere('email', $termino)
                ->orWhere('id', ctype_digit($termino) ? (int) $termino : 0)
                ->orWhere('name', 'like', '%'.$termino.'%'))
            ->limit(10)
            ->get();

        if ($socios->isEmpty()) {
            $this->error('Nadie coincide con «'.$termino.'».');

            return self::FAILURE;
        }

        $ahora = MembershipPeriod::today();
        $this->line('Hoy, para el gimnasio: '.$ahora->toDateString().' ('.MembershipPeriod::TZ.')');

        foreach ($socios as $socio) {
            $this->newLine();
            $this->info('#'.$socio->id.'  '.$socio->name.'  ·  doc '.($socio->document ?: '—'));

            $acceso = app(MembershipAccess::class)->state($socio);

            $this->table(['Dato', 'Valor'], [
                ['¿Puede entrar AHORA?', $acceso['can_enter'] ? 'SÍ' : 'NO'],
                ['Por qué puerta', $acceso['via'] ?? '—'],
                ['Motivo del rechazo', $acceso['reason'] ?? '—'],
                ['Mensaje', $acceso['message'] ?? '—'],
                ['Plan', $socio->plan ?: '—'],
                ['Membresía', ($socio->membership_start_date ?: '—').' → '.($socio->membership_end_date ?: '—')],
            ]);

            $this->empleado($socio, $acceso);
        }

        $this->newLine();
        $this->comment('Lo de «Acceso de empleado» es LO MISMO que viaja en GET /api/users,');
        $this->comment('que es con lo que decide el terminal. Si aquí sale que entra y el');
        $this->comment('lector dice que no, al terminal no le ha llegado: resincronízalo.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $acceso */
    private function empleado(User $socio, array $acceso): void
    {
        $bloque = $acceso['employee'] ?? null;
        $fila = EmployeeAccess::where('user_id', $socio->id)->first();

        if (! $bloque && ! $fila) {
            $this->line('  Acceso de empleado: no tiene.');

            return;
        }

        if (! $bloque) {
            // No debería pasar: hay fila y el estado no la ve. Si ocurre, es
            // justo lo que había que descubrir.
            $this->error('  Hay permiso de empleado en la base PERO no sale en el acceso. Avisa de esto.');

            return;
        }

        $this->table(['Acceso de empleado', 'Valor'], [
            ['Encendido', $bloque['active'] ? 'sí' : 'NO'],
            ['Vale hoy', $bloque['current'] ? 'sí' : 'NO'],
            ['Y es su hora', $bloque['open_now'] ? 'sí' : 'NO'],
            ['Cargo', $bloque['position'] ?: '—'],
            ['Desde / hasta', ($bloque['starts_on'] ?: '—').' → '.($bloque['ends_on'] ?: 'sin fin')],
            ['Días', $bloque['rules']['days_label'] ?: 'todos'],
            ['Horas', $bloque['rules']['hours_label'] ?: 'todo el día'],
            ['Lo concedió', $bloque['granted_by_name'] ?: '—'],
        ]);
    }
}
