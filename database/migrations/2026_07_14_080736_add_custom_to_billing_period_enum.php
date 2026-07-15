<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY billing_period ENUM('monthly','quarterly','semiannual','annual','custom') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY billing_period ENUM('monthly','quarterly','semiannual','annual') NOT NULL");
    }
};