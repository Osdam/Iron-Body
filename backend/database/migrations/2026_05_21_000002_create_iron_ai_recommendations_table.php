<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            // type: routine | nutrition | progress | reminder | motivation
            //       | membership | class | risk
            $table->string('type');
            $table->string('title');
            $table->text('message');
            // status: pending | sent | read | dismissed
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('member_id');
            $table->index(['member_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_recommendations');
    }
};
