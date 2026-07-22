<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Request;

final class CompanyPageContext
{
    public const TABS = ['contacts', 'contracts', 'invoices', 'payments'];

    public static function activeTab(Request $request): string
    {
        $tab = $request->query('tab');

        return is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'contacts';
    }

    /** @return array{active: bool, tab: string, company_url: string, label: string, query: array<string, string>} */
    public static function resolve(Request $request, Company $company, string $expectedTab): array
    {
        $active = $request->input('origin') === 'company'
            && in_array($expectedTab, self::TABS, true)
            && $request->input('tab') === $expectedTab;
        $query = $active ? ['origin' => 'company', 'tab' => $expectedTab] : [];

        return [
            'active' => $active,
            'tab' => $expectedTab,
            'company_url' => route('companies.show', ['company' => $company, 'tab' => $expectedTab]),
            'label' => "Назад к {$company->name}",
            'query' => $query,
        ];
    }

    /** @return array{origin: string, tab: string} */
    public static function query(string $tab): array
    {
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'contacts';
        }

        return ['origin' => 'company', 'tab' => $tab];
    }
}
