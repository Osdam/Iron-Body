<?php
/*
 * Datos SINTÉTICOS de una «persona» (Zoila Pérez Sintética, +57 300 999 0777) repartidos por el Studio del banco, para
 * ensayar supresion-1581.sql con el modelo de roles real. Se ejecuta con la aplicación del banco (rol h5_app, el de la
 * aplicación: solo INSERT en las tablas de solo inserción), como lo dejaría el uso normal:
 *   · una entrada de Legacy de la cadena FIRMADA con la clave del banco (como la escribe el Studio) y su motivo;
 *   · eventos del libro (resumen y diff con el nombre y el teléfono; el diff con la tilde escapada é, como lo
 *     guarda json_encode) y uno del personal (por admin_id) con IP y navegador;
 *   · una instantánea reconocida por una persona con una nota;
 *   · un número canario REVOCADO con alias y su huella calculada con PhoneFingerprint::of (la vía del runbook) y otro
 *     canario ACTIVO de otro teléfono (la supresión por huella de un vivo tiene que abortar);
 *   · un evento del laboratorio.
 * Uso: php sembrar-1581.php <directorio backend del banco> <id de admin del personal>   (imprime solo recuentos)
 */
declare(strict_types=1);

[$_, $dir, $staff] = $argv + [null, null, null];
(is_string($dir) && is_dir($dir) && ctype_digit((string) $staff)) || exit("uso: php sembrar-1581.php <backend> <admin_id>\n");
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$staff = (int) $staff;
$ahora = now();
$nombre = 'Zoila Pérez Sintética';

// 1. La cadena: una vuelta a Legacy firmada (seq = cabeza + 1, apuntando a la cabeza: el disparador del H7 lo exige).
$cabeza = DB::table('ultron_studio_publications')->orderByDesc('seq')->first(['id', 'seq']);
$seq = ($cabeza->seq ?? 0) + 1;
$uuid = strtolower((string) Str::uuid());
$d = D::declaration($uuid, $seq, $ahora, null, null, UltronPublishedConfig::signatureFor(...));
DB::table('ultron_studio_publications')->insert([
    'uuid' => $uuid, 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => D::ACTION_LEGACY,
    'version_id' => null, 'restores_publication_id' => null, 'previous_publication_id' => $cabeza->id ?? null,
    'engine_contract' => $d['contract'], 'effective_json' => $d['effectiveJson'], 'effective_hash' => $d['effectiveHash'],
    'signature' => $d['signature'], 'catalog_version' => '2026-10-19.1', 'lab_gate' => json_encode(['mode' => null]),
    'acknowledged' => null, 'evidence' => null,
    'reason' => "Vuelta a Legacy: lo pidió {$nombre} por teléfono", 'actor_admin_id' => $staff,
    'actor_name' => 'Ana Operadora', 'actor_role' => 'Super Admin', 'created_at' => $ahora,
]);

// 2. El libro: lo que dejaría editar una regla con el dato dentro (el diff como lo guarda json_encode: é).
$base = ['subject_uuid' => null, 'actor_role' => 'Super Admin', 'from_status' => null, 'to_status' => null, 'content_hash' => null, 'metadata' => null, 'created_at' => $ahora];
DB::table('ultron_studio_audit_events')->insert([
    $base + ['event' => 'rule.updated', 'subject_type' => 'rule', 'actor_type' => 'admin', 'actor_admin_id' => $staff, 'actor_name' => 'Ana Operadora',
        'summary' => "Regla editada: avisar a {$nombre} al 300 999 0777", 'diff' => json_encode(['before' => ['description' => "Llamar a {$nombre}"], 'after' => ['description' => 'sin datos']]),
        'ip_address' => '10.20.30.40', 'user_agent' => 'Mozilla/5.0 (banco)'],
    $base + ['event' => 'draft.saved', 'subject_type' => 'draft', 'actor_type' => 'admin', 'actor_admin_id' => $staff, 'actor_name' => 'Ana Operadora',
        'summary' => 'Borrador guardado', 'diff' => json_encode(['nota' => '+57 300-999-0777 (contacto)']), 'ip_address' => '10.20.30.40', 'user_agent' => 'Mozilla/5.0 (banco)'],
    $base + ['event' => 'rule.created', 'subject_type' => 'rule', 'actor_type' => 'admin', 'actor_admin_id' => $staff + 1000, 'actor_name' => 'Otra Persona',
        'summary' => 'Regla sin datos', 'diff' => json_encode(['a' => 1]), 'ip_address' => null, 'user_agent' => null],
]);

// 3. Una instantánea reconocida por una persona, con nota.
DB::table('ultron_studio_integration_snapshots')->insert([
    'uuid' => (string) Str::uuid(), 'provider' => 'twilio', 'kind' => 'config', 'fingerprint' => hash('sha256', 'banco-1581'),
    'facts' => json_encode(['banco' => true]), 'outcome' => null, 'actor_type' => 'admin', 'actor_admin_id' => $staff,
    'actor_name' => 'Ana Operadora', 'actor_role' => 'Super Admin', 'note' => "Reconocido tras hablar con {$nombre}", 'created_at' => $ahora,
]);

// 4. Canarios: el de la persona, REVOCADO (alias y huella), y uno vivo de otro teléfono.
$fila = static fn (string $e164, string $alias, string $estado): array => [
    'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'alias' => $alias,
    'phone_hash' => PhoneFingerprint::of($e164), 'phone_last4' => substr($e164, -4), 'country_code' => '57', 'phone_encrypted' => null,
    'status' => $estado, 'expected_version_id' => null, 'consent_attested' => true,
    'registered_by_admin_id' => $staff, 'registered_by_name' => 'Ana Operadora', 'registered_by_role' => 'Super Admin',
    'authorized_by_admin_id' => $estado === 'revoked' ? null : $staff + 1000, 'authorized_by_name' => $estado === 'revoked' ? null : 'Otra Persona',
    'authorized_by_role' => null, 'authorized_at' => $estado === 'revoked' ? null : now(),
    'activated_by_admin_id' => $estado === 'active' ? $staff + 1000 : null, 'activated_by_name' => $estado === 'active' ? 'Otra Persona' : null,
    'activated_by_role' => null, 'activated_at' => $estado === 'active' ? now() : null,
    'revoked_by_admin_id' => $estado === 'revoked' ? $staff + 1000 : null, 'revoked_by_name' => $estado === 'revoked' ? 'Otra Persona' : null,
    'revoked_by_role' => null, 'revoked_at' => $estado === 'revoked' ? now() : null,
    'revoke_reason' => $estado === 'revoked' ? 'Ya no participa en el canario' : null,
    'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
];
DB::table('ultron_studio_canary_numbers')->insert($fila('+573009990777', 'Móvil de Zoila Pérez', 'revoked'));
DB::table('ultron_studio_canary_numbers')->insert($fila('+573009990888', 'Canario vivo banco', 'active'));

// 5. El laboratorio: un evento con el nombre.
DB::table('ultron_studio_lab_events')->insert([
    'event' => 'scenario.updated', 'subject_type' => 'scenario', 'subject_uuid' => null, 'actor_type' => 'admin', 'actor_admin_id' => $staff,
    'actor_name' => 'Ana Operadora', 'actor_role' => 'Super Admin', 'from_status' => null, 'to_status' => null,
    'summary' => "Escenario retocado con el caso de {$nombre}", 'diff' => null, 'created_at' => $ahora,
]);

echo 'sembrado: cadena 1 · libro 3 · instantánea 1 · canarios 2 (1 revocado, 1 activo) · laboratorio 1', PHP_EOL;
