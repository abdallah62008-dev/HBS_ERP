<?php

namespace App\Http\Controllers;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Marketer;
use App\Models\PaymentMethod;
use App\Services\FinanceReportsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance Phase 5E — read-only finance reports.
 *
 * All endpoints are GET. No mutations. Permission gating happens at
 * the route layer (`finance_reports.view`). The controller is thin —
 * filter parsing + render call. Heavy lifting lives in
 * `FinanceReportsService`.
 */
class FinanceReportsController extends Controller
{
    public function __construct(
        private readonly FinanceReportsService $reports,
    ) {}

    public function index(Request $request): Response
    {
        $data = $this->reports->overview($request->from, $request->to);

        return Inertia::render('FinanceReports/Index', $data);
    }

    public function cashboxes(Request $request): Response
    {
        $data = $this->reports->cashboxes(
            $request->from,
            $request->to,
            $request->string('type')->toString() ?: null,
            $request->string('active')->toString() ?: null,
        );

        return Inertia::render('FinanceReports/Cashboxes', $data);
    }

    public function movements(Request $request): Response
    {
        $filters = $request->only(['cashbox_id', 'direction', 'source_type', 'payment_method_id']);

        $rows = $this->reports->movements($request->from, $request->to, $filters);
        $totals = $this->reports->movementsTotals($request->from, $request->to, $filters);

        ['from' => $from, 'to' => $to] = $this->reports->dateRange($request->from, $request->to);

        return Inertia::render('FinanceReports/Movements', [
            'from' => $from,
            'to' => $to,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'cashboxes' => $this->cashboxList(),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code']),
            'source_types' => CashboxTransaction::PHASE_5D_SOURCE_TYPES,
            'directions' => [CashboxTransaction::DIRECTION_IN, CashboxTransaction::DIRECTION_OUT],
        ]);
    }

    public function collections(Request $request): Response
    {
        $filters = $request->only(['cashbox_id', 'payment_method_id', 'posted', 'settlement_status']);

        $data = $this->reports->collections($request->from, $request->to, $filters);

        return Inertia::render('FinanceReports/Collections', array_merge($data, [
            'cashboxes' => $this->cashboxList(),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code']),
        ]));
    }

    public function expenses(Request $request): Response
    {
        $filters = $request->only(['cashbox_id', 'payment_method_id', 'posted']);

        $data = $this->reports->expenses($request->from, $request->to, $filters);

        return Inertia::render('FinanceReports/Expenses', array_merge($data, [
            'cashboxes' => $this->cashboxList(),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code']),
        ]));
    }

    public function refunds(Request $request): Response
    {
        $filters = $request->only(['status', 'cashbox_id', 'payment_method_id', 'order_id']);

        $data = $this->reports->refunds($request->from, $request->to, $filters);

        return Inertia::render('FinanceReports/Refunds', array_merge($data, [
            'cashboxes' => $this->cashboxList(),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code']),
        ]));
    }

    public function marketerPayouts(Request $request): Response
    {
        $filters = $request->only(['status', 'cashbox_id', 'payment_method_id', 'marketer_id']);

        $data = $this->reports->marketerPayouts($request->from, $request->to, $filters);

        return Inertia::render('FinanceReports/MarketerPayouts', array_merge($data, [
            'cashboxes' => $this->cashboxList(),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code']),
            'marketers' => Marketer::with('user:id,name')
                ->orderBy('code')
                ->get(['id', 'code', 'user_id'])
                ->map(fn (Marketer $m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->user?->name])
                ->values(),
        ]));
    }

    public function transfers(Request $request): Response
    {
        $filters = $request->only(['from_cashbox_id', 'to_cashbox_id']);

        $data = $this->reports->transfers($request->from, $request->to, $filters);

        return Inertia::render('FinanceReports/Transfers', array_merge($data, [
            'cashboxes' => $this->cashboxList(),
        ]));
    }

    public function cashFlow(Request $request): Response
    {
        $cashboxId = $request->cashbox_id ? (int) $request->cashbox_id : null;

        $data = $this->reports->cashFlow($request->from, $request->to, $cashboxId);

        return Inertia::render('FinanceReports/CashFlow', $data);
    }

    /**
     * Cashboxes for filter dropdowns — id, name, currency, active flag.
     */
    private function cashboxList(): array
    {
        return Cashbox::query()
            ->orderBy('name')
            ->get(['id', 'name', 'currency_code', 'is_active'])
            ->map(fn (Cashbox $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency_code' => $c->currency_code,
                'is_active' => (bool) $c->is_active,
            ])
            ->all();
    }
}
