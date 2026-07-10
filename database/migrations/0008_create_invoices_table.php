<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('restrict');

            // Идентификация документа (Hesab Faktura)
            $table->string('invoice_number', 50)->unique();
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'cancelled'])
                ->default('draft');

            // Реквизиты получателя платежа (наша компания — заполняется на инвойсе)
            $table->string('seller_name', 255)->nullable();       // название нашей компании
            $table->string('seller_voen', 20)->nullable();        // наш VÖEN
            $table->string('seller_bank_name', 255)->nullable();  // наш банк
            $table->string('seller_iban', 50)->nullable();        // наш H/h счёт
            $table->string('seller_bank_code', 20)->nullable();   // наш Kod
            $table->string('seller_bank_voen', 20)->nullable();   // наш Bank VÖEN
            $table->string('seller_swift', 20)->nullable();       // наш SWIFT

            // Реквизиты плательщика (клиент — Ödəyici)
            $table->string('payer_name', 255)->nullable();        // название клиента на инвойсе
            $table->string('payer_voen', 20)->nullable();         // VÖEN клиента на инвойсе
            $table->string('contract_reference', 50)->nullable(); // Müqavilə №

            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('status');
            $table->index('due_date');
            $table->index('issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
