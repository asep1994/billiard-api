<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billiard_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['8_ball', '9_ball', 'snooker', 'carom'])->default('8_ball');
            $table->decimal('hourly_rate', 10, 2);
            $table->enum('status', ['available', 'maintenance', 'inactive'])->default('available');
            $table->timestamps();

            $table->unique(['venue_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billiard_tables');
    }
};
