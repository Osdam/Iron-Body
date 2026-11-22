<?php

namespace Tests\Feature\Outreach\Schema;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Filas válidas del libro de autorizaciones de contacto (C9-1,
 * `outreach_consents`) y LA CONSULTA DE LA TANDA de referencia para el lector
 * (C9-2). Todo con el constructor de consultas, nunca con el modelo: lo que se
 * prueba es la base. Ningún teléfono: el número es su seudónimo (un sha256 de
 * una etiqueta, con la forma de `PhoneRef`).
 *
 * Cuentas del personal: Ana (1) registra, Beto (2) confirma, Caro (3) es una
 * tercera. Se usa junto a {@see OutreachSchemaFixtures} (sus aserciones y el
 * rol de la aplicación).
 */
trait OutreachConsentFixtures
{
    private const CONSENT_MIGRATION = '2026_11_22_100001_create_outreach_consents_table';

    private const T_CONSENTS = 'outreach_consents';

    private const STAFF = [1 => ['Ana', 'recepcion'], 2 => ['Beto', 'coordinacion'], 3 => ['Caro', 'recepcion']];

    /**
     * El último evento por persona, finalidad y canal, con `revoked` ganando,
     * para una TANDA de personas (cada una con una o varias claves: su `m:` y
     * sus `l:`). Un `granted` cuenta en el lugar de SU PETICIÓN (`pos`): lo que
     * se pidió antes de una retirada no revive por confirmarse después. Las
     * filas redactadas no casan (su clave es `x:<id>`). Sin filas para una
     * finalidad: ninguna autorización. SQL de los dos motores (VALUES + ventana;
     * sin DISTINCT ON ni unnest). `{values}` = `(?, ?), (?, ?), …`: persona, clave.
     */
    private const CONSENT_BATCH_SQL = <<<'SQL'
        WITH s (person, subject_key) AS (VALUES {values}),
        ev AS (
            SELECT s.person, c.purpose, c.channel, c.kind, c.phone_ref, c.id,
                   CASE WHEN c.kind = 'granted' THEN r.id ELSE c.id END AS pos
              FROM s
              JOIN outreach_consents c ON c.subject_key = s.subject_key
              LEFT JOIN outreach_consents r ON c.kind = 'granted' AND r.uuid = c.request_uuid
             WHERE c.channel = 'whatsapp' AND c.kind IN ('granted', 'revoked')
        ),
        ranked AS (
            SELECT person, purpose, channel, kind, phone_ref, id,
                   ROW_NUMBER() OVER (PARTITION BY person, purpose, channel
                                      ORDER BY pos DESC, CASE WHEN kind = 'revoked' THEN 0 ELSE 1 END) AS n
              FROM ev
        )
        SELECT person, purpose, channel, kind, phone_ref, id FROM ranked WHERE n = 1 ORDER BY person, purpose, channel
        SQL;

    private int $consentSeq = 0;

    private function consentMigration(): object
    {
        return $this->migrationNamed(self::CONSENT_MIGRATION);
    }

    private function phoneRefOf(string $etiqueta): string
    {
        return hash('sha256', 'c9-phone-'.$etiqueta);
    }

    private function legalHashOf(string $texto): string
    {
        return hash('sha256', $texto);
    }

    /** @param  array<string, mixed>  $o */
    private function consentRequestRow(array $o = []): array
    {
        $admin = (int) ($o['recorded_by_admin_id'] ?? 1);
        [$nombre, $rol] = self::STAFF[$admin] ?? ['Otra cuenta', null];
        $member = array_key_exists('member_id', $o) ? $o['member_id'] : 7001;

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'subject_key' => $member === null ? 'l:'.($o['lead_id'] ?? 0) : 'm:'.$member,
            'member_id' => $member,
            'lead_id' => null,
            'purpose' => 'commercial',
            'channel' => 'whatsapp',
            'kind' => 'requested',
            'request_uuid' => null,
            'source' => 'reception_form',
            'evidence' => 'Formulario firmado en recepción, carpeta de noviembre',
            'legal_version' => 'c9-whatsapp-comercial/2026-11-22.1',
            'legal_hash' => $this->legalHashOf('Autorizo a Iron Body a escribirme por WhatsApp con ofertas.'),
            'phone_ref' => $this->phoneRefOf('A'),
            'actor_type' => 'admin',
            'recorded_by_admin_id' => $admin,
            'recorded_by_name' => $nombre,
            'recorded_by_role' => $rol,
            'confirmed_by_admin_id' => null,
            'confirmed_by_name' => null,
            'confirmed_by_role' => null,
            'redacted_at' => null,
            'created_at' => '2026-11-22 15:00:'.str_pad((string) (++$this->consentSeq % 60), 2, '0', STR_PAD_LEFT),
        ], $o);
    }

    /**
     * La resolución (granted | rejected) de una petición: copia lo que la base
     * exige que copie; resuelve Beto (2).
     *
     * @param  array<string, mixed>  $peticion
     * @param  array<string, mixed>  $o
     */
    private function consentResolutionRow(array $peticion, string $kind = 'granted', array $o = []): array
    {
        $admin = (int) ($o['confirmed_by_admin_id'] ?? 2);
        [$nombre, $rol] = self::STAFF[$admin] ?? ['Otra cuenta', null];
        $copia = array_intersect_key($peticion, array_flip([
            'subject_key', 'member_id', 'lead_id', 'purpose', 'channel', 'source', 'actor_type', 'recorded_by_admin_id', 'recorded_by_name', 'recorded_by_role',
        ]));
        $concedida = $kind === 'granted'
            ? array_intersect_key($peticion, array_flip(['phone_ref', 'legal_version', 'legal_hash']))
            : ['phone_ref' => null, 'legal_version' => null, 'legal_hash' => null];

        return array_merge($copia, $concedida, [
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'kind' => $kind,
            'request_uuid' => $peticion['uuid'],
            'evidence' => $kind === 'granted' ? 'Verificado contra el formulario físico' : 'La firma no coincide con la del contrato',
            'confirmed_by_admin_id' => $admin,
            'confirmed_by_name' => $nombre,
            'confirmed_by_role' => $rol,
            'redacted_at' => null,
            'created_at' => '2026-11-22 15:10:'.str_pad((string) (++$this->consentSeq % 60), 2, '0', STR_PAD_LEFT),
        ], $o);
    }

    /** La retirada: sin petición, sin número, sin texto legal; por defecto la registra el sistema (la palabra de baja). */
    private function consentRevocationRow(array $o = []): array
    {
        $member = array_key_exists('member_id', $o) ? $o['member_id'] : 7001;

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'subject_key' => $member === null ? 'l:'.($o['lead_id'] ?? 0) : 'm:'.$member,
            'member_id' => $member,
            'lead_id' => null,
            'purpose' => 'commercial',
            'channel' => 'whatsapp',
            'kind' => 'revoked',
            'request_uuid' => null,
            'source' => 'member_request',
            'evidence' => 'Respondió BAJA al último mensaje',
            'legal_version' => null,
            'legal_hash' => null,
            'phone_ref' => null,
            'actor_type' => 'system',
            'recorded_by_admin_id' => null,
            'recorded_by_name' => 'Sistema (palabra de baja)',
            'recorded_by_role' => null,
            'confirmed_by_admin_id' => null,
            'confirmed_by_name' => null,
            'confirmed_by_role' => null,
            'redacted_at' => null,
            'created_at' => '2026-11-22 15:20:'.str_pad((string) (++$this->consentSeq % 60), 2, '0', STR_PAD_LEFT),
        ], $o);
    }

    /** @param  array<string, mixed>  $fila  @return array<string, mixed> la fila con su id */
    private function insertConsent(array $fila): array
    {
        $fila['id'] = (int) $this->db()->table(self::T_CONSENTS)->insertGetId($fila);

        return $fila;
    }

    /** @param  array<string, mixed>  $o  @return array<string, mixed> */
    private function requestConsent(array $o = []): array
    {
        return $this->insertConsent($this->consentRequestRow($o));
    }

    /** @param  array<string, mixed>  $peticion  @return array<string, mixed> */
    private function resolveConsent(array $peticion, string $kind = 'granted', array $o = []): array
    {
        return $this->insertConsent($this->consentResolutionRow($peticion, $kind, $o));
    }

    /** @return array<string, mixed> */
    private function revokeConsent(array $o = []): array
    {
        return $this->insertConsent($this->consentRevocationRow($o));
    }

    /** La redacción 1581 de una persona, como la hace `ops/supresion-1581.sql`. */
    private function redactSubject(string $subjectKey): int
    {
        return $this->db()->update(
            'UPDATE outreach_consents SET subject_key = \'x:\' || id, member_id = NULL, lead_id = NULL, phone_ref = NULL, evidence = ?, redacted_at = ? '
            .'WHERE subject_key = ? AND redacted_at IS NULL',
            ['[suprimido]', '2026-11-22 18:00:00', $subjectKey],
        );
    }

    /**
     * La consulta de la tanda, ejecutada: persona → finalidad → [kind, phone_ref, id].
     *
     * @param  array<string, list<string>>  $personas  persona → sus claves
     * @return array<string, array<string, array{kind: string, phone_ref: ?string, id: int}>>
     */
    private function consentBatch(array $personas): array
    {
        $valores = [];
        $bindings = [];
        foreach ($personas as $persona => $claves) {
            foreach ($claves as $clave) {
                $valores[] = '(?, ?)';
                array_push($bindings, (string) $persona, $clave);
            }
        }
        $out = [];
        foreach ($this->db()->select(str_replace('{values}', implode(', ', $valores), self::CONSENT_BATCH_SQL), $bindings) as $f) {
            $out[$f->person][$f->purpose] = ['kind' => $f->kind, 'phone_ref' => $f->phone_ref === null ? null : trim((string) $f->phone_ref), 'id' => (int) $f->id];
        }

        return $out;
    }

    /**
     * `$fila` viola UNA regla: en PG su CHECK y solo ese (evaluando las
     * expresiones reales, porque PG nombra solo el primero) y el INSERT da
     * 23514 con su nombre; en SQLite, su disparador equivalente (23000, por su
     * motivo).
     *
     * @param  array<string, mixed>  $fila
     */
    private function assertConsentRule(array $fila, string $check, string $que = ''): void
    {
        if ($this->isPostgres()) {
            $this->assertSame([$check], $this->violatedChecks(self::T_CONSENTS, $fila), "{$que}: viola solo {$check}");
            $this->assertRejected($this->consentInsertClosure($fila), '23514', $check, $que);

            return;
        }
        $m = $this->consentMigration();
        $this->assertRejected($this->consentInsertClosure($fila), '23000', $m::message($m::checks()[$check][2], 'INSERT'), $que);
    }

    /** Una guarda de disparador (P0001 en PG, 23000 en SQLite), por su motivo. */
    private function assertConsentGuard(Closure $sentencia, string $motivo, string $operacion, string $que = ''): void
    {
        $m = $this->consentMigration();
        $this->assertGuarded($sentencia, $m::message($motivo, $operacion), $que);
    }

    private function consentReason(string $trigger): string
    {
        $m = $this->consentMigration();

        return ($m::rowTriggers()[$trigger] ?? null)[3] ?? $m::crossTriggers()[$trigger][3];
    }

    private function consentInsertClosure(array $fila): Closure
    {
        return fn () => $this->db()->table(self::T_CONSENTS)->insert($fila);
    }

    private function consentCount(): int
    {
        return (int) DB::connection($this->fixtureConnection)->table(self::T_CONSENTS)->count();
    }
}
