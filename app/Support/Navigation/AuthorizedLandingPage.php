<?php

namespace App\Support\Navigation;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Gate;

final class AuthorizedLandingPage
{
    public function __construct(private readonly UrlGenerator $url) {}

    public function url(User $user): string
    {
        $gate = Gate::forUser($user);

        if ($gate->allows(PermissionName::DashboardView->value)) {
            return $this->url->route('dashboard');
        }

        if ($gate->allows('viewAny', Company::class)) {
            return $this->url->route('companies.index');
        }

        if ($gate->allows('viewAny', Contract::class)) {
            return $this->url->route('contracts.index');
        }

        if ($gate->allows('viewAny', Invoice::class)) {
            return $this->url->route('invoices.index');
        }

        foreach ([
            PermissionName::UsersView->value => 'admin.users.index',
            PermissionName::RolesView->value => 'admin.roles.index',
            PermissionName::AccessPermissionsView->value => 'admin.access-permissions.index',
        ] as $ability => $route) {
            if ($gate->allows($ability)) {
                return $this->url->route($route);
            }
        }

        return $this->url->route('home');
    }

    public function hasReadableSection(User $user): bool
    {
        return $this->url($user) !== $this->url->route('home');
    }
}
