<?php

namespace Tests\Feature\Finance;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\ExpenseCashboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance Phase 4.5 — Hardening guards.
 *
 * These tests pin the model-level guarantees added by Phase 4.5:
 *   - CashboxTransaction rows cannot be updated.
 *   - CashboxTransaction rows cannot be deleted.
 *   - Cashbox currency_code is immutable after first transaction.
 *   - Cashbox opening_balance is immutable after first transaction.
 *   - Posted Expense cannot be deleted at the model layer.
 *   - Unposted Expense can still be deleted (regression).
 *   - Posted Collection's amount_collected / settlement_date /
 *     settlement_reference are protected by the controller.
 *
 * Race-condition correctness (concurrent posts / overdraft) cannot be
 * exercised in SQLite single-process tests — it relies on real DB row
 * locks. The guard is encoded in the service via `lockForUpdate()` and
 * was added in the same phase; concurrency assertions live in code
 * review + production observation, not here.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ExpenseCategory $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->customer = Customer::create([
            'name' => 'Hardening Customer',
            'primary_phone' => '01099991234',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Hardening Street',
            'created_by' => $this->admin->id,
        ]);
        $this->expenseCategory = ExpenseCategory::firstOrFail();
    }

    /* ────────────────────── Fix E — CashboxTransaction append-only ────────────────────── */

    public function test_cashbox_transaction_cannot_be_updated(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $tx = CashboxTransaction::where('cashbox_id', $cashbox->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cashbox_transactions are append-only');

        $tx->amount = 9999;
        $tx->save();
    }

    public function test_cashbox_transaction_update_via_query_builder_is_not_caught_but_eloquent_is(): void
    {
        // Sanity check: the model-level guard catches Eloquent paths. DB
        // query builder UPDATE remains a developer responsibility (it
        // bypasses Eloquent events entirely). We pin Eloquent here.
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $tx = CashboxTransaction::where('cashbox_id', $cashbox->id)->firstOrFail();

        $caught = false;
        try {
            $tx->update(['amount' => 9999]);
        } catch (\RuntimeException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Eloquent update() should hit the updating event.');
    }

    public function test_cashbox_transaction_cannot_be_deleted(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $tx = CashboxTransaction::where('cashbox_id', $cashbox->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $tx->delete();
    }

    /* ────────────────────── Fix G — Cashbox immutable fields ────────────────────── */

    public function test_cashbox_currency_code_cannot_change_after_first_transaction(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100, 'currency_code' => 'EGP']);
        $this->assertTrue($cashbox->fresh()->hasTransactions());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('currency_code is immutable');

        $cashbox->currency_code = 'USD';
        $cashbox->save();
    }

    public function test_cashbox_currency_code_can_change_before_any_transaction(): void
    {
        // Created via Eloquent directly (no opening-balance tx is written).
        $cashbox = Cashbox::create([
            'name' => 'Fresh Cashbox ' . uniqid(),
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->assertFalse($cashbox->hasTransactions());

        // No exception — guard correctly allows the change pre-first-tx.
        $cashbox->currency_code = 'USD';
        $cashbox->save();

        $this->assertSame('USD', $cashbox->fresh()->currency_code);
    }

    public function test_cashbox_opening_balance_cannot_change_after_first_transaction(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $this->assertTrue($cashbox->fresh()->hasTransactions());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opening_balance is immutable');

        $cashbox->opening_balance = 9999;
        $cashbox->save();
    }

    public function test_cashbox_unrelated_fields_can_still_be_edited_after_first_transaction(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100, 'name' => 'Old name']);

        // No exception — guard targets only currency_code + opening_balance.
        $cashbox->name = 'New name';
        $cashbox->description = 'updated';
        $cashbox->allow_negative_balance = false;
        $cashbox->is_active = true;
        $cashbox->save();

        $fresh = $cashbox->fresh();
        $this->assertSame('New name', $fresh->name);
        $this->assertSame('updated', $fresh->description);
        $this->assertFalse($fresh->allow_negative_balance);
    }

    /* ────────────────────── Fix F — Posted Expense delete guard ────────────────────── */

    public function test_posted_expense_cannot_be_deleted_via_model(): void
    {
        $expense = $this->createPostedExpense();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('posted to a cashbox');

        // Direct model delete (bypassing the controller).
        $expense->delete();
    }

    public function test_unposted_expense_can_be_deleted_via_model(): void
    {
        $expense = Expense::create([
            'expense_category_id' => $this->expenseCategory->id,
            'title' => 'Unposted draft',
            'amount' => 50,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->assertFalse($expense->isPosted());

        // No exception — unposted expenses still soft-deletable.
        $expense->delete();

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    /* ────────────────────── Fix D — Posted Collection drift block ────────────────────── */

    public function test_posted_collection_cannot_change_amount_collected_via_update(): void
    {
        $collection = $this->createPostedCollection(amount: 250);

        $this->actingAs($this->admin)->put('/collections/' . $collection->id, [
            'collection_status' => $collection->collection_status,
            'amount_collected' => 9999,
        ])->assertRedirect();

        $this->assertSame(
            '250.00',
            (string) $collection->fresh()->amount_collected,
            'amount_collected must be locked once posted.'
        );
    }

    public function test_posted_collection_cannot_change_settlement_date_or_reference(): void
    {
        $collection = $this->createPostedCollection();
        $collection->update([
            'settlement_date' => now()->toDateString(),
            'settlement_reference' => 'ORIGINAL-REF',
        ]);
        $originalDate = $collection->fresh()->settlement_date?->toDateString();

        $this->actingAs($this->admin)->put('/collections/' . $collection->id, [
            'collection_status' => $collection->collection_status,
            'settlement_date' => now()->subDays(30)->toDateString(),
            'settlement_reference' => 'CHANGED-REF',
        ])->assertRedirect();

        $fresh = $collection->fresh();
        $this->assertSame($originalDate, $fresh->settlement_date?->toDateString(), 'settlement_date must be locked.');
        $this->assertSame('ORIGINAL-REF', $fresh->settlement_reference, 'settlement_reference must be locked.');
    }

    public function test_posted_collection_can_still_edit_notes(): void
    {
        $collection = $this->createPostedCollection();

        $this->actingAs($this->admin)->put('/collections/' . $collection->id, [
            'collection_status' => $collection->collection_status,
            'notes' => 'Updated note — financial fields stayed locked',
        ])->assertRedirect();

        $this->assertSame(
            'Updated note — financial fields stayed locked',
            $collection->fresh()->notes
        );
    }

    /* ────────────────────── Regression: existing service writes still work ────────────────────── */

    public function test_service_write_path_still_works_after_append_only_guard(): void
    {
        // The Phase 1 service writes opening_balance + adjustment rows
        // via Eloquent create(). create() does NOT trigger the updating
        // event — only INSERT runs — so the append-only guard must not
        // accidentally block the legitimate write path.
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $this->assertSame(100.0, $cashbox->fresh()->balance());

        // A second adjustment uses the same code path.
        $this->actingAs($this->admin);
        app(\App\Services\CashboxService::class)->createAdjustmentTransaction($cashbox, [
            'direction' => 'in',
            'amount' => 50,
            'notes' => 'top up',
        ]);

        $this->assertSame(150.0, $cashbox->fresh()->balance());
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;

        $opening = (float) ($overrides['opening_balance'] ?? 0);

        $cashbox = Cashbox::create(array_merge([
            'name' => 'Hardening Cashbox ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => $opening,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));

        if ($opening != 0) {
            CashboxTransaction::create([
                'cashbox_id' => $cashbox->id,
                'direction' => $opening >= 0 ? 'in' : 'out',
                'amount' => $opening,
                'occurred_at' => now(),
                'source_type' => 'opening_balance',
                'notes' => 'Test fixture opening balance.',
                'created_by' => $this->admin->id,
            ]);
        }

        return $cashbox;
    }

    private function createPostedExpense(): Expense
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = PaymentMethod::where('code', 'cash')->firstOrFail();

        $expense = Expense::create([
            'expense_category_id' => $this->expenseCategory->id,
            'title' => 'Posted expense fixture',
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        app(ExpenseCashboxService::class)->postExpenseToCashbox($expense, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        return $expense->fresh();
    }

    private function createPostedCollection(float $amount = 100): Collection
    {
        $order = Order::create([
            'order_number' => 'HRD-' . uniqid(),
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
            'total_amount' => $amount,
            'created_by' => $this->admin->id,
        ]);

        $collection = Collection::create([
            'order_id' => $order->id,
            'amount_due' => $amount,
            'amount_collected' => $amount,
            'collection_status' => 'Collected',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $cashbox = $this->makeCashbox();
        $method = PaymentMethod::where('code', 'cash')->firstOrFail();

        $this->actingAs($this->admin)->post(
            '/collections/' . $collection->id . '/post-to-cashbox',
            ['cashbox_id' => $cashbox->id, 'payment_method_id' => $method->id]
        )->assertRedirect();

        return $collection->fresh();
    }
}
