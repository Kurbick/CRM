<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access\PermissionRegistry;
use App\Support\Access\SystemRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->isActive() || ! PermissionRegistry::contains($ability)) {
                return null;
            }

            return $user->hasRole(SystemRole::Administrator->value) ? true : null;
        });
    }
}
