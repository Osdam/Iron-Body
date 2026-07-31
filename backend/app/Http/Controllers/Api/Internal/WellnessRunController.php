<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\Notifications\WellnessPlanner;
use App\Support\Notifications\SendingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tanda de bienestar disparada por n8n (firmado HMAC).
 *
 * Sigue la misma regla que el resto de la automatización: **n8n decide CUÁNDO,
 * Laravel decide QUÉ y A QUIÉN**. Aquí no llega ni un texto ni una lista de
 * socios; n8n solo dice «ahora». Las preferencias, las horas de silencio, los
 * límites, la exclusión de menores y la idempotencia siguen viviendo en el
 * servidor, donde están los datos y donde se pueden probar.
 *
 * Es el mismo trabajo que hace `php artisan notifications:wellness`, así que
 * ambos caminos son intercambiables y la llave de idempotencia diaria impide
 * que alguien reciba dos avisos si por error se disparan los dos.
 *
 *  POST /api/internal/automation/wellness-run
 */
class WellnessRunController extends Controller
{
    public function __construct(private readonly WellnessPlanner $planner) {}

    public function run(Request $request): JsonResponse
    {
        // `dry_run` permite a n8n comprobar la conexión sin mandar nada.
        $data = $request->validate([
            'dry_run' => 'nullable|boolean',
        ]);

        if (! config('notifications.wellness.enabled', false)) {
            return response()->json([
                'ok' => false,
                'reason' => 'wellness_disabled',
                'message' => 'El módulo de bienestar está inerte (NOTIFICATIONS_WELLNESS_ENABLED=false).',
            ], 409);
        }

        $window = SendingWindow::describe();

        if ($data['dry_run'] ?? false) {
            return response()->json([
                'ok' => true,
                'dry_run' => true,
                'window' => $window,
                'message' => 'Conexión correcta. No se envió nada.',
            ]);
        }

        // Segunda barrera. Si n8n se dispara fuera de hora —por un cambio de
        // horario, un reintento o una ejecución manual— aquí no sale nada.
        // Devuelve 200 a propósito: no es un fallo del workflow, es el sistema
        // haciendo su trabajo, y marcarlo como error llenaría n8n de rojo.
        if (! SendingWindow::isOpen()) {
            Log::info('notifications.wellness.run', [
                'source' => 'n8n',
                'skipped' => 'outside_window',
            ] + $window);

            return response()->json([
                'ok' => true,
                'considered' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'skipped' => 'outside_window',
                'window' => $window,
                'next_opening' => SendingWindow::nextOpening()->toIso8601String(),
                'message' => sprintf(
                    'Fuera de la franja permitida (%02d:00–%02d:00 %s). No se envió nada.',
                    SendingWindow::startHour(),
                    SendingWindow::endHour(),
                    SendingWindow::timezone(),
                ),
            ]);
        }

        $stats = $this->planner->planDaily(CarbonImmutable::now());

        Log::info('notifications.wellness.run', $stats + ['source' => 'n8n'] + $window);

        return response()->json(['ok' => true, 'window' => $window] + $stats);
    }
}
