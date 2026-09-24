<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(): View
    {
        $this->authorize('viewAny', Role::class);

        return view('admin.roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('label_ar')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Role::class);

        return view('admin.roles.create', ['catalog' => Permissions::CATALOG]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $data = $this->validateRole($request);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'label_ar' => $data['label_ar'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);

            $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

            $this->audit->log(
                action: 'role.created',
                subject: $role,
                after: ['name' => $role->name, 'permissions' => $data['permissions'] ?? []],
                description: 'إنشاء دور جديد',
            );

            return $role;
        });

        return redirect()
            ->route('admin.roles.index')
            ->with('toast', ['type' => 'success', 'message' => "تم إنشاء الدور «{$role->label_ar}»."]);
    }

    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('admin.roles.edit', [
            'role' => $role->load('permissions'),
            'catalog' => Permissions::CATALOG,
            'assigned' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $data = $this->validateRole($request, $role);

        DB::transaction(function () use ($role, $data) {
            $before = ['label_ar' => $role->label_ar, 'permissions' => $role->permissions->pluck('name')->all()];

            // A system role keeps its machine key so code referencing it by
            // name never breaks; only its label and grants are editable.
            $role->update([
                'name' => $role->is_system ? $role->name : $data['name'],
                'label_ar' => $data['label_ar'],
                'description' => $data['description'] ?? null,
            ]);

            $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

            $this->audit->log(
                action: 'role.permissions_changed',
                subject: $role,
                before: $before,
                after: ['label_ar' => $role->label_ar, 'permissions' => $data['permissions'] ?? []],
                description: 'تعديل صلاحيات دور',
            );
        });

        return redirect()
            ->route('admin.roles.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث الدور وصلاحياته.']);
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->audit->logDelete('role.deleted', $role);
        $role->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف الدور.']);
    }

    protected function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                $role?->is_system ? 'nullable' : 'required',
                'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'label_ar' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ], [
            'name.regex' => 'المعرّف يجب أن يحتوي على حروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط.',
        ], [
            'name' => 'المعرّف',
            'label_ar' => 'اسم الدور',
            'description' => 'الوصف',
            'permissions' => 'الصلاحيات',
        ]);
    }

    /** @param array<int, string> $names */
    protected function permissionIds(array $names): array
    {
        return Permission::whereIn('name', $names)->pluck('id')->all();
    }
}
