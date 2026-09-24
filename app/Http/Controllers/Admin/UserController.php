<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()
                ->with(['roles:id,label_ar', 'branch:id,uuid,name'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = trim($request->string('search'));

                    $q->where(fn ($i) => $i->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
                })
                ->when($request->filled('role_id'), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('roles.id', $request->integer('role_id'))))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'roles' => Role::orderBy('label_ar')->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', $this->formData($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'branch_id' => ['required', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', Rule::in($request->user()->accessibleBranchIds())],
            'can_access_all_branches' => ['nullable', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ], [], $this->attributes());

        // Only a super admin may mint another super admin or hand out
        // all-branch access.
        if (! $request->user()->isSuperAdmin()) {
            $data['can_access_all_branches'] = false;
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'branch_id' => $data['branch_id'],
                'can_access_all_branches' => (bool) ($data['can_access_all_branches'] ?? false),
                'status' => $data['status'],
                'email_verified_at' => now(),
            ]);

            $user->roles()->sync($data['roles']);
            $user->branches()->sync($data['branches'] ?? [$data['branch_id']]);

            $this->audit->log(
                action: 'user.created',
                subject: $user,
                after: ['email' => $user->email, 'roles' => $data['roles'], 'status' => $user->status],
                description: 'إنشاء مستخدم جديد',
            );

            return $user;
        });

        return redirect()
            ->route('admin.users.index')
            ->with('toast', ['type' => 'success', 'message' => "تم إنشاء المستخدم {$user->name}."]);
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', array_merge($this->formData($request), [
            'user' => $user->load(['roles', 'branches', 'permissionOverrides']),
            'effectivePermissions' => $user->permissionNames(),
        ]));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at')],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'branch_id' => ['required', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', Rule::in($request->user()->accessibleBranchIds())],
            'can_access_all_branches' => ['nullable', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ], [], $this->attributes());

        DB::transaction(function () use ($user, $data, $request) {
            $before = [
                'roles' => $user->roles->pluck('id')->all(),
                'status' => $user->status,
                'branch_id' => $user->branch_id,
                'can_access_all_branches' => $user->can_access_all_branches,
            ];

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'branch_id' => $data['branch_id'],
                'status' => $data['status'],
            ]);

            if ($request->user()->isSuperAdmin()) {
                $user->can_access_all_branches = (bool) ($data['can_access_all_branches'] ?? false);
            }

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();
            $user->roles()->sync($data['roles']);
            $user->branches()->sync($data['branches'] ?? [$data['branch_id']]);

            $after = [
                'roles' => $data['roles'],
                'status' => $user->status,
                'branch_id' => $user->branch_id,
                'can_access_all_branches' => $user->can_access_all_branches,
            ];

            $this->audit->log(
                action: $before['roles'] !== $after['roles'] ? 'user.permissions_changed' : 'user.updated',
                subject: $user,
                before: $before,
                after: $after,
                description: 'تعديل بيانات مستخدم',
            );
        });

        return redirect()
            ->route('admin.users.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات المستخدم.']);
    }

    /** Per-user permission overrides, layered on top of role grants. */
    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $this->authorize('roles.manage');

        $data = $request->validate([
            'granted' => ['nullable', 'array'],
            'granted.*' => ['string', Rule::in(Permissions::all())],
            'revoked' => ['nullable', 'array'],
            'revoked.*' => ['string', Rule::in(Permissions::all())],
        ]);

        DB::transaction(function () use ($user, $data) {
            $before = $user->permissionNames()->all();
            $ids = Permission::pluck('id', 'name');
            $sync = [];

            foreach ($data['granted'] ?? [] as $name) {
                if (isset($ids[$name])) {
                    $sync[$ids[$name]] = ['granted' => true];
                }
            }

            foreach ($data['revoked'] ?? [] as $name) {
                if (isset($ids[$name])) {
                    $sync[$ids[$name]] = ['granted' => false];
                }
            }

            $user->permissionOverrides()->sync($sync);
            $user->forgetPermissionCache();

            $this->audit->log(
                action: 'user.permissions_changed',
                subject: $user,
                before: ['permissions' => $before],
                after: ['permissions' => $user->permissionNames()->all()],
                description: 'تعديل صلاحيات مستخدم',
            );
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث الصلاحيات الخاصة بالمستخدم.']);
    }

    /** Deactivate rather than delete, so audit history keeps its actor. */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        DB::transaction(function () use ($user) {
            $user->update(['status' => 'inactive']);
            $user->tokens()->delete();

            $this->audit->log(
                action: 'user.deactivated',
                subject: $user,
                before: ['status' => 'active'],
                after: ['status' => 'inactive'],
                description: 'تعطيل حساب مستخدم',
            );
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تعطيل الحساب وإلغاء جلساته.']);
    }

    protected function formData(Request $request): array
    {
        return [
            'roles' => Role::orderBy('label_ar')->get(),
            'branches' => Branch::whereIn('id', $request->user()->accessibleBranchIds())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'permissionCatalog' => Permissions::CATALOG,
        ];
    }

    protected function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'branch_id' => 'الفرع الرئيسي',
            'branches' => 'الفروع المتاحة',
            'roles' => 'الأدوار',
            'status' => 'الحالة',
        ];
    }
}
