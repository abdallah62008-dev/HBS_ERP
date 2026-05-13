<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentMethodRequest;
use App\Models\Cashbox;
use App\Models\PaymentMethod;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance Phase 2 — Payment Methods CRUD.
 *
 * Per design: no destroy() method. Methods are retired with
 * `is_active = false`; the slug `payment_methods.deactivate` gates
 * the toggle endpoints.
 */
class PaymentMethodsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'type', 'status']);

        $methods = PaymentMethod::query()
            ->with('defaultCashbox:id,name,is_active')
            ->when($filters['q'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('name', 'like', "%{$v}%")
                      ->orWhere('code', 'like', "%{$v}%");
                });
            })
            ->ofType($filters['type'] ?? null)
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentMethod $pm) => [
                'id' => $pm->id,
                'name' => $pm->name,
                'code' => $pm->code,
                'type' => $pm->type,
                'is_active' => $pm->is_active,
                'default_cashbox' => $pm->defaultCashbox
                    ? ['id' => $pm->defaultCashbox->id, 'name' => $pm->defaultCashbox->name, 'is_active' => $pm->defaultCashbox->is_active]
                    : null,
                'description' => $pm->description,
            ])
            ->values()
            ->all();

        return Inertia::render('PaymentMethods/Index', [
            'paymentMethods' => $methods,
            'filters' => $filters,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PaymentMethods/Create', [
            'types' => PaymentMethod::TYPES,
            'cashboxes' => $this->activeCashboxesForSelect(),
        ]);
    }

    public function store(PaymentMethodRequest $request): RedirectResponse
    {
        $method = PaymentMethod::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($method, 'created', 'finance.payment_method');

        return redirect()
            ->route('payment-methods.index')
            ->with('success', 'Payment method created.');
    }

    public function edit(PaymentMethod $paymentMethod): Response
    {
        return Inertia::render('PaymentMethods/Edit', [
            'paymentMethod' => [
                'id' => $paymentMethod->id,
                'name' => $paymentMethod->name,
                'code' => $paymentMethod->code,
                'type' => $paymentMethod->type,
                'default_cashbox_id' => $paymentMethod->default_cashbox_id,
                'is_active' => $paymentMethod->is_active,
                'description' => $paymentMethod->description,
            ],
            'types' => PaymentMethod::TYPES,
            'cashboxes' => $this->activeCashboxesForSelect($paymentMethod->default_cashbox_id),
        ]);
    }

    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->fill([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($paymentMethod, 'updated', 'finance.payment_method');

        return redirect()
            ->route('payment-methods.index')
            ->with('success', 'Payment method updated.');
    }

    public function deactivate(PaymentMethod $paymentMethod): RedirectResponse
    {
        if ($paymentMethod->is_active) {
            $paymentMethod->is_active = false;
            $paymentMethod->updated_by = Auth::id();
            $paymentMethod->save();

            AuditLogService::log(
                action: 'deactivated',
                module: 'finance.payment_method',
                recordType: PaymentMethod::class,
                recordId: $paymentMethod->id,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
            );
        }

        return back()->with('success', 'Payment method deactivated.');
    }

    public function reactivate(PaymentMethod $paymentMethod): RedirectResponse
    {
        if (! $paymentMethod->is_active) {
            $paymentMethod->is_active = true;
            $paymentMethod->updated_by = Auth::id();
            $paymentMethod->save();

            AuditLogService::log(
                action: 'reactivated',
                module: 'finance.payment_method',
                recordType: PaymentMethod::class,
                recordId: $paymentMethod->id,
                oldValues: ['is_active' => false],
                newValues: ['is_active' => true],
            );
        }

        return back()->with('success', 'Payment method reactivated.');
    }

    /**
     * Active cashboxes for the "default cashbox" selector. If editing
     * an existing method whose current default is now inactive, that
     * specific row is included so the form can display it (and the
     * admin can change it).
     */
    private function activeCashboxesForSelect(?int $includeId = null): array
    {
        return Cashbox::query()
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', true);
                if ($includeId) {
                    $q->orWhere('id', $includeId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'currency_code', 'is_active'])
            ->map(fn (Cashbox $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency_code' => $c->currency_code,
                'is_active' => $c->is_active,
            ])
            ->all();
    }
}
