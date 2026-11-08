<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOS PERFILES DE VOZ por la API (contrato H8, D8-6, D8-22; S8-b): crear una
 * revisión, el reintento, las negativas con el motivo del compilador, enviar
 * (solo quien lo creó), aprobar y rechazar (otra identidad), y lo que se ve
 * (nunca el teléfono ni su huella). Sin red.
 */
class UltronStudioVoiceProfilesTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voiceSetUp();
        $this->ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve'], 'Ana Voz');
        $this->root = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve'], 'Root Revisa');
    }

    public function test_crea_un_borrador_con_lo_que_pone_el_servidor_y_sin_teléfonos_en_claro(): void
    {
        $r = $this->createProfile($this->ana, self::profileContentWithTransfer())->assertCreated();
        $dto = $r->json('data');

        $this->assertSame('draft', $dto['status']);
        $this->assertSame(1, $dto['revision']);
        $this->assertFalse($dto['recordCalls']);
        $this->assertFalse($dto['recordVoice']);
        $this->assertSame(1, $dto['transferCount']);
        $this->assertSame([['alias' => 'Recepción', 'last4' => '0099']], $dto['content']['transfer']['destinations']);
        // El servidor pone el formato, el idioma, el aviso vigente y la privacidad apagada.
        $this->assertSame(StudioVoiceCompiler::PROFILE_FORMAT, $dto['content']['format']);
        $this->assertSame('es', $dto['content']['language']);
        $this->assertSame(StudioVoiceTemplate::AI_NOTICE, $dto['content']['aiNotice']);
        $this->assertSame(['retentionDays' => 30, 'storeAudio' => false, 'recording' => false], $dto['content']['privacy']);

        // En la fila: la huella del destino en el contenido y el número solo cifrado.
        $fila = StudioVoiceProfile::query()->where('uuid', $dto['uuid'])->sole();
        $huella = StudioVoiceProfiles::phoneHash(self::DESTINATION_PHONE);
        $this->assertSame([['alias' => 'Recepción', 'phoneHash' => $huella]], $fila->content['transfer']['destinations']);
        $this->assertSame(StudioVoiceCompiler::profileHash($fila->content), $fila->content_hash);
        $crudo = (string) DB::table('ultron_studio_voice_profiles')->where('id', $fila->id)->value('transfer_destinations_encrypted');
        $this->assertStringNotContainsString(self::DESTINATION_PHONE, $crudo);
        $this->assertSame([$huella => self::DESTINATION_PHONE], StudioVoiceProfiles::transferNumbers($fila));
        $this->assertFalse((bool) DB::table('ultron_studio_voice_profiles')->where('id', $fila->id)->value('record_calls'));

        // Ni el número ni su huella salen en una respuesta.
        foreach ([$r->getContent(), $this->voice('GET', "profiles/{$dto['uuid']}", $this->root)->getContent(), $this->voice('GET', 'profiles', $this->root)->getContent()] as $cuerpo) {
            $this->assertStringNotContainsString('6010000099', $cuerpo);
            $this->assertStringNotContainsString($huella, $cuerpo);
        }

        $evento = StudioAuditEvent::query()->where('event', 'voice_profile.created')->sole();
        $this->assertSame([StudioAuditEvent::SUBJECT_INTEGRATION, $dto['uuid'], 'draft'], [$evento->subject_type, $evento->subject_uuid, $evento->to_status]);
    }

    public function test_el_mismo_cliente_y_cuerpo_es_el_mismo_perfil_y_otro_cuerpo_o_persona_es_409(): void
    {
        $crid = (string) Str::uuid();
        $primero = $this->createProfile($this->ana, null, $crid)->assertCreated()->json('data.uuid');

        $otraVez = $this->createProfile($this->ana, null, $crid)->assertOk();
        $this->assertTrue($otraVez->json('replayed'));
        $this->assertSame($primero, $otraVez->json('data.uuid'));
        $this->assertSame(1, StudioVoiceProfile::query()->count());

        $this->createProfile($this->ana, self::profileContent(['maxDurationSeconds' => 240]), $crid)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->createProfile($this->root, null, $crid)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
    }

    public function test_se_niega_con_el_motivo_y_el_campo_del_compilador_sin_repetir_el_texto(): void
    {
        $casos = [
            'dinero' => [['holes' => ['presentation' => 'Planes desde 80.000 al mes', 'closing' => 'Gracias por tu tiempo.']], 'text_money', 'profile.content.holes.presentation'],
            'descuento' => [['greetings' => ['inbound' => 'Hoy tenemos promoción especial', 'outbound' => 'Te llamo de Iron Body.']], 'text_discount', 'profile.content.greetings.inbound'],
            'campo inventado' => [['extra' => 'x'], 'profile_field_unknown', 'profile.content.extra'],
            'grabación' => [['privacy' => ['retentionDays' => 30, 'recording' => true]], 'recording_not_allowed', 'profile.content.privacy.recording'],
            'audio' => [['privacy' => ['retentionDays' => 30, 'storeAudio' => true]], 'audio_retention_not_allowed', 'profile.content.privacy.storeAudio'],
            'sin no volver a llamar' => [['tools' => ['consultar_planes']], 'opt_out_tool_required', 'profile.content.tools'],
            'herramienta de fuera' => [['tools' => ['no_volver_a_llamar', 'cobrar']], 'tool_not_in_catalog', 'profile.content.tools.1'],
            'transferir sin solicitar humano' => [['systemTools' => ['end_call', 'transfer_to_number'], 'transfer' => ['destinations' => [['alias' => 'Recepción', 'phone' => self::DESTINATION_PHONE]]]], 'transfer_tools_mismatch', 'profile.content.systemTools'],
            'teléfono sin forma' => [['tools' => ['no_volver_a_llamar', 'solicitar_humano'], 'systemTools' => ['end_call', 'transfer_to_number'], 'transfer' => ['destinations' => [['alias' => 'Recepción', 'phone' => '601 000 0099']]]], 'profile_field_invalid', 'profile.content.transfer.destinations.0.phone'],
            'más que el tope del servidor' => [['maxDurationSeconds' => 600], 'duration_exceeds_limit', 'profile.content.maxDurationSeconds'],
        ];
        foreach ($casos as $nombre => [$cambio, $motivo, $campo]) {
            $r = $this->createProfile($this->ana, array_replace(self::profileContent(), $cambio));
            $r->assertStatus(422);
            $this->assertSame(['voice_compile_refused', $motivo, $campo], [$r->json('code'), $r->json('reason'), $r->json('field')], $nombre);
            $this->assertStringNotContainsString('80.000', $r->getContent(), $nombre);
        }
        $this->assertSame(0, StudioVoiceProfile::query()->count());

        // La forma, en el borde (sin llegar al compilador).
        $this->voice('POST', 'profiles', $this->ana, ['clientRequestId' => 'no-uuid', 'label' => 'x', 'content' => []])->assertStatus(422);
        $this->createProfile($this->ana, self::profileContent(['maxDurationSeconds' => 30]))->assertStatus(422)->assertJsonValidationErrors('content.maxDurationSeconds');
        $this->createProfile($this->ana, null, null, 'Llama al 3000000000')->assertStatus(422)->assertJsonValidationErrors('label');
    }

    public function test_enviar_solo_quien_lo_creo_y_aprobar_o_rechazar_otra_identidad(): void
    {
        $uuid = (string) $this->createProfile($this->ana)->assertCreated()->json('data.uuid');

        $this->voice('POST', "profiles/{$uuid}/submit", $this->root)->assertStatus(403)->assertJsonPath('code', 'not_profile_owner');
        $this->voice('POST', "profiles/{$uuid}/approve", $this->root)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $enviado = $this->voice('POST', "profiles/{$uuid}/submit", $this->ana)->assertOk();
        $this->assertSame('submitted', $enviado->json('data.status'));
        $this->voice('POST', "profiles/{$uuid}/submit", $this->ana)->assertStatus(409)->assertJsonPath('currentStatus', 'submitted');

        // Cuatro ojos: quien lo creó no lo aprueba ni lo rechaza, y el intento queda en el libro.
        $this->voice('POST', "profiles/{$uuid}/approve", $this->ana)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->voice('POST', "profiles/{$uuid}/reject", $this->ana, ['note' => 'No me gusta la despedida'])->assertStatus(403);
        $this->assertSame(2, StudioAuditEvent::query()->where('event', 'voice_profile.four_eyes_denied')->count());

        $aprobado = $this->voice('POST', "profiles/{$uuid}/approve", $this->root, ['note' => 'Revisado con el equipo'])->assertOk();
        $this->assertSame(['approved', 'Root Revisa', 'Revisado con el equipo'], [$aprobado->json('data.status'), $aprobado->json('data.reviewedBy.name'), $aprobado->json('data.reviewNote')]);
        $this->voice('POST', "profiles/{$uuid}/reject", $this->root, ['note' => 'Demasiado tarde'])->assertStatus(409);

        // Rechazar exige una nota.
        $otro = (string) $this->createProfile($this->ana, null, null, 'Otra voz')->assertCreated()->json('data.uuid');
        $this->voice('POST', "profiles/{$otro}/submit", $this->ana)->assertOk();
        $this->voice('POST', "profiles/{$otro}/reject", $this->root)->assertStatus(422)->assertJsonValidationErrors('note');
        $this->voice('POST', "profiles/{$otro}/reject", $this->root, ['note' => 'La despedida es muy larga'])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Una revisión nueva, basada en la aprobada.
        $nueva = $this->createProfile($this->ana, self::profileContent(['maxDurationSeconds' => 240]), null, 'Voz v2', $uuid)->assertCreated();
        $this->assertSame([2, $uuid], [$nueva->json('data.revision'), $nueva->json('data.basedOn.uuid')]);
    }

    public function test_aprobar_vuelve_a_comprobar_la_huella_y_la_plantilla(): void
    {
        // Una huella que no es la de su contenido (escrita a mano): no se aprueba.
        $sembrado = $this->seedProfileRow($this->ana, 'submitted', str_repeat('0', 64));
        $r = $this->voice('POST', "profiles/{$sembrado}/approve", $this->root)->assertStatus(422);
        $this->assertSame(['voice_compile_refused', 'profile_hash_mismatch'], [$r->json('code'), $r->json('reason')]);
        $this->assertSame('submitted', StudioVoiceProfile::query()->where('uuid', $sembrado)->value('status'));

        // Un aviso que ya no es el de la plantilla vigente: tampoco (hay que revisarlo otra vez).
        $contenido = self::seededContent();
        $contenido['aiNotice'] = 'Hola, te habla un asistente.';
        $viejo = $this->seedProfileRow($this->ana, 'submitted', StudioVoiceCompiler::profileHash($contenido), $contenido);
        $r = $this->voice('POST', "profiles/{$viejo}/approve", $this->root)->assertStatus(422);
        $this->assertSame('notice_mismatch', $r->json('reason'));
    }

    public function test_sin_permiso_o_sin_persona_no_entra_y_un_uuid_inventado_es_404(): void
    {
        $solover = $this->personWith(['ultron.view'], 'Solo Ve');
        $this->createProfile($solover)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $uuid = (string) $this->createProfile($this->ana)->assertCreated()->json('data.uuid');
        $this->voice('POST', "profiles/{$uuid}/submit", $solover)->assertStatus(403);
        $this->voice('POST', "profiles/{$uuid}/approve", $this->personWith(self::VOICE_MANAGER, 'Gestiona'))->assertStatus(403)->assertJsonPath('required_permission', 'ultron.approve');

        // Leer exige ultron.view y una persona.
        $this->voice('GET', 'profiles', $this->personWith(['ultron.voice_manage'], 'Sin ver'))->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        $this->json('GET', '/api/admin/ultron-studio/voice/profiles')->assertStatus(401);
        $this->json('GET', '/api/admin/ultron-studio/voice/profiles', [], $this->sharedTokenHeaders())->assertStatus(403)->assertJsonPath('code', 'person_required');

        $this->voice('GET', 'profiles/'.Str::uuid(), $this->ana)->assertStatus(404)->assertJsonPath('code', 'voice_profile_not_found');
        $this->createProfile($this->ana, null, null, 'Basada en nada', (string) Str::uuid())->assertStatus(404);

        $lista = $this->voice('GET', 'profiles?status=draft', $solover)->assertOk();
        $this->assertSame([$uuid], array_column($lista->json('data.data'), 'uuid'));
        $this->assertArrayNotHasKey('content', $lista->json('data.data.0'));
        $this->assertSame(['allowed' => false, 'code' => 'forbidden'], $lista->json('data.data.0.actions.submit'));
        $this->voice('GET', 'profiles?status=otro', $solover)->assertStatus(422);
    }

    /** @return array<string, mixed> el contenido completo de un perfil válido, como lo guarda el servidor */
    private static function seededContent(): array
    {
        return [
            'format' => StudioVoiceCompiler::PROFILE_FORMAT, 'language' => 'es', 'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            ...array_diff_key(self::profileContent(), ['privacy' => 1]),
            'privacy' => ['retentionDays' => 30, 'storeAudio' => false, 'recording' => false],
        ];
    }

    /**
     * Un perfil sembrado en la base con la huella que se pida (para lo que la API no deja hacer).
     *
     * @param  array<string, mixed>|null  $contenido
     */
    private function seedProfileRow(Admin $creator, string $status, string $hash, ?array $contenido = null): string
    {
        $contenido ??= self::seededContent();
        $uuid = (string) Str::uuid();
        $ahora = now();
        $id = DB::table('ultron_studio_voice_profiles')->insertGetId([
            'uuid' => $uuid, 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'label' => 'Sembrado', 'language' => 'es',
            'max_duration_seconds' => 300, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false, 'transfer_count' => 0,
            'content' => json_encode($contenido), 'content_hash' => $hash, 'status' => 'draft',
            'created_by_admin_id' => $creator->id, 'created_by_name' => $creator->name, 'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        if ($status !== 'draft') {
            DB::table('ultron_studio_voice_profiles')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by_admin_id' => $creator->id, 'submitted_by_name' => $creator->name, 'submitted_at' => $ahora, 'status_changed_at' => $ahora,
            ]);
        }

        return $uuid;
    }
}
