<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->onDelete('restrict');
            // Нельзя удалить инвойс, по которому есть платежи

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('restrict');

            $table->date('payment_date');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['cash', 'card', 'transfer']);
            $table->enum('status', ['pending', 'confirmed'])->default('pending');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('company_id');
            $table->index('status');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
