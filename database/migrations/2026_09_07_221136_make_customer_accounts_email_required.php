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
        DB::table('customer_accounts')->whereNull('email')->get(['id'])->each(function ($row) {
            DB::table('customer_accounts')->where('id', $row->id)->update([
                'email' => "customer{$row->id}@placeholder.local",
            ]);
        });

        DB::statement('ALTER TABLE customer_accounts ALTER COLUMN email SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE customer_accounts ALTER COLUMN email DROP NOT NULL');
    }
};
