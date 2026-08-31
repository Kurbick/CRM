<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_organization_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('last_organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_organization_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['last_organization_id']);
                $table->dropColumn('last_organization_id');
            });
        }
    }
};
