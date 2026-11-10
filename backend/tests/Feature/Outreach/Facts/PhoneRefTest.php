<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\PhoneRef;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectRef;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · EL SEUDÓNIMO DEL TELÉFONO (decisión L9-29 del lead sobre Q-8): el
 * tope diario se aplica también POR TELÉFONO, así que la foto expone
 * `phone_ref` = HMAC del número normalizado con la subclave `outreach-phone/1`
 * (64 hex), por persona, desconocido sin teléfono, y nunca el número.
 */
class PhoneRefTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    private const NOW = '2026-11-09 15:00:00';

    /** Lo que pide la escalera: la política (y con ella los teléfonos). */
    private static function policy(): FactSelection
    {
        return new FactSelection([], [], true, true, false);
    }

    public function test_dos_personas_con_el_mismo_numero_tienen_el_mismo_phone_ref(): void
    {
        // Una familia: dos prospectos sin ficha de socio y un socio, el mismo número escrito de tres formas.
        $uno = $this->lead(['phone' => '3001234567']);
        $otro = $this->lead(['phone' => '+57 300 123 4567']);
        $socio = $this->socio([], ['phone' => '573001234567']);
        $claves = [SubjectKey::lead($uno), SubjectKey::lead($otro), SubjectKey::member($socio['member'])];
        $foto = $this->snap($claves, self::NOW, self::policy());

        $refs = array_map(fn (string $k) => $foto->subject($k)->phoneRef, $claves);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $refs[0]);
        $this->assertSame([$refs[0], $refs[0]], [$refs[1], $refs[2]]);
        // Son tres personas distintas (el teléfono no une personas), con un solo número.
        $this->assertCount(3, array_unique(array_map(fn (string $k) => $foto->subject($k)->ref, $claves)));
        $ordenadas = $claves;
        sort($ordenadas, SORT_STRING);
        $this->assertSame([$refs[0] => $ordenadas], CoreProjection::sharedPhones($foto));
        $this->assertSame([$refs[0]], CoreProjection::phoneKeys($foto->subject($claves[0])));
        // Ni el número ni sus 10 dígitos aparecen en la foto.
        $this->assertStringNotContainsString('3001234567', $foto->canonicalJson());
    }

    public function test_dos_numeros_distintos_dan_refs_distintos_y_sin_numero_es_desconocido(): void
    {
        $a = $this->lead(['phone' => '3001234567']);
        $b = $this->lead(['phone' => '3001234568']);
        $sinTelefono = $this->socio([], ['phone' => null]);
        $fijo = $this->socio([], ['phone' => '6015551234']);   // un fijo: la puerta de WhatsApp no lo reconoce
        $claves = [SubjectKey::lead($a), SubjectKey::lead($b), SubjectKey::member($sinTelefono['member']), SubjectKey::member($fijo['member']), 'm:999999'];
        $foto = $this->snap($claves, self::NOW, self::policy());

        $this->assertNotSame($foto->subject($claves[0])->phoneRef, $foto->subject($claves[1])->phoneRef);
        $this->assertSame([], CoreProjection::sharedPhones($foto));
        foreach ([2, 3, 4] as $i) {
            $this->assertNull($foto->subject($claves[$i])->phoneRef, "{$claves[$i]} no tiene número: desconocido.");
            $this->assertSame([], $foto->subject($claves[$i])->phoneRefs);
        }
        // Sin la política no se leen: null también en la lista (no se pidió).
        $sinPolitica = $this->snap([$claves[0]], self::NOW, self::only(['subject.is_lead']));
        $this->assertNull($sinPolitica->subject($claves[0])->phoneRefs);
        $this->assertNull(CoreProjection::phoneKeys($sinPolitica->subject($claves[0])));
    }

    public function test_el_principal_es_el_del_prospecto_y_la_lista_lleva_todos_sus_numeros(): void
    {
        $socio = $this->socio([], ['phone' => '3009990001']);
        $this->lead(['member_id' => $socio['member'], 'phone' => '3009990002']);
        $clave = SubjectKey::member($socio['member']);
        $foto = $this->snap([$clave], self::NOW, self::policy());
        $s = $foto->subject($clave);
        $esperados = [PhoneRef::fromRaw('3009990001'), PhoneRef::fromRaw('3009990002')];
        sort($esperados, SORT_STRING);
        $this->assertSame(PhoneRef::fromRaw('3009990002'), $s->phoneRef);
        $this->assertSame($esperados, $s->phoneRefs);

        // Serializa y vuelve igual; un seudónimo que no está en la lista no se admite.
        $datos = json_decode($foto->canonicalJson(), true);
        $this->assertSame($foto->hash(), BatchSnapshot::fromArray($datos)->hash());
        $datos['subjects'][$clave]['phone_ref'] = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        BatchSnapshot::fromArray($datos);
    }

    public function test_la_derivacion_es_el_hmac_con_la_subclave_outreach_phone(): void
    {
        $clave = random_bytes(32);
        $esperado = hash_hmac('sha256', '573001234567', hash_hmac('sha256', 'outreach-phone/1', $clave, true));
        $this->assertSame($esperado, PhoneRef::withKey('573001234567', $clave));
        $this->assertSame(PhoneRef::fromRaw('3001234567'), PhoneRef::fromRaw('+57 (300) 123-4567'));
        $this->assertNull(PhoneRef::fromRaw(null));
        $this->assertNull(PhoneRef::fromRaw('12345'));
        // Otro dominio, otro valor: ni el seudónimo de persona ni la huella de los canarios.
        $this->assertNotSame(PhoneRef::withKey('573001234567', $clave), hash_hmac('sha256', '573001234567', SubjectRef::subkey($clave)));
        $this->assertNotSame(PhoneRef::fromRaw('3001234567'), PhoneFingerprint::of('+573001234567'));
        foreach (['3001234567', '+573001234567', '57300123456a', ''] as $malo) {
            try {
                PhoneRef::withKey($malo, $clave);
                $this->fail('Solo se admite el número normalizado.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
