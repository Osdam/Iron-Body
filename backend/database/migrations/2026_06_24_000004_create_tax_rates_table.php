<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de impuestos por concepto (IVA 19/5/0, excluido, exento). Se mapea
 * al tributo correspondiente de Factus (`factus_tribute_id`). Planes y productos
 * apuntan a una tarifa; el InvoiceDtoBuilder calcula base/IVA según ella.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();      // IVA_19 | IVA_5 | IVA_0 | EXCLUDED | EXEMPT
            $table->string('name');
            $table->decimal('rate', 5, 2)->default(0); // porcentaje
            $table->string('factus_tribute_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
