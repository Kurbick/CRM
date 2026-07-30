<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('billing_occurrence_key', 64)
                ->nullable()
                ->unique()
                ->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropUnique(['billing_occurrence_key']);
            $table->dropColumn('billing_occurrence_key');
        });
    }
};
