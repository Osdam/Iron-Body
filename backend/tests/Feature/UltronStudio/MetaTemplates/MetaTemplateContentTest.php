<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 1b (PLANTILLAS_META_PLAN §5.1–§5.3; L9-96): el
 * contenido ampliado de WhatsApp, PURO. Las huellas de lo que ya existe no
 * cambian (golden), la matriz de límites de Meta, la petición a Meta congelada
 * por caso, la huella de los componentes igual desde el Studio y desde una
 * respuesta de Meta, el peor caso de tamaño por debajo de 8 KB (R11), las
 * etiquetas de baja reconocidas por el normalizador de W9 y la vista previa
 * estructurada. Nada cableado: ni red ni escritura fuera de lo de siempre.
 */
class MetaTemplateContentTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;
    use StudioFixtures;

    private const GOLDEN = [
        // [finalidad, contenido, huella con el código ANTERIOR a este paso (calculada sobre 07e70dac)]
        'StrategyFixtures (comercial)' => ['commercial', ['metaName' => 'renovacion_7d', 'language' => 'es_CO', 'category' => 'marketing', 'bodyPreview' => 'Hola {{1}}, tu plan {{2}} vence pronto. Si no quieres más mensajes, responde BAJA.', 'variables' => ['first_name', 'plan_name'], 'optOutLine' => true], '04940d02065cdbfa0e6d861e87d2872ee72fee81aa8118022e498082e8263d99'],
        'StrategyFixtures (servicio)' => ['service', ['metaName' => 'renovacion_7d', 'language' => 'es_CO', 'category' => 'utility', 'bodyPreview' => 'Hola {{1}}, tu plan {{2}} vence pronto. Si no quieres más mensajes, responde BAJA.', 'variables' => ['first_name', 'plan_name'], 'optOutLine' => true], '46f36dce9061581ec9a099b36dc9b9167c189fa3b6c52c3bc016519a87d70db5'],
        'ValidationTest $bien' => ['commercial', ['metaName' => 'aviso', 'language' => 'es', 'category' => 'marketing', 'bodyPreview' => 'Hola {{1}}. Si no quieres más mensajes, responde BAJA.', 'variables' => ['first_name'], 'optOutLine' => true], 'b8c9714354d1c636316c82f72eb974ee9e0dc40df24df9ee66cd88c6381b3a27'],
        'W9World (comercial)' => ['commercial', ['bodyPreview' => 'Hola {{1}}, tu plan vence el {{2}}. Responde BAJA para no recibir más mensajes.', 'category' => 'marketing', 'language' => 'es', 'metaName' => 'renovacion_w9', 'optOutLine' => true, 'variables' => ['first_name', 'membership_end_date_text']], 'baa7317044897261dadbb1595e992378e55eeb02c606449352f6b2e17e4295fa'],
        'W9World (cobranza)' => ['collections', ['bodyPreview' => 'Hola {{1}}, tu plan vence el {{2}}. Responde BAJA para no recibir más mensajes.', 'category' => 'utility', 'language' => 'es', 'metaName' => 'renovacion_w9', 'optOutLine' => true, 'variables' => ['first_name', 'membership_end_date_text']], 'a96bd588e954dca8b79f256e586f99dd92d7bcda694ba4ba5be0a8e00690757d'],
        'W9 enlace de pago' => ['commercial', ['bodyPreview' => 'Hola {{1}}, renueva aquí: {{2}}. Responde BAJA para no recibir más mensajes.', 'category' => 'marketing', 'language' => 'es', 'metaName' => 'renovacion_w9', 'optOutLine' => true, 'variables' => ['first_name', 'payment_link']], '29781c679bcde93ddb67eea0877a79c856bb3e8a276291703b68075a4c4ab68f'],
        'sin variables' => ['service', ['bodyPreview' => 'Tu clase de mañana sigue en pie. Responde BAJA para no recibir más mensajes.', 'category' => 'utility', 'language' => 'es_CO', 'metaName' => 'clase_confirmada', 'optOutLine' => true, 'variables' => []], 'cd6497bd71e0fe4ac82c9ee16b07b597325f998dd26924fc8edf08b41469ea6d'],
    ];

    /** La plantilla del ejemplo de §5.1 del plan. */
    private const BIEN = [
        'metaName' => 'renovacion_aviso_v2',
        'language' => 'es_CO',
        'category' => 'marketing',
        'header' => ['format' => 'TEXT', 'text' => 'Tu plan está por vencer'],
        'bodyPreview' => 'Hola {{1}}, tu {{2}} vence el {{3}}. Si quieres seguir entrenando con nosotros, respóndenos y te ayudamos.',
        'variables' => ['first_name', 'plan_name', 'membership_end_date_text'],
        'examples' => ['first_name' => 'Laura', 'plan_name' => 'Plan Trimestral', 'membership_end_date_text' => '15 de diciembre'],
        'footer' => ['text' => 'Escribe BAJA para no recibir más mensajes'],
        'buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'],
            ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out'],
        ],
        'optOutLine' => true,
    ];

    private const HOST = 'ironbodyneiva.cloud';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        config(['ultron_studio.meta_templates.url_hosts' => [], 'ultron_studio.meta_templates.phone_button' => false, 'legal.privacy_phone' => '+57 314 3455483']);
    }

    /** @param  array<string, mixed>  $cambios @return array<string, mixed> */
    private static function bien(array $cambios = []): array
    {
        return array_replace(self::BIEN, $cambios);
    }

    /** @param  array<string, mixed>  $contenido @return list<array{key:string,code:string}> */
    private static function errores(array $contenido, string $finalidad = 'commercial'): array
    {
        return T::content('whatsapp', $finalidad, $contenido)['errors'];
    }

    private function conBotonesEspeciales(): void
    {
        config(['ultron_studio.meta_templates.url_hosts' => [self::HOST], 'ultron_studio.meta_templates.phone_button' => true]);
    }

    // ── Golden: lo que existe no cambia ni un byte ─────────────────────────

    public function test_las_huellas_de_las_plantillas_de_las_semillas_y_los_fixtures_no_cambian(): void
    {
        foreach (self::GOLDEN as $nombre => [$finalidad, $contenido, $huella]) {
            $r = T::content('whatsapp', $finalidad, $contenido);
            $this->assertSame([], $r['errors'], $nombre);
            $this->assertSame($huella, $r['hash'], "{$nombre}: la huella de una plantilla de solo cuerpo cambió.");
            $this->assertSame(hash('sha256', (string) $r['json']), $r['hash']);
            $this->assertSame(['bodyPreview', 'category', 'language', 'metaName', 'optOutLine', 'variables'], array_keys(json_decode((string) $r['json'], true)), "{$nombre}: claves nuevas en un contenido de los de siempre.");
            // Las claves nuevas, vacías o a null, son lo mismo que no mandarlas: los mismos bytes.
            $vacias = T::content('whatsapp', $finalidad, $contenido + ['header' => null, 'footer' => null, 'buttons' => [], 'examples' => []]);
            $this->assertSame($huella, $vacias['hash'], "{$nombre}: las claves opcionales vacías cambian la huella.");
        }
        // Las cuatro plantillas de solo cuerpo de las semillas: ninguna tiene componentes.
        foreach (self::GOLDEN as [, $contenido]) {
            $this->assertFalse(T::hasComponents($contenido));
        }
        // AUTHENTICATION sigue fuera del dominio (D-PM-7).
        $this->assertSame(['marketing', 'utility'], T::CATEGORIES);
        $this->assertSame(['marketing' => 'MARKETING', 'utility' => 'UTILITY'], T::META_CATEGORIES);
    }

    public function test_una_version_guardada_con_las_reglas_de_antes_se_sigue_leyendo_igual(): void
    {
        // Empieza con un marcador y no trae ejemplos: válida antes; hoy una versión NUEVA así no se crea, pero la guardada se lee igual.
        $vieja = ['bodyPreview' => '{{1}}, tu plan vence pronto. Responde BAJA para no recibir más mensajes.', 'category' => 'marketing', 'language' => 'es', 'metaName' => 'vieja_con_variable_al_inicio', 'optOutLine' => true, 'variables' => ['first_name']];
        $this->assertContains(['key' => 'content.bodyPreview', 'code' => 'meta_dangling_parameter'], self::errores($vieja));
        $json = CanonicalJson::encode($vieja);
        $this->assertSame('d48698fadef0985d3b9d94ae910a1991f95553fd5b2d67937e99a5c20364f356', hash('sha256', $json), 'la huella con la que se guardó');

        [$ana, , $personaAna] = $this->bootstrapPair();
        $uuid = (string) Str::uuid();
        DB::table('ultron_studio_message_templates')->insert([
            'uuid' => $uuid, 'client_request_id' => (string) Str::uuid(), 'key' => 'vieja', 'number' => 1, 'channel' => 'whatsapp', 'purpose' => 'commercial',
            'category' => 'marketing', 'content_json' => $json, 'content_hash' => hash('sha256', $json), 'status' => 'DRAFT',
            'created_by_admin_id' => $ana->id, 'created_by_person_id' => $personaAna->id, 'created_by_name' => 'Ana', 'created_by_role' => 'super_admin',
            'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $d = $this->s9('GET', "strategies/templates/{$uuid}", $ana)->assertOk()->json('data');
        $this->assertTrue($d['integrity']);
        $this->assertSame('[first_name], tu plan vence pronto. Responde BAJA para no recibir más mensajes.', $d['preview'], '`preview` sigue siendo la cadena de siempre');
        $this->assertSame(['first_name' => 'Laura'], $d['waPreview']['examples'], 'sin ejemplos propios, los ficticios del catálogo');
        $this->assertContains('meta_examples_missing', $d['waPreview']['metaWarnings']);
        $this->assertTrue(T::missingMetaExamples(json_decode($json, true)));
    }

    // ── La matriz de límites ───────────────────────────────────────────────

    public function test_el_ejemplo_del_plan_es_valido_y_canonico(): void
    {
        $r = T::content('whatsapp', 'commercial', self::BIEN);
        $this->assertSame([], $r['errors']);
        $this->assertTrue(T::hasComponents($r['canonical']));
        $this->assertFalse(T::missingMetaExamples($r['canonical']));
        // El orden de las claves de entrada no cambia los bytes.
        $this->assertSame($r['hash'], T::content('whatsapp', 'commercial', array_reverse(self::BIEN, true))['hash']);
        // Solo con los ejemplos: sigue sin componentes (no llegan al socio).
        $soloEjemplos = array_diff_key(self::BIEN, ['header' => 1, 'footer' => 1, 'buttons' => 1]);
        $this->assertSame([], self::errores($soloEjemplos));
        $this->assertFalse(T::hasComponents($soloEjemplos));
    }

    public function test_la_matriz_de_limites_de_meta(): void
    {
        $this->conBotonesEspeciales();
        $url = 'https://'.self::HOST.'/planes';
        $casos = [
            // Encabezado: 60, una línea, solo TEXT, sin marcadores, sin Markdown, sin blancos en los bordes.
            'encabezado de 61' => [['header' => ['format' => 'TEXT', 'text' => str_repeat('a', 61)]], 'content.header.text', 'too_long'],
            'encabezado de imagen' => [['header' => ['format' => 'IMAGE', 'text' => 'Hola']], 'content.header.format', 'value_not_in_domain'],
            'encabezado sin formato' => [['header' => ['text' => 'Hola']], 'content.header.format', 'missing_key'],
            'encabezado con clave de más' => [['header' => ['format' => 'TEXT', 'text' => 'Hola', 'example' => 'x']], 'content.header.example', 'unexpected_key'],
            'encabezado lista' => [['header' => ['TEXT', 'Hola']], 'content.header', 'not_an_object'],
            'encabezado con marcador' => [['header' => ['format' => 'TEXT', 'text' => 'Hola {{1}}']], 'content.header.text', 'variables_not_allowed'],
            'encabezado con negrita' => [['header' => ['format' => 'TEXT', 'text' => 'Tu *plan* vence']], 'content.header.text', 'markdown_not_allowed'],
            'encabezado con cursiva' => [['header' => ['format' => 'TEXT', 'text' => 'Tu _plan_ vence']], 'content.header.text', 'markdown_not_allowed'],
            'encabezado con tachado' => [['header' => ['format' => 'TEXT', 'text' => 'Tu ~plan~ vence']], 'content.header.text', 'markdown_not_allowed'],
            'encabezado con código' => [['header' => ['format' => 'TEXT', 'text' => 'Tu `plan` vence']], 'content.header.text', 'markdown_not_allowed'],
            'encabezado de dos líneas' => [['header' => ['format' => 'TEXT', 'text' => "Tu plan\nvence"]], 'content.header.text', 'plain_text'],
            'encabezado con blanco final' => [['header' => ['format' => 'TEXT', 'text' => 'Tu plan vence ']], 'content.header.text', 'leading_trailing_spaces'],
            'encabezado R-02' => [['header' => ['format' => 'TEXT', 'text' => 'Mes GRATIS']], 'content.header.text', 'restriction_violated'],
            'encabezado con teléfono' => [['header' => ['format' => 'TEXT', 'text' => 'Llama al 3001234567']], 'content.header.text', 'contact_data'],
            // Pie: 60, una línea, sin marcadores.
            'pie de 61' => [['footer' => ['text' => str_repeat('a', 61)]], 'content.footer.text', 'too_long'],
            'pie con marcador' => [['footer' => ['text' => 'Hola {{1}}']], 'content.footer.text', 'variables_not_allowed'],
            'pie vacío' => [['footer' => ['text' => '   ']], 'content.footer.text', 'missing_key'],
            'pie con URL' => [['footer' => ['text' => 'Mira www.ironbody.co']], 'content.footer.text', 'restriction_violated'],
            // Cuerpo: ni empieza ni termina con un marcador; sin blancos en los bordes; 1 024.
            'cuerpo que empieza con marcador' => [['bodyPreview' => '{{1}}, tu {{2}} vence el {{3}}. Responde BAJA.'], 'content.bodyPreview', 'meta_dangling_parameter'],
            'cuerpo que termina con marcador' => [['bodyPreview' => 'Responde BAJA. Hola {{1}}, tu {{2}} vence el {{3}}'], 'content.bodyPreview', 'meta_dangling_parameter'],
            'cuerpo con salto final' => [['bodyPreview' => "Hola {{1}}, tu {{2}} vence el {{3}}. Responde BAJA.\n"], 'content.bodyPreview', 'leading_trailing_spaces'],
            'cuerpo de 1 025' => [['bodyPreview' => 'Hola {{1}}, tu {{2}} vence el {{3}}. '.str_repeat('a', 1025 - 36)], 'content.bodyPreview', 'too_long'],
            // Ejemplos: exactamente los marcadores; 40; reglas de parámetros.
            'falta un ejemplo' => [['examples' => ['first_name' => 'Laura', 'plan_name' => 'Plan Trimestral']], 'content.examples.membership_end_date_text', 'missing_key'],
            'sobra un ejemplo' => [['examples' => self::BIEN['examples'] + ['gym_name' => 'Iron Body']], 'content.examples.gym_name', 'unexpected_key'],
            'ejemplos en lista' => [['examples' => ['Laura', 'Plan', 'Mañana']], 'content.examples', 'invalid_type'],
            'ejemplo de 41' => [['examples' => ['first_name' => str_repeat('a', 41)] + self::BIEN['examples']], 'content.examples.first_name', 'too_long'],
            'ejemplo con #' => [['examples' => ['first_name' => 'Laura #1'] + self::BIEN['examples']], 'content.examples.first_name', 'example_forbidden_character'],
            'ejemplo con %' => [['examples' => ['first_name' => 'Laura 10%'] + self::BIEN['examples']], 'content.examples.first_name', 'example_forbidden_character'],
            'ejemplo con llaves' => [['examples' => ['first_name' => 'La{u}ra'] + self::BIEN['examples']], 'content.examples.first_name', 'example_forbidden_character'],
            'ejemplo con 4 espacios' => [['examples' => ['first_name' => 'La    ura'] + self::BIEN['examples']], 'content.examples.first_name', 'too_many_spaces'],
            'ejemplo con tabulador' => [['examples' => ['first_name' => "La\tura"] + self::BIEN['examples']], 'content.examples.first_name', 'plain_text'],
            'ejemplo con correo' => [['examples' => ['first_name' => 'laura@correo.co'] + self::BIEN['examples']], 'content.examples.first_name', 'contact_data'],
            'ejemplo con importe' => [['examples' => ['plan_name' => 'Plan de 120.000'] + self::BIEN['examples']], 'content.examples.plan_name', 'restriction_violated'],
            'ejemplo que no es texto' => [['examples' => ['first_name' => 7] + self::BIEN['examples']], 'content.examples.first_name', 'invalid_type'],
            // Botones: 1–3, 25, agrupación, etiquetas de baja, URL de la lista, el teléfono del gimnasio.
            'cuatro botones' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Uno'], ['type' => 'QUICK_REPLY', 'text' => 'Dos'], ['type' => 'QUICK_REPLY', 'text' => 'Tres'], ['type' => 'QUICK_REPLY', 'text' => 'Cuatro']]], 'content.buttons', 'too_many_values'],
            'botones como objeto' => [['buttons' => ['a' => ['type' => 'QUICK_REPLY', 'text' => 'Uno']]], 'content.buttons', 'invalid_type'],
            'texto de botón de 26' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => str_repeat('a', 26)]]], 'content.buttons.0.text', 'too_long'],
            'tipo de botón ajeno' => [['buttons' => [['type' => 'COPY_CODE', 'text' => 'Copiar']]], 'content.buttons.0.type', 'value_not_in_domain'],
            'tipo en minúsculas' => [['buttons' => [['type' => 'quick_reply', 'text' => 'Uno']]], 'content.buttons.0.type', 'value_not_in_domain'],
            'botón sin tipo' => [['buttons' => [['text' => 'Uno']]], 'content.buttons.0.type', 'missing_key'],
            'botón con clave de otro tipo' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Uno', 'url' => $url]]], 'content.buttons.0.url', 'unexpected_key'],
            'etiquetas repetidas' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Uno'], ['type' => 'QUICK_REPLY', 'text' => 'Uno']]], 'content.buttons.1.text', 'duplicate_value'],
            'baja con etiqueta libre' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ya no quiero', 'role' => 'opt_out']]], 'content.buttons.0.text', 'opt_out_label_not_allowed'],
            'papel ajeno' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Uno', 'role' => 'admin']]], 'content.buttons.0.role', 'value_not_in_domain'],
            'dos bajas' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out'], ['type' => 'QUICK_REPLY', 'text' => 'No más mensajes', 'role' => 'opt_out']]], 'content.buttons', 'too_many_values'],
            'respuestas rápidas partidas' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Uno'], ['type' => 'URL', 'text' => 'Ver', 'url' => $url], ['type' => 'QUICK_REPLY', 'text' => 'Dos']]], 'content.buttons', 'meta_button_grouping'],
            'tres URL' => [['buttons' => [['type' => 'URL', 'text' => 'A', 'url' => $url], ['type' => 'URL', 'text' => 'B', 'url' => $url], ['type' => 'URL', 'text' => 'C', 'url' => $url]]], 'content.buttons', 'too_many_values'],
            'dos teléfonos' => [['buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Llamar'], ['type' => 'PHONE_NUMBER', 'text' => 'Llamar ya']]], 'content.buttons', 'too_many_values'],
            'URL de 201' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://'.self::HOST.'/'.str_repeat('a', 201 - strlen('https://'.self::HOST.'/'))]]], 'content.buttons.0.url', 'too_long'],
            'URL http' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'http://'.self::HOST.'/planes']]], 'content.buttons.0.url', 'invalid_format'],
            'URL con marcador' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://'.self::HOST.'/p/{{1}}']]], 'content.buttons.0.url', 'invalid_format'],
            'URL con usuario' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://yo@'.self::HOST.'/planes']]], 'content.buttons.0.url', 'invalid_format'],
            'URL con puerto' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://'.self::HOST.':8443/planes']]], 'content.buttons.0.url', 'invalid_format'],
            'URL con espacio' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://'.self::HOST.'/mis planes']]], 'content.buttons.0.url', 'invalid_format'],
            'URL de otro dominio' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://ironbodyneiva.cloud.evil.com/planes']]], 'content.buttons.0.url', 'url_host_not_allowed'],
            'URL de un subdominio' => [['buttons' => [['type' => 'URL', 'text' => 'Ver', 'url' => 'https://api.'.self::HOST.'/planes']]], 'content.buttons.0.url', 'url_host_not_allowed'],
            'URL sin url' => [['buttons' => [['type' => 'URL', 'text' => 'Ver']]], 'content.buttons.0.url', 'missing_key'],
            'teléfono tecleado' => [['buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Llamar', 'phone' => '+57 300 1234567']]], 'content.buttons.0.phone', 'phone_not_allowed'],
            'botón con R-01' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Pagar $50.000']]], 'content.buttons.0.text', 'restriction_violated'],
            'botón con marcador' => [['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Hola {{1}}']]], 'content.buttons.0.text', 'variables_not_allowed'],
            // Lo de siempre sigue cerrado.
            'clave de más' => [['carousel' => []], 'content.carousel', 'unexpected_key'],
        ];
        foreach ($casos as $nombre => [$cambios, $clave, $codigo]) {
            $errores = self::errores(self::bien($cambios));
            $this->assertContains(['key' => $clave, 'code' => $codigo], $errores, $nombre.': '.json_encode($errores, JSON_UNESCAPED_UNICODE));
            // Todo error tiene su mensaje en el 422.
            $this->assertArrayHasKey($codigo, T::MESSAGES, $nombre);
        }

        // Y los límites exactos SÍ caben.
        $justos = self::bien([
            'header' => ['format' => 'TEXT', 'text' => str_repeat('a', 60)],
            'footer' => ['text' => str_repeat('b', 60)],
            'examples' => ['first_name' => str_repeat('c', 40), 'plan_name' => 'Plan', 'membership_end_date_text' => 'Mañana'],
            'buttons' => [
                ['type' => 'URL', 'text' => str_repeat('d', 25), 'url' => 'https://'.self::HOST.'/'.str_repeat('e', 200 - strlen('https://'.self::HOST.'/'))],
                ['type' => 'PHONE_NUMBER', 'text' => 'Llamar'],
                ['type' => 'QUICK_REPLY', 'text' => 'Detener promociones', 'role' => 'opt_out'],
            ],
        ]);
        $this->assertSame(200, strlen($justos['buttons'][0]['url']));
        $this->assertSame([], self::errores($justos));
        // Agrupadas antes o después de los demás: las dos valen.
        foreach ([['QUICK_REPLY', 'QUICK_REPLY', 'URL'], ['URL', 'QUICK_REPLY', 'QUICK_REPLY'], ['URL', 'PHONE_NUMBER', 'QUICK_REPLY'], ['QUICK_REPLY', 'URL', 'PHONE_NUMBER']] as $orden) {
            $botones = array_map(static fn (string $tipo, int $i): array => ['type' => $tipo, 'text' => "Botón {$i}"] + ($tipo === 'URL' ? ['url' => $url] : []), $orden, array_keys($orden));
            $this->assertSame([], self::errores(self::bien(['buttons' => $botones])), implode(',', $orden));
        }
    }

    public function test_url_y_llamada_solo_si_el_servidor_las_admite_y_el_numero_es_siempre_el_del_gimnasio(): void
    {
        $url = ['type' => 'URL', 'text' => 'Ver planes', 'url' => 'https://'.self::HOST.'/planes'];
        $llamar = ['type' => 'PHONE_NUMBER', 'text' => 'Llamar'];
        // Por defecto (lista vacía y bandera apagada): ninguno de los dos.
        $this->assertContains(['key' => 'content.buttons.0.type', 'code' => 'button_type_disabled'], self::errores(self::bien(['buttons' => [$url]])));
        $this->assertContains(['key' => 'content.buttons.0.type', 'code' => 'button_type_disabled'], self::errores(self::bien(['buttons' => [$llamar]])));
        $this->assertFalse(T::metaCatalog()['urlButtons']);
        $this->assertFalse(T::metaCatalog()['phoneButton']);
        $this->assertNull(T::metaCatalog()['phoneNumber']);

        $this->conBotonesEspeciales();
        $r = T::content('whatsapp', 'commercial', self::bien(['buttons' => [$url, $llamar]]));
        $this->assertSame([], $r['errors']);
        $this->assertSame([
            ['text' => 'Ver planes', 'type' => 'URL', 'url' => 'https://ironbodyneiva.cloud/planes'],
            ['phone' => '573143455483', 'text' => 'Llamar', 'type' => 'PHONE_NUMBER'],
        ], json_decode((string) $r['json'], true)['buttons'], 'el número lo pone el servidor, en dígitos con el prefijo de país');
        // Escribir el MISMO número (con otro formato) es lo mismo; los bytes no cambian.
        $this->assertSame($r['hash'], T::content('whatsapp', 'commercial', self::bien(['buttons' => [$url, $llamar + ['phone' => '+57 (314) 345-5483']]]))['hash']);
        // El host se compara en minúsculas.
        $this->assertSame([], self::errores(self::bien(['buttons' => [['url' => 'https://IronBodyNeiva.Cloud/planes'] + $url]])));
        $this->assertSame(['ironbodyneiva.cloud'], T::metaCatalog()['urlHosts']);
        $this->assertSame('573143455483', T::metaCatalog()['phoneNumber']);

        // Con la bandera encendida pero sin un número válido en la configuración: no se inventa uno.
        config(['legal.privacy_phone' => 'sin número']);
        $this->assertContains(['key' => 'content.buttons.0.type', 'code' => 'phone_not_configured'], self::errores(self::bien(['buttons' => [$llamar]])));
        $this->assertFalse(T::metaCatalog()['phoneButton']);
    }

    public function test_la_lista_de_hosts_de_la_configuracion_descarta_lo_que_no_es_un_host(): void
    {
        $leer = function (?string $valor): array {
            $nombre = 'ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS';
            $antes = getenv($nombre);
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            if ($valor !== null) {
                $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }
            try {
                return (require base_path('config/ultron_studio.php'))['meta_templates'];
            } finally {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
                if ($antes !== false) {
                    putenv("{$nombre}={$antes}");
                }
            }
        };
        $this->assertSame([], $leer(null)['url_hosts']);
        $this->assertSame(['ironbodyneiva.cloud'], $leer(' IronBodyNeiva.cloud ')['url_hosts']);
        $this->assertSame(['ironbodyneiva.cloud', 'www.ironbodyneiva.cloud'], $leer('ironbodyneiva.cloud,https://x.com,www.ironbodyneiva.cloud,*.evil.com,ironbodyneiva.cloud,localhost,a b.com')['url_hosts']);
        // Las tres banderas nacen apagadas, el doble por defecto y la versión propia del módulo.
        $todo = $leer(null);
        $this->assertFalse($todo['enabled']);
        $this->assertFalse($todo['submit_enabled']);
        $this->assertFalse($todo['sync_enabled']);
        $this->assertFalse($todo['phone_button']);
        $this->assertSame('fake', $todo['driver']);
        $this->assertSame('v25.0', $todo['graph_version']);
    }

    // ── La petición a Meta, congelada ──────────────────────────────────────

    public function test_meta_payload_congelado_por_caso(): void
    {
        $plan = T::content('whatsapp', 'commercial', self::BIEN)['canonical'];
        $this->assertSame(
            '{"name":"renovacion_aviso_v2","language":"es_CO","category":"MARKETING","parameter_format":"POSITIONAL","components":['
            .'{"type":"HEADER","format":"TEXT","text":"Tu plan está por vencer"},'
            .'{"type":"BODY","text":"Hola {{1}}, tu {{2}} vence el {{3}}. Si quieres seguir entrenando con nosotros, respóndenos y te ayudamos.","example":{"body_text":[["Laura","Plan Trimestral","15 de diciembre"]]}},'
            .'{"type":"FOOTER","text":"Escribe BAJA para no recibir más mensajes"},'
            .'{"type":"BUTTONS","buttons":[{"type":"QUICK_REPLY","text":"Quiero renovar"},{"type":"QUICK_REPLY","text":"Darme de baja"}]}]}',
            json_encode(T::metaPayload($plan), CanonicalJson::FLAGS),
            'la marca de baja (role) no viaja a Meta; los ejemplos van en el orden de las variables',
        );

        // Solo cuerpo y sin variables: ni `example` ni más componentes.
        $soloCuerpo = T::content('whatsapp', 'service', self::GOLDEN['sin variables'][1])['canonical'];
        $this->assertSame(
            '{"name":"clase_confirmada","language":"es_CO","category":"UTILITY","parameter_format":"POSITIONAL","components":[{"type":"BODY","text":"Tu clase de mañana sigue en pie. Responde BAJA para no recibir más mensajes."}]}',
            json_encode(T::metaPayload($soloCuerpo), CanonicalJson::FLAGS),
        );

        // URL y llamada (Q1 y Q2).
        $this->conBotonesEspeciales();
        $especial = T::content('whatsapp', 'service', self::bien([
            'category' => 'utility', 'buttons' => [['type' => 'URL', 'text' => 'Ver horario', 'url' => 'https://ironbodyneiva.cloud/horario?x=1'], ['type' => 'PHONE_NUMBER', 'text' => 'Llamar']],
        ]))['canonical'];
        $this->assertSame(
            '{"type":"BUTTONS","buttons":[{"type":"URL","text":"Ver horario","url":"https://ironbodyneiva.cloud/horario?x=1"},{"type":"PHONE_NUMBER","text":"Llamar","phone_number":"573143455483"}]}',
            json_encode(T::metaPayload($especial)['components'][3], CanonicalJson::FLAGS),
        );
        $this->assertSame('UTILITY', T::metaPayload($especial)['category']);

        // Con variables y sin ejemplos no se arma: falla cerrado (el servicio dice antes 422 meta_examples_required).
        $sinEjemplos = self::GOLDEN['W9World (comercial)'][1];
        $this->assertTrue(T::missingMetaExamples($sinEjemplos));
        $this->expectException(InvalidArgumentException::class);
        T::metaPayload($sinEjemplos);
    }

    public function test_meta_payload_es_determinista_y_su_huella_es_la_del_json_canonico(): void
    {
        $c = T::content('whatsapp', 'commercial', self::BIEN)['canonical'];
        $a = T::metaPayload($c);
        $b = T::metaPayload(json_decode((string) json_encode($c), true));
        $this->assertSame($a, $b);
        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CanonicalJson::hash($a));
    }

    // ── La huella de los componentes ───────────────────────────────────────

    public function test_la_huella_de_componentes_es_la_misma_desde_el_studio_y_desde_meta(): void
    {
        $this->conBotonesEspeciales();
        $studio = T::content('whatsapp', 'commercial', self::bien(['buttons' => [
            ['type' => 'URL', 'text' => 'Ver planes', 'url' => 'https://ironbodyneiva.cloud/planes'],
            ['type' => 'PHONE_NUMBER', 'text' => 'Llamar'],
            ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out'],
        ]]))['canonical'];
        // Lo que devuelve Meta: otras mayúsculas, otro orden de claves, el teléfono con formato y los ejemplos dentro.
        $meta = [
            ['text' => 'Tu plan está por vencer', 'format' => 'text', 'type' => 'header'],
            ['type' => 'BODY', 'text' => $studio['bodyPreview'], 'example' => ['body_text' => [['Laura', 'Plan Trimestral', '15 de diciembre']]]],
            ['type' => 'Footer', 'text' => 'Escribe BAJA para no recibir más mensajes'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['url' => 'https://ironbodyneiva.cloud/planes', 'text' => 'Ver planes', 'type' => 'url'],
                ['type' => 'PHONE_NUMBER', 'phone_number' => '+57 314 3455483', 'text' => 'Llamar'],
                ['type' => 'quick_reply', 'text' => 'Darme de baja'],
            ]],
        ];
        $this->assertSame(T::componentsFingerprint($studio), T::metaComponentsFingerprint($meta));
        $this->assertSame($studio['bodyPreview'], T::metaBodyText($meta));

        // Cualquier diferencia que vea el socio la cambia.
        $cambios = [
            'otro texto de botón' => fn (array $m): array => array_replace_recursive($m, [3 => ['buttons' => [2 => ['text' => 'Dame de baja']]]]),
            'otra URL' => fn (array $m): array => array_replace_recursive($m, [3 => ['buttons' => [0 => ['url' => 'https://ironbodyneiva.cloud/planes/']]]]),
            'otro teléfono' => fn (array $m): array => array_replace_recursive($m, [3 => ['buttons' => [1 => ['phone_number' => '573000000000']]]]),
            'otro orden de botones' => fn (array $m): array => array_replace($m, [3 => ['type' => 'BUTTONS', 'buttons' => array_reverse($m[3]['buttons'])]]),
            'sin pie' => fn (array $m): array => array_values(array_filter($m, static fn (array $c): bool => strtoupper($c['type']) !== 'FOOTER')),
            'otro encabezado' => fn (array $m): array => array_replace_recursive($m, [0 => ['text' => 'Tu plan vence']]),
            'encabezado de imagen' => fn (array $m): array => array_replace($m, [0 => ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['x']]]]),
            'un componente que no conocemos' => fn (array $m): array => [...$m, ['type' => 'CAROUSEL', 'cards' => []]],
            'dos pies' => fn (array $m): array => [...$m, ['type' => 'FOOTER', 'text' => 'Escribe BAJA para no recibir más mensajes']],
        ];
        foreach ($cambios as $nombre => $cambio) {
            $this->assertNotSame(T::componentsFingerprint($studio), T::metaComponentsFingerprint($cambio($meta)), $nombre);
        }
        // Los ejemplos y el cuerpo no entran en la huella de componentes.
        $sinEjemplos = $meta;
        unset($sinEjemplos[1]['example']);
        $sinEjemplos[1]['text'] = 'Otro texto';
        $this->assertSame(T::componentsFingerprint($studio), T::metaComponentsFingerprint($sinEjemplos));
        // Dos cuerpos: no hay «el» cuerpo.
        $this->assertNull(T::metaBodyText([...$meta, ['type' => 'BODY', 'text' => 'x']]));
    }

    public function test_una_plantilla_de_solo_cuerpo_tiene_siempre_la_misma_huella_de_componentes(): void
    {
        $fija = T::componentsFingerprint([]);
        foreach (self::GOLDEN as $nombre => [, $contenido]) {
            $this->assertSame($fija, T::componentsFingerprint($contenido), $nombre);
        }
        $this->assertSame($fija, T::metaComponentsFingerprint([['type' => 'BODY', 'text' => 'Hola']]));
        $this->assertSame(CanonicalJson::hash(['b' => [], 'f' => null, 'h' => null]), $fija);
        $this->assertNotSame($fija, T::componentsFingerprint(T::content('whatsapp', 'commercial', self::BIEN)['canonical']));
    }

    // ── El peor caso de tamaño (R11) ───────────────────────────────────────

    public function test_el_peor_caso_cabe_en_los_8_kb(): void
    {
        $this->conBotonesEspeciales();
        $marcadores = T::MARKERS;   // los 9, todos admitidos en comercial
        $base = 'Hola';
        foreach (array_keys($marcadores) as $i) {
            $base .= ' {{'.($i + 1).'}}';
        }
        $base .= ' ';
        // 1 024 caracteres; todo lo que no es marcador, de 4 bytes; no termina con un marcador.
        $cuerpo = $base.str_repeat('😀', T::BODY_MAX - mb_strlen($base));
        $this->assertSame(T::BODY_MAX, mb_strlen($cuerpo));
        $ejemplos = [];
        foreach ($marcadores as $i => $m) {
            $ejemplos[$m] = str_repeat(mb_chr(0x1F600 + $i), T::EXAMPLE_MAX);
        }
        $url = static fn (string $c): string => 'https://'.self::HOST.'/'.str_repeat($c, T::URL_MAX - strlen('https://'.self::HOST.'/'));
        $peor = [
            'metaName' => str_repeat('a', 64),
            'language' => 'es_CO',
            'category' => 'marketing',
            'header' => ['format' => 'TEXT', 'text' => str_repeat('😎', T::HEADER_MAX)],
            'bodyPreview' => $cuerpo,
            'variables' => $marcadores,
            'examples' => $ejemplos,
            'footer' => ['text' => str_repeat('😴', T::FOOTER_MAX)],
            'buttons' => [
                ['type' => 'URL', 'text' => str_repeat('😁', T::BUTTON_TEXT_MAX), 'url' => $url('a')],
                ['type' => 'URL', 'text' => str_repeat('😂', T::BUTTON_TEXT_MAX), 'url' => $url('b')],
                ['type' => 'PHONE_NUMBER', 'text' => str_repeat('😃', T::BUTTON_TEXT_MAX)],
            ],
            'optOutLine' => true,
        ];
        $r = T::content('whatsapp', 'commercial', $peor);
        $this->assertSame([], $r['errors'], json_encode($r['errors']));
        $bytes = strlen((string) $r['json']);
        fwrite(STDERR, "\n[R11] peor caso del contenido canónico: {$bytes} bytes de ".T::MAX_CONTENT_BYTES."\n");
        $this->assertLessThanOrEqual(T::MAX_CONTENT_BYTES, $bytes);
        // Y la petición a Meta del peor caso se arma.
        $this->assertCount(4, T::metaPayload($r['canonical'])['components']);
    }

    // ── La baja y la vista previa ──────────────────────────────────────────

    public function test_cada_etiqueta_de_baja_es_una_baja_segura_para_el_normalizador_de_w9(): void
    {
        $this->assertCount(3, T::OPT_OUT_LABELS);
        foreach (T::OPT_OUT_LABELS as $etiqueta) {
            $this->assertContains(WhatsappSignals::normalize($etiqueta), WhatsappSignals::OPT_OUT_PHRASES, $etiqueta);
            $this->assertTrue(WhatsappSignals::isOptOutText($etiqueta), $etiqueta);
            $this->assertLessThanOrEqual(T::BUTTON_TEXT_MAX, mb_strlen($etiqueta));
            $this->assertSame([], self::errores(self::bien(['buttons' => [['type' => 'QUICK_REPLY', 'text' => $etiqueta, 'role' => 'opt_out']]])), $etiqueta);
        }
    }

    public function test_los_ejemplos_ficticios_del_catalogo_pasan_sus_propias_reglas(): void
    {
        $this->assertSame(T::MARKERS, array_keys(T::META_EXAMPLES), 'un ejemplo por marcador, en el mismo orden');
        $r = T::content('whatsapp', 'commercial', self::bien([
            'bodyPreview' => 'Hola '.implode(' ', array_map(static fn (int $n): string => '{{'.$n.'}}', range(1, count(T::MARKERS)))).' fin.',
            'variables' => T::MARKERS,
            'examples' => T::META_EXAMPLES,
        ]));
        $this->assertSame([], $r['errors'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
    }

    public function test_la_vista_previa_estructurada_y_sus_avisos(): void
    {
        $c = T::content('whatsapp', 'commercial', self::BIEN)['canonical'];
        $this->assertSame([
            'header' => 'Tu plan está por vencer',
            'body' => self::BIEN['bodyPreview'],
            'footer' => 'Escribe BAJA para no recibir más mensajes',
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'], ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out']],
            'variables' => ['first_name', 'plan_name', 'membership_end_date_text'],
            'examples' => ['first_name' => 'Laura', 'plan_name' => 'Plan Trimestral', 'membership_end_date_text' => '15 de diciembre'],
            'metaWarnings' => [],
        ], T::waPreview($c));
        // La cadena de siempre no cambia.
        $this->assertSame('Hola [first_name], tu [plan_name] vence el [membership_end_date_text]. Si quieres seguir entrenando con nosotros, respóndenos y te ayudamos.', T::preview('whatsapp', $c));

        // Demasiadas variables para su longitud; botones mezclados (no se ven en escritorio); sin ejemplos.
        $this->conBotonesEspeciales();
        $denso = self::bien(['bodyPreview' => 'Hola {{1}} {{2}} {{3}} ya.', 'examples' => [],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Sí'], ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://'.self::HOST.'/x']]]);
        $this->assertSame([], self::errores($denso), 'los avisos no bloquean');
        $this->assertSame(['meta_variable_density', 'meta_buttons_hidden_on_desktop', 'meta_examples_missing'], T::waPreview(T::content('whatsapp', 'commercial', $denso)['canonical'])['metaWarnings']);
        $this->assertSame([], T::metaWarnings(self::GOLDEN['sin variables'][1]));
    }

    // ── Por la API: crear con el contenido ampliado y el catálogo ──────────

    public function test_por_la_api_se_crea_con_el_contenido_ampliado_y_el_catalogo_lo_describe(): void
    {
        [$ana] = $this->bootstrapPair();
        $d = $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'renovacion-aviso', 'channel' => 'whatsapp', 'purpose' => 'commercial',
            'content' => self::BIEN])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $d['status']);
        $this->assertSame(self::BIEN['header'], $d['content']['header']);
        $this->assertSame('Tu plan está por vencer', $d['waPreview']['header']);
        $this->assertSame(T::content('whatsapp', 'commercial', self::BIEN)['hash'], $d['contentHash']);
        $this->assertIsString($d['preview']);

        // 422 con TODAS las claves a la vez, en el formato de siempre.
        $malo = self::bien(['header' => ['format' => 'TEXT', 'text' => '*Hola*'], 'footer' => ['text' => 'Hola {{1}}'], 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Basta', 'role' => 'opt_out']]]);
        $r = $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'renovacion-mala', 'channel' => 'whatsapp', 'purpose' => 'commercial', 'content' => $malo])
            ->assertStatus(422)->assertJsonPath('code', 'template_invalid');
        $claves = array_column($r->json('findings'), 'key');
        foreach (['content.header.text', 'content.footer.text', 'content.buttons.0.text'] as $clave) {
            $this->assertContains($clave, $claves);
        }

        $catalogo = $this->s9('GET', 'strategies/catalog', $ana)->assertOk()->json('data.templates.meta');
        $this->assertSame(T::OPT_OUT_LABELS, $catalogo['optOutLabels']);
        $this->assertSame(T::META_EXAMPLES, $catalogo['examples']);
        $this->assertSame(['headerMax' => 60, 'footerMax' => 60, 'buttonTextMax' => 25, 'buttonsMax' => 3], array_intersect_key($catalogo, array_flip(['headerMax', 'footerMax', 'buttonTextMax', 'buttonsMax'])));
        $this->assertFalse($catalogo['urlButtons']);
        $this->assertFalse($catalogo['phoneButton']);
        // La lista de siempre sigue ahí.
        $this->assertSame(T::MARKERS, $this->s9('GET', 'strategies/catalog', $ana)->json('data.templates.markers'));
    }
}
