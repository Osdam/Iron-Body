<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula una cuenta del CRM con un entrenador concreto.
 *
 * EL HUECO QUE CIERRA. `member_trainer_assignments` apunta a `trainers.id`, y
 * `admins` no tenía forma de llegar hasta ahí: ni a `trainers` ni a
 * `identities`. Una cuenta con rol Entrenador no se podía resolver a un perfil
 * profesional, así que «sus socios asignados» no tenía por dónde filtrarse y
 * `members.view` alcanzaba a TODOS los socios del gimnasio.
 *
 * POR QUÉ A `trainers` Y NO A `identities`. Sería lo conceptualmente más
 * limpio —la identidad es la persona— pero `Identity::trainers()` es `hasMany`:
 * una identidad puede tener varios perfiles profesionales, y entonces no
 * quedaría claro CUÁL de ellos representa la cuenta. La pregunta que hay que
 * responder aquí es «¿de qué entrenador son estas asignaciones?», y esa la
 * contesta `trainers.id` sin ambigüedad.
 *
 * NULLABLE. Las ocho cuentas que ya existen no son entrenadores y no tienen a
 * quién apuntar. Que sea obligatorio lo exige la VALIDACIÓN cuando el rol es
 * Entrenador ({@see AdminUserController}), no el esquema: una columna NOT NULL
 * habría que rellenarla para Recepción y Super Admin, que es justo lo contrario
 * de lo que significa.
 *
 * ÚNICO. Un entrenador es una persona y su cuenta del CRM es su forma de
 * entrar: dos cuentas para el mismo entrenador serían dos sesiones con el mismo
 * alcance y sin manera de saber cuál usó cada quien. En producción hoy no hay
 * ninguna cuenta vinculada, así que no hay caso real que lo contradiga. Los
 * NULL no colisionan entre sí ni en PostgreSQL —el motor de producción— ni en
 * SQLite, que es el de las pruebas, de modo que el resto de roles no se ve
 * afectado. Si algún día un entrenador necesitara dos cuentas, quitar un índice
 * único es una migración; recuperar la traza perdida por no tenerlo, no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->foreignId('trainer_id')
                ->nullable()
                ->after('role')
                // Si se da de baja el perfil profesional, la cuenta no
                // desaparece: se queda sin vincular, y sin vínculo no ve a
                // ningún socio. Es el fallo seguro.
                ->constrained('trainers')
                ->nullOnDelete();

            $table->unique('trainer_id');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropUnique(['trainer_id']);
            $table->dropConstrainedForeignId('trainer_id');
        });
    }
};
