<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ExpenseCashboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 4 — Expenses × Cashboxes feature coverage.
 *
 * Mirrors CollectionCashboxTest in structure. Validates the rules
 * from docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md §5:
 *   - new expense create writes exactly one cashbox_transactions row (OUT, negative)
 *   - source_type='expense', source_id=expense.id
 *   - expense row stamps cashbox_transaction_id + cashbox_posted_at
 *   - double-posting rejected
 *   - inactive cashbox / payment method rejected
 *   - insufficient balance blocks when allow_negative_balance=false
 *   - allow_negative_balance=true permits overdraft
 *   - posted expense cannot have financial fields edited
 *   - posted expense cannot be soft-deleted
 *   - permission gating for posting and retro-posting
 *   - audit log entries written
 */
class ExpenseCashboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->category = ExpenseCategory::firstOrFail();
    }

    /* ────────────────────── Create flow ────────────────────── */

    public function test_creating_expense_writes_one_out_cashbox_transaction(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Facebook Ads',
            'amount' => 250,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect('/expenses');

        $expense = Expense::firstOrFail();
        $tx = CashboxTransaction::where('source_type', 'expense')->firstOrFail();

        $this->assertSame('out', $tx->direction);
        $this->assertSame('-250.00', (string) $tx->amount);
        $this->assertSame($cashbox->id, $tx->cashbox_id);
        $this->assertSame($method->id, $tx->payment_method_id);
        $this->assertSame('expense', $tx->source_type);
        $this->assertSame($expense->id, $tx->source_id);

        // Expense linked back.
        $this->assertSame($tx->id, $expense->cashbox_transaction_id);
        $this->assertSame($cashbox->id, $expense->cashbox_id);
        $this->assertSame($method->id, $expense->payment_method_id);
        $this->assertNotNull($expense->cashbox_posted_at);

        // Cashbox balance reflects the OUT.
        $this->assertSame(750.0, $cashbox->fresh()->balance(), '1000 - 250 = 750');
    }

    public function test_creating_expense_requires_cashbox_and_payment_method(): void
    {
        $response = $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Missing cashbox',
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            // intentionally no cashbox_id / payment_method_id
        ]);

        $response->assertSessionHasErrors(['cashbox_id', 'payment_method_id']);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, CashboxTransaction::where('source_type', 'expense')->count());
    }

    public function test_inactive_cashbox_rejects_create_and_rolls_back_expense_row(): void
    {
        $cashbox = $this->makeCashbox(['is_active' => false]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Inactive box',
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        // DB::transaction rolled back — no expense, no cashbox transaction.
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, CashboxTransaction::where('source_type', 'expense')->count());
    }

    public function test_inactive_payment_method_rejects_create(): void
    {
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');
        $method->update(['is_active' => false]);

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Inactive method',
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(0, Expense::count());
    }

    public function test_insufficient_balance_blocks_when_negative_not_allowed(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 50, 'allow_negative_balance' => false]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Overdraft attempt',
            'amount' => 200,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(0, Expense::count(), 'Rollback: no expense row written.');
        $this->assertSame(50.0, $cashbox->fresh()->balance(), 'Cashbox unchanged.');
    }

    public function test_overdraft_allowed_when_allow_negative_balance_true(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 50, 'allow_negative_balance' => true]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Permitted overdraft',
            'amount' => 200,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect('/expenses');

        $this->assertSame(1, Expense::count());
        $this->assertSame(-150.0, $cashbox->fresh()->balance(), '50 - 200 = -150 (permitted).');
    }

    public function test_currency_mismatch_rejects(): void
    {
        $cashbox = $this->makeCashbox(['currency_code' => 'EGP', 'opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Cross-currency',
            'amount' => 100,
            'currency_code' => 'USD',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(0, Expense::count());
        $this->assertSame(1000.0, $cashbox->fresh()->balance());
    }

    /* ────────────────────── Posted-expense immutability ────────────────────── */

    public function test_posted_expense_cannot_have_amount_edited_via_update(): void
    {
        $expense = $this->createPostedExpense(['amount' => 100]);

        $this->actingAs($this->admin)->put('/expenses/' . $expense->id, [
            'expense_category_id' => $expense->expense_category_id,
            'title' => 'Title change',
            'amount' => 9999, // attempt to drift
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect();

        $fresh = $expense->fresh();
        $this->assertSame('Title change', $fresh->title);
        $this->assertSame('100.00', (string) $fresh->amount, 'Amount must be locked.');
    }

    public function test_posted_expense_cannot_be_soft_deleted(): void
    {
        $expense = $this->createPostedExpense();

        $this->actingAs($this->admin)->delete('/expenses/' . $expense->id)->assertRedirect();

        $this->assertNotNull(Expense::find($expense->id), 'Expense should still exist (not soft-deleted).');
    }

    /* ────────────────────── Retro-posting ────────────────────── */

    public function test_can_post_historical_expense_to_cashbox(): void
    {
        $expense = $this->makeHistoricalExpense(['amount' => 75]);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses/' . $expense->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $tx = CashboxTransaction::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->firstOrFail();
        $this->assertSame('-75.00', (string) $tx->amount);

        $fresh = $expense->fresh();
        $this->assertSame($tx->id, $fresh->cashbox_transaction_id);
        $this->assertNotNull($fresh->cashbox_posted_at);
    }

    public function test_cannot_post_same_expense_twice(): void
    {
        $expense = $this->createPostedExpense(['amount' => 100]);
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        // Service-level: throws.
        $this->expectException(\RuntimeException::class);
        $this->actingAs($this->admin);
        app(ExpenseCashboxService::class)->postExpenseToCashbox($expense->fresh(), [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);
    }

    public function test_endpoint_double_post_does_not_write_second_transaction(): void
    {
        $expense = $this->createPostedExpense(['amount' => 100]);
        $beforeCount = CashboxTransaction::where('source_type', 'expense')->count();

        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses/' . $expense->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame($beforeCount, CashboxTransaction::where('source_type', 'expense')->count());
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_unauthorized_user_cannot_post_expense(): void
    {
        $expense = $this->makeHistoricalExpense();
        $cashbox = $this->makeCashbox();
        $method = $this->getMethod('cash');

        // User has expenses.edit but NOT expenses.post_to_cashbox.
        $restricted = $this->makeRestrictedUser(['expenses.view', 'expenses.edit']);

        $this->actingAs($restricted)->post('/expenses/' . $expense->id . '/post-to-cashbox', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertForbidden();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'expense')->count());
    }

    /* ────────────────────── Historical data tolerance ────────────────────── */

    public function test_historical_expense_with_null_cashbox_still_loads(): void
    {
        $this->makeHistoricalExpense();

        $response = $this->actingAs($this->admin)->get('/expenses');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Expenses/Index')
            ->has('expenses.data.0')
            ->where('expenses.data.0.cashbox', null)
            ->where('expenses.data.0.payment_method', null)
            ->where('expenses.data.0.cashbox_transaction_id', null)
        );
    }

    /* ────────────────────── Audit log ────────────────────── */

    public function test_creating_expense_writes_audit_log_entries(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 500]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_category_id' => $this->category->id,
            'title' => 'Audited expense',
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect('/expenses');

        $this->assertGreaterThanOrEqual(
            1,
            AuditLog::where('action', 'cashbox_transaction.created')
                ->where('module', 'finance.expense')->count(),
            'Cashbox transaction audit row missing.'
        );

        $this->assertGreaterThanOrEqual(
            1,
            AuditLog::where('action', 'posted_to_cashbox')
                ->where('record_type', Expense::class)->count(),
            'Expense posted_to_cashbox audit row missing.'
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        $opening = (float) ($overrides['opening_balance'] ?? 0);

        $cashbox = Cashbox::create(array_merge([
            'name' => 'Expense Test Cashbox ' . $counter,
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

    private function getMethod(string $code): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    /**
     * Insert a row directly via DB to mimic a pre-Phase-4 expense
     * (no cashbox_id, no payment_method_id, never posted).
     */
    private function makeHistoricalExpense(array $overrides = []): Expense
    {
        static $counter = 0;
        $counter++;

        return Expense::create(array_merge([
            'expense_category_id' => $this->category->id,
            'title' => 'Historical expense ' . $counter,
            'amount' => 50,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * Build an expense + post it in one step, returning the posted row.
     */
    private function createPostedExpense(array $overrides = []): Expense
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 10000]);
        $method = $this->getMethod('cash');

        $expense = Expense::create(array_merge([
            'expense_category_id' => $this->category->id,
            'title' => 'Posted ' . uniqid(),
            'amount' => 100,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));

        $this->actingAs($this->admin);
        app(ExpenseCashboxService::class)->postExpenseToCashbox($expense, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        return $expense->fresh();
    }

    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Expense Restricted ' . uniqid(),
            'slug' => 'exp-restricted-' . uniqid(),
            'description' => 'Expense cashbox test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted',
            'email' => 'exp-restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
