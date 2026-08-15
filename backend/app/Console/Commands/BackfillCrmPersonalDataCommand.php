<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara las fichas del CRM a las que nunca llegó el sexo o la fecha de
 * nacimiento del registro desde la app.
 *
 * El CRM lee `gender` y `birth_date` del User (UserController::serialize), pero
 * `syncCrmUser` solo replicaba nombre, correo, documento, teléfono y estado, así
 * que esos dos campos se quedaban únicamente en `members`. El origen ya está
 * corregido; este comando arregla los registros anteriores.
 *
 * NO DESTRUCTIVO: solo escribe donde el User tiene el campo vacío. Nunca
 * sobrescribe un valor que el CRM ya tuviera, y es idempotente (una segunda
 * corrida no encuentra nada que hacer).
 */
class BackfillCrmPersonalDataCommand extends Command
{
    protected $signature = 'ironbody:backfill-crm-personal-data
                            {--dry-run : Solo muestra lo que cambiaría, sin escribir}';

    protected $description = 'Copia sexo y fecha de nacimiento de members a users donde el CRM los tiene vacíos';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $members = Member::query()
            ->with('user')
            ->whereNotNull('user_id')
            ->where(function ($q) {
                $q->whereNotNull('gender')->orWhereNotNull('birth_date');
            })
            ->get();

        $rows = [];
        $updates = [];

        foreach ($members as $member) {
            $user = $member->user;
            if (! $user) {
                continue;
            }

            $patch = [];
            if (filled($member->gender) && blank($user->gender)) {
                $patch['gender'] = $member->gender;
            }
            if (filled($member->birth_date) && blank($user->birth_date)) {
                $patch['birth_date'] = $member->birth_date;
            }

            if ($patch === []) {
                continue;
            }

            $rows[] = [
                $member->id,
                $user->id,
                $patch['gender'] ?? '—',
                isset($patch['birth_date']) ? substr((string) $patch['birth_date'], 0, 10) : '—',
            ];
            $updates[$user->id] = $patch;
        }

        if ($rows === []) {
            $this->info('No hay fichas por reparar: el CRM ya tiene sexo y fecha donde el miembro los tiene.');

            return self::SUCCESS;
        }

        $this->table(['member_id', 'user_id', 'sexo a escribir', 'fecha a escribir'], $rows);

        if ($dryRun) {
            $this->warn(count($rows).' ficha(s) se repararían. Ejecuta sin --dry-run para aplicarlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $userId => $patch) {
                DB::table('users')->where('id', $userId)->update($patch);
            }
        });

        $this->info(count($rows).' ficha(s) reparadas.');

        return self::SUCCESS;
    }
}
