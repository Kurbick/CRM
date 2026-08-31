<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'is_active')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('invoice_number_code');
            });
        }
        if (! Schema::hasColumn('organizations', 'is_default')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('is_active');
            });
        }

        Schema::table('organizations', function (Blueprint $table): void {
            if ($this->hasIndex('organizations', 'organizations_singleton_key_unique')) {
                $table->dropUnique('organizations_singleton_key_unique');
            }
            $table->string('singleton_key')->nullable()->change();
            if (! $this->hasIndex('organizations', 'organizations_invoice_number_code_unique')) {
                $table->unique('invoice_number_code', 'organizations_invoice_number_code_unique');
            }
            if (! $this->hasIndex('organizations', 'organizations_active_default_index')) {
                $table->index(['is_active', 'is_default'], 'organizations_active_default_index');
            }
        });

        $organizationId = DB::table('organizations')->orderBy('id')->value('id');
        if ($organizationId !== null) {
            DB::table('organizations')->update(['is_default' => false]);
            DB::table('organizations')->where('id', $organizationId)->update([
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        if (! Schema::hasColumn('contracts', 'issuer_organization_id')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->foreignId('issuer_organization_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('organizations')
                    ->onDelete('restrict');
            });
        }
        if (! $this->hasIndex('contracts', 'contracts_issuer_organization_index')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->index('issuer_organization_id', 'contracts_issuer_organization_index');
            });
        }

        if (! Schema::hasColumn('credit_balances', 'organization_id')) {
            Schema::table('credit_balances', function (Blueprint $table): void {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('organizations')
                    ->onDelete('restrict');
            });
        }
        Schema::table('credit_balances', function (Blueprint $table): void {
            if (! $this->hasIndex('credit_balances', 'credit_balances_company_index')) {
                $table->index('company_id', 'credit_balances_company_index');
            }
            if ($this->hasIndex('credit_balances', 'credit_balances_company_id_unique')) {
                $table->dropUnique('credit_balances_company_id_unique');
            }
            if (! $this->hasIndex('credit_balances', 'credit_balances_company_organization_unique')) {
                $table->unique(['company_id', 'organization_id'], 'credit_balances_company_organization_unique');
            }
        });

        if ($organizationId !== null) {
            DB::table('contracts')->whereNull('issuer_organization_id')->update([
                'issuer_organization_id' => $organizationId,
            ]);
            DB::table('invoices')->whereNull('issuer_organization_id')->update([
                'issuer_organization_id' => $organizationId,
            ]);
            DB::table('credit_balances')->whereNull('organization_id')->update([
                'organization_id' => $organizationId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table): void {
            $table->dropUnique('credit_balances_company_organization_unique');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            $table->unique('company_id');
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropIndex('contracts_issuer_organization_index');
            $table->dropForeign(['issuer_organization_id']);
            $table->dropColumn('issuer_organization_id');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('organizations_active_default_index');
            $table->dropUnique('organizations_invoice_number_code_unique');
            $table->enum('singleton_key', ['own'])->default('own')->unique()->change();
            $table->dropColumn(['is_active', 'is_default']);
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $definition): bool => ($definition['name'] ?? null) === $index,
        );
    }
};
