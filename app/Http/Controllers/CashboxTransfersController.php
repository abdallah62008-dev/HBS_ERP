<?php

namespace App\Http\Controllers;

use App\Models\Cashbox;
use App\Models\CashboxTransfer;
use App\Services\CashboxTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 2 — Cashbox Transfers list + create.
 *
 * Per design: no edit, no delete. Mistakes are corrected by creating
 * the opposite transfer (reversal-by-transaction).
 */
class CashboxTransfersController extends Controller
{
    public function __construct(private CashboxTransferService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['from', 'to', 'from_cashbox_id', 'to_cashbox_id']);

        $transfers = CashboxTransfer::query()
            ->with(['fromCashbox:id,name,currency_code', 'toCashbox:id,name,currency_code', 'createdBy:id,name'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('occurred_at', '<=', $v))
            ->when($filters['from_cashbox_id'] ?? null, fn ($q, $v) => $q->where('from_cashbox_id', $v))
            ->when($filters['to_cashbox_id'] ?? null, fn ($q, $v) => $q->where('to_cashbox_id', $v))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (CashboxTransfer $t) => [
                'id' => $t->id,
                'occurred_at' => $t->occurred_at?->toDateTimeString(),
                'amount' => (float) $t->amount,
                'reason' => $t->reason,
                'from_cashbox' => $t->fromCashbox ? [
                    'id' => $t->fromCashbox->id,
                    'name' => $t->fromCashbox->name,
                    'currency_code' => $t->fromCashbox->currency_code,
                ] : null,
                'to_cashbox' => $t->toCashbox ? [
                    'id' => $t->toCashbox->id,
                    'name' => $t->toCashbox->name,
                    'currency_code' => $t->toCashbox->currency_code,
                ] : null,
                'created_by' => $t->createdBy?->only(['id', 'name']),
            ]);

        return Inertia::render('CashboxTransfers/Index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'cashboxes' => $this->cashboxesForFilter(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CashboxTransfers/Create', [
            'cashboxes' => $this->activeCashboxesWithBalances(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'to_cashbox_id' => ['required', 'integer', 'exists:cashboxes,id', 'different:from_cashbox_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->service->createTransfer($data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()
                ->withInput()
                ->withErrors(['from_cashbox_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('cashbox-transfers.index')
            ->with('success', 'Transfer recorded.');
    }

    /**
     * Cashboxes for the filter dropdowns — active + inactive (history).
     */
    private function cashboxesForFilter(): array
    {
        return Cashbox::orderBy('name')
            ->get(['id', 'name', 'currency_code', 'is_active'])
            ->map(fn (Cashbox $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency_code' => $c->currency_code,
                'is_active' => $c->is_active,
            ])
            ->all();
    }

    /**
     * Active cashboxes for the create form — includes live balances so
     * the operator sees what's available before transferring out.
     */
    private function activeCashboxesWithBalances(): array
    {
        return Cashbox::active()
            ->orderBy('name')
            ->get(['id', 'name', 'currency_code', 'allow_negative_balance'])
            ->map(fn (Cashbox $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency_code' => $c->currency_code,
                'allow_negative_balance' => $c->allow_negative_balance,
                'balance' => $c->balance(),
            ])
            ->all();
    }
}
