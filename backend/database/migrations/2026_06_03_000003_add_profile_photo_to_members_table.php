<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('members')) {
            return;
        }
        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'profile_photo_url')) {
                // URL de descarga (Firebase Storage) de la foto de perfil. Es un
                // dato de PERFIL, no identificación biométrica.
                $table->text('profile_photo_url')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('members', 'profile_photo_path')) {
                // Ruta del objeto en el bucket (para reemplazar/limpiar).
                $table->string('profile_photo_path')->nullable()->after('profile_photo_url');
            }
            if (! Schema::hasColumn('members', 'profile_photo_updated_at')) {
                // Cambia al subir una foto nueva: sirve para invalidar caché de
                // imagen aunque la URL base sea la misma.
                $table->timestamp('profile_photo_updated_at')->nullable()->after('profile_photo_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('members')) {
            return;
        }
        Schema::table('members', function (Blueprint $table) {
            foreach (['profile_photo_url', 'profile_photo_path', 'profile_photo_updated_at'] as $col) {
                if (Schema::hasColumn('members', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
