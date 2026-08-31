<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'subtotal_amount')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->decimal('subtotal_amount', 10, 2)->nullable()->after('total_amount');
            });
        }

        if (! Schema::hasColumn('invoices', 'vat_enabled')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->boolean('vat_enabled')->default(false)->after('subtotal_amount');
            });
        }

        if (! Schema::hasColumn('invoices', 'vat_rate')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_enabled');
            });
        }

        if (! Schema::hasColumn('invoices', 'vat_amount')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->decimal('vat_amount', 10, 2)->nullable()->after('vat_rate');
            });
        }

        DB::table('invoices')->whereNull('subtotal_amount')->update([
            'subtotal_amount' => DB::raw('COALESCE(subtotal_amount, total_amount)'),
            'vat_enabled' => false,
            'vat_rate' => null,
            'vat_amount' => DB::raw('COALESCE(vat_amount, 0.00)'),
        ]);

        DB::table('invoices')->whereNull('vat_amount')->update(['vat_amount' => '0.00']);
    }

    public function down(): void
    {
        foreach (['vat_amount', 'vat_rate', 'vat_enabled', 'subtotal_amount'] as $column) {
            if (Schema::hasColumn('invoices', $column)) {
                Schema::table('invoices', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
