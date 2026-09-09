<?php

namespace App\Support\Access;

use App\Models\Admin;
use App\Models\MemberTrainerAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un entrenador solo ve a los socios que tiene asignados.
 *
 * POR QUÉ NO BASTA EL PERMISO. `members.view` es una llave de PUERTA: dice si
 * se puede entrar al módulo de socios. No dice a QUIÉN. Sin esto, la cuenta de
 * un entrenador con `members.view` leía la ficha, la nutrición, las
 * valoraciones y el riesgo de los 1.000 socios del gimnasio con solo cambiar el
 * número de la URL. Permiso y alcance son dos preguntas distintas y hacían
 * falta las dos.
 *
 * POR QUÉ UN SCOPE GLOBAL Y NO UN `if` EN CADA CONTROLADOR. Son 39 rutas sobre
 * cinco modelos, y la lista crece. Comprobar el alcance a mano en cada una es
 * exactamente el patrón que ya falló aquí: {@see AuthorizationMap} existe
 * porque anotar ruta por ruta dejó 43 protegidas de 341. Puesto en el modelo,
 * una consulta nueva nace acotada sin que nadie se acuerde.
 *
 * FALLA CERRADO Y ES INERTE POR DEFECTO. `applies()` solo es cierto cuando hay
 * un admin autenticado, con rol Entrenador y vinculado a un entrenador
 * concreto. Sin petición administrativa —consola, colas, API del socio— no
 * filtra nada, así que no cambia el comportamiento de nadie más. Un entrenador
 * SIN `trainer_id` no ve a ningún socio, que es lo correcto: una cuenta a la
 * que no se le dijo a quién representa no puede representar a todos.
 *
 * DEVUELVE 404, NO 403. Al filtrar en el modelo, el route-model binding de un
 * socio ajeno no encuentra nada. Es deliberado: un 403 confirmaría que ese
 * socio existe, y quien no puede verlo tampoco debería poder averiguar que
 * está ahí. La ausencia de permiso sí responde 403 —eso lo sigue haciendo
 * {@see EnsureAdminPermission}—; lo que aquí se oculta es la existencia.
 */
final class TrainerMemberScope
{
    /** Nombre del scope, por si alguna consulta legítima necesita salirse. */
    public const NAME = 'trainer_member_scope';

    /**
     * Dónde se memorizan los ids ya resueltos.
     *
     * En los atributos de la PETICIÓN, no en una propiedad del objeto. El scope
     * se resuelve del contenedor en cada consulta, y guardarlo en el objeto
     * obligaría a que fuera singleton: entonces una segunda petición del mismo
     * test —o de la misma cuenta tras cambiarle las asignaciones— seguiría
     * viendo la lista vieja. La petición nace y muere con la consulta HTTP, que
     * es exactamente la vida que debe tener este dato.
     */
    private const CACHE_KEY = 'trainer_member_scope.ids';

    /**
     * El entrenador que está operando ahora mismo, si lo hay.
     *
     * Sale de `auth_admin`, que dejan `ProtectAdminPaths` y `auth.admin`. Se
     * exige LAS TRES cosas: sesión administrativa, rol Entrenador y vínculo con
     * un entrenador. Cualquier otro rol —Super Admin, Recepción— no se toca.
     */
    public function actingTrainerAccount(): ?Admin
    {
        if (! app()->bound('request')) {
            return null;
        }

        $admin = request()->attributes->get('auth_admin');

        if (! $admin instanceof Admin) {
            return null;
        }

        return $admin->role === CrmPermission::ROLE_ENTRENADOR ? $admin : null;
    }

    public function applies(): bool
    {
        return $this->actingTrainerAccount() !== null;
    }

    /**
     * Ids de los socios con asignación ACTIVA a este entrenador.
     *
     * Solo `status = active`. Una asignación terminada es historia, no una
     * llave: quien dejó de atender a alguien deja de ver su ficha.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        $cuenta = $this->actingTrainerAccount();

        if (! $cuenta instanceof Admin) {
            return [];
        }

        // Una cuenta de entrenador sin vincular no representa a nadie.
        if (! $cuenta->trainer_id) {
            return [];
        }

        $atributos = request()->attributes;
        $memorizado = $atributos->get(self::CACHE_KEY);

        if (is_array($memorizado) && ($memorizado['admin'] ?? null) === $cuenta->id) {
            return $memorizado['ids'];
        }

        $ids = MemberTrainerAssignment::query()
            ->where('trainer_id', $cuenta->trainer_id)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $atributos->set(self::CACHE_KEY, ['admin' => $cuenta->id, 'ids' => $ids]);

        return $ids;
    }

    public function allowsMember(int $memberId): bool
    {
        return ! $this->applies() || in_array($memberId, $this->memberIds(), true);
    }

    // ── Registro de los scopes globales ─────────────────────────────────────

    /**
     * Acota un modelo que referencia al socio por una columna.
     *
     * @param  class-string<Model>  $model
     */
    public static function guard(string $model, string $column): void
    {
        $model::addGlobalScope(self::NAME, function (Builder $q) use ($column): void {
            $scope = app(self::class);

            if (! $scope->applies()) {
                return;
            }

            // Se cualifica con la tabla: sin esto, una consulta con `join`
            // —las hay— rompería por columna ambigua.
            $q->whereIn($q->getModel()->getTable().'.'.$column, $scope->memberIds());
        });
    }

    /**
     * Acota las cuentas de la app del socio (`users`).
     *
     * No tienen `member_id`: la relación va al revés, `members.user_id`. Se
     * consulta con el query builder crudo a propósito, para no volver a entrar
     * en el scope global de `Member` y quedarnos mirando nuestra propia cola.
     *
     * @param  class-string<Model>  $model
     */
    public static function guardUsersOf(string $model): void
    {
        $model::addGlobalScope(self::NAME, function (Builder $q): void {
            $scope = app(self::class);

            if (! $scope->applies()) {
                return;
            }

            $userIds = DB::table('members')
                ->whereIn('id', $scope->memberIds())
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all();

            $q->whereIn($q->getModel()->getTable().'.id', $userIds);
        });
    }
}
