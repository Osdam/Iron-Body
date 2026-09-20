<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingKnowledgeItem;
use App\Models\MyClass;
use App\Models\Plan;
use App\Models\Trainer;
use App\Services\Marketing\Ultron\GymFactsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La única fuente de verdad del gimnasio, sanitizada y honesta con lo que no existe. */
class GymFactsProviderTest extends TestCase
{
    use RefreshDatabase;

    private GymFactsProvider $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new GymFactsProvider;
    }

    public function test_plans_come_only_from_the_sellable_catalogue_and_without_price(): void
    {
        $ok = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $interno = Plan::create(['name' => 'Comisión de personalizados', 'price' => 0, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode([])]);
        $noVendible = Plan::create(['name' => 'PLAN SEPTIEMBRE', 'price' => 50000, 'duration_days' => 30, 'active' => true, 'sellable' => false, 'benefits' => json_encode([])]);

        $rows = $this->g->comparePlans([$ok->id, $interno->id, $noVendible->id]);

        $this->assertSame([$ok->id], array_column($rows, 'id'));
        $this->assertStringNotContainsString('80000', json_encode($rows));
        $this->assertStringNotContainsString('price', json_encode($rows));
        $this->assertNull($this->g->planDetails($noVendible->id));
    }

    public function test_classes_are_active_deduplicated_and_readable(): void
    {
        MyClass::create(['name' => 'IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'description' => 'control corporal, core, movilidad', 'allow_online_booking' => true, 'requires_active_plan' => true, 'max_capacity' => 20]);
        MyClass::create(['name' => 'Copia de Copia de IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20]);
        MyClass::create(['name' => 'RUMBA', 'type' => 'grupal', 'day_of_week' => 'tuesday', 'start_time' => '19:00:00', 'end_time' => '20:00:00', 'status' => 'inactive', 'max_capacity' => 20]);

        $classes = $this->g->classes();

        $this->assertCount(1, $classes, 'la copia y la inactiva no son clases');
        $this->assertSame('IRON POWERFLOW', $classes[0]['name']);
        $this->assertSame('lunes', $classes[0]['day']);
        $this->assertSame('06:00', $classes[0]['start_time']);
        $this->assertStringNotContainsString('RUMBA', json_encode($classes));
    }

    public function test_class_availability_is_honest_about_its_source(): void
    {
        $con = MyClass::create(['name' => 'IRON STRENGTH', 'type' => 'grupal', 'day_of_week' => 'wednesday', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'status' => 'active', 'max_capacity' => 12]);
        $sin = MyClass::create(['name' => 'IRON PARTY', 'type' => 'grupal', 'day_of_week' => 'thursday', 'start_time' => '07:00:00', 'end_time' => '08:00:00', 'status' => 'active', 'max_capacity' => 0]);

        $this->assertSame(12, $this->g->classAvailability($con->id, '2026-09-23')['spots_left']);
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->classAvailability($sin->id, '2026-09-24')['spots_left']);
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->classAvailability(999, '2026-09-24')['spots_left']);
    }

    public function test_trainers_expose_count_and_specialties_and_nothing_personal(): void
    {
        Trainer::create(['full_name' => 'Carlos Pérez', 'document' => '1010', 'phone' => '3001112233', 'email' => 'carlos@x.co', 'birth_date' => '1990-01-01', 'main_specialty' => 'Musculación', 'specialties' => ['Fuerza'], 'status' => 'active']);
        Trainer::create(['full_name' => 'Ana Gómez', 'document' => '2020', 'phone' => '3004445566', 'email' => 'ana@x.co', 'main_specialty' => 'Funcional', 'status' => 'active']);
        Trainer::create(['full_name' => 'Ex Entrenador', 'document' => '3030', 'phone' => '3007778899', 'email' => 'ex@x.co', 'main_specialty' => 'Yoga', 'status' => 'inactive']);

        $t = $this->g->trainers();
        $json = json_encode($this->g->all());

        $this->assertSame(2, $t['active_count']);
        $this->assertEqualsCanonicalizing(['Musculación', 'Fuerza', 'Funcional'], $t['specialties']);
        foreach (['Carlos', 'Pérez', 'Ana', 'Gómez', '1010', '2020', '3001112233', 'carlos@x.co', '1990-01-01', 'Yoga'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, $pii);
        }
    }

    public function test_opening_hours_are_source_not_available_until_the_knowledge_base_has_them(): void
    {
        MarketingKnowledgeItem::create(['category' => 'location', 'key' => 'sede', 'title' => 'Sede principal', 'content' => 'Cl. 24 Sur #33-53, Neiva', 'is_active' => true, 'priority' => 1, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);

        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->openingHours());
        $this->assertSame(['Sede principal: Cl. 24 Sur #33-53, Neiva'], $this->g->businessInfo()['location']);

        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'horario', 'title' => 'Horario', 'content' => 'Lunes a viernes 5am a 10pm', 'is_active' => true, 'priority' => 1, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        // Los hechos se leen una vez por instancia (una petición = una instancia): otra petición, otra lectura.
        $this->assertSame(['Horario: Lunes a viernes 5am a 10pm'], app(GymFactsProvider::class)->openingHours());
    }

    /**
     * EL HORARIO DE APERTURA NO ES EL HORARIO DE CLASES.
     *
     * Son dos preguntas distintas con dos fuentes distintas, y confundirlas es
     * la forma barata de mentir: contestar «hasta las 8 de la noche» porque esa
     * es la última clase, cuando el gimnasio cierra a las diez —o al revés—.
     * `opening_hours` sale de la base de conocimiento (categoría `schedule`) y
     * `classes` de la tabla de clases; ninguna de las dos se deduce de la otra,
     * y esta prueba existe para que sigan sin deducirse.
     */
    public function test_opening_hours_and_class_schedule_are_different_facts(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.apertura', 'title' => 'Horario general',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'priority' => 1, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER,
        ]);
        MyClass::create([
            'name' => 'IRON STRENGTH', 'type' => 'grupal', 'day_of_week' => 'monday',
            'start_time' => '19:00:00', 'end_time' => '20:00:00', 'status' => 'active', 'max_capacity' => 20,
        ]);

        $g = app(GymFactsProvider::class);
        $horario = $g->openingHours();
        $clases = $g->classes();

        // 1. El horario general es el del gimnasio, no el de la última clase.
        $this->assertIsArray($horario);
        $this->assertStringContainsString('10:00 p. m.', implode(' ', $horario));
        $this->assertStringNotContainsString('IRON STRENGTH', implode(' ', $horario), 'una clase no es el horario de apertura');
        $this->assertStringNotContainsString('19:00', implode(' ', $horario));

        // 2. La clase es la clase: su hora no se toca ni se mezcla.
        $this->assertSame(['IRON STRENGTH'], array_column($clases, 'name'));
        $this->assertSame(['19:00'], array_column($clases, 'start_time'));
        $this->assertSame(['lunes'], array_column($clases, 'day'));

        // 3. Y viajan por claves distintas del contexto, que es lo que impide
        //    que el modelo responda una con la otra.
        $prompt = $g->forPrompt();
        $this->assertSame($horario, $prompt['opening_hours']);
        $this->assertSame($clases, $prompt['classes']);
        $this->assertStringNotContainsString('a. m.', json_encode($prompt['classes'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Y el reverso, que es el que de verdad muerde: sin ítem de horario, tener
     * clases NO da un horario de apertura. El gimnasio no «abre a las 6» porque
     * haya una clase a las 6.
     */
    public function test_having_classes_does_not_invent_opening_hours(): void
    {
        MyClass::create([
            'name' => 'IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday',
            'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20,
        ]);

        $g = app(GymFactsProvider::class);

        $this->assertNotSame([], $g->classes(), 'la clase existe');
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $g->openingHours(), 'y aun así el horario de apertura no existe');
    }

    public function test_search_returns_knowledge_entries_not_answers(): void
    {
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'lesion', 'title' => 'Mencionan una lesión', 'content' => 'No diagnosticar; marcar revisión del equipo.', 'is_active' => true, 'priority' => 2, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        MarketingKnowledgeItem::create(['category' => 'payment_policy', 'key' => 'pagos', 'title' => 'Pagos', 'content' => 'El equipo confirma el medio de pago al final.', 'is_active' => true, 'priority' => 1, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'off', 'title' => 'Inactiva', 'content' => 'pagos pagos pagos', 'is_active' => false, 'priority' => 1, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);

        $r = $this->g->search('cómo son los pagos?');

        $this->assertCount(1, $r);
        $this->assertSame('Pagos', $r[0]['title']);
        $this->assertSame([], $this->g->search('a'));
    }

    /** Hallazgo del revisor: la vigencia (valid_from / valid_until) manda igual que en el resto del sistema. */
    public function test_expired_or_future_knowledge_is_not_a_fact(): void
    {
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'vacaciones', 'title' => 'Horario de vacaciones', 'content' => '8am a 2pm', 'is_active' => true, 'valid_until' => now()->subDay(), 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'futuro', 'title' => 'Horario nuevo', 'content' => '5am a 11pm', 'is_active' => true, 'valid_from' => now()->addWeek(), 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        MarketingKnowledgeItem::create(['category' => 'location', 'key' => 'sede_vieja', 'title' => 'Sede anterior', 'content' => 'Cra 5 #10-20', 'is_active' => true, 'valid_until' => now()->subMonth(), 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);

        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->openingHours(), 'un horario caducado o aún no vigente no es el horario');
        $this->assertArrayNotHasKey('location', $this->g->businessInfo());
        $this->assertSame([], $this->g->search('sede anterior'));
    }
}
