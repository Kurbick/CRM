<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contracts', 'signed_document')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('signed_document');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('contracts', 'signed_document')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->string('signed_document')->nullable();
            });
        }
    }
};
