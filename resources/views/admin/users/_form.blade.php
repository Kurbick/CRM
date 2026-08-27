@php
    $isCreate = $mode === 'create';
    $errorBag = $isCreate ? 'default' : 'updateUser';
    $roleLabel = fn ($role) => match ($role->name) {
        \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator'),
        \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant'),
        \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer'),
        default => $role->display_name,
    };
    $roleDescription = fn ($role) => match ($role->name) {
        \App\Support\Access\SystemRole::Administrator->value => __('admin.access.system_roles.administrator_description'),
        \App\Support\Access\SystemRole::Accountant->value => __('admin.access.system_roles.accountant_description'),
        \App\Support\Access\SystemRole::Viewer->value => __('admin.access.system_roles.viewer_description'),
        default => $role->description,
    };
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6" data-user-{{ $isCreate ? 'create' : 'update' }}-form>
    @csrf
    @unless ($isCreate)
        @method('PUT')
        <input type="hidden" name="_section" value="user">
    @endunless

    <section class="border-b border-gray-200 pb-6">
        <div class="mb-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.sections.general') }}</h2>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.users.fields.name') }}</label>
                <input id="name" name="name" value="{{ old('name', $user?->name) }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                @error('name', $errorBag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.users.fields.email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user?->email) }}" required autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                @error('email', $errorBag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    @if ($isCreate)
        <section class="border-b border-gray-200 pb-6">
            <div class="mb-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.sections.access') }}</h2>
            </div>
            <div class="max-w-md">
                <label for="role_id" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.users.fields.group') }}</label>
                <select id="role_id" name="role_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">{{ __('admin.users.select_group') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $roleLabel($role) }}{{ $roleDescription($role) ? ' — '.$roleDescription($role) : '' }}</option>
                    @endforeach
                </select>
                @error('role_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="border-b border-gray-200 pb-6">
            <div class="mb-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.users.password.temporary') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.users.password.first_login') }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.password-input name="password" :label="__('admin.users.password.temporary')" autocomplete="new-password" required />
                <x-forms.password-input name="password_confirmation" :label="__('admin.users.password.confirmation')" autocomplete="new-password" required />
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('admin.users.password.requirements') }}</p>
        </section>
    @endif

    <div class="flex flex-wrap items-center justify-end gap-3 pt-1">
        <a href="{{ $cancelUrl }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.users.actions.cancel') }}</a>
        <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">{{ $isCreate ? __('admin.users.actions.add') : __('admin.users.actions.save') }}</button>
    </div>
</form>
