<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Services\Fcm\FcmHttpV1Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Envía un push FCM de prueba a los dispositivos de un miembro. Sirve para
 * validar end-to-end el envío real una vez colocado el service-account.json.
 *
 *   php artisan fcm:test                 # primer miembro con token
 *   php artisan fcm:test 1034778400      # por número de documento
 */
class FcmTest extends Command
{
    protected $signature = 'fcm:test {document? : Documento del miembro (por defecto, el primero con token)}';

    protected $description = 'Envía un push FCM de prueba a los dispositivos de un miembro (valida service-account + token).';

    public function handle(FcmHttpV1Client $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('FCM no está configurado.');
            $this->line('  → Coloca storage/app/firebase/service-account.json y pon FCM_ENABLED=true en .env, luego `php artisan config:clear`.');
            return self::FAILURE;
        }

        $doc = $this->argument('document');
        $member = $doc
            ? Member::where('document_number', $doc)->first()
            : Member::whereIn('id', MemberDeviceToken::query()->pluck('member_id'))->first();

        if (! $member) {
            $this->error('No se encontró el miembro (o ningún miembro tiene token FCM).');
            return self::FAILURE;
        }

        $tokens = MemberDeviceToken::query()->where('member_id', $member->id)->pluck('token');
        if ($tokens->isEmpty()) {
            $this->error("El miembro {$member->id} ({$member->full_name}) no tiene tokens FCM registrados.");
            return self::FAILURE;
        }

        $this->info("Proyecto: {$client->projectId()} · Enviando a {$member->full_name} ({$tokens->count()} token/s)…");

        $ok = 0;
        $fail = 0;
        foreach ($tokens as $token) {
            $unregistered = false;
            $sent = $client->send([
                'token'        => $token,
                'notification' => [
                    'title' => 'Iron Body — Prueba',
                    'body'  => 'Push de prueba ✅ Tu app está conectada a las notificaciones.',
                ],
                'data' => [
                    'type' => 'system',
                    'uuid' => (string) Str::uuid(),
                ],
                'android' => [
                    'priority'     => 'high',
                    'notification' => ['channel_id' => 'iron_body_high', 'sound' => 'default'],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ], $unregistered);

            if ($sent) {
                $ok++;
                $this->line('  ✓ ' . substr($token, 0, 16) . '…');
            } else {
                $fail++;
                $this->warn('  ✗ ' . substr($token, 0, 16) . '… ' . ($unregistered ? '(token inválido, eliminado)' : '(fallo de envío)'));
                if ($unregistered) {
                    MemberDeviceToken::query()->where('token', $token)->delete();
                }
            }
        }

        $this->info("Resultado: {$ok} enviados, {$fail} fallidos.");
        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }
}
