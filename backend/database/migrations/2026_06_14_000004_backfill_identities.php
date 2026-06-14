<?php

use App\Services\Identity\IdentityLinkService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill idempotente de identidades para los datos existentes. Por cada
 * documento normalizado se crea UNA identidad y se enlazan todos los miembros y
 * entrenadores que comparten ese documento (misma persona = mismo documento
 * nacional). Filas sin documento normalizable reciben su propia identidad
 * dedicada (no se fusionan entre sí).
 *
 * La lógica vive en IdentityLinkService::backfillExisting() para poder
 * reejecutarse desde un comando de mantenimiento. Seguro de re-ejecutar: solo
 * procesa filas con `identity_id` aún nulo y reusa la identidad del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(IdentityLinkService::class)->backfillExisting();
    }

    public function down(): void
    {
        DB::table('members')->update(['identity_id' => null]);
        DB::table('trainers')->update(['identity_id' => null]);
        DB::table('identities')->delete();
    }
};
