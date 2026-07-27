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
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('roles.view');

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
        $action->handle($request->validated());

        return redirect()->route('admin.roles.index')->with('success', 'Группа создана. Права доступа можно будет настроить отдельно.');
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): RedirectResponse
    {
        Gate::authorize('roles.update');
        abort_unless($role->guard_name === 'web', 404);
        $action->handle($role, $request->validated());

        return redirect()->route('admin.roles.index')->with('success', 'Данные группы обновлены.');
    }

    public function destroy(Role $role, DeleteRole $action): RedirectResponse
    {
        Gate::authorize('roles.delete');
        abort_unless($role->guard_name === 'web', 404);

        try {
            $action->handle($role);
        } catch (RoleDeletionException $exception) {
            return back()->with('error', $exception->getMessage())->with('openRole', $role->getKey());
        }

        return redirect()->route('admin.roles.index')->with('success', 'Группа удалена.');
    }
}
