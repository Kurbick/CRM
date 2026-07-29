<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class DomainQueryRecorder
{
    /** @var list<string> */
    public const DOMAIN_TABLES = [
        'companies',
        'company_contacts',
        'contracts',
        'orders',
        'subscriptions',
        'contract_documents',
        'service_types',
        'service_type_items',
        'invoices',
        'invoice_lines',
        'payments',
        'payment_allocations',
        'credit_balances',
        'credit_balance_entries',
    ];

    /**
     * @return array{result: mixed, records: list<array{sql: string, tables: list<string>}>}
     */
    public function capture(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $result = $callback();

        return [
            'result' => $result,
            'records' => array_values(array_filter(array_map(
                fn (string $query): array => [
                    'sql' => $query,
                    'tables' => self::tablesInSql($query),
                ],
                $sql
            ), fn (array $record): bool => $record['tables'] !== [])),
        ];
    }

    /** @return list<string> */
    public static function tablesInSql(string $sql): array
    {
        $normalized = strtolower(str_replace(['"', '`', '[', ']'], '', $sql));
        preg_match_all(
            '/\b(?:delete\s+from|from|join|update|into)\s+(?:(?:[a-z_][a-z0-9_$]*)\s*\.\s*)?([a-z_][a-z0-9_$]*)\b/',
            $normalized,
            $matches
        );

        return array_values(array_unique(array_intersect(
            $matches[1] ?? [],
            self::DOMAIN_TABLES
        )));
    }

    /** @param list<array{sql: string, tables: list<string>}> $records @return list<string> */
    public static function tables(array $records): array
    {
        return array_values(array_unique(array_merge(
            [],
            ...array_column($records, 'tables')
        )));
    }

    /** @param list<array{sql: string, tables: list<string>}> $records */
    public static function count(array $records): int
    {
        return count($records);
    }
}
