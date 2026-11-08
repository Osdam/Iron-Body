<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Services\UltronStudio\StudioVoicePublishing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA CARRERA POR LA CABEZA DE LA CADENA DE VOZ EN POSTGRESQL, con dos sesiones
 * de verdad (contrato H8, D8-8; como el H5): mientras una publicación ya pasó
 * sus puertas y desplegó, OTRA conexión confirma la entrada siguiente. La
 * inserción de la primera choca con el UNIQUE de `seq` (23505), que en
 * PostgreSQL deja la transacción abortada: el servicio lo resuelve FUERA de
 * ella como 409 `publication_moved`, no escribe nada, el número vuelve al
 * agente que dice la cadena y la cadena sigue verificando.
 *
 * Sin transacción envolvente (la rival tiene que ver lo sembrado): cada prueba
 * confirma filas que no se pueden borrar, así que el esquema se recrea. En
 * SQLite se omite (escribe en serie y no hay dos sesiones).
 */
class UltronStudioVoiceRaceTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'pgsql') {
            RefreshDatabaseState::$migrated = false;
        }
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dos sesiones de verdad y el 23505 que aborta la transacción: solo PostgreSQL (SQLite escribe en serie).');
        }
        $this->confirmoFuera = true;
        $this->voiceSetUp();
        $this->bindFake();
        config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('pgsql_rival');
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_la_otra_sesion_confirma_la_siguiente_entrada_y_esta_es_publication_moved_sin_escribir(): void
    {
        $ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], 'Ana Autora');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $pablo = $this->personWith(self::VOICE_PUBLISHER, 'Pablo Publica');
        $v = $this->approvedVersion($ana, $root, 'Versión');
        $p = $this->approvedProfile($ana, $root);
        $this->labVerdictFor($v, $p, $ana, $root);
        // Un agente de publicación ya desplegado, para la entrada de la rival.
        $this->publishVoice($pablo, $v, $p)->assertCreated();
        $rival = StudioVoiceAgent::query()->where('serves_canary', true)->sole();

        // Justo antes de que Pablo inserte su entrada (con el cerrojo de la
        // cadena ya tomado y la cabeza comprobada), otra sesión confirma la siguiente.
        $colada = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$colada): void {
            if ($colada || ! str_starts_with(ltrim($sql), 'insert into "ultron_studio_voice_publications"')) {
                return;
            }
            $colada = true;
            $cabeza = DB::connection('pgsql_rival')->table('ultron_studio_voice_publications')->orderByDesc('seq')->first();
            $mensaje = StudioVoicePublications::message([
                'seq' => (int) $cabeza->seq + 1, 'prev_hash' => $cabeza->effective_hash, 'version_uuid' => null, 'profile_uuid' => null,
                'spec_hash' => null, 'agent_config_hash' => null, 'provider_agent_id' => null, 'scope' => 'off', 'actor_id' => 1,
                'published_at' => '2026-11-09T15:00:00Z',
            ]);
            DB::connection('pgsql_rival')->table('ultron_studio_voice_publications')->insert([
                'uuid' => (string) Str::uuid(), 'seq' => (int) $cabeza->seq + 1, 'client_request_id' => (string) Str::uuid(), 'action' => 'off', 'scope' => 'rest',
                'previous_publication_id' => $cabeza->id, 'prev_hash' => $cabeza->effective_hash, 'agent_id' => null,
                'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje), 'signature' => StudioVoicePublications::signer()($mensaje),
                'lab_gate' => json_encode(['mode' => null]), 'reason' => 'La otra sesión', 'actor_admin_id' => 1, 'actor_name' => 'Rival', 'created_at' => now(),
            ]);
        });

        $v2 = $this->approvedVersion($ana, $root, 'Versión 2');
        $p2 = $this->approvedProfile($ana, $root, self::profileContent(['maxDurationSeconds' => 240]));
        $this->labVerdictFor($v2, $p2, $ana, $root);
        $antes = count($this->fake->callsOf('assign'));
        $this->publishVoice($pablo, $v2, $p2)->assertStatus(409)->assertJsonPath('code', 'publication_moved');
        $this->assertTrue($colada, 'la rival no llegó a colarse');

        // Nada de Pablo se confirmó: la entrada 2 es la de la rival y el canario sigue con el agente de antes.
        $this->assertSame([1, 2], StudioVoicePublication::query()->orderBy('seq')->pluck('seq')->all());
        $this->assertSame('off', StudioVoicePublication::query()->where('seq', 2)->value('action'));
        $this->assertSame((int) $rival->id, (int) StudioVoiceAgent::query()->where('serves_canary', true)->value('id'));
        // El número se asignó al agente nuevo y la compensación lo devolvió al de la cadena.
        $asignaciones = array_slice($this->fake->callsOf('assign'), $antes);
        $this->assertSame(['agent_h8s_0002', $rival->provider_agent_id], array_column($asignaciones, 2));
        // Y la cadena sigue verificando entera.
        foreach (StudioVoicePublication::SCOPES as $parte) {
            $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current($parte)), $parte);
        }
        $this->assertSame(StudioVoicePublishing::LOCK, 'ultron_studio_voice:chain');
    }
}
