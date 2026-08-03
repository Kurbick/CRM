<?php

namespace App\Http\Middleware;

use App\Support\Access\ApiRouteAuthorizationRegistry;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeApiRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AuthorizationException('The API route requires an authenticated user.');
        }

        $routeName = $request->route()?->getName();
        if (! is_string($routeName) || ! ApiRouteAuthorizationRegistry::has($routeName)) {
            throw new AuthorizationException('This API route is not authorized.');
        }

        Gate::forUser($user)->authorize(
            ApiRouteAuthorizationRegistry::permissionFor($routeName),
        );

        return $next($request);
    }
}
