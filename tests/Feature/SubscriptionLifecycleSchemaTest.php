<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_interval_columns_are_nullable_with_expected_mysql_types(): void
    {
        $this->assertTrue(Schema::hasColumns('subscriptions', [
            'custom_interval_value',
            'custom_interval_unit',
        ]));

        $columns = collect(DB::select('SHOW COLUMNS FROM subscriptions'))
            ->keyBy('Field');

        $this->assertSame('smallint unsigned', $columns['custom_interval_value']->Type);
        $this->assertSame('YES', $columns['custom_interval_value']->Null);
        $this->assertSame("enum('day','month','year')", $columns['custom_interval_unit']->Type);
        $this->assertSame('YES', $columns['custom_interval_unit']->Null);
    }

    public function test_occurrence_key_is_nullable_unique_and_legacy_rows_remain_valid(): void
    {
        $column = collect(DB::select('SHOW COLUMNS FROM invoice_lines'))
            ->firstWhere('Field', 'billing_occurrence_key');
        $this->assertSame('varchar(64)', $column->Type);
        $this->assertSame('YES', $column->Null);

        $indexes = collect(DB::select('SHOW INDEX FROM invoice_lines'));
        $this->assertTrue($indexes->contains(
            fn ($index): bool => $index->Column_name === 'billing_occurrence_key'
                && (int) $index->Non_unique === 0
        ));

        $companyId = DB::table('companies')->insertGetId(['name' => 'Legacy schema']);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'LEGACY-SCHEMA',
            'start_date' => '2026-01-01',
        ]);
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'contract_id' => $contractId,
            'title' => 'Legacy standard',
            'start_date' => '2026-01-01',
            'next_billing_date' => '2026-01-01',
            'billing_period' => 'monthly',
            'amount' => 100,
        ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'LEGACY-SCHEMA',
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15',
            'total_amount' => 100,
        ]);
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'subscription_id' => $subscriptionId,
            'description' => 'Legacy line',
            'amount' => 100,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ]);

        $this->assertNull(DB::table('invoice_lines')->where('id', $lineId)->value('billing_occurrence_key'));
        $this->assertNull(DB::table('subscriptions')->where('id', $subscriptionId)->value('custom_interval_value'));
    }

    public function test_new_down_migrations_do_not_modify_main_billing_period_enum(): void
    {
        foreach ([
            database_path('migrations/2026_07_30_090000_add_custom_interval_to_subscriptions_table.php'),
            database_path('migrations/2026_07_30_090100_add_billing_occurrence_key_to_invoice_lines_table.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('MODIFY billing_period', $source);
            $this->assertStringNotContainsString("dropColumn('billing_period')", $source);
        }
    }
}
