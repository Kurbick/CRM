<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE credit_balance_entries
            MODIFY type ENUM(
                'top_up',
                'applied',
                'top_up_reversal',
                'applied_reversal'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        /*
         * При откате сохраняем математический смысл записей:
         * обратные операции превращаются в отрицательные
         * записи старых типов.
         */
        DB::table('credit_balance_entries')
            ->where('type', 'top_up_reversal')
            ->update([
                'type' => 'top_up',
                'amount' => DB::raw('-ABS(amount)'),
            ]);

        DB::table('credit_balance_entries')
            ->where('type', 'applied_reversal')
            ->update([
                'type' => 'applied',
                'amount' => DB::raw('-ABS(amount)'),
            ]);

        DB::statement("
            ALTER TABLE credit_balance_entries
            MODIFY type ENUM(
                'top_up',
                'applied'
            ) NOT NULL
        ");
    }
};
