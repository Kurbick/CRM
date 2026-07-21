<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->foreignId('invoice_line_id')
                ->constrained('invoice_lines')
                ->restrictOnDelete();

            $table->decimal('amount', 10, 2)
                ->unsigned();

            $table->timestamps();

            $table->unique(
                ['payment_id', 'invoice_line_id'],
                'payment_allocations_payment_line_unique'
            );

            $table->index(
                'invoice_line_id',
                'payment_allocations_line_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
