<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\User;
use App\Support\Access\TrainerMemberScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra el hueco del route-model binding en el alcance del entrenador.
 *
 * POR QUÉ HACE FALTA ADEMÁS DEL SCOPE GLOBAL. {@see TrainerMemberScope} acota
 * las consultas de los modelos de socio, y con eso bastan los listados y todo
 * lo que el controlador pregunte. Pero `SubstituteBindings` va en el grupo `api`
 * ANTES que {@see ProtectAdminPaths}: cuando Laravel resuelve `{member}` de la
 * URL todavía no hay `auth_admin`, así que el scope aún es inerte y el socio se
 * carga sin filtrar. Justo la vía que había que cerrar: cambiar el número de la
 * URL.
 *
 * Reordenar el grupo para autenticar antes de resolver bindings arreglaría el
 * síntoma tocando el orden de TODAS las peticiones de la API. Esto solo mira lo
 * que la ruta ya resolvió, y solo cuando quien opera es un entrenador.
 *
 * GENÉRICO A PROPÓSITO. No lleva una lista de rutas. Recorre los parámetros ya
 * resueltos y reconoce tres formas de referirse a un socio: el propio
 * {@see Member}, la cuenta de app {@see User}, y cualquier modelo con
 * `member_id` —valoraciones, contratos, bloqueos y lo que venga—. Una ruta
 * nueva queda cubierta sin que nadie se acuerde de añadirla.
 *
 * 404 Y NO 403: un 403 confirmaría que ese socio existe. Ver TrainerMemberScope.
 */
class EnforceTrainerMemberScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = app(TrainerMemberScope::class);

        // Inerte para todo el mundo menos las cuentas de entrenador.
        if (! $scope->applies()) {
            return $next($request);
        }

        $route = $request->route();
        if ($route === null) {
            return $next($request);
        }

        foreach ($route->parameters() as $nombre => $valor) {
            $memberId = $this->memberIdOf($valor);

            if ($memberId === null) {
                continue;
            }

            if (! $scope->allowsMember($memberId)) {
                // Un entrenador tanteando ids ajenos es señal, no ruido.
                Log::warning('auth:trainer:out_of_scope', [
                    'admin_id' => $scope->actingTrainerAccount()?->id,
                    'parameter' => $nombre,
                    'member_id' => $memberId,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                abort(404);
            }
        }

        return $next($request);
    }

    /**
     * A qué socio se refiere un parámetro ya resuelto, si es que se refiere a
     * alguno. Devuelve null cuando no habla de un socio —un ejercicio, una
     * clase— y la petición sigue su curso.
     */
    private function memberIdOf(mixed $valor): ?int
    {
        if ($valor instanceof Member) {
            return (int) $valor->getKey();
        }

        if ($valor instanceof User) {
            // La relación va al revés: `members.user_id`. Se consulta en crudo
            // para no volver a entrar en el scope global de Member, que ya
            // habría filtrado la fila y nos haría concluir que no existe.
            $id = DB::table('members')->where('user_id', $valor->getKey())->value('id');

            return $id === null ? null : (int) $id;
        }

        if ($valor instanceof Model && $valor->getAttribute('member_id') !== null) {
            return (int) $valor->getAttribute('member_id');
        }

        return null;
    }
}
