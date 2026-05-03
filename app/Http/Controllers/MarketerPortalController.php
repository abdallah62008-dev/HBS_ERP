<?php

namespace App\Http\Controllers;

use App\Models\MarketerProductPrice;
use App\Models\MarketerTransaction;
use App\Models\Order;
use App\Services\MarketerWalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The marketer self-service portal at /marketer/*. Every method in this
 * controller MUST start by resolving the current marketer via the
 * authenticated user and abort if the user has no marketer record.
 *
 * The Order model already has a `forCurrentMarketer` scope that
 * filters by the user's marketer_id; we use it here, but also
 * defensive-load relations only after scoping.
 */
class MarketerPortalController extends Controller
{
    public function __construct(
        private readonly MarketerWalletService $wallet,
    ) {}

    public function dashboard(Request $request): Response
    {
        $marketer = $this->resolveMarketer();

        $kpis = [
            'total_orders' => Order::where('marketer_id', $marketer->id)->count(),
            'delivered_orders' => Order::where('marketer_id', $marketer->id)->where('status', 'Delivered')->count(),
            'returned_orders' => Order::where('marketer_id', $marketer->id)->where('status', 'Returned')->count(),
            'open_orders' => Order::where('marketer_id', $marketer->id)->open()->count(),
        ];

        $marketer->load('wallet');
        $this->wallet->ensureWallet($marketer);

        return Inertia::render('MarketerPortal/Dashboard', [
            'marketer' => $marketer->refresh()->load('wallet', 'priceGroup'),
            'kpis' => $kpis,
            'recent_orders' => Order::where('marketer_id', $marketer->id)
                ->latest('id')->limit(10)
                ->get(['id', 'order_number', 'customer_name', 'status', 'total_amount', 'created_at']),
        ]);
    }

    public function orders(Request $request): Response
    {
        $marketer = $this->resolveMarketer();

        $orders = Order::query()
            ->where('marketer_id', $marketer->id)
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('order_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%");
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('MarketerPortal/Orders', [
            'orders' => $orders,
            'filters' => $request->only(['q', 'status']),
            'marketer' => $marketer,
        ]);
    }

    public function showOrder(Order $order): Response
    {
        $marketer = $this->resolveMarketer();

        if ($order->marketer_id !== $marketer->id) {
            abort(403, 'You do not have access to this order.');
        }

        $order->load('items', 'customer:id,name,primary_phone');

        // The marketer transaction for THIS order is the truth they care
        // about (vs. the order's net_profit which uses internal cost).
        $tx = MarketerTransaction::where('order_id', $order->id)
            ->where('marketer_id', $marketer->id)
            ->whereIn('transaction_type', [
                MarketerTransaction::TYPE_EXPECTED,
                MarketerTransaction::TYPE_PENDING,
                MarketerTransaction::TYPE_EARNED,
                MarketerTransaction::TYPE_CANCELLED,
            ])
            ->first();

        return Inertia::render('MarketerPortal/OrderShow', [
            'order' => $order,
            'profit_tx' => $tx,
        ]);
    }

    public function wallet(): Response
    {
        $marketer = $this->resolveMarketer();
        $this->wallet->recalculateWallet($marketer);

        $transactions = MarketerTransaction::where('marketer_id', $marketer->id)
            ->with('order:id,order_number,status')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('MarketerPortal/Wallet', [
            'marketer' => $marketer->refresh()->load('wallet'),
            'transactions' => $transactions,
        ]);
    }

    public function statement(): Response
    {
        $marketer = $this->resolveMarketer();

        $payouts = MarketerTransaction::where('marketer_id', $marketer->id)
            ->where('transaction_type', MarketerTransaction::TYPE_PAYOUT)
            ->orderBy('created_at')
            ->get();

        $earned = MarketerTransaction::where('marketer_id', $marketer->id)
            ->where('transaction_type', MarketerTransaction::TYPE_EARNED)
            ->with('order:id,order_number,delivered_at')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('MarketerPortal/Statement', [
            'marketer' => $marketer->refresh()->load('wallet'),
            'payouts' => $payouts,
            'earned' => $earned,
        ]);
    }

    public function products(): Response
    {
        $marketer = $this->resolveMarketer();

        $prices = MarketerProductPrice::query()
            ->where('marketer_price_group_id', $marketer->price_group_id)
            ->with('product:id,sku,name,image_url,minimum_selling_price')
            ->orderBy('product_id')
            ->paginate(50);

        return Inertia::render('MarketerPortal/Products', [
            'marketer' => $marketer,
            'prices' => $prices,
        ]);
    }

    /** Resolves the marketer record for the current user; aborts 403 if missing. */
    private function resolveMarketer()
    {
        $user = auth()->user();
        $marketer = $user?->marketer;

        if (! $marketer) {
            abort(403, 'No marketer record linked to your account. Ask an administrator.');
        }

        return $marketer;
    }
}
