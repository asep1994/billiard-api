<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings RENAME COLUMN reminder_sent_at TO reminder_1h_sent_at');
        DB::statement('ALTER TABLE bookings ADD COLUMN reminder_30m_sent_at TIMESTAMP NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP COLUMN reminder_30m_sent_at');
        DB::statement('ALTER TABLE bookings RENAME COLUMN reminder_1h_sent_at TO reminder_sent_at');
    }
};
