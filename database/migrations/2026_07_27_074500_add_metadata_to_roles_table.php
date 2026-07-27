<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name')->after('guard_name');
            $table->text('description')->nullable()->after('display_name');
            $table->boolean('is_system')->default(false)->index()->after('description');
            $table->unsignedInteger('sort_order')->default(0)->index()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['is_system']);
            $table->dropIndex(['sort_order']);
            $table->dropColumn(['display_name', 'description', 'is_system', 'sort_order']);
        });
    }
};
