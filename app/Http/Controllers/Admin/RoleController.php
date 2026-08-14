<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Roles\CreateRole;
use App\Actions\Admin\Roles\DeleteRole;
use App\Actions\Admin\Roles\UpdateRole;
use App\Exceptions\RoleDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Roles\StoreRoleRequest;
use App\Http\Requests\Admin\Roles\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View|RedirectResponse
    {
        Gate::authorize('roles.view');

        if (Gate::allows('access_permissions.view')) {
            return redirect()->route('admin.access-permissions.index');
        }

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount(['users', 'permissions'])
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->orderBy('name')
            ->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function store(StoreRoleRequest $request, CreateRole $action): RedirectResponse
    {
        Gate::authorize('roles.create');
        $role = $action->handle($request->validated());

        return $this->workspaceRedirect($request, $role)
            ->with('success', 'Группа создана.');
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): RedirectResponse
    {
        Gate::authorize('roles.update');
        abort_unless($role->guard_name === 'web', 404);
        $action->handle($role, $request->validated());

        return $this->workspaceRedirect($request, $role)
            ->with('success', 'Данные группы обновлены.');
    }

    public function destroy(Request $request, Role $role, DeleteRole $action): RedirectResponse
    {
        Gate::authorize('roles.delete');
        abort_unless($role->guard_name === 'web', 404);

        try {
            $action->handle($role);
        } catch (RoleDeletionException $exception) {
            return back()->with('error', $exception->getMessage())->with('openRole', $role->getKey());
        }

        return $this->workspaceRedirect($request)->with('success', 'Группа удалена.');
    }

    private function workspaceRedirect(Request $request, ?Role $role = null): RedirectResponse
    {
        if ($request->user()?->can('access_permissions.view')) {
            return redirect()->route('admin.access-permissions.index', $role ? ['role' => $role->getKey()] : []);
        }

        return redirect()->route('admin.roles.index');
    }
}
