<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AdminSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cambia la contraseña de una cuenta del panel/CRM sin que el valor pase por
 * ningún sitio donde quede grabado.
 *
 * Existe porque `admin:create --password=…` —la única forma que había de fijar
 * una contraseña— la recibe como argumento, y un argumento es exactamente lo
 * que sí queda registrado: aparece en `ps` mientras el comando corre, en el
 * historial del shell del que lo lanzó y, por SSH, en el log del propio
 * comando remoto. Para crear una cuenta de prueba da igual; para rotar la
 * credencial administrativa de producción, no.
 *
 * Aquí la contraseña se teclea en un prompt oculto y se descarta en cuanto se
 * ha convertido en hash. No se imprime, no se devuelve y no se registra.
 *
 * Además revoca las sesiones vivas de esa cuenta: cambiar la contraseña sin
 * cerrar las sesiones abiertas deja al portador de un bearer robado dentro,
 * que es justo de lo que se rota.
 *
 *   php artisan admin:password --email=admin@ironbody.com
 */
class RotateAdminPassword extends Command
{
    protected $signature = 'admin:password
        {--email=          : Correo de la cuenta a rotar}
        {--keep-sessions   : No revocar las sesiones abiertas (por defecto SÍ se revocan)}';

    protected $description = 'Rota la contraseña de una cuenta del CRM (entrada oculta, sin exponer el valor).';

    /** Longitud mínima. Por debajo de esto no es una credencial de producción. */
    private const MIN_LENGTH = 14;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo de la cuenta'))));

        $admin = Admin::where('email', $email)->first();
        if (! $admin) {
            $this->error("No existe ninguna cuenta con el correo {$email}.");

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  Cuenta : {$admin->email}");
        $this->line("  Rol    : {$admin->role}");
        $this->line('  Estado : '.$admin->status);
        $this->line('  Hash   : '.$this->hashInfo((string) $admin->password).' (actual)');
        $this->line('');

        $new = (string) $this->secret('Contraseña NUEVA (no se muestra)');
        $confirm = (string) $this->secret('Repítela para confirmar');

        if ($new !== $confirm) {
            $this->error('Las dos contraseñas no coinciden. No se ha cambiado nada.');

            return self::FAILURE;
        }

        if (($problem = $this->reject($new, $admin)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $admin->forceFill(['password' => $new])->save(); // el cast 'hashed' la bcrypt-ea
        $admin->refresh();

        // Comprobación real contra el hash almacenado, no contra lo que creemos
        // haber guardado.
        if (! Hash::check($new, (string) $admin->password)) {
            $this->error('El hash almacenado no valida la contraseña nueva. Revisa la cuenta.');

            return self::FAILURE;
        }

        $revoked = 0;
        if (! $this->option('keep-sessions')) {
            $revoked = AdminSession::query()
                ->where('admin_id', $admin->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'password_rotated']);
        }

        unset($new, $confirm);

        $this->line('');
        $this->info('  ✔ Contraseña rotada.');
        $this->line('  Hash nuevo        : '.$this->hashInfo((string) $admin->password));
        $this->line('  Sesiones revocadas: '.$revoked.($this->option('keep-sessions') ? ' (--keep-sessions)' : ''));
        $this->line('  La contraseña anterior ya no valida.');
        $this->line('');

        return self::SUCCESS;
    }

    /** Motivo de rechazo, o null si la contraseña es aceptable. */
    private function reject(string $password, Admin $admin): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Demasiado corta: '.mb_strlen($password).' caracteres, mínimo '.self::MIN_LENGTH.'.';
        }

        $classes = 0;
        foreach (['/[a-z]/u', '/[A-Z]/u', '/\d/', '/[^\p{L}\d]/u'] as $rx) {
            $classes += preg_match($rx, $password) ? 1 : 0;
        }
        if ($classes < 3) {
            return 'Poca variedad: usa al menos tres de minúsculas, mayúsculas, dígitos y símbolos.';
        }

        if (Hash::check($password, (string) $admin->password)) {
            return 'Es la misma contraseña que ya tenía la cuenta: no habría rotación.';
        }

        if (str_contains(mb_strtolower($password), mb_strtolower(explode('@', $admin->email)[0]))) {
            return 'Contiene el usuario del correo de la cuenta.';
        }

        return null;
    }

    /** Describe un hash sin revelarlo: algoritmo y coste, que no son secretos. */
    private function hashInfo(string $hash): string
    {
        $info = password_get_info($hash);
        $algo = $info['algoName'] ?? 'desconocido';
        $cost = $info['options']['cost'] ?? null;

        return $algo.($cost ? " (coste {$cost})" : '').', '.mb_strlen($hash).' caracteres';
    }
}
