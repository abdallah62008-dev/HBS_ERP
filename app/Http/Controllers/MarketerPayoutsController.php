<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketerPayoutRequest;
use App\Models\Cashbox;
use App\Models\Marketer;
use App\Models\MarketerPayout;
use App\Models\PaymentMethod;
use App\Services\AuditLogService;
use App\Services\MarketerPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5D — Marketer Payouts CRUD + lifecycle.
 *
 * Permission gates (per route):
 *   index/show   — marketer_payouts.view
 *   create/store — marketer_payouts.create
 *   edit/update  — marketer_payouts.create + canBeEdited() (requested only)
 *   destroy      — marketer_payouts.create + canBeDeleted() (requested only)
 *   approve      — marketer_payouts.approve
 *   reject       — marketer_payouts.reject
 *   pay          — marketer_payouts.pay
 */
class MarketerPayoutsController extends Controller
{
    public function __construct(
        private readonly MarketerPayoutService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'q', 'marketer_id']);

        $payouts = MarketerPayout::query()
            ->with([
                'marketer:id,code,user_id',
                'marketer.user:id,name,email',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
                'requestedBy:id,name',
                'approvedBy:id,name',
                'rejectedBy:id,name',
                'paidBy:id,name',
            ])
            ->status($filters['status'] ?? null)
            ->when($filters['marketer_id'] ?? null, fn ($q, $v) => $q->where('marketer_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereHas('marketer', function ($w) use ($term) {
                    $w->where('code', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $totals = [
            'requested_count' => MarketerPayout::requested()->count(),
            'approved_count' => MarketerPayout::approved()->count(),
            'rejected_count' => MarketerPayout::rejected()->count(),
            'paid_count' => MarketerPayout::where('status', MarketerPayout::STATUS_PAID)->count(),
            'requested_amount' => (float) MarketerPayout::requested()->sum('amount'),
            'approved_amount' => (float) MarketerPayout::approved()->sum('amount'),
            'rejected_amount' => (float) MarketerPayout::rejected()->sum('amount'),
            'paid_amount' => (float) MarketerPayout::where('status', MarketerPayout::STATUS_PAID)->sum('amount'),
        ];

        return Inertia::render('MarketerPayouts/Index', [
            'payouts' => $payouts,
            'filters' => $filters,
            'totals' => $totals,
            'statuses' => MarketerPayout::STATUSES,
            'cashboxes' => Cashbox::active()
                ->orderBy('name')
                ->get(['id', 'name', 'currency_code', 'allow_negative_balance'])
                ->map(fn (Cashbox $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'currency_code' => $c->currency_code,
                    'allow_negative_balance' => $c->allow_negative_balance,
                    'balance' => $c->balance(),
                ])
                ->all(),
            'payment_methods' => PaymentMethod::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MarketerPayouts/Create', [
            'marketers' => Marketer::query()
                ->with(['user:id,name,email', 'wallet'])
                ->where('status', 'Active')
                ->orderBy('code')
                ->get(['id', 'code', 'user_id', 'status'])
                ->map(fn (Marketer $m) => [
                    'id' => $m->id,
                    'code' => $m->code,
                    'name' => $m->user?->name,
                    'balance' => (float) ($m->wallet?->balance ?? 0),
                ])
                ->values(),
        ]);
    }

    public function store(MarketerPayoutRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $marketer = Marketer::findOrFail($data['marketer_id']);

        try {
            $this->service->requestPayout($marketer, $request->user(), [
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('marketer-payouts.index')->with('success', 'Payout requested.');
    }

    public function edit(MarketerPayout $payout): Response|RedirectResponse
    {
        if (! $payout->canBeEdited()) {
            return redirect()
                ->route('marketer-payouts.index')
                ->with('error', "Payout #{$payout->id} cannot be edited (status: {$payout->status}).");
        }

        return Inertia::render('MarketerPayouts/Edit', [
            'payout' => $payout->load(['marketer:id,code,user_id', 'marketer.user:id,name']),
        ]);
    }

    public function update(MarketerPayoutRequest $request, MarketerPayout $payout): RedirectResponse
    {
        if (! $payout->canBeEdited()) {
            return back()->with(
                'error',
                "Payout #{$payout->id} cannot be edited (status: {$payout->status})."
            );
        }

        $data = $request->validated();
        $payout->fill([
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
        ])->save();

        AuditLogService::logModelChange($payout, 'marketer_payout_updated', MarketerPayoutService::MODULE);

        return redirect()->route('marketer-payouts.index')->with('success', 'Payout updated.');
    }

    public function destroy(MarketerPayout $payout): RedirectResponse
    {
        if (! $payout->canBeDeleted()) {
            return back()->with(
                'error',
                "Payout #{$payout->id} cannot be deleted (status: {$payout->status})."
            );
        }

        $id = $payout->id;
        $payout->delete();
        AuditLogService::log(
            action: 'marketer_payout_deleted',
            module: MarketerPayoutService::MODULE,
            recordType: MarketerPayout::class,
            recordId: $id,
        );

        return redirect()->route('marketer-payouts.index')->with('success', 'Payout deleted.');
    }

    public function approve(Request $request, MarketerPayout $payout): RedirectResponse
    {
        try {
            $this->service->approve($payout, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout approved.');
    }

    public function reject(Request $request, MarketerPayout $payout): RedirectResponse
    {
        try {
            $this->service->reject($payout, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout rejected.');
    }

    public function pay(Request $request, MarketerPayout $payout): RedirectResponse
    {
        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $this->service->pay($payout, $request->user(), $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout paid from cashbox.');
    }
}
