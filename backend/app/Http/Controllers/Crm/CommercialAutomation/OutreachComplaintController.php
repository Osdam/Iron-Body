<?php

namespace App\Http\Controllers\Crm\CommercialAutomation;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Member;
use App\Observers\Commercial\ComplaintCommercialEmitter;
use App\Services\Commercial\CommercialVocabulary;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectResolver;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachConsents;
use App\Services\Outreach\Run\OutreachException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

/**
 * Q1-8 · REGISTRAR UNA QUEJA presencial, por teléfono u otra (Enmienda Q1-8 del
 * contrato H9; botón «Registrar queja» de la ficha). La escribe
 * {@see ComplaintCommercialEmitter} como `complaint_created` con
 * `source = staff`, la misma que N6 (`complaint_recent`) ya lee de la IA: el
 * contacto automático comercial y de cobranza a esa persona se para durante la
 * ventana; el servicio no cambia.
 *
 * LAS CERRADURAS, como las autorizaciones de contacto (C9-3): la puerta
 * (`AuthorizationMap`, la llave de {@see ROUTES}) → una persona → la llave otra
 * vez → la llave de VER a la persona (`members.view` o `marketing.view`, y la
 * de socios si el prospecto es de un socio), ANTES de leer nada de ella: sin
 * ella, 403 sin decir si existe. Con el registro de hechos apagado, 409
 * `commercial_events_disabled` y no escribe. Un socio es su `users.id`, como
 * en las autorizaciones (PT-18).
 *
 * SIN TEXTO LIBRE: la persona, el medio y una categoría de listas cerradas.
 * IDEMPOTENTE por `clientRequestId` (200 `replayed`; con otro contenido, 409
 * `client_request_reused`). Una al día de Bogotá por persona: la segunda del
 * día responde igual que la primera (201) y no cambia la ventana; la respuesta
 * no dice si ya había otra (no es un oráculo de «quién se quejó»). Un TOPE
 * DIARIO POR CUENTA (NB3, `OutreachConfig::staffComplaintsPerAccountPerDay()`,
 * 20 por defecto): pasado, 429 `complaint_daily_limit_reached` y no escribe;
 * repetir una petición ya hecha no cuenta.
 */
class OutreachComplaintController extends Controller
{
    /** @var list<array{0: string, 1: string, 2: string}> Bajo `admin/commercial-automation/`. */
    public const ROUTES = [
        ['POST', 'complaints', OutreachAccess::COMPLAINTS_RECORD],
    ];

    public const ROUTE_COUNT = 1;

    /** Los ids de persona que se aceptan (12 cifras, como las autorizaciones). */
    private const ID_MAX = 999999999999;

    /** Montado en `routes/commercial-automation.php`, con el cupo de escritura de la API de la ejecución. */
    public static function routes(): void
    {
        Route::middleware('throttle:ultron-studio-write')->group(function (): void {
            Route::post('complaints', [self::class, 'store']);
        });
    }

    /** @return array<string, string> el bloque literal de `AuthorizationMap::OVERRIDES` */
    public static function authorizationMap(): array
    {
        $mapa = [];
        foreach (self::ROUTES as [$metodo, $ruta, $llave]) {
            $mapa["{$metodo} api/admin/commercial-automation/{$ruta}"] = $llave;
        }

        return $mapa;
    }

    /** POST complaints {clientRequestId, personType, personId, channel, category}: 201; 200 `replayed` */
    public function store(Request $request, ComplaintCommercialEmitter $emitter, SubjectResolver $resolver): JsonResponse
    {
        $access = OutreachAccess::requireAny($request, [OutreachAccess::COMPLAINTS_RECORD]);
        if (! ComplaintCommercialEmitter::enabled()) {
            throw OutreachException::conflict('commercial_events_disabled',
                'El registro de hechos comerciales está apagado en el servidor: la queja no se puede registrar ahora. No se ha guardado nada.');
        }
        $v = Validator::make($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'personType' => ['required', 'string', 'in:'.implode(',', OutreachConsents::PERSON_TYPES)],
            'personId' => ['required', 'integer', 'min:1', 'max:'.self::ID_MAX],
            'channel' => ['required', 'string', 'in:'.implode(',', ComplaintCommercialEmitter::CHANNELS)],
            'category' => ['required', 'string', 'in:'.implode(',', ComplaintCommercialEmitter::CATEGORIES)],
        ]);
        if ($v->fails()) {
            throw OutreachException::invalid(array_map(static fn (array $m): array => ['invalid'], $v->errors()->toArray()));
        }
        $d = $v->validated();
        $p = self::person($access, $resolver, (string) $d['personType'], (int) $d['personId']);
        $crid = strtolower((string) $d['clientRequestId']);
        $canal = (string) $d['channel'];
        $categoria = (string) $d['category'];

        $previa = $emitter->staffComplaintByRequest($crid);
        if ($previa !== null) {
            $suya = $p['canonical']['type'] === OutreachConsents::PERSON_MEMBER
                ? $previa['member_id'] === $p['canonical']['id']
                : $previa['marketing_lead_id'] === $p['canonical']['id'];
            $igual = $suya && ($previa['payload']['recorded_by'] ?? null) === $access->id()
                && ($previa['payload']['channel'] ?? null) === $canal && ($previa['payload']['category'] ?? null) === $categoria;
            if (! $igual) {
                throw OutreachException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
            }

            return self::respond($p, $canal, $categoria, $previa['occurred_at'], true, 200);
        }

        $ahora = Carbon::now();
        $tope = OutreachConfig::staffComplaintsPerAccountPerDay();
        // NB3: el tope diario POR CUENTA, contado y registrado con la fila de la cuenta bloqueada, para que dos
        // peticiones a la vez de la misma cuenta no lo pasen. El registrador escribe en su punto de guardado: un
        // duplicado del día no aborta esta transacción (PostgreSQL).
        $resultado = DB::transaction(function () use ($emitter, $access, $p, $canal, $categoria, $crid, $ahora, $tope): string {
            Admin::query()->whereKey($access->id())->lockForUpdate()->first(['id']);
            if ($emitter->staffComplaintsRecordedBy($access->id(), $ahora) >= $tope) {
                Log::warning('commercial.complaint.staff_daily_limit_reached', ['by' => $access->id(), 'limit' => $tope]);

                throw new OutreachException("Ya registraste {$tope} quejas hoy, el tope diario de una cuenta. No se ha guardado nada: si hace falta registrar más, pídeselo a un Super Admin.",
                    'complaint_daily_limit_reached', 429, ['limit' => $tope]);
            }
            try {
                return $emitter->recordStaffComplaint($p['canonical']['type'], $p['canonical']['id'], $canal, $categoria, $access->id(), $crid, $ahora);
            } catch (\RuntimeException $e) {
                Log::error('commercial.complaint.staff_record_failed', ['by' => $access->id(), 'exception' => class_basename($e)]);

                throw new OutreachException('No se pudo registrar la queja. Inténtalo de nuevo en un momento.', 'complaint_not_recorded', 503);
            }
        });
        // NB3: sobre quién fue, con su identificador interno (tipo e id de la persona canónica); nunca nombre ni teléfono.
        Log::info('commercial.complaint.staff_recorded', ['channel' => $canal, 'category' => $categoria, 'result' => $resultado, 'by' => $access->id(),
            'subject_type' => $p['canonical']['type'], 'subject_id' => $p['canonical']['id']]);

        return self::respond($p, $canal, $categoria, $ahora, false, 201);
    }

    /**
     * La persona: primero la llave de verla (sin ella, 403 sin leer nada);
     * después, 404 si no es nadie. Un prospecto de un socio se registra como el
     * socio, y verlo exige también ver socios (como las autorizaciones de C9-3).
     * Un socio es su `users.id` (PT-18, {@see OutreachConsents::requestedSubject()}):
     * `canonical` es el sujeto donde se escribe (`members.id` o `marketing_leads.id`)
     * y `recordsAs`, cómo lo nombra el CRM.
     *
     * @return array{type: string, id: int, canonical: array{type: string, id: int}, recordsAs: array{type: string, id: int}}
     *
     * @throws OutreachException
     */
    private static function person(OutreachAccess $access, SubjectResolver $resolver, string $type, int $id): array
    {
        self::assertMayView($access, $type);
        $s = OutreachConsents::requestedSubject($resolver, $type, $id);
        if ($s === null) {
            throw OutreachException::notFound('person');
        }
        $canonica = SubjectKey::parse((string) $s->key);
        $tipo = $canonica['kind'] === SubjectKey::MEMBER ? OutreachConsents::PERSON_MEMBER : OutreachConsents::PERSON_LEAD;
        self::assertMayView($access, $tipo);

        return ['type' => $type, 'id' => $id, 'canonical' => ['type' => $tipo, 'id' => (int) $canonica['id']],
            'recordsAs' => OutreachConsents::recordsAs($s, $type, $id)];
    }

    /** @throws OutreachException 403 */
    private static function assertMayView(OutreachAccess $access, string $type): void
    {
        $llave = OutreachConsents::PERSON_VIEW[$type] ?? null;
        if ($llave === null) {
            throw OutreachException::invalid(['personType' => ['in']]);
        }
        if (! $access->can($llave)) {
            throw OutreachException::forbidden($llave);
        }
    }

    /** @param  array{type: string, id: int, canonical: array{type: string, id: int}, recordsAs: array{type: string, id: int}}  $p */
    private static function respond(array $p, string $canal, string $categoria, Carbon $cuando, bool $replayed, int $http): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => [
            'personType' => $p['type'],
            'personId' => $p['id'],
            'recordsAs' => $p['recordsAs'],
            'channel' => $canal,
            'category' => $categoria,
            'source' => CommercialVocabulary::COMPLAINT_SOURCE_STAFF,
            // El día de Bogotá de la queja; la ventana cuenta desde la última de la persona.
            'day' => $cuando->copy()->setTimezone(Member::BUSINESS_TZ)->format('Y-m-d'),
            'brake' => ['days' => OutreachConfig::complaintDays(), 'purposes' => OutreachConfig::complaintPurposes()],
        ], 'meta' => ['replayed' => $replayed]], $http);
    }
}
