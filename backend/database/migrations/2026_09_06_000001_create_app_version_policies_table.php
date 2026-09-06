<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política de versión mínima por plataforma.
 *
 * Vive en BD y no en `config/` porque su razón de ser es cambiarla SIN
 * desplegar: cuando una tienda aprueba una versión hay que subir el número el
 * mismo día, y esperar a un deploy del backend para eso es exactamente lo que
 * hace que nadie lo actualice nunca.
 *
 * Una fila por plataforma: Play y App Store aprueban en momentos distintos y
 * atarlas al mismo número obligaría a bloquear a los usuarios de una tienda por
 * los tiempos de la otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_version_policies', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->unique(); // android | ios

            // Última versión publicada en la tienda. Por debajo de esto se
            // ofrece actualizar.
            $table->unsignedInteger('latest_build');
            $table->string('latest_version', 32);

            // Mínima que la app puede seguir usando. Por debajo se exige
            // actualizar. NUNCA debe superar a una versión que no esté ya
            // disponible públicamente en la tienda.
            $table->unsignedInteger('minimum_build');

            $table->string('store_url', 512);

            // Texto opcional para la pantalla de actualización. Si es null la
            // app usa el suyo.
            $table->string('message', 255)->nullable();

            $table->foreignId('updated_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Estado inicial INOCUO: `minimum_build` = 1 para que ninguna versión
        // instalada quede bloqueada al desplegar esto, y `latest_build` = la
        // que hay hoy en las tiendas, de modo que nadie vea tampoco un aviso
        // opcional. La infraestructura entra en producción sin efecto visible.
        $now = now();
        \Illuminate\Support\Facades\DB::table('app_version_policies')->insert([
            [
                'platform' => 'android',
                'latest_build' => 10,
                'latest_version' => '2.0.1',
                'minimum_build' => 1,
                'store_url' => 'https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout',
                'message' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'platform' => 'ios',
                'latest_build' => 10,
                'latest_version' => '2.0.1',
                'minimum_build' => 1,
                'store_url' => 'https://apps.apple.com/co/app/id6792374138',
                'message' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_version_policies');
    }
};
