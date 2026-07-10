<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['company', 'individual'])->default('company');
            $table->string('name', 255);
            $table->string('short_name', 100)->nullable();
            $table->string('voen', 20)->nullable();
            // Банковские реквизиты клиента (для инвойса)
            $table->string('bank_name', 255)->nullable();
            $table->string('iban', 50)->nullable();        // H/h номер счёта
            $table->string('bank_code', 20)->nullable();   // Kod
            $table->string('bank_voen', 20)->nullable();   // Bank VÖEN
            $table->string('swift', 20)->nullable();       // S.W.I.F.T
            $table->string('legal_address', 255)->nullable();
            $table->string('actual_address', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 255)->nullable();
            $table->enum('status', ['active', 'suspended', 'archived'])->default('active');
            $table->enum('invoice_mode', ['separate', 'consolidated'])->default('separate');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
