<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\CashboxTransfer;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\MarketerPayout;
use App\Models\Refund;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finance Phase 5E — read-only reports backed by the cashbox ledger.
 *
 * Source of truth contract:
 *   - Actual cash movement: `cashbox_transactions` (signed-amount ledger,
 *     append-only per Phase 4.5).
 *   - Operational drill-down + status: domain tables (`collections`,
 *     `expenses`, `refunds`, `cashbox_transfers`, `marketer_payouts`).
 *
 * No method in this service mutates a row. No write queries. Pagination
 * is used for unbounded tables. Aggregates filter by date range before
 * grouping where practical.
 */
class FinanceReportsService
{
    /** Default date range — current month, mirroring ReportsService convention. */
    public function dateRange(?string $from, ?string $to): array
    {
        return [
            'from' => $from ?: now()->startOfMonth()->toDateString(),
            'to' => $to ?: now()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * 6.1 — Overview landing-page summary cards.
     *
     * The "cash movement" buckets sum `cashbox_transactions` because that
     * is the canonical money-moved ledger. Transfers are deliberately
     * excluded from net inflow/outflow (they don't change the company-
     * wide cash position).
     */
    public function overview(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);
        [$fromAt, $toAt] = $this->boundary($from, $to);

        $totalBalance = (float) Cashbox::active()->get()->sum(fn (Cashbox $c) => $c->balance());

        $tx = CashboxTransaction::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt]);

        $inflow = (float) (clone $tx)->where('amount', '>', 0)
            ->where('source_type', '!=', CashboxTransaction::SOURCE_TRANSFER)
            ->sum('amount');

        $outflow = (float) (clone $tx)->where('amount', '<', 0)
            ->where('source_type', '!=', CashboxTransaction::SOURCE_TRANSFER)
            ->sum('amount');

        $bySource = (clone $tx)
            ->selectRaw('source_type, COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
                COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS outflow,
                COUNT(*) AS count')
            ->groupBy('source_type')
            ->get()
            ->keyBy('source_type');

        return [
            'from' => $from, 'to' => $to,
            'total_balance' => round($totalBalance, 2),
            'inflow' => round($inflow, 2),
            'outflow' => round($outflow, 2), // signed-negative total
            'net' => round($inflow + $outflow, 2),
            'collections_posted' => (float) ($bySource[CashboxTransaction::SOURCE_COLLECTION]->inflow ?? 0),
            'expenses_posted' => (float) abs($bySource[CashboxTransaction::SOURCE_EXPENSE]->outflow ?? 0),
            'refunds_paid' => (float) abs($bySource[CashboxTransaction::SOURCE_REFUND]->outflow ?? 0),
            'marketer_payouts_paid' => (float) abs($bySource[CashboxTransaction::SOURCE_MARKETER_PAYOUT]->outflow ?? 0),
            'transfers_count' => (int) ($bySource[CashboxTransaction::SOURCE_TRANSFER]->count ?? 0),
            'transfers_amount' => (float) ($bySource[CashboxTransaction::SOURCE_TRANSFER]->inflow ?? 0), // each transfer creates one IN row equal to amount
        ];
    }

    /**
     * 6.2 — Per-cashbox summary.
     */
    public function cashboxes(?string $from, ?string $to, ?string $type, ?string $active): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);
        [$fromAt, $toAt] = $this->boundary($from, $to);

        $cashboxes = Cashbox::query()
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->when($active === 'active', fn ($q) => $q->where('is_active', true))
            ->when($active === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->get();

        $stats = CashboxTransaction::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->selectRaw('cashbox_id,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
                COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS outflow,
                COUNT(*) AS tx_count,
                MAX(occurred_at) AS last_tx')
            ->groupBy('cashbox_id')
            ->get()
            ->keyBy('cashbox_id');

        $rows = $cashboxes->map(function (Cashbox $c) use ($stats) {
            $s = $stats[$c->id] ?? null;
            return [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'currency_code' => $c->currency_code,
                'is_active' => (bool) $c->is_active,
                'allow_negative_balance' => (bool) $c->allow_negative_balance,
                'opening_balance' => (float) $c->opening_balance,
                'inflow' => (float) ($s->inflow ?? 0),
                'outflow' => (float) ($s->outflow ?? 0),
                'tx_count' => (int) ($s->tx_count ?? 0),
                'last_tx' => $s->last_tx ?? null,
                'balance' => $c->balance(),
            ];
        })->values();

        $totals = [
            'count' => $rows->count(),
            'active' => $rows->where('is_active', true)->count(),
            'inflow' => round($rows->sum('inflow'), 2),
            'outflow' => round($rows->sum('outflow'), 2),
            'total_balance' => round($rows->sum('balance'), 2),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => ['type' => $type, 'active' => $active],
            'types' => Cashbox::TYPES,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.3 — Cashbox transactions movement report (paginated).
     */
    public function movements(?string $from, ?string $to, array $filters): LengthAwarePaginator
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);
        [$fromAt, $toAt] = $this->boundary($from, $to);

        $q = CashboxTransaction::query()
            ->with([
                'cashbox:id,name,currency_code',
                'createdBy:id,name',
                // Phase 2 payment method relation exists on the model fillable but not as relation by default.
            ])
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        return $q->paginate(50)->withQueryString();
    }

    public function movementsTotals(?string $from, ?string $to, array $filters): array
    {
        [$fromAt, $toAt] = $this->boundary(...array_values($this->dateRange($from, $to)));

        $q = CashboxTransaction::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v));

        $row = $q->selectRaw('
            COUNT(*) AS count,
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
            COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS outflow
        ')->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'inflow' => round((float) ($row->inflow ?? 0), 2),
            'outflow' => round((float) ($row->outflow ?? 0), 2),
            'net' => round((float) ($row->inflow ?? 0) + (float) ($row->outflow ?? 0), 2),
        ];
    }

    /**
     * 6.4 — Collections report.
     */
    public function collections(?string $from, ?string $to, array $filters): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $posted = $filters['posted'] ?? null; // 'posted' | 'unposted' | null

        $q = Collection::query()
            ->with([
                'order:id,order_number,customer_name,customer_phone',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
            ])
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->when($filters['settlement_status'] ?? null, fn ($q, $v) => $q->where('collection_status', $v))
            ->when($posted === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
            ->when($posted === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
            // Use cashbox_posted_at when posted; otherwise fall back to created_at
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween('cashbox_posted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                  ->orWhere(function ($w2) use ($from, $to) {
                      $w2->whereNull('cashbox_posted_at')
                         ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
                  });
            })
            ->orderByDesc('id');

        $rows = $q->paginate(30)->withQueryString();

        $totals = [
            'count' => Collection::query()
                ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
                ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
                ->when($filters['settlement_status'] ?? null, fn ($q, $v) => $q->where('collection_status', $v))
                ->when($posted === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
                ->when($posted === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->count(),
            'posted_amount' => (float) Collection::query()
                ->whereNotNull('cashbox_transaction_id')
                ->whereBetween('cashbox_posted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount_collected'),
            'unposted_amount' => (float) Collection::query()
                ->whereNull('cashbox_transaction_id')
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount_collected'),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => $filters,
            'collection_statuses' => Collection::STATUSES,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.5 — Expenses report.
     */
    public function expenses(?string $from, ?string $to, array $filters): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $posted = $filters['posted'] ?? null;

        $q = Expense::query()
            ->with([
                'category:id,name',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
                'createdBy:id,name',
            ])
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->when($posted === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
            ->when($posted === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
            ->whereBetween('expense_date', [$from, $to])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        $rows = $q->paginate(30)->withQueryString();

        $totals = [
            'count' => Expense::query()
                ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
                ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
                ->when($posted === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
                ->when($posted === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
                ->whereBetween('expense_date', [$from, $to])
                ->count(),
            'posted_amount' => (float) Expense::query()
                ->whereNotNull('cashbox_transaction_id')
                ->whereBetween('expense_date', [$from, $to])
                ->sum('amount'),
            'unposted_amount' => (float) Expense::query()
                ->whereNull('cashbox_transaction_id')
                ->whereBetween('expense_date', [$from, $to])
                ->sum('amount'),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.6 — Refunds report.
     */
    public function refunds(?string $from, ?string $to, array $filters): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $q = Refund::query()
            ->with([
                'order:id,order_number,customer_name',
                'collection:id,order_id',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
                'requestedBy:id,name',
                'approvedBy:id,name',
                'paidBy:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->when($filters['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderByDesc('id');

        $rows = $q->paginate(30)->withQueryString();

        $totals = [
            'requested_amount' => (float) Refund::query()
                ->where('status', Refund::STATUS_REQUESTED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'approved_amount' => (float) Refund::query()
                ->where('status', Refund::STATUS_APPROVED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'paid_amount' => (float) Refund::query()
                ->where('status', Refund::STATUS_PAID)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'rejected_amount' => (float) Refund::query()
                ->where('status', Refund::STATUS_REJECTED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => $filters,
            'statuses' => Refund::STATUSES,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.7 — Marketer payouts report.
     */
    public function marketerPayouts(?string $from, ?string $to, array $filters): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $q = MarketerPayout::query()
            ->with([
                'marketer:id,code,user_id',
                'marketer.user:id,name',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
                'requestedBy:id,name',
                'approvedBy:id,name',
                'paidBy:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['marketer_id'] ?? null, fn ($q, $v) => $q->where('marketer_id', $v))
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderByDesc('id');

        $rows = $q->paginate(30)->withQueryString();

        $totals = [
            'requested_amount' => (float) MarketerPayout::query()
                ->where('status', MarketerPayout::STATUS_REQUESTED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'approved_amount' => (float) MarketerPayout::query()
                ->where('status', MarketerPayout::STATUS_APPROVED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'paid_amount' => (float) MarketerPayout::query()
                ->where('status', MarketerPayout::STATUS_PAID)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
            'rejected_amount' => (float) MarketerPayout::query()
                ->where('status', MarketerPayout::STATUS_REJECTED)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->sum('amount'),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => $filters,
            'statuses' => MarketerPayout::STATUSES,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.8 — Cashbox transfers report.
     */
    public function transfers(?string $from, ?string $to, array $filters): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);
        [$fromAt, $toAt] = $this->boundary($from, $to);

        $q = CashboxTransfer::query()
            ->with([
                'fromCashbox:id,name,currency_code',
                'toCashbox:id,name,currency_code',
                'createdBy:id,name',
            ])
            ->when($filters['from_cashbox_id'] ?? null, fn ($q, $v) => $q->where('from_cashbox_id', $v))
            ->when($filters['to_cashbox_id'] ?? null, fn ($q, $v) => $q->where('to_cashbox_id', $v))
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $rows = $q->paginate(30)->withQueryString();

        $totals = [
            'count' => CashboxTransfer::query()
                ->whereBetween('occurred_at', [$fromAt, $toAt])
                ->count(),
            'total_amount' => (float) CashboxTransfer::query()
                ->whereBetween('occurred_at', [$fromAt, $toAt])
                ->sum('amount'),
        ];

        return [
            'from' => $from, 'to' => $to,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 6.9 — Cash flow report (cashbox-domain).
     *
     * Groups `cashbox_transactions` by source_type into inflow/outflow
     * buckets. Distinct from the operational-domain `ReportsService::cashFlow`
     * which queries orders/expenses/supplier_payments/marketer_transactions
     * directly. Both reports coexist.
     */
    public function cashFlow(?string $from, ?string $to, ?int $cashboxId): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);
        [$fromAt, $toAt] = $this->boundary($from, $to);

        $rows = CashboxTransaction::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->when($cashboxId, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->selectRaw('source_type,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
                COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS outflow,
                COUNT(*) AS count')
            ->groupBy('source_type')
            ->orderBy('source_type')
            ->get();

        $inflowTotal = (float) $rows->where('inflow', '>', 0)->sum('inflow');
        $outflowTotal = (float) $rows->where('outflow', '<', 0)->sum('outflow');

        // Exclude transfers from net (they cancel out across cashboxes).
        $nonTransferInflow = (float) $rows->where('source_type', '!=', CashboxTransaction::SOURCE_TRANSFER)->sum('inflow');
        $nonTransferOutflow = (float) $rows->where('source_type', '!=', CashboxTransaction::SOURCE_TRANSFER)->sum('outflow');

        return [
            'from' => $from, 'to' => $to,
            'cashbox_id' => $cashboxId,
            'cashboxes' => Cashbox::active()->orderBy('name')->get(['id', 'name', 'currency_code']),
            'rows' => $rows,
            'totals' => [
                'inflow' => round($inflowTotal, 2),
                'outflow' => round($outflowTotal, 2),
                'net_including_transfers' => round($inflowTotal + $outflowTotal, 2),
                'inflow_excluding_transfers' => round($nonTransferInflow, 2),
                'outflow_excluding_transfers' => round($nonTransferOutflow, 2),
                'net_excluding_transfers' => round($nonTransferInflow + $nonTransferOutflow, 2),
            ],
            'source_types' => CashboxTransaction::PHASE_5D_SOURCE_TYPES,
        ];
    }

    /* ────────────────────── Helpers ────────────────────── */

    /**
     * Convert Y-m-d date strings to the inclusive day-boundary datetimes
     * used by the timestamp-indexed columns.
     */
    private function boundary(string $from, string $to): array
    {
        return [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ];
    }
}
