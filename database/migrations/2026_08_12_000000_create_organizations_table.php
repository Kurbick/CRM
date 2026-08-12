<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->enum('singleton_key', ['own'])->default('own')->unique();
            $table->string('name', 255);
            $table->string('voen', 20)->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('iban', 50)->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_voen', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->timestamps();
        });

        $values = [
            'name' => $this->value(config('invoice.seller.name')),
            'voen' => $this->value(config('invoice.seller.voen')),
            'bank_name' => $this->value(config('invoice.seller.bank_name')),
            'iban' => $this->value(config('invoice.seller.iban')),
            'bank_code' => $this->value(config('invoice.seller.bank_code')),
            'bank_voen' => $this->value(config('invoice.seller.bank_voen')),
            'swift' => $this->value(config('invoice.seller.swift')),
        ];

        if ($values['name'] === null) {
            return;
        }

        DB::table('organizations')->insert([
            'singleton_key' => 'own',
            ...$values,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }

    private function value(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
};
