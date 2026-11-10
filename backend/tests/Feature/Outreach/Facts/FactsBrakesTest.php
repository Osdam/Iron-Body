<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Facts\OperationalBrakes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B9-facts · LOS FRENOS AJENOS DE N2 (contrato H9, D9-2 «única excepción
 * enumerada», D9-7 N2, T17): el freno de ULTRON, la pausa del agente, el freno
 * de voz y las dos banderas heredadas, en LECTURA, y FALLANDO CERRADO: un freno
 * que no se puede leer frena.
 */
class FactsBrakesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_frenos_nada_frena(): void
    {
        config(['commercial.autonomy_enabled' => false, 'marketing.followups.scheduler_enabled' => false]);
        $r = (new OperationalBrakes)->read();
        $this->assertSame(array_fill_keys(OperationalBrakes::KEYS, false), $r['brakes']);
        $this->assertSame([], $r['unreadable']);
    }

    public function test_cada_freno_ajeno_y_cada_bandera_heredada_frenan_con_su_motivo(): void
    {
        $ahora = now()->utc()->format('Y-m-d H:i:s');
        DB::table('ultron_aborts')->insert(['reason' => 'manual', 'engaged_by' => 'Ana', 'engaged_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('marketing_agent_settings')->where('id', 1)->update(['paused' => true, 'paused_at' => $ahora]);
        DB::table('voice_brakes')->insert(['uuid' => (string) Str::uuid(), 'kind' => 'open', 'reason' => 'guard_violation', 'open_key' => 'guard:facts:1',
            'actor_type' => 'system', 'actor_name' => 'Sistema', 'created_at' => $ahora]);
        config(['commercial.autonomy_enabled' => true, 'marketing.followups.scheduler_enabled' => true]);

        $r = (new OperationalBrakes)->read();
        $this->assertSame(array_fill_keys(OperationalBrakes::KEYS, true), $r['brakes']);
        $this->assertSame([], $r['unreadable']);
        // Solo `true` enciende una bandera (el `filter_var` de la configuración ya la convierte).
        config(['commercial.autonomy_enabled' => 'true']);
        $this->assertFalse((new OperationalBrakes)->read()['brakes']['legacy_autonomy_on']);
    }

    public function test_un_freno_que_no_se_puede_leer_frena(): void
    {
        Schema::drop('ultron_aborts');
        $r = (new OperationalBrakes)->read();
        $this->assertTrue($r['brakes']['ultron_abort_latch']);
        $this->assertSame(['ultron_abort_latch'], $r['unreadable']);
        $this->assertFalse($r['brakes']['voice_braked']);
    }
}
