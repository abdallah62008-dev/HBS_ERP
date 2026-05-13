<?php

namespace Tests\Feature\Performance;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Performance Phase 2 — query-count + value-equality regression for the
 * FinanceReportsService cashbox-balance optimization.
 *
 * Pins:
 *   - Total cashbox balance equals SUM(cashbox_transactions.amount)
 *     over active cashboxes (the previous loop's contract).
 *   - Per-cashbox balance equals SUM(amount) WHERE cashbox_id=X
 *     (previously `Cashbox::balance()`).
 *   - Query count does NOT grow linearly with cashbox count
 *     (was N+1; now O(1) extra query for balance lookup).
 *   - Reports remain read-only.
 *
 * Companion to `tests/Feature/Finance/FinanceReportsTest.php` (which
 * already covers report semantics). This file specifically exercises
 * the loop-replacement performance contract.
 */
class FinanceReportsQueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    /* ────────────────────── 1. Total balance equals ledger ────────────────────── */

    public function test_overview_total_balance_equals_ledger_sum_of_active_cashboxes(): void
    {
        $a = $this->cashbox(name: 'A', opening: 100);
        $b = $this->cashbox(name: 'B', opening: 250);
        $inactive = $this->cashbox(name: 'C-inactive', opening: 500, isActive: false);

        // Add some additional movements to make the test more meaningful.
        $this->tx($a, +50, 'collection');
        $this->tx($a, -30, 'expense');
        $this->tx($b, +200, 'collection');
        $this->tx($inactive, +100, 'collection'); // must be excluded

        $expectedActiveBalance = ((float) DB::table('cashbox_transactions')
            ->whereIn('cashbox_id', [$a->id, $b->id])
            ->sum('amount'));

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('total_balance', fn ($v) => (float) $v === $expectedActiveBalance)
        );
    }

    public function test_overview_total_balance_excludes_inactive_cashboxes(): void
    {
        $active = $this->cashbox(name: 'Active', opening: 100);
        $inactive = $this->cashbox(name: 'Inactive', opening: 999, isActive: false);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->where('total_balance', fn ($v) => (float) $v === 100.0)
        );
    }

    public function test_cashbox_with_no_transactions_shows_zero_balance(): void
    {
        $empty = $this->cashbox(name: 'Empty', opening: 0);
        $this->assertSame(0, CashboxTransaction::where('cashbox_id', $empty->id)->count());

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/cashboxes?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.balance', fn ($v) => (float) $v === 0.0)
        );
    }

    /* ────────────────────── 2. Per-cashbox balance equals ledger ────────────────────── */

    public function test_cashboxes_report_per_row_balance_equals_ledger(): void
    {
        $boxes = [];
        for ($i = 1; $i <= 5; $i++) {
            $boxes[] = $this->cashbox(name: "Box $i", opening: $i * 100);
        }
        // Spread some IN/OUT movements across them.
        foreach ($boxes as $idx => $box) {
            $this->tx($box, ($idx + 1) * 50, 'collection');
            if ($idx % 2 === 0) {
                $this->tx($box, -1 * ($idx + 1) * 10, 'expense');
            }
        }

        // Compute expected balances directly from the ledger.
        $expected = [];
        foreach ($boxes as $box) {
            $expected[$box->id] = (float) DB::table('cashbox_transactions')
                ->where('cashbox_id', $box->id)
                ->sum('amount');
        }

        $user = $this->userWith(['finance_reports.view']);

        $this->actingAs($user)
            ->get('/finance/reports/cashboxes?from=2000-01-01&to=2099-12-31')
            ->assertOk()
            ->assertInertia(function ($page) use ($expected) {
                $rows = $page->toArray()['props']['rows'] ?? [];
                foreach ($rows as $row) {
                    $this->assertSame(
                        $expected[(int) $row['id']],
                        (float) $row['balance'],
                        "Row for cashbox #{$row['id']} should match the ledger SUM.",
                    );
                }
                return $page;
            });
    }

    public function test_overview_matches_legacy_balance_call_per_active_cashbox(): void
    {
        // Build 4 active cashboxes with varied movements.
        $boxes = [];
        for ($i = 1; $i <= 4; $i++) {
            $boxes[] = $this->cashbox(name: "Cmp $i", opening: $i * 200);
        }
        $this->tx($boxes[0], +50, 'collection');
        $this->tx($boxes[1], -30, 'expense');
        $this->tx($boxes[2], -75, 'refund');
        $this->tx($boxes[3], -25, 'marketer_payout');

        // Reference value: legacy loop.
        $legacyTotal = (float) Cashbox::active()->get()
            ->sum(fn (Cashbox $c) => $c->balance());

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->where('total_balance', fn ($v) => (float) $v === round($legacyTotal, 2))
        );
    }

    /* ────────────────────── 3. Query count — N+1 is gone ────────────────────── */

    public function test_overview_query_count_is_constant_in_cashbox_count(): void
    {
        // Create 10 cashboxes with movements. Pre-Phase-2 this would
        // produce ~10 extra `SELECT SUM(amount) WHERE cashbox_id=?`
        // queries from the legacy loop. Post-Phase-2 there should be
        // exactly one grouped `SUM(amount) GROUP BY cashbox_id`.
        for ($i = 1; $i <= 10; $i++) {
            $box = $this->cashbox(name: "QC $i", opening: $i * 10);
            $this->tx($box, +$i, 'collection');
        }

        $user = $this->userWith(['finance_reports.view']);

        // Sanity: ensure data exists.
        $this->assertSame(10, Cashbox::active()->count());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The legacy N+1 pattern looked like:
        //   SELECT SUM(amount) FROM cashbox_transactions
        //   WHERE cashbox_id = ?   (one binding, one cashbox per query)
        // The fix uses ONE grouped query:
        //   SELECT cashbox_id, SUM(amount) FROM cashbox_transactions
        //   GROUP BY cashbox_id
        // We assert the legacy signature does NOT appear at all.
        $perCashboxBalanceQueries = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'select sum(')
                && str_contains($sql, 'cashbox_transactions')
                && str_contains($sql, 'where "cashbox_id" =');
        });

        $this->assertSame(
            0,
            $perCashboxBalanceQueries->count(),
            'Pre-Phase-2 the legacy loop ran one `SUM(amount) WHERE cashbox_id=?` query per active cashbox. After the fix there should be zero such queries — replaced by a single grouped `SUM(amount) GROUP BY cashbox_id`.',
        );

        // And confirm the grouped query DID fire (positive signature check).
        $groupedBalanceQueries = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'cashbox_id')
                && str_contains($sql, 'cashbox_transactions')
                && str_contains($sql, 'group by')
                && str_contains($sql, 'sum(amount)');
        });

        $this->assertGreaterThanOrEqual(
            1,
            $groupedBalanceQueries->count(),
            'The grouped balance query should appear exactly once for the overview page.',
        );
    }

    /* ────────────────────── 4. Read-only contract ────────────────────── */

    public function test_overview_does_not_mutate_data(): void
    {
        $box = $this->cashbox(name: 'RO', opening: 100);
        $txCountBefore = CashboxTransaction::count();
        $cashboxCountBefore = Cashbox::count();

        $user = $this->userWith(['finance_reports.view']);
        $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31')
            ->assertOk();
        $this->actingAs($user)
            ->get('/finance/reports/cashboxes?from=2000-01-01&to=2099-12-31')
            ->assertOk();

        $this->assertSame($txCountBefore, CashboxTransaction::count());
        $this->assertSame($cashboxCountBefore, Cashbox::count());
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function cashbox(string $name, float $opening = 0, bool $isActive = true): Cashbox
    {
        $cashbox = Cashbox::create([
            'name' => $name,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => $opening,
            'allow_negative_balance' => true,
            'is_active' => $isActive,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        if ($opening != 0) {
            CashboxTransaction::create([
                'cashbox_id' => $cashbox->id,
                'direction' => $opening >= 0 ? 'in' : 'out',
                'amount' => $opening,
                'occurred_at' => '2020-01-01 00:00:00',
                'source_type' => 'opening_balance',
                'notes' => 'Test fixture',
                'created_by' => $this->admin->id,
            ]);
        }

        return $cashbox;
    }

    private function tx(Cashbox $cashbox, float $amount, string $sourceType): CashboxTransaction
    {
        return CashboxTransaction::create([
            'cashbox_id' => $cashbox->id,
            'direction' => $amount >= 0 ? 'in' : 'out',
            'amount' => $amount,
            'occurred_at' => now(),
            'source_type' => $sourceType,
            'notes' => "Test {$sourceType}",
            'created_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Perf Test ' . uniqid(),
            'slug' => 'perf-test-' . uniqid(),
            'description' => 'Performance Phase 2 test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Perf Test User',
            'email' => 'perf-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
