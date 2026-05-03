<?php

namespace App\Http\Controllers;

use App\Models\StaffTarget;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StaffTargetsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['user_id', 'status']);

        // Recompute achieved values on the fly so the page is honest.
        $targets = StaffTarget::with('user:id,name,email,role_id')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->get();

        foreach ($targets as $t) {
            $this->refreshAchieved($t);
        }

        return Inertia::render('Staff/Targets', [
            'targets' => $targets,
            'filters' => $filters,
            'staff' => User::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'email']),
            'types' => StaffTarget::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'target_type' => ['required', 'string', 'max:64'],
            'target_period' => ['required', 'in:Daily,Weekly,Monthly,Quarterly'],
            'target_value' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $target = StaffTarget::create([
            ...$data,
            'achieved_value' => 0,
            'status' => 'Active',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($target, 'created', 'staff');

        return back()->with('success', 'Target created.');
    }

    public function update(Request $request, StaffTarget $staffTarget): RedirectResponse
    {
        $data = $request->validate([
            'target_value' => ['sometimes', 'numeric', 'gt:0'],
            'status' => ['sometimes', 'in:Active,Completed,Cancelled'],
            'end_date' => ['sometimes', 'date'],
        ]);

        $staffTarget->fill([...$data, 'updated_by' => Auth::id()])->save();
        AuditLogService::logModelChange($staffTarget, 'updated', 'staff');

        return back()->with('success', 'Target updated.');
    }

    public function destroy(StaffTarget $staffTarget): RedirectResponse
    {
        $staffTarget->delete();
        AuditLogService::log('deleted', 'staff', StaffTarget::class, $staffTarget->id);
        return back()->with('success', 'Target deleted.');
    }

    /**
     * Recompute achieved_value from the database. Cheap because the queries
     * are scoped by user_id + date range.
     */
    private function refreshAchieved(StaffTarget $target): void
    {
        $start = $target->start_date->toDateString();
        $end = $target->end_date->toDateString() . ' 23:59:59';

        $value = match ($target->target_type) {
            'Confirmed Orders' => DB::table('order_status_history')
                ->where('changed_by', $target->user_id)
                ->where('new_status', 'Confirmed')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'Delivered Orders' => DB::table('order_status_history')
                ->where('changed_by', $target->user_id)
                ->where('new_status', 'Delivered')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'Sales Amount' => (float) DB::table('orders')
                ->where('confirmed_by', $target->user_id)
                ->whereBetween('confirmed_at', [$start, $end])
                ->sum('total_amount'),
            'Low Return Rate' => $this->returnRateFor($target->user_id, $start, $end),
            default => 0,
        };

        if ((float) $target->achieved_value !== (float) $value) {
            $target->forceFill(['achieved_value' => $value])->save();
        }
    }

    private function returnRateFor(int $userId, string $start, string $end): float
    {
        $confirmed = DB::table('orders')
            ->where('confirmed_by', $userId)
            ->whereBetween('confirmed_at', [$start, $end])
            ->count();
        if ($confirmed === 0) return 0;

        $returned = DB::table('orders')
            ->where('confirmed_by', $userId)
            ->whereBetween('confirmed_at', [$start, $end])
            ->where('status', 'Returned')
            ->count();

        // Return rate as percentage; lower is better, so target_value
        // should be the maximum acceptable return rate.
        return round(($returned / $confirmed) * 100, 2);
    }
}
