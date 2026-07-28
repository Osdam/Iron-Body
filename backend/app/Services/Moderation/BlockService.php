<?php

namespace App\Services\Moderation;

use App\Models\Member;
use App\Models\ModerationAuditLog;
use App\Models\UserBlock;
use App\Services\RealtimeEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bloqueos entre miembros.
 *
 * Invariantes que este servicio garantiza (y que ningún controlador repite):
 *  - Nadie puede bloquearse a sí mismo.
 *  - Bloquear dos veces es idempotente (devuelve la fila existente).
 *  - El efecto es SIMÉTRICO: `hiddenMemberIdsFor()` devuelve ambos sentidos.
 *  - Bloquear no toca membresías, pagos ni acceso al gimnasio.
 *  - El bloqueado NUNCA sabe quién lo bloqueó: no hay endpoint que lo revele
 *    y la lista de "usuarios bloqueados" solo muestra a quien YO bloqueé.
 */
class BlockService
{
    public function __construct(private ModerationAudit $audit) {}

    /**
     * Bloquea a otro miembro. Idempotente.
     *
     * @return array{block: UserBlock, created: bool}
     */
    public function block(
        Member $blocker,
        int $blockedMemberId,
        ?string $reason = null,
        ?Request $request = null,
    ): array {
        if ((int) $blocker->id === $blockedMemberId) {
            throw new RuntimeException('self_block_not_allowed');
        }

        // El objetivo debe existir y no estar eliminado. Se resuelve del lado
        // del servidor: el cliente solo manda un id, no un estado.
        $target = Member::query()
            ->whereKey($blockedMemberId)
            ->where('status', '!=', Member::STATUS_DELETED)
            ->first();

        if (! $target) {
            throw new RuntimeException('member_not_found');
        }

        $created = false;

        /** @var UserBlock $block */
        $block = DB::transaction(function () use ($blocker, $target, $reason, &$created): UserBlock {
            $existing = UserBlock::query()
                ->where('blocker_member_id', $blocker->id)
                ->where('blocked_member_id', $target->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = true;

            return UserBlock::create([
                'blocker_member_id' => $blocker->id,
                'blocked_member_id' => $target->id,
                'reason' => $reason,
            ]);
        });

        if ($created) {
            $this->audit->member(
                (int) $blocker->id,
                ModerationAuditLog::ACTION_MEMBER_BLOCKED,
                'user_block',
                (int) $block->id,
                ['blocked_member_id' => (int) $target->id],
                $request,
            );

            // El bloqueo es SIMÉTRICO, así que ambos feeds cambian. Se avisa a
            // los dos para que el contenido desaparezca sin recargar.
            //
            // Al bloqueado se le manda una señal genérica de refresco de
            // stories: NO se le dice que lo bloquearon ni quién (eso lo
            // convertiría en un canal de acoso). Solo ve que cierto contenido
            // dejó de estar disponible, igual que si hubiera expirado.
            $this->emitFeedRefresh((int) $blocker->id);
            $this->emitFeedRefresh((int) $target->id);
        }

        return ['block' => $block, 'created' => $created];
    }

    /**
     * Desbloquea. Idempotente: si no había bloqueo, no es un error.
     *
     * @return bool true si existía un bloqueo y se eliminó.
     */
    public function unblock(Member $blocker, int $blockedMemberId, ?Request $request = null): bool
    {
        $block = UserBlock::query()
            ->where('blocker_member_id', $blocker->id)
            ->where('blocked_member_id', $blockedMemberId)
            ->first();

        if (! $block) {
            return false;
        }

        $blockId = (int) $block->id;
        $block->delete();

        $this->audit->member(
            (int) $blocker->id,
            ModerationAuditLog::ACTION_MEMBER_UNBLOCKED,
            'user_block',
            $blockId,
            ['blocked_member_id' => $blockedMemberId],
            $request,
        );

        // Al desbloquear, el contenido vuelve a ser visible para ambos.
        $this->emitFeedRefresh((int) $blocker->id);
        $this->emitFeedRefresh($blockedMemberId);

        return true;
    }

    /**
     * Señal de "refresca tus stories" para un miembro.
     *
     * Best-effort y sin contenido: solo el módulo afectado. Si el canal falla,
     * el feed se actualizará en el siguiente refresco normal — nunca rompe el
     * bloqueo, que ya está persistido.
     */
    private function emitFeedRefresh(int $memberId): void
    {
        RealtimeEvents::emit($memberId, RealtimeEvents::STORY_DEL, ['stories', 'moderation']);
    }

    /**
     * IDs de miembros cuyo contenido NO debe verse desde la cuenta indicada.
     *
     * Incluye los dos sentidos: a quien yo bloqueé y a quien me bloqueó. Es la
     * única función que el feed debe consultar — así el filtro nunca se aplica
     * "a medias" en un endpoint nuevo.
     *
     * @return list<int>
     */
    public function hiddenMemberIdsFor(int $memberId): array
    {
        return UserBlock::query()
            ->involving($memberId)
            ->get(['blocker_member_id', 'blocked_member_id'])
            ->flatMap(fn (UserBlock $b) => [$b->blocker_member_id, $b->blocked_member_id])
            ->unique()
            ->reject(fn (int $id) => $id === $memberId)
            ->values()
            ->all();
    }

    /** ¿Existe un bloqueo en cualquier sentido entre estos dos miembros? */
    public function isBlockedBetween(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }

        return UserBlock::query()
            ->where(function ($q) use ($a, $b) {
                $q->where('blocker_member_id', $a)->where('blocked_member_id', $b);
            })
            ->orWhere(function ($q) use ($a, $b) {
                $q->where('blocker_member_id', $b)->where('blocked_member_id', $a);
            })
            ->exists();
    }

    /**
     * Lista paginada de a quién bloqueó ESTE miembro. Nunca al revés: revelar
     * quién me bloqueó permitiría acosar por otra vía.
     */
    public function listBlockedBy(Member $member, int $perPage = 20)
    {
        return UserBlock::query()
            ->where('blocker_member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
