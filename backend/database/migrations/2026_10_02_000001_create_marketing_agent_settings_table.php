<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El interruptor global del agente IA de Mercadeo: activo o en pausa.
 *
 * Una sola fila (id 1), sembrada aquí y activa: crear la tabla no cambia nada
 * de lo que hace el sistema hoy. Vive en la base y no en .env ni en caché por
 * la misma razón que el freno de ULTRON (`ultron_aborts`): los doce workers ven
 * la misma fila sin `queue:restart`, y ningún reinicio ni `cache:clear` puede
 * reactivar un agente que alguien pausó.
 *
 * `paused_at` y `resumed_at` son la ÚLTIMA pausa y la ÚLTIMA reactivación, y no
 * se borran: con las dos se sabe si el agente estuvo pausado en algún momento
 * desde que empezó un turno, y su respuesta no sale tarde aunque para entonces
 * el agente ya esté activo otra vez.
 *
 * `version` sube en cada cambio real: el canal SSE la compara para avisar al
 * CRM sin depender del orden de los ids de auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_agent_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('paused')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('changed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Instantánea: sigue diciendo quién fue aunque la cuenta cambie de nombre.
            $table->string('changed_by_name', 120)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::table('marketing_agent_settings')->insert([
            'id' => 1,
            'paused' => false,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_agent_settings');
    }
};
