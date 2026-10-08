<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESO DE EMPLEADO: entrar al gimnasio sin comprar un plan.
 *
 * EL PROBLEMA. Para que un empleado pudiera entrenar había que venderle un
 * plan. Eso es un cobro que nadie cobra —entra en las cifras como si alguien
 * hubiera pagado— y, peor, no se acaba cuando se acaba el trabajo: el día que
 * renuncia sigue teniendo una membresía vigente, con su fecha de vencimiento,
 * y hay que acordarse de ir a quitársela. Un permiso laboral estaba escrito
 * como si fuera una venta.
 *
 * LO QUE ESTO CAMBIA. El acceso del empleado es una COSA APARTE de la
 * membresía: no tiene precio, no toca caja, no aparece como ingreso y se corta
 * con un interruptor el día que la persona deja de trabajar aquí. La fila no se
 * borra: queda con quién lo concedió, quién lo cortó y cuándo, porque «¿desde
 * cuándo entra este señor gratis?» es una pregunta que alguien va a hacer.
 *
 * LAS HORAS SON LAS MISMAS QUE LAS DE LOS PLANES. `access_days` y
 * `access_windows` tienen aquí el formato EXACTO de las columnas homónimas de
 * `plans`, y las lee la misma clase —PlanAccessRules—, así que «solo de 2 a 5»
 * significa lo mismo para un plan por horas valle que para el empleado que solo
 * puede entrenar fuera de su turno. Vacío significa sin restricción.
 *
 * Y CONVIVE CON UNA MEMBRESÍA PAGADA. Un entrenador puede ser empleado Y socio:
 * paga su mensualidad y entrena a cualquier hora, y además tiene su acceso de
 * empleado. Son dos puertas distintas y basta con que una esté abierta; por eso
 * esto es una tabla aparte y no un estado del socio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_accesses', function (Blueprint $table): void {
            $table->id();
            // Uno por persona: el acceso se edita, no se acumula. El historial
            // de quién lo dio y quién lo cortó vive en las columnas de abajo.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // «Entrenador», «Recepción», «Aseo». Solo informativo: no concede
            // nada por sí mismo, pero es lo que se lee en la ficha.
            $table->string('position', 60)->nullable();

            // EL INTERRUPTOR. Renunció: se apaga y deja de entrar hoy mismo.
            $table->boolean('active')->default(true);

            // Vigencia opcional del permiso. `ends_on` es para el contrato con
            // fecha conocida —un practicante de tres meses—: cuando llega, deja
            // de abrir sin que nadie tenga que acordarse.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Mismo formato que en `plans`. Null = sin restricción.
            $table->json('access_days')->nullable();
            $table->json('access_windows')->nullable();

            $table->string('note', 255)->nullable();

            // Quién lo concedió y quién lo cortó. Un acceso gratuito sin
            // responsable es exactamente lo que no se puede auditar.
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('granted_by_name', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name', 120)->nullable();

            $table->timestamps();

            // La puerta pregunta «¿quiénes entran gratis hoy?» en cada
            // sincronización del terminal.
            $table->index(['active', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_accesses');
    }
};
