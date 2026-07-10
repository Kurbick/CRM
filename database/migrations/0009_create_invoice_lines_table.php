<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->onDelete('cascade');
            // Удалили инвойс — строки удаляются вместе

            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->onDelete('restrict');

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->onDelete('restrict');

            // Ровно одно из двух заполнено: subscription_id или order_id
            $table->string('description', 255);
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('subscription_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
