<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->unsignedBigInteger('seed');
            $table->unsignedInteger('completion_time_ms');
            $table->string('replay_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_scores');
    }
};
