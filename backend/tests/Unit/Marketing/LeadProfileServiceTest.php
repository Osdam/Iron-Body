<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\LeadProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LA FICHA PERMANENTE DEL LEAD: aprende de lo que la persona dice y de lo
 * que cambió en la memoria de conversación; con significado, sin duplicar,
 * sin pisar lo que una persona escribió a mano, y sin tumbar nunca un turno.
 */
class LeadProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00', 'UTC'));
        $this->plan = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function absorb(string $texto, string $intent = SalesIntents::GENERAL_INFO, array $antes = [], array $despues = []): array
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto, 'meta_message_id' => uniqid('w.'), 'status' => 'received']);
        (new LeadProfileService)->absorb(
            $this->conversation->fresh(), $m, $intent,
            CM::fromArray(array_merge(CM::blank(), $antes)),
            CM::fromArray(array_merge(CM::blank(), $despues)),
        );

        return (new LeadProfileService)->profileOf($this->lead->fresh());
    }

    /**
     * La ficha y la columna del objetivo en blanco DE VERDAD. `absorb` guarda
     * con otra instancia, así que `$this->lead` sigue creyendo que están en
     * null y, a partir del segundo, un `forceFill([... => null])->save()` no
     * tiene nada que guardar: el caso siguiente heredaba lo aprendido en el
     * anterior y un `assertSame` podía pasar por lo que dejó otro.
     */
    private function sinFicha(): void
    {
        $this->lead->refresh()->forceFill(['metadata' => null, 'objective' => null])->save();
    }

    public function test_una_ficha_vacia_tiene_siempre_la_misma_forma(): void
    {
        $vacia = (new LeadProfileService)->profileOf(null);
        $this->assertSame(['objective' => null, 'experience_level' => null, 'availability' => null, 'preferences' => [], 'episodes' => [], 'updated_at' => null], $vacia);
        $this->assertSame($vacia, (new LeadProfileService)->profileOf($this->lead), 'un lead sin metadata tiene ficha vacía, no error');
    }

    public function test_el_objetivo_se_aprende_de_lo_que_escribe_y_se_escribe_en_el_lead(): void
    {
        $f = $this->absorb('quiero bajar de peso', SalesIntents::GOAL_FAT_LOSS);
        $this->assertSame('bajar grasa', $f['objective']);
        $this->assertSame('bajar grasa', $this->lead->fresh()->objective, 'marketing_leads.objective sigue en null');
        $this->assertNotNull($f['updated_at']);
    }

    public function test_el_objetivo_se_aprende_del_texto_cuando_la_intencion_no_lo_dice(): void
    {
        $f = $this->absorb('me interesa la recomposición corporal', SalesIntents::GENERAL_INFO);
        $this->assertSame('recomposición corporal', $f['objective']);
    }

    public function test_un_objetivo_escrito_a_mano_no_se_pisa(): void
    {
        $this->lead->forceFill(['objective' => 'preparación para maratón'])->save();
        $f = $this->absorb('quiero ganar masa muscular', SalesIntents::GOAL_MUSCLE_GAIN);
        $this->assertSame('preparación para maratón', $this->lead->fresh()->objective, 'lo que escribió una persona en el CRM manda');
        // La ficha, en cambio, sí anota lo último que la persona dijo… pero la lectura prefiere la columna.
        $this->assertSame('preparación para maratón', $f['objective']);
    }

    public function test_experiencia_disponibilidad_y_preferencias(): void
    {
        $f = $this->absorb('nunca he entrenado, solo puedo en las noches entre semana y quiero conocer las instalaciones primero');
        $this->assertSame('beginner', $f['experience_level']);
        $this->assertSame('noches, entre semana', $f['availability']);
        $this->assertContains('quiere conocer las instalaciones antes de decidir', $f['preferences']);

        $f = $this->absorb('la verdad ya he entrenado antes, vengo de otro gym');
        $this->assertSame('experienced', $f['experience_level'], 'lo último que dijo actualiza la experiencia');
        $this->assertSame('noches, entre semana', $f['availability'], 'lo que no se volvió a mencionar se conserva');

        $f = $this->absorb('soy avanzado, compito en powerlifting');
        $this->assertSame('advanced', $f['experience_level']);
    }

    public function test_los_episodios_tienen_significado_y_no_se_duplican(): void
    {
        $precio = ['prices_delivered' => [['plan_id' => $this->plan->id, 'price' => 180000, 'at' => 'x']]];
        $f = $this->absorb('cuánto vale?', SalesIntents::PRICING_QUESTION, [], $precio);
        $this->assertSame(['Pidió el precio del TOTAL ACCESS - ELITE.'], array_column($f['episodes'], 'meaning'));
        $this->assertSame('price_asked', $f['episodes'][0]['kind']);
        $this->assertSame($this->conversation->id, $f['episodes'][0]['conversation_id']);

        // El mismo hecho, otra vez: ni un episodio más.
        $f = $this->absorb('y cuánto vale?', SalesIntents::PRICING_QUESTION, [], $precio);
        $this->assertCount(1, $f['episodes']);
        // Y el delta que no cambió tampoco anota: el precio ya estaba antes.
        $f = $this->absorb('ok', SalesIntents::GENERAL_INFO, $precio, $precio);
        $this->assertCount(1, $f['episodes']);
    }

    public function test_rechazo_objecion_visita_compromiso_y_pendiente(): void
    {
        $f = $this->absorb('no, ese no', SalesIntents::NOT_INTERESTED, [], ['plans_rejected' => [$this->plan->id]]);
        $this->assertContains('Rechazó el TOTAL ACCESS - ELITE.', array_column($f['episodes'], 'meaning'));

        $f = $this->absorb('está caro', SalesIntents::PRICE_OBJECTION, [], ['objections_seen' => [['type' => SalesIntents::PRICE_OBJECTION, 'at' => 'x']]]);
        $this->assertContains('Manifestó una objeción: el precio.', array_column($f['episodes'], 'meaning'));

        $f = $this->absorb('quiero ir el jueves a las 10', SalesIntents::GENERAL_INFO, [], ['courtesy_request' => ['status' => 'requested', 'date' => '2026-09-24', 'time' => '10:00']]);
        $this->assertContains('Solicitó una visita de cortesía para el jueves 24 de septiembre a las 10:00; el equipo la confirma.', array_column($f['episodes'], 'meaning'));

        $f = $this->absorb('mejor cancela la visita', SalesIntents::GENERAL_INFO, ['courtesy_request' => ['status' => 'requested']], ['courtesy_request' => ['status' => 'cancelled']]);
        $this->assertContains('Canceló la visita de cortesía.', array_column($f['episodes'], 'meaning'));

        $f = $this->absorb('me quedo con ese', SalesIntents::HIGH_INTENT_CLOSE, [], ['commercial_commitment' => ['plan_id' => $this->plan->id, 'at' => 'x']]);
        $significados = array_column($f['episodes'], 'meaning');
        $this->assertContains('Se decidió por el TOTAL ACCESS - ELITE.', $significados);
        $this->assertContains('Mostró intención de inscribirse o pagar.', $significados);

        $f = $this->absorb('lo voy a pensar y después te digo', SalesIntents::DELAY_OBJECTION);
        $this->assertContains('Quedó pendiente de decidir; dijo que lo pensaría.', array_column($f['episodes'], 'meaning'));
    }

    public function test_la_ficha_guarda_como_mucho_doce_episodios_los_ultimos(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $p = Plan::create(['name' => "Plan {$i}", 'price' => 1000 * $i, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
            $f = $this->absorb("y el plan {$i}?", SalesIntents::PRICING_QUESTION, [], ['prices_delivered' => [['plan_id' => $p->id, 'price' => 1000 * $i, 'at' => 'x']]]);
        }
        $this->assertCount(LeadProfileService::MAX_EPISODIOS, $f['episodes']);
        $this->assertSame('Pidió el precio del Plan 15.', end($f['episodes'])['meaning']);
        $this->assertSame('Pidió el precio del Plan 4.', $f['episodes'][0]['meaning']);
    }

    public function test_el_texto_del_primer_interes_solo_se_anota_la_primera_vez(): void
    {
        $f = $this->absorb('quiero información', SalesIntents::GENERAL_INFO, [], []);
        $this->assertContains('Pidió información del gimnasio por primera vez.', array_column($f['episodes'], 'meaning'));
        $f = $this->absorb('más información', SalesIntents::GENERAL_INFO, ['facts_delivered' => [['key' => 'x', 'at' => 'y']]], []);
        $this->assertCount(1, $f['episodes']);
    }

    /**
     * La disponibilidad es la que la persona dice de SÍ MISMA. Casi toda
     * conversación de WhatsApp abre con «buenas tardes», y eso escribía «tardes»
     * en la ficha permanente y sacaba los horarios de lo que se pregunta.
     */
    public function test_la_disponibilidad_no_sale_de_un_saludo_ni_de_una_pregunta(): void
    {
        foreach (['buenas noches', 'Buenas tardes, quiero información', 'tienen clases en la tarde?', 'a qué hora abren los sábados?', 'buenos días, cuánto vale el mensual?'] as $texto) {
            $f = $this->absorb($texto);
            $this->assertNull($f['availability'], "«{$texto}» dejó disponibilidad en la ficha");
        }

        $this->assertSame('noches', $this->absorb('trabajo en las mañanas, puedo en las noches')['availability'], 'donde trabaja no es cuando puede');
        $this->assertSame('noches', $this->absorb('no puedo en las mañanas, solo en las noches')['availability']);
        $this->assertSame('tardes, de lunes a viernes', $this->absorb('me quedan mejor las tardes de lunes a viernes')['availability']);
    }

    public function test_la_experiencia_respeta_la_negacion_y_al_principiante_dicho_sin_rodeos(): void
    {
        $this->assertSame('beginner', $this->absorb('nunca he entrenado, quiero un estilo de vida saludable')['experience_level']);
        $this->assertSame('experienced', $this->absorb('no soy principiante, ya he entrenado')['experience_level']);
        $this->assertSame('experienced', $this->absorb('soy nuevo en este gym pero hace 5 años entreno')['experience_level'], '«soy nuevo» es nuevo en el gimnasio');

        $this->lead->forceFill(['metadata' => null])->save();
        $f = $this->absorb('no soy avanzado ni nada, qué los diferencia de la competencia?');
        $this->assertNotSame('advanced', $f['experience_level'], 'ni un «no soy avanzado» ni «la competencia» hacen un atleta');
    }

    public function test_una_preferencia_negada_no_se_anota_y_si_se_niega_despues_se_retira(): void
    {
        $f = $this->absorb('no me interesan las clases grupales');
        $this->assertNotContains('le interesan las clases grupales', $f['preferences']);

        $f = $this->absorb('me interesan las clases grupales');
        $this->assertContains('le interesan las clases grupales', $f['preferences']);

        $f = $this->absorb('pensándolo bien, no me interesan las clases grupales');
        $this->assertNotContains('le interesan las clases grupales', $f['preferences'], 'la ficha siguió diciendo lo contrario de lo que la persona dijo');
    }

    public function test_el_objetivo_del_texto_necesita_que_la_persona_hable_de_lo_que_quiere(): void
    {
        foreach (['tienen máquinas de resistencia?', 'a qué volumen ponen la música?', 'hay que definir el horario?'] as $texto) {
            $this->assertNull($this->absorb($texto)['objective'], "«{$texto}» inventó un objetivo");
        }
        $this->assertSame('ganar masa muscular', $this->absorb('no quiero bajar de peso, quiero ganar masa muscular')['objective']);
    }

    /**
     * Quien pasa de «bajar grasa» a «ganar masa» no se queda en «bajar grasa»
     * para siempre porque la columna ya no estaba vacía; y lo que una persona
     * del equipo escribe a mano sigue mandando.
     */
    public function test_el_objetivo_sigue_a_la_persona_salvo_que_lo_haya_escrito_el_equipo(): void
    {
        $this->absorb('quiero bajar de peso', SalesIntents::GOAL_FAT_LOSS);
        $this->assertSame('bajar grasa', $this->lead->fresh()->objective);

        $f = $this->absorb('mejor quiero ganar masa', SalesIntents::GOAL_MUSCLE_GAIN);
        $this->assertSame('ganar masa muscular', $this->lead->fresh()->objective, 'la columna escrita por ULTRON no siguió a la persona');
        $this->assertSame('ganar masa muscular', $f['objective']);

        // Alguien del equipo lo cambia a mano: desde ahí manda la columna.
        $this->lead->forceFill(['objective' => 'preparación para maratón'])->save();
        $f = $this->absorb('quiero bajar de peso', SalesIntents::GOAL_FAT_LOSS);
        $this->assertSame('preparación para maratón', $this->lead->fresh()->objective);
        $this->assertSame('preparación para maratón', $f['objective']);
        $this->assertSame('bajar grasa', $this->lead->fresh()->metadata[LeadProfileService::KEY]['objective'], 'lo aprendido se guarda aunque no mande');
    }

    public function test_la_ficha_no_pisa_otras_claves_de_metadata(): void
    {
        $this->lead->forceFill(['metadata' => ['agent_profile' => ['temperature' => 'hot'], 'payment_claims' => [7]]])->save();
        $this->absorb('quiero bajar de peso', SalesIntents::GOAL_FAT_LOSS);

        $meta = $this->lead->fresh()->metadata;
        $this->assertSame(['temperature' => 'hot'], $meta['agent_profile']);
        $this->assertSame([7], $meta['payment_claims']);
        $this->assertSame('bajar grasa', $meta[LeadProfileService::KEY]['objective']);
    }

    /** «Mañana» a secas es un día, y una franja atada a un día concreto es un plan de ese día, no su costumbre. */
    public function test_la_disponibilidad_no_sale_de_un_dia_concreto_ni_de_lo_que_la_ocupa(): void
    {
        foreach (['puedo ir mañana a las 5 pm', 'mañana puedo', 'quiero ir mañana miércoles a las 2 pm', 'puedo ir mañana en la tarde', 'me queda bien pasar mañana en la tarde a la visita', '¿Abren solo de lunes a viernes?', '¿Puedo ir los sábados o solo entre semana?', 'puedo pagar en la tarde?'] as $texto) {
            $this->lead->forceFill(['metadata' => null])->save();
            $this->assertNull($this->absorb($texto)['availability'], "«{$texto}» dejó disponibilidad en la ficha");
        }
        $this->assertSame('tardes', $this->absorb('trabajo en las mañanas y puedo en las tardes')['availability'], 'la franja del trabajo ganó a la de la disponibilidad');
        $this->assertSame('noches, fines de semana', $this->absorb('solo puedo en las noches y los fines de semana')['availability']);
    }

    public function test_la_experiencia_necesita_el_entrenamiento_como_objeto(): void
    {
        foreach (['hola, les escribí hace 2 meses por la cortesía', 'me mudé a Neiva hace 2 años', 'hace 3 meses me operaron, quiero empezar', 'quiero retomar mis estudios y empezar a entrenar', 'hace 3 años que quiero empezar pero nunca me decido'] as $texto) {
            $this->lead->forceFill(['metadata' => null])->save();
            $this->assertNull($this->absorb($texto)['experience_level'], "«{$texto}» se leyó como experiencia");
        }
        $this->lead->forceFill(['metadata' => null])->save();
        $this->assertSame('experienced', $this->absorb('quiero retomar el gym')['experience_level']);
        $this->assertSame('experienced', $this->absorb('no he entrenado desde hace un año')['experience_level']);
        $this->assertSame('experienced', $this->absorb('no soy del todo principiante, entrenaba antes')['experience_level'], '«no soy del todo principiante» se leyó como principiante');
    }

    public function test_la_negacion_que_va_detras_tambien_cuenta(): void
    {
        $f = $this->absorb('las clases grupales no me gustan, prefiero pesas');
        $this->assertNotContains('le interesan las clases grupales', $f['preferences']);

        $this->absorb('me interesan las clases grupales');
        $f = $this->absorb('la verdad las clases no me llaman la atención');
        $this->assertNotContains('le interesan las clases grupales', $f['preferences'], 'la preferencia negada después no se retiró');

        $this->assertSame('ganar masa muscular', $this->absorb('bajar de peso no es mi meta, quiero ganar masa')['objective']);
    }

    public function test_el_objetivo_de_otra_persona_no_es_el_suyo(): void
    {
        $this->assertNull($this->absorb('necesito información para mi mamá que quiere bajar de peso')['objective']);
        $this->assertNull($this->lead->fresh()->objective);
    }

    /** La inseguridad con el cuerpo y el miedo a empezar se atienden en el turno, pero no se guardan en la ficha permanente. */
    public function test_las_objeciones_sensibles_no_se_guardan(): void
    {
        $f = $this->absorb('me da pena mi cuerpo', SalesIntents::INSECURITY_BODY);
        $f = $this->absorb('me da miedo empezar', SalesIntents::BEGINNER_FEAR);
        $this->assertSame([], array_values(array_filter(array_column($f['episodes'], 'kind'), fn ($k) => $k === 'objection')));
    }

    public function test_mover_la_visita_deja_su_episodio(): void
    {
        $antes = ['courtesy_request' => ['status' => 'requested', 'date' => '2026-09-24', 'time' => '10:00']];
        $f = $this->absorb('mejor el viernes a las 6', SalesIntents::GENERAL_INFO, $antes, ['courtesy_request' => ['status' => 'requested', 'date' => '2026-09-25', 'time' => '18:00']]);
        $this->assertContains('Cambió la visita de cortesía para el viernes 25 de septiembre a las 18:00; el equipo la confirma.', array_column($f['episodes'], 'meaning'));
    }

    /** Lo que escribió el agente clásico (un código) es de máquina: se lee con su nombre y sigue a la persona. */
    public function test_el_codigo_del_agente_clasico_no_bloquea_el_objetivo(): void
    {
        $this->lead->forceFill(['objective' => 'fat_loss'])->save();
        $this->assertSame('bajar grasa', (new LeadProfileService)->profileOf($this->lead->fresh())['objective']);

        $f = $this->absorb('ahora quiero ganar masa', SalesIntents::GOAL_MUSCLE_GAIN);
        $this->assertSame('ganar masa muscular', $f['objective']);
        $this->assertSame('ganar masa muscular', $this->lead->fresh()->objective);
    }

    /**
     * La etiqueta del modelo no enseña nada: la ficha es permanente y se
     * aprende de lo que la persona escribió. Un «hola» etiquetado como
     * objetivo, un «no me interesa por ahora» etiquetado como cierre o un
     * «ok» etiquetado como objeción no dejan rastro; la objeción escrita con
     * otra etiqueta, sí.
     */
    public function test_la_etiqueta_del_modelo_no_ensena_nada(): void
    {
        $this->assertNull($this->absorb('hola', SalesIntents::GOAL_FAT_LOSS)['objective']);
        $this->assertSame([], $this->absorb('no me interesa por ahora', SalesIntents::HIGH_INTENT_CLOSE)['episodes'], 'un «no me interesa» dejó intención de pagar');
        $this->assertSame([], $this->absorb('ok', SalesIntents::PRICE_OBJECTION)['episodes']);

        $significados = array_column($this->absorb('uy, está muy caro para mí', SalesIntents::GENERAL_INFO)['episodes'], 'meaning');
        $this->assertContains('Manifestó una objeción: el precio.', $significados);
        $significados = array_column($this->absorb('no tengo tiempo entre semana', SalesIntents::GENERAL_INFO)['episodes'], 'meaning');
        $this->assertContains('Manifestó una objeción: el tiempo.', $significados);
    }

    /** Si el mensaje habla de otra persona, no se aprende nada de él: lo que diga puede ser de ella. */
    public function test_de_otra_persona_no_se_aprende_nada(): void
    {
        foreach (['es para mi hijo, es principiante y puede en las tardes', 'mi novia quiere bajar de peso, nunca ha entrenado', 'ella quiere entrenar en las noches, es principiante'] as $texto) {
            $this->lead->forceFill(['metadata' => null])->save();
            $f = $this->absorb($texto);
            $this->assertSame([null, null, null], [$f['objective'], $f['experience_level'], $f['availability']], "«{$texto}» describió a la persona con lo de otra");
        }
    }

    /** «Principiante no soy» no es un principiante, y «es la primera vez que les escribo» habla del chat, no del ejercicio. */
    public function test_lo_que_no_dice_de_su_entrenamiento_no_la_hace_principiante(): void
    {
        foreach (['principiante no soy, llevo rato en esto', 'es la primera vez que les escribo', 'primera vez que te escribo, qué planes hay?'] as $texto) {
            $this->lead->forceFill(['metadata' => null])->save();
            $this->assertNotSame('beginner', $this->absorb($texto)['experience_level'], "«{$texto}» se leyó como principiante");
        }
        $this->lead->forceFill(['metadata' => null])->save();
        $this->assertSame('beginner', $this->absorb('es la primera vez que voy a un gimnasio, soy principiante')['experience_level']);
    }

    /** La preferencia se retira con cualquier negación clara, no sólo con «no me interesa». */
    public function test_una_preferencia_se_retira_con_cualquier_negacion_clara(): void
    {
        foreach (['odio las clases grupales', 'nada de clases grupales, gracias', 'clases grupales? no', 'las clases grupales, paso'] as $texto) {
            $this->assertContains('le interesan las clases grupales', $this->absorb('me interesan las clases grupales')['preferences']);
            $this->assertNotContains('le interesan las clases grupales', $this->absorb($texto)['preferences'], "«{$texto}» no retiró la preferencia");
        }
    }

    /** Lo que se dice del gimnasio, del horario o del trabajo no es la disponibilidad de la persona. */
    public function test_la_disponibilidad_es_la_de_la_persona_y_no_la_del_gimnasio(): void
    {
        foreach (['a qué hora cierran en las noches?', 'las clases de las tardes son buenas', 'mi horario de trabajo es en las tardes', 'el entrenador de las mañanas me cae bien', 'en las noches se llena mucho la zona de pesas'] as $texto) {
            $this->lead->forceFill(['metadata' => null])->save();
            $this->assertNull($this->absorb($texto)['availability'], "«{$texto}» dejó disponibilidad en la ficha");
        }
        $this->lead->forceFill(['metadata' => null])->save();
        $this->assertSame('los sabados', $this->absorb('solo tengo libre los sábados')['availability']);
    }

    public function test_sin_lead_no_pasa_nada(): void
    {
        $huerfana = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
        $huerfana->setRelation('lead', null);
        $m = MarketingMessage::create(['conversation_id' => $huerfana->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola', 'meta_message_id' => 'w.x', 'status' => 'received']);
        (new LeadProfileService)->absorb($huerfana, $m, SalesIntents::GREETING, null, CM::empty());
        $this->assertNull($this->lead->fresh()->metadata);
    }

    /**
     * La negación que RIGE el verbo niega lo que venga detrás aunque haya
     * relleno: «no me interesan para nada las clases grupales» añadía justo la
     * preferencia que la persona rechazaba y no retiraba la que ya estaba. Lo
     * que le quita el mando —la corrección o el deseo afirmado otra vez— sigue
     * valiendo.
     */
    public function test_la_negacion_que_rige_el_verbo_niega_aunque_haya_relleno(): void
    {
        foreach (['no me interesan para nada las clases grupales', 'No me gustan mucho las clases grupales, prefiero entrenar solo', 'no me llaman la atención las clases grupales'] as $texto) {
            $this->assertContains('le interesan las clases grupales', $this->absorb('me interesan las clases grupales')['preferences']);
            $this->assertNotContains('le interesan las clases grupales', $this->absorb($texto)['preferences'], "«{$texto}» no retiró la preferencia");
        }
        $this->assertContains('busca acompañamiento de entrenador', $this->absorb('quiero entrenador personal')['preferences']);
        $this->assertNotContains('busca acompañamiento de entrenador', $this->absorb('no me hace falta entrenador personal')['preferences']);

        $this->assertSame('ganar masa muscular', $this->absorb('no me interesa mucho bajar de peso, quiero es ganar masa')['objective']);
        $this->assertSame('experienced', $this->absorb('tampoco es que sea principiante, ya he entrenado antes')['experience_level']);

        foreach (['no quiero bajar de peso, quiero ganar masa', 'no quiero bajar de peso sino ganar masa', 'no quiero bajar de peso quiero ganar masa'] as $texto) {
            $this->sinFicha();
            $this->assertSame('ganar masa muscular', $this->absorb($texto)['objective'], "«{$texto}» perdió lo que sí quiere");
        }
        $this->assertContains('le interesan las clases grupales', $this->absorb('no me gustan las pesas y me interesan las clases grupales')['preferences']);
    }

    /**
     * Avanzada es ELLA, no la rutina: «me da miedo que sea muy avanzado» o
     * «¿el entrenamiento es muy avanzado?» los escribe una principiante, y la
     * ficha la guardaba como avanzada.
     */
    public function test_lo_avanzado_tiene_que_decirlo_de_si_misma(): void
    {
        foreach (['nunca he entrenado, me da miedo que sea muy avanzado', 'soy principiante, ¿el entrenamiento es muy avanzado?', 'soy principiante, la rutina de ustedes es avanzada?'] as $texto) {
            $this->sinFicha();
            $this->assertSame('beginner', $this->absorb($texto)['experience_level'], "«{$texto}» se leyó como avanzado");
        }
        foreach (['me considero avanzada', 'llevo 5 años entrenando', 'soy competidora'] as $texto) {
            $this->sinFicha();
            $this->assertSame('advanced', $this->absorb($texto)['experience_level'], "«{$texto}» dejó de leerse como avanzado");
        }
    }

    /** Principiante y con experiencia en el mismo mensaje: no se adivina, y el nivel que ya estaba se queda. */
    public function test_principiante_y_con_experiencia_a_la_vez_no_ensena_nivel(): void
    {
        $this->assertNull($this->absorb('soy principiante pero llevo años entrenando en casa')['experience_level']);
        $this->assertSame('experienced', $this->absorb('la verdad ya he entrenado antes')['experience_level']);
        $this->assertSame('experienced', $this->absorb('ya he entrenado en casa, pero es mi primera vez en un gimnasio')['experience_level'], 'una contradicción pisó el nivel que ya estaba');
    }

    /** La primera vez POR ACÁ no es la primera vez entrenando: habla del chat, de la página o del barrio. */
    public function test_la_primera_vez_por_aca_no_la_hace_principiante(): void
    {
        foreach (['Buenas, es mi primera vez por acá', 'primera vez que entro a su página', 'soy nuevo en el barrio'] as $texto) {
            $this->sinFicha();
            $this->assertNull($this->absorb($texto)['experience_level'], "«{$texto}» se leyó como principiante");
        }
        foreach (['es mi primera vez en un gimnasio', 'soy nueva en esto'] as $texto) {
            $this->sinFicha();
            $this->assertSame('beginner', $this->absorb($texto)['experience_level'], "«{$texto}» dejó de leerse como principiante");
        }
    }

    /**
     * «¿Sería tu primera vez entrenando o ya tienes experiencia?» se contesta
     * «ya he entrenado, pero sería mi primera vez aquí»: eso es experiencia, y
     * la ficha la cambiaba a principiante.
     */
    public function test_la_primera_vez_en_este_gimnasio_no_pisa_la_experiencia(): void
    {
        foreach (['No, ya he entrenado antes, pero sería mi primera vez aquí', 'sí, sería mi primera vez en este gym, antes entrenaba en otro', 'ya tengo experiencia, sería mi primera vez en Iron Body', 'ya entrené antes, es mi primera vez con ustedes', 'es mi primera vez en este gimnasio, antes iba a otro'] as $texto) {
            $this->sinFicha();
            $this->assertNotSame('beginner', $this->absorb($texto)['experience_level'], "«{$texto}» se leyó como principiante");
            $this->absorb('la verdad ya he entrenado antes');
            $this->assertSame('experienced', $this->absorb($texto)['experience_level'], "«{$texto}» pisó la experiencia que ya estaba");
        }
    }

    /**
     * «Rebajar el precio» pide un descuento y «saber mi masa muscular» pide
     * una medida: ninguno es un objetivo, y el objetivo aprendido también se
     * escribe en la columna del lead.
     */
    public function test_rebajar_el_precio_o_medir_la_masa_muscular_no_es_un_objetivo(): void
    {
        foreach (['¿Hay forma de rebajar el precio para estudiantes?', 'busco que me puedan rebajar la mensualidad', '¿me pueden rebajar algo para pagar hoy mismo?', 'quisiera saber si me pueden rebajar el precio', '¿Hacen valoración para saber mi porcentaje de grasa y masa muscular?', 'quiero saber mi masa muscular'] as $texto) {
            $this->sinFicha();
            $this->assertNull($this->absorb($texto)['objective'], "«{$texto}» inventó un objetivo");
            $this->assertNull($this->lead->fresh()->objective, "«{$texto}» escribió un objetivo en el lead");
        }
        foreach (['quiero rebajar' => 'bajar grasa', 'necesito rebajar unos kilos' => 'bajar grasa', 'quiero aumentar mi masa muscular' => 'ganar masa muscular', 'quiero desarrollar masa muscular' => 'ganar masa muscular'] as $texto => $objetivo) {
            $this->sinFicha();
            $this->assertSame($objetivo, $this->absorb($texto)['objective'], "«{$texto}» dejó de leerse como objetivo");
        }
    }

    /**
     * Un episodio se queda meses en la ficha y vuelve al modelo: tiene que
     * decir lo que la persona dijo. «Me quedo con el gimnasio donde estoy» es
     * lo contrario de inscribirse; «luego te confirmo» no es «lo voy a
     * pensar»; y la cara, la estatura o tener presupuesto no son una queja del
     * precio.
     */
    public function test_los_episodios_dicen_lo_que_la_persona_dijo(): void
    {
        foreach (['Gracias, por ahora me quedo con el gimnasio donde estoy', 'me quedo con el que tengo, gracias', 'no, gracias, me quedo con el mío', 'gracias pero me quedo con el gym de mi barrio'] as $texto) {
            $this->sinFicha();
            $this->assertNotContains('Mostró intención de inscribirse o pagar.', array_column($this->absorb($texto)['episodes'], 'meaning'), "«{$texto}» dejó intención de pagar");
        }
        foreach (['listo ya pagué, luego te confirmo', 'el jueves voy, después te aviso la hora'] as $texto) {
            $this->sinFicha();
            $significados = array_column($this->absorb($texto)['episodes'], 'meaning');
            $this->assertNotContains('Quedó pendiente de decidir; dijo que lo pensaría.', $significados, "«{$texto}» inventó que lo pensaría");
            $this->assertContains('Dijo que respondería después.', $significados);
        }
        foreach (['tengo presupuesto para el plan anual, cuánto sale?', 'tienen tratamientos para la cara?', 'soy muy alto, las máquinas me sirven?'] as $texto) {
            $this->sinFicha();
            $this->assertNotContains('Manifestó una objeción: el precio.', array_column($this->absorb($texto)['episodes'], 'meaning'), "«{$texto}» dejó una objeción de precio");
        }
        $this->sinFicha();
        $this->assertNotContains('Quedó pendiente de decidir; dijo que lo pensaría.', array_column($this->absorb('no lo voy a pensar, quiero inscribirme ya')['episodes'], 'meaning'));

        // Lo que sí lo dice se sigue anotando.
        $this->sinFicha();
        $this->assertContains('Mostró intención de inscribirse o pagar.', array_column($this->absorb('me quedo con el mensual')['episodes'], 'meaning'));
        foreach (['el precio está muy alto', 'se sale de mi presupuesto', 'está muy cara la mensualidad'] as $texto) {
            $this->sinFicha();
            $this->assertContains('Manifestó una objeción: el precio.', array_column($this->absorb($texto)['episodes'], 'meaning'), "«{$texto}» dejó de leerse como objeción de precio");
        }
        $this->assertContains('Quedó pendiente de decidir; dijo que lo pensaría.', array_column($this->absorb('déjame pensarlo')['episodes'], 'meaning'));
    }

    /** La sobrina, la señora, el hermanito o «él» también son otra persona: su objetivo y su nivel no son los de quien escribe. */
    public function test_la_familia_la_pareja_y_el_tambien_son_otra_persona(): void
    {
        foreach (['es para mi sobrina que quiere bajar de peso', 'es para mi señora que quiere bajar de peso', 'es para mi hermanita que quiere bajar de peso', 'es para mi mami que quiere bajar de peso'] as $texto) {
            $this->sinFicha();
            $this->assertNull($this->absorb($texto)['objective'], "«{$texto}» le dio a la persona el objetivo de otra");
            $this->assertNull($this->lead->fresh()->objective);
        }
        foreach (['somos pareja, ella es principiante', 'mi señora quiere empezar, es principiante y puede en las noches', 'es para mi hermanito, es principiante', 'somos mi cuñado y yo, él es avanzado'] as $texto) {
            $this->sinFicha();
            $f = $this->absorb($texto);
            $this->assertSame([null, null], [$f['experience_level'], $f['availability']], "«{$texto}» describió a la persona con lo de otra");
        }
        // Los diminutivos van uno a uno: «mi cita» y «mi visita» son suyas.
        foreach (['mi cita es el jueves, soy principiante', 'quiero agendar mi visita, soy principiante'] as $texto) {
            $this->sinFicha();
            $this->assertSame('beginner', $this->absorb($texto)['experience_level'], "«{$texto}» se leyó como otra persona");
        }
    }

    /**
     * Manda el último objetivo que la persona afirmó, y el que retiró no
     * cuenta: «quiero bajar de peso, mejor dicho ganar masa» se guardaba como
     * «bajar grasa», también en la columna del lead.
     */
    public function test_manda_el_ultimo_objetivo_y_el_retirado_no_cuenta(): void
    {
        foreach (['quiero bajar de peso, bueno no, más bien quiero ganar masa', 'quiero bajar de peso, mejor dicho ganar masa', 'busco bajar de peso. Mentiras, quiero ganar masa', 'mi objetivo era bajar de peso pero ahora quiero ganar masa', 'no quiero bajar de peso sino ganar masa', 'busco bajar de peso mejor dicho ganar masa'] as $texto) {
            $this->sinFicha();
            $this->assertSame('ganar masa muscular', $this->absorb($texto)['objective'], "«{$texto}» se quedó con el objetivo que retiró");
            $this->assertSame('ganar masa muscular', $this->lead->fresh()->objective);
        }
        foreach (['busco bajar de peso, pensándolo bien no', 'quiero bajar de peso? no', 'busco bajar de peso. Mentiras', 'quiero bajar de peso, es broma'] as $texto) {
            $this->sinFicha();
            $this->assertNull($this->absorb($texto)['objective'], "«{$texto}» aprendió el objetivo que retiró");
            $this->assertNull($this->lead->fresh()->objective);
        }
    }

    /**
     * Lo que va tras «menos», «excepto» o «salvo» es justo lo que la persona
     * NO puede, y la ficha lo guardaba como su disponibilidad permanente.
     */
    public function test_la_disponibilidad_no_guarda_lo_que_la_persona_excluye(): void
    {
        foreach (['puedo cualquier día menos en las tardes', 'me queda bien cualquier día menos los lunes', 'puedo cualquier día menos los lunes', 'puedo todos los días menos el domingo'] as $texto) {
            $this->sinFicha();
            $this->assertNull($this->absorb($texto)['availability'], "«{$texto}» guardó como disponibilidad lo que excluyó");
        }
        $this->sinFicha();
        $this->assertNotSame('mananas', $this->absorb('en las mañanas jamás puedo, en las noches sí')['availability']);

        foreach ([
            'por las tardes me queda imposible, en la mañana sí puedo' => 'manana',
            'puedo todas las noches excepto los viernes' => 'noches',
            'puedo todas las noches menos los viernes y los sábados' => 'noches',
            'puedo por lo menos 3 días' => '3 dias',
            'puedo al menos en las noches' => 'noches',
        ] as $texto => $esperada) {
            $this->sinFicha();
            $this->assertSame($esperada, $this->absorb($texto)['availability'], "«{$texto}»");
        }
    }
}
