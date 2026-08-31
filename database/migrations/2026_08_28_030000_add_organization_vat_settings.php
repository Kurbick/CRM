<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'is_vat_payer')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->boolean('is_vat_payer')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasColumn('organizations', 'vat_rate')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('is_vat_payer');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'vat_rate')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('vat_rate');
            });
        }

        if (Schema::hasColumn('organizations', 'is_vat_payer')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('is_vat_payer');
            });
        }
    }
};
