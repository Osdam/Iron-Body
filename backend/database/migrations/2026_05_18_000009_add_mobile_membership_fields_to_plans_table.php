<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'original_price')) {
                $table->decimal('original_price', 10, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('plans', 'is_recommended')) {
                $table->boolean('is_recommended')->default(false)->after('benefits');
            }
            if (! Schema::hasColumn('plans', 'badge')) {
                $table->string('badge', 80)->nullable()->after('is_recommended');
            }
            if (! Schema::hasColumn('plans', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('badge');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            foreach (['sort_order', 'badge', 'is_recommended', 'original_price'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
