<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access\PermissionRegistry;
use App\Support\Access\SystemRole;
use Illuminate\Support\Facades\DB;
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
        DB::prohibitDestructiveCommands($this->shouldProhibitDestructiveDatabaseCommands());

        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->isActive() || ! PermissionRegistry::contains($ability)) {
                return null;
            }

            return $user->hasRole(SystemRole::Administrator->value) ? true : null;
        });
    }

    private function shouldProhibitDestructiveDatabaseCommands(): bool
    {
        if (app()->environment(['local', 'development'])) {
            return ! (bool) config('database.allow_destructive_commands', false);
        }

        if (app()->environment('testing')) {
            $connection = (string) config('database.default');
            $database = config("database.connections.{$connection}.database");

            return ! is_string($database) || ! str_ends_with($database, '_test');
        }

        return true;
    }
}
