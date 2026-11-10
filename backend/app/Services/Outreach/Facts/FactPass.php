<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Una PASADA de lectura: el instante de referencia, las personas ya resueltas
 * y las filas que comparten varios adaptadores (se leen UNA vez por lote).
 * Vive solo en memoria mientras {@see BatchSnapshotBuilder} construye la foto.
 *
 * El reloj ENTRA aquí (`$now`) y nadie más lo lee: misma fecha de referencia y
 * misma base dan la misma foto (D9-12).
 */
final class FactPass
{
    public readonly DateTimeImmutable $now;

    /** La fecha de Bogotá de `$now` (`Y-m-d`). */
    public readonly string $today;

    /** @var array<string, list<string>> */
    private array $porSocio = [];

    /** @var array<string, list<string>> */
    private array $porUsuario = [];

    /** @var array<string, list<string>> */
    private array $porLead = [];

    /** @var array<int, object>|null */
    private ?array $leadRows = null;

    /** @var array<int, ?string>|null */
    private ?array $memberPhones = null;

    /** @var array<string, ?object>|null */
    private ?array $subscriptions = null;

    /**
     * @param  array<string, Subject>  $subjects  por clave pedida
     */
    public function __construct(DateTimeInterface $now, public readonly array $subjects)
    {
        $this->now = RelativeTime::utc($now);
        $this->today = RelativeTime::bogotaDate($this->now);
        foreach ($subjects as $clave => $s) {
            foreach ($s->memberIds as $id) {
                $this->porSocio[(string) $id][] = $clave;
            }
            foreach ($s->userIds as $id) {
                $this->porUsuario[(string) $id][] = $clave;
            }
            foreach ($s->leadIds as $id) {
                $this->porLead[(string) $id][] = $clave;
            }
        }
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return self::sortedIds(array_keys($this->porSocio));
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return self::sortedIds(array_keys($this->porUsuario));
    }

    /** @return list<int> los usuarios cuya membresía manda (uno por persona socia) */
    public function primaryUserIds(): array
    {
        $ids = [];
        foreach ($this->subjects as $s) {
            if ($s->primaryUserId !== null) {
                $ids[] = $s->primaryUserId;
            }
        }

        return self::sortedIds($ids);
    }

    /** @return list<int> */
    public function leadIds(): array
    {
        return self::sortedIds(array_keys($this->porLead));
    }

    /** @return list<string> claves pedidas que contienen esta ficha de socio */
    public function keysForMember(int $memberId): array
    {
        return $this->porSocio[(string) $memberId] ?? [];
    }

    /** @return list<string> */
    public function keysForUser(int $userId): array
    {
        return $this->porUsuario[(string) $userId] ?? [];
    }

    /** @return list<string> */
    public function keysForLead(int $leadId): array
    {
        return $this->porLead[(string) $leadId] ?? [];
    }

    /**
     * Las filas de los prospectos del lote (compartidas por `LeadFacts` y
     * `CanaryFacts`). Una sentencia, la primera vez que alguien las pide.
     *
     * @return array<int, object>
     */
    public function leadRows(): array
    {
        if ($this->leadRows !== null) {
            return $this->leadRows;
        }
        $this->leadRows = [];
        $ids = $this->leadIds();
        if ($ids !== []) {
            $filas = DB::table('marketing_leads')->whereIn('id', $ids)->orderBy('id')
                ->get(['id', 'member_id', 'channel', 'phone', 'temperature', 'consent_status', 'do_not_contact']);
            foreach ($filas as $f) {
                $this->leadRows[(int) $f->id] = $f;
            }
        }

        return $this->leadRows;
    }

    /**
     * El teléfono de cada ficha de socio del lote, NORMALIZADO ({@see PhoneKey}).
     * Solo en memoria. Una sentencia, la primera vez.
     *
     * @return array<int, ?string>
     */
    public function memberPhones(): array
    {
        if ($this->memberPhones !== null) {
            return $this->memberPhones;
        }
        $this->memberPhones = [];
        $ids = $this->memberIds();
        if ($ids !== []) {
            foreach (DB::table('members')->whereIn('id', $ids)->orderBy('id')->get(['id', 'phone']) as $f) {
                $this->memberPhones[(int) $f->id] = PhoneKey::normalize($f->phone);
            }
        }

        return $this->memberPhones;
    }

    /**
     * La suscripción MÁS RECIENTE de cada persona (por id), como la lee
     * `CommercialSubject::membershipFacts()`; la comparten `MembershipFacts` y
     * `PaymentFacts`. Una sentencia, la primera vez. Sin importes.
     *
     * @return array<string, ?object> por clave pedida
     */
    public function subscriptions(): array
    {
        if ($this->subscriptions !== null) {
            return $this->subscriptions;
        }
        $this->subscriptions = array_fill_keys(array_keys($this->subjects), null);
        $usuarios = $this->userIds();
        $socios = $this->memberIds();
        if ($usuarios === [] && $socios === []) {
            return $this->subscriptions;
        }
        $q = DB::table('membership_subscriptions')->orderByDesc('id')->where(function ($w) use ($usuarios, $socios): void {
            if ($usuarios !== []) {
                $w->orWhereIn('user_id', $usuarios);
            }
            if ($socios !== []) {
                $w->orWhereIn('member_id', $socios);
            }
        });
        foreach ($q->get(['id', 'user_id', 'member_id', 'status', 'interval_days', 'current_period_start', 'failed_attempts']) as $fila) {
            $claves = [];
            if ($fila->user_id !== null) {
                $claves = [...$claves, ...$this->keysForUser((int) $fila->user_id)];
            }
            if ($fila->member_id !== null) {
                $claves = [...$claves, ...$this->keysForMember((int) $fila->member_id)];
            }
            foreach (array_unique($claves) as $clave) {
                // Por id descendente: la primera que se ve es la más reciente.
                $this->subscriptions[$clave] ??= $fila;
            }
        }

        return $this->subscriptions;
    }

    /**
     * Los teléfonos normalizados de una persona (de sus prospectos y de sus
     * fichas de socio), sin repetir y ordenados.
     *
     * @return list<string>
     */
    public function phonesOf(Subject $s): array
    {
        $leads = $this->leadRows();
        $socios = $this->memberPhones();
        $numeros = [];
        foreach ($s->leadIds as $id) {
            $n = isset($leads[$id]) ? PhoneKey::normalize($leads[$id]->phone) : null;
            if ($n !== null) {
                $numeros[$n] = true;
            }
        }
        foreach ($s->memberIds as $id) {
            $n = $socios[$id] ?? null;
            if ($n !== null) {
                $numeros[$n] = true;
            }
        }
        $lista = array_keys($numeros);
        $lista = array_map('strval', $lista);
        sort($lista, SORT_STRING);

        return $lista;
    }

    /**
     * El teléfono PRINCIPAL de una persona, normalizado (L9-29, `phone_ref`):
     * el de su prospecto más reciente que tenga un número válido (la identidad
     * del canal automático, WhatsApp); si no, el de su ficha de socio canónica;
     * si no, el de sus otras fichas por id. null si no tiene ninguno.
     */
    public function primaryPhoneOf(Subject $s): ?string
    {
        $leads = $this->leadRows();
        foreach (array_reverse($s->leadIds) as $id) {
            $n = isset($leads[$id]) ? PhoneKey::normalize($leads[$id]->phone) : null;
            if ($n !== null) {
                return $n;
            }
        }
        $socios = $this->memberPhones();
        $orden = $s->memberId === null ? $s->memberIds : [$s->memberId, ...array_values(array_diff($s->memberIds, [$s->memberId]))];
        foreach ($orden as $id) {
            if (($socios[$id] ?? null) !== null) {
                return $socios[$id];
            }
        }

        return null;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private static function sortedIds(array $ids): array
    {
        $limpios = array_values(array_unique(array_map('intval', $ids)));
        sort($limpios);

        return $limpios;
    }
}
