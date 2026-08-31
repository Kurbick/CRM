<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Organization\StoreOrganizationRequest;
use App\Http\Requests\Admin\Organization\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Support\Access\SystemRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organizations.index', [
            'organizations' => Organization::query()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organizations.form', ['organization' => null]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        $organization = Organization::query()->create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return redirect()->route('admin.organizations.show', $organization)
            ->with('success', __('organizations.admin.flash.created'));
    }

    public function show(Request $request, Organization $organization): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organizations.show', [
            'organization' => $organization,
            'organizationCount' => Organization::query()->count(),
        ]);
    }

    public function edit(Request $request, Organization $organization): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organizations.form', compact('organization'));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $organization->update($request->validated());

        return redirect()->route('admin.organizations.show', $organization)
            ->with('success', __('organizations.admin.flash.updated'));
    }

    public function activate(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $organization->update(['is_active' => true]);

        return redirect()->route('admin.organizations.show', $organization)
            ->with('success', __('organizations.admin.flash.activated'));
    }

    public function deactivate(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $organization->update(['is_active' => false]);

        return redirect()->route('admin.organizations.show', $organization)
            ->with('success', __('organizations.admin.flash.deactivated'));
    }

    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        if ($organization->contracts()->exists()
            || $organization->invoices()->exists()
            || $organization->invoiceNumberCounters()->exists()
            || $organization->creditBalances()->exists()) {
            return back()->with('error', __('organizations.admin.messages.delete_dependencies'));
        }

        $organization->delete();

        return redirect()->route('admin.organizations.index')
            ->with('success', __('organizations.admin.flash.deleted'));
    }

    public function legacyShow(Request $request): View
    {
        $this->authorizeAdministrator($request);
        $organization = $this->legacyOrganization();

        return view('admin.organization.show', compact('organization'));
    }

    public function legacyEdit(Request $request): View
    {
        $this->authorizeAdministrator($request);
        $organization = $this->legacyOrganization();

        return view('admin.organization.edit', compact('organization'));
    }

    public function legacyUpdate(UpdateOrganizationRequest $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $organization = $this->legacyOrganization();
        abort_unless($organization !== null, 404);

        return $this->update($request, $organization);
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()?->hasRole(SystemRole::Administrator->value),
            403,
        );
    }

    private function legacyOrganization(): ?Organization
    {
        $organizations = Organization::query()->limit(2)->get();

        return $organizations->count() === 1 ? $organizations->first() : null;
    }
}
