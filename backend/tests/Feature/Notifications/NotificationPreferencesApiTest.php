<?php

namespace Tests\Feature\Notifications;

use App\Models\MemberNotificationPreference;
use App\Support\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;

class NotificationPreferencesApiTest extends NotificationTestCase
{
    public function test_devuelve_los_valores_por_defecto_sin_fila_guardada(): void
    {
        $member = $this->makeMember();

        $res = $this->getJson('/api/app/notification-preferences', $this->asMember($member));

        $res->assertOk();
        $this->assertTrue($res->json('data.categories.'.NotificationCategory::MOTIVATION));
        $this->assertFalse(
            $res->json('data.categories.'.NotificationCategory::SUPPLEMENTS),
            'Los suplementos deben nacer apagados: son opt-in explícito.',
        );
        $this->assertFalse($res->json('data.categories.'.NotificationCategory::PROMOTIONS));
        $this->assertTrue($res->json('data.categories.'.NotificationCategory::ACCOUNT_SECURITY));
    }

    public function test_exige_autenticacion(): void
    {
        $this->getJson('/api/app/notification-preferences')->assertUnauthorized();
    }

    public function test_guarda_un_interruptor_sin_borrar_los_demas(): void
    {
        $member = $this->makeMember();

        $this->putJson('/api/app/notification-preferences', [
            'categories' => [NotificationCategory::SUPPLEMENTS => true],
        ], $this->asMember($member))->assertOk();

        $res = $this->putJson('/api/app/notification-preferences', [
            'categories' => [NotificationCategory::SOCIAL => false],
        ], $this->asMember($member));

        $res->assertOk();
        $this->assertTrue(
            $res->json('data.categories.'.NotificationCategory::SUPPLEMENTS),
            'Mandar un solo interruptor no debe borrar los que ya estaban.',
        );
        $this->assertFalse($res->json('data.categories.'.NotificationCategory::SOCIAL));
    }

    public function test_no_deja_apagar_la_seguridad_de_la_cuenta(): void
    {
        $member = $this->makeMember();

        $res = $this->putJson('/api/app/notification-preferences', [
            'categories' => [NotificationCategory::ACCOUNT_SECURITY => false],
        ], $this->asMember($member));

        $res->assertOk();
        $this->assertTrue($res->json('data.categories.'.NotificationCategory::ACCOUNT_SECURITY));
    }

    public function test_ignora_categorias_desconocidas(): void
    {
        $member = $this->makeMember();

        $this->putJson('/api/app/notification-preferences', [
            'categories' => ['categoria_inventada' => true],
        ], $this->asMember($member))->assertOk();

        $saved = MemberNotificationPreference::forMember($member->id);
        $this->assertArrayNotHasKey('categoria_inventada', $saved->categories ?? []);
    }

    public function test_rechaza_una_zona_horaria_invalida(): void
    {
        $member = $this->makeMember();

        $this->putJson('/api/app/notification-preferences', [
            'timezone' => 'Marte/Olympus',
        ], $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_timezone');
    }

    public function test_guarda_las_horas_de_silencio(): void
    {
        $member = $this->makeMember();

        $res = $this->putJson('/api/app/notification-preferences', [
            'quiet_hours_start' => 22,
            'quiet_hours_end' => 6,
            'timezone' => 'America/Bogota',
        ], $this->asMember($member));

        $res->assertOk();
        $this->assertSame(22, $res->json('data.quiet_hours_start'));
        $this->assertSame(6, $res->json('data.quiet_hours_end'));
    }

    public function test_el_opt_out_global_se_refleja_y_se_revierte(): void
    {
        $member = $this->makeMember();

        $this->putJson('/api/app/notification-preferences', ['opted_out' => true], $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data.opted_out', true);

        $this->putJson('/api/app/notification-preferences', ['opted_out' => false], $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data.opted_out', false);
    }

    public function test_nadie_puede_cambiar_las_preferencias_de_otro(): void
    {
        $uno = $this->makeMember('Uno');
        $otro = $this->makeMember('Otro');

        // Aunque se cuele un member_id en el cuerpo, el actor sale del bearer.
        $this->putJson('/api/app/notification-preferences', [
            'member_id' => $otro->id,
            'categories' => [NotificationCategory::SOCIAL => false],
        ], $this->asMember($uno))->assertOk();

        $this->assertTrue(
            MemberNotificationPreference::forMember($otro->id)->allows(NotificationCategory::SOCIAL),
            'Las preferencias del otro socio no debían cambiar.',
        );
    }

    public function test_las_horas_de_silencio_cruzan_la_medianoche(): void
    {
        $prefs = new MemberNotificationPreference([
            'timezone' => 'America/Bogota',
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => 21,
            'quiet_hours_end' => 7,
        ]);

        // 03:00 en Bogotá (08:00 UTC) está dentro del tramo nocturno.
        $this->assertTrue($prefs->inQuietHours(CarbonImmutable::parse('2026-07-30 08:00:00', 'UTC')));
        // 12:00 en Bogotá (17:00 UTC) está fuera.
        $this->assertFalse($prefs->inQuietHours(CarbonImmutable::parse('2026-07-30 17:00:00', 'UTC')));
        // 22:00 en Bogotá (03:00 UTC del día siguiente) está dentro.
        $this->assertTrue($prefs->inQuietHours(CarbonImmutable::parse('2026-07-31 03:00:00', 'UTC')));
    }
}
