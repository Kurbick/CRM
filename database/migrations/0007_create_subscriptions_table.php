<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')
                ->constrained('contracts')
                ->onDelete('restrict');
            $table->foreignId('service_type_id')
                ->constrained('service_types')
                ->onDelete('restrict');
            $table->date('start_date');
            $table->date('next_billing_date');
            $table->enum('billing_period', ['monthly', 'quarterly', 'semiannual', 'annual']);
            $table->decimal('amount', 10, 2);
            $table->tinyInteger('payment_terms')->default(14);
            $table->enum('status', ['active', 'suspended', 'completed', 'cancelled'])->default('active');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('service_type_id');
            $table->index('status');
            $table->index('next_billing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
