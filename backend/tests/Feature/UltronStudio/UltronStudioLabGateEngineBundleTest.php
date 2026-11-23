<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Models\UltronStudio\StudioPublication;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * NB2 DE LA REVISIÓN DE SEGURIDAD DEL APARTADO 5 (`informe-security-lab.md`):
 * con la llave de la puerta en `engine` (`ULTRON_STUDIO_PUBLISH_LAB_SOURCE`),
 * el veredicto del motor tiene que haberse emitido sobre EL MISMO paquete de
 * prompts que se va a publicar. `StudioLabEngineFlow::promptVersion()` es
 * constante: si la base o el compilador cambiaran sin subirla, un veredicto
 * viejo del motor seguiría contando. La puerta compara la huella congelada en
 * la ejecución (`target_snapshot.engine.bundleHash`) con la de la versión
 * compilada hoy; si no coinciden, el mismo tratamiento que `stale_prompts`.
 *
 * Con la llave en `simulation` (la de producción hoy), NADA cambia: el mismo
 * veredicto del motor no toca la puerta, ni su forma, ni la huella de la
 * vista previa.
 */
class UltronStudioLabGateEngineBundleTest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** La huella de OTRO paquete de prompts: el que se habría probado antes de un cambio de la base sin subir la versión. */
    private const OTHER_BUNDLE = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
    }

    public function test_nb2_con_engine_un_veredicto_del_motor_sobre_otro_paquete_no_abre_la_puerta(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $this->assertNotSame(self::OTHER_BUNDLE, StudioPromptBundle::compile(StudioPromptBundle::governedFrom($v->content))['prompts']['bundleHash']);
        config(['ultron_studio.publishing.lab_source' => 'engine']);

        // Aprobado, de la familia del motor, con esta versión y esta huella… pero sobre otro paquete.
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion(), self::OTHER_BUNDLE);
        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => 'stale_prompts', 'message' => 'El veredicto del laboratorio es de una versión anterior de los prompts.'],
            $this->previewOf($root, $v->uuid)['requirements']['labGate']);
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required');
        $this->assertSame(0, StudioPublication::query()->count());

        // Un veredicto del motor sobre el paquete que se publica sí la abre, y es el que queda firmado.
        $bueno = $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion());
        $this->assertSame(['mode' => 'required', 'ok' => true, 'latest' => 'approved'], $this->previewOf($root, $v->uuid)['requirements']['labGate']);
        $this->publishAs($root, $v->uuid)->assertCreated();
        $this->assertSame(['mode' => 'required', 'runUuid' => $bueno->run->uuid, 'verdictId' => $bueno->id, 'promptVersion' => StudioLabEngineFlow::promptVersion()],
            StudioPublication::query()->sole()->lab_gate);
    }

    public function test_nb2_con_engine_el_ultimo_veredicto_manda_y_uno_viejo_de_otro_paquete_no_cuenta(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        config(['ultron_studio.publishing.lab_source' => 'engine']);

        // El aprobado se emitió sobre el paquete de hoy; DESPUÉS, otro sobre un paquete distinto: el último no vale.
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion());
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion(), self::OTHER_BUNDLE);
        $this->assertSame(['ok' => false, 'latest' => 'stale_prompts'], array_intersect_key($this->previewOf($root, $v->uuid)['requirements']['labGate'], ['ok' => 1, 'latest' => 1]));
    }

    public function test_nb2_en_simulation_el_mismo_veredicto_del_motor_no_cambia_nada(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        // Solo un veredicto del motor sobre otro paquete: para la simulación, como cualquier otro del motor (null).
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion(), self::OTHER_BUNDLE);
        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'],
            $this->previewOf($root, $v->uuid)['requirements']['labGate']);

        // Con el de la simulación, la puerta se abre con él, y el del motor (posterior) no mueve nada: ni la
        // puerta, ni la huella de la vista previa, ni lo que se firma.
        $sim = $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED);
        $antes = $this->previewOf($root, $v->uuid);
        $this->labVerdict($v, StudioLabVerdict::DECISION_REJECTED, StudioLabEngineFlow::promptVersion(), self::OTHER_BUNDLE);
        $despues = $this->previewOf($root, $v->uuid);
        $this->assertSame(['mode' => 'required', 'ok' => true, 'latest' => 'approved'], $despues['requirements']['labGate']);
        $this->assertSame([$antes['requirements']['labGate'], $antes['previewHash']], [$despues['requirements']['labGate'], $despues['previewHash']]);
        $this->publishAs($root, $v->uuid)->assertCreated();
        $this->assertSame(['mode' => 'required', 'runUuid' => $sim->run->uuid, 'verdictId' => $sim->id, 'promptVersion' => $sim->run->prompt_version],
            StudioPublication::query()->sole()->lab_gate);
    }
}
