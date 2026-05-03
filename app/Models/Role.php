<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        // Pivot has only `created_at` (DB auto-fills via CURRENT_TIMESTAMP),
        // so we deliberately do NOT use withTimestamps().
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * Sync permissions by their slugs. Used by seeders and the future
     * roles management UI to grant/revoke a whole set in one operation.
     *
     * @param  array<int,string>  $slugs
     */
    public function syncPermissionsBySlug(array $slugs): void
    {
        $ids = Permission::query()
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->all();

        $this->permissions()->sync($ids);
    }
}
