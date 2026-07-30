<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('custom_interval_value')
                ->nullable()
                ->after('billing_period');
            $table->enum('custom_interval_unit', ['day', 'month', 'year'])
                ->nullable()
                ->after('custom_interval_value');
        });

        // Existing custom rows intentionally remain null and must be repaired
        // manually before they can participate in new billing occurrences.
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['custom_interval_value', 'custom_interval_unit']);
        });
    }
};
