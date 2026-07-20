<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Добавляем статус cancelled.
         *
         * Проект использует MySQL ENUM, поэтому изменяем
         * список допустимых значений напрямую.
         */
        DB::statement("
            ALTER TABLE payments
            MODIFY status ENUM(
                'pending',
                'confirmed',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'
        ");

        Schema::table('payments', function (Blueprint $table) {
            /*
             * Когда платёж был отменён.
             */
            $table->timestamp('cancelled_at')
                ->nullable()
                ->after('status');

            /*
             * Причина отмены обязательна на уровне интерфейса
             * и контроллера, но в базе оставляем nullable
             * для совместимости со старыми записями.
             */
            $table->text('cancel_reason')
                ->nullable()
                ->after('cancelled_at');
        });
    }

    public function down(): void
    {
        /*
         * Перед возвратом старого ENUM убираем значение,
         * которого в старой схеме не существовало.
         */
        DB::table('payments')
            ->where('status', 'cancelled')
            ->update([
                'status' => 'pending',
                'cancelled_at' => null,
                'cancel_reason' => null,
            ]);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_at',
                'cancel_reason',
            ]);
        });

        DB::statement("
            ALTER TABLE payments
            MODIFY status ENUM(
                'pending',
                'confirmed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
