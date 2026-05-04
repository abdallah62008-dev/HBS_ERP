<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'entry_code',
        'password',
        'role_id',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /* -----------------------------------------------------------------
     | Relationships
     | ----------------------------------------------------------------- */

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * If this user is a Marketer, the corresponding marketer record.
     * Phase 5+ uses this for ownership scoping (Order::scopeForCurrentMarketer).
     */
    public function marketer(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Marketer::class);
    }

    /**
     * Per-user permission overrides (allow / deny) on top of the role.
     */
    public function userPermissions(): BelongsToMany
    {
        // Pivot has only `created_at` (auto-filled by MySQL).
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('action_type');
    }

    /* -----------------------------------------------------------------
     | RBAC helpers (backend-enforced, not just UI)
     | ----------------------------------------------------------------- */

    /**
     * Permission resolution:
     *   1. Super Admin always passes (slug = "super-admin").
     *   2. user_permissions with action_type = "deny" overrides everything.
     *   3. user_permissions with action_type = "allow" grants.
     *   4. Otherwise fall back to role -> role_permissions.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $override = $this->userPermissions
            ->firstWhere('slug', $slug);

        if ($override) {
            return $override->pivot->action_type === 'allow';
        }

        if (! $this->role) {
            return false;
        }

        return $this->role->permissions
            ->contains('slug', $slug);
    }

    public function hasAnyPermission(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === 'super-admin';
    }

    public function isMarketer(): bool
    {
        return $this->role?->slug === 'marketer';
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }
}
