<?php

namespace App\Services;

use App\Models\CatalogEvent;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\User;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bus de eventos real-time por miembro (Bloque 2). `emit()` inserta una señal de
 * cambio que el stream SSE privado entrega al instante. Es BEST-EFFORT: nunca
 * lanza ni bloquea la mutación de negocio (pago/story/perfil). No incluye datos
 * sensibles (tokens/OTP/montos), solo el tipo y los módulos afectados.
 */
class RealtimeEvents
{
    /** Tipos de evento (espejo de los handlers del cliente). */
    public const MEMBERSHIP = 'membership.updated';

    public const PAYMENT = 'payment.updated';

    public const PROFILE = 'profile.updated';

    public const PHONE = 'phone.updated';

    public const LIVE_PERMS = 'live.permissions.updated';

    public const STORY_NEW = 'story.created';

    public const STORY_DEL = 'story.deleted';

    public const SECURITY = 'device.security.updated';

    public const APP_STATE = 'app_state.updated';

    public const ASSESSMENT = 'assessment.updated';

    public const ROUTINE = 'routine.updated';

    public const CLASS_EVENT = 'class.updated';

    public const NUTRITION = 'nutrition.updated';

    public const RANKING = 'ranking.updated';

    public const BIOMETRIC = 'biometric.enrolled';

    /**
     * Emite una señal de cambio para un miembro. [$changed] son los módulos que
     * el cliente debe refrescar (p.ej. ['membership']). Nunca rompe el flujo.
     */
    public static function emit(?int $memberId, string $type, array $changed = []): void
    {
        if ($memberId === null || $memberId <= 0) {
            return;
        }
        try {
            MemberRealtimeEvent::create([
                'member_id' => $memberId,
                'type' => $type,
                'changed' => $changed,
                // Versión monotónica (ms) para que el cliente ignore duplicados.
                'version' => (int) (microtime(true) * 1000),
                'created_at' => now(),
            ]);

            // Poda: las señales son efímeras (no histórico). Borra las del miembro
            // con más de 5 min para que la tabla no crezca.
            MemberRealtimeEvent::query()
                ->where('member_id', $memberId)
                ->where('created_at', '<', now()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            // El real-time es aditivo: si falla, el fallback (resume/TTL) cubre.
            Log::warning('realtime.emit_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    // Atajos semánticos para los call-sites.
    public static function membership(?int $memberId): void
    {
        self::emit($memberId, self::MEMBERSHIP, ['membership']);
    }

    public static function payment(?int $memberId): void
    {
        self::emit($memberId, self::PAYMENT, ['membership', 'payment']);
    }

    public static function profile(?int $memberId): void
    {
        self::emit($memberId, self::PROFILE, ['profile']);
    }

    public static function phone(?int $memberId): void
    {
        self::emit($memberId, self::PHONE, ['profile']);
    }

    public static function livePermissions(?int $memberId): void
    {
        self::emit($memberId, self::LIVE_PERMS, ['live']);
    }

    public static function security(?int $memberId): void
    {
        self::emit($memberId, self::SECURITY, ['security']);
    }

    /**
     * Cambió la configuración del plan (features/capacidades IA) desde el CRM.
     * El cliente refresca app-state y reevalúa el gating de módulos al instante.
     */
    public static function features(?int $memberId): void
    {
        self::emit($memberId, self::APP_STATE, self::FEATURES_CHANGED);
    }

    /** Lo que `features()` dice que cambió: la app refresca su estado global. */
    private const FEATURES_CHANGED = ['features', 'membership'];

    /** Socios por sentencia en los avisos en bloque. */
    private const BULK_CHUNK = 500;

    /**
     * La misma señal que {@see features()}, para TODOS los socios de app de un
     * plan y en bloque. La usa Planes cuando cambia lo que el plan le da a quien
     * lo tiene (sus funciones o sus capacidades de IRON IA).
     *
     * DESTINATARIOS: los de siempre. Cada socio de app cuyo usuario tiene
     * `users.plan` = el nombre del plan, que es como Member::resolvedFeatures()
     * encuentra su plan, sin filtrar por estado. Sin el alcance del entrenador:
     * esto no lee datos de nadie, solo reparte una señal, y con él el aviso
     * dependería de quién guarda el plan.
     *
     * POR QUÉ EN BLOQUE Y CON SENTENCIAS CORTAS. Antes eran un INSERT y un DELETE
     * por socio dentro de la transacción del plan: 4.000 socios, 8.000
     * sentencias y unos 5 s por petición. Aquí son dos por cada bloque de 500.
     * Y cada una se confirma sola: el stream lee por cursor (`id > after_id`),
     * así que una fila que tardara segundos en confirmarse podía quedar por
     * detrás del cursor de un socio que ya hubiera leído otra señal posterior.
     * Quien llama lo hace DESPUÉS del commit del cambio.
     *
     * `$skipIfSignaledWithinSeconds`: no se repite a quien ya recibió esta señal
     * hace menos de N segundos. Solo vale para pistas que no cambian lo que
     * devuelve `app-state` (las capacidades de IA). Un cambio de funciones se
     * avisa siempre: esas sí viajan en `app-state`, y quien refrescó antes del
     * cambio tiene que volver a pedirlas.
     *
     * BEST-EFFORT como emit(): nunca lanza. Un bloque que falla se registra y
     * se sigue con los demás; la app tiene sus redes (refresco al volver a
     * primer plano y sondeo mientras el stream está caído).
     *
     * @return int señales escritas
     */
    public static function featuresForPlanMembers(string $planName, ?int $skipIfSignaledWithinSeconds = null): int
    {
        $inicio = microtime(true);
        $ahora = now();

        try {
            // El alcance del entrenador está registrado en Member Y en User
            // (AppServiceProvider: guard + guardUsersOf): se quita en los dos,
            // o una cuenta de entrenador con `plans.manage` solo avisaría a
            // sus propios socios de un cambio que es de todos.
            $consulta = Member::withoutGlobalScope(TrainerMemberScope::NAME)
                ->whereIn('user_id', User::withoutGlobalScope(TrainerMemberScope::NAME)
                    ->where('plan', $planName)->select('id'));

            if ($skipIfSignaledWithinSeconds !== null) {
                $desde = $ahora->copy()->subSeconds($skipIfSignaledWithinSeconds);
                $consulta->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from('member_realtime_events')
                    ->whereColumn('member_realtime_events.member_id', 'members.id')
                    ->where('member_realtime_events.type', self::APP_STATE)
                    ->where('member_realtime_events.created_at', '>=', $desde));
            }

            // Cada paso en su propia transacción: corta en producción, y un punto
            // de guardado si alguien llama desde dentro de otra. Así un fallo
            // aquí nunca deja inservible la transacción de quien llama (en
            // PostgreSQL un error la aborta entera).
            $ids = DB::transaction(fn () => $consulta->orderBy('members.id')->pluck('members.id'));
        } catch (\Throwable $e) {
            Log::warning('realtime.plan_members_failed', ['plan' => $planName, 'stage' => 'recipients', 'error' => $e->getMessage()]);

            return 0;
        }

        $version = (int) (microtime(true) * 1000);
        $changed = json_encode(self::FEATURES_CHANGED);
        $poda = $ahora->copy()->subMinutes(5);
        $escritas = 0;
        $fallidas = 0;

        foreach ($ids->chunk(self::BULK_CHUNK) as $bloque) {
            $bloque = $bloque->map(fn ($id): int => (int) $id)->values()->all();
            try {
                DB::transaction(fn () => MemberRealtimeEvent::insert(array_map(fn (int $id): array => [
                    'member_id' => $id,
                    'type' => self::APP_STATE,
                    'changed' => $changed,
                    'version' => $version,
                    'created_at' => $ahora,
                ], $bloque)));
                $escritas += count($bloque);
            } catch (\Throwable $e) {
                $fallidas += count($bloque);
                Log::warning('realtime.plan_members_failed', ['plan' => $planName, 'stage' => 'insert', 'members' => count($bloque), 'error' => $e->getMessage()]);

                continue;
            }

            // La misma poda que hace emit() por socio: las señales son efímeras.
            try {
                DB::transaction(fn () => MemberRealtimeEvent::query()
                    ->whereIn('member_id', $bloque)->where('created_at', '<', $poda)->delete());
            } catch (\Throwable $e) {
                Log::warning('realtime.plan_members_failed', ['plan' => $planName, 'stage' => 'prune', 'error' => $e->getMessage()]);
            }
        }

        try {
            Log::info('realtime.plan_members', [
                'plan' => $planName,
                'sent' => $escritas,
                'failed' => $fallidas,
                'dedup_seconds' => $skipIfSignaledWithinSeconds,
                'ms' => (int) round((microtime(true) - $inicio) * 1000),
            ]);
        } catch (\Throwable) {
            // Ni el registro puede convertir en error un guardado ya confirmado.
        }

        return $escritas;
    }

    /** El entrenador asignó/editó una rutina del miembro (CRM). */
    /** Una valoración nueva cambia su progreso y sus datos corporales. */
    public static function assessment(?int $memberId): void
    {
        self::emit($memberId, self::ASSESSMENT, ['assessments', 'progress']);
    }

    public static function routine(?int $memberId): void
    {
        self::emit($memberId, self::ROUTINE, ['routines']);
    }

    /** Cambió el plan/metas de nutrición del miembro (CRM/IA o el propio app). */
    public static function nutrition(?int $memberId): void
    {
        self::emit($memberId, self::NUTRITION, ['nutrition']);
    }

    /** El miembro registró/actualizó su rostro (biometría facial). */
    public static function biometric(?int $memberId): void
    {
        self::emit($memberId, self::BIOMETRIC, ['biometric', 'profile']);
    }

    /**
     * Cambio GLOBAL que afecta a todos los miembros (p.ej. horario/cupo de una
     * clase, o el ranking de entrenadores). Inserta una señal por cada miembro
     * activo en bloque (sin N+1) y poda lo viejo. BEST-EFFORT: nunca rompe el
     * flujo de negocio.
     *
     * Nota de escala: es 1 fila por miembro activo. Para un gimnasio (cientos de
     * miembros) es trivial; las señales se podan a los 5 min.
     */
    public static function broadcastToActiveMembers(string $type, array $changed = []): void
    {
        try {
            $now = now();
            $version = (int) (microtime(true) * 1000);
            $changedJson = json_encode($changed);

            // Sin el alcance del entrenador: esto no lee datos de nadie, solo
            // reparte una señal. Si quien dispara el cambio es una cuenta de
            // entrenador, el scope global dejaría el aviso solo a SUS socios y
            // el resto seguiría viendo el cupo viejo.
            $ids = Member::withoutGlobalScope(TrainerMemberScope::NAME)
                ->where('status', Member::STATUS_ACTIVE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            foreach ($ids->chunk(500) as $chunk) {
                MemberRealtimeEvent::insert(
                    $chunk->map(fn ($id) => [
                        'member_id' => (int) $id,
                        'type' => $type,
                        'changed' => $changedJson,
                        'version' => $version,
                        'created_at' => $now,
                    ])->all()
                );
            }

            // Poda global de señales efímeras (>5 min).
            MemberRealtimeEvent::query()
                ->where('created_at', '<', $now->copy()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('realtime.broadcast_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cambió una clase o sus cupos/reservas (afecta a todos).
     *
     * UNA fila en el canal global, no una por socio. Antes cada reserva
     * escribía unas 3.900 filas en `member_realtime_events` (una por socio
     * activo) dentro de la petición. El canal global (`catalog_events`, cursor
     * `c` del mismo stream) lo leen todas las versiones de la app en uso, y
     * todas enrutan por `type`: `class.*` y `reservation.*` refrescan Clases.
     */
    public static function classesChanged(?int $classId = null, ?string $sessionDate = null, string $type = self::CLASS_EVENT): void
    {
        self::global($type, $classId, $sessionDate, ['classes']);
    }

    /**
     * Señal para TODOS los socios, en una sola fila del canal global.
     *
     * Best-effort como el resto: un aviso que no se puede escribir no tumba la
     * operación de negocio. Quien llama decide si va después del commit.
     */
    public static function global(string $type, ?int $classId, ?string $sessionDate, array $changed = []): void
    {
        try {
            CatalogEvent::create([
                'type' => $type,
                'product_id' => null,
                'class_id' => $classId,
                'session_date' => $sessionDate,
                'changed' => $changed,
                'version' => (int) (microtime(true) * 1000),
                'created_at' => now(),
            ]);

            // Poda: las señales de clases son efímeras, como lo eran las de
            // cada socio (>5 min). Solo hacen falta para cubrir una reconexión;
            // al volver de segundo plano la app recarga. Sin poda, la app que
            // volvía tras horas recibía todas las señales de la pausa. Las de
            // producto no se tocan (las poda `realtime:prune`).
            CatalogEvent::query()
                ->where(fn ($q) => $q->where('type', 'like', 'class.%')->orWhere('type', 'like', 'reservation.%'))
                ->where('created_at', '<', now()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('realtime.global_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** Cambió el ranking/datos de entrenadores (afecta a todos). */
    public static function rankingChanged(): void
    {
        self::broadcastToActiveMembers(self::RANKING, ['ranking']);
    }

    /** Snapshot global del miembro cambió (membresía/acceso/días/perfil). */
    public static function appState(?int $memberId): void
    {
        self::emit($memberId, self::APP_STATE, ['membership', 'payment', 'profile']);
    }
}
