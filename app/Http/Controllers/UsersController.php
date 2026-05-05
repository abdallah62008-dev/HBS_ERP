<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-side user management. Permission-gated by `users.manage`.
 *
 * Drives the four admin actions the spec calls for:
 *   - List users with role + status
 *   - Create a user (assign role + initial password)
 *   - Edit a user (change role, entry_code, status, password reset)
 *   - Toggle per-user permission overrides on top of the role
 *
 * Role-level permission editing lives in RolesController. Users can only
 * carry one role at a time (users.role_id) — per-user `allow` / `deny`
 * overrides on the user_permissions pivot fine-tune that.
 */
class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'role_id', 'status']);

        $users = User::query()
            ->with('role:id,name,slug')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('entry_code', 'like', "%{$term}%");
                });
            })
            ->when($filters['role_id'] ?? null, fn ($q, $v) => $q->where('role_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Create', [
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'entry_code' => ['nullable', 'string', 'max:16'],
            'role_id' => ['required', 'exists:roles,id'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive', 'Suspended'])],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'entry_code' => $data['entry_code'] ?? null,
            'role_id' => $data['role_id'],
            'status' => $data['status'] ?? 'Active',
            'password' => Hash::make($data['password']),
        ]);

        AuditLogService::logModelChange($user, 'created', 'users');

        return redirect()
            ->route('users.edit', $user)
            ->with('success', "User {$user->email} created.");
    }

    public function edit(User $user): Response
    {
        $user->load('role:id,name,slug', 'userPermissions:id,slug,name,module');

        // Group permissions by module for the toggle UI. Mark each as
        // `inherited_from_role`, `allow_override`, or `deny_override` so
        // the front-end can render three-state checkboxes intuitively.
        $allPerms = Permission::orderBy('module')->orderBy('name')->get(['id', 'slug', 'name', 'module']);
        $rolePermSlugs = $user->role
            ? $user->role->permissions()->pluck('slug')->all()
            : [];
        $userOverrides = $user->userPermissions
            ->mapWithKeys(fn ($p) => [$p->slug => $p->pivot->action_type])
            ->all();

        $grouped = [];
        foreach ($allPerms as $perm) {
            $state = isset($userOverrides[$perm->slug])
                ? ($userOverrides[$perm->slug] === 'allow' ? 'allow_override' : 'deny_override')
                : (in_array($perm->slug, $rolePermSlugs, true) ? 'inherited_from_role' : 'none');
            $grouped[$perm->module][] = [
                'id' => $perm->id,
                'slug' => $perm->slug,
                'name' => $perm->name,
                'state' => $state,
                'in_role' => in_array($perm->slug, $rolePermSlugs, true),
            ];
        }

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'entry_code' => $user->entry_code,
                'role_id' => $user->role_id,
                'status' => $user->status,
                'role' => $user->role,
            ],
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
            'permissions_grouped' => $grouped,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'entry_code' => ['nullable', 'string', 'max:16'],
            'role_id' => ['required', 'exists:roles,id'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive', 'Suspended'])],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $payload = collect($data)->except('password')->all();
        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->fill($payload)->save();

        AuditLogService::logModelChange($user, 'updated', 'users');

        return back()->with('success', 'User updated.');
    }

    /**
     * Persist the per-user permission overrides for a given user.
     * Expected payload: { overrides: { "<slug>": "allow"|"deny", ... } }
     * Slugs not present are treated as "inherit from role" (no row).
     */
    public function syncOverrides(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'overrides' => ['nullable', 'array'],
            'overrides.*' => [Rule::in(['allow', 'deny'])],
        ]);

        $overrides = $data['overrides'] ?? [];
        $slugToId = Permission::pluck('id', 'slug')->all();

        $sync = [];
        foreach ($overrides as $slug => $actionType) {
            if (isset($slugToId[$slug])) {
                $sync[$slugToId[$slug]] = ['action_type' => $actionType];
            }
        }

        $user->userPermissions()->sync($sync);

        AuditLogService::log(
            action: 'permission_overrides_updated',
            module: 'users',
            recordType: User::class,
            recordId: $user->id,
            newValues: ['count' => count($sync)],
        );

        return back()->with('success', 'Permission overrides saved.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Super-admin accounts cannot be deleted from the UI.');
        }

        $user->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'users',
            recordType: User::class,
            recordId: $user->id,
        );

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }
}
