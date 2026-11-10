<?php

namespace Tests\Feature\Outreach\Facts\Support;

use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\FactPass;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\SubjectResolver;
use App\Services\Telephony\VoicePhone;
use App\Support\Privacy\PhoneFingerprint;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El mundo de las pruebas de B9-facts: filas sembradas con INSERT directos
 * (sin modelos ni observers: un observer del motor comercial escribiría en
 * `commercial_events` y la prueba de solo lectura no sabría de quién es la
 * escritura). Todas las horas en UTC de pared, como las escribe producción
 * (memoria del proyecto: relojes de prueba en UTC).
 */
trait FactsWorld
{
    private int $semilla = 0;

    /** Un instante UTC escrito como lo guarda la base. */
    protected static function utc(string $wall): string
    {
        return (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** `now` inyectado (UTC). */
    protected static function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * Un socio con usuario. `$user` y `$member` sobrescriben columnas.
     *
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $member
     * @return array{user: int, member: int}
     */
    protected function socio(array $user = [], array $member = []): array
    {
        $n = ++$this->semilla;
        $userId = null;
        if ($user !== ['__none__' => true]) {
            $userId = (int) DB::table('users')->insertGetId(array_merge([
                'name' => "Socio {$n}", 'email' => "socio{$n}-".Str::random(6).'@facts.test', 'password' => 'x',
                'plan' => 'Mensual', 'membership_end_date' => '2026-12-31', 'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
            ], $user));
        }
        $memberId = (int) DB::table('members')->insertGetId(array_merge([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'm'.$n.Str::random(8)), 'full_name' => "Socio {$n}",
            'document_number' => 'DOC'.$n.Str::random(6), 'user_id' => $userId, 'birth_date' => '1990-05-05', 'is_minor' => false,
            'status' => 'active', 'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ], $member));

        return ['user' => $userId, 'member' => $memberId];
    }

    /** @param  array<string, mixed>  $o */
    protected function lead(array $o = []): int
    {
        $n = ++$this->semilla;

        return (int) DB::table('marketing_leads')->insertGetId(array_merge([
            'channel' => 'whatsapp', 'phone' => '30010'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'status' => 'new', 'temperature' => 'warm',
            'consent_status' => 'granted', 'do_not_contact' => false, 'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ], $o));
    }

    /** @param  array<string, mixed>  $o */
    protected function conversation(int $leadId, array $o = []): int
    {
        return (int) DB::table('marketing_conversations')->insertGetId(array_merge([
            'lead_id' => $leadId, 'channel' => 'whatsapp', 'status' => 'open', 'human_takeover' => false, 'ai_enabled' => true,
            'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ], $o));
    }

    /** Un saliente de la IA (`kind` en la metadata; `reply` no cuenta). */
    protected function aiMessage(int $conversationId, string $atUtc, ?string $kind = 'followup', string $sender = 'ai', string $direction = 'outbound'): int
    {
        return (int) DB::table('marketing_messages')->insertGetId([
            'conversation_id' => $conversationId, 'direction' => $direction, 'sender_type' => $sender, 'body' => 'TEXTO-PRIVADO-QUE-NO-SALE',
            'status' => 'sent', 'metadata' => json_encode(['kind' => $kind, 'recipient' => '573001112233']),
            'created_at' => self::utc($atUtc), 'updated_at' => self::utc($atUtc),
        ]);
    }

    /** @param  array<string, mixed>  $o */
    protected function plan(array $o = []): int
    {
        return (int) DB::table('plans')->insertGetId(array_merge([
            'name' => 'Mensual', 'price' => 100000, 'duration_days' => 30, 'active' => true, 'sellable' => true,
            'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ], $o));
    }

    protected function entry(int $userId, string $atUtc, ?int $memberId = null, string $action = 'entry'): void
    {
        DB::table('attendances')->insert([
            'user_id' => $userId, 'member_id' => $memberId, 'action' => $action, 'source' => 'manual',
            'captured_at' => self::utc($atUtc), 'created_at' => self::utc($atUtc), 'updated_at' => self::utc($atUtc),
        ]);
    }

    protected function receivable(int $memberId, ?string $dueAt, string $status = 'pending'): int
    {
        return (int) DB::table('receivables')->insertGetId([
            'member_id' => $memberId, 'debtor_type' => 'member', 'debtor_id' => $memberId, 'type' => 'gym', 'concept' => 'Plan a plazos',
            'total_amount' => 80000, 'status' => $status, 'due_at' => $dueAt, 'created_at' => self::utc('2026-10-01 12:00'), 'updated_at' => self::utc('2026-10-01 12:00'),
        ]);
    }

    protected function receivablePayment(int $receivableId, string $status = 'applied'): void
    {
        DB::table('receivable_payments')->insert([
            'receivable_id' => $receivableId, 'amount' => 1000, 'method' => 'cash', 'balance_before' => 80000, 'balance_after' => 79000,
            'status' => $status, 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);
    }

    /** @param  array<string, mixed>  $o */
    protected function tx(array $o): int
    {
        $n = ++$this->semilla;

        return (int) DB::table('payment_transactions')->insertGetId(array_merge([
            'reference' => "REF-{$n}-".Str::random(6), 'idempotency_key' => "IK-{$n}-".Str::random(6), 'amount' => 100000, 'currency' => 'COP',
            'status' => 'pending', 'provider' => 'wompi', 'checkout_url' => 'https://checkout.wompi.co/l/test'.$n,
            'created_at' => self::utc('2026-11-09 10:00'), 'updated_at' => self::utc('2026-11-09 10:00'),
        ], $o));
    }

    /** @param  array<string, mixed>  $o */
    protected function klass(array $o = []): int
    {
        return (int) DB::table('classes')->insertGetId(array_merge([
            'name' => 'Spinning AM', 'type' => 'Spinning', 'day_of_week' => 'Miércoles', 'start_time' => '18:00', 'end_time' => '19:00',
            'max_capacity' => 2, 'status' => 'active', 'is_recurring' => true, 'allow_online_booking' => true,
            'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ], $o));
    }

    protected function reservation(int $classId, int $memberId, ?string $sessionDate): void
    {
        DB::table('class_reservations')->insert([
            'class_id' => $classId, 'member_id' => $memberId, 'session_date' => $sessionDate, 'reserved_at' => self::utc('2026-11-01 12:00'),
            'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);
    }

    protected function classAttendance(int $classId, int $memberId, string $sessionDate, string $status = 'present'): void
    {
        DB::table('class_attendances')->insert([
            'class_id' => $classId, 'member_id' => $memberId, 'session_date' => $sessionDate, 'status' => $status,
            'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);
    }

    protected function trainer(): int
    {
        return (int) DB::table('trainers')->insertGetId(['full_name' => 'Entrenador '.Str::random(4), 'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00')]);
    }

    protected function valuation(int $memberId, int $trainerId, string $status, string $startsUtc, ?string $completedUtc = null): void
    {
        $inicio = self::utc($startsUtc);
        DB::table('valuation_appointments')->insert([
            'uuid' => (string) Str::uuid(), 'member_id' => $memberId, 'trainer_id' => $trainerId, 'type' => 'initial', 'status' => $status,
            'starts_at' => $inicio, 'ends_at' => (new DateTimeImmutable($inicio, new DateTimeZone('UTC')))->modify('+60 minutes')->format('Y-m-d H:i:s'),
            'duration_minutes' => 60, 'completed_at' => $completedUtc !== null ? self::utc($completedUtc) : null,
            'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00'),
        ]);
    }

    protected function physicalEvaluation(int $memberId, string $atUtc): void
    {
        DB::table('physical_evaluations')->insert(['member_id' => $memberId, 'created_at' => self::utc($atUtc), 'updated_at' => self::utc($atUtc)]);
    }

    /**
     * Un contacto de voz ACTIVO (número cifrado; huella `voice-phone`).
     *
     * @return array{id: int, hash: string}
     */
    protected function voiceContact(string $sourceType, int $sourceId, string $e164): array
    {
        $huella = VoicePhone::hash($e164);
        $id = (int) DB::table('voice_contacts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'phone_hash' => $huella, 'phone_last4' => substr($e164, -4),
            'phone_encrypted' => encrypt($e164), 'source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'active',
            'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'status_changed_at' => self::utc('2026-11-01 12:00'),
            'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);

        return ['id' => $id, 'hash' => $huella];
    }

    protected function voiceConsent(int $contactId, string $kind, ?int $confirmedBy, string $atUtc, ?int $recordedBy = 1, string $source = 'written_form'): void
    {
        DB::table('voice_consents')->insert([
            'uuid' => (string) Str::uuid(), 'contact_id' => $contactId, 'kind' => $kind, 'channel' => 'voice_call', 'purpose' => 'commercial',
            'source' => $source, 'evidence' => 'Formulario firmado en recepción', 'actor_type' => $recordedBy === null ? 'system' : 'admin', 'recorded_by_admin_id' => $recordedBy,
            'recorded_by_name' => 'Ana', 'recorded_at' => self::utc($atUtc), 'confirmed_by_admin_id' => $confirmedBy,
            'confirmed_by_name' => $confirmedBy !== null ? 'Beto' : null, 'created_at' => self::utc($atUtc),
        ]);
    }

    /**
     * Una exclusión por huella con la forma que exige DB8 (`vex_source_shape_chk`):
     * `spoken_opt_out` («no volver a llamar») la deja el sistema con su llamada;
     * `admin`, una persona.
     */
    protected function voiceExclusion(string $hash, string $source = 'admin', ?int $callId = null): void
    {
        DB::table('voice_exclusions')->insert([
            'uuid' => (string) Str::uuid(), 'phone_hash' => $hash, 'phone_last4' => '0000', 'source' => $source, 'call_id' => $callId,
            'actor_type' => $source === 'admin' ? 'admin' : 'system', 'actor_admin_id' => $source === 'admin' ? 1 : null,
            'actor_name' => $source === 'admin' ? 'Ana' : 'Sistema', 'created_at' => self::utc('2026-11-01 12:00'),
        ]);
    }

    protected function rneCheck(string $hash, string $checkedUtc, string $result = 'not_listed'): void
    {
        DB::table('voice_rne_checks')->insert([
            'uuid' => (string) Str::uuid(), 'phone_hash' => $hash, 'checked_at' => self::utc($checkedUtc), 'result' => $result,
            'method' => 'manual_portal', 'evidence_ref' => 'CRC-portal-ref', 'actor_type' => 'admin', 'actor_admin_id' => 1, 'actor_name' => 'Ana',
            'created_at' => self::utc($checkedUtc),
        ]);
    }

    /**
     * Una saliente de voz al número, por el camino que admiten los disparadores
     * de DB8 (nace `requested` y avanza), como `VoiceFixtures::voiceCall()`.
     */
    protected function outboundCall(string $e164, string $status, string $createdUtc): int
    {
        $n = ++$this->semilla;
        $creada = self::utc($createdUtc);
        $id = (int) DB::table('voice_calls')->insertGetId([
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'requested',
            'remote_hash' => VoicePhone::hash($e164), 'remote_last4' => substr($e164, -4), 'attempt' => 1, 'budget_day' => substr($creada, 0, 10),
            'reserved_micros' => 610500, 'tool_calls' => 0, 'status_changed_at' => $creada, 'created_at' => $creada, 'updated_at' => $creada,
            'voice_publication_seq' => 1, 'part' => 'canary', 'stamp_state' => 'applied', 'studio_version_uuid' => (string) Str::uuid(),
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_hash' => hash('sha256', 'p'), 'spec_hash' => hash('sha256', 's'),
            'payload_hash' => hash('sha256', 'c'), 'compiler_version' => 'ultron-voice-compiler/1', 'provider' => 'elevenlabs', 'provider_agent_id' => 'agent_facts_0001',
            'target_source' => 'canary_registry', 'canary_number_uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
            'requested_by_type' => 'admin', 'requested_by_admin_id' => 1, 'requested_by_name' => 'Ana', 'requested_by_role' => 'Super Admin',
        ]);
        $ids = ['provider_conversation_id' => "conv_facts_{$n}", 'provider_call_sid' => 'CA'.substr(hash('sha256', "facts{$n}"), 0, 32)];
        $camino = match ($status) {
            'requested' => [],
            'canceled' => [['status' => 'canceled', 'policy_reason' => 'outbound_disabled', 'ended_at' => $creada]],
            'failed_to_start' => [['status' => 'failed_to_start', 'failure_code' => 'client_error', 'ended_at' => $creada]],
            default => [['status' => 'dialing', 'started_at' => $creada] + $ids, ['status' => $status, 'ended_at' => $creada]],
        };
        foreach ($camino as $paso) {
            DB::table('voice_calls')->where('id', $id)->update($paso + ['status_changed_at' => $creada]);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $o */
    protected function opportunity(array $o): void
    {
        DB::table('commercial_opportunities')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'goal' => 'renew', 'status' => 'open', 'next_action' => 'offer_renewal', 'priority' => 50,
            'reason' => 'motor', 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ], $o));
    }

    protected function commercialEvent(string $event, string $atUtc, ?int $memberId = null, ?int $leadId = null): void
    {
        DB::table('commercial_events')->insert([
            'event' => $event, 'member_id' => $memberId, 'marketing_lead_id' => $leadId, 'occurred_at' => self::utc($atUtc),
            'payload' => json_encode(['amount' => 99999]), 'created_at' => self::utc($atUtc), 'updated_at' => self::utc($atUtc),
        ]);
    }

    /** Un número canario ACTIVO del H6 (huella `ultron-canary-phone/1`). */
    protected function canary(string $e164, string $channel = 'whatsapp'): void
    {
        DB::table('ultron_studio_canary_numbers')->insert([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => $channel, 'alias' => 'Canario prueba',
            'phone_hash' => PhoneFingerprint::of($e164), 'phone_last4' => substr($e164, -4), 'country_code' => 'CO',
            'phone_encrypted' => $channel === 'voice' ? encrypt($e164) : null, 'status' => 'active', 'consent_attested' => true,
            'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'authorized_by_admin_id' => 2, 'authorized_by_name' => 'Beto',
            'authorized_at' => self::utc('2026-11-01 12:00'), 'activated_by_admin_id' => 2, 'activated_by_name' => 'Beto',
            'activated_at' => self::utc('2026-11-01 12:00'), 'status_changed_at' => self::utc('2026-11-01 12:00'),
            'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);
    }

    /** @param  list<string>  $keys */
    protected function snap(array $keys, string $nowUtc, ?FactSelection $sel = null): BatchSnapshot
    {
        return (new BatchSnapshotBuilder)->build($keys, self::at($nowUtc), $sel);
    }

    /** @param  list<string>  $keys */
    protected function pass(array $keys, string $nowUtc): FactPass
    {
        return new FactPass(self::at($nowUtc), (new SubjectResolver)->forKeys($keys));
    }

    /** @param  list<string>  $facts */
    protected static function only(array $facts, bool $contacts = false, bool $policy = false, array $events = []): FactSelection
    {
        return new FactSelection($facts, $events, $contacts, $policy, false);
    }
}
