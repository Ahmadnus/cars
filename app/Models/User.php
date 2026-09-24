<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar_path', 'locale',
        'branch_id', 'is_super_admin', 'can_access_all_branches', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    /** Resolved permission names, memoised per request. */
    protected ?Collection $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'can_access_all_branches' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Extra branches this user may reach beyond their home branch. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /** Per-user grants and revocations layered over the role grants. */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withPivot('granted')
            ->withTimestamps();
    }

    public function trainer(): HasOne
    {
        return $this->hasOne(Trainer::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function trainee(): HasOne
    {
        return $this->hasOne(Trainee::class);
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Effective permission names: the union of role permissions, plus per-user
     * grants, minus per-user revocations.
     */
    public function permissionNames(): Collection
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionCache = Permission::query()->pluck('name');
        }

        $this->loadMissing('roles.permissions', 'permissionOverrides');

        $fromRoles = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();

        $granted = $this->permissionOverrides->filter(fn ($p) => (bool) $p->pivot->granted)->pluck('name');
        $revoked = $this->permissionOverrides->reject(fn ($p) => (bool) $p->pivot->granted)->pluck('name');

        return $this->permissionCache = $fromRoles->merge($granted)->unique()->diff($revoked)->values();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isSuperAdmin() || $this->permissionNames()->contains($permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $names = $this->permissionNames();

        foreach ($permissions as $permission) {
            if ($names->contains($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
        $this->unsetRelation('permissionOverrides');
    }

    // ------------------------------------------------------------------
    // Branch access
    // ------------------------------------------------------------------

    public function canAccessAllBranches(): bool
    {
        return $this->isSuperAdmin() || (bool) $this->can_access_all_branches;
    }

    /** @return array<int, int> */
    public function accessibleBranchIds(): array
    {
        if ($this->canAccessAllBranches()) {
            return Branch::query()->where('status', 'active')->pluck('id')->all();
        }

        $ids = $this->branches()->pluck('branches.id')->all();

        if ($this->branch_id) {
            $ids[] = $this->branch_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function canAccessBranch(int|Branch|null $branch): bool
    {
        if ($branch === null) {
            return true;
        }

        $id = $branch instanceof Branch ? $branch->id : (int) $branch;

        return in_array($id, $this->accessibleBranchIds(), true);
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->take(2)
            ->map(fn (string $part) => mb_substr($part, 0, 1))
            ->implode('');
    }
}
