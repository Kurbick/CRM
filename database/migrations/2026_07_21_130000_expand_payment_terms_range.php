<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE orders MODIFY payment_terms SMALLINT UNSIGNED NOT NULL DEFAULT 14'
            );
            DB::statement(
                'ALTER TABLE subscriptions MODIFY payment_terms SMALLINT UNSIGNED NOT NULL DEFAULT 14'
            );

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')
                ->default(14)
                ->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')
                ->default(14)
                ->change();
        });
    }

    public function down(): void
    {
        $incompatibleRows = collect([
            'orders' => $this->countIncompatibleRows('orders'),
            'subscriptions' => $this->countIncompatibleRows('subscriptions'),
        ])->filter();

        if ($incompatibleRows->isNotEmpty()) {
            $details = $incompatibleRows
                ->map(fn(int $count, string $table) =>
                    "{$table}: {$count} incompatible row(s)")
                ->implode('; ');

            throw new RuntimeException(
                'Cannot rollback payment_terms columns: '
                . $details
                . '. Rollback was stopped to prevent data loss. '
                . 'Normalize these values to the signed TINYINT range -128..127 before rolling back.'
            );
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE orders MODIFY payment_terms TINYINT NOT NULL DEFAULT 14'
            );
            DB::statement(
                'ALTER TABLE subscriptions MODIFY payment_terms TINYINT NOT NULL DEFAULT 14'
            );

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->tinyInteger('payment_terms')
                ->default(14)
                ->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->tinyInteger('payment_terms')
                ->default(14)
                ->change();
        });
    }

    public static function isRollbackCompatible(mixed $value): bool
    {
        return $value === null
            || ((int) $value >= -128 && (int) $value <= 127);
    }

    private function countIncompatibleRows(string $table): int
    {
        return DB::table($table)
            ->where(function ($query) {
                $query
                    ->where('payment_terms', '<', -128)
                    ->orWhere('payment_terms', '>', 127);
            })
            ->count();
    }
};
