<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DomainQueryRecorder;

final class DomainQueryRecorderTest extends TestCase
{
    #[DataProvider('domainSqlProvider')]
    public function test_it_detects_domain_tables_across_sql_quoting_and_schema_forms(
        string $sql,
        array $tables
    ): void {
        $this->assertSame($tables, DomainQueryRecorder::tablesInSql($sql));
    }

    public function test_it_explicitly_ignores_acl_and_system_tables(): void
    {
        $this->assertSame([], DomainQueryRecorder::tablesInSql(
            'SELECT * FROM users JOIN model_has_roles ON users.id = model_has_roles.model_id '
            .'JOIN permissions ON permissions.id = model_has_roles.role_id'
        ));
    }

    public static function domainSqlProvider(): array
    {
        return [
            'plain from' => ['select * from companies c', ['companies']],
            'double quotes and uppercase' => ['SELECT * FROM "companies" AS c', ['companies']],
            'backticks' => ['select * from `companies` as c', ['companies']],
            'schema double quotes' => ['select * from "public"."companies"', ['companies']],
            'schema backticks' => ['select * from `crm`.`companies`', ['companies']],
            'join and subquery' => [
                'select * from (select * from invoices) i join payment_allocations pa on pa.invoice_id = i.id',
                ['invoices', 'payment_allocations'],
            ],
            'write statements' => [
                'insert into invoice_lines (invoice_id) select id from invoices',
                ['invoice_lines', 'invoices'],
            ],
            'update' => ['update contract_documents set original_name = ?', ['contract_documents']],
            'delete' => ['delete from credit_balance_entries where id = ?', ['credit_balance_entries']],
        ];
    }
}
