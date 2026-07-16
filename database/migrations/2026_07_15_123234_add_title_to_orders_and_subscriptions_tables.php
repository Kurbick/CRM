<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('title')->nullable()->after('service_type_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('title')->nullable()->after('service_type_id');
        });

        /*
         * Переносим названия существующих услуг из service_types,
         * чтобы старые записи не потеряли название.
         */
        DB::table('orders')
            ->join(
                'service_types',
                'orders.service_type_id',
                '=',
                'service_types.id'
            )
            ->update([
                'orders.title' => DB::raw('service_types.name'),
            ]);

        DB::table('subscriptions')
            ->join(
                'service_types',
                'subscriptions.service_type_id',
                '=',
                'service_types.id'
            )
            ->update([
                'subscriptions.title' => DB::raw('service_types.name'),
            ]);

        /*
         * Новые записи больше не обязаны иметь service_type_id.
         * Старые связи при этом пока сохраняются.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('service_type_id')
                ->nullable()
                ->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('service_type_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        /*
         * Перед откатом нельзя вернуть NOT NULL,
         * если появились записи без service_type_id.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('title');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};