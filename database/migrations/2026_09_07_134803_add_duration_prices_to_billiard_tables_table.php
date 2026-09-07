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
        Schema::table('billiard_tables', function (Blueprint $table) {
            // Optional flat-package prices for whole-hour durations, e.g.
            // {"1": 60000, "2": 110000, "3": 160000} - keyed by hour count as
            // a string (JSON object keys are always strings). A duration with
            // no entry here falls back to hourly_rate * hours.
            $table->json('duration_prices')->nullable()->after('hourly_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billiard_tables', function (Blueprint $table) {
            $table->dropColumn('duration_prices');
        });
    }
};
