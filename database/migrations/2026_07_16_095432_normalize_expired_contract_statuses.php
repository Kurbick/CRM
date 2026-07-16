<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Истечение теперь определяется автоматически по end_date.
        DB::table('contracts')
            ->where('status', 'expired')
            ->update([
                'status' => 'active',
            ]);
    }

    public function down(): void
    {
        DB::table('contracts')
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update([
                'status' => 'expired',
            ]);
    }
};
