<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\NotificationDispatch;
use App\Services\Fcm\FcmHttpV1Client;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * Diagnóstico de push de extremo a extremo, en un solo comando.
 *
 * Nace de que la entrega en iPhone no se pudo verificar desde el servidor: FCM
 * devuelve 200 tanto si Apple entrega como si lo descarta, así que hace falta
 * el dispositivo delante. Esto reúne todo lo comprobable sin él y deja el envío
 * de prueba a un toque.
 *
 *   php artisan push:doctor                 # salud general
 *   php artisan push:doctor 9999999999      # el socio de ese documento
 *   php artisan push:doctor 9999999999 --send=ios
 */
class PushDoctor extends Command
{
    protected $signature = 'push:doctor
        {document? : Documento del socio a revisar}
        {--send= : Envía una prueba real: android, ios o all}';

    protected $description = 'Revisa la salud del sistema de push y, si se pide, envía una prueba real.';

    public function handle(FcmHttpV1Client $client, NotificationDispatcher $dispatcher): int
    {
        $this->components->info('Configuración del servidor');
        $this->line('  proyecto FCM   : '.($client->projectId() ?? '(sin determinar)'));
        $this->line('  configurado    : '.($client->isConfigured() ? 'sí' : 'NO — revisa FCM_ENABLED y el service account'));

        if (! $client->isConfigured()) {
            $this->error('Sin credenciales no hay nada más que revisar.');

            return self::FAILURE;
        }

        // El token OAuth es la prueba de que la clave privada firma bien.
        $ref = new ReflectionClass($client);
        $method = $ref->getMethod('accessToken');
        $method->setAccessible(true);
        $access = $method->invoke($client);
        $this->line('  token OAuth    : '.($access ? 'obtenido' : 'NO — la cuenta de servicio no autentica'));

        $this->line('  bienestar      : '.(config('notifications.wellness.enabled') ? 'activo' : 'inerte'));
        $this->newLine();

        $document = $this->argument('document');
        $member = $document ? Member::where('document_number', $document)->first() : null;

        if ($document && ! $member) {
            $this->error("No hay ningún socio con el documento {$document}.");

            return self::FAILURE;
        }

        $member ? $this->reportMember($member) : $this->reportFleet();

        $target = $this->option('send');
        if ($target === null) {
            return self::SUCCESS;
        }

        if (! $member) {
            $this->error('Para enviar una prueba hay que indicar el documento del socio.');

            return self::FAILURE;
        }

        return $this->sendTest($dispatcher, $member, $target);
    }

    private function reportFleet(): void
    {
        $this->components->info('Dispositivos registrados');

        foreach (['android', 'ios'] as $platform) {
            $activos = MemberDeviceToken::where('platform', $platform)->where('is_active', true)->count();
            $muertos = MemberDeviceToken::where('platform', $platform)->where('is_active', false)->count();
            $this->line(sprintf('  %-8s activos: %-4d  desactivados: %d', $platform, $activos, $muertos));
        }

        $this->newLine();
        $this->components->info('Últimos 7 días');
        $enviadas = NotificationDispatch::sent()->where('created_at', '>=', now()->subWeek())->count();
        $this->line("  enviadas       : {$enviadas}");

        $motivos = NotificationDispatch::query()
            ->where('created_at', '>=', now()->subWeek())
            ->where('status', NotificationDispatch::STATUS_SUPPRESSED)
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        foreach ($motivos as $motivo => $total) {
            $this->line(sprintf('  no enviadas    : %-20s %d', $motivo, $total));
        }

        $this->newLine();
        $this->line('  Para revisar un socio: php artisan push:doctor <documento>');
    }

    private function reportMember(Member $member): void
    {
        $this->components->info("Socio {$member->id} — {$member->full_name}");
        $this->line('  estado         : '.$member->status);

        $tokens = MemberDeviceToken::where('member_id', $member->id)->orderByDesc('updated_at')->get();
        if ($tokens->isEmpty()) {
            $this->warn('  Sin dispositivos registrados: no puede recibir nada.');

            return;
        }

        foreach ($tokens as $t) {
            $dias = $t->updated_at?->diffInDays(now()) ?? 0;
            $this->line(sprintf(
                '  %-8s %s · permiso %s · registrado hace %d día(s) · %s…%s',
                $t->platform,
                $t->is_active ? 'ACTIVO  ' : 'inactivo',
                $t->notification_permission ?? '?',
                $dias,
                substr($t->token, 0, 6),
                substr($t->token, -4),
            ));

            // Un token iOS que lleva mucho sin refrescarse es el sospechoso
            // habitual cuando "antes llegaba y ahora no": si el iPhone no ha
            // abierto la app, nadie ha vuelto a registrarlo.
            if ($t->platform === 'ios' && $t->is_active && $dias >= 7) {
                $this->warn("    ↳ lleva {$dias} días sin refrescarse. Abre la app en el iPhone para renovarlo.");
            }
        }

        $hoy = NotificationDispatch::where('member_id', $member->id)->sent()
            ->where('created_at', '>=', now()->subDay())->count();
        $this->newLine();
        $this->line("  cupo diario    : {$hoy} usadas");
    }

    private function sendTest(NotificationDispatcher $dispatcher, Member $member, string $target): int
    {
        if (! in_array($target, ['android', 'ios', 'all'], true)) {
            $this->error('--send debe ser android, ios o all.');

            return self::FAILURE;
        }

        // Aviso de seguridad a propósito: es la única categoría que ignora
        // preferencias y horas de silencio, así que una prueba nunca se queda
        // callada por un ajuste del socio y confunde el diagnóstico.
        $this->components->info("Enviando prueba a {$target}…");

        $antes = MemberDeviceToken::where('member_id', $member->id)->where('is_active', true)->get();
        if ($target !== 'all') {
            $otras = $antes->where('platform', '!=', $target);
            if ($otras->isNotEmpty()) {
                $this->warn(sprintf(
                    '  Aviso: el socio tiene %d dispositivo(s) de otra plataforma. El envío va a TODOS '
                    .'sus dispositivos activos, así que también llegará allí.',
                    $otras->count(),
                ));
            }
        }

        $dispatch = $dispatcher->dispatch(
            memberId: $member->id,
            category: NotificationCategory::ACCOUNT_SECURITY,
            title: 'Iron Body — Prueba de notificaciones',
            body: 'Si ves esto, la entrega funciona en este dispositivo.',
            idempotencyKey: 'doctor:'.$member->id.':'.now()->timestamp,
        );

        $this->line("  resultado      : {$dispatch->status}".($dispatch->reason ? " ({$dispatch->reason})" : ''));
        $this->line("  dispositivos   : {$dispatch->tokens_delivered} de {$dispatch->tokens_targeted}");

        if ($dispatch->status !== NotificationDispatch::STATUS_SENT) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  FCM aceptó el mensaje. Eso NO garantiza que el sistema operativo lo muestre:');
        $this->line('  en iPhone, Apple puede descartarlo sin avisar si falta la clave APNs (.p8)');
        $this->line('  en Firebase, o si el permiso está denegado. Mira el dispositivo.');

        return self::SUCCESS;
    }
}
