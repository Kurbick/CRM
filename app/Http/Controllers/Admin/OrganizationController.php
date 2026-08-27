<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Organization\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Support\Access\SystemRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organization.show', [
            'organization' => Organization::query()->current()->first(),
        ]);
    }

    public function edit(Request $request): View
    {
        $this->authorizeAdministrator($request);

        return view('admin.organization.edit', [
            'organization' => Organization::query()->current()->first(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request): RedirectResponse
    {
        $this->authorizeAdministrator($request);

        Organization::query()->updateOrCreate(
            ['singleton_key' => Organization::SINGLETON_KEY],
            $request->validated(),
        );

        return redirect()->route('admin.organization.show')->with('success', __('admin.organization.flash.updated'));
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()?->hasRole(SystemRole::Administrator->value),
            403,
        );
    }
}
