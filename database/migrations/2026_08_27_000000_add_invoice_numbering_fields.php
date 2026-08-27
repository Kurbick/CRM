<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('invoice_number_code', 12)->nullable()->after('swift');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('issuer_organization_id')
                ->nullable()
                ->after('company_id')
                ->constrained('organizations')
                ->onDelete('restrict');
            $table->unsignedSmallInteger('invoice_number_year')->nullable()->after('invoice_number');
            $table->unsignedBigInteger('invoice_number_sequence')->nullable()->after('invoice_number_year');
            $table->string('invoice_number_code', 12)->nullable()->after('invoice_number_sequence');
            $table->unique(
                ['issuer_organization_id', 'invoice_number_year', 'invoice_number_sequence'],
                'invoices_issuer_year_sequence_unique'
            );
        });

        Schema::create('invoice_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('restrict');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'year'], 'invoice_number_counters_organization_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counters');

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_issuer_year_sequence_unique');
            $table->dropForeign(['issuer_organization_id']);
            $table->dropColumn([
                'issuer_organization_id',
                'invoice_number_year',
                'invoice_number_sequence',
                'invoice_number_code',
            ]);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('invoice_number_code');
        });
    }
};
