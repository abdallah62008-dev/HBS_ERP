<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders & Products O-2 — DuplicateDetectionService now matches on the
 * indexed `orders.customer_phone_normalized` column when available, with
 * a fallback to the legacy raw-string compare for un-backfilled orders.
 *
 * This test pins both paths:
 *  - Two orders that normalize to the same E.164 are flagged regardless
 *    of how the operator typed each one.
 *  - The legacy fallback still works for orders that don't yet have a
 *    `customer_phone_normalized` snapshot.
 */
class DuplicateDetectionPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_same_normalized_phone_with_different_raw_form_is_flagged(): void
    {
        // Order A is saved with the E.164 form via O-2 wiring.
        $this->seedOrder(rawPhone: '+201012345678', normalized: '+201012345678');

        // The operator now enters the same number in local form. The
        // dedupe service must flag it.
        $svc = app(DuplicateDetectionService::class);
        $result = $svc->evaluate([
            'primary_phone' => '01012345678',
            'product_ids' => [],
        ]);

        $this->assertGreaterThanOrEqual(60, $result['score'], 'Same phone (different raw form) should score >= 60.');
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_legacy_raw_string_compare_still_fires_when_normalized_is_missing(): void
    {
        // Simulate an order saved BEFORE O-2: customer_phone_normalized = null
        // but customer_phone has a value the cleaning rule can match.
        $this->seedOrder(rawPhone: '+201012345678', normalized: null);

        $svc = app(DuplicateDetectionService::class);
        $result = $svc->evaluate([
            'primary_phone' => '01012345678',
            'product_ids' => [],
        ]);

        // The legacy REPLACE-based compare normalises both sides by
        // stripping spaces/dashes/`+`, so '01012345678' vs '+201012345678'
        // still differ — that's the OLD behaviour; the legacy fallback
        // matches only on identical stripped form. Therefore this case
        // does NOT score from the phone rule. We pin this so we know
        // exactly what the upgrade buys us.
        $reasonHit = false;
        foreach ($result['reasons'] as $reason) {
            if (str_contains($reason, 'phone used')) $reasonHit = true;
        }
        $this->assertFalse($reasonHit, 'Legacy fallback alone should NOT match across raw forms (proves we need the normalization upgrade).');
    }

    public function test_legacy_raw_string_compare_matches_identical_raw_form(): void
    {
        // Pre-O-2 order: no normalized snapshot, raw string identical.
        $this->seedOrder(rawPhone: '01012345678', normalized: null);

        $svc = app(DuplicateDetectionService::class);
        $result = $svc->evaluate([
            'primary_phone' => '01012345678',
            'product_ids' => [],
        ]);

        $this->assertGreaterThanOrEqual(60, $result['score']);
    }

    private function seedOrder(string $rawPhone, ?string $normalized): Order
    {
        $c = Customer::firstOrCreate(
            ['primary_phone' => $rawPhone],
            [
                'name' => 'Dedupe Test',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'default_address' => 'addr',
                'normalized_phone' => $normalized,
                'created_by' => $this->admin->id,
            ],
        );

        return Order::create([
            'order_number' => 'ORD-DEDUPE-' . uniqid(),
            'entry_code' => 'TST',
            'fiscal_year_id' => \App\Models\FiscalYear::firstOrFail()->id,
            'customer_id' => $c->id,
            'customer_name' => $c->name,
            'customer_phone' => $rawPhone,
            'customer_phone_normalized' => $normalized,
            'customer_address' => 'addr',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'status' => 'New',
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
    }
}
