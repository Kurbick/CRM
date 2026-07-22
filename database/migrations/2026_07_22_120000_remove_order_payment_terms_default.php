<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE orders MODIFY payment_terms SMALLINT UNSIGNED NOT NULL'
            );

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')->change();
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE orders MODIFY payment_terms SMALLINT UNSIGNED NOT NULL DEFAULT 14'
            );

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')->default(14)->change();
        });
    }
};
