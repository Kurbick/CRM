<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AccessPermissions\UpdateRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessPermissions\UpdateAccessPermissionsRequest;
use App\Models\Role;
use App\Support\Access\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccessPermissionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access_permissions.view');

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->orderBy('name')
            ->get();

        $selectedRole = $request->query->has('role')
            ? $roles->firstWhere('id', ctype_digit((string) $request->query('role')) ? (int) $request->query('role') : null)
            : $roles->first();
        abort_if($selectedRole === null, 404);

        $selectedRole->load('permissions');
        $managedNames = PermissionRegistry::names();
        $assignedNames = $selectedRole->permissions->pluck('name')->all();
        $managedAssigned = array_values(array_intersect($managedNames, $assignedNames));
        $unknownPermissions = $selectedRole->permissions->whereNotIn('name', $managedNames)->values();

        return view('admin.access-permissions.index', [
            'roles' => $roles,
            'selectedRole' => $selectedRole,
            'categories' => PermissionRegistry::grouped(),
            'managedAssigned' => $selectedRole->isAdministrator() ? $managedNames : $managedAssigned,
            'unknownPermissions' => $unknownPermissions,
            'immutable' => $selectedRole->isAdministrator(),
        ]);
    }

    public function update(UpdateAccessPermissionsRequest $request, Role $role, UpdateRolePermissions $action): RedirectResponse
    {
        Gate::authorize('access_permissions.update');
        abort_unless($role->guard_name === 'web', 404);
        $action->handle($role, $request->validated('permissions'));

        return redirect()->route('admin.access-permissions.index', ['role' => $role->getKey()])
            ->with('success', 'Права доступа группы обновлены.');
    }
}
