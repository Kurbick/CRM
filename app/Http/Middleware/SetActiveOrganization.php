<?php

namespace App\Http\Middleware;

use App\Services\ActiveOrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetActiveOrganization
{
    public function __construct(private readonly ActiveOrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            $organization = $this->context->resolve($request);
            view()->share('activeOrganization', $organization);
            view()->share('activeOrganizations', $this->context->activeOrganizations());
        }

        return $next($request);
    }
}
