<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea o actualiza una cuenta del panel/CRM (login email + contraseña). No hay
 * registro público de admins: las cuentas se crean con este comando.
 *
 *   php artisan admin:create --email=admin@ironbody.com --name="Admin" --role=super_admin
 *
 * Si no se pasa --password, genera una fuerte y la imprime UNA sola vez.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--email=    : Correo de acceso}
        {--name=     : Nombre visible}
        {--role=administrador : super_admin | administrador | administrativo | recepcion}
        {--password= : Contraseña (si se omite, se genera una aleatoria)}';

    protected $description = 'Crea o actualiza una cuenta de administrador del CRM (email + contraseña).';

    /** Slugs de CLI → valor de `role` almacenado (alineado con el enum del front). */
    private const ROLE_MAP = [
        'super_admin' => Admin::ROLE_SUPER_ADMIN,
        'administrador' => Admin::ROLE_ADMINISTRADOR,
        'administrativo' => Admin::ROLE_ADMINISTRATIVO,
        'recepcion' => Admin::ROLE_RECEPCION,
    ];

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo'))));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre')));

        $roleSlug = Str::of((string) $this->option('role'))->lower()->replace('-', '_')->value();
        if (! isset(self::ROLE_MAP[$roleSlug])) {
            $this->error('Rol inválido. Usa: '.implode(', ', array_keys(self::ROLE_MAP)));

            return self::FAILURE;
        }
        $role = self::ROLE_MAP[$roleSlug];

        if ($email === '' || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email y nombre son obligatorios y el email debe ser válido.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        $generated = false;
        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $admin = Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => $role,
                'status' => 'active',
                'password' => $password, // el cast 'hashed' lo bcrypt-ea
            ],
        );

        $this->info(($admin->wasRecentlyCreated ? 'Admin creado: ' : 'Admin actualizado: ').$email." (rol: {$role})");
        if ($generated) {
            $this->warn('Contraseña generada (guárdala, no se vuelve a mostrar): '.$password);
        }

        return self::SUCCESS;
    }
}
