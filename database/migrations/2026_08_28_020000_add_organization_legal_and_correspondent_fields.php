<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'legal_name')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->string('legal_name', 255)->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('organizations', 'bank_correspondent_account')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->string('bank_correspondent_account', 100)->nullable()->after('iban');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'bank_correspondent_account')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('bank_correspondent_account');
            });
        }

        if (Schema::hasColumn('organizations', 'legal_name')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('legal_name');
            });
        }
    }
};
