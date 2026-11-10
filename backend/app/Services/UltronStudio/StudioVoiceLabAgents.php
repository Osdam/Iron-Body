<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceLabSession;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use stdClass;
use Throwable;

/**
 * Los AGENTES DE LABORATORIO del laboratorio hablado (contrato H8, D8-7 y
 * D8-23): «misma compilación, sin número y con `purpose = lab`, y nunca se
 * usan para llamadas».
 *
 *  1. PREPARAR ({@see prepare()}): la versión y el perfil, la compilación de
 *     S8 con el propósito `lab` (admite además la versión enviada a revisión y
 *     el perfil `submitted`: D8-23; el propósito no entra en la
 *     especificación, así que lo que se prueba y lo que se publique de la
 *     misma versión y el mismo perfil tienen el MISMO `specHash`, que es lo
 *     que exigirá la publicación, P-19), el lector estricto de B8 y la carga
 *     de ElevenLabs con el mapeador puro de B8. Nada de red ni de base salvo
 *     las dos lecturas.
 *  2. INSERTAR la fila `pending` del agente ({@see insertPending()}) dentro
 *     de la transacción de la reserva: UN AGENTE POR SESIÓN, con el mismo
 *     `client_request_id` («un reintento encuentra el suyo»). Así el informe
 *     post-llamada de un agente es de una sola sesión, sin ambigüedad.
 *  3. DESPLEGAR ({@see deploy()}), FUERA de toda transacción: en `fake`, un
 *     agente simulado sin red; en `elevenlabs`, `createAgent` y después
 *     `describeAgent`: la proyección observada tiene que ser la compilada
 *     (D8-7, paso 3) o el agente queda `rejected` y la sesión no se abre. Un
 *     agente nace `pending` y se marca `deployed` en el MISMO UPDATE que su
 *     observación (informe de DB8, §11).
 *  4. LA URL FIRMADA ({@see issueUrl()}), con el emisor de B8: caduca a los 15
 *     minutos, se entrega una vez y no se guarda.
 *
 * Desplegar y pedir la URL solo se hace desde aquí y desde la publicación de
 * S8 (guarda de SEC8: `TelephonyIsolationTest`, sección 7). El agente nunca
 * recibe número: `assignNumber` no se nombra en este fichero.
 *
 * DATOS PERSONALES: la carga lleva en claro el prompt y, si los hubiera, los
 * destinos de transferencia; vive en memoria y se descarta. De ella se guardan
 * solo las huellas.
 *
 * LOS DESTINOS DE TRANSFERENCIA (E10-10, S8-d): un perfil con destinos SÍ se
 * prueba aquí, con la MISMA compilación que se publicará (el `specHash` que
 * exige la puerta P-19). Los números se descifran en memoria
 * ({@see StudioVoiceProfiles::transferNumbers()}), se comprueban contra los
 * destinos aprobados de la especificación (R-07,
 * {@see StudioVoiceCompiler::assertDestinationsMatch()}) y van en la carga
 * del agente de laboratorio (`transfer_to_number` en conferencia), como en la
 * publicación. EL LABORATORIO NO TRANSFIERE DE VERDAD, por dos razones que no
 * dependen del modelo:
 *  - la sesión es una conversación web (URL firmada), no una llamada, y
 *    ElevenLabs solo ofrece `transfer_to_number` en llamadas telefónicas
 *    (documentación oficial: «only available for phone calls»);
 *  - `solicitar_humano` llega a Laravel con el `conversation_id` de la sesión,
 *    que no es una llamada de `voice_calls`: la herramienta responde con su
 *    negativa neutra (`call_not_found`) y el prompt manda no pasar la llamada
 *    sin un «sí» de esa herramienta. Si el interlocutor pide una persona, el
 *    agente le dice que ahora no hay nadie disponible y que puede escribir a
 *    Iron Body por WhatsApp (lo que se evalúa en el laboratorio).
 * El agente de laboratorio deja `transfer_destination_hashes` en NULL (E7-3):
 * nunca entra en la cadena de publicación.
 */
final class StudioVoiceLabAgents
{
    public const AGENTS = 'ultron_studio_voice_agents';

    public const PROFILES = 'ultron_studio_voice_profiles';

    public const PURPOSE_LAB = 'lab';

    public const PROVIDER_FAKE = 'fake';

    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DEPLOYED = 'deployed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** Las columnas del agente que se enseñan con una sesión. */
    public const PRESENTED_COLUMNS = [
        'id', 'uuid', 'purpose', 'studio_version_id', 'studio_content_hash', 'voice_profile_id', 'voice_profile_hash',
        'compiler_version', 'spec_hash', 'provider', 'provider_agent_id', 'status', 'failure_code', 'created_by_admin_id',
    ];

    /**
     * Prepara un agente de laboratorio: resuelve, compila y arma la carga.
     *
     * @return array{version:StudioVersion,profile:stdClass,spec:VoiceAgentSpec,payload:VoiceAgentPayload,provider:string}
     *
     * @throws UltronStudioException 404 (versión o perfil), 422 `voice_compile_refused`, 409 (duración, destinos ilegibles)
     */
    public function prepare(string $versionUuid, string $profileUuid, StudioVoiceLabConfig $cfg): array
    {
        $version = StudioVersion::query()->with('review')->where('uuid', strtolower($versionUuid))->first()
            ?? throw UltronStudioException::versionNotFound();
        $perfil = DB::table(self::PROFILES)
            ->where('uuid', strtolower($profileUuid))
            ->first(['id', 'uuid', 'label', 'revision', 'status', 'content', 'content_hash', 'created_by_admin_id', 'reviewed_by_admin_id', 'transfer_count'])
            ?? throw StudioVoiceLabErrors::profileNotFound();

        // El compilador no ve teléfonos: los destinos son alias y huellas.
        $compilada = StudioVoiceCompiler::compile(self::versionInput($version), self::profileInput($perfil), $cfg->perCallMaxSeconds, StudioVoiceCompiler::PURPOSE_LAB);
        $spec = VoiceAgentSpec::fromCompiled($compilada);

        // D8-23: como mucho 5 minutos por sesión. El agente corta la
        // conversación en su `max_duration_seconds`, que es el del perfil: si
        // pasa del tope de la sesión, la reserva no cubriría la conversación.
        if ($spec->maxDurationSeconds() > $cfg->maxSessionSeconds) {
            throw StudioVoiceLabErrors::durationExceedsSession($spec->maxDurationSeconds(), $cfg->maxSessionSeconds);
        }

        $proveedor = $cfg->isElevenLabs() ? self::PROVIDER_ELEVENLABS : self::PROVIDER_FAKE;

        return [
            'version' => $version,
            'profile' => $perfil,
            'spec' => $spec,
            'payload' => ElevenLabsAgentMapper::payload($spec, self::target($cfg, self::transferNumbers($perfil, $spec))),
            'provider' => $proveedor,
        ];
    }

    /**
     * E10-10: los teléfonos de los destinos aprobados, DESCIFRADOS EN MEMORIA
     * para la carga (D8-14: «en claro solo dentro de la carga compilada») y
     * comprobados contra la especificación (R-07: exactamente los aprobados,
     * cada uno con su huella). Sin destinos, nada que descifrar. Nunca se
     * guardan, se devuelven ni se registran.
     *
     * @return array<string, string> ref (huella) → E.164
     *
     * @throws UltronStudioException 409 `voice_destinations_unreadable`, 422 `transfer_destination_not_in_profile`
     */
    private static function transferNumbers(stdClass $perfil, VoiceAgentSpec $spec): array
    {
        if ($spec->transferDestinations() === []) {
            return [];
        }
        try {
            // El modelo de S8 trae el cast cifrado; la lectura es la misma que al publicar.
            $numeros = StudioVoiceProfiles::transferNumbers(StudioVoiceProfile::query()->findOrFail((int) $perfil->id));
        } catch (Throwable $e) {
            // Tragado a propósito (sin el mensaje, que podría llevar el texto
            // cifrado): una `APP_KEY` rotada deja los destinos ilegibles y el
            // perfil hay que volver a crearlo. Nada se ha reservado todavía.
            Log::warning('ultron_studio.voice_lab_destinations_unreadable', ['error_class' => class_basename($e)]);

            throw StudioVoiceLabErrors::destinationsUnreadable();
        }
        StudioVoiceCompiler::assertDestinationsMatch($spec->toArray(), $numeros, StudioVoiceProfiles::phoneHash(...));

        return $numeros;
    }

    /**
     * La fila del agente, `pending`, dentro de la transacción de la reserva.
     *
     * @param  array{version:StudioVersion,profile:stdClass,spec:VoiceAgentSpec,payload:VoiceAgentPayload,provider:string}  $prepared
     * @param  array{id:int,name:string,role:?string}  $actor
     */
    public function insertPending(array $prepared, array $actor, string $clientRequestId): int
    {
        $ahora = now();

        return (int) DB::table(self::AGENTS)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => $clientRequestId,
            'purpose' => self::PURPOSE_LAB,
            'studio_version_id' => $prepared['version']->id,
            'studio_content_hash' => (string) $prepared['version']->content_hash,
            'voice_profile_id' => (int) $prepared['profile']->id,
            'voice_profile_hash' => (string) $prepared['profile']->content_hash,
            'compiler_version' => $prepared['spec']->compilerVersion(),
            'spec_hash' => $prepared['spec']->specHash,
            'payload_hash' => $prepared['payload']->payloadHash,
            'projection_hash' => $prepared['payload']->projectionHash,
            'provider' => $prepared['provider'],
            'provider_agent_id' => null,
            'observed_hash' => null,
            'observed_at' => null,
            'status' => self::STATUS_PENDING,
            'failure_code' => null,
            'serves_canary' => false,
            'serves_rest' => false,
            'created_by_admin_id' => $actor['id'],
            'created_by_name' => $actor['name'],
            'created_by_role' => $actor['role'],
            'created_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * Despliega el agente `pending`. FUERA de toda transacción: aquí puede
     * haber red.
     *
     * @param  array{version:StudioVersion,profile:stdClass,spec:VoiceAgentSpec,payload:VoiceAgentPayload,provider:string}  $prepared
     * @return array{ok:true,providerAgentId:string}|array{ok:false,endReason:string}
     */
    public function deploy(int $agentId, array $prepared): array
    {
        $carga = $prepared['payload'];

        if ($prepared['provider'] === self::PROVIDER_FAKE) {
            // El simulador: ni ElevenLabs ni red. Su «observación» es la
            // compilada: no hay proveedor que la pueda cambiar.
            $id = 'fake_lab_'.bin2hex(random_bytes(12));
            $this->markDeployed($agentId, $id, $carga->projectionHash);

            return ['ok' => true, 'providerAgentId' => $id];
        }

        $desplegador = VoiceAdapters::deployer();
        $creado = $desplegador->createAgent($carga);
        if ($creado->outcome !== VoiceDeployResult::OUTCOME_CREATED || $creado->agentId === null) {
            // Un tiempo agotado o un 5xx (`ambiguous`) pueden haber dejado un
            // agente en el proveedor: inerte (sin número, con autenticación) y
            // sin URL nunca habla con nadie.
            $this->markFailed($agentId, 'create_'.$creado->outcome);

            return ['ok' => false, 'endReason' => $creado->outcome === VoiceDeployResult::OUTCOME_AMBIGUOUS ? 'agent_create_ambiguous' : 'agent_not_created'];
        }
        // El id, una vez y antes de leerlo: si el proceso muere aquí, la fila
        // dice qué agente existe en el proveedor.
        DB::table(self::AGENTS)->where('id', $agentId)->where('status', self::STATUS_PENDING)->whereNull('provider_agent_id')
            ->update(['provider_agent_id' => $creado->agentId, 'updated_at' => now()]);

        $observado = $desplegador->describeAgent($creado->agentId);
        if ($observado->projectionHash === null) {
            $this->markFailed($agentId, 'describe_'.$observado->outcome);

            return ['ok' => false, 'endReason' => 'agent_unverified'];
        }
        if (! $observado->matches($carga)) {
            // D8-7, paso 3: lo que guardó el proveedor no es lo compilado.
            $ahora = now();
            DB::table(self::AGENTS)->where('id', $agentId)->where('status', self::STATUS_PENDING)->update([
                'observed_hash' => $observado->projectionHash,
                'observed_at' => $ahora,
                'status' => self::STATUS_REJECTED,
                'status_changed_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            return ['ok' => false, 'endReason' => 'agent_drift'];
        }
        $this->markDeployed($agentId, null, $observado->projectionHash);

        return ['ok' => true, 'providerAgentId' => $creado->agentId];
    }

    /**
     * La URL firmada de una sesión del agente desplegado, con el emisor de B8.
     * En `fake` no hay URL (el simulador no habla).
     *
     * @return array{ok:true,signedUrl:?string}|array{ok:false,endReason:string}
     */
    public function issueUrl(string $provider, string $providerAgentId): array
    {
        if ($provider === self::PROVIDER_FAKE) {
            return ['ok' => true, 'signedUrl' => null];
        }

        $sesion = VoiceAdapters::labSessionIssuer()->signedSessionUrl($providerAgentId);
        if ($sesion->outcome !== VoiceLabSession::OUTCOME_ISSUED) {
            return ['ok' => false, 'endReason' => 'url_'.$sesion->outcome];
        }

        return ['ok' => true, 'signedUrl' => $sesion->signedUrl()];
    }

    /**
     * El agente de una sesión, con lo que se enseña.
     */
    public static function find(int $agentId): ?stdClass
    {
        return DB::table(self::AGENTS)->where('id', $agentId)->first(self::PRESENTED_COLUMNS);
    }

    /**
     * Los agentes de laboratorio de varias sesiones, en UNA consulta.
     *
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    public static function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table(self::AGENTS)->whereIn('id', $ids)->get(self::PRESENTED_COLUMNS) as $a) {
            $out[(int) $a->id] = $a;
        }

        return $out;
    }

    /**
     * El agente de laboratorio con este id del proveedor, o null (los de
     * publicación no son de aquí).
     */
    public static function findLabByProviderId(string $providerAgentId): ?stdClass
    {
        if (! VoiceIds::isProviderId($providerAgentId)) {
            return null;
        }

        return DB::table(self::AGENTS)
            ->where('provider_agent_id', $providerAgentId)
            ->where('purpose', self::PURPOSE_LAB)
            ->first(self::PRESENTED_COLUMNS);
    }

    /**
     * Dónde y con qué límites se despliega el agente de laboratorio. Sin
     * número; `call_limits` de una conversación a la vez y una al día: el
     * agente es de una sola sesión. El del simulador apunta a un dominio que
     * no resuelve: sus huellas existen, sus URL nunca se llaman. Los destinos
     * de transferencia, descifrados y comprobados (E10-10), como al publicar.
     *
     * @param  array<string, string>  $transferNumbers  ref → E.164
     */
    private static function target(StudioVoiceLabConfig $cfg, #[\SensitiveParameter] array $transferNumbers): VoiceAgentTarget
    {
        if ($cfg->isElevenLabs()) {
            return new VoiceAgentTarget(
                VoiceAgentTarget::PURPOSE_LAB,
                (string) $cfg->webhookBaseUrl,
                $cfg->toolSecretRef,
                $cfg->initiationSecretRef,
                $cfg->postCallWebhookRef,
                1,
                StudioVoiceLabConfig::LAB_CONCURRENCY,
                $transferNumbers,
            );
        }

        return new VoiceAgentTarget(
            VoiceAgentTarget::PURPOSE_LAB,
            StudioVoiceLabConfig::FAKE_WEBHOOK_BASE,
            StudioVoiceLabConfig::FAKE_TOOL_SECRET_REF,
            StudioVoiceLabConfig::FAKE_INITIATION_SECRET_REF,
            null,
            1,
            StudioVoiceLabConfig::LAB_CONCURRENCY,
            $transferNumbers,
        );
    }

    /** `pending` → `deployed` en un solo UPDATE con su observación (y, en `fake`, su id). */
    private function markDeployed(int $agentId, ?string $providerAgentId, string $observedHash): void
    {
        $ahora = now();
        DB::table(self::AGENTS)->where('id', $agentId)->where('status', self::STATUS_PENDING)->update(array_filter([
            'provider_agent_id' => $providerAgentId,
            'observed_hash' => $observedHash,
            'observed_at' => $ahora,
            'status' => self::STATUS_DEPLOYED,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ], static fn (mixed $v): bool => $v !== null));
    }

    private function markFailed(int $agentId, string $failureCode): void
    {
        $ahora = now();
        DB::table(self::AGENTS)->where('id', $agentId)->where('status', self::STATUS_PENDING)->update([
            'status' => self::STATUS_FAILED,
            'failure_code' => mb_substr($failureCode, 0, 48),
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * La versión como la recibe el compilador (`approvedBy`: quien firmó la
     * reseña `approved`, o null).
     *
     * @return array<string, mixed>
     */
    private static function versionInput(StudioVersion $v): array
    {
        $resena = $v->review;

        return [
            'uuid' => (string) $v->uuid,
            'status' => (string) $v->status,
            'contentHash' => (string) $v->content_hash,
            'content' => is_array($v->content) ? $v->content : [],
            'createdBy' => (int) $v->created_by_admin_id,
            'approvedBy' => $resena?->decision === StudioVersionReview::DECISION_APPROVED ? (int) $resena->reviewer_admin_id : null,
        ];
    }

    /**
     * El perfil como lo recibe el compilador, leído de su fila (el modelo es
     * de S8). Un contenido que no se puede leer llega vacío: el compilador lo
     * rechaza por la huella.
     *
     * @return array<string, mixed>
     */
    private static function profileInput(stdClass $p): array
    {
        try {
            $contenido = is_string($p->content) ? json_decode($p->content, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $contenido = null;
        }
        $aprobado = $p->status === StudioVoiceCompiler::PROFILE_APPROVED;

        return [
            'uuid' => (string) $p->uuid,
            'status' => (string) $p->status,
            'contentHash' => (string) $p->content_hash,
            'content' => is_array($contenido) ? $contenido : [],
            'createdBy' => (int) $p->created_by_admin_id,
            'approvedBy' => $aprobado && $p->reviewed_by_admin_id !== null ? (int) $p->reviewed_by_admin_id : null,
        ];
    }
}
