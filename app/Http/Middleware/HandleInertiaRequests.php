<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. Keep payload small — heavy data
     * belongs in the controller for that page.
     *
     * The "auth.user.permissions" array is the source of truth for the
     * React sidebar's permission-aware visibility (matches the backend
     * CheckPermission middleware).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $authPayload = ['user' => null];
        if ($user) {
            $user->loadMissing(['role.permissions', 'userPermissions']);

            $rolePerms = $user->role?->permissions->pluck('slug')->all() ?? [];
            $allowOverrides = $user->userPermissions
                ->where('pivot.action_type', 'allow')
                ->pluck('slug')
                ->all();
            $denyOverrides = $user->userPermissions
                ->where('pivot.action_type', 'deny')
                ->pluck('slug')
                ->all();

            $effective = array_values(array_diff(
                array_unique(array_merge($rolePerms, $allowOverrides)),
                $denyOverrides
            ));

            $authPayload = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $user->status,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                    ] : null,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_marketer' => $user->isMarketer(),
                    'permissions' => $user->isSuperAdmin() ? ['*'] : $effective,
                ],
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => $authPayload,
            'app' => [
                'name' => config('app.name'),
                'currency_symbol' => SettingsService::get('currency_symbol', 'EGP'),
                'currency_code' => SettingsService::get('currency_code', 'EGP'),
                'country' => SettingsService::get('country', 'Egypt'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
