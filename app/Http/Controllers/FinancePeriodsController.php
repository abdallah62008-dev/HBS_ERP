<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancePeriodRequest;
use App\Models\FinancePeriod;
use App\Services\FinancePeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5F — Finance period administration.
 *
 * Permissions (gated at the route layer):
 *   index / show          — finance_periods.view
 *   create / store        — finance_periods.create
 *   edit / update         — finance_periods.update  (open periods only)
 *   close                 — finance_periods.close
 *   reopen                — finance_periods.reopen
 *
 * No delete route — periods are permanent records. The model has a
 * `deleting` hook as defence-in-depth.
 */
class FinancePeriodsController extends Controller
{
    public function __construct(
        private readonly FinancePeriodService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status']);

        $periods = FinancePeriod::query()
            ->with(['closedBy:id,name', 'reopenedBy:id,name', 'createdBy:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('FinancePeriods/Index', [
            'periods' => $periods,
            'filters' => $filters,
            'statuses' => FinancePeriod::STATUSES,
            'totals' => [
                'open_count' => FinancePeriod::where('status', FinancePeriod::STATUS_OPEN)->count(),
                'closed_count' => FinancePeriod::where('status', FinancePeriod::STATUS_CLOSED)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('FinancePeriods/Create');
    }

    public function store(FinancePeriodRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $period = $this->service->createPeriod($data, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('finance-periods.index')
            ->with('success', "Finance period \"{$period->name}\" created.");
    }

    public function edit(FinancePeriod $period): Response|RedirectResponse
    {
        if (! $period->canBeEdited()) {
            return redirect()
                ->route('finance-periods.index')
                ->with('error', "Finance period \"{$period->name}\" is closed and cannot be edited.");
        }

        return Inertia::render('FinancePeriods/Edit', [
            'period' => $period,
        ]);
    }

    public function update(FinancePeriodRequest $request, FinancePeriod $period): RedirectResponse
    {
        if (! $period->canBeEdited()) {
            return back()->with(
                'error',
                "Finance period \"{$period->name}\" is closed and cannot be edited."
            );
        }

        try {
            $this->service->updatePeriod($period, $request->validated(), $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('finance-periods.index')
            ->with('success', 'Finance period updated.');
    }

    public function close(Request $request, FinancePeriod $period): RedirectResponse
    {
        try {
            $this->service->closePeriod($period, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Period \"{$period->name}\" closed.");
    }

    public function reopen(Request $request, FinancePeriod $period): RedirectResponse
    {
        try {
            $this->service->reopenPeriod($period, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Period \"{$period->name}\" reopened.");
    }
}
