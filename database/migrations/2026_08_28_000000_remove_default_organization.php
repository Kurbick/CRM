<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'is_default')) {
            return;
        }

        if ($this->hasIndex('organizations', 'organizations_active_default_index')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropIndex('organizations_active_default_index');
            });
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'is_default')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('is_active');
            });
        }

        if (! $this->hasIndex('organizations', 'organizations_active_default_index')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->index(['is_active', 'is_default'], 'organizations_active_default_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $definition): bool => ($definition['name'] ?? null) === $index,
        );
    }
};
