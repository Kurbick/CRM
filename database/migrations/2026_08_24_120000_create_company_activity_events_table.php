<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('category', 30);
            $table->string('visibility_scope', 30);
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'category', 'occurred_at']);
            $table->index('event_type');
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_activity_events');
    }
};
