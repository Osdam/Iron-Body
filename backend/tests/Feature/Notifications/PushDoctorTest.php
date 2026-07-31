<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationDispatch;

class PushDoctorTest extends NotificationTestCase
{
    public function test_resume_la_flota_sin_enviar_nada(): void
    {
        $this->giveDevice($this->makeMember());

        $this->artisan('push:doctor')
            ->expectsOutputToContain('iron-body-test')
            ->assertSuccessful();

        $this->assertSame(0, NotificationDispatch::count(), 'Diagnosticar no debe enviar.');
    }

    public function test_avisa_si_el_socio_no_existe(): void
    {
        $this->artisan('push:doctor', ['document' => '000000'])->assertFailed();
    }

    public function test_avisa_si_el_socio_no_tiene_dispositivos(): void
    {
        $member = $this->makeMember();

        $this->artisan('push:doctor', ['document' => $member->document_number])
            ->expectsOutputToContain('Sin dispositivos registrados')
            ->assertSuccessful();
    }

    public function test_marca_un_token_ios_viejo(): void
    {
        $member = $this->makeMember();
        $token = $this->giveDevice($member);
        $token->platform = 'ios';
        $token->save();
        // Se salta los timestamps automáticos para simular abandono real.
        $token->newQuery()->where('id', $token->id)
            ->update(['updated_at' => now()->subDays(20)]);

        $this->artisan('push:doctor', ['document' => $member->document_number])
            ->expectsOutputToContain('sin refrescarse')
            ->assertSuccessful();
    }

    public function test_envia_la_prueba_cuando_se_pide(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $this->artisan('push:doctor', [
            'document' => $member->document_number,
            '--send' => 'android',
        ])->assertSuccessful();

        $dispatch = NotificationDispatch::firstWhere('member_id', $member->id);
        $this->assertSame(NotificationDispatch::STATUS_SENT, $dispatch->status);
    }

    public function test_rechaza_una_plataforma_desconocida(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);

        $this->artisan('push:doctor', [
            'document' => $member->document_number,
            '--send' => 'blackberry',
        ])->assertFailed();

        $this->assertSame(0, NotificationDispatch::count());
    }

    public function test_no_deja_enviar_sin_indicar_socio(): void
    {
        $this->artisan('push:doctor', ['--send' => 'all'])->assertFailed();
        $this->assertSame(0, NotificationDispatch::count());
    }
}
