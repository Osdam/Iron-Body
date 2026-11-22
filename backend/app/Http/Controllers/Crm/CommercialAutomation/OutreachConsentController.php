<?php

namespace App\Http\Controllers\Crm\CommercialAutomation;

use App\Http\Controllers\Controller;
use App\Models\OutreachConsent;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachConsentConfig;
use App\Services\Outreach\Run\OutreachConsents;
use App\Services\Outreach\Run\OutreachException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

/**
 * C9-3 · LAS AUTORIZACIONES DE CONTACTO en el CRM (Enmienda C9 del contrato
 * H9; pantalla «Autorizaciones de contacto»): `routes/commercial-automation.php`,
 * prefijo `/api/admin/commercial-automation`, como dice §7 del contrato para la
 * ejecución. El servicio es {@see OutreachConsents}.
 *
 * LAS CERRADURAS: la puerta (`AuthorizationMap`, una ruta, su llave de
 * {@see ROUTES}) → una persona → la llave otra vez dentro → la llave de VER a
 * la persona (en el servicio). Sin texto legal configurado, pedir y confirmar
 * dan 409 `consent_legal_text_missing` ANTES de validar el cuerpo y sin
 * escribir; rechazar y retirar no lo miran. Las validaciones dan 422 con los
 * campos, nunca sus valores.
 */
class OutreachConsentController extends Controller
{
    /**
     * Las 5 rutas, con su llave (o sus llaves: basta una), una a una: la lista
     * que `AuthorizationMap` tiene que clasificar. Bajo `admin/commercial-automation/`.
     *
     * @var list<array{0: string, 1: string, 2: string|list<string>}>
     */
    public const ROUTES = [
        ['GET', 'consents', [OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM]],
        ['POST', 'consents', OutreachAccess::CONSENTS_RECORD],
        ['POST', 'consents/{uuid}/confirm', OutreachAccess::CONSENTS_CONFIRM],
        ['POST', 'consents/{uuid}/reject', OutreachAccess::CONSENTS_CONFIRM],
        ['POST', 'consents/revoke', [OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM]],
    ];

    public const ROUTE_COUNT = 5;

    /** El cuerpo más grande (una petición con 300 caracteres de 4 bytes) no llega a 2 KB. */
    public const BODY_LIMIT = 16384;

    /** Los ids de persona que se aceptan (12 cifras: muy por encima de cualquier id real). */
    private const ID_MAX = 999999999999;

    /**
     * El bloque de rutas, montado en `routes/commercial-automation.php` dentro
     * de `admin/commercial-automation` con el tope de cuerpo. Lecturas con el
     * cupo `ultron-studio-read` y escrituras con `ultron-studio-write`, los de
     * la API de la ejecución. Sin route-model binding: el uuid es texto.
     */
    public static function routes(): void
    {
        Route::middleware('throttle:ultron-studio-read')->group(function (): void {
            Route::get('consents', [self::class, 'index']);
        });
        Route::middleware('throttle:ultron-studio-write')->group(function (): void {
            Route::post('consents', [self::class, 'store']);
            Route::post('consents/revoke', [self::class, 'revoke']);
            Route::post('consents/{uuid}/confirm', [self::class, 'confirm'])->whereUuid('uuid');
            Route::post('consents/{uuid}/reject', [self::class, 'reject'])->whereUuid('uuid');
        });
    }

    /**
     * El bloque literal de `AuthorizationMap::OVERRIDES` (una guarda lo compara con {@see ROUTES}).
     *
     * @return array<string, string|list<string>>
     */
    public static function authorizationMap(): array
    {
        $mapa = [];
        foreach (self::ROUTES as [$metodo, $ruta, $llave]) {
            $mapa["{$metodo} api/admin/commercial-automation/{$ruta}"] = $llave;
        }

        return $mapa;
    }

    /** GET consents?personType=member|lead&personId */
    public function index(Request $request, OutreachConsents $consents): JsonResponse
    {
        $access = self::access($request, [OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM]);
        $d = self::validated($request->query(), self::personRules());

        return response()->json(['ok' => true, 'data' => $consents->list($access, (string) $d['personType'], (int) $d['personId'])]);
    }

    /** POST consents {clientRequestId, personType, personId, purpose, source, evidence, legalVersion, legalHash}: 201; 200 `replayed` */
    public function store(Request $request, OutreachConsents $consents): JsonResponse
    {
        $access = self::access($request, [OutreachAccess::CONSENTS_RECORD]);
        self::assertSomeLegalText();
        $d = self::validated($request->all(), self::personRules() + [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'purpose' => ['required', 'string', 'in:'.implode(',', OutreachConsent::PURPOSES)],
            'source' => ['required', 'string', 'max:20'],
            'evidence' => ['required', 'string', 'max:'.OutreachConsents::EVIDENCE_MAX],
            'legalVersion' => ['required', 'string', 'max:48'],
            'legalHash' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/D'],
        ]);
        $r = $consents->record($access, [
            'clientRequestId' => (string) $d['clientRequestId'], 'personType' => (string) $d['personType'], 'personId' => (int) $d['personId'],
            'purpose' => (string) $d['purpose'], 'source' => (string) $d['source'], 'evidence' => (string) $d['evidence'],
            'legalVersion' => (string) $d['legalVersion'], 'legalHash' => (string) $d['legalHash'],
        ]);

        return response()->json(['ok' => true, 'data' => $r['consent'], 'meta' => ['replayed' => $r['replayed'], 'legal' => $r['legal'] ?? null]], $r['http']);
    }

    /** POST consents/{uuid}/confirm {clientRequestId}: OTRA cuenta que la que la registró. 201; 200 `replayed` */
    public function confirm(Request $request, OutreachConsents $consents, string $uuid): JsonResponse
    {
        $access = self::access($request, [OutreachAccess::CONSENTS_CONFIRM]);
        self::assertSomeLegalText();
        $d = self::validated($request->all(), ['clientRequestId' => ['required', 'string', 'uuid']]);
        $r = $consents->confirm($access, $uuid, (string) $d['clientRequestId']);

        return response()->json(['ok' => true, 'data' => $r['consent'], 'meta' => ['replayed' => $r['replayed']]], $r['http']);
    }

    /** POST consents/{uuid}/reject {clientRequestId, evidence}: 201; 200 `replayed` */
    public function reject(Request $request, OutreachConsents $consents, string $uuid): JsonResponse
    {
        $access = self::access($request, [OutreachAccess::CONSENTS_CONFIRM]);
        $d = self::validated($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'evidence' => ['required', 'string', 'max:'.OutreachConsents::EVIDENCE_MAX],
        ]);
        $r = $consents->reject($access, $uuid, (string) $d['clientRequestId'], (string) $d['evidence']);

        return response()->json(['ok' => true, 'data' => $r['consent'], 'meta' => ['replayed' => $r['replayed']]], $r['http']);
    }

    /** POST consents/revoke {clientRequestId, personType, personId, purpose, source, evidence}: sin segunda cuenta. 201; 200 `replayed` */
    public function revoke(Request $request, OutreachConsents $consents): JsonResponse
    {
        $access = self::access($request, [OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM]);
        $d = self::validated($request->all(), self::personRules() + [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'purpose' => ['required', 'string', 'in:'.implode(',', OutreachConsent::PURPOSES)],
            'source' => ['required', 'string', 'in:'.implode(',', OutreachConsent::SOURCES)],
            'evidence' => ['required', 'string', 'max:'.OutreachConsents::EVIDENCE_MAX],
        ]);
        $r = $consents->revoke($access, [
            'clientRequestId' => (string) $d['clientRequestId'], 'personType' => (string) $d['personType'], 'personId' => (int) $d['personId'],
            'purpose' => (string) $d['purpose'], 'source' => (string) $d['source'], 'evidence' => (string) $d['evidence'],
        ]);

        return response()->json(['ok' => true, 'data' => $r['consent'], 'meta' => ['replayed' => $r['replayed']]], $r['http']);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Una persona con UNA de las llaves (la puerta ya lo comprobó; aquí, otra vez).
     *
     * @param  non-empty-list<string>  $llaves
     *
     * @throws OutreachException 403
     */
    private static function access(Request $request, array $llaves): OutreachAccess
    {
        return OutreachAccess::requireAny($request, $llaves);
    }

    /** @throws OutreachException 409 `consent_legal_text_missing` sin ningún texto legal configurado */
    private static function assertSomeLegalText(): void
    {
        if (! OutreachConsentConfig::anyLegalText()) {
            throw OutreachException::conflict(OutreachConsentConfig::CODE_LEGAL_TEXT_MISSING,
                'Todavía no hay un texto legal aprobado para las autorizaciones de contacto: lo fija el propietario con su asesoría. No se ha guardado nada.');
        }
    }

    /** @return array<string, list<string>> */
    private static function personRules(): array
    {
        return [
            'personType' => ['required', 'string', 'in:'.implode(',', OutreachConsents::PERSON_TYPES)],
            'personId' => ['required', 'integer', 'min:1', 'max:'.self::ID_MAX],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $reglas
     * @return array<string, mixed>
     *
     * @throws OutreachException 422 con los campos, sin sus valores
     */
    private static function validated(array $datos, array $reglas): array
    {
        $v = Validator::make($datos, $reglas);
        if ($v->fails()) {
            throw OutreachException::invalid(array_map(static fn (array $m): array => ['invalid'], $v->errors()->toArray()));
        }

        return $v->validated();
    }
}
