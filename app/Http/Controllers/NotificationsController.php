<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\SmartAlertsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    public function __construct(
        private readonly SmartAlertsService $alerts,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only(['type', 'unread_only']);

        $notifications = Notification::query()
            ->forUser($user)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(! empty($filters['unread_only']), fn ($q) => $q->unread())
            ->orderByDesc('created_at')
            ->paginate(50);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => $filters,
            'types' => Notification::TYPES,
        ]);
    }

    /**
     * Bell-icon endpoint: returns count + a few recent items.
     * Returns plain JSON (not Inertia) for the layout's polling.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $unread = Notification::query()->forUser($user)->unread()->count();

        $recent = Notification::query()
            ->forUser($user)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'title', 'type', 'action_url', 'created_at', 'read_at']);

        return response()->json([
            'unread_count' => $unread,
            'recent' => $recent,
        ]);
    }

    public function markRead(Notification $notification): RedirectResponse
    {
        $user = request()->user();
        // Make sure the user can see this notification (own user_id or own role_id).
        if ($notification->user_id !== $user->id && $notification->role_id !== $user->role_id) {
            abort(403);
        }

        $notification->forceFill(['read_at' => now()])->save();
        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        Notification::query()
            ->forUser($user)
            ->unread()
            ->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function refresh(): RedirectResponse
    {
        $count = $this->alerts->runAll();
        return back()->with('success', "Refreshed alerts. {$count} new notification(s).");
    }
}
