<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-side role management. Permission-gated by `roles.manage`.
 *
 * Only role-level permission editing is exposed here. The Phase 1
 * RolesSeeder is the source of truth for the default catalogue;
 * editing through this UI updates the role_permissions pivot directly.
 *
 * Roles themselves are not user-creatable in this phase — the system
 * roles (Super Admin, Admin, Manager, Marketer, Viewer, etc.) are
 * seeded once and treated as fixed names. Editing the permission
 * mapping per role IS user-facing because operations need to
 * loosen/tighten access without a redeploy.
 */
class RolesController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->withCount('permissions', 'users')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'is_system']);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
        ]);
    }

    public function edit(Role $role): Response
    {
        $rolePermSlugs = $role->permissions()->pluck('slug')->all();
        $allPerms = Permission::orderBy('module')->orderBy('name')->get(['id', 'slug', 'name', 'module']);

        $grouped = [];
        foreach ($allPerms as $perm) {
            $grouped[$perm->module][] = [
                'id' => $perm->id,
                'slug' => $perm->slug,
                'name' => $perm->name,
                'granted' => in_array($perm->slug, $rolePermSlugs, true),
            ];
        }

        return Inertia::render('Roles/Edit', [
            'role' => $role->only(['id', 'name', 'slug', 'description', 'is_system']),
            'permissions_grouped' => $grouped,
            'user_count' => $role->users()->count(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        $slugs = $data['permissions'] ?? [];

        // Super-admin's permission list is intentionally empty — User::hasPermission
        // short-circuits to true on super-admin role. Refuse to change it
        // here so a curious admin doesn't lock themselves out.
        if ($role->slug === 'super-admin') {
            return back()->with('error', 'Super Admin permissions are computed dynamically and cannot be edited.');
        }

        $oldSlugs = $role->permissions()->pluck('slug')->all();
        $role->syncPermissionsBySlug($slugs);

        AuditLogService::log(
            action: 'role_permissions_updated',
            module: 'roles',
            recordType: Role::class,
            recordId: $role->id,
            oldValues: ['count' => count($oldSlugs)],
            newValues: ['count' => count($slugs)],
        );

        return back()->with('success', "Permissions for {$role->name} saved.");
    }
}
