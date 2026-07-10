<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')
                ->constrained('contracts')
                ->onDelete('restrict');
            $table->foreignId('service_type_id')
                ->constrained('service_types')
                ->onDelete('restrict');
            $table->date('order_date');
            $table->date('deadline')->nullable();
            $table->decimal('price', 10, 2);
            $table->tinyInteger('payment_terms')->default(14);
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('service_type_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
