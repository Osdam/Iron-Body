<?php

namespace Tests\Feature;

use App\Models\IronAiConversation;
use App\Models\IronAiMessage;
use App\Models\IronAiMessageAttachment;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alcance REAL del borrado de cuenta (Google Play — Datos de Usuario; App Store
 * 5.1.1(v)): qué desaparece y qué sobrevive.
 *
 * La política publicada promete que al eliminar la cuenta se borran las
 * conversaciones de IRON IA, sus mensajes y sus adjuntos. Antes no ocurría: se
 * anonimizaba la ficha del miembro y las tablas de IA se quedaban intactas, con
 * el número de documento del socio guardado en columna propia. Estas pruebas
 * atan el texto al comportamiento — si alguien recorta el borrado, el documento
 * publicado pasa a ser mentira y aquí se ve.
 */
class AccountDeletionDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'password' => 'secret',
            'document' => '1010101010',
            'phone' => '3001234567',
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'access_hash' => 'test-token-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    /** Confirma el borrado resolviendo el OTP que exige la acción sensible. */
    private function deleteAccount(Member $member): void
    {
        $req = $this->postJson('/api/member/account/delete-request', [], $this->auth($member));
        $req->assertOk();

        $payload = [];
        if ($req->json('requires_otp')) {
            $payload = [
                'challenge_id' => $req->json('challenge_id'),
                'code' => $req->json('dev_code'),
            ];
        }

        $this->postJson('/api/member/account/delete-confirm', $payload, $this->auth($member))
            ->assertOk();
    }

    public function test_iron_ai_conversations_messages_and_attachments_are_erased(): void
    {
        Storage::fake('local');
        $member = $this->member();

        $conversation = IronAiConversation::create([
            'uuid' => (string) Str::uuid(),
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'document' => $member->document_number,
            'title' => 'Consulta con IRON IA',
        ]);

        $message = IronAiMessage::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'iron_ai_conversation_id' => $conversation->id,
            'conversation_uuid' => $conversation->uuid,
            'role' => 'user',
            'content' => 'Me duele la rodilla izquierda al hacer sentadilla.',
        ]);

        Storage::disk('local')->put('iron-ai/audio-ana.m4a', 'binario-de-audio');

        IronAiMessageAttachment::create([
            'message_id' => $message->id,
            'iron_ai_conversation_id' => $conversation->id,
            'conversation_uuid' => $conversation->uuid,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'document' => $member->document_number,
            'type' => IronAiMessageAttachment::TYPE_AUDIO,
            'stored_path' => 'iron-ai/audio-ana.m4a',
            'disk' => 'local',
            'transcript' => 'Me duele la rodilla izquierda.',
        ]);

        $this->deleteAccount($member);

        // Las filas desaparecen de verdad: `iron_ai_conversations` usa
        // SoftDeletes, así que un `delete()` de Eloquent habría dejado el
        // contenido ahí, solo marcado.
        $this->assertDatabaseCount('iron_ai_conversations', 0);
        $this->assertDatabaseCount('iron_ai_messages', 0);
        $this->assertDatabaseCount('iron_ai_message_attachments', 0);

        // Y el fichero del audio tampoco se queda huérfano en disco.
        Storage::disk('local')->assertMissing('iron-ai/audio-ana.m4a');
    }

    public function test_deletion_survives_an_attachment_whose_file_is_already_gone(): void
    {
        Storage::fake('local');
        $member = $this->member();

        // Fichero referenciado que ya no existe (purga previa, disco recreado):
        // no puede impedir que alguien elimine su cuenta.
        IronAiMessageAttachment::create([
            'member_id' => $member->id,
            'type' => IronAiMessageAttachment::TYPE_IMAGE,
            'stored_path' => 'iron-ai/no-existe.jpg',
            'disk' => 'local',
        ]);

        $this->deleteAccount($member);

        $this->assertDatabaseCount('iron_ai_message_attachments', 0);
        $this->assertSame(Member::STATUS_DELETED, $member->fresh()->status);
    }

    public function test_member_identifiers_are_anonymized_and_login_is_blocked(): void
    {
        $member = $this->member();
        $originalDocument = $member->document_number;

        $this->deleteAccount($member);

        $fresh = $member->fresh();
        $this->assertSame(Member::STATUS_DELETED, $fresh->status);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertNotSame($originalDocument, $fresh->document_number);

        // El documento real queda libre: nada en la ficha permite volver a ella.
        $this->assertDatabaseMissing('members', ['document_number' => $originalDocument]);
    }

    public function test_usage_log_is_kept_for_accounting_but_without_the_document(): void
    {
        $member = $this->member();

        DB::table('iron_ai_usage_logs')->insert([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'document' => $member->document_number,
            'model' => 'gpt-4.1-mini',
            'input_tokens' => 120,
            'output_tokens' => 340,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteAccount($member);

        // La fila sigue (coste del servicio) pero ya no identifica a nadie.
        $log = DB::table('iron_ai_usage_logs')->where('member_id', $member->id)->first();
        $this->assertNotNull($log, 'El consumo es contabilidad de uso: no se borra.');
        $this->assertNull($log->document);
    }
}
