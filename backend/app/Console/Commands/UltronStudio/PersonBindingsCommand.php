<?php

namespace App\Console\Commands\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioPersonBinding;
use App\Services\UltronStudio\StudioPersonBindings;
use Illuminate\Console\Command;

/**
 * EL ARRANQUE DE LOS CUATRO OJOS POR PERSONA (contrato H9, D9-11.2, P9-4): las
 * DOS PRIMERAS personas se vinculan SOLO por consola, con la autorización
 * escrita del propietario, y el evento queda en el libro del Studio (actor
 * `system`, este comando), como el T1 del H6. Cada cuenta queda vinculada a su
 * identidad con origen `bootstrap`: verificada al nacer, sin cuenta que la cree
 * ni la verifique y con la autorización en `bootstrap_authorization` (≤ 160;
 * el CHECK `uspb_origin_shape_chk` de DB9). Solo vale mientras no haya ningún
 * vínculo vivo: después, los vínculos los crea y verifica el CRM.
 *
 *   php artisan ultron-studio:person-bindings --bootstrap \
 *       --admin-a=12 --identity-a=<uuid> --admin-b=34 --identity-b=<uuid> \
 *       --authorization="Orden escrita del propietario del 14-11-2026 (correo)"
 *
 * `--list` enseña los vínculos (sin documento ni teléfono). Sin red. Nunca
 * borra: un vínculo se revoca desde el CRM.
 */
class PersonBindingsCommand extends Command
{
    public const AUTHORIZATION_MIN = 10;

    public const AUTHORIZATION_MAX = StudioPersonBindings::AUTHORIZATION_MAX;

    protected $signature = 'ultron-studio:person-bindings
        {--bootstrap : Vincula las dos primeras personas (cada una creada y verificada por la otra)}
        {--admin-a= : Id de la cuenta de la primera persona}
        {--identity-a= : Uuid de la identidad de la primera persona}
        {--admin-b= : Id de la cuenta de la segunda persona}
        {--identity-b= : Uuid de la identidad de la segunda persona}
        {--authorization= : La autorización escrita del propietario (10–160 caracteres)}
        {--list : Enseña los vínculos, sin documento ni teléfono}';

    protected $description = 'Vínculos cuenta → persona de ULTRON Studio: arranque de las dos primeras personas (cuatro ojos por persona)';

    public function handle(StudioPersonBindings $bindings): int
    {
        if ($this->option('list')) {
            return $this->list();
        }
        if (! $this->option('bootstrap')) {
            $this->error('Indica --bootstrap (o --list). Los demás vínculos se crean y verifican desde el CRM.');

            return self::INVALID;
        }

        $a = $this->option('admin-a');
        $b = $this->option('admin-b');
        $ia = $this->option('identity-a');
        $ib = $this->option('identity-b');
        $autorizacion = trim((string) $this->option('authorization'));
        $uuid = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
        if (! is_string($a) || ! ctype_digit($a) || ! is_string($b) || ! ctype_digit($b)
            || ! is_string($ia) || preg_match($uuid, $ia) !== 1 || ! is_string($ib) || preg_match($uuid, $ib) !== 1) {
            $this->error('Faltan --admin-a, --identity-a, --admin-b o --identity-b (ids enteros y uuids).');

            return self::INVALID;
        }
        $largo = mb_strlen($autorizacion);
        if ($largo < self::AUTHORIZATION_MIN || $largo > self::AUTHORIZATION_MAX || preg_match('/[\x00-\x1F\x7F]/u', $autorizacion) === 1) {
            $this->error('--authorization: la autorización escrita del propietario, en una línea de '.self::AUTHORIZATION_MIN.' a '.self::AUTHORIZATION_MAX.' caracteres.');

            return self::INVALID;
        }

        try {
            $filas = $bindings->bootstrap((int) $a, $ia, (int) $b, $ib, $autorizacion);
        } catch (UltronStudioException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Vínculo', 'Cuenta', 'Origen'], array_map(
            static fn (StudioPersonBinding $f): array => [(string) $f->uuid, (int) $f->admin_id, (string) $f->origin],
            $filas,
        ));
        $this->info('Las dos primeras personas quedan vinculadas y verificadas. Queda en el libro del Studio con la autorización.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $filas = StudioPersonBinding::query()->orderBy('id')->get();
        $this->table(['Vínculo', 'Cuenta', 'Persona (huella)', 'Estado'], $filas->map(static function (StudioPersonBinding $f): array {
            $dto = StudioPersonBindings::dto($f);

            return [(string) $f->uuid, (int) $f->admin_id, (string) $dto['identityRef'], (string) $dto['status']];
        })->all());

        return self::SUCCESS;
    }
}
