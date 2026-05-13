<?php

namespace Tests\Feature\Finance;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CollectionCashboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 3 — Collections × Cashboxes feature coverage.
 *
 * Validates the business rules from docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md §3:
 *   - posting writes exactly one cashbox_transactions row (IN, positive)
 *   - source_type='collection', source_id=collection.id
 *   - collection row stamps cashbox_transaction_id + cashbox_posted_at
 *   - double-posting is rejected
 *   - inactive cashbox / payment method are rejected
 *   - non-postable status is rejected (e.g. courier COD before settlement)
 *   - historical (null-cashbox) rows continue to load cleanly
 *   - permission gating: assigning fields ≠ posting; the post action
 *     specifically requires `collections.reconcile_settlement`
 */
class CollectionCashboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();

        $this->customer = Customer::create([
            'name' => 'Collection Test Customer',
            'primary_phone' => '01099991111',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Cashbox Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── Assignment via update endpoint ────────────────────── */

    public function test_authorized_user_can_assign_cashbox_and_payment_method_without_posting(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->put('/collections/' . $collection->id, [
            'collection_status' => 'Collected',
            'amount_collected' => 100,
            'payment_method_id' => $method->id,
            'cashbox_id' => $cashbox->id,
        ])->assertRedirect();

        $fresh = $collection->fresh();
        $this->assertSame($cashbox->id, $fresh->cashbox_id);
        $this->assertSame($method->id, $fresh->payment_method_id);
        // Assignment alone does NOT post — those fields stay null.
        $this->assertNull($fresh->cashbox_transaction_id);
        $this->assertNull($fresh->cashbox_posted_at);
    }

    /* ────────────────────── Posting flow ────────────────────── */

    public function test_posting_collection_creates_one_cashbox_in_transaction(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 250, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox(['name' => 'Main Cash']);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $txs = CashboxTransaction::where('source_type', 'collection')
            ->where('source_id', $collection->id)
            ->get();
        $this->assertCount(1, $txs);

        $tx = $txs->first();
        $this->assertSame('in', $tx->direction);
        $this->assertSame('250.00', (string) $tx->amount);
        $this->assertSame($cashbox->id, $tx->cashbox_id);
        $this->assertSame($method->id, $tx->payment_method_id);
        $this->assertSame('collection', $tx->source_type);
        $this->assertSame($collection->id, $tx->source_id);

        // Cashbox balance reflects the inflow.
        $this->assertSame(250.0, $cashbox->fresh()->balance());

        // Collection now linked to the ledger row.
        $fresh = $collection->fresh();
        $this->assertSame($tx->id, $fresh->cashbox_transaction_id);
        $this->assertNotNull($fresh->cashbox_posted_at);
    }

    public function test_posting_can_override_amount(): void
    {
        // Operator records partial collection of 60 instead of 100.
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'amount' => 60,
        ])->assertRedirect();

        $this->assertSame(60.0, $cashbox->fresh()->balance());
        $this->assertSame('60.00', (string) $collection->fresh()->amount_collected);
    }

    public function test_cannot_post_same_collection_twice(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        // First post — succeeds.
        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        // Second post — same collection, must NOT write a new transaction.
        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        // Exactly one cashbox transaction still.
        $this->assertSame(1, CashboxTransaction::where('source_type', 'collection')->where('source_id', $collection->id)->count());
        $this->assertSame(100.0, $cashbox->fresh()->balance());
    }

    public function test_inactive_cashbox_cannot_be_used(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox(['is_active' => false]);
        $method = $this->getMethod('cash');

        $response = $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect();
        $this->assertSame(0, CashboxTransaction::where('source_type', 'collection')->count());
        $this->assertNull($collection->fresh()->cashbox_transaction_id);
    }

    public function test_inactive_payment_method_cannot_be_used(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');
        $method->update(['is_active' => false]);

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'collection')->count());
    }

    public function test_courier_cod_collection_in_pending_settlement_status_is_not_postable(): void
    {
        // Mirrors the Phase 0 rule: courier COD stays "Pending Settlement"
        // until the courier actually remits. Operators must move it to
        // "Settlement Received" (with reference + date) before posting.
        $collection = $this->makeCollection([
            'amount_collected' => 100,
            'collection_status' => 'Pending Settlement',
        ]);
        $cashbox = $this->makeCashbox();
        $courierMethod = $this->getMethod('courier_cod');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $courierMethod->id,
        ])->assertRedirect();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'collection')->count());
        $this->assertNull($collection->fresh()->cashbox_transaction_id);
    }

    public function test_courier_cod_posts_after_settlement_received(): void
    {
        $collection = $this->makeCollection([
            'amount_collected' => 100,
            'collection_status' => 'Settlement Received',
            'settlement_reference' => 'CR-2026-0001',
            'settlement_date' => now()->toDateString(),
        ]);
        $cashbox = $this->makeCashbox();
        $courierMethod = $this->getMethod('courier_cod');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $courierMethod->id,
        ])->assertRedirect();

        $this->assertSame(1, CashboxTransaction::where('source_type', 'collection')->count());
        $this->assertSame(100.0, $cashbox->fresh()->balance());
    }

    public function test_collection_with_zero_amount_cannot_be_posted(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 0, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'collection')->count());
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_unauthorized_user_cannot_post_collection_to_cashbox(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        // User has collections.update but NOT collections.reconcile_settlement.
        $restricted = $this->makeRestrictedUser(['collections.view', 'collections.update']);

        $this->actingAs($restricted)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertForbidden();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'collection')->count());
    }

    /* ────────────────────── Historical data tolerance ────────────────────── */

    public function test_historical_collections_with_null_cashbox_still_load(): void
    {
        // Pre-Phase-3 row with no cashbox / payment_method assigned.
        $this->makeCollection(['amount_collected' => 0, 'collection_status' => 'Not Collected']);

        $response = $this->actingAs($this->admin)->get('/collections');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Collections/Index')
            ->has('collections.data.0')
            ->where('collections.data.0.cashbox', null)
            ->where('collections.data.0.payment_method', null)
            ->where('collections.data.0.cashbox_transaction_id', null)
        );
    }

    /* ────────────────────── Audit log ────────────────────── */

    public function test_posting_writes_audit_log_entries(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/collections/' . $collection->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        // Two audit rows: the cashbox_transaction.created (on the tx) and
        // the posted_to_cashbox event (on the collection itself).
        $txAudit = \App\Models\AuditLog::where('action', 'cashbox_transaction.created')
            ->where('module', 'finance.collection')->count();
        $this->assertSame(1, $txAudit);

        $postAudit = \App\Models\AuditLog::where('action', 'posted_to_cashbox')
            ->where('record_type', Collection::class)
            ->where('record_id', $collection->id)->count();
        $this->assertSame(1, $postAudit);
    }

    /* ────────────────────── Service direct invocation ────────────────────── */

    public function test_service_throws_on_double_post(): void
    {
        $collection = $this->makeCollection(['amount_collected' => 100, 'collection_status' => 'Collected']);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin);
        $svc = app(CollectionCashboxService::class);

        $svc->postCollectionToCashbox($collection->fresh(), [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $svc->postCollectionToCashbox($collection->fresh(), [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;

        return Order::create([
            'order_number' => 'CC-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => 'Confirmed',
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => 100,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeCollection(array $overrides = []): Collection
    {
        $order = $this->makeOrder();
        return Collection::create(array_merge([
            'order_id' => $order->id,
            'shipping_company_id' => null,
            'amount_due' => 100,
            'amount_collected' => 0,
            'collection_status' => 'Not Collected',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        return Cashbox::create(array_merge([
            'name' => 'Collection Test Cashbox ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    private function getMethod(string $code): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Collection Restricted ' . uniqid(),
            'slug' => 'col-restricted-' . uniqid(),
            'description' => 'Collection cashbox test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted',
            'email' => 'col-restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
