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
        MarketingKnowledgeItem::create(['category' => 'location', 'key' => 'sede', 'title' => 'Sede principal', 'content' => 'Cl. 24 Sur #33-53, Neiva', 'is_active' => true, 'priority' => 1]);

        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->openingHours());
        $this->assertSame(['Sede principal: Cl. 24 Sur #33-53, Neiva'], $this->g->businessInfo()['location']);

        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'horario', 'title' => 'Horario', 'content' => 'Lunes a viernes 5am a 10pm', 'is_active' => true, 'priority' => 1]);
        // Los hechos se leen una vez por instancia (una petición = una instancia): otra petición, otra lectura.
        $this->assertSame(['Horario: Lunes a viernes 5am a 10pm'], app(GymFactsProvider::class)->openingHours());
    }

    public function test_search_returns_knowledge_entries_not_answers(): void
    {
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'lesion', 'title' => 'Mencionan una lesión', 'content' => 'No diagnosticar; marcar revisión del equipo.', 'is_active' => true, 'priority' => 2]);
        MarketingKnowledgeItem::create(['category' => 'payment_policy', 'key' => 'pagos', 'title' => 'Pagos', 'content' => 'El equipo confirma el medio de pago al final.', 'is_active' => true, 'priority' => 1]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'off', 'title' => 'Inactiva', 'content' => 'pagos pagos pagos', 'is_active' => false, 'priority' => 1]);

        $r = $this->g->search('cómo son los pagos?');

        $this->assertCount(1, $r);
        $this->assertSame('Pagos', $r[0]['title']);
        $this->assertSame([], $this->g->search('a'));
    }

    /** Hallazgo del revisor: la vigencia (valid_from / valid_until) manda igual que en el resto del sistema. */
    public function test_expired_or_future_knowledge_is_not_a_fact(): void
    {
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'vacaciones', 'title' => 'Horario de vacaciones', 'content' => '8am a 2pm', 'is_active' => true, 'valid_until' => now()->subDay()]);
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'futuro', 'title' => 'Horario nuevo', 'content' => '5am a 11pm', 'is_active' => true, 'valid_from' => now()->addWeek()]);
        MarketingKnowledgeItem::create(['category' => 'location', 'key' => 'sede_vieja', 'title' => 'Sede anterior', 'content' => 'Cra 5 #10-20', 'is_active' => true, 'valid_until' => now()->subMonth()]);

        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $this->g->openingHours(), 'un horario caducado o aún no vigente no es el horario');
        $this->assertArrayNotHasKey('location', $this->g->businessInfo());
        $this->assertSame([], $this->g->search('sede anterior'));
    }
}
