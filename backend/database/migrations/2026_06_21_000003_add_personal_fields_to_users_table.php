<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos personales del miembro que el CRM ya recolectaba en el modal de "Crear
 * miembro" pero que NO se guardaban (se descartaban en el backend). Ahora el
 * modal queda como un registro de usuario/identidad y estos campos se persisten.
 *
 * La membresía/plan NO vive aquí: se otorga exclusivamente con pagos.
 */
return new class extends Migration
{
    private const COLUMNS = ['birth_date', 'gender', 'address', 'emergency_contact', 'notes'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 30)->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->string('address', 255)->nullable()->after('gender');
            }
            if (! Schema::hasColumn('users', 'emergency_contact')) {
                $table->string('emergency_contact', 255)->nullable()->after('address');
            }
            if (! Schema::hasColumn('users', 'notes')) {
                $table->text('notes')->nullable()->after('emergency_contact');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (self::COLUMNS as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
