<?php

namespace App\Services\Outreach\Facts;

use Illuminate\Support\Facades\DB;

/**
 * LA UNIFICACIÓN DE LA PERSONA (contrato H9, D9-2, D9-8 etapa 2 y §13.3
 * «Identidad»). SOLO LECTURA, por lotes: cuatro sentencias para N personas,
 * nunca N × algo. Tablas: `members`, `marketing_leads`.
 *
 * LAS REGLAS (deterministas):
 *  1. EL SOCIO MANDA, como `EvaluateCommercialSubject::lockKey()`: un
 *     prospecto con `member_id` es esa persona socia; su clave es `m:`.
 *  2. Las fichas de socio que comparten IDENTIDAD (`members.identity_id`) o
 *     USUARIO (`members.user_id`) son la MISMA persona (un mismo documento
 *     escrito de dos formas da dos filas y una identidad). Su clave es la del
 *     socio de MENOR id: es estable (una ficha duplicada nueva siempre tiene un
 *     id mayor y no cambia la clave de la persona ya inscrita).
 *  3. El teléfono NO une personas (§3.13: «tener el teléfono no autoriza
 *     nada»; una familia comparte número). Solo se usa, fuera de aquí, para la
 *     regla del *opt-out* de los leads hermanos.
 *  4. Una clave que no corresponde a nadie queda SIN RESOLVER
 *     (`unknown_subject`, N1), nunca se adivina.
 *
 * Que la clave canónica sea distinta de la pedida (un prospecto que se hizo
 * socio después de inscribirse) lo ve quien llama en {@see Subject::$key}.
 */
final class SubjectResolver
{
    public const TABLES = ['members', 'marketing_leads'];

    /** Rondas de expansión por identidad/usuario (una basta salvo datos corruptos en cadena). */
    private const MAX_EXPANSION_ROUNDS = 3;

    /**
     * @param  list<string>  $keys  claves pedidas (`m:<id>` / `l:<id>`); las que no tienen forma quedan sin resolver
     * @return array<string, Subject> por clave pedida, en el orden recibido
     */
    public function forKeys(array $keys): array
    {
        $miembros = [];
        $leads = [];
        foreach ($keys as $clave) {
            if (! SubjectKey::isValid($clave)) {
                continue;
            }
            $p = SubjectKey::parse($clave);
            if ($p['kind'] === SubjectKey::MEMBER) {
                $miembros[] = $p['id'];
            } else {
                $leads[] = $p['id'];
            }
        }

        $mundo = $this->world($miembros, $leads, []);

        $salida = [];
        foreach ($keys as $clave) {
            if (! SubjectKey::isValid($clave)) {
                $salida[$clave] = Subject::unresolved($clave);

                continue;
            }
            $p = SubjectKey::parse($clave);
            $salida[$clave] = $p['kind'] === SubjectKey::MEMBER
                ? $mundo->forMember($clave, $p['id'])
                : $mundo->forLead($clave, $p['id']);
        }

        return $salida;
    }

    /**
     * Para los prefiltros que encuentran usuarios (membresía, asistencia).
     *
     * @param  list<int>  $userIds
     * @return array<int, Subject> por id de usuario; los que no tienen ficha de socio no salen
     */
    public function forUserIds(array $userIds): array
    {
        $mundo = $this->world([], [], $userIds);
        $salida = [];
        foreach (array_values(array_unique($userIds)) as $userId) {
            $socio = $mundo->memberOfUser((int) $userId);
            if ($socio !== null) {
                $salida[(int) $userId] = $mundo->forMember(SubjectKey::member($socio), $socio);
            }
        }

        return $salida;
    }

    /**
     * Para los prefiltros que encuentran fichas de socio (cartera, pagos, agenda).
     *
     * @param  list<int>  $memberIds
     * @return array<int, Subject> por id de socio pedido
     */
    public function forMemberIds(array $memberIds): array
    {
        $mundo = $this->world($memberIds, [], []);
        $salida = [];
        foreach (array_values(array_unique($memberIds)) as $memberId) {
            $sujeto = $mundo->forMember(SubjectKey::member((int) $memberId), (int) $memberId);
            if ($sujeto->resolved()) {
                $salida[(int) $memberId] = $sujeto;
            }
        }

        return $salida;
    }

    /**
     * Para los prefiltros que encuentran prospectos (seguimientos).
     *
     * @param  list<int>  $leadIds
     * @return array<int, Subject> por id de prospecto pedido
     */
    public function forLeadIds(array $leadIds): array
    {
        $mundo = $this->world([], $leadIds, []);
        $salida = [];
        foreach (array_values(array_unique($leadIds)) as $leadId) {
            $sujeto = $mundo->forLead(SubjectKey::lead((int) $leadId), (int) $leadId);
            if ($sujeto->resolved()) {
                $salida[(int) $leadId] = $sujeto;
            }
        }

        return $salida;
    }

    /**
     * Las filas que hacen falta para resolver estas semillas, en lote.
     *
     * @param  list<int>  $memberSeeds
     * @param  list<int>  $leadSeeds
     * @param  list<int>  $userSeeds
     */
    private function world(array $memberSeeds, array $leadSeeds, array $userSeeds): SubjectWorld
    {
        $leadsPedidos = [];
        if ($leadSeeds !== []) {
            foreach (DB::table('marketing_leads')->whereIn('id', self::ids($leadSeeds))->orderBy('id')->get(['id', 'member_id']) as $fila) {
                $leadsPedidos[(int) $fila->id] = $fila->member_id !== null ? (int) $fila->member_id : null;
                if ($fila->member_id !== null) {
                    $memberSeeds[] = (int) $fila->member_id;
                }
            }
        }

        $socios = [];
        if ($memberSeeds !== [] || $userSeeds !== []) {
            $q = DB::table('members')->orderBy('id');
            $q->where(function ($w) use ($memberSeeds, $userSeeds): void {
                if ($memberSeeds !== []) {
                    $w->orWhereIn('id', self::ids($memberSeeds));
                }
                if ($userSeeds !== []) {
                    $w->orWhereIn('user_id', self::ids($userSeeds));
                }
            });
            foreach ($q->get(['id', 'user_id', 'identity_id']) as $fila) {
                $socios[(int) $fila->id] = self::memberRow($fila);
            }
        }

        // Expansión: las fichas que comparten identidad o usuario con las ya vistas.
        $identidadesVistas = [];
        $usuariosVistos = array_fill_keys(self::ids($userSeeds), true);
        for ($ronda = 0; $ronda < self::MAX_EXPANSION_ROUNDS; $ronda++) {
            $identidades = [];
            $usuarios = [];
            foreach ($socios as $s) {
                if ($s['identity_id'] !== null && ! isset($identidadesVistas[$s['identity_id']])) {
                    $identidades[] = $s['identity_id'];
                }
                if ($s['user_id'] !== null && ! isset($usuariosVistos[$s['user_id']])) {
                    $usuarios[] = $s['user_id'];
                }
            }
            if ($identidades === [] && $usuarios === []) {
                break;
            }
            foreach ($identidades as $i) {
                $identidadesVistas[$i] = true;
            }
            foreach ($usuarios as $u) {
                $usuariosVistos[$u] = true;
            }
            $antes = count($socios);
            $q = DB::table('members')->orderBy('id')->where(function ($w) use ($identidades, $usuarios): void {
                if ($identidades !== []) {
                    $w->orWhereIn('identity_id', self::ids($identidades));
                }
                if ($usuarios !== []) {
                    $w->orWhereIn('user_id', self::ids($usuarios));
                }
            });
            foreach ($q->get(['id', 'user_id', 'identity_id']) as $fila) {
                $socios[(int) $fila->id] = self::memberRow($fila);
            }
            if (count($socios) === $antes) {
                break;
            }
        }

        $leadsDeSocios = [];
        if ($socios !== []) {
            foreach (DB::table('marketing_leads')->whereIn('member_id', array_keys($socios))->orderBy('id')->get(['id', 'member_id']) as $fila) {
                $leadsDeSocios[(int) $fila->id] = (int) $fila->member_id;
            }
        }

        return new SubjectWorld($socios, $leadsPedidos, $leadsDeSocios);
    }

    /** @return array{user_id: ?int, identity_id: ?int} */
    private static function memberRow(object $fila): array
    {
        return [
            'user_id' => $fila->user_id !== null ? (int) $fila->user_id : null,
            'identity_id' => $fila->identity_id !== null ? (int) $fila->identity_id : null,
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        $limpios = array_values(array_unique(array_map('intval', $ids)));
        sort($limpios);

        return $limpios;
    }
}
