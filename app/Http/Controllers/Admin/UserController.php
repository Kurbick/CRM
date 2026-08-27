<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Users\CreateUser;
use App\Actions\Admin\Users\ResetUserPassword;
use App\Actions\Admin\Users\SetUserActiveStatus;
use App\Actions\Admin\Users\UpdateUser;
use App\Actions\Admin\Users\UpdateUserRole;
use App\Exceptions\LastAdministratorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ResetUserPasswordRequest;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('users.view');
        $search = preg_replace('/\s+/u', ' ', trim((string) $request->query('search', ''))) ?? '';
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '';
        $role = $request->query('role', '');
        $sort = in_array($request->query('sort'), ['name', 'created_at', 'last_login_at'], true) ? $request->query('sort') : 'created_at';
        $direction = in_array($request->query('direction'), ['asc', 'desc'], true) ? $request->query('direction') : 'desc';

        $query = User::query()->with(['roles' => fn ($query) => $query->where('guard_name', 'web')]);
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($status !== '') {
            $query->where('is_active', $status === 'active');
        }
        if ($role === 'none') {
            $query->whereDoesntHave('roles', fn ($query) => $query->where('guard_name', 'web'));
        } elseif (ctype_digit((string) $role)) {
            $query->whereHas('roles', fn ($query) => $query->where('roles.id', (int) $role)->where('guard_name', 'web'));
        }

        $users = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users', 'search', 'status', 'role', 'sort', 'direction') + ['roles' => $this->roles()]);
    }

    public function create(): View
    {
        Gate::authorize('users.create');
        Gate::authorize('users.assign_role');

        return view('admin.users.create', ['roles' => $this->roles()]);
    }

    public function store(StoreUserRequest $request, CreateUser $action): RedirectResponse
    {
        Gate::authorize('users.create');
        Gate::authorize('users.assign_role');
        $validated = $request->validated();
        $role = Role::query()->where('guard_name', 'web')->findOrFail($validated['role_id']);
        $user = $action->handle($request->user(), $validated, $role);

        return redirect()->route('admin.users.edit', $user)->with('success', __('admin.users.flash.created'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('users.view');
        $user->load(['roles' => fn ($query) => $query->where('guard_name', 'web')]);

        return view('admin.users.edit', ['user' => $user, 'roles' => $this->roles()]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): RedirectResponse
    {
        Gate::authorize('users.update');
        $action->handle($user, $request->validated());

        return back()->with('success', __('admin.users.flash.updated'));
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user, UpdateUserRole $action): RedirectResponse
    {
        Gate::authorize('users.assign_role');
        $role = Role::query()->where('guard_name', 'web')->findOrFail($request->validated('role_id'));
        try {
            $action->handle($request->user(), $user, $role);
        } catch (LastAdministratorException $exception) {
            $validationException = ValidationException::withMessages(['role_id' => $exception->getMessage()]);
            $validationException->errorBag = 'updateRole';
            throw $validationException;
        }

        return back()->with('success', __('admin.users.flash.role_updated'));
    }

    public function activate(Request $request, User $user, SetUserActiveStatus $action): RedirectResponse
    {
        Gate::authorize('users.activate');
        $action->handle($request->user(), $user, true);

        return back()->with('success', __('admin.users.flash.activated'));
    }

    public function deactivate(Request $request, User $user, SetUserActiveStatus $action): RedirectResponse
    {
        Gate::authorize('users.deactivate');
        try {
            $action->handle($request->user(), $user, false);
        } catch (LastAdministratorException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('admin.users.flash.deactivated'));
    }

    public function updatePassword(ResetUserPasswordRequest $request, User $user, ResetUserPassword $action): RedirectResponse
    {
        Gate::authorize('users.reset_password');
        $action->handle($request->user(), $user, $request->validated('password'));

        return back()->with('success', __('admin.users.flash.password_updated'));
    }

    private function roles()
    {
        return Role::query()->where('guard_name', 'web')->orderBy('sort_order')->orderBy('display_name')->orderBy('name')->get();
    }
}
