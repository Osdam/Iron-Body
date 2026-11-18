<?php

namespace Tests\Feature\Valuations\Solicitudes;

use App\Models\Member;
use App\Models\Notification;
use App\Models\ValuationRequest;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Valuations\ValuationFixtures;

/**
 * Lo común de las pruebas de «Solicitar valoración». Reloj en UTC (memoria
 * «relojes de prueba en UTC»): miércoles 18-11-2026, 08:00 de Bogotá.
 */
trait SolicitudFixtures
{
    use ValuationFixtures;

    /** Socio con token de la app (`access_hash`). */
    protected function socio(string $nombre = 'María González', array $extra = []): Member
    {
        return $this->member($nombre, '2027-01-31', array_merge(['access_hash' => 'tok-'.Str::random(24)], $extra));
    }

    protected function tokenDe(Member $socio): array
    {
        return ['Authorization' => 'Bearer '.$socio->access_hash];
    }

    protected function solicitar(Member $socio, ?string $id = null, array $extra = []): TestResponse
    {
        return $this->postJson('/api/app/valuation-requests', array_merge(['client_request_id' => $id ?? (string) Str::uuid()], $extra), $this->tokenDe($socio));
    }

    protected function estado(Member $socio): TestResponse
    {
        return $this->getJson('/api/app/valuation-requests/current', $this->tokenDe($socio));
    }

    protected function solicitud(string $uuid): ValuationRequest
    {
        return ValuationRequest::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @return list<string> */
    protected function clavesDeAvisos(): array
    {
        return Notification::query()->where('event_key', 'like', 'valuation:request:%')->orderBy('id')->pluck('event_key')->all();
    }
}
