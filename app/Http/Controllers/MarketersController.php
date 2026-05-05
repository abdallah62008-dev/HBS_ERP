<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketerPriceRequest;
use App\Http\Requests\MarketerRequest;
use App\Models\Marketer;
use App\Models\MarketerPriceGroup;
use App\Models\MarketerProductPrice;
use App\Models\MarketerTransaction;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MarketerWalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin-side marketer management. The marketer-self portal lives in
 * MarketerPortalController and respects strict ownership scoping.
 */
class MarketersController extends Controller
{
    public function __construct(
        private readonly MarketerWalletService $wallet,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status', 'price_group_id']);

        $marketers = Marketer::query()
            ->with(['user:id,name,email', 'priceGroup:id,name', 'priceTier:id,code,name', 'wallet'])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('code', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['price_group_id'] ?? null, fn ($q, $v) => $q->where('price_group_id', $v))
            ->withCount('orders')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Marketers/Index', [
            'marketers' => $marketers,
            'filters' => $filters,
            'price_groups' => MarketerPriceGroup::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Marketers/Create', [
            'price_groups' => MarketerPriceGroup::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'marketer_tiers' => $this->marketerTiers(),
        ]);
    }

    public function store(MarketerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $marketer = DB::transaction(function () use ($data) {
                $marketerRole = Role::where('slug', 'marketer')->firstOrFail();

                // Resolve user — either pick an existing one or create inline.
                if (! empty($data['user_id'])) {
                    $user = User::findOrFail($data['user_id']);
                } else {
                    $user = User::create([
                        'name' => $data['user']['name'],
                        'email' => $data['user']['email'],
                        'password' => Hash::make($data['user']['password']),
                        'role_id' => $marketerRole->id,
                        'status' => 'Active',
                    ]);
                }

                // Force the marketer role onto the user account so login works.
                $user->forceFill(['role_id' => $marketerRole->id])->save();

                $marketer = Marketer::create([
                    'user_id' => $user->id,
                    'code' => $data['code'],
                    'price_group_id' => $data['price_group_id'],
                    'marketer_price_tier_id' => $data['marketer_price_tier_id'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'status' => $data['status'] ?? 'Active',
                    'shipping_deducted' => $data['shipping_deducted'] ?? true,
                    'tax_deducted' => $data['tax_deducted'] ?? true,
                    'commission_after_delivery_only' => $data['commission_after_delivery_only'] ?? true,
                    'settlement_cycle' => $data['settlement_cycle'] ?? 'Weekly',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $this->wallet->ensureWallet($marketer);

                AuditLogService::logModelChange($marketer, 'created', 'marketers');

                return $marketer;
            });
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('marketers.show', $marketer)->with('success', "Marketer {$marketer->code} created.");
    }

    public function show(Marketer $marketer): Response
    {
        $marketer->load(['user:id,name,email', 'priceGroup', 'priceTier:id,code,name', 'wallet']);
        $this->wallet->ensureWallet($marketer);

        $recentTx = MarketerTransaction::where('marketer_id', $marketer->id)
            ->with('order:id,order_number,status,total_amount')
            ->latest('id')
            ->limit(20)
            ->get();

        return Inertia::render('Marketers/Show', [
            'marketer' => $marketer,
            'recent_transactions' => $recentTx,
        ]);
    }

    public function edit(Marketer $marketer): Response
    {
        $marketer->load('user:id,name,email');
        return Inertia::render('Marketers/Edit', [
            'marketer' => $marketer,
            'price_groups' => MarketerPriceGroup::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'marketer_tiers' => $this->marketerTiers(),
        ]);
    }

    public function update(MarketerRequest $request, Marketer $marketer): RedirectResponse
    {
        $data = $request->validated();
        unset($data['user'], $data['user_id']);

        $marketer->fill([
            ...$data,
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($marketer, 'updated', 'marketers');

        return redirect()->route('marketers.show', $marketer)->with('success', 'Marketer updated.');
    }

    /**
     * Active tier rows (Phase 5.6 codes A/B/D/E) for the marketer create/edit
     * dropdown. Distinct from `price_groups` (which still includes legacy
     * Bronze/Silver/Gold/VIP groups used by per-(group, product) pricing).
     *
     * @return array<int, array{id:int, code:string, name:string, sort_order:int}>
     */
    private function marketerTiers(): array
    {
        return MarketerPriceGroup::query()
            ->whereNotNull('code')
            ->where('status', 'Active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'sort_order'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'code' => $g->code,
                'name' => $g->name,
                'sort_order' => (int) ($g->sort_order ?? 0),
            ])
            ->all();
    }

    /**
     * Wallet view: full transaction list + recompute on demand.
     */
    public function wallet(Marketer $marketer): Response
    {
        $marketer->load(['user:id,name,email', 'priceGroup', 'wallet']);
        $this->wallet->recalculateWallet($marketer);

        $transactions = MarketerTransaction::where('marketer_id', $marketer->id)
            ->with('order:id,order_number,status')
            ->latest('id')
            ->paginate(50);

        return Inertia::render('Marketers/Wallet', [
            'marketer' => $marketer->refresh(),
            'transactions' => $transactions,
        ]);
    }

    public function payout(Request $request, Marketer $marketer): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->wallet->payout($marketer, (float) $data['amount'], $data['notes'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout recorded.');
    }

    public function adjust(Request $request, Marketer $marketer): RedirectResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'numeric'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->wallet->adjust($marketer, (float) $data['delta'], $data['notes']);

        return back()->with('success', 'Adjustment applied.');
    }

    /* ─────────────── Marketer Product Prices ─────────────── */

    public function prices(Marketer $marketer): Response
    {
        $marketer->load('priceGroup');

        $prices = MarketerProductPrice::query()
            ->where('marketer_price_group_id', $marketer->price_group_id)
            ->with('product:id,sku,name,selling_price')
            ->orderBy('product_id')
            ->paginate(50);

        return Inertia::render('Marketers/Prices', [
            'marketer' => $marketer,
            'prices' => $prices,
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name', 'cost_price', 'selling_price', 'minimum_selling_price']),
        ]);
    }

    public function storePrice(MarketerPriceRequest $request, Marketer $marketer): RedirectResponse
    {
        $data = $request->validated();

        MarketerProductPrice::updateOrCreate(
            [
                'marketer_price_group_id' => $marketer->price_group_id,
                'product_id' => $data['product_id'],
                'product_variant_id' => $data['product_variant_id'] ?? null,
            ],
            [
                'trade_price' => $data['trade_price'],
                'minimum_selling_price' => $data['minimum_selling_price'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ],
        );

        return back()->with('success', 'Price saved.');
    }

    public function destroyPrice(Marketer $marketer, MarketerProductPrice $price): RedirectResponse
    {
        $price->delete();
        return back()->with('success', 'Price removed.');
    }
}
