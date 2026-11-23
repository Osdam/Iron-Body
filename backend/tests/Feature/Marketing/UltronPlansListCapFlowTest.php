<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17b · LA REGLA «TOPE DE LISTA» POR LOS ENDPOINTS REALES (Enmienda A4-6 del
 * H5; decisión B del propietario, 23-11-2026): `decide` → `ai/check` →
 * `commit`, con las clases reales y la versión publicada solo en la canaria
 * («profesional · 200»), el catálogo vendible de producción y la ficha del
 * horario (el escenario de `UltronA4ListaDelModeloTest`).
 *
 *  1. `decide` firma `sp.k` = la versión del compilador SOLO en `studio`; la
 *     respuesta a n8n (`studio_prompts`) no cambia de forma.
 *  2. `ai/check` con tope 200: una lista que mide 310 pasa; una de 332 pide
 *     reescribir con la nota que nombra 320; sin lista, 204 pide reescribir
 *     con 200; con el sello del compilador 5 (el token sin `k`), la lista
 *     vuelve a medirse contra 200.
 *  3. `commit`: la lista de 303 con su pregunta sale ENTERA, sin recorte
 *     (`metadata.length.list_cap` = 320); la de 332 no se recorta, se
 *     descarta con `policy_length_exceeded` y sale el respaldo de precios de
 *     hoy, con el tope aplicado en la traza.
 *  4. EL COMPILADOR 5 Y LEGACY, COMO HOY: con el token sin `k`, a la lista de
 *     214 se le quita la pregunta final (la regla de siempre) y la traza no
 *     lleva `list_cap`; sin negociar los prompts (Legacy), no hay ni tope ni
 *     traza de longitud.
 *  5. «PROFESIONAL · 320», EL TOPE DE LA LÍNEA BASE (bloqueante B1 de la
 *     revisión del 23-11-2026): `decide` no firma `sp.n`, y aun así la lista
 *     se mide contra 320. `ai/check`: la de 310 pasa y la de 450 pide
 *     reescribir con la nota de 320. `commit`: la de 310 sale entera
 *     (`cap` null, `list_cap` 320) y la de 450 no se recorta, se descarta con
 *     `policy_length_exceeded` y sale el respaldo de precios, con el tope en
 *     la traza. Un turno SIN lista de 450 sale igual que con el token del
 *     compilador 5 (sin medir, sin traza de longitud), en `ai/check` y en el
 *     commit.
 *
 * Lo que no cambia en el resto lo fijan las instantáneas Legacy de A4
 * (`UltronA4LongitudYPreciosTest`, `UltronA4RegistroYPanoramaTest`) y las
 * pruebas de la lista (`UltronA4ListaDelModeloTest`), sin regenerar.
 */
class UltronPlansListCapFlowTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c6';

    /** La versión «profesional · 320» (el tope de la línea base). */
    private const VERSION_320 = 'c0000000-0000-4000-8000-000000000320';

    /** El catálogo vendible de producción (A4 §2.1): id, nombre, precio, días; en el orden de `activePlans()`. */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1],
        [2, 'Plan Semana', 45000, 7],
        [3, 'Plan Valera', 65000, 30],
        [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90],
        [6, 'Semestre', 390000, 180],
        [7, 'Anualidad', 624000, 365],
        [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90],
        [10, 'Prime', 950000, 180],
        [11, 'Evolution', 1800000, 365],
        [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    /** La lista que firma `decide` para esa pregunta. */
    private const PO = [3, 4, 8];

    /** La lista fija de hoy con tope 200: el respaldo de precios. */
    private const PRECIOS_200 = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    /** 303 caracteres expandida, con su pregunta final (248 sin ella). */
    private const LISTA_303 = 'Con gusto le cuento. Tenemos {{PLAN_1}}, con acceso total al gimnasio y asesoría semi personalizada; {{PLAN_2}}, con acceso total y clases grupales ilimitadas; y {{PLAN_3}}, con entrenador personal dedicado. ¿Cuál de los tres le gustaría revisar con más detalle?';

    /** 332 caracteres expandida (277 sin la pregunta). */
    private const LISTA_332 = 'Con mucho gusto le cuento cómo funciona cada uno. Tenemos {{PLAN_1}}, con acceso total al gimnasio y asesoría semi personalizada; {{PLAN_2}}, con acceso total y clases grupales ilimitadas; y {{PLAN_3}}, con entrenador personal dedicado. ¿Cuál de los tres le gustaría revisar con más detalle?';

    /** 214 caracteres expandida (151 sin la pregunta): con el compilador 5, la regla de siempre la recorta. */
    private const LISTA_214 = 'Claro, con gusto. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. ¿Cuál de los tres le gustaría que le explique con más detalle?';

    /** 450 caracteres expandida, con su pregunta final: pasa de 320. */
    private const LISTA_450 = 'Con mucho gusto le cuento cómo funciona cada uno. Tenemos {{PLAN_1}}, con acceso total al gimnasio y asesoría semi personalizada; {{PLAN_2}}, con acceso total y clases grupales ilimitadas; y {{PLAN_3}}, con entrenador personal dedicado. Los tres son de un mes, con acceso de lunes a domingos a todas las zonas del gimnasio desde el primer día de su plan. ¿Cuál de los tres le gustaría revisar con más detalle?';

    /** Una respuesta sin lista ni marcadores, de 450 caracteres: con la línea base no se mide. */
    private const HORARIO_450 = 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. Entre semana puede venir a la hora que mejor le quede, desde muy temprano hasta la noche, y entrenar con calma en cualquiera de las zonas del gimnasio. Los fines de semana y los festivos el horario es más corto, así que le conviene llegar temprano para aprovechar bien su entrenamiento. Si quiere, le cuento qué clases hay cada día.';

    /** Una respuesta sin lista ni marcadores, de 204 caracteres y sin pregunta final. */
    private const HORARIO_204 = 'Abrimos de lunes a viernes desde las cinco de la mañana hasta las diez de la noche, y los sábados, domingos y festivos de ocho de la mañana a dos de la tarde. Si quiere, le cuento qué clases hay cada día.';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        config()->set('automation.rate_limits.marketing_ai', 100000);
        Http::fake();

        $this->catalogoDeProduccion();
        $this->fichaDelHorario();
        $this->nuevaConversacion();
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Lo que miden los textos de arriba ya expandidos: si el catálogo cambiara, las pruebas dirían otra cosa. */
    public function test_los_textos_miden_lo_que_dicen(): void
    {
        $this->assertSame([303, 332, 214, 204, 450, 450], [self::mide(self::LISTA_303), self::mide(self::LISTA_332), self::mide(self::LISTA_214), self::mide(self::HORARIO_204), self::mide(self::LISTA_450), self::mide(self::HORARIO_450)]);
        $this->assertSame(151, UltronReplyFitter::measure((string) UltronReplyFitter::withoutFinalQuestion(self::expandida(self::LISTA_214))));
    }

    // ── 1 · `decide` ─────────────────────────────────────────────────────────

    public function test_decide_firma_el_compilador_solo_en_studio_y_la_respuesta_a_n8n_no_cambia(): void
    {
        $decide = $this->decide($this->entrante(self::PANORAMA), true);
        $sp = self::cargaDelToken($decide['decide_token'])['x']['sp'];
        $this->assertSame(['studio', 200, 'profesional', 1, UltronPromptCompiler::VERSION], [$sp['m'], $sp['n'], $sp['t'], $sp['o'], $sp['k']]);
        $this->assertSame(6, $sp['k']);
        $this->assertSame(['contract', 'mode', 'reason', 'base', 'compiler', 'bundle_hash', 'version', 'content_hash', 'entry', 'seq', 'roles'], array_keys($decide['studio_prompts']), 'la respuesta a n8n, con su forma de siempre');
        $this->assertSame(self::PO, self::cargaDelToken($decide['decide_token'])['x']['po']);

        // Legacy con los prompts negociados (llave apagada): sin `k`.
        config()->set('marketing.ultron.studio_prompts', 'off');
        $legacy = self::cargaDelToken($this->decide($this->entrante(self::PANORAMA), true)['decide_token'])['x']['sp'];
        $this->assertSame('legacy', $legacy['m']);
        $this->assertArrayNotHasKey('k', $legacy);
        // Sin negociar: ni `sp`.
        $this->assertArrayNotHasKey('sp', (array) (self::cargaDelToken($this->decide($this->entrante(self::PANORAMA), false)['decide_token'])['x'] ?? []));
    }

    // ── 2 · `ai/check` ───────────────────────────────────────────────────────

    public function test_ai_check_la_lista_de_310_pasa_la_de_332_nombra_320_y_sin_lista_204_nombra_200(): void
    {
        $m = $this->entrante(self::PANORAMA);
        $token = $this->decide($m, true)['decide_token'];

        // 310 (la de 303 con siete caracteres más dentro): pasa con tope 200.
        $de310 = str_replace('Con gusto le cuento.', 'Con mucho gusto, le cuento.', self::LISTA_303);
        $this->assertSame(310, self::mide($de310));
        $r = $this->check($m, $token, $de310)->assertOk()->json();
        $this->assertSame([true, [], false], [$r['pass'], $r['defects'], $r['rewrite']], '310 con la lista');

        // 332: reescribir por la longitud, con la nota del tope de la lista.
        $r = $this->check($m, $token, self::LISTA_332)->assertOk()->json();
        $this->assertSame([false, [N::LENGTH_OVER_CAP], true], [$r['pass'], array_column($r['defects'], 'code'), $r['rewrite']], '332 con la lista');
        $this->assertStringContainsString('mide 332 caracteres y el limite publicado es 320', $r['notes']);

        // El sello del compilador 5 (el mismo token sin `k`, re-firmado): la de 310 se mide contra 200, como hoy.
        $r = $this->check($m, self::sinElCompilador($token), $de310)->assertOk()->json();
        $this->assertSame([false, [N::LENGTH_OVER_CAP]], [$r['pass'], array_column($r['defects'], 'code')], '310 con el sello del 5');
        $this->assertStringContainsString('mide 310 caracteres y el limite publicado es 200', $r['notes']);

        // Sin lista en el turno (no pidió los planes): 204 contra 200.
        $h = $this->entrante('¿Qué horario tienen?');
        $th = $this->decide($h, true)['decide_token'];
        $this->assertArrayNotHasKey('po', self::cargaDelToken($th)['x'], 'sin lista ofrecida');
        $r = $this->check($h, $th, self::HORARIO_204, 1, SalesIntents::SCHEDULE_QUESTION)->assertOk()->json();
        $this->assertContains(N::LENGTH_OVER_CAP, array_column($r['defects'], 'code'), '204 sin lista');
        $this->assertStringContainsString('mide 204 caracteres y el limite publicado es 200', $r['notes']);
    }

    // ── 3 · `commit` ─────────────────────────────────────────────────────────

    public function test_commit_la_lista_de_303_con_su_pregunta_sale_entera(): void
    {
        $r = $this->aprobado(self::LISTA_303);

        $this->assertSame(self::expandida(self::LISTA_303), $r['reply_final'], 'sin recortar la pregunta final');
        $this->assertSame(self::expandida(self::LISTA_303), $this->ultimoSaliente()?->body);
        $this->assertStringEndsWith('¿Cuál de los tres le gustaría revisar con más detalle?', $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertSame(['cap' => 200, 'action' => 'fit', 'from' => 303, 'to' => 303, 'dropped' => 0, 'list_cap' => 320], $fila->metadata['length']);
        $this->assertTrue($fila->metadata['plans_overview']['valid']);
    }

    public function test_commit_la_lista_de_332_no_se_recorta_y_sale_el_respaldo_con_su_causa(): void
    {
        $r = $this->aprobado(self::LISTA_332);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el respaldo de precios de hoy, dentro del tope publicado');
        $this->assertSame(self::PRECIOS_200, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertSame(self::expandida(self::LISTA_332), $fila->metadata['policy_discarded_draft'], 'el borrador descartado, entero: no se recortó');
        $this->assertSame(['cap' => 200, 'action' => 'replaced', 'from' => 332, 'to' => UltronReplyFitter::measure(self::PRECIOS_200), 'dropped' => 0, 'list_cap' => 320], $fila->metadata['length']);
    }

    // ── 4 · el compilador 5 y Legacy, como hoy ───────────────────────────────

    public function test_con_el_sello_del_compilador_5_la_lista_se_recorta_como_hoy(): void
    {
        // Con el compilador 6, la de 214 sale entera.
        $r = $this->aprobado(self::LISTA_214);
        $this->assertSame(self::expandida(self::LISTA_214), $r['reply_final']);
        $this->assertSame(['cap' => 200, 'action' => 'fit', 'from' => 214, 'to' => 214, 'dropped' => 0, 'list_cap' => 320], $this->ultimaAccion()->metadata['length']);

        // Con el token del compilador 5 (sin `k`): la regla de siempre le quita la pregunta final.
        $this->nuevaConversacion();
        $m = $this->entrante(self::PANORAMA);
        $token = self::sinElCompilador($this->decide($m, true)['decide_token']);
        $r = $this->commit($this->payload($m, $token, self::propuesta(self::LISTA_214), self::APROBADO))->assertOk()->json();
        $sinPregunta = (string) UltronReplyFitter::withoutFinalQuestion(self::expandida(self::LISTA_214));
        $this->assertSame($sinPregunta, $r['reply_final']);
        $this->assertSame(['cap' => 200, 'action' => 'trimmed', 'from' => 214, 'to' => 151, 'dropped' => 1], $this->ultimaAccion()->metadata['length'], 'sin list_cap');
    }

    public function test_en_legacy_no_hay_tope_ni_traza_de_longitud(): void
    {
        $m = $this->entrante('¿Qué horario tienen?');
        $decide = $this->decide($m, false);
        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(self::HORARIO_204, SalesIntents::SCHEDULE_QUESTION), self::APROBADO))->assertOk()->json();

        $this->assertSame(self::HORARIO_204, $r['reply_final'], 'Legacy: sin tope, sale entera');
        $this->assertArrayNotHasKey('length', (array) $this->ultimaAccion()->metadata);
    }

    // ── 5 · «profesional · 320»: el tope de la línea base (B1) ──────────────

    public function test_con_320_decide_no_firma_n_y_ai_check_mide_la_lista_contra_320(): void
    {
        $this->publicaLaDe320();
        $m = $this->entrante(self::PANORAMA);
        $token = $this->decide($m, true)['decide_token'];
        $sp = self::cargaDelToken($token)['x']['sp'];
        $this->assertSame(['studio', 'profesional', 1, UltronPromptCompiler::VERSION], [$sp['m'], $sp['t'], $sp['o'], $sp['k']]);
        $this->assertArrayNotHasKey('n', $sp, 'el tope de la línea base no se firma');
        $this->assertSame(self::PO, self::cargaDelToken($token)['x']['po']);

        // 310 con la lista: pasa, sin recorte.
        $de310 = str_replace('Con gusto le cuento.', 'Con mucho gusto, le cuento.', self::LISTA_303);
        $r = $this->check($m, $token, $de310)->assertOk()->json();
        $this->assertSame([true, [], false, 310], [$r['pass'], $r['defects'], $r['rewrite'], self::mide($de310)], '310 con la lista');

        // 450 con la lista: reescribir por la longitud, con la nota del tope de la lista.
        $r = $this->check($m, $token, self::LISTA_450)->assertOk()->json();
        $this->assertSame([false, [N::LENGTH_OVER_CAP], true], [$r['pass'], array_column($r['defects'], 'code'), $r['rewrite']], '450 con la lista');
        $this->assertStringContainsString('mide 450 caracteres y el limite publicado es 320', $r['notes']);
    }

    public function test_con_320_el_commit_saca_la_lista_de_310_entera_y_la_de_450_es_respaldo_con_su_causa(): void
    {
        $this->publicaLaDe320();

        $de310 = str_replace('Con gusto le cuento.', 'Con mucho gusto, le cuento.', self::LISTA_303);
        $r = $this->aprobado($de310);
        $this->assertSame(self::expandida($de310), $r['reply_final'], 'sin recortar la pregunta final');
        $this->assertSame(self::expandida($de310), $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertSame(['cap' => null, 'action' => 'fit', 'from' => 310, 'to' => 310, 'dropped' => 0, 'list_cap' => 320], $fila->metadata['length']);

        $this->nuevaConversacion();
        $r = $this->aprobado(self::LISTA_450);
        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el respaldo de precios de hoy (sin tope publicado, el mismo texto)');
        $this->assertSame(self::PRECIOS_200, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        // La fila guarda los 400 primeros caracteres del descartado (como siempre); `from` = 450 dice que no se recortó.
        $this->assertSame(mb_substr(self::expandida(self::LISTA_450), 0, 400), $fila->metadata['policy_discarded_draft'], 'el borrador descartado, sin recortar');
        $this->assertSame(['cap' => null, 'action' => 'replaced', 'from' => 450, 'to' => UltronReplyFitter::measure(self::PRECIOS_200), 'dropped' => 0, 'list_cap' => 320], $fila->metadata['length']);
    }

    public function test_con_320_un_turno_sin_lista_de_450_va_como_hoy(): void
    {
        $this->publicaLaDe320();

        // `ai/check`: con el sello del 6 y con el del 5, los mismos defectos, y ninguno de longitud.
        $m = $this->entrante('¿Qué horario tienen?');
        $token = $this->decide($m, true)['decide_token'];
        $this->assertArrayNotHasKey('po', self::cargaDelToken($token)['x'], 'sin lista ofrecida');
        $con6 = $this->check($m, $token, self::HORARIO_450, 1, SalesIntents::SCHEDULE_QUESTION)->assertOk()->json();
        $con5 = $this->check($m, self::sinElCompilador($token), self::HORARIO_450, 1, SalesIntents::SCHEDULE_QUESTION)->assertOk()->json();
        $this->assertNotContains(N::LENGTH_OVER_CAP, array_column($con6['defects'], 'code'), '450 sin lista: no se mide');
        $this->assertSame($con5['defects'], $con6['defects']);

        // `commit`: sale igual con el token del 6 que con el del 5, sin traza de longitud.
        $hoy = [];
        foreach ([false, true] as $conElCinco) {
            $this->nuevaConversacion();
            $m = $this->entrante('¿Qué horario tienen?');
            $token = $this->decide($m, true)['decide_token'];
            $token = $conElCinco ? self::sinElCompilador($token) : $token;
            $r = $this->commit($this->payload($m, $token, self::propuesta(self::HORARIO_450, SalesIntents::SCHEDULE_QUESTION), self::APROBADO))->assertOk()->json();
            $fila = $this->ultimaAccion()->metadata;
            $this->assertArrayNotHasKey('length', $fila, $conElCinco ? 'sello del 5' : 'sello del 6');
            $this->assertNotContains(UltronCommitService::VIOLACION_LONGITUD, (array) ($fila['policy_violations'] ?? []));
            $hoy[] = [$r['reply_final'], $fila['policy_violations'] ?? null];
        }
        $this->assertSame($hoy[1], $hoy[0], 'el sello del 6 y el del 5 dan lo mismo');
        $this->assertSame(self::HORARIO_450, $hoy[0][0], 'sale entera');
    }

    public function test_con_320_y_la_visita_a_medias_la_frase_que_la_retoma_no_saca_la_lista_de_su_tope(): void
    {
        $this->publicaLaDe320();
        // Una visita a medio concretar (como `UltronCheckCommitParityTest`): tras contestar, el commit la retoma si cabe.
        app(ConversationMemoryService::class)->recordCourtesyCollecting($this->conversation->fresh(), ['date' => '2026-11-21']);
        $m = $this->entrante(self::PANORAMA);
        $decide = $this->decide($m, true);
        $this->assertSame(self::PO, self::cargaDelToken($decide['decide_token'])['x']['po'] ?? null, 'la lista se ofrece');
        $this->assertTrue($decide['context']['commercial_turn_policy']['resume_goal_after_answer'] ?? null, 'la visita se retoma tras contestar');

        // La de 310 con la frase que retoma la visita pasaría de 320: la frase no se añade y la lista sale entera (no el último recurso).
        $de310 = str_replace('Con gusto le cuento.', 'Con mucho gusto, le cuento.', self::LISTA_303);
        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($de310), self::APROBADO))->assertOk()->json();
        $this->assertSame(self::expandida($de310), $r['reply_final']);
        $this->assertSame(['cap' => null, 'action' => 'fit', 'from' => 310, 'to' => 310, 'dropped' => 0, 'list_cap' => 320], $this->ultimaAccion()->metadata['length']);
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** Publica «profesional · 320» (el tope de la línea base) encima de la de 200: la cadena sirve la última. */
    private function publicaLaDe320(): void
    {
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_320, ['persona.tone' => 'profesional', 'persona.maxChars' => 320]), null);
    }

    private static function expandida(string $borrador): string
    {
        return str_replace(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], ['Plan Valera ($65.000 COP)', 'Plan Mensual ($80.000 COP)', 'Élite ($180.000 COP)'], $borrador);
    }

    private static function mide(string $borrador): int
    {
        return UltronReplyFitter::measure(self::expandida($borrador));
    }

    /**
     * El mismo token SIN `sp.k`, re-firmado con la clave de la aplicación: lo
     * que habría firmado `decide` con el compilador 5 (o antes del despliegue).
     */
    private static function sinElCompilador(string $token): string
    {
        $carga = self::cargaDelToken($token);
        unset($carga['x']['sp']['k']);
        $cuerpo = rtrim(strtr(base64_encode((string) json_encode($carga, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        return $cuerpo.'.'.hash_hmac('sha256', $cuerpo, (string) config('app.key'));
    }

    private function catalogoDeProduccion(): void
    {
        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
    }

    private function fichaDelHorario(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150540'.$n, 'meta_user_id' => '573150540'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        config()->set('marketing.ultron.studio_prompts', 'canary');
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.c6.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m, bool $negocia): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
    }

    private function check(MarketingMessage $m, string $token, string $borrador, int $attempt = 1, string $intent = SalesIntents::PRICING_QUESTION): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id,
            'message_id' => $m->id,
            'decide_token' => $token,
            'attempt' => $attempt,
            'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => null, 'tools_requested' => []],
        ], $this->headers());
    }

    private static function propuesta(string $borrador, string $intent = SalesIntents::PRICING_QUESTION): array
    {
        return [
            'strategy_goal' => 'resolve_objection',
            'next_state' => P::GOAL_DISCOVERY,
            'intent' => $intent,
            'reply_draft' => $borrador,
            'recommended_plan_id' => null,
            'main_barrier' => null,
            'next_best_action' => 'answer',
            'confidence' => 0.9,
            'tools_requested' => [],
        ];
    }

    private function payload(MarketingMessage $m, string $token, array $propuesta, array $critico): array
    {
        return [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => $propuesta,
            'critic' => $critico,
        ];
    }

    /** Un borrador que el revisor aprobó, a la pregunta de los planes: el turno sale por el camino normal. */
    private function aprobado(string $borrador): array
    {
        $m = $this->entrante(self::PANORAMA);
        $decide = $this->decide($m, true);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($borrador), self::APROBADO))->assertOk()->json();
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }

    private function ultimoSaliente(): ?MarketingMessage
    {
        return MarketingMessage::query()->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first();
    }
}
