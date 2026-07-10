<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credit_balance_entries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('credit_balance_id')
            ->constrained('credit_balances')
            ->onDelete('restrict');
        $table->enum('type', ['top_up', 'applied']);
        // top_up — пополнение от переплаты
        // applied — списание в счёт инвойса
        $table->decimal('amount', 10, 2);
        $table->foreignId('payment_id')
            ->nullable()
            ->constrained('payments')
            ->onDelete('set null');
        // какой платёж породил переплату
        $table->foreignId('invoice_id')
            ->nullable()
            ->constrained('invoices')
            ->onDelete('set null');
        // к какому инвойсу применили баланс
        $table->string('description', 255)->nullable();
        $table->timestamps();

        $table->index('credit_balance_id');
        $table->index('type');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_balance_entries');
    }
};
