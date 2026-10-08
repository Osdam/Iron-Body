<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL PISO DE LOS ENTRENADORES: lo que el gimnasio cobra por cada cliente
 * personalizado que se atiende dentro.
 *
 * CÓMO ESTABA ESCRITO ANTES. Como un PLAN llamado «Comisión de personalizados»,
 * con treinta días de duración. Y un plan, en este sistema, define la membresía
 * de quien lo tiene: por eso hay socios cuya vigencia la fija una comisión, y
 * por eso aparecieron membresías vencidas en 2027. El cobro no era un plan —no
 * da acceso a nada, no se renueva como una membresía— pero era la única forma
 * de registrarlo. Esto es su sitio propio.
 *
 * DOS TABLAS PORQUE SON DOS COSAS CON VIDAS DISTINTAS
 *
 *   El ACUERDO es duradero: «por Ana Ruiz, Carlos paga 50.000 al mes, y lo
 *   asume la clienta». Se pacta una vez y se edita cuando cambia el trato.
 *
 *   El COBRO es de un mes concreto: «el piso de octubre de ese acuerdo». Es lo
 *   que permite contestar «¿quién no ha pagado este mes?», que es la pregunta
 *   que de verdad se hace en el mostrador, y guardar el importe CONGELADO: si
 *   el mes que viene se renegocia a 60.000, octubre siguió siendo de 50.000.
 *
 * EL DINERO NO SE INVENTA UN LIBRO NUEVO. Cada cobro abre una cuenta por cobrar
 * de la caja del GIMNASIO, que es el mecanismo que ya existe: tiene abonos,
 * anulación con motivo, plazos y aparece en Pagos y en los informes del
 * gimnasio sin tocar nada. Aquí solo se guarda a qué mes y a qué acuerdo
 * pertenece esa deuda.
 *
 * NADA SE GENERA SOLO. El panel del mes enseña a quién le falta y se cobra con
 * un clic; no se abren deudas automáticas a nombre de nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_commission_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            // El cliente. Es `members` —no `users`— porque es la tabla con la
            // que ya trabajan la asignación de entrenador y el directorio de
            // deudores, y tener dos identidades del mismo cliente en el mismo
            // cobro es exactamente como se descuadra un histórico.
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // El valor es del ACUERDO, no del sistema: 50.000 es lo habitual,
            // no una regla. Cada trato se pacta aparte.
            $table->decimal('amount', 12, 2);

            // Quién lo asume: 'trainer' o 'member'. Se decide entre ellos, y
            // cambia a quién se le reclama, así que viaja en el acuerdo y se
            // congela en cada cobro.
            $table->string('payer', 10);

            $table->boolean('active')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('notes', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by_name', 120)->nullable();

            $table->timestamps();

            // Un mismo entrenador y un mismo cliente no pactan dos pisos a la
            // vez. Si cambia el trato se edita el acuerdo; si se acaba, se
            // cierra y queda en el histórico.
            $table->unique(['trainer_id', 'member_id']);
            $table->index(['active', 'trainer_id']);
        });

        Schema::create('trainer_commission_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')
                ->constrained('trainer_commission_agreements')
                ->cascadeOnDelete();

            // El mes, siempre como su día 1. Una columna de fecha y no un texto
            // «2026-10»: se ordena, se compara y se filtra por rango sin que
            // nadie tenga que acordarse del formato.
            $table->date('period');

            // CONGELADOS. El acuerdo puede cambiar mañana; lo que se cobró en
            // octubre no.
            $table->decimal('amount', 12, 2);
            $table->string('payer', 10);
            $table->string('debtor_type', 12);
            $table->unsignedBigInteger('debtor_id');

            // La deuda donde vive el dinero. Es la que tiene los abonos, el
            // plazo y la anulación; aquí no se duplica ningún saldo.
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();

            // Un acuerdo cobra UNA vez cada mes. Es lo que impide cobrarle dos
            // veces octubre a la misma persona por un doble clic o por dos
            // recepcionistas a la vez.
            $table->unique(['agreement_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_commission_charges');
        Schema::dropIfExists('trainer_commission_agreements');
    }
};
