<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Controller;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioMetaTemplates;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategyConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ULTRON STUDIO: PLANTILLAS DE WHATSAPP DE META DESDE EL CRM (plan
 * PLANTILLAS_META_PLAN §5.8; contrato H9, L9-96): el estado de la conexión, la
 * sonda de permisos, enviar a Meta una versión aprobada y «Comprobar ahora».
 * Las lecturas de las plantillas (lista y detalle, con su bloque `meta`) siguen
 * en {@see UltronStudioStrategyTemplateController}.
 *
 * LAS CERRADURAS: la puerta (`AuthorizationMap`, una ruta, una llave de
 * {@see ROUTES}) → una persona (`StudioAccess::requirePerson`) → la maestra de
 * estrategias (409 `strategies_disabled`, como todo `strategies/**`) → la
 * maestra de este módulo (409 `meta_templates_disabled`, salvo `status`) → la
 * llave dentro del servicio → la persona VERIFICADA. Enviar exige además su
 * bandera y `META_ENABLED`. Un fallo de Meta nunca es un 5xx: va en el DTO.
 */
class UltronStudioMetaTemplateController extends Controller
{
    /**
     * Las 4 rutas (de 121 a 125), con su permiso, una a una: la lista que
     * `AuthorizationMap` clasifica. Todas bajo `admin/ultron-studio/`.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const ROUTES = [
        ['GET', 'strategies/templates/meta/status', StudioAccess::VIEW],
        ['POST', 'strategies/templates/meta/probe', StudioAccess::INTEGRATIONS_MANAGE],
        ['POST', 'strategies/templates/{uuid}/meta/submit', StudioAccess::META_TEMPLATES_SUBMIT],
        ['POST', 'strategies/templates/{uuid}/meta/refresh', StudioAccess::META_TEMPLATES_SUBMIT],
    ];

    public const ROUTE_COUNT = 4;

    /**
     * EL BLOQUE DE RUTAS, montado con UNA línea dentro del grupo del Studio de
     * `routes/ultron-studio.php` (prefijo `admin/ultron-studio`, el tope de 64 KB):
     * el estado con el cupo de lectura; la sonda con el de escritura y el suyo
     * (`ultron-studio-meta-probe`, 6/h por persona y 20/h por red; no el de la
     * sonda de Twilio, PM-2); enviar y comprobar con
     * el de escritura y el suyo (`ultron-studio-meta`, 20/h por persona, 60/h
     * por red). `{uuid}` con `whereUuid`: no choca con el literal `meta`.
     */
    public static function routes(): void
    {
        Route::middleware('throttle:ultron-studio-read')->group(function (): void {
            Route::get('strategies/templates/meta/status', [self::class, 'status']);
        });
        Route::middleware('throttle:ultron-studio-write')->group(function (): void {
            Route::post('strategies/templates/meta/probe', [self::class, 'probe'])->middleware('throttle:ultron-studio-meta-probe');
            Route::post('strategies/templates/{uuid}/meta/submit', [self::class, 'submit'])->whereUuid('uuid')->middleware('throttle:ultron-studio-meta');
            Route::post('strategies/templates/{uuid}/meta/refresh', [self::class, 'refresh'])->whereUuid('uuid')->middleware('throttle:ultron-studio-meta');
        });
    }

    /**
     * El bloque de `AuthorizationMap::OVERRIDES` (una guarda lo compara con {@see ROUTES}).
     *
     * @return array<string, string>
     */
    public static function authorizationMap(): array
    {
        $mapa = [];
        foreach (self::ROUTES as [$metodo, $ruta, $llave]) {
            $mapa["{$metodo} api/admin/ultron-studio/{$ruta}"] = $llave;
        }

        return $mapa;
    }

    /** GET strategies/templates/meta/status: responde también con el módulo apagado (no con las estrategias apagadas). */
    public function status(Request $request, StudioMetaTemplates $meta): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => $meta->status($access)]);
    }

    /**
     * POST strategies/templates/meta/probe   (sin cuerpo)
     *
     * 201 con la sonda nueva; 200 `reused` dentro del enfriamiento (la última, sin red).
     */
    public function probe(Request $request, StudioMetaTemplates $meta, StudioPersonBindings $persons): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        StudioMetaTemplates::assertEnabled();

        $r = $meta->probe($access, $request, $persons, now());

        return response()->json(['ok' => true, 'data' => ['reused' => $r['reused'], 'probe' => $r['probe']]], $r['reused'] ? 200 : 201);
    }

    /**
     * POST strategies/templates/{uuid}/meta/submit   {clientRequestId}
     *
     * 201 con el detalle de la plantilla (su bloque `meta` dice cómo quedó el
     * envío: SUBMITTED, FAILED con el error de Meta o UNKNOWN); 200 `replayed`
     * si se repite la misma petición.
     */
    public function submit(Request $request, StudioMetaTemplates $meta, StudioPersonBindings $persons, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        StudioMetaTemplates::assertEnabled();
        $data = $request->validate(['clientRequestId' => ['required', 'uuid']]);

        $r = $meta->submit($access, $request, $persons, $uuid, $data['clientRequestId'], now());
        $dto = StudioMessageTemplates::detailDto($r['template'], $access);

        return $r['replayed']
            ? response()->json(['ok' => true, 'replayed' => true, 'data' => $dto])
            : response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /**
     * POST strategies/templates/{uuid}/meta/refresh   (sin cuerpo): «Comprobar ahora».
     *
     * 200 con el detalle y `check` {outcome: ok | not_found | failed, error, changed, rowWritten, channelAccepts}.
     */
    public function refresh(Request $request, StudioMetaTemplates $meta, StudioPersonBindings $persons, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        StudioMetaTemplates::assertEnabled();

        $r = $meta->refresh($access, $request, $persons, $uuid, now());

        return response()->json(['ok' => true, 'check' => $r['check'], 'data' => StudioMessageTemplates::detailDto($r['template'], $access)]);
    }
}
