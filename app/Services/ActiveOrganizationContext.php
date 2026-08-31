<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

final class ActiveOrganizationContext
{
    public const SESSION_KEY = 'active_organization_id';

    private ?Organization $resolved = null;
    private ?Request $resolvedRequest = null;
    private bool $resolvedForRequest = false;

    public function resolve(?Request $request = null): ?Organization
    {
        $request ??= request();

        if ($this->resolvedForRequest && $this->resolvedRequest === $request) {
            return $this->resolved;
        }

        $selectedId = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;
        $organization = is_numeric($selectedId)
            ? Organization::query()->active()->whereKey((int) $selectedId)->first()
            : null;

        if (is_numeric($selectedId) && $organization === null && $request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        if ($organization === null && $request->hasSession() && $request->user() !== null) {
            $lastOrganizationId = $request->user()->last_organization_id;
            $lastOrganization = is_numeric($lastOrganizationId)
                ? Organization::query()->active()->whereKey((int) $lastOrganizationId)->first()
                : null;

            if ($lastOrganization !== null) {
                $organization = $lastOrganization;
                $request->session()->put(self::SESSION_KEY, $organization->getKey());
            } elseif (is_numeric($lastOrganizationId)) {
                $request->user()->forceFill(['last_organization_id' => null])->saveQuietly();
            }
        }

        if ($organization === null) {
            $organization = $this->singleActive();
            if ($organization !== null) {
                if ($request->hasSession()) {
                    $request->session()->put(self::SESSION_KEY, $organization->getKey());
                }
                $this->rememberForUser($request, $organization);
            }
        }

        $this->resolved = $organization;
        $this->resolvedRequest = $request;
        $this->resolvedForRequest = true;

        return $organization;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Organization> */
    public function activeOrganizations()
    {
        return Organization::query()->active()->orderBy('name')->orderBy('id')->get();
    }

    public function activeById(int $id): ?Organization
    {
        return Organization::query()->active()->whereKey($id)->first();
    }

    public function singleActive(): ?Organization
    {
        $activeOrganizations = $this->activeOrganizations();

        return $activeOrganizations->count() === 1 ? $activeOrganizations->first() : null;
    }

    public function select(Organization $organization, ?Request $request = null): void
    {
        if (! $organization->is_active) {
            abort(422);
        }

        $request ??= request();
        $request->session()->put(self::SESSION_KEY, $organization->getKey());
        $this->rememberForUser($request, $organization);
        $this->resolved = $organization;
        $this->resolvedRequest = $request;
        $this->resolvedForRequest = true;
    }

    private function rememberForUser(Request $request, Organization $organization): void
    {
        if (! $request->hasSession() || $request->user() === null) {
            return;
        }

        if ((int) $request->user()->last_organization_id !== (int) $organization->getKey()) {
            $request->user()->forceFill([
                'last_organization_id' => $organization->getKey(),
            ])->saveQuietly();
        }
    }

    public function requireCurrent(?Request $request = null): Organization
    {
        return $this->resolve($request) ?? throw \Illuminate\Validation\ValidationException::withMessages([
            'organization' => __($this->missingContextMessage()),
        ]);
    }

    public function missingContextMessage(): string
    {
        return $this->activeOrganizations()->count() > 1
            ? 'organizations.errors.selection_required'
            : 'organizations.errors.none_available';
    }

    public function scopeFor(
        Builder|Relation $query,
        ?Organization $organization,
        string $column = 'issuer_organization_id',
    ): Builder|Relation {
        if ($organization === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $organization->getKey());
    }
}
