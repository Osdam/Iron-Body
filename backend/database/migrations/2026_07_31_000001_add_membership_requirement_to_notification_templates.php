<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca qué plantillas dan por hecho que el socio puede entrar al gimnasio.
 *
 * «Bebe agua durante el entrenamiento» no le sirve a quien tiene la membresía
 * vencida: le habla de algo que hoy no puede hacer. A esa persona le
 * corresponde el tono de reactivación, no el de rutina.
 *
 * El valor por defecto es `true` a propósito: una plantilla nueva que nadie
 * clasifique llegará solo a quien está al día, que es el error barato. Al
 * revés —mandarle a un socio vencido que revise su rutina— es el caro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_templates', 'requires_active_membership')) {
                $table->boolean('requires_active_membership')->default(true)->after('is_seeded');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_templates', 'requires_active_membership')) {
            Schema::table('notification_templates', function (Blueprint $table): void {
                $table->dropColumn('requires_active_membership');
            });
        }
    }
};
